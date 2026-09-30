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
					'rest'           => '/airworthy/v1',
					'cronDisabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
					'resultsActions' => self::results_actions(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Extra buttons for the results header, from add-ons (Airworthy Pro's client report).
	 * Each is array( 'label' => …, 'url' => … ) where "__SCAN__" in the URL becomes the scan ID.
	 *
	 * @return array<int,array{label:string,url:string}>
	 */
	private static function results_actions() {
		/**
		 * Filters the extra buttons shown next to Export CSV on the results.
		 *
		 * @param array $actions Each array( 'label' => string, 'url' => string with "__SCAN__" ).
		 */
		$actions = (array) apply_filters( 'airworthy_results_actions', array() );
		$out     = array();
		foreach ( $actions as $a ) {
			if ( is_array( $a ) && ! empty( $a['label'] ) && ! empty( $a['url'] ) ) {
				$out[] = array(
					'label' => (string) $a['label'],
					'url'   => esc_url_raw( (string) $a['url'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Extra tabs on the Airworthy screen, from add-ons: slug => label. Each is drawn by the
	 * "airworthy_render_tab_{slug}" action.
	 *
	 * @return array<string,string>
	 */
	public static function extra_tabs() {
		/**
		 * Filters extra tabs on the Airworthy screen (after Scan and Settings).
		 *
		 * @param array $tabs slug => label.
		 */
		$tabs = (array) apply_filters( 'airworthy_admin_tabs', array() );
		$out  = array();
		foreach ( $tabs as $slug => $label ) {
			$slug = sanitize_key( $slug );
			if ( '' !== $slug && ! in_array( $slug, array( 'scan', 'settings' ), true ) ) {
				$out[ $slug ] = (string) $label;
			}
		}
		return $out;
	}

	/**
	 * Asset version: the plugin version plus the file's modified time. A reinstall of the same
	 * version still gets a new URL, so browsers and CDNs (which often keep CSS and JS for a year)
	 * never serve a stale copy.
	 *
	 * @param string $relative Asset path relative to the plugin folder.
	 * @return string
	 */
	private static function asset_version( $relative ) {
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
		$extra_tabs = self::extra_tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which tab to show.
		$tab          = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'scan';
		$tab          = 'settings' === $tab || isset( $extra_tabs[ $tab ] ) ? $tab : 'scan';
		$components   = \Airworthy\Scan\Components::discover(); // With the site's settings applied.
		$skipped      = \Airworthy\Scan\Components::$last_skipped;
		$plugin_count = count( wp_list_filter( $components, array( 'type' => 'plugin' ) ) );
		$theme        = wp_get_theme();
		$current      = \Airworthy\Env::php_version();
		$default      = Targets::default_for( $current );
		?>
		<div class="wrap airworthy-wrap">
			<div class="airworthy-brand">
				<svg class="airworthy-logo" viewBox="0 0 64 64" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="airworthy-logo-bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#2f74e8"/><stop offset="1" stop-color="#1747a6"/></linearGradient></defs><rect width="64" height="64" rx="15" fill="url(#airworthy-logo-bg)"/><path d="M12 36.5 L23 47 L37.5 29.5" fill="none" stroke="#8fe3a8" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/><g transform="translate(45.5 20) rotate(40) scale(0.5) translate(-32 -32)"><path fill="#ffffff" d="M32 4c2.4 0 3.8 3 3.8 6.5V25l21.2 12.5v5.2L35.8 35.6V48l7.2 5.4V58L32 54.8 21 58v-4.6l7.2-5.4V35.6L7 42.7v-5.2L28.2 25V10.5C28.2 7 29.6 4 32 4z"/></g></svg>
				<div>
					<?php
					/**
					 * Filters a short badge shown after the screen's title (Airworthy Pro shows "Pro").
					 *
					 * @param string $badge Badge text; empty for none.
					 */
					$badge = (string) apply_filters( 'airworthy_admin_badge', '' );
					?>
					<h1><?php echo esc_html( get_admin_page_title() ); ?>
					<?php
					if ( '' !== $badge ) :
						?>
						<span class="airworthy-edition"><?php echo esc_html( $badge ); ?></span><?php endif; ?></h1>
					<p class="airworthy-lede"><?php esc_html_e( 'Before you upgrade PHP, see which plugins and themes are ready, which need fixing, and what to do next.', 'airworthy' ); ?></p>
				</div>
			</div>
			<hr class="wp-header-end" />
			<nav class="nav-tab-wrapper airworthy-tabs" aria-label="<?php esc_attr_e( 'Airworthy', 'airworthy' ); ?>">
				<a href="<?php echo esc_url( self::url() ); ?>" class="nav-tab<?php echo 'scan' === $tab ? ' nav-tab-active' : ''; ?>"<?php echo 'scan' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Scan', 'airworthy' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', self::url() ) ); ?>" class="nav-tab<?php echo 'settings' === $tab ? ' nav-tab-active' : ''; ?>"<?php echo 'settings' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Settings', 'airworthy' ); ?></a>
				<?php foreach ( $extra_tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, self::url() ) ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $slug === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'settings' === $tab ) {
				self::render_settings();
				echo '</div>';
				return;
			}
			if ( 'scan' !== $tab ) {
				/**
				 * Draws an add-on's tab (see the airworthy_admin_tabs filter).
				 */
				do_action( 'airworthy_render_tab_' . $tab );
				echo '</div>';
				return;
			}
			?>
			<p id="airworthy-live" class="screen-reader-text" aria-live="polite" aria-atomic="true"></p>

			<noscript><div class="notice notice-error"><p><?php esc_html_e( 'Airworthy needs JavaScript to run scans and show results.', 'airworthy' ); ?></p></div></noscript>

			<div class="airworthy-card" id="airworthy-start" hidden>
				<h2><?php esc_html_e( 'Check your plugins and theme', 'airworthy' ); ?></h2>
				<dl class="airworthy-facts">
					<dt><?php esc_html_e( 'Server PHP version', 'airworthy' ); ?></dt>
					<dd>
						<strong><?php echo esc_html( $current ); ?></strong>
						<?php $airworthy_support = Targets::support_status( $current ); ?>
						<?php if ( $airworthy_support ) : ?>
							<span class="airworthy-support airworthy-support-<?php echo esc_attr( $airworthy_support['level'] ); ?>"><?php echo esc_html( $airworthy_support['text'] ); ?></span>
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
						} elseif ( \Airworthy\Settings::get( 'skip_inactive' ) ) {
							echo esc_html(
								sprintf(
									/* translators: 1: number of plugins, 2: theme name. */
									_n( '%1$d active plugin and the active theme, %2$s', '%1$d active plugins and the active theme, %2$s', $plugin_count, 'airworthy' ),
									$plugin_count,
									$theme->get( 'Name' )
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
						if ( $skipped['inactive'] || $skipped['ignored'] ) {
							echo '<br /><span class="airworthy-muted">';
							echo esc_html( self::skipped_text( $skipped ) );
							echo ' <a href="' . esc_url( add_query_arg( 'tab', 'settings', self::url() ) ) . '">' . esc_html__( 'Change', 'airworthy' ) . '</a></span>';
						}
						?>
					</dd>
					<dt><?php esc_html_e( 'Time needed', 'airworthy' ); ?></dt>
					<dd id="airworthy-estimate" class="airworthy-muted"><?php esc_html_e( 'Working it out…', 'airworthy' ); ?></dd>
				</dl>

				<p class="airworthy-small airworthy-muted"><?php esc_html_e( 'Airworthy only reads files. It never changes your site, your plugins or your server. The results help you plan but are not a guarantee: always back up your site and test on a staging copy before you change your PHP version.', 'airworthy' ); ?></p>

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
					<?php $airworthy_from = \Airworthy\Targets::minor( $current ); ?>
					<details class="airworthy-from">
						<summary><?php esc_html_e( 'Compare from another PHP version', 'airworthy' ); ?></summary>
						<label for="airworthy-from"><?php esc_html_e( 'Your site runs PHP', 'airworthy' ); ?></label>
						<select id="airworthy-from">
							<?php
							$airworthy_versions = array_unique( array_merge( array( '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' ), array( $airworthy_from ) ) );
							usort( $airworthy_versions, 'version_compare' );
							foreach ( $airworthy_versions as $airworthy_version ) :
								?>
								<option value="<?php echo esc_attr( $airworthy_version ); ?>" <?php selected( $airworthy_version, $airworthy_from ); ?>>
									<?php
									echo esc_html(
										$airworthy_version === $airworthy_from ?
										/* translators: %s: PHP version. */
										sprintf( __( 'PHP %s (this server)', 'airworthy' ), $airworthy_version ) :
										/* translators: %s: PHP version. */
										sprintf( __( 'PHP %s', 'airworthy' ), $airworthy_version )
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Problems from PHP changes up to this version already apply to your site today, so they aren\'t counted as Blockers. Change it when you check a copy of a site that runs on another server.', 'airworthy' ); ?></p>
					</details>
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

	/**
	 * "5 inactive plugins and 2 ignored ones are left out by your settings."
	 *
	 * @param array{inactive:int,ignored:int} $skipped Counts.
	 * @return string
	 */
	public static function skipped_text( array $skipped ) {
		$parts = array();
		if ( $skipped['inactive'] ) {
			/* translators: %d: number of plugins. */
			$parts[] = sprintf( _n( '%d inactive plugin', '%d inactive plugins', $skipped['inactive'], 'airworthy' ), $skipped['inactive'] );
		}
		if ( $skipped['ignored'] ) {
			/* translators: %d: number of plugins and themes. */
			$parts[] = sprintf( _n( '%d ignored plugin or theme', '%d ignored plugins and themes', $skipped['ignored'], 'airworthy' ), $skipped['ignored'] );
		}
		/* translators: %s: e.g. "5 inactive plugins and 2 ignored plugins and themes". */
		return sprintf( __( 'Left out by your settings: %s.', 'airworthy' ), implode( __( ' and ', 'airworthy' ), $parts ) );
	}

	/**
	 * The Settings tab: scan speed, inactive plugins, ignore list.
	 */
	private static function render_settings() {
		$settings = \Airworthy\Settings::all();
		$all      = \Airworthy\Scan\Components::discover( false );
		usort(
			$all,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		$speeds = array(
			'gentle' => array( __( 'Gentle', 'airworthy' ), __( 'Short batches with pauses in between. Takes longer; easiest on cheap shared hosting.', 'airworthy' ) ),
			'normal' => array( __( 'Normal (recommended)', 'airworthy' ), __( 'Batches of up to 20 seconds, one after another.', 'airworthy' ) ),
			'fast'   => array( __( 'Fast', 'airworthy' ), __( 'Longer batches, for servers with room to spare. Still stops well before your server\'s time limit.', 'airworthy' ) ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows the saved message.
		$updated = isset( $_GET['updated'] );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="airworthy-card airworthy-settings" data-autosave="1">
			<input type="hidden" name="action" value="<?php echo esc_attr( \Airworthy\Settings::ACTION ); ?>" />
			<?php wp_nonce_field( \Airworthy\Settings::ACTION ); ?>
			<div class="airworthy-settings-head">
				<h2><?php esc_html_e( 'Settings', 'airworthy' ); ?></h2>
				<p class="airworthy-save-status" role="status" aria-live="polite"></p>
			</div>
			<p class="description airworthy-autosave-note" hidden><?php esc_html_e( 'Changes save as you make them, and apply from your next scan.', 'airworthy' ); ?></p>
			<?php if ( $updated ) : ?>
				<p class="airworthy-note" role="status"><?php esc_html_e( 'Settings saved. They apply from your next scan.', 'airworthy' ); ?></p>
			<?php endif; ?>

			<fieldset class="airworthy-setting">
				<legend><?php esc_html_e( 'Scan speed', 'airworthy' ); ?></legend>
				<?php foreach ( $speeds as $value => $speed ) : ?>
					<label class="airworthy-choice">
						<input type="radio" name="airworthy[speed]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['speed'], $value ); ?> />
						<span><strong><?php echo esc_html( $speed[0] ); ?></strong> <span class="airworthy-muted"><?php echo esc_html( $speed[1] ); ?></span></span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<fieldset class="airworthy-setting">
				<legend><?php esc_html_e( 'Inactive plugins', 'airworthy' ); ?></legend>
				<label class="airworthy-choice">
					<input type="checkbox" name="airworthy[skip_inactive]" value="1" <?php checked( $settings['skip_inactive'] ); ?> />
					<span><strong><?php esc_html_e( 'Skip inactive plugins', 'airworthy' ); ?></strong> <span class="airworthy-muted"><?php esc_html_e( 'Inactive plugins don\'t run, so they can\'t break your site today. They would if you turned them back on, though, and their files stay on your server. If you don\'t use a plugin, deleting it is better than skipping it.', 'airworthy' ); ?></span></span>
				</label>
			</fieldset>

			<fieldset class="airworthy-setting">
				<legend><?php esc_html_e( 'Never check these', 'airworthy' ); ?></legend>
				<p class="description"><?php esc_html_e( 'For code you look after yourself, or are about to remove. Ignored plugins and themes aren\'t checked, and aren\'t looked up on WordPress.org.', 'airworthy' ); ?></p>
				<div class="airworthy-ignore-list">
					<?php foreach ( $all as $c ) : ?>
						<?php $key = $c['type'] . ':' . $c['slug']; ?>
						<label class="airworthy-choice">
							<input type="checkbox" name="airworthy[ignore][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $settings['ignore'], true ) ); ?> />
							<span>
								<?php echo esc_html( $c['name'] ); ?>
								<?php if ( 'theme' === $c['type'] ) : ?>
									<span class="airworthy-muted"><?php esc_html_e( '· theme', 'airworthy' ); ?></span>
								<?php elseif ( ! $c['is_active'] ) : ?>
									<span class="airworthy-muted"><?php esc_html_e( '· inactive', 'airworthy' ); ?></span>
								<?php endif; ?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<p class="airworthy-save-row"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'airworthy' ); ?></button></p>
		</form>
		<?php
	}
}
