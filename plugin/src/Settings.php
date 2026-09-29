<?php
/**
 * The site's Airworthy settings.
 *
 * @package Airworthy
 */

namespace Airworthy;

defined( 'ABSPATH' ) || exit;

/**
 * Three settings, stored in one site option:
 * - skip_inactive: leave inactive plugins out of scans;
 * - ignore: plugins and themes never scanned ("plugin:slug" / "theme:slug");
 * - speed: gentle | normal | fast, how hard background scans work the server.
 *
 * The admin screen's Settings tab saves them (save(), through admin-post.php). They apply to
 * new scans; WP-CLI's --only scans exactly what it names, whatever they say.
 */
final class Settings {

	/** Site option. */
	const OPTION = 'airworthy_settings';

	/** Action name for admin-post.php (the settings form). */
	const ACTION = 'airworthy_save_settings';

	/** Scan speeds: seconds per background batch, and the pause before the next one. */
	const SPEEDS = array(
		'gentle' => array(
			'seconds' => 8,
			'pause'   => 30,
		),
		'normal' => array(
			'seconds' => 20,
			'pause'   => 0,
		),
		'fast'   => array(
			'seconds' => 40,
			'pause'   => 0,
		),
	);

	/**
	 * Hooks the form handler.
	 */
	public static function register() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array{skip_inactive:bool,ignore:string[],speed:string}
	 */
	public static function all() {
		$saved = get_site_option( self::OPTION, array() );
		return self::clean( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key skip_inactive, ignore or speed.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Settings from any input, with every value checked.
	 *
	 * @param array $input Raw values.
	 * @return array{skip_inactive:bool,ignore:string[],speed:string}
	 */
	public static function clean( array $input ) {
		$ignore = array();
		foreach ( isset( $input['ignore'] ) ? (array) $input['ignore'] : array() as $key ) {
			if ( is_string( $key ) && preg_match( '/^(plugin|theme):[A-Za-z0-9._-]+$/', $key ) ) {
				$ignore[] = $key;
			}
		}
		$speed = isset( $input['speed'] ) ? (string) $input['speed'] : 'normal';
		return array(
			'skip_inactive' => ! empty( $input['skip_inactive'] ),
			'ignore'        => array_values( array_unique( $ignore ) ),
			'speed'         => isset( self::SPEEDS[ $speed ] ) ? $speed : 'normal',
		);
	}

	/**
	 * Seconds one background batch may run, for the speed setting.
	 *
	 * @return int
	 */
	public static function batch_seconds() {
		return self::SPEEDS[ self::get( 'speed' ) ]['seconds'];
	}

	/**
	 * Seconds to wait before the next background batch, for the speed setting.
	 *
	 * @return int
	 */
	public static function batch_pause() {
		return self::SPEEDS[ self::get( 'speed' ) ]['pause'];
	}

	/**
	 * Saves the Settings tab's form (admin-post.php), then returns to it.
	 */
	public static function save() {
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'airworthy' ), 403 );
		}
		check_admin_referer( self::ACTION );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is checked by clean().
		$input = isset( $_POST['airworthy'] ) ? wp_unslash( (array) $_POST['airworthy'] ) : array();
		update_site_option( self::OPTION, self::clean( $input ) );
		delete_site_transient( Rest\Controller::FILE_COUNT ); // The time estimate depends on what's scanned.
		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'     => 'settings',
					'updated' => '1',
				),
				Admin\Page::url()
			)
		);
		exit;
	}
}
