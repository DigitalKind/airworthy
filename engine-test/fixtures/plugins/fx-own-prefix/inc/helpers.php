<?php
if ( ! function_exists( 'sqlite_fx_is_ready' ) ) {
	function sqlite_fx_is_ready() {
		return true;
	}
}
function mysql_fx_table_name( $name ) {
	global $wpdb;
	return $wpdb->prefix . $name;
}
class FX_Own_Prefix_Db {
	// A method with an extension prefix must NOT make a global sqlite_open() call "own code".
	public function sqlite_open() {
		return null;
	}
}
