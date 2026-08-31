<?php

/**
 * @file plugins/generic/zenodo/filter/ZenodoJsonFilter.php
 *
 * Copyright (c) 2025-2026 Simon Fraser University
 * Copyright (c) 2025-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ZenodoJsonFilter
 *
 * @ingroup plugins_generic_zenodo
 *
 * @brief Class that converts an Article to a Zenodo JSON string.
 */

namespace APP\plugins\generic\zenodo\filter;

use APP\author\Author;
use APP\core\Application;
use APP\decision\Decision;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\generic\zenodo\ZenodoExportDeployment;
use APP\plugins\generic\zenodo\ZenodoExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use Carbon\Carbon;
use Exception;
use PKP\affiliation\Affiliation;
use PKP\author\contributorRole\ContributorType;
use PKP\citation\Citation;
use PKP\context\Context;
use PKP\core\PKPString;
use PKP\dataCitation\DataCitation;
use PKP\filter\FilterGroup;
use PKP\galley\Galley;
use PKP\i18n\LocaleConversion;
use PKP\plugins\importexport\PKPImportExportFilter;
use PKP\submission\PKPSubmission;

class ZenodoJsonFilter extends PKPImportExportFilter
{
    /**
     * InvenioRDM resource type titles keyed by resource type id.
     * https://github.com/inveniosoftware/invenio-rdm-records/blob/master/invenio_rdm_records/fixtures/data/vocabularies/resource_types.yaml
     */
    private const RESOURCE_TYPE_TITLES = [
        'publication-article' => 'Journal article',
        'publication-journal' => 'Journal',
        'publication-book' => 'Book',
        'publication-section' => 'Book chapter',
        'publication-conferencepaper' => 'Conference paper',
        'publication-conferenceproceeding' => 'Conference proceeding',
        'publication-preprint' => 'Preprint',
        'publication-report' => 'Report',
        'publication-standard' => 'Standard',
        'publication-dissertation' => 'Thesis',
        'publication-peerreview' => 'Peer review',
        'publication-other' => 'Other',
        'dataset' => 'Dataset',
    ];

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
     * @param Submission|Publication $pubObject
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

        if ($pubObject instanceof Submission) {
            $submission = $pubObject;
            $publication = $pubObject->getCurrentPublication();
            $submissionId = $pubObject->getId();
            if (!$cache->isCached('articles', $submissionId)) {
                $cache->add($submission, null);
            }
        } elseif ($pubObject instanceof Publication) {
            $publication = $pubObject;
            $submissionId = $pubObject->getData('submissionId');
            if ($cache->isCached('articles', $submissionId)) {
                $submission = $cache->get('articles', $submissionId); /** @var Submission $submission */
            } else {
                $submission = Repo::submission()->get($submissionId, $context->getId());
                if ($submission) {
                    $cache->add($submission, null);
                }
            }
        } else {
            throw new Exception('Invalid object type');
        }

        $publicationLocale = $publication->getData('locale');

        $issueId = $publication->getData('issueId');
        $issue = null;
        if ($issueId) {
            if ($cache->isCached('issues', $issueId)) {
                $issue = $cache->get('issues', $issueId); /** @var Issue $issue */
            } else {
                $issue = Repo::issue()->get($issueId, $context->getId());
                if ($issue) {
                    $cache->add($issue, null);
                }
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
            $article['metadata']['publication_date'] = Carbon::parse(
                $publication->getData('datePublished')
            )->format('Y-m-d');
        } elseif ($issue?->getDatePublished()) {
            $article['metadata']['publication_date'] = Carbon::parse(
                $issue->getDatePublished()
            )->format('Y-m-d');
        }

        // Publisher name
        if (!empty($context->getData('publisherInstitution'))) {
            $article['metadata']['publisher'] = $context->getData('publisherInstitution');
        }

        // References
        $citations = $publication->getData('citations') ?? [];
        if (!empty($citations)) {
            $citedIdentifiers = [];
            $supportedIdentifiers = [
                'arxiv','doi', 'handle', 'url', 'urn'
            ];
            foreach ($citations as $citation) { /** @var Citation $citation */
                $referenceData = [];
                $referenceData['reference'] = $citation->getRawCitation();
                $resourceType = $this->getResourceTypeFromCitationType($citation->getData('type'));

                foreach ($supportedIdentifiers as $identifier) {
                    if ($citation->getData($identifier)) {
                        $referenceData['identifier'] = $citation->getData($identifier);
                        $referenceData['scheme'] = $identifier;
                        $citedIdentifiers[] = [
                            'identifier' => $citation->getData($identifier),
                            'scheme' => $identifier,
                            'resourceType' => $resourceType,
                        ];
                    }
                }

                $article['metadata']['references'][] = $referenceData;
            }
        }

        // Related Identifiers
        // Schemes: https://inveniordm-dev.docs.cern.ch/reference/metadata/#identifier-schemes
        // Types: https://github.com/inveniosoftware/invenio-rdm-records/blob/master/invenio_rdm_records/fixtures/data/vocabularies/relation_types.yaml

        // Cites relations
        if (!empty($citedIdentifiers)) {
            foreach ($citedIdentifiers as $citedIdentifier) {
                $relatedIdentifier = [
                    'identifier' => $citedIdentifier['identifier'],
                    'relation_type' => [
                        'id' => 'cites',
                    ],
                    'scheme' => $citedIdentifier['scheme'],
                ];
                if (!empty($citedIdentifier['resourceType'])) {
                    $relatedIdentifier['resource_type'] = $citedIdentifier['resourceType'];
                }
                $article['metadata']['related_identifiers'][] = $relatedIdentifier;
            }
        }

        // Data citations
        $dataCitationSchemes = [
            'DOI' => 'doi',
            'ARXIV' => 'arxiv',
            'Handle' => 'handle',
            'ARK' => 'ark',
            'PURL' => 'purl',
            'ISSN' => 'issn',
            'ISBN' => 'isbn',
            'PMID' => 'pmid',
            'PMCID' => 'pubmedcentral',
            'URI' => 'url',
        ];
        foreach ($publication->getData('dataCitations') ?? [] as $dataCitation) { /** @var DataCitation $dataCitation */
            $reference = $this->formatDataCitationReference($dataCitation);
            $referenceData = ($reference !== '') ? ['reference' => $reference] : null;

            $identifier = $dataCitation->identifier;
            $scheme = $dataCitationSchemes[$dataCitation->identifierType] ?? null;
            if (!$identifier || !$scheme) {
                $identifier = $dataCitation->url;
                $scheme = $identifier ? 'url' : null;
            }

            if ($identifier && $scheme) {
                $relationType = match ($dataCitation->relationshipType) {
                    'generated', 'supporting' => 'issupplementedby',
                    default => 'cites', // analyzed, non-analyzed
                };
                if ($referenceData !== null) {
                    $referenceData['identifier'] = $identifier;
                    $referenceData['scheme'] = $scheme;
                }
                $article['metadata']['related_identifiers'][] = [
                    'identifier' => $identifier,
                    'relation_type' => [
                        'id' => $relationType,
                    ],
                    'scheme' => $scheme,
                    'resource_type' => $this->resourceType('dataset'),
                ];
            }

            if ($referenceData !== null) {
                $article['metadata']['references'][] = $referenceData;
            }
        }

        // FullText URL relation
        $request = Application::get()->getRequest();
        $doiVersioning = $context->getData(Context::SETTING_DOI_VERSIONING);
        $path = $doiVersioning ?
                ([$publication->getData('urlPath') ?? $submissionId, 'version', $publication->getId()]) :
                ([$publication->getData('urlPath') ?? $submissionId]);

        $url = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'article',
            'view',
            $path,
            urlLocaleForPage: ''
        );

        $article['metadata']['related_identifiers'][] = [
            'identifier' => $url,
            'relation_type' => [
                'id' => 'isidenticalto'
            ],
            'scheme' => 'url',
            'resource_type' => $this->resourceType('publication-article'),
        ];

        // Online ISSN relation
        $onlineIssn = $context->getData('onlineIssn') ?? null;
        if ($onlineIssn) {
            $article['metadata']['related_identifiers'][] = [
                'identifier' => $onlineIssn,
                'relation_type' => [
                    'id' => 'ispublishedin'
                ],
                'scheme' => 'issn',
                'resource_type' => $this->resourceType('publication-journal'),
            ];
        }

        // Print ISSN relation
        $printIssn = $context->getData('printIssn') ?? null;
        if ($printIssn) {
            $article['metadata']['related_identifiers'][] = [
                'identifier' => $printIssn,
                'relation_type' => [
                    'id' => 'ispublishedin'
                ],
                'scheme' => 'issn',
                'resource_type' => $this->resourceType('publication-journal'),
            ];
        }

        // Review relations
        $reviewItems = Repo::publication()->getReviewDoiItemsGroupedByPublication([$publication->getId()]);
        foreach ($reviewItems[$publication->getId()] ?? [] as $reviewItem) {
            if ($reviewItem['pubObjectType'] !== Repo::doi()::TYPE_PEER_REVIEW) {
                continue;
            }
            $reviewDoi = $reviewItem['doiObject']?->getData('doi');
            if ($reviewDoi) {
                $article['metadata']['related_identifiers'][] = [
                    'identifier' => $reviewDoi,
                    'relation_type' => [
                        'id' => 'isreviewedby'
                    ],
                    'scheme' => 'doi',
                    'resource_type' => $this->resourceType('publication-peerreview'),
                ];
            }
        }

        // Version relations
        if ($doiVersioning) {
            $previousPublications = Repo::publication()->getCollector()
                ->filterBySubmissionIds([$publication->getData('submissionId')])
                ->filterByVersionStage($publication->getData('versionStage'))
                ->filterByStatus([PKPSubmission::STATUS_PUBLISHED])
                ->getMany();

            if (!$previousPublications->isEmpty()) {
                $previousDois = [];
                foreach ($previousPublications as $previousPublication) { /** @var $previousPublication Publication */
                    if (
                        ((int)$previousPublication->getData('versionMajor')
                        < (int)$publication->getData('versionMajor'))
                        && $previousPublication->getDoi()
                    ) {
                        $previousDois[] = $previousPublication->getDoi();
                    }
                }

                foreach (array_unique($previousDois) as $previousDoi) {
                    $article['metadata']['related_identifiers'][] = [
                        'relation_type' => [
                            'id' => 'isversionof'
                        ],
                        'identifier' => $previousDoi,
                        'scheme' => 'doi',
                        'resource_type' => $this->resourceType('publication-article'),
                    ];
                }
            }
        }

        // Keywords and Subjects
        $keywords = $publication->getData('keywords', $publicationLocale) ?? [];
        $subjects = $publication->getData('subjects', $publicationLocale) ?? [];
        $keywordsSubjects = array_merge($keywords, $subjects);
        if (!empty($keywordsSubjects)) {
            $subjectMetadata = [];
            foreach ($keywordsSubjects as $subject) {
                $subjectMetadata[] = [
                    'subject' => $subject['name'],
                ];
            }
            $article['metadata']['subjects'] = $subjectMetadata;
        }

        // Funding metadata
        $fundingMetadata = $submission ? $this->getFundingData($submission, $context, $publicationLocale) : false;
        if ($fundingMetadata) {
            $article['metadata']['funding'] = $fundingMetadata;
        }

        // Publication version
        $versionMajor = (string)$publication->getData('versionMajor');
        $versionMinor = (string)$publication->getData('versionMinor');
        if ($versionMajor != '' && $versionMinor != '') {
            $article['metadata']['version'] = $versionMajor . '.' . $versionMinor;
        }

        // Languages (ISO 639-3)
        $languagesData = $this->getLanguagesData($publication, $publicationLocale);
        if (!empty($languagesData)) {
            foreach ($languagesData as $language) {
                $article['metadata']['languages'][] = [
                    'id' => $language,
                ];
            }
        }

        // Copyright statement
        if ($publication->getData('copyrightHolder', $publicationLocale) && $publication->getData('copyrightYear')) {
            $article['metadata']['copyright'] = __('submission.copyrightStatement', [
                'copyrightYear' => $publication->getData('copyrightYear'),
                'copyrightHolder' => $publication->getData('copyrightHolder', $publicationLocale)
            ]);
        }

        // License
        $licenseUrl = $publication->getData('licenseUrl') ?? $context->getData('licenseUrl') ?? '';
        if (preg_match('/creativecommons\.org\/licenses\/(.*?)\/([\d.]+)\/?$/i', $licenseUrl, $match)) {
            $article['metadata']['rights'][] = [
                'id' => 'cc-' . $match[1] . '-' . $match[2],
            ];
        }

        // Dates
        // https://inveniordm.docs.cern.ch/reference/metadata/#dates-0-n
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

    /**
     * Helper function returning an InvenioRDM resource type array (id and title) for a resource type id.
     */
    private function resourceType(string $resourceTypeId): array
    {
        return ['id' => $resourceTypeId, 'title' => ['en' => self::RESOURCE_TYPE_TITLES[$resourceTypeId]]];
    }

    /**
     * Helper function mapping a citation type to an InvenioRDM resource type.
     */
    private function getResourceTypeFromCitationType(?string $citationType): ?array
    {
        if (empty($citationType)) {
            return null;
        }

        $resourceTypeId = match ($citationType) {
            'journal-article', 'editorial', 'letter', 'review' => 'publication-article',
            'journal', 'journal-issue', 'journal-volume' => 'publication-journal',
            'book', 'book-series', 'book-set', 'edited-book', 'monograph', 'reference-book' => 'publication-book',
            'book-chapter', 'book-section', 'book-part', 'book-track', 'reference-entry' => 'publication-section',
            'proceedings-article' => 'publication-conferencepaper',
            'proceedings', 'proceedings-series' => 'publication-conferenceproceeding',
            'preprint', 'posted-content' => 'publication-preprint',
            'report', 'report-component', 'report-series' => 'publication-report',
            'standard' => 'publication-standard',
            'dissertation' => 'publication-dissertation',
            'peer-review' => 'publication-peerreview',
            'dataset', 'database' => 'dataset',
            default => 'publication-other',
        };

        return $this->resourceType($resourceTypeId);
    }

    /**
     * Helper function to build a reference string for a data citation.
     */
    private function formatDataCitationReference(DataCitation $dataCitation): string
    {
        $parts = [];

        $names = [];
        foreach (is_array($dataCitation->authors) ? $dataCitation->authors : [] as $author) {
            $name = trim(($author['familyName'] ?? '') . ', ' . ($author['givenName'] ?? ''), ' ,');
            if ($name !== '') {
                $names[] = $name;
            }
        }
        if (!empty($names)) {
            $parts[] = implode('; ', $names);
        }

        if ($dataCitation->year) {
            $parts[] = '(' . $dataCitation->year . ')';
        }
        if ($dataCitation->title) {
            $parts[] = $dataCitation->title;
        }
        if ($dataCitation->repository) {
            $parts[] = $dataCitation->repository;
        }

        return implode('. ', $parts);
    }

    /**
     * Helper function for journal metadata.
     * https://inveniordm.docs.cern.ch/reference/metadata/#journal
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
        } elseif ($context->getData('printIssn') != '') {
            $journalData['issn'] = $context->getData('printIssn');
        }

        // Volume and Issue Number
        if ($issue) {
            $volume = $issue->getVolume();
            if (!empty($volume)) {
                $journalData['volume'] = (string)$volume;
            }

            $issueNumber = $issue->getNumber();
            if (!empty($issueNumber)) {
                $journalData['issue'] = $issueNumber;
            }
        }

        // Pages or Article Number
        $startPage = $publication->getStartingPage();
        $endPage = $publication->getEndingPage();
        if (isset($startPage) && $startPage !== '') {
            $journalData['pages'] = $startPage;
            if (isset($endPage) && $endPage !== '') {
                $journalData['pages'] = $startPage . '-' . $endPage;
            }
        } elseif ($publication->getData('articleNumber')) {
            $journalData['pages'] = $publication->getData('articleNumber');
        }

        return $journalData;
    }

    /**
     * Helper function for authors metadata.
     */
    private function getAuthorsData(Publication $publication, string $publicationLocale): array
    {
        $articleAuthors = $publication->getData('authors');
        $authorsData = [];

        foreach ($articleAuthors as $articleAuthor) { /** @var Author $articleAuthor */
            $author = [];
            $contributorType = $articleAuthor->getData('contributorType');

            if ($contributorType === ContributorType::PERSON->getName()) {
                // Family name is required by Zenodo
                if (empty($articleAuthor->getFamilyName($publicationLocale))) {
                    $author['family_name'] = $articleAuthor->getGivenName($publicationLocale);
                } else {
                    if ($articleAuthor->getGivenName($publicationLocale)) {
                        $author['given_name'] = $articleAuthor->getGivenName($publicationLocale);
                    }
                    if ($articleAuthor->getFamilyName($publicationLocale)) {
                        $author['family_name'] = $articleAuthor->getFamilyName($publicationLocale);
                    }
                }
                $author['type'] = 'personal';
                if ($articleAuthor->getOrcid() && $articleAuthor->hasVerifiedOrcid()) {
                    $author['identifiers'][] = [
                        'identifier' => basename(parse_url($articleAuthor->getOrcid(), PHP_URL_PATH)),
                        'scheme' => 'orcid',
                    ];
                }
                $affiliations = $articleAuthor->getAffiliations();
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
            } elseif ($contributorType === ContributorType::ORGANIZATION->getName()) {
                // @todo add ROR as well? or just part of affiliations same as for person?
                if ($articleAuthor->getOrganizationName($publicationLocale)) {
                    $author['name'] = $articleAuthor->getOrganizationName($publicationLocale);
                    $author['type'] = 'organizational';
                    $authorsData[] = ['person_or_org' => $author];
                }
            } elseif ($contributorType === ContributorType::ANONYMOUS->getName()) {
                $author['family_name'] = 'Anonymous';
                $author['type'] = 'personal';
                $authorsData[] = ['person_or_org' => $author];
            }
        }
        return $authorsData;
    }

    /**
     * Helper function for funding metadata.
     */
    private function getFundingData(Submission $submission, Context $context, string $locale): false|array
    {
        /** @var ZenodoExportDeployment $deployment */
        $deployment = $this->getDeployment();
        /** @var ZenodoExportPlugin $plugin */
        $plugin = $deployment->getPlugin();

        $funders = $submission->getData('funders') ?? [];
        $fundingData = [];

        foreach ($funders as $funder) {
            $ror = !empty($funder->ror) ? basename(parse_url($funder->ror, PHP_URL_PATH)) : null;
            $funderField = $ror ? ['id' => $ror] : ['name' => $funder->getLocalizedData('name', $locale)];
            $grants = $funder->grants ?? [];

            if (!empty($grants)) {
                foreach ($grants as $grant) {
                    $entry = ['funder' => $funderField];
                    $award = [];

                    if (
                        $ror &&
                        !empty($grant['grantNumber']) &&
                        $plugin->isValidAward($context, $ror, $grant['grantNumber']) === true
                    ) {
                        $award['id'] = $ror . '::' . $grant['grantNumber'];
                    } else {
                        if (!empty($grant['grantDoi'])) {
                            $award['identifiers'] = [['scheme' => 'doi', 'identifier' => $grant['grantDoi']]];
                        }
                        if (!empty($grant['grantNumber'])) {
                            $award['number'] = $grant['grantNumber'];
                        }
                        if (!empty($grant['grantName'])) {
                            $award['title'] = [LocaleConversion::getIso1FromLocale($locale) => $grant['grantName']];
                        }
                    }

                    if (!empty($award)) {
                        $entry['award'] = $award;
                    }

                    $fundingData[] = $entry;
                }
            } else {
                $fundingData[] = ['funder' => $funderField];
            }
        }

        return $fundingData ?: false;
    }

    /**
     * Helper function for language metadata which collects publication language
     * and galley languages.
     */
    private function getLanguagesData(Publication $publication, string $publicationLocale): array
    {
        $languageList = [];
        $languageList[] = LocaleConversion::getIso3FromLocale($publicationLocale);
        $galleys = $publication->getData('galleys');
        if (!empty($galleys)) {
            foreach ($publication->getData('galleys') as $galley) { /** @var Galley $galley */
                $languageList[] = LocaleConversion::getIso3FromLocale($galley->getLocale());
            }
        }
        return array_values(array_unique(array_filter($languageList)));
    }
}
