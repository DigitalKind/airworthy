<?php
/**
 * Plugin Name: FX Suppressed
 * Description: Broken code silenced only by the author's phpcs:ignore comments.
 * Expect: Suppressed by author for every target 8.0-8.5 (shown, never hidden).
 */
function fx_suppressed_next( $arr ) {
	return each( $arr ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved -- Legacy support.
}

// phpcs:ignore PHPCompatibility
$fx_cb = create_function( '', 'return 1;' );

// phpcs:disable PHPCompatibility.FunctionUse.RemovedFunctions
$fx_q = get_magic_quotes_gpc();
// phpcs:enable
