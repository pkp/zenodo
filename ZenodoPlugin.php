<?php

/**
 * @file plugins/generic/zenodo/ZenodoPlugin.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class ZenodoPlugin
 *
 * @brief Plugin to export articles to Zenodo.
 *
 */

namespace APP\plugins\generic\zenodo;

use APP\plugins\PubObjectsExportGenericPlugin;
use PKP\plugins\PluginRegistry;

class ZenodoPlugin extends PubObjectsExportGenericPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        return parent::register($category, $path, $mainContextId);
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.zenodo.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.zenodo.description');
    }

    protected function setExportPlugin(): void
    {
        PluginRegistry::register('importexport', new ZenodoExportPlugin(), $this->getPluginPath());
        $this->exportPlugin = PluginRegistry::getPlugin('importexport', 'ZenodoExportPlugin');
    }

    /**
     * @copydoc Plugin::getContextSpecificPluginSettingsFile()
     */
    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * @copydoc Plugin::getInstallSitePluginSettingsFile()
     */
    public function getInstallSitePluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }
}
