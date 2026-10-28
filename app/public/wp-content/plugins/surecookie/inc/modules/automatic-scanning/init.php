<?php
/**
 * Initialize Automatic Scanning.
 *
 * Free base of the Automatic Scanning feature: schedules recurring scans
 * (Monthly on Free; Weekly unlocked by Pro), reuses the existing scan engine,
 * and powers change detection + scan history. Pro extends this module with
 * Weekly frequency, email digests, auto-apply and the compliance guard.
 *
 * @package SureCookie\Inc\Modules\AutomaticScanning
 * @since 1.2.0
 */

namespace SureCookie\Inc\Modules\AutomaticScanning;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init
 *
 * @since 1.2.0
 */
class Init {
	use GetInstance;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	private function __construct() {
		Scheduler::get_instance();
		Runner::get_instance();
		History::get_instance();

		add_filter( 'surecookie_api_controllers', [ $this, 'register_api_controller' ] );
	}

	/**
	 * Register the module's REST controller with the core API router.
	 *
	 * @param array<int, string> $controllers Registered controller class names.
	 * @since 1.2.0
	 * @return array<int, string>
	 */
	public function register_api_controller( $controllers = [] ) {
		if ( is_array( $controllers ) ) {
			$controllers[] = Api::class;
		}

		return $controllers;
	}
}
