<?php
/**
 * Queue Manager
 *
 * Manages the API request retry queue with 4 processing modes:
 * 1. Single retry — immediate direct API call, updates same row.
 * 2. Selected retry — immediate direct API calls for N items from UI page.
 * 3. Retry all failed — resets entries, fires immediate async AS action, chains batches.
 * 4. Hourly cron — same batch processing as #3.
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact;

use SureContact\Database\Api_Queue_Operations;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Queue_Manager
 *
 * Handles queueing and retry logic for failed API requests.
 *
 * @since 0.0.1
 */
class Queue_Manager {

	/**
	 * Queue table name (without prefix)
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	const TABLE_NAME = 'surecontact_api_queue';

	/**
	 * Action Scheduler hook for hourly cron
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	const PROCESSOR_HOOK = 'surecontact_process_api_queue';

	/**
	 * Action Scheduler hook for async batch processing (retry-all and chaining)
	 *
	 * @since 1.4.0
	 *
	 * @var string
	 */
	const BATCH_PROCESSOR_HOOK = 'surecontact_process_queue_batch';

	/**
	 * Cleanup hook name
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	const CLEANUP_HOOK = 'surecontact_cleanup_api_queue';

	/**
	 * Default maximum retries
	 *
	 * @since 0.0.1
	 *
	 * @var int
	 */
	const DEFAULT_MAX_RETRIES = 5;

	/**
	 * Entries to process per async batch
	 *
	 * @since 0.0.1
	 *
	 * @var int
	 */
	const BATCH_SIZE = 10;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Both hourly cron and async batch use the same processing method.
		add_action( self::PROCESSOR_HOOK, array( $this, 'process_batch' ) );
		add_action( self::BATCH_PROCESSOR_HOOK, array( $this, 'process_batch' ) );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup_old_records' ) );

		add_action( 'action_scheduler_completed_action', array( $this, 'cleanup_completed_action_logs' ), 10, 1 );
		add_action( 'action_scheduler_init', array( $this, 'maybe_schedule_processor' ) );
	}

	/**
	 * Schedule processor if not already scheduled
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function maybe_schedule_processor() {
		self::ensure_processor_scheduled();
	}

	// =========================================================================
	// Case 1 & 2: Single / Selected retry — immediate direct execution
	// =========================================================================

	/**
	 * Retry a single queue item immediately
	 *
	 * @since 0.0.1
	 *
	 * @param int $queue_id Queue item ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function manual_retry( $queue_id ) {
		$results = $this->retry_selected( array( $queue_id ) );

		if ( $results['skipped'] > 0 ) {
			return new WP_Error( 'not_retryable', __( 'This log entry is not an API request and cannot be retried.', 'surecontact' ) );
		}

		if ( $results['failed'] > 0 ) {
			return new WP_Error( 'retry_failed', __( 'Retry failed. Check the log entry for details.', 'surecontact' ) );
		}

		return true;
	}

	/**
	 * Retry selected queue items immediately
	 *
	 * Executes the API call directly for each item and updates the same row.
	 * Used for both single retry and selected retry from the UI page.
	 *
	 * @since 1.4.0
	 *
	 * @param array $ids Array of queue item IDs.
	 * @return array Results with succeeded/failed/skipped counts.
	 */
	public function retry_selected( $ids ) {
		$results = array(
			'succeeded' => 0,
			'failed'    => 0,
			'skipped'   => 0,
		);

		foreach ( $ids as $id ) {
			$entry = Api_Queue_Operations::get( absint( $id ) );

			if ( ! $entry
				|| empty( $entry->endpoint ) || empty( $entry->request_type )
				|| ! in_array( $entry->status, array( 'failed', 'error' ), true ) // @phpstan-ignore property.notFound
			) {
				++$results['skipped'];
				continue;
			}

			$this->reset_for_retry( $entry->id );

			$fresh_entry = Api_Queue_Operations::get( $entry->id );
			$result      = $this->process_single_request( $fresh_entry ); // @phpstan-ignore argument.type

			if ( $result['success'] ) {
				++$results['succeeded'];
			} else {
				++$results['failed'];
			}
		}

		return $results;
	}

	// =========================================================================
	// Case 3: Retry all failed — async via Action Scheduler
	// =========================================================================

	/**
	 * Retry all failed entries via async Action Scheduler
	 *
	 * Resets ALL failed/error entries (no row limit) to retryable state via a single
	 * UPDATE query, then fires an immediate async AS action to start processing.
	 *
	 * @since 1.4.0
	 *
	 * @return int Number of entries queued for retry.
	 */
	public function retry_all_failed() {
		$count = Api_Queue_Operations::reset_all_for_retry( self::DEFAULT_MAX_RETRIES );

		if ( $count > 0 && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::BATCH_PROCESSOR_HOOK, array(), 'surecontact' );
		}

		return $count;
	}

	// =========================================================================
	// Case 4: Hourly cron + async batch processing
	// =========================================================================

	/**
	 * Process one batch of retryable entries and chain another if more remain
	 *
	 * Shared entry point for both hourly cron (PROCESSOR_HOOK) and
	 * async batch chaining (BATCH_PROCESSOR_HOOK).
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function process_batch() {
		$requests = Api_Queue_Operations::get_processable_entries( self::BATCH_SIZE );

		if ( empty( $requests ) ) {
			return;
		}

		foreach ( $requests as $request ) {
			$this->process_single_request( $request );
		}

		// Chain another async batch if more retryable entries remain.
		$remaining = Api_Queue_Operations::get_retryable_count();
		if ( $remaining > 0 && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::BATCH_PROCESSOR_HOOK, array(), 'surecontact' );
		}
	}

	// =========================================================================
	// Core processing (single source of truth for executing a queued request)
	// =========================================================================

	/**
	 * Process a single queued request
	 *
	 * Executes the API call and updates the SAME row with the result.
	 *
	 * @since 0.0.1
	 *
	 * @param object $request Queue record from database.
	 * @return array Result with success/deleted status.
	 */
	private function process_single_request( $request ) {
		$payload = json_decode( $request->payload, true );
		if ( null === $payload ) {
			Api_Queue_Operations::update(
				$request->id,
				array(
					'status'     => 'error',
					'last_error' => 'Invalid JSON payload — cannot process',
				)
			);
			return array(
				'success' => false,
				'deleted' => false,
			);
		}

		Api_Queue_Operations::update( $request->id, array( 'status' => 'processing' ) );

		$saas_client = new SaaS_Client();
		$response    = $this->execute_request( $saas_client, $request->request_type, $request->endpoint, $payload );

		if ( is_wp_error( $response ) ) {
			return $this->handle_failed_request( $request, $response );
		}

		return $this->handle_successful_request( $request, $response, $saas_client );
	}

	/**
	 * Execute API request based on type
	 *
	 * @since 0.0.1
	 *
	 * @param SaaS_Client $client   SaaS client instance.
	 * @param string      $method   HTTP method.
	 * @param string      $endpoint API endpoint.
	 * @param array       $payload  Request payload.
	 * @return array|WP_Error API response or error.
	 */
	private function execute_request( $client, $method, $endpoint, $payload ) {
		try {
			switch ( strtoupper( $method ) ) {
				case 'POST':
					return $client->post( $endpoint, $payload );
				case 'PUT':
					return $client->put( $endpoint, $payload );
				case 'PATCH':
					return $client->patch( $endpoint, $payload );
				case 'DELETE':
					return $client->delete( $endpoint );
				default:
					return new WP_Error( 'invalid_method', "Unsupported HTTP method: {$method}" );
			}
		} catch ( \Exception $e ) {
			return new WP_Error( 'request_exception', $e->getMessage() );
		}
	}

	/**
	 * Handle successful request — update the same row to success
	 *
	 * @since 0.0.1
	 *
	 * @param object      $request     Queue record.
	 * @param mixed       $response    API response data.
	 * @param SaaS_Client $saas_client SaaS client instance.
	 * @return array Result status.
	 */
	private function handle_successful_request( $request, $response, $saas_client ) {
		Api_Queue_Operations::update(
			$request->id,
			array(
				'status'        => 'success',
				'last_error'    => null,
				'response_data' => is_array( $response ) ? wp_json_encode( $response ) : null,
				'response_code' => $saas_client->get_last_response_code(),
			)
		);

		return array(
			'success' => true,
			'deleted' => false,
		);
	}

	/**
	 * Handle failed request — update retry info or mark as terminal
	 *
	 * @since 0.0.1
	 *
	 * @param object   $request Queue record.
	 * @param WP_Error $error   Error object.
	 * @return array Result status.
	 */
	private function handle_failed_request( $request, $error ) {
		$new_retry_count = $request->retry_count + 1;
		$error_data      = $error->get_error_data();
		$response_code   = is_array( $error_data ) && isset( $error_data['code'] ) ? (int) $error_data['code'] : null;

		if ( $this->is_rate_limit_error( $error ) ) {
			$new_retry_count = $request->retry_count;
		}

		$next_retry = wp_date( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS, wp_timezone() );
		if ( $new_retry_count >= $request->max_retries ) {
			$next_retry = null;
		}

		Api_Queue_Operations::update(
			$request->id,
			array(
				'status'        => 'failed',
				'retry_count'   => $new_retry_count,
				'last_error'    => substr( $error->get_error_message(), 0, 500 ),
				'next_retry_at' => $next_retry,
				'response_code' => $response_code,
			)
		);

		return array(
			'success' => false,
			'deleted' => false,
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Reset a queue entry for retry
	 *
	 * @since 1.4.0
	 *
	 * @param int $queue_id Queue item ID.
	 * @return void
	 */
	private function reset_for_retry( $queue_id ) {
		Api_Queue_Operations::update(
			$queue_id,
			array(
				'retry_count'   => 0,
				'max_retries'   => self::DEFAULT_MAX_RETRIES,
				'status'        => 'failed',
				'next_retry_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Check if a WP_Error represents a 429 rate limit error
	 *
	 * @since 1.4.0
	 *
	 * @param WP_Error $error The error to check.
	 * @return bool
	 */
	private function is_rate_limit_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$error_data = $error->get_error_data();
		if ( isset( $error_data['code'] ) && 429 === (int) $error_data['code'] ) {
			return true;
		}

		$error_message       = $error->get_error_message();
		$rate_limit_keywords = array( 'rate limit', 'too many requests', 'throttled', '429' );
		foreach ( $rate_limit_keywords as $keyword ) {
			if ( stripos( $error_message, $keyword ) !== false ) {
				return true;
			}
		}

		return false;
	}

	// =========================================================================
	// CRUD / Stats (pass-through to Api_Queue_Operations)
	// =========================================================================

	/**
	 * Delete a queue item
	 *
	 * @since 0.0.1
	 *
	 * @param int $queue_id Queue item ID.
	 * @return bool
	 */
	public function delete_queue_item( $queue_id ) {
		return Api_Queue_Operations::delete( $queue_id );
	}

	/**
	 * Delete multiple queue items
	 *
	 * @since 1.4.0
	 *
	 * @param array $ids Array of queue item IDs.
	 * @return int Number of deleted rows.
	 */
	public function delete_items( $ids ) {
		return Api_Queue_Operations::delete_many( $ids );
	}

	/**
	 * Get queue statistics
	 *
	 * @since 0.0.1
	 *
	 * @return array Queue stats.
	 */
	public function get_stats() {
		return wp_parse_args(
			Api_Queue_Operations::get_stats( array( 'processing', 'failed' ) ),
			array(
				'processing' => 0,
				'failed'     => 0,
				'total'      => 0,
			)
		);
	}

	/**
	 * Get total count of all requests
	 *
	 * @since 0.0.1
	 *
	 * @return int
	 */
	public function get_total_requests_count() {
		return Api_Queue_Operations::get_count();
	}

	/**
	 * Get failed requests for admin UI
	 *
	 * @since 0.0.1
	 *
	 * @param int $limit  Number of records.
	 * @param int $offset Offset for pagination.
	 * @return array
	 */
	public function get_failed_requests( $limit = 100, $offset = 0 ) {
		return Api_Queue_Operations::get_entries(
			array(
				'statuses' => array( 'processing', 'failed' ),
				'limit'    => $limit,
				'offset'   => $offset,
			)
		);
	}

	// =========================================================================
	// Scheduling & Cleanup
	// =========================================================================

	/**
	 * Clean up old records from queue and logs
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function cleanup_old_records() {
		$deleted_queue = Api_Queue_Operations::cleanup_by_age( 7, array( 'failed' ) );
		if ( $deleted_queue > 0 ) {
			Logger::info( 'Queue', "Cleaned up {$deleted_queue} failed queue records older than 7 days" );
		}

		$settings       = get_option( 'surecontact_general_settings', array() );
		$log_settings   = $settings['log_settings'] ?? array();
		$retention_days = max( 1, absint( $log_settings['log_retention_days'] ?? 1 ) );

		$deleted_logs = Api_Queue_Operations::cleanup_by_age( $retention_days, array( 'success', 'error' ) );
		if ( $deleted_logs > 0 ) {
			Logger::info( 'Queue', "Cleaned up {$deleted_logs} log entries older than {$retention_days} day(s)" );
		}
	}

	/**
	 * Ensure background processor is scheduled
	 *
	 * @since 0.0.1
	 *
	 * @return bool
	 */
	public static function ensure_processor_scheduled() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}

		try {
			if ( ! as_next_scheduled_action( self::PROCESSOR_HOOK ) ) {
				as_schedule_recurring_action( time(), HOUR_IN_SECONDS, self::PROCESSOR_HOOK, array(), 'surecontact' );
			}

			if ( ! as_next_scheduled_action( self::CLEANUP_HOOK ) ) {
				as_schedule_recurring_action( strtotime( 'tomorrow 3:00am' ), DAY_IN_SECONDS, self::CLEANUP_HOOK, array(), 'surecontact' );
			}

			return true;
		} catch ( \Exception $e ) {
			Logger::error( 'Queue', 'Exception while scheduling: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Clean up Action Scheduler logs for completed SureContact jobs
	 *
	 * @since 0.0.1
	 *
	 * @param int $action_id The completed action ID.
	 * @return void
	 */
	public function cleanup_completed_action_logs( $action_id ) {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return;
		}

		try {
			$store  = \ActionScheduler::store();
			$action = $store->fetch_action( $action_id );

			if ( $action->get_group() === 'surecontact' ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $wpdb->prefix . 'actionscheduler_logs', array( 'action_id' => $action_id ), array( '%d' ) );
			}
		} catch ( \Exception $e ) {
			Logger::error( 'Queue', "Error cleaning up Action Scheduler logs for action ID {$action_id}", array( 'error' => $e->getMessage() ) );
		}
	}
}
