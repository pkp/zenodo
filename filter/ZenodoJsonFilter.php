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

use APP\author\Author;
use APP\core\Application;
use APP\decision\Decision;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\importexport\zenodo\ZenodoExportDeployment;
use APP\plugins\importexport\zenodo\ZenodoExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use PKP\affiliation\Affiliation;
use PKP\citation\CitationDAO;
use PKP\context\Context;
use PKP\core\PKPString;
use PKP\db\DAORegistry;
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

        $submissionId = $pubObject->getId();
        $publication = $pubObject->getCurrentPublication();
        $publicationId = $publication->getId();
        $publicationLocale = $publication->getData('locale');

        $issueId = $publication->getData('issueId');
        if ($cache->isCached('issues', $issueId)) {
            $issue = $cache->get('issues', $issueId);
            /** @var Issue $issue */
        } else {
            $issue = Repo::issue()->get($issueId);
            $issue = $issue->getJournalId() == $context->getId() ? $issue : null;
            if ($issue) {
                $cache->add($issue, null);
            }
        }

        $article = [];

        // Access Rights
        $status = 'open';
        $fileAccess = 'public';

        if ($issue) {
            if (
                $context->getData('publishingMode') == Journal::PUBLISHING_MODE_SUBSCRIPTION &&
                $issue->getAccessStatus() == Issue::ISSUE_ACCESS_SUBSCRIPTION
            ) {
                $status = $issue->getOpenAccessDate() ? 'embargoed' : 'metadata-only';
                $fileAccess = 'restricted';
            }
        }

        $article['access'] = [
            'files' => $fileAccess,
            'record' => 'public', // only files can be restricted
            'status' => $status,
        ];

        if ($issue && $status == 'embargoed') {
            $openAccessDate = Carbon::parse($issue->getOpenAccessDate());
            $article['access']['embargo']['active'] = 'true';
            $article['access']['embargo']['until'] = $openAccessDate->format('Y-m-d');
        }

        // Journal Metadata
        $journalData = $this->getJournalData($context, $publication, $issue);
        $article['custom_fields'] = ['journal:journal' => $journalData];

        $article['metadata'] = [];

        // Resource type
        $article['metadata']['resource_type'] = [
            'id' => 'publication-article',
        ];

        // Article title
        if ($publication->getLocalizedTitle($publicationLocale)) {
            $article['metadata']['title'] = $publication->getLocalizedTitle($publicationLocale);
        }

        // Authors: name, affiliations and ORCID
        if ($publication->getData('authors')->isNotEmpty()) {
            $authorsData = $this->getAuthorsData($publication, $publicationLocale);
            $article['metadata']['creators'] = $authorsData;
        }

        // Abstract
        $abstract = $publication->getData('abstract', $publicationLocale);
        if (!empty($abstract)) {
            $article['metadata']['description'] = PKPString::html2text($abstract);
        }

        // Publication date
        if ($publication->getData('datePublished')) {
            $article['metadata']['publication_date'] = Carbon::parse($publication->getData('datePublished'))->format('Y-m-d');
        } elseif ($issue->getDatePublished()) {
            $article['metadata']['publication_date'] = Carbon::parse($issue->getDatePublished())->format('Y-m-d');
        }

        // Publisher name
        if (!empty($context->getData('publisherInstitution'))) {
            $article['metadata']['publisher'] = $context->getData('publisherInstitution');
        }

        // References
        $citationDao = DAORegistry::getDAO('CitationDAO');
        /** @var CitationDAO $citationDao */
        $rawCitations = $citationDao->getRawCitationsByPublicationId($publicationId)->toArray();
        if ($rawCitations) {
            foreach ($rawCitations as $rawCitation) {
                $article['metadata']['references'][] = [
                    'reference' => $rawCitation
                ];
            }
        }

        // Related Identifiers
        // Schemes: https://inveniordm-dev.docs.cern.ch/reference/metadata/#identifier-schemes
        // Types: https://github.com/inveniosoftware/invenio-rdm-records/blob/master/invenio_rdm_records/fixtures/data/vocabularies/relation_types.yaml

        // FullText URL relation
        $request = Application::get()->getRequest();
        $url = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'article',
            'view',
            [$submissionId],
            urlLocaleForPage: ''
        );
        $article['metadata']['related_identifiers'][] = [
            'identifier' => $url,
            'relation_type' => [
                'id' => 'isidenticalto'
            ],
            'scheme' => 'url',
        ];

        // Cites relation
        // @todo once structured citations are available add related identifiers using "Cites" relation
        // $article['metadata']['related_identifiers'][] = [
        //     'identifier' => '',
        //     'relation_type' => [
        //         'id' => 'cites',
        //     ],
        //     'scheme' => 'doi',
        // ];

        // Version relation
        // DOI versioning is only supported for Zenodo DOIs
        // @todo once DOI versioning is implemented, add relations for all version DOIs
        // $article['metadata']['related_identifiers'][] = [
        //     'relation_type' => [
        //         'id' => 'isVersionOf'
        //     ],
        //     'identifier' => '', // DOI
        //     'scheme' => 'doi',
        // ];

        // Other Subjects? @todo
        // Keywords
        $keywords = $publication->getData('keywords', $publicationLocale);
        if (!empty($keywords)) {
            $keywordsMeta = [];
            foreach ($keywords as $keyword) {
                $keywordsMeta[] = [
                    'subject' => $keyword,
                ];
            }
            $article['metadata']['subjects'] = $keywordsMeta;
        }

        // Funding metadata
        // @todo re-test if validation of award and conversion to ROR is needed in new API
        $fundingMetadata = $this->fundingMetadata($submissionId);
        if ($fundingMetadata) {
            $article['metadata']['funding'] = $fundingMetadata;
        }

        // Publication version
        $versionMajor = (string)$publication->getData('versionMajor');
        $versionMinor = (string)$publication->getData('versionMinor');
        if (!is_null($versionMajor) && !is_null($versionMinor)) {
            $article['metadata']['version'] = $versionMajor . '.' . $versionMinor;
        }

        // Language (ISO 639-3)
        // @todo multilingual? e.g. what if galleys are in multiple languages?
        $language = LocaleConversion::getIso3FromLocale($publicationLocale);
        if ($language) {
            $article['metadata']['languages'][] = [
                'id' => $language,
            ];
        }

        // Copyright statement
        if ($publication->getData('copyrightHolder', $publicationLocale) && $publication->getData('copyrightYear')) {
            $copyrightHolder = $publication->getData('copyrightHolder', $publicationLocale);
            $copyrightYear = $publication->getData('copyrightYear');
            $article['metadata']['copyright'] = __('submission.copyrightStatement', [
                'copyrightHolder' => $copyrightHolder,
                'copyrightYear' => $copyrightYear
            ]);
        };

        // License @todo check for restricted data
        $licenseUrl = $publication->getData('licenseUrl') ?? $context->getData('licenseUrl') ?? '';
        if (preg_match('/creativecommons\.org\/licenses\/(.*?)\/([\d.]+)\/?$/i', $licenseUrl, $match)) {
            $article['metadata']['rights'][] = [
                'id' => 'cc-' . $match[1] . '-' . $match[2],
            ];
        }

        // Dates
        // For an exact date, use the same value for both start and end.
        // Options: accepted, available, collected, copyrighted, created, issued,
        //          other, submitted, updated, valid, withdrawn.

        $editorDecision = Repo::decision()->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->getMany()
            ->first(fn (Decision $decision, $key) => $decision->getData('decision') === Decision::ACCEPT);

        if ($editorDecision) {
            $decisionDate = Carbon::parse($editorDecision->getData('dateDecided'));
            $article['metadata']['dates'][] = [
                'date' => $decisionDate->format('Y-m-d'),
                'type' => [
                    'id' => 'accepted',
                    'title' => [
                        'en' => 'Accepted',
                    ]
                ],
                'description' => 'Acceptance date',
            ];
        }

        // DOI
        $doi = $publication->getDoi();
        if (!empty($doi)) {
            $article['pids'] =
                [
                    'doi' => [
                        'provider' => 'external',
                        'identifier' => $doi
                    ],
                ];
        }

        $json = json_encode($article, JSON_UNESCAPED_SLASHES);
        return $json;
    }

    /*
     * Helper function for journal metadata
     */
    private function getJournalData(Context $context, Publication $publication, ?Issue $issue = null): array
    {
        $journalData = [];

        // Journal title
        $journalTitle = $context->getName($context->getPrimaryLocale());
        $journalData['title'] = $journalTitle;

        // ISSN
        if ($context->getData('onlineIssn') != '') {
            $journalData['issn'] = $context->getData('onlineIssn');
        } elseif ($context->getData('issn') != '') {
            $journalData['issn']  = $context->getData('issn');
        } elseif ($context->getData('printIssn') != '') {
            $journalData['issn']  = $context->getData('printIssn');
        }

        if ($issue) {
            // Volume
            $volume = $issue->getVolume();
            if (!empty($volume)) {
                $journalData['volume'] = (string)$volume;
            }

            // Issue Number
            $issueNumber = $issue->getNumber();
            if (!empty($issueNumber)) {
                $journalData['issue'] = $issueNumber;
            }
        }

        // Pages
        $startPage = $publication->getStartingPage();
        $endPage = $publication->getEndingPage();
        if (isset($startPage) && $startPage !== '') {
            $journalData['pages'] = $startPage . '-' . $endPage;
        }

        return $journalData;
    }

    /*
     * Helper function for authors metadata
     */
    private function getAuthorsData(Publication $publication, string $publicationLocale): array
    {
        $articleAuthors = $publication->getData('authors');
        $authorsData = [];

        foreach ($articleAuthors as $articleAuthor) {
            /** @var Author $author */
            $author = [];

            if ($articleAuthor->getGivenName($publicationLocale)) {
                $author['given_name'] = $articleAuthor->getGivenName($publicationLocale);
            }

            if ($articleAuthor->getFamilyName($publicationLocale)) {
                $author['family_name'] = $articleAuthor->getFamilyName($publicationLocale);
            }

            $author['type'] = 'personal';

            if ($articleAuthor->getOrcid() && $articleAuthor->hasVerifiedOrcid()) {
                $author['identifiers'] = [
                    'identifier' => $articleAuthor->getOrcid(),
                    'scheme' => 'orcid',
                ];
            }

            $affiliations = $articleAuthor->getAffiliations($publicationLocale);
            if (count($affiliations) > 0) {
                $affiliationsData = [];
                foreach ($affiliations as $affiliation) { /** @var Affiliation $affiliation */
                    if ($affiliation->getRor()) {
                        $affiliationsData[] = [
                            'id' => str_replace('https://ror.org/', '', $affiliation->getRor()),
                            'name' => $affiliation->getAffiliationName($publicationLocale),
                        ];
                    } elseif ($affiliation->getAffiliationName($publicationLocale)) {
                        $affiliationsData[] = [
                            'name' => $affiliation->getAffiliationName($publicationLocale),
                        ];
                    }
                }
                $authorsData[] = [
                    'person_or_org' => $author,
                    'affiliations' => $affiliationsData
                ];
            } else {
                $authorsData[] = ['person_or_org' => $author];
            }
        }
        return $authorsData;
    }

    /*
     * Helper function for funding metadata
     */
    private function fundingMetadata(int $submissionId): false|array
    {
        /** @var ZenodoExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        /** @var ZenodoExportPlugin $plugin */
        $plugin = $deployment->getPlugin();

        if (!PluginRegistry::getPlugin('generic', 'FundingPlugin')) {
            return false;
        }

        $funderIds = DB::table('funders')
            ->where('submission_id', $submissionId)
            ->pluck('funder_identification', 'funder_id');

        if (!$funderIds->isEmpty()) {
            foreach ($funderIds as $funderId => $funderIdentification) {
                if ($funderRor = $this->getFunderROR($funderIdentification)) {
                    $awardIds = DB::table('funder_awards')
                        ->where('funder_id', $funderId)
                        ->pluck('funder_award_number');

                    foreach ($awardIds as $awardId) {
                        if ($plugin->isValidAward($context, $funderRor, $awardId) === true) {
                            // @todo look into COST Action from example
                            $fundData[] = [
                                'award' => [
                                    'id' => $funderRor . '::' . $awardId,
                                ],
                                'funder' => [
                                    'id' => $funderRor,
                                ]
                            ];
                        }
                    }
                }
            }
        }
        return $fundData ?? false;
    }

    /*
     * Find the funder ROR ID from the Crossref funder ID.
     * To be removed once the funding plugin has migrated to ROR.
     * e.g. https://api.ror.org/v2/organizations?query=%22501100002341%22
     */
    private function getFunderROR(string $funderIdentification): string|bool|array
    {
        $apiUrl = 'https://api.ror.org/v2/organizations';
        $funderId = str_replace('https://doi.org/10.13039/', '', $funderIdentification);
        $queryUrl = $apiUrl . '?query=%22' . $funderId . '%22';
        $httpClient = Application::get()->getHttpClient();

        try {
            $rorResponse = $httpClient->request('GET', $queryUrl);
            $body = json_decode($rorResponse->getBody(), true);

            if (
                $body['number_of_results'] == 1
                && preg_match('/^https:\/\/ror\.org\/(.*)$/', $body['items'][0]['id'], $matches)
            ) {
                $rorId = $matches[1];
            }

            return $rorId ?? false;
        } catch (GuzzleException | Exception $e) {
            return [['plugins.importexport.ror.api.error.awardError', $e->getMessage()]];
        }
    }
}
