<?php
/**
 * SureContactVerify class
 *
 * Public REST endpoint that the SureContact platform calls during account
 * provisioning to verify domain control. Echoes the supplied token back as
 * JSON so SureContact can confirm this WordPress site responds at this URL.
 *
 * @package SureMails\Inc\API
 */

namespace SureMails\Inc\API;

use SureMails\Inc\Traits\Instance;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SureContactVerify
 *
 * Handles the `GET /suremails/v1/verify` endpoint.
 */
class SureContactVerify extends Api_Base {
	use Instance;

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '/verify';

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
					'callback'            => [ $this, 'echo_token' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'token' => [
							'required' => true,
							'type'     => 'string',
						],
					],
				],
			]
		);
	}

	/**
	 * Echo the supplied token back if it looks like a hex string.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 * @return WP_REST_Response
	 */
	public function echo_token( $request ) {
		$token = (string) $request->get_param( 'token' );

		if ( $token === '' || ! preg_match( '/^[A-Fa-f0-9]{1,128}$/', $token ) ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => 'Invalid token.',
				],
				400
			);
		}

		return new WP_REST_Response(
			[
				'token' => $token,
			],
			200
		);
	}
}

SureContactVerify::instance();
