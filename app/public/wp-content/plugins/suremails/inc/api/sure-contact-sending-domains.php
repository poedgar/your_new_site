<?php
/**
 * SureContactSendingDomains class
 *
 * Proxies SureContact's `/suremails/sending-domains` for the "add sender"
 * picker. The plugin holds the connection's bearer token so the React UI can
 * list the workspace's verified sending domains without ever seeing the key.
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
 * Class SureContactSendingDomains
 *
 * Handles `GET /suremails/v1/surecontact/sending-domains`.
 */
class SureContactSendingDomains extends Api_Base {
	use Instance;

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '/surecontact/sending-domains';

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
					'callback'            => [ $this, 'sending_domains' ],
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
	 * Fetch the workspace's active sending domains from SureContact.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 * @return WP_REST_Response
	 */
	public function sending_domains( $request ) {
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
			rtrim( SurecontactHandler::api_base(), '/' ) . '/suremails/sending-domains',
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
						: __( 'Unable to fetch SureContact sending domains.', 'suremails' ),
				],
				$code !== 0 ? $code : 502
			);
		}

		$domains = is_array( $body['data'] ?? null ) ? $body['data'] : [];
		$clean   = [];
		foreach ( $domains as $domain ) {
			if ( ! is_array( $domain ) ) {
				continue;
			}
			$clean[] = [
				'uuid'   => sanitize_text_field( (string) ( $domain['uuid'] ?? '' ) ),
				'domain' => sanitize_text_field( (string) ( $domain['domain'] ?? '' ) ),
			];
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'domains' => $clean,
			],
			200
		);
	}
}

SureContactSendingDomains::instance();
