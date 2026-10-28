<?php
/**
 * Queue REST Controller
 *
 * Handles REST API endpoints for the API queue logs and retry management.
 *
 * @since 0.0.1
 *
 * @package SureContact\API_WP
 */

namespace SureContact\API_WP;

use SureContact\Database\Api_Queue_Operations;
use SureContact\Operation_Labels;
use SureContact\Queue_Manager;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue REST Controller class
 *
 * Provides REST API endpoints for:
 * - Viewing all logs (unified view)
 * - Viewing queue logs (processing/failed only)
 * - Getting log/queue statistics
 * - Manual retry of failed requests
 * - Bulk retry and delete operations
 *
 * @since 0.0.1
 */
class Queue_Rest_Controller extends Api_Base {

	/**
	 * Singleton instance
	 *
	 * @since 0.0.1
	 *
	 * @var Queue_Rest_Controller
	 */
	private static $instance = null;

	/**
	 * Queue Manager instance
	 *
	 * @since 0.0.1
	 *
	 * @var Queue_Manager
	 */
	private $queue_manager;

	/**
	 * Get singleton instance
	 *
	 * @since 0.0.1
	 *
	 * @return Queue_Rest_Controller
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	private function __construct() {
		parent::__construct();
		$this->queue_manager = new Queue_Manager();
	}

	/**
	 * Register routes
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function register_routes() {
		$namespace = $this->get_api_namespace();

		// === All Logs Endpoints (unified view) ===

		// Get all log entries.
		register_rest_route(
			$namespace,
			'/queue/all-logs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_all_logs' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'status' => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $param ) {
								return empty( $param ) || in_array( $param, array( 'success', 'error', 'failed', 'processing' ), true );
							},
						),
						'search' => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'limit'  => array(
							'default'           => 100,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && $param > 0 && $param <= 500;
							},
						),
						'offset' => array(
							'default'           => 0,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && $param >= 0;
							},
						),
					),
				),
			)
		);

		// Get all log stats.
		register_rest_route(
			$namespace,
			'/queue/all-logs/stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_all_log_stats' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);

		// Delete a single log entry.
		register_rest_route(
			$namespace,
			'/queue/all-logs/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_log' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
			)
		);

		// Bulk delete log entries.
		register_rest_route(
			$namespace,
			'/queue/all-logs/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'bulk_delete_logs' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'ids' => array(
							'required'          => true,
							'type'              => 'array',
							'items'             => array( 'type' => 'integer' ),
							'maxItems'          => 100,
							'sanitize_callback' => function ( $ids ) {
								return array_map( 'absint', $ids );
							},
						),
					),
				),
			)
		);

		// Clear all non-queue entries (success/error).
		register_rest_route(
			$namespace,
			'/queue/all-logs/clear',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_logs' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);

		// === Queue-Specific Endpoints ===

		// Get queue logs (processing/failed only).
		register_rest_route(
			$namespace,
			'/queue/logs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_queue_logs' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'limit'  => array(
							'default'           => 100,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && $param > 0 && $param <= 500;
							},
						),
						'offset' => array(
							'default'           => 0,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && $param >= 0;
							},
						),
					),
				),
			)
		);

		// Get queue statistics.
		register_rest_route(
			$namespace,
			'/queue/stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_queue_stats' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);

		// Manual retry single item.
		register_rest_route(
			$namespace,
			'/queue/retry/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'manual_retry' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
			)
		);

		// Bulk retry all failed.
		register_rest_route(
			$namespace,
			'/queue/retry/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_retry_all' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);

		// Bulk retry selected items.
		register_rest_route(
			$namespace,
			'/queue/retry/selected',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_retry_selected' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'ids' => array(
							'required'          => true,
							'type'              => 'array',
							'items'             => array( 'type' => 'integer' ),
							'maxItems'          => 100,
							'sanitize_callback' => function ( $ids ) {
								return array_map( 'absint', $ids );
							},
						),
					),
				),
			)
		);

		// Delete single queue entry.
		register_rest_route(
			$namespace,
			'/queue/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_queue_item' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
			)
		);

		// Bulk delete queue entries.
		register_rest_route(
			$namespace,
			'/queue/bulk-delete',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'bulk_delete_queue_items' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'ids' => array(
							'required'          => true,
							'type'              => 'array',
							'items'             => array( 'type' => 'integer' ),
							'maxItems'          => 100,
							'sanitize_callback' => function ( $ids ) {
								return array_map( 'absint', $ids );
							},
						),
					),
				),
			)
		);
	}

	// === All Logs Callbacks ===

	/**
	 * Get all log entries with filters
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_all_logs( $request ) {
		$status = $request->get_param( 'status' );
		$search = $request->get_param( 'search' );
		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );

		$args = array(
			'search' => $search,
			'limit'  => $limit,
			'offset' => $offset,
		);

		if ( ! empty( $status ) ) {
			$args['statuses'] = array( $status );
		}

		$logs  = Api_Queue_Operations::get_entries( $args );
		$total = Api_Queue_Operations::get_count( $args );

		$formatted_logs = array_map( array( $this, 'format_log_entry' ), $logs );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $formatted_logs,
				'total'   => $total,
			)
		);
	}

	/**
	 * Get all log stats
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_all_log_stats( $request ) {
		$stats = Api_Queue_Operations::get_stats();

		// Ensure all expected keys exist.
		$defaults = array(
			'success'    => 0,
			'error'      => 0,
			'processing' => 0,
			'failed'     => 0,
			'total'      => 0,
		);

		$stats = wp_parse_args( $stats, $defaults );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $stats,
			)
		);
	}

	/**
	 * Delete a single log entry
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_log( $request ) {
		$id      = (int) $request->get_param( 'id' );
		$deleted = Api_Queue_Operations::delete( $id );

		if ( ! $deleted ) {
			return new WP_Error(
				'delete_failed',
				__( 'Log entry not found or could not be deleted.', 'surecontact' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Log entry deleted.', 'surecontact' ),
			)
		);
	}

	/**
	 * Bulk delete log entries
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_delete_logs( $request ) {
		$ids     = array_map( 'absint', $request->get_param( 'ids' ) );
		$deleted = Api_Queue_Operations::delete_many( $ids );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: number of deleted entries */
					__( '%d log entries deleted.', 'surecontact' ),
					$deleted
				),
				'deleted' => $deleted,
			)
		);
	}

	/**
	 * Clear all non-queue entries (success/error)
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function clear_logs( $request ) {
		$deleted = Api_Queue_Operations::delete_by_status( array( 'success', 'error' ) );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: number of cleared entries */
					__( '%d log entries cleared.', 'surecontact' ),
					$deleted
				),
				'deleted' => $deleted,
			)
		);
	}

	// === Queue-Specific Callbacks ===

	/**
	 * Get queue logs (processing/failed entries)
	 *
	 * @since 0.0.1
	 * @since 1.4.0 Now includes payload, response_data, response_code in response.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_queue_logs( $request ) {
		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );

		$logs = Api_Queue_Operations::get_entries(
			array(
				'statuses' => array( 'processing', 'failed' ),
				'limit'    => $limit,
				'offset'   => $offset,
			)
		);

		$total = Api_Queue_Operations::get_count(
			array( 'statuses' => array( 'processing', 'failed' ) )
		);

		$formatted_logs = array_map( array( $this, 'format_log_entry' ), $logs );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $formatted_logs,
				'total'   => $total,
			)
		);
	}

	/**
	 * Get queue statistics
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_queue_stats( $request ) {
		$stats = $this->queue_manager->get_stats();

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $stats,
			)
		);
	}

	/**
	 * Manual retry of a specific queue item
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function manual_retry( $request ) {
		$queue_id = (int) $request->get_param( 'id' );

		$result = $this->queue_manager->manual_retry( $queue_id );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'retry_failed',
				$result->get_error_message(),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Request queued for immediate retry.', 'surecontact' ),
			)
		);
	}

	/**
	 * Bulk retry all failed items
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_retry_all( $request ) {
		$count = $this->queue_manager->retry_all_failed();

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: number of items queued for retry */
					__( '%d items queued for retry.', 'surecontact' ),
					$count
				),
				'count'   => $count,
			)
		);
	}

	/**
	 * Bulk retry selected items
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_retry_selected( $request ) {
		$ids     = array_map( 'absint', $request->get_param( 'ids' ) );
		$results = $this->queue_manager->retry_selected( $ids );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %1$d: succeeded count, %2$d: failed count */
					__( '%1$d succeeded, %2$d failed.', 'surecontact' ),
					$results['succeeded'],
					$results['failed']
				),
				'data'    => $results,
			)
		);
	}

	/**
	 * Delete a single queue entry
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_queue_item( $request ) {
		$id      = (int) $request->get_param( 'id' );
		$deleted = $this->queue_manager->delete_queue_item( $id );

		if ( ! $deleted ) {
			return new WP_Error(
				'delete_failed',
				__( 'Queue item not found or could not be deleted.', 'surecontact' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Queue item deleted.', 'surecontact' ),
			)
		);
	}

	/**
	 * Bulk delete queue entries
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_delete_queue_items( $request ) {
		$ids     = array_map( 'absint', $request->get_param( 'ids' ) );
		$deleted = $this->queue_manager->delete_items( $ids );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: number of deleted items */
					__( '%d queue items deleted.', 'surecontact' ),
					$deleted
				),
				'deleted' => $deleted,
			)
		);
	}

	// === Helpers ===

	/**
	 * Format a log entry for API response
	 *
	 * @since 1.4.0
	 *
	 * @param object $log Log entry from database.
	 * @return array Formatted log entry.
	 */
	private function format_log_entry( $log ) {
		return array(
			'id'              => (int) $log->id, // @phpstan-ignore property.notFound
			'request_type'    => $log->request_type, // @phpstan-ignore property.notFound
			'endpoint'        => $log->endpoint, // @phpstan-ignore property.notFound
			'payload'         => $log->payload, // @phpstan-ignore property.notFound
			'operation'       => $log->operation, // @phpstan-ignore property.notFound
			'operation_label' => Operation_Labels::get_label( $log->operation ), // @phpstan-ignore property.notFound
			'retry_count'     => (int) $log->retry_count, // @phpstan-ignore property.notFound
			'max_retries'     => (int) $log->max_retries, // @phpstan-ignore property.notFound
			'status'          => $log->status, // @phpstan-ignore property.notFound
			'last_error'      => $log->last_error, // @phpstan-ignore property.notFound
			'response_data'   => $log->response_data, // @phpstan-ignore property.notFound
			'response_code'   => $log->response_code ? (int) $log->response_code : null, // @phpstan-ignore property.notFound
			'created_at'      => surecontact_format_date_for_api( $log->created_at ), // @phpstan-ignore property.notFound
			'next_retry_at'   => surecontact_format_date_for_api( $log->next_retry_at ), // @phpstan-ignore property.notFound
		);
	}
}
