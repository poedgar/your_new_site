<?php
/**
 * Centralized Error Response System.
 *
 * Provides consistent error response patterns for all API endpoints, email handlers,
 * and AJAX handlers across the SureMails plugin.
 *
 * @package SureMails\Inc
 * @since 1.10.0
 */

namespace SureMails\Inc;

use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ErrorResponse
 *
 * Centralized error response factory for consistent error handling.
 *
 * Usage:
 *   return ErrorResponse::validation( 'API key is required.' );
 *   return ErrorResponse::not_found( 'Connection not found.' );
 *   return ErrorResponse::auth_failed( 'Invalid API key.' );
 *   return ErrorResponse::server_error( 'Unexpected failure.', $exception );
 *   return ErrorResponse::send_failed( 'brevo', 'Rate limit exceeded.' );
 */
class ErrorResponse {

	// ── Error Codes ──────────────────────────────────────────────────────

	public const VALIDATION_ERROR = 'validation_error';

	public const NOT_FOUND = 'not_found';

	public const AUTH_FAILED = 'auth_failed';

	public const SEND_FAILED = 'send_failed';

	public const SERVER_ERROR = 'server_error';

	public const PERMISSION_DENIED = 'permission_denied';

	public const CONFLICT = 'conflict';

	// ── REST Response Builders ───────────────────────────────────────────

	/**
	 * Validation / bad-request error (HTTP 400).
	 *
	 * @param string $message Human-readable error message.
	 * @return WP_REST_Response
	 */
	public static function validation( string $message ): WP_REST_Response {
		return self::rest( self::VALIDATION_ERROR, $message, 400 );
	}

	/**
	 * Resource not found error (HTTP 404).
	 *
	 * @param string $message Human-readable error message.
	 * @return WP_REST_Response
	 */
	public static function not_found( string $message ): WP_REST_Response {
		return self::rest( self::NOT_FOUND, $message, 404 );
	}

	/**
	 * Authentication failure error (HTTP 401).
	 *
	 * @param string $message Human-readable error message.
	 * @return WP_REST_Response
	 */
	public static function auth_failed( string $message ): WP_REST_Response {
		return self::rest( self::AUTH_FAILED, $message, 401 );
	}

	/**
	 * Permission denied error (HTTP 403).
	 *
	 * @param string $message Human-readable error message.
	 * @return WP_REST_Response
	 */
	public static function permission_denied( string $message ): WP_REST_Response {
		return self::rest( self::PERMISSION_DENIED, $message, 403 );
	}

	/**
	 * Email send failure error (HTTP 502).
	 *
	 * @param string $provider Provider slug (e.g. 'brevo', 'gmail').
	 * @param string $message  Error detail from the provider.
	 * @return WP_REST_Response
	 */
	public static function send_failed( string $provider, string $message ): WP_REST_Response {
		return self::rest(
			self::SEND_FAILED,
			sprintf(
				/* translators: 1: provider name, 2: error message */
				__( 'Email sending failed via %1$s. Error: %2$s', 'suremails' ),
				$provider,
				$message
			),
			502
		);
	}

	/**
	 * Server / internal error (HTTP 500).
	 *
	 * @param string          $message   Human-readable error message.
	 * @param \Throwable|null $exception Optional exception for debug context.
	 * @return WP_REST_Response
	 */
	public static function server_error( string $message, ?\Throwable $exception = null ): WP_REST_Response {
		return self::rest( self::SERVER_ERROR, $message, 500, $exception );
	}

	/**
	 * Conflict / duplicate error (HTTP 409).
	 *
	 * @param string $message Human-readable error message.
	 * @return WP_REST_Response
	 */
	public static function conflict( string $message ): WP_REST_Response {
		return self::rest( self::CONFLICT, $message, 409 );
	}

	// ── Handler Array Builders (for email providers) ─────────────────────

	/**
	 * Build a handler-level error array (used by email provider handlers).
	 *
	 * @param string $message    Human-readable error message.
	 * @param int    $error_code HTTP-style error code (default 400).
	 * @return array{success: false, message: string, send: false, error_code: int}
	 */
	public static function handler_error( string $message, int $error_code = 400 ): array {
		return [
			'success'    => false,
			'message'    => $message,
			'send'       => false,
			'error_code' => $error_code,
		];
	}

	/**
	 * Build an authentication-level error array (used by email provider handlers).
	 *
	 * @param string $message    Human-readable error message.
	 * @param int    $error_code HTTP-style error code (default 401).
	 * @return array{success: false, message: string, error_code: int}
	 */
	public static function auth_error( string $message, int $error_code = 401 ): array {
		return [
			'success'    => false,
			'message'    => $message,
			'error_code' => $error_code,
		];
	}

	// ── Internal ─────────────────────────────────────────────────────────

	/**
	 * Build a WP_REST_Response with a consistent error structure.
	 *
	 * Response body shape:
	 * {
	 *   "success": false,
	 *   "code":    "validation_error",
	 *   "message": "API key is required."
	 * }
	 *
	 * @param string          $code       Machine-readable error code.
	 * @param string          $message    Human-readable error message.
	 * @param int             $status     HTTP status code.
	 * @param \Throwable|null $exception  Optional exception for debug context.
	 * @return WP_REST_Response
	 */
	private static function rest( string $code, string $message, int $status, ?\Throwable $exception = null ): WP_REST_Response {
		$body = [
			'success' => false,
			'code'    => $code,
			'message' => $message,
		];

		if ( $exception !== null && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$body['debug'] = [
				'exception' => $exception->getMessage(),
				'file'      => $exception->getFile(),
				'line'      => $exception->getLine(),
			];
		}

		return new WP_REST_Response( $body, $status );
	}
}
