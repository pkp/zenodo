<?php

/**
 * @file plugins/importexport/zenodo/ZenodoInfoSender.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ZenodoInfoSender
 *
 * @brief Scheduled task to send deposits to Zenodo.
 */

namespace APP\plugins\importexport\zenodo;

use APP\core\Application;
use APP\journal\Journal;
use Exception;
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
     */
    public function executeActions(): bool
    {
        if (!$this->plugin) {
            return false;
        }

        $plugin = $this->plugin;
        $journals = $this->getJournals();

        foreach ($journals as $journal) {
            // load pubIds for this journal
            PluginRegistry::loadCategory('pubIds', true, $journal->getId());
            // Get unregistered articles
            $unregisteredArticles = $plugin->getUnregisteredArticles($journal);
            // If there are articles to be deposited
            if (count($unregisteredArticles)) {
                $this->registerObjects($unregisteredArticles, 'article=>zenodo-json', $journal);
            }
        }

        return true;
    }

    /**
     * Get all journals that meet the requirements to have
     * their articles automatically sent to Zenodo.
     */
    public function getJournals(): array
    {
        $plugin = $this->plugin;
        $contextDao = Application::getContextDAO();
        $journalFactory = $contextDao->getAll(true);

        $journals = [];
        while ($journal = $journalFactory->next()) {
            $journalId = $journal->getId();
            if (
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
     * Register objects
     */
    public function registerObjects(array $objects, string $filter, Journal $journal): void
    {
        $plugin = $this->plugin;
        foreach ($objects as $object) {
            // Get the JSON
            $exportJson = $plugin->exportJSON($object, $filter, $journal);
            // Deposit the JSON
            $result = $plugin->depositXML($object, $journal, $exportJson);
            if ($result !== true) {
                $this->addLogEntry($result);
            }
        }
    }

    /**
     * Add execution log entry
     * @throws Exception
     */
    public function addLogEntry(array $errors): void
    {
        if (is_array($errors)) {
            foreach ($errors as $error) {
                assert(is_array($error) && count($error) >= 1);
                $this->addExecutionLogEntry(
                    __($error[0], ['param' => $error[1] ?? null]),
                    ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING
                );
            }
        }
    }
}
