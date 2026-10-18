<?php
/**
 * Plugin Name: FX Static This
 * Version: 1.0.0
 * Description: FIXTURE. $this inside a static closure (a pattern found in a widely used plugin): an Error on every PHP version, so it already applies on the site's current PHP. Expect: Ready, with the finding counted as already applying, never a Blocker.
 */

class FX_Static_This_Routes {

	public function register() {
		register_rest_route(
			'fx-static-this/v1',
			'/items',
			array(
				'methods'             => 'GET',
				'callback'            => '__return_empty_array',
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' ) ? true : new WP_Error( 'forbidden', 'No.', array( 'status' => $this->status_code() ) );
				},
			)
		);
	}

	private function status_code() {
		return is_user_logged_in() ? 403 : 401;
	}
}
