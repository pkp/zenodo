# Zenodo Plugin for OJS

An OJS plugin for exporting articles to [Zenodo](https://zenodo.org/).

## Compatibility

Compatible with OJS 3.6 and later.

## Installation

### For Development

- Create [a Zenodo sandbox account and API key](https://sandbox.zenodo.org)
- Copy the plugin files to `plugins/generic/zenodo`
- Run the installation tool: `php lib/pkp/tools/installPluginVersion.php plugins/generic/zenodo/version.xml`
- Set Zenodo plugin settings in Tools > Zenodo Export Plugin:
  - Enter the API key from your sandbox account
  - Enable test mode
  - If you don't have DOIs set up for your publications, enable Zenodo DOIs

## Requirements

Deposits are dispatched as queued jobs, so the installation needs a way of running them: either OJS's
scheduled `ProcessQueueJobs` task (which runs with the rest of the scheduler) or a dedicated worker,
`php lib/pkp/tools/jobs.php work`.

## Zenodo API

This plugin uses the Invenio RDM API and does not use Zenodo's legacy API. Refer to
[the Invenio RDM documentation](https://inveniordm.docs.cern.ch/reference/rest_api_index/) for more details.

## Deposit Workflow

Deposits are queued: clicking Deposit (or the automatic deposit task running) dispatches one job per record,
which builds the record's metadata, creates or updates the draft in Zenodo and uploads the galley files. The
request returns as soon as the jobs are queued, so a slow Zenodo never blocks the browser, and each record's
outcome is recorded against it individually. A record waiting on its job shows the Submitted status; when
the job runs it becomes Deposited, or Failed with the error message. A deposit that fails because Zenodo
could not be reached, timed out, rate-limited the request or answered with a server error is retried by
the queue; a deposit Zenodo refused is not.

A workflow diagram is available in the `docs` directory which outlines the steps in the plugin's workflow from selection
of a record to deposit in Zenodo.

## Using the Plugin

### Required Metadata

Zenodo requires a title, at least one author and a publication date, and the plugin requires an article
PDF so that every record carries the full text: a PDF galley in the article's language whose file type is
the article text (not a supplementary or dependent file). A record missing any of these is not sent; it is
marked as failed with a message naming what is missing. Metadata-only records are never created. The
journal's publisher and ISSN are included in every record, and the plugin page shows a reminder when
either is not set.

### Contributors

Each contributor is listed once. Contributors with the Author role are the record's creators, whatever other
roles they hold. The others become contributor entries in Zenodo with one role: Editor has an equivalent and
takes priority, and the rest (translators, reviewers, chairs, readers, other) are sent as "Other", since
Zenodo's vocabulary has no translator role. CRediT roles have no equivalent in Zenodo's
vocabulary and are not sent. An organization contributor's ROR ID is sent when it is a ROR ID or a
ror.org URL; other text in that field is ignored.

### DOIs

By default, the plugin expects that exported records have a DOI, and records will not be exported if a DOI
is not set. Zenodo is able to mint their own DOIs, and this option can be enabled in the plugin settings.
The DOI minted in Zenodo is not saved in OJS.

### DOI Versioning

If DOI versioning is enabled in OJS, then the user can deposit each major version of an article to Zenodo as an
individual record. The previous version will be included in the relations metadata.

### Updating a Deposit

Depositing a record again updates the draft in Zenodo in place, so it keeps its Zenodo identifier, any
DOI Zenodo reserved for it and any community review request that is still open. The draft's files are
replaced with the current galley files. If the draft was removed in Zenodo, a new one is created. Once a
record is published in Zenodo, later deposits update its metadata only, since Zenodo does not allow the
files of a published record to change.

A published record that is already in the community, or whose inclusion request is still pending, is not
submitted to it again when its metadata is updated.

If a published record is deleted in Zenodo, only a tombstone remains and it can not be updated. The next
deposit then creates a new record. Zenodo releases an external DOI when the record is deleted, so the new
record carries the article's DOI from OJS; a DOI minted by Zenodo stays with the tombstone and the new
record receives another one.

A draft that has been submitted to a community keeps its review request across deposits: Zenodo does not
allow an open request to be replaced, deleted or published over, so the record is published when the
community accepts it. To submit the record to a different community, cancel the open request in Zenodo
first.

### Automatic Publishing

By default, the plugin will create a draft record in Zenodo, which can then be published in the Zenodo application.
This allows users to review the accuracy of the record or add additional metadata before publishing. This plugin includes
a setting for automatic publishing, but it's important to note that a record in Zenodo
**can't easily be deleted once it has been published** (metadata can be updated for the record).

### Funder Metadata

If the Funder metadata is enabled, the plugin adds funding metadata to the exported record. A funder is
sent by its ROR ID when it has one, otherwise by name. Each of the funder's grants becomes an award:

- A grant number that Zenodo's awards database knows for that funder is sent as that award, so Zenodo
  links it to the funder's programme.
- Otherwise the grant is sent as a custom award with its number and name, and its DOI as an identifier.
  Zenodo requires a custom award to have a number or a name; a grant that has only a DOI is left out of
  the award, though the funder itself is still sent.

### Embargoes and Restricted Data

If an article is embargoed and sent to Zenodo, the same embargo date will be set in Zenodo. If an article is
only accessible via a subscription model, then the data will be set as restricted in Zenodo.

### Communities

If a community is enabled in the plugin settings, the plugin will attempt to submit the record to the community in
Zenodo. Depending on the community settings, the record may be published immediately or may be published after
review. If the community submission fails for any reason, such as insufficient permissions or an API error,
the record will still be exported to Zenodo and the status and identifier will be saved.

## Tests

Unit tests live in `tests/` and a Cypress functional test in `cypress/tests/functional/`. Both run in
the GitHub workflow through `.github/actions/tests.sh`. To run them from the OJS root:

```bash
php lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml plugins/generic/zenodo/tests
npx cypress run --config '{"specPattern":["plugins/generic/zenodo/cypress/tests/functional/*.cy.js"]}'
```

## License

This plugin is licensed under the GNU General Public License v3.
