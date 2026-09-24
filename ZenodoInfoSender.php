<?php

/**
 * @file plugins/generic/zenodo/ZenodoInfoSender.php
 *
 * Copyright (c) 2025-2026 Simon Fraser University
 * Copyright (c) 2025-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ZenodoInfoSender
 *
 * @brief Scheduled task to send deposits to Zenodo.
 */

namespace APP\plugins\generic\zenodo;

use APP\core\Application;
use APP\journal\Journal;
use APP\publication\Publication;
use APP\submission\Submission;
use Exception;
use PKP\context\Context;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;

class ZenodoInfoSender extends ScheduledTask
{
    public ZenodoExportPlugin $plugin;

    /**
     * Constructor.
     */
    public function __construct(array $args = [])
    {
        PluginRegistry::loadCategory('importexport');

        /** @var ZenodoExportPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('importexport', 'ZenodoExportPlugin');
        $this->plugin = $plugin;

        if ($plugin instanceof ZenodoExportPlugin) {
            $plugin->addLocaleData();
        }

        parent::__construct($args);
    }

    /**
     * @copydoc ScheduledTask::getName()
     */
    public function getName(): string
    {
        return __('plugins.importexport.zenodo.senderTask.name');
    }

    /**
     * @copydoc ScheduledTask::executeActions()
     *
     * @throws Exception
     */
    public function executeActions(): bool
    {
        if (!$this->plugin) {
            return false;
        }

        $plugin = $this->plugin;
        $journals = $this->getJournals();

        foreach ($journals as $journal) {
            $depositableObjects = $journal->getData(Context::SETTING_DOI_VERSIONING)
                ? $plugin->getAllDepositablePublications($journal)
                : $plugin->getAllDepositableArticles($journal);
            if (count($depositableObjects)) {
                $this->registerObjects($depositableObjects, $journal);
            }
        }

        return true;
    }

    /**
     * Get all journals that meet the requirements to have
     * their articles automatically sent to Zenodo.
     *
     * @throws Exception
     *
     * @return array<Journal>
     */
    protected function getJournals(): array
    {
        $plugin = $this->plugin;
        PluginRegistry::loadCategory('generic');
        $genericPlugin = PluginRegistry::getPlugin('generic', 'zenodoplugin');
        $contextDao = Application::getContextDAO();
        $journalFactory = $contextDao->getAll(true);

        $journals = [];
        while ($journal = $journalFactory->next()) { /** @var Journal $journal */
            $journalId = $journal->getId();
            if (
                ($genericPlugin && !$genericPlugin->getEnabled($journalId)) ||
                !$plugin->getSetting($journalId, 'apiKey') ||
                !$plugin->getSetting($journalId, 'automaticRegistration')
            ) {
                continue;
            }
            $journals[] = $journal;
        }
        return $journals;
    }


    /**
     * Queue the deposit of articles or publications
     *
     * @param array<Submission|Publication> $objects
     *
     * @throws Exception
     */
    protected function registerObjects(array $objects, Journal $journal): void
    {
        $plugin = $this->plugin;
        foreach ($objects as $object) {
            $result = $plugin->queueDeposit($object, $journal);
            if ($result !== true) {
                $this->addLogEntry($result);
            }
        }
    }

    /**
     * Add execution log entry
     *
     * @throws Exception
     */
    protected function addLogEntry(array $errors): void
    {
        foreach ($errors as $error) {
            if (!is_array($error) || count($error) < 1) {
                throw new Exception('Invalid error message');
            }
            $this->addExecutionLogEntry(
                __($error[0], ['param' => $error[1] ?? null]),
                ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING
            );
        }
    }
}
