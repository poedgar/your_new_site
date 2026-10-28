<?php
/**
 * Elementor - Integration Initialization
 *
 * Gates Elementor widgets that carry a third-party embed as widget config and
 * build it in the browser, which the tag-level blocking passes cannot see.
 *
 * @package SureCookie\Inc\Integrations\Elementor
 * @since   1.4.0
 */

namespace SureCookie\Inc\Integrations\Elementor;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init class.
 *
 * @since 1.4.0
 */
class Init {
	use GetInstance;

	/**
	 * Constructor - gates on Elementor being active.
	 *
	 * @since 1.4.0
	 */
	private function __construct() {
		if ( ! self::is_elementor_active() ) {
			return;
		}

		Video_Widget::get_instance();
	}

	/**
	 * Check whether Elementor is loaded.
	 *
	 * Elementor defines `ELEMENTOR_VERSION` on bootstrap. A constant check rather
	 * than a class check so the integration survives internal refactors.
	 *
	 * @since 1.4.0
	 * @return bool
	 */
	public static function is_elementor_active(): bool {
		return defined( 'ELEMENTOR_VERSION' );
	}
}
