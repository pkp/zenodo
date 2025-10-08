# Zenodo Plugin for OJS

_This plugin is in development and should not be used in a production environment._

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
- To test funding metadata, [the funding metadata plugin](https://github.com/ajnyga/funding) must be installed and enabled.

## Zenodo API

This plugin uses the Invenio RDM API and does not use Zenodo's legacy API. Refer to
[the Invenio RDM documentation](https://inveniordm.docs.cern.ch/reference/rest_api_index/) for more details.

## Using the Plugin

### DOIs

By default, the plugin expects that exported records have a DOI, and will not be exported if a DOI
is not available. Zenodo is able to mint their own DOIs, and this can be enabled in the plugin settings.
The DOI minted in Zenodo does not get saved in OJS.

### Automatic Publishing

By default, the plugin will create a draft record in Zenodo, which can then be published in the Zenodo application.
This allows users to review the accuracy of the record or add additional metadata before publishing. This plugin includes
a setting for automatic publishing, but it's important to note that a record in Zenodo
**can't easily be deleted once it has been published** (metadata can be updated for the record).

### Funding Metadata

If the [funding metadata plugin](https://github.com/ajnyga/funding) is installed and enabled, the plugin will
attempt to add funding metadata to the exported record. Only funding metadata which is supported by Zenodo will be
included in the exported record. The ROR API is used to look up ROR IDs for funders, as the
funding plugin currently uses DOIs for funders.

### Embargoes and Restricted Data

If an article is embargoed and sent to Zenodo, the same embargo date will be set in Zenodo. If an article is
only accessible via a subscription model, then the data will be set as restricted in Zenodo.

### Communities

If a community is enabled in the plugin settings, the plugin will attempt to submit the record to the community in
Zenodo. Depending on the community settings, the record may be published immediately or may be published after
review. If the community submission fails for any reason, such as insufficient permissions or an API error,
the record will still be exported to Zenodo and the status and identifier will be saved.

## License

This plugin is licensed under the GNU General Public License v3.
