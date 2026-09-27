<?php
/**
 * Plugin bootstrap.
 *
 * @package Airworthy
 */

namespace Airworthy;

defined( 'ABSPATH' ) || exit;

/**
 * Wires up hooks. Front-end requests only get the job-handler hook; all work happens in the
 * admin screen, background jobs or WP-CLI.
 */
final class Plugin {

	/**
	 * Registers hooks for the contexts the plugin runs in.
	 */
	public static function boot() {
		// Job handler, registered by name so the queue class (and the scan engine) only load
		// when a job actually runs. Must match Scan\Queue::HOOK.
		add_action( 'airworthy_scan_batch', array( Scan\Queue::class, 'run_batch' ) );
		add_action( 'airworthy_wporg_batch', array( Wporg\Fetcher::class, 'run' ) );

		// REST routes: the controller only loads on REST requests.
		add_action( 'rest_api_init', array( Rest\Controller::class, 'register_routes' ) );

		// `wp airworthy …` commands.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli\Command::register();
		}

		// Re-check plugins and themes in the latest results after WordPress updates them.
		add_action( 'upgrader_process_complete', array( Scan\Queue::class, 'after_upgrade' ), 20, 2 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) );
			( new Admin\Page() )->register();
			Admin\Notice::register();
			Admin\Export::register();
		}
	}
}
