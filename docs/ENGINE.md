# Scan engine: pinned versions, build patches and fallback plan

The scanner bundles PHP_CodeSniffer and PHPCompatibility, prefixed under `Airworthy\Vendor` by PHP-Scoper (`bin/build.sh`, `scoper.inc.php`). Step 1 found that the stable PHPCompatibility (9.3.5, 2019) can't be used for PHP 8, so we depend on an **alpha**. This file records exactly what we ship and what to do if that alpha becomes a problem.

## Pinned versions

Exact versions in `plugin/composer.json`, and locked in `plugin/composer.lock`. Never use ranges.

| Package | Version | Licence |
|---|---|---|
| squizlabs/php_codesniffer | 4.0.4 | BSD-3-Clause |
| phpcsstandards/phpcsutils | 1.2.3 | LGPL-3.0-or-later |
| phpcompatibility/php-compatibility | 10.0.0-alpha2 | LGPL-3.0-or-later |
| phpcompatibility/phpcompatibility-paragonie | 2.0.0-alpha2 | LGPL-3.0-or-later |
| phpcompatibility/phpcompatibility-wp | 3.0.0-alpha2 | LGPL-3.0-or-later |
| woocommerce/action-scheduler (background jobs, bundled **unscoped** in `vendor/woocommerce/action-scheduler/`) | 3.9.3 | GPL-3.0-or-later |

PHP_CodeSniffer 4 sets the plugin's minimum PHP version: **7.2**.

Action Scheduler sets the minimum WordPress version. Decided 27 Sep 2026 against WordPress.org version stats:

| Action Scheduler | Minimum WordPress | Share of sites |
|---|---|---|
| 4.2.0 (latest) | 6.9 | 76.8% |
| **3.9.3 (chosen)** | **6.5** | **87.8%** |
| 3.8.2 | 6.4 | 89.0% |

Our users run older, neglected sites, so we give up 4.x to reach 11 points more of the market. Scanned with our own engine, 3.9.3 and 4.2.0 give the same result on PHP 8.5: no errors, and 4 warnings in fallback code. Action Scheduler is deliberately *not* scoped: when several plugins bundle it, it loads only the newest copy, so a site with WooCommerce runs WooCommerce's newer version. Revisit if WordPress 6.9+ passes about 90% of sites.

Because the engine is bundled, upstream changes can never break a released version of the plugin. The risks are all about *future* releases: new PHP versions, and what we can upgrade to.

## Build patches (scoper.inc.php)

PHP-Scoper renames classes but not class names held in strings. Three patches are needed. `bin/build.sh` fails the build if any of them doesn't apply.

1. **PHPCS autoloader** (`autoload.php`): it matches `'PHP_CodeSniffer\'` by string and fixed length (16/22), so we point it at the prefixed namespace.
2. **Standard search paths** (`Autoload::addSearchPath`): standards register their unprefixed namespace (from Config, Runner and Ruleset), so we prefix it in that one method.
3. **Token constants** (`Util/Tokens.php`): the ~70 global `T_*` constants can't be prefixed. They're guarded with `defined() ||` so a second copy of PHPCS from another plugin doesn't trigger "already defined" warnings (a fatal error in PHP 9). Their values are `'PHPCS_T_<NAME>'` strings, the same in any copy.

Verified 27 Sep 2026:
- **Scoped and unscoped give identical results**, with 0 differences in about 18,000 rows at targets 8.0, 8.4 and 8.5 (fixtures plus WooCommerce, UpdraftPlus, Wordfence and Essential Addons).
- **Coexistence:** an unscoped PHPCS 4 and our scoped copy run in the same process with no warnings and identical results (`engine-test/coexist.php`).

Not patched because they're never used: Reports, Generators, and the PEAR FileComment sniff.

## When to revisit

- **A new PHP version is released** (8.6, 9.0). PHPCS tokenises with the *host* PHP, so a site running a PHP version newer than our PHPCS knows about may produce tokens it doesn't understand. This is the most likely trigger. Test each new PHP release against the fixtures as soon as it's out.
- **PHPCompatibility publishes alpha3, beta or stable 10.0.** Upgrade deliberately, following the checklist below.
- **Upstream stalls** (no release for 12+ months while new PHP versions ship).

## Fallback options, in order

1. **Stay pinned.** This is the default. The bundled alpha keeps working for PHP 8.0–8.5. Newer PHP targets get covered by our own sniffs (option 4).
2. **Upgrade to the next upstream release**, only after the checklist passes.
3. **Fork PHPCompatibility** into our own namespace. The LGPL allows it, provided we publish our changes. Most of its value is version data: tables of removed or deprecated functions, constants, classes and ini settings per PHP version. That data is straightforward to maintain from the official PHP migration guides.
4. **Our own sniffs** in the `Airworthy` namespace. We need these anyway for the six static gaps found in Step 1, and they work whatever happens upstream. New PHP versions' deprecations can be added here first.

## Upgrade checklist (any engine version change)

1. Change the exact versions in `plugin/composer.json` and run `bin/build.sh` (this refreshes `composer.lock`).
2. The build must pass its patch checks. If a patch no longer matches, review the PHPCS change and update `scoper.inc.php`.
3. `engine-test/coverage.php`: the coverage matrix must be no worse than before.
4. `engine-test/verdicts.php`: all fixture verdicts must be as expected.
5. Scoped-vs-unscoped parity (`engine-test/scan.php --engine=build` against `--engine=v10`, after updating `v10/`) must show 0 differences.
6. `engine-test/coexist.php` must run clean.
7. Scan the top-30 plugin set inside WordPress with 64 MB and 128 MB limits (`engine-test/drive.sh`): no fatal errors, and peak memory similar to before.
8. Update this file's pinned-versions table.
