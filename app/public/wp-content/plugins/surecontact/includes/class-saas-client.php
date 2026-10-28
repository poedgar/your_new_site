<?php
/**
 * SaaS API Client
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact;

use WP_Error;

/**
 * Class SaaS_Client
 *
 * Handles communication with the external SaaS API
 *
 * @since 0.0.1
 */
class SaaS_Client {


	/**
	 * API base URL
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	private $api_base_url = SURECONTACT_SAAS_API_BASE_URL . '/api/v1';

	/**
	 * Auth Manager instance
	 *
	 * @since 0.0.1
	 *
	 * @var Auth_Manager
	 */
	private $auth_manager;

	/**
	 * Last HTTP response code from an API request
	 *
	 * @since 1.4.0
	 *
	 * @var int|null
	 */
	private $last_response_code = null;

	/**
	 * Whether ANY SaaS request in the current PHP request was rate-limited
	 * (HTTP 429 from the throttle middleware) or rejected for hitting the
	 * workspace plan quota (HTTP 403 plan_limit_exceeded). Bulk-sync loops
	 * check this once per work unit to decide whether to back off and
	 * reschedule the next batch with a delay.
	 *
	 * Static so the signal is visible across every SaaS_Client instance in
	 * the same PHP request (e.g. Company_Service and Contact_Service each
	 * construct their own client, but a 429 on either should trip the
	 * single shared brake).
	 *
	 * @since 1.5.1
	 *
	 * @var bool
	 */
	private static $rate_limited_this_request = false;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 *
	 * @param Auth_Manager $auth_manager Optional. Auth manager instance.
	 */
	public function __construct( ?Auth_Manager $auth_manager = null ) {
		$this->auth_manager = $auth_manager ? $auth_manager : new Auth_Manager();
	}

	/**
	 * Get the last HTTP response code
	 *
	 * @since 1.4.0
	 *
	 * @return int|null Last response code, or null if no request was made.
	 */
	public function get_last_response_code() {
		return $this->last_response_code;
	}

	/**
	 * Whether any SaaS request in this PHP request was rate-limited or
	 * plan-quota-rejected. Bulk-sync loops read this to break + reschedule.
	 *
	 * @since 1.5.1
	 *
	 * @phpstan-impure The return value depends on whether any SaaS HTTP
	 *                 call set the static flag — repeated checks across an
	 *                 enclosing block can legitimately disagree.
	 *
	 * @return bool
	 */
	public static function was_rate_limited_this_request() {
		return self::$rate_limited_this_request;
	}

	/**
	 * Clear the rate-limit signal. Provided for tests; production code never
	 * needs to call this — PHP starts each request with the static reset to
	 * its default `false` value.
	 *
	 * @since 1.5.1
	 *
	 * @return void
	 */
	public static function reset_rate_limit_signal() {
		self::$rate_limited_this_request = false;
	}

	/**
	 * Mark this PHP request as rate-limited based on a SaaS response.
	 *
	 * Centralized in SaaS_Client so every call site (Company, Contact, Lists,
	 * Notes, …) trips the same brake — bulk-sync loops only need to check
	 * one flag.
	 *
	 * @since 1.5.1
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Raw response body.
	 * @return void
	 */
	private function maybe_flag_rate_limit( $code, $body ) {
		if ( self::$rate_limited_this_request ) {
			return;
		}

		if ( 429 === $code ) {
			self::$rate_limited_this_request = true;
			return;
		}

		if ( 403 === $code && is_string( $body ) && false !== stripos( $body, 'plan_limit_exceeded' ) ) {
			self::$rate_limited_this_request = true;
		}
	}

	/**
	 * Get default headers for API requests
	 *
	 * @since 0.0.1
	 *
	 * @return array
	 */
	private function get_default_headers() {
		$bearer_token = $this->auth_manager->get_bearer_token();

		if ( ! $bearer_token ) {
			return array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			);
		}

		return array(
			'Authorization'    => 'Bearer ' . $bearer_token,
			'Accept'           => 'application/json',
			'Content-Type'     => 'application/json',
			'X-Workspace-UUID' => $this->get_workspace_uuid(),
		);
	}

	/**
	 * Get workspace UUID from Auth Manager
	 *
	 * @since 0.0.1
	 *
	 * @return string|false
	 */
	private function get_workspace_uuid() {
		return $this->auth_manager->get_workspace_uuid();
	}

	/**
	 * Add workspace UUID to request data if available
	 *
	 * @since 0.0.1
	 *
	 * @param array $data Request data.
	 * @return array Modified request data with workspace_uuid.
	 */
	private function add_workspace_uuid( $data ) {
		$workspace_uuid = $this->get_workspace_uuid();

		if ( $workspace_uuid && ! isset( $data['workspace_uuid'] ) ) {
			$data['workspace_uuid'] = $workspace_uuid;
		}

		return $data;
	}

	/**
	 * Make a GET request to the SaaS API
	 *
	 * @since 0.0.1
	 *
	 * @param string $endpoint The API endpoint (relative to base URL).
	 * @param array  $args     Optional. Additional arguments for wp_remote_get.
	 * @return array|WP_Error The response or WP_Error on failure.
	 */
	public function get( $endpoint, $args = array() ) {
		try {
			$url = $this->api_base_url . '/' . ltrim( $endpoint, '/' );

			$default_args = array(
				'headers' => $this->get_default_headers(),
				'timeout' => 30,
			);

			$args = wp_parse_args( $args, $default_args );

			$response = wp_remote_get( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body                     = wp_remote_retrieve_body( $response );
			$code                     = wp_remote_retrieve_response_code( $response );
			$this->last_response_code = (int) $code;

			if ( 200 !== $code ) {
				$this->maybe_flag_rate_limit( (int) $code, $body );
				return new WP_Error(
					'saas_api_error',
					$this->extract_error_message( $body, (int) $code ),
					array(
						'body' => $body,
						'code' => $code,
					)
				);
			}

			$data = json_decode( $body, true );

			if ( null === $data && ! empty( $body ) ) {
				return new WP_Error( 'saas_api_invalid_json', __( 'Invalid JSON response from API', 'surecontact' ) );
			}

			return $data;
		} catch ( \Exception $e ) {
			return new WP_Error(
				'saas_api_exception',
				/* translators: %s: exception message */
				sprintf( __( 'Exception during API request: %s', 'surecontact' ), $e->getMessage() )
			);
		}
	}

	/**
	 * Make a POST request to the SaaS API
	 *
	 * @since 0.0.1
	 *
	 * @param string $endpoint The API endpoint (relative to base URL).
	 * @param array  $data     The data to send.
	 * @param array  $args     Optional. Additional arguments for wp_remote_post.
	 * @return array|WP_Error The response or WP_Error on failure.
	 */
	public function post( $endpoint, $data = array(), $args = array() ) {
		try {
			$url = $this->api_base_url . '/' . ltrim( $endpoint, '/' );

			// Automatically add workspace_uuid to data.
			$data = $this->add_workspace_uuid( $data );

			$default_args = array(
				'headers' => $this->get_default_headers(),
				'body'    => wp_json_encode( $data ),
				'timeout' => 30,
			);

			$args = wp_parse_args( $args, $default_args );

			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body                     = wp_remote_retrieve_body( $response );
			$code                     = wp_remote_retrieve_response_code( $response );
			$this->last_response_code = (int) $code;

			if ( ! in_array( $code, array( 200, 201, 202 ), true ) ) {
				$this->maybe_flag_rate_limit( (int) $code, $body );
				return new WP_Error(
					'saas_api_error',
					$this->extract_error_message( $body, (int) $code ),
					array(
						'body' => $body,
						'code' => $code,
					)
				);
			}

			$data = json_decode( $body, true );

			if ( null === $data && ! empty( $body ) ) {
				return new WP_Error( 'saas_api_invalid_json', __( 'Invalid JSON response from API', 'surecontact' ) );
			}

			return $data;
		} catch ( \Exception $e ) {
			return new WP_Error(
				'saas_api_exception',
				/* translators: %s: exception message */
				sprintf( __( 'Exception during API request: %s', 'surecontact' ), $e->getMessage() )
			);
		}
	}

	/**
	 * Make a PUT request to the SaaS API
	 *
	 * @since 0.0.1
	 *
	 * @param string $endpoint The API endpoint (relative to base URL).
	 * @param array  $data     The data to send.
	 * @param array  $args     Optional. Additional arguments for wp_remote_request.
	 * @return array|WP_Error The response or WP_Error on failure.
	 */
	public function put( $endpoint, $data = array(), $args = array() ) {
		try {
			$url = $this->api_base_url . '/' . ltrim( $endpoint, '/' );

			// Automatically add workspace_uuid to data.
			$data = $this->add_workspace_uuid( $data );

			$default_args = array(
				'method'  => 'PUT',
				'headers' => $this->get_default_headers(),
				'body'    => wp_json_encode( $data ),
				'timeout' => 30,
			);

			$args = wp_parse_args( $args, $default_args );

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body                     = wp_remote_retrieve_body( $response );
			$code                     = wp_remote_retrieve_response_code( $response );
			$this->last_response_code = (int) $code;

			if ( 200 !== $code ) {
				$this->maybe_flag_rate_limit( (int) $code, $body );
				return new WP_Error(
					'saas_api_error',
					$this->extract_error_message( $body, (int) $code ),
					array(
						'body' => $body,
						'code' => $code,
					)
				);
			}

			$data = json_decode( $body, true );

			if ( null === $data && ! empty( $body ) ) {
				return new WP_Error( 'saas_api_invalid_json', __( 'Invalid JSON response from API', 'surecontact' ) );
			}

			return $data;
		} catch ( \Exception $e ) {
			return new WP_Error(
				'saas_api_exception',
				/* translators: %s: exception message */
				sprintf( __( 'Exception during API request: %s', 'surecontact' ), $e->getMessage() )
			);
		}
	}

	/**
	 * Make a DELETE request to the SaaS API
	 *
	 * @since 0.0.1
	 *
	 * @param string $endpoint The API endpoint (relative to base URL).
	 * @param array  $args     Optional. Additional arguments for wp_remote_request.
	 * @return array|WP_Error The response or WP_Error on failure.
	 */
	public function delete( $endpoint, $args = array() ) {
		try {
			$url = $this->api_base_url . '/' . ltrim( $endpoint, '/' );

			$default_args = array(
				'method'  => 'DELETE',
				'headers' => $this->get_default_headers(),
				'timeout' => 30,
			);

			$args = wp_parse_args( $args, $default_args );

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code                     = wp_remote_retrieve_response_code( $response );
			$this->last_response_code = (int) $code;

			if ( ! in_array( $code, array( 200, 204 ), true ) ) {
				$body = wp_remote_retrieve_body( $response );
				$this->maybe_flag_rate_limit( (int) $code, $body );
				return new WP_Error(
					'saas_api_error',
					$this->extract_error_message( $body, (int) $code ),
					array(
						'body' => $body,
						'code' => $code,
					)
				);
			}

			return true;
		} catch ( \Exception $e ) {
			return new WP_Error(
				'saas_api_exception',
				/* translators: %s: exception message */
				sprintf( __( 'Exception during API request: %s', 'surecontact' ), $e->getMessage() )
			);
		}
	}

	/**
	 * Make a PATCH request to the SaaS API
	 *
	 * @since 0.0.1
	 *
	 * @param string $endpoint The API endpoint (relative to base URL).
	 * @param array  $data     The data to send.
	 * @param array  $args     Optional. Additional arguments for wp_remote_request.
	 * @return array|WP_Error The response or WP_Error on failure.
	 */
	public function patch( $endpoint, $data = array(), $args = array() ) {
		try {
			$url = $this->api_base_url . '/' . ltrim( $endpoint, '/' );

			// Automatically add workspace_uuid to data.
			$data = $this->add_workspace_uuid( $data );

			$default_args = array(
				'method'  => 'PATCH',
				'headers' => $this->get_default_headers(),
				'body'    => wp_json_encode( $data ),
				'timeout' => 30,
			);

			$args = wp_parse_args( $args, $default_args );

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body                     = wp_remote_retrieve_body( $response );
			$code                     = wp_remote_retrieve_response_code( $response );
			$this->last_response_code = (int) $code;

			if ( 200 !== $code ) {
				$this->maybe_flag_rate_limit( (int) $code, $body );
				return new WP_Error(
					'saas_api_error',
					$this->extract_error_message( $body, (int) $code ),
					array(
						'body' => $body,
						'code' => $code,
					)
				);
			}

			$data = json_decode( $body, true );

			if ( null === $data && ! empty( $body ) ) {
				return new WP_Error( 'saas_api_invalid_json', __( 'Invalid JSON response from API', 'surecontact' ) );
			}

			return $data;
		} catch ( \Exception $e ) {
			return new WP_Error(
				'saas_api_exception',
				/* translators: %s: exception message */
				sprintf( __( 'Exception during API request: %s', 'surecontact' ), $e->getMessage() )
			);
		}
	}

	/**
	 * Extract a user-friendly error message from API response body
	 *
	 * Parses common API error response formats and returns a human-readable message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $body Response body.
	 * @param int    $code HTTP status code.
	 * @return string Human-readable error message.
	 */
	private function extract_error_message( $body, $code ) {
		// Try to parse JSON response.
		$data    = json_decode( $body, true );
		$message = '';

		if ( is_array( $data ) ) {
			// Check for "errors" field first (Laravel validation format).
			// This takes precedence because Laravel returns both a generic "message"
			// and specific "errors", and we want the specific ones.
			if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
				$error_messages = array();
				foreach ( $data['errors'] as $field => $messages ) {
					if ( is_array( $messages ) ) {
						$error_messages = array_merge( $error_messages, $messages );
					} elseif ( is_string( $messages ) ) {
						$error_messages[] = $messages;
					}
				}
				if ( ! empty( $error_messages ) ) {
					$message = implode( ' ', $error_messages );
				}
			}

			if ( '' === $message && ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
				$message = $data['message'];
			}

			if ( '' === $message && ! empty( $data['error'] ) && is_string( $data['error'] ) ) {
				$message = $data['error'];
			}

			// "detail" field (FastAPI and some REST APIs).
			if ( '' === $message && ! empty( $data['detail'] ) && is_string( $data['detail'] ) ) {
				$message = $data['detail'];
			}
		}

		if ( '' === $message ) {
			// Fallback to generic message with status code.
			$message = sprintf(
				/* translators: %d: HTTP status code */
				__( 'Request failed (error %d). Please try again.', 'surecontact' ),
				$code
			);
		}

		// Defense-in-depth: upstream-controlled strings flow into WP_Error
		// and may surface in admin notices. Strip any HTML so the message
		// renders safely even if the surrounding context misses an esc_html().
		return wp_strip_all_tags( $message );
	}

	/**
	 * Update connection status with plugin version and experiments count
	 *
	 * @since 0.0.1
	 *
	 * @param string $workspace_uuid Workspace UUID.
	 * @param string $plugin_version Plugin version.
	 * @return array|WP_Error The response or WP_Error on failure.
	 */
	public function update_connection_status( $workspace_uuid, $plugin_version ) {
		return $this->patch(
			'connections/status',
			array(
				'workspace_uuid' => $workspace_uuid,
				'plugin_version' => $plugin_version,
			)
		);
	}
}
