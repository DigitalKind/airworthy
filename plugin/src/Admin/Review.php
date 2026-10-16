<?php
/**
 * "Did Airworthy help?" review request.
 *
 * @package Airworthy
 */

namespace Airworthy\Admin;

use Airworthy\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * After a finished scan, the results screen asks once whether the user would leave a review
 * on WordPress.org. Only on Airworthy's own screen, below the results, never blocking
 * anything. Each user answers for themselves: Leave a review and Don't ask again end it for
 * good, Maybe later asks again after 30 days.
 */
final class Review {

	/** User meta: "done", "never", or the Unix time to ask again after. */
	const META = 'airworthy_review';

	/** How long Maybe later waits. */
	const SNOOZE = 30 * DAY_IN_SECONDS;

	/** Where the button goes. */
	const URL = 'https://wordpress.org/support/plugin/airworthy/reviews/#new-post';

	/** Answers the results screen can send. */
	const CHOICES = array( 'done', 'later', 'never' );

	/**
	 * Whether to ask the current user now.
	 *
	 * @return bool
	 */
	public static function should_ask() {
		$user = get_current_user_id();
		if ( ! $user || ! Capabilities::current_user_can_scan() ) {
			return false;
		}
		$answer = get_user_meta( $user, self::META, true );
		if ( '' === $answer ) {
			return true;
		}
		return is_numeric( $answer ) && time() >= (int) $answer;
	}

	/**
	 * What the results screen needs to show the request, or null when it shouldn't.
	 *
	 * @return array{url:string}|null
	 */
	public static function config() {
		return self::should_ask() ? array( 'url' => self::URL ) : null;
	}

	/**
	 * Saves the current user's answer.
	 *
	 * @param string $choice One of self::CHOICES.
	 * @return bool Whether it was a valid answer.
	 */
	public static function answer( $choice ) {
		$user = get_current_user_id();
		if ( ! $user || ! in_array( $choice, self::CHOICES, true ) ) {
			return false;
		}
		update_user_meta( $user, self::META, 'later' === $choice ? (string) ( time() + self::SNOOZE ) : $choice );
		return true;
	}

	/**
	 * POST /review: saves the answer from the results screen.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_answer( \WP_REST_Request $request ) {
		if ( ! self::answer( (string) $request['choice'] ) ) {
			return new \WP_Error( 'airworthy_bad_choice', __( 'Unknown answer.', 'airworthy' ), array( 'status' => 400 ) );
		}
		return new \WP_REST_Response( array( 'saved' => true ), 200 );
	}

	/**
	 * Removes every user's answer (on uninstall).
	 */
	public static function forget_all() {
		delete_metadata( 'user', 0, self::META, '', true );
	}
}
