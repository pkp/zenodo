{**
 * @file plugins/generic/zenodo/index.tpl
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * List of operations this plugin can perform
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle}
	</h1>

	{if !empty($configurationErrors)}
		{assign var="allowExport" value=false}
	{else}
		{assign var="allowExport" value=true}
	{/if}

	<script type="text/javascript">
		// Attach the JS file tab handler.
		$(function() {ldelim}
			$('#importExportTabs').pkpHandler('$.pkp.controllers.TabHandler');
		{rdelim});
	</script>
	<div id="importExportTabs">
		<ul>
			<li><a href="#settings-tab">{translate key="plugins.importexport.common.settings"}</a></li>
			{if $allowExport}
				{if $doiVersioning}
					<li><a href="#exportPublications-tab">{translate key="plugins.importexport.common.export.publications"}</a></li>
				{else}
					<li><a href="#exportSubmissions-tab">{translate key="plugins.importexport.common.export.articles"}</a></li>
				{/if}
			{/if}
		</ul>
		<div id="settings-tab">
			{if !$allowExport}
				<div class="pkp_notification" id="zenodoConfigurationErrors">
					{foreach from=$configurationErrors item=configurationError}
						{if $configurationError == APP\plugins\PubObjectsExportPlugin::EXPORT_CONFIG_ERROR_SETTINGS}
							{include file="controllers/notification/inPlaceNotificationContent.tpl" notificationId=zenodoConfigurationErrors notificationStyleClass="notifyWarning" notificationTitle="plugins.importexport.common.missingRequirements"|translate notificationContents="plugins.importexport.common.error.pluginNotConfigured"|translate}
						{/if}
					{/foreach}
				</div>
			{/if}
			<p class="">
				<a href="https://zenodo.org/signup/" target="_blank">{translate key="plugins.importexport.zenodo.export.createAccount"}</a>
			</p>
			{capture assign=zenodoSettingsGridUrl}{url router=PKP\core\PKPApplication::ROUTE_COMPONENT component="grid.settings.plugins.settingsPluginGridHandler" op="manage" plugin="ZenodoExportPlugin" category="importexport" verb="index" escape=false}{/capture}
			{load_url_in_div id="zenodoSettingsGridContainer" url=$zenodoSettingsGridUrl}
		</div>
		{if $allowExport}
			{if $doiVersioning}
				<div id="exportPublications-tab">
					<script type="text/javascript">
						$(function() {ldelim}
							// Attach the form handler.
							$('#exportPublicationXmlForm').pkpHandler('$.pkp.controllers.form.FormHandler');
							{rdelim});
					</script>
					<form id="exportPublicationXmlForm" class="pkp_form" action="{plugin_url path="exportPublications"}" method="post">
						{csrf}
						<input type="hidden" name="tab" value="exportPublications-tab" />
						{fbvFormArea id="publicationsXmlForm"}
						{capture assign=publicationsListGridUrl}{url router=PKP\core\PKPApplication::ROUTE_COMPONENT component="grid.publications.ExportPublishedPublicationsListGridHandler" op="fetchGrid" plugin="ZenodoExportPlugin" category="importexport" escape=false}{/capture}
						{load_url_in_div id="publicationsListGridContainer" url=$publicationsListGridUrl}
						{fbvFormSection list="true"}
						{fbvElement type="checkbox" id="validation" label="plugins.importexport.common.validation" checked=$validation|default:true}
						{/fbvFormSection}
						{if !empty($actionNames)}
							{fbvFormSection}
								<ul class="export_actions">
									{foreach from=$actionNames key=action item=actionName}
										<li class="export_action">
											{fbvElement type="submit" label="$actionName" id="$action" name="$action" value="1" class="$action" translate=false inline=true}
										</li>
									{/foreach}
								</ul>
							{/fbvFormSection}
						{/if}
						{/fbvFormArea}
					</form>
				</div>
			{else}
				<div id="exportSubmissions-tab">
					<script type="text/javascript">
						$(function() {ldelim}
							// Attach the form handler.
							$('#exportSubmissionXmlForm').pkpHandler('$.pkp.controllers.form.FormHandler');
						{rdelim});
					</script>
					<form id="exportSubmissionXmlForm" class="pkp_form" action="{plugin_url path="exportSubmissions"}" method="post">
						{csrf}
						<input type="hidden" name="tab" value="exportSubmissions-tab" />
						{fbvFormArea id="submissionsXmlForm"}
							{capture assign=submissionsListGridUrl}{url router=PKP\core\PKPApplication::ROUTE_COMPONENT component="grid.submissions.ExportPublishedSubmissionsListGridHandler" op="fetchGrid" plugin="ZenodoExportPlugin" category="importexport" escape=false}{/capture}
							{load_url_in_div id="submissionsListGridContainer" url=$submissionsListGridUrl}
							{if !empty($actionNames)}
								{fbvFormSection}
								<ul class="export_actions">
									{foreach from=$actionNames key=action item=actionName}
										<li class="export_action">
											{fbvElement type="submit" label="$actionName" id="$action" name="$action" value="1" class="$action" translate=false inline=true}
										</li>
									{/foreach}
								</ul>
								{/fbvFormSection}
							{/if}
						{/fbvFormArea}
					</form>
				</div>
			{/if}
		{/if}
	</div>
{/block}
