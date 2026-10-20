<?php

/**
 * @file plugins/generic/zenodo/ZenodoPlugin.php
 *
 * Copyright (c) 2025-2026 Simon Fraser University
 * Copyright (c) 2025-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ZenodoPlugin
 *
 * @brief Plugin to export articles to Zenodo.
 *
 */

namespace APP\plugins\generic\zenodo;

use APP\plugins\generic\zenodo\controllers\grid\ZenodoPublicationsListGridHandler;
use APP\plugins\generic\zenodo\controllers\grid\ZenodoSubmissionsListGridHandler;
use APP\plugins\PubObjectsExportGenericPlugin;
use PKP\plugins\Hook;
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
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadComponentHandler', $this->setupGridHandler(...));
        }
        return $success;
    }

    /**
     * Serve the export grids with the plugin's Zenodo record column.
     */
    public function setupGridHandler(string $hookName, array $params): bool
    {
        $component = &$params[0];
        $componentInstance = &$params[2];
        $handlerClass = match ($component) {
            'plugins.generic.zenodo.controllers.grid.ZenodoPublicationsListGridHandler' => ZenodoPublicationsListGridHandler::class,
            'plugins.generic.zenodo.controllers.grid.ZenodoSubmissionsListGridHandler' => ZenodoSubmissionsListGridHandler::class,
            default => null,
        };
        if (!$handlerClass) {
            return false;
        }
        $componentInstance = new $handlerClass();
        return true;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.zenodo.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
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
