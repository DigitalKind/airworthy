<?php
/**
 * Verdict for one plugin or theme.
 *
 * @package Airworthy
 */

namespace Airworthy\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a component's counts into its verdict, most serious first:
 *
 * - blocker:    at least one plain (unguarded, unsuppressed) error for the target version, or
 *               the plugin's WordPress.org "Requires PHP" is above the target. Wins even over
 *               files that couldn't be checked: a known break is known.
 * - unknown:    a file couldn't be checked (unreadable, crashed twice, too large, parse error).
 * - suppressed: errors silenced only by the author's phpcs:ignore comments (lower confidence).
 * - guarded:    errors only in code that won't run on the target (Guarded legacy code).
 * - warnings:   deprecations only; works today but will break later.
 * - ready:      nothing found by the static scan.
 */
final class Verdict {

	const BLOCKER    = 'blocker';
	const UNKNOWN    = 'unknown';
	const SUPPRESSED = 'suppressed';
	const GUARDED    = 'guarded';
	const WARNINGS   = 'warnings';
	const READY      = 'ready';

	/** All verdicts, most serious first. */
	const ALL = array( self::BLOCKER, self::UNKNOWN, self::SUPPRESSED, self::GUARDED, self::WARNINGS, self::READY );

	/**
	 * Works out a verdict.
	 *
	 * @param array $counts errors (plain), suppressed, guarded, warnings, files_failed, files_skipped,
	 *                      requires_php_too_high.
	 * @return string
	 */
	public static function from_counts( array $counts ) {
		$get = static function ( $key ) use ( $counts ) {
			return isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
		};
		if ( $get( 'errors' ) > 0 || $get( 'requires_php_too_high' ) > 0 ) {
			return self::BLOCKER;
		}
		if ( $get( 'files_failed' ) > 0 || $get( 'files_skipped' ) > 0 ) {
			return self::UNKNOWN;
		}
		if ( $get( 'suppressed' ) > 0 ) {
			return self::SUPPRESSED;
		}
		if ( $get( 'guarded' ) > 0 ) {
			return self::GUARDED;
		}
		if ( $get( 'warnings' ) > 0 ) {
			return self::WARNINGS;
		}
		return self::READY;
	}
}
