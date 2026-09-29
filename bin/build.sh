#!/usr/bin/env bash
# Builds the distributable plugin:
#   1. installs the scan engine (production deps only) from plugin/composer.json
#   2. prefixes it with PHP-Scoper (scoper.inc.php) into the plugin's vendor/ folder
#   3. regenerates the scoped Composer autoloader
#   3b. bundles Action Scheduler (unscoped) in vendor/woocommerce/action-scheduler
#   4. copies the plugin source and zips it to dist/airworthy.zip
#
# Usage: bin/build.sh            release build from the committed composer.lock
#        bin/build.sh --update   re-resolve dependencies after editing plugin/composer.json
#        DEV_BUILD=1 bin/build.sh   development build: also includes src/Dev.php (previews)
#        RC=2 bin/build.sh          test build numbered 1.0.0-rc.2: each test zip gets its own version,
#                                   so sites can tell builds apart and browsers reload the admin screen
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="airworthy"
BUILD="$ROOT/build"
STAGE="$BUILD/stage"
OUT="$BUILD/$SLUG"
SCOPER="$ROOT/tools/php-scoper.phar"
# PHP-Scoper is pinned like the libraries: same input, same build.
SCOPER_VERSION="0.18.19"
SCOPER_SHA256="170fb84bd3390defb30f99f7dc39c9a89d10c29973accc26f31c00abc5b25933"

if [ ! -f "$SCOPER" ]; then
	mkdir -p "$ROOT/tools"
	curl -fsSL -o "$SCOPER" "https://github.com/humbug/php-scoper/releases/download/$SCOPER_VERSION/php-scoper.phar"
fi
if command -v sha256sum >/dev/null; then HASH=$(sha256sum "$SCOPER" | cut -d' ' -f1); else HASH=$(shasum -a 256 "$SCOPER" | cut -d' ' -f1); fi
if [ "$HASH" != "$SCOPER_SHA256" ]; then
	echo "ERROR: tools/php-scoper.phar is not PHP-Scoper $SCOPER_VERSION (checksum mismatch). Delete it to re-download." >&2
	exit 1
fi

rm -rf "$BUILD"
mkdir -p "$STAGE" "$OUT" "$ROOT/dist"

echo "==> Installing scan engine"
cp "$ROOT/plugin/composer.json" "$STAGE/"
[ -f "$ROOT/plugin/composer.lock" ] && cp "$ROOT/plugin/composer.lock" "$STAGE/"
if [ "${1:-}" = "--update" ]; then
	composer update --working-dir="$STAGE" --no-dev --no-interaction --no-progress --prefer-dist --quiet
else
	# Fails if composer.json and composer.lock disagree: engine versions only change on purpose.
	if ! composer validate --working-dir="$STAGE" --no-check-all --no-check-publish --quiet 2>/dev/null; then
		echo "ERROR: plugin/composer.lock is out of date with composer.json. Run: bin/build.sh --update" >&2
		exit 1
	fi
	composer install --working-dir="$STAGE" --no-dev --no-interaction --no-progress --prefer-dist --quiet
fi
# Keep the lock file with the source so every build uses identical engine versions.
cp "$STAGE/composer.lock" "$ROOT/plugin/composer.lock"

echo "==> Scoping under Airworthy\\Vendor"
AIRWORTHY_VENDOR_DIR="$STAGE/vendor" php "$SCOPER" add-prefix \
	--config="$ROOT/scoper.inc.php" \
	--output-dir="$OUT/vendor" \
	--force --no-interaction --quiet
# PHP-Scoper can swallow patcher errors, so check the PHPCS autoloader patches really landed.
AUTOLOAD="$OUT/vendor/squizlabs/php_codesniffer/autoload.php"
if ! grep -q "'Airworthy\\\\\\\\Vendor\\\\\\\\PHP_CodeSniffer\\\\\\\\'" "$AUTOLOAD" \
	|| ! grep -q 'strpos($nsPrefix, ' "$AUTOLOAD"; then
	echo "ERROR: PHP_CodeSniffer autoload patch missing in scoped build (see scoper.inc.php)." >&2
	exit 1
fi
if grep -qE "^define\('T_" "$OUT/vendor/squizlabs/php_codesniffer/src/Util/Tokens.php"; then
	echo "ERROR: unguarded T_* constant definitions left in scoped Tokens.php (see scoper.inc.php)." >&2
	exit 1
fi
# Scoper writes paths relative to the finder root; composer metadata must sit where
# `composer dump-autoload` expects it.
cp "$STAGE/composer.json" "$OUT/composer.json"

echo "==> Regenerating scoped autoloader"
composer dump-autoload --working-dir="$OUT" --classmap-authoritative --no-dev --quiet
rm "$OUT/composer.json"

echo "==> Bundling Action Scheduler (unscoped: it picks the newest copy loaded by any plugin)"
mkdir -p "$OUT/vendor/woocommerce"
rsync -a --exclude tests --exclude docs --exclude '*.md' --exclude phpunit.xml.dist --exclude phpcs.xml \
	"$STAGE/vendor/woocommerce/action-scheduler/" "$OUT/vendor/woocommerce/action-scheduler/"

echo "==> Copying plugin source"
# src/Dev.php (previews for screenshots/tests) only goes into development builds.
DEV_EXCLUDE="--exclude src/Dev.php"
[ "${DEV_BUILD:-0}" = "1" ] && DEV_EXCLUDE=""
# composer.json/.lock ship too, so the bundled libraries and their versions are visible.
rsync -a --exclude vendor $DEV_EXCLUDE "$ROOT/plugin/" "$OUT/"
if [ -n "${RC:-}" ]; then
	# Release candidate: the built copy's version becomes X.Y.Z-rc.N (header and constant).
	case "$RC" in *[!0-9]*|'') echo "RC must be a number, e.g. RC=2" >&2; exit 1 ;; esac
	sed -i.bak -E "s/^( \* Version: +)([0-9]+\.[0-9]+\.[0-9]+)$/\1\2-rc.$RC/; s/('AIRWORTHY_VERSION', '[0-9]+\.[0-9]+\.[0-9]+)'/\1-rc.$RC'/" "$OUT/airworthy.php" && rm -f "$OUT/airworthy.php.bak"
	grep -q "AIRWORTHY_VERSION', '[0-9.]*-rc.$RC'" "$OUT/airworthy.php" && grep -qE "^ \* Version: +[0-9.]+-rc.$RC$" "$OUT/airworthy.php" || { echo "Could not mark the release candidate's version." >&2; exit 1; }
	# The readme's Stable tag follows, so Plugin Check on a test site doesn't report a mismatch.
	sed -i.bak -E "s/^(Stable tag: *)([0-9]+\.[0-9]+\.[0-9]+)$/\1\2-rc.$RC/" "$OUT/readme.txt" && rm -f "$OUT/readme.txt.bak"
	grep -qE "^Stable tag: *[0-9.]+-rc.$RC$" "$OUT/readme.txt" || { echo "Could not mark the release candidate's Stable tag." >&2; exit 1; }
fi

echo "==> Software bill of materials"
VERSION=$(sed -n "s/.*define( 'AIRWORTHY_VERSION', '\([^']*\)' );.*/\1/p" "$OUT/airworthy.php")
php "$ROOT/bin/sbom.php" "$VERSION" "$ROOT/plugin/composer.lock" "$OUT/sbom.cdx.json"
cp "$OUT/sbom.cdx.json" "$ROOT/docs/sbom.cdx.json"

ZIP="$SLUG.zip"
if [ "${DEV_BUILD:-0}" = "1" ]; then
	# Development builds say so in their version (turns on previews and asset cache-busting),
	# and never overwrite the release zip.
	sed -i.bak -E "s/('AIRWORTHY_VERSION', '[0-9.]+)'/\1-dev'/" "$OUT/airworthy.php" && rm -f "$OUT/airworthy.php.bak"
	grep -q "AIRWORTHY_VERSION', '[0-9.]*-dev'" "$OUT/airworthy.php" || { echo "Could not mark the dev build's version." >&2; exit 1; }
	ZIP="$SLUG-dev.zip"
fi

echo "==> Zipping"
rm -f "$ROOT/dist/$ZIP"
( cd "$BUILD" && zip -qr "$ROOT/dist/$ZIP" "$SLUG" )
rm -rf "$STAGE"

echo "Built $OUT"
echo "Zip:  dist/$ZIP ($(du -h "$ROOT/dist/$ZIP" | cut -f1)), unpacked $(du -sh "$OUT" | cut -f1)"
