<?php

/**
 * @file plugins/generic/zenodo/tests/ZenodoExportPluginTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Unit tests for the Zenodo export plugin.
 */

namespace APP\plugins\generic\zenodo\tests;

use APP\issue\Issue;
use APP\issue\Repository as IssueRepository;
use APP\journal\Journal;
use APP\plugins\generic\zenodo\jobs\ZenodoDeposit;
use APP\plugins\generic\zenodo\ZenodoExportPlugin;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\enums\VersionStage;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\submissionFile\Repository as SubmissionFileRepository;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PKP\core\Registry;
use PKP\db\DAORegistry;
use PKP\doi\Doi;
use PKP\galley\Galley;
use PKP\submission\Genre;
use PKP\submission\GenreDAO;
use PKP\submissionFile\SubmissionFile;
use PKP\tests\PKPTestCase;
use ReflectionMethod;
use Throwable;

#[CoversClass(ZenodoExportPlugin::class)]
class ZenodoExportPluginTest extends PKPTestCase
{
    /**
     * Localized publication data resolves its locale fallbacks through the request.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->mockRequest();
    }

    /**
     * @copydoc PKPTestCase::getMockedDAOs()
     */
    protected function getMockedDAOs(): array
    {
        return ['GenreDAO'];
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(IssueRepository::class);
        app()->forgetInstance(SubmissionFileRepository::class);
        parent::tearDown();
    }

    /**
     * Build the plugin with its settings stubbed out.
     *
     * @param array $settings Plugin setting name => value
     */
    private function createPlugin(array $settings = []): ZenodoExportPlugin
    {
        // Status and object updates write to the database, which these tests do not use.
        $plugin = $this->getMockBuilder(ZenodoExportPlugin::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSetting', 'updateStatus', 'updateObject'])
            ->getMock();

        $plugin->method('getSetting')
            ->willReturnCallback(fn ($contextId, $name) => $settings[$name] ?? null);

        return $plugin;
    }

    /**
     * Call a protected method on the plugin.
     */
    private function invoke(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        return $reflection->invokeArgs($object, $args);
    }

    private function createJournal(): Journal
    {
        $journal = new Journal();
        $journal->setId(1);
        $journal->setData('primaryLocale', 'en');
        return $journal;
    }

    /**
     * A publication carrying everything Zenodo requires.
     */
    private function createPublication(array $overrides = []): Publication
    {
        $publication = new Publication();
        $publication->setId(10);
        $publication->setData('locale', 'en');
        $publication->setData('title', ['en' => 'Signalling theory']);
        $publication->setData('authors', collect(['an author']));
        $publication->setData('datePublished', '2025-03-01');
        foreach ($overrides as $key => $value) {
            $publication->setData($key, $value);
        }
        return $publication;
    }

    private function bindIssueRepository(?Issue $issue): void
    {
        $issueRepository = $this->createMock(IssueRepository::class);
        $issueRepository->method('get')->willReturn($issue);
        app()->instance(IssueRepository::class, $issueRepository);
    }

    //
    // Export actions and settings
    //
    public function testDepositActionOfferedWhenAnApiKeyIsSet(): void
    {
        $plugin = $this->createPlugin(['apiKey' => 'secret']);

        $this->assertSame([
            PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT,
            PubObjectsExportPlugin::EXPORT_ACTION_EXPORT,
            PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED,
        ], $plugin->getExportActions($this->createJournal()));
    }

    public function testDepositActionWithheldWithoutAnApiKey(): void
    {
        $plugin = $this->createPlugin();

        $this->assertSame([
            PubObjectsExportPlugin::EXPORT_ACTION_EXPORT,
            PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED,
        ], $plugin->getExportActions($this->createJournal()));
    }

    /**
     * Settings are stored as strings, so the boolean accessors must read "1" as on.
     */
    public function testBooleanSettingsReadStoredStrings(): void
    {
        $on = $this->createPlugin(['mintDoi' => '1', 'automaticPublishing' => '1', 'automaticRegistration' => '1']);
        $off = $this->createPlugin(['mintDoi' => '0', 'automaticPublishing' => '', 'automaticRegistration' => null]);
        $journal = $this->createJournal();

        $this->assertTrue($on->mintZenodoDois($journal));
        $this->assertTrue($on->automaticPublishing($journal));
        $this->assertTrue($on->automaticRegistration($journal));
        $this->assertFalse($off->mintZenodoDois($journal));
        $this->assertFalse($off->automaticPublishing($journal));
        $this->assertFalse($off->automaticRegistration($journal));
    }

    public function testOnlyTheVersionOfRecordIsDepositable(): void
    {
        $this->assertSame([VersionStage::VERSION_OF_RECORD], $this->createPlugin()->getExportableVersionStages());
    }

    public function testTheZenodoIdIsStoredUnderThePluginPrefix(): void
    {
        $this->assertSame('zenodo::id', $this->createPlugin()->getIdSettingName());
    }

    //
    // convertErrorMessage()
    //
    public function testConvertErrorMessagePassesTheDetailAsParam(): void
    {
        $this->assertSame(
            __('plugins.importexport.zenodo.export.failure.missingMetadata', ['param' => 'Title']),
            $this->createPlugin()->convertErrorMessage(['plugins.importexport.zenodo.export.failure.missingMetadata', 'Title'])
        );
    }

    public function testConvertErrorMessageWithoutADetail(): void
    {
        $this->assertSame(
            __('plugins.importexport.zenodo.register.error.noApiKey'),
            $this->createPlugin()->convertErrorMessage(['plugins.importexport.zenodo.register.error.noApiKey'])
        );
    }

    //
    // getExceptionMessage()
    //
    public function testAnApiErrorResponseIsQuotedWithItsStatus(): void
    {
        $exception = new RequestException(
            'Client error',
            new Request('POST', 'https://zenodo.org/api/records'),
            new Response(400, [], '{"message":"A validation error occurred."}')
        );

        $this->assertSame(
            '{"message":"A validation error occurred."} (400 Bad Request)',
            $this->invoke($this->createPlugin(), 'getExceptionMessage', [$exception])
        );
    }

    /**
     * A connection failure carries no response, and used to fatal on hasResponse().
     */
    public function testAConnectionFailureFallsBackToItsMessage(): void
    {
        $exception = new ConnectException('Could not resolve host', new Request('GET', 'https://zenodo.org/api/records/1'));

        $this->assertSame(
            'Could not resolve host',
            $this->invoke($this->createPlugin(), 'getExceptionMessage', [$exception])
        );
    }

    public function testAnyOtherExceptionFallsBackToItsMessage(): void
    {
        $this->assertSame(
            'boom',
            $this->invoke($this->createPlugin(), 'getExceptionMessage', [new Exception('boom')])
        );
    }

    //
    // validateRequiredMetadata()
    //
    public function testCompleteMetadataPassesThePreflight(): void
    {
        $this->assertNull($this->createPlugin()->validateRequiredMetadata($this->createPublication()));
    }

    public function testAPublicationDateFromTheIssueIsEnough(): void
    {
        $issue = new Issue();
        $issue->setData('datePublished', '2025-03-01');
        $this->bindIssueRepository($issue);

        $publication = $this->createPublication(['datePublished' => null, 'issueId' => 3]);

        $this->assertNull($this->createPlugin()->validateRequiredMetadata($publication));
    }

    public function testMissingAuthorsAreReported(): void
    {
        $publication = $this->createPublication(['authors' => collect([])]);

        $this->assertSame(
            ['plugins.importexport.zenodo.export.failure.missingMetadata', __('submission.authors')],
            $this->createPlugin()->validateRequiredMetadata($publication)
        );
    }

    public function testEveryMissingFieldIsListed(): void
    {
        $this->bindIssueRepository(null);
        $publication = $this->createPublication([
            'title' => [],
            'authors' => collect([]),
            'datePublished' => null,
            'issueId' => 3,
        ]);

        $result = $this->createPlugin()->validateRequiredMetadata($publication);

        $this->assertSame(
            __('common.title') . ', ' . __('submission.authors') . ', ' . __('publication.datePublished'),
            $result[1]
        );
    }

    public function testASubmissionIsCheckedThroughItsCurrentPublication(): void
    {
        $publication = $this->createPublication(['authors' => collect([])]);
        $submission = new Submission();
        $submission->setData('publications', collect([$publication]));
        $submission->setData('currentPublicationId', $publication->getId());

        $result = $this->createPlugin()->validateRequiredMetadata($submission);

        $this->assertSame(__('submission.authors'), $result[1]);
    }

    public function testASubmissionWithoutACurrentPublicationFailsThePreflight(): void
    {
        $submission = new Submission();
        $submission->setData('publications', collect([]));

        $this->assertNotNull($this->createPlugin()->validateRequiredMetadata($submission));
    }

    //
    // validateGalleys() and getArticlePdfFile()
    //
    private const GENRE_ARTICLE = 1;
    private const GENRE_SUPPLEMENTARY = 2;
    private const GENRE_DEPENDENT = 3;

    /**
     * Register a genre DAO knowing an article-text genre, a supplementary genre
     * and a dependent genre.
     */
    private function bindGenres(): void
    {
        $genres = [];
        foreach ([
            self::GENRE_ARTICLE => [Genre::GENRE_CATEGORY_DOCUMENT, false, false],
            self::GENRE_SUPPLEMENTARY => [Genre::GENRE_CATEGORY_SUPPLEMENTARY, true, false],
            self::GENRE_DEPENDENT => [Genre::GENRE_CATEGORY_DOCUMENT, false, true],
        ] as $id => [$category, $supplementary, $dependent]) {
            $genre = new Genre();
            $genre->setId($id);
            $genre->setData('category', $category);
            $genre->setData('supplementary', $supplementary);
            $genre->setData('dependent', $dependent);
            $genres[$id] = $genre;
        }
        $genreDao = $this->createMock(GenreDAO::class);
        $genreDao->method('getById')->willReturnCallback(fn ($id) => $genres[$id] ?? null);
        DAORegistry::registerDAO('GenreDAO', $genreDao);
    }

    private function createSubmissionFile(int $id, string $mimetype, int $genreId = self::GENRE_ARTICLE): SubmissionFile
    {
        $submissionFile = new SubmissionFile();
        $submissionFile->setId($id);
        $submissionFile->setData('mimetype', $mimetype);
        $submissionFile->setData('genreId', $genreId);
        return $submissionFile;
    }

    public function testTheArticlePdfIsFoundAmongOtherGalleys(): void
    {
        $this->bindGenres();
        $html = $this->createSubmissionFile(100, 'text/html');
        $pdf = $this->createSubmissionFile(101, 'application/pdf');
        $this->bindSubmissionFiles([100 => $html, 101 => $pdf]);
        $publication = $this->createPublication([
            'galleys' => [$this->createGalley(5, 100), $this->createGalley(6, 101)],
        ]);

        $this->assertSame($pdf, $this->createPlugin()->getArticlePdfFile($publication));
        $this->assertNull($this->createPlugin()->validateGalleys($publication));
    }

    /**
     * A record without the full text would be metadata-only in Zenodo, which the
     * plugin refuses to create.
     */
    public function testGalleysWithoutAPdfFailTheGalleyCheck(): void
    {
        $this->bindGenres();
        $this->bindSubmissionFiles([100 => $this->createSubmissionFile(100, 'text/html')]);
        $publication = $this->createPublication(['galleys' => [$this->createGalley(5, 100)]]);

        $this->assertSame(
            ['plugins.importexport.zenodo.export.failure.noPdfGalley'],
            $this->createPlugin()->validateGalleys($publication)
        );
    }

    /**
     * A supplementary or dependent PDF is not the article, however it is labelled.
     */
    public function testSupplementaryAndDependentPdfsAreNotTheArticle(): void
    {
        $this->bindGenres();
        $this->bindSubmissionFiles([
            100 => $this->createSubmissionFile(100, 'application/pdf', self::GENRE_SUPPLEMENTARY),
            101 => $this->createSubmissionFile(101, 'application/pdf', self::GENRE_DEPENDENT),
        ]);
        $publication = $this->createPublication([
            'galleys' => [$this->createGalley(5, 100), $this->createGalley(6, 101)],
        ]);

        $this->assertNull($this->createPlugin()->getArticlePdfFile($publication));
    }

    public function testAPdfInAnotherLanguageIsNotTheArticle(): void
    {
        $this->bindGenres();
        $this->bindSubmissionFiles([100 => $this->createSubmissionFile(100, 'application/pdf')]);
        $publication = $this->createPublication(['galleys' => [$this->createGalley(5, 100, 'fr_CA')]]);

        $this->assertNull($this->createPlugin()->getArticlePdfFile($publication));
    }

    public function testARemoteGalleyIsNotTheArticle(): void
    {
        $this->bindGenres();
        $this->bindSubmissionFiles([100 => $this->createSubmissionFile(100, 'application/pdf')]);
        $remote = $this->createGalley(5, 100);
        $remote->setData('urlRemote', 'https://example.org/article.pdf');
        $publication = $this->createPublication(['galleys' => [$remote]]);

        $this->assertNull($this->createPlugin()->getArticlePdfFile($publication));
    }

    public function testAPublicationWithoutGalleysFailsTheGalleyCheck(): void
    {
        $this->bindGenres();

        $this->assertSame(
            ['plugins.importexport.zenodo.export.failure.noPdfGalley'],
            $this->createPlugin()->validateGalleys($this->createPublication())
        );
    }

    public function testASubmissionIsCheckedForGalleysThroughItsCurrentPublication(): void
    {
        $this->bindGenres();
        $this->bindSubmissionFiles([101 => $this->createSubmissionFile(101, 'application/pdf')]);
        $publication = $this->createPublication(['galleys' => [$this->createGalley(6, 101)]]);
        $submission = new Submission();
        $submission->setData('publications', collect([$publication]));
        $submission->setData('currentPublicationId', $publication->getId());

        $this->assertNull($this->createPlugin()->validateGalleys($submission));
    }

    //
    // validateDoi()
    //
    private function createPublicationWithDoi(string $doi): Publication
    {
        $doiObject = new Doi();
        $doiObject->setData('doi', $doi);
        return $this->createPublication(['doiObject' => $doiObject]);
    }

    public function testADoiPassesTheDoiCheck(): void
    {
        $this->assertNull(
            $this->createPlugin()->validateDoi($this->createPublicationWithDoi('10.1234/abc'), $this->createJournal())
        );
    }

    public function testAMissingDoiFailsTheDoiCheck(): void
    {
        $this->assertSame(
            ['plugins.importexport.zenodo.api.error.noDoi'],
            $this->createPlugin()->validateDoi($this->createPublication(), $this->createJournal())
        );
    }

    /**
     * With Zenodo minting DOIs, a record without one in OJS is still depositable.
     */
    public function testAMissingDoiPassesWhenZenodoMintsDois(): void
    {
        $this->assertNull(
            $this->createPlugin(['mintDoi' => '1'])->validateDoi($this->createPublication(), $this->createJournal())
        );
    }

    public function testASubmissionIsCheckedForADoiThroughItsCurrentPublication(): void
    {
        $publication = $this->createPublicationWithDoi('10.1234/abc');
        $submission = new Submission();
        $submission->setData('publications', collect([$publication]));
        $submission->setData('currentPublicationId', $publication->getId());

        $this->assertNull($this->createPlugin()->validateDoi($submission, $this->createJournal()));
    }

    //
    // getDepositableGalleys()
    //
    private function createGalley(int $id, ?int $submissionFileId, string $locale = 'en'): Galley
    {
        $galley = new Galley();
        $galley->setId($id);
        $galley->setData('submissionFileId', $submissionFileId);
        $galley->setData('locale', $locale);
        return $galley;
    }

    /**
     * @param array $files [submission file id => SubmissionFile] the repository knows
     */
    private function bindSubmissionFiles(array $files): void
    {
        $repository = $this->createMock(SubmissionFileRepository::class);
        $repository->method('get')->willReturnCallback(fn ($id) => $files[$id] ?? null);
        app()->instance(SubmissionFileRepository::class, $repository);
    }

    public function testGalleyFilesAreKeyedByGalleyId(): void
    {
        $pdf = new SubmissionFile();
        $pdf->setId(100);
        $html = new SubmissionFile();
        $html->setId(101);
        $this->bindSubmissionFiles([100 => $pdf, 101 => $html]);

        $publication = $this->createPublication([
            'galleys' => [$this->createGalley(5, 100), $this->createGalley(6, 101)],
        ]);

        $this->assertSame([5 => $pdf, 6 => $html], $this->createPlugin()->getDepositableGalleys($publication));
    }

    /**
     * Remote galleys carry no file, and a galley whose file has been removed is
     * nothing Zenodo can be sent either.
     */
    public function testGalleysWithoutAFileAreSkipped(): void
    {
        $pdf = new SubmissionFile();
        $pdf->setId(100);
        $this->bindSubmissionFiles([100 => $pdf]);

        $publication = $this->createPublication([
            'galleys' => [
                $this->createGalley(5, 100),
                $this->createGalley(6, null),
                $this->createGalley(7, 999),
            ],
        ]);

        $this->assertSame([5 => $pdf], $this->createPlugin()->getDepositableGalleys($publication));
    }

    public function testAPublicationWithoutGalleysHasNothingToDeposit(): void
    {
        $this->assertSame([], $this->createPlugin()->getDepositableGalleys($this->createPublication()));
    }

    //
    // Draft updates
    //
    private const API_URL = 'https://sandbox.zenodo.org/api/';
    private const RECORDS_URL = 'https://sandbox.zenodo.org/api/records';

    /**
     * Serve the given responses from the application's HTTP client, in order, and
     * collect the requests made. A 4xx or 5xx response is raised as Guzzle would.
     *
     * @param Response[] $responses
     */
    private array $history = [];

    private function mockHttp(array $responses): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $client = new Client(['handler' => $stack]);
        Registry::set(PKPTestCase::MOCKED_GUZZLE_CLIENT_NAME, $client);
    }

    private function jsonResponse(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /**
     * @return array [method, path] of each request made
     */
    private function requestsMade(): array
    {
        return array_map(
            fn ($entry) => [$entry['request']->getMethod(), $entry['request']->getUri()->getPath()],
            $this->history
        );
    }

    private function createSubmissionWithZenodoId(?string $zenodoId): Submission
    {
        $submission = new Submission();
        $submission->setId(1);
        $submission->setData('zenodo::id', $zenodoId);
        return $submission;
    }

    public function testAnExistingDraftIsUpdatedInPlace(): void
    {
        $this->mockHttp([$this->jsonResponse(200, ['id' => '123'])]);
        $plugin = $this->createPlugin();

        $result = $this->invoke($plugin, 'createOrUpdateDraft', ['{}', $this->createSubmissionWithZenodoId('123'), self::RECORDS_URL, 'key', false, '123']);

        $this->assertSame('123', $result);
        $this->assertSame([['PUT', '/api/records/123/draft']], $this->requestsMade());
    }

    /**
     * A draft the user removed in Zenodo can not be updated, so the stored id is
     * forgotten and a new draft created.
     */
    public function testADraftRemovedInZenodoIsRecreated(): void
    {
        $this->mockHttp([new Response(404), $this->jsonResponse(201, ['id' => '456'])]);
        $plugin = $this->createPlugin();
        $submission = $this->createSubmissionWithZenodoId('123');

        $result = $this->invoke($plugin, 'createOrUpdateDraft', ['{}', $submission, self::RECORDS_URL, 'key', false, '123']);

        $this->assertSame('456', $result);
        $this->assertNull($submission->getData('zenodo::id'));
        $this->assertSame([['PUT', '/api/records/123/draft'], ['POST', '/api/records']], $this->requestsMade());
    }

    public function testADraftWhoseIdentifierIsGoneIsRecreated(): void
    {
        $this->mockHttp([new Response(410), $this->jsonResponse(201, ['id' => '456'])]);
        $submission = $this->createSubmissionWithZenodoId('123');

        $result = $this->invoke($this->createPlugin(), 'createOrUpdateDraft', ['{}', $submission, self::RECORDS_URL, 'key', false, '123']);

        $this->assertSame('456', $result);
        $this->assertNull($submission->getData('zenodo::id'));
        $this->assertSame([['PUT', '/api/records/123/draft'], ['POST', '/api/records']], $this->requestsMade());
    }

    /**
     * Only a missing draft is recreated; any other failure of the update is reported.
     */
    public function testAnUpdateRefusedForAnotherReasonIsNotRecreated(): void
    {
        $this->mockHttp([new Response(403)]);
        $plugin = $this->createPlugin();
        $submission = $this->createSubmissionWithZenodoId('123');

        $result = $this->invoke($plugin, 'createOrUpdateDraft', ['{}', $submission, self::RECORDS_URL, 'key', false, '123']);

        $this->assertSame('plugins.importexport.zenodo.register.error.mdsError', $result[0][0]);
        $this->assertSame('123', $submission->getData('zenodo::id'));
        $this->assertCount(1, $this->history);
    }

    public function testANewDraftIsCreatedWithoutAStoredId(): void
    {
        $this->mockHttp([$this->jsonResponse(201, ['id' => '789'])]);

        $result = $this->invoke($this->createPlugin(), 'createOrUpdateDraft', ['{}', $this->createSubmissionWithZenodoId(null), self::RECORDS_URL, 'key', false, null]);

        $this->assertSame('789', $result);
        $this->assertSame([['POST', '/api/records']], $this->requestsMade());
    }

    public function testAPublishedRecordGetsANewDraftForTheUpdate(): void
    {
        $this->mockHttp([$this->jsonResponse(201, ['id' => '123']), $this->jsonResponse(200, ['id' => '123'])]);

        $result = $this->invoke($this->createPlugin(), 'createOrUpdateDraft', ['{}', $this->createSubmissionWithZenodoId('123'), self::RECORDS_URL, 'key', true, '123']);

        $this->assertSame('123', $result);
        $this->assertSame([['POST', '/api/records/123/draft'], ['PUT', '/api/records/123/draft']], $this->requestsMade());
    }

    public function testAFailedDraftUpdateIsRecordedAsAnError(): void
    {
        $this->mockHttp([new Response(400, [], '{"message":"A validation error occurred."}')]);
        $plugin = $this->createPlugin();
        $plugin->expects($this->once())
            ->method('updateStatus')
            ->with($this->anything(), PubObjectsExportPlugin::EXPORT_STATUS_ERROR);

        $result = $this->invoke($plugin, 'createOrUpdateDraft', ['{}', $this->createSubmissionWithZenodoId('123'), self::RECORDS_URL, 'key', false, '123']);

        $this->assertSame('plugins.importexport.zenodo.register.error.mdsError', $result[0][0]);
        $this->assertStringContainsString('400 Bad Request', $result[0][1]);
    }

    public function testDraftFilesAreDeletedByKey(): void
    {
        $this->mockHttp([
            $this->jsonResponse(200, ['entries' => [['key' => 'article.pdf'], ['key' => 'figure 1.png']]]),
            new Response(204),
            new Response(204),
        ]);

        $result = $this->invoke($this->createPlugin(), 'deleteDraftFiles', [$this->createSubmissionWithZenodoId('123'), self::RECORDS_URL, 'key', '123']);

        $this->assertTrue($result);
        $this->assertSame([
            ['GET', '/api/records/123/draft/files'],
            ['DELETE', '/api/records/123/draft/files/article.pdf'],
            ['DELETE', '/api/records/123/draft/files/figure%201.png'],
        ], $this->requestsMade());
    }

    public function testADraftWithoutFilesNeedsNoDeletion(): void
    {
        $this->mockHttp([$this->jsonResponse(200, ['entries' => []])]);

        $result = $this->invoke($this->createPlugin(), 'deleteDraftFiles', [$this->createSubmissionWithZenodoId('123'), self::RECORDS_URL, 'key', '123']);

        $this->assertTrue($result);
        $this->assertCount(1, $this->history);
    }

    public function testAFailedFileDeletionIsRecordedAsAnError(): void
    {
        $this->mockHttp([$this->jsonResponse(200, ['entries' => [['key' => 'article.pdf']]]), new Response(500)]);
        $plugin = $this->createPlugin();
        $plugin->expects($this->once())->method('updateStatus')->with($this->anything(), PubObjectsExportPlugin::EXPORT_STATUS_ERROR);

        $result = $this->invoke($plugin, 'deleteDraftFiles', [$this->createSubmissionWithZenodoId('123'), self::RECORDS_URL, 'key', '123']);

        $this->assertSame('plugins.importexport.zenodo.api.error.fileDeleteError', $result[0][0]);
    }

    //
    // Queued deposits
    //
    public function testDepositingQueuesOneJobPerObjectAndMarksItSubmitted(): void
    {
        Bus::fake();
        $plugin = $this->createPlugin(['apiKey' => 'secret']);
        $submission = $this->createSubmissionWithZenodoId(null);
        $plugin->expects($this->once())
            ->method('updateStatus')
            ->with($submission, PubObjectsExportPlugin::EXPORT_STATUS_SUBMITTED);

        $this->assertTrue($plugin->queueDeposit($submission, $this->createJournal()));

        Bus::assertDispatched(ZenodoDeposit::class, 1);
    }

    public function testNothingIsQueuedWithoutAnApiKey(): void
    {
        Bus::fake();
        $plugin = $this->createPlugin();
        $plugin->expects($this->never())->method('updateStatus');

        $result = $plugin->queueDeposit($this->createSubmissionWithZenodoId(null), $this->createJournal());

        $this->assertSame('plugins.importexport.zenodo.register.error.noApiKey', $result[0][0]);
        Bus::assertNothingDispatched();
    }

    public static function transientFailureProvider(): array
    {
        $request = new Request('POST', 'https://zenodo.org/api/records');
        return [
            'connection refused' => [new ConnectException('refused', $request), true],
            'no response' => [new RequestException('dropped', $request), true],
            'server error' => [new ServerException('boom', $request, new Response(503)), true],
            'rate limited' => [new RequestException('slow down', $request, new Response(429)), true],
            'request timeout' => [new RequestException('timeout', $request, new Response(408)), true],
            'validation refused' => [new RequestException('bad', $request, new Response(400)), false],
            'not found' => [new RequestException('gone', $request, new Response(404)), false],
            'forbidden' => [new RequestException('no', $request, new Response(403)), false],
            'any other exception' => [new Exception('boom'), false],
        ];
    }

    /**
     * Only failures a later attempt may get past are retried by the queue.
     */
    #[DataProvider('transientFailureProvider')]
    public function testTransientFailuresAreToldApart(Throwable $exception, bool $transient): void
    {
        $this->assertSame($transient, $this->createPlugin()->isTransientFailure($exception));
    }

    public function testTheLastFailureIsRememberedForTheJob(): void
    {
        $plugin = $this->createPlugin();
        $request = new Request('POST', 'https://zenodo.org/api/records');

        $this->invoke($plugin, 'getExceptionMessage', [new ServerException('boom', $request, new Response(502))]);
        $this->assertTrue($plugin->wasLastFailureTransient());

        $this->invoke($plugin, 'getExceptionMessage', [new RequestException('bad', $request, new Response(400))]);
        $this->assertFalse($plugin->wasLastFailureTransient());
    }

    //
    // Published records and communities
    //
    public function testARecordAlreadyInTheCommunityIsRecognised(): void
    {
        $this->mockHttp([$this->jsonResponse(200, ['id' => '123', 'parent' => ['communities' => ['ids' => ['c1', 'c2'], 'default' => 'c1']]])]);

        $this->assertTrue($this->createPlugin()->isRecordInCommunity($this->createSubmissionWithZenodoId('123'), '123', 'c2', self::RECORDS_URL, 'key'));
        $this->assertSame([['GET', '/api/records/123']], $this->requestsMade());
    }

    public function testARecordInOtherCommunitiesIsNotInThisOne(): void
    {
        $this->mockHttp([$this->jsonResponse(200, ['id' => '123', 'parent' => ['communities' => ['ids' => ['c1']]]])]);

        $this->assertFalse($this->createPlugin()->isRecordInCommunity($this->createSubmissionWithZenodoId('123'), '123', 'c2', self::RECORDS_URL, 'key'));
    }

    public function testARecordWithoutCommunitiesIsNotInThisOne(): void
    {
        $this->mockHttp([$this->jsonResponse(200, ['id' => '123', 'parent' => ['id' => 'p1']])]);

        $this->assertFalse($this->createPlugin()->isRecordInCommunity($this->createSubmissionWithZenodoId('123'), '123', 'c2', self::RECORDS_URL, 'key'));
    }

    public function testAFailedCommunityCheckIsAnError(): void
    {
        $this->mockHttp([new Response(500)]);
        $plugin = $this->createPlugin();
        $plugin->expects($this->once())->method('updateStatus')->with($this->anything(), PubObjectsExportPlugin::EXPORT_STATUS_ERROR);

        $result = $plugin->isRecordInCommunity($this->createSubmissionWithZenodoId('123'), '123', 'c2', self::RECORDS_URL, 'key');

        $this->assertSame('plugins.importexport.zenodo.api.error.communityCheckError', $result[0][0]);
    }

    public function testSubmittingAPublishedRecordReturnsTheInclusionRequestId(): void
    {
        $this->mockHttp([$this->jsonResponse(200, ['processed' => [['community' => 'c2', 'request_id' => 'r9']]])]);

        $this->assertSame(
            'r9',
            $this->createPlugin()->submitReviewPublished($this->createSubmissionWithZenodoId('123'), '123', self::RECORDS_URL, 'key', 'c2')
        );
        $this->assertSame([['POST', '/api/records/123/communities']], $this->requestsMade());
    }

    /**
     * Zenodo refuses a record that is already included or already requested. That
     * is the state the plugin wanted, not a failure to record and retry.
     */
    public function testAnAlreadyIncludedRefusalIsNotAnError(): void
    {
        $this->mockHttp([new Response(400, [], '{"errors": [{"community": "c2", "message": "The record is already included in this community."}]}')]);
        $plugin = $this->createPlugin();
        $plugin->expects($this->never())->method('updateStatus');

        $this->assertNull($plugin->submitReviewPublished($this->createSubmissionWithZenodoId('123'), '123', self::RECORDS_URL, 'key', 'c2'));
    }

    /**
     * A pending inclusion request the plugin did not record can not be accepted, so
     * the caller is told it is open rather than handed a null id.
     */
    public function testAPendingInclusionRequestIsReportedAsOpen(): void
    {
        $this->mockHttp([new Response(400, [], '{"errors": [{"community": "c2", "message": "There is already an open inclusion request for this community."}]}')]);
        $plugin = $this->createPlugin();
        $plugin->expects($this->never())->method('updateStatus');

        $this->assertSame(
            ZenodoExportPlugin::REVIEW_OPEN,
            $plugin->submitReviewPublished($this->createSubmissionWithZenodoId('123'), '123', self::RECORDS_URL, 'key', 'c2')
        );
    }

    public function testAnyOtherCommunitySubmissionFailureIsAnError(): void
    {
        $this->mockHttp([new Response(403, [], '{"message":"Permission denied."}')]);
        $plugin = $this->createPlugin();
        $plugin->expects($this->once())->method('updateStatus')->with($this->anything(), PubObjectsExportPlugin::EXPORT_STATUS_ERROR);

        $result = $plugin->submitReviewPublished($this->createSubmissionWithZenodoId('123'), '123', self::RECORDS_URL, 'key', 'c2');

        $this->assertSame('plugins.importexport.zenodo.api.error.submitPublishedCommunityError', $result[0][0]);
    }

    public function testAPublishedRecordWithoutAStoredRequestIsNotLookedUp(): void
    {
        $this->mockHttp([]);

        $this->assertNull($this->createPlugin()->getReviewRequest($this->createSubmissionWithZenodoId('123'), '123', self::API_URL, 'key', true));
        $this->assertSame([], $this->requestsMade());
    }

    //
    // isRecordPublished()
    //
    public function testAPublishedRecordIsReported(): void
    {
        $this->mockHttp([$this->jsonResponse(200, ['id' => '123', 'is_published' => true])]);

        $this->assertTrue($this->createPlugin()->isRecordPublished($this->createSubmissionWithZenodoId('123'), '123', self::RECORDS_URL));
        $this->assertSame([['GET', '/api/records/123']], $this->requestsMade());
    }

    public function testAnUnpublishedDraftIsNotPublished(): void
    {
        $this->mockHttp([new Response(404)]);

        $this->assertFalse($this->createPlugin()->isRecordPublished($this->createSubmissionWithZenodoId('123'), '123', self::RECORDS_URL));
    }

    /**
     * A record deleted in Zenodo answers with a tombstone, which the deposit treats
     * as a record to replace rather than as a failure.
     */
    public function testADeletedRecordIsReportedAsDeleted(): void
    {
        $this->mockHttp([new Response(410, [], '{"status": 410, "message": "Record deleted", "tombstone": {"note": ""}}')]);
        $plugin = $this->createPlugin();
        $plugin->expects($this->never())->method('updateStatus');

        $this->assertSame(
            ZenodoExportPlugin::RECORD_DELETED,
            $plugin->isRecordPublished($this->createSubmissionWithZenodoId('123'), '123', self::RECORDS_URL)
        );
    }

    public function testAFailedPublishCheckIsAnError(): void
    {
        $this->mockHttp([new Response(500)]);
        $plugin = $this->createPlugin();
        $plugin->expects($this->once())->method('updateStatus')->with($this->anything(), PubObjectsExportPlugin::EXPORT_STATUS_ERROR);

        $result = $plugin->isRecordPublished($this->createSubmissionWithZenodoId('123'), '123', self::RECORDS_URL);

        $this->assertSame('plugins.importexport.zenodo.api.error.publishCheckError', $result[0][0]);
    }

    public function testForgettingARecordClearsItsIdAndRequest(): void
    {
        $submission = $this->createSubmissionWithZenodoId('123');
        $submission->setData('zenodo::reviewRequestId', 'r1');

        $this->createPlugin()->removeZenodoId($submission);

        $this->assertNull($submission->getData('zenodo::id'));
        $this->assertNull($submission->getData('zenodo::reviewRequestId'));
    }

    //
    // Review requests
    //
    /**
     * A request the plugin submitted is looked up by its stored id through the
     * requests API, which is documented and answers reliably.
     */
    public function testAStoredReviewRequestIsLookedUpDirectly(): void
    {
        $this->mockHttp([$this->jsonResponse(200, ['id' => 'r1', 'status' => 'submitted', 'is_open' => true, 'is_closed' => false])]);
        $submission = $this->createSubmissionWithZenodoId('123');
        $submission->setData('zenodo::reviewRequestId', 'r1');

        $this->assertSame(
            ['id' => 'r1', 'status' => 'submitted', 'is_open' => true],
            $this->createPlugin()->getReviewRequest($submission, '123', self::API_URL, 'key')
        );
        $this->assertSame([['GET', '/api/requests/r1']], $this->requestsMade());
    }

    /**
     * Without a stored id the request is read from the draft's parent. Zenodo's own
     * review endpoint is never used because it answers with a 500.
     */
    public function testAnUnstoredReviewRequestIsReadFromTheDraft(): void
    {
        $this->mockHttp([$this->jsonResponse(200, [
            'id' => '123',
            'parent' => ['review' => ['id' => 'r1', 'status' => 'submitted', 'is_open' => true, 'is_closed' => false]],
        ])]);

        $this->assertSame(
            ['id' => 'r1', 'status' => 'submitted', 'is_open' => true],
            $this->createPlugin()->getReviewRequest($this->createSubmissionWithZenodoId('123'), '123', self::API_URL, 'key')
        );
        $this->assertSame([['GET', '/api/records/123/draft']], $this->requestsMade());
    }

    public function testAClosedReviewRequestIsNotOpen(): void
    {
        $this->mockHttp([$this->jsonResponse(200, ['id' => 'r1', 'status' => 'declined', 'is_open' => false, 'is_closed' => true])]);
        $submission = $this->createSubmissionWithZenodoId('123');
        $submission->setData('zenodo::reviewRequestId', 'r1');

        $review = $this->createPlugin()->getReviewRequest($submission, '123', self::API_URL, 'key');

        $this->assertFalse($review['is_open']);
    }

    public function testADraftWithoutAReviewRequestReturnsNothing(): void
    {
        $this->mockHttp([$this->jsonResponse(200, ['id' => '123', 'parent' => ['id' => 'p1']])]);

        $this->assertNull($this->createPlugin()->getReviewRequest($this->createSubmissionWithZenodoId('123'), '123', self::API_URL, 'key'));
    }

    /**
     * Zenodo's refusal to replace an open request the plugin could not see is
     * recognised, not recorded as a failed deposit.
     */
    public function testTheOpenReviewRefusalIsRecognised(): void
    {
        $this->mockHttp([new Response(400, [], '{"status": 400, "message": "An open review cannot be deleted."}')]);
        $plugin = $this->createPlugin();
        $plugin->expects($this->never())->method('updateStatus');

        $this->assertSame(
            ZenodoExportPlugin::REVIEW_OPEN,
            $plugin->createReview($this->createSubmissionWithZenodoId('123'), '123', 'community', self::RECORDS_URL, 'key')
        );
    }

    public function testAnyOtherReviewCreationFailureIsAnError(): void
    {
        $this->mockHttp([new Response(403, [], '{"message":"Permission denied."}')]);
        $plugin = $this->createPlugin();
        $plugin->expects($this->once())->method('updateStatus')->with($this->anything(), PubObjectsExportPlugin::EXPORT_STATUS_ERROR);

        $result = $plugin->createReview($this->createSubmissionWithZenodoId('123'), '123', 'community', self::RECORDS_URL, 'key');

        $this->assertSame('plugins.importexport.zenodo.api.error.createReviewError', $result[0][0]);
    }

    /**
     * Zenodo refuses to replace or delete an open review request, so a failed check must
     * stop the deposit rather than be taken as "no request" and lead to that refusal.
     */
    public function testAFailedReviewCheckIsReportedNotIgnored(): void
    {
        $this->mockHttp([new Response(500)]);
        $plugin = $this->createPlugin();
        $plugin->expects($this->once())->method('updateStatus')->with($this->anything(), PubObjectsExportPlugin::EXPORT_STATUS_ERROR);

        $result = $plugin->getReviewRequest($this->createSubmissionWithZenodoId('123'), '123', self::API_URL, 'key');

        $this->assertSame('plugins.importexport.zenodo.api.error.reviewCheckError', $result['error'][0]);
    }

    public function testCancellingAReviewRequestPostsTheCancelAction(): void
    {
        $this->mockHttp([$this->jsonResponse(200, ['id' => 'r1', 'status' => 'cancelled'])]);

        $this->assertTrue($this->createPlugin()->cancelReviewRequest('r1', 'https://sandbox.zenodo.org/api/', 'key'));
        $this->assertSame([['POST', '/api/requests/r1/actions/cancel']], $this->requestsMade());
    }
}
