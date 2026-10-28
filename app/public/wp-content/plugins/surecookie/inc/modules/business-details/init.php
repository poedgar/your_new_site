<?php
/**
 * Initialize Business Details Module.
 *
 * @package SureCookie\Inc\Modules\BusinessDetails
 * @since 1.5.0
 */

namespace SureCookie\Inc\Modules\BusinessDetails;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init
 *
 * @since 1.5.0
 */
class Init {
	use GetInstance;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 */
	private function __construct() {
		// Register the shortcodes that expose Business Details.
		Shortcode::get_instance();
	}
}
