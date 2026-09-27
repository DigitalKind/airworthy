<?php
/**
 * Plugin Name: FX Ready
 * Description: Modern code, fine on PHP 8.0-8.5. Expect: Ready for every target.
 */
function fx_ready_title( ?string $title = null ): string {
	return $title ?? get_bloginfo( 'name' );
}
add_filter( 'the_title', fn( $t ) => strtoupper( $t ) );
