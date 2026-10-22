<?php
/**
 * Plugin Name: FX PHP 8.6 look-alikes
 * Version: 1.0.0
 * Description: FIXTURE. Code that looks like a PHP 8.6 deprecation but isn't one. Expect: Ready for every target, 8.6 included.
 */

namespace FX_PHP86_Fake;

class Hasher {
	const SORT_LOCALE_STRING = 1;

	public function spl_object_hash( $o ) {
		return $o;
	}

	public static function is_long( $v ) {
		return $v;
	}

	public function let() {
		return 1;
	}

	public function __construct( $o ) {
		$this->cb = function () use ( $o ) {
			return $o; // A closure inside the constructor may return a value.
		};
		return;
	}

	public function __debugInfo(): array {
		return array();
	}
}

function is_double( $v ) {
	return $v;
}

function use_them( $h, $o ) {
	// spl_object_hash( $o ) in a comment, and 'spl_object_hash()' in a string.
	$text = 'is_long( 1 ) and doubleval( 2 )';
	$a    = $h->spl_object_hash( $o );
	$b    = Hasher::is_long( 1 );
	$c    = is_double( 2 ); // Calls FX_PHP86_Fake\is_double(), not PHP's.
	$d    = \FX_PHP86_Fake\is_double( 3 );
	$e    = Hasher::SORT_LOCALE_STRING;
	$f    = is_a( $o, 'stdClass' );
	$g    = is_a( 'stdClass', 'stdClass', true );
	$h2   = is_subclass_of( 'stdClass', 'stdClass' );
	define( 'FX_PHP86_FAKE_OK', 1 );
	try {
		$z = 1;
	} finally {
		$fn = function () {
			return 2; // A closure inside finally may return.
		};
	}
	return array( $text, $a, $b, $c, $d, $e, $f, $g, $h2, $fn );
}
