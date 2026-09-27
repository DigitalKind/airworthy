<?php
/**
 * Turns WordPress.org data into the signals shown per plugin.
 *
 * @package Airworthy
 */

namespace Airworthy\Wporg;

defined( 'ABSPATH' ) || exit;

/**
 * The static scan says whether code will break; WordPress.org says whether anyone will fix it.
 *
 * Status per plugin:
 * - listed:      on WordPress.org and matched.
 * - closed:      removed from WordPress.org: no more updates will come. Shown prominently.
 * - not_listed:  premium or custom code (or a different plugin that happens to share the
 *                folder name). Never an error.
 * - unreachable: WordPress.org couldn't be reached; the scan finishes without signals.
 *
 * Signals for listed plugins: last updated (Abandoned after 2 years), tested up to (a warning
 * when 3+ WordPress releases behind), Requires PHP (a Blocker when above the target), and
 * update available.
 */
final class Signals {

	/** No update for this long counts as abandoned. */
	const ABANDONED_AFTER = 2 * YEAR_IN_SECONDS;

	/** Tested-up-to this many WordPress releases behind is a warning. */
	const TESTED_BEHIND_WARNING = 3;

	/** Words ignored when comparing an installed plugin's name with the WordPress.org one. */
	const NAME_STOP_WORDS = array( 'a', 'an', 'the', 'and', 'for', 'by', 'of', 'to', 'with', 'wordpress', 'wp', 'plugin', 'lite', 'free' );

	/**
	 * How WordPress core identifies an installed plugin, from its own update check (no extra
	 * request): the WordPress.org slug, or "external" when another service updates it.
	 *
	 * @param string $plugin_file Plugin file, e.g. "akismet/akismet.php".
	 * @param array  $headers     Plugin headers from get_plugins().
	 * @return array{source:string,slug?:string,new_version?:string} source: wporg | external | unknown.
	 */
	public static function identify( $plugin_file, array $headers ) {
		// A plugin that declares a non-WordPress.org Update URI is not a WordPress.org plugin.
		if ( ! empty( $headers['UpdateURI'] ) ) {
			$host = wp_parse_url( (string) $headers['UpdateURI'], PHP_URL_HOST );
			if ( ! in_array( $host, array( 'w.org', 'wordpress.org', 'downloads.wordpress.org' ), true ) ) {
				return array( 'source' => 'external' );
			}
		}
		$updates = get_site_transient( 'update_plugins' );
		foreach ( array( 'response', 'no_update' ) as $list ) {
			if ( is_object( $updates ) && isset( $updates->{$list}[ $plugin_file ] ) ) {
				$entry = (object) $updates->{$list}[ $plugin_file ];
				$id    = isset( $entry->id ) ? (string) $entry->id : '';
				if ( 0 === strpos( $id, 'w.org/plugins/' ) ) {
					$out = array(
						'source' => 'wporg',
						'slug'   => substr( $id, strlen( 'w.org/plugins/' ) ),
					);
					if ( 'response' === $list && ! empty( $entry->new_version ) ) {
						$out['new_version'] = (string) $entry->new_version;
					}
					return $out;
				}
				return array( 'source' => 'external' ); // A premium updater hooked into the update check.
			}
		}
		return array( 'source' => 'unknown' );
	}

	/**
	 * Builds the signals record for one plugin.
	 *
	 * @param array      $installed    name, version (installed).
	 * @param array|null $record       Client::lookup() result, or null if not looked up.
	 * @param array      $identity     identify() result.
	 * @param string     $target       Target PHP version.
	 * @param string     $latest_wp    Latest WordPress version, e.g. "7.1.2".
	 * @param int|null   $now          Timestamp (for tests).
	 * @return array
	 */
	public static function build( array $installed, $record, array $identity, $target, $latest_wp, $now = null ) {
		$now = null === $now ? time() : $now;

		if ( 'external' === $identity['source'] ) {
			return array(
				'status' => 'not_listed',
				'why'    => 'external', // Updated by another service: premium or custom.
			);
		}
		if ( ! is_array( $record ) || ( ! $record['listed'] && ! $record['closed'] ) ) {
			return array(
				'status' => 'not_listed',
				'why'    => 'not_found',
			);
		}
		// Only trust the match if the names match too. Even WordPress core's own update check
		// matches by folder name, so a custom plugin in a folder called "calendar" gets matched
		// to WordPress.org's unrelated "Calendar" plugin (the reason core added Update URI).
		if ( ! self::names_match( (string) $installed['name'], (string) $record['name'] ) ) {
			return array(
				'status' => 'not_listed',
				'why'    => 'name_mismatch', // A different plugin uses this folder name on WordPress.org.
			);
		}

		if ( $record['closed'] ) {
			return array(
				'status'        => 'closed',
				'wporg_name'    => $record['name'],
				'closed_date'   => $record['closed_date'],
				'closed_reason' => $record['closed_reason'],
				'permanent'     => ! empty( $record['permanent'] ),
			);
		}

		$out = array(
			'status'               => 'listed',
			'wporg_name'           => $record['name'],
			'latest'               => $record['version'],
			'last_updated'         => $record['last_updated'],
			'abandoned'            => '' !== $record['last_updated'] && ( $now - strtotime( $record['last_updated'] . ' 00:00:00 UTC' ) ) > self::ABANDONED_AFTER,
			'tested'               => $record['tested'],
			'tested_behind'        => null,
			'tested_warning'       => false,
			'requires_php'         => $record['requires_php'],
			'requires_php_blocker' => '' !== $record['requires_php'] && version_compare( self::minor( $record['requires_php'] ), $target, '>' ),
			'update'               => null,
		);
		if ( '' !== $record['tested'] ) {
			$behind                = self::releases_between( $record['tested'], $latest_wp );
			$out['tested_behind']  = $behind;
			$out['tested_warning'] = $behind >= self::TESTED_BEHIND_WARNING;
		}
		$newer = isset( $identity['new_version'] ) ? $identity['new_version'] : $record['version'];
		if ( '' !== (string) $newer && version_compare( (string) $installed['version'], (string) $newer, '<' ) ) {
			$out['update'] = (string) $newer;
		}
		return $out;
	}

	/**
	 * Whether an installed plugin's name refers to the same plugin as a WordPress.org name.
	 * WordPress.org names are often longer ("Elementor Website Builder – more than just a page
	 * builder" for "Elementor"), so this compares words rather than whole strings.
	 *
	 * @param string $installed Name from the plugin header.
	 * @param string $listed    Name on WordPress.org.
	 * @return bool
	 */
	public static function names_match( $installed, $listed ) {
		$a = self::name_words( $installed );
		$b = self::name_words( $listed );
		if ( ! $a || ! $b ) {
			return false;
		}
		$joined_a = implode( ' ', $a );
		$joined_b = implode( ' ', $b );
		if ( $joined_a === $joined_b || 0 === strpos( $joined_b, $joined_a . ' ' ) || 0 === strpos( $joined_a, $joined_b . ' ' ) ) {
			return true;
		}
		$short  = count( $a ) <= count( $b ) ? $a : $b;
		$long   = count( $a ) <= count( $b ) ? $b : $a;
		$shared = count( array_intersect( $short, $long ) );
		if ( count( $short ) === $shared ) {
			return true; // Every word of the shorter name appears in the longer one.
		}
		// Renamed plugins keep their leading words: "Ultimate Addons for Elementor (UAE)" vs
		// "Ultimate Addons for Elementor – Widgets, Templates, …". At least 2 leading words in
		// common, covering at least half of the shorter name.
		$lead = 0;
		while ( isset( $a[ $lead ], $b[ $lead ] ) && $a[ $lead ] === $b[ $lead ] ) {
			++$lead;
		}
		if ( $lead >= 2 && $lead * 2 >= count( $short ) ) {
			return true;
		}
		return $shared / count( array_unique( array_merge( $a, $b ) ) ) >= 0.5;
	}

	/**
	 * Normalised words of a plugin name.
	 *
	 * @param string $name Name.
	 * @return string[]
	 */
	private static function name_words( $name ) {
		$name  = strtolower( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) );
		$name  = str_replace( '&', ' and ', $name );
		$words = preg_split( '/[^a-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_diff( array_unique( $words ), self::NAME_STOP_WORDS ) );
	}

	/**
	 * Number of WordPress feature releases from $from to $to (6.9 -> 7.1 is 2).
	 *
	 * @param string $from Older version.
	 * @param string $to   Newer version.
	 * @return int
	 */
	private static function releases_between( $from, $to ) {
		$index = static function ( $v ) {
			$p = array_map( 'intval', explode( '.', $v ) );
			return $p[0] * 10 + ( isset( $p[1] ) ? $p[1] : 0 );
		};
		return max( 0, $index( $to ) - $index( $from ) );
	}

	/**
	 * "8.1.2" -> "8.1".
	 *
	 * @param string $version Version.
	 * @return string
	 */
	private static function minor( $version ) {
		$p = explode( '.', $version );
		return $p[0] . '.' . ( isset( $p[1] ) ? $p[1] : '0' );
	}
}
