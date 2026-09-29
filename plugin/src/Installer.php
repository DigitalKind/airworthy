<?php
/**
 * Activation, schema upgrades and uninstall.
 *
 * @package Airworthy
 */

namespace Airworthy;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin's three custom tables and its stored options.
 *
 * Verdicts (components.verdict): blocker, guarded, suppressed, warnings, ready, unknown.
 * Issue context (issues.context), which decides how an error counts towards the verdict:
 *   plain      — no mitigating signal; an error here makes the component a Blocker
 *   guarded    — inside a real version/extension check or a known engine-switching library
 *   suppressed — silenced only by the author's phpcs:ignore comment; lower-confidence warning
 * Issues with severity "scan" record files that weren't checked (rule Airworthy.Scan.*);
 * severity "notice" is informational only (e.g. no PHP code found: short open tags).
 *
 * Components hold their own sorted file list (files, JSON) so a plugin updating mid-scan
 * can't shift the cursor. phase: main (first pass) -> retry (deferred files, one per
 * request) -> done. inflight_file is the index being scanned right now; if a batch finds it
 * set, the previous request died on that file.
 *
 * Tables use the network-wide base prefix, so a multisite network has one set of tables:
 * plugins are shared across the network and are scanned once.
 */
final class Installer {

	/** Bump when the schema changes; maybe_upgrade() then re-runs dbDelta. */
	const DB_VERSION = 6;

	/** Stored as a network option on multisite (plain option on single site). */
	const OPTION_DB_VERSION = 'airworthy_db_version';

	/** Every option the plugin owns, so uninstall can remove them all. */
	const OPTIONS = array( self::OPTION_DB_VERSION, 'airworthy_unseen_scan', 'airworthy_wporg_consent', 'airworthy_settings' );

	/** Transient prefix for cached WordPress.org plugin data (Step 5). */
	const TRANSIENT_PREFIX = 'airworthy_wporg_';

	/** Action Scheduler group for all background jobs (Step 3). */
	const JOB_GROUP = 'airworthy';

	/**
	 * Table names, keyed by short name.
	 *
	 * @return array<string,string>
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'scans'      => $wpdb->base_prefix . 'airworthy_scans',
			'components' => $wpdb->base_prefix . 'airworthy_components',
			'issues'     => $wpdb->base_prefix . 'airworthy_issues',
		);
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::create_tables();
	}

	/**
	 * Deactivation hook: stop background work but keep data, in case of reactivation.
	 * Unfinished scans are cancelled so none is left "running" with no jobs to finish it.
	 */
	public static function deactivate() {
		Scan\Queue::cancel_all();
		self::unschedule_jobs();
	}

	/**
	 * Re-runs the schema when the stored version is behind (e.g. after a plugin update,
	 * which does not fire the activation hook).
	 */
	public static function maybe_upgrade() {
		if ( (int) get_site_option( self::OPTION_DB_VERSION, 0 ) < self::DB_VERSION ) {
			self::create_tables();
		}
	}

	/**
	 * Creates or updates the tables with dbDelta. Safe to run repeatedly.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t       = self::tables();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is strict about formatting: one column per line, two spaces after PRIMARY KEY.
		$sql = array(
			"CREATE TABLE {$t['scans']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				target_php varchar(10) NOT NULL,
				host_php varchar(20) NOT NULL DEFAULT '',
				from_php varchar(10) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'queued',
				components_total int(10) unsigned NOT NULL DEFAULT 0,
				skipped_inactive int(10) unsigned NOT NULL DEFAULT 0,
				skipped_ignored int(10) unsigned NOT NULL DEFAULT 0,
				files_total int(10) unsigned NOT NULL DEFAULT 0,
				files_done int(10) unsigned NOT NULL DEFAULT 0,
				count_blocker smallint(5) unsigned NOT NULL DEFAULT 0,
				count_guarded smallint(5) unsigned NOT NULL DEFAULT 0,
				count_suppressed smallint(5) unsigned NOT NULL DEFAULT 0,
				count_warnings smallint(5) unsigned NOT NULL DEFAULT 0,
				count_ready smallint(5) unsigned NOT NULL DEFAULT 0,
				count_unknown smallint(5) unsigned NOT NULL DEFAULT 0,
				wporg_status varchar(20) NOT NULL DEFAULT '',
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				error text DEFAULT NULL,
				locked_until datetime DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				started_at datetime DEFAULT NULL,
				finished_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) $collate;",
			"CREATE TABLE {$t['components']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scan_id bigint(20) unsigned NOT NULL,
				type varchar(10) NOT NULL,
				slug varchar(191) NOT NULL,
				name varchar(255) NOT NULL DEFAULT '',
				version varchar(64) NOT NULL DEFAULT '',
				rel_path varchar(255) NOT NULL DEFAULT '',
				is_active tinyint(1) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'queued',
				phase varchar(10) NOT NULL DEFAULT 'main',
				files longtext DEFAULT NULL,
				deferred longtext DEFAULT NULL,
				files_total int(10) unsigned NOT NULL DEFAULT 0,
				files_done int(10) unsigned NOT NULL DEFAULT 0,
				files_ignored int(10) unsigned NOT NULL DEFAULT 0,
				files_failed int(10) unsigned NOT NULL DEFAULT 0,
				file_cursor int(10) unsigned NOT NULL DEFAULT 0,
				inflight_file int(10) DEFAULT NULL,
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				verdict varchar(20) DEFAULT NULL,
				errors int(10) unsigned NOT NULL DEFAULT 0,
				warnings int(10) unsigned NOT NULL DEFAULT 0,
				guarded int(10) unsigned NOT NULL DEFAULT 0,
				suppressed int(10) unsigned NOT NULL DEFAULT 0,
				existing int(10) unsigned NOT NULL DEFAULT 0,
				files_skipped int(10) unsigned NOT NULL DEFAULT 0,
				prepass longtext DEFAULT NULL,
				wporg longtext DEFAULT NULL,
				started_at datetime DEFAULT NULL,
				finished_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY scan_id (scan_id),
				KEY scan_verdict (scan_id,verdict),
				KEY scan_phase (scan_id,phase)
			) $collate;",
			"CREATE TABLE {$t['issues']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scan_id bigint(20) unsigned NOT NULL,
				component_id bigint(20) unsigned NOT NULL,
				file varchar(512) NOT NULL,
				line int(10) unsigned NOT NULL DEFAULT 0,
				severity varchar(10) NOT NULL,
				context varchar(20) NOT NULL DEFAULT 'plain',
				rule varchar(191) NOT NULL DEFAULT '',
				message text NOT NULL,
				PRIMARY KEY  (id),
				KEY component_id (component_id),
				KEY scan_id (scan_id)
			) $collate;",
		);

		dbDelta( $sql );
		update_site_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	/**
	 * Removes everything the plugin stored: tables, options, cached WordPress.org data and
	 * scheduled jobs. Called from uninstall.php only.
	 */
	public static function uninstall() {
		global $wpdb;

		self::unschedule_jobs();
		delete_site_transient( 'airworthy_file_count' ); // Rest\Controller::FILE_COUNT.

		foreach ( self::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are our own constants.
		}

		foreach ( self::OPTIONS as $option ) {
			delete_site_option( $option );
		}

		// Cached WordPress.org lookups. They're site transients, which live in the options table
		// on a single site (as _site_transient_*) and in sitemeta on multisite. Plain transients
		// are matched too, in case an older version stored any.
		$patterns = array();
		foreach ( array( '_site_transient_', '_site_transient_timeout_', '_transient_', '_transient_timeout_' ) as $prefix ) {
			$patterns[] = $wpdb->esc_like( $prefix . self::TRANSIENT_PREFIX ) . '%';
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $patterns[0], $patterns[1], $patterns[2], $patterns[3] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( is_multisite() ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $patterns[0], $patterns[1] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Cancels all queued background jobs (no-op if Action Scheduler isn't loaded).
	 */
	private static function unschedule_jobs() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::JOB_GROUP );
		}
	}
}
