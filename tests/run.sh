#!/usr/bin/env bash
# Airworthy integration tests. Runs against a real WordPress install with Airworthy installed
# (from dist/airworthy.zip or build/airworthy), on whatever PHP and database it runs on.
#
# Usage: tests/run.sh <path to WordPress> [--uninstall]
#   WP_CLI=/path/to/wp   WP-CLI command (default: wp)
#   --uninstall          finish by uninstalling Airworthy and checking it leaves nothing behind
#                        (deletes the plugin: use on throwaway sites only)
#
# What it checks:
#   1. Verdicts: every fixture plugin, scanned against every target PHP 8.0-8.5, gets the
#      verdict in tests/expected-verdicts.json. WordPress.org is mocked (tests/wporg-mock.json).
#   2. tests/checks.php: unit checks (targets, verdict rules, names, signals, CSV safety),
#      REST permissions, privacy of WordPress.org lookups.
#   3. With --uninstall: tables, options, transients and scheduled jobs are removed.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WP_PATH=${1:?Usage: tests/run.sh <path to WordPress> [--uninstall]}
UNINSTALL=${2:-}
read -r -a WPCMD <<< "${WP_CLI:-wp}"
# `command` so the default "wp" runs WP-CLI, not this function again.
wp() { command "${WPCMD[@]}" --path="$WP_PATH" "$@"; }

PLUGINS=$(wp eval 'echo WP_PLUGIN_DIR;')
MU=$(wp eval 'echo WPMU_PLUGIN_DIR;')
NETWORK=""
if wp eval 'exit( is_multisite() ? 0 : 1 );'; then NETWORK="--network"; fi

echo "==> Environment"
wp eval 'global $wpdb; printf( "WordPress %s, PHP %s, %s %s%s\n", get_bloginfo( "version" ), PHP_VERSION, $wpdb->is_mysql ? "MySQL-compatible" : "other", $wpdb->db_server_info(), is_multisite() ? ", multisite" : "" );'

echo "==> Installing fixtures"
mkdir -p "$MU"
cp "$ROOT/tests/mu-plugin.php" "$MU/airworthy-tests.php"
cp "$ROOT/tests/wporg-mock.json" "$MU/airworthy-tests-wporg.json"
for dir in "$ROOT"/engine-test/fixtures/plugins/*/ "$ROOT"/engine-test/fixtures/wporg/*/; do
	slug=$(basename "$dir")
	rm -rf "${PLUGINS:?}/$slug"
	rsync -a --exclude secret.php "$dir" "$PLUGINS/$slug/"
done
# Unreadable file (git can't store it).
mkdir -p "$PLUGINS/fx-unreadable/inc"
printf '<?php\nmysql_connect();\n' > "$PLUGINS/fx-unreadable/inc/secret.php"
chmod 000 "$PLUGINS/fx-unreadable/inc/secret.php"
# Links out of the plugins folder must never be followed.
OUTSIDE=$(mktemp -d)
printf '<?php\n$x = create_function( "", "return 1;" );\n' > "$OUTSIDE/broken.php"
rm -rf "$PLUGINS/fx-symlink" && mkdir -p "$PLUGINS/fx-symlink"
printf '<?php\n/**\n * Plugin Name: FX Symlink\n * Version: 1.0.0\n * Description: FIXTURE. Links out of the plugins folder. Expect: Ready; links never followed.\n */\n' > "$PLUGINS/fx-symlink/fx-symlink.php"
ln -s "$OUTSIDE" "$PLUGINS/fx-symlink/linked-dir"
ln -s "$OUTSIDE/broken.php" "$PLUGINS/fx-symlink/linked-file.php"

wp plugin is-active airworthy $NETWORK 2>/dev/null || wp plugin activate airworthy $NETWORK
wp option delete airworthy_tests_http_log >/dev/null 2>&1 || true
wp eval 'global $wpdb; if ( is_multisite() ) { $wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE \"_site_transient%airworthy_wporg_%\"" ); } $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \"_site_transient%airworthy_wporg_%\"" );' 2>/dev/null || true

SLUGS=$(php -r '$e = json_decode( file_get_contents( $argv[1] ), true ); echo implode( ",", array_keys( $e["verdicts"] ) );' "$ROOT/tests/expected-verdicts.json")

echo "==> Verdicts for every target"
FAILED=0
for target in 8.0 8.1 8.2 8.3 8.4 8.5; do
	OUT=$(mktemp)
	if ! wp airworthy scan --target="$target" --only="$SLUGS" --wporg --from=5.6 --format=summary > "$OUT.log" 2>&1 \
		|| ! wp airworthy results --format=json --fields=slug,verdict,wporg > "$OUT" 2>> "$OUT.log"; then
		echo "FAIL scan for PHP $target:"; cat "$OUT.log"; FAILED=1; continue
	fi
	php "$ROOT/tests/compare-verdicts.php" "$ROOT/tests/expected-verdicts.json" "$target" "$OUT" || FAILED=1
done

echo "==> WP-CLI: --only applies to background scans"
wp airworthy scan --target=8.4 --only=fx-ready,fx-implode --background > /dev/null
COMPONENTS=$(wp eval 'global $wpdb; $t = Airworthy\Installer::tables(); echo implode( ",", $wpdb->get_col( "SELECT slug FROM {$t["components"]} WHERE scan_id = ( SELECT MAX(id) FROM {$t["scans"]} ) ORDER BY slug" ) );')
wp airworthy cancel > /dev/null
if [ "$COMPONENTS" = "fx-implode,fx-ready" ]; then echo "PASS background scan covers only fx-implode,fx-ready"; else echo "FAIL background scan covers: $COMPONENTS"; FAILED=1; fi

echo "==> Checks"
AIRWORTHY_TESTS_ROOT="$ROOT" wp eval-file "$ROOT/tests/checks.php" || FAILED=1

echo "==> Only changes after the PHP version compared from can be Blockers"
wp airworthy scan --target=8.4 --only=fx-blocker-80 --no-wporg --from=7.4 --format=summary > /dev/null 2>&1
V74=$(wp airworthy results --fields=verdict,existing --format=csv 2>/dev/null | tail -1)
wp airworthy scan --target=8.4 --only=fx-blocker-80 --no-wporg --from=8.0 --format=summary > /dev/null 2>&1
V80=$(wp airworthy results --fields=verdict,existing --format=csv 2>/dev/null | tail -1)
if [ "$V74" = "blocker,0" ]; then echo "PASS from 7.4: create_function (removed in 8.0) is a Blocker"; else echo "FAIL from 7.4: $V74"; FAILED=1; fi
if [ "$V80" = "ready,1" ]; then echo "PASS from 8.0: the same code is already so on 8.0, not a Blocker"; else echo "FAIL from 8.0: $V80"; FAILED=1; fi

echo "==> WordPress.org lookups only with consent"
# Clear cached answers too, so every lookup is a real (mocked) request and gets logged.
CLEAR='global $wpdb; delete_option( "airworthy_tests_http_log" ); $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \"%transient%airworthy_wporg_%\"" ); if ( is_multisite() ) { $wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE \"%transient%airworthy_wporg_%\"" ); } wp_cache_flush();'
wp eval "$CLEAR"
wp eval 'delete_site_option( "airworthy_wporg_consent" );'
wp airworthy scan --target=8.4 --only=fx-ready --format=summary > /dev/null 2>&1
LOOKUPS=$(wp eval 'echo count( (array) get_option( "airworthy_tests_http_log", array() ) );')
STATUS=$(wp eval 'global $wpdb; $t = Airworthy\Installer::tables(); echo $wpdb->get_var( "SELECT wporg_status FROM {$t["scans"]} ORDER BY id DESC LIMIT 1" );')
if [ "$LOOKUPS" = "0" ] && [ "$STATUS" = "off" ]; then echo "PASS no consent: no WordPress.org lookups, scan marked off"; else echo "FAIL no consent: $LOOKUPS lookups, status '$STATUS'"; FAILED=1; fi
wp eval 'update_site_option( "airworthy_wporg_consent", "yes" );'
wp eval "$CLEAR"
wp airworthy scan --target=8.4 --only=fx-ready --format=summary > /dev/null 2>&1
LOOKUPS=$(wp eval 'echo count( (array) get_option( "airworthy_tests_http_log", array() ) );')
if [ "$LOOKUPS" -gt 0 ]; then echo "PASS saved consent: WordPress.org looked up"; else echo "FAIL saved consent: no lookups"; FAILED=1; fi
wp airworthy scan --target=8.4 --only=fx-ready --no-wporg --format=summary > /dev/null 2>&1
STATUS=$(wp eval 'global $wpdb; $t = Airworthy\Installer::tables(); echo $wpdb->get_var( "SELECT wporg_status FROM {$t["scans"]} ORDER BY id DESC LIMIT 1" );')
if [ "$STATUS" = "off" ]; then echo "PASS --no-wporg overrides the saved choice"; else echo "FAIL --no-wporg: status '$STATUS'"; FAILED=1; fi

if [ "$UNINSTALL" = "--uninstall" ]; then
	echo "==> Uninstall leaves nothing behind"
	wp plugin deactivate airworthy $NETWORK
	wp plugin uninstall airworthy
	wp eval-file "$ROOT/tests/after-uninstall.php" || FAILED=1
fi

rm -rf "$OUTSIDE"
if [ "$FAILED" != "0" ]; then
	echo "Some tests FAILED."
	exit 1
fi
echo "All tests passed."
