<?php
/**
 * WP-CLI commands.
 *
 * @package Airworthy
 */

namespace Airworthy\Cli;

use Airworthy\Admin\Export;
use Airworthy\Installer;
use Airworthy\Scan\Components;
use Airworthy\Scan\Queue;
use Airworthy\Scan\Verdict;
use Airworthy\Targets;
use Airworthy\Wporg\Fetcher;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Checks which plugins and themes are ready for a newer PHP version, from the terminal.
 *
 * Uses the same scanner, verdicts and results as the admin screen (Tools > Airworthy), so both
 * always agree. Scans run in this process, without the web's time limits, unless --background
 * is given.
 *
 * ## EXAMPLES
 *
 *     # Check the whole site against the recommended PHP version.
 *     $ wp airworthy scan
 *
 *     # Check against PHP 8.4 and fail (exit code 1) if anything would break, e.g. in CI.
 *     $ wp airworthy scan --target=8.4 --fail-on=blocker
 *
 *     # What needs attention in the latest results?
 *     $ wp airworthy results --verdict=blocker,unknown
 *
 *     # One plugin's findings.
 *     $ wp airworthy issues woocommerce --severity=error
 */
final class Command {

	/** Result columns shown by default. */
	const FIELDS = array( 'name', 'slug', 'version', 'verdict', 'errors', 'suppressed', 'guarded', 'warnings', 'wporg' );

	/**
	 * Registers the command.
	 */
	public static function register() {
		WP_CLI::add_command( 'airworthy', self::class );
	}

	/**
	 * Scans plugins and themes against a PHP version.
	 *
	 * ## OPTIONS
	 *
	 * [--target=<version>]
	 * : PHP version to check against. Default: the oldest version still getting security fixes that's newer than this server's (see `wp airworthy targets`).
	 *
	 * [--only=<slugs>]
	 * : Comma-separated plugin or theme slugs to check, instead of everything installed.
	 *
	 * [--fail-on=<verdict>]
	 * : Exit with code 1 when any plugin or theme has this verdict or a more serious one (for CI).
	 * ---
	 * default: none
	 * options:
	 *   - none
	 *   - blocker
	 *   - unknown
	 *   - suppressed
	 *   - guarded
	 *   - warnings
	 * ---
	 *
	 * [--from=<version>]
	 * : PHP version the site upgrades from, e.g. 7.4. Problems from PHP changes up to that version already apply today, so they aren't Blockers. Default: this server's PHP version.
	 *
	 * [--[no-]wporg]
	 * : Also look plugins up on WordPress.org (closed, abandoned, Requires PHP), which sends their folder names to api.wordpress.org. Default: the choice saved on the admin screen; off if nobody has chosen yet.
	 *
	 * [--background]
	 * : Queue the scan for the site's background jobs (like the admin screen) and return at once.
	 *
	 * [--format=<format>]
	 * : Output format for the results.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - summary
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp airworthy scan --target=8.4
	 *     $ wp airworthy scan --only=woocommerce,my-plugin --fail-on=blocker --format=summary
	 *     $ wp airworthy scan --target=8.3 --wporg
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function scan( $args, $assoc_args ) {
		$target = isset( $assoc_args['target'] ) ? (string) $assoc_args['target'] : Targets::default_for( PHP_VERSION );
		if ( ! Targets::is_valid( $target ) ) {
			WP_CLI::error( sprintf( 'Unsupported target "%s". Choose one of: %s.', $target, implode( ', ', Targets::ALL ) ) );
		}
		if ( ! Targets::is_supported( $target ) ) {
			WP_CLI::warning( sprintf( 'PHP %1$s no longer gets security fixes, and results for unsupported versions are less precise. We recommend PHP %2$s.', $target, Targets::default_for( PHP_VERSION ) ) );
		}

		$only = isset( $assoc_args['only'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['only'] ) ) ) : array();
		if ( $only ) {
			Components::$bypass_settings = true; // --only scans exactly what it names.
			add_filter(
				'airworthy_scan_components',
				static function ( $components ) use ( $only ) {
					return array_filter(
						$components,
						static function ( $c ) use ( $only ) {
							return in_array( $c['slug'], $only, true );
						}
					);
				}
			);
		}

		$wporg = Utils\get_flag_value( $assoc_args, 'wporg', null );
		$wporg = null === $wporg ? \Airworthy\Wporg\Consent::allowed() : (bool) $wporg;
		if ( ! $wporg ) {
			WP_CLI::log( 'WordPress.org checks are off for this scan (add --wporg to include them).' );
		}

		$from = isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : null;
		if ( null !== $from && ! Targets::is_valid_from( $from ) ) {
			WP_CLI::error( sprintf( 'Unsupported --from "%s". Use a PHP version such as 7.4 or 8.3.', $from ) );
		}

		$scan_id = Queue::start( $target, 0, $wporg, $from );
		if ( is_wp_error( $scan_id ) ) {
			WP_CLI::error( $scan_id->get_error_message() );
		}

		if ( Utils\get_flag_value( $assoc_args, 'background', false ) ) {
			if ( $only ) {
				Queue::prepare_now( $scan_id ); // Apply --only now: background batches won't see this filter.
			}
			WP_CLI::success( sprintf( 'Scan %1$d queued (PHP %2$s). Check on it with: wp airworthy status', $scan_id, $target ) );
			return;
		}

		WP_CLI::log( sprintf( 'Checking against PHP %1$s, upgrading from PHP %2$s…', $target, null !== $from ? $from : Targets::minor( \Airworthy\Env::php_version() ) ) );
		$this->run_to_completion( $scan_id );
		if ( $only && ! $this->components( $scan_id ) ) {
			WP_CLI::warning( sprintf( 'None of these are installed: %s.', implode( ', ', $only ) ) );
		}
		$this->print_results( $scan_id, $assoc_args );
		$this->exit_for( $scan_id, isset( $assoc_args['fail-on'] ) ? $assoc_args['fail-on'] : 'none' );
	}

	/**
	 * Shows the latest results (or a given scan's).
	 *
	 * ## OPTIONS
	 *
	 * [--scan=<id>]
	 * : Scan ID. Default: the latest scan.
	 *
	 * [--verdict=<verdicts>]
	 * : Only these verdicts, comma-separated: blocker, unknown, suppressed, guarded, warnings, ready.
	 *
	 * [--fields=<fields>]
	 * : Columns to show. Default: name,slug,version,verdict,errors,suppressed,guarded,warnings,wporg. Also available: type, active, existing (problems that already apply on the PHP version compared from), files, last_updated, tested, requires_php, update, closed.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - summary
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function results( $args, $assoc_args ) {
		$this->print_results( $this->scan_id( $assoc_args ), $assoc_args );
	}

	/**
	 * Lists one plugin's or theme's findings.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Plugin or theme slug (folder name).
	 *
	 * [--scan=<id>]
	 * : Scan ID. Default: the latest scan.
	 *
	 * [--severity=<severities>]
	 * : Only these, comma-separated: error, warning, scan (files not checked), notice.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function issues( $args, $assoc_args ) {
		global $wpdb;
		$t       = Installer::tables();
		$scan_id = $this->scan_id( $assoc_args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
		$component = $wpdb->get_row( $wpdb->prepare( "SELECT id, name FROM {$t['components']} WHERE scan_id = %d AND slug = %s", $scan_id, $args[0] ) );
		if ( ! $component ) {
			WP_CLI::error( sprintf( '"%1$s" is not in scan %2$d.', $args[0], $scan_id ) );
		}
		$where = array( 'component_id = %d' );
		$vals  = array( (int) $component->id );
		if ( ! empty( $assoc_args['severity'] ) ) {
			$severities = array_intersect( array_map( 'trim', explode( ',', (string) $assoc_args['severity'] ) ), array( 'error', 'warning', 'scan', 'notice' ) );
			if ( $severities ) {
				$where[] = 'severity IN (' . implode( ',', array_fill( 0, count( $severities ), '%s' ) ) . ')';
				$vals    = array_merge( $vals, array_values( $severities ) );
			}
		}
		$sql  = "SELECT file, line, severity, context, rule, message FROM {$t['issues']} WHERE " . implode( ' AND ', $where ) . " ORDER BY FIELD(severity, 'scan','error','warning','notice'), file, line";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table; placeholders built above.
		Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $rows, array( 'file', 'line', 'severity', 'context', 'rule', 'message' ) );
	}

	/**
	 * Shows the progress of the running scan, or the latest one's summary.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function status( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI's command signature.
		global $wpdb;
		$t  = Installer::tables();
		$id = Queue::active_scan_id();
		$id = $id ? $id : $this->scan_id( array() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
		$scan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['scans']} WHERE id = %d", $id ) );
		$pct  = $scan->files_total ? (int) floor( 100 * $scan->files_done / $scan->files_total ) : 0;
		WP_CLI::log( sprintf( 'Scan %1$d · PHP %2$s · %3$s · %4$d%% (%5$s of %6$s files) · WordPress.org: %7$s', $scan->id, $scan->target_php, $scan->status, $pct, number_format_i18n( $scan->files_done ), number_format_i18n( $scan->files_total ), $scan->wporg_status ? $scan->wporg_status : '-' ) );
	}

	/**
	 * Stops the running scan. Results so far are kept.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function cancel( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI's command signature.
		$id = Queue::active_scan_id();
		if ( ! $id ) {
			WP_CLI::warning( 'No scan is running.' );
			return;
		}
		Queue::cancel( $id );
		WP_CLI::success( sprintf( 'Scan %d stopped.', $id ) );
	}

	/**
	 * Continues the latest scan after it was stopped, from where it stopped.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format for the results.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - summary
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function resume( $args, $assoc_args ) {
		$scan_id = $this->scan_id( array() );
		$result  = Queue::resume( $scan_id );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::log( sprintf( 'Continuing scan %d.', $scan_id ) );
		$this->run_to_completion( $scan_id );
		$this->print_results( $scan_id, $assoc_args );
	}

	/**
	 * Checks plugins or themes again within the latest scan (e.g. after updating them).
	 *
	 * ## OPTIONS
	 *
	 * <slug>...
	 * : Plugin or theme slugs.
	 *
	 * [--format=<format>]
	 * : Output format for the results.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - summary
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function rescan( $args, $assoc_args ) {
		global $wpdb;
		$t       = Installer::tables();
		$scan_id = $this->scan_id( array() );
		$ids     = array();
		foreach ( $args as $slug ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['components']} WHERE scan_id = %d AND slug = %s", $scan_id, $slug ) );
			if ( ! $id ) {
				WP_CLI::error( sprintf( '"%1$s" is not in scan %2$d. Run a new scan to include it.', $slug, $scan_id ) );
			}
			$ids[] = $id;
		}
		$result = Queue::rescan_components( $scan_id, $ids );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		$this->run_to_completion( $scan_id );
		$assoc_args['only-ids'] = $ids;
		$this->print_results( $scan_id, $assoc_args );
	}

	/**
	 * Exports results as CSV (the same file as the admin screen's Export CSV).
	 *
	 * ## OPTIONS
	 *
	 * [--scan=<id>]
	 * : Scan ID. Default: the latest scan.
	 *
	 * [--file=<path>]
	 * : Write to this file instead of standard output.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function export( $args, $assoc_args ) {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
		$scan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['scans']} WHERE id = %d", $this->scan_id( $assoc_args ) ) );
		$path = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : 'php://stdout';
		$out  = @fopen( $path, 'w' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CLI output file.
		if ( ! $out ) {
			WP_CLI::error( sprintf( 'Cannot write to %s.', $path ) );
		}
		Export::write( $scan, $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CLI output file.
		if ( isset( $assoc_args['file'] ) ) {
			WP_CLI::success( sprintf( 'Saved %s.', $path ) );
		}
	}

	/**
	 * Lists the PHP versions Airworthy can check against, with their security support.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function targets( $args, $assoc_args ) {
		$default = Targets::default_for( PHP_VERSION );
		$rows    = array();
		foreach ( Targets::ALL as $target ) {
			$rows[] = array(
				'php'         => $target,
				'security'    => Targets::is_supported( $target ) ? 'supported' : 'ended',
				'until'       => (string) Targets::security_until( $target ),
				'recommended' => $target === $default ? 'yes' : '',
			);
		}
		WP_CLI::log( sprintf( 'This server runs PHP %s.', PHP_VERSION ) );
		Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $rows, array( 'php', 'security', 'until', 'recommended' ) );
	}

	// -----------------------------------------------------------------------------------------

	/**
	 * Runs a queued or re-opened scan in this process until it's finished, with a progress bar.
	 *
	 * @param int $scan_id Scan ID.
	 */
	private function run_to_completion( $scan_id ) {
		global $wpdb;
		$t     = Installer::tables();
		$bar   = null;
		$shown = 0;
		$idle  = 0;
		while ( true ) {
			Queue::run_batch( $scan_id );   // Returns at once if a web request holds the lock.
			Fetcher::run( $scan_id );       // WordPress.org signals, alongside.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
			$scan = $wpdb->get_row( $wpdb->prepare( "SELECT status, files_done, files_total, wporg_status FROM {$t['scans']} WHERE id = %d", $scan_id ) );
			if ( ! $bar && $scan->files_total ) {
				$bar = Utils\make_progress_bar( 'Scanning files', (int) $scan->files_total );
			}
			if ( $bar && (int) $scan->files_done > $shown ) {
				$bar->tick( (int) $scan->files_done - $shown );
				$shown = (int) $scan->files_done;
				$idle  = 0;
			} else {
				++$idle;
			}
			if ( in_array( $scan->status, array( 'complete', 'cancelled', 'failed' ), true ) && 'pending' !== $scan->wporg_status ) {
				break;
			}
			if ( $idle > 0 ) {
				sleep( min( 5, $idle ) ); // Another runner holds the lock (or WordPress.org is slow): wait a little.
			}
			if ( $idle > 60 ) {
				WP_CLI::error( 'The scan stopped making progress. Check it with: wp airworthy status' );
			}
		}
		if ( $bar ) {
			$bar->finish();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$t['scans']} WHERE id = %d", $scan_id ) );
		if ( 'complete' !== $status ) {
			WP_CLI::warning( sprintf( 'Scan %1$d ended as "%2$s"; results are incomplete.', $scan_id, $status ) );
		}
	}

	/**
	 * Prints results: a table (or other format) plus a one-line summary, or just the summary.
	 *
	 * @param int   $scan_id    Scan ID.
	 * @param array $assoc_args Options (format, fields, verdict, only-ids).
	 */
	private function print_results( $scan_id, array $assoc_args ) {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
		$scan   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['scans']} WHERE id = %d", $scan_id ) );
		$rows   = $this->components( $scan_id );
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		if ( ! empty( $assoc_args['verdict'] ) ) {
			$wanted = array_map( 'trim', explode( ',', (string) $assoc_args['verdict'] ) );
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( $r ) use ( $wanted ) {
						return in_array( $r['verdict'], $wanted, true );
					}
				)
			);
		}
		if ( ! empty( $assoc_args['only-ids'] ) ) {
			$ids  = $assoc_args['only-ids'];
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $r ) use ( $ids ) {
						return in_array( $r['id'], $ids, true );
					}
				)
			);
		}

		$counts = array_fill_keys( Verdict::ALL, 0 );
		foreach ( $this->components( $scan_id ) as $r ) {
			if ( isset( $counts[ $r['verdict'] ] ) ) {
				++$counts[ $r['verdict'] ];
			}
		}
		$summary = sprintf( 'PHP %1$s (scan %2$d, %3$s): %4$d blocker, %5$d unknown, %6$d suppressed, %7$d guarded, %8$d warnings, %9$d ready.', $scan->target_php, $scan->id, $scan->status, $counts['blocker'], $counts['unknown'], $counts['suppressed'], $counts['guarded'], $counts['warnings'], $counts['ready'] );

		if ( 'summary' !== $format ) {
			$fields = ! empty( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', (string) $assoc_args['fields'] ) ) : self::FIELDS;
			Utils\format_items( $format, $rows, $fields );
		}
		if ( in_array( $format, array( 'table', 'summary' ), true ) ) {
			$closed = array_filter(
				$rows,
				static function ( $r ) {
					return '' !== $r['closed'];
				}
			);
			foreach ( $closed as $r ) {
				WP_CLI::warning( sprintf( '%1$s was removed from WordPress.org (%2$s). No more updates will come.', $r['name'], $r['closed'] ) );
			}
			WP_CLI::log( $summary );
			$existing = array_sum( wp_list_pluck( $this->components( $scan_id ), 'existing' ) );
			if ( $existing && ! empty( $scan->from_php ) ) {
				WP_CLI::log( sprintf( '%1$d problems already apply on PHP %2$s, so they are not caused by this upgrade (see the "existing" column).', $existing, $scan->from_php ) );
			}
			if ( in_array( $scan->wporg_status, array( 'offline', 'partial' ), true ) ) {
				WP_CLI::warning( 'WordPress.org could not be reached for some plugins; their maintenance details are missing.' );
			}
		}
	}

	/**
	 * Components of a scan as flat rows, most serious first.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array
	 */
	private function components( $scan_id ) {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['components']} WHERE scan_id = %d ORDER BY FIELD(verdict, 'blocker','unknown','suppressed','guarded','warnings','ready'), name", $scan_id ) );
		$out  = array();
		foreach ( $rows as $c ) {
			$w     = $c->wporg ? (array) json_decode( $c->wporg, true ) : array();
			$flags = array();
			if ( isset( $w['status'] ) && 'listed' !== $w['status'] ) {
				$flags[] = 'closed' === $w['status'] ? 'CLOSED' : str_replace( '_', ' ', $w['status'] );
			}
			if ( ! empty( $w['abandoned'] ) ) {
				$flags[] = 'abandoned';
			}
			if ( ! empty( $w['tested_warning'] ) ) {
				$flags[] = sprintf( 'tested %s', $w['tested'] );
			}
			if ( ! empty( $w['requires_php_blocker'] ) ) {
				$flags[] = sprintf( 'requires PHP %s', $w['requires_php'] );
			}
			if ( ! empty( $w['update'] ) ) {
				$flags[] = sprintf( 'update %s', $w['update'] );
			}
			$out[] = array(
				'id'           => (int) $c->id,
				'type'         => $c->type,
				'name'         => $c->name,
				'slug'         => $c->slug,
				'version'      => $c->version,
				'active'       => $c->is_active ? 'yes' : 'no',
				'verdict'      => (string) $c->verdict,
				'errors'       => (int) $c->errors,
				'suppressed'   => (int) $c->suppressed,
				'guarded'      => (int) $c->guarded,
				'existing'     => (int) $c->existing,
				'warnings'     => (int) $c->warnings,
				'files'        => sprintf( '%d/%d', (int) $c->files_done, (int) $c->files_total ),
				'wporg'        => $w ? ( $flags ? implode( ', ', $flags ) : 'ok' ) : '-',
				'last_updated' => isset( $w['last_updated'] ) ? $w['last_updated'] : '',
				'tested'       => isset( $w['tested'] ) ? $w['tested'] : '',
				'requires_php' => isset( $w['requires_php'] ) ? $w['requires_php'] : '',
				'update'       => isset( $w['update'] ) ? (string) $w['update'] : '',
				'closed'       => 'closed' === ( isset( $w['status'] ) ? $w['status'] : '' ) ? trim( $w['closed_date'] . ', ' . $w['closed_reason'], ', ' ) : '',
			);
		}
		return $out;
	}

	/**
	 * Scan ID from --scan, or the latest scan.
	 *
	 * @param array $assoc_args Options.
	 * @return int
	 */
	private function scan_id( array $assoc_args ) {
		global $wpdb;
		$t  = Installer::tables();
		$id = isset( $assoc_args['scan'] ) ? absint( $assoc_args['scan'] ) : (int) $wpdb->get_var( "SELECT MAX(id) FROM {$t['scans']}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
		if ( ! $id || ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['scans']} WHERE id = %d", $id ) ) ) {
			WP_CLI::error( 'No scan found. Run one with: wp airworthy scan' );
		}
		return $id;
	}

	/**
	 * Exits with code 1 when any component has the given verdict or a more serious one.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $fail_on Verdict threshold, or "none".
	 */
	private function exit_for( $scan_id, $fail_on ) {
		if ( 'none' === $fail_on ) {
			return;
		}
		$limit = array_search( $fail_on, Verdict::ALL, true );
		foreach ( $this->components( $scan_id ) as $r ) {
			$rank = array_search( $r['verdict'], Verdict::ALL, true );
			if ( false !== $rank && $rank <= $limit ) {
				WP_CLI::halt( 1 );
			}
		}
	}
}
