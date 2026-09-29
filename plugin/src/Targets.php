<?php
/**
 * Supported target PHP versions.
 *
 * @package Airworthy
 */

namespace Airworthy;

defined( 'ABSPATH' ) || exit;

/**
 * The PHP versions a scan can check against. Scans always use one exact version, never a range.
 */
final class Targets {

	/** Oldest first. */
	const ALL = array( '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' );

	/**
	 * Last day each PHP version gets security fixes (https://www.php.net/supported-versions.php
	 * and https://www.php.net/eol.php). Update this table with each Airworthy release, and
	 * add new PHP versions here and to ALL.
	 */
	const SECURITY_UNTIL = array(
		'7.0' => '2019-01-10', // 7.x: only for the countdown on sites still running them.
		'7.1' => '2019-12-01',
		'7.2' => '2020-11-30',
		'7.3' => '2021-12-06',
		'7.4' => '2022-11-28',
		'8.0' => '2023-11-26',
		'8.1' => '2025-12-31',
		'8.2' => '2026-12-31',
		'8.3' => '2027-12-31',
		'8.4' => '2028-12-31',
		'8.5' => '2029-12-31',
	);

	/** When SECURITY_UNTIL was last checked against php.net. */
	const TABLE_CHECKED = '2026-09-29';

	/**
	 * Whether a target is supported.
	 *
	 * @param string $target e.g. "8.4".
	 * @return bool
	 */
	/**
	 * Whether a PHP version is usable as the version a scan compares from ("5.6"–"9.9").
	 *
	 * @param string $version e.g. "8.3".
	 * @return bool
	 */
	public static function is_valid_from( $version ) {
		return (bool) preg_match( '/^[5-9]\.\d$/', (string) $version );
	}

	/**
	 * Whether a target is a supported check version ("8.0"–"8.5").
	 *
	 * @param string $target e.g. "8.4".
	 * @return bool
	 */
	public static function is_valid( $target ) {
		return in_array( $target, self::ALL, true );
	}

	/**
	 * The default target: the oldest PHP version that still gets security fixes, so upgrading
	 * to it buys the most time with the least change. Never the server's own version or
	 * older (that would be a downgrade): then the next supported version above it. When the
	 * table is out of date (nothing supported any more), simply the next version up.
	 *
	 * @param string   $current PHP version string, e.g. "7.4.33".
	 * @param int|null $today   Timestamp (for tests); default now.
	 * @return string
	 */
	public static function default_for( $current, $today = null ) {
		$current_minor = self::minor( $current );
		foreach ( self::ALL as $target ) {
			if ( version_compare( $target, $current_minor, '>' ) && self::is_supported( $target, $today ) ) {
				return $target;
			}
		}
		foreach ( self::ALL as $target ) {
			if ( version_compare( $target, $current_minor, '>' ) ) {
				return $target;
			}
		}
		return self::ALL[ count( self::ALL ) - 1 ];
	}

	/**
	 * Whether a PHP version still gets security fixes. Versions older than the table have
	 * long since ended; ones newer than it are assumed supported.
	 *
	 * @param string   $version e.g. "8.2" or "7.4.33".
	 * @param int|null $today   Timestamp; default now.
	 * @return bool
	 */
	public static function is_supported( $version, $today = null ) {
		$until = self::security_until( $version );
		if ( null === $until ) {
			return version_compare( self::minor( $version ), self::ALL[0], '>' );
		}
		$today = null === $today ? time() : $today;
		return gmdate( 'Y-m-d', $today ) <= $until;
	}

	/**
	 * Last day of security fixes for a version, or null when it's not in the table.
	 *
	 * @param string $version e.g. "8.2" or "8.2.12".
	 * @return string|null Y-m-d.
	 */
	public static function security_until( $version ) {
		$minor = self::minor( $version );
		return isset( self::SECURITY_UNTIL[ $minor ] ) ? self::SECURITY_UNTIL[ $minor ] : null;
	}

	/**
	 * How long a PHP version keeps getting security fixes, for the countdown on the start
	 * and results screens.
	 *
	 * @param string   $version e.g. "8.3.12".
	 * @param int|null $today   Timestamp (for tests); default now.
	 * @return array{level:string,until:?string,months:?int,text:string}|null level: ended |
	 *         soon (six months or less) | ok. Null when the version isn't in the table.
	 */
	public static function support_status( $version, $today = null ) {
		$until = self::security_until( $version );
		if ( null === $until ) {
			return null;
		}
		$today  = null === $today ? time() : $today;
		$minor  = self::minor( $version );
		$date   = date_i18n( get_option( 'date_format' ), strtotime( $until . ' 12:00:00 UTC' ) );
		$diff   = ( new \DateTimeImmutable( gmdate( 'Y-m-d', $today ) ) )->diff( new \DateTimeImmutable( $until ) );
		$months = $diff->invert ? 0 : $diff->y * 12 + $diff->m;
		if ( $diff->invert ) {
			return array(
				'level'  => 'ended',
				'until'  => $until,
				'months' => 0,
				/* translators: 1: PHP version, 2: date. */
				'text'   => sprintf( __( 'Security fixes for PHP %1$s ended on %2$s. Ask your host to move your site to a newer version.', 'airworthy' ), $minor, $date ),
			);
		}
		if ( $months < 1 ) {
			/* translators: 1: PHP version, 2: date. */
			$text = sprintf( __( 'Security fixes for PHP %1$s end on %2$s, in less than a month.', 'airworthy' ), $minor, $date );
		} else {
			/* translators: 1: PHP version, 2: date, 3: number of months. */
			$text = sprintf( _n( 'Security fixes for PHP %1$s continue until %2$s: %3$d month left.', 'Security fixes for PHP %1$s continue until %2$s: %3$d months left.', $months, 'airworthy' ), $minor, $date, $months );
		}
		return array(
			'level'  => $months <= 6 ? 'soon' : 'ok',
			'until'  => $until,
			'months' => $months,
			'text'   => $text,
		);
	}

	/**
	 * "8.1.27" -> "8.1".
	 *
	 * @param string $version Version.
	 * @return string
	 */
	public static function minor( $version ) {
		return implode( '.', array_slice( explode( '.', (string) $version ), 0, 2 ) );
	}
}
