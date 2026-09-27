<?php
/**
 * Who may use the scanner.
 *
 * @package Airworthy
 */

namespace Airworthy;

defined( 'ABSPATH' ) || exit;

/**
 * One place for the permission rule, used by the admin screen, REST endpoints and jobs.
 *
 * Single site: administrators (manage_options).
 * Multisite: network admins only (manage_network_options), since plugins are shared network-wide.
 */
final class Capabilities {

	/**
	 * The capability required to see the screen, start scans or read results.
	 *
	 * @return string
	 */
	public static function required() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Whether the current user may use the scanner.
	 *
	 * @return bool
	 */
	public static function current_user_can_scan() {
		return current_user_can( self::required() );
	}
}
