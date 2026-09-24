#!/bin/bash

set -e

# Run the plugin's unit tests first, so a failure is reported before the slower
# browser tests run. The PKP test bootstrap constructs the full application and
# connects to the database, both of which are already set up by this point.
# The tests are scoped by path rather than by --testsuite ApplicationPlugins,
# which would also run the tests of every other bundled plugin.
php lib/pkp/lib/vendor/bin/phpunit \
    --configuration lib/pkp/tests/phpunit.xml \
    plugins/generic/zenodo/tests

php lib/pkp/tools/installPluginVersion.php plugins/generic/zenodo/version.xml

npx cypress run --headless --browser chrome --config '{"specPattern":["plugins/generic/zenodo/cypress/tests/functional/*.cy.{js,jsx,ts,tsx}"]}'
