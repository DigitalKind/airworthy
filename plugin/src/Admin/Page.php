<?php
/**
 * The plugin's single admin screen.
 *
 * @package Airworthy
 */

namespace Airworthy\Admin;

use Airworthy\Capabilities;
use Airworthy\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Tools > Airworthy (single site) or Network Admin > Settings > Airworthy (multisite, where
 * the network admin has no Tools menu).
 *
 * The start card is rendered here (the target picker needs PHP's view of the server); the
 * progress and results views are built by assets/admin.js from the REST API
 * (/airworthy/v1). Everything the script shows from a scan is inserted as text, never HTML.
 */
final class Page {

	const SLUG = 'airworthy';

	/**
	 * Hook suffix returned when the page is registered; used to load assets only here.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * URL of the screen.
	 *
	 * @return string
	 */
	public static function url() {
		return is_multisite() ? network_admin_url( 'settings.php?page=' . self::SLUG ) : admin_url( 'tools.php?page=' . self::SLUG );
	}

	/**
	 * Registers menu and asset hooks.
	 */
	public function register() {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the menu item for users with the required capability.
	 */
	public function add_menu() {
		$title = __( 'Airworthy: PHP Upgrade Check', 'airworthy' );
		$menu  = __( 'Airworthy', 'airworthy' );

		if ( is_multisite() ) {
			$this->hook = (string) add_submenu_page( 'settings.php', $title, $menu, Capabilities::required(), self::SLUG, array( $this, 'render' ) );
		} else {
			$this->hook = (string) add_management_page( $title, $menu, Capabilities::required(), self::SLUG, array( $this, 'render' ) );
		}
	}

	/**
	 * Loads the screen's assets on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue( $hook_suffix ) {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'airworthy-admin', AIRWORTHY_URL . 'assets/admin.css', array(), self::asset_version( 'assets/admin.css' ) );
		wp_enqueue_script( 'airworthy-admin', AIRWORTHY_URL . 'assets/admin.js', array( 'wp-api-fetch', 'wp-i18n' ), self::asset_version( 'assets/admin.js' ), true );
		wp_set_script_translations( 'airworthy-admin', 'airworthy' );
		wp_add_inline_script(
			'airworthy-admin',
			'window.airworthyConfig = ' . wp_json_encode(
				array(
					'rest'         => '/airworthy/v1',
					'cronDisabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Asset version: the plugin version, plus the file's modified time in development builds
	 * (so browsers never keep a stale copy while the plugin is being worked on).
	 *
	 * @param string $relative Asset path relative to the plugin folder.
	 * @return string
	 */
	private static function asset_version( $relative ) {
		if ( false === strpos( AIRWORTHY_VERSION, '-dev' ) ) {
			return AIRWORTHY_VERSION;
		}
		return AIRWORTHY_VERSION . '.' . (int) @filemtime( AIRWORTHY_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file: version 0.
	}

	/**
	 * Picker label: version, security-support status, and "recommended" for the default.
	 *
	 * @param string $target  Target version.
	 * @param string $recommended Default (recommended) target.
	 * @return string
	 */
	private static function target_label( $target, $recommended ) {
		$until = Targets::security_until( $target );
		$date  = $until ? date_i18n( get_option( 'date_format' ), strtotime( $until . ' 12:00:00 UTC' ) ) : '';
		if ( ! Targets::is_supported( $target ) ) {
			/* translators: 1: PHP version, 2: date security support ended. */
			$label = sprintf( __( 'PHP %1$s (security fixes ended %2$s)', 'airworthy' ), $target, $date );
		} elseif ( $until ) {
			/* translators: 1: PHP version, 2: date security support ends. */
			$label = sprintf( __( 'PHP %1$s (security fixes until %2$s)', 'airworthy' ), $target, $date );
		} else {
			/* translators: %s: PHP version. */
			$label = sprintf( __( 'PHP %s', 'airworthy' ), $target );
		}
		return $target === $recommended ? $label . ' ' . __( '– recommended', 'airworthy' ) : $label;
	}

	/**
	 * One line explaining why the default target was chosen.
	 *
	 * @param string $current Server PHP version.
	 * @param string $recommended Default (recommended) target.
	 * @return string
	 */
	private static function default_explanation( $current, $recommended ) {
		$current_minor = implode( '.', array_slice( explode( '.', $current ), 0, 2 ) );
		if ( version_compare( $recommended, $current_minor, '<=' ) ) {
			return __( 'Your server already runs the newest PHP version Airworthy can check.', 'airworthy' );
		}
		$oldest = null;
		foreach ( Targets::ALL as $target ) {
			if ( Targets::is_supported( $target ) ) {
				$oldest = $target;
				break;
			}
		}
		if ( $recommended === $oldest ) {
			/* translators: %s: PHP version. */
			return sprintf( __( 'PHP %s is recommended: it is the oldest version that still gets security fixes, so it is the smallest upgrade that keeps your site protected. Pick a newer version to plan further ahead.', 'airworthy' ), $recommended );
		}
		/* translators: %s: PHP version. */
		return sprintf( __( 'PHP %s is recommended: it is the next version up from your server\'s that still gets security fixes. Pick a newer version to plan further ahead.', 'airworthy' ), $recommended );
	}

	/**
	 * Renders the screen: the start card, plus containers the script fills with the progress
	 * and results views.
	 */
	public function render() {
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'airworthy' ), 403 );
		}
		Notice::mark_seen(); // The results are on screen now.

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_count = count( get_plugins() );
		$theme        = wp_get_theme();
		$current      = \Airworthy\Env::php_version();
		$default      = Targets::default_for( $current );
		?>
		<div class="wrap airworthy-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p class="airworthy-lede"><?php esc_html_e( 'Find out which plugins and themes will break on a newer PHP version.', 'airworthy' ); ?></p>
			<p id="airworthy-live" class="screen-reader-text" aria-live="polite" aria-atomic="true"></p>

			<noscript><div class="notice notice-error"><p><?php esc_html_e( 'Airworthy needs JavaScript to run scans and show results.', 'airworthy' ); ?></p></div></noscript>

			<div class="airworthy-card" id="airworthy-start" hidden>
				<dl class="airworthy-facts">
					<dt><?php esc_html_e( 'Server PHP version', 'airworthy' ); ?></dt>
					<dd>
						<strong><?php echo esc_html( $current ); ?></strong>
						<?php if ( ! Targets::is_supported( $current ) ) : ?>
							<span class="airworthy-muted"><?php esc_html_e( '(no longer receives security fixes)', 'airworthy' ); ?></span>
						<?php endif; ?>
					</dd>
					<dt><?php esc_html_e( 'Will be scanned', 'airworthy' ); ?></dt>
					<dd>
						<?php
						if ( is_multisite() ) {
							$theme_count = count( \WP_Theme::get_allowed_on_network() );
							echo esc_html(
								sprintf(
									/* translators: 1: number of plugins, 2: number of themes. */
									__( '%1$s and %2$s', 'airworthy' ),
									/* translators: %d: number of plugins. */
									sprintf( _n( '%d plugin', '%d plugins', $plugin_count, 'airworthy' ), $plugin_count ),
									/* translators: %d: number of themes. */
									sprintf( _n( '%d network-enabled theme (and any parent themes)', '%d network-enabled themes (and any parent themes)', $theme_count, 'airworthy' ), $theme_count )
								)
							);
						} else {
							echo esc_html(
								sprintf(
									/* translators: 1: number of plugins, 2: theme name. */
									_n( '%1$d plugin (active and inactive) and the active theme, %2$s', '%1$d plugins (active and inactive) and the active theme, %2$s', $plugin_count, 'airworthy' ),
									$plugin_count,
									$theme->get( 'Name' )
								)
							);
						}
						?>
					</dd>
					<dt><?php esc_html_e( 'Time needed', 'airworthy' ); ?></dt>
					<dd id="airworthy-estimate" class="airworthy-muted"><?php esc_html_e( 'Working it out…', 'airworthy' ); ?></dd>
				</dl>

				<p class="airworthy-small airworthy-muted"><?php esc_html_e( 'Airworthy only reads files. It never changes your site, your plugins or your server.', 'airworthy' ); ?></p>

				<form class="airworthy-start" id="airworthy-start-form">
					<?php $airworthy_consent = \Airworthy\Wporg\Consent::get(); ?>
					<fieldset class="airworthy-consent">
						<legend><?php esc_html_e( 'Also check WordPress.org?', 'airworthy' ); ?></legend>
						<p class="description"><?php esc_html_e( 'This finds plugins that were removed from WordPress.org, look abandoned, or need a newer PHP version. Airworthy sends each plugin\'s folder name to WordPress.org, and nothing else: not your site\'s address, not your PHP version.', 'airworthy' ); ?></p>
						<label><input type="radio" name="wporg" value="yes" required <?php checked( $airworthy_consent, 'yes' ); ?> /> <?php esc_html_e( 'Yes, check WordPress.org', 'airworthy' ); ?></label>
						<label><input type="radio" name="wporg" value="no" required <?php checked( $airworthy_consent, 'no' ); ?> /> <?php esc_html_e( 'No, only scan the files', 'airworthy' ); ?></label>
					</fieldset>
					<label for="airworthy-target"><?php esc_html_e( 'Check against PHP', 'airworthy' ); ?></label>
					<select id="airworthy-target" name="target" aria-describedby="airworthy-target-why airworthy-ended-note">
						<?php foreach ( Targets::ALL as $target ) : ?>
							<option value="<?php echo esc_attr( $target ); ?>" data-supported="<?php echo Targets::is_supported( $target ) ? '1' : '0'; ?>" <?php selected( $target, $default ); ?>><?php echo esc_html( self::target_label( $target, $default ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button button-primary" id="airworthy-start-button"><?php esc_html_e( 'Start scan', 'airworthy' ); ?></button>
					<button type="button" class="button-link" id="airworthy-start-cancel" hidden><?php esc_html_e( 'Back to results', 'airworthy' ); ?></button>
					<p id="airworthy-target-why" class="description"><?php echo esc_html( self::default_explanation( $current, $default ) ); ?></p>
					<p id="airworthy-ended-note" class="airworthy-note airworthy-note-warning" hidden
						data-template="<?php /* translators: 1: chosen PHP version, 2: recommended PHP version. */ echo esc_attr( sprintf( __( 'PHP %1$s no longer gets security fixes, and results for unsupported versions are less precise. We recommend PHP %2$s instead.', 'airworthy' ), '%1$s', $default ) ); ?>"></p>
					<p id="airworthy-start-error" class="airworthy-note airworthy-note-error" role="alert" hidden></p>
				</form>
			</div>

			<div id="airworthy-progress" hidden></div>
			<div id="airworthy-results" hidden></div>
		</div>
		<?php
	}
}
