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
use APP\plugins\PubObjectsExportPlugin;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use PKP\context\Context;
use PKP\db\DAORegistry;
use PKP\filter\FilterDAO;
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
     * @param Submission $objects
     * @param Context $context
     * @param string $jsonString Export JSON string
     *
     * @return bool|array Whether the JSON string has been registered
     *
     * @see PubObjectsExportPlugin::depositXML()
     *
     */
    public function depositXML($objects, $context, $jsonString): bool|array /* @todo rename? */
    {
        $httpClient = Application::get()->getHttpClient();
        $apiKey = $this->getSetting($context->getId(), 'apiKey');

        $url = ($this->isTestMode($context) ? ZENODO_API_URL_DEV : ZENODO_API_URL) . ZENODO_API_OPERATION;
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        // @todo cleanup later
        // Laravel HTTP client example (would be missing)
        //$response = Http::withHeaders($headers)->withToken($apiKey)->post($url, $data);

        //error_log(print_r($jsonString, true));
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
        if (($status = $response->getStatusCode()) != ZENODO_API_DEPOSIT_OK) { //@TODO check zenodo status codes
            return [['plugins.importexport.zenodo.register.error.mdsError', $status . ' - ' . $response->getBody()]];
        }
        // Deposit was received; set the status
        $objects->setData($this->getDepositStatusSettingName(), PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED);
        $this->updateObject($objects);
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
            foreach ($objects as $object) { // @todo fix output formatting when multiple articles are selected
                // Get the JSON
                $exportJson = $this->exportJSON($object, $filter, $context);
                header('Content-Type: application/json');
                echo $exportJson;
            }
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
}
