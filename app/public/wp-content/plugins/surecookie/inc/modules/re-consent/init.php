<?php
/**
 * Initialize Re-Consent Module.
 *
 * @package SureCookie\Inc\Modules\ReConsent
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\ReConsent;

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
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	private function __construct() {
		// Register the shortcode for re-consent button.
		Shortcode::get_instance();

		// Register nav menu integration.
		Menu::get_instance();
	}
}
