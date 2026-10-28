<?php
/**
 * Initialize Assisted Scan.
 *
 * A recovery path for sites the cloud scanner cannot reach (host firewalls that block
 * our external scanner or its domain-verification request, leaving the site with no
 * scanner and no cookie policy). It walks the selected public pages in the admin's own
 * browser, collects browser-visible cookies, scripts and request domains, matches them
 * against the bundled Known Services catalog, and feeds the same ingestion pipeline a
 * cloud scan uses. It needs no SaaS reachability, which is the failure it exists for.
 *
 * A workaround, not a headline feature: no scan history, admin menu entry or plan gating.
 *
 * @package SureCookie\Inc\Modules\AssistedScan
 * @since   1.3.0
 */

namespace SureCookie\Inc\Modules\AssistedScan;

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
		// Kill switch checked before anything registers, so disabling the feature
		// removes the REST routes and front-end collector outright, not just the entry point.
		if ( ! Utils::is_enabled() ) {
			return;
		}

		Session::get_instance();
		Collector::get_instance();

		add_filter( 'surecookie_api_controllers', [ $this, 'register_api_controller' ] );

		// Rescue for a walk the browser never finished: Session arms a one-shot event per
		// recorded page, so this fires after the last sign of life and finalizes what was collected.
		add_action( Session::FINALIZE_HOOK, [ $this, 'finalize_abandoned_scan' ] );
	}

	/**
	 * Register the module's REST controller with the core API router.
	 *
	 * @param array<int, string> $controllers Registered controller class names.
	 * @since 1.3.0
	 * @return array<int, string>
	 */
	public function register_api_controller( $controllers = [] ) {
		if ( is_array( $controllers ) ) {
			$controllers[] = Api::class;
		}

		return $controllers;
	}

	/**
	 * Finalize a session the browser walked away from.
	 *
	 * Only acts once the session has genuinely gone quiet, so this cannot cut short
	 * a slow walk that is still reporting pages.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function finalize_abandoned_scan(): void {
		$session = Session::get_instance();

		if ( empty( $session->get() ) ) {
			return;
		}

		if ( ! $session->is_stale() && ! $session->is_complete() ) {
			return;
		}

		// Flagged abandoned only when the walk went quiet rather than reaching its last
		// page, so analytics can tell "tab closed" from "finished but the finish call never landed".
		Ingest::get_instance()->finalize( $session->is_stale() );
	}
}
