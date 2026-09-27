<?php
/**
 * Facts about the server.
 *
 * @package Airworthy
 */

namespace Airworthy;

defined( 'ABSPATH' ) || exit;

/**
 * One place to read the server's PHP version. Release builds always report PHP_VERSION;
 * development builds (which include src/Dev.php) can preview another version.
 */
final class Env {

	/**
	 * The server's PHP version.
	 *
	 * @return string
	 */
	public static function php_version() {
		// Development builds only: a "-dev" version AND src/Dev.php (left out of release zips).
		if ( false !== strpos( AIRWORTHY_VERSION, '-dev' ) && class_exists( Dev::class ) ) {
			return Dev::server_php_version();
		}
		return PHP_VERSION;
	}
}
