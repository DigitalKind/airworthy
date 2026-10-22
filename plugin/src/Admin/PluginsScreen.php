<?php
/**
 * Verdict badges on the Plugins screen.
 *
 * @package Airworthy
 */

namespace Airworthy\Admin;

use Airworthy\Capabilities;
use Airworthy\Installer;
use Airworthy\Settings;
use Airworthy\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a small badge to each plugin's row on the Plugins screen ("PHP 8.4: Ready"), from the
 * latest finished scan, linking to that plugin's details in the results. One query per page
 * load, only for users who can scan, and nothing when the scan no longer applies (the server
 * already runs the PHP version it checked).
 */
final class PluginsScreen {

	/**
	 * The latest finished scan's plugin verdicts, loaded once per request.
	 *
	 * @var array{target:string,plugins:array<string,array{verdict:string,version:string}>}|false|null
	 */
	private static $latest = null;

	/**
	 * Hooks the badges.
	 */
	public static function register() {
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 3 );
		add_action( 'admin_head-plugins.php', array( __CLASS__, 'styles' ) );
	}

	/**
	 * Adds the badge to a plugin's row.
	 *
	 * @param string[] $meta        Row meta links.
	 * @param string   $plugin_file Plugin file, relative to the plugins folder.
	 * @param array    $plugin_data Plugin header data.
	 * @return string[]
	 */
	public static function row_meta( $meta, $plugin_file, $plugin_data = array() ) {
		$badge = self::badge( (string) $plugin_file, is_array( $plugin_data ) ? $plugin_data : array() );
		if ( '' !== $badge ) {
			$meta[] = $badge;
		}
		return $meta;
	}

	/**
	 * The badge for one plugin, or '' when there's nothing to show.
	 *
	 * @param string $plugin_file Plugin file, relative to the plugins folder.
	 * @param array  $plugin_data Plugin header data (Version).
	 * @return string HTML.
	 */
	public static function badge( $plugin_file, array $plugin_data = array() ) {
		if ( ! Capabilities::current_user_can_scan() ) {
			return '';
		}
		$latest = self::latest();
		if ( ! $latest ) {
			return '';
		}
		$slug   = '.' === dirname( $plugin_file ) ? basename( $plugin_file, '.php' ) : dirname( $plugin_file );
		$target = $latest['target'];
		$url    = Page::url() . '#plugin:' . rawurlencode( $slug );

		if ( ! isset( $latest['plugins'][ $slug ] ) ) {
			// Not in the scan: installed since, left out by the settings, or a must-use plugin.
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$active = is_plugin_active( $plugin_file ) || ( is_multisite() && is_plugin_active_for_network( $plugin_file ) );
			if ( ! $active || in_array( 'plugin:' . $slug, (array) Settings::get( 'ignore' ), true ) ) {
				return '';
			}
			return self::html(
				'pending',
				/* translators: %s: PHP version. */
				sprintf( __( 'PHP %s: not checked yet', 'airworthy' ), $target ),
				__( 'Airworthy hasn\'t checked this plugin yet: it was added after the last scan. Run a new scan to check it.', 'airworthy' ),
				Page::url()
			);
		}

		$row     = $latest['plugins'][ $slug ];
		$version = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';
		if ( '' !== $version && '' !== $row['version'] && $version !== $row['version'] ) {
			return self::html(
				'pending',
				/* translators: %s: PHP version. */
				sprintf( __( 'PHP %s: not checked since the update', 'airworthy' ), $target ),
				/* translators: %s: version number. */
				sprintf( __( 'Airworthy checked version %s. It checks updated plugins again by itself; open Airworthy to see the latest results.', 'airworthy' ), $row['version'] ),
				$url
			);
		}

		$labels = self::labels();
		if ( ! isset( $labels[ $row['verdict'] ] ) ) {
			return ''; // No verdict yet (a stopped scan).
		}
		list( $label, $explain ) = $labels[ $row['verdict'] ];
		return self::html(
			$row['verdict'],
			/* translators: 1: PHP version, 2: verdict, e.g. "Ready". */
			sprintf( __( 'PHP %1$s: %2$s', 'airworthy' ), $target, $label ),
			sprintf( $explain, $target ),
			$url
		);
	}

	/**
	 * Badge markup.
	 *
	 * @param string $kind    Verdict, or "pending".
	 * @param string $text    Badge text.
	 * @param string $title   Longer explanation (tooltip and screen readers).
	 * @param string $url     Link.
	 * @return string
	 */
	private static function html( $kind, $text, $title, $url ) {
		return sprintf(
			'<a class="airworthy-plugin-badge airworthy-plugin-badge-%1$s" href="%2$s" title="%3$s"><span class="screen-reader-text">%4$s </span>%5$s</a>',
			esc_attr( $kind ),
			esc_url( $url ),
			esc_attr( $title ),
			esc_html__( 'Airworthy:', 'airworthy' ),
			esc_html( $text )
		);
	}

	/**
	 * Short verdict labels and their one-line meaning (%s: PHP version).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function labels() {
		return array(
			'blocker'    => array(
				__( 'Blocker', 'airworthy' ),
				/* translators: %s: PHP version. */
				__( 'Some of this plugin\'s code will not work on PHP %s. See what and why in Airworthy.', 'airworthy' ),
			),
			'unknown'    => array(
				__( 'Unknown', 'airworthy' ),
				/* translators: %s: PHP version. */
				__( 'Some of this plugin\'s files could not be checked for PHP %s.', 'airworthy' ),
			),
			'suppressed' => array(
				__( 'Suppressed by author', 'airworthy' ),
				/* translators: %s: PHP version. */
				__( 'Its authors marked some code as intentional. Usually safe on PHP %s, but not verified.', 'airworthy' ),
			),
			'guarded'    => array(
				__( 'Guarded legacy code', 'airworthy' ),
				/* translators: %s: PHP version. */
				__( 'Old code that never runs on PHP %s. Safe.', 'airworthy' ),
			),
			'warnings'   => array(
				__( 'Warnings', 'airworthy' ),
				/* translators: %s: PHP version. */
				__( 'Works on PHP %s. Some code will need updating before a later PHP version.', 'airworthy' ),
			),
			'ready'      => array(
				__( 'Ready', 'airworthy' ),
				/* translators: %s: PHP version. */
				__( 'No problems found for PHP %s.', 'airworthy' ),
			),
		);
	}

	/**
	 * The latest finished scan's plugin verdicts, or false when there's none that applies.
	 *
	 * @return array{target:string,plugins:array<string,array{verdict:string,version:string}>}|false
	 */
	private static function latest() {
		if ( null !== self::$latest ) {
			return self::$latest;
		}
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own tables, one query per page load.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.target_php, c.slug, c.verdict, c.version FROM %i s JOIN %i c ON c.scan_id = s.id WHERE s.id = ( SELECT MAX(id) FROM %i WHERE status = 'complete' ) AND c.type = 'plugin'", $t['scans'], $t['components'], $t['scans'] ) );
		if ( ! $rows || version_compare( $rows[0]->target_php, Targets::minor( \Airworthy\Env::php_version() ), '<=' ) ) {
			self::$latest = false; // No scan, or the server already runs the version it checked.
			return false;
		}
		$plugins = array();
		foreach ( $rows as $r ) {
			$plugins[ $r->slug ] = array(
				'verdict' => (string) $r->verdict,
				'version' => (string) $r->version,
			);
		}
		self::$latest = array(
			'target'  => (string) $rows[0]->target_php,
			'plugins' => $plugins,
		);
		return self::$latest;
	}

	/**
	 * Forgets the cached scan (tests, and after a scan finishes in the same request).
	 */
	public static function reset() {
		self::$latest = null;
	}

	/**
	 * Badge styles, on the Plugins screen only.
	 */
	public static function styles() {
		if ( ! Capabilities::current_user_can_scan() ) {
			return;
		}
		?>
		<style id="airworthy-plugin-badges">
			.airworthy-plugin-badge { display: inline-block; padding: 0 8px; border-radius: 9px; font-size: 12px; line-height: 18px; text-decoration: none; white-space: nowrap; box-shadow: inset 0 0 0 1px currentColor; }
			.airworthy-plugin-badge:focus { box-shadow: inset 0 0 0 1px currentColor, 0 0 0 2px #2271b1; outline: none; }
			.airworthy-plugin-badge-blocker { color: #8a2424; background: #fcf0f1; }
			.airworthy-plugin-badge-unknown, .airworthy-plugin-badge-pending { color: #50575e; background: #f6f7f7; }
			.airworthy-plugin-badge-suppressed, .airworthy-plugin-badge-warnings { color: #6e4f00; background: #fcf9e8; }
			.airworthy-plugin-badge-guarded, .airworthy-plugin-badge-ready { color: #00692a; background: #edfaef; }
		</style>
		<?php
	}
}
