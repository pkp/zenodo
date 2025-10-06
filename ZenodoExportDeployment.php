<?php

/**
 * @file plugins/generic/zenodo/ZenodoExportDeployment.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ZenodoExportDeployment
 *
 * @brief Base class configuring the Zenodo export process to an
 * application's specifics.
 */

namespace APP\plugins\generic\zenodo;

use APP\plugins\PubObjectCache;
use PKP\context\Context;
use PKP\plugins\Plugin;

class ZenodoExportDeployment
{
    public Context $context;
    public Plugin $plugin;

    /**
     * Get the plugin cache
     */
    public function getCache(): PubObjectCache
    {
        return $this->plugin->getCache();
    }

    /**
     * Constructor
     *
     */
    public function __construct(Context $context, ZenodoExportPlugin $plugin)
    {
        $this->setContext($context);
        $this->setPlugin($plugin);
    }

    //
    // Deployment items for subclasses to override
    //
    /**
     * Get the root element name
     */
    public function getRootElementName(): string
    {
        return 'metadata';
    }

    //
    // Getter/setters
    //
    /**
     * Set the import/export context.
     */
    public function setContext(Context $context): void
    {
        $this->context = $context;
    }

    /**
     * Get the import/export context.
     */
    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * Set the import/export plugin.
     */
    public function setPlugin(Plugin $plugin): void
    {
        $this->plugin = $plugin;
    }

    /**
     * Get the import/export plugin.
     */
    public function getPlugin(): Plugin
    {
        return $this->plugin;
    }
}
