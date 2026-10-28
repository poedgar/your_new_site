<?php
/**
 * Script-Blocking Datasets Cron.
 *
 * Warms the unified remote-served dataset (services.json) off the request path
 * and reconciles declared cookies against the refreshed catalog. The dataset is
 * always available from the bundled floor, so this only keeps it current - a
 * failed run never leaves blocking without data.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.2.5
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Cron
 *
 * Schedules and runs the daily dataset refresh.
 *
 * @since 1.2.5
 */
class Cron {
	use GetInstance;

	/**
	 * Action hook that refreshes the datasets. Also used by Known_Scripts to
	 * schedule a one-off near-immediate warm when its cache is cold.
	 *
	 * @since 1.2.5
	 */
	public const REFRESH_HOOK = 'surecookie_refresh_datasets';

	/**
	 * Constructor.
	 *
	 * @since 1.2.5
	 */
	private function __construct() {
		add_action( self::REFRESH_HOOK, [ $this, 'refresh' ] );

		// Cron::get_instance() is instantiated from the module bootstrap on the
		// `init` hook at priority 999, so an add_action( 'init', ... ) here would
		// register a callback for a priority that has already run and never fire.
		// Schedule directly instead - wp_schedule_event() is available this late,
		// and schedule() is idempotent (a no-op once the event is registered).
		$this->schedule();
	}

	/**
	 * Schedule the daily refresh if it is not already scheduled.
	 *
	 * @since 1.2.5
	 * @return void
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::REFRESH_HOOK );
		}
	}

	/**
	 * Refresh the unified remote catalog and reconcile declared cookies.
	 *
	 * One fetch warms Services_Source (which feeds both the blocking view and the
	 * declared-cookie view); then stale declared cookies are reconciled against it.
	 *
	 * @since 1.2.5
	 * @return void
	 */
	public function refresh(): void {
		Services_Source::get_instance()->refresh_from_remote();
		Known_Scripts::get_instance()->load_data();
		Declared_Cookies::get_instance()->reconcile_declared_cookies();
	}
}
