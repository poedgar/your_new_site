<?php
/**
 * Onboarding Class
 *
 * Handles the onboarding process for the SureMails plugin.
 *
 * @package SureMails\Inc
 */

namespace SureMails\Inc;

use SureMails\Inc\Traits\Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Onboarding Class
 *
 * Handles the onboarding process for the SureMails plugin.
 *
 * @package SureMails\Inc\Onboarding
 */
class Onboarding {
	use Instance;

	/**
	 * Onboarding completion setting.
	 *
	 * @var string
	 */
	private $onboarding_status_option = 'suremails_onboarding_completed';

	/**
	 * Onboarding skipped setting.
	 *
	 * @var string
	 */
	private $onboarding_skipped_option = 'suremails_onboarding_skipped';

	/**
	 * Set onboarding completion status.
	 *
	 * @since 0.0.1
	 * @param string $completed Whether the onboarding is completed.
	 * @return bool
	 */
	public function set_onboarding_status( $completed = 'no' ) {
		return update_option( $this->onboarding_status_option, $completed );
	}

	/**
	 * Get onboarding completion status.
	 *
	 * @since 0.0.1
	 * @return bool
	 */
	public function get_onboarding_status() {
		return get_option( $this->onboarding_status_option, 'no' ) === 'yes';
	}

	/**
	 * Set onboarding skipped status.
	 *
	 * @param bool $skipped Whether any onboarding step was skipped.
	 * @return bool
	 */
	public function set_onboarding_skipped_status( bool $skipped = false ) {
		return update_option( $this->onboarding_skipped_option, $skipped );
	}

	/**
	 * Get onboarding skipped status.
	 *
	 * @return bool
	 */
	public function get_onboarding_skipped_status() {
		return (bool) get_option( $this->onboarding_skipped_option, false );
	}
}
