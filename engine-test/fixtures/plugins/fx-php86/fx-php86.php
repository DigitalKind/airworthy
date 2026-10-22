<?php
/**
 * Plugin Name: FX PHP 8.6
 * Version: 1.0.0
 * Description: FIXTURE. One use of each PHP 8.6 deprecation Airworthy checks. Expect: Ready for PHP 8.0–8.5, Warnings for 8.6 (deprecations, nothing removed).
 */

function fx_php86_functions( $obj, $value, $a, $b ) {
	$id    = spl_object_hash( $obj );
	$list  = spl_classes();
	$float = is_double( $value ) || is_long( $value ) || is_integer( $value );
	$num   = doubleval( $value );
	$cmp   = strcoll( $a, $b );
	$sound = metaphone( $a );
	$found = mb_ereg( '[a-z]+', $a ) || mb_eregi_replace( 'x', 'y', $b ) || mb_split( ',', $a );
	mb_regex_encoding( 'UTF-8' );
	$sorted = array( $a, $b );
	sort( $sorted, SORT_LOCALE_STRING );
	return array( $id, $list, $float, $num, $cmp, $sound, $found, $sorted, \is_long( 1 ) );
}

function fx_php86_mysqli( $link ) {
	$charset = mysqli_get_charset( $link );
	$stmt    = mysqli_stmt_init( $link );
	return array( $charset, $stmt );
}

define( '_', 1 );

function fx_php86_finally() {
	try {
		return 1;
	} finally {
		return 2;
	}
}

class FX_PHP86_Thing {
	const namespace = 'x';

	public function __construct() {
		return $this;
	}

	public function __destruct() {
		yield 1;
	}

	public function __debugInfo(): ?array {
		return null;
	}

	public static function check() {
		return is_a( 'FX_PHP86_Thing', 'FX_PHP86_Thing' ) || is_subclass_of( FX_PHP86_Thing::class, 'stdClass', false );
	}
}

class Let {}

function is() {}
