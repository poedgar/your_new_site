<?php
/**
 * Send JSON
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Functions;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Send JSON
 *
 * @since 0.0.1
 */
class SendJson {
	/**
	 * Sends the success JSON response.
	 *
	 * @param array<string, mixed>|array<int, string> $data array of data to be send.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public static function success( $data = [] ): void {
		$response = [ 'success' => true ];
		SendJson::response( $response, $data );
	}

	/**
	 * Sends the error JSON response.
	 *
	 * @param array<string, mixed>|array<int, string> $data        array of data to be send.
	 * @param int|null                                $status_code Optional HTTP status code.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public static function error( $data = [], $status_code = null ): void {
		$response = [ 'success' => false ];
		SendJson::response( $response, $data, $status_code );
	}

	/**
	 * Sends the JSON response.
	 * using WordPress function wp_send_json
	 *
	 * @param array<string, mixed>|array<int, string> $response    required data.
	 * @param array<string, mixed>|array<int, string> $data        array of data to be send.
	 * @param int|null                                 $status_code HTTP status code (default: 200).
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public static function response( $response, $data = [], $status_code = null ): void {
		if ( ! empty( $data ) && is_array( $data ) ) {
			$response = array_merge( $response, $data );
		}

		if ( $status_code !== null ) {
			status_header( $status_code );
		}

		wp_send_json( $response );
	}
}
