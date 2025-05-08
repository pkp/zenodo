<?php

/**
 * @file plugins/importexport/zenodo/ZenodoExportDeployment.php
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

namespace APP\plugins\importexport\zenodo;

use APP\plugins\importexport\zenodo\ZenodoExportPlugin;
use APP\plugins\PubObjectCache;
use PKP\context\Context;
use PKP\plugins\Plugin;

class ZenodoExportDeployment
{
    /** @var Context The current import/export context */
    public Context $context;

    /** @var Plugin The current import/export plugin */
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
     * @param Context $context
     * @param ZenodoExportPlugin $plugin
     */
    public function __construct($context, $plugin)
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
        return 'records';
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
