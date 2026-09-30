<?php
/**
 * "Scan finished" admin notice.
 *
 * @package Airworthy
 */

namespace Airworthy\Admin;

use Airworthy\Capabilities;
use Airworthy\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * After a scan finishes in the background, users who can scan see a short notice on other
 * admin screens, linking to the results, until they've looked at them (or dismissed it).
 */
final class Notice {

	/** Site option holding the ID of a finished scan whose results haven't been seen. */
	const OPTION = 'airworthy_unseen_scan';

	/**
	 * Hooks the notice.
	 */
	public static function register() {
		add_action( is_multisite() ? 'network_admin_notices' : 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_airworthy_dismiss_notice', array( __CLASS__, 'dismiss' ) );
	}

	/**
	 * Marks the results as seen (called when the results screen is viewed).
	 */
	public static function mark_seen() {
		delete_site_option( self::OPTION );
	}

	/**
	 * Shows the notice.
	 */
	public static function render() {
		$scan_id = (int) get_site_option( self::OPTION, 0 );
		if ( ! $scan_id || ! Capabilities::current_user_can_scan() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, Page::SLUG ) ) {
			return; // The results are on screen already.
		}
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$scan = $wpdb->get_row( $wpdb->prepare( "SELECT target_php, count_blocker, count_unknown FROM %i WHERE id = %d AND status = 'complete'", $t['scans'], $scan_id ) );
		if ( ! $scan ) {
			return;
		}
		if ( (int) $scan->count_blocker > 0 ) {
			$summary = sprintf(
				/* translators: 1: PHP version, 2: number of plugins/themes. */
				_n( 'Airworthy finished checking your site for PHP %1$s: %2$d plugin or theme needs attention before you upgrade.', 'Airworthy finished checking your site for PHP %1$s: %2$d plugins or themes need attention before you upgrade.', (int) $scan->count_blocker, 'airworthy' ),
				$scan->target_php,
				(int) $scan->count_blocker
			);
		} elseif ( (int) $scan->count_unknown > 0 ) {
			$summary = sprintf(
				/* translators: 1: PHP version, 2: number of plugins/themes. */
				_n( 'Airworthy finished checking your site for PHP %1$s: %2$d plugin or theme could not be fully checked.', 'Airworthy finished checking your site for PHP %1$s: %2$d plugins or themes could not be fully checked.', (int) $scan->count_unknown, 'airworthy' ),
				$scan->target_php,
				(int) $scan->count_unknown
			);
		} else {
			$summary = sprintf(
				/* translators: %s: PHP version. */
				__( 'Airworthy finished checking your site for PHP %s: nothing found that would stop the upgrade.', 'airworthy' ),
				$scan->target_php
			);
		}
		$results = Page::url();
		$dismiss = add_query_arg(
			array(
				'action'   => 'airworthy_dismiss_notice',
				'_wpnonce' => wp_create_nonce( 'airworthy_dismiss_notice' ),
			),
			admin_url( 'admin-post.php' )
		);
		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a> &middot; <a href="%4$s">%5$s</a></p></div>',
			esc_html( $summary ),
			esc_url( $results ),
			esc_html__( 'See the results', 'airworthy' ),
			esc_url( $dismiss ),
			esc_html__( 'Dismiss', 'airworthy' )
		);
	}

	/**
	 * Dismiss link handler.
	 */
	public static function dismiss() {
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'airworthy' ), 403 );
		}
		check_admin_referer( 'airworthy_dismiss_notice' );
		self::mark_seen();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
