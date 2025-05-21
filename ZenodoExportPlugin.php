<?php

/**
 * @file plugins/importexport/zenodo/ZenodoExportPlugin.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ZenodoExportPlugin
 *
 * @brief Zenodo export plugin
 */

namespace APP\plugins\importexport\zenodo;

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\plugins\PubObjectsExportPlugin;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7;
use PKP\config\Config;
use PKP\context\Context;
use PKP\db\DAORegistry;
use PKP\filter\FilterDAO;
use PKP\galley\Galley;
use PKP\notification\Notification;

define('ZENODO_API_DEPOSIT_OK', 201);
define('ZENODO_API_URL', 'https://zenodo.org/api/');
define('ZENODO_API_URL_DEV', 'https://sandbox.zenodo.org/api/');
define('ZENODO_API_OPERATION', 'deposit/depositions');

class ZenodoExportPlugin extends PubObjectsExportPlugin
{
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
     * @copydoc PubObjectsExportPlugin::getExportActions()
     */
    public function getExportActions($context): array
    {
        $actions = [PubObjectsExportPlugin::EXPORT_ACTION_EXPORT, PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED];
        if ($this->getSetting($context->getId(), 'apiKey')) {
            array_unshift($actions, PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT);
        }
        return $actions;
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportDeploymentClassName()
     */
    public function getExportDeploymentClassName(): string
    {
        return '\APP\plugins\importexport\zenodo\ZenodoExportDeployment';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getSettingsFormClassName()
     */
    public function getSettingsFormClassName(): string
    {
        return '\APP\plugins\importexport\zenodo\classes\form\ZenodoSettingsForm';
    }

    /**
     * @param Submission $object
     * @param Context $context
     * @param string $jsonString Export JSON string
     *
     * @return bool|array Whether the JSON string has been registered
     *
     * @see PubObjectsExportPlugin::depositXML()
     *
     */
    public function depositXML($object, $context, $jsonString): bool|array /* @todo rename? */
    {
        $httpClient = Application::get()->getHttpClient();
        $apiKey = $this->getSetting($context->getId(), 'apiKey');

        $url = ($this->isTestMode($context) ? ZENODO_API_URL_DEV : ZENODO_API_URL) . ZENODO_API_OPERATION;
        $mintDoi = $this->mintZenodoDois($context);
        if (!$mintDoi && !$object->getCurrentPublication()->getDoi()) {
            return [['plugins.importexport.zenodo.register.error.noDoi']];
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        try {
            $response = $httpClient->request(
                'POST',
                $url,
                [
                    'headers' => $headers,
                    'json' => json_decode($jsonString),
                ]
            );
        } catch (GuzzleException | Exception $e) {
            error_log('exception catch');
            error_log($e->getMessage());
            return [['plugins.importexport.zenodo.register.error.mdsError', $e->getMessage()]];
        }

        $responseBody = json_decode($response->getBody());
        $zenodoRecId = $responseBody->id;

        if (($status = $response->getStatusCode()) != ZENODO_API_DEPOSIT_OK) { //@TODO check zenodo status codes
            return [['plugins.importexport.zenodo.register.error.mdsError', $status . ' - ' . $response->getBody()]];
        }

        // if the submission has files
        $this->depositFiles($object, $zenodoRecId, $url, $apiKey);

        // Deposit was received; set the status
        $object->setData($this->getDepositStatusSettingName(), PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED);
        $object->setData($this->getIdSettingName(), $zenodoRecId);
        $this->updateObject($object);
        return true;
    }

    /**
     * @copydoc PubObjectsExportPlugin::executeExportAction()
     *
     * @param null|mixed $noValidation
     * @throws Exception
     */
    public function executeExportAction($request, $objects, $filter, $tab, $objectsFileNamePart, $noValidation = true, $shouldRedirect = true): void
    {
        $context = $request->getContext();
        $path = ['plugin', $this->getName()];
        if ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT)) {
            assert($filter != null);
            // Set filter for JSON
            $filter = 'article=>zenodo-json';
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
                        assert(is_array($error) && count($error) >= 1);
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
            assert($filter != null);
            // Set filter for JSON
            $filter = 'article=>zenodo-json';
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
        } else { // @todo remove?
            parent::executeExportAction($request, $objects, $filter, $tab, $objectsFileNamePart, $noValidation);
        }
    }

    /**
     * Get the JSON for selected objects.
     *
     * @return string JSON variable.
     * @throws Exception
     */
    public function exportJSON(Submission $object, string $filter, Context $context): string
    {
        $filterDao = DAORegistry::getDAO('FilterDAO'); /** @var FilterDAO $filterDao */
        $exportFilters = $filterDao->getObjectsByGroup($filter);
        assert(count($exportFilters) == 1); // Assert only a single serialization filter
        $exportFilter = array_shift($exportFilters);
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
     * Get Zenodo ID setting name.
     */
    public function getIdSettingName(): string
    {
        return $this->getPluginSettingsPrefix() . '::id';
    }

    /**
     * Get a list of additional setting names that should be stored with the objects.
     */
    protected function _getObjectAdditionalSettings(): array
    {
        return [$this->getDepositStatusSettingName(), $this->getIdSettingName()];
    }

    /*
     * Send files to the Zenodo API (see https://developers.zenodo.org/#deposition-files)
     */
    protected function depositFiles(Submission $object, int $zenodoRecId, string $url, string $apiKey): bool|array
    {
        $httpClient = Application::get()->getHttpClient();
        $filesUrl = $url . '/' . $zenodoRecId . '/files';
        $fileService = Services::get('file');
        $filesDir = Config::getVar('files', 'files_dir');

        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        $publication = $object->getCurrentPublication();
        $pubLocale = $publication->getData('locale');

        foreach ($publication->getData('galleys') as $galley) { /** @var Galley $galley */
            $submissionFile = Repo::submissionFile()->get($galley->getData('submissionFileId'));
            if (!$submissionFile) {
                continue;
            }

            $fileName = $submissionFile->getData('name', $pubLocale);
            $filePath = $filesDir . '/' . $fileService->get($submissionFile->getData('fileId'))->path;

            // @todo possible to turn into a single request with multiple files to reduce API calls?
            try {
                $response = $httpClient->request(
                    'POST',
                    $filesUrl,
                    [
                        'headers' => $headers,
                        'multipart' => [
                            [
                                'name' => 'name',
                                'contents' => $fileName,
                            ],
                            [
                                'name' => 'file',
                                'contents' => Psr7\Utils::tryFopen($filePath, 'r'),
                            ],
                        ],
                    ],
                );
            } catch (GuzzleException | Exception $e) {
                return [['plugins.importexport.zenodo.register.error.fileError', $e->getMessage()]];
            }

            if (($status = $response->getStatusCode()) != ZENODO_API_DEPOSIT_OK) {
                return [['plugins.importexport.zenodo.register.error.fileError', $status . ' - ' . $response->getBody()]];
            }
        }
        return true;
    }
}
