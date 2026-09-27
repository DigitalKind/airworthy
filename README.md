# Airworthy – PHP Compatibility & Upgrade Checker

A WordPress plugin that finds out which plugins and themes will break on a newer PHP version (8.0 to 8.5), and which look abandoned, before you upgrade.

- Website and docs: https://airworthywp.com
- WordPress.org: https://wordpress.org/plugins/airworthy/
- Made by [DigitalKind](https://digitalkind.ie), Ireland.

This repository is the plugin's public source code and build tools, as the [WordPress.org plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) require. It is published from the development repository at each release.

## What's here

| Path | What it is |
| --- | --- |
| `plugin/` | The plugin's own code: `airworthy.php`, `src/`, `assets/`, `languages/`, `readme.txt` |
| `plugin/composer.json`, `plugin/composer.lock` | The bundled libraries, pinned to exact versions |
| `bin/build.sh` | Builds the release zip (see below) |
| `scoper.inc.php` | PHP-Scoper configuration, with the patches the build applies |
| `bin/sbom.php` | Writes the software bill of materials (`sbom.cdx.json`, CycloneDX) |
| `tests/`, `engine-test/fixtures/` | Integration tests and the test plugins they scan |
| `docs/ENGINE.md` | How the scan engine is built, pinned and scoped |
| `SECURITY.md`, `docs/VULNERABILITY-HANDLING.md` | How to report a security problem, and what happens next |

## How the release zip is built

Airworthy's scan engine is [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) with [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) and [PHPCSUtils](https://github.com/PHPCSStandards/PHPCSUtils). Other plugins, or a site's own tools, may load different versions of these, so the build moves them into Airworthy's own namespace (`Airworthy\Vendor`) with [PHP-Scoper](https://github.com/humbug/php-scoper). The scoped code is ordinary, readable PHP; only the namespace names change. [Action Scheduler](https://github.com/woocommerce/action-scheduler) is bundled unscoped, because it is designed to share one copy between plugins.

Requirements: PHP 8.2 or newer (for PHP-Scoper), Composer 2, `curl`, `rsync`, `zip`.

```bash
bin/build.sh
```

This installs the exact versions in `plugin/composer.lock`, downloads PHP-Scoper 0.18.19 (checked against its SHA-256), scopes the libraries, checks the scoping patches landed, bundles Action Scheduler, writes the SBOM and zips everything to `dist/airworthy.zip`. The built plugin supports PHP 7.2 and newer.

## Tests

```bash
composer install          # WordPress coding standards
vendor/bin/phpcs          # 0 errors, 0 warnings
tests/run.sh /path/to/wordpress --uninstall
```

`tests/run.sh` runs against a throwaway WordPress site with the built plugin installed; see `tests/README.md`. The GitHub Actions workflow runs it on PHP 7.2, 7.4, 8.2 and 8.5 against WordPress 6.5 and the latest release, plus multisite and WordPress.org Plugin Check.

## Security

Please report security problems to **security@airworthywp.com**, not in public issues. See [SECURITY.md](SECURITY.md).

## Licence

Airworthy is free software under the GNU General Public License, version 2 or later ([LICENSE](LICENSE)). The bundled libraries keep their own licences (BSD-3-Clause, LGPL-3.0-or-later, GPL-3.0-or-later), listed in `plugin/readme.txt`; the plugin as distributed, taken as a whole, is covered by the GPL version 3.

© DigitalKind Limited.
