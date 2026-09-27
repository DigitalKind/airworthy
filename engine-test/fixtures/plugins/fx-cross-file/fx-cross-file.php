<?php
/**
 * Plugin Name: FX Cross File
 * Description: Removed-in-PHP-8 code in files whose code only runs under a PHP-version condition set in ANOTHER file.
 * Expect: Guarded legacy code for every target 8.0-8.5.
 */
defined( 'ABSPATH' ) || exit;

define( 'FX_CF_DIR', plugin_dir_path( __FILE__ ) );

// Class files are loaded unconditionally, but only declare classes.
require_once FX_CF_DIR . 'includes/class-fx-cf-db.php';
require_once FX_CF_DIR . 'includes/class-fx-cf-db-mysqli.php';
require_once __DIR__ . '/includes/class-fx-cf-db-mysql.php';

// Pattern 1 (All-in-One WP Migration): the legacy class is only created after an early
// return that is always taken on PHP 7+.
function fx_cf_db() {
	if ( PHP_MAJOR_VERSION >= 7 ) {
		return new FX_CF_Db_Mysqli();
	}
	return new FX_CF_Db_Mysql();
}

// Pattern 2: a procedural compat file only included on PHP 5.
if ( version_compare( PHP_VERSION, '7.0', '<' ) ) {
	require dirname( __FILE__ ) . '/includes/compat/php5.php';
}
