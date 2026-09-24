<?php

/**
 * @file plugins/generic/zenodo/ZenodoExportPlugin.php
 *
 * Copyright (c) 2025-2026 Simon Fraser University
 * Copyright (c) 2025-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ZenodoExportPlugin
 *
 * @brief Zenodo export plugin
 */

namespace APP\plugins\generic\zenodo;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\zenodo\filter\ZenodoJsonFilter;
use APP\plugins\generic\zenodo\jobs\ZenodoDeposit;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Exception;
use GuzzleHttp\Exception\ConnectException;
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
use PKP\submission\Genre;
use PKP\submission\GenreDAO;
use PKP\submissionFile\SubmissionFile;
use Throwable;

class ZenodoExportPlugin extends PubObjectsExportPlugin implements HasTaskScheduler
{
    public const ZENODO_API_OK = 200;
    public const ZENODO_API_DEPOSIT_CREATED = 201;
    public const ZENODO_API_ACCEPTED = 202;
    public const ZENODO_API_NO_CONTENT = 204;
    public const ZENODO_API_NOT_FOUND = 404;
    public const ZENODO_API_GONE = 410;
    public const ZENODO_API_URL = 'https://zenodo.org/api/';
    public const ZENODO_API_URL_DEV = 'https://sandbox.zenodo.org/api/';
    public const ZENODO_API_OPERATION = 'records';
    public const REVIEW_OPEN = 'open';
    public const RECORD_DELETED = 'deleted';

    /**
     * Whether the last API failure was one a later attempt may get past (connection
     * refused, timeout, rate limit, server error) rather than a refusal of the request.
     */
    protected bool $lastFailureTransient = false;

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
     * @copydoc Plugin::getEncryptedSettingFields()
     */
    public function getEncryptedSettingFields(): array
    {
        return [
            'apiKey',
        ];
    }

    /**
     * @copydoc ImportExportPlugin::display()
     *
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
     * @copydoc PubObjectsExportPlugin::getDepositSuccessNotificationMessageKey()
     *
     * Deposits are queued rather than performed in the request, so the deposit action
     * reports that the records were submitted, not that they arrived.
     */
    public function getDepositSuccessNotificationMessageKey(): string
    {
        return 'plugins.importexport.zenodo.submit.success';
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
     * Queue the deposit of an object. The job builds the record and deposits it, so a
     * slow Zenodo never blocks the request, and records the outcome on the object.
     *
     * @return bool|array True when queued, or an error message
     */
    public function queueDeposit(Submission|Publication $object, Context $context): bool|array
    {
        if (!$this->getApiKey($context)) {
            return [['plugins.importexport.zenodo.register.error.noApiKey']];
        }

        dispatch(new ZenodoDeposit($object->getId(), $object instanceof Publication, $context->getId()));
        $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_SUBMITTED);

        return true;
    }

    /**
     * Deposit an object's record to Zenodo. Run from the queued ZenodoDeposit job.
     *
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
        $this->lastFailureTransient = false;

        $apiKey = $this->getApiKey($context);
        if (!$apiKey) {
            return [['plugins.importexport.zenodo.register.error.noApiKey']];
        }

        $preflightError = $this->validateRequiredMetadata($object)
            ?? $this->validateGalleys($object)
            ?? $this->validateDoi($object, $context);
        if ($preflightError) {
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $this->convertErrorMessage($preflightError));
            return [$preflightError];
        }

        $isPublication = $object instanceof Publication;

        $zenodoApiUrl = ($this->isTestMode($context) ? self::ZENODO_API_URL_DEV : self::ZENODO_API_URL);
        $recordsApiUrl = $zenodoApiUrl . self::ZENODO_API_OPERATION;

        $existingZenodoId = $object->getData($this->getIdSettingName()) ?: null;
        $isPublished = false;
        if ($existingZenodoId) {
            $isPublished = $this->isRecordPublished($object, $existingZenodoId, $recordsApiUrl);
            if (is_array($isPublished)) {
                // Don't continue if we can't check the published status.
                return $isPublished;
            }
            if ($isPublished === self::RECORD_DELETED) {
                // A record deleted in Zenodo leaves a tombstone that can not be updated;
                // its DOI is released, so the article is deposited as a new record.
                $this->removeZenodoId($object);
                $existingZenodoId = null;
                $isPublished = false;
            }
        }

        $zenodoId = $this->createOrUpdateDraft(
            $jsonString,
            $object,
            $recordsApiUrl,
            $apiKey,
            $isPublished,
            $existingZenodoId
        );
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

        // Files can not be changed on a published record. An existing draft keeps its
        // files between deposits, so they are replaced with the current galley files.
        if (!$isPublished) {
            if ($zenodoId === $existingZenodoId) {
                $filesDeleted = $this->deleteDraftFiles($object, $recordsApiUrl, $apiKey, $zenodoId);
                if (is_array($filesDeleted)) {
                    return $filesDeleted;
                }
            }
            $filesDeposit = $this->depositFiles($object, $recordsApiUrl, $apiKey, $zenodoId);
            if (is_array($filesDeposit)) {
                return $filesDeposit;
            }
        }

        // A draft under community review can neither be published directly nor have its
        // request replaced: Zenodo refuses both while the request is open. The request is
        // kept, and acceptance is what publishes the record.
        $communityId = $this->getCommunityId($context);
        $existingReview = null;
        if ($communityId) {
            $existingReview = $this->getReviewRequest($object, $zenodoId, $zenodoApiUrl, $apiKey, $isPublished);
            if (isset($existingReview['error'])) {
                return [$existingReview['error']];
            }
        }
        $hasOpenReview = !empty($existingReview['is_open']);

        // Publish based on settings or updating a previously published record.
        if (($this->automaticPublishing($context) && !$hasOpenReview) || $isPublished) {
            $published = $this->publishZenodoDraft($object, $zenodoId, $recordsApiUrl, $apiKey);
            if (is_array($published)) {
                return $published;
            } else {
                $isPublished = true;
            }
        }

        // Deposit was received; set the status.
        // If community submission fails, the record still exists, so we need to set the status for now.
        $object->setData($this->getDepositStatusSettingName(), PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED);
        $this->updateObject($object);

        // Submit the record to a community (record may be published depending on settings).
        if ($communityId) {
            $requestId = null;
            if ($isPublished) {
                // A record already in the community, or with an inclusion request pending, is not submitted again.
                if ($hasOpenReview) {
                    $requestId = $existingReview['id'];
                } else {
                    $inCommunity = $this->isRecordInCommunity($object, $zenodoId, $communityId, $recordsApiUrl, $apiKey);
                    if (is_array($inCommunity)) {
                        return $inCommunity;
                    }
                    if (!$inCommunity) {
                        $requestId = $this->submitReviewPublished($object, $zenodoId, $recordsApiUrl, $apiKey, $communityId);
                        if (is_array($requestId)) {
                            return $requestId;
                        }
                        if ($requestId === self::REVIEW_OPEN) {
                            // An inclusion request this plugin did not record is pending; only
                            // automatic acceptance is impossible without its id.
                            return $this->automaticPublishingCommunity($context)
                                ? [['plugins.importexport.zenodo.api.error.openReviewNotAccepted']]
                                : true;
                        }
                    }
                }
            } else {
                if ($hasOpenReview) {
                    $requestId = $existingReview['id'];
                } else {
                    $review = $this->createReview($object, $zenodoId, $communityId, $recordsApiUrl, $apiKey);
                    if (is_array($review)) {
                        return $review;
                    }
                    if ($review === self::REVIEW_OPEN) {
                        // Zenodo refused to replace a request this plugin could not see. The record
                        // is updated and the request stays open; only automatic acceptance is impossible.
                        return $this->automaticPublishingCommunity($context)
                            ? [['plugins.importexport.zenodo.api.error.openReviewNotAccepted']]
                            : true;
                    }
                    $requestId = $this->submitReview($object, $zenodoId, $zenodoApiUrl, $apiKey);
                    if (is_array($requestId)) {
                        return $requestId;
                    }
                }
            }
            if ($requestId) {
                $object->setData($this->getReviewRequestIdSettingName(), $requestId);
                $this->updateObject($object);
            }
            $autoPublishCommunity = $this->automaticPublishingCommunity($context);
            if ($autoPublishCommunity && $requestId) {
                $reviewAccepted = $this->acceptReview($object, $requestId, $zenodoApiUrl, $apiKey);
                if (is_array($reviewAccepted)) {
                    return $reviewAccepted;
                }
            }
        }

        return true;
    }

    /**
     * @copydoc PubObjectsExportPlugin::executeExportAction()
     *
     * @param null|mixed $noValidation
     *
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
            $resultErrors = [];
            foreach ($objects as $object) {
                $result = $this->queueDeposit($object, $context);
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
                        if (!is_array($error) || count($error) < 1) {
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
            // Redirect back to the right tab
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
     * @throws Exception
     *
     * @return string JSON variable.
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
     * Get the Zenodo record ID setting name.
     */
    public function getIdSettingName(): string
    {
        return $this->getPluginSettingsPrefix() . '::id';
    }

    /**
     * Get the community review request ID setting name.
     */
    public function getReviewRequestIdSettingName(): string
    {
        return $this->getPluginSettingsPrefix() . '::reviewRequestId';
    }

    /**
     * Get a list of additional setting names that should be stored with the objects.
     */
    public function getObjectAdditionalSettings(): array
    {
        return array_merge(parent::getObjectAdditionalSettings(), [
            $this->getIdSettingName(),
            $this->getReviewRequestIdSettingName(),
            $this->getDepositStatusSettingName()
        ]);
    }

    /**
     * Create a draft in Zenodo, or update the one this object already has.
     *
     * An unpublished draft is updated in place, which keeps its Zenodo id, any reserved
     * DOI and any open community review request. A draft that was removed in Zenodo is
     * replaced by a new one. A published record gets a new draft to carry the update.
     */
    protected function createOrUpdateDraft(
        string $json,
        Submission|Publication $object,
        string $url,
        string $apiKey,
        bool $isPublished = false,
        ?string $zenodoId = null
    ): string|array {
        $draftUrl = $url . '/' . $zenodoId . '/draft';

        if ($isPublished) {
            $publishDraft = $this->createDraftFromPublished($object, $url, $apiKey, $zenodoId);
            if (is_array($publishDraft)) {
                return $publishDraft;
            }
            return $this->sendDraft('PUT', $draftUrl, $json, $object, $apiKey);
        }

        if ($zenodoId) {
            $result = $this->sendDraft('PUT', $draftUrl, $json, $object, $apiKey, true);
            if ($result !== null) {
                return $result;
            }
            $this->removeZenodoId($object);
        }

        return $this->sendDraft('POST', $url, $json, $object, $apiKey);
    }

    /**
     * Send draft metadata to Zenodo and return the record id.
     *
     * @param bool $allowNotFound Return null when the draft no longer exists, instead of an error
     */
    protected function sendDraft(
        string $method,
        string $url,
        string $json,
        Submission|Publication $object,
        string $apiKey,
        bool $allowNotFound = false
    ): string|array|null {
        $httpClient = Application::get()->getHttpClient();
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/vnd.inveniordm.v1+json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        try {
            $response = $httpClient->request(
                $method,
                $url,
                [
                    'headers' => $headers,
                    'json' => json_decode($json),
                ]
            );
        } catch (RequestException $e) {
            // A draft removed in Zenodo answers 404, or 410 once its identifier is marked deleted.
            if ($allowNotFound && in_array($e->getCode(), [self::ZENODO_API_NOT_FOUND, self::ZENODO_API_GONE])) {
                return null;
            }
            $returnMessage = $this->getExceptionMessage($e);
            $errorMessage = __('plugins.importexport.zenodo.register.error.mdsError', ['param' => $returnMessage]);
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            return [['plugins.importexport.zenodo.register.error.mdsError', $e->getMessage()]];
        }

        $responseBody = json_decode($response->getBody());
        return (string) $responseBody->id;
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
        $url = $url . '/' . $zenodoId . '/draft';

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
            $returnMessage = $this->getExceptionMessage($e);
            $errorMessage = __('plugins.importexport.zenodo.register.error.draftPublishError', ['param' => $returnMessage]);
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
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
        string $zenodoId
    ): bool|array {
        $httpClient = Application::get()->getHttpClient();
        $filesMetadataUrl = $url . '/' . $zenodoId . '/draft/files';
        $fileService = app()->get('file');
        $filesDir = Config::getVar('files', 'files_dir');

        $metadataHeaders = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        $publication = $object instanceof Publication ? $object : $object->getCurrentPublication();
        $pubLocale = $publication->getData('locale');
        $usedKeys = [];

        foreach ($this->getDepositableGalleys($publication) as $galleyId => $submissionFile) {
            // File keys must be unique within the record; slashes would be read as a path.
            $fileName = $submissionFile->getLocalizedData('name', $pubLocale)
                ?: basename($fileService->get($submissionFile->getData('fileId'))->path);
            $fileName = str_replace('/', '_', $fileName);
            if (in_array($fileName, $usedKeys)) {
                $fileName = $galleyId . '_' . $fileName;
            }
            $usedKeys[] = $fileName;
            $encodedFileName = rawurlencode($fileName);

            // Initialize the file upload
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
                $returnMessage = $this->getExceptionMessage($e);
                $errorMessage = __('plugins.importexport.zenodo.api.error.fileError', ['param' => $returnMessage]);
                $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
                return [['plugins.importexport.zenodo.api.error.fileError', $e->getMessage()]];
            }

            // Upload the file contents
            $filePath = $filesDir . '/' . $fileService->get($submissionFile->getData('fileId'))->path;
            $filesFileUrl = $url . '/' . $zenodoId . '/draft/files/' . $encodedFileName . '/content';
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
                $returnMessage = $this->getExceptionMessage($e);
                $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $returnMessage);
                return [['plugins.importexport.zenodo.api.error.fileError', $e->getMessage()]];
            }

            // Commit the file upload
            $filesCommitUrl = $url . '/' . $zenodoId . '/draft/files/' . $encodedFileName . '/commit';
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
                $returnMessage = $this->getExceptionMessage($e);
                $errorMessage = __('plugins.importexport.zenodo.api.error.fileError', ['param' => $returnMessage]);
                $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
                return [['plugins.importexport.zenodo.api.error.fileError', $e->getMessage()]];
            }
        }
        return true;
    }

    /**
     * Check the metadata InvenioRDM requires before any request is made, so a missing
     * field is reported in one readable message rather than as the API's validation error.
     *
     * @return ?array [message key, missing fields] or null when nothing is missing
     */
    public function validateRequiredMetadata(Submission|Publication $object): ?array
    {
        $publication = $object instanceof Publication ? $object : $object->getCurrentPublication();
        $missing = [];

        if (!$publication?->getLocalizedData('title', $publication->getData('locale'))) {
            $missing[] = __('common.title');
        }
        if (collect($publication?->getData('authors') ?? [])->isEmpty()) {
            $missing[] = __('submission.authors');
        }
        $issueId = $publication?->getData('issueId');
        $issue = $issueId ? Repo::issue()->get($issueId) : null;
        if (!$publication?->getData('datePublished') && !$issue?->getDatePublished()) {
            $missing[] = __('publication.datePublished');
        }

        return empty($missing)
            ? null
            : ['plugins.importexport.zenodo.export.failure.missingMetadata', implode(', ', $missing)];
    }

    /**
     * Zenodo records must carry the article's full text, so a publication without an
     * article PDF is refused rather than deposited as a metadata-only record.
     *
     * @return ?array [message key] or null when an article PDF is present
     */
    public function validateGalleys(Submission|Publication $object): ?array
    {
        $publication = $object instanceof Publication ? $object : $object->getCurrentPublication();

        return $publication && $this->getArticlePdfFile($publication)
            ? null
            : ['plugins.importexport.zenodo.export.failure.noPdfGalley'];
    }

    /**
     * Find the article's full-text PDF among the galleys: a local galley in the
     * publication's locale holding a PDF whose genre is a primary document, so
     * supplementary and dependent files are not taken for the article.
     */
    public function getArticlePdfFile(Publication $publication): ?SubmissionFile
    {
        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var GenreDAO $genreDao */
        $locale = $publication->getData('locale');

        foreach ($publication->getData('galleys') ?? [] as $galley) { /** @var Galley $galley */
            if ($galley->getData('urlRemote') || $galley->getData('locale') !== $locale) {
                continue;
            }

            $submissionFileId = $galley->getData('submissionFileId');
            $galleyFile = $submissionFileId ? Repo::submissionFile()->get($submissionFileId) : null;
            if (!$galleyFile || $galleyFile->getData('mimetype') !== 'application/pdf') {
                continue;
            }

            $genre = $genreDao->getById($galleyFile->getData('genreId'));
            $isPrimaryDocument = $genre
                && $genre->getCategory() == Genre::GENRE_CATEGORY_DOCUMENT
                && !$genre->getSupplementary()
                && !$genre->getDependent();

            if ($isPrimaryDocument) {
                return $galleyFile;
            }
        }

        return null;
    }

    /**
     * Unless Zenodo is allowed to mint DOIs, a record without a DOI in OJS is refused.
     *
     * @return ?array [message key] or null when a DOI is present or will be minted
     */
    public function validateDoi(Submission|Publication $object, Context $context): ?array
    {
        if ($this->mintZenodoDois($context)) {
            return null;
        }

        $publication = $object instanceof Publication ? $object : $object->getCurrentPublication();
        return $publication?->getDoi()
            ? null
            : ['plugins.importexport.zenodo.api.error.noDoi'];
    }

    /**
     * Localize an error message stored as [message key, parameter].
     */
    public function convertErrorMessage(array $errorMessage): string
    {
        return __($errorMessage[0], ['param' => $errorMessage[1] ?? null]);
    }

    /**
     * Get the galley files that can be deposited, keyed by galley ID.
     * Galleys without a submission file (e.g. remote galleys) are skipped.
     *
     * @return array<int, SubmissionFile>
     */
    public function getDepositableGalleys(Publication $publication): array
    {
        $files = [];
        foreach ($publication->getData('galleys') ?? [] as $galley) { /** @var Galley $galley */
            $submissionFile = $galley->getData('submissionFileId')
                ? Repo::submissionFile()->get($galley->getData('submissionFileId'))
                : null;
            if ($submissionFile) {
                $files[$galley->getId()] = $submissionFile;
            }
        }
        return $files;
    }

    /**
     * Whether the last API failure was transient, so a queued deposit should be retried.
     */
    public function wasLastFailureTransient(): bool
    {
        return $this->lastFailureTransient;
    }

    /**
     * Whether a failure may get past on a later attempt: no connection or response, a
     * timeout, a rate limit or a server error. A 4xx refusal of the request is not.
     */
    public function isTransientFailure(Throwable $e): bool
    {
        if ($e instanceof ConnectException) {
            return true;
        }
        if ($e instanceof RequestException) {
            if (!$e->hasResponse()) {
                return true;
            }
            $status = $e->getResponse()->getStatusCode();
            return $status >= 500 || in_array($status, [408, 429]);
        }
        return false;
    }

    /**
     * Build an error message from an HTTP client exception, including the response when there is one.
     */
    protected function getExceptionMessage(Throwable $e): string
    {
        $this->lastFailureTransient = $this->isTransientFailure($e);
        if ($e instanceof RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            return $response->getBody() . ' (' . $response->getStatusCode() . ' ' . $response->getReasonPhrase() . ')';
        }
        return $e->getMessage();
    }

    /**
     * Remove the files of an existing draft so the current galley files can replace them.
     * https://inveniordm.docs.cern.ch/reference/rest_api_drafts_records/#delete-a-draft-file
     */
    protected function deleteDraftFiles(
        Submission|Publication $object,
        string $url,
        string $apiKey,
        string $zenodoId
    ): bool|array {
        $httpClient = Application::get()->getHttpClient();
        $filesUrl = $url . '/' . $zenodoId . '/draft/files';
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        try {
            $response = $httpClient->request('GET', $filesUrl, ['headers' => $headers]);
            $entries = json_decode($response->getBody(), true)['entries'] ?? [];
            foreach ($entries as $entry) {
                $httpClient->request('DELETE', $filesUrl . '/' . rawurlencode($entry['key']), ['headers' => $headers]);
            }
        } catch (RequestException $e) {
            $returnMessage = $this->getExceptionMessage($e);
            $errorMessage = __('plugins.importexport.zenodo.api.error.fileDeleteError', ['param' => $returnMessage]);
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            return [['plugins.importexport.zenodo.api.error.fileDeleteError', $e->getMessage()]];
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
            $returnMessage = $this->getExceptionMessage($e);
            error_log(__('plugins.importexport.zenodo.api.error.awardError', ['param' => $returnMessage]));
            return false;
        }
    }

    /**
     * Publish a Zenodo record.
     */
    public function publishZenodoDraft(
        Submission|Publication $object,
        string $zenodoId,
        string $url,
        string $apiKey
    ): string|array {
        $httpClient = Application::get()->getHttpClient();
        $publishUrl = $url . '/' . $zenodoId . '/draft/actions/publish';

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
            $returnMessage = $this->getExceptionMessage($e);
            $errorMessage = __('plugins.importexport.zenodo.api.error.publishError', ['param' => $returnMessage]);
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            return [['plugins.importexport.zenodo.api.error.publishError', $e->getMessage()]];
        }

        return true;
    }

    /**
     * Check if a Zenodo record has been published.
     */
    public function isRecordPublished(Submission|Publication $object, string $zenodoId, string $url): bool|string|array
    {
        $recordUrl = $url . '/' . $zenodoId;
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
            } elseif ($e->getCode() === self::ZENODO_API_GONE) {
                return self::RECORD_DELETED;
            } else {
                $returnMessage = $this->getExceptionMessage($e);
                $errorMessage = __('plugins.importexport.zenodo.api.error.publishCheckError', ['param' => $returnMessage]);
                $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
                return [['plugins.importexport.zenodo.api.error.publishCheckError', $e->getMessage()]];
            }
        }
        return false;
    }

    /**
     * Create a review request for a Zenodo record.
     * https://inveniordm.docs.cern.ch/reference/rest_api_reviews/#createupdate-a-review-request
     */
    public function createReview(
        Submission|Publication $object,
        string $zenodoId,
        string $communityId,
        string $url,
        string $apiKey
    ): bool|string|array {
        $communityUrl = $url . '/' . $zenodoId . '/draft/review';
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
                            'community' => $communityId,
                        ],
                        'type' => 'community-submission'
                    ]
                ]
            );
        } catch (RequestException $e) {
            $returnMessage = $this->getExceptionMessage($e);
            // "An open review cannot be deleted.": the draft already has an open request.
            if ($e->getCode() === 400 && stripos($returnMessage, 'open review') !== false) {
                return self::REVIEW_OPEN;
            }
            $errorMessage = __('plugins.importexport.zenodo.api.error.createReviewError', ['param' => $returnMessage]);
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            return [['plugins.importexport.zenodo.api.error.createReviewError', $returnMessage]];
        }

        return true;
    }

    /**
     * Submit a review request to a Zenodo community.
     * Depending on the community's submission policy settings, the record may also be published.
     * https://inveniordm.docs.cern.ch/reference/rest_api_reviews/#submit-a-record-for-review
     */
    public function submitReview(
        Submission|Publication $object,
        string $zenodoId,
        string $url,
        string $apiKey
    ): array|string {
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
            $returnMessage = $this->getExceptionMessage($e);
            $errorMessage = __('plugins.importexport.zenodo.api.error.submitReviewError', ['param' => $returnMessage]);
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            return [['plugins.importexport.zenodo.api.error.submitReviewError', $returnMessage]];
        }

        return $requestId;
    }

    /**
     * Submit a published record to a community.
     */
    public function submitReviewPublished(
        Submission|Publication $object,
        string $zenodoId,
        string $url,
        string $apiKey,
        string $communityId
    ): array|string|null {
        // Returns the inclusion request id; null when the record is already included;
        // REVIEW_OPEN when an inclusion request is already pending; or an error.
        $submitUrl = $url . '/' . $zenodoId . '/communities';
        $httpClient = Application::get()->getHttpClient();

        $submitHeaders = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ];

        try {
            $submitCommunityResponse = $httpClient->request(
                'POST',
                $submitUrl,
                [
                    'headers' => $submitHeaders,
                    'json' => [
                        'communities' => [
                            [
                                'id' => $communityId
                            ],
                        ],
                    ],
                ]
            );
            $body = json_decode($submitCommunityResponse->getBody(), true);
            $requestId = $body['processed'][0]['request_id'] ?? null;
        } catch (RequestException $e) {
            $returnMessage = $this->getExceptionMessage($e);
            if ($e->getCode() === 400) {
                // "There is already an open inclusion request for this community."
                if (stripos($returnMessage, 'open inclusion request') !== false) {
                    return self::REVIEW_OPEN;
                }
                // "The record is already included in this community."
                if (stripos($returnMessage, 'already included') !== false) {
                    return null;
                }
            }
            $errorMessage = __('plugins.importexport.zenodo.api.error.submitPublishedCommunityError', ['param' => $returnMessage]);
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            return [['plugins.importexport.zenodo.api.error.submitPublishedCommunityError', $returnMessage]];
        }

        return $requestId;
    }

    /**
     * Whether a published record is already included in a community, read from the
     * record's parent.
     *
     * @return bool|array Whether it is, or an error message when the record could not be read
     */
    public function isRecordInCommunity(
        Submission|Publication $object,
        string $zenodoId,
        string $communityId,
        string $url,
        string $apiKey
    ): bool|array {
        $httpClient = Application::get()->getHttpClient();
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        try {
            $response = $httpClient->request('GET', $url . '/' . $zenodoId, ['headers' => $headers]);
        } catch (RequestException $e) {
            $returnMessage = $this->getExceptionMessage($e);
            $errorMessage = __('plugins.importexport.zenodo.api.error.communityCheckError', ['param' => $returnMessage]);
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            return [['plugins.importexport.zenodo.api.error.communityCheckError', $returnMessage]];
        }

        $communities = json_decode($response->getBody(), true)['parent']['communities'] ?? [];
        $ids = $communities['ids'] ?? array_column($communities['entries'] ?? [], 'id');

        return in_array($communityId, $ids);
    }

    /**
     * Accept a review request to a community. This will also publish the record.
     * https://inveniordm.docs.cern.ch/reference/rest_api_requests/#accept-a-request
     */
    public function acceptReview(
        Submission|Publication $object,
        string $requestId,
        string $url,
        string $apiKey
    ): bool|array {
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
            $returnMessage = $this->getExceptionMessage($e);
            $errorMessage = __('plugins.importexport.zenodo.api.error.acceptReviewError', ['param' => $returnMessage]);
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            return [['plugins.importexport.zenodo.api.error.acceptReviewError', $returnMessage]];
        }

        return true;
    }

    /**
     * Get the community review request of a draft, if it has one.
     *
     * The request id stored when the plugin submitted it is looked up through the
     * requests API. Without a stored id, the draft record's parent is read instead.
     * Zenodo's own review endpoint (GET …/draft/review) answers 500 and is not used.
     * https://inveniordm.docs.cern.ch/reference/rest_api_requests/#get-a-request
     *
     * @param string $url The Zenodo API base URL
     *
     * @return ?array The request's id, status and whether it is open; null when the draft
     *  has no request; ['error' => [message key, detail]] when the check itself failed
     */
    public function getReviewRequest(
        Submission|Publication $object,
        string $zenodoId,
        string $url,
        string $apiKey,
        bool $isPublished = false
    ): ?array {
        $httpClient = Application::get()->getHttpClient();
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        $requestId = $object->getData($this->getReviewRequestIdSettingName());
        // A published record has no draft to read a request from.
        if (!$requestId && $isPublished) {
            return null;
        }
        $requestUrl = $requestId
            ? $url . 'requests/' . $requestId
            : $url . self::ZENODO_API_OPERATION . '/' . $zenodoId . '/draft';

        try {
            $response = $httpClient->request('GET', $requestUrl, ['headers' => $headers]);
        } catch (GuzzleException | Exception $e) {
            $returnMessage = $this->getExceptionMessage($e);
            $errorMessage = __('plugins.importexport.zenodo.api.error.reviewCheckError', ['param' => $returnMessage]);
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            return ['error' => ['plugins.importexport.zenodo.api.error.reviewCheckError', $returnMessage]];
        }

        $body = json_decode($response->getBody(), true);
        $review = $requestId ? $body : ($body['parent']['review'] ?? null);
        if (empty($review['id'])) {
            return null;
        }
        return [
            'id' => $review['id'],
            'status' => $review['status'] ?? null,
            'is_open' => $review['is_open'] ?? (($review['status'] ?? null) === 'submitted'),
        ];
    }

    /**
     * Cancel a review request to a community.
     * https://inveniordm.docs.cern.ch/reference/rest_api_requests/#cancel-a-request
     */
    public function cancelReviewRequest(string $requestId, string $url, string $apiKey): bool|array
    {
        $cancelUrl = $url . 'requests/' . $requestId . '/actions/cancel';
        $httpClient = Application::get()->getHttpClient();

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        try {
            $httpClient->request(
                'POST',
                $cancelUrl,
                [
                    'headers' => $headers,
                    'json' => [
                        'payload' => [
                            'content' => 'This request was cancelled from the OJS Zenodo plugin.',
                            'format' => 'html'
                        ],
                    ]
                ]
            );
        } catch (RequestException $e) {
            $returnMessage = $this->getExceptionMessage($e);
            return [['plugins.importexport.zenodo.api.error.reviewCancelError', $returnMessage]];
        }
        return true;
    }

    /**
     * Remove an object's Zenodo ID.
     */
    public function removeZenodoId(Submission|Publication $object): void
    {
        $object->setData($this->getIdSettingName(), null);
        $object->setData($this->getReviewRequestIdSettingName(), null);
        $this->updateObject($object);
        if ($object instanceof Publication) {
            $editParams = [
                $this->getIdSettingName() => null,
                $this->getReviewRequestIdSettingName() => null,
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
    }

    /**
     * @copydoc ImportExportPlugin::executeCLI()
     */
    public function executeCLI($scriptName, &$args)
    {
    }

    /**
     * @copydoc ImportExportPlugin::usage()
     */
    public function usage($scriptName)
    {
    }

    /**
     * @copydoc ImportExportPlugin::supportsCLI()
     */
    public function supportsCLI(): bool
    {
        return false;
    }
}
