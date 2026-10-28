<?php
/**
 * ScannedResources API.
 *
 * REST endpoint for scan-detected scripts and iframes.
 *
 * @package SureCookie\Inc\API
 * @since 0.0.0-alpha.2
 */

namespace SureCookie\Inc\API;

use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Services\ScriptBlockingService;
use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ScannedResources
 *
 * Handles REST API endpoints for scan-detected third-party resources.
 *
 * @since 0.0.0-alpha.2
 */
class ScannedResources extends Base {
	use GetInstance;

	/**
	 * Route path.
	 */
	protected const ROUTE = '/scanned-resources';

	/**
	 * Register API routes.
	 *
	 * @since 0.0.0-alpha.2
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			self::ROUTE,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_scanned_resources' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);
	}

	/**
	 * Get scan-detected resources.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 0.0.0-alpha.2
	 * @return void
	 */
	public function get_scanned_resources( $request ): void {
		/**
		 * Filter the scan-detected resources before they reach the scanner UI.
		 * Modules annotate each resource here, e.g. Google Consent Mode flags
		 * `gcmManaged` so the UI can disable the block toggle for scripts GCM
		 * already lets through under consent-mode signaling.
		 *
		 * Applied inside ScriptBlockingService::get_scanned_resources_payload(),
		 * which the script-blocking ability shares. The `{ scripts, iframes,
		 * metadata }` shape below is what the admin table reads, so it is
		 * returned unchanged; the ability flattens it separately.
		 *
		 * @since 1.2.0
		 * @param array<string, mixed> $resources Scanned resources payload.
		 */
		SendJson::success(
			[
				'message'   => __( 'Scanned resources retrieved successfully.', 'surecookie' ),
				'resources' => ( new ScriptBlockingService() )->get_scanned_resources_payload(),
			]
		);
	}
}
