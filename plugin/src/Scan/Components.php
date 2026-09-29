<?php
/**
 * Finds what a scan covers.
 *
 * @package Airworthy
 */

namespace Airworthy\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Every installed plugin (active and inactive) plus the active theme, and its parent theme
 * when the active theme is a child theme (the parent's code runs too).
 */
final class Components {

	/**
	 * What the site's settings left out of the last discover() call, for the scan's record.
	 *
	 * @var array{inactive:int,ignored:int}
	 */
	public static $last_skipped = array(
		'inactive' => 0,
		'ignored'  => 0,
	);

	/**
	 * When true, discover() ignores the site's settings (WP-CLI's --only, rescans).
	 *
	 * @var bool
	 */
	public static $bypass_settings = false;

	/**
	 * Lists components to scan, applying the site's settings (skip inactive plugins, ignore
	 * list) unless $apply_settings is false or $bypass_settings is set.
	 *
	 * @param bool $apply_settings Whether to apply the settings.
	 * @return array<int,array{type:string,slug:string,name:string,version:string,rel_path:string,is_active:bool}>
	 */
	public static function discover( $apply_settings = true ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			// "folder/main.php" plugins are scanned as a folder; "hello.php" as a single file.
			$is_single = false === strpos( $file, '/' );
			$out[]     = array(
				'type'      => 'plugin',
				'slug'      => $is_single ? basename( $file, '.php' ) : dirname( $file ),
				'name'      => (string) $data['Name'],
				'version'   => (string) $data['Version'],
				'rel_path'  => $is_single ? $file : dirname( $file ),
				'is_active' => self::is_active_anywhere( $file ),
			);
		}

		// The theme in use and its parent. On multisite, every network-enabled theme too (and
		// their parents), since each site may use a different one. Inactive themes on a single
		// site never run, so they're left out.
		$active = wp_get_theme();
		$themes = array( $active );
		if ( is_multisite() ) {
			foreach ( array_keys( \WP_Theme::get_allowed_on_network() ) as $stylesheet ) {
				$themes[] = wp_get_theme( $stylesheet );
			}
		}
		$seen = array();
		foreach ( $themes as $theme ) {
			foreach ( array( $theme, $theme->parent() ) as $t ) {
				if ( ! $t || ! $t->exists() || isset( $seen[ $t->get_stylesheet() ] ) ) {
					continue;
				}
				$seen[ $t->get_stylesheet() ] = true;
				$in_use                       = $t->get_stylesheet() === $active->get_stylesheet() || $t->get_stylesheet() === $active->get_template();
				$out[]                        = self::theme_row( $t, $in_use || is_multisite() );
			}
		}

		self::$last_skipped = array(
			'inactive' => 0,
			'ignored'  => 0,
		);
		if ( $apply_settings && ! self::$bypass_settings ) {
			$settings = \Airworthy\Settings::all();
			$out      = array_filter(
				$out,
				static function ( $c ) use ( $settings ) {
					if ( in_array( $c['type'] . ':' . $c['slug'], $settings['ignore'], true ) ) {
						++self::$last_skipped['ignored'];
						return false;
					}
					if ( $settings['skip_inactive'] && 'plugin' === $c['type'] && ! $c['is_active'] ) {
						++self::$last_skipped['inactive'];
						return false;
					}
					return true;
				}
			);
		}

		/**
		 * Filters the plugins and themes a new scan covers.
		 *
		 * @param array $components Component rows (type, slug, name, version, rel_path, is_active).
		 */
		return array_values( (array) apply_filters( 'airworthy_scan_components', $out ) );
	}

	/**
	 * Whether a plugin is active: on this site, network-wide, or (multisite) on any site of
	 * the network, since the network admin sees every site's plugins.
	 *
	 * @param string $file Plugin file, e.g. "akismet/akismet.php".
	 * @return bool
	 */
	private static function is_active_anywhere( $file ) {
		if ( is_plugin_active( $file ) ) {
			return true;
		}
		if ( ! is_multisite() ) {
			return false;
		}
		if ( is_plugin_active_for_network( $file ) ) {
			return true;
		}
		static $active = null;
		if ( null === $active ) {
			$active = array();
			foreach ( get_sites(
				array(
					'number' => 1000,
					'fields' => 'ids',
				)
			) as $site_id ) {
				foreach ( (array) get_blog_option( $site_id, 'active_plugins', array() ) as $plugin ) {
					$active[ $plugin ] = true;
				}
			}
		}
		return isset( $active[ $file ] );
	}

	/**
	 * Absolute path of a component's folder (or single file).
	 *
	 * @param string $type     "plugin" or "theme".
	 * @param string $rel_path Path relative to the plugins folder or the theme's root.
	 * @param string $slug     Theme slug (stylesheet), used to find its theme root.
	 * @return string
	 */
	public static function root( $type, $rel_path, $slug ) {
		if ( 'theme' === $type ) {
			return trailingslashit( get_theme_root( $slug ) ) . $rel_path;
		}
		return trailingslashit( WP_PLUGIN_DIR ) . $rel_path;
	}

	/**
	 * Builds a component row for a theme.
	 *
	 * @param \WP_Theme $theme  Theme.
	 * @param bool      $in_use Whether it's in use (on multisite: network-enabled).
	 * @return array
	 */
	private static function theme_row( \WP_Theme $theme, $in_use ) {
		return array(
			'type'      => 'theme',
			'slug'      => $theme->get_stylesheet(),
			'name'      => (string) $theme->get( 'Name' ),
			'version'   => (string) $theme->get( 'Version' ),
			'rel_path'  => $theme->get_stylesheet(),
			'is_active' => (bool) $in_use,
		);
	}
}
