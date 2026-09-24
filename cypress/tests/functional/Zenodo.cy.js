/**
 * @file cypress/tests/functional/Zenodo.cy.js
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Functional tests for the Zenodo plugin.
 */

describe('Zenodo plugin tests', function () {

	it('Configures the plugin, shows the export tab, and exports InvenioRDM JSON', function() {
		cy.login('admin', 'admin', 'publicknowledge');

		cy.get('nav').contains('Settings').click();
		cy.get('nav').contains('Website').click({force: true});
		cy.get('button[id="plugins-button"]').click();

		// Enable the plugin unless it already is.
		cy.get('input[id^="select-cell-zenodoplugin-enabled"]').then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).check();
				cy.contains(/The plugin "Zenodo Plugin" has been enabled\./i, {timeout: 20000});
			}
		});
		cy.get('input[id^="select-cell-zenodoplugin-enabled"]').should('be.checked');
		cy.reload();

		// Open the import/export plugin page.
		cy.get('nav').contains('Tools').click();
		cy.contains(/Zenodo Export Plugin/i, {timeout: 20000}).click();

		// The settings form has no required fields: the API key only gates the
		// deposit action, and the JSON export does not need it.
		cy.waitJQuery({timeout: 20000});
		cy.get('form#zenodoSettingsForm', {timeout: 20000}).should('be.visible');
		cy.get('form#zenodoSettingsForm button:contains("Save")').click();
		cy.contains('Your changes have been saved.');
		cy.waitJQuery({timeout: 20000});

		// Verify an export tab is present (articles, or publications when the
		// journal versions its DOIs).
		cy.get('a[href="#exportSubmissions-tab"], a[href="#exportPublications-tab"]').should('exist');

		// Drive the export via cy.request and check the InvenioRDM record.
		cy.getCsrfToken();
		cy.get('@csrfToken').then((csrfToken) => {
			cy.request({
				url: '/index.php/publicknowledge/en/management/importexport/plugin/ZenodoExportPlugin/exportSubmissions',
				method: 'POST',
				headers: {
					'X-Csrf-Token': csrfToken,
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: [
					`csrfToken=${encodeURIComponent(csrfToken)}`,
					'tab=exportSubmissions-tab',
					'selectedSubmissions%5B%5D=1',
					'export=1',
				].join('&'),
				timeout: 60000,
				followRedirect: false,
			}).then((response) => {
				expect(response.status).to.eq(200);
				expect(response.headers['content-type']).to.include('application/json');

				const records = typeof response.body === 'string' ? JSON.parse(response.body) : response.body;
				expect(records).to.have.length(1);

				const record = records[0];
				expect(record.access.record).to.eq('public');
				expect(record.files.enabled).to.eq(true);
				expect(record.metadata.resource_type.id).to.eq('publication-article');
				expect(record.metadata.title).to.be.a('string').and.not.be.empty;
				expect(record.metadata.creators).to.have.length.gt(0);
				expect(record.metadata.publication_date).to.match(/^\d{4}-\d{2}-\d{2}$/);
				expect(record.custom_fields['journal:journal'].title).to.be.a('string').and.not.be.empty;
			});
		});
	});
});
