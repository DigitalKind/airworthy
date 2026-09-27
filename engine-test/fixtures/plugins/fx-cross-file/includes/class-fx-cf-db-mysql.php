<?php
defined( 'ABSPATH' ) || exit;
/**
 * Legacy driver: only created on PHP 5 (see fx_cf_db()).
 */
final class FX_CF_Db_Mysql extends FX_CF_Db {
	public function query( $sql ) {
		return mysql_query( $sql );
	}
	public function error() {
		return mysql_error();
	}
}
