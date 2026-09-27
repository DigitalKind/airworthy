<?php
/**
 * Run after `wp plugin uninstall airworthy`: checks nothing is left behind.
 * wp eval-file tests/after-uninstall.php
 */

global $wpdb;
$left = array();

foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', '%' . $wpdb->esc_like( 'airworthy_' ) . '%' ) ) as $table ) {
	$left[] = "table $table";
}
$like = '%' . $wpdb->esc_like( 'airworthy' ) . '%';
foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s", $like, $wpdb->esc_like( 'airworthy_tests_' ) . '%' ) ) as $name ) {
	$left[] = "option $name";
}
if ( is_multisite() ) {
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $like ) ) as $name ) {
		$left[] = "network option $name";
	}
}
$as_table = $wpdb->prefix . 'actionscheduler_actions';
if ( $as_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_table ) ) ) {
	$jobs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$as_table} WHERE hook LIKE %s AND status = 'pending'", $like ) ); // phpcs:ignore
	if ( $jobs ) {
		$left[] = "$jobs pending scheduled jobs";
	}
}
foreach ( array( 'airworthy_scan_batch', 'airworthy_wporg_batch' ) as $hook ) {
	if ( wp_next_scheduled( $hook ) ) {
		$left[] = "cron event $hook";
	}
}

if ( $left ) {
	echo 'FAIL Uninstall left: ' . implode( ', ', $left ) . "\n";
	exit( 1 );
}
echo "PASS Uninstall removed all tables, options, transients and scheduled jobs\n";
