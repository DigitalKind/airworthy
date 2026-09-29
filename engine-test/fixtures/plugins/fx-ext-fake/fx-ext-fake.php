<?php
/**
 * Plugin Name: FX Extension Fake
 * Version: 1.0.0
 * Description: FIXTURE. Checks for openssl but still runs the mcrypt code (no return, no else). Expect: Blocker.
 */

function fx_ex_fake( $key, $text ) {
	$mode = 'mcrypt';
	if ( extension_loaded( 'openssl' ) ) {
		$mode = 'openssl';
	}
	return mcrypt_encrypt( MCRYPT_RIJNDAEL_128, $key, $text . $mode, MCRYPT_MODE_CBC, '' );
}
