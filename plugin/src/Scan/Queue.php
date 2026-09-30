<?php
/**
 * Background scanning: small, resumable batches run by Action Scheduler.
 *
 * @package Airworthy
 */

namespace Airworthy\Scan;

use Airworthy\Engine\Engine;
use Airworthy\Installer;
use Airworthy\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * A scan never runs in one long request. start() records the scan and queues one job; each
 * job (run_batch) scans files until its time budget runs out, saves its place, and queues the
 * next. Robustness rules from the Step 1 test:
 *
 * - Before each file, its index is saved as "in flight". If a job finds one already set, the
 *   previous request died on that file: it is deferred and retried alone at the end, and a
 *   second crash records it as failed, so one bad file can never wedge the queue.
 * - Files too big for the free memory (on a cautious estimate) are deferred too. Deferred
 *   files are retried at the end on a realistic estimate; one that doesn't fit waits for a
 *   fresh request, and only one that doesn't fit even then is recorded as too large.
 * - A watchdog job is scheduled at the start of every batch, so the scan resumes even when
 *   a request dies before it can queue the next batch.
 * - A lock stops two runners working on the same scan at once.
 *
 * Every finding is classified (plain, guarded or suppressed; see Classifier) as it is saved, and
 * each component gets its verdict (see Verdict) when its last file is done.
 */
final class Queue {

	/** Job hook. Registered in Plugin::boot() by name, so this class loads only when needed. */
	const HOOK = 'airworthy_scan_batch';

	/**
	 * Action Scheduler priority for our jobs (0 = highest; everyone else defaults to 10). Lower
	 * priority means other plugins' due jobs always run before our batch in a request, instead
	 * of waiting behind it until the next one.
	 */
	const JOB_PRIORITY = 20;

	/** Scans kept (older ones are deleted when a new scan starts), so tables can't grow without limit. */
	const KEEP_SCANS = 3;

	/** Batches that may fail outright (e.g. the engine won't start) before the scan is marked failed. */
	const MAX_SCAN_ATTEMPTS = 3;

	/** Crashes allowed within one component before its remaining files are given up on. */
	const MAX_COMPONENT_CRASHES = 5;

	/**
	 * Seconds past a batch's time budget before its lock expires; the watchdog fires the same
	 * again later. Filterable (airworthy_recovery_delay), mainly so tests can run quickly.
	 */
	const RECOVERY_DELAY = 60;

	/** Context of an error whose PHP change already applies on the version the site upgrades from. */
	const CONTEXT_EXISTING = 'existing';

	/**
	 * PHP_CodeSniffer rules that report harmless behaviour notes, not failures: counted as
	 * warnings. Self-closing tags in strip_tags()'s allowed list are only ignored (PHP 5.3.4+).
	 */
	const NOTE_RULES = array( 'PHPCompatibility.ParameterValues.ForbiddenStripTagsSelfClosingXHTML' );

	/** Rules recorded (severity "scan") for files that weren't checked. */
	const RULE_UNREADABLE = 'Airworthy.Scan.Unreadable';
	const RULE_CRASHED    = 'Airworthy.Scan.Crashed';
	const RULE_TOO_LARGE  = 'Airworthy.Scan.TooLarge';
	const RULE_ENGINE     = 'Airworthy.Scan.EngineError';
	const RULE_GAVE_UP    = 'Airworthy.Scan.TooManyCrashes';

	/**
	 * Starts a scan against one target PHP version.
	 *
	 * @param string      $target  Exact PHP version, e.g. "8.4".
	 * @param int         $user_id Who started it.
	 * @param bool|null   $wporg   Whether to look plugins up on WordPress.org; null = the site's
	 *                             saved choice (see Wporg\Consent), which is off until answered.
	 * @param string|null $from    PHP version the site upgrades from, e.g. "8.3"; null = this
	 *                             server's. Problems from PHP changes up to it already apply
	 *                             today, so they're not counted as Blockers (see scan_one()).
	 * @return int|\WP_Error Scan ID.
	 */
	public static function start( $target, $user_id = 0, $wporg = null, $from = null ) {
		global $wpdb;

		if ( ! Targets::is_valid( $target ) ) {
			return new \WP_Error( 'airworthy_bad_target', __( 'That PHP version is not supported.', 'airworthy' ) );
		}
		$from = null === $from ? Targets::minor( \Airworthy\Env::php_version() ) : (string) $from;
		if ( ! Targets::is_valid_from( $from ) ) {
			return new \WP_Error( 'airworthy_bad_from', __( 'That PHP version to compare from is not valid.', 'airworthy' ) );
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new \WP_Error( 'airworthy_no_queue', __( 'The background job library failed to load.', 'airworthy' ) );
		}
		if ( ! Engine::is_available() ) {
			return new \WP_Error( 'airworthy_no_engine', __( 'The scan engine is missing from this copy of the plugin. Reinstall it from WordPress.org.', 'airworthy' ) );
		}
		if ( self::active_scan_id() ) {
			return new \WP_Error( 'airworthy_scan_running', __( 'A scan is already running.', 'airworthy' ) );
		}

		$t = Installer::tables();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$t['scans'],
			array(
				'target_php'   => $target,
				'host_php'     => \Airworthy\Env::php_version(),
				'from_php'     => $from,
				'status'       => 'queued',
				'wporg_status' => ( null === $wporg ? \Airworthy\Wporg\Consent::allowed() : $wporg ) ? '' : 'off',
				'created_by'   => (int) $user_id,
				'created_at'   => self::now(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		$scan_id = (int) $wpdb->insert_id;
		if ( ! $scan_id ) {
			return new \WP_Error( 'airworthy_db', __( 'Could not save the scan.', 'airworthy' ) );
		}

		self::prune();
		self::enqueue_now( $scan_id );
		return $scan_id;
	}

	/**
	 * Checks one plugin or theme again within a finished scan (on request).
	 *
	 * @param int $scan_id      Scan ID.
	 * @param int $component_id Component ID.
	 * @return true|\WP_Error
	 */
	public static function rescan_component( $scan_id, $component_id ) {
		return self::rescan_components( $scan_id, array( $component_id ) );
	}

	/**
	 * Checks some plugins or themes again within a finished scan (after an update, or on
	 * request), keeping everyone else's results. Their file lists, pre-pass, findings and
	 * WordPress.org signals are rebuilt; the scan re-opens until they're done.
	 *
	 * @param int   $scan_id       Scan ID.
	 * @param int[] $component_ids Component IDs.
	 * @return true|\WP_Error
	 */
	public static function rescan_components( $scan_id, array $component_ids ) {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$scan = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status, wporg_status FROM %i WHERE id = %d', $t['scans'], $scan_id ) );
		if ( ! $scan ) {
			return new \WP_Error( 'airworthy_not_found', __( 'Not found.', 'airworthy' ) );
		}
		if ( self::active_scan_id() ) {
			return new \WP_Error( 'airworthy_scan_running', __( 'Wait for the current scan to finish, then try again.', 'airworthy' ) );
		}
		if ( 'complete' !== $scan->status ) {
			return new \WP_Error( 'airworthy_scan_incomplete', __( 'This scan was stopped before it finished, so single plugins can\'t be checked again in it. Start a new scan instead.', 'airworthy' ) );
		}

		$installed = array();
		foreach ( Components::discover( false ) as $row ) { // Rescans may name an ignored plugin.
			$installed[ $row['type'] . '|' . $row['slug'] ] = $row;
		}
		$any_plugin = false;
		$reset      = 0;
		foreach ( array_unique( array_map( 'intval', $component_ids ) ) as $component_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$c = $wpdb->get_row( $wpdb->prepare( 'SELECT id, type, slug FROM %i WHERE id = %d AND scan_id = %d', $t['components'], $component_id, $scan_id ) );
			if ( ! $c ) {
				return new \WP_Error( 'airworthy_not_found', __( 'Not found.', 'airworthy' ) );
			}
			if ( ! isset( $installed[ $c->type . '|' . $c->slug ] ) ) {
				return new \WP_Error( 'airworthy_gone', __( 'That plugin or theme is no longer installed.', 'airworthy' ) );
			}
			$fresh   = $installed[ $c->type . '|' . $c->slug ]; // Current name and version (it may just have been updated).
			$listing = FileLister::list_files( Components::root( $c->type, $fresh['rel_path'], $c->slug ) );
			$wpdb->delete( $t['issues'], array( 'component_id' => (int) $c->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$t['components'],
				array(
					'name'          => mb_substr( $fresh['name'], 0, 255 ),
					'version'       => mb_substr( $fresh['version'], 0, 64 ),
					'rel_path'      => $fresh['rel_path'],
					'is_active'     => $fresh['is_active'] ? 1 : 0,
					'status'        => 'queued',
					'phase'         => 'main',
					'files'         => wp_json_encode( $listing['files'] ),
					'deferred'      => '[]',
					'files_total'   => count( $listing['files'] ),
					'files_done'    => 0,
					'files_ignored' => $listing['ignored'],
					'files_failed'  => 0,
					'files_skipped' => 0,
					'file_cursor'   => 0,
					'inflight_file' => null,
					'attempts'      => 0,
					'verdict'       => null,
					'errors'        => 0,
					'warnings'      => 0,
					'guarded'       => 0,
					'suppressed'    => 0,
					'existing'      => 0,
					'prepass'       => null,
					'wporg'         => null,
					'started_at'    => null,
					'finished_at'   => null,
				),
				array( 'id' => (int) $c->id )
			);
			$any_plugin = $any_plugin || 'plugin' === $c->type;
			++$reset;
		}
		if ( ! $reset ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(files_total) FROM %i WHERE scan_id = %d', $t['components'], $scan_id ) );
		$data  = array(
			'status'      => 'running',
			'finished_at' => null,
			'files_total' => $total,
			'attempts'    => 0,
		);
		if ( $any_plugin && 'off' !== $scan->wporg_status ) { // Lookups only in scans that had consent.
			$data['wporg_status'] = 'pending';
			\Airworthy\Wporg\Fetcher::enqueue( (int) $scan_id );
		}
		$wpdb->update( $t['scans'], $data, array( 'id' => (int) $scan_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::sync_scan_progress( (int) $scan_id );
		self::enqueue_now( (int) $scan_id );
		return true;
	}

	/**
	 * After WordPress updates plugins or themes, re-checks them in the latest finished scan so
	 * its results match what's installed.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader.
	 * @param array        $extra    Hook data: type, action, plugins / themes.
	 */
	public static function after_upgrade( $upgrader, $extra ) {
		global $wpdb;
		if ( empty( $extra['action'] ) || empty( $extra['type'] ) ) {
			return;
		}
		// "Replace current with uploaded" (how many paid plugins are updated) is reported as an
		// install: treat it as an update when that plugin or theme is already in the results.
		if ( 'install' === $extra['action'] && is_object( $upgrader ) ) {
			if ( 'plugin' === $extra['type'] && method_exists( $upgrader, 'plugin_info' ) && $upgrader->plugin_info() ) {
				$extra['plugins'] = array( $upgrader->plugin_info() );
			} elseif ( 'theme' === $extra['type'] && method_exists( $upgrader, 'theme_info' ) && $upgrader->theme_info() ) {
				$extra['themes'] = array( $upgrader->theme_info()->get_stylesheet() );
			} else {
				return;
			}
		} elseif ( 'update' !== $extra['action'] ) {
			return;
		}
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$scan = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM %i ORDER BY id DESC LIMIT 1', $t['scans'] ) );
		if ( ! $scan || 'complete' !== $scan->status ) {
			return;
		}
		$slugs = array();
		if ( 'plugin' === $extra['type'] ) {
			$files = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
			foreach ( $files as $file ) {
				$slugs[] = false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file );
			}
		} elseif ( 'theme' === $extra['type'] ) {
			$slugs = isset( $extra['themes'] ) ? (array) $extra['themes'] : ( isset( $extra['theme'] ) ? array( $extra['theme'] ) : array() );
		}
		if ( ! $slugs ) {
			return;
		}
		$ids = array();
		foreach ( $slugs as $slug ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE scan_id = %d AND type = %s AND slug = %s', $t['components'], $scan->id, $extra['type'], $slug ) );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		if ( $ids ) {
			self::rescan_components( (int) $scan->id, $ids ); // All updated items in one re-opened scan.
		}
	}

	/**
	 * Cancels a scan and its queued jobs. Results so far are kept, and resume() can carry on
	 * from here. The lock is left to expire, so a batch still running when the scan was
	 * stopped can't overlap one started by a quick resume.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public static function cancel( $scan_id ) {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'cancelled', finished_at = %s WHERE id = %d AND status IN ('queued','running')", $t['scans'], self::now(), $scan_id ) );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array( (int) $scan_id ), Installer::JOB_GROUP );
			as_unschedule_all_actions( \Airworthy\Wporg\Fetcher::HOOK, array( (int) $scan_id ), Installer::JOB_GROUP );
		}
	}

	/**
	 * Whether a stopped scan can be continued: it's the latest scan and nothing else is running.
	 *
	 * @param object $scan Scan row (id, status).
	 * @return bool
	 */
	public static function can_resume( $scan ) {
		global $wpdb;
		if ( ! $scan || 'cancelled' !== $scan->status ) {
			return false;
		}
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$latest = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $t['scans'] ) );
		return (int) $scan->id === $latest;
	}

	/**
	 * Continues a stopped scan where it left off: finished plugins and themes keep their
	 * results, the one in progress carries on from its next file, and the rest follow.
	 *
	 * @param int $scan_id Scan ID.
	 * @return true|\WP_Error
	 */
	public static function resume( $scan_id ) {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$scan = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status, started_at, wporg_status FROM %i WHERE id = %d', $t['scans'], $scan_id ) );
		if ( ! $scan ) {
			return new \WP_Error( 'airworthy_not_found', __( 'Not found.', 'airworthy' ) );
		}
		if ( self::active_scan_id() ) {
			return new \WP_Error( 'airworthy_scan_running', __( 'A scan is already running.', 'airworthy' ) );
		}
		if ( ! self::can_resume( $scan ) ) {
			return new \WP_Error( 'airworthy_cannot_resume', __( 'Only the latest scan can be continued, and only if it was stopped. Start a new scan instead.', 'airworthy' ) );
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! Engine::is_available() ) {
			return new \WP_Error( 'airworthy_no_queue', __( 'The scan can\'t run on this copy of the plugin. Reinstall it from WordPress.org.', 'airworthy' ) );
		}

		// A scan stopped before its first batch hasn't listed its plugins yet: it starts over.
		$status = null === $scan->started_at ? 'queued' : 'running';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = %s, finished_at = NULL, error = NULL, attempts = 0 WHERE id = %d AND status = 'cancelled'", $t['scans'], $status, $scan_id ) );
		if ( 'pending' === $scan->wporg_status ) {
			\Airworthy\Wporg\Fetcher::enqueue( (int) $scan_id ); // Its lookups were stopped too.
		}
		self::enqueue_now( (int) $scan_id );
		return true;
	}

	/**
	 * Cancels every unfinished scan (on deactivation, so none is left "running" forever).
	 */
	public static function cancel_all() {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status IN ('queued','running')", $t['scans'] ) );
		foreach ( (array) $ids as $id ) {
			self::cancel( (int) $id );
		}
	}

	/**
	 * The unfinished scan, if any. Self-heals a scan that has no job queued (for example after
	 * Action Scheduler's tables were cleaned) by queueing one.
	 *
	 * @return int Scan ID, or 0.
	 */
	public static function active_scan_id() {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1", $t['scans'] ) );
		if ( $id && function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK, array( $id ), Installer::JOB_GROUP ) ) {
			self::enqueue_now( $id );
		}
		return $id;
	}

	/**
	 * Job handler: scans one batch of files.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public static function run_batch( $scan_id ) {
		global $wpdb;
		$scan_id = (int) $scan_id;
		$budget  = Budget::for_batch();

		if ( ! self::lock( $scan_id, $budget ) ) {
			return; // Finished, cancelled, or another runner has it.
		}

		// If this request dies, the watchdog resumes the scan once the lock has expired.
		as_schedule_single_action( time() + (int) ceil( $budget->seconds() ) + 2 * self::recovery_delay(), self::HOOK, array( $scan_id ), Installer::JOB_GROUP, false, self::JOB_PRIORITY );

		$t    = Installer::tables();
		$more = true;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$scan = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t['scans'], $scan_id ) );
			if ( 'queued' === $scan->status ) {
				self::prepare( $scan );
			}
			$more = self::work( $scan, $budget );
			if ( ! $more ) {
				self::finish( $scan_id );
			}
		} catch ( \Throwable $e ) {
			$more = self::record_scan_failure( $scan_id, $e );
		}

		self::sync_scan_progress( $scan_id );
		// Replace the watchdog with an immediate follow-up (or nothing, when done).
		as_unschedule_all_actions( self::HOOK, array( $scan_id ), Installer::JOB_GROUP );
		self::unlock( $scan_id );
		if ( $more ) {
			$pause = \Airworthy\Settings::batch_pause(); // Scan speed "gentle" spaces batches out.
			if ( $pause > 0 && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				as_schedule_single_action( time() + $pause, self::HOOK, array( $scan_id ), Installer::JOB_GROUP, false, self::JOB_PRIORITY );
			} else {
				self::enqueue_now( $scan_id );
			}
		}

		// This batch used most of the request. Stop Action Scheduler starting more jobs in it
		// (ours or anyone's): they run on the next request, with fresh time and memory. Without
		// this, AS runs our follow-up batch straight away in the same process, so deferred
		// files never get the fresh request they're retried in.
		add_filter( 'action_scheduler_maximum_execution_time_likely_to_be_exceeded', '__return_true' );
	}

	/**
	 * Lists a queued scan's components now, in this request, instead of in its first batch.
	 * For WP-CLI's `scan --only … --background`: the --only filter only exists in the CLI
	 * process, so the list must be built before it exits.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public static function prepare_now( $scan_id ) {
		global $wpdb;
		$scan_id = (int) $scan_id;
		if ( ! self::lock( $scan_id, Budget::for_batch() ) ) {
			return;
		}
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$scan = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t['scans'], $scan_id ) );
		if ( $scan && 'queued' === $scan->status ) {
			self::prepare( $scan );
			self::sync_scan_progress( $scan_id );
		}
		self::unlock( $scan_id );
	}

	/**
	 * First batch: lists components and their files.
	 *
	 * @param object $scan Scan row.
	 */
	private static function prepare( $scan ) {
		global $wpdb;
		$t     = Installer::tables();
		$total = 0;
		$count = 0;

		foreach ( Components::discover() as $c ) {
			$listing = FileLister::list_files( Components::root( $c['type'], $c['rel_path'], $c['slug'] ) );
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$t['components'],
				array(
					'scan_id'       => (int) $scan->id,
					'type'          => $c['type'],
					'slug'          => $c['slug'],
					'name'          => mb_substr( $c['name'], 0, 255 ),
					'version'       => mb_substr( $c['version'], 0, 64 ),
					'rel_path'      => $c['rel_path'],
					'is_active'     => $c['is_active'] ? 1 : 0,
					'status'        => 'queued',
					'phase'         => 'main',
					'files'         => wp_json_encode( $listing['files'] ),
					'deferred'      => '[]',
					'files_total'   => count( $listing['files'] ),
					'files_ignored' => $listing['ignored'],
				)
			);
			$total += count( $listing['files'] );
			++$count;
		}

		$data = array(
			'status'           => 'running',
			'started_at'       => self::now(),
			'components_total' => $count,
			'files_total'      => $total,
			'skipped_inactive' => Components::$last_skipped['inactive'],
			'skipped_ignored'  => Components::$last_skipped['ignored'],
		);
		// WordPress.org signals are fetched alongside the file scan, when the site agreed to it.
		if ( 'off' !== $scan->wporg_status ) {
			$data['wporg_status'] = 'pending';
			\Airworthy\Wporg\Fetcher::enqueue( (int) $scan->id );
		}
		$wpdb->update( $t['scans'], $data, array( 'id' => (int) $scan->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$scan->status = 'running';
	}

	/**
	 * Scans files until the budget runs out or the scan is finished.
	 *
	 * @param object $scan   Scan row.
	 * @param Budget $budget Batch limits.
	 * @return bool Whether work remains.
	 */
	private static function work( $scan, Budget $budget ) {
		$engine  = null;
		$scanned = 0; // Files scanned in this request.

		while ( $budget->has_time() ) {
			$c = self::next_component( (int) $scan->id );
			if ( ! $c ) {
				return false;
			}

			$state         = array(
				'files'    => (array) json_decode( (string) $c->files, true ),
				'deferred' => (array) json_decode( (string) $c->deferred, true ),
				'cursor'   => (int) $c->file_cursor,
				'counts'   => array(
					'errors'        => (int) $c->errors,
					'guarded'       => (int) $c->guarded,
					'suppressed'    => (int) $c->suppressed,
					'existing'      => (int) $c->existing,
					'warnings'      => (int) $c->warnings,
					'files_done'    => (int) $c->files_done,
					'files_failed'  => (int) $c->files_failed,
					'files_skipped' => (int) $c->files_skipped,
					'attempts'      => (int) $c->attempts,
				),
				'phase'    => $c->phase,
				'target'   => $scan->target_php,
				'from'     => isset( $scan->from_php ) ? (string) $scan->from_php : '',
			);
			$state['root'] = Components::root( $c->type, $c->rel_path, $c->slug );
			$root          = Components::root( $c->type, $c->rel_path, $c->slug );

			// Facts that span files (interfaces extending Serializable; the component's own
			// functions with removed-extension prefixes), read once before its first file.
			if ( null === $c->prepass ) {
				$c->prepass = wp_json_encode( FileLister::prepass( $root, $state['files'] ) );
				global $wpdb;
				$wpdb->update( Installer::tables()['components'], array( 'prepass' => $c->prepass ), array( 'id' => (int) $c->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			$prepass               = (array) json_decode( (string) $c->prepass, true );
			$state['serializable'] = isset( $prepass['serializable'] ) ? (array) $prepass['serializable'] : array();
			$state['own']          = array_intersect_key( $prepass, array_flip( array( 'functions', 'constants', 'classes' ) ) );

			if ( null !== $c->inflight_file ) {
				self::recover_crash( $c, $state );
				if ( 'done' === $state['phase'] ) {
					continue;
				}
				if ( $state['counts']['attempts'] > self::MAX_COMPONENT_CRASHES ) {
					self::give_up( $c, $state );
					continue;
				}
			}

			if ( 'main' === $state['phase'] ) {
				$n = count( $state['files'] );
				while ( $state['cursor'] < $n && $budget->has_time() ) {
					$index = $state['cursor'];
					$path  = self::path( $root, $state['files'][ $index ] );

					if ( ! self::readable_inside( $root, $path ) ) {
						self::record_problem( $c, $state, $index, self::RULE_UNREADABLE, __( 'The file could not be read.', 'airworthy' ), 'files_failed' );
						++$state['cursor'];
						continue;
					}
					if ( ! $budget->fits( (int) filesize( $path ) ) ) {
						$state['deferred'][] = $index; // Retried alone at the end, with the most memory.
						++$state['cursor'];
						continue;
					}

					$engine = $engine ? $engine : Engine::for_target( $scan->target_php );
					self::scan_one( $engine, $c, $state, $index, $path );
					++$state['cursor'];
					++$scanned;
				}
				if ( $state['cursor'] >= $n ) {
					$state['phase'] = $state['deferred'] ? 'retry' : 'done';
				}
				self::save( $c, $state, null );
				if ( 'done' === $state['phase'] ) {
					self::finish_component( $c );
				}
				continue;
			}

			// Retry phase: deferred files, on a realistic memory estimate. A file that doesn't fit
			// now waits for a fresh request; only one that doesn't fit even then is too large.
			while ( $state['deferred'] && $budget->has_time() ) {
				$index = (int) $state['deferred'][0];
				$path  = self::path( $root, $state['files'][ $index ] );
				if ( ! self::readable_inside( $root, $path ) ) {
					array_shift( $state['deferred'] );
					self::record_problem( $c, $state, $index, self::RULE_UNREADABLE, __( 'The file could not be read.', 'airworthy' ), 'files_failed' );
					continue;
				}
				if ( ! $budget->fits( (int) filesize( $path ), true ) ) {
					if ( $scanned > 0 ) {
						self::save( $c, $state, null );
						return true; // Try it at the start of the next request, with the most headroom.
					}
					array_shift( $state['deferred'] );
					self::record_problem( $c, $state, $index, self::RULE_TOO_LARGE, __( 'The file is too large to check within this server\'s memory limit.', 'airworthy' ), 'files_skipped' );
					continue;
				}
				array_shift( $state['deferred'] );
				$engine = $engine ? $engine : Engine::for_target( $scan->target_php );
				self::scan_one( $engine, $c, $state, $index, $path, true );
				++$scanned;
			}
			if ( ! $state['deferred'] ) {
				$state['phase'] = 'done';
			}
			self::save( $c, $state, null );
			if ( 'done' === $state['phase'] ) {
				self::finish_component( $c );
			}
		}

		return true;
	}

	/**
	 * Scans one file, with its index saved as "in flight" first so a crash can be detected.
	 *
	 * @param Engine $engine   Engine.
	 * @param object $c        Component row.
	 * @param array  $state    Component state (by reference).
	 * @param int    $index    File index.
	 * @param string $path     Absolute path.
	 * @param bool   $is_retry Whether this is the file's single retry.
	 */
	private static function scan_one( Engine $engine, $c, array &$state, $index, $path, $is_retry = false ) {
		// In the retry phase the file has already been shifted off the deferred list; keep it
		// there while in flight so a crash here is recognised as the second one.
		if ( $is_retry ) {
			array_unshift( $state['deferred'], $index );
		}
		self::save( $c, $state, $index );
		if ( $is_retry ) {
			array_shift( $state['deferred'] );
		}

		try {
			$engine->set_serializable_interfaces( $state['serializable'] );
			$issues = $engine->scan_file( $path );
		} catch ( \Throwable $e ) {
			self::record_problem( $c, $state, $index, self::RULE_ENGINE, $e->getMessage(), 'files_failed' );
			return;
		}

		$issues = self::normalise( $issues );
		if ( isset( $issues['failed'] ) ) {
			// PHP_CodeSniffer couldn't parse the file, so its results can't be trusted.
			self::record_problem( $c, $state, $index, self::RULE_ENGINE, $issues['failed'], 'files_failed' );
			return;
		}

		$classifier = null;
		foreach ( $issues as $k => $issue ) {
			if ( 'notice' === $issue['severity'] ) {
				continue;
			}
			if ( ! $classifier ) {
				$graph = LoadGraph::for_component( $state['root'], $state['files'], $state['target'], $state['own'] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
				$classifier = new Classifier( (string) file_get_contents( $path ), $state['files'][ $index ], $state['target'], $state['own'], $graph );
			}
			$issues[ $k ]['context'] = $classifier->classify( $issue );
			// A removal that already applies on the PHP version the site runs now isn't caused by
			// this upgrade: that code either never runs today or already fails today.
			if ( 'error' === $issue['severity'] && in_array( $issues[ $k ]['context'], array( Classifier::PLAIN, Classifier::SUPPRESSED ), true ) && self::already_applies( $issue['message'], $state['from'] ) ) {
				$issues[ $k ]['context'] = self::CONTEXT_EXISTING;
			}
			if ( Classifier::OWN_CODE === $issues[ $k ]['context'] ) {
				// Not a PHP problem: keep it visible as a notice explaining why it doesn't count.
				$issues[ $k ]['severity'] = 'notice';
				$issues[ $k ]['context']  = Classifier::PLAIN;
				$issues[ $k ]['message']  = sprintf(
					/* translators: %s: original PHP_CodeSniffer message. */
					__( 'Not a problem: this plugin provides the flagged function, constant or class itself (its own code or a polyfill). (%s)', 'airworthy' ),
					$issue['message']
				);
				continue;
			}
			if ( 'warning' === $issue['severity'] ) {
				++$state['counts']['warnings'];
			} elseif ( self::CONTEXT_EXISTING === $issues[ $k ]['context'] ) {
				++$state['counts']['existing'];
			} elseif ( Classifier::GUARDED === $issues[ $k ]['context'] ) {
				++$state['counts']['guarded'];
			} elseif ( Classifier::SUPPRESSED === $issues[ $k ]['context'] ) {
				++$state['counts']['suppressed'];
			} else {
				++$state['counts']['errors'];
			}
		}
		self::insert_issues( $c, $state['files'][ $index ], $issues );
		++$state['counts']['files_done'];
	}

	/**
	 * Tidies raw engine findings before they are classified and saved:
	 * - PHP_CodeSniffer's own notices: mixed line endings are dropped (they don't affect the
	 *   result); "no PHP code found" (short open tags) becomes an informational notice; any
	 *   other internal error means the file couldn't be parsed.
	 * - Rules ending in ".Changed" report behaviour changes, not removals: warnings, not errors.
	 *
	 * @param array $issues Findings from Engine::scan_file().
	 * @return array Findings, or ['failed' => reason] when the file couldn't be parsed.
	 */
	private static function normalise( array $issues ) {
		$out = array();
		foreach ( $issues as $issue ) {
			$rule = $issue['rule'];
			if ( 0 === strpos( $rule, 'Internal.' ) ) {
				if ( 0 === strpos( $rule, 'Internal.LineEndings' ) ) {
					continue;
				}
				if ( 'Internal.NoCodeFound' === $rule ) {
					$issue['severity'] = 'notice';
					$out[]             = $issue;
					continue;
				}
				return array( 'failed' => $issue['message'] );
			}
			if ( 'error' === $issue['severity'] && ( '.Changed' === substr( $rule, -8 ) || self::is_note_rule( $rule ) ) ) {
				$issue['severity'] = 'warning';
			}
			$out[] = $issue;
		}
		return $out;
	}

	/**
	 * Whether a rule only reports a harmless behaviour note (NOTE_RULES).
	 *
	 * @param string $rule Rule code.
	 * @return bool
	 */
	private static function is_note_rule( $rule ) {
		foreach ( self::NOTE_RULES as $note ) {
			if ( 0 === strpos( $rule, $note ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The PHP version in which a finding's change took effect, read from PHPCompatibility's
	 * message: "removed since PHP 8.0", else the last "since / as of / in PHP x.y".
	 *
	 * @param string $message Finding message.
	 * @return string|null "x.y", or null when the message names no version.
	 */
	public static function change_version( $message ) {
		// "PHP" is sometimes left out: "deprecated since 8.4".
		if ( preg_match( '/removed (?:since|in) (?:PHP )?(?:version )?(\d+\.\d+)/i', $message, $m ) ) {
			return $m[1];
		}
		if ( preg_match_all( '/(?:since|as of|in) PHP (?:version )?(\d+\.\d+)/i', $message, $m ) ) {
			return (string) end( $m[1] );
		}
		if ( preg_match( '/(?:deprecated|removed) (?:since|as of|in) (\d+\.\d+)/i', $message, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Whether a finding's change already applies on the PHP version the site upgrades from.
	 * Unknown versions count as new (the cautious answer).
	 *
	 * @param string $message Finding message.
	 * @param string $from    "x.y", or '' when the scan has none (older scans).
	 * @return bool
	 */
	public static function already_applies( $message, $from ) {
		$version = self::change_version( $message );
		return '' !== $from && null !== $version && version_compare( $version, $from, '<=' );
	}

	/**
	 * The previous request died while scanning the in-flight file.
	 *
	 * @param object $c     Component row.
	 * @param array  $state Component state (by reference).
	 */
	private static function recover_crash( $c, array &$state ) {
		$index = (int) $c->inflight_file;
		++$state['counts']['attempts'];

		if ( 'main' === $state['phase'] ) {
			// First crash: defer it and carry on after it.
			$state['deferred'][] = $index;
			$state['cursor']     = $index + 1;
		} else {
			// Crashed again on its single retry: record it as failed.
			$state['deferred'] = array_values( array_diff( $state['deferred'], array( $index ) ) );
			self::record_problem( $c, $state, $index, self::RULE_CRASHED, __( 'Checking this file stopped the server process twice (usually running out of memory or time).', 'airworthy' ), 'files_failed' );
			if ( ! $state['deferred'] ) {
				$state['phase'] = 'done';
			}
		}
		self::save( $c, $state, null );
		if ( 'done' === $state['phase'] ) {
			self::finish_component( $c );
		}
	}

	/**
	 * Too many crashes in one component: record its remaining files as not checked and move on.
	 *
	 * @param object $c     Component row.
	 * @param array  $state Component state.
	 */
	private static function give_up( $c, array $state ) {
		$remaining = $state['deferred'];
		for ( $i = $state['cursor'], $n = count( $state['files'] ); $i < $n; $i++ ) {
			$remaining[] = $i;
		}
		$remaining = array_values( array_unique( $remaining ) );
		if ( ! $remaining ) {
			$remaining = array( max( 0, $state['cursor'] - 1 ) );
		}
		self::record_problem( $c, $state, (int) reset( $remaining ), self::RULE_GAVE_UP, __( 'Checking stopped after the server process died repeatedly in this plugin.', 'airworthy' ), 'files_failed' );
		$state['counts']['files_failed'] += max( 0, count( $remaining ) - 1 );
		$state['cursor']                  = count( $state['files'] );
		$state['deferred']                = array();
		$state['phase']                   = 'done';
		self::save( $c, $state, null );
		self::finish_component( $c );
	}

	/**
	 * Records a file that wasn't checked, as an issue with severity "scan".
	 *
	 * @param object $c       Component row.
	 * @param array  $state   Component state (by reference).
	 * @param int    $index   File index.
	 * @param string $rule    One of the RULE_* constants.
	 * @param string $message Plain-language reason.
	 * @param string $counter files_failed or files_skipped.
	 */
	private static function record_problem( $c, array &$state, $index, $rule, $message, $counter ) {
		$file = isset( $state['files'][ $index ] ) ? $state['files'][ $index ] : '';
		self::insert_issues(
			$c,
			$file,
			array(
				array(
					'line'     => 0,
					'severity' => 'scan',
					'rule'     => $rule,
					'message'  => $message,
				),
			)
		);
		++$state['counts'][ $counter ];
	}

	/**
	 * Saves a component's progress in one query.
	 *
	 * @param object   $c        Component row.
	 * @param array    $state    Component state.
	 * @param int|null $inflight Index being scanned now, or null.
	 */
	private static function save( $c, array $state, $inflight ) {
		global $wpdb;
		$t    = Installer::tables();
		$data = array_merge(
			$state['counts'],
			array(
				'file_cursor'   => $state['cursor'],
				'inflight_file' => $inflight,
				'deferred'      => wp_json_encode( array_values( $state['deferred'] ) ),
				'phase'         => $state['phase'],
				'status'        => 'done' === $state['phase'] ? 'done' : 'scanning',
			)
		);
		if ( null === $c->started_at ) {
			$data['started_at'] = self::now();
			$c->started_at      = $data['started_at'];
		}
		$wpdb->update( $t['components'], $data, array( 'id' => (int) $c->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Marks a component finished, with its verdict.
	 *
	 * @param object $c Component row.
	 */
	private static function finish_component( $c ) {
		global $wpdb;
		$t = Installer::tables();
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$t['components'],
			array(
				'status'        => 'done',
				'phase'         => 'done',
				'inflight_file' => null,
				'finished_at'   => self::now(),
			),
			array( 'id' => (int) $c->id )
		);
		Results::refresh_component( (int) $c->id ); // Counts plus WordPress.org signals, if they're in.
	}

	/**
	 * Marks the scan complete.
	 *
	 * @param int $scan_id Scan ID.
	 */
	private static function finish( $scan_id ) {
		global $wpdb;
		$t = Installer::tables();
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$t['scans'],
			array(
				'status'      => 'complete',
				'finished_at' => self::now(),
			),
			array( 'id' => $scan_id )
		);
		Results::refresh_scan( $scan_id );
		update_site_option( \Airworthy\Admin\Notice::OPTION, (int) $scan_id ); // "Scan finished" notice until the results are seen.
	}

	/**
	 * A batch failed outright (not tied to one file). Retries a few times, then fails the scan.
	 *
	 * @param int        $scan_id Scan ID.
	 * @param \Throwable $e       What went wrong.
	 * @return bool Whether to try again.
	 */
	private static function record_scan_failure( $scan_id, \Throwable $e ) {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$attempts = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attempts FROM %i WHERE id = %d', $t['scans'], $scan_id ) );
		$data     = array(
			'attempts' => $attempts,
			'error'    => get_class( $e ) . ': ' . $e->getMessage(),
		);
		$retry    = $attempts < self::MAX_SCAN_ATTEMPTS;
		if ( ! $retry ) {
			$data['status']      = 'failed';
			$data['finished_at'] = self::now();
		}
		$wpdb->update( $t['scans'], $data, array( 'id' => $scan_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $retry;
	}

	/**
	 * Copies file progress up to the scan row, for the progress bar.
	 *
	 * @param int $scan_id Scan ID.
	 */
	private static function sync_scan_progress( $scan_id ) {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$done = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(files_done + files_failed + files_skipped) FROM %i WHERE scan_id = %d', $t['components'], $scan_id ) );
		$wpdb->update( $t['scans'], array( 'files_done' => $done ), array( 'id' => $scan_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Next component to work on: first passes before any retries.
	 *
	 * @param int $scan_id Scan ID.
	 * @return object|null
	 */
	private static function next_component( $scan_id ) {
		global $wpdb;
		$t = Installer::tables();
		foreach ( array( 'main', 'retry' ) as $phase ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE scan_id = %d AND phase = %s ORDER BY id LIMIT 1', $t['components'], $scan_id, $phase ) );
			if ( $row ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Saves issues for one file.
	 *
	 * @param object $c      Component row.
	 * @param string $file   Path relative to the component.
	 * @param array  $issues Issues from the engine (or scan problems).
	 */
	private static function insert_issues( $c, $file, array $issues ) {
		global $wpdb;
		$t    = Installer::tables();
		$file = mb_substr( $file, 0, 512 );
		foreach ( $issues as $issue ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
			$wpdb->insert(
				$t['issues'],
				array(
					'scan_id'      => (int) $c->scan_id,
					'component_id' => (int) $c->id,
					'file'         => $file,
					'line'         => (int) $issue['line'],
					'severity'     => $issue['severity'],
					'context'      => isset( $issue['context'] ) ? $issue['context'] : Classifier::PLAIN,
					'rule'         => substr( $issue['rule'], 0, 191 ),
					'message'      => self::without_server_paths( $issue['message'] ),
				),
				array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Takes the scan's lock until the batch's time budget (plus a margin) has passed.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param Budget $budget  Batch limits.
	 * @return bool Whether the lock was taken.
	 */
	private static function lock( $scan_id, Budget $budget ) {
		global $wpdb;
		$t     = Installer::tables();
		$until = gmdate( 'Y-m-d H:i:s', time() + (int) ceil( $budget->seconds() ) + self::recovery_delay() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE %i SET locked_until = %s WHERE id = %d AND status IN ('queued','running') AND (locked_until IS NULL OR locked_until < %s)", $t['scans'], $until, $scan_id, self::now() ) );
		return 1 === (int) $taken;
	}

	/**
	 * Releases the scan's lock.
	 *
	 * @param int $scan_id Scan ID.
	 */
	private static function unlock( $scan_id ) {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET locked_until = NULL WHERE id = %d', $t['scans'], $scan_id ) );
	}

	/**
	 * Seconds a dead batch's lock outlives its time budget.
	 *
	 * @return int
	 */
	private static function recovery_delay() {
		/**
		 * Filters how long after a batch's time budget a dead batch's lock expires. The
		 * watchdog resumes the scan after twice this.
		 *
		 * @param int $seconds Default 60.
		 */
		return max( 1, (int) apply_filters( 'airworthy_recovery_delay', self::RECOVERY_DELAY ) );
	}

	/**
	 * Queues a batch to run as soon as possible.
	 *
	 * @param int $scan_id Scan ID.
	 */
	private static function enqueue_now( $scan_id ) {
		as_enqueue_async_action( self::HOOK, array( (int) $scan_id ), Installer::JOB_GROUP, false, self::JOB_PRIORITY );
	}

	/**
	 * Deletes all but the newest KEEP_SCANS scans, with their components and issues.
	 */
	private static function prune() {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$old = array_slice( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC', $t['scans'] ) ) ), self::KEEP_SCANS );
		if ( ! $old ) {
			return;
		}
		// IDs only grow, so every older scan has an ID at or below the newest one being removed.
		$newest_old = $old[0];
		foreach ( array( 'issues', 'components' ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE scan_id <= %d', $t[ $table ], $newest_old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $t['scans'], $newest_old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Absolute path of a component file. Single-file plugins have the file itself as root.
	 *
	 * @param string $root     Component root.
	 * @param string $relative Relative path.
	 * @return string
	 */
	private static function path( $root, $relative ) {
		return is_file( $root ) ? $root : trailingslashit( $root ) . $relative;
	}

	/**
	 * Whether a file is readable and really inside its component (no symlinks out).
	 *
	 * @param string $root Component root.
	 * @param string $path File path.
	 * @return bool
	 */
	private static function readable_inside( $root, $path ) {
		$real_root = realpath( $root );
		$real_path = realpath( $path );
		if ( false === $real_root || false === $real_path || ! is_readable( $real_path ) ) {
			return false;
		}
		return $real_path === $real_root || 0 === strpos( $real_path, trailingslashit( $real_root ) );
	}

	/**
	 * Removes absolute server paths from a message, so results never reveal them.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function without_server_paths( $message ) {
		$paths = array_unique( array_filter( array( WP_PLUGIN_DIR, get_theme_root(), WP_CONTENT_DIR, untrailingslashit( ABSPATH ) ) ) );
		usort(
			$paths,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		foreach ( $paths as $path ) {
			$message = str_replace( trailingslashit( $path ), '', $message );
			$message = str_replace( $path, '', $message );
		}
		return $message;
	}

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @return string
	 */
	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
