<?php
/**
 * Site Health check.
 *
 * @package Airworthy
 */

namespace Airworthy\Admin;

use Airworthy\Capabilities;
use Airworthy\Installer;
use Airworthy\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "PHP upgrade readiness" to Tools > Site Health, where site owners already look before
 * a PHP upgrade. It reads the latest finished scan (one query, no scanning):
 * - no scan, or the site has since moved to that PHP version: recommend a check (passed when
 *   the site already runs the newest version Airworthy knows);
 * - Blockers: recommend fixing them before the upgrade;
 * - plugins that couldn't be fully checked: recommend a look;
 * - otherwise: passed, with the date of the check.
 */
final class SiteHealth {

	/** Test key in the site_status_tests filter. */
	const TEST = 'airworthy_php_upgrade';

	/**
	 * Hooks the test.
	 */
	public static function register() {
		add_filter( 'site_status_tests', array( __CLASS__, 'add_test' ) );
	}

	/**
	 * Adds the test to Site Health.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public static function add_test( $tests ) {
		$tests['direct'][ self::TEST ] = array(
			'label' => __( 'PHP upgrade readiness', 'airworthy' ),
			'test'  => array( __CLASS__, 'run' ),
		);
		return $tests;
	}

	/**
	 * The test result.
	 *
	 * @param string|null $php PHP version the site runs (for tests); default the server's.
	 * @return array Site Health result.
	 */
	public static function run( $php = null ) {
		$php     = null === $php ? \Airworthy\Env::php_version() : $php;
		$current = Targets::minor( $php );
		$scan    = self::latest_scan();
		if ( $scan && version_compare( $scan->target_php, $current, '<=' ) ) {
			$scan = null; // The site already runs that version: the check is out of date.
		}

		$target = Targets::default_for( $php );
		if ( ! $scan && version_compare( $target, $current, '<=' ) ) {
			return self::result(
				'good',
				/* translators: %s: PHP version. */
				sprintf( __( 'Your site runs PHP %s, the newest version Airworthy checks', 'airworthy' ), $current ),
				__( 'There is no newer PHP version to prepare for yet. Airworthy will check against the next one once it is released.', 'airworthy' ),
				__( 'Open Airworthy', 'airworthy' )
			);
		}

		if ( ! $scan ) {
			return self::result(
				'recommended',
				__( 'Check your plugins and themes before your next PHP upgrade', 'airworthy' ),
				sprintf(
					/* translators: 1: PHP version the site runs, 2: suggested PHP version. */
					__( 'Your site runs PHP %1$s. Before you or your host move it to PHP %2$s, Airworthy can check every plugin and theme and tell you which ones would break. It only reads the files; nothing on your site changes.', 'airworthy' ),
					$current,
					$target
				),
				/* translators: %s: PHP version. */
				sprintf( __( 'Check for PHP %s', 'airworthy' ), $target )
			);
		}

		$checked = mysql2date( get_option( 'date_format' ), get_date_from_gmt( $scan->finished_at ) );
		$blocker = (int) $scan->count_blocker;
		$unknown = (int) $scan->count_unknown;
		if ( $blocker ) {
			return self::result(
				'recommended',
				sprintf(
					/* translators: 1: number of plugins/themes, 2: PHP version. */
					_n( '%1$d plugin or theme will not work on PHP %2$s', '%1$d plugins or themes will not work on PHP %2$s', $blocker, 'airworthy' ),
					$blocker,
					$scan->target_php
				),
				sprintf(
					/* translators: 1: date, 2: PHP version. */
					__( 'Airworthy checked your site on %1$s. Fix, update or replace these before you upgrade to PHP %2$s, or parts of your site may stop working.', 'airworthy' ),
					$checked,
					$scan->target_php
				),
				__( 'See what needs attention', 'airworthy' )
			);
		}
		if ( $unknown ) {
			return self::result(
				'recommended',
				sprintf(
					/* translators: 1: number of plugins/themes, 2: PHP version. */
					_n( '%1$d plugin or theme could not be fully checked for PHP %2$s', '%1$d plugins or themes could not be fully checked for PHP %2$s', $unknown, 'airworthy' ),
					$unknown,
					$scan->target_php
				),
				sprintf(
					/* translators: %s: date. */
					__( 'Airworthy checked your site on %s, but some files could not be read. The results list which ones, so you can check them another way.', 'airworthy' ),
					$checked
				),
				__( 'See the results', 'airworthy' )
			);
		}
		return self::result(
			'good',
			/* translators: %s: PHP version. */
			sprintf( __( 'Your plugins and themes are ready for PHP %s', 'airworthy' ), $scan->target_php ),
			sprintf(
				/* translators: 1: date, 2: PHP version. */
				__( 'Airworthy checked your site on %1$s and found nothing that would break on PHP %2$s. Back up your site and try the new version on a staging copy before you switch.', 'airworthy' ),
				$checked,
				$scan->target_php
			),
			__( 'See the results', 'airworthy' )
		);
	}

	/**
	 * The latest finished scan.
	 *
	 * @return object|null
	 */
	private static function latest_scan() {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table, no user input.
		$scan = $wpdb->get_row( "SELECT target_php, count_blocker, count_unknown, finished_at FROM {$t['scans']} WHERE status = 'complete' AND finished_at IS NOT NULL ORDER BY id DESC LIMIT 1" );
		return $scan ? $scan : null;
	}

	/**
	 * A Site Health result array.
	 *
	 * @param string $status      good | recommended.
	 * @param string $label       Heading.
	 * @param string $description One paragraph (plain text).
	 * @param string $link_text   Link to the Airworthy screen (shown to users who can scan).
	 * @return array
	 */
	private static function result( $status, $label, $description, $link_text ) {
		$actions = '';
		if ( Capabilities::current_user_can_scan() ) {
			$actions = sprintf( '<p><a href="%1$s">%2$s</a></p>', esc_url( Page::url() ), esc_html( $link_text ) );
		}
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Compatibility', 'airworthy' ),
				'color' => 'good' === $status ? 'blue' : 'orange',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => self::TEST,
		);
	}
}
