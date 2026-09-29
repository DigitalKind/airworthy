<?php
/**
 * Airworthy checks run inside WordPress: wp eval-file tests/checks.php
 * (tests/run.sh calls it after the fixture scans, so scan data exists).
 *
 * Exits with code 1 if any check fails.
 */

use Airworthy\Targets;
use Airworthy\Scan\Verdict;
use Airworthy\Wporg\Signals;

$airworthy_pass = 0;
$airworthy_fail = 0;
$check          = static function ( $label, $ok, $detail = '' ) use ( &$airworthy_pass, &$airworthy_fail ) {
	$ok ? $airworthy_pass++ : $airworthy_fail++;
	printf( "%s %-66s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : $detail );
};
$day = static function ( $ymd ) {
	return strtotime( $ymd . ' 12:00:00 UTC' );
};

// --- Plugin loaded as a release build.
$check( 'Version is a release version', (bool) preg_match( '/^\d+\.\d+\.\d+$/', AIRWORTHY_VERSION ), AIRWORTHY_VERSION );
$check( 'Development-only code is not in the build', ! class_exists( 'Airworthy\\Dev' ) );
$check( 'Preview filter has no effect', PHP_VERSION === \Airworthy\Env::php_version() );
$engine = AIRWORTHY_DIR . 'vendor/squizlabs/php_codesniffer/src/Config.php';
$check( 'Scan engine is scoped into Airworthy\\Vendor', is_readable( $engine ) && false !== strpos( (string) file_get_contents( $engine ), 'namespace Airworthy\\Vendor\\PHP_CodeSniffer;' ) );

// --- Targets.
$check( 'Default for PHP 7.4 on 2026-09-27 is 8.2', '8.2' === Targets::default_for( '7.4.33', $day( '2026-09-27' ) ) );
$check( 'Default for PHP 8.2 on 2026-09-27 is 8.3 (never a downgrade)', '8.3' === Targets::default_for( '8.2.10', $day( '2026-09-27' ) ) );
$check( 'Default for PHP 7.4 on 2027-01-15 is 8.3', '8.3' === Targets::default_for( '7.4.33', $day( '2027-01-15' ) ) );
$check( 'Default for PHP 8.5 is 8.5 (nothing newer)', '8.5' === Targets::default_for( '8.5.0', $day( '2026-09-27' ) ) );
$check( 'Default when the table is out of date is the next version', '8.0' === Targets::default_for( '7.4.33', $day( '2031-01-01' ) ) );
$check( '8.1 ended on 2025-12-31', ! Targets::is_supported( '8.1', $day( '2026-01-01' ) ) && Targets::is_supported( '8.1', $day( '2025-12-31' ) ) );
$check( 'Unknown targets are refused', ! Targets::is_valid( '9.0' ) && ! Targets::is_valid( '8.4.1' ) && Targets::is_valid( '8.4' ) );
$check( 'Support table checked within the last 13 months', ( time() - $day( Targets::TABLE_CHECKED ) ) < 395 * DAY_IN_SECONDS, 'Update Targets::SECURITY_UNTIL (docs/RELEASE.md)' );

// --- Verdict order.
$check( 'Verdict: plain error is Blocker', Verdict::BLOCKER === Verdict::from_counts( array( 'errors' => 1, 'files_failed' => 1 ) ) );
$check( 'Verdict: Requires PHP too high is Blocker', Verdict::BLOCKER === Verdict::from_counts( array( 'requires_php_too_high' => 1 ) ) );
$check( 'Verdict: unreadable file is Unknown', Verdict::UNKNOWN === Verdict::from_counts( array( 'files_skipped' => 1, 'suppressed' => 2 ) ) );
$check( 'Verdict: Suppressed beats Guarded', Verdict::SUPPRESSED === Verdict::from_counts( array( 'suppressed' => 1, 'guarded' => 5 ) ) );
$check( 'Verdict: Guarded beats Warnings', Verdict::GUARDED === Verdict::from_counts( array( 'guarded' => 1, 'warnings' => 5 ) ) );
$check( 'Verdict: nothing found is Ready', Verdict::READY === Verdict::from_counts( array() ) );

// --- The PHP version each finding's change took effect in.
$versions = array(
	'Function create_function() is deprecated since PHP 7.2 and removed since PHP 8.0; Use an anonymous function' => '8.0',
	"Extension 'mysql_' is deprecated since PHP 5.5 and removed since PHP 7.0; Use mysqli instead" => '7.0',
	'Indirect access to variables, properties and methods will be evaluated strictly in left-to-right order since PHP 7.0.' => '7.0',
	'Passing E_USER_ERROR to trigger_error() is deprecated since 8.4.' => '8.4', // "PHP" left out of the message.
	'Something with no version' => null,
);
foreach ( $versions as $message => $want ) {
	$check( 'Change version: ' . substr( $message, 0, 40 ), $want === \Airworthy\Scan\Queue::change_version( $message ), wp_json_encode( \Airworthy\Scan\Queue::change_version( $message ) ) );
}
$check( 'Already applies: removed in 8.0, compared from 8.3', \Airworthy\Scan\Queue::already_applies( 'removed since PHP 8.0', '8.3' ) );
$check( 'Not yet: removed in 8.4, compared from 8.3', ! \Airworthy\Scan\Queue::already_applies( 'removed since PHP 8.4', '8.3' ) );
$check( 'Unknown version counts as new', ! \Airworthy\Scan\Queue::already_applies( 'no version here', '8.3' ) );

// --- Settings: skip inactive plugins, ignore list, scan speed.
$saved = get_site_option( 'airworthy_settings', null );
update_site_option( 'airworthy_settings', array( 'skip_inactive' => true, 'ignore' => array( 'plugin:fx-ready', 'plugin:no such thing' ), 'speed' => 'bogus' ) );
$found  = wp_list_pluck( \Airworthy\Scan\Components::discover(), 'slug' );
$active = array_filter( \Airworthy\Scan\Components::discover( false ), function ( $c ) { return 'plugin' === $c['type'] && $c['is_active']; } );
$check( 'Settings: ignored plugins are left out', ! in_array( 'fx-ready', $found, true ) && \Airworthy\Scan\Components::$last_skipped['ignored'] >= 0 );
$check( 'Settings: inactive plugins are left out, active ones kept', ! in_array( 'fx-implode', $found, true ) && in_array( 'airworthy', $found, true ) );
$check( 'Settings: invalid values fall back safely', 'normal' === \Airworthy\Settings::get( 'speed' ) && array( 'plugin:fx-ready' ) === \Airworthy\Settings::get( 'ignore' ) );
$check( 'Settings: discover( false ) ignores the settings', in_array( 'fx-ready', wp_list_pluck( \Airworthy\Scan\Components::discover( false ), 'slug' ), true ) );
update_site_option( 'airworthy_settings', array( 'speed' => 'gentle' ) );
$check( 'Settings: gentle speed means short batches with pauses', 8 === \Airworthy\Settings::batch_seconds() && 30 === \Airworthy\Settings::batch_pause() );
if ( null === $saved ) {
	delete_site_option( 'airworthy_settings' );
} else {
	update_site_option( 'airworthy_settings', $saved );
}

// --- WordPress.org name matching and signals.
$check( 'Names: same plugin, extra words', Signals::names_match( 'Akismet', 'Akismet Anti-spam: Spam Protection' ) );
$check( 'Names: different plugin, same folder', ! Signals::names_match( 'FX Acme Events', 'Calendar' ) );
$check( 'Names: a brand with WP added (GiveWP)', Signals::names_match( 'Give - Donation Plugin', 'GiveWP &#8211; Donation Plugin and Fundraising Platform' ) );
$check( 'Names: WP at the start (WPForms)', Signals::names_match( 'WPForms Lite', 'WPForms &#8211; Easy Form Builder for WordPress' ) );
$record = array(
	'listed'       => true,
	'closed'       => false,
	'name'         => 'Old Thing',
	'version'      => '2.0.0',
	'tested'       => '5.4.2',
	'requires'     => '3.0',
	'requires_php' => '8.3',
	'last_updated' => '2020-01-01',
);
$signals = Signals::build( array( 'name' => 'Old Thing', 'version' => '1.0.0' ), $record, array( 'source' => 'unknown' ), '8.2', '7.1.2', $day( '2026-09-27' ) );
$check( 'Signals: abandoned after 2 years', ! empty( $signals['abandoned'] ) );
$check( 'Signals: tested far behind', ! empty( $signals['tested_warning'] ) );
$check( 'Signals: Requires PHP above target is a blocker', ! empty( $signals['requires_php_blocker'] ) );
$check( 'Signals: newer version on WordPress.org', '2.0.0' === (string) $signals['update'], wp_json_encode( $signals ) );
$external = Signals::build( array( 'name' => 'X', 'version' => '1' ), $record, array( 'source' => 'external' ), '8.2', '7.1.2' );
$check( 'Signals: premium plugins are never matched', 'not_listed' === $external['status'] );

// --- CSV export neutralises spreadsheet formulas.
$method = new ReflectionMethod( 'Airworthy\\Admin\\Export', 'safe' );
$method->setAccessible( true );
$safe = $method->invoke( null, array( '=HYPERLINK("x")', '+1', '-2', '@SUM(A1)', 'plain', 3 ) );
$check( 'CSV: formula cells are neutralised', array( "'=HYPERLINK(\"x\")", "'+1", "'-2", "'@SUM(A1)", 'plain', 3 ) === $safe, wp_json_encode( $safe ) );

// --- WordPress.org lookups: slug only, own user agent, no site URL.
$log   = (array) get_option( 'airworthy_tests_http_log', array() );
$site  = wp_parse_url( home_url(), PHP_URL_HOST );
$leaks = array_filter(
	$log,
	static function ( $entry ) use ( $site ) {
		return false !== stripos( $entry['url'] . $entry['user-agent'], (string) $site ) || 0 !== strpos( $entry['user-agent'], 'Airworthy/' );
	}
);
$check( 'WordPress.org was looked up', count( $log ) > 0 );
$check( 'Lookups send only the slug, with the Airworthy user agent', ! $leaks, wp_json_encode( array_values( $leaks ) ) );
$slugs = wp_list_pluck( $log, 'slug' );
$check( 'Themes and fixtures without a match are still only slugs', ! array_diff( $slugs, array_map( 'sanitize_key', $slugs ) ) );

// --- REST permissions.
global $wpdb;
$t       = \Airworthy\Installer::tables();
$scan_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$t['scans']} WHERE status = 'complete'" ); // phpcs:ignore
$comp_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['components']} WHERE scan_id = %d AND slug = 'fx-implode'", $scan_id ) ); // phpcs:ignore
$check( 'A finished scan exists', $scan_id > 0 && $comp_id > 0 );

$call = static function ( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, '/airworthy/v1' . $route );
	foreach ( $params as $k => $v ) {
		$request->set_param( $k, $v );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
};
$routes = array(
	array( 'GET', '/scans/current' ),
	array( 'GET', "/scans/$scan_id" ),
	array( 'GET', "/scans/$scan_id/components/$comp_id/issues" ),
	array( 'POST', '/scans', array( 'target' => '8.4' ) ),
	array( 'POST', "/scans/$scan_id/cancel" ),
	array( 'POST', "/scans/$scan_id/nudge" ),
	array( 'POST', "/scans/$scan_id/components/$comp_id/rescan" ),
	array( 'GET', '/estimate' ),
	array( 'GET', "/scans/$scan_id/unchecked" ),
);

$editor_id = username_exists( 'airworthy_test_editor' );
if ( ! $editor_id ) {
	$editor_id = wp_insert_user(
		array(
			'user_login' => 'airworthy_test_editor',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => 'editor',
		)
	);
}
$admins   = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'blog_id' => get_main_site_id() ) );
$admin_id = is_multisite() ? (int) get_user_by( 'login', get_super_admins()[0] )->ID : (int) $admins[0];

$wrong = array();
foreach ( array( 'visitor' => 0, 'editor' => (int) $editor_id ) as $who => $user ) {
	wp_set_current_user( $user );
	foreach ( $routes as $r ) {
		list( $status ) = $call( $r[0], $r[1], isset( $r[2] ) ? $r[2] : array() );
		if ( ! in_array( $status, array( 401, 403 ), true ) ) {
			$wrong[] = "$who {$r[0]} {$r[1]} => $status";
		}
	}
}
$check( 'REST: visitors and editors are refused on every route', ! $wrong, implode( '; ', $wrong ) );

if ( is_multisite() ) {
	// A site administrator who isn't a network administrator must be refused too.
	$site_admin = username_exists( 'airworthy_test_site_admin' );
	if ( ! $site_admin ) {
		$site_admin = wp_insert_user(
			array(
				'user_login' => 'airworthy_test_site_admin',
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => 'administrator',
			)
		);
	}
	wp_set_current_user( (int) $site_admin );
	list( $status ) = $call( 'GET', "/scans/$scan_id" );
	$check( 'REST (multisite): site administrators are refused', in_array( $status, array( 401, 403 ), true ), (string) $status );
}

wp_set_current_user( $admin_id );
list( $status, $data ) = $call( 'GET', "/scans/$scan_id" );
$check( 'REST: administrators can read results', 200 === $status, (string) $status );
list( $status, $data ) = $call( 'GET', "/scans/$scan_id/components/$comp_id/issues" );
$paths = wp_json_encode( $data );
$check( 'REST: findings list relative paths only', 200 === $status && false === strpos( $paths, WP_CONTENT_DIR ) && false === strpos( $paths, ABSPATH ), substr( $paths, 0, 200 ) );
list( $status ) = $call( 'POST', '/scans', array( 'target' => '9.9' ) );
$check( 'REST: invalid target is rejected', 400 === $status, (string) $status );
list( $status, $data ) = $call( 'POST', '/scans', array( 'target' => '8.4', 'wporg' => false ) );
$check( 'REST: a scan can be started with WordPress.org checks off', 201 === $status && 'off' === $data['wporg'], (string) $status . ' ' . wp_json_encode( $data['wporg'] ?? null ) );
$check( 'REST: the answer is remembered', 'no' === get_site_option( 'airworthy_wporg_consent' ) );
if ( 201 === $status ) {
	\Airworthy\Scan\Queue::cancel( (int) $data['id'] );
}
list( $status ) = $call( 'GET', '/scans/999999' );
$check( 'REST: unknown scan is 404', 404 === $status, (string) $status );

// PHP support countdown.
$support = Targets::support_status( '8.3.12', $day( '2026-09-27' ) );
$check( 'Support: PHP 8.3 on 2026-09-27 has 15 months left', 'ok' === $support['level'] && 15 === $support['months'], wp_json_encode( $support ) );
$support = Targets::support_status( '8.2.1', $day( '2026-09-27' ) );
$check( 'Support: PHP 8.2 on 2026-09-27 ends soon', 'soon' === $support['level'] && 3 === $support['months'], wp_json_encode( $support ) );
$support = Targets::support_status( '7.4.33', $day( '2026-09-27' ) );
$check( 'Support: PHP 7.4 has ended', 'ended' === $support['level'], wp_json_encode( $support ) );
$support = Targets::support_status( '8.0.30', $day( '2026-09-27' ) );
$check( 'Support: PHP 8.0 has ended', 'ended' === $support['level'], wp_json_encode( $support ) );

// Continue scan: only a stopped scan, and only the latest one.
$check( 'Resume: a finished scan cannot be continued', ! \Airworthy\Scan\Queue::can_resume( (object) array( 'id' => PHP_INT_MAX, 'status' => 'complete' ) ) );
$check( 'Resume: an older stopped scan cannot be continued', ! \Airworthy\Scan\Queue::can_resume( (object) array( 'id' => -1, 'status' => 'cancelled' ) ) );
$check( 'Resume: an unknown scan is refused', is_wp_error( \Airworthy\Scan\Queue::resume( 999999 ) ) );

// Site Health: the test is registered and answers for the site's PHP version.
$tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
$check( 'Site Health: test is registered', isset( $tests['direct'][ \Airworthy\Admin\SiteHealth::TEST ] ) );
$health = \Airworthy\Admin\SiteHealth::run( '8.5.1' );
$check( 'Site Health: passes on the newest PHP version', 'good' === $health['status'], $health['label'] );
$health = \Airworthy\Admin\SiteHealth::run( '7.2.34' );
$check( 'Site Health: gives a result for an older PHP version', in_array( $health['status'], array( 'good', 'recommended' ), true ) && '' !== $health['label'], $health['label'] );

wp_delete_user( (int) $editor_id );
if ( isset( $site_admin ) ) {
	wp_delete_user( (int) $site_admin );
}

printf( "%d passed, %d failed\n", $airworthy_pass, $airworthy_fail );
if ( $airworthy_fail ) {
	exit( 1 );
}
