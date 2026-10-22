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
$check( 'Default for PHP 8.5 before 8.6 is out is 8.5 (nothing newer released)', '8.5' === Targets::default_for( '8.5.0', $day( '2026-09-27' ) ) );
$check( 'Default when the table is out of date is the next version', '8.0' === Targets::default_for( '7.4.33', $day( '2031-01-01' ) ) );
$check( 'PHP 8.6 is a release candidate until 19 Nov 2026', ! Targets::is_released( '8.6', $day( '2026-11-18' ) ) && Targets::is_released( '8.6', $day( '2026-11-19' ) ) && Targets::is_released( '8.5', $day( '2026-11-18' ) ) );
$check( 'A release candidate is never the default', '8.5' === Targets::default_for( '8.5.12', $day( '2026-11-18' ) ) );
$check( 'Default for PHP 8.5 once 8.6 is out is 8.6', '8.6' === Targets::default_for( '8.5.12', $day( '2026-11-20' ) ) );
$check( 'A server already on the release candidate keeps it as the default', '8.6' === Targets::default_for( '8.6.0', $day( '2026-11-01' ) ) );
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
$check( 'Always fails ($this in a static closure): already applies', \Airworthy\Scan\Queue::already_applies( 'Closures / anonymous functions declared as static do not have access to $this', '8.3', 'PHPCompatibility.FunctionDeclarations.NewClosure.ThisFoundInStatic' ) );
$check( 'Always fails, but no version compared from: still counted', ! \Airworthy\Scan\Queue::already_applies( 'Closures / anonymous functions declared as static do not have access to $this', '', 'PHPCompatibility.FunctionDeclarations.NewClosure.ThisFoundInStatic' ) );

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
	array( 'POST', '/review', array( 'choice' => 'later' ) ),
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

// The review request: asked once per user; Maybe later waits 30 days, the others end it.
$review = \Airworthy\Admin\Review::class;
delete_user_meta( $admin_id, $review::META );
$check( 'Review: asked when the user has never answered', $review::should_ask() && is_array( $review::config() ) );
list( $status ) = $call( 'POST', '/review', array( 'choice' => 'later' ) );
$later          = (int) get_user_meta( $admin_id, $review::META, true );
$check( 'Review: Maybe later waits about 30 days', 200 === $status && ! $review::should_ask() && abs( $later - ( time() + 30 * DAY_IN_SECONDS ) ) < 60, "$status $later" );
update_user_meta( $admin_id, $review::META, (string) ( time() - 1 ) );
$check( 'Review: asked again once the 30 days are up', $review::should_ask() );
list( $status ) = $call( 'POST', '/review', array( 'choice' => 'never' ) );
$check( 'Review: Don\'t ask again ends it', 200 === $status && ! $review::should_ask() && null === $review::config() );
list( $status ) = $call( 'POST', '/review', array( 'choice' => 'five-stars' ) );
$check( 'Review: unknown answers are refused', 400 === $status, (string) $status );
delete_user_meta( $admin_id, $review::META );

// The findings list and its filters, against counts taken straight from the table.
$in_table = static function ( $where ) use ( $wpdb, $t, $scan_id, $comp_id ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['issues']} WHERE scan_id = %d AND component_id = %d $where", $scan_id, $comp_id ) ); // phpcs:ignore
};
$listed = static function ( $params ) use ( $scan_id, $comp_id ) {
	$request = new WP_REST_Request( 'GET', "/airworthy/v1/scans/$scan_id/components/$comp_id/issues" );
	foreach ( $params + array( 'per_page' => 100 ) as $k => $v ) {
		$request->set_param( $k, $v );
	}
	$response = rest_do_request( $request );
	$headers  = $response->get_headers();
	return array( (int) $headers['X-WP-Total'], (array) $response->get_data() );
};
list( $total, $items ) = $listed( array() );
$check( 'REST: findings list returns every finding', $total > 0 && $total === $in_table( '' ) && count( $items ) === min( 100, $total ), "$total listed, " . $in_table( '' ) . ' in table' );
list( $total, $items ) = $listed( array( 'severity' => array( 'error' ) ) );
$check( 'REST: severity filter', $total === $in_table( "AND severity = 'error'" ) && ! array_diff( array_unique( wp_list_pluck( $items, 'severity' ) ), array( 'error' ) ), "$total" );
list( $total ) = $listed( array( 'severity' => array( 'error', 'warning' ), 'context' => array( 'plain' ) ) );
$check( 'REST: several severities plus a context', $total === $in_table( "AND severity IN ('error','warning') AND context = 'plain'" ), "$total" );
list( $total ) = $listed( array( 'severity' => array( 'notice' ) ) );
$check( 'REST: a filter with no matches lists nothing', $total === $in_table( "AND severity = 'notice'" ), "$total" );
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
$health = \Airworthy\Admin\SiteHealth::run( Targets::ALL[ count( Targets::ALL ) - 1 ] . '.1' );
$check( 'Site Health: passes on the newest PHP version', 'good' === $health['status'], $health['label'] );
$health = \Airworthy\Admin\SiteHealth::run( '7.2.34' );
$check( 'Site Health: gives a result for an older PHP version', in_array( $health['status'], array( 'good', 'recommended' ), true ) && '' !== $health['label'], $health['label'] );

// Explanations: hand-written entries, family entries built from the message, and the context.
$x = \Airworthy\Scan\Explain::issue( 'PHPCompatibility.ParameterValues.RemovedImplodeFlexibleParamOrder.Removed', 'Passing the $glue and $pieces parameters in reverse order to implode has been deprecated since PHP 7.4 and is removed since PHP 8.0', 'error', 'plain', '8.4' );
$check( 'Explain: implode() order has a fix with a before/after example', $x && '' !== $x['what'] && false !== strpos( $x['after'], "implode( ', '" ) && false !== strpos( $x['effect'], 'fatal error' ), wp_json_encode( $x ) );
$x = \Airworthy\Scan\Explain::issue( 'PHPCompatibility.FunctionUse.RemovedFunctions.some_old_fnDeprecatedRemoved', 'Function some_old_fn() is deprecated since PHP 7.2 and removed since PHP 8.0; Use new_fn() instead', 'error', 'plain', '8.4' );
$check( 'Explain: an unknown removed function gets a family explanation with the suggested replacement', $x && false !== strpos( $x['what'], 'some_old_fn()' ) && false !== strpos( $x['fix'], 'new_fn()' ), wp_json_encode( $x ) );
$x = \Airworthy\Scan\Explain::issue( 'Airworthy.PHP86.DeprecatedFunctions.spl_object_hashDeprecated', 'Function spl_object_hash() is deprecated since PHP 8.6; Use spl_object_id() instead', 'warning', 'plain', '8.6' );
$check( 'Explain: our own PHP 8.6 checks are explained, as deprecations that still work', $x && false !== strpos( $x['after'], 'spl_object_id' ) && false !== strpos( $x['effect'], 'Still works' ), wp_json_encode( $x ) );
$x = \Airworthy\Scan\Explain::issue( 'PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved', "Extension 'mysql_' is deprecated since PHP 5.5 and removed since PHP 7.0; Use mysqli instead", 'error', 'guarded', '8.4' );
$check( 'Explain: guarded code says there is nothing to do, and offers no fix', $x && false !== strpos( $x['effect'], 'Nothing to do' ) && '' === $x['fix'] && '' === $x['before'], wp_json_encode( $x ) );
$x = \Airworthy\Scan\Explain::issue( 'PHPCompatibility.FunctionUse.RemovedFunctions.mysql_real_escape_stringDeprecatedRemoved', 'Function mysql_real_escape_string() is deprecated since PHP 5.5 and removed since PHP 7.0', 'error', 'plain', '8.4' );
$check( 'Explain: old mysql_* functions point to $wpdb, not "no replacement"', $x && false !== strpos( $x['fix'], '$wpdb' ), wp_json_encode( $x ) );
$check( 'Explain: notices and scan problems get none', null === \Airworthy\Scan\Explain::issue( 'Internal.NoCodeFound', 'No PHP code was found', 'notice', 'plain', '8.4' ) );
$x = \Airworthy\Scan\Explain::issue( 'Some.Unknown.Rule.Found', 'Something new', 'warning', 'plain', '8.4' );
$check( 'Explain: an unknown rule still gets what happens and a general fix', $x && '' === $x['what'] && '' !== $x['effect'] && '' !== $x['fix'] );
list( $status, $data ) = $call( 'GET', "/scans/$scan_id/components/$comp_id/issues" );
$check( 'REST: findings carry their explanation', 200 === $status && isset( $data[0]['explain'] ) && ( null === $data[0]['explain'] || isset( $data[0]['explain']['effect'] ) ), wp_json_encode( isset( $data[0] ) ? $data[0] : null ) );

// Plugins screen badges, from the latest finished scan (the fixtures' scan in run.sh).
\Airworthy\Admin\PluginsScreen::reset();
$last    = $wpdb->get_row( "SELECT id, target_php FROM {$t['scans']} WHERE status = 'complete' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore
$fx_ver  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT version FROM {$t['components']} WHERE scan_id = %d AND slug = 'fx-implode'", $last->id ) ); // phpcs:ignore
$applies = version_compare( $last->target_php, Targets::minor( \Airworthy\Env::php_version() ), '>' );
wp_set_current_user( $admin_id );
$badge = \Airworthy\Admin\PluginsScreen::badge( 'fx-implode/fx-implode.php', array( 'Version' => $fx_ver ) );
if ( $applies ) {
	$check( 'Plugins screen: a scanned plugin gets its verdict, linking to its details', false !== strpos( $badge, 'Blocker' ) && false !== strpos( $badge, 'PHP ' . $last->target_php ) && false !== strpos( $badge, '#plugin:fx-implode' ), $badge );
	$badge = \Airworthy\Admin\PluginsScreen::badge( 'fx-php86/fx-php86.php', array( 'Version' => '99.0.0' ) ); // A fixture with a Version header.
	$check( 'Plugins screen: an updated plugin says it isn\'t checked since the update', false !== strpos( $badge, 'not checked since the update' ), $badge );
	$meta = \Airworthy\Admin\PluginsScreen::row_meta( array( 'Version 1.0' ), 'fx-ready/fx-ready.php', array() );
	$check( 'Plugins screen: the badge is added to the row meta', 2 === count( $meta ) && false !== strpos( $meta[1], 'airworthy-plugin-badge-ready' ), wp_json_encode( $meta ) );
} else {
	$check( 'Plugins screen: no badges when the server already runs the scanned PHP version', '' === $badge, $badge );
}
$check( 'Plugins screen: no badge for an inactive plugin that wasn\'t scanned', '' === \Airworthy\Admin\PluginsScreen::badge( 'not-installed/not-installed.php' ) );
wp_set_current_user( (int) $editor_id );
$check( 'Plugins screen: editors see no badges', '' === \Airworthy\Admin\PluginsScreen::badge( 'fx-implode/fx-implode.php', array( 'Version' => $fx_ver ) ) );
wp_set_current_user( $admin_id );

// PHP security releases: the server's PHP against the latest fixes (sample release data and
// build facts; the "museum" build is Hosting Ireland's CloudLinux alt-php83 8.3.35-6 of 21 Oct 2026,
// which has the fixes as a backport but still reports PHP_VERSION 8.3.35).
$rel = array(
	'date'         => '2026-10-21',
	'critical'     => true,
	'fixed'        => array( '8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12' ),
	'eol_affected' => true,
);
$b_unknown = array( 'date' => null, 'vendor' => null );               // Build date unreadable, vendor unknown.
$b_old     = array( 'date' => '2026-09-01', 'vendor' => null );       // An upstream-looking build from before the release.
$b_museum  = array( 'date' => '2026-10-21', 'vendor' => 'cloudlinux' ); // Built on release day by a backport vendor.
$b_cl_old  = array( 'date' => '2026-09-01', 'vendor' => 'cloudlinux' ); // A backport vendor's build that hasn't had the update.
$check( 'Patch: 8.3.36 and later have them, whatever the build', null === Targets::missing_patch( '8.3.36', $rel, $b_unknown ) && null === Targets::missing_patch( '8.3.40', $rel, $b_old ) && 'patched' === Targets::patch_status( '8.3.36', $rel, $b_old )['level'] );
$miss = Targets::missing_patch( '8.3.30', $rel, $b_old );
$check( 'Patch: 8.3.30 built before the fixes existed is missing them (red)', $miss && 'missing' === $miss['level'] && 'ended' === $miss['tone'] && '8.3.36' === $miss['fixed_in'] && $miss['critical'] && false !== strpos( $miss['text'], '8.3.36' ) && false !== strpos( $miss['text'], 'built on' ), wp_json_encode( $miss ) );
$back = Targets::patch_status( '8.3.35', $rel, $b_museum );
$check( 'Patch: the museum build (8.3.35, CloudLinux, built on release day) very likely has them, no notice', $back && 'backported' === $back['level'] && 'ok' === $back['tone'] && null === Targets::missing_patch( '8.3.35', $rel, $b_museum ) && false !== strpos( $back['text'], 'CloudLinux' ) && '2026-10-21' === $back['build_date'], wp_json_encode( $back ) );
$check( 'Patch: an unknown vendor built after the release is treated the same way', 'backported' === Targets::patch_status( '8.3.35', $rel, array( 'date' => '2026-10-25', 'vendor' => null ) )['level'] );
$behind = Targets::missing_patch( '8.3.35', $rel, $b_cl_old );
$check( 'Patch: a CloudLinux 8.3.35 built before the fixes hasn\'t had the update (orange, not red)', $behind && 'behind' === $behind['level'] && 'soon' === $behind['tone'] && false !== strpos( $behind['text'], 'keeps the version number' ), wp_json_encode( $behind ) );
$unsure = Targets::missing_patch( '8.3.35', $rel, $b_unknown );
$check( 'Patch: no build date means unsure, never red on the version alone', $unsure && 'unsure' === $unsure['level'] && 'soon' === $unsure['tone'] && false !== strpos( $unsure['text'], 'Ask your host' ), wp_json_encode( $unsure ) );
$check( 'Patch: unsure names the vendor when known', false !== strpos( Targets::missing_patch( '8.2.30', $rel, array( 'date' => null, 'vendor' => 'cpanel' ) )['text'], 'cPanel' ) );
$old = Targets::missing_patch( '8.1.33', $rel, $b_unknown );
$check( 'Patch: 8.1 gets no fix, and says to move to a newer version', $old && 'unpatched' === $old['level'] && 'ended' === $old['tone'] && null === $old['fixed_in'], wp_json_encode( $old ) );
$check( 'Patch: nothing said about 8.1 when old versions aren\'t affected', null === Targets::missing_patch( '8.1.33', array( 'eol_affected' => false ) + $rel, $b_unknown ) );
$check( 'Patch: nothing said without a known release or a full version number', null === Targets::missing_patch( '8.3.30', array( 'date' => '' ) + $rel, $b_old ) && null === Targets::missing_patch( '8.3', $rel, $b_old ) );
$check( 'Patch: a newer PHP than the release covers says nothing', null === Targets::missing_patch( '8.6.0', $rel, $b_old ) && ! \Airworthy\Admin\SiteHealth::knows_patch( '8.6.0', $rel ) );
$check( 'Patch: Site Health knows a patched, a missing and an unsupported version', \Airworthy\Admin\SiteHealth::knows_patch( '8.3.36', $rel ) && \Airworthy\Admin\SiteHealth::knows_patch( '8.3.30', $rel ) && \Airworthy\Admin\SiteHealth::knows_patch( '8.1.33', $rel ) );

// How the server's PHP was built (Env::php_build and its parsers).
$check( 'Build: PHP_BUILD_DATE and phpinfo() dates parse to Y-m-d', '2026-10-21' === \Airworthy\Env::build_date( 'Oct 21 2026 11:45:44' ) && '2024-11-05' === \Airworthy\Env::build_date( 'Nov  5 2024 14:00:00' ) && null === \Airworthy\Env::build_date( '' ) && null === \Airworthy\Env::build_date( 'no date here' ) );
$museum_info = "phpinfo()\nPHP Version => 8.3.35\n\nSystem => Linux gutenberg.webhostingireland.ie 4.18.0-553.123.2.lve.el8.x86_64 #1 SMP x86_64\nBuild Date => Oct 21 2026 11:45:44\nBuild System => Linux buildfarm06-new.corp.cloudlinux.com 5.14.0-687.15.1.el9_8.x86_64 #1 SMP x86_64 GNU/Linux\nServer API => LiteSpeed V8.1\n";
$museum      = \Airworthy\Env::build_from( array( 'constant' => '', 'info' => $museum_info, 'version' => '8.3.35', 'paths' => array( '/opt/alt/php83/usr/bin/lsphp', '/opt/alt/php83/etc/php.ini', '/opt/alt/php83/usr/lib64/php/modules' ), 'uname' => '4.18.0-553.123.2.lve.el8.x86_64' ) );
$check( 'Build: the museum\'s CloudLinux PHP reads as CloudLinux, built 2026-10-21', array( 'date' => '2026-10-21', 'vendor' => 'cloudlinux' ) === $museum, wp_json_encode( $museum ) );
$check( 'Build: CloudLinux is recognised from the build host alone', 'cloudlinux' === \Airworthy\Env::build_from( array( 'info' => $museum_info, 'paths' => array( '/usr/bin/php' ) ) )['vendor'] );
$check( 'Build: HTML phpinfo() output parses too', '2026-10-21' === \Airworthy\Env::build_from( array( 'info' => '<table><tr><td class="e">Build Date </td><td class="v">Oct 21 2026 11:45:44 </td></tr></table>' ) )['date'] );
$check( 'Build: PHP_BUILD_DATE wins over phpinfo()', '2026-09-22' === \Airworthy\Env::build_from( array( 'constant' => 'Sep 22 2026 13:32:06', 'info' => "Build Date => Jan 01 2020 00:00:00\n" ) )['date'] );
$check( 'Build: nothing readable gives null, not a guess', array( 'date' => null, 'vendor' => null ) === \Airworthy\Env::build_from( array() ) );
$vendor_paths = array(
	'cpanel' => array( '/opt/cpanel/ea-php83/root/usr/sbin/php-fpm', '/opt/cpanel/ea-php83/root/etc/php.ini' ),
	'plesk'  => array( '/opt/plesk/php/8.3/sbin/php-fpm', '/opt/plesk/php/8.3/etc/php.ini' ),
	'debian' => array( '/usr/sbin/php-fpm8.3', '/etc/php/8.3/fpm/php.ini', '/usr/lib/php/20230831' ),
	''       => array( '/usr/local/sbin/php-fpm', '/usr/local/etc/php/php.ini', '/usr/local/lib/php/extensions/no-debug-non-zts-20230831' ),
);
foreach ( $vendor_paths as $want => $paths ) {
	$got = \Airworthy\Env::build_from( array( 'version' => '8.3.35', 'paths' => $paths ) )['vendor'];
	$check( 'Build: vendor from paths is ' . ( '' === $want ? 'unknown (upstream-looking)' : $want ), ( '' === $want ? null : $want ) === $got, wp_json_encode( $got ) );
}
$check( 'Build: Homebrew\'s /opt/homebrew/etc/php/8.5 is not mistaken for Debian', null === \Airworthy\Env::build_from( array( 'version' => '8.5.11', 'paths' => array( '/opt/homebrew/Cellar/php/8.5.11/bin/php', '/opt/homebrew/etc/php/8.5/php.ini' ) ) )['vendor'] );
$check( 'Build: Ubuntu from the version string', 'ubuntu' === \Airworthy\Env::build_from( array( 'version' => '8.3.6-0ubuntu0.24.04.5', 'paths' => array( '/usr/sbin/php-fpm8.3' ) ) )['vendor'] );
$check( 'Build: Red Hat family from the kernel when nothing else says', 'redhat' === \Airworthy\Env::build_from( array( 'version' => '8.2.27', 'paths' => array( '/usr/sbin/php-fpm' ), 'uname' => '5.14.0-503.14.1.el9_5.x86_64' ) )['vendor'] );
$check( 'Build: every vendor id has a name', 6 === count( array_filter( array_map( array( '\Airworthy\Env', 'vendor_name' ), \Airworthy\Env::VENDORS ) ) ) && null === \Airworthy\Env::vendor_name( 'nope' ) );
$live = \Airworthy\Env::php_build();
$check( 'Build: this server\'s build date is readable and not in the future', ! empty( $live['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $live['date'] ) && $live['date'] <= gmdate( 'Y-m-d' ), wp_json_encode( $live ) );

// Site Health's security-fixes test, by level.
$ph = \Airworthy\Admin\SiteHealth::run_patch( '8.4.20', $rel, $b_old );
$check( 'Site Health: a missing critical fix is critical, with a red Security badge', 'critical' === $ph['status'] && 'red' === $ph['badge']['color'] && \Airworthy\Admin\SiteHealth::TEST_PATCH === $ph['test'], $ph['label'] );
$ph = \Airworthy\Admin\SiteHealth::run_patch( '8.4.27', $rel, $b_old );
$check( 'Site Health: patched is good', 'good' === $ph['status'] && 'blue' === $ph['badge']['color'], $ph['label'] );
$ph = \Airworthy\Admin\SiteHealth::run_patch( '8.3.35', $rel, $b_museum );
$check( 'Site Health: the museum build is good, saying very likely', 'good' === $ph['status'] && 'blue' === $ph['badge']['color'] && false !== strpos( $ph['label'], 'very likely' ) && false !== strpos( $ph['description'], 'CloudLinux' ), $ph['label'] );
$ph = \Airworthy\Admin\SiteHealth::run_patch( '8.3.35', $rel, $b_cl_old );
$check( 'Site Health: a backport vendor build from before the fixes is recommended (orange), not critical', 'recommended' === $ph['status'] && 'orange' === $ph['badge']['color'], $ph['label'] );
$ph = \Airworthy\Admin\SiteHealth::run_patch( '8.3.35', $rel, $b_unknown );
$check( 'Site Health: an unreadable build date is recommended, never critical', 'recommended' === $ph['status'] && false !== strpos( $ph['label'], 'can\'t tell' ), $ph['label'] );
$ph = \Airworthy\Admin\SiteHealth::run_patch( '8.1.33', $rel, $b_unknown );
$check( 'Site Health: a version that gets no fix is critical when the release is', 'critical' === $ph['status'] && false !== strpos( $ph['label'], 'no longer gets' ), $ph['label'] );

wp_delete_user( (int) $editor_id );
if ( isset( $site_admin ) ) {
	wp_delete_user( (int) $site_admin );
}

printf( "%d passed, %d failed\n", $airworthy_pass, $airworthy_fail );
if ( $airworthy_fail ) {
	exit( 1 );
}
