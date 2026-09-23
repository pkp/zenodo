<?php

/**
 * @file plugins/generic/zenodo/filter/ZenodoJsonFilter.php
 *
 * Copyright (c) 2025-2026 Simon Fraser University
 * Copyright (c) 2025-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
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
use APP\publication\enums\VersionStage;
use APP\publication\Publication;
use APP\submission\Submission;
use Carbon\Carbon;
use Exception;
use PKP\affiliation\Affiliation;
use PKP\author\contributorRole\ContributorRoleIdentifier;
use PKP\author\contributorRole\ContributorType;
use PKP\citation\Citation;
use PKP\context\Context;
use PKP\core\PKPString;
use PKP\dataCitation\DataCitation;
use PKP\filter\FilterGroup;
use PKP\galley\Galley;
use PKP\i18n\LocaleConversion;
use PKP\plugins\importexport\PKPImportExportFilter;

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
     * @throws Exception
     *
     * @return string JSON
     *
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
        $fileAccess = 'public';
        $embargoUntil = null;

        if (
            $issue &&
            $context->getData('publishingMode') == Journal::PUBLISHING_MODE_SUBSCRIPTION &&
            $issue->getAccessStatus() == Issue::ISSUE_ACCESS_SUBSCRIPTION &&
            $publication->getData('accessStatus') != Submission::ARTICLE_ACCESS_OPEN
        ) {
            $openAccessDate = $issue->getOpenAccessDate() ? Carbon::parse($issue->getOpenAccessDate()) : null;
            if (!$openAccessDate) {
                $fileAccess = 'restricted';
            } elseif ($openAccessDate->isFuture()) {
                // Zenodo requires the embargo date to be in the future; a past date means the issue is open.
                $fileAccess = 'restricted';
                $embargoUntil = $openAccessDate;
            }
        }

        $article['access'] = [
            'files' => $fileAccess,
            'record' => 'public', // only files can be restricted
        ];

        if ($embargoUntil) {
            $article['access']['embargo'] = [
                'active' => true,
                'until' => $embargoUntil->format('Y-m-d'),
            ];
        }

        // Every record carries the article's galley files; metadata-only records are not deposited.
        $article['files'] = [
            'enabled' => true,
        ];

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

        // Subtitle and translated titles
        $additionalTitles = $this->getAdditionalTitlesData($publication, $publicationLocale);
        if (!empty($additionalTitles)) {
            $article['metadata']['additional_titles'] = $additionalTitles;
        }

        // Creators and contributors: names, identifiers, affiliations and roles
        [$creators, $contributors] = $this->getContributorsData($publication, $publicationLocale);
        if (!empty($creators)) {
            $article['metadata']['creators'] = $creators;
        }
        if (!empty($contributors)) {
            $article['metadata']['contributors'] = $contributors;
        }

        // Abstract (InvenioRDM accepts sanitized HTML) and translated abstracts
        $abstract = $publication->getData('abstract', $publicationLocale);
        if (!empty($abstract)) {
            $article['metadata']['description'] = PKPString::stripUnsafeHtml($abstract);
        }
        foreach ($publication->getData('abstract') ?? [] as $locale => $localizedAbstract) {
            if ($locale == $publicationLocale || !$this->isValidText($localizedAbstract)) {
                continue;
            }
            $article['metadata']['additional_descriptions'][] = $this->withLanguage([
                'description' => PKPString::stripUnsafeHtml($localizedAbstract),
                'type' => ['id' => 'abstract'],
            ], $locale);
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

        // Publisher name, falling back to the journal name
        $publisher = $context->getData('publisherInstitution') ?: $context->getName($context->getPrimaryLocale());
        if (!empty($publisher)) {
            $article['metadata']['publisher'] = $publisher;
        }

        // References
        $citations = $publication->getData('citations') ?? [];
        if (!empty($citations)) {
            $citedIdentifiers = [];
            foreach ($citations as $citation) { /** @var Citation $citation */
                $referenceData = [];
                $referenceData['reference'] = $citation->getRawCitation();
                $resourceType = $this->getResourceTypeFromCitationType($citation->getData('type'));

                // One identifier per cited work
                $scheme = $this->getCitationIdentifierScheme($citation);
                if ($scheme) {
                    $referenceData['identifier'] = $citation->getData($scheme);
                    $referenceData['scheme'] = $scheme;
                    $citedIdentifiers[] = [
                        'identifier' => $citation->getData($scheme),
                        'scheme' => $scheme,
                        'resourceType' => $resourceType,
                    ];
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

        // Issue relation
        if ($issue?->getDoi()) {
            $article['metadata']['related_identifiers'][] = [
                'identifier' => $issue->getDoi(),
                'relation_type' => [
                    'id' => 'ispartof'
                ],
                'scheme' => 'doi',
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

        // Version relation: link the immediately preceding published version (any stage),
        // using the DataCite isNewVersionOf model shared with the JATS, DC and MARC exports.
        if ($doiVersioning && $submission) {
            $versionRelation = Repo::publication()->getVersionRelation($publication, $submission, $context);
            if ($versionRelation) {
                $versionIdentifier = $versionRelation->doi ?: $request->getDispatcher()->url(
                    $request,
                    Application::ROUTE_PAGE,
                    $context->getPath(),
                    'article',
                    'view',
                    [$submission->getBestId(), 'version', $versionRelation->publicationId],
                    urlLocaleForPage: ''
                );
                $article['metadata']['related_identifiers'][] = [
                    'identifier' => $versionIdentifier,
                    'relation_type' => [
                        'id' => strtolower($versionRelation->relationType->value)
                    ],
                    'scheme' => $versionRelation->doi ? 'doi' : 'url',
                    'resource_type' => $this->resourceType(
                        $versionRelation->versionStage === VersionStage::AUTHOR_ORIGINAL->value
                            ? 'publication-preprint'
                            : 'publication-article'
                    ),
                ];
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
        $licenseUrl = $publication->getData('licenseUrl') ?: $context->getData('licenseUrl') ?: '';
        $rights = $this->getRightsData($licenseUrl);
        if ($rights) {
            $article['metadata']['rights'][] = $rights;
        }

        // Dates
        // https://inveniordm.docs.cern.ch/reference/metadata/#dates-0-n
        $acceptDecisions = [Decision::ACCEPT, Decision::SKIP_EXTERNAL_REVIEW];
        $editorDecision = Repo::decision()->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->getMany()
            ->first(fn (Decision $decision, $key) => in_array($decision->getData('decision'), $acceptDecisions));

        $dates = [];
        if ($submission?->getData('dateSubmitted')) {
            $dates[] = $this->dateEntry($submission->getData('dateSubmitted'), 'submitted', 'Submission date');
        }
        if ($editorDecision) {
            $dates[] = $this->dateEntry($editorDecision->getData('dateDecided'), 'accepted', 'Acceptance date');
        }
        if ($publication->getData('lastModified')) {
            $dates[] = $this->dateEntry($publication->getData('lastModified'), 'updated', 'Last modified');
        }
        if ($embargoUntil) {
            $dates[] = $this->dateEntry($embargoUntil, 'available', 'Open access date');
        }
        if (!empty($dates)) {
            $article['metadata']['dates'] = $dates;
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
     * Helper function returning an InvenioRDM date entry.
     */
    private function dateEntry(string|Carbon $date, string $typeId, string $description): array
    {
        return [
            'date' => Carbon::parse($date)->format('Y-m-d'),
            'type' => ['id' => $typeId],
            'description' => $description,
        ];
    }

    /**
     * Helper function adding an ISO 639-3 language to a title or description entry.
     */
    private function withLanguage(array $entry, string $locale): array
    {
        $iso3 = LocaleConversion::getIso3FromLocale($locale);
        if ($iso3) {
            $entry['lang'] = ['id' => $iso3];
        }
        return $entry;
    }

    /**
     * InvenioRDM rejects titles and descriptions shorter than three characters.
     */
    private function isValidText(?string $text): bool
    {
        return mb_strlen(trim(strip_tags((string) $text))) >= 3;
    }

    /**
     * Helper function for the subtitle and the titles in other locales.
     * https://inveniordm.docs.cern.ch/reference/metadata/#additional-titles-0-n
     */
    private function getAdditionalTitlesData(Publication $publication, string $publicationLocale): array
    {
        $titles = [];
        $allTitles = $publication->getTitles();
        $allSubtitles = $publication->getSubTitles();

        if ($this->isValidText($allSubtitles[$publicationLocale] ?? null)) {
            $titles[] = $this->withLanguage([
                'title' => $allSubtitles[$publicationLocale],
                'type' => ['id' => 'subtitle'],
            ], $publicationLocale);
        }

        foreach ($allTitles as $locale => $title) {
            if ($locale == $publicationLocale) {
                continue;
            }
            $fullTitle = isset($allSubtitles[$locale])
                ? PKPString::concatTitleFields([$title, $allSubtitles[$locale]])
                : $title;
            if ($this->isValidText($fullTitle)) {
                $titles[] = $this->withLanguage([
                    'title' => $fullTitle,
                    'type' => ['id' => 'translated-title'],
                ], $locale);
            }
        }

        return $titles;
    }

    /**
     * Helper function mapping a license URL to an InvenioRDM rights entry: a vocabulary id for
     * Creative Commons licenses (e.g. cc-by-4.0, cc0-1.0), otherwise a custom title and link.
     * https://inveniordm.docs.cern.ch/reference/metadata/#rights-licenses-0-n
     */
    private function getRightsData(string $licenseUrl): ?array
    {
        $licenseUrl = trim($licenseUrl);
        if ($licenseUrl === '') {
            return null;
        }

        $ccPattern = '#^https?://(?:www\.)?creativecommons\.org/(?:licenses/([a-z-]+)|publicdomain/(zero|mark))/(\d\.\d)/?(?:(?:deed|legalcode)(?:\.[a-z_]+)?)?$#i';
        if (preg_match($ccPattern, $licenseUrl, $match)) {
            $id = match (strtolower($match[2])) {
                'zero' => 'cc0-' . $match[3],
                'mark' => 'cc-pdm-' . $match[3],
                default => 'cc-' . strtolower($match[1]) . '-' . $match[3],
            };
            return ['id' => $id];
        }

        if (!filter_var($licenseUrl, FILTER_VALIDATE_URL)) {
            return null;
        }
        return [
            'title' => ['en' => $licenseUrl],
            'link' => $licenseUrl,
        ];
    }

    /**
     * The scheme of the identifier to send for a cited work, preferring the most
     * persistent one it carries, or null when it has none.
     */
    private function getCitationIdentifierScheme(Citation $citation): ?string
    {
        foreach (['doi', 'handle', 'arxiv', 'urn', 'url'] as $scheme) {
            if ($citation->getData($scheme)) {
                return $scheme;
            }
        }
        return null;
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
     * Zenodo contributor roles for the OJS contributor roles that are not "author".
     * Zenodo's vocabulary differs from InvenioRDM's default: it has no "translator", so
     * every role but editor (translator, reviewer, chair, reader...) is "other".
     * https://zenodo.org/api/vocabularies/contributorsroles
     */
    private function getContributorRoleId(string $roleIdentifier): string
    {
        return match ($roleIdentifier) {
            ContributorRoleIdentifier::EDITOR->getName() => 'editor',
            default => 'other',
        };
    }

    /**
     * Helper function for creators and contributors.
     *
     * Each contributor appears once. Those holding the author role are creators,
     * whatever else they hold; the others are contributors with one role, editor
     * taking priority over "other". Contributors from before roles existed hold none
     * at all; when that is true of everyone, everyone is a creator.
     *
     * @return array{0: array, 1: array} [creators, contributors]
     */
    private function getContributorsData(Publication $publication, string $publicationLocale): array
    {
        $creators = [];
        $contributors = [];
        $authorRole = ContributorRoleIdentifier::AUTHOR->getName();
        $articleAuthors = collect($publication->getData('authors') ?? []);
        $anyRole = $articleAuthors->contains(
            fn (Author $articleAuthor) => !empty($articleAuthor->getContributorRoleIdentifiers())
        );

        foreach ($articleAuthors as $articleAuthor) { /** @var Author $articleAuthor */
            $entry = $this->getPersonOrOrgData($articleAuthor, $publicationLocale);
            if (!$entry) {
                continue;
            }

            $roles = $articleAuthor->getContributorRoleIdentifiers();
            if (!$anyRole || in_array($authorRole, $roles)) {
                $creators[] = $entry;
                continue;
            }

            $roleIds = array_map(fn (string $roleIdentifier) => $this->getContributorRoleId($roleIdentifier), $roles);
            if (!empty($roleIds)) {
                $contributors[] = $entry + ['role' => ['id' => in_array('editor', $roleIds) ? 'editor' : 'other']];
            }
        }

        return [$creators, $contributors];
    }

    /**
     * Helper function for one creator or contributor entry: the person or organization
     * with its identifiers, plus affiliations for a person. Null when there is nothing
     * to send.
     */
    private function getPersonOrOrgData(Author $articleAuthor, string $publicationLocale): ?array
    {
        $contributorType = $articleAuthor->getData('contributorType');
        $author = [];

        if ($contributorType === ContributorType::PERSON->getName()) {
            // Family name is required by Zenodo
            if (empty($articleAuthor->getFamilyName($publicationLocale))) {
                $author['family_name'] = $articleAuthor->getGivenName($publicationLocale);
            } else {
                if ($articleAuthor->getGivenName($publicationLocale)) {
                    $author['given_name'] = $articleAuthor->getGivenName($publicationLocale);
                }
                $author['family_name'] = $articleAuthor->getFamilyName($publicationLocale);
            }
            $author['type'] = 'personal';
            if ($articleAuthor->getOrcid() && $articleAuthor->hasVerifiedOrcid()) {
                $author['identifiers'][] = [
                    'identifier' => basename(parse_url($articleAuthor->getOrcid(), PHP_URL_PATH)),
                    'scheme' => 'orcid',
                ];
            }

            $affiliationsData = [];
            foreach ($articleAuthor->getAffiliations() as $affiliation) { /** @var Affiliation $affiliation */
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

            return empty($affiliationsData)
                ? ['person_or_org' => $author]
                : ['person_or_org' => $author, 'affiliations' => $affiliationsData];
        }

        if ($contributorType === ContributorType::ORGANIZATION->getName()) {
            if (!$articleAuthor->getOrganizationName($publicationLocale)) {
                return null;
            }
            $author['name'] = $articleAuthor->getOrganizationName($publicationLocale);
            $author['type'] = 'organizational';
            $rorId = $this->normalizeRorId($articleAuthor->getData('rorId'));
            if ($rorId) {
                $author['identifiers'][] = [
                    'identifier' => $rorId,
                    'scheme' => 'ror',
                ];
            }
            return ['person_or_org' => $author];
        }

        if ($contributorType === ContributorType::ANONYMOUS->getName()) {
            return ['person_or_org' => ['family_name' => 'Anonymous', 'type' => 'personal']];
        }

        return null;
    }

    /**
     * The bare ROR id from a free-text ROR field, or null when the text is not one.
     * The pattern is the one InvenioRDM validates the "ror" scheme with, so a value it
     * rejects is left out rather than failing the whole deposit.
     */
    private function normalizeRorId(?string $ror): ?string
    {
        if (!$ror || !preg_match('#^(?:https?://)?(?:ror\.org/)?(0\w{6}\d{2})$#i', trim($ror), $match)) {
            return null;
        }
        return strtolower($match[1]);
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
                        if (!empty($grant['grantNumber'])) {
                            $award['number'] = $grant['grantNumber'];
                        }
                        if (!empty($grant['grantName'])) {
                            $award['title'] = [LocaleConversion::getIso1FromLocale($locale) => $grant['grantName']];
                        }
                        // InvenioRDM requires a number or title for custom awards; identifiers alone are rejected.
                        if (!empty($award) && !empty($grant['grantDoi'])) {
                            $award['identifiers'] = [['scheme' => 'doi', 'identifier' => $grant['grantDoi']]];
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
