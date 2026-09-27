<?php
function acme_legacy_callback() {
	return create_function( '$a', 'return $a;' );
}
