<?php

/**
 * @file plugins/generic/zenodo/controllers/grid/ZenodoSubmissionsListGridHandler.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ZenodoSubmissionsListGridHandler
 *
 * @brief Export grid of published submissions with a Zenodo record column.
 */

namespace APP\plugins\generic\zenodo\controllers\grid;

use APP\controllers\grid\submissions\ExportPublishedSubmissionsListGridHandler;
use APP\core\Application;
use PKP\controllers\grid\GridColumn;

class ZenodoSubmissionsListGridHandler extends ExportPublishedSubmissionsListGridHandler
{
    /**
     * @copydoc ExportPublishedSubmissionsListGridHandler::getGridCellProvider()
     */
    public function getGridCellProvider()
    {
        $authorizedRoles = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
        return new ZenodoSubmissionsListGridCellProvider($this->_plugin, $authorizedRoles);
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
