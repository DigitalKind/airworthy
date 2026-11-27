<?php
/**
 * Airworthy Pro promotion in the free plugin: one dismissible card on the results screen and one
 * "Airworthy Pro" item in the Tools menu.
 *
 * @package Airworthy
 */

namespace Airworthy\Admin;

use Airworthy\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * After a finished scan that found something to fix, Airworthy's own results screen offers one
 * card that explains what Airworthy Pro adds. It never blocks anything, never shows when Pro is
 * installed, and each user decides for themselves: "Not now" hides it for 60 days and "Don't
 * show again" ends it, and also hides the menu item for that user. The check and its results
 * stay free; nothing in the free plugin is held back for Pro.
 *
 * Sites and hosts can hide all of it for everyone with the airworthy_show_pro_promotions filter.
 */
final class ProCard {

	/** User meta: "never", or the Unix time to show it again after. */
	const META = 'airworthy_pro_card';

	/** How long Not now waits. */
	const SNOOZE = 60 * DAY_IN_SECONDS;

	/** Where the button goes. The utm values say which placement it came from. */
	const URL = 'https://airworthywp.com/pro/?utm_source=plugin&utm_medium=results&utm_campaign=pro-card';

	/** Where the Tools menu item goes. */
	const MENU_URL = 'https://airworthywp.com/pro/?utm_source=plugin&utm_medium=menu&utm_campaign=pro-menu';

	/** Answers the results screen can send. */
	const CHOICES = array( 'later', 'never' );

	/**
	 * Whether Airworthy Pro is installed and active.
	 *
	 * @return bool
	 */
	public static function pro_active() {
		return defined( 'AIRWORTHY_PRO_VERSION' );
	}

	/**
	 * Whether Airworthy Pro may be promoted at all on this site: not when Pro is installed, and
	 * not when a site or host has turned it off.
	 *
	 * @return bool
	 */
	public static function promotions_allowed() {
		if ( self::pro_active() ) {
			return false;
		}
		/**
		 * Filters whether the free plugin may promote Airworthy Pro (the results card and the
		 * Tools menu item).
		 *
		 * @param bool $show True by default; return false to hide all of it for everyone.
		 */
		return (bool) apply_filters( 'airworthy_show_pro_promotions', true );
	}

	/**
	 * Whether to show the results card to the current user now.
	 *
	 * @return bool
	 */
	public static function should_show() {
		$user = get_current_user_id();
		if ( ! $user || ! Capabilities::current_user_can_scan() || ! self::promotions_allowed() ) {
			return false;
		}
		$answer = get_user_meta( $user, self::META, true );
		if ( '' === $answer ) {
			return true;
		}
		return is_numeric( $answer ) && time() >= (int) $answer;
	}

	/**
	 * Whether to show the Tools menu item to the current user: like the card, but Not now
	 * doesn't hide it (it is quiet); Don't show again does.
	 *
	 * @return bool
	 */
	public static function menu_visible() {
		$user = get_current_user_id();
		if ( ! $user || ! Capabilities::current_user_can_scan() || ! self::promotions_allowed() ) {
			return false;
		}
		return 'never' !== get_user_meta( $user, self::META, true );
	}

	/**
	 * Hooks the Tools menu item.
	 */
	public static function register() {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( __CLASS__, 'add_menu' ), 20 ); // After Airworthy's own item.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'style_menu' ) );
	}

	/**
	 * Adds "Airworthy Pro" right after Airworthy in the Tools menu (Settings on a network). It
	 * links to the Pro page on airworthywp.com. The entry is added to the menu array directly
	 * because add_submenu_page() would mangle an external address.
	 */
	public static function add_menu() {
		global $submenu;
		if ( ! self::menu_visible() ) {
			return;
		}
		$parent = is_multisite() ? 'settings.php' : 'tools.php';
		if ( ! isset( $submenu[ $parent ] ) ) {
			return;
		}
		$entry    = array( __( 'Airworthy Pro', 'airworthy' ), Capabilities::required(), self::MENU_URL );
		$position = count( $submenu[ $parent ] ); // Last, unless Airworthy's own item is found.
		foreach ( array_values( $submenu[ $parent ] ) as $index => $item ) {
			if ( isset( $item[2] ) && Page::SLUG === $item[2] ) {
				$position = $index + 1; // Right after Airworthy.
				break;
			}
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Adding a menu entry is how WordPress expects external links.
		$submenu[ $parent ] = array_merge( array_slice( array_values( $submenu[ $parent ] ), 0, $position ), array( $entry ), array_slice( array_values( $submenu[ $parent ] ), $position ) );
	}

	/**
	 * Makes the menu item stand out a little and open in a new tab.
	 */
	public static function style_menu() {
		if ( ! self::menu_visible() ) {
			return;
		}
		wp_add_inline_style( 'admin-menu', '#adminmenu .wp-submenu li a[href*="utm_campaign=pro-menu"]{color:#8fe3a8 !important;font-weight:600}#adminmenu .wp-submenu li a[href*="utm_campaign=pro-menu"]:hover,#adminmenu .wp-submenu li a[href*="utm_campaign=pro-menu"]:focus{color:#fff !important}' );
		wp_add_inline_script( 'common', 'document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(\'#adminmenu a[href*="utm_campaign=pro-menu"]\').forEach(function(a){a.target="_blank";a.rel="noopener";});});' );
	}

	/**
	 * What the results screen needs to show the card, or null when it shouldn't.
	 *
	 * @return array{url:string}|null
	 */
	public static function config() {
		return self::should_show() ? array( 'url' => self::URL ) : null;
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
	 * POST /pro-card: saves the answer from the results screen.
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
