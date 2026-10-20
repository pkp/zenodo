<?php

/**
 * @file plugins/generic/zenodo/controllers/grid/ZenodoPublicationsListGridHandler.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ZenodoPublicationsListGridHandler
 *
 * @brief Export grid of published publications with a Zenodo record column.
 */

namespace APP\plugins\generic\zenodo\controllers\grid;

use APP\controllers\grid\publications\ExportPublishedPublicationsListGridHandler;
use APP\core\Application;
use PKP\controllers\grid\GridColumn;

class ZenodoPublicationsListGridHandler extends ExportPublishedPublicationsListGridHandler
{
    /**
     * @copydoc ExportPublishedPublicationsListGridHandler::getGridCellProvider()
     */
    public function getGridCellProvider()
    {
        $authorizedRoles = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
        return new ZenodoPublicationsListGridCellProvider($this->_plugin, $authorizedRoles);
    }

    /**
     * Add the Zenodo record column before the status column.
     */
    public function addAdditionalColumns($cellProvider): void
    {
        $this->addColumn(
            new GridColumn(
                'zenodoId',
                'plugins.importexport.zenodo.grid.recordId',
                null,
                null,
                $cellProvider,
                ['alignment' => GridColumn::COLUMN_ALIGNMENT_LEFT,
                    'width' => 10]
            )
        );
    }
}
