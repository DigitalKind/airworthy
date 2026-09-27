<?php
/**
 * Plugin Name: FX Warnings
 * Description: Deprecations only. Expect: Warnings (8.1: strftime, 8.2: utf8_encode, 8.4: implicit nullable).
 */
function fx_warn_date() { return strftime( '%Y' ); }
function fx_warn_enc( $s ) { return utf8_encode( $s ); }
function fx_warn_post( WP_Post $post = null ) {}
