<?php

/**
 * @file plugins/generic/zenodo/tests/ZenodoGridCellProviderTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Unit tests for the Zenodo record column of the export grids.
 */

namespace APP\plugins\generic\zenodo\tests;

use APP\core\Request;
use APP\journal\Journal;
use APP\plugins\generic\zenodo\controllers\grid\ZenodoPublicationsListGridCellProvider;
use APP\plugins\generic\zenodo\controllers\grid\ZenodoSubmissionsListGridCellProvider;
use APP\plugins\generic\zenodo\ZenodoExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\controllers\grid\GridColumn;
use PKP\controllers\grid\GridRow;
use PKP\core\DataObject;
use PKP\linkAction\request\OpenWindowAction;
use PKP\tests\PKPTestCase;

#[CoversClass(ZenodoPublicationsListGridCellProvider::class)]
#[CoversClass(ZenodoSubmissionsListGridCellProvider::class)]
class ZenodoGridCellProviderTest extends PKPTestCase
{
    private function createPlugin(): ZenodoExportPlugin
    {
        $plugin = $this->getMockBuilder(ZenodoExportPlugin::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSetting'])
            ->getMock();
        $plugin->method('getSetting')->willReturnCallback(fn ($contextId, $name) => $name === 'testMode' ? '1' : null);
        return $plugin;
    }

    private function createRequest(): Request
    {
        $journal = new Journal();
        $journal->setId(1);
        $request = $this->createMock(Request::class);
        $request->method('getContext')->willReturn($journal);
        return $request;
    }

    private function createRow(DataObject $object): GridRow
    {
        $row = new GridRow();
        $row->setData($object);
        return $row;
    }

    private function zenodoColumn(): GridColumn
    {
        return new GridColumn('zenodoId', 'plugins.importexport.zenodo.grid.recordId');
    }

    public function testADepositedPublicationLinksToItsRecord(): void
    {
        $publication = new Publication();
        $publication->setData('zenodo::id', '614398');
        $provider = new ZenodoPublicationsListGridCellProvider($this->createPlugin());

        $actions = $provider->getCellActions($this->createRequest(), $this->createRow($publication), $this->zenodoColumn());

        $this->assertCount(1, $actions);
        $this->assertSame('614398', $actions[0]->getTitle());
        $this->assertInstanceOf(OpenWindowAction::class, $actions[0]->getActionRequest());
        $this->assertSame('https://sandbox.zenodo.org/records/614398', $actions[0]->getActionRequest()->getUrl());
    }

    public function testAPublicationWithoutARecordHasNoLink(): void
    {
        $provider = new ZenodoPublicationsListGridCellProvider($this->createPlugin());

        $this->assertSame([], $provider->getCellActions($this->createRequest(), $this->createRow(new Publication()), $this->zenodoColumn()));
    }

    public function testADepositedSubmissionLinksToItsRecord(): void
    {
        $submission = new Submission();
        $submission->setData('zenodo::id', '607149');
        $provider = new ZenodoSubmissionsListGridCellProvider($this->createPlugin());

        $actions = $provider->getCellActions($this->createRequest(), $this->createRow($submission), $this->zenodoColumn());

        $this->assertSame('607149', $actions[0]->getTitle());
    }

    public function testTheRecordColumnHasNoLabelBesideItsLink(): void
    {
        $provider = new ZenodoPublicationsListGridCellProvider($this->createPlugin());

        $this->assertSame(['label' => ''], $provider->getTemplateVarsFromRowColumn($this->createRow(new Publication()), $this->zenodoColumn()));
    }
}
