<?php
/**
 * WordPress User Sync
 *
 * Handles bulk synchronization of WordPress users to SureContact.
 * Each sync type gets its own file to keep complexity manageable.
 *
 * @since 1.2.0
 *
 * @package SureContact
 */

namespace SureContact\Integrations;

use SureContact\Logger;
use SureContact\Bulk_Sync_Service;
use SureContact\Contact_Service;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WordPress_User_Sync
 *
 * Manages bulk user synchronization for the WordPress integration.
 *
 * @since 1.2.0
 */
class WordPress_User_Sync {

	/**
	 * WordPress Integration instance.
	 *
	 * @since 1.2.0
	 *
	 * @var WordPress_Integration
	 */
	private $integration;

	/**
	 * Contact Service instance.
	 *
	 * @since 1.2.0
	 *
	 * @var \SureContact\Contact_Service
	 */
	private $contact_service;

	/**
	 * Batch size for user processing.
	 *
	 * @since 1.2.0
	 *
	 * @var int
	 */
	private $batch_size;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param WordPress_Integration $integration     Parent integration instance.
	 * @param Contact_Service       $contact_service Contact service instance.
	 */
	public function __construct( WordPress_Integration $integration, Contact_Service $contact_service ) {
		$this->integration     = $integration;
		$this->contact_service = $contact_service;
		$this->batch_size      = Bulk_Sync_Service::BATCH_SIZE;

		// Register with Bulk_Sync_Service for routing.
		Bulk_Sync_Service::register_sync_handler( 'all_users', $this );
	}

	/**
	 * Get available sync types for WordPress users.
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of sync type definitions.
	 */
	public function get_sync_types() {
		return array(
			array(
				'type'        => 'all_users',
				'title'       => __( 'Users', 'surecontact' ),
				'description' => __( 'Synchronize all WordPress users to SureContact in the background', 'surecontact' ),
			),
		);
	}

	/**
	 * Handle bulk sync for WordPress users.
	 *
	 * @since 1.2.0
	 *
	 * @param string $sync_type Sync type identifier.
	 * @return array Results with job information.
	 */
	public function handle_sync( $sync_type ) {
		$total_count = $this->get_total_user_count();

		if ( 0 === $total_count ) {
			return array(
				'success' => true,
				'message' => __( 'No users found to sync', 'surecontact' ),
			);
		}

		return $this->start_bulk_sync( $total_count, $sync_type );
	}

	/**
	 * Start the user bulk sync job.
	 *
	 * Creates job metadata and schedules the first batch via Action Scheduler.
	 * Processes first batch synchronously to get batch_uuid for tracking.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $total_count Total number of users to sync.
	 * @param string $sync_type   Sync type identifier (e.g. 'all_users').
	 * @return array Job response data.
	 */
	private function start_bulk_sync( $total_count, $sync_type = '' ) {
		$job_id        = uniqid( 'sync_job_', true );
		$batch_size    = $this->batch_size;
		$total_batches = (int) ceil( $total_count / $batch_size );

		// Process first batch synchronously to get batch_uuid.
		$first_batch_ids = $this->get_user_ids_with_offset( $batch_size, 0 );
		$batch_uuid      = $this->process_first_batch( $first_batch_ids );

		if ( is_wp_error( $batch_uuid ) || empty( $batch_uuid ) ) {
			return array(
				'success' => false,
				/* translators: %s: error message */
				'message' => sprintf( __( 'Failed to start sync: %s', 'surecontact' ), is_wp_error( $batch_uuid ) ? $batch_uuid->get_error_message() : 'No batch_uuid returned' ),
			);
		}

		// Store job metadata.
		$job_data = array(
			'job_id'            => $job_id,
			'batch_uuid'        => $batch_uuid,
			'total_users'       => $total_count,
			'total_batches'     => $total_batches,
			'processed_batches' => 1,
			'current_offset'    => $batch_size,
			'batch_size'        => $batch_size,
			'status'            => 'processing',
			'created_at'        => current_time( 'mysql' ),
			'type'              => $this->integration->get_slug(),
			'sync_type'         => $sync_type,
		);

		update_option( "surecontact_job_{$job_id}", $job_data );

		// Queue remaining batches via unified hook.
		if ( $total_batches > 1 ) {
			as_enqueue_async_action( Bulk_Sync_Service::BATCH_HOOK, array( 'job_id' => $job_id ), 'surecontact' );
		}

		// Return response using common formatter for consistency.
		$response = Bulk_Sync_Service::format_job_response( $job_id );
		if ( ! $response ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to retrieve job status', 'surecontact' ),
			);
		}

		return $response;
	}

	/**
	 * Process a batch of WordPress users for bulk sync.
	 *
	 * @since 1.2.0
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function process_batch( $job_id ) {
		// Check if job is in cancelled list.
		$cancelled_jobs = get_option( 'surecontact_sync_job_cancelled', array() );
		if ( is_array( $cancelled_jobs ) && in_array( $job_id, $cancelled_jobs, true ) ) {
			Logger::info( 'WordPress User Sync', "Job {$job_id} is cancelled, bypassing execution" );
			Bulk_Sync_Service::safe_update_job( $job_id, array( 'status' => 'cancelled' ) );
			return;
		}

		$job_data = get_option( "surecontact_job_{$job_id}" );

		if ( ! $job_data ) {
			Logger::error( 'WordPress User Sync', "Job {$job_id} not found" );
			return;
		}

		// Check if job has been cancelled or completed.
		if ( in_array( $job_data['status'], array( 'completed', 'cancelled' ), true ) ) {
			Logger::info( 'WordPress User Sync', "Job {$job_id} already {$job_data['status']}" );
			return;
		}

		$current_offset = $job_data['current_offset'];
		$batch_size     = $job_data['batch_size'];
		$batch_uuid     = $job_data['batch_uuid'];

		// Fetch user IDs for this batch.
		$user_ids = $this->get_user_ids_with_offset( $batch_size, $current_offset );

		if ( empty( $user_ids ) ) {
			// No more users - all batches sent. Update the job counter.
			Bulk_Sync_Service::safe_update_job(
				$job_id,
				array(
					'processed_batches' => $job_data['processed_batches'],
				)
			);

			Logger::info(
				'WordPress User Sync',
				sprintf(
					'Sync job %s: All contacts sent to SureContact (%d/%d batches). Waiting for processing to complete.',
					$job_id,
					$job_data['processed_batches'],
					$job_data['total_batches']
				)
			);
			return;
		}

		// Prepare contacts.
		$contacts = $this->prepare_users_batch( $user_ids );

		if ( ! empty( $contacts ) ) {
			$payload = array(
				'contacts'   => $contacts,
				'batch_uuid' => $batch_uuid,
			);
			$payload = $this->add_bulk_sync_lists_tags( $payload );

			// Send batch.
			$this->contact_service->batch_sync_contacts( $payload, array( 'source' => $this->integration->get_slug() ) );
		}

		// Update job progress.
		++$job_data['processed_batches'];
		$job_data['current_offset'] += $batch_size;

		// Check if this was the last batch.
		$is_last_batch = ( $job_data['current_offset'] >= $job_data['total_users'] );

		// Update job progress using safe_update_job BEFORE logging.
		// This ensures the counter is persisted before status polling reads it.
		Bulk_Sync_Service::safe_update_job(
			$job_id,
			array(
				'processed_batches' => $job_data['processed_batches'],
				'current_offset'    => $job_data['current_offset'],
			)
		);

		if ( $is_last_batch ) {
			Logger::info(
				'WordPress User Sync',
				sprintf(
					'Sync job %s: All %d contacts sent to SureContact (%d/%d batches). Waiting for processing to complete.',
					$job_id,
					$job_data['total_users'],
					$job_data['processed_batches'],
					$job_data['total_batches']
				)
			);
		}

		// Free memory.
		unset( $user_ids, $contacts, $payload );

		// Only schedule next batch if not the last one AND job is not cancelled.
		if ( ! $is_last_batch ) {
			// Re-check cancellation status before scheduling next batch.
			$job_data_check = get_option( "surecontact_job_{$job_id}" );
			if ( $job_data_check && isset( $job_data_check['status'] ) && 'cancelled' === $job_data_check['status'] ) {
				Logger::info( 'WordPress User Sync', "Job {$job_id} was cancelled, not scheduling next batch" );
				return;
			}

			// Schedule next batch in chain.
			as_enqueue_async_action(
				Bulk_Sync_Service::BATCH_HOOK,
				array( 'job_id' => $job_id ),
				'surecontact'
			);
		}
	}

	/**
	 * Process first batch synchronously to get batch_uuid.
	 *
	 * @since 1.2.0
	 *
	 * @param array $user_ids Array of user IDs.
	 * @return string|\WP_Error batch_uuid on success or WP_Error.
	 */
	private function process_first_batch( $user_ids ) {
		$contacts = $this->prepare_users_batch( $user_ids );

		if ( empty( $contacts ) ) {
			return new \WP_Error( 'no_contacts', __( 'No valid contacts to sync in first batch', 'surecontact' ) );
		}

		$payload = array( 'contacts' => $contacts );
		$payload = $this->add_bulk_sync_lists_tags( $payload );

		$result = $this->contact_service->batch_sync_contacts( $payload, array( 'source' => $this->integration->get_slug() ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['batch_uuid'] ) ) {
			return new \WP_Error( 'no_batch_uuid', __( 'CRM did not return batch_uuid', 'surecontact' ) );
		}

		return $result['batch_uuid'];
	}

	/**
	 * Get total count of users for bulk sync.
	 *
	 * @since 1.2.0
	 *
	 * @return int Total count.
	 */
	public function get_total_user_count() {
		$user_count = count_users();

		return (int) $user_count['total_users'];
	}

	/**
	 * Get user IDs with offset pagination.
	 *
	 * @since 1.2.0
	 *
	 * @param int $limit  Number of users to fetch.
	 * @param int $offset Offset for pagination.
	 * @return array Array of user IDs.
	 */
	public function get_user_ids_with_offset( $limit, $offset ) {
		$user_query = new \WP_User_Query(
			array(
				'fields'  => 'ID',
				'number'  => $limit,
				'offset'  => $offset,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		$user_ids = $user_query->get_results();

		return $user_ids ? array_map( 'intval', $user_ids ) : array();
	}

	/**
	 * Prepare users batch for bulk sync.
	 *
	 * @since 1.2.0
	 *
	 * @param array $user_ids Array of user IDs.
	 * @return array Array of prepared contact data.
	 */
	public function prepare_users_batch( $user_ids ) {
		$contacts = array();

		foreach ( $user_ids as $user_id ) {
			$contact_data = $this->prepare_user_for_sync( $user_id );

			if ( $contact_data && ! is_wp_error( $contact_data ) ) {
				$contacts[] = $contact_data;
			}

			unset( $contact_data );
		}

		return $contacts;
	}

	/**
	 * Prepare single user for bulk sync.
	 *
	 * @since 1.2.0
	 *
	 * @param int $user_id User ID.
	 * @return array|false Contact data or false.
	 */
	private function prepare_user_for_sync( $user_id ) {
		$user_data = $this->integration->get_user_data( $user_id );

		if ( empty( $user_data ) || empty( $user_data['user_email'] ) ) {
			return false;
		}

		// Normalize to CRM format using base class method.
		$mapped_data = $this->integration->normalize_data( $user_data );

		// Validate that email got mapped - skip if empty after sanitization.
		if ( empty( $mapped_data['primary_fields']['email'] ) ) {
			return false;
		}

		// Add role-based lists/tags for this user.
		$role_context = $this->integration->get_role_based_lists_tags_context( $user_id );
		if ( ! empty( $role_context['list_uuids'] ) ) {
			$mapped_data['list_uuids'] = $role_context['list_uuids'];
		}
		if ( ! empty( $role_context['tag_uuids'] ) ) {
			$mapped_data['tag_uuids'] = $role_context['tag_uuids'];
		}

		return $mapped_data;
	}

	/**
	 * Add lists and tags from global settings to bulk sync payload.
	 *
	 * WordPress user sync uses global lists/tags from surecontact_general_settings.
	 *
	 * @since 1.2.0
	 *
	 * @param array $payload Base payload.
	 * @return array Modified payload.
	 */
	public function add_bulk_sync_lists_tags( $payload ) {
		$all_settings  = get_option( 'surecontact_general_settings', array() );
		$sync_settings = isset( $all_settings['sync_settings'] ) ? $all_settings['sync_settings'] : array();

		// Use extract_uuids from Base_Integration to handle both formats.
		$list_uuids = $this->integration->extract_uuids(
			isset( $sync_settings['assigned_lists'] ) ? $sync_settings['assigned_lists'] : array()
		);
		$tag_uuids  = $this->integration->extract_uuids(
			isset( $sync_settings['assigned_tags'] ) ? $sync_settings['assigned_tags'] : array()
		);

		if ( ! empty( $list_uuids ) ) {
			$payload['list_uuids'] = $list_uuids;
		}

		if ( ! empty( $tag_uuids ) ) {
			$payload['tag_uuids'] = $tag_uuids;
		}

		return $payload;
	}
}
