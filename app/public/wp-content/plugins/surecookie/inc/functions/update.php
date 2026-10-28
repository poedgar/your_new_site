<?php
/**
 * Update
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Functions;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WP_Error;

/**
 * Update
 *
 * @since 0.0.1
 */
class Update {
	/**
	 * Update the option.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $option_value Option value.
	 * @param bool   $autoload     Whether to autoload the option.
	 * @since 0.0.1
	 * @return bool
	 */
	public static function option( $option_name, $option_value, $autoload = false ) {
		// Update the option.
		return update_option( $option_name, $option_value, $autoload );
	}
}
