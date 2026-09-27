<?php
/**
 * Plugin Name: FX Blocker 8.0
 * Description: Uses create_function (removed in 8.0), in vendor code. Expect: Blocker for 8.0+.
 */
require __DIR__ . '/vendor/acme/lib/Legacy.php';
add_action( 'init', 'fx_blocker_init' );
function fx_blocker_init() {}
