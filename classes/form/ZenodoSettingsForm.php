<?php

/**
 * @file plugins/importexport/zenodo/classes/form/ZenodoSettingsForm.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ZenodoSettingsForm
 *
 * @brief Form for journal managers to set up the Zenodo plugin
 */

namespace APP\plugins\importexport\zenodo\classes\form;

use APP\core\Application;
use APP\plugins\importexport\zenodo\ZenodoExportPlugin;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;
use PKP\plugins\Plugin;

class ZenodoSettingsForm extends Form
{
    public int $contextId;
    public Plugin $plugin;

    /**
     * Get the context ID.
     */
    public function getContextId(): int
    {
        return $this->contextId;
    }

    /**
     * Get the plugin.
     */
    public function getPlugin(): Plugin
    {
        return $this->plugin;
    }

    /**
     * Constructor
     *
     * @param Plugin $plugin
     * @param int $contextId
     */
    public function __construct($plugin, $contextId)
    {
        $this->contextId = $contextId;
        $this->plugin = $plugin;

        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        // Add form validation checks.
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'community',
            'optional',
            'plugins.importexport.zenodo.register.error.communityError',
            function ($community) {
                $communityId = $this->getCommunityId($community, $this->contextId, $this->plugin);
                if (is_array($communityId)) {
                    error_log(__($communityId[0], ['param' => $communityId[1]]));
                    return false;
                }
                if ($communityId) {
                    return true;
                }
                return false;
            }
        ));
    }

    //
    // Implement template methods from Form.
    //
    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        $contextId = $this->getContextId();
        $plugin = $this->getPlugin();
        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $this->setData($fieldName, $plugin->getSetting($contextId, $fieldName));
        }
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars(array_keys($this->getFormFields()));
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs): void
    {
        $plugin = $this->getPlugin();
        $contextId = $this->getContextId();
        parent::execute(...$functionArgs);
        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $plugin->updateSetting($contextId, $fieldName, $this->getData($fieldName), $fieldType);
            // reset the communityId if the community field is empty.
            if ($fieldName == 'community') {
                if (
                    $this->getData($fieldName) == ''
                    && $plugin->getSetting($contextId, 'communityId') != ''
                ) {
                    $plugin->updateSetting($contextId, 'communityId', '', 'string');
                }
            }
        }
    }


    //
    // Public helper methods.
    //
    /**
     * Get form fields.
     *
     * @return array (field name => field type)
     */
    public function getFormFields(): array
    {
        return [
            'apiKey' => 'string',
            'community' => 'string',
            'automaticRegistration' => 'bool',
            'automaticPublishing' => 'bool',
            'testMode' => 'bool',
            'mintDoi' => 'bool',
        ];
    }

    /**
     * If the form field is optional.
     */
    public function isOptional(string $settingName): bool
    {
        return in_array($settingName, [
            'apiKey',
            'community',
            'automaticRegistration',
            'automaticPublishing',
            'testMode',
            'mintDoi'
        ]);
    }

    /*
    * Find and store the community ID in Zenodo, which we need to send records to a community.
    */
    public function getCommunityId(string $communityName, int $contextId, Plugin $plugin): array|bool
    {
        $contextDao = Application::getContextDAO();
        $context = $contextDao->getById($contextId);
        $httpClient = Application::get()->getHttpClient();
        $url = $plugin->isTestMode($context)
            ? ZenodoExportPlugin::ZENODO_API_URL_DEV
            : ZenodoExportPlugin::ZENODO_API_URL;
        $communityUrl = $url . 'communities/' . $communityName;

        try {
            $response = $httpClient->request(
                'GET',
                $communityUrl
            );
            $body = json_decode($response->getBody(), true);

            if ($response->getStatusCode() === ZenodoExportPlugin::ZENODO_API_OK) {
                if ($body['id']) {
                    $plugin->updateSetting($contextId, 'communityId', $body['id'], 'string');
                    return true;
                } else {
                    return [
                        'plugins.importexport.zenodo.api.error.communityIdError',
                        'No community ID found in the Zenodo API response.'
                    ];
                }
            }
        } catch (GuzzleException | Exception $e) {
            return [
                'plugins.importexport.zenodo.api.error.communityIdError',
                $e->getCode() . ' - ' . $e->getMessage()
            ];
        }
        return false;
    }
}
