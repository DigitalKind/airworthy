<?php
/**
 * Background job: WordPress.org signals for every plugin in a scan.
 *
 * @package Airworthy
 */

namespace Airworthy\Wporg;

use Airworthy\Installer;
use Airworthy\Scan\Queue;
use Airworthy\Scan\Results;

defined( 'ABSPATH' ) || exit;

/**
 * Runs next to the file scan (it's queued when the scan's component list is ready) in short,
 * time-boxed batches. Saves each plugin's signals, re-works its verdict once its files are
 * done, and records how the lookups went on the scan (wporg_status):
 * done, partial (some lookups failed) or offline (WordPress.org unreachable).
 */
final class Fetcher {

	/** Job hook. Registered in Plugin::boot() by name. */
	const HOOK = 'airworthy_wporg_batch';

	/** Seconds one batch may spend on lookups. */
	const BUDGET = 10;

	/** After this many failed lookups in a row, the rest are marked unreachable without trying. */
	const GIVE_UP_AFTER = 2;

	/**
	 * Queues a batch.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public static function enqueue( $scan_id ) {
		as_enqueue_async_action( self::HOOK, array( (int) $scan_id ), Installer::JOB_GROUP, false, Queue::JOB_PRIORITY );
	}

	/**
	 * Job handler.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public static function run( $scan_id ) {
		global $wpdb;
		$scan_id = (int) $scan_id;
		$t       = Installer::tables();
		$lock    = 'airworthy_wporg_lock_' . $scan_id;
		if ( get_site_transient( $lock ) ) {
			return;
		}
		set_site_transient( $lock, 1, 2 * self::BUDGET + 30 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$scan = $wpdb->get_row( $wpdb->prepare( 'SELECT id, target_php, status, wporg_status FROM %i WHERE id = %d', $t['scans'], $scan_id ) );
		// 'off' = no consent for this scan (Wporg\Consent): never contact WordPress.org, whoever calls.
		if ( ! $scan || 'off' === $scan->wporg_status || in_array( $scan->status, array( 'cancelled', 'failed' ), true ) ) {
			delete_site_transient( $lock );
			return;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$by_path = array(); // Component rel_path => [plugin file, headers].
		foreach ( get_plugins() as $file => $headers ) {
			$key             = false === strpos( $file, '/' ) ? $file : dirname( $file );
			$by_path[ $key ] = array( $file, $headers );
		}
		$latest_wp = self::latest_wordpress();
		$started   = microtime( true );
		$failures  = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, slug, name, version, rel_path FROM %i WHERE scan_id = %d AND type = 'plugin' AND wporg IS NULL ORDER BY id", $t['components'], $scan_id ) );
		foreach ( $rows as $row ) {
			if ( microtime( true ) - $started > self::BUDGET ) {
				break;
			}
			$file     = isset( $by_path[ $row->rel_path ] ) ? $by_path[ $row->rel_path ][0] : '';
			$headers  = isset( $by_path[ $row->rel_path ] ) ? $by_path[ $row->rel_path ][1] : array();
			$identity = Signals::identify( $file, $headers );
			$record   = null;

			if ( 'external' !== $identity['source'] ) {
				$slug   = isset( $identity['slug'] ) ? $identity['slug'] : $row->slug;
				$record = $failures >= self::GIVE_UP_AFTER ? new \WP_Error( 'airworthy_wporg_skipped', 'WordPress.org unreachable.' ) : Client::lookup( $slug );
			}
			if ( is_wp_error( $record ) ) {
				++$failures;
				$signals = array( 'status' => 'unreachable' );
			} else {
				$failures = 0;
				$signals  = Signals::build(
					array(
						'name'    => $row->name,
						'version' => $row->version,
					),
					$record,
					$identity,
					$scan->target_php,
					$latest_wp
				);
			}
			$wpdb->update( $t['components'], array( 'wporg' => wp_json_encode( $signals ) ), array( 'id' => (int) $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Results::refresh_component( (int) $row->id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE scan_id = %d AND type = 'plugin' AND wporg IS NULL", $t['components'], $scan_id ) );
		if ( $left > 0 ) {
			delete_site_transient( $lock );
			self::enqueue( $scan_id );
			return;
		}

		// All done: record how it went, and refresh the scan's verdict counts if it has finished.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$stats  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(wporg LIKE %s) AS unreachable FROM %i WHERE scan_id = %d AND type = 'plugin'", '%"status":"unreachable"%', $t['components'], $scan_id ) );
		$status = 0 === (int) $stats->unreachable ? 'done' : ( (int) $stats->unreachable === (int) $stats->total ? 'offline' : 'partial' );
		$wpdb->update( $t['scans'], array( 'wporg_status' => $status ), array( 'id' => $scan_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Results::refresh_scan( $scan_id );
		delete_site_transient( $lock );
	}

	/**
	 * Latest WordPress version, from core's own update check, else this site's version.
	 *
	 * @return string
	 */
	private static function latest_wordpress() {
		$core = get_site_transient( 'update_core' );
		if ( is_object( $core ) && ! empty( $core->updates[0]->current ) ) {
			return (string) $core->updates[0]->current;
		}
		return (string) get_bloginfo( 'version' );
	}
}
