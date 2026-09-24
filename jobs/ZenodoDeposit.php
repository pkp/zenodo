<?php

/**
 * @file plugins/generic/zenodo/jobs/ZenodoDeposit.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ZenodoDeposit
 *
 * @ingroup jobs
 *
 * @brief Build an object's InvenioRDM record and deposit it, with its galley files, to Zenodo.
 */

namespace APP\plugins\generic\zenodo\jobs;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\zenodo\ZenodoExportPlugin;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use PKP\job\exceptions\JobException;
use PKP\jobs\BaseJob;
use PKP\plugins\PluginRegistry;
use Throwable;

class ZenodoDeposit extends BaseJob
{
    /**
     * A deposit is several API calls plus one upload per galley file, so allow
     * more than the default minute.
     */
    public int $timeout = 300;

    public function __construct(
        protected int $objectId,
        protected bool $isPublication,
        protected int $contextId
    ) {
        parent::__construct();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $plugin = $this->registerPlugin();
        $object = $this->getObject();

        if (!$object) {
            throw new JobException(JobException::INVALID_PAYLOAD);
        }

        // Queuing a deposit marks the object submitted, so a status still reading
        // "registered" here means an earlier attempt of this job already deposited it.
        if ($object->getData($plugin->getDepositStatusSettingName()) === PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED) {
            return;
        }

        // The journal can be removed while deposits for it are still queued. This would
        // fail the same way on every attempt, so record it and stop rather than retry.
        $context = Application::getContextDAO()->getById($this->contextId);
        if (!$context) {
            $plugin->updateStatus(
                $object,
                PubObjectsExportPlugin::EXPORT_STATUS_ERROR,
                $plugin->convertErrorMessage(['plugins.importexport.zenodo.export.failure.journalNotFound'])
            );
            return;
        }

        $filter = $object instanceof Publication ? 'publication=>zenodo-json' : 'article=>zenodo-json';
        $json = $plugin->exportJSON($object, $filter, $context);

        $result = $plugin->depositXML($object, $context, $json);
        if ($result === true) {
            return;
        }

        // The deposit has recorded its failure. A refused connection, a timeout or a rate
        // limit may well succeed on a later attempt, so those are thrown for the queue to retry.
        $errorMessage = $plugin->convertErrorMessage($result[0]);
        if ($plugin->wasLastFailureTransient()) {
            throw new JobException($errorMessage);
        }
        if ($object->getData($plugin->getDepositStatusSettingName()) !== PubObjectsExportPlugin::EXPORT_STATUS_ERROR) {
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
        }
    }

    /**
     * Record a deposit that failed for a reason handle() could not report itself -- a
     * galley file that could not be read, an unexpected exception, a timeout -- once no
     * attempts remain. Without this the object would keep the "submitted" status it
     * was queued with, which the automatic deposit task does not pick up again.
     */
    public function failed(Throwable $exception): void
    {
        $plugin = $this->registerPlugin();
        $object = $this->getObject();

        if (
            !$object ||
            $object->getData($plugin->getDepositStatusSettingName()) === PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED
        ) {
            return;
        }

        $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $exception->getMessage());
    }

    /**
     * Register the export plugin, and hand back whichever instance the registry holds.
     *
     * This has to happen before anything loads a submission or publication: registering
     * is what adds the plugin's deposit status fields to the schema, and the schema is
     * only built once per process.
     */
    protected function registerPlugin(): ZenodoExportPlugin
    {
        PluginRegistry::register(
            'importexport',
            new ZenodoExportPlugin(),
            'plugins/generic/zenodo',
            $this->contextId
        );

        /** @var ZenodoExportPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('importexport', 'ZenodoExportPlugin');
        $plugin->addLocaleData();

        return $plugin;
    }

    /**
     * Load the object this deposit was queued for.
     */
    protected function getObject(): Submission|Publication|null
    {
        return $this->isPublication
            ? Repo::publication()->get($this->objectId)
            : Repo::submission()->get($this->objectId);
    }
}
