<?php

/**
 * @file plugins/generic/zenodo/tests/ZenodoSettingsFormTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Unit tests for the Zenodo settings form.
 */

namespace APP\plugins\generic\zenodo\tests;

use APP\plugins\generic\zenodo\classes\form\ZenodoSettingsForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;

#[CoversClass(ZenodoSettingsForm::class)]
class ZenodoSettingsFormTest extends PKPTestCase
{
    /**
     * The setting types accepted by DAO::convertToDB(), which is what
     * Plugin::updateSetting() ultimately hands the declared type to.
     */
    private const SETTING_TYPES = [
        'bool', 'boolean', 'int', 'integer', 'float', 'number',
        'object', 'array', 'date', 'string',
    ];

    /**
     * The constructor registers a validator that looks the community up in Zenodo,
     * so the form is built without it.
     */
    private function createForm(): ZenodoSettingsForm
    {
        return $this->getMockBuilder(ZenodoSettingsForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * The plugin has no required settings: the API key is only needed for depositing,
     * which ZenodoExportPlugin::getExportActions() gates separately. If any field became
     * non-optional, the parent's display() would raise EXPORT_CONFIG_ERROR_SETTINGS and
     * hide the export tab until it was filled in.
     */
    public function testEveryFormFieldIsOptional(): void
    {
        $form = $this->createForm();

        foreach (array_keys($form->getFormFields()) as $fieldName) {
            $this->assertTrue($form->isOptional($fieldName), "Setting '{$fieldName}' should be optional");
        }
    }

    public function testFormFieldsAreTheExpectedSettings(): void
    {
        $this->assertSame([
            'apiKey' => 'string',
            'automaticPublishing' => 'bool',
            'automaticPublishingCommunity' => 'bool',
            'automaticRegistration' => 'bool',
            'community' => 'string',
            'mintDoi' => 'bool',
            'testMode' => 'bool',
        ], $this->createForm()->getFormFields());
    }

    /**
     * execute() passes each declared type straight to Plugin::updateSetting(). A
     * type DAO::convertToDB() does not recognise is stored as a serialised string
     * rather than failing, so a typo here would only surface as a corrupt setting.
     */
    public function testEveryFieldDeclaresAStorableType(): void
    {
        foreach ($this->createForm()->getFormFields() as $fieldName => $fieldType) {
            $this->assertContains($fieldType, self::SETTING_TYPES, "Setting '{$fieldName}' declares an unsupported type '{$fieldType}'");
        }
    }
}
