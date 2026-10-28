<?php
/**
 * Initialize the Services module.
 *
 * Owns the known-services catalog domain: fetch/cache/validate of the unified
 * dataset/services.json (Services_Source), its projections (Known_Scripts blocking
 * view, Service_Cookies_Source / Declared_Cookies declared cookies), the daily
 * refresh Cron, and the REST controller. Other modules consume these views purely
 * through `surecookie_*` hooks, so this module has no hard dependency on them and
 * they have none on it.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.3.0
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init
 *
 * @since 1.3.0
 */
class Init {
	use GetInstance;

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	private function __construct() {
		// Schedules + runs the daily unified-catalog refresh and reconciles
		// declared cookies. Schedules directly in its own constructor because
		// modules boot on init:999 - too late for an init-hooked callback.
		Cron::get_instance();

		// Registry of admin-added services; its constructor wires the read-time
		// Detected Resources overlay (surecookie_scanned_resources filter).
		Installed_Services::get_instance();

		// Provide the blocking view to consumers (the Blocker + Scan_Scripts merge)
		// via the shared filter, so script-blocking stays a pure consumer with no
		// dependency on this module's classes. Priority 5 seeds the catalog view
		// before Scan_Scripts (p20) merges scan-detected resources on top.
		add_filter( 'surecookie_known_scripts', [ $this, 'provide_blocking_view' ], 5 );

		// Register this module's REST controller(s).
		add_filter( 'surecookie_api_controllers', [ $this, 'register_api_controllers' ], 20 );
	}

	/**
	 * Seed the `surecookie_known_scripts` filter with the catalog blocking view.
	 *
	 * @param array<string, array<string, mixed>> $scripts Incoming (unseeded) view.
	 * @return array<string, array<string, mixed>> The category => slug => definition view.
	 * @since 1.3.0
	 */
	public function provide_blocking_view( $scripts = [] ) {
		$view = Services_Source::get_instance()->get_blocking_view();

		// The catalog is the authoritative base; anything already present merges under it.
		return is_array( $scripts ) && $scripts !== []
			? array_merge( $scripts, $view )
			: $view;
	}

	/**
	 * Register this module's REST API controllers.
	 *
	 * @param array<string> $controllers Existing controllers.
	 * @return array<string> Updated controllers.
	 * @since 1.3.0
	 */
	public function register_api_controllers( $controllers = [] ) {
		$controllers[] = '\SureCookie\Inc\Modules\Services\Api';
		return $controllers;
	}
}
