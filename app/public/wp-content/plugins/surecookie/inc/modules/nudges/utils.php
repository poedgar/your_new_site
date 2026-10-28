<?php
/**
 * Utils class.
 *
 * @package SureCookie\Inc\Modules\Nudges
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\Nudges;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The Utils class.
 *
 * @package SureCookie\Inc\Modules\Nudges
 * @since 0.0.1
 */
class Utils {
	use GetInstance;

	/**
	 * Get Pro Nudges.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_nudges() {
		$nudges = get_option( SURECOOKIE_NUDGES, [] );

		if ( empty( $nudges ) ) {
			return [];
		}

		foreach ( $nudges as $key => $nudge ) {

			if ( isset( $nudge['display'] ) && $nudge['display'] === false ) {
				continue;
			}

			if ( isset( $nudge['next_time_to_display'] ) && time() < $nudge['next_time_to_display'] ) {
				$nudges[ $key ]['display'] = false;
			}
		}
		return $nudges;
	}
}
