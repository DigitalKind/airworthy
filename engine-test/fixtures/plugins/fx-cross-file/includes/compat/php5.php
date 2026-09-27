<?php
// Only included on PHP 5 (see the main plugin file).
function fx_cf_next( $arr ) {
	return each( $arr );
}
$fx_cf_cb = create_function( '', 'return 1;' );
