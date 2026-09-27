<?php
/**
 * Plugin Name: FX Fake Guard
 * Description: Guards that look like guards but don't protect the broken line.
 * Expect: Blocker for every target 8.0-8.5. If this is ever cleared, guard detection hides real bugs.
 */

// 1. Include-guard on the plugin's own class: says nothing about PHP.
if ( ! class_exists( 'FX_Fake_Guard' ) ) {
	class FX_Fake_Guard {
		public function next( $arr ) {
			return each( $arr );
		}
	}
}

// 2. Capability check for something unrelated.
if ( function_exists( 'wp_get_current_user' ) ) {
	$fx_cb = create_function( '$a', 'return $a;' );
}

// 3. Version check that closes before the broken line.
if ( PHP_VERSION_ID < 80000 ) {
	$fx_old = true;
}
$fx_row = each( $fx_list );

// 4. Version check whose branch DOES run on PHP 8.
if ( PHP_VERSION_ID >= 70000 ) {
	$fx_quotes = get_magic_quotes_gpc();
}

// 5. Negated capability check: runs exactly when the function is missing.
if ( ! function_exists( 'money_format' ) ) {
	echo money_format( '%i', 5 );
}
