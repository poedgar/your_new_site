<?php
/**
 * Initialize API.
 *
 * @package SureCookie\Inc\API
 * @since 0.0.1
 */

namespace SureCookie\Inc\API;

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
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_routes(): void {
		$controllers = [
			'\SureCookie\Inc\API\Plugin',
			'\SureCookie\Inc\API\Cookies',
			'\SureCookie\Inc\API\Settings',
			'\SureCookie\Inc\API\ConsentLogs',
			'\SureCookie\Inc\API\ScanHistory',
			'\SureCookie\Inc\API\CookieCategories',
			'\SureCookie\Inc\API\Onboarding',
			'\SureCookie\Inc\API\ScannedResources',
			'\SureCookie\Inc\API\Posts',
			'\SureCookie\Inc\Modules\Nudges\Api',
		];

		$controllers = apply_filters( 'surecookie_api_controllers', $controllers );

		foreach ( $controllers as $controller_class ) {
			if ( class_exists( $controller_class ) ) {
				$controller = $controller_class::get_instance();
				$controller->register_routes();
			}
		}
	}
}
