<?php
/**
 * Initialize Consent Logging.
 *
 * @package SureCookie\Inc\Modules\ConsentLogs
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\ConsentLogs;

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
		$this->init_modules();
	}

	/**
	 * Initialize consent logs modules.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	private function init_modules(): void {
		// Initialize cron for log retention cleanup.
		Cron::get_instance();
	}
}
