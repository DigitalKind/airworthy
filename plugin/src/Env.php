<?php
/**
 * Facts about the server.
 *
 * @package Airworthy
 */

namespace Airworthy;

defined( 'ABSPATH' ) || exit;

/**
 * One place to read the server's PHP version and how its PHP was built. Release builds always
 * report the real server; development builds (which include src/Dev.php) can preview another
 * version or build.
 */
final class Env {

	/**
	 * Vendors that ship PHP security fixes as updates keeping the old version number
	 * (backports). The ids php_build() returns; vendor_name() gives the names.
	 */
	const VENDORS = array( 'cloudlinux', 'cpanel', 'plesk', 'ubuntu', 'debian', 'redhat' );

	/**
	 * Build facts for this request (see php_build()).
	 *
	 * @var array|null
	 */
	private static $build = null;

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

	/**
	 * How the server's PHP was built: on which day, and by which backport vendor, if any. The
	 * day comes from PHP_BUILD_DATE (PHP 8.4+) or the "Build Date" line of a buffered phpinfo();
	 * the vendor from the binary's path, the php.ini path, the version string, phpinfo()'s
	 * "Build System" host and the kernel release. Nothing is output and nothing leaves the server.
	 *
	 * @return array{date:?string,vendor:?string} date: Y-m-d, null when unreadable (phpinfo()
	 *         disabled on PHP 8.3 or older); vendor: a VENDORS id, null when unknown (an
	 *         upstream-looking build).
	 */
	public static function php_build() {
		if ( null === self::$build ) {
			$info = '';
			if ( function_exists( 'phpinfo' ) ) {
				ob_start();
				phpinfo( INFO_GENERAL ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_phpinfo -- Buffered and parsed for the build date and build host; never output.
				$info = (string) ob_get_clean();
			}
			$build = self::build_from(
				array(
					'constant' => defined( 'PHP_BUILD_DATE' ) ? (string) constant( 'PHP_BUILD_DATE' ) : '',
					'info'     => $info,
					'version'  => PHP_VERSION,
					'paths'    => array( (string) PHP_BINARY, (string) php_ini_loaded_file(), (string) ini_get( 'extension_dir' ) ),
					'uname'    => function_exists( 'php_uname' ) ? php_uname( 'r' ) : '',
				)
			);
			if ( false !== strpos( AIRWORTHY_VERSION, '-dev' ) && class_exists( Dev::class ) ) {
				$build = Dev::php_build( $build );
			}
			self::$build = $build;
		}
		return self::$build;
	}

	/**
	 * Build facts from the raw strings (separate from php_build() for tests).
	 *
	 * @param array $raw constant: PHP_BUILD_DATE or ''; info: phpinfo( INFO_GENERAL ) output
	 *                   (text or HTML) or ''; version: PHP_VERSION; paths: the binary, php.ini
	 *                   and extension dir; uname: the kernel release.
	 * @return array{date:?string,vendor:?string}
	 */
	public static function build_from( array $raw ) {
		$raw  = array_merge(
			array(
				'constant' => '',
				'info'     => '',
				'version'  => '',
				'paths'    => array(),
				'uname'    => '',
			),
			$raw
		);
		$info = wp_strip_all_tags( (string) $raw['info'] );
		$date = self::build_date( (string) $raw['constant'] );
		if ( null === $date && preg_match( '/Build Date\s*(?:=>)?\s*([^\n]+)/', $info, $m ) ) {
			$date = self::build_date( $m[1] );
		}

		$paths   = strtolower( implode( "\n", array_map( 'strval', (array) $raw['paths'] ) ) );
		$version = strtolower( (string) $raw['version'] );
		$system  = preg_match( '/Build System\s*(?:=>)?\s*([^\n]+)/', $info, $m ) ? strtolower( $m[1] ) : '';
		$kernel  = strtolower( (string) $raw['uname'] );
		$vendor  = null;
		if ( false !== strpos( $paths, '/opt/alt/php' ) || false !== strpos( $system, 'cloudlinux' ) || false !== strpos( $kernel, '.lve.' ) ) {
			$vendor = 'cloudlinux';
		} elseif ( false !== strpos( $paths, '/opt/cpanel/ea-php' ) || false !== strpos( $system, 'cpanel' ) ) {
			$vendor = 'cpanel';
		} elseif ( false !== strpos( $paths, '/opt/plesk/php' ) ) {
			$vendor = 'plesk';
		} elseif ( false !== strpos( $version, 'ubuntu' ) ) {
			$vendor = 'ubuntu';
		} elseif ( preg_match( '#^(/etc/php/\d+\.\d+/|/usr/lib/php/\d{8}|/usr/sbin/php-fpm\d+\.\d+$|/usr/bin/php\d+\.\d+$)#m', $paths ) || preg_match( '/[~+]deb\d/', $version ) ) {
			$vendor = 'debian'; // Debian's layout, which Ubuntu shares (an Ubuntu build without the version suffix lands here).
		} elseif ( preg_match( '/\.el\d/', $kernel ) ) {
			$vendor = 'redhat'; // The kernel is the host's, so a container with an upstream PHP lands here too: a softer message, never a false red.
		}
		return array(
			'date'   => $date,
			'vendor' => $vendor,
		);
	}

	/**
	 * "Oct 21 2026 11:45:44" (the __DATE__ form PHP_BUILD_DATE and phpinfo() use) -> "2026-10-21".
	 *
	 * @param string $text Build date text.
	 * @return string|null Y-m-d, or null when it isn't one.
	 */
	public static function build_date( $text ) {
		if ( ! preg_match( '/\b([A-Z][a-z]{2}) +(\d{1,2}) (\d{4})\b/', (string) $text, $m ) ) {
			return null;
		}
		$time = strtotime( $m[1] . ' ' . $m[2] . ' ' . $m[3] . ' 12:00:00 UTC' );
		return $time ? gmdate( 'Y-m-d', $time ) : null;
	}

	/**
	 * A vendor's name for the screens.
	 *
	 * @param string $id A VENDORS id.
	 * @return string|null
	 */
	public static function vendor_name( $id ) {
		switch ( $id ) {
			case 'cloudlinux':
				return 'CloudLinux';
			case 'cpanel':
				return 'cPanel';
			case 'plesk':
				return 'Plesk';
			case 'ubuntu':
				return 'Ubuntu';
			case 'debian':
				return __( 'Debian or Ubuntu', 'airworthy' );
			case 'redhat':
				return __( 'Red Hat Enterprise Linux or a derivative', 'airworthy' );
		}
		return null;
	}
}
