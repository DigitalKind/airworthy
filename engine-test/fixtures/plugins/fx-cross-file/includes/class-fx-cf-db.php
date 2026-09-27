<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access' );
}
abstract class FX_CF_Db {
	abstract public function query( $sql );
}
