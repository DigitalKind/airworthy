<?php
/**
 * Plugin Name: FX Cross Fake
 * Description: Look-alikes of cross-file guards that do NOT protect the broken code.
 * Expect: Blocker for every target 8.0-8.5.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-fx-xf-legacy.php';
require_once __DIR__ . '/includes/class-fx-xf-dynamic.php';
require_once __DIR__ . '/includes/class-fx-xf-base.php';

// 1. Used in a dead branch AND in live code: the live use counts.
function fx_xf_pick() {
	if ( PHP_VERSION_ID < 70000 ) {
		return new FX_XF_Legacy();
	}
	return null;
}
add_action( 'init', function () {
	$legacy = new FX_XF_Legacy();
} );

// 2. Created dynamically from a string that builds the class name.
function fx_xf_make( $kind ) {
	$class = 'FX_XF_' . $kind;
	return new $class();
}

// 3. A class used only through a subclass: we don't follow inheritance, so no guard.
class FX_XF_Child extends FX_XF_Base {}
if ( PHP_VERSION_ID < 70000 ) {
	$fx_xf = new FX_XF_Base();
}

// 4. A compat file included under a dead condition here, but ALSO unconditionally below.
if ( PHP_VERSION_ID < 70000 ) {
	require __DIR__ . '/includes/compat.php';
}
require_once __DIR__ . '/includes/compat.php';
