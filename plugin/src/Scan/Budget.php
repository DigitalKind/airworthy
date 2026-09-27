<?php
/**
 * Time and memory limits for one background batch.
 *
 * @package Airworthy
 */

namespace Airworthy\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Measures the server's real limits at runtime and keeps each batch well inside them.
 *
 * Time: at most 20 seconds, and never more than half of what's left of the request's
 * max_execution_time (Action Scheduler's own queue runner stops at 30 seconds).
 *
 * Memory: Step 1 measured peak memory per file at a median of ~165x the file's size and up
 * to ~380x (plus a small fixed cost). The first pass is cautious: a file is only scanned when
 * bytes x 450 + 2 MB fits in the free headroom, keeping 16 MB spare. Files that don't fit are
 * deferred and retried alone at the end with a realistic bytes x 200 + 2 MB; a retry that
 * still runs out of memory is caught by the crash recovery and recorded as failed.
 */
final class Budget {

	const MAX_SECONDS    = 20;
	const BYTES_PER_BYTE = 450;
	const RETRY_PER_BYTE = 200;
	const FIXED_BYTES    = 2097152;  // 2 MB.
	const RESERVE_BYTES  = 16777216; // 16 MB.

	/** Tokenizer memory per byte of source, with a margin (FileLister::prepass()). */
	const TOKENS_PER_BYTE = 100;

	/**
	 * When this batch started (microtime).
	 *
	 * @var float
	 */
	private $started;

	/**
	 * Seconds this batch may spend scanning.
	 *
	 * @var float
	 */
	private $seconds;

	/**
	 * PHP memory limit in bytes.
	 *
	 * @var int
	 */
	private $memory_limit;

	/**
	 * Measures limits for a new batch. Raises the memory limit the way wp-admin does, where
	 * the host allows it.
	 *
	 * @return self
	 */
	public static function for_batch() {
		wp_raise_memory_limit( 'airworthy' );

		$budget          = new self();
		$budget->started = microtime( true );

		$max_execution = (int) ini_get( 'max_execution_time' );
		$seconds       = self::MAX_SECONDS;
		if ( $max_execution > 0 ) {
			$request_start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $budget->started;
			$remaining     = $max_execution - ( $budget->started - $request_start );
			$seconds       = min( $seconds, max( 1, $remaining * 0.5 ) );
		}
		/**
		 * Filters how many seconds one scan batch may run.
		 *
		 * @param float $seconds Default: min(20, half the remaining max_execution_time).
		 */
		$budget->seconds = (float) apply_filters( 'airworthy_batch_seconds', $seconds );

		$limit                = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$budget->memory_limit = $limit > 0 ? $limit : 1073741824; // Unlimited (-1): assume 1 GB.

		return $budget;
	}

	/**
	 * Seconds allowed for this batch.
	 *
	 * @return float
	 */
	public function seconds() {
		return $this->seconds;
	}

	/**
	 * Whether there is time to start another file.
	 *
	 * @return bool
	 */
	public function has_time() {
		return ( microtime( true ) - $this->started ) < $this->seconds;
	}

	/**
	 * Whether PHP's tokenizer can read a file of this size in the free memory right now.
	 * Measured: about 70 bytes per byte (141 MB for a 2 MB file). Used where other files of a
	 * component are read while one file is in flight (FileLister::prepass(), LoadGraph): a
	 * crash there would be blamed on the wrong file, or on none.
	 *
	 * @param int $bytes File size.
	 * @return bool
	 */
	public static function tokenizable( $bytes ) {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$limit = $limit > 0 ? $limit : 1073741824;
		return $bytes * self::TOKENS_PER_BYTE + self::FIXED_BYTES <= $limit - memory_get_usage() - self::RESERVE_BYTES;
	}

	/**
	 * Whether a file of this size is likely to fit in the free memory right now.
	 *
	 * @param int  $bytes    File size.
	 * @param bool $is_retry Whether this is the file's single retry (realistic, not worst-case, estimate).
	 * @return bool
	 */
	public function fits( $bytes, $is_retry = false ) {
		$ratio = $is_retry ? self::RETRY_PER_BYTE : self::BYTES_PER_BYTE;
		/**
		 * Filters the estimated peak memory needed to scan a file.
		 *
		 * @param int  $estimate Bytes; default bytes x 450 + 2 MB (bytes x 200 + 2 MB on retry).
		 * @param int  $bytes    File size.
		 * @param bool $is_retry Whether this is the file's single retry.
		 */
		$estimate = (int) apply_filters( 'airworthy_memory_estimate', $bytes * $ratio + self::FIXED_BYTES, $bytes, $is_retry );
		// Memory in use now, not memory PHP has reserved (memory_get_usage( true ) never shrinks
		// within a request, so after one big file every later file would look too large).
		// This is the setting Step 1 validated: no crashes at 64 MB or 128 MB over 22,000 files.
		$headroom = $this->memory_limit - memory_get_usage() - self::RESERVE_BYTES;
		return $estimate <= $headroom;
	}
}
