<?php
/**
 * API Base
 *
 * Base class for all REST API controllers in SureContact
 *
 * @since 0.0.1
 *
 * @package SureContact\API_WP
 */

namespace SureContact\API_WP;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Api_Base class
 *
 * @since 0.0.1
 */
abstract class Api_Base extends WP_REST_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	protected $namespace = 'surecontact/v1';

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
	 *
	 * @return string
	 */
	public function get_api_namespace() {
		return $this->namespace;
	}

	/**
	 * Validate the permission for REST API requests.
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool|\WP_Error True if valid, WP_Error if invalid.
	 */
	public function validate_permission( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'surecontact_rest_cannot_access',
				__( 'You do not have permission to perform this action.', 'surecontact' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return $this->validate_nonce( $request );
	}

	/**
	 * Validate the nonce for REST API requests.
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool|\WP_Error True if valid, WP_Error if invalid.
	 */
	protected function validate_nonce( $request ) {
		// Retrieve the nonce from the request header.
		$nonce = $request->get_header( 'X-WP-Nonce' );

		// Check if nonce is null or empty.
		if ( empty( $nonce ) || ! is_string( $nonce ) ) {
			return new WP_Error(
				'surecontact_nonce_verification_failed',
				__( 'Nonce is missing.', 'surecontact' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Verify the nonce.
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'surecontact_nonce_verification_failed',
				__( 'Nonce is invalid.', 'surecontact' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
