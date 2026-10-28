<?php
/**
 * SureContactStatus class
 *
 * Proxies SureContact's `/suremails/status` for the verify banner. Updates the
 * local `email_verified` flag whenever SureContact reports it as flipped, so
 * the banner reflects verification clicks made elsewhere without polling.
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
 * Class SureContactStatus
 *
 * Handles `GET /suremails/v1/surecontact/status`.
 */
class SureContactStatus extends Api_Base {
	use Instance;

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '/surecontact/status';

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
					'callback'            => [ $this, 'status' ],
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
	 * Fetch the status from SureContact, sync the local flag if needed.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 * @return WP_REST_Response
	 */
	public function status( $request ) {
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
			rtrim( SurecontactHandler::api_base(), '/' ) . '/suremails/status',
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
						: __( 'Unable to fetch SureContact status.', 'suremails' ),
				],
				$code !== 0 ? $code : 502
			);
		}

		$data           = $body['data'] ?? [];
		$verified       = (bool) ( $data['account']['email_verified'] ?? false );
		$is_paid        = (bool) ( $data['plan']['is_paid'] ?? false );
		$cap            = is_array( $data['cap'] ?? null ) ? $data['cap'] : [];
		$local_verified = (bool) ( $connection['email_verified'] ?? false );
		$local_is_paid  = (bool) ( $connection['is_paid'] ?? false );

		// Intentional write side-effect on a read endpoint: mirror SureContact's
		// account-level verification + plan flags locally so the verify-banner
		// and the paid-only "add sender" gate reflect reality without polling.
		// Verification and plan are account-level, so fan the values out to all
		// SureContact rows that share this workspace — they're siblings of one
		// account. Low-traffic (fires only when the banner/meter mounts).
		if ( $verified !== $local_verified || $is_paid !== $local_is_paid ) {
			$workspace_uuid = (string) ( $connection['workspace_uuid'] ?? '' );
			if ( $workspace_uuid !== '' ) {
				Settings::instance()->sync_surecontact_siblings(
					$workspace_uuid,
					[
						'email_verified' => $verified,
						'is_paid'        => $is_paid,
					]
				);
			} else {
				$connection['email_verified'] = $verified;
				$connection['is_paid']        = $is_paid;
				Settings::instance()->update_connection( $connection );
			}
		}

		return new WP_REST_Response(
			[
				'success'        => true,
				'email_verified' => $verified,
				'is_paid'        => $is_paid,
				'cap'            => $cap,
			],
			200
		);
	}
}

SureContactStatus::instance();
