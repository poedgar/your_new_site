<?php
/**
 * Authentication Manager class
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact;

use SureContact\Encryption;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authentication Manager class
 * Handles OAuth authentication with the SaaS platform
 *
 * @since 0.0.1
 */
class Auth_Manager {


	/**
	 * Option name for storing the auth token
	 *
	 * @since 0.0.1
	 */
	const TOKEN_OPTION = 'surecontact_auth_token';

	/**
	 * Option name for storing the bearer token
	 *
	 * @since 0.0.1
	 */
	const BEARER_TOKEN_OPTION = 'surecontact_bearer_token';

	/**
	 * SaaS authentication URL
	 *
	 * @since 0.0.1
	 */
	const SAAS_AUTH_URL = SURECONTACT_SAAS_BASE_URL . '/connect';

	/**
	 * SaaS token exchange URL
	 *
	 * @since 0.0.1
	 */
	const TOKEN_EXCHANGE_URL = SURECONTACT_SAAS_API_BASE_URL . '/api/v1/connections/exchange';

	/**
	 * SaaS in-plugin provisioning URL
	 *
	 * @since 1.5.1
	 */
	const PROVISION_URL = SURECONTACT_SAAS_API_BASE_URL . '/api/v1/surecontact/provision';

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
		add_action( 'admin_init', array( $this, 'check_authentication' ) );
	}

	/**
	 * Check if user is authenticated
	 *
	 * @since 0.0.1
	 *
	 * @return bool
	 */
	public function is_authenticated() {
		$token = $this->get_bearer_token();
		return ! empty( $token );
	}

	/**
	 * Get the stored bearer token
	 *
	 * @since 0.0.1
	 *
	 * @return string|false
	 */
	public function get_bearer_token() {
		// Get from database option.
		$encrypted_token = get_option( self::BEARER_TOKEN_OPTION, false );
		if ( $encrypted_token ) {
			return Encryption::decrypt( $encrypted_token );
		}

		return false;
	}

	/**
	 * Get the stored workspace UUID
	 *
	 * @since 0.0.1
	 *
	 * @return string|false
	 */
	public function get_workspace_uuid() {
		return get_option( 'surecontact_workspace_uuid', false );
	}


	/**
	 * Check if there's an authentication error
	 *
	 * @since 0.0.1
	 *
	 * @return bool
	 */
	public function has_auth_error() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback, nonce not applicable for external redirects.
		return isset( $_GET['auth_error'] ) && ! empty( $_GET['auth_error'] );
	}

	/**
	 * Store the bearer token
	 *
	 * @since 0.0.1
	 *
	 * @param string $token Bearer token.
	 * @return bool
	 */
	private function store_bearer_token( $token ) {
		// Store in database.
		return update_option( self::BEARER_TOKEN_OPTION, Encryption::encrypt( sanitize_text_field( $token ) ) );
	}


	/**
	 * Get the OAuth callback URL
	 *
	 * @since 0.0.1
	 *
	 * @return string
	 */
	public function get_callback_url() {
		return admin_url( 'admin.php?page=surecontact-dashboard' );
	}

	/**
	 * Get the authentication URL
	 *
	 * @since 0.0.1
	 *
	 * @return string
	 */
	public function get_auth_url() {
		$callback_url = $this->get_callback_url();
		$state        = $this->generate_oauth_state();

		$params = array(
			'oauth_url' => rawurlencode( $callback_url ),
		);

		// Include state parameter if generated successfully.
		if ( $state ) {
			$params['state'] = $state;
		}

		return add_query_arg( $params, self::SAAS_AUTH_URL );
	}

	/**
	 * Handle OAuth callback
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function handle_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth callback from external SaaS, nonce not applicable

		// First check if we have oauth_token in the URL
		// Handle both proper format and malformed URLs.
		$oauth_token = null;

		// Check standard $_GET parameter.
		if ( isset( $_GET['oauth_token'] ) ) {
			$oauth_token = sanitize_text_field( wp_unslash( $_GET['oauth_token'] ) );
		} else {
			// Handle malformed URL with double question mark.
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			if ( $request_uri && preg_match( '/[?&]oauth_token=([^&]+)/', $request_uri, $matches ) ) {
				$oauth_token = sanitize_text_field( $matches[1] );
			}
		}

		// If no token found, return early.
		if ( ! $oauth_token ) {
			return;
		}

		// Check if we're on our plugin page.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! $page ) {
			// Try to extract page from URL if not in $_GET.
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			if ( $request_uri && preg_match( '/page=([^&?]+)/', $request_uri, $matches ) ) {
				$page = sanitize_text_field( $matches[1] );
			}
		}

		if ( ! $page || strpos( $page, 'surecontact' ) !== 0 ) {
			return;
		}

		// Validate state parameter for CSRF protection.
		// State is URL-safe base64 (see generate_oauth_state) so the format is
		// strictly [A-Za-z0-9_-]. We sanitize (defensive) AND then enforce the
		// exact format — garbage gets rejected before reaching the HMAC verifier.
		$state         = '';
		$state_pattern = '/^[A-Za-z0-9_-]+$/';

		if ( isset( $_GET['state'] ) ) {
			$candidate = sanitize_text_field( wp_unslash( $_GET['state'] ) );
			if ( preg_match( $state_pattern, $candidate ) ) {
				$state = $candidate;
			}
		} else {
			// Handle malformed URL with double question mark.
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			if ( $request_uri && preg_match( '/[?&]state=([^&]+)/', $request_uri, $matches ) ) {
				$candidate = sanitize_text_field( $matches[1] );
				if ( preg_match( $state_pattern, $candidate ) ) {
					$state = $candidate;
				}
			}
		}

		if ( ! $this->validate_oauth_state( $state ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . $page . '&auth_error=invalid_state' ) );
			exit;
		}

		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Exchange token (pass state for SaaS validation).
		$result = $this->exchange_token( $oauth_token, $state );

		if ( $result ) {
			// Redirect to remove oauth_token and state from URL.
			wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
			exit;
		} else {
			// Redirect with error parameter.
			wp_safe_redirect( admin_url( 'admin.php?page=' . $page . '&auth_error=1' ) );
			exit;
		}
	}

	/**
	 * Exchange OAuth token for bearer token
	 *
	 * @since 0.0.1
	 *
	 * @param string $oauth_token OAuth token from callback.
	 * @param string $state State parameter for CSRF validation.
	 * @return bool
	 */
	private function exchange_token( $oauth_token, $state = '' ) {
		try {
			$body     = array(
				'oauth_token' => $oauth_token,
				'state'       => $state,
				'site_url'    => rest_url( 'surecontact/v1/' ),
			);
			$response = wp_remote_post(
				self::TOKEN_EXCHANGE_URL,
				array(
					'headers' => array(
						'Content-Type' => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$body          = wp_remote_retrieve_body( $response );
			$result        = json_decode( $body, true );

			// Check HTTP status code.
			if ( $response_code !== 200 && $response_code !== 201 ) {
				return false;
			}

			// Check if the response is successful and has the expected structure.
			if ( ! empty( $result['success'] ) && $result['success'] === true && ! empty( $result['data'] ) ) {
				$data = $result['data'];
				// Store the access token as bearer token.
				if ( ! empty( $data['access_token'] ) ) {
					$this->store_bearer_token( $data['access_token'] );

					// Also store the connection ID if needed.
					if ( ! empty( $data['connection_id'] ) ) {
						update_option( 'surecontact_connection_id', sanitize_text_field( $data['connection_id'] ) );
					}

					if ( ! empty( $data['workspace_uuid'] ) ) {
						update_option( 'surecontact_workspace_uuid', sanitize_text_field( $data['workspace_uuid'] ) );
					}

					// Trigger initial sync of lists, tags, and custom fields.
					$this->trigger_initial_sync();

					return true;
				}
			}

			return false;
		} catch ( \Exception $e ) {
			// Log error for debugging and return false.
			return false;
		}
	}

	/**
	 * Provision a new SaaS account and auto-connect this site.
	 *
	 * Calls the unauthenticated provisioning endpoint with first/last/email and
	 * site identity. On success, persists the returned access_token,
	 * connection_id, and workspace_uuid using the same storage path as the
	 * OAuth exchange flow, then kicks off the initial metadata sync.
	 *
	 * @since 1.5.1
	 *
	 * @param string $first_name First name.
	 * @param string $last_name  Last name.
	 * @param string $email      Email address.
	 * @return true|\WP_Error True on success, WP_Error with SaaS-provided code on failure.
	 */
	public function provision_account( $first_name, $last_name, $email ) {
		$first_name = sanitize_text_field( $first_name );
		$last_name  = sanitize_text_field( $last_name );
		$email      = sanitize_email( $email );

		if ( '' === $first_name || '' === $last_name ) {
			return new \WP_Error(
				'surecontact_invalid_name',
				__( 'First and last name are required.', 'surecontact' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error(
				'surecontact_invalid_email',
				__( 'A valid email address is required.', 'surecontact' ),
				array( 'status' => 400 )
			);
		}

		$body = array(
			'first_name'     => $first_name,
			'last_name'      => $last_name,
			'email'          => $email,
			// REST namespace URL — matches the shape the OAuth exchange
			// flow already sends (see exchange_token()), so the SaaS stores
			// `Connection.site_url` identically across both onboarding
			// paths and downstream consumers (disconnect notifier,
			// webhook dispatcher) build URLs uniformly.
			'site_url'       => rest_url( 'surecontact/v1/' ),
			'site_name'      => get_bloginfo( 'name' ),
			'plugin_version' => SURECONTACT_VERSION,
			'locale'         => get_locale(),
		);

		$encoded_body = wp_json_encode( $body );
		if ( false === $encoded_body ) {
			return new \WP_Error(
				'surecontact_provision_invalid_response',
				__( 'Unable to prepare signup request. Please try again.', 'surecontact' ),
				array( 'status' => 500 )
			);
		}

		$response = wp_remote_post(
			self::PROVISION_URL,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => $encoded_body,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'surecontact_provision_network_error',
				$response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$raw_body      = wp_remote_retrieve_body( $response );
		$result        = json_decode( $raw_body, true );

		// Failure path — map SaaS error code/message to a WP_Error.
		if ( 200 !== $response_code && 201 !== $response_code ) {
			// SaaS error codes are documented as snake_case ([a-z0-9_-]).
			// Use a non-destructive whitelist rather than sanitize_key() so
			// an unexpected code (e.g. mixed-case from a future SaaS change)
			// reaches the frontend intact for branching, not silently mangled.
			$code = is_array( $result ) && ! empty( $result['code'] )
				? (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $result['code'] )
				: 'surecontact_provision_failed';
			if ( '' === $code ) {
				$code = 'surecontact_provision_failed';
			}

			$message = is_array( $result ) && ! empty( $result['message'] )
				? wp_strip_all_tags( (string) $result['message'] )
				: __( 'Unable to create your SureContact account. Please try again.', 'surecontact' );
			// Cap at 300 chars — admin-toast surface, defense-in-depth against
			// an unbounded server message.
			if ( function_exists( 'mb_substr' ) ) {
				$message = mb_substr( $message, 0, 300 );
			} else {
				$message = substr( $message, 0, 300 );
			}

			return new \WP_Error(
				$code,
				$message,
				array( 'status' => $response_code ? $response_code : 500 )
			);
		}

		// Defensive: success status but malformed envelope.
		if ( ! is_array( $result ) || empty( $result['success'] ) || true !== $result['success'] || empty( $result['data'] ) || ! is_array( $result['data'] ) ) {
			return new \WP_Error(
				'surecontact_provision_invalid_response',
				__( 'Unexpected response from SureContact. Please try again.', 'surecontact' ),
				array( 'status' => 502 )
			);
		}

		$data           = $result['data'];
		$access_token   = isset( $data['access_token'] ) ? (string) $data['access_token'] : '';
		$connection_id  = isset( $data['connection_id'] ) ? (string) $data['connection_id'] : '';
		$workspace_uuid = isset( $data['workspace_uuid'] ) ? (string) $data['workspace_uuid'] : '';

		if ( '' === $access_token || '' === $workspace_uuid ) {
			return new \WP_Error(
				'surecontact_provision_invalid_response',
				__( 'Unexpected response from SureContact. Please try again.', 'surecontact' ),
				array( 'status' => 502 )
			);
		}

		// All required fields validated — persist atomically.
		$this->store_bearer_token( $access_token );
		update_option( 'surecontact_workspace_uuid', sanitize_text_field( $workspace_uuid ) );

		if ( '' !== $connection_id ) {
			update_option( 'surecontact_connection_id', sanitize_text_field( $connection_id ) );
		}

		$this->trigger_initial_sync();

		return true;
	}

	/**
	 * Check authentication on admin pages
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function check_authentication() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading page parameter for navigation, nonce not applicable

		// Only check on our plugin pages.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! $page || strpos( $page, 'surecontact' ) !== 0 ) {
			return;
		}

		// Skip if already authenticated.
		if ( $this->is_authenticated() ) {
			return;
		}

		// Skip if we're handling OAuth callback.
		$oauth_token = isset( $_GET['oauth_token'] ) ? sanitize_text_field( wp_unslash( $_GET['oauth_token'] ) ) : '';
		if ( $oauth_token ) {
			return;
		}

        // phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Show authentication page.
		add_action( 'admin_menu', array( $this, 'override_menu_pages' ), 999 );
	}

	/**
	 * Override menu pages to show auth screen
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function override_menu_pages() {
		global $submenu;

		// Remove all submenu items for our plugin.
		if ( isset( $submenu['surecontact-dashboard'] ) ) {
			unset( $submenu['surecontact-dashboard'] );
		}
	}

	/**
	 * Generate OAuth state token for CSRF protection.
	 *
	 * Uses HMAC-signed stateless token instead of transients to avoid issues
	 * with caching plugins, object cache flushes, and domain transfers.
	 *
	 * @since 0.0.4
	 * @since 1.4.0 Replaced transient-based state with HMAC-signed stateless token.
	 *
	 * @return string|false State token or false on failure
	 */
	private function generate_oauth_state() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$timestamp = time();
		$payload   = $user_id . '|' . $timestamp;
		$signature = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );

		// Encode as URL-safe base64 (no padding) to avoid issues with = in URLs.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Used for URL-safe encoding of state token, not obfuscation.
		return rtrim( strtr( base64_encode( $payload . '|' . $signature ), '+/', '-_' ), '=' );
	}

	/**
	 * Validate OAuth state token for CSRF protection.
	 *
	 * Verifies the HMAC signature and checks that the token belongs to the
	 * current user and has not expired (10-minute window).
	 *
	 * @since 0.0.4
	 * @since 1.4.0 Replaced transient-based validation with HMAC signature verification.
	 *
	 * @param string $state State token to validate.
	 * @return bool True if valid, false otherwise
	 */
	private function validate_oauth_state( $state ) {
		if ( empty( $state ) ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		// Decode URL-safe base64.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding HMAC state token, not obfuscation.
		$decoded = base64_decode( strtr( $state, '-_', '+/' ), true );
		if ( false === $decoded ) {
			return false;
		}

		$parts = explode( '|', $decoded );
		if ( 3 !== count( $parts ) ) {
			return false;
		}

		list( $token_user_id, $timestamp, $signature ) = $parts;

		// Verify user matches.
		if ( (int) $token_user_id !== $user_id ) {
			return false;
		}

		// HMAC tokens are valid for the full 10-minute window (not single-use).
		// Replay protection is provided by the SaaS exchange endpoint's
		// single-use enforcement on oauth_token.
		// Verify not expired (10-minute window) and not in the future.
		$elapsed = time() - (int) $timestamp;
		if ( $elapsed < 0 || $elapsed > 600 ) {
			return false;
		}

		// Recompute HMAC and verify with timing-safe comparison.
		$expected_signature = hash_hmac( 'sha256', $token_user_id . '|' . $timestamp, wp_salt( 'auth' ) );

		return hash_equals( $expected_signature, $signature );
	}

	/**
	 * Trigger initial sync of lists, tags, and custom fields after successful connection
	 *
	 * @since 0.0.4
	 *
	 * @return void
	 */
	private function trigger_initial_sync() {
		try {
			// Sync lists and tags.
			$lists_tags_api = \SureContact\API_WP\Lists_Tags_API::instance();
			$lists_tags_api->sync_lists_and_tags( new \WP_REST_Request() );

			// Sync custom fields.
			$field_mapper = new Field_Mapper();
			$field_mapper->sync_crm_fields();
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Silently fail - user can manually sync later from settings.
			// Connection should still succeed even if sync fails.
			unset( $e );
		}
	}
}
