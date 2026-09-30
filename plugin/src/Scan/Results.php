<?php
/**
 * Verdicts from saved results.
 *
 * @package Airworthy
 */

namespace Airworthy\Scan;

use Airworthy\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * One place that turns a component's saved counts and WordPress.org signals into its verdict,
 * and a scan's components into its verdict counts. Called by the file scan when a component
 * finishes and by the WordPress.org fetcher when signals arrive, in whichever order.
 */
final class Results {

	/**
	 * Re-works a component's verdict, once its files are done.
	 *
	 * @param int $component_id Component ID.
	 */
	public static function refresh_component( $component_id ) {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$c = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status, errors, suppressed, guarded, warnings, files_failed, files_skipped, wporg FROM %i WHERE id = %d', $t['components'], $component_id ), ARRAY_A );
		if ( ! $c || 'done' !== $c['status'] ) {
			return;
		}
		$wporg                      = $c['wporg'] ? (array) json_decode( $c['wporg'], true ) : array();
		$c['requires_php_too_high'] = ! empty( $wporg['requires_php_blocker'] ) ? 1 : 0;
		$wpdb->update( $t['components'], array( 'verdict' => Verdict::from_counts( $c ) ), array( 'id' => (int) $component_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Recounts a finished scan's verdicts.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public static function refresh_scan( $scan_id ) {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		if ( 'complete' !== $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $t['scans'], $scan_id ) ) ) {
			return;
		}
		$data = array();
		foreach ( Verdict::ALL as $verdict ) {
			$data[ 'count_' . $verdict ] = 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT verdict, COUNT(*) AS n FROM %i WHERE scan_id = %d GROUP BY verdict', $t['components'], $scan_id ) ) as $row ) {
			if ( in_array( $row->verdict, Verdict::ALL, true ) ) {
				$data[ 'count_' . $row->verdict ] = (int) $row->n;
			}
		}
		$wpdb->update( $t['scans'], $data, array( 'id' => (int) $scan_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
