<?php
/**
 * Google Consent Mode - Module Initialization
 *
 * Bootstraps the Google Consent Mode v2 module.
 *
 * @package SureCookie
 * @since 0.0.0-alpha.1
 */

namespace SureCookie\Inc\Modules\GoogleConsentMode;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init class.
 *
 * Initializes the Google Consent Mode module.
 *
 * @since 0.0.0-alpha.1
 */
class Init {
	use GetInstance;

	/**
	 * Constructor.
	 *
	 * @since 0.0.0-alpha.1
	 */
	private function __construct() {
		// Initialize required hooks (always initialize actions).
		Actions::get_instance();

		// Initialize Whitelist_Handler (always needed to hook into script blocking).
		// This prevents Google scripts from being blocked when GCM is active.
		Whitelist_Handler::get_instance();

		// Only initialize if feature is enabled.
		if ( ! Settings::get( 'gcm_enabled' ) ) {
			return;
		}

		// Initialize Consent_Handler (handles script output).
		Consent_Handler::get_instance();

		// Register hooks for extensibility.
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks for extensibility.
	 *
	 * @return void
	 * @since 0.0.0-alpha.1
	 */
	private function register_hooks(): void {
		/**
		 * Action: Fires after Google Consent Mode module is initialized.
		 *
		 * @since 0.0.0-alpha.1
		 */
		do_action( 'surecookie_google_consent_mode_initialized' );
	}
}
