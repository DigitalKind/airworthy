<?php
/**
 * "Does updating fix it?": scans a plugin's or theme's waiting update before it is installed.
 *
 * @package Airworthy
 */

namespace Airworthy\Scan;

use Airworthy\Installer;
use Airworthy\Wporg\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads the update package WordPress itself would install, unpacks it in the server's temp
 * folder, runs the normal checks on it (Preview), and compares the result with what the site has
 * now. Nothing is installed or run; the folder is deleted afterwards.
 *
 * Only packages from downloads.wordpress.org are fetched, and only when the site has agreed to
 * WordPress.org lookups. Paid plugins that update from their own servers are not looked inside.
 */
final class UpdateCheck {

	/** The one host packages are fetched from. */
	const HOST = 'downloads.wordpress.org';

	/** Largest package accepted, in bytes. */
	const MAX_ZIP = 62914560;

	/** Largest unpacked size accepted, in bytes. */
	const MAX_UNPACKED = 314572800;

	/** Folder name prefix in the temp folder. */
	const TEMP_PREFIX = 'airworthy-uc-';

	/** Job hook that removes a leftover folder. */
	const CLEANUP_HOOK = 'airworthy_uc_cleanup';

	/**
	 * The waiting update's version and package address, from WordPress's own update check.
	 *
	 * @param string $type plugin | theme.
	 * @param string $slug Slug.
	 * @return array{version:string,url:string}|null
	 */
	public static function package( $type, $slug ) {
		if ( 'theme' === $type ) {
			$t = get_site_transient( 'update_themes' );
			if ( is_object( $t ) && ! empty( $t->response[ $slug ] ) ) {
				$e = (array) $t->response[ $slug ];
				return array(
					'version' => isset( $e['new_version'] ) ? (string) $e['new_version'] : '',
					'url'     => isset( $e['package'] ) ? (string) $e['package'] : '',
				);
			}
			return null;
		}
		$t = get_site_transient( 'update_plugins' );
		if ( is_object( $t ) && ! empty( $t->response ) ) {
			foreach ( (array) $t->response as $file => $entry ) {
				$key = false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file );
				if ( $key === $slug ) {
					$entry = (object) $entry;
					return array(
						'version' => isset( $entry->new_version ) ? (string) $entry->new_version : '',
						'url'     => isset( $entry->package ) ? (string) $entry->package : '',
					);
				}
			}
		}
		return null;
	}

	/**
	 * Whether a package address is one Airworthy may fetch.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	public static function allowed_package( $url ) {
		$p = wp_parse_url( (string) $url );
		return is_array( $p )
			&& isset( $p['scheme'], $p['host'], $p['path'] )
			&& 'https' === $p['scheme']
			&& self::HOST === strtolower( $p['host'] )
			&& 1 === preg_match( '~^/(plugin|theme)/[A-Za-z0-9._-]+\.zip$~', $p['path'] );
	}

	/**
	 * Whether the update for this plugin or theme can be looked inside (for the screen to offer
	 * the button).
	 *
	 * @param string $type plugin | theme.
	 * @param string $slug Slug.
	 * @return bool
	 */
	public static function checkable( $type, $slug ) {
		$pkg = self::package( $type, $slug );
		return $pkg && self::allowed_package( $pkg['url'] );
	}

	/**
	 * Starts or continues the check of one waiting update.
	 *
	 * @param string $type plugin | theme.
	 * @param string $slug Slug.
	 * @return array status: running | done | problem (with code and message).
	 */
	public static function run( $type, $slug ) {
		$type = 'theme' === $type ? 'theme' : 'plugin';
		$pkg  = self::package( $type, $slug );
		if ( ! $pkg || '' === $pkg['version'] ) {
			return self::problem( 'no_update', __( 'There is no update waiting for this one.', 'airworthy' ) );
		}
		if ( ! self::allowed_package( $pkg['url'] ) ) {
			return self::problem( 'unavailable', __( 'This update does not come from WordPress.org, so Airworthy cannot look inside it before you install it.', 'airworthy' ) );
		}
		if ( ! Consent::allowed() ) {
			return self::problem( 'needs_consent', __( 'Checking an update downloads it from WordPress.org. Turn on WordPress.org lookups in Airworthy\'s settings to allow that.', 'airworthy' ) );
		}
		$scan = self::latest_scan();
		if ( ! $scan ) {
			return self::problem( 'no_scan', __( 'Run a scan first: Airworthy compares the update with what your site has now.', 'airworthy' ) );
		}

		$key  = md5( $type . '|' . $slug . '|' . $pkg['version'] . '|' . $scan->target_php . '|' . $scan->id );
		$done = get_transient( 'airworthy_ucr_' . $key );
		if ( is_array( $done ) ) {
			return $done;
		}
		$job = get_transient( 'airworthy_uc_' . $key );
		if ( ! $job || ! Preview::load( (string) $job ) ) {
			$job = self::begin( $pkg, $type, $slug, $scan, $key );
			if ( is_wp_error( $job ) ) {
				return self::problem( 'error', $job->get_error_message() );
			}
			set_transient( 'airworthy_uc_' . $key, $job, Preview::TTL );
		}
		return self::advance( (string) $job );
	}

	/**
	 * Takes the next step of a check job.
	 *
	 * @param string $job Job ID.
	 * @return array status: running | done | problem.
	 */
	public static function advance( $job ) {
		$state = Preview::step( $job );
		if ( ! $state ) {
			return self::problem( 'expired', __( 'The check ran out of time. Try again.', 'airworthy' ) );
		}
		if ( 'failed' === $state['status'] ) {
			return self::problem( 'error', $state['error'] );
		}
		if ( 'running' === $state['status'] ) {
			return array(
				'status'  => 'running',
				'job'     => $job,
				'percent' => Preview::percent( $state ),
			);
		}
		$result = self::result( $state );
		if ( ! empty( $state['meta']['key'] ) ) {
			set_transient( 'airworthy_ucr_' . $state['meta']['key'], $result, 6 * HOUR_IN_SECONDS );
			delete_transient( 'airworthy_uc_' . $state['meta']['key'] );
		}
		return $result;
	}

	/**
	 * Downloads and unpacks the update and starts the check.
	 *
	 * @param array  $pkg  version and url.
	 * @param string $type plugin | theme.
	 * @param string $slug Slug.
	 * @param object $scan Latest scan row.
	 * @param string $key  Result cache key.
	 * @return string|\WP_Error Job ID.
	 */
	private static function begin( array $pkg, $type, $slug, $scan, $key ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		// Like the WordPress.org lookups: Airworthy's own user agent, not WordPress's default, which contains the site's address.
		$agent = static function ( $args, $url ) use ( $pkg ) {
			if ( $url === $pkg['url'] ) {
				$args['user-agent'] = 'Airworthy/' . AIRWORTHY_VERSION;
			}
			return $args;
		};
		add_filter( 'http_request_args', $agent, 99, 2 );
		$zip = download_url( $pkg['url'], 120 );
		remove_filter( 'http_request_args', $agent, 99 );
		if ( is_wp_error( $zip ) ) {
			return new \WP_Error( 'download', __( 'The update could not be downloaded from WordPress.org just now. Try again in a minute.', 'airworthy' ) );
		}
		$job = self::from_zip(
			$zip,
			(string) $scan->target_php,
			isset( $scan->from_php ) ? (string) $scan->from_php : '',
			array(
				'type'    => $type,
				'slug'    => $slug,
				'version' => $pkg['version'],
				'key'     => $key,
			)
		);
		wp_delete_file( $zip );
		return $job;
	}

	/**
	 * Unpacks a zip in the temp folder and starts a check job on it.
	 *
	 * @param string $zip    Path of the zip file.
	 * @param string $target PHP version to check against.
	 * @param string $from   PHP version compared from.
	 * @param array  $meta   Kept with the job: type, slug, version, key.
	 * @return string|\WP_Error Job ID.
	 */
	public static function from_zip( $zip, $target, $from, array $meta = array() ) {
		if ( ! is_readable( $zip ) || filesize( $zip ) > self::MAX_ZIP ) {
			return new \WP_Error( 'zip', __( 'That file is missing or too large to check.', 'airworthy' ) );
		}
		if ( class_exists( 'ZipArchive' ) ) {
			$za = new \ZipArchive();
			if ( true !== $za->open( $zip ) ) {
				return new \WP_Error( 'zip', __( 'That is not a zip file Airworthy can read.', 'airworthy' ) );
			}
			$total   = 0;
			$entries = $za->count();
			for ( $i = 0; $i < $entries; $i++ ) {
				$stat = $za->statIndex( $i );
				$name = $stat ? (string) $stat['name'] : '';
				if ( '' === $name || false !== strpos( $name, '..' ) || '/' === $name[0] || '\\' === $name[0] || false !== strpos( $name, ':' ) ) {
					$za->close();
					return new \WP_Error( 'zip', __( 'That zip holds file names Airworthy will not unpack.', 'airworthy' ) );
				}
				$total += (int) $stat['size'];
			}
			$za->close();
			if ( $total > self::MAX_UNPACKED ) {
				return new \WP_Error( 'zip', __( 'That zip is too large to check.', 'airworthy' ) );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return new \WP_Error( 'fs', __( 'This server does not let Airworthy unpack files, so it cannot look inside the update.', 'airworthy' ) );
		}
		$dir = trailingslashit( get_temp_dir() ) . self::TEMP_PREFIX . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'fs', __( 'Airworthy could not make a temporary folder to unpack the update in.', 'airworthy' ) );
		}
		$unzipped = unzip_file( $zip, $dir );
		if ( is_wp_error( $unzipped ) ) {
			self::remove_folder( $dir );
			return new \WP_Error( 'zip', __( 'The update could not be unpacked.', 'airworthy' ) );
		}
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK, array( $dir ) ) ) {
			wp_schedule_single_event( time() + 2 * HOUR_IN_SECONDS, self::CLEANUP_HOOK, array( $dir ) ); // In case nobody comes back for the result.
		}
		$entries = array_values( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) );
		$root    = ( 1 === count( $entries ) && is_dir( $dir . '/' . $entries[0] ) ) ? $dir . '/' . $entries[0] : $dir;
		return Preview::create( $root, $target, $from, $meta, $dir );
	}

	/**
	 * Deletes one of Airworthy's temporary folders (and nothing else).
	 *
	 * @param string $dir Folder.
	 */
	public static function remove_folder( $dir ) {
		$dir = wp_normalize_path( (string) $dir );
		$tmp = wp_normalize_path( get_temp_dir() );
		if ( 0 !== strpos( $dir, $tmp ) || 0 !== strpos( basename( $dir ), self::TEMP_PREFIX ) || false !== strpos( $dir, '..' ) || ! is_dir( $dir ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		if ( WP_Filesystem() && $wp_filesystem ) {
			$wp_filesystem->delete( $dir, true );
		}
	}

	/**
	 * What a finished job says, in words, set against what the site has now.
	 *
	 * @param array $state A finished Preview job.
	 * @return array status done, outcome, message, before, after, samples, version, target.
	 */
	public static function result( array $state ) {
		$meta    = $state['meta'];
		$target  = $state['target'];
		$verdict = Preview::verdict( $state );
		$after   = array(
			'verdict'   => $verdict,
			'errors'    => (int) $state['counts']['errors'],
			'warnings'  => (int) $state['counts']['warnings'],
			'unchecked' => (int) $state['counts']['files_failed'] + (int) $state['counts']['files_skipped'],
		);
		$before  = null;
		if ( ! empty( $meta['type'] ) && ! empty( $meta['slug'] ) ) {
			$scan = self::latest_scan();
			$row  = $scan ? self::component( (int) $scan->id, $meta['type'], $meta['slug'] ) : null;
			if ( $row && null !== $row->verdict ) {
				$before = array(
					'verdict'  => (string) $row->verdict,
					'errors'   => (int) $row->errors,
					'warnings' => (int) $row->warnings,
				);
			}
		}
		$version = isset( $meta['version'] ) ? (string) $meta['version'] : '';
		$outcome = self::outcome( $before, $after );
		return array(
			'status'  => 'done',
			'outcome' => $outcome,
			'message' => self::message( $outcome, $version, $target, $before, $after ),
			'version' => $version,
			'target'  => $target,
			'before'  => $before,
			'after'   => $after,
			'samples' => $state['samples'],
		);
	}

	/**
	 * How the update compares with what the site has now.
	 *
	 * @param array|null $before verdict, errors, warnings (null when unknown).
	 * @param array      $after  The same for the update.
	 * @return string fixes | better | same | worse | ok | blocker | unknown
	 */
	public static function outcome( $before, array $after ) {
		$bad = static function ( $v ) {
			return in_array( $v, array( Verdict::BLOCKER, Verdict::UNKNOWN ), true );
		};
		if ( ! $before ) {
			return $bad( $after['verdict'] ) ? ( Verdict::BLOCKER === $after['verdict'] ? 'blocker' : 'unknown' ) : 'ok';
		}
		if ( $bad( $before['verdict'] ) && ! $bad( $after['verdict'] ) ) {
			return 'fixes';
		}
		if ( ! $bad( $before['verdict'] ) && $bad( $after['verdict'] ) ) {
			return 'worse';
		}
		if ( $bad( $before['verdict'] ) && $bad( $after['verdict'] ) ) {
			if ( $after['errors'] < $before['errors'] ) {
				return 'better';
			}
			return $after['errors'] > $before['errors'] ? 'worse' : 'same';
		}
		return 'same';
	}

	/**
	 * The sentence for an outcome.
	 *
	 * @param string     $outcome Outcome.
	 * @param string     $version Version of the update.
	 * @param string     $target  PHP version.
	 * @param array|null $before  Before.
	 * @param array      $after   After.
	 * @return string
	 */
	private static function message( $outcome, $version, $target, $before, array $after ) {
		$v = '' !== $version ? $version : __( 'the update', 'airworthy' );
		switch ( $outcome ) {
			case 'fixes':
				/* translators: 1: version, 2: PHP version. */
				$text = sprintf( __( 'Updating to %1$s fixes it: nothing in it would break on PHP %2$s.', 'airworthy' ), $v, $target );
				break;
			case 'better':
				/* translators: 1: version, 2: problems in the update, 3: problems now, 4: PHP version. */
				$text = sprintf( __( 'Updating to %1$s helps, but not enough: %2$d problems instead of %3$d, still a Blocker on PHP %4$s.', 'airworthy' ), $v, $after['errors'], $before ? $before['errors'] : 0, $target );
				break;
			case 'worse':
				/* translators: 1: version, 2: PHP version. */
				$text = sprintf( __( 'Careful: %1$s would make it worse on PHP %2$s. Ask the developer before you update.', 'airworthy' ), $v, $target );
				break;
			case 'blocker':
				/* translators: 1: version, 2: PHP version. */
				$text = sprintf( __( '%1$s would still not work on PHP %2$s.', 'airworthy' ), $v, $target );
				break;
			case 'unknown':
				/* translators: 1: version, 2: PHP version. */
				$text = sprintf( __( 'Some files in %1$s could not be checked for PHP %2$s.', 'airworthy' ), $v, $target );
				break;
			case 'ok':
				/* translators: 1: version, 2: PHP version. */
				$text = sprintf( __( 'Nothing in %1$s would break on PHP %2$s.', 'airworthy' ), $v, $target );
				break;
			default:
				/* translators: 1: version, 2: PHP version. */
				$text = sprintf( __( 'Updating to %1$s changes nothing for PHP %2$s: the result stays the same.', 'airworthy' ), $v, $target );
		}
		if ( $after['unchecked'] > 0 && 'unknown' !== $outcome ) {
			/* translators: %d: number of files. */
			$text .= ' ' . sprintf( _n( '%d file could not be checked.', '%d files could not be checked.', $after['unchecked'], 'airworthy' ), $after['unchecked'] );
		}
		return $text;
	}

	/**
	 * The latest finished scan.
	 *
	 * @return object|null id, target_php, from_php.
	 */
	public static function latest_scan() {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, read-only.
		$scan = $wpdb->get_row( $wpdb->prepare( "SELECT id, target_php, from_php FROM %i WHERE status = 'complete' ORDER BY id DESC LIMIT 1", $t['scans'] ) );
		return $scan ? $scan : null;
	}

	/**
	 * One installed component's row in a scan.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $type    plugin | theme.
	 * @param string $slug    Slug.
	 * @return object|null verdict, errors, warnings.
	 */
	private static function component( $scan_id, $type, $slug ) {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, read-only.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT verdict, errors, warnings FROM %i WHERE scan_id = %d AND type = %s AND slug = %s', $t['components'], $scan_id, $type, $slug ) );
		return $row ? $row : null;
	}

	/**
	 * A problem answer.
	 *
	 * @param string $code    Short code.
	 * @param string $message What to tell the user.
	 * @return array
	 */
	private static function problem( $code, $message ) {
		return array(
			'status'  => 'problem',
			'code'    => $code,
			'message' => $message,
		);
	}
}
