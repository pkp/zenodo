<?php

/**
 * @file plugins/generic/zenodo/ZenodoInfoSender.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
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
            // load pubIds for this journal
            PluginRegistry::loadCategory('pubIds', true, $journal->getId());
            if ($journal->getData(Context::SETTING_DOI_VERSIONING)) {
                $depositablePublications = $plugin->getAllDepositablePublications($journal);
                if (count($depositablePublications)) {
                    $this->registerObjects($depositablePublications, 'publication=>zenodo-json', $journal);
                }
            } else {
                $depositableArticles = $plugin->getAllDepositableArticles($journal);
                if (count($depositableArticles)) {
                    $this->registerObjects($depositableArticles, 'article=>zenodo-json', $journal);
                }
            }
        }

        return true;
    }

    /**
     * Get all journals that meet the requirements to have
     * their articles automatically sent to Zenodo.
     *
     * @return array<Journal>
     * @throws Exception
     */
    protected function getJournals(): array
    {
        $plugin = $this->plugin;
        $contextDao = Application::getContextDAO();
        $journalFactory = $contextDao->getAll(true);

        $journals = [];
        while ($journal = $journalFactory->next()) { /** @var  Journal $journal */
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
     * Register articles or publications
     *
     * @param array<Submission|Publication> $objects
     * @throws Exception
     */
    protected function registerObjects(array $objects, string $filter, Journal $journal): void
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
    protected function addLogEntry(array $errors): void
    {
        foreach ($errors as $error) {
            if (!is_array($error) || !count($error) > 0) {
                throw new Exception('Invalid error message');
            };
            $this->addExecutionLogEntry(
                __($error[0], ['param' => $error[1] ?? null]),
                ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING
            );
        }
    }
}
