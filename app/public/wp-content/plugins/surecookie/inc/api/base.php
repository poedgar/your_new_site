<?php
/**
 * API base.
 *
 * @package SureCookie;
 * @since 0.0.1
 */

namespace SureCookie\Inc\API;

use SureCookie\Inc\Functions\Helper;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Base
 *
 * @since 0.0.1
 */
abstract class Base extends WP_REST_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @var non-falsy-string
	 */
	protected $namespace = 'surecookie/v1';

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
	}
	/**
	 * Get API namespace.
	 *
	 * @since 0.0.1
	 * @return non-falsy-string
	 */
	public function get_api_namespace() {
		return $this->namespace;
	}

	/**
	 * Validate the nonce for REST API requests.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The REST request object.
	 * @return bool|WP_Error True if valid, WP_REST_Response if invalid.
	 */
	public function validate_permission( $request ) {
		if ( ! current_user_can( Helper::capability() ) ) {
			return new WP_Error(
				'surecookie_rest_cannot_access',
				__( 'You don\'t have access to do this.', 'surecookie' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		// Retrieve the nonce from the request header.
		$nonce = $request->get_header( 'X-WP-Nonce' );

		// Check if nonce is null or empty.
		if ( empty( $nonce ) || ! is_string( $nonce ) ) {
			return new WP_Error(
				'surecookie_nonce_verification_failed',
				__( 'Security check failed. Please refresh the page and try again.', 'surecookie' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		// Verify the nonce.
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'surecookie_nonce_verification_failed',
				__( 'Your session expired. Please refresh the page and try again.', 'surecookie' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}
}
