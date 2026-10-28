<?php
/**
 * Auth API class
 *
 * Handles authentication related REST API endpoints + the admin-post handler
 * that receives the inbound POST form from the billing portal popup.
 *
 * @package SureCookie\Inc\Modules\Auth
 * @since 0.0.1-beta.3
 */

namespace SureCookie\Inc\Modules\Auth;

use SureCookie\Inc\API\Base;
use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Api
 *
 * Handles authentication related REST API endpoints.
 */
class Api extends Base {
	use GetInstance;

	/**
	 * Admin-post.php action slug for the inbound auth callback (issue #466).
	 */
	public const CALLBACK_ACTION = 'surecookie_auth_callback';

	/**
	 * Transient prefix recording which screen started an auth flow.
	 *
	 * Kept apart from the flow key transient so the shape `save_auth()` decrypts
	 * stays untouched.
	 */
	public const ORIGIN_TRANSIENT_PREFIX = 'surecookie_auth_origin_';

	/**
	 * Register REST routes.
	 *
	 * @since 0.0.1-beta.3
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			'/auth',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_auth_payload' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					// Which screen to return to. An enum, never a caller-supplied
					// URL: this value ends up in a redirect.
					'origin' => [
						'required'          => false,
						'type'              => 'string',
						'default'           => Controller::ORIGIN_ADMIN,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( $value ): bool => in_array( $value, Controller::ORIGINS, true ),
					],
				],
			]
		);
	}

	/**
	 * GET /surecookie/v1/auth - returns the form payload the frontend POSTs
	 * to the billing portal (issue #466). Replaces the URL-string response.
	 *
	 * @since 0.0.1-beta.3
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return void
	 */
	public function get_auth_payload( $request ): void {
		$controller = Controller::get_instance();

		if ( $controller->get_auth_status() ) {
			SendJson::success(
				[
					'is_authenticated' => true,
					'email'            => $controller->get_auth_email(),
					'message'          => __( 'Authentication is already completed.', 'surecookie' ),
				]
			);
			return;
		}

		$payload = $controller->get_auth_payload( (string) $request->get_param( 'origin' ) );

		if ( is_wp_error( $payload ) ) {
			SendJson::error( [ 'message' => $payload->get_error_message() ] );
			return;
		}

		SendJson::success( $payload );
	}

	/**
	 * Handle the inbound POST form from the billing portal popup (issue #466).
	 *
	 * Receives `{action, flow_id, access_key}` as form fields, runs save_auth,
	 * and redirects the popup to /wp-admin/admin.php?page=surecookie with a
	 * benign `?auth=success|fail` query - the ciphertext never lands in the
	 * post-OAuth URL.
	 *
	 * @since 0.0.1-beta.3
	 */
	public function handle_inbound_callback(): void {
		// Cast to string up front - array-typed $_POST values would trigger
		// sanitize_text_field warnings. flow_id is then validated as UUID v4
		// inside save_auth(); access_key is base64-URL-safe ciphertext that
		// sanitization would corrupt, validated by decrypt.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Replay protection is via one-time transient in save_auth. flow_id validated as UUID v4 downstream.
		$flow_raw = isset( $_POST['flow_id'] ) && is_string( $_POST['flow_id'] ) ? wp_unslash( $_POST['flow_id'] ) : '';
		$flow_id  = sanitize_text_field( (string) $flow_raw );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- ciphertext is base64 URL-safe; sanitization would corrupt it. Validated by save_auth.
		$access_key = isset( $_POST['access_key'] ) && is_string( $_POST['access_key'] ) ? (string) wp_unslash( $_POST['access_key'] ) : '';

		// Return to whichever screen started the flow. A popup-blocked connect
		// navigates the admin tab itself, so sending every return to the admin app
		// used to drop a user out of the setup wizard with no way back into it.
		$origin = '';
		if ( $flow_id !== '' ) {
			$stored = get_transient( self::ORIGIN_TRANSIENT_PREFIX . $flow_id );
			$origin = is_string( $stored ) ? $stored : '';
			delete_transient( self::ORIGIN_TRANSIENT_PREFIX . $flow_id );
		}

		if ( $access_key === '' || $flow_id === '' ) {
			wp_safe_redirect( Controller::get_instance()->get_return_url( $origin, 'fail' ) );
			exit;
		}

		$result = Controller::get_instance()->save_auth( $access_key, $flow_id );

		$status = is_wp_error( $result ) ? 'fail' : 'success';
		wp_safe_redirect( Controller::get_instance()->get_return_url( $origin, $status ) );
		exit;
	}
}
