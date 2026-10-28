<?php
/**
 * API Utils Base Class.
 *
 * Abstract base class for shared API utility functions across modules.
 *
 * @package SureRank\Inc\Functions
 * @since 1.7.2
 */

namespace SureRank\Inc\Functions;

use SureRank\Inc\Modules\Ai_Auth\Controller as Ai_Auth_Controller;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract API_Utils class
 *
 * Base class for modules that need to interact with the credit system API.
 */
abstract class API_Utils {

	/**
	 * Get Credit System API URL.
	 *
	 * @return string API URL.
	 * @since 1.7.2
	 */
	public static function get_credit_system_api_url() {
		if ( ! defined( 'SURERANK_CREDIT_SERVER_API' ) ) {
			define( 'SURERANK_CREDIT_SERVER_API', 'https://credits.startertemplates.com/' );
		}
		return trailingslashit( (string) SURERANK_CREDIT_SERVER_API );
	}

	/**
	 * Get Auth Token.
	 *
	 * @since 1.7.2
	 * @return string|\WP_Error
	 */
	public function get_auth_token() {
		$auth_token = $this->get_auth_data( 'user_email' );
		$token      = '';

		if ( ! is_wp_error( $auth_token ) && is_string( $auth_token ) ) {
			$token = sanitize_text_field( $auth_token );
		}

		if ( '' === $token ) {
			$token = $this->get_license_token();
		}

		$token = apply_filters( 'surerank_content_generation_auth_token', $token );

		if ( empty( $token ) || is_wp_error( $token ) ) {
			return new WP_Error( 'no_auth_token', self::get_missing_auth_token_message() );
		}

		if ( ! is_string( $token ) ) {
			return new WP_Error( 'invalid_auth_token', __( 'Invalid authentication token format.', 'surerank' ) );
		}

		return sanitize_text_field( $token );
	}

	/**
	 * Get custom error messages for API responses.
	 *
	 * @since 1.7.2
	 * @return array<string, string> Array of error codes and their custom messages.
	 */
	public static function get_custom_error_messages() {
		return [
			'internal_server_error' => __( 'Something went wrong on our end. Please try again in a moment, or contact support if you need help.', 'surerank' ),
			'require_pro'           => __( 'You\'ve reached your free usage limit. Upgrade to Premium for additional credits.', 'surerank' ),
			'limit_exceeded'        => __( 'You\'ve used all your AI credits for today. Your credits will refresh automatically tomorrow.', 'surerank' ),
		];
	}

	/**
	 * Message shown when no credit system token could be resolved.
	 *
	 * Pro filters this to point at the license instead of the free account, so the
	 * free plugin does not have to know whether Pro is installed.
	 *
	 * @since 1.10.0
	 * @return string
	 */
	public static function get_missing_auth_token_message() {
		return apply_filters(
			'surerank_missing_auth_token_message',
			__( 'No authentication token found. Please connect your account.', 'surerank' )
		);
	}

	/**
	 * Error codes that a plan upgrade resolves.
	 *
	 * Callers must branch on these codes rather than matching the wording of a
	 * message, which is translated and can change server side at any time.
	 *
	 * @since 1.10.0
	 * @return array<int, string> Error codes.
	 */
	public static function get_upgrade_required_error_codes() {
		return apply_filters( 'surerank_upgrade_required_error_codes', [ 'require_pro' ] );
	}

	/**
	 * Whether an upgrade would resolve the given error code.
	 *
	 * @since 1.10.0
	 * @param string $code Error code returned by the credit system.
	 * @return bool
	 */
	public static function is_upgrade_required_code( $code ) {
		return in_array( $code, self::get_upgrade_required_error_codes(), true );
	}

	/**
	 * Send GET request to service.
	 *
	 * @since 1.7.2
	 * @param string $route   API route.
	 * @param int    $timeout Request timeout in seconds.
	 * @return array<string, mixed>|\WP_Error API response or WP_Error.
	 */
	public function send_get_request( $route, $timeout = 30 ) {
		$auth_token = $this->get_auth_token();

		if ( empty( $auth_token ) || is_wp_error( $auth_token ) ) {
			return new WP_Error( 'no_auth_token', self::get_missing_auth_token_message() );
		}

		$url = $this->build_credit_system_url( $route );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$encoded_token = base64_encode( $auth_token );

		$args = [
			'headers' => [
				'X-Token'      => $encoded_token,
				'Content-Type' => 'application/json; charset=utf-8',
			],
			'timeout' => $timeout, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
		];

		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			// Signature: vip_safe_wp_remote_get( $url, $fallback_value, $threshold, $timeout, $retry, $args ).
			// $args (with the auth headers) is the 6th argument; the function ignores $args['timeout']
			// and uses the 4th argument. An empty fallback keeps failures returning a WP_Error.
			return vip_safe_wp_remote_get( $url, '', 3, $timeout, 20, $args );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		return wp_remote_get( $url, $args );
	}

	/**
	 * Send API request to service.
	 *
	 * @since 1.7.2
	 * @param array<string, mixed> $request_data Request data to send.
	 * @param string               $route        API route.
	 * @param int                  $timeout      Request timeout in seconds.
	 * @return array<string, mixed>|\WP_Error API response or WP_Error.
	 */
	public function send_api_request( $request_data, $route, $timeout = 30 ) {
		$auth_token = $this->get_auth_token();

		if ( empty( $auth_token ) || is_wp_error( $auth_token ) ) {
			return new WP_Error( 'no_auth_token', self::get_missing_auth_token_message() );
		}

		$url = $this->build_credit_system_url( $route );

		$body = wp_json_encode( $request_data );

		if ( false === $body ) {
			return new WP_Error( 'json_encode_error', __( 'Failed to encode request data to JSON.', 'surerank' ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$encoded_token = base64_encode( $auth_token );

		return wp_remote_post(
			$url,
			[
				'headers' => [
					'X-Token'      => $encoded_token,
					'Content-Type' => 'application/json; charset=utf-8',
					// Force JSON error responses; without this a SaaS validation
					// failure can come back as a 302 HTML redirect that masks the
					// real error from the plugin.
					'Accept'       => 'application/json',
				],
				'body'    => $body,
				'timeout' => $timeout, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			]
		);
	}

	/**
	 * Get Auth Data.
	 *
	 * @since 1.7.2
	 * @param string $key Optional. Key to retrieve specific data.
	 * @return array<string, mixed>|string|\WP_Error
	 */
	protected function get_auth_data( $key = '' ) {
		$auth_data = get_option( Ai_Auth_Controller::SETTINGS_KEY, false );

		if ( empty( $auth_data ) ) {
			return new WP_Error( 'no_auth_data', __( 'No authentication data found.', 'surerank' ) );
		}

		if ( ! empty( $key ) && is_string( $key ) ) {
			return $auth_data[ $key ] ?? new WP_Error( 'no_key_found', __( 'No data found for the provided key.', 'surerank' ) );
		}

		return $auth_data;
	}

	/**
	 * Build a normalized credit-system endpoint URL.
	 *
	 * @since 1.7.2
	 * @param string $route API route.
	 * @return string
	 */
	protected function build_credit_system_url( $route ) {
		$base  = self::get_credit_system_api_url();
		$route = ltrim( (string) $route, '/' );

		return $base . $route;
	}

	/**
	 * Get license token as fallback for API authentication.
	 *
	 * @since 1.7.2
	 * @return string
	 */
	protected function get_license_token() {
		$license_status = sanitize_text_field( (string) get_option( 'surerank_pro_license_status', '' ) );
		$license_data   = get_option( 'surerankpro_license_options', [] );

		if ( 'licensed' !== strtolower( $license_status ) || ! is_array( $license_data ) ) {
			return '';
		}

		$license_token = sanitize_text_field( (string) ( $license_data['sc_license_key'] ?? '' ) );
		if ( '' !== $license_token ) {
			return $license_token;
		}

		return sanitize_text_field( (string) ( $license_data['sc_license_id'] ?? '' ) );
	}
}
