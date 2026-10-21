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
	const TABLE_CHECKED = '2026-10-21';

	/**
	 * The latest PHP security release Airworthy knows about (from php.net's release notes),
	 * so the start screen, results and Site Health can say when the server's PHP is missing
	 * it. Bundled, not fetched: no outside connection. Update it whenever php.net publishes a
	 * security release (see docs/RELEASE.md).
	 * - date:         release day, Y-m-d ('' = none known).
	 * - critical:     whether php.net rated any of its fixes critical.
	 * - fixed:        minor version => first patch release with the fixes, e.g. '8.3' => '8.3.36'.
	 * - eol_affected: whether php.net says versions that no longer get security fixes are
	 *                 affected too (no release from the PHP project; vendors may patch).
	 */
	const SECURITY_RELEASE = array(
		'date'         => '2026-10-21', // CVE-2026-109618 and CVE-2026-109619, unauthenticated remote code execution.
		'critical'     => true,
		'fixed'        => array(
			'8.2' => '8.2.35',
			'8.3' => '8.3.36',
			'8.4' => '8.4.27',
			'8.5' => '8.5.12',
		),
		'eol_affected' => true, // php.net: 8.1 and older get no update from the PHP project; only source backports and vendor patches.
	);

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
	 * Whether the server's PHP is missing the latest security release Airworthy knows about.
	 *
	 * @param string     $version e.g. "8.3.30".
	 * @param array|null $release Release data (for tests); default SECURITY_RELEASE.
	 * @return array{level:string,fixed_in:?string,date:string,critical:bool,text:string}|null
	 *         level: missing (a supported version without the fixes) | unpatched (a version
	 *         that no longer gets fixes and is affected). Null when nothing is missing or
	 *         nothing is known.
	 */
	public static function missing_patch( $version, $release = null ) {
		$release = is_array( $release ) ? $release : self::SECURITY_RELEASE;
		if ( empty( $release['date'] ) || ! preg_match( '/^\d+\.\d+\.\d+/', (string) $version, $m ) ) {
			return null;
		}
		$exact    = $m[0];
		$minor    = self::minor( $exact );
		$date     = date_i18n( get_option( 'date_format' ), strtotime( $release['date'] . ' 12:00:00 UTC' ) );
		$critical = ! empty( $release['critical'] );
		$fixed    = isset( $release['fixed'][ $minor ] ) ? (string) $release['fixed'][ $minor ] : null;

		if ( null !== $fixed ) {
			if ( version_compare( $exact, $fixed, '>=' ) ) {
				return null;
			}
			$text = $critical ?
				/* translators: 1: date, 2: fixed PHP version, 3: server's PHP version. */
				__( 'Critical PHP security fixes came out on %1$s, in PHP %2$s. This server runs PHP %3$s, without them. Ask your host to update PHP now: an update within the same version is routine and doesn\'t change how your plugins work.', 'airworthy' ) :
				/* translators: 1: date, 2: fixed PHP version, 3: server's PHP version. */
				__( 'PHP security fixes came out on %1$s, in PHP %2$s. This server runs PHP %3$s, without them. Ask your host to update PHP: an update within the same version is routine and doesn\'t change how your plugins work.', 'airworthy' );
			return array(
				'level'    => 'missing',
				'fixed_in' => $fixed,
				'date'     => $release['date'],
				'critical' => $critical,
				'text'     => sprintf( $text, $date, $fixed, $exact ),
			);
		}

		// No fix for this version: only worth saying when it no longer gets fixes and is affected.
		$until = self::security_until( $minor );
		if ( empty( $release['eol_affected'] ) || null === $until || $until >= $release['date'] ) {
			return null;
		}
		$text = $critical ?
			/* translators: 1: PHP version, 2: date, 3: suggested PHP version. */
			__( 'PHP %1$s no longer gets security fixes from the PHP project, so there was no PHP %1$s release with the critical fixes of %2$s. Some hosts patch old versions themselves: ask yours whether this server has them, and plan to move your site to PHP %3$s or newer, after checking your plugins and themes for it here.', 'airworthy' ) :
			/* translators: 1: PHP version, 2: date, 3: suggested PHP version. */
			__( 'PHP %1$s no longer gets security fixes from the PHP project, so there was no PHP %1$s release with the fixes of %2$s. Some hosts patch old versions themselves: ask yours whether this server has them, and plan to move your site to PHP %3$s or newer, after checking your plugins and themes for it here.', 'airworthy' );
		return array(
			'level'    => 'unpatched',
			'fixed_in' => null,
			'date'     => $release['date'],
			'critical' => $critical,
			'text'     => sprintf( $text, $minor, $date, self::default_for( $exact ) ),
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
