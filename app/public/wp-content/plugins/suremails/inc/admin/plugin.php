<?php
/**
 * SureMails Plugin Class
 *
 * This file contains the main admin class for the SureMails plugin.
 *
 * @package SureMails\Admin
 */

namespace SureMails\Inc\Admin;

use SureMails\Inc\API\RecommendedPlugin;
use SureMails\Inc\Emails\Providers\SURECONTACT\SurecontactHandler;
use SureMails\Inc\Onboarding;
use SureMails\Inc\Settings;
use SureMails\Inc\Traits\Instance;
use SureMails\Inc\Utils\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Plugin
 *
 * Main class for the SureMails Plugin admin functionalities.
 */
class Plugin {
	use Instance;

	/**
	 * Plugin initialization function.
	 */
	protected function __construct() {
		// Hook into WordPress actions and filters.
		add_action( 'admin_init', [ $this, 'activation_redirect' ] );
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_notice_scripts' ] );
		add_action( 'admin_notices', [ $this, 'check_configuration' ] );
		add_action( 'admin_head', [ $this, 'hide_duplicate_menu_css' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_menu_location_notice_scripts' ] );

		// Add settings link to the plugin action links.
		add_filter( 'plugin_action_links_' . SUREMAILS_BASE, [ $this, 'add_settings_link' ] );
	}

	/**
	 * Plugin initialization function.
	 *
	 * @return void
	 */
	public function activation_redirect() {
		// Avoid redirection in case of WP_CLI calls.
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			return;
		}

		// Avoid redirection in case of ajax calls.
		if ( wp_doing_ajax() ) {
			return;
		}

		$do_redirect = apply_filters( 'suremails_enable_redirect_activation', get_option( 'suremails_do_redirect' ) );

		if ( $do_redirect ) {

			update_option( 'suremails_do_redirect', false );

			if ( ! is_multisite() ) {
				$page = SUREMAILS;

				// Check if the user completed onboarding setup.
				$done_onboarding_setup = Onboarding::instance()->get_onboarding_status();
				// Check if the user has any connections (For old users).
				$connections = Settings::instance()->get_settings( 'connections' );

				if ( ! $done_onboarding_setup && ( empty( $connections ) || count( $connections ) === 0 ) ) {
					$page = SUREMAILS . '#/onboarding';
				}

				wp_safe_redirect(
					Utils::get_admin_url( str_replace( SUREMAILS, '', $page ) )
				);
				exit;
			}
		}
	}

	/**
	 * Check if the plugin is configured correctly and display a notice if not.
	 *
	 * @return void
	 */
	public function check_configuration() {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// If notice is disabled (within expiry), do not show.
		if ( $this->is_notice_disabled() ) {
			return;
		}

		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$options      = Settings::instance()->get_settings();

		if ( ! empty( $options['connections'] ) || $current_page === SUREMAILS ) {
			return;
		}

		?>
			<div id="suremails-admin-notice" class="notice notice-warning is-dismissible">
			</div>
		<?php
	}

	/**
	 * Enqueue admin notice scripts.
	 *
	 * @return void
	 */
	public function enqueue_admin_notice_scripts() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// If notice is disabled (within expiry), do not enqueue.
		if ( $this->is_notice_disabled() ) {
			return;
		}

		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$options      = Settings::instance()->get_settings();
		// If the user is on the SureMails settings page or there are connections, don't show the notice.
		if ( ! empty( $options['connections'] ) || $current_page === SUREMAILS ) {
			return;
		}

		$assets = require_once SUREMAILS_DIR . 'build/admin-notice.asset.php';

		if ( ! isset( $assets ) ) {
			return;
		}

		wp_register_script(
			'suremails-admin-notice',
			SUREMAILS_PLUGIN_URL . 'build/admin-notice.js',
			[ 'wp-element', 'wp-dom-ready', 'wp-i18n', 'wp-api-fetch' ],
			$assets['version'],
			true
		);

		wp_enqueue_script(
			'suremails-admin-notice',
			SUREMAILS_PLUGIN_URL . 'build/admin-notice.js',
			[ 'wp-element', 'wp-dom-ready', 'wp-i18n' ],
			$assets['version'],
			true
		);

		wp_enqueue_style(
			'suremails-admin-notice',
			SUREMAILS_PLUGIN_URL . 'build/admin-notice.css',
			[],
			$assets['version'],
		);

		wp_localize_script(
			'suremails-admin-notice',
			'suremailsNotice',
			[
				'dashboardUrl'  => esc_url( Utils::get_admin_url( '/dashboard' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'onboardingURL' => Utils::get_admin_url( '/onboarding/welcome' ),
			]
		);

		// Set the script translations.
		wp_set_script_translations( 'suremails-admin-notice', 'suremails', SUREMAILS_DIR . 'languages' );
	}

	/**
	 * Add settings page to the WordPress admin menu.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		if ( Utils::is_sidebar_enabled() ) {

			add_menu_page(
				__( 'SureMail Settings', 'suremails' ),
				__( 'SureMail SMTP', 'suremails' ),
				'manage_options',
				SUREMAILS,
				[ $this, 'render_suremails_frontend' ],
				'dashicons-email-alt',
				30
			);

			// Add submenu items using helper function.
			$this->add_suremails_submenus();
		} else {
			add_options_page(
				__( 'SureMail Settings', 'suremails' ),
				__( 'SureMail SMTP', 'suremails' ),
				'manage_options',
				SUREMAILS,
				[ $this, 'render_suremails_frontend' ]
			);
		}
	}

	/**
	 * Enqueue admin scripts and styles for the SureMails settings page.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Check if we're on a SureMails admin page.
		if ( ! $this->is_suremails_admin_page( $hook ) ) {
			return;
		}

		$assets = require_once SUREMAILS_DIR . '/build/main.asset.php';

		if ( ! isset( $assets ) ) {
			return;
		}

		wp_register_script(
			'suremails-react-script',
			SUREMAILS_PLUGIN_URL . 'build/main.js',
			[ 'wp-api-fetch', 'wp-components', 'wp-i18n', 'wp-hooks', 'updates' ],
			$assets['version'],
			true
		);

		// Enqueue your custom React script.
		wp_enqueue_script(
			'suremails-react-script',
			SUREMAILS_PLUGIN_URL . 'build/main.js', // Adjust the path if necessary.
			[ 'wp-element', 'wp-api-fetch', 'wp-dom-ready', 'wp-api', 'wp-components', 'wp-i18n', 'wp-hooks' ],
			$assets['version'],
			true // Load in footer.
		);

		wp_enqueue_script( 'suremails-suretriggers-integration', 'https://app.ottokit.com/js/v2/embed.js', [], SUREMAILS_VERSION, true );

		// RTL checks.
		$rtl_suffix = is_rtl() ? '-rtl' : '';
		$file_name  = 'main' . $rtl_suffix . '.css';

		// Enqueue your custom styles.
		wp_enqueue_style(
			'suremails-react-styles',
			SUREMAILS_PLUGIN_URL . 'build/' . $file_name,
			[],
			$assets['version'],
		);

		$current_user = wp_get_current_user();
		$user_details = Settings::instance()->get_user_details();

		if ( ! is_array( $user_details ) ) {
			$user_details = [];
		}

		$analytics_optin            = get_option( 'suremails_usage_optin', 'no' );
		$content_guard_user_details = [
			'first_name'     => isset( $user_details['first_name'] ) ? sanitize_text_field( $user_details['first_name'] ) : '',
			'last_name'      => isset( $user_details['last_name'] ) ? sanitize_text_field( $user_details['last_name'] ) : '',
			'email'          => isset( $user_details['email'] ) ? sanitize_email( $user_details['email'] ) : '',
			'agree_to_terms' => isset( $user_details['agree_to_terms'] ) ? (bool) $user_details['agree_to_terms'] : ( 'yes' === $analytics_optin ),
			'skip'           => isset( $user_details['skip'] ) ? sanitize_text_field( $user_details['skip'] ) : 'no',
			'lead'           => ! empty( $user_details['lead'] ),
		];

		// Localize script to pass data to React.
		wp_localize_script(
			'suremails-react-script',
			'suremails',
			[
				'currentUser'                           => [
					'firstName' => sanitize_text_field( (string) ( $current_user->first_name ?? '' ) ),
					'lastName'  => sanitize_text_field( (string) ( $current_user->last_name ?? '' ) ),
					'email'     => sanitize_email( (string) ( $current_user->user_email ?? '' ) ),
				],
				'siteUrl'                               => esc_url( get_site_url( get_current_blog_id() ) ),
				'attachmentUrl'                         => $this->get_attachment_url(),
				'userEmail'                             => sanitize_email( (string) ( $current_user->user_email ?? '' ) ),
				'version'                               => SUREMAILS_VERSION,
				'nonce'                                 => current_user_can( 'manage_options' ) ? wp_create_nonce( 'wp_rest' ) : '',
				'_ajax_nonce'                           => current_user_can( 'manage_options' ) ? wp_create_nonce( 'suremails_plugin' ) : '',
				'contentGuardPopupStatus'               => Settings::instance()->show_content_guard_lead_popup(),
				'contentGuardActiveStatus'              => get_option( 'suremails_content_guard_activated', 'no' ),
				'contentGuardUserDetails'               => $content_guard_user_details,
				'termsURL'                              => 'https://suremails.com/terms?utm_campaign=suremails&utm_medium=suremails-dashboard',
				'privacyPolicyURL'                      => 'https://suremails.com/privacy-policy?utm_campaign=suremails&utm_medium=suremails-dashboard',
				'docsURL'                               => 'https://suremails.com/docs?utm_campaign=suremails&utm_medium=suremails-dashboard',
				'supportURL'                            => 'https://suremails.com/contact/?utm_campaign=suremails&utm_medium=suremails-dashboard',
				'adminURL'                              => Utils::get_admin_url(),
				'ottokit_connected'                     => apply_filters( 'suretriggers_is_user_connected', '' ),
				'ottokit_admin_url'                     => admin_url( 'admin.php?page=suretriggers' ),
				'pluginInstallationPermission'          => current_user_can( 'install_plugins' ),
				'onboardingCompleted'                   => Onboarding::instance()->get_onboarding_status(),
				'recommendedPluginsData'                => RecommendedPlugin::get_recommended_plugins_sequence(),
				'surecontactBillingUrl'                 => SurecontactHandler::billing_url(),
				'surecontactSendingDomainsUrl'          => SurecontactHandler::sending_domains_url(),
				'surecontactPluginUrl'                  => apply_filters( 'suremails_surecontact_plugin_url', 'https://wordpress.org/plugins/surecontact' ),
				'surecontactPromoDismissed'             => $this->is_surecontact_promo_dismissed(),
				'surecontactSmtpPromoDismissed'         => $this->is_surecontact_smtp_promo_dismissed(),
				'paymentLogosUrl'                       => SUREMAILS_PLUGIN_URL . 'assets/images/payment-logos.png',
			]
		);

		// Set the script translations.
		wp_set_script_translations( 'suremails-react-script', 'suremails', SUREMAILS_DIR . 'languages' );

		// Hide duplicate main menu item in submenu.
		wp_add_inline_style(
			'suremails-react-styles',
			'
			#adminmenu .toplevel_page_' . SUREMAILS . ' .wp-submenu li.wp-first-item,
			#adminmenu .toplevel_page_' . SUREMAILS . ' .wp-submenu li.wp-first-item a {
				display: none !important;
			}
		'
		);
	}

	/**
	 * Render the React application inside the SureMails settings page.
	 *
	 * @return void
	 */
	public function render_suremails_frontend() {
		echo '<div id="suremails-root-app"></div>';
	}

	/**
	 * Add a "Settings" and a "Setup Wizard" link on the Plugins page.
	 *
	 * @param array<int|string, string> $links Existing plugin action links.
	 * @return array<int|string, string> Updated plugin action links.
	 */
	public function add_settings_link( array $links ) {

		$settings_url = Utils::get_admin_url( 'settings' );
		$links[]      = '<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'suremails' ) . '</a>';

		$wizard_url = Utils::get_admin_url( '/onboarding/welcome' );
		$links[]    = '<a href="' . esc_url( $wizard_url ) . '">' . __( 'Setup Wizard', 'suremails' ) . '</a>';

		return $links;
	}

	/**
	 * Hide duplicate menu item with CSS in admin head.
	 *
	 * @return void
	 */
	public function hide_duplicate_menu_css() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only apply these styles if showing in sidebar.
		if ( Utils::is_sidebar_enabled() ) {
			?>
			<style>
				#adminmenu .toplevel_page_<?php echo esc_attr( SUREMAILS ); ?> .wp-submenu li.wp-first-item,
				#adminmenu .toplevel_page_<?php echo esc_attr( SUREMAILS ); ?> .wp-submenu li.wp-first-item a {
					display: none !important;
				}
			</style>
			<?php
		}
	}

	/**
	 * Enqueue menu location notice scripts and styles.
	 *
	 * @return void
	 */
	public function enqueue_menu_location_notice_scripts() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// If notice is disabled (dismissed), do not enqueue.
		if ( $this->is_menu_notice_disabled() ) {
			return;
		}

		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Don't enqueue on SureMails pages.
		if ( $current_page === SUREMAILS ) {
			return;
		}

		// Use the same admin-notice script for menu location notice.
		$assets = require SUREMAILS_DIR . 'build/admin-notice.asset.php';

		if ( ! isset( $assets ) ) {
			return;
		}

		wp_enqueue_script(
			'suremails-admin-notice',
			SUREMAILS_PLUGIN_URL . 'build/admin-notice.js',
			[ 'wp-element', 'wp-dom-ready', 'wp-i18n' ],
			$assets['version'],
			true
		);

		wp_enqueue_style(
			'suremails-admin-notice',
			SUREMAILS_PLUGIN_URL . 'build/admin-notice.css',
			[],
			$assets['version'],
		);

		// Localize script for menu location notice.
		wp_localize_script(
			'suremails-admin-notice',
			'suremailsMenuNotice',
			[
				'settingsUrl' => esc_url( Utils::get_admin_url( 'settings' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
			]
		);

		// Set the script translations.
		wp_set_script_translations( 'suremails-admin-notice', 'suremails', SUREMAILS_DIR . 'languages' );
	}

	/**
	 * Add SureMails submenu items.
	 *
	 * @return void
	 */
	private function add_suremails_submenus() {
		$submenu_items = [
			[
				'title' => __( 'Dashboard', 'suremails' ),
				'path'  => '/dashboard',
			],
			[
				'title' => __( 'Settings', 'suremails' ),
				'path'  => '/settings',
			],
			[
				'title' => __( 'Connections', 'suremails' ),
				'path'  => '/connections',
			],
			[
				'title' => __( 'Email Logs', 'suremails' ),
				'path'  => '/logs',
			],
			[
				'title' => __( 'Notifications', 'suremails' ),
				'path'  => '/notifications',
			],
		];

		foreach ( $submenu_items as $item ) {
			add_submenu_page(
				SUREMAILS,
				$item['title'],
				$item['title'],
				'manage_options',
				SUREMAILS . '#' . $item['path'],
				[ $this, 'render_suremails_frontend' ]
			);
		}
	}

	/**
	 * Get the attachment URL.
	 * This is used to display the attachment in the email log. The attachment URL is used to display the attachment in the email log.
	 * The attachment URL is different for multisite and single site installations. For multisite, the attachment URL is based on the current blog ID.
	 *
	 * @return string
	 */
	private function get_attachment_url() {

		$attachment_base_url = '';
		if ( is_multisite() ) {
			$current_blog_id     = get_current_blog_id();
			$attachment_base_url = esc_url( get_site_url( $current_blog_id ) ) . '/wp-content/uploads/sites/' . $current_blog_id . '/suremails/attachments/';
		} else {
			$attachment_base_url = esc_url( get_site_url() ) . '/wp-content/uploads/suremails/attachments/';
		}
		return $attachment_base_url;
	}

	/**
	 * Check if the current page is a SureMails admin page.
	 *
	 * @param string $hook The page hook.
	 * @return bool True if on a SureMails page, false otherwise.
	 */
	private function is_suremails_admin_page( $hook ) {
		if ( Utils::is_sidebar_enabled() ) {
			// Top-level menu page.
			if ( $hook === 'toplevel_page_' . SUREMAILS ) {
				return true;
			}

			// Submenu pages.
			$submenu_hooks = [
				'suremails_page_' . SUREMAILS . '#/dashboard',
				'suremails_page_' . SUREMAILS . '#/settings',
				'suremails_page_' . SUREMAILS . '#/connections',
				'suremails_page_' . SUREMAILS . '#/logs',
				'suremails_page_' . SUREMAILS . '#/notifications',
				'suremails_page_' . SUREMAILS . '#/add-ons',
			];

			return in_array( $hook, $submenu_hooks, true );
		}
			// Settings submenu page (default).
			return $hook === 'settings_page_' . SUREMAILS;
	}

	/**
	 * Check if the notice is currently disabled.
	 *
	 * @return bool True if notice is disabled (within expiry), false if notice should be shown.
	 */
	private function is_notice_disabled() {
		$notice_expiry = get_option( 'suremails_notice_dismissal_time', 0 );
		if ( ! $notice_expiry ) {
			return false; // No expiry set, so notice is not disabled.
		}

		// Check if the current time is greater than or equal to the notice expiry time.
		if ( time() >= $notice_expiry ) {
			// Expired: remove the option so notice can be shown next time.
			delete_option( 'suremails_notice_dismissal_time' );
			return false; // Notice is NOT disabled anymore.
		}

		// Still within disabled period.
		return true; // Notice is disabled.
	}

	/**
	 * Check if the menu location notice is currently disabled.
	 *
	 * @return bool True if notice is disabled (dismissed), false if notice should be shown.
	 */
	private function is_menu_notice_disabled() {
		return (bool) get_option( 'suremails_menu_notice_dismissed', false );
	}

	/**
	 * Check if the SureContact cross-sell promo is currently dismissed.
	 *
	 * Mirrors is_notice_disabled(): the dismissal lasts 15 days and is cleared
	 * once expired so the promo can resurface.
	 *
	 * @return bool True if the promo is dismissed (within the 15-day window).
	 */
	private function is_surecontact_promo_dismissed() {
		$expiry = get_option( 'suremails_surecontact_promo_dismissal_time', 0 );
		if ( ! $expiry ) {
			return false;
		}

		if ( time() >= $expiry ) {
			delete_option( 'suremails_surecontact_promo_dismissal_time' );
			return false;
		}

		return true;
	}

	/**
	 * Check if the SureContact SMTP launch promo is currently dismissed.
	 *
	 * Mirrors is_surecontact_promo_dismissed(): the dismissal lasts 15 days and
	 * is cleared once expired so the promo can resurface.
	 *
	 * @return bool True if the promo is dismissed (within the 15-day window).
	 */
	private function is_surecontact_smtp_promo_dismissed() {
		$expiry = get_option( 'suremails_surecontact_smtp_promo_dismissal_time', 0 );
		if ( ! $expiry ) {
			return false;
		}

		if ( time() >= $expiry ) {
			delete_option( 'suremails_surecontact_smtp_promo_dismissal_time' );
			return false;
		}

		return true;
	}
}

// Instantiate the singleton instance of Plugin.
Plugin::instance();
