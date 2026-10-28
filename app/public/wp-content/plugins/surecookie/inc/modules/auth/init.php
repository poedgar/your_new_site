<?php
/**
 * Auth Module Init
 *
 * Handles the initialization and hooks for SureCookie Auth functionality.
 *
 * @package SureCookie\Inc\Modules\Auth
 * @since 0.0.1-beta.3
 */

namespace SureCookie\Inc\Modules\Auth;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init class
 *
 * Handles initialization and WordPress hooks for authentication.
 */
class Init {
	use GetInstance;

	/**
	 * Constructor
	 *
	 * @since 0.0.1-beta.3
	 */
	private function __construct() {
		if ( ! defined( 'SURECOOKIE_BILLING_PORTAL' ) ) {
			define( 'SURECOOKIE_BILLING_PORTAL', 'https://my.surecookie.com/' );
		}

		Controller::get_instance();

		// Hook into the filter to add this module's API controller.
		add_filter( 'surecookie_api_controllers', [ $this, 'register_api_controller' ], 20 );

		// Add localization variables.
		add_filter( 'surecookie_localized_admin_data', [ $this, 'add_admin_localization_vars' ] );
		add_filter( 'surecookie_onboarding_localize_data', [ $this, 'add_onboarding_localization_vars' ] );

		// Inbound POST callback from billing portal popup (issue #466).
		// Registered here (not in Api class) because admin-post.php requests
		// don't trigger rest_api_init. nopriv is required because SameSite=Lax
		// auth cookies aren't sent on cross-origin POST.
		add_action( 'admin_post_' . Api::CALLBACK_ACTION, [ $this, 'handle_inbound_callback' ] );
		add_action( 'admin_post_nopriv_' . Api::CALLBACK_ACTION, [ $this, 'handle_inbound_callback' ] );
	}

	/**
	 * Delegate to Api::handle_inbound_callback() - kept here so the hook
	 * registers on every request, not just rest_api_init.
	 *
	 * @since 0.0.1-beta.3
	 */
	public function handle_inbound_callback(): void {
		Api::get_instance()->handle_inbound_callback();
	}

	/**
	 * Add admin localization variables.
	 *
	 * @since 0.0.1-beta.3
	 * @param array<string, mixed> $variables Localization variables.
	 * @return array<string, mixed> Localization variables.
	 */
	public function add_admin_localization_vars( $variables = [] ) {
		$controller = Controller::get_instance();
		return array_merge(
			$variables,
			[
				'ai_authenticated' => $controller->get_auth_status(),
				'ai_auth_email'    => $controller->get_auth_email(),
			]
		);
	}

	/**
	 * Add onboarding localization variables.
	 *
	 * @since 0.0.1-beta.3
	 * @param array<string, mixed> $variables Localization variables.
	 * @return array<string, mixed> Localization variables.
	 */
	public function add_onboarding_localization_vars( $variables = [] ) {
		return array_merge(
			$variables,
			[
				'ai_authenticated' => Controller::get_instance()->get_auth_status(),
			]
		);
	}

	/**
	 * Register API controller for this module.
	 *
	 * @since 0.0.1-beta.3
	 * @param array<string> $controllers Existing controllers.
	 * @return array<string> Updated controllers.
	 */
	public function register_api_controller( $controllers = [] ) {
		$controllers[] = '\SureCookie\Inc\Modules\Auth\Api';
		return $controllers;
	}
}
