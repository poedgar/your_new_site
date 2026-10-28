<?php
/**
 * Initialize Cookie Policy Module.
 *
 * Registers the cookie policy shortcode and REST API controller
 * for auto-generating cookie policy pages.
 *
 * @package SureCookie\Inc\Modules\CookiePolicy
 * @since 0.0.0-alpha.1
 */

namespace SureCookie\Inc\Modules\CookiePolicy;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init
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
		// Register the shortcode for cookie policy content.
		Shortcode::get_instance();

		// Register API controller.
		add_filter( 'surecookie_api_controllers', [ $this, 'register_api_controller' ], 20 );
	}

	/**
	 * Register API controller for this module.
	 *
	 * @param array<string> $controllers Existing controllers.
	 * @return array<string> Updated controllers.
	 * @since 0.0.0-alpha.1
	 */
	public function register_api_controller( $controllers = [] ) {
		$controllers[] = '\SureCookie\Inc\Modules\CookiePolicy\Api';
		return $controllers;
	}
}
