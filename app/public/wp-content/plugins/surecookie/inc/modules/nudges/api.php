<?php
/**
 * Nudges API class
 *
 * Handles pro nudges related REST API endpoints.
 *
 * @package SureCookie\Inc\Modules\Nudges
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\Nudges;

use SureCookie\Inc\API\Base;
use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Api
 *
 * @package SureCookie\Inc\Modules\Nudges
 * @since 0.0.1
 */
class Api extends Base {
	use GetInstance;

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_routes(): void {
		// Register a single POST endpoint to disable pro nudges.
		register_rest_route(
			$this->get_api_namespace(),
			'/nudges/disable',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'disable_nudge' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'type' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => [ 'upgrade_banner', 'consent_log_report' ],
					],
				],
			]
		);
	}

	/**
	 * Disable a pro nudge (persist the disabled type with count and next display time).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The request object.
	 * @return void
	 */
	public function disable_nudge( $request ): void {
		$type = $request->get_param( 'type' );

		if ( ! in_array( $type, [ 'upgrade_banner', 'consent_log_report' ], true ) ) {
			SendJson::error( [ 'message' => __( 'Invalid type provided.', 'surecookie' ) ] );
			return;
		}

		$nudges = (array) get_option( SURECOOKIE_NUDGES, [] );

		// Initialize if not set.
		if ( ! isset( $nudges[ $type ] ) ) {
			$nudges[ $type ] = [
				'count'                => 0,
				'next_time_to_display' => 0,
				'display'              => true,
			];
		}

		// Increment count and set next display time.
		$nudges[ $type ]['count']++;
		$nudges[ $type ]['next_time_to_display'] = time() + ( 7 * DAY_IN_SECONDS );
		$nudges[ $type ]['display']              = $nudges[ $type ]['count'] < 2;

		// Non-autoloaded - dismissal state is only read by the admin app (via
		// LocalizeData::get_admin_data()) and the analytics collector, never by the
		// front-end banner, so it has no business in the alloptions cache.
		if ( ! Update::option( SURECOOKIE_NUDGES, $nudges ) ) {
			SendJson::error( [ 'message' => __( 'Could not disable the nudge. Please try again.', 'surecookie' ) ] );
			return;
		}

		SendJson::success(
			[
				'message'              => sprintf( /* translators: %s: nudge type */__( '"%s" has been disabled.', 'surecookie' ), $type ),
				'count'                => $nudges[ $type ]['count'],
				'next_time_to_display' => $nudges[ $type ]['next_time_to_display'],
			]
		);
	}
}
