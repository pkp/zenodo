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

use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;
use PKP\plugins\Plugin;

class ZenodoSettingsForm extends Form
{
    //
    // Private properties
    //
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

    //
    // Constructor
    //
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
    }

    //
    // Implement template methods from Form
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
        }
    }


    //
    // Public helper methods
    //
    /**
     * Get form fields
     *
     * @return array (field name => field type)
     */
    public function getFormFields(): array
    {
        return [
            'apiKey' => 'string',
            'automaticRegistration' => 'bool',
            'testMode' => 'bool',
            'mintDOI' => 'bool', // @TODO proper implementation
            //'community' => 'string' // @TODO for submitting all articles to a zenodo community
        ];
    }

    /**
     * If the form field is optional
     */
    public function isOptional(string $settingName): bool
    {
        return in_array($settingName, ['apiKey', 'automaticRegistration', 'testMode', 'mintDOI']);
    }
}
