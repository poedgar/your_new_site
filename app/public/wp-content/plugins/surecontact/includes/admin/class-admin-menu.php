<?php
/**
 * Admin Menu class
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact\Admin;

use SureContact\Auth_Manager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Menu class
 *
 * @since 0.0.1
 */
class Admin_Menu {


	/**
	 * Menu slug
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	private $menu_slug = 'surecontact';

	/**
	 * Auth manager instance
	 *
	 * @since 0.0.1
	 *
	 * @var Auth_Manager
	 */
	private $auth_manager;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		$this->auth_manager = new Auth_Manager();

		// Register menu - no need to hook since this is already called within admin_menu hook.
		$this->register_menu();
		// Enqueue our styles/scripts late so our CSS wins over WP admin styles.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 100 );
	}

	/**
	 * Register admin menu
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function register_menu() {
		// Main menu (single page).
		add_menu_page(
			__( 'SureContact', 'surecontact' ),
			__( 'SureContact', 'surecontact' ),
			'manage_options',
			$this->menu_slug . '-dashboard',
			array( $this, 'render_dashboard_page' ),
			$this->get_menu_icon(),
			30
		);

		// Optional: also add a visible Dashboard submenu (mirrors main page).
		add_submenu_page(
			$this->menu_slug . '-dashboard',
			__( 'Dashboard', 'surecontact' ),
			__( 'Dashboard', 'surecontact' ),
			'manage_options',
			$this->menu_slug . '-dashboard',
			array( $this, 'render_dashboard_page' )
		);
	}

	/**
	 * Get menu icon as base64 encoded SVG data URI
	 *
	 * @since 0.0.2
	 *
	 * @return string Base64 encoded SVG data URI
	 */
	private function get_menu_icon() {
		$icon_path = SURECONTACT_PLUGIN_PATH . 'assets/images/icon.svg';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file, not remote URL.
		$svg_content = file_get_contents( $icon_path );

		if ( false === $svg_content ) {
			return 'dashicons-admin-generic';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Used for data URI encoding, not obfuscation.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg_content );
	}

	/**
	 * Enqueue admin assets
	 *
	 * @since 0.0.1
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		// Only load on our plugin pages.
		if ( strpos( $hook, $this->menu_slug ) === false ) {
			return;
		}

		// Enqueue Tailwind CSS.
		wp_enqueue_style(
			'surecontact-tailwind',
			SURECONTACT_PLUGIN_URL . 'assets/css/tailwind.css',
			array(),
			SURECONTACT_VERSION
		);

		// Enqueue the React app. wp-element declares react and react-dom as its
		// dependencies, so WordPress loads the React runtime automatically and the
		// bundle never ships its own copy.
		wp_enqueue_script(
			'surecontact-admin',
			SURECONTACT_PLUGIN_URL . 'assets/js/admin.js',
			array( 'wp-element', 'wp-i18n', 'wp-api-fetch', 'updates' ),
			SURECONTACT_VERSION,
			true
		);

		wp_set_script_translations( 'surecontact-admin', 'surecontact', SURECONTACT_PLUGIN_PATH . 'languages' );

		$current_user     = wp_get_current_user();
		$is_authenticated = $this->auth_manager->is_authenticated();

		// Localize script with configuration data for React app.
		$localized_data = array(
			'ajaxUrl'                      => admin_url( 'admin-ajax.php' ),
			'restUrl'                      => rest_url( 'surecontact/v1/' ),
			'nonce'                        => wp_create_nonce( 'wp_rest' ),
			'_ajax_nonce'                  => wp_create_nonce( 'surecontact_plugin' ),
			'adminUrl'                     => admin_url( 'admin.php' ),
			'siteUrl'                      => home_url(),
			'menuSlug'                     => $this->menu_slug,
			'currentPage'                  => $hook,
			'isAuthenticated'              => $is_authenticated,
			'authUrl'                      => $this->auth_manager->get_auth_url(),
			'pluginsUrl'                   => admin_url( 'plugins.php' ),
			'pluginUrl'                    => SURECONTACT_PLUGIN_URL,
			'hasAuthError'                 => $this->auth_manager->has_auth_error(),
			'saasApiBaseUrl'               => SURECONTACT_SAAS_API_BASE_URL,
			'saasSdkBaseUrl'               => SURECONTACT_SAAS_BASE_URL,
			'pluginInstallationPermission' => current_user_can( 'install_plugins' ) ? '1' : '0',
			'version'                      => SURECONTACT_VERSION,
			'isRtl'                        => is_rtl(),
		);

		// Admin profile fields only pre-fill the in-plugin signup form, which
		// is gated on `! isAuthenticated`. Don't ship PII to sessions that
		// will never render it.
		if ( ! $is_authenticated && $current_user ) {
			$localized_data['adminEmail']     = $current_user->user_email;
			$localized_data['adminFirstName'] = $current_user->first_name;
			$localized_data['adminLastName']  = $current_user->last_name;
		}

		wp_localize_script(
			'surecontact-admin',
			'surecontactAdmin',
			$localized_data
		);
	}

	/**
	 * Render React app container
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function render_react_app() {
		$dir_attr = is_rtl() ? ' dir="' . esc_attr( 'rtl' ) . '"' : '';

		// Check if authenticated.
		if ( ! $this->auth_manager->is_authenticated() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $dir_attr is a static string built above with esc_attr().
			echo '<div id="surecontact-app" data-page="auth"' . $dir_attr . '></div>';
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $dir_attr is a static string built above with esc_attr().
			echo '<div id="surecontact-app"' . $dir_attr . '></div>';
		}
	}

	/**
	 * Render dashboard page
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function render_dashboard_page() {
		$this->render_react_app();
	}
}
