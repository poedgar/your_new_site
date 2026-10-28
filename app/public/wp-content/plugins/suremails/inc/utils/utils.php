<?php
/**
 * Utils Class.
 *
 * @package SureMails;
 * @since 1.9.0
 */

namespace SureMails\Inc\Utils;

use SureMails\Inc\Settings;
use SureMails\Inc\Traits\Instance;

/**
 * Utils
 *
 * @since 1.9.0
 */
class Utils {

	use Instance;

	/**
	 * Get the SureMails admin URL.
	 *
	 * @param string $fragment Optional URL fragment (hash).
	 * @return string The complete admin URL.
	 */
	public static function get_admin_url( $fragment = '' ) {

		if ( self::is_sidebar_enabled() ) {

			$base_url = admin_url( 'admin.php?page=' . SUREMAILS );
		} else {

			$base_url = admin_url( 'options-general.php?page=' . SUREMAILS );
		}

		if ( ! empty( $fragment ) ) {
			$base_url .= '#' . ltrim( $fragment, '#' );
		}

		return $base_url;
	}

	/**
	 * Check if SureMails should be displayed in the admin sidebar.
	 *
	 * @since 1.9.2
	 * @return bool True if SureMails should appear as a top-level menu, false if it should appear under Settings.
	 */
	public static function is_sidebar_enabled() {
		$show_in_sidebar = Settings::instance()->get_misc_settings( 'show_in_sidebar' );
		return 'yes' === $show_in_sidebar;
	}
}
