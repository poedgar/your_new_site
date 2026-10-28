<?php
/**
 * FluentCRM Contact Sync
 *
 * Handles bulk synchronization of FluentCRM contacts to SureContact.
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
 * Class FluentCRM_Contact_Sync
 *
 * Manages bulk contact synchronization for the FluentCRM integration.
 *
 * @since 1.2.0
 */
class FluentCRM_Contact_Sync {

	/**
	 * FluentCRM Integration instance.
	 *
	 * @since 1.2.0
	 *
	 * @var FluentCRM_Integration
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
	 * Batch size for contact processing.
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
	 * @param FluentCRM_Integration $integration      Parent integration instance.
	 * @param Contact_Service       $contact_service   Contact service instance.
	 */
	public function __construct( FluentCRM_Integration $integration, Contact_Service $contact_service ) {
		$this->integration     = $integration;
		$this->contact_service = $contact_service;
		$this->batch_size      = Bulk_Sync_Service::BATCH_SIZE;

		// Register with Bulk_Sync_Service for routing.
		Bulk_Sync_Service::register_sync_handler( 'fluentcrm_contacts', $this );
	}

	/**
	 * Get available sync types for FluentCRM contacts.
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of sync type definitions.
	 */
	public function get_sync_types() {
		return array(
			array(
				'type'        => 'fluentcrm_contacts',
				'title'       => __( 'Contacts', 'surecontact' ),
				'description' => __( 'Synchronize all subscribed FluentCRM contacts to SureContact', 'surecontact' ),
			),
		);
	}

	/**
	 * Handle bulk sync for FluentCRM contacts.
	 *
	 * @since 1.2.0
	 *
	 * @param string $sync_type Sync type identifier.
	 * @return array Results with job information.
	 */
	public function handle_sync( $sync_type ) {
		return $this->handle_contacts_sync( $sync_type );
	}

	/**
	 * Handle bulk sync for FluentCRM contacts.
	 *
	 * Builds query arguments based on settings, counts subscribers,
	 * and starts the bulk sync process.
	 *
	 * @since 1.2.0
	 *
	 * @param string $sync_type Sync type identifier.
	 * @return array Results with job information.
	 */
	private function handle_contacts_sync( $sync_type ) {
		$query_args = array();

		// Apply status filter - respect sync_status_filter setting.
		$sync_status_filter_enabled = $this->integration->get_setting( 'sync_status_filter', true );
		$sync_status_filter_enabled = filter_var( $sync_status_filter_enabled, FILTER_VALIDATE_BOOLEAN );

		if ( $sync_status_filter_enabled ) {
			$query_args['status'] = 'subscribed';
		}

		$total_count = $this->get_total_subscriber_count( $query_args );

		if ( 0 === $total_count ) {
			return array(
				'success' => true,
				'message' => __( 'No subscribers to sync', 'surecontact' ),
			);
		}

		return $this->start_bulk_sync( $query_args, $total_count, $sync_type );
	}

	/**
	 * Start the contact bulk sync job.
	 *
	 * Creates job metadata and schedules the first batch via Action Scheduler.
	 * Processes first batch synchronously to get batch_uuid for tracking.
	 * Pre-syncs all metadata (lists, tags, custom fields) before batch processing.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $query_args  Query arguments for filtering subscribers.
	 * @param int    $total_count Total number of subscribers to sync.
	 * @param string $sync_type   Sync type identifier (e.g. 'fluentcrm_contacts').
	 * @return array Job response data or error array.
	 */
	private function start_bulk_sync( $query_args, $total_count, $sync_type = '' ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			Logger::error( 'FluentCRM Contact Sync', 'Action Scheduler is not available' );
			return array(
				'success' => false,
				'message' => __( 'Action Scheduler is not available', 'surecontact' ),
			);
		}

		// Pre-sync ALL lists/tags/custom fields from FluentCRM before batch processing.
		// This prevents redundant API calls during contact preparation.
		$this->pre_sync_all_metadata();

		$job_id        = uniqid( 'sync_job_', true );
		$batch_size    = $this->batch_size;
		$total_batches = (int) ceil( $total_count / $batch_size );

		// Process first batch synchronously to get batch_uuid.
		$subscriber_ids = $this->get_subscriber_ids_with_offset( $query_args, $batch_size, 0 );

		if ( empty( $subscriber_ids ) ) {
			Logger::error( 'FluentCRM Contact Sync', "No subscribers found for job {$job_id}" );
			return array(
				'success' => false,
				'message' => __( 'No subscribers found to sync', 'surecontact' ),
			);
		}

		$batch_uuid = $this->process_first_batch( $subscriber_ids );

		if ( is_wp_error( $batch_uuid ) || empty( $batch_uuid ) ) {
			$error_message = is_wp_error( $batch_uuid ) ? $batch_uuid->get_error_message() : 'No batch_uuid returned';
			return array(
				'success' => false,
				/* translators: %s: error message */
				'message' => sprintf( __( 'Failed to start sync: %s', 'surecontact' ), $error_message ),
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
			'query_args'        => $query_args,
			'status'            => 'processing',
			'created_at'        => current_time( 'mysql' ),
			'type'              => $this->integration->get_slug(),
			'sync_type'         => $sync_type,
		);

		update_option( "surecontact_job_{$job_id}", $job_data );

		// Queue remaining batches via unified hook.
		if ( $total_batches > 1 ) {
			as_enqueue_async_action( Bulk_Sync_Service::BATCH_HOOK, array( 'job_id' => $job_id ), 'surecontact' );
		} else {
			// Only one batch - log completion of sending phase.
			Logger::info(
				'FluentCRM Contact Sync',
				sprintf(
					'Sync job %s: All %d contacts sent to SureContact (1/1 batch). Waiting for processing to complete.',
					$job_id,
					$total_count
				)
			);
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
	 * Process a batch of FluentCRM contacts for bulk sync.
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
			Logger::info( 'FluentCRM Contact Sync', "Job {$job_id} is cancelled, bypassing execution" );
			Bulk_Sync_Service::safe_update_job( $job_id, array( 'status' => 'cancelled' ) );
			return;
		}

		$job_data = get_option( "surecontact_job_{$job_id}" );

		if ( ! $job_data ) {
			Logger::error( 'FluentCRM Contact Sync', "Job {$job_id} not found" );
			return;
		}

		// Check if job has been cancelled or completed.
		if ( in_array( $job_data['status'], array( 'completed', 'cancelled' ), true ) ) {
			Logger::info( 'FluentCRM Contact Sync', "Job {$job_id} already {$job_data['status']}" );
			return;
		}

		$current_offset = $job_data['current_offset'];
		$batch_size     = $job_data['batch_size'];
		$query_args     = $job_data['query_args'];
		$batch_uuid     = $job_data['batch_uuid'];

		// Fetch subscriber IDs for current batch using LIMIT/OFFSET.
		$subscriber_ids = $this->get_subscriber_ids_with_offset( $query_args, $batch_size, $current_offset );

		if ( empty( $subscriber_ids ) ) {
			// No more subscribers - all batches sent.
			Bulk_Sync_Service::safe_update_job(
				$job_id,
				array(
					'processed_batches' => $job_data['processed_batches'],
				)
			);

			Logger::info(
				'FluentCRM Contact Sync',
				sprintf(
					'Sync job %s: All contacts sent to SureContact (%d/%d batches). Waiting for processing to complete.',
					$job_id,
					$job_data['processed_batches'],
					$job_data['total_batches']
				)
			);
			return;
		}

		// Re-check if job was cancelled during subscriber ID fetching.
		$job_data = get_option( "surecontact_job_{$job_id}" );
		if ( $job_data && isset( $job_data['status'] ) && 'cancelled' === $job_data['status'] ) {
			Logger::info( 'FluentCRM Contact Sync', "Job {$job_id} was cancelled, aborting batch processing" );
			return;
		}

		// Process batch.
		if ( empty( $batch_uuid ) ) {
			// First batch - creates new batch_uuid.
			$result = $this->process_first_batch( $subscriber_ids );

			if ( ! is_wp_error( $result ) && ! empty( $result ) ) {
				$batch_uuid             = $result;
				$job_data['batch_uuid'] = $batch_uuid;
			}
		} else {
			// Subsequent batches - append to existing batch_uuid.
			$result = $this->process_subsequent_batch( $subscriber_ids, $batch_uuid );

		}

		// Update job progress.
		++$job_data['processed_batches'];
		$job_data['current_offset'] += $batch_size;

		// Check if this was the last batch.
		$is_last_batch = ( $job_data['current_offset'] >= $job_data['total_users'] );

		if ( $is_last_batch ) {
			Logger::info(
				'FluentCRM Contact Sync',
				sprintf(
					'Sync job %s: All %d contacts sent to SureContact (%d/%d batches). Waiting for processing to complete.',
					$job_id,
					$job_data['total_users'],
					$job_data['processed_batches'],
					$job_data['total_batches']
				)
			);
		}

		// Update job progress using safe_update_job.
		Bulk_Sync_Service::safe_update_job(
			$job_id,
			array(
				'processed_batches' => $job_data['processed_batches'],
				'current_offset'    => $job_data['current_offset'],
				'batch_uuid'        => $job_data['batch_uuid'],
			)
		);

		// Free memory.
		unset( $subscriber_ids, $result );

		// Only schedule next batch if not the last one AND job is not cancelled.
		if ( ! $is_last_batch ) {
			// Re-check cancellation status before scheduling next batch.
			$job_data_check = get_option( "surecontact_job_{$job_id}" );
			if ( $job_data_check && isset( $job_data_check['status'] ) && 'cancelled' === $job_data_check['status'] ) {
				Logger::info( 'FluentCRM Contact Sync', "Job {$job_id} was cancelled, not scheduling next batch" );
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
	 * @param array $subscriber_ids Array of subscriber IDs.
	 * @return string|\WP_Error Batch UUID or error.
	 */
	private function process_first_batch( $subscriber_ids ) {
		$contacts = $this->prepare_contacts_from_subscribers( $subscriber_ids );

		if ( is_wp_error( $contacts ) ) {
			return $contacts;
		}

		if ( empty( $contacts ) ) {
			return new \WP_Error( 'no_valid_contacts', __( 'No valid contacts to sync', 'surecontact' ) );
		}

		$result = $this->contact_service->batch_sync_contacts( array( 'contacts' => $contacts ), array( 'source' => $this->integration->get_slug() ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['batch_uuid'] ) ) {
			return new \WP_Error( 'no_batch_uuid', __( 'CRM did not return batch_uuid', 'surecontact' ) );
		}

		return $result['batch_uuid'];
	}

	/**
	 * Process subsequent batch with existing batch_uuid.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $subscriber_ids Array of subscriber IDs.
	 * @param string $batch_uuid     Batch UUID to append to.
	 * @return true|\WP_Error True on success or error.
	 */
	private function process_subsequent_batch( $subscriber_ids, $batch_uuid ) {
		$contacts = $this->prepare_contacts_from_subscribers( $subscriber_ids );

		if ( is_wp_error( $contacts ) ) {
			return $contacts;
		}

		if ( empty( $contacts ) ) {
			return new \WP_Error( 'no_valid_contacts', __( 'No valid contacts to sync', 'surecontact' ) );
		}

		$result = $this->contact_service->batch_sync_contacts(
			array(
				'contacts'   => $contacts,
				'batch_uuid' => $batch_uuid,
			),
			array( 'source' => $this->integration->get_slug() )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Get total count of FluentCRM subscribers matching filters.
	 *
	 * @since 1.2.0
	 *
	 * @param array $args Query arguments.
	 * @return int Subscriber count.
	 */
	public function get_total_subscriber_count( $args = array() ) {
		if ( ! class_exists( 'FluentCrm\App\Models\Subscriber' ) ) {
			return 0;
		}

		$query = \FluentCrm\App\Models\Subscriber::query();

		// Apply status filter.
		if ( isset( $args['status'] ) ) {
			$query->where( 'status', $args['status'] );
		}

		// Apply list filter.
		if ( ! empty( $args['lists'] ) ) {
			$query->filterByLists( $args['lists'] );
		}

		// Apply tag filter.
		if ( ! empty( $args['tags'] ) ) {
			$query->filterByTags( $args['tags'] );
		}

		return $query->count();
	}

	/**
	 * Get subscriber IDs with LIMIT/OFFSET for chain processing.
	 * Memory-efficient method that only fetches IDs for current batch.
	 *
	 * Uses ORDER BY id ASC to ensure stable pagination even when new contacts
	 * are added during the sync process.
	 *
	 * @since 1.2.0
	 *
	 * @param array $args   Query arguments.
	 * @param int   $limit  Batch size.
	 * @param int   $offset Starting offset.
	 * @return array Array of subscriber IDs.
	 */
	private function get_subscriber_ids_with_offset( $args, $limit, $offset ) {
		if ( ! class_exists( 'FluentCrm\App\Models\Subscriber' ) ) {
			return array();
		}

		$query = \FluentCrm\App\Models\Subscriber::query();

		// Apply filters.
		if ( isset( $args['status'] ) ) {
			$query->where( 'status', $args['status'] );
		}

		if ( ! empty( $args['lists'] ) ) {
			$query->filterByLists( $args['lists'] );
		}

		if ( ! empty( $args['tags'] ) ) {
			$query->filterByTags( $args['tags'] );
		}

		return $query->select( 'id' )
			->orderBy( 'id', 'ASC' )
			->limit( $limit )
			->offset( $offset )
			->pluck( 'id' )
			->toArray();
	}

	/**
	 * Prepare contacts from subscriber IDs.
	 * Optimized with eager loading to prevent N+1 queries.
	 *
	 * @since 1.2.0
	 *
	 * @param array $subscriber_ids Array of subscriber IDs.
	 * @return array|\WP_Error Array of contact data or error.
	 */
	private function prepare_contacts_from_subscribers( $subscriber_ids ) {
		if ( ! class_exists( 'FluentCrm\App\Models\Subscriber' ) ) {
			return new \WP_Error( 'fluentcrm_not_active', __( 'FluentCRM is not active', 'surecontact' ) );
		}

		// Eager load relations to prevent N+1 queries.
		// Only select necessary fields to reduce memory usage.
		$query = \FluentCrm\App\Models\Subscriber::select(
			array(
				'id',
				'email',
				'prefix',
				'first_name',
				'last_name',
				'phone',
				'address_line_1',
				'address_line_2',
				'city',
				'state',
				'postal_code',
				'country',
				'timezone',
				'date_of_birth',
				'status',
				'contact_type',
				'source',
				'life_time_value',
				'total_points',
				'user_id',
				'created_at',
				'last_activity',
				'updated_at',
			)
		)->whereIn( 'id', $subscriber_ids );

		// Conditionally eager load lists and tags based on settings.
		$sync_lists_enabled = $this->integration->is_setting_enabled( 'sync_lists', true );
		$sync_tags_enabled  = $this->integration->is_setting_enabled( 'sync_tags', true );

		if ( $sync_lists_enabled ) {
			$query->with(
				array(
					'lists' => function ( $query ) {
						$query->select( array( 'fc_lists.id', 'fc_lists.title', 'fc_lists.description' ) );
					},
				)
			);
		}

		if ( $sync_tags_enabled ) {
			$query->with(
				array(
					'tags' => function ( $query ) {
						$query->select( array( 'fc_tags.id', 'fc_tags.title' ) );
					},
				)
			);
		}

		$subscribers = $query->get();

		if ( $subscribers->isEmpty() ) {
			return new \WP_Error( 'no_subscribers', __( 'No subscribers found', 'surecontact' ) );
		}

		// Prepare contacts.
		$contacts = array();
		foreach ( $subscribers as $subscriber ) {
			$subscriber_data = $this->integration->prepare_subscriber_data( $subscriber );
			$mapped_data     = $this->integration->normalize_data( $subscriber_data );

			// Skip contact if email is missing (required by SureContact API).
			if ( empty( $mapped_data['primary_fields']['email'] ) ) {
				Logger::warning(
					'FluentCRM Contact Sync',
					sprintf(
						'Skipping subscriber ID %d: Invalid or missing email address (%s)',
						$subscriber->id,
						$subscriber->email ?? 'empty'
					)
				);
				unset( $subscriber_data, $mapped_data );
				continue;
			}

			$contacts[] = $mapped_data;

			// Free memory immediately after processing each subscriber.
			unset( $subscriber_data, $mapped_data );
		}

		// Free memory after batch processing.
		unset( $subscribers );

		return $contacts;
	}

	/**
	 * Pre-sync ALL lists, tags, and custom fields from FluentCRM.
	 *
	 * Optimization: Only syncs metadata that's not already in the mappings.
	 * Uses FluentCRM's built-in functions to fetch all metadata at once,
	 * dramatically reducing API calls for large datasets.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function pre_sync_all_metadata() {
		if ( ! class_exists( 'FluentCrm\App\Models\Lists' ) ) {
			return;
		}

		$start_time = microtime( true );

		// Get sync settings.
		$sync_lists_enabled  = $this->integration->is_setting_enabled( 'sync_lists', true );
		$sync_tags_enabled   = $this->integration->is_setting_enabled( 'sync_tags', true );
		$sync_custom_enabled = $this->integration->is_setting_enabled( 'sync_custom_fields', true );

		$lists_synced  = 0;
		$tags_synced   = 0;
		$fields_synced = 0;

		// Sync lists from FluentCRM. Always run search-or-create so that a
		// workspace switch or SaaS-side wipe — which leaves stale UUIDs in
		// `metadata_mappings['lists']` — gets refreshed at bulk-sync start
		// rather than failing the entire batch with a 422 invalid list_uuid.
		// Calls `sync_metadata` directly (instead of `sync_and_get_uuids`)
		// because the latter has its own cache-skip that would prevent
		// refreshing already-mapped entries.
		if ( $sync_lists_enabled ) {
			$all_lists = \FluentCrm\App\Models\Lists::all();

			if ( ! empty( $all_lists ) ) {
				$lists_to_sync = array();
				foreach ( $all_lists as $list ) {
					$lists_to_sync[] = array(
						'id'          => $list->id,
						'name'        => $list->title,
						'description' => $list->description ?? '',
					);
				}

				if ( ! empty( $lists_to_sync ) ) {
					$mappings = $this->contact_service->sync_metadata( $lists_to_sync, 'list' );
					if ( ! empty( $mappings ) ) {
						$this->integration->update_metadata_mapping( 'lists', $mappings );
						$lists_synced = count( $mappings );
					}
				}
			}
		}

		// Sync tags from FluentCRM. Same rationale as lists above.
		if ( $sync_tags_enabled ) {
			if ( class_exists( 'FluentCrm\App\Models\Tag' ) ) {
				$all_tags = \FluentCrm\App\Models\Tag::all();

				if ( ! empty( $all_tags ) ) {
					$tags_to_sync = array();
					foreach ( $all_tags as $tag ) {
						$tags_to_sync[] = array(
							'id'   => $tag->id,
							'name' => $tag->title,
						);
					}

					if ( ! empty( $tags_to_sync ) ) {
						$mappings = $this->contact_service->sync_metadata( $tags_to_sync, 'tag' );
						if ( ! empty( $mappings ) ) {
							$this->integration->update_metadata_mapping( 'tags', $mappings );
							$tags_synced = count( $mappings );
						}
					}
				}
			}
		}

		// Sync custom fields from FluentCRM - only new ones.
		if ( $sync_custom_enabled ) {
			if ( function_exists( 'fluentcrm_get_custom_contact_fields' ) ) {
				$all_custom_fields = fluentcrm_get_custom_contact_fields();

				if ( ! empty( $all_custom_fields ) && is_array( $all_custom_fields ) ) {
					// Fetch existing custom fields from CRM once to avoid multiple API calls.
					$fields_array = $this->contact_service->get_custom_fields();

					$existing_crm_fields_map = array();

					if ( ! is_wp_error( $fields_array ) && is_array( $fields_array ) ) {
						foreach ( $fields_array as $field ) {
							if ( is_array( $field ) && isset( $field['name'] ) ) {
								$existing_crm_fields_map[ $field['name'] ] = $field;
							}
						}
					}

					// Always run search-or-create per field; a workspace switch
					// or SaaS-side wipe leaves stale `custom_fields` mappings,
					// and the search step here refreshes them at bulk-sync
					// start rather than letting the per-contact payload carry
					// dead keys that Laravel silently drops.
					foreach ( $all_custom_fields as $field_config ) {
						if ( empty( $field_config['slug'] ) ) {
							continue;
						}

						$fc_slug        = $field_config['slug'];
						$crm_field_name = 'fc_' . $fc_slug;

						if ( isset( $existing_crm_fields_map[ $crm_field_name ] ) ) {
							// Field exists - create / refresh mapping without creating it.
							++$fields_synced;
							$this->integration->update_metadata_mapping( 'custom_fields', array( $fc_slug => $crm_field_name ) );
						} else {
							// Field doesn't exist - create it.
							$field_data = $this->integration->prepare_custom_field_for_sync( $crm_field_name, $field_config );

							$result = $this->contact_service->sync_custom_field( $field_data, array( 'source' => $this->integration->get_slug() ) );
							if ( ! is_wp_error( $result ) ) {
								++$fields_synced;
								$this->integration->update_metadata_mapping( 'custom_fields', array( $fc_slug => $crm_field_name ) );
								$existing_crm_fields_map[ $crm_field_name ] = array( 'name' => $crm_field_name );
							}
						}
					}
				}
			}
		}

		$duration = round( microtime( true ) - $start_time, 2 );
		Logger::info(
			'FluentCRM Contact Sync',
			sprintf(
				'Pre-synced metadata in %ss: %d lists, %d tags, %d custom fields',
				$duration,
				$lists_synced,
				$tags_synced,
				$fields_synced
			)
		);
	}
}
