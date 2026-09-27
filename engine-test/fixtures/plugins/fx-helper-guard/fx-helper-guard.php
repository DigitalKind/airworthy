<?php
/**
 * Plugin Name: FX Helper Guard
 * Description: Old or new-PHP-only code behind helper methods that only return a PHP version check.
 * Expect: Guarded legacy code for every target 8.0-8.5.
 */
defined( 'ABSPATH' ) || exit;

final class FX_HG_Env {
	public static function is_legacy_php(): bool {
		return PHP_VERSION_ID < 80000;
	}

	// The WooCommerce shape: a version check AND a runtime setting (false on 8.0 whatever the setting).
	public static function supports_new_api(): bool {
		return self::is_modern() && get_option( 'fx_hg_api', false );
	}

	// Helper used by another helper.
	private static function is_modern(): bool {
		return PHP_VERSION_ID >= 80100;
	}

	private function modern() {
		return version_compare( PHP_VERSION, '8.1', '>=' );
	}

	public function run( $arr ) {
		if ( self::is_legacy_php() ) {
			return each( $arr );                     // Removed in 8.0; only runs before 8.0.
		}
		if ( ! $this->modern() ) {
			return null;
		}
		return array_is_list( $arr ) ? 1 : 0;        // PHP 8.1 function; only reached on 8.1+.
	}
}

function fx_hg_is_php5() {
	return PHP_MAJOR_VERSION < 7;
}
if ( fx_hg_is_php5() ) {
	$fx_hg_cb = create_function( '', 'return 1;' ); // Only runs on PHP 5.
}

// The WooCommerce shape: a hook callback that bails out unless a helper says PHP 8.1+.
class FX_HG_Api {
	public static function boot() {
		if ( ! FX_HG_Env::supports_new_api() ) {
			return;
		}
		$len = strlen( ... );                        // PHP 8.1 first-class callable syntax.
		return $len( 'x' );
	}
}
