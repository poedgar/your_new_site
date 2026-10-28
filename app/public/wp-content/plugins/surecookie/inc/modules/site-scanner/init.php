<?php
/**
 * Initialize Site Scanner.
 *
 * @package SureCookie\Inc\Modules\SiteScanner
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\SiteScanner;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init
 *
 * @since 0.0.1
 */
class Init {
	use GetInstance;

	/**
	 * Constructor -- Register API routes.
	 *
	 * @since 0.0.1
	 */
	private function __construct() {
		// Initialize cron (which also initializes SaasClient).
		Cron::get_instance();

		// Issue #473: register the public site-verify REST route that the SaaS hits during registration to prove the plugin controls the domain.
		VerificationHandler::get_instance();

		// Register API controller.
		add_filter( 'surecookie_api_controllers', [ $this, 'register_api_controller' ], 20 );
	}

	/**
	 * Register API controller for this module.
	 *
	 * @param array<string> $controllers Existing controllers.
	 * @return array<string> Updated controllers.
	 * @since 0.0.1
	 */
	public function register_api_controller( $controllers = [] ) {
		$controllers[] = '\SureCookie\Inc\Modules\SiteScanner\Api';
		return $controllers;
	}
}
