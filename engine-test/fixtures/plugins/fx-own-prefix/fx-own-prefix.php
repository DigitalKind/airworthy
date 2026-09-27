<?php
/**
 * Plugin Name: FX Own Prefix
 * Description: Own functions whose names start with removed-extension prefixes (sqlite_, mysql_).
 * PHPCompatibility flags the calls as the removed extensions. Expect: Ready (notices only).
 */
require __DIR__ . '/inc/helpers.php';

function fx_own_prefix_boot() {
	if ( sqlite_fx_is_ready() ) {
		return mysql_fx_table_name( 'posts' );
	}
	return '';
}
