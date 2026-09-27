<?php
/**
 * Plugin Name: FX Guarded
 * Description: Removed-in-PHP-8 code that can only run on old PHP, behind real guards.
 * Expect: Guarded legacy code for every target 8.0-8.5 (no Blocker).
 */

// 1. Version check, then-branch dead on PHP 8.
function fx_guarded_iter( $arr ) {
	if ( PHP_VERSION_ID < 80000 ) {
		return each( $arr );
	}
	return array( key( $arr ), current( $arr ) );
}

// 2. Capability check naming the removed function, inline with &&.
function fx_guarded_quotes() {
	return function_exists( 'get_magic_quotes_gpc' ) && get_magic_quotes_gpc();
}

// 3. version_compare with PHP_VERSION, single-statement body.
if ( version_compare( PHP_VERSION, '7.0', '<' ) )
	$fx_cb = create_function( '', 'return 1;' );

// 4. else-branch of a check that is true on PHP 8.
function fx_guarded_money( $n ) {
	if ( PHP_MAJOR_VERSION >= 8 ) {
		return number_format( $n, 2 );
	} else {
		return money_format( '%i', $n );
	}
}

// 5. Early return when the extension is missing; the rest only runs where it exists.
function fx_guarded_mysql( $sql ) {
	if ( ! extension_loaded( 'mysql' ) ) {
		return false;
	}
	return mysql_query( $sql );
}

// 6. elseif naming the removed function.
function fx_guarded_errno( $link ) {
	if ( function_exists( 'mysqli_errno' ) ) {
		return mysqli_errno( $link );
	} elseif ( function_exists( 'mysql_errno' ) ) {
		return mysql_errno( $link );
	}
	return 0;
}
