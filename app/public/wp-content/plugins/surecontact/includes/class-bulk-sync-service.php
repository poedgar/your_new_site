<?php
/**
 * Bulk Sync Service Class
 *
 * Orchestrates bulk synchronization operations by delegating to integration-specific handlers.
 * Manages job status tracking, polling, and coordination between integrations.
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact;

use SureContact\API\Contact_API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bulk_Sync_Service
 *
 * Orchestrates bulk synchronization operations by delegating to integration-specific handlers.
 * Manages job status tracking, polling, and coordination between integrations.
 *
 * @since 0.0.1
 */
class Bulk_Sync_Service {

	/**
	 * Unified Action Scheduler hook for all bulk sync operations
	 *
	 * @since 0.0.4
	 *
	 * @var string
	 */
	const BATCH_HOOK = 'surecontact_process_bulk_sync_batch';

	/**
	 * Batch size for bulk sync
	 *
	 * @since 0.0.1
	 *
	 * @var int
	 */
	const BATCH_SIZE = 100;

	/**
	 * Batch size for individual API call operations (e.g. order tracking).
	 * Smaller than BATCH_SIZE since each item requires its own API call.
	 *
	 * @since 0.0.1
	 *
	 * @var int
	 */
	const ORDER_BATCH_SIZE = 20;

	/**
	 * Registered bulk sync handlers (sync_type => handler instance).
	 *
	 * Handlers are keyed by sync_type for direct routing.
	 *
	 * @since 0.0.1
	 *
	 * @var array
	 */
	private static $sync_handlers = array();

	/**
	 * Contact API instance for status polling
	 *
	 * @since 0.0.4
	 *
	 * @var Contact_API
	 */
	private $contact_api;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		$this->contact_api = new Contact_API(); // Used for batch status polling.

		// Verify Action Scheduler is available.
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			Logger::error( 'Bulk Sync Service', 'Action Scheduler is not available. Background sync will not work.' );
			return;
		}

		// Register the unified bulk sync batch hook (accepts job_id).
		add_action( self::BATCH_HOOK, array( __CLASS__, 'dispatch_batch' ), 10, 1 );
	}

	/**
	 * Register a bulk sync handler for routing.
	 *
	 * @since 0.0.1
	 *
	 * @param string $sync_type Sync type identifier (e.g. 'surecart_orders').
	 * @param object $handler   Handler with handle_sync() and process_batch() methods.
	 * @return void
	 */
	public static function register_sync_handler( $sync_type, $handler ) {
		self::$sync_handlers[ $sync_type ] = $handler;
	}

	/**
	 * Dispatch batch processing to the appropriate handler.
	 *
	 * Routes to the sync handler registered for this job's sync_type.
	 *
	 * @since 0.0.4
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public static function dispatch_batch( $job_id ) {
		$job_data = get_option( "surecontact_job_{$job_id}" );

		if ( ! $job_data ) {
			Logger::error( 'Bulk Sync', "Job {$job_id} not found" );
			return;
		}

		$sync_type = isset( $job_data['sync_type'] ) ? $job_data['sync_type'] : '';

		if ( ! empty( $sync_type ) && isset( self::$sync_handlers[ $sync_type ] ) ) {
			self::$sync_handlers[ $sync_type ]->process_batch( $job_id );
			return;
		}

		Logger::error( 'Bulk Sync', "No handler for sync type: {$sync_type}" );
	}

	/**
	 * Sync all contacts for a given integration type
	 *
	 * Routes to the sync handler registered for the sync_type.
	 *
	 * @since 0.0.4
	 *
	 * @param string $integration_type Integration type (WordPress, woocommerce, fluentcrm, surecart).
	 * @param array  $args             Optional. Arguments including 'sync_type'.
	 * @return array Results with job information
	 */
	public function sync_integration( $integration_type, $args = array() ) {
		$sync_type = isset( $args['sync_type'] ) ? $args['sync_type'] : '';

		if ( ! empty( $sync_type ) && isset( self::$sync_handlers[ $sync_type ] ) ) {
			return self::$sync_handlers[ $sync_type ]->handle_sync( $sync_type );
		}

		return array(
			'success' => false,
			'message' => ucfirst( $integration_type ) . ' integration not available',
		);
	}

	/**
	 * Get job status for tracking and API responses
	 *
	 * @since 0.0.4
	 *
	 * @param string $job_id Job ID.
	 * @param bool   $refresh Whether to fetch fresh status from CRM (default: true).
	 * @return array|\WP_Error Job status or error
	 */
	public function get_job_status( $job_id, $refresh = true ) {
		$job_info = get_option( "surecontact_job_{$job_id}" );

		if ( ! $job_info ) {
			return new \WP_Error( 'invalid_job', 'Job not found' );
		}

		// If job is not in terminal state and refresh is enabled, check CRM for updated status.
		if ( $refresh && ! in_array( $job_info['status'], array( 'completed', 'cancelled', 'failed' ), true ) && ! empty( $job_info['batch_uuid'] ) ) {
			$updated_job_info = $this->check_and_update_batch_status( $job_id, $job_info );

			if ( $updated_job_info ) {
				$job_info = $updated_job_info;
			}
		}

		// Format and return job status response.
		return self::format_job_response( $job_id, $job_info ) ?? array();
	}

	/**
	 * Check and update batch status from CRM
	 *
	 * @since 0.0.1
	 *
	 * @param string $job_id Job ID.
	 * @param array  $job_info Job info.
	 * @return array|null Updated job info or null
	 */
	private function check_and_update_batch_status( $job_id, $job_info ) {
		$batch_uuid = $job_info['batch_uuid'];
		$status     = $this->contact_api->get_batch_status( $batch_uuid );

		if ( is_wp_error( $status ) ) {
			return $job_info;
		}

		$data = isset( $status['data'] ) ? $status['data'] : $status;

		// Update real-time stats from CRM.
		$job_info['crm_batch_status'] = $status;
		if ( isset( $data['created'] ) ) {
			$job_info['created'] = (int) $data['created'];
		}
		if ( isset( $data['updated'] ) ) {
			$job_info['updated'] = (int) $data['updated'];
		}
		if ( isset( $data['failed'] ) ) {
			$job_info['failed'] = (int) $data['failed'];
		}
		if ( isset( $data['created'] ) && isset( $data['updated'] ) ) {
			$job_info['synced'] = (int) $data['created'] + (int) $data['updated'];
		}

		// Save updated job info.
		self::safe_update_job(
			$job_id,
			array(
				'crm_batch_status' => $status,
				'created'          => $job_info['created'] ?? null,
				'updated'          => $job_info['updated'] ?? null,
				'failed'           => $job_info['failed'] ?? null,
				'synced'           => $job_info['synced'] ?? null,
			)
		);

		// Re-fetch job info to get latest processed_batches count.
		$job_info = get_option( "surecontact_job_{$job_id}" );

		// Check completion conditions.
		$all_batches_sent = $job_info['processed_batches'] >= $job_info['total_batches'];
		$crm_completed    = isset( $data['status'] ) && $data['status'] === 'completed';

		if ( $all_batches_sent && $crm_completed ) {
			self::safe_update_job(
				$job_id,
				array(
					'status'       => 'completed',
					'completed_at' => current_time( 'mysql' ),
				)
			);
			$job_info = get_option( "surecontact_job_{$job_id}" );
			Logger::info( 'Bulk Sync Service', "Job {$job_id} completed successfully" );

			/**
			 * Fires after a bulk-sync job reaches the `completed` state.
			 *
			 * Allows integrations to run follow-up work that needs all batches
			 * to have landed on the SaaS — e.g. linking contacts to companies
			 * after a FluentCRM contact bulk sync, since per-contact UUIDs are
			 * only resolvable post-completion.
			 *
			 * @since 1.5.1
			 *
			 * @param string $job_id   Job ID.
			 * @param array  $job_info Full job info array, including `sync_type` and `type` keys.
			 */
			do_action( 'surecontact_bulk_sync_completed', $job_id, $job_info );
		}

		return $job_info;
	}

	/**
	 * Format job response for API consistency
	 *
	 * Used by both get_job_status and start_bulk_sync to ensure consistent response format.
	 * Fetches job data from the database if only job_id is provided.
	 *
	 * @since 0.0.4
	 *
	 * @param string     $job_id   Job ID.
	 * @param array|null $job_info Optional. Job info array. If null, fetches from database.
	 * @return array|null Formatted response with consistent keys for frontend consumption, or null if job not found.
	 */
	public static function format_job_response( $job_id, $job_info = null ) {
		// Fetch job data from database if not provided.
		if ( null === $job_info ) {
			$job_info = get_option( "surecontact_job_{$job_id}" );
			if ( ! $job_info ) {
				return null;
			}
		}

		$total_batches       = isset( $job_info['total_batches'] ) ? (int) $job_info['total_batches'] : 1;
		$processed_batches   = isset( $job_info['processed_batches'] ) ? (int) $job_info['processed_batches'] : 0;
		$progress_percentage = $total_batches > 0 ? round( ( $processed_batches / $total_batches ) * 100, 2 ) : 0;
		$integration_type    = isset( $job_info['type'] ) ? $job_info['type'] : 'WordPress';
		$label               = self::get_label_for_type( $integration_type );
		$sync_type_title     = self::resolve_sync_type_title( $job_info );

		$sync_type = isset( $job_info['sync_type'] ) ? (string) $job_info['sync_type'] : '';

		return array(
			'job_id'              => $job_id,
			'label'               => $label,
			'sync_type'           => $sync_type,
			'sync_type_title'     => $sync_type_title,
			'batch_uuid'          => isset( $job_info['batch_uuid'] ) ? $job_info['batch_uuid'] : null,
			'total_users'         => isset( $job_info['total_users'] ) ? (int) $job_info['total_users'] : 0,
			'total_batches'       => $total_batches,
			'processed_batches'   => $processed_batches,
			'progress_percentage' => $progress_percentage,
			'status'              => isset( $job_info['status'] ) ? $job_info['status'] : 'processing',
			'created_at'          => isset( $job_info['created_at'] ) ? surecontact_format_date_for_api( $job_info['created_at'] ) : null,
			'completed_at'        => isset( $job_info['completed_at'] ) ? surecontact_format_date_for_api( $job_info['completed_at'] ) : null,
			'synced'              => isset( $job_info['synced'] ) ? (int) $job_info['synced'] : 0,
			'created'             => isset( $job_info['created'] ) ? (int) $job_info['created'] : 0,
			'updated'             => isset( $job_info['updated'] ) ? (int) $job_info['updated'] : 0,
			'failed'              => isset( $job_info['failed'] ) ? (int) $job_info['failed'] : 0,
			// Company-sync counters. Populated only by the FluentCRM company sync
			// handler; other sync types leave these at 0 and the UI only renders
			// them when `sync_type === 'fluentcrm_companies'`.
			'companies_synced'    => isset( $job_info['success_count'] ) ? (int) $job_info['success_count'] : 0,
			'companies_failed'    => isset( $job_info['failure_count'] ) ? (int) $job_info['failure_count'] : 0,
			'linked_contacts'     => isset( $job_info['linked_contacts'] ) ? (int) $job_info['linked_contacts'] : 0,
			'total_contact_links' => isset( $job_info['total_contact_links'] ) ? (int) $job_info['total_contact_links'] : 0,
		);
	}

	/**
	 * Update job data safely
	 *
	 * @since 0.0.3
	 *
	 * @param string $job_id Job ID.
	 * @param array  $updates Updates to apply.
	 * @return bool True on success
	 */
	public static function safe_update_job( string $job_id, array $updates ): bool {
		$job_info = get_option( "surecontact_job_{$job_id}" );

		if ( ! $job_info ) {
			return false;
		}

		$job_info = array_merge( $job_info, $updates );
		update_option( "surecontact_job_{$job_id}", $job_info );

		return true;
	}

	/**
	 * Get display label for a job type by looking up the integration dynamically
	 *
	 * First tries to get the name from the loaded integration instance.
	 * Falls back to the integration config for cases where the integration
	 * may not be loaded yet or is disabled.
	 *
	 * @since 1.2.0
	 *
	 * @param string $type Integration slug (e.g. 'woocommerce', 'fluentcrm').
	 * @return string Display label for the job type.
	 */
	private static function get_label_for_type( $type ) {
		$integrations_loader = \surecontact()->integrations_loader;
		if ( ! $integrations_loader ) {
			return $type;
		}

		// Try loaded integration first.
		$integration = $integrations_loader->get_integration( $type );

		if ( $integration && method_exists( $integration, 'get_name' ) ) {
			return $integration->get_name();
		}

		// Fallback to integration config.
		$config = $integrations_loader->get_integration_config( $type );

		if ( $config && ! empty( $config['name'] ) ) {
			return $config['name'];
		}

		return $type;
	}

	/**
	 * Get available sync types based on active integrations
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of available sync types
	 */
	public function get_available_sync_types() {
		/**
		 * Filter available sync types
		 *
		 * Each integration registers its own sync types via get_sync_types().
		 *
		 * @since 0.0.3
		 *
		 * @param array $sync_types Array of sync type definitions.
		 */
		return apply_filters( 'surecontact_available_sync_types', array() );
	}

	/**
	 * Resolve sync type title dynamically from registered sync types.
	 *
	 * @since 1.2.0
	 *
	 * @param array $job_info Job data from database.
	 * @return string Resolved title.
	 */
	private static function resolve_sync_type_title( $job_info ) {
		static $title_map = null;

		if ( null === $title_map ) {
			$title_map = array();
			foreach ( apply_filters( 'surecontact_available_sync_types', array() ) as $entry ) {
				if ( isset( $entry['type'], $entry['title'] ) ) {
					$title_map[ $entry['type'] ] = $entry['title'];
				}
			}
		}

		$sync_type = isset( $job_info['sync_type'] ) ? $job_info['sync_type'] : '';

		if ( ! empty( $sync_type ) && isset( $title_map[ $sync_type ] ) ) {
			return $title_map[ $sync_type ];
		}

		// Fallback to stored value for jobs whose sync type is no longer registered.
		return isset( $job_info['sync_type_title'] ) ? $job_info['sync_type_title'] : '';
	}
}
