<?php
/**
 * Plugin Name: FX Extension Fallback
 * Version: 1.0.0
 * Description: FIXTURE. mcrypt code that only runs when openssl is missing; a function_exists() check that covers the extension's constants; strip_tags() with a self-closing tag (harmless). Expect: Guarded.
 */

function fx_ef_encrypt( $key, $text ) {
	if ( extension_loaded( 'openssl' ) ) {
		return openssl_encrypt( $text, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, str_repeat( "\0", 16 ) );
	}
	$iv = mcrypt_create_iv( mcrypt_get_iv_size( MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC ), MCRYPT_RAND );
	return mcrypt_encrypt( MCRYPT_RIJNDAEL_128, $key, $text, MCRYPT_MODE_CBC, $iv );
}

function fx_ef_rc4( $key, $text ) {
	if ( function_exists( 'mcrypt_encrypt' ) && ( $out = @mcrypt_encrypt( MCRYPT_ARCFOUR, $key, $text, MCRYPT_MODE_STREAM, '' ) ) ) {
		return $out;
	}
	return $text;
}

function fx_ef_strip( $html ) {
	return strip_tags( $html, '<br/><a>' );
}
