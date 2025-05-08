{**
 * plugins/importexport/zenodo/templates/settingsForm.tpl
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Zenodo plugin settings
 *
 *}
<script type="text/javascript">
	$(function() {ldelim}
		// Attach the form handler.
		$('#zenodoSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>
<form class="pkp_form" id="zenodoSettingsForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" plugin="ZenodoExportPlugin" category="importexport" verb="save"}">
	{csrf}
	{fbvFormArea id="zenodoSettingsFormArea"}
		{fbvFormSection}
			<p class="pkp_help">
				{translate key="plugins.importexport.zenodo.registrationIntro"}
			</p>
			{fbvElement type="text" id="apiKey" value=$apiKey label="plugins.importexport.zenodo.settings.form.apiKey" maxlength="100" size=$fbvStyles.size.MEDIUM}
			<span class="instruct">
				{translate key="plugins.importexport.zenodo.settings.form.apiKey.description"}
			</span>
			<br/>
		{/fbvFormSection}
		{fbvFormSection list="true"}
			{fbvElement type="checkbox" id="automaticRegistration" label="plugins.importexport.zenodo.settings.form.automaticRegistration.description" checked=$automaticRegistration|compare:true}
		{/fbvFormSection}
		{fbvFormSection list="true"}
			{fbvElement type="checkbox" id="mintDoi" label="plugins.importexport.zenodo.settings.form.doi.description" checked=$mintDoi|compare:true}
		{/fbvFormSection}
		{fbvFormSection list="true"}
			{fbvElement type="checkbox" id="testMode" label="plugins.importexport.zenodo.settings.form.testMode.description" checked=$testMode|compare:true}
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save"}
	<p>
		<span class="formRequired">{translate key="common.requiredField"}</span>
	</p>
</form>
