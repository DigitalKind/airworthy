# Tests

`tests/run.sh <path to WordPress> [--uninstall]` runs Airworthy's integration tests against a real
WordPress install that has Airworthy installed. GitHub Actions (`.github/workflows/ci.yml`) runs it
on PHP 7.2, 7.4, 8.2 and 8.5 against WordPress 6.9 and the latest release, on MySQL 8.0 (MariaDB
10.6 for PHP 7.2), plus a multisite run. Each run installs the release zip built by `bin/build.sh`.

What it checks:

- **Verdicts** (`expected-verdicts.json`): every fixture plugin in `engine-test/fixtures/` scanned
  against every target PHP 8.0–8.5, including WordPress.org signals (closed, abandoned, Requires
  PHP, name clashes, updates). WordPress.org is mocked by `mu-plugin.php` + `wporg-mock.json`, so
  results never depend on the network.
- **Checks** (`checks.php`, run with `wp eval-file`): target defaults and support dates, verdict
  order, name matching, signals, CSV formula neutralising, that WordPress.org lookups send only
  the slug with Airworthy's user agent, REST permissions (visitors, editors and, on multisite,
  site administrators are refused), relative paths only, input validation, and that development
  code is not in the build.
- **Uninstall** (`after-uninstall.php`, with `--uninstall`): no tables, options, transients or
  scheduled jobs are left. `--uninstall` deletes the plugin: throwaway sites only.

Locally, against the test site: `WP_CLI=engine-test/tools/wp tests/run.sh engine-test/wp`
(copy `build/airworthy` into the site's plugins folder first).
