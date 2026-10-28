<?php
/**
 * API Retry Trait
 *
 * Provides automatic retry logic for API calls with intelligent error handling.
 * This trait can be used by any API class that needs retry capabilities.
 *
 * Every API call is logged to the surecontact_api_queue table as a single entry
 * that serves both as a debug log and retry queue item (when failed).
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact\Traits;

use SureContact\Database\Api_Queue_Operations;
use SureContact\Queue_Manager;
use SureContact\Logger;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait API_Retry
 *
 * Adds retry logic to API operations with automatic queueing for failed requests.
 *
 * @since 0.0.1
 */
trait API_Retry {

	/**
	 * Execute API call immediately, queue on failure
	 *
	 * Every call is logged to the DB. On success, the entry has status='success'.
	 * On retryable failure, status='failed' with retry columns set.
	 * On non-retryable failure, status='error'.
	 *
	 * @since 0.0.1
	 * @since 1.4.0 Now logs every API call (success, error, failed) to the queue table.
	 *
	 * @param callable $callback   The API call to execute.
	 * @param array    $queue_data Data for queue if call fails.
	 * @param array    $options    Optional. Retry behavior options.
	 *     @type bool $skip_queue When true, retryable errors are returned directly
	 *                            instead of being queued to the retry table. The caller
	 *                            is responsible for handling the retry (e.g., scheduling
	 *                            via Action Scheduler with a delay). Useful for bulk sync
	 *                            operations that manage their own retry flow.
	 * @return mixed API response or queued status.
	 */
	protected function execute_with_retry( $callback, $queue_data = array(), $options = array() ) {
		// Prepend source to operation for log context.
		if ( ! empty( $options['source'] ) && ! empty( $queue_data['operation'] ) ) {
			$queue_data['operation'] = sanitize_key( $options['source'] ) . ':' . $queue_data['operation'];
		}

		$skip_queue = ! empty( $options['skip_queue'] );

		// Step 1: Try immediate API call.
		try {
			$response = call_user_func( $callback );

			// Step 2: If success, log and return.
			if ( ! is_wp_error( $response ) ) {
				$this->log_api_call( $queue_data, 'success', $response );
				return $response;
			}

			// Step 3: If error, classify and log.
			if ( $this->should_queue_error( $response ) ) {
				// If skip_queue is set, log as error and return for caller to handle.
				if ( $skip_queue ) {
					$this->log_api_call( $queue_data, 'error', null, $response );
					return $response;
				}
				return $this->queue_request( $queue_data, $response );
			}

			// Non-retryable error - log as error and return.
			$this->log_api_call( $queue_data, 'error', null, $response );
			return $response;

		} catch ( \Exception $e ) {
			$wp_error = new WP_Error( 'api_exception', $e->getMessage() );

			if ( $skip_queue ) {
				$this->log_api_call( $queue_data, 'error', null, $wp_error );
				return $wp_error;
			}
			// Network/timeout error - queue for retry.
			return $this->queue_request( $queue_data, $wp_error );
		}
	}

	/**
	 * Log an API call to the database
	 *
	 * @since 1.4.0
	 *
	 * @param array         $queue_data Queue data with endpoint, operation, etc.
	 * @param string        $status     Entry status: 'success' or 'error'.
	 * @param mixed         $response   API response data (for success).
	 * @param WP_Error|null $error      Error object (for errors).
	 * @return void
	 */
	private function log_api_call( $queue_data, $status, $response = null, $error = null ) {
		if ( empty( $queue_data ) ) {
			return;
		}

		$response_code = null;
		$error_message = null;

		if ( is_wp_error( $error ) ) {
			$error_message = substr( $error->get_error_message(), 0, 500 );
			$error_data    = $error->get_error_data();
			if ( isset( $error_data['code'] ) ) {
				$response_code = (int) $error_data['code'];
			}
		}

		// Get response code from SaaS client if available.
		if ( null === $response_code && property_exists( $this, 'saas_client' ) && method_exists( $this->saas_client, 'get_last_response_code' ) ) {
			$response_code = $this->saas_client->get_last_response_code();
		}

		$data = array(
			'request_type'  => sanitize_text_field( $queue_data['request_type'] ?? '' ),
			'endpoint'      => sanitize_text_field( $queue_data['endpoint'] ?? '' ),
			'payload'       => wp_json_encode( $queue_data['payload'] ?? array() ),
			'operation'     => sanitize_text_field( $queue_data['operation'] ?? 'unknown' ),
			'status'        => $status,
			'response_data' => is_array( $response ) ? wp_json_encode( $response ) : null,
			'response_code' => $response_code,
			'last_error'    => $error_message,
		);

		Api_Queue_Operations::insert( $data );
	}

	/**
	 * Check if error should be queued for retry
	 *
	 * @since 0.0.1
	 *
	 * @param WP_Error $error The error to check.
	 * @return bool True if should queue.
	 */
	private function should_queue_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		// Queue retryable errors only.
		return 'retryable' === $this->classify_error( $error );
	}

	/**
	 * Queue request for background processing
	 *
	 * Inserts a failed entry directly and schedules retry via Action Scheduler.
	 *
	 * @since 0.0.1
	 * @since 1.4.0 Now inserts entry directly via Api_Queue_Operations.
	 *
	 * @param array         $queue_data Queue data.
	 * @param WP_Error|null $error      Original error.
	 * @return array|WP_Error Queued response or error.
	 */
	private function queue_request( $queue_data, $error = null ) {
		if ( empty( $queue_data ) ) {
			Logger::error( 'API_Retry', 'Cannot queue request: missing queue data' );
			return new WP_Error( 'missing_queue_data', __( 'Cannot queue: missing data', 'surecontact' ) );
		}

		// Validate required queue data fields.
		$required_fields = array( 'request_type', 'endpoint', 'payload', 'operation' );
		foreach ( $required_fields as $field ) {
			if ( empty( $queue_data[ $field ] ) ) {
				Logger::error(
					'API_Retry',
					"Cannot queue request: missing required field '{$field}'",
					array( 'queue_data' => $queue_data )
				);
				return new WP_Error(
					'invalid_queue_data',
					/* translators: %s: field name */
					sprintf( __( 'Cannot queue: missing required field "%s"', 'surecontact' ), $field )
				);
			}
		}

		$now           = current_time( 'mysql' );
		$response_code = null;
		$error_message = null;

		if ( is_wp_error( $error ) ) {
			$error_message = substr( $error->get_error_message(), 0, 500 );
			$error_data    = $error->get_error_data();
			if ( isset( $error_data['code'] ) ) {
				$response_code = (int) $error_data['code'];
			}
		}

		$queue_id = Api_Queue_Operations::insert(
			array(
				'request_type'  => sanitize_text_field( $queue_data['request_type'] ),
				'endpoint'      => sanitize_text_field( $queue_data['endpoint'] ),
				'payload'       => wp_json_encode( $queue_data['payload'] ),
				'operation'     => sanitize_text_field( $queue_data['operation'] ?? 'unknown' ),
				'retry_count'   => 0,
				'max_retries'   => absint( $queue_data['max_retries'] ?? Queue_Manager::DEFAULT_MAX_RETRIES ),
				'status'        => 'failed',
				'last_error'    => $error_message,
				'next_retry_at' => $now,
				'response_code' => $response_code,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);

		if ( $queue_id ) {
			// Ensure background processor is scheduled.
			$this->ensure_queue_processor_scheduled();

			return array(
				'success'  => true,
				'queued'   => true,
				'queue_id' => $queue_id,
				'message'  => __( 'Request queued for retry.', 'surecontact' ),
			);
		}

		Logger::error(
			'API_Retry',
			'Failed to enqueue request',
			array( 'operation' => $queue_data['operation'] ?? 'unknown' )
		);
		return new WP_Error( 'queue_failed', __( 'Failed to queue request for retry', 'surecontact' ) );
	}

	/**
	 * Ensure queue processor is scheduled
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	private function ensure_queue_processor_scheduled() {
		Queue_Manager::ensure_processor_scheduled();
	}

	/**
	 * Classify error type to determine if retry is appropriate
	 *
	 * @since 0.0.1
	 *
	 * @param WP_Error $error Error object to classify.
	 * @return string Error classification: 'retryable' or 'non_retryable'.
	 */
	private function classify_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return 'non_retryable';
		}

		$error_data = $error->get_error_data();
		$code       = is_array( $error_data ) && isset( $error_data['code'] ) ? (int) $error_data['code'] : 0;

		// Retryable HTTP status codes.
		$retryable_codes = array(
			0,   // Network failure / timeout.
			429, // Too Many Requests (rate limit).
			500, // Internal Server Error.
			502, // Bad Gateway.
			503, // Service Unavailable.
			504, // Gateway Timeout.
		);

		if ( in_array( $code, $retryable_codes, true ) ) {
			return 'retryable';
		}

		// 5xx errors are generally retryable.
		if ( $code >= 500 && $code < 600 ) {
			return 'retryable';
		}

		// Remaining 4xx errors are client errors (validation, not found) - don't retry.
		if ( $code >= 400 && $code < 500 ) {
			return 'non_retryable';
		}

		// Check error message for common retryable patterns.
		$error_message      = strtolower( $error->get_error_message() );
		$retryable_patterns = array(
			'timeout',
			'connection',
			'unavailable',
			'temporarily',
			'rate limit',
			'too many requests',
		);

		foreach ( $retryable_patterns as $pattern ) {
			if ( false !== strpos( $error_message, $pattern ) ) {
				return 'retryable';
			}
		}

		// Default to non-retryable for unknown errors.
		return 'non_retryable';
	}

	/**
	 * Execute multiple API calls with retry logic
	 *
	 * Useful for batch operations where you want to queue all failed requests.
	 *
	 * @since 0.0.1
	 *
	 * @param array $calls Array of calls, each with 'callback' and 'queue_data'.
	 * @return array Results for each call.
	 */
	protected function execute_batch_with_retry( $calls ) {
		$results = array();

		foreach ( $calls as $index => $call ) {
			if ( ! isset( $call['callback'] ) || ! isset( $call['queue_data'] ) ) {
				$results[ $index ] = new WP_Error(
					'invalid_call',
					__( 'Each call must have "callback" and "queue_data" keys', 'surecontact' )
				);
				continue;
			}

			$results[ $index ] = $this->execute_with_retry(
				$call['callback'],
				$call['queue_data']
			);
		}

		return $results;
	}

	/**
	 * Check if a response indicates a queued request
	 *
	 * @since 0.0.1
	 *
	 * @param mixed $response API response to check.
	 * @return bool True if response indicates request was queued.
	 */
	protected function is_queued_response( $response ) {
		return is_array( $response )
			&& isset( $response['queued'] )
			&& true === $response['queued'];
	}

	/**
	 * Get human-readable message for API response
	 *
	 * @since 0.0.1
	 *
	 * @param mixed $response API response.
	 * @return string Human-readable message.
	 */
	protected function get_response_message( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		if ( $this->is_queued_response( $response ) ) {
			return $response['message'] ?? __( 'Request queued for processing.', 'surecontact' );
		}

		if ( is_array( $response ) && isset( $response['message'] ) ) {
			return $response['message'];
		}

		return __( 'Request completed successfully.', 'surecontact' );
	}
}
