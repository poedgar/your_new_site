<?php
/**
 * SureContactResendVerification class
 *
 * Triggers the SureContact verify-email/resend endpoint on behalf of a saved
 * connection. The plugin holds the connection's bearer token so the React UI
 * never has to.
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
 * Class SureContactResendVerification
 *
 * Handles `POST /suremails/v1/surecontact/resend-verification`.
 */
class SureContactResendVerification extends Api_Base {
	use Instance;

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '/surecontact/resend-verification';

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
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'resend' ],
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
	 * Forward a verify-email/resend request to SureContact.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 * @return WP_REST_Response
	 */
	public function resend( $request ) {
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

		$response = wp_remote_post(
			rtrim( SurecontactHandler::api_base(), '/' ) . '/suremails/verify-email/resend',
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

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$body   = json_decode( $raw, true );
		$body   = is_array( $body ) ? $body : [];
		$reason = (string) ( $body['reason'] ?? '' );

		if ( $code >= 200 && $code < 300 && ( $body['success'] ?? false ) === true ) {
			return new WP_REST_Response(
				[
					'success' => true,
					'message' => __( 'Verification email sent. Check your inbox.', 'suremails' ),
				],
				200
			);
		}

		if ( $reason === 'ALREADY_VERIFIED' ) {
			$this->mark_verified( $connection );
			return new WP_REST_Response(
				[
					'success'        => true,
					'message'        => __( 'This email is already verified.', 'suremails' ),
					'email_verified' => true,
				],
				200
			);
		}

		$message = (string) ( $body['message'] ?? '' );
		if ( $reason === 'RESEND_RATE_LIMITED' ) {
			$retry_after = (int) ( $body['context']['retry_after'] ?? 0 );
			return new WP_REST_Response(
				[
					'success'     => false,
					'message'     => $message !== ''
						? $message
						: __( 'Verification email already sent recently. Please try again in a few minutes.', 'suremails' ),
					'retry_after' => $retry_after,
				],
				429
			);
		}

		return new WP_REST_Response(
			[
				'success' => false,
				'message' => $message !== ''
					? $message
					: __( 'SureContact could not resend the verification email.', 'suremails' ),
			],
			$code !== 0 ? $code : 502
		);
	}

	/**
	 * Persist email_verified=true on the connection row.
	 *
	 * @param array<string, mixed> $connection Connection record (already decrypted).
	 * @return void
	 */
	private function mark_verified( array $connection ) {
		// Verification is account-level — fan it out to every SureContact row
		// that shares this workspace so sibling senders flip verified together.
		$workspace_uuid = (string) ( $connection['workspace_uuid'] ?? '' );
		if ( $workspace_uuid !== '' ) {
			Settings::instance()->sync_surecontact_siblings(
				$workspace_uuid,
				[ 'email_verified' => true ]
			);
			return;
		}

		$connection['email_verified'] = true;
		Settings::instance()->update_connection( $connection );
	}
}

SureContactResendVerification::instance();
