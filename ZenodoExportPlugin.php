<?php

/**
 * @file plugins/generic/zenodo/ZenodoExportPlugin.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ZenodoExportPlugin
 *
 * @brief Zenodo export plugin
 */

namespace APP\plugins\generic\zenodo;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\zenodo\filter\ZenodoJsonFilter;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;
use PKP\config\Config;
use PKP\context\Context;
use PKP\db\DAORegistry;
use PKP\filter\FilterDAO;
use PKP\galley\Galley;
use PKP\notification\Notification;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;

class ZenodoExportPlugin extends PubObjectsExportPlugin implements HasTaskScheduler
{
    public const ZENODO_API_OK = 200;
    public const ZENODO_API_DEPOSIT_CREATED = 201;
    public const ZENODO_API_ACCEPTED = 202;
    public const ZENODO_API_NO_CONTENT = 204;
    public const ZENODO_API_NOT_FOUND = 404;
    public const ZENODO_API_URL = 'https://zenodo.org/api/';
    public const ZENODO_API_URL_DEV = 'https://sandbox.zenodo.org/api/';
    public const ZENODO_API_OPERATION = 'records';

    /**
     * @copydoc Plugin::getName()
     */
    public function getName(): string
    {
        return 'ZenodoExportPlugin';
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.importexport.zenodo.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.importexport.zenodo.description');
    }

    /**
     * @copydoc ImportExportPlugin::display()
     * @throws Exception
     */
    public function display($args, $request): void
    {
        parent::display($args, $request);
        switch (array_shift($args)) {
            case 'index':
            case '':
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;
        }
    }

    /**
     * @copydoc ImportExportPlugin::getPluginSettingsPrefix()
     */
    public function getPluginSettingsPrefix(): string
    {
        return 'zenodo';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getSubmissionFilter()
     */
    public function getSubmissionFilter(): string
    {
        return 'article=>zenodo-json';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getPublicationFilter()
     */
    public function getPublicationFilter(): ?string
    {
        return 'publication=>zenodo-json';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportActions()
     */
    public function getExportActions($context): array
    {
        $actions = [PubObjectsExportPlugin::EXPORT_ACTION_EXPORT, PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED];
        if ($this->getApiKey($context)) {
            array_unshift($actions, PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT);
        }
        return $actions;
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportDeploymentClassName()
     */
    public function getExportDeploymentClassName(): string
    {
        return '\APP\plugins\generic\zenodo\ZenodoExportDeployment';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getSettingsFormClassName()
     */
    public function getSettingsFormClassName(): string
    {
        return '\APP\plugins\generic\zenodo\classes\form\ZenodoSettingsForm';
    }

    /**
     * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler
            ->addSchedule(new ZenodoInfoSender())
            ->daily()
            ->name(ZenodoInfoSender::class)
            ->withoutOverlapping();
    }

    /**
     * @param Submission|Publication $object
     * @param Context $context
     * @param string $jsonString Export JSON string
     *
     * @return bool|array Whether the JSON string has been registered
     *
     * @see PubObjectsExportPlugin::depositXML()
     *
     */
    public function depositXML($object, $context, $jsonString): bool|array
    {
        $apiKey = $this->getApiKey($context);
        if (!$apiKey) {
            return [['plugins.importexport.zenodo.register.error.noApiKey']];
        }

        $mintDoi = $this->mintZenodoDois($context);
        $isPublication = $object instanceof Publication;
        $doi = $isPublication ? $object->getDoi() : $object->getCurrentPublication()->getDoi();
        if (!$mintDoi && !$doi) {
            return [['plugins.importexport.zenodo.api.error.noDoi']];
        }

        $zenodoApiUrl = ($this->isTestMode($context) ? self::ZENODO_API_URL_DEV : self::ZENODO_API_URL);
        $recordsApiUrl = $zenodoApiUrl . self::ZENODO_API_OPERATION . '/';

        $isUpdate = false;
        $isPublished = false;
        if ($existingZenodoId = $object->getData($this->getIdSettingName())) {
            $isUpdate = true;
            $isPublished = $this->isRecordPublished($object, $existingZenodoId, $recordsApiUrl);
            if (is_array($isPublished)) {
                // Don't continue if we can't check the published status.
                return $isPublished;
            }
        }

        if ($isUpdate && !$isPublished) {
            // @todo what if there is a review request and we try to delete the record?
            // We won't be able to delete a draft record with an open review request, to be solved.
            $result = $this->deleteDraft($object, $existingZenodoId, $zenodoApiUrl, $apiKey);
            if (is_array($result)) {
                return $result;
            }
        }

        $zenodoId = $this->createOrUpdateDraft($jsonString, $object, $recordsApiUrl, $apiKey, $isUpdate, $isPublished, $existingZenodoId);
        if (is_array($zenodoId)) {
            return $zenodoId;
        }

        // Set Zenodo ID, including for all other sibling minor publications.
        $object->setData($this->getIdSettingName(), $zenodoId);
        $this->updateObject($object);
        if ($isPublication) {
            // Set Zenodo ID for all other sibling minor publications.
            $editParams = [
                $this->getIdSettingName() => $zenodoId,
            ];
            Repo::publication()->getCollector()
                ->filterBySubmissionIds([$object->getData('submissionId')])
                ->filterByVersionStage($object->getData('versionStage'))
                ->filterByVersionMajor($object->getData('versionMajor'))
                ->getMany()
                ->filter(function (Publication $publication) use ($object) {
                    return $publication->getId() != $object->getId();
                })
                ->each(fn (Publication $publication) => Repo::publication()->edit($publication, $editParams));
        }

        // Note: can't update files on published records.
        if (!$isPublished) {
            $filesDeposit = $this->depositFiles($object, $recordsApiUrl, $apiKey, $zenodoId);
            if (is_array($filesDeposit)) {
                return $filesDeposit;
            }
        }

        // Publish based on settings or updating a previously published record.
        if ($this->automaticPublishing($context) || $isPublished) {
            $published = $this->publishZenodoDraft($object, $zenodoId, $recordsApiUrl, $apiKey);
            if (is_array($published)) {
                // Try to delete the draft if publishing failed.
                $this->deleteDraft($object, $zenodoId, $zenodoApiUrl, $apiKey);
                return $published;
            }
        }

        // Deposit was received; set the status
        // If community submission fails, the record still exists, so we need to set the status and id.
        $object->setData($this->getDepositStatusSettingName(), PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED);
        $this->updateObject($object);

        // Submit the record to a community (record may be published depending on settings)
        // @todo manage errors - if this fails, cancel the review request?
        // if we set error status, then this won't be called for the next update.
        $communityId = $this->getCommunityId($context);
        if ($communityId && !$isUpdate) {
            if ($review = $this->createReview($zenodoId, $communityId, $recordsApiUrl, $apiKey)) {
                $requestId = $this->submitReview($zenodoId, $zenodoApiUrl, $apiKey);
                $autoPublishCommunity = $this->automaticPublishingCommunity($context);
                if (is_array($requestId)) {
                    return $requestId;
                } elseif ($autoPublishCommunity && $requestId) {
                    $reviewAccepted = $this->acceptReview($requestId, $zenodoApiUrl, $apiKey);
                }
            } elseif (is_array($review)) {
                return $review;
            }
        }

        return true;
    }

    /**
     * @copydoc PubObjectsExportPlugin::executeExportAction()
     *
     * @param null|mixed $noValidation
     * @throws Exception
     */
    public function executeExportAction(
        $request,
        $objects,
        $filter,
        $tab,
        $objectsFileNamePart,
        $noValidation = true,
        $shouldRedirect = true
    ): void {
        $context = $request->getContext();
        $path = ['plugin', $this->getName()];
        if ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT)) {
            $filter = $context->getData(Context::SETTING_DOI_VERSIONING)
                ? 'publication=>zenodo-json'
                : 'article=>zenodo-json';
            $resultErrors = [];
            foreach ($objects as $object) {
                // Get the JSON
                $exportJson = $this->exportJSON($object, $filter, $context);
                // Deposit the JSON
                $result = $this->depositXML($object, $context, $exportJson);
                if (is_array($result)) {
                    $resultErrors[] = $result;
                }
            }
            // send notifications
            if (empty($resultErrors)) {
                $this->_sendNotification(
                    $request->getUser(),
                    $this->getDepositSuccessNotificationMessageKey(),
                    Notification::NOTIFICATION_TYPE_SUCCESS
                );
            } else {
                foreach ($resultErrors as $errors) {
                    foreach ($errors as $error) {
                        if (!is_array($error) || !count($error) > 0) {
                            throw new Exception('Invalid error message');
                        }
                        $this->_sendNotification(
                            $request->getUser(),
                            $error[0],
                            Notification::NOTIFICATION_TYPE_ERROR,
                            ($error[1] ?? null)
                        );
                    }
                }
            }
            // redirect back to the right tab
            $request->redirect(null, null, null, $path, null, $tab);
        } elseif ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_EXPORT)) {
            $items = [];
            foreach ($objects as $object) {
                // Get the JSON
                $exportJson = $this->exportJSON($object, $filter, $context);
                $export = json_decode($exportJson);
                $items[] = $export;
            }
            // Display the combined JSON for all articles selected
            header('Content-Type: application/json');
            echo json_encode($items);
        } else {
            parent::executeExportAction($request, $objects, $filter, $tab, $objectsFileNamePart, $noValidation);
        }
    }

    /**
     * Get the JSON for selected objects.
     *
     * @return string JSON variable.
     * @throws Exception
     */
    public function exportJSON(Submission|Publication $object, string $filter, Context $context): string
    {
        $filterDao = DAORegistry::getDAO('FilterDAO'); /** @var FilterDAO $filterDao */
        $exportFilters = $filterDao->getObjectsByGroup($filter);
        if (count($exportFilters) == 0) {
            throw new Exception('No export filter found');
        } elseif (count($exportFilters) > 1) {
            throw new Exception('Multiple export filters found');
        }

        $exportFilter = array_shift($exportFilters); /** @var ZenodoJsonFilter $exportFilter */
        $exportDeployment = $this->_instantiateExportDeployment($context);
        $exportFilter->setDeployment($exportDeployment);
        return $exportFilter->execute($object, true);
    }

    /**
     * Check whether we will allow Zenodo to mint DOIs.
     */
    public function mintZenodoDois(Context $context): bool
    {
        return ($this->getSetting($context->getId(), 'mintDoi') == 1);
    }

    /**
     * Get the Zenodo API key setting value.
     */
    public function getApiKey(Context $context): string|false
    {
        return $this->getSetting($context->getId(), 'apiKey') ?? false;
    }

    /**
     * Get the Zenodo community slug that records should be submitted to.
     */
    public function getCommunity(Context $context): string|false
    {
        return $this->getSetting($context->getId(), 'community') ?? false;
    }

    /**
     * Get the Zenodo community ID that records should be submitted to.
     */
    public function getCommunityId(Context $context): string|false
    {
        return $this->getSetting($context->getId(), 'communityId') ?? false;
    }

    /**
     * Check whether we will try to automatically publish Zenodo records in the community.
     */
    public function automaticPublishingCommunity(Context $context): bool
    {
        return $this->getSetting($context->getId(), 'automaticPublishingCommunity') ?? false;
    }

    /**
     * Check whether we will try to automatically export Zenodo records.
     */
    public function automaticRegistration(Context $context): bool
    {
        return ($this->getSetting($context->getId(), 'automaticRegistration') == 1);
    }

    /**
     * Check whether we will try to automatically publish exported Zenodo records.
     */
    public function automaticPublishing(Context $context): bool
    {
        return ($this->getSetting($context->getId(), 'automaticPublishing') == 1);
    }

    /**
     * Get Zenodo ID setting name.
     */
    public function getIdSettingName(): string
    {
        return $this->getPluginSettingsPrefix() . '::id';
    }

    /**
     * Get a list of additional setting names that should be stored with the objects.
     */
    public function getObjectAdditionalSettings(): array
    {
        return array_merge(parent::getObjectAdditionalSettings(), [
            $this->getIdSettingName(),
            $this->getDepositStatusSettingName()
        ]);
    }

    /**
     * Create a draft or update an existing record in Zenodo.
     */
    protected function createOrUpdateDraft(
        string $json,
        Submission|Publication $object,
        string $url,
        string $apiKey,
        bool $isUpdate = false,
        bool $isPublished = false,
        ?string $zenodoId = null
    ): string|array {
        $httpClient = Application::get()->getHttpClient();
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/vnd.inveniordm.v1+json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        // If the record is published, create a new draft first that we will update
        if ($isPublished) {
            $publishDraft = $this->createDraftFromPublished($object, $url, $apiKey, $zenodoId);
            if (is_array($publishDraft)) {
                return $publishDraft;
            }
        }

        // Settings depending on whether we are updating or creating a record
        $operation = $isUpdate ? 'PUT' : 'POST';
        if ($isUpdate) {
            $url = $url . $zenodoId . '/draft';
        }

        try {
            $response = $httpClient->request(
                $operation,
                $url,
                [
                    'headers' => $headers,
                    'json' => json_decode($json),
                ]
            );
        } catch (RequestException $e) {
            $returnMessage = $e->hasResponse()
                ? $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')'
                : $e->getMessage();
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $returnMessage);
            return [['plugins.importexport.zenodo.register.error.mdsError', $e->getMessage()]];
        }

        $responseBody = json_decode($response->getBody());
        return $responseBody->id;
    }

    /**
     * Create a new draft record for a published record in Zenodo.
     */
    protected function createDraftFromPublished(
        Submission|Publication $object,
        string $url,
        string $apiKey,
        string $zenodoId
    ): bool|array {
        $httpClient = Application::get()->getHttpClient();
        $url = $url . $zenodoId . '/draft';

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/vnd.inveniordm.v1+json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        try {
            $httpClient->request(
                'POST',
                $url,
                [
                    'headers' => $headers,
                ]
            );
        } catch (RequestException $e) {
            $returnMessage = $e->hasResponse()
                ? $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')'
                : $e->getMessage();
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $returnMessage);
            return [['plugins.importexport.zenodo.register.error.draftPublishError', $e->getMessage()]];
        }

        return true;
    }

    /**
     * Send files to the Zenodo API.
     * https://inveniordm-dev.docs.cern.ch/reference/rest_api_quickstart/#upload-a-file
     */
    protected function depositFiles(
        Submission|Publication $object,
        string $url,
        string $apiKey,
        int $zenodoId
    ): bool|array {
        $httpClient = Application::get()->getHttpClient();
        $filesMetadataUrl = $url . $zenodoId . '/draft/files';
        $fileService = app()->get('file');
        $filesDir = Config::getVar('files', 'files_dir');

        $metadataHeaders = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        $publication = $object instanceof Publication ? $object : $object->getCurrentPublication();
        $pubLocale = $publication->getData('locale');

        foreach ($publication->getData('galleys') as $galley) { /** @var Galley $galley */
            $submissionFile = $galley->getData('submissionFileId')
                ? Repo::submissionFile()->get($galley->getData('submissionFileId'))
                : null;
            if (!$submissionFile) {
                continue;
            }

            // Initialize the file upload
            $fileName = $submissionFile->getData('name', $pubLocale);
            try {
                $httpClient->request(
                    'POST',
                    $filesMetadataUrl,
                    [
                        'headers' => $metadataHeaders,
                        'json' => [
                            [
                                'key' => $fileName
                            ]
                        ]
                    ],
                );
            } catch (RequestException $e) {
                $returnMessage = $e->hasResponse()
                    ? $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')'
                    : $e->getMessage();
                $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $returnMessage);
                return [['plugins.importexport.zenodo.api.error.fileError', $e->getMessage()]];
            }

            // Upload the file contents
            $filePath = $filesDir . '/' . $fileService->get($submissionFile->getData('fileId'))->path;
            $filesFileUrl = $url . $zenodoId . '/draft/files/' . $fileName . '/content';
            $fileHeaders = [
                'Content-Type' => 'application/octet-stream',
                'Authorization' => 'Bearer ' . $apiKey,
            ];
            try {
                $httpClient->request(
                    'PUT',
                    $filesFileUrl,
                    [
                        'headers' => $fileHeaders,
                        'body' => Psr7\Utils::tryFopen($filePath, 'r')
                    ],
                );
            } catch (RequestException $e) {
                $returnMessage = $e->hasResponse()
                    ? $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')'
                    : $e->getMessage();
                $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $returnMessage);
                return [['plugins.importexport.zenodo.api.error.fileError', $e->getMessage()]];
            }

            // Commit the file upload
            $filesCommitUrl = $url . $zenodoId . '/draft/files/' . $fileName . '/commit';
            $commitHeaders = [
                'Authorization' => 'Bearer ' . $apiKey,
            ];

            try {
                $httpClient->request(
                    'POST',
                    $filesCommitUrl,
                    [
                        'headers' => $commitHeaders,
                    ],
                );
            } catch (RequestException $e) {
                $returnMessage = $e->hasResponse()
                    ? $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')'
                    : $e->getMessage();
                $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $returnMessage);
                return [['plugins.importexport.zenodo.api.error.fileError', $e->getMessage()]];
            }
        }
        return true;
    }

    /**
     * Delete a draft record in Zenodo.
     * https://inveniordm-dev.docs.cern.ch/reference/rest_api_drafts_records/#deletediscard-a-draft-record
     */
    protected function deleteDraft(
        Submission|Publication $object,
        int $zenodoId,
        string $url,
        string $apiKey
    ): bool|array {
        // @todo unable to check requests for drafts
        //        // Can't delete a draft if it has a review request, so try to cancel it first if it exists
        //        $reviewRequestId = $this->getReviewRequest($zenodoId, $url, $apiKey);
        //        if ($reviewRequestId) {
        //            $this->cancelReviewRequest($reviewRequestId, $url, $apiKey);
        //        }

        $httpClient = Application::get()->getHttpClient();
        $deleteRecordUrl = $url . self::ZENODO_API_OPERATION . '/' . $zenodoId . '/draft';
        $deleteRecordHeaders = [
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        try {
            $httpClient->request(
                'DELETE',
                $deleteRecordUrl,
                [
                    'headers' => $deleteRecordHeaders,
                ],
            );
        } catch (RequestException $e) {
            $returnMessage = $e->hasResponse()
                ? $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')'
                : $e->getMessage();
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $returnMessage);
            return [['plugins.importexport.zenodo.api.error.recordDeleteError', $e->getMessage()]];
        }

        // Delete Zenodo ID, including for all other sibling minor publications.
        $object->setData($this->getIdSettingName(), null);
        $this->updateObject($object);
        if ($object instanceof Publication) {
            $editParams = [
                $this->getIdSettingName() => null,
            ];
            Repo::publication()->getCollector()
                ->filterBySubmissionIds([$object->getData('submissionId')])
                ->filterByVersionStage($object->getData('versionStage'))
                ->filterByVersionMajor($object->getData('versionMajor'))
                ->getMany()
                ->filter(function (Publication $publication) use ($object) {
                    return $publication->getId() != $object->getId();
                })
                ->each(fn (Publication $publication) => Repo::publication()->edit($publication, $editParams));
        }
        return true;
    }

    /**
     * Check against Zenodo's awards API that a given
     * combination of a funder (ROR) and award number
     * is valid for import.
     * Endpoint format: https://zenodo.org/api/awards/{ROR::award}
     */
    public function isValidAward(Context $context, string $funderRor, string $award): bool
    {
        $apiUrl = ($this->isTestMode($context) ? self::ZENODO_API_URL_DEV : self::ZENODO_API_URL);
        $awardsUrl = $apiUrl . 'awards/' . $funderRor . '::' . $award;
        $httpClient = Application::get()->getHttpClient();

        try {
            $awardResponse = $httpClient->request('GET', $awardsUrl);
            $body = json_decode($awardResponse->getBody(), true);

            if (
                $awardResponse->getStatusCode() === self::ZENODO_API_OK
                && !empty($body['id'])
                && $body['id'] == $funderRor . '::' . $award
            ) {
                return true;
            } else {
                return false;
            }
        } catch (GuzzleException | Exception $e) {
            if ($e->getCode() === self::ZENODO_API_NOT_FOUND) {
                // The award does not exist in Zenodo.
                return false;
            }
            $returnMessage = $e->hasResponse()
                ? $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')'
                : $e->getMessage();
            error_log(__('plugins.importexport.zenodo.api.error.awardError', ['param' => $returnMessage]));
            return false;
        }
    }

    /**
     * Publish a Zenodo record.
     */
    public function publishZenodoDraft(
        Submission|Publication $object,
        int $zenodoId,
        string $url,
        string $apiKey
    ): string|array {
        $httpClient = Application::get()->getHttpClient();
        $publishUrl = $url . $zenodoId . '/draft/actions/publish';

        $publishHeaders = [
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        try {
            $httpClient->request(
                'POST',
                $publishUrl,
                [
                    'headers' => $publishHeaders,
                ]
            );
        } catch (RequestException $e) {
            $returnMessage = $e->hasResponse()
                ? $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')'
                : $e->getMessage();
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $returnMessage);
            return [['plugins.importexport.zenodo.api.error.publishError', $e->getMessage()]];
        }

        return true;
    }

    /**
     * Check if a Zenodo record has been published.
     */
    public function isRecordPublished(Submission|Publication $object, int $zenodoId, string $url): bool|array
    {
        $recordUrl = $url . $zenodoId;
        $httpClient = Application::get()->getHttpClient();

        try {
            $response = $httpClient->request(
                'GET',
                $recordUrl
            );

            if ($response->getStatusCode() === self::ZENODO_API_OK) {
                return true;
            }
        } catch (GuzzleException | Exception $e) {
            if ($e->getCode() === self::ZENODO_API_NOT_FOUND) {
                return false;
            } else {
                $returnMessage = $e->hasResponse()
                    ? $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')'
                    : $e->getMessage();
                $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $returnMessage);
                return [['plugins.importexport.zenodo.api.error.publishCheckError', $e->getMessage()]];
            }
        }
        return false;
    }

    /**
     * Create a review request for a Zenodo record.
     * https://inveniordm.docs.cern.ch/reference/rest_api_reviews/#createupdate-a-review-request
     */
    public function createReview(int $zenodoId, string $communityName, string $url, string $apiKey): bool|array
    {
        $communityUrl = $url . $zenodoId . '/draft/review';
        $httpClient = Application::get()->getHttpClient();

        $communityHeaders = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        try {
            $httpClient->request(
                'PUT',
                $communityUrl,
                [
                    'headers' => $communityHeaders,
                    'json' => [
                        'receiver' => [
                            'community' => $communityName,
                        ],
                        'type' => 'community-submission'
                    ]
                ]
            );
        } catch (RequestException $e) {
            $returnMessage = $e->hasResponse()
                ? $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')'
                : $e->getMessage();
            return [['plugins.importexport.zenodo.api.error.createReviewError', $returnMessage]];
        }

        return true;
    }

    /**
     * Submit a review request to a Zenodo community.
     * Depending on the community's submission policy settings, the record may also be published.
     * https://inveniordm.docs.cern.ch/reference/rest_api_reviews/#submit-a-record-for-review
     */
    public function submitReview(int $zenodoId, string $url, string $apiKey): array|string
    {
        $submitUrl = $url . self::ZENODO_API_OPERATION . '/' . $zenodoId . '/draft/actions/submit-review';
        $httpClient = Application::get()->getHttpClient();

        $submitHeaders = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        try {
            $submitReviewResponse = $httpClient->request(
                'POST',
                $submitUrl,
                [
                    'headers' => $submitHeaders,
                    'json' => [
                        'payload' => [
                            'content' => 'This request was submitted from the OJS Zenodo plugin.',
                            'format' => 'html'
                        ],
                    ]
                ]
            );
            $body = json_decode($submitReviewResponse->getBody(), true);
            $requestId = $body['id'];
        } catch (RequestException $e) {
            $returnMessage = $e->hasResponse()
                ? $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')'
                : $e->getMessage();
            return [['plugins.importexport.zenodo.api.error.submitReviewError', $returnMessage]];
        }

        return $requestId;
    }

    /**
     * Accept a review request to a community. This will also publish the record.
     * https://inveniordm.docs.cern.ch/reference/rest_api_requests/#accept-a-request
     */
    public function acceptReview(string $requestId, string $url, string $apiKey): bool
    {
        $acceptUrl = $url . 'requests/' . $requestId . '/actions/accept';
        $httpClient = Application::get()->getHttpClient();

        $acceptHeaders = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        try {
            $httpClient->request(
                'POST',
                $acceptUrl,
                [
                    'headers' => $acceptHeaders,
                    'json' => [
                        'payload' => [
                            'content' => 'This request was accepted from the OJS Zenodo plugin.',
                            'format' => 'html'
                        ],
                    ]
                ]
            );
        } catch (RequestException $e) {
            $returnMessage = $e->hasResponse()
                ? $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')'
                : $e->getMessage();
            error_log(__('plugins.importexport.zenodo.api.error.acceptReviewError', ['param' => $returnMessage]));
            return false;
        }

        return true;
    }

    /**
     * Check if there is an open review request for a Zenodo record and get the
     * request ID if there is one.
     * @todo not yet in use until we determine how to get the request ID for a draft record.
     */
    public function getReviewRequest(int $zenodoId, string $url, string $apiKey): bool|string
    {
        $reviewUrl = $url . self::ZENODO_API_OPERATION . '/' . $zenodoId . '/requests';
        $httpClient = Application::get()->getHttpClient();

        $acceptHeaders = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        try {
            $reviewResponse = $httpClient->request(
                'GET',
                $reviewUrl,
                [
                    'headers' => $acceptHeaders,
                ]
            );
        } catch (GuzzleException | Exception $e) {
            $returnMessage = $e->hasResponse()
                ? $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')'
                : $e->getMessage();
            error_log(__('plugins.importexport.zenodo.api.error.reviewCheckError', ['param' => $returnMessage]));
            return false;
        }

        $body = json_decode($reviewResponse->getBody(), true);
        return $body['id'] ?? false;
    }

    /**
     * Cancel a review request to a community.
     * https://inveniordm.docs.cern.ch/reference/rest_api_requests/#cancel-a-request
     * @todo not yet in use until we can determine how to get the request ID for a draft.
     */
    public function cancelReviewRequest(string $requestId, string $url, string $apiKey): bool|array
    {
        $cancelUrl = $url . 'requests/' . $requestId . '/actions/cancel';
        $httpClient = Application::get()->getHttpClient();

        $acceptHeaders = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        try {
            $httpClient->request(
                'DELETE',
                $cancelUrl,
                [
                   'headers' => $acceptHeaders,
                ]
            );
        } catch (RequestException $e) {
            $returnMessage = $e->hasResponse()
                ? $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')'
                : $e->getMessage();
            return [['plugins.importexport.zenodo.api.error.reviewCancelError', $returnMessage]];
        }
        return true;
    }
}
