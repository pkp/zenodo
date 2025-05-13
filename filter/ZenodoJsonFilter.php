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
use Illuminate\Support\Facades\DB;
use PKP\controlledVocab\ControlledVocab;
use PKP\core\PKPString;
use PKP\filter\FilterGroup;
use PKP\i18n\LocaleConversion;
use PKP\plugins\importexport\PKPImportExportFilter;
use PKP\plugins\PluginRegistry;

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

        $submissionId = $pubObject->getId();
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
        // $article['metadata']['embargo_date'] = ''

        // @todo if access_right = restricted (may not be applicable for this plugin)
        // free text string
        // $article['metadata']['access_conditions'] = '';

        // DOI
        // @todo handle DOI settings
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

        // Related identifiers - FullText URL
        $request = Application::get()->getRequest();
        $article['metadata']['related_identifiers'][] = [
            'relation' => 'isIdenticalTo',
            'identifier' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'article', 'view', [$submissionId], urlLocaleForPage: ''),
            'resource_type' => 'publication',
        ];

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
        // This will also be added to related identifiers above (using "Cites" relation)
        // array of strings
        // Example: ["Doe J (2014). Title. Publisher. DOI", "Smith J (2014). Title. Publisher. DOI"]
        // $article['metadata']['references'] = [];

        // Zenodo community
        // @todo Confirm this is working on Zenodo side; consider option for multiple communities
        $community = $plugin->getSetting($context->getId(), 'community');
        if ($community) {
            $article['metadata']['communities'][] = ['identifier' => $community];
        }

        // @todo funding metadata
        // currently failing in zenodo api
        $fundingMetadata = $this->fundingMetadata($submissionId);
        //        if ($fundingMetadata) {
        //            $article['metadata']['grants'] = $fundingMetadata;
        //        }

        // Journal title
        $journalTitle = $context->getName($context->getPrimaryLocale());
        $article['metadata']['journal_title'] = $journalTitle;

        // Volume
        $volume = $issue->getVolume();
        if (!empty($volume)) {
            $article['metadata']['journal_volume'] = (string)$volume;
        }

        // Issue Number
        $issueNumber = $issue->getNumber();
        if (!empty($issueNumber)) {
            $article['metadata']['journal_issue'] = $issueNumber;
        }

        // Pages
        $startPage = $publication->getStartingPage();
        $endPage = $publication->getEndingPage();
        if (isset($startPage) && $startPage !== '') {
            $article['metadata']['journal_pages'] = $startPage . '-' . $endPage;
        }

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

        // @todo remove later
        $prettyJson = json_encode($article, JSON_PRETTY_PRINT);
        error_log(print_r($prettyJson, true));

        $json = json_encode($article, JSON_UNESCAPED_SLASHES);
        return $json;
    }

    /*
     * Helper function for funding metadata
     */
    private function fundingMetadata(int $submissionId): false|array
    {
        // @todo temporarily removed this check for plugin development
        // if (!PluginRegistry::getPlugin('generic', 'FundingPlugin')) {
        //     return false;
        // }

        $validFunders = false;

        $funderIds = DB::table('funders')
            ->where('submission_id', $submissionId)
            ->pluck('funder_identification', 'funder_id');

        if (!$funderIds->isEmpty()) {
            foreach ($funderIds as $funderId => $funderIdentification) {
                if ($this->isValidFunder($funderIdentification)) {
                    $validFunders = true;
                } else {
                    continue;
                }

                $awardIds = DB::table('funder_awards')
                    ->where('funder_id', $funderId)
                    ->pluck('funder_award_number');

                foreach ($awardIds as $awardId) {
                    $fundData[] = [
                        'id' => str_replace('https://doi.org/', '', $funderIdentification) . '::' . $awardId
                    ];
                }
            }

            if (!$validFunders) {
                return false;
            }
        }

        return $fundData ?? false;
    }

    /*
     * Helper function for checking if a funder is supported in Zenodo
     */
    private function isValidFunder(string $funder): bool
    {
        $validFunders = [
            'https://doi.org/10.13039/501100002341', // Academy of Finland
            'https://doi.org/10.13039/501100001665', // Agence Nationale de la Recherche
            'https://doi.org/10.13039/100018231',    // Aligning Science Across Parkinson’s
            'https://doi.org/10.13039/501100000923', // Australian Research Council
            'https://doi.org/10.13039/501100002428', // Austrian Science Fund
            'https://doi.org/10.13039/501100000024', // Canadian Institutes of Health Research
            'https://doi.org/10.13039/501100000780', // European Commission
            'https://doi.org/10.13039/501100000806', // European Environment Agency
            'https://doi.org/10.13039/501100001871', // Fundação para a Ciência e a Tecnologia
            'https://doi.org/10.13039/501100004488', // Hrvatska Zaklada za Znanost
            'https://doi.org/10.13039/501100006364', // Institut National Du Cancer
            'https://doi.org/10.13039/501100004564', // Ministarstvo Prosvete, Nauke i Tehnološkog Razvoja
            'https://doi.org/10.13039/501100006588', // Ministarstvo Znanosti, Obrazovanja i Sporta
            'https://doi.org/10.13039/501100000925', // National Health and Medical Research Council
            'https://doi.org/10.13039/100000002',    // National Institutes of Health
            'https://doi.org/10.13039/100000001',    // National Science Foundation
            'https://doi.org/10.13039/501100000038', // Natural Sciences and Engineering Research Council of Canada
            'https://doi.org/10.13039/501100003246', // Nederlandse Organisatie voor Wetenschappelijk Onderzoek
            'https://doi.org/10.13039/501100000690', // Research Councils
            'https://doi.org/10.13039/501100001711', // Schweizerischer Nationalfonds zur Förderung der wissenschaftlichen Forschung
            'https://doi.org/10.13039/501100001602', // Science Foundation Ireland
            'https://doi.org/10.13039/100001345',    // Social Science Research Council
            'https://doi.org/10.13039/501100011730', // Templeton World Charity Foundation
            'https://doi.org/10.13039/501100004410', // Türkiye Bilimsel ve Teknolojik Araştırma Kurumu
            'https://doi.org/10.13039/100014013',    // UK Research and Innovation
            'https://doi.org/10.13039/100004440',    // Wellcome Trust
        ];

        return in_array($funder, $validFunders);
    }

    public function uploadFiles()
    {
        // @todo
    }
}
