<?php
/**
 * The site's choice about WordPress.org lookups.
 *
 * @package Airworthy
 */

namespace Airworthy\Wporg;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress.org lookups send each plugin's folder name to api.wordpress.org, so they only run
 * with the administrator's explicit consent (WordPress.org plugin guideline 7). The admin
 * screen asks before the first scan and remembers the answer; it can be changed at any scan.
 * Until someone answers, no lookups are made.
 */
final class Consent {

	/** Site option: 'yes', 'no', or absent (never asked). */
	const OPTION = 'airworthy_wporg_consent';

	/**
	 * The saved answer.
	 *
	 * @return string 'yes', 'no', or '' when nobody has answered yet.
	 */
	public static function get() {
		$value = get_site_option( self::OPTION, '' );
		return in_array( $value, array( 'yes', 'no' ), true ) ? $value : '';
	}

	/**
	 * Whether lookups are allowed.
	 *
	 * @return bool
	 */
	public static function allowed() {
		return 'yes' === self::get();
	}

	/**
	 * Saves an answer.
	 *
	 * @param bool $allowed Whether lookups are allowed.
	 */
	public static function set( $allowed ) {
		update_site_option( self::OPTION, $allowed ? 'yes' : 'no' );
	}
}
