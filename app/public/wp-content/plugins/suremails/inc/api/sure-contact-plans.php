<?php
/**
 * SureContactPlans class
 *
 * Proxies SureContact's public `/smtp-plans` pricing endpoint for the upgrade
 * modal. The plugin holds the connection's bearer token so the React UI can
 * fetch SMTP plans + email-credit packs without ever seeing the key. The
 * payload (header, plans, credits) is forwarded as-is; checkout URLs are
 * resolved on the SureContact side.
 *
 * @package SureMails\Inc\API
 */

namespace SureMails\Inc\API;

use SureMails\Inc\Emails\Providers\SURECONTACT\SurecontactHandler;
use SureMails\Inc\Settings;
use SureMails\Inc\Traits\Instance;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SureContactPlans
 *
 * Handles `GET /suremails/v1/surecontact/plans`.
 */
class SureContactPlans extends Api_Base {
	use Instance;

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '/surecontact/plans';

	/**
	 * Register API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->get_api_namespace(),
			$this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'plans' ],
					'permission_callback' => [ $this, 'validate_permission' ],
					'args'                => [
						'connection_id' => [
							'required' => true,
							'type'     => 'string',
						],
					],
				],
			]
		);
	}

	/**
	 * Fetch the SMTP plans + email-credit packs from SureContact.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 * @return WP_REST_Response
	 */
	public function plans( $request ) {
		$connection_id = sanitize_text_field( (string) $request->get_param( 'connection_id' ) );
		$settings      = Settings::instance()->get_settings();
		$connection    = $settings['connections'][ $connection_id ] ?? null;

		if ( ! is_array( $connection ) || ( $connection['type'] ?? '' ) !== 'SURECONTACT' ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'SureContact connection not found.', 'suremails' ),
				],
				404
			);
		}

		$api_key = (string) ( $connection['api_key'] ?? '' );
		if ( $api_key === '' ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'No API key on file for this SureContact connection.', 'suremails' ),
				],
				400
			);
		}

		$response = wp_remote_get(
			rtrim( SurecontactHandler::api_base(), '/' ) . '/smtp-plans',
			[
				'headers' => [
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => $response->get_error_message(),
				],
				502
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );
		$body = is_array( $body ) ? $body : [];

		if ( $code < 200 || $code >= 300 || ( $body['success'] ?? false ) !== true ) {
			$message = (string) ( $body['message'] ?? '' );
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => $message !== ''
						? $message
						: __( 'Unable to fetch SureContact plans.', 'suremails' ),
				],
				$code !== 0 ? $code : 502
			);
		}

		// Forward the pricing payload as-is. Checkout URLs are resolved on the
		// SureContact side, so the modal consumes header/plans/credits directly.
		return new WP_REST_Response(
			[
				'success' => true,
				'header'  => is_array( $body['header'] ?? null ) ? $body['header'] : [],
				'plans'   => is_array( $body['plans'] ?? null ) ? $body['plans'] : [],
				'credits' => is_array( $body['credits'] ?? null ) ? $body['credits'] : null,
			],
			200
		);
	}
}

SureContactPlans::instance();
