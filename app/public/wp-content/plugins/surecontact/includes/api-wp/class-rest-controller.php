<?php
/**
 * REST Controller class
 *
 * Handles WordPress-specific REST API endpoints for the SureContact plugin.
 * Manages authentication bridging between WordPress and SaaS platform,
 * and handles data synchronization.
 *
 * @since 0.0.1
 *
 * @package SureContact\API_WP
 */

namespace SureContact\API_WP;

use WP_REST_Server;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST Controller class
 *
 * Provides REST API endpoints for:
 * - Authentication bridging (WordPress ↔ SaaS)
 * - Sync operations (SaaS webhook handling)
 * - Data cache management
 *
 * @since 0.0.1
 */
class Rest_Controller extends Api_Base {

	/**
	 * Register routes
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function register_routes() {
		// SaaS authentication bridging.
		$this->register_auth_routes();
	}


	/**
	 * Register authentication routes (WordPress ↔ SaaS bridging)
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function register_auth_routes() {
		$namespace = $this->get_api_namespace();

		register_rest_route(
			$namespace,
			'/auth/disconnect',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'disconnect_from_saas' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);

		// Reverse-callback target. Called by the SaaS during provisioning to
		// prove this site controls the URL the plugin claimed. Public on
		// purpose — the SaaS calls it server-to-server before any bearer
		// token exists. Echo-the-token is the entire contract.
		register_rest_route(
			$namespace,
			'/verify',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'verify_site_callback' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'token' => array(
							'required'          => true,
							'type'              => 'string',
							'maxLength'         => 128,
							'description'       => 'Nonce supplied by the SaaS; echoed back to prove site control.',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// In-plugin signup → provision a new SaaS account + workspace and auto-connect.
		register_rest_route(
			$namespace,
			'/auth/provision',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'provision_saas_account' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'first_name' => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => 'Account holder first name.',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_name_param' ),
						),
						'last_name'  => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => 'Account holder last name.',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_name_param' ),
						),
						'email'      => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => 'Account holder email address.',
							'sanitize_callback' => 'sanitize_email',
							'validate_callback' => array( $this, 'validate_email_param' ),
						),
					),
				),
			)
		);

		// SaaS-initiated disconnect endpoint (for external platform to disconnect).
		register_rest_route(
			$namespace,
			'/auth/saas-disconnect',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saas_initiated_disconnect' ),
					'permission_callback' => array( $this, 'validate_saas_bearer_token' ),
					'args'                => array(
						'workspace_uuid' => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => 'Workspace UUID to verify the disconnect request',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	// ===========================================
	// AUTHENTICATION BRIDGING (WordPress ↔ SaaS)
	// ===========================================

	/**
	 * Validate SaaS bearer token for SaaS-initiated requests
	 *
	 * @since 0.0.3
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error True if valid, WP_Error if invalid.
	 */
	public function validate_saas_bearer_token( $request ) {
		// Get Authorization header.
		$auth_header = $request->get_header( 'Authorization' );

		if ( empty( $auth_header ) ) {
			return new WP_Error(
				'surecontact_missing_auth',
				__( 'Authorization header is required.', 'surecontact' ),
				array( 'status' => 401 )
			);
		}

		// Extract bearer token from "Bearer {token}" format.
		if ( ! preg_match( '/Bearer\s+(.+)/i', $auth_header, $matches ) ) {
			return new WP_Error(
				'surecontact_invalid_auth_format',
				__( 'Invalid authorization header format. Expected: Bearer {token}', 'surecontact' ),
				array( 'status' => 401 )
			);
		}

		$provided_token = trim( $matches[1] );

		// Get stored bearer token.
		$auth_manager = new \SureContact\Auth_Manager();
		$stored_token = $auth_manager->get_bearer_token();

		if ( empty( $stored_token ) ) {
			return new WP_Error(
				'surecontact_no_connection',
				__( 'No active connection found.', 'surecontact' ),
				array( 'status' => 404 )
			);
		}

		// Verify token matches.
		if ( ! hash_equals( $stored_token, $provided_token ) ) {
			return new WP_Error(
				'surecontact_invalid_token',
				__( 'Invalid bearer token.', 'surecontact' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Echo back the SaaS-supplied nonce to prove this site is in control of
	 * the URL the plugin claimed during provisioning. The SaaS hits this
	 * before issuing any token, so the endpoint cannot require auth.
	 *
	 * @since 1.5.1
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function verify_site_callback( $request ) {
		return rest_ensure_response(
			array(
				'token' => (string) $request->get_param( 'token' ),
			)
		);
	}

	/**
	 * Validate name param (1–60 chars after sanitisation).
	 *
	 * @since 1.5.1
	 *
	 * @param mixed $value Value to validate.
	 * @return bool|\WP_Error
	 */
	public function validate_name_param( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );

		if ( '' === $value || $len > 60 ) {
			return new WP_Error(
				'surecontact_invalid_name',
				__( 'Name must be between 1 and 60 characters.', 'surecontact' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Validate email param.
	 *
	 * @since 1.5.1
	 *
	 * @param mixed $value Value to validate.
	 * @return bool|\WP_Error
	 */
	public function validate_email_param( $value ) {
		if ( ! is_string( $value ) || ! is_email( $value ) ) {
			return new WP_Error(
				'surecontact_invalid_email',
				__( 'A valid email address is required.', 'surecontact' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Provision a new SaaS account + workspace and auto-connect this site.
	 *
	 * Short-circuits with 409 if the site is already connected, otherwise
	 * delegates to Auth_Manager::provision_account(). On failure, the
	 * SaaS-provided error code (e.g. `email_exists`) is preserved on the
	 * returned WP_Error so the client can branch on it.
	 *
	 * @since 1.5.1
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function provision_saas_account( $request ) {
		$auth_manager = new \SureContact\Auth_Manager();

		if ( $auth_manager->is_authenticated() ) {
			return new WP_Error(
				'surecontact_already_connected',
				__( 'This site is already connected to SureContact.', 'surecontact' ),
				array( 'status' => 409 )
			);
		}

		$result = $auth_manager->provision_account(
			(string) $request->get_param( 'first_name' ),
			(string) $request->get_param( 'last_name' ),
			(string) $request->get_param( 'email' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Account created and connected to SureContact.', 'surecontact' ),
			)
		);
	}

	/**
	 * Disconnect from SaaS platform (user-initiated)
	 *
	 * @since 0.0.1
	 *
	 * @return \WP_REST_Response Response object.
	 */
	public function disconnect_from_saas() {
		$this->perform_disconnect();

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Successfully disconnected from SureContact.', 'surecontact' ),
			)
		);
	}

	/**
	 * Handle SaaS-initiated disconnect request
	 *
	 * This endpoint is called by the SaaS platform when it wants to disconnect
	 * the connection from their side. It uses bearer token authentication instead
	 * of WordPress user authentication.
	 *
	 * @since 0.0.3
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response object or error.
	 */
	public function saas_initiated_disconnect( $request ) {
		// Validate workspace UUID matches stored value.
		$provided_uuid = $request->get_param( 'workspace_uuid' );
		$stored_uuid   = get_option( 'surecontact_workspace_uuid', '' );

		if ( empty( $stored_uuid ) || ! hash_equals( $stored_uuid, $provided_uuid ) ) {
			return new WP_Error(
				'surecontact_invalid_workspace',
				__( 'Workspace UUID does not match.', 'surecontact' ),
				array( 'status' => 403 )
			);
		}

		$this->perform_disconnect();

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Connection successfully disconnected by SaaS platform.', 'surecontact' ),
			)
		);
	}

	/**
	 * Perform the actual disconnect by clearing all SaaS-related options
	 *
	 * @since 0.0.3
	 *
	 * @return void
	 */
	private function perform_disconnect() {
		// Authentication & Connection.
		delete_option( 'surecontact_bearer_token' );
		delete_option( 'surecontact_connection_id' );
		delete_option( 'surecontact_workspace_uuid' );

		// Sync & Cache.
		delete_option( 'surecontact_last_status_sync' );
		delete_option( 'surecontact_last_bulk_sync' );
		delete_option( 'surecontact_last_field_sync' );
		delete_option( 'surecontact_sync_batches' );
		delete_option( 'surecontact_sync_job_cancelled' );

		// Metadata & Fields.
		delete_option( 'surecontact_synced_metadata' );
		delete_option( 'surecontact_contact_fields' );
		delete_option( 'surecontact_custom_metafields' );

		// Settings.
		delete_option( 'surecontact_general_settings' );

		// Note: Database version options (surecontact_db_version, surecontact_api_queue_table_version,
		// surecontact_integrations_table_version) are intentionally NOT deleted on disconnect.
		// These track table creation and data migrations, which should persist across reconnections.
		// They are only deleted on full plugin uninstall (see uninstall.php).

		// Clean up transients.
		$this->delete_transients();

		// Clean up dynamic job options (surecontact_job_*).
		$this->delete_job_options();
	}

	/**
	 * Delete all job-related options (surecontact_job_*)
	 *
	 * @since 0.0.3
	 *
	 * @return void
	 */
	private function delete_job_options() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk delete operation for performance.
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE 'surecontact\_job\_%'"
		);
	}

	/**
	 * Delete all plugin-related transients
	 *
	 * @since 0.0.3
	 *
	 * @return void
	 */
	private function delete_transients() {
		global $wpdb;

		// Delete general transients.
		delete_transient( 'surecontact_api_circuit_breaker' );

		// Delete dynamic transients (surecontact_woo_processing_*, surecontact_surecart_tracked_*).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk delete operation for performance.
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '\_transient\_surecontact\_%'
			 OR option_name LIKE '\_transient\_timeout\_surecontact\_%'"
		);
	}
}
