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
use APP\issue\Issue;
use APP\plugins\importexport\zenodo\ZenodoExportDeployment;
use APP\plugins\importexport\zenodo\ZenodoExportPlugin;
use APP\submission\Submission;
use Carbon\Carbon;
use Exception;
use PKP\controlledVocab\ControlledVocab;
use PKP\core\PKPString;
use PKP\filter\FilterGroup;
use PKP\i18n\LocaleConversion;
use PKP\plugins\importexport\PKPImportExportFilter;

class ZenodoJsonFilter extends PKPImportExportFilter
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
     * @param Submission $pubObject
     *
     * @return string JSON
     * @throws Exception
     * @see Filter::process()
     *
     */
    public function &process(&$pubObject)
    {
        /** @var ZenodoExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        /** @var ZenodoExportPlugin $plugin */
        $plugin = $deployment->getPlugin();
        $cache = $plugin->getCache();

        // Create the JSON string
        // https://developers.zenodo.org/#depositions
        // https://github.com/zenodo/zenodo/blob/master/zenodo/modules/deposit/jsonschemas/deposits/records/legacyrecord.json

        $publication = $pubObject->getCurrentPublication();
        $publicationLocale = $publication->getData('locale');

        $issueId = $publication->getData('issueId');
        if ($cache->isCached('issues', $issueId)) {
            $issue = $cache->get('issues', $issueId); /** @var Issue $issue */
        } else {
            $issue = Repo::issue()->get($issueId);
            $issue = $issue->getJournalId() == $context->getId() ? $issue : null;
            if ($issue) {
                $cache->add($issue, null);
            }
        }

        $article = [];
        $article['metadata'] = [];

        // see https://developers.zenodo.org/#representation

        // Upload and publication type
        $uploadType = 'publication';
        $article['metadata']['upload_type'] = $uploadType;

        $publicationType = 'article'; // or book, preprint
        $article['metadata']['publication_type'] = $publicationType;

        // Year and month from the article's publication date
        // publication_date in YYYY-MM-DD @todo Carbon?
        $publicationDate = Carbon::parse($issue->getDatePublished());
        if ($publication->getData('datePublished')) {
            $publicationDate = Carbon::parse($publication->getData('datePublished'));
        }
        $article['metadata']['publication_date'] = $publicationDate->format('Y-m-d');

        // Article title
        $article['metadata']['title'] = $publication?->getLocalizedTitle($publicationLocale) ?? '';

        // Authors: name, affiliation and ORCID
        $articleAuthors = $publication->getData('authors');
        if ($articleAuthors->isNotEmpty()) {
            $article['metadata']['creators'] = [];

            foreach ($articleAuthors as $articleAuthor) {
                $author = ['name' => $articleAuthor->getFullName(false, false, $publicationLocale)];
                $affiliations = $articleAuthor->getLocalizedAffiliationNamesAsString($publicationLocale);
                if (!empty($affiliations)) {
                    $author['affiliation'] = $affiliations;
                }
                if ($articleAuthor->getData('orcid') && $articleAuthor->getData('orcidIsVerified')) {
                    $author['orcid'] = $articleAuthor->getData('orcid');
                }
                $article['metadata']['creators'][] = $author;
            }
        }

        // Abstract
        $abstract = $publication->getData('abstract', $publicationLocale);
        if (!empty($abstract)) {
            $article['metadata']['description'] = PKPString::html2text($abstract);
        }

        // @todo
        // options: open, embargoed, restricted, closed
        // $article['metadata']['access_right'] = 'open';

        // @todo if access_right = open or embargoed
        // options: https://developers.zenodo.org/#licenses
        // $article['metadata']['license'] = 'cc-by'

        // @todo if access_right = embargoed
        // $article['metadata']['embargo_date'] = 'cc-by'

        // @todo if access_right = restricted (may not be applicable for this plugin)
        // free text string
        // $article['metadata']['access_conditions'] = '';

        // DOI
        // @todo option to pre-reserve DOI, probably not needed for this use case of already published materials?
        $mintDoi = $plugin->getSetting($context->getId(), 'mint_doi');
        if (!$mintDoi) {
            $doi = $publication->getDoi();
            if (!empty($doi)) {
                $article['metadata']['doi'] = $doi;
            } else {
                error_log('Warning: DOI is empty');
                // minting new DOI in Zenodo - end execution?
            }
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

        // @todo
        // array of objects
        //$article['metadata']['related_identifiers'] = [];

        // Contributors
        // @todo check if needed - most roles not applicable to publications context
        // array of objects
        // type: Contributor type. Controlled vocabulary (ContactPerson, DataCollector, DataCurator, DataManager,
        // Distributor, Editor, HostingInstitution, Producer, ProjectLeader, ProjectManager, ProjectMember,
        // RegistrationAgency, RegistrationAuthority, RelatedPerson, Researcher, ResearchGroup, RightsHolder,
        // Supervisor, Sponsor, WorkPackageLeader, Other)
        // $article['metadata']['contributors'] = [];

        // References
        // @todo once structured citations are in place
        // This will also become part of related works (using "Cites" relation)
        // array of strings
        // Example: ["Doe J (2014). Title. Publisher. DOI", "Smith J (2014). Title. Publisher. DOI"]
        // $article['metadata']['references'] = [];

        // Zenodo community
        // causes 500 error in sandbox API
        // $community = $plugin->getSetting($context->getId(), 'community');
        // if ($community) {
        //     $article['metadata']['communities'] = $community;
        // }

        // @todo later funding metadata
        // array of objects
        // only some funders are supported by Zenodo (based on DOI prefix) - see docs for list
        // $supportedFunders = [];
        // $article['metadata']['grants'] = [];

        // Journal title
        $journalTitle = $context->getName($context->getPrimaryLocale());
        $article['metadata']['journal_title'] = $journalTitle;

        // Volume, Number
        $volume = $issue->getVolume();
        if (!empty($volume)) {
            $article['metadata']['journal_volume'] = (string)$volume;
        }

        $issueNumber = $issue->getNumber();
        if (!empty($issueNumber)) {
            $article['metadata']['journal_issue'] = $issueNumber; //@todo check if this is the correct field
        }

        // Pages
        $startPage = $publication->getStartingPage();
        $endPage = $publication->getEndingPage();
        if (isset($startPage) && $startPage !== '') {
            $article['metadata']['journal_pages'] = $startPage . '-' . $endPage;
        }

        // @todo conference metadata?

        // Publisher name
        $publisher = $context->getData('publisherInstitution');
        if (!empty($publisher)) {
            $article['metadata']['imprint_publisher'] = $publisher; //
        }

        // @todo subjects only with a proper controlled vocabulary
        // array of objects
        // Specify subjects from a taxonomy or controlled vocabulary. Each term must be uniquely identified (e.g. a URL). For free form text, use the keywords field. Each array element is an object with the attributes:
        //* term: Term from taxonomy or controlled vocabulary.
        //* identifier: Unique identifier for term.
        //* scheme: Persistent identifier scheme for id (automatically detected).
        //
        // Example: [{"term": "Astronomy", "identifier": "http://id.loc.gov/authorities/subjects/sh85009003", "scheme": "url"}]

        // Publication version
        $version = $publication->getData('version');
        if ($version) {
            $article['metadata']['version'] = (string)$version;
        }

        // Language (ISO 639-2 or 639-3)
        $language = LocaleConversion::get3LetterFrom2LetterIsoLanguage($publicationLocale);
        if ($language) {
            $article['metadata']['language'] = $language;
        }

        //        // FullText URL
        //        $request = Application::get()->getRequest();
        //        $article['metadata']['link'] = [];
        //        $article['metadata']['link'][] = [
        //            'url' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'article', 'view', [$pubObject->getId()], urlLocaleForPage: ''),
        //            'type' => 'fulltext',
        //            'content_type' => 'html'
        //        ];

        // @todo remove later
        $prettyJson = json_encode($article, JSON_PRETTY_PRINT);
        error_log(print_r($prettyJson, true));

        $json = json_encode($article);
        return $json;
    }

    public function uploadFiles()
    {
        // @todo
    }
}
