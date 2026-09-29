<?php
/**
 * Updates waiting for scanned plugins and themes.
 *
 * @package Airworthy
 */

namespace Airworthy\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Reads WordPress's own update check (the update_plugins and update_themes site transients),
 * so no request leaves the site: it covers WordPress.org plugins and any paid plugin whose
 * updater hooks into WordPress's updates. An update often clears a plugin's findings, and
 * Airworthy rescans plugins and themes after WordPress updates them (Queue::after_upgrade).
 */
final class Updates {

	/**
	 * Updates waiting, keyed "type|slug".
	 *
	 * @return array<string,array{version:string,url:?string}> url: the one-click update link,
	 *         or null when the current user can't update.
	 */
	public static function all() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();

		$plugins = get_site_transient( 'update_plugins' );
		$can     = current_user_can( 'update_plugins' );
		foreach ( get_plugins() as $file => $data ) {
			if ( ! is_object( $plugins ) || empty( $plugins->response[ $file ] ) ) {
				continue;
			}
			$entry = (object) $plugins->response[ $file ];
			$new   = isset( $entry->new_version ) ? (string) $entry->new_version : '';
			if ( '' === $new || ! version_compare( $new, (string) $data['Version'], '>' ) ) {
				continue;
			}
			$slug                     = false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file );
			$out[ 'plugin|' . $slug ] = array(
				'version' => $new,
				'url'     => $can ? wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ), 'upgrade-plugin_' . $file ) : null,
			);
		}

		$themes = get_site_transient( 'update_themes' );
		$can    = current_user_can( 'update_themes' );
		if ( is_object( $themes ) && ! empty( $themes->response ) ) {
			foreach ( (array) $themes->response as $stylesheet => $update ) {
				$new   = isset( $update['new_version'] ) ? (string) $update['new_version'] : '';
				$theme = wp_get_theme( $stylesheet );
				if ( '' === $new || ! $theme->exists() || ! version_compare( $new, (string) $theme->get( 'Version' ), '>' ) ) {
					continue;
				}
				$out[ 'theme|' . $stylesheet ] = array(
					'version' => $new,
					'url'     => $can ? wp_nonce_url( self_admin_url( 'update.php?action=upgrade-theme&theme=' . rawurlencode( $stylesheet ) ), 'upgrade-theme_' . $stylesheet ) : null,
				);
			}
		}
		return $out;
	}
}
