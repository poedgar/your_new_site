<?php
/**
 * Bulk Sync REST API Controller
 *
 * Provides REST API endpoints for bulk sync operations.
 *
 * @since 0.0.1
 *
 * @package SureContact\API_WP
 */

namespace SureContact\API_WP;

use SureContact\Bulk_Sync_Service;
use SureContact\Field_Mapper;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk_Sync_Api class
 *
 * @since 0.0.1
 */
class Bulk_Sync_Api extends Api_Base {

	/**
	 * API namespace
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	protected $namespace = 'surecontact/v1';

	/**
	 * REST base
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	protected $rest_base = 'bulk-sync';

	/**
	 * Instance
	 *
	 * @since 0.0.1
	 *
	 * @var Bulk_Sync_Api
	 */
	private static $instance = null;

	/**
	 * Get instance
	 *
	 * @since 0.0.1
	 *
	 * @return Bulk_Sync_Api
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register REST API routes
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function register_routes() {
		// POST /surecontact/v1/bulk-sync/start.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_sync' ),
				'permission_callback' => array( $this, 'validate_permission' ),
				'args'                => array(
					'type' => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => 'Type of sync to perform',
						'validate_callback' => array( $this, 'validate_sync_type' ),
					),
				),
			)
		);

		// GET /surecontact/v1/bulk-sync/job/{job_id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/job/(?P<job_id>[a-zA-Z0-9_.]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_job_status' ),
				'permission_callback' => array( $this, 'validate_permission' ),
				'args'                => array(
					'job_id' => array(
						'required'    => true,
						'type'        => 'string',
						'description' => 'Job ID to query',
					),
				),
			)
		);

		// GET /surecontact/v1/bulk-sync/jobs.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/jobs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_jobs' ),
				'permission_callback' => array( $this, 'validate_permission' ),
				'args'                => array(
					'page'     => array(
						'required'    => false,
						'type'        => 'integer',
						'default'     => 1,
						'description' => 'Page number for pagination',
					),
					'per_page' => array(
						'required'    => false,
						'type'        => 'integer',
						'default'     => 5,
						'description' => 'Number of jobs per page',
					),
				),
			)
		);

		// GET /surecontact/v1/bulk-sync/active-job.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/active-job',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_active_job' ),
				'permission_callback' => array( $this, 'validate_permission' ),
			)
		);

		// DELETE /surecontact/v1/bulk-sync/job/{job_id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/job/(?P<job_id>[a-zA-Z0-9_.]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'cancel_job' ),
				'permission_callback' => array( $this, 'validate_permission' ),
				'args'                => array(
					'job_id' => array(
						'required'    => true,
						'type'        => 'string',
						'description' => 'Job ID to cancel',
					),
				),
			)
		);

		// GET /surecontact/v1/bulk-sync/available-types.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/available-types',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_available_sync_types' ),
				'permission_callback' => array( $this, 'validate_permission' ),
			)
		);
	}

	/**
	 * Validate sync type against dynamically registered types
	 *
	 * @since 1.2.0
	 *
	 * @param string $value Sync type value to validate.
	 * @return bool Whether the sync type is valid.
	 */
	public function validate_sync_type( $value ) {
		$sync_service    = new Bulk_Sync_Service();
		$available_types = wp_list_pluck( $sync_service->get_available_sync_types(), 'type' );

		return in_array( $value, $available_types, true );
	}

	/**
	 * Start a sync job
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response object.
	 */
	public function start_sync( WP_REST_Request $request ) {
		$sync_service = new Bulk_Sync_Service();
		$type         = $request->get_param( 'type' );

		// Resolve integration from the sync type dynamically.
		$available_types = $sync_service->get_available_sync_types();
		$sync_type_entry = null;
		foreach ( $available_types as $entry ) {
			if ( $entry['type'] === $type ) {
				$sync_type_entry = $entry;
				break;
			}
		}

		if ( ! $sync_type_entry ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid sync type', 'surecontact' ),
				),
				400
			);
		}

		// Check if integration requires field mapping and its email field is mapped.
		$integration_slug = $sync_type_entry['integration'];
		$loader           = \SureContact::get_instance()->integrations_loader;
		$integration      = $loader ? $loader->get_integration( $integration_slug ) : null;
		$requires_mapping = $integration && method_exists( $integration, 'get_require_field_mapping' ) && $integration->get_require_field_mapping();

		if ( $requires_mapping && ! Field_Mapper::has_required_mapping( $integration_slug ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'mapping_required',
					'message' => __( 'Email field mapping is required before syncing. Please configure it in Contact Fields settings.', 'surecontact' ),
				),
				422
			);
		}

		$result = $sync_service->sync_integration( $sync_type_entry['integration'], array( 'sync_type' => $type ) );

		// Check if the sync service returned a failure.
		if ( is_array( $result ) && isset( $result['success'] ) && false === $result['success'] ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => isset( $result['message'] ) ? $result['message'] : __( 'Failed to start sync', 'surecontact' ),
				),
				422
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}

	/**
	 * Get job status
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response object.
	 */
	public function get_job_status( WP_REST_Request $request ) {
		$job_id       = $request->get_param( 'job_id' );
		$sync_service = new Bulk_Sync_Service();
		$status       = $sync_service->get_job_status( $job_id );

		if ( is_wp_error( $status ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $status->get_error_message(),
				),
				404
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $status,
			),
			200
		);
	}

	/**
	 * List recent jobs
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response object.
	 */
	public function list_jobs( WP_REST_Request $request ) {
		global $wpdb;

		$page     = max( 1, $request->get_param( 'page' ) );
		$per_page = max( 1, min( 100, $request->get_param( 'per_page' ) ) ); // Cap at 100 per page.
		$offset   = ( $page - 1 ) * $per_page;

		// The underscores in the option name prefix are literal — wrap with
		// esc_like so they aren't treated as LIKE single-char wildcards
		// (matches the pattern already used by get_active_job() below).
		$job_prefix_like = $wpdb->esc_like( 'surecontact_job_' ) . '%';

		// Get total count for pagination.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Querying options table for job metadata, not cacheable.
		$total_jobs = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				 WHERE option_name LIKE %s",
				$job_prefix_like
			)
		);

		// Get paginated job options.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Querying options table for job metadata with pagination, not cacheable.
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				 ORDER BY option_id DESC
				 LIMIT %d OFFSET %d",
				$job_prefix_like,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$job_list = array();
		foreach ( $jobs as $job ) {
			$job_id   = str_replace( 'surecontact_job_', '', $job['option_name'] );
			$job_info = maybe_unserialize( $job['option_value'] );

			// Use format_job_response directly with already-fetched data to avoid N+1 queries.
			if ( $job_info ) {
				$job_list[] = Bulk_Sync_Service::format_job_response( $job_id, $job_info );
			}
		}

		$total_pages = ceil( $total_jobs / $per_page );

		return new WP_REST_Response(
			array(
				'success'    => true,
				'data'       => $job_list,
				'pagination' => array(
					'total'        => (int) $total_jobs,
					'total_pages'  => (int) $total_pages,
					'current_page' => (int) $page,
					'per_page'     => (int) $per_page,
				),
			),
			200
		);
	}

	/**
	 * Get currently active job (if any)
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response object.
	 */
	public function get_active_job( WP_REST_Request $request ) {
		global $wpdb;
		$sync_service = new Bulk_Sync_Service();

		// Find the most recent job that's still processing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Querying options table for active jobs, not cacheable.
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				WHERE option_name LIKE %s
				ORDER BY option_id DESC
				LIMIT %d",
				$wpdb->esc_like( 'surecontact_job_' ) . '%',
				10
			),
			ARRAY_A
		);

		foreach ( $jobs as $job ) {
			$job_data = maybe_unserialize( $job['option_value'] );

			// Check if job is still active (processing or queued).
			if ( isset( $job_data['status'] ) && in_array( $job_data['status'], array( 'processing', 'queued' ), true ) ) {
				$job_id = str_replace( 'surecontact_job_', '', $job['option_name'] );

				// Get fresh status.
				$job_status = $sync_service->get_job_status( $job_id, true );

				if ( ! is_wp_error( $job_status ) ) {
					// Re-check status after refresh - job may have completed during the refresh.
					if ( in_array( $job_status['status'], array( 'processing', 'queued' ), true ) ) {
						return new WP_REST_Response(
							array(
								'success' => true,
								'data'    => $job_status,
							),
							200
						);
					}
					// Job completed during refresh, continue to check next job.
				}
			}
		}

		// No active job found.
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => null,
			),
			200
		);
	}

	/**
	 * Cancel a job
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response object.
	 */
	public function cancel_job( WP_REST_Request $request ) {
		$job_id = $request->get_param( 'job_id' );

		// Get job info.
		$job_info = get_option( "surecontact_job_{$job_id}" );

		if ( ! $job_info ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Job not found', 'surecontact' ),
				),
				404
			);
		}

		$cancelled_count  = 0;
		$failed_count     = 0;
		$integration_type = isset( $job_info['type'] ) ? $job_info['type'] : 'WordPress';
		// IMPORTANT: Update job status to 'cancelled' FIRST, before cancelling actions.
		// This ensures that any currently running batches will see the cancelled status
		// and exit gracefully when they check the job status.
		$success = Bulk_Sync_Service::safe_update_job(
			$job_id,
			array(
				'status'       => 'cancelled',
				'cancelled_at' => current_time( 'mysql' ),
			)
		);

		if ( ! $success ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Job is already in a terminal state and cannot be cancelled', 'surecontact' ),
				),
				400
			);
		}

		// Cancel unified bulk sync hook actions.
		$statuses_to_cancel = array(
			\ActionScheduler_Store::STATUS_PENDING,
			\ActionScheduler_Store::STATUS_RUNNING,
		);

		$batch_args = array( 'job_id' => $job_id );

		foreach ( $statuses_to_cancel as $status ) {
			// All integrations use the unified hook with job_id as the argument.
			$actions = as_get_scheduled_actions(
				array(
					'hook'   => Bulk_Sync_Service::BATCH_HOOK,
					'args'   => $batch_args,
					'group'  => 'surecontact',
					'status' => $status,
				),
				'ids'
			);

			foreach ( $actions as $action_id ) {
				if ( $status === \ActionScheduler_Store::STATUS_RUNNING ) {
					try {
						\ActionScheduler::store()->mark_failure( $action_id );
						++$failed_count;
					} catch ( \Exception $e ) {
						as_unschedule_action( Bulk_Sync_Service::BATCH_HOOK, $batch_args, 'surecontact' );
						++$cancelled_count;
					}
				} else {
					as_unschedule_action( Bulk_Sync_Service::BATCH_HOOK, $batch_args, 'surecontact' );
					++$cancelled_count;
				}
			}
		}

		$total_stopped = $cancelled_count + $failed_count;
		$message       = sprintf(
			/* translators: %1$d: total stopped actions, %2$d: pending actions count, %3$d: running actions count */
			_n( 'Job cancelled successfully. Stopped %1$d action (%2$d pending, %3$d running).', 'Job cancelled successfully. Stopped %1$d actions (%2$d pending, %3$d running).', $total_stopped, 'surecontact' ),
			$total_stopped,
			$cancelled_count,
			$failed_count
		);

		// Store cancelled job ID.
		$cancelled_jobs = get_option( 'surecontact_sync_job_cancelled', array() );
		if ( ! is_array( $cancelled_jobs ) ) {
			$cancelled_jobs = array();
		}

		if ( ! in_array( $job_id, $cancelled_jobs, true ) ) {
			$cancelled_jobs[] = $job_id;
			update_option( 'surecontact_sync_job_cancelled', $cancelled_jobs );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $message,
				'data'    => array(
					'job_id'           => $job_id,
					'status'           => 'cancelled',
					'integration_type' => $integration_type,
					'cancelled_count'  => $cancelled_count,
					'failed_count'     => $failed_count,
					'total_stopped'    => $total_stopped,
				),
			),
			200
		);
	}

	/**
	 * Get available sync types
	 *
	 * @since 0.0.3
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_available_sync_types( WP_REST_Request $request ) {
		$sync_service = new Bulk_Sync_Service();
		$sync_types   = $sync_service->get_available_sync_types();
		$loader       = \SureContact::get_instance()->integrations_loader;

		// Enrich each sync type with require_field_mapping and mapping status.
		foreach ( $sync_types as &$sync_type ) {
			$slug = $sync_type['integration'] ?? '';

			if ( ! isset( $sync_type['require_field_mapping'] ) && $loader ) {
				$integration                        = $loader->get_integration( $slug );
				$sync_type['require_field_mapping'] = $integration && method_exists( $integration, 'get_require_field_mapping' ) ? $integration->get_require_field_mapping() : false;
			}

			// Add per-integration email mapping status.
			if ( ! empty( $sync_type['require_field_mapping'] ) ) {
				$sync_type['has_email_mapping'] = Field_Mapper::has_required_mapping( $slug );
			}
		}
		unset( $sync_type );

		return new WP_REST_Response(
			array(
				'success'    => true,
				'sync_types' => $sync_types,
			),
			200
		);
	}
}
