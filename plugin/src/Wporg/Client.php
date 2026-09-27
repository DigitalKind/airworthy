<?php
/**
 * WordPress.org plugin directory lookups.
 *
 * @package Airworthy
 */

namespace Airworthy\Wporg;

use Airworthy\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up one plugin slug in the public WordPress.org plugin information API.
 *
 * Privacy: the request carries the slug and nothing else about the site. WordPress's default
 * User-Agent includes the site's URL ("WordPress/x.y; https://example.com"), so we send our
 * own ("Airworthy/<version>") instead. Only the fields we show are requested.
 *
 * Results (listed, closed, or not found) are cached per slug for 24 hours; failures to reach
 * WordPress.org are not cached, so the next scan tries again.
 */
final class Client {

	const API = 'https://api.wordpress.org/plugins/info/1.2/';

	/** Seconds to cache a lookup. */
	const TTL = DAY_IN_SECONDS;

	/** Fields the API would otherwise include; turned off to keep responses small. */
	const OFF = array( 'sections', 'description', 'short_description', 'screenshots', 'versions', 'contributors', 'ratings', 'banners', 'icons', 'tags', 'reviews', 'active_installs', 'donate_link', 'compatibility', 'downloaded', 'homepage', 'download_link', 'upgrade_notice', 'support_threads', 'support_threads_resolved', 'num_ratings', 'rating', 'added' );

	/**
	 * Looks up a slug.
	 *
	 * @param string $slug Plugin slug (folder name).
	 * @return array|\WP_Error Normalised record: listed, closed, name, version, tested,
	 *                         requires, requires_php, last_updated, closed_date, closed_reason.
	 */
	public static function lookup( $slug ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return self::not_found();
		}
		$key    = Installer::TRANSIENT_PREFIX . $slug;
		$cached = get_site_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$args = array(
			'action'  => 'plugin_information',
			'request' => array(
				'slug'   => $slug,
				'fields' => array_fill_keys( self::OFF, 0 ),
			),
		);
		/**
		 * Filters the WordPress.org API URL (for tests).
		 *
		 * @param string $url API base URL.
		 */
		$url      = add_query_arg( $args, apply_filters( 'airworthy_wporg_api_url', self::API ) );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 8,
				'user-agent' => 'Airworthy/' . AIRWORTHY_VERSION, // Not WordPress's default, which contains the site URL.
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		// The API answers 404 for unknown slugs; anything else unexpected is "unreachable".
		if ( ! is_array( $data ) || ( 200 !== $code && 404 !== $code ) ) {
			return new \WP_Error( 'airworthy_wporg_unreachable', sprintf( 'WordPress.org answered HTTP %d.', $code ) );
		}

		$record = self::normalise( $data );
		set_site_transient( $key, $record, self::TTL );
		return $record;
	}

	/**
	 * Keeps the fields we use, in one shape for listed, closed and unknown plugins.
	 *
	 * @param array $data API response.
	 * @return array
	 */
	private static function normalise( array $data ) {
		if ( ! empty( $data['closed'] ) || ( isset( $data['error'] ) && 'closed' === $data['error'] ) ) {
			return array(
				'listed'        => false,
				'closed'        => true,
				'name'          => self::text( isset( $data['name'] ) ? $data['name'] : '' ),
				'closed_date'   => isset( $data['closed_date'] ) ? substr( (string) $data['closed_date'], 0, 10 ) : '',
				'closed_reason' => self::text( isset( $data['reason_text'] ) ? $data['reason_text'] : '' ),
				'permanent'     => isset( $data['description'] ) && false !== stripos( (string) $data['description'], 'permanent' ),
			);
		}
		if ( isset( $data['error'] ) || empty( $data['slug'] ) ) {
			return self::not_found();
		}
		$updated = isset( $data['last_updated'] ) ? strtotime( (string) $data['last_updated'] ) : false;
		return array(
			'listed'       => true,
			'closed'       => false,
			'name'         => self::text( $data['name'] ),
			'version'      => isset( $data['version'] ) ? (string) $data['version'] : '',
			'tested'       => isset( $data['tested'] ) && $data['tested'] ? (string) $data['tested'] : '',
			'requires'     => isset( $data['requires'] ) && $data['requires'] ? (string) $data['requires'] : '',
			'requires_php' => isset( $data['requires_php'] ) && $data['requires_php'] ? (string) $data['requires_php'] : '',
			'last_updated' => $updated ? gmdate( 'Y-m-d', $updated ) : '',
		);
	}

	/**
	 * Record for a slug WordPress.org doesn't know.
	 *
	 * @return array
	 */
	private static function not_found() {
		return array(
			'listed' => false,
			'closed' => false,
		);
	}

	/**
	 * Plain text from an API string (it contains HTML entities such as &#8211;).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ) {
		return trim( wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) ) );
	}
}
