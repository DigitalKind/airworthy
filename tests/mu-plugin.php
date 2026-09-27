<?php
/**
 * TEST SITES ONLY (not part of the plugin). Installed as an mu-plugin by tests/run.sh.
 *
 * Answers every WordPress.org plugin lookup from tests/wporg-mock.json, so test results never
 * depend on the network or on the live directory. Unknown slugs get the API's 404 answer.
 * Every lookup is logged (URL and User-Agent) to the airworthy_tests_http_log option.
 */

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'api.wordpress.org/plugins/info' ) ) {
			return $pre;
		}
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$slug = isset( $query['request']['slug'] ) ? (string) $query['request']['slug'] : '';

		$log   = (array) get_option( 'airworthy_tests_http_log', array() );
		$log[] = array(
			'slug'       => $slug,
			'url'        => $url,
			'user-agent' => isset( $args['user-agent'] ) ? $args['user-agent'] : '',
		);
		update_option( 'airworthy_tests_http_log', $log, false );

		static $mock = null;
		if ( null === $mock ) {
			$mock = json_decode( (string) file_get_contents( __DIR__ . '/airworthy-tests-wporg.json' ), true );
		}
		if ( isset( $mock[ $slug ] ) ) {
			$code = 200;
			$body = $mock[ $slug ];
			if ( isset( $body['last_updated_days_ago'] ) ) {
				$body['last_updated'] = gmdate( 'Y-m-d g:ia \G\M\T', time() - DAY_IN_SECONDS * (int) $body['last_updated_days_ago'] );
				unset( $body['last_updated_days_ago'] );
			}
			if ( isset( $body['error'] ) ) {
				$code = 404;
			}
		} else {
			$code = 404;
			$body = array( 'error' => 'Plugin not found.' );
		}
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Not Found',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// Core's update checks would also call WordPress.org: answer them with "nothing new".
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== strpos( $url, 'api.wordpress.org/plugins/update-check' ) || false !== strpos( $url, 'api.wordpress.org/themes/update-check' ) ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'plugins' => array(), 'themes' => array(), 'translations' => array(), 'no_update' => array() ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		return $pre;
	},
	9,
	3
);
