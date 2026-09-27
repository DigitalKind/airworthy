<?php
/**
 * Plugin Name: FX Helper Fake
 * Description: Helpers that look like version-check guards but can't be trusted.
 * Expect: Blocker for every target 8.0-8.5.
 */
defined( 'ABSPATH' ) || exit;

class FX_HF_Env {
	// 1. Takes a parameter: the result depends on the call.
	public static function older_than( $version_id ) {
		return PHP_VERSION_ID < $version_id;
	}

	// 2. Does something before returning.
	public static function checked_legacy() {
		update_option( 'fx_hf_checked', 1 );
		return PHP_VERSION_ID < 80000;
	}

	// 3. OR with a runtime setting: may be true on any PHP.
	public static function legacy_or_forced() {
		return get_option( 'fx_hf_force_legacy' ) || PHP_VERSION_ID < 80000;
	}

	// 4. Public and not final: a subclass may override it, so $this-> calls can't be trusted.
	public function is_legacy_env() {
		return PHP_VERSION_ID < 80000;
	}

	public function run( $a ) {
		if ( self::older_than( 80000 ) ) {
			each( $a );
		}
		if ( self::checked_legacy() ) {
			each( $a );
		}
		if ( self::legacy_or_forced() ) {
			each( $a );
		}
		if ( $this->is_legacy_env() ) {
			each( $a );
		}
	}
}

// 5. Same method name as a real guard, on a different class that always returns true.
class FX_HF_Real {
	public static function is_legacy() {
		return PHP_VERSION_ID < 80000;
	}
}
class FX_HF_Other {
	public static function is_legacy() {
		return true;
	}
}
if ( FX_HF_Other::is_legacy() ) {
	$fx_hf_cb = create_function( '', 'return 1;' );
}
