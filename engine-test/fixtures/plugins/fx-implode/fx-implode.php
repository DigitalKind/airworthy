<?php
/**
 * Plugin Name: FX Implode
 * Description: implode() with its arguments reversed (a pattern found in popular plugins): a fatal TypeError on PHP 8.
 * Expect: Blocker for every target 8.0-8.5.
 */
function fx_implode_cell( $values ) {
	return '<td>' . implode( (array) $values, ', ' ) . '</td>';
}
