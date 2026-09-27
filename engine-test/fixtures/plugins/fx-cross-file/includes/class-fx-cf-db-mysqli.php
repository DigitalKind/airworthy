<?php
defined( 'ABSPATH' ) || exit;
class FX_CF_Db_Mysqli extends FX_CF_Db {
	public function query( $sql ) {
		global $wpdb;
		return mysqli_query( $wpdb->dbh, $sql );
	}
}
