<?php

/**
 * @file plugins/importexport/zenodo/filter/ZenodoJsonFilter.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ZenodoJsonFilter
 *
 * @ingroup plugins_importexport_zenodo
 *
 * @brief Class that converts an Article to a Zenodo JSON string.
 */

namespace APP\plugins\importexport\zenodo\filter;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\importexport\zenodo\ZenodoExportDeployment;
use APP\plugins\importexport\zenodo\ZenodoExportPlugin;
use APP\submission\Submission;
use PKP\controlledVocab\ControlledVocab;
use PKP\core\PKPString;
use PKP\filter\FilterGroup;
use PKP\filter\PersistableFilter;
use PKP\plugins\importexport\native\filter\NativeExportFilter;
use PKP\plugins\importexport\PKPImportExportFilter;

class ZenodoJsonFilter extends PKPImportExportFilter //PersistableFilter // // NativeExportFilter
{
    /**
     * Constructor
     *
     * @param FilterGroup $filterGroup
     */
    public function __construct($filterGroup)
    {
        $this->setDisplayName('Zenodo JSON export');
        parent::__construct($filterGroup);
    }

    /**
     * Set no validation option
     */
    public function setNoValidation(bool $noValidation): void
    {
        $this->noValidation = $noValidation;
    }

    //
    // Implement template methods from Filter
    //
    /**
     * @see Filter::process()
     *
     * @param Submission $pubObject
     *
     * @return string JSON
     */
    public function &process(&$pubObject)
    {
        /** @var ZenodoExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        /** @var ZenodoExportPlugin $plugin */
        $plugin = $deployment->getPlugin();
        $cache = $plugin->getCache();

        $mintDoi = $plugin->getSetting($context->getId(), 'mint_doi');
        $community = $plugin->getSetting($context->getId(), 'community');

        $application = Application::get();
        error_log(print_r($application, true));

        // see https://developers.zenodo.org/#representation
        $uploadType = 'publication';
        $publicationType = 'article'; // or book, preprint

        // Create the JSON string
        // https://developers.zenodo.org/#depositions
        // https://github.com/zenodo/zenodo/blob/master/zenodo/modules/deposit/jsonschemas/deposits/records/legacyrecord.json
        //$data[] = null;

        $publication = $pubObject->getCurrentPublication();
        $publicationLocale = $publication->getData('locale');

        $issueId = $publication->getData('issueId');
        if ($cache->isCached('issues', $issueId)) {
            $issue = $cache->get('issues', $issueId);
        } else {
            $issue = Repo::issue()->get($issueId);
            $issue = $issue->getJournalId() == $context->getId() ? $issue : null;
            if ($issue) {
                $cache->add($issue, null);
            }
        }

        $article = [];
        $article['metadata'] = [];
        // Publisher name (i.e. institution name)
        $publisher = $context->getData('publisherInstitution');
        if (!empty($publisher)) {
            $article['metadata']['publisher'] = $publisher;
        }

        // Journal's title (M)
        $journalTitle = $context->getName($context->getPrimaryLocale());
        $article['metadata']['journal_title'] = $journalTitle;

        // Upload and publication type
        $article['metadata']['upload_type'] = $uploadType;
        $article['metadata']['publication_type'] = $publicationType;

        // Volume, Number
        $volume = $issue->getVolume();
        if (!empty($volume)) {
            $article['metadata']['journal_volume'] = $volume;
        }
        $issueNumber = $issue->getNumber();
        if (!empty($issueNumber)) {
            $article['metadata']['number'] = $issueNumber;
        }

        // Article title
        $article['metadata']['title'] = $publication?->getLocalizedTitle($publicationLocale) ?? '';

        // Identifiers
        $article['metadata']['identifier'] = [];

        // DOI
        if (!$mintDoi) {
            $doi = $publication->getDoi();
            if (!empty($doi)) {
                $article['metadata']['identifier'][] = ['type' => 'doi', 'id' => $doi];
            } else {
                error_log('Warning: DOI is empty');
                // minting new DOI in Zenodo
            }
        }

        // Identification Numbers
        $issns = [];
        $pissn = $context->getData('printIssn');
        if (!empty($pissn)) {
            $issns[] = $pissn;
        }
        $eissn = $context->getData('onlineIssn');
        if (!empty($eissn)) {
            $issns[] = $eissn;
        }
        if (!empty($issns)) {
            $article['metadata']['issns'] = $issns;
        }

        // Print and online ISSN
        if (!empty($pissn)) {
            $article['metadata']['identifier'][] = ['type' => 'pissn', 'id' => $pissn];
        }
        if (!empty($eissn)) {
            $article['metadata']['identifier'][] = ['type' => 'eissn', 'id' => $eissn];
        }

        // Year and month from the article's publication date


        // publication_date in YYYY-MM-DD @todo Carbon?
        $publicationDate = $this->formatDate($issue->getDatePublished());
        if ($publication->getData('datePublished')) {
            $publicationDate = $this->formatDate($publication->getData('datePublished'));
        }
        $yearMonth = explode('-', $publicationDate);
        $article['metadata']['year'] = $yearMonth[0];
        $article['metadata']['month'] = $yearMonth[1];
        $article['metadata']['publication_date'] = $publicationDate;
        /** --- FirstPage / LastPage (from PubMed plugin)---
         * there is some ambiguity for online journals as to what
         * "page numbers" are; for example, some journals (eg. JMIR)
         * use the "e-location ID" as the "page numbers" in PubMed
         */
        //            $startPage = $publication->getStartingPage();
        //            $endPage = $publication->getEndingPage();
        //            if (isset($startPage) && $startPage !== '') {
        //                $article['metadata']['start_page'] = $startPage;
        //                $article['metadata']['end_page'] = $endPage;
        //            }
        // FullText URL
        //            $request = Application::get()->getRequest();
        //            $article['metadata']['link'] = [];
        //            $article['metadata']['link'][] = [
        //                'url' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'article', 'view', [$pubObject->getId()], urlLocaleForPage: ''),
        //                'type' => 'fulltext',
        //                'content_type' => 'html'
        //            ];

        // Authors: name, affiliation and ORCID
        $articleAuthors = $publication->getData('authors');
        if ($articleAuthors->isNotEmpty()) {
            $article['metadata']['author'] = [];

            foreach ($articleAuthors as $articleAuthor) {
                $author = ['name' => $articleAuthor->getFullName(false, false, $publicationLocale)];
                $affiliations = $articleAuthor->getLocalizedAffiliationNamesAsString($publicationLocale);
                if (!empty($affiliations)) {
                    $author['affiliations'] = $affiliations;
                }
                if ($articleAuthor->getData('orcid') && $articleAuthor->getData('orcidIsVerified')) {
                    $author['orcid_id'] = $articleAuthor->getData('orcid');
                }
                $article['metadata']['author'][] = $author;
            }
        }

        // Abstract
        $abstract = $publication->getData('abstract', $publicationLocale);
        if (!empty($abstract)) {
            $article['metadata']['description'] = PKPString::html2text($abstract);
        }

        // Keywords
        $keywords = Repo::controlledVocab()->getBySymbolic(
            ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
            Application::ASSOC_TYPE_PUBLICATION,
            $publication->getId(),
            [$publicationLocale]
        );

        $allowedNoOfKeywords = array_slice($keywords[$publicationLocale] ?? [], 0, 6);
        if (!empty($keywords[$publicationLocale])) {
            $article['metadata']['keywords'] = $allowedNoOfKeywords;
        }

        // @todo later funding metadata

        // @todo remove later
        $prettyJson = json_encode($article, JSON_PRETTY_PRINT);
        error_log(print_r($prettyJson, true));

        $json = json_encode($article);
        return $json;
    }

    /**
     * Format a date to Y-M-D format.
     */
    public function formatDate(string $date): ?string
    {
        if ($date == '') {
            return null;
        }
        return date('Y-M-D', strtotime($date));
    }
}
