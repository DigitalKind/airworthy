<?php
/**
 * REST endpoints for the admin screen and other clients.
 *
 * @package Airworthy
 */

namespace Airworthy\Rest;

use Airworthy\Capabilities;
use Airworthy\Installer;
use Airworthy\Scan\Queue;
use Airworthy\Scan\Verdict;
use Airworthy\Targets;
use Airworthy\Wporg\Consent;
use Airworthy\Wporg\Fetcher;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /airworthy/v1. The admin screen only ever talks to these; they read results
 * back from the plugin's tables.
 *
 * Security: every route requires the scan capability (manage_options, or
 * manage_network_options on multisite). Browser requests authenticate with the logged-in
 * cookie, which WordPress only honours together with a valid wp_rest nonce (X-WP-Nonce);
 * without one the request is anonymous and refused. Queries are prepared, and file paths in
 * results are always relative to the plugin or theme folder.
 */
final class Controller {

	/**
	 * Issue severities and contexts a filter may ask for. Four of each, which the issue
	 * queries' fixed IN (%s,%s,%s,%s) lists rely on.
	 */
	const SEVERITIES = array( 'error', 'warning', 'scan', 'notice' );
	const CONTEXTS   = array( 'plain', 'guarded', 'suppressed', 'existing' );

	/**
	 * Turns a filter into a flag and four values for a fixed `( %d = 0 OR x IN (%s,%s,%s,%s) )`,
	 * so the SQL never changes shape: flag 0 when nothing (known) was asked for, otherwise the
	 * asked-for values, repeated to fill all four places.
	 *
	 * @param mixed    $asked   Requested values.
	 * @param string[] $allowed Known values (four).
	 * @return array{0:int,1:string[]}
	 */
	public static function filter_values( $asked, array $allowed ) {
		$values = array_values( array_intersect( $allowed, array_map( 'strval', (array) $asked ) ) );
		if ( ! $values ) {
			return array( 0, $allowed );
		}
		return array( 1, array_pad( $values, count( $allowed ), $values[0] ) );
	}


	const NAMESPACE_V1 = 'airworthy/v1';

	/** Site transient caching how many files a full scan covers (for the time estimate). */
	const FILE_COUNT = 'airworthy_file_count';

	/**
	 * Registers routes (on rest_api_init).
	 */
	public static function register_routes() {
		$can  = array( Capabilities::class, 'current_user_can_scan' );
		$id   = array(
			'validate_callback' => static function ( $v ) {
				return is_numeric( $v ) && (int) $v > 0;
			},
			'sanitize_callback' => 'absint',
		);
		$ns   = self::NAMESPACE_V1;
		$scan = '/scans/(?P<id>\d+)';

		register_rest_route(
			$ns,
			'/scans',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'start' ),
				'permission_callback' => $can,
				'args'                => array(
					'target' => array(
						'required'    => true,
						'type'        => 'string',
						'enum'        => Targets::ALL,
						'description' => __( 'Exact PHP version to check against.', 'airworthy' ),
					),
					'from'   => array(
						'type'        => 'string',
						'pattern'     => '^[5-9]\\.[0-9]$',
						'description' => __( 'PHP version the site upgrades from, e.g. 8.3. Default: this server\'s.', 'airworthy' ),
					),
					'wporg'  => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to look plugins up on WordPress.org (sends their folder names). Saved as the site\'s choice. Default: the saved choice, off if none.', 'airworthy' ),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/scans/current',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'current' ),
				'permission_callback' => $can,
			)
		);
		register_rest_route(
			$ns,
			$scan,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_scan' ),
				'permission_callback' => $can,
				'args'                => array( 'id' => $id ),
			)
		);
		register_rest_route(
			$ns,
			$scan . '/components/(?P<component>\d+)/issues',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'issues' ),
				'permission_callback' => $can,
				'args'                => array(
					'id'        => $id,
					'component' => $id,
					'severity'  => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
							'enum' => self::SEVERITIES,
						),
					),
					'context'   => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
							'enum' => self::CONTEXTS,
						),
					),
					'page'      => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 100,
						'minimum' => 1,
						'maximum' => 500,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			$scan . '/components/(?P<component>\d+)/rescan',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rescan' ),
				'permission_callback' => $can,
				'args'                => array(
					'id'        => $id,
					'component' => $id,
				),
			)
		);
		register_rest_route(
			$ns,
			$scan . '/unchecked',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'unchecked' ),
				'permission_callback' => $can,
				'args'                => array( 'id' => $id ),
			)
		);
		register_rest_route(
			$ns,
			'/estimate',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'estimate' ),
				'permission_callback' => $can,
			)
		);
		register_rest_route(
			$ns,
			$scan . '/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cancel' ),
				'permission_callback' => $can,
				'args'                => array( 'id' => $id ),
			)
		);
		register_rest_route(
			$ns,
			$scan . '/resume',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'resume' ),
				'permission_callback' => $can,
				'args'                => array( 'id' => $id ),
			)
		);
		register_rest_route(
			$ns,
			$scan . '/nudge',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'nudge' ),
				'permission_callback' => $can,
				'args'                => array( 'id' => $id ),
			)
		);
	}

	/**
	 * POST /scans: starts a scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function start( \WP_REST_Request $request ) {
		$wporg = null;
		if ( null !== $request['wporg'] ) {
			$wporg = (bool) $request['wporg'];
			Consent::set( $wporg ); // The administrator's explicit answer, remembered for next time.
		}
		$from = null !== $request['from'] && '' !== $request['from'] ? (string) $request['from'] : null;
		$id   = Queue::start( (string) $request['target'], get_current_user_id(), $wporg, $from );
		if ( is_wp_error( $id ) ) {
			$id->add_data( array( 'status' => 'airworthy_scan_running' === $id->get_error_code() ? 409 : 400 ) );
			return $id;
		}
		return new \WP_REST_Response( self::scan_summary( self::scan_row( $id ) ), 201 );
	}

	/**
	 * GET /scans/current: the unfinished scan, or else the latest one, with per-component progress
	 * (null when no scan has been run yet).
	 *
	 * @param \WP_REST_Request|null $request Request (seen=1 marks a finished scan's notice as seen).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function current( $request = null ) {
		global $wpdb;
		$t  = Installer::tables();
		$id = Queue::active_scan_id();
		if ( ! $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1', $t['scans'] ) );
		}
		if ( ! $id ) {
			return new \WP_REST_Response( null, 200 ); // No scan yet: a normal state, not an error.
		}
		$response = self::get_scan( array( 'id' => $id ) );
		// The results screen passes seen=1 once it shows a finished scan: no "finished" notice needed.
		if ( $request instanceof \WP_REST_Request && $request['seen'] && ! is_wp_error( $response ) && 'complete' === $response->data['status'] ) {
			\Airworthy\Admin\Notice::mark_seen();
		}
		return $response;
	}

	/**
	 * GET /scans/{id}: summary and every component with its verdict and counts.
	 *
	 * @param \WP_REST_Request|array $request Request (or ['id' => …]).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_scan( $request ) {
		global $wpdb;
		$scan = self::scan_row( (int) $request['id'] );
		if ( ! $scan ) {
			return self::not_found();
		}
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, type, slug, name, version, is_active, status, phase, verdict, errors, guarded, suppressed, existing, warnings, files_total, files_done, files_failed, files_skipped, files_ignored, wporg FROM %i WHERE scan_id = %d ORDER BY id', $t['components'], $scan->id ) );

		$order      = array_flip( Verdict::ALL );
		$updates    = \Airworthy\Scan\Updates::all();
		$components = array();
		foreach ( $rows as $r ) {
			$components[] = array(
				'id'      => (int) $r->id,
				'type'    => $r->type,
				'slug'    => $r->slug,
				'name'    => $r->name,
				'version' => $r->version,
				'active'  => (bool) $r->is_active,
				'status'  => $r->status,
				'phase'   => $r->phase,
				'verdict' => $r->verdict,
				'counts'  => array(
					'errors'     => (int) $r->errors,
					'suppressed' => (int) $r->suppressed,
					'guarded'    => (int) $r->guarded,
					'existing'   => (int) $r->existing,
					'warnings'   => (int) $r->warnings,
				),
				'files'   => array(
					'total'   => (int) $r->files_total,
					'checked' => (int) $r->files_done,
					'failed'  => (int) $r->files_failed,
					'skipped' => (int) $r->files_skipped,
					'ignored' => (int) $r->files_ignored,
				),
				'wporg'   => $r->wporg ? json_decode( $r->wporg, true ) : null,
				'update'  => isset( $updates[ $r->type . '|' . $r->slug ] ) ? $updates[ $r->type . '|' . $r->slug ] : null,
			);
		}
		// Most serious first, then by name.
		usort(
			$components,
			static function ( $a, $b ) use ( $order ) {
				$va = isset( $order[ $a['verdict'] ] ) ? $order[ $a['verdict'] ] : count( $order );
				$vb = isset( $order[ $b['verdict'] ] ) ? $order[ $b['verdict'] ] : count( $order );
				return $va === $vb ? strcasecmp( $a['name'], $b['name'] ) : $va - $vb;
			}
		);

		$out               = self::scan_summary( $scan );
		$out['components'] = $components;
		return new \WP_REST_Response( $out, 200 );
	}

	/**
	 * GET /scans/{id}/components/{component}/issues: findings with file, line and message.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function issues( \WP_REST_Request $request ) {
		global $wpdb;
		$t    = Installer::tables();
		$scan = (int) $request['id'];
		$comp = (int) $request['component'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d AND scan_id = %d', $t['components'], $comp, $scan ) ) ) {
			return self::not_found();
		}
		// Optional filters, each as a flag (0 = no filter) and exactly four values for a fixed IN ().
		list( $sev_on, $sev ) = self::filter_values( $request['severity'], self::SEVERITIES );
		list( $ctx_on, $ctx ) = self::filter_values( $request['context'], self::CONTEXTS );
		$per_page             = (int) $request['per_page'];
		$offset               = ( (int) $request['page'] - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scan_id = %d AND component_id = %d AND ( %d = 0 OR severity IN (%s,%s,%s,%s) ) AND ( %d = 0 OR context IN (%s,%s,%s,%s) )', $t['issues'], $scan, $comp, $sev_on, $sev[0], $sev[1], $sev[2], $sev[3], $ctx_on, $ctx[0], $ctx[1], $ctx[2], $ctx[3] ) );
		// Scan problems first, then errors, then warnings; within each by file and line.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT file, line, severity, context, rule, message FROM %i WHERE scan_id = %d AND component_id = %d AND ( %d = 0 OR severity IN (%s,%s,%s,%s) ) AND ( %d = 0 OR context IN (%s,%s,%s,%s) ) ORDER BY CASE severity WHEN 'scan' THEN 0 WHEN 'error' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END, CASE context WHEN 'plain' THEN 0 WHEN 'suppressed' THEN 1 WHEN 'guarded' THEN 2 ELSE 3 END, file, line LIMIT %d OFFSET %d", $t['issues'], $scan, $comp, $sev_on, $sev[0], $sev[1], $sev[2], $sev[3], $ctx_on, $ctx[0], $ctx[1], $ctx[2], $ctx[3], $per_page, $offset ) );

		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'file'     => $r->file,
				'line'     => (int) $r->line,
				'severity' => $r->severity,
				'context'  => $r->context,
				'rule'     => $r->rule,
				'message'  => $r->message,
			);
		}
		$response = new \WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );
		return $response;
	}

	/**
	 * GET /scans/{id}/unchecked: every file that couldn't be checked, with its plugin or theme
	 * and the reason (unreadable, crashed twice, too large for the memory limit, unparseable).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function unchecked( \WP_REST_Request $request ) {
		global $wpdb;
		if ( ! self::scan_row( (int) $request['id'] ) ) {
			return self::not_found();
		}
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own tables.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.id AS component_id, c.name, c.type, i.file, i.rule, i.message FROM %i i JOIN %i c ON c.id = i.component_id WHERE i.scan_id = %d AND i.severity = 'scan' ORDER BY c.name, i.file", $t['issues'], $t['components'], (int) $request['id'] ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'component_id' => (int) $r->component_id,
				'component'    => $r->name,
				'type'         => $r->type,
				'file'         => $r->file,
				'reason'       => $r->rule, // Airworthy.Scan.Unreadable / Crashed / TooLarge / EngineError / TooManyCrashes.
				'message'      => $r->message,
			);
		}
		return new \WP_REST_Response( $out, 200 );
	}

	/**
	 * POST /scans/{id}/components/{component}/rescan: checks one plugin or theme again.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rescan( \WP_REST_Request $request ) {
		$result = Queue::rescan_component( (int) $request['id'], (int) $request['component'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 'airworthy_not_found' === $result->get_error_code() ? 404 : 409 ) );
			return $result;
		}
		return new \WP_REST_Response( self::scan_summary( self::scan_row( (int) $request['id'] ) ), 200 );
	}

	/**
	 * GET /estimate: how many files a new scan would check, and roughly how long it takes.
	 * Counts the files a full scan would cover (the last scan may have covered only some
	 * plugins: a rescan, or WP-CLI's --only), cached for an hour.
	 * Minutes: 7 ms per file on a fast server, 40 ms on cheap shared hosting (Step 1/3 tests).
	 *
	 * @return \WP_REST_Response
	 */
	public static function estimate() {
		$files = get_site_transient( self::FILE_COUNT );
		if ( false === $files ) {
			$files = 0;
			foreach ( \Airworthy\Scan\Components::discover() as $c ) {
				$listing = \Airworthy\Scan\FileLister::list_files( \Airworthy\Scan\Components::root( $c['type'], $c['rel_path'], $c['slug'] ) );
				$files  += count( $listing['files'] );
			}
			set_site_transient( self::FILE_COUNT, $files, HOUR_IN_SECONDS );
		}
		$files = (int) $files;
		return new \WP_REST_Response(
			array(
				'files'        => $files,
				'minutes_low'  => max( 1, (int) ceil( $files * 0.007 / 60 ) ),
				'minutes_high' => max( 1, (int) ceil( $files * 0.040 / 60 ) ),
			),
			200
		);
	}

	/**
	 * POST /scans/{id}/cancel.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function cancel( \WP_REST_Request $request ) {
		if ( ! self::scan_row( (int) $request['id'] ) ) {
			return self::not_found();
		}
		Queue::cancel( (int) $request['id'] );
		return new \WP_REST_Response( self::scan_summary( self::scan_row( (int) $request['id'] ) ), 200 );
	}

	/**
	 * POST /scans/{id}/resume: continues a stopped scan where it left off.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function resume( \WP_REST_Request $request ) {
		if ( ! self::scan_row( (int) $request['id'] ) ) {
			return self::not_found();
		}
		$result = Queue::resume( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		return new \WP_REST_Response( self::scan_summary( self::scan_row( (int) $request['id'] ) ), 200 );
	}

	/**
	 * POST /scans/{id}/nudge: runs one batch in this request (file scan, or the WordPress.org
	 * lookups left once it's done). The admin screen calls it while open, so scans still
	 * progress on sites where WP-Cron is disabled or rarely triggered.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function nudge( \WP_REST_Request $request ) {
		$scan = self::scan_row( (int) $request['id'] );
		if ( ! $scan ) {
			return self::not_found();
		}
		if ( in_array( $scan->status, array( 'queued', 'running' ), true ) ) {
			Queue::run_batch( (int) $scan->id ); // Returns at once if another runner holds the lock.
		} elseif ( 'complete' === $scan->status && 'pending' === $scan->wporg_status ) {
			Fetcher::run( (int) $scan->id ); // WordPress.org lookups still left after the file scan.
		}
		return new \WP_REST_Response( self::scan_summary( self::scan_row( (int) $scan->id ) ), 200 );
	}

	/**
	 * Loads a scan row.
	 *
	 * @param int $id Scan ID.
	 * @return object|null
	 */
	private static function scan_row( $id ) {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t['scans'], $id ) );
	}

	/**
	 * Public shape of a scan.
	 *
	 * @param object $s Scan row.
	 * @return array
	 */
	private static function scan_summary( $s ) {
		$counts = array();
		foreach ( Verdict::ALL as $verdict ) {
			$counts[ $verdict ] = (int) $s->{'count_' . $verdict};
		}
		$total = (int) $s->files_total;
		return array(
			'id'           => (int) $s->id,
			'target_php'   => $s->target_php,
			'host_php'     => $s->host_php,
			'host_support' => Targets::support_status( \Airworthy\Env::php_version() ), // The server now, not when scanned.
			'from_php'     => isset( $s->from_php ) && '' !== $s->from_php ? $s->from_php : null,
			'status'       => $s->status,
			'resumable'    => Queue::can_resume( $s ),
			'progress'     => array(
				'files_done'  => (int) $s->files_done,
				'files_total' => $total,
				'percent'     => $total ? (int) floor( 100 * (int) $s->files_done / $total ) : ( 'complete' === $s->status ? 100 : 0 ),
				'components'  => (int) $s->components_total,
			),
			'skipped'      => array(
				'inactive' => isset( $s->skipped_inactive ) ? (int) $s->skipped_inactive : 0,
				'ignored'  => isset( $s->skipped_ignored ) ? (int) $s->skipped_ignored : 0,
			),
			'settings_url' => add_query_arg( 'tab', 'settings', \Airworthy\Admin\Page::url() ),
			'updates_url'  => current_user_can( 'update_plugins' ) ? self_admin_url( 'update-core.php' ) : null,
			'counts'       => $counts,
			'wporg'        => $s->wporg_status ? $s->wporg_status : null, // pending | done | partial | offline | off (no consent).
			'export_url'   => \Airworthy\Admin\Export::url( (int) $s->id ),
			'created_at'   => self::iso( $s->created_at ),
			'started_at'   => self::iso( $s->started_at ),
			'finished_at'  => self::iso( $s->finished_at ),
			'error'        => 'failed' === $s->status ? __( 'The scan could not be completed. Try again, or ask your host whether background tasks (WP-Cron) are working.', 'airworthy' ) : null,
		);
	}

	/**
	 * MySQL UTC datetime to ISO 8601.
	 *
	 * @param string|null $datetime Datetime.
	 * @return string|null
	 */
	private static function iso( $datetime ) {
		return $datetime ? gmdate( 'c', (int) strtotime( $datetime . ' UTC' ) ) : null;
	}

	/**
	 * 404 error.
	 *
	 * @return \WP_Error
	 */
	private static function not_found() {
		return new \WP_Error( 'airworthy_not_found', __( 'Not found.', 'airworthy' ), array( 'status' => 404 ) );
	}
}
