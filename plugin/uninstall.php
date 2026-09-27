<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: removes all tables, options,
 * cached data and scheduled jobs.
 *
 * @package Airworthy
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/autoload.php';

Airworthy\Installer::uninstall();
