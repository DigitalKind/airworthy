<?php
/**
 * Development-only helpers. This file is NOT in release builds (bin/build.sh leaves it out
 * unless run with --dev), so nothing here exists on real sites.
 *
 * @package Airworthy
 */

namespace Airworthy;

defined( 'ABSPATH' ) || exit;

/**
 * Previews and test hooks used to take screenshots and run the local test site.
 */
final class Dev {

	/**
	 * The server PHP version to show and record, overridable in development builds only (for
	 * screenshots of how an older server sees the screen).
	 *
	 * @return string
	 */
	public static function server_php_version() {
		return (string) apply_filters( 'airworthy_dev_server_php_version', PHP_VERSION );
	}

	/**
	 * The server's PHP build facts (date, vendor), overridable in development builds only (to
	 * preview how a server on a backport vendor sees the security-fixes check).
	 *
	 * @param array $build The real facts, from Env::php_build().
	 * @return array
	 */
	public static function php_build( $build ) {
		return (array) apply_filters( 'airworthy_dev_php_build', $build );
	}
}
