<?php
/**
 * CSV export of a scan's results.
 *
 * @package Airworthy
 */

namespace Airworthy\Admin;

use Airworthy\Capabilities;
use Airworthy\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Download handler: admin-post.php?action=airworthy_export&scan=ID&_wpnonce=…
 *
 * One row per finding, with the plugin's verdict and WordPress.org signals on every row, plus
 * one row for each plugin or theme with no findings, so the file is complete on its own.
 * Cells that a spreadsheet would treat as a formula (starting with = + - @ or a tab) are
 * prefixed with an apostrophe: findings quote other people's code. fputcsv()'s separator,
 * enclosure and escape are passed explicitly (relying on the default escape is deprecated
 * in PHP 8.4).
 */
final class Export {

	const ACTION = 'airworthy_export';

	/**
	 * Hooks the handler.
	 */
	public static function register() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'download' ) );
	}

	/**
	 * Signed download URL for a scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return string
	 */
	public static function url( $scan_id ) {
		// Not wp_nonce_url(): it returns an HTML-escaped URL ("&amp;"), and this one travels in JSON.
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'scan'     => (int) $scan_id,
				'_wpnonce' => wp_create_nonce( self::ACTION . '_' . (int) $scan_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Streams the CSV.
	 */
	public static function download() {
		global $wpdb;
		$scan_id = isset( $_GET['scan'] ) ? absint( $_GET['scan'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below.
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'airworthy' ), 403 );
		}
		check_admin_referer( self::ACTION . '_' . $scan_id );

		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
		$scan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['scans']} WHERE id = %d", $scan_id ) );
		if ( ! $scan ) {
			wp_die( esc_html__( 'Not found.', 'airworthy' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::filename( $scan ) . '"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the download.
		self::write( $scan, $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming the download.
		exit;
	}

	/**
	 * File name for a scan's CSV, e.g. airworthy-php-8.4-2026-09-27.csv.
	 *
	 * @param object $scan Scan row.
	 * @return string
	 */
	public static function filename( $scan ) {
		return sprintf( 'airworthy-php-%s-%s.csv', $scan->target_php, gmdate( 'Y-m-d', strtotime( $scan->created_at . ' UTC' ) ) );
	}

	/**
	 * Writes a scan's CSV to an open stream (the download above, or a file from WP-CLI).
	 *
	 * @param object   $scan Scan row.
	 * @param resource $out  Writable stream.
	 */
	public static function write( $scan, $out ) {
		global $wpdb;
		$t       = Installer::tables();
		$scan_id = (int) $scan->id;
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM so spreadsheets read accents correctly.

		fputcsv( $out, array( 'Target PHP', 'Type', 'Name', 'Slug', 'Version', 'Active', 'Verdict', 'Errors', 'Suppressed', 'Guarded', 'Warnings', 'WordPress.org', 'Closed', 'Closed reason', 'Last updated', 'Abandoned', 'Tested up to', 'Requires PHP', 'Update available', 'File', 'Line', 'Severity', 'Context', 'Rule', 'Message' ), ',', '"', '\\' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
		$components = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['components']} WHERE scan_id = %d ORDER BY FIELD(verdict, 'blocker','unknown','suppressed','guarded','warnings','ready'), name", $scan_id ) );
		foreach ( $components as $c ) {
			$w    = $c->wporg ? (array) json_decode( $c->wporg, true ) : array();
			$base = array(
				$scan->target_php,
				$c->type,
				$c->name,
				$c->slug,
				$c->version,
				$c->is_active ? 'yes' : 'no',
				(string) $c->verdict,
				(int) $c->errors,
				(int) $c->suppressed,
				(int) $c->guarded,
				(int) $c->warnings,
				isset( $w['status'] ) ? $w['status'] : '',
				isset( $w['closed_date'] ) ? $w['closed_date'] : '',
				isset( $w['closed_reason'] ) ? $w['closed_reason'] : '',
				isset( $w['last_updated'] ) ? $w['last_updated'] : '',
				! empty( $w['abandoned'] ) ? 'yes' : '',
				isset( $w['tested'] ) ? $w['tested'] : '',
				isset( $w['requires_php'] ) ? $w['requires_php'] : '',
				isset( $w['update'] ) ? (string) $w['update'] : '',
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
			$issues = (array) $wpdb->get_results( $wpdb->prepare( "SELECT file, line, severity, context, rule, message FROM {$t['issues']} WHERE component_id = %d ORDER BY FIELD(severity, 'scan','error','warning','notice'), file, line", $c->id ) );
			if ( ! $issues ) {
				fputcsv( $out, self::safe( array_merge( $base, array( '', '', '', '', '', '' ) ) ), ',', '"', '\\' );
			}
			foreach ( $issues as $i ) {
				fputcsv( $out, self::safe( array_merge( $base, array( $i->file, (int) $i->line, $i->severity, $i->context, $i->rule, $i->message ) ) ), ',', '"', '\\' );
			}
		}
	}

	/**
	 * Neutralises cells a spreadsheet would run as a formula.
	 *
	 * @param array $row Cells.
	 * @return array
	 */
	private static function safe( array $row ) {
		foreach ( $row as $k => $cell ) {
			if ( is_string( $cell ) && '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) ) {
				$row[ $k ] = "'" . $cell;
			}
		}
		return $row;
	}
}
