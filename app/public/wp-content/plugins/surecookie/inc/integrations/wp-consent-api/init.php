<?php
/**
 * WP Consent API - Integration Initialization
 *
 * Bootstraps the WP Consent API integration.
 * Only activates when the WP Consent API plugin is installed and active.
 *
 * @package SureCookie
 * @since 0.0.1-beta.1
 */

namespace SureCookie\Inc\Integrations\WpConsentApi;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init class.
 *
 * Initializes the WP Consent API integration.
 *
 * @since 0.0.1-beta.1
 */
class Init {
	use GetInstance;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1-beta.1
	 */
	private function __construct() {
		// Only initialize if WP Consent API plugin is active.
		if ( ! self::is_wp_consent_api_active() ) {
			return;
		}

		// Always on by design: installing the WP Consent API plugin IS the
		// opt-in. A partial toggle shipped dead for several releases (issue
		// #863, a compliance-audit "false off"), so the setting was removed
		// entirely rather than wired - consent state must bridge seamlessly
		// or not exist as an option at all.

		// Register SureCookie as a Consent Management Platform.
		$this->register_as_cmp();

		// Initialize actions (cookie declarations, category map).
		Actions::get_instance();

		// Initialize consent handler (server-side consent state bridge).
		Consent_Handler::get_instance();

		/**
		 * Action: Fires after WP Consent API integration is initialized.
		 *
		 * @since 0.0.1-beta.1
		 */
		do_action( 'surecookie_wp_consent_api_initialized' );
	}

	/**
	 * Return the consent type for WP Consent API.
	 *
	 * Maps SureCookie's consent_model setting to WP Consent API's consent type.
	 * - 'opt-in' (GDPR) → 'optin' (deny by default)
	 * - 'opt-out' (CCPA) → 'optout' (allow by default)
	 *
	 * @return string 'optin' or 'optout'.
	 * @since 0.0.1-beta.1
	 */
	public function get_consent_type(): string {
		$consent_model = Consent_Handler::get_active_consent_model();

		if ( $consent_model === 'opt-out' ) {
			return 'optout';
		}

		return 'optin';
	}

	/**
	 * Check if WP Consent API plugin is active.
	 *
	 * @return bool True if WP Consent API functions are available.
	 * @since 0.0.1-beta.1
	 */
	public static function is_wp_consent_api_active(): bool {
		return class_exists( 'WP_Consent_API' );
	}

	/**
	 * Register SureCookie as a Consent Management Platform with WP Consent API.
	 *
	 * Two things are required for proper registration:
	 * 1. `wp_consent_api_registered_{basename}` filter - tells WP Consent API
	 *    that SureCookie is the active CMP.
	 * 2. `wp_get_consent_type` filter - declares the consent model (optin/optout).
	 *    Without this, WP Consent API defaults to 'optout' which means
	 *    wp_has_consent() returns true even before the user gives consent.
	 *
	 * @return void
	 * @since 0.0.1-beta.1
	 */
	private function register_as_cmp(): void {
		$plugin_basename = plugin_basename( SURECOOKIE_FILE );
		add_filter( 'wp_consent_api_registered_' . $plugin_basename, '__return_true' );

		// Declare consent type so WP Consent API knows the default behavior.
		// SureCookie defaults to opt-in (GDPR-style: deny all until user consents).
		add_filter( 'wp_get_consent_type', [ $this, 'get_consent_type' ] );
	}
}
