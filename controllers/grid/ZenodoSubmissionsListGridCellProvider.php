<?php

/**
 * @file plugins/generic/zenodo/controllers/grid/ZenodoSubmissionsListGridCellProvider.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ZenodoSubmissionsListGridCellProvider
 *
 * @brief Cell provider for the Zenodo export grid of published submissions, linking each row to its Zenodo record.
 */

namespace APP\plugins\generic\zenodo\controllers\grid;

use APP\controllers\grid\submissions\ExportPublishedSubmissionsListGridCellProvider;
use PKP\controllers\grid\GridHandler;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\OpenWindowAction;

class ZenodoSubmissionsListGridCellProvider extends ExportPublishedSubmissionsListGridCellProvider
{
    /**
     * @copydoc ExportPublishedSubmissionsListGridCellProvider::getCellActions()
     */
    public function getCellActions($request, $row, $column, $position = GridHandler::GRID_ACTION_POSITION_DEFAULT)
    {
        if ($column->getId() !== 'zenodoId') {
            return parent::getCellActions($request, $row, $column, $position);
        }

        $object = $row->getData();
        $recordUrl = $this->_plugin->getRecordUrl($object, $request->getContext());
        if (!$recordUrl) {
            return [];
        }

        return [new LinkAction(
            'zenodoRecord',
            new OpenWindowAction($recordUrl, '_blank'),
            $object->getData($this->_plugin->getIdSettingName())
        )];
    }

    /**
     * @copydoc ExportPublishedSubmissionsListGridCellProvider::getTemplateVarsFromRowColumn()
     */
    public function getTemplateVarsFromRowColumn($row, $column)
    {
        // The record id is the link text of the cell action.
        return $column->getId() === 'zenodoId'
            ? ['label' => '']
            : parent::getTemplateVarsFromRowColumn($row, $column);
    }
}
