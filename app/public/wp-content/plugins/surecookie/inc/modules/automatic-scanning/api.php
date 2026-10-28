<?php
/**
 * Automatic Scanning REST API.
 *
 * Exposes the single latest scan record (with its change set), scheduling
 * status, and a manual "Scan now" trigger. Registered via the
 * surecookie_api_controllers filter and gated by the shared nonce + capability.
 *
 * @package SureCookie\Inc\Modules\AutomaticScanning
 * @since 1.2.0
 */

namespace SureCookie\Inc\Modules\AutomaticScanning;

use SureCookie\Inc\API\Base;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;
use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Api
 *
 * @since 1.2.0
 */
class Api extends Base {
	use GetInstance;

	/**
	 * Register API routes.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			'/auto-scan/history',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_history' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			'/auto-scan/next-run',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_next_run' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			'/auto-scan/run-now',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'run_now' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);
	}

	/**
	 * Get the single latest scan record with its change set.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @since 1.2.0
	 * @return void
	 */
	public function get_history( $request ): void {
		unset( $request );

		SendJson::success( [ 'history' => History::get_latest() ] );
	}

	/**
	 * Get scheduling status (next run, enablement, cron availability).
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @since 1.2.0
	 * @return void
	 */
	public function get_next_run( $request ): void {
		unset( $request );

		SendJson::success(
			[
				'enabled'             => (bool) Settings::get( 'auto_scan_enabled' ),
				'frequency'           => Scheduler::effective_frequency(),
				'allowed_frequencies' => Scheduler::allowed_frequencies(),
				'next_run'            => Scheduler::next_run(),
				'cron_status'         => Helper::are_crons_available(),
			]
		);
	}

	/**
	 * Trigger the scheduled scan flow on demand.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @since 1.2.0
	 * @return void
	 */
	public function run_now( $request ): void {
		unset( $request );

		if ( ! (bool) Settings::get( 'auto_scan_enabled' ) ) {
			SendJson::error( [ 'message' => __( 'Enable Automatic Scanning before running a scan.', 'surecookie' ) ] );
		}

		if ( SaasClient::get_instance()->is_scan_in_progress() ) {
			SendJson::error( [ 'message' => __( 'A scan is already in progress.', 'surecookie' ) ] );
		}

		try {
			Runner::get_instance()->run_scheduled_scan();
		} catch ( \Throwable $e ) {
			SendJson::error( [ 'message' => __( 'Could not start the scan. Please try again.', 'surecookie' ) ] );
		}

		SendJson::success( [ 'message' => __( 'Scan triggered.', 'surecookie' ) ] );
	}
}
