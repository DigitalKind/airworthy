<?php
/**
 * Autoloader for the plugin's own classes (Airworthy\Foo\Bar => src/Foo/Bar.php).
 *
 * The bundled scan engine has its own, separately scoped autoloader and is only loaded
 * when a scan actually runs, never on normal page loads.
 *
 * @package Airworthy
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Airworthy\\';
		if ( 0 !== strpos( $class_name, $prefix ) || 0 === strpos( $class_name, $prefix . 'Vendor\\' ) ) {
			return;
		}
		$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);
