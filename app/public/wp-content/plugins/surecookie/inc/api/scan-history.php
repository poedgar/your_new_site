<?php
/**
 * ScanHistory class
 *
 * Handles installed products related REST API endpoints for the SureCookie plugin.
 *
 * @package SureCookie\Inc\API
 */

namespace SureCookie\Inc\API;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ScanHistory
 *
 * Handles this related REST API endpoints.
 */
class ScanHistory extends Base {
	use GetInstance;

	/**
	 * Route Get Logs
	 */
	protected const GET_SCANNED_HISTORY = '/scan-history/get';

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			self::GET_SCANNED_HISTORY,
			[
				'methods'             => WP_REST_Server::READABLE, // GET method.
				'callback'            => [ $this, 'get_scanned_history' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);
	}

	/**
	 * Get scanned history
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 */
	public function get_scanned_history( $request ): void {
		$scanned_history = Get::option( SURECOOKIE_SCANNED_DETAILS_OPTION, [], 'array' );
		SendJson::success(
			[
				'message' => __( 'Scanned history retrieved successfully.', 'surecookie' ),
				'history' => $scanned_history,
			]
		);
	}
}
