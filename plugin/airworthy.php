<?php
/**
 * Plugin Name:       Airworthy
 * Plugin URI:        https://airworthywp.com
 * Description:       Before you upgrade PHP, see which plugins and themes are ready, which need fixing, and which look abandoned.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.2
 * Author:            digitalkind.ie
 * Author URI:        https://digitalkind.ie
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       airworthy
 * Domain Path:       /languages
 * Network:           true
 *
 * @package Airworthy
 */

// This file must stay parseable on very old PHP so the version check below can run.
// Keep modern syntax out of it; everything else lives in src/.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIRWORTHY_VERSION', '1.0.0' );
define( 'AIRWORTHY_FILE', __FILE__ );
define( 'AIRWORTHY_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIRWORTHY_URL', plugin_dir_url( __FILE__ ) );
define( 'AIRWORTHY_MIN_PHP', '7.2' );

if ( version_compare( PHP_VERSION, AIRWORTHY_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', 'airworthy_php_too_old_notice' );
	add_action( 'network_admin_notices', 'airworthy_php_too_old_notice' );

	/**
	 * Tells the admin the plugin needs a newer PHP version to run.
	 */
	function airworthy_php_too_old_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version. */
					__( 'Airworthy needs PHP %1$s or newer. This site is running PHP %2$s.', 'airworthy' ),
					AIRWORTHY_MIN_PHP,
					PHP_VERSION
				)
			)
		);
	}
	return;
}

require_once AIRWORTHY_DIR . 'src/autoload.php';

// Background jobs. Action Scheduler must load with the plugin (before plugins_loaded); when
// several plugins bundle it, the newest copy is the one that runs.
if ( is_readable( AIRWORTHY_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once AIRWORTHY_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

register_activation_hook( __FILE__, array( 'Airworthy\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Airworthy\\Installer', 'deactivate' ) );

Airworthy\Plugin::boot();
