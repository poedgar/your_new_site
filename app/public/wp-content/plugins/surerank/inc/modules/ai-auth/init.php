<?php
/**
 * Auth Module Init
 *
 * Handles the initialization and hooks for SureRank Auth functionality.
 *
 * @package SureRank\Inc\Modules\Ai_Auth
 * @since 1.4.2
 */

namespace SureRank\Inc\Modules\Ai_Auth;

use SureRank\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init class
 *
 * Handles initialization and WordPress hooks for instant indexing.
 */
class Init {

	use Get_Instance;

	/**
	 * Constructor
	 *
	 * @since 1.4.2
	 */
	public function __construct() {
		if ( ! defined( 'SURERANK_BILLING_PORTAL' ) ) {
			define( 'SURERANK_BILLING_PORTAL', 'https://my.surerank.com/' );
		}

		Controller::get_instance();

		// Hook into the filter to add this module's API controller.
		add_filter( 'surerank_api_controllers', [ $this, 'register_api_controller' ], 20 );
		add_filter( 'surerank_common_localization_vars', [ $this, 'add_localization_vars' ] );
	}

	/**
	 * Add localisation variables
	 *
	 * @since 1.4.2
	 * @param array<string, mixed> $variables Localisation variables.
	 * @return array<string, mixed> Localisation variables.
	 */
	public function add_localization_vars( $variables ) {
		return array_merge(
			$variables,
			[
				// The account (email) is deliberately NOT localized here. These
				// common vars print on every wp-admin page for every logged-in
				// user, and the email doubles as the credit-service API token.
				// Screens that need it must use the permission-gated /ai/auth
				// REST route instead.
				'ai_authenticated'  => Controller::get_instance()->get_auth_status(),
				'ai_auth_source'    => Controller::get_instance()->get_auth_source(),
				'ai_billing_portal' => SURERANK_BILLING_PORTAL,
			]
		);
	}

	/**
	 * Register API controller for this module.
	 *
	 * @since 1.4.2
	 * @param array<string> $controllers Existing controllers.
	 * @return array<string> Updated controllers.
	 */
	public function register_api_controller( $controllers ) {
		$controllers[] = '\SureRank\Inc\Modules\Ai_Auth\Api';
		return $controllers;
	}
}
