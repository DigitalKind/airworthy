<?php
/**
 * A scan of a folder that is not installed (an update, or a zip), kept in memory.
 *
 * @package Airworthy
 */

namespace Airworthy\Scan;

use Airworthy\Engine\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the same checks as a site scan on one unpacked plugin or theme, without saving anything
 * to the scan tables. It works in steps so a big plugin never has to finish inside one request:
 * each step scans files until the time budget is used, saves where it got to in a transient, and
 * says whether more is left. The folder is only read, never run.
 */
final class Preview {

	/** Seconds a job lives after its last step. */
	const TTL = 1800;

	/** Findings kept as examples. */
	const SAMPLES = 8;

	/** Steps that may crash before the job is given up on. */
	const MAX_ATTEMPTS = 4;

	/**
	 * Starts a job on an unpacked folder.
	 *
	 * @param string $root    The plugin or theme folder.
	 * @param string $target  PHP version to check against.
	 * @param string $from    PHP version compared from ('' for none).
	 * @param array  $meta    Anything the caller wants to get back (it is kept with the job).
	 * @param string $cleanup Folder to delete when the job ends.
	 * @return string Job ID.
	 */
	public static function create( $root, $target, $from, array $meta = array(), $cleanup = '' ) {
		$listing = FileLister::list_files( $root );
		$prepass = FileLister::prepass( $root, $listing['files'] );
		$id      = wp_generate_password( 16, false );
		self::save(
			$id,
			array(
				'id'           => $id,
				'root'         => $root,
				'cleanup'      => $cleanup,
				'meta'         => $meta,
				'target'       => $target,
				'from'         => $from,
				'files'        => $listing['files'],
				'cursor'       => 0,
				'deferred'     => array(),
				'serializable' => isset( $prepass['serializable'] ) ? (array) $prepass['serializable'] : array(),
				'own'          => array_intersect_key( $prepass, array_flip( array( 'functions', 'constants', 'classes' ) ) ),
				'counts'       => array(
					'errors'        => 0,
					'guarded'       => 0,
					'suppressed'    => 0,
					'existing'      => 0,
					'warnings'      => 0,
					'files_done'    => 0,
					'files_failed'  => 0,
					'files_skipped' => 0,
				),
				'samples'      => array(),
				'attempts'     => 0,
				'status'       => 'running', // running | done | failed.
				'error'        => '',
			)
		);
		return $id;
	}

	/**
	 * A job's state, or null when it has expired or never existed.
	 *
	 * @param string $id Job ID.
	 * @return array|null
	 */
	public static function load( $id ) {
		$state = get_transient( self::key( $id ) );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Scans files until the time budget runs out or the job is finished.
	 *
	 * @param string      $id     Job ID.
	 * @param Budget|null $budget Budget (default: one batch).
	 * @return array|null The job's state, or null when it has expired.
	 */
	public static function step( $id, $budget = null ) {
		$state = self::load( $id );
		if ( ! $state ) {
			return null;
		}
		if ( 'running' !== $state['status'] ) {
			return $state;
		}
		$budget = $budget ? $budget : Budget::for_batch();

		// A step that dies (memory) never gets to save, so count it before starting: a file that
		// always crashes ends the job after a few tries instead of looping.
		++$state['attempts'];
		if ( $state['attempts'] > self::MAX_ATTEMPTS ) {
			return self::fail( $state, __( 'The check kept stopping part-way, so it could not be finished.', 'airworthy' ) );
		}
		self::save( $state['id'], $state );

		$engine = null;
		$root   = $state['root'];
		$n      = count( $state['files'] );
		$st     = array(
			'root'         => $root,
			'files'        => $state['files'],
			'target'       => $state['target'],
			'from'         => $state['from'],
			'own'          => $state['own'],
			'serializable' => $state['serializable'],
			'counts'       => $state['counts'],
		);

		while ( $budget->has_time() ) {
			$retry = false;
			if ( $state['cursor'] < $n ) {
				$index = $state['cursor'];
			} elseif ( $state['deferred'] ) {
				$index = (int) array_shift( $state['deferred'] );
				$retry = true;
			} else {
				break;
			}
			$relative = $state['files'][ $index ];
			$path     = rtrim( $root, '/\\' ) . '/' . ltrim( $relative, '/\\' );
			if ( ! $retry ) {
				++$state['cursor'];
			}

			$real = realpath( $path );
			if ( false === $real || 0 !== strpos( wp_normalize_path( $real ), wp_normalize_path( (string) realpath( $root ) ) . '/' ) ) {
				++$st['counts']['files_failed']; // Missing, or a link pointing outside the folder.
				continue;
			}
			$size = (int) filesize( $real );
			if ( ! $budget->fits( $size, $retry ) ) {
				if ( $retry ) {
					++$st['counts']['files_skipped']; // Too large for this server's memory, even alone.
				} else {
					$state['deferred'][] = $index;
				}
				continue;
			}

			$engine = $engine ? $engine : Engine::for_target( $state['target'] );
			try {
				$issues = Queue::file_issues( $engine, $st, $relative, $real );
			} catch ( \Throwable $e ) {
				++$st['counts']['files_failed'];
				continue;
			}
			if ( isset( $issues['failed'] ) ) {
				++$st['counts']['files_failed'];
				continue;
			}
			++$st['counts']['files_done'];
			foreach ( $issues as $issue ) {
				if ( count( $state['samples'] ) >= self::SAMPLES ) {
					break;
				}
				if ( 'error' === $issue['severity'] && in_array( $issue['context'], array( 'plain', 'suppressed' ), true ) ) {
					$state['samples'][] = array(
						'file'    => $relative,
						'line'    => (int) $issue['line'],
						'message' => mb_substr( str_replace( array( $root, ABSPATH ), '', (string) $issue['message'] ), 0, 300 ),
					);
				}
			}
		}

		$state['counts']   = $st['counts'];
		$state['attempts'] = 0; // The step finished: it did not crash.
		if ( $state['cursor'] >= $n && ! $state['deferred'] ) {
			$state['status'] = 'done';
			self::cleanup( $state );
		}
		self::save( $state['id'], $state );
		return $state;
	}

	/**
	 * The verdict for a job's counts.
	 *
	 * @param array $state Job state.
	 * @return string One of Verdict::ALL.
	 */
	public static function verdict( array $state ) {
		return Verdict::from_counts( $state['counts'] );
	}

	/**
	 * Share of the files done, 0 to 100.
	 *
	 * @param array $state Job state.
	 * @return int
	 */
	public static function percent( array $state ) {
		$n = count( $state['files'] );
		if ( 'done' === $state['status'] || 0 === $n ) {
			return 100;
		}
		return (int) min( 99, floor( 100 * $state['cursor'] / $n ) );
	}

	/**
	 * Ends a job early and removes its folder.
	 *
	 * @param string $id Job ID.
	 */
	public static function discard( $id ) {
		$state = self::load( $id );
		if ( $state ) {
			self::cleanup( $state );
		}
		delete_transient( self::key( $id ) );
	}

	/**
	 * Marks a job as failed.
	 *
	 * @param array  $state  Job state.
	 * @param string $reason What to tell the user.
	 * @return array The state.
	 */
	private static function fail( array $state, $reason ) {
		$state['status'] = 'failed';
		$state['error']  = $reason;
		self::cleanup( $state );
		self::save( $state['id'], $state );
		return $state;
	}

	/**
	 * Deletes the job's unpacked folder.
	 *
	 * @param array $state Job state.
	 */
	private static function cleanup( array $state ) {
		if ( '' === $state['cleanup'] ) {
			return;
		}
		UpdateCheck::remove_folder( $state['cleanup'] );
	}

	/**
	 * Saves a job.
	 *
	 * @param string $id    Job ID.
	 * @param array  $state State.
	 */
	private static function save( $id, array $state ) {
		set_transient( self::key( $id ), $state, self::TTL );
	}

	/**
	 * The transient's name.
	 *
	 * @param string $id Job ID.
	 * @return string
	 */
	private static function key( $id ) {
		return 'airworthy_pv_' . preg_replace( '/[^a-zA-Z0-9]/', '', $id );
	}
}
