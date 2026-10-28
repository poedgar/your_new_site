<?php
/**
 * WooCommerce Customer Sync
 *
 * Handles bulk synchronization of WooCommerce customers to SureContact.
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
 * Class WooCommerce_Customer_Sync
 *
 * Manages bulk customer synchronization for the WooCommerce integration.
 *
 * @since 1.2.0
 */
class WooCommerce_Customer_Sync {

	/**
	 * WooCommerce Integration instance.
	 *
	 * @since 1.2.0
	 *
	 * @var WooCommerce_Integration
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
	 * Batch size for customer processing.
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
	 * @param WooCommerce_Integration $integration     Parent integration instance.
	 * @param Contact_Service         $contact_service Contact service instance.
	 */
	public function __construct( WooCommerce_Integration $integration, Contact_Service $contact_service ) {
		$this->integration     = $integration;
		$this->contact_service = $contact_service;
		$this->batch_size      = Bulk_Sync_Service::BATCH_SIZE;

		// Register with Bulk_Sync_Service for routing.
		Bulk_Sync_Service::register_sync_handler( 'woocommerce_customers', $this );
	}

	/**
	 * Get available sync types for WooCommerce customers.
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of sync type definitions.
	 */
	public function get_sync_types() {
		return array(
			array(
				'type'        => 'woocommerce_customers',
				'title'       => __( 'Customers', 'surecontact' ),
				'description' => __( 'Synchronize all WooCommerce customers (including guests) to SureContact', 'surecontact' ),
			),
		);
	}

	/**
	 * Handle bulk sync for WooCommerce customers.
	 *
	 * @since 1.2.0
	 *
	 * @param string $sync_type Sync type identifier.
	 * @return array Results with job information.
	 */
	public function handle_sync( $sync_type ) {
		$total_count = $this->get_total_customer_count();

		if ( 0 === $total_count ) {
			return array(
				'success' => true,
				'message' => __( 'No customers found to sync', 'surecontact' ),
			);
		}

		return $this->start_bulk_sync( $total_count, $sync_type );
	}

	/**
	 * Start the customer bulk sync job.
	 *
	 * Creates job metadata and schedules the first batch via Action Scheduler.
	 * Processes first batch synchronously to get batch_uuid for tracking.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $total_count Total number of customers to sync.
	 * @param string $sync_type   Sync type identifier (e.g. 'woocommerce_customers').
	 * @return array Job response data.
	 */
	public function start_bulk_sync( $total_count, $sync_type = '' ) {
		$job_id        = uniqid( 'sync_job_', true );
		$batch_size    = $this->batch_size;
		$total_batches = (int) ceil( $total_count / $batch_size );

		// Process first batch synchronously to get batch_uuid.
		$first_batch_ids = $this->get_customer_ids_with_offset( $batch_size, 0 );
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
	 * Process a batch of WooCommerce customers for bulk sync.
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
			Logger::info( 'WooCommerce Customer Sync', "Job {$job_id} is cancelled, bypassing execution" );
			Bulk_Sync_Service::safe_update_job( $job_id, array( 'status' => 'cancelled' ) );
			return;
		}

		$job_data = get_option( "surecontact_job_{$job_id}" );

		if ( ! $job_data ) {
			Logger::error( 'WooCommerce Customer Sync', "Job {$job_id} not found" );
			return;
		}

		// Check if job has been cancelled or completed.
		if ( in_array( $job_data['status'], array( 'completed', 'cancelled' ), true ) ) {
			Logger::info( 'WooCommerce Customer Sync', "Job {$job_id} already {$job_data['status']}" );
			return;
		}

		$current_offset = $job_data['current_offset'];
		$batch_size     = $job_data['batch_size'];
		$batch_uuid     = $job_data['batch_uuid'];

		// Fetch customer IDs for this batch.
		$customer_ids = $this->get_customer_ids_with_offset( $batch_size, $current_offset );

		if ( empty( $customer_ids ) ) {
			// No more customers - all batches sent. Update the job counter.
			Bulk_Sync_Service::safe_update_job(
				$job_id,
				array(
					'processed_batches' => $job_data['processed_batches'],
				)
			);

			Logger::info(
				'WooCommerce Customer Sync',
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
		$contacts = $this->prepare_customers_batch( $customer_ids );

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
				'WooCommerce Customer Sync',
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
		unset( $customer_ids, $contacts, $payload );

		// Only schedule next batch if not the last one AND job is not cancelled.
		if ( ! $is_last_batch ) {
			// Re-check cancellation status before scheduling next batch.
			$job_data_check = get_option( "surecontact_job_{$job_id}" );
			if ( $job_data_check && isset( $job_data_check['status'] ) && 'cancelled' === $job_data_check['status'] ) {
				Logger::info( 'WooCommerce Customer Sync', "Job {$job_id} was cancelled, not scheduling next batch" );
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
	 * @param array $customer_ids Array of customer IDs/emails.
	 * @return string|\WP_Error batch_uuid on success or WP_Error.
	 */
	private function process_first_batch( $customer_ids ) {
		$contacts = $this->prepare_customers_batch( $customer_ids );

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
	 * Get total count of WooCommerce customers from wc_customer_lookup table.
	 *
	 * This table contains both registered users (user_id > 0) and guests (user_id = 0).
	 *
	 * @since 1.2.0
	 *
	 * @return int Total count of customers.
	 */
	public function get_total_customer_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_customer_lookup WHERE email != ''" );

		return (int) $count;
	}

	/**
	 * Get customer IDs with offset pagination.
	 *
	 * @since 1.2.0
	 *
	 * @param int $limit  Number of customers to fetch.
	 * @param int $offset Offset for pagination.
	 * @return array Array of wc_customer_lookup customer_ids.
	 */
	public function get_customer_ids_with_offset( $limit, $offset ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$customer_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT customer_id FROM {$wpdb->prefix}wc_customer_lookup WHERE email != '' ORDER BY customer_id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		return $customer_ids ? array_map( 'intval', $customer_ids ) : array();
	}

	/**
	 * Prepare customers batch for bulk sync.
	 *
	 * @since 1.2.0
	 *
	 * @param array $customer_ids Array of wc_customer_lookup customer_ids.
	 * @return array Array of prepared contact data.
	 */
	public function prepare_customers_batch( $customer_ids ) {
		$contacts = array();

		foreach ( $customer_ids as $customer_id ) {
			$contact_data = $this->prepare_customer_from_lookup( $customer_id );

			if ( $contact_data && ! is_wp_error( $contact_data ) ) {
				$contacts[] = $contact_data;
			}

			unset( $contact_data );
		}

		return $contacts;
	}

	/**
	 * Prepare customer for bulk sync using wc_customer_lookup table.
	 *
	 * Handles both registered and guest customers from the wc_customer_lookup table.
	 * Uses get_customer_data() from integration as single source of truth for customer data extraction.
	 *
	 * @since 1.2.0
	 *
	 * @param int $wc_customer_id WooCommerce customer_id from wc_customer_lookup table.
	 * @return array|false Contact data or false.
	 */
	private function prepare_customer_from_lookup( $wc_customer_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$customer_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wc_customer_lookup WHERE customer_id = %d",
				$wc_customer_id
			),
			ARRAY_A
		);

		if ( ! $customer_row || ! is_array( $customer_row ) || empty( $customer_row['email'] ) ) {
			return false;
		}

		$user_id  = isset( $customer_row['user_id'] ) ? (int) $customer_row['user_id'] : 0;
		$is_guest = ( 0 === $user_id );

		// For registered users, use get_customer_data() from integration.
		if ( ! $is_guest && $user_id > 0 ) {
			$customer_data = $this->integration->get_customer_data( $user_id );
		} else {
			// For guest customers, get their most recent order and use get_customer_data with it.
			$orders = wc_get_orders(
				array(
					'billing_email' => $customer_row['email'],
					'customer_id'   => 0,
					'limit'         => 1,
					'orderby'       => 'date',
					'order'         => 'DESC',
				)
			);

			if ( is_array( $orders ) && ! empty( $orders ) && $orders[0] instanceof \WC_Order ) {
				$customer_data = $this->integration->get_customer_data( 0, $orders[0] );
			} else {
				// Fallback: build minimal data from wc_customer_lookup if no orders found.
				$customer_data = array(
					'user_role'          => 'customer',
					'user_id'            => 0,
					'is_guest'           => true,
					'billing_email'      => $customer_row['email'],
					'billing_first_name' => $customer_row['first_name'] ?? '',
					'billing_last_name'  => $customer_row['last_name'] ?? '',
					'billing_city'       => $customer_row['city'] ?? '',
					'billing_state'      => $customer_row['state'] ?? '',
					'billing_postcode'   => $customer_row['postcode'] ?? '',
					'billing_country'    => $customer_row['country'] ?? '',
				);
			}
		}

		// Check for email - registered users may have user_email instead of billing_email.
		$has_email = ! empty( $customer_data['billing_email'] ) || ! empty( $customer_data['user_email'] );
		if ( empty( $customer_data ) || ! $has_email ) {
			return false;
		}

		// Normalize to CRM format.
		$mapped_data = $this->integration->normalize_data( $customer_data );

		// Validate that email got mapped - skip if not.
		if ( empty( $mapped_data['primary_fields']['email'] ) ) {
			return false;
		}

		return $mapped_data;
	}

	/**
	 * Add lists and tags from integration settings to bulk sync payload.
	 *
	 * @since 1.2.0
	 *
	 * @param array $payload Base payload.
	 * @return array Modified payload.
	 */
	public function add_bulk_sync_lists_tags( $payload ) {
		// Get lists and tags from WooCommerce integration settings.
		$add_lists = $this->integration->get_setting( 'add_lists', array() );
		$add_tags  = $this->integration->get_setting( 'add_tags', array() );

		// Extract UUIDs (already cleaned by extract_uuids).
		$list_uuids = $this->integration->extract_uuids( $add_lists );
		$tag_uuids  = $this->integration->extract_uuids( $add_tags );

		if ( ! empty( $list_uuids ) ) {
			$payload['list_uuids'] = $list_uuids;
		}

		if ( ! empty( $tag_uuids ) ) {
			$payload['tag_uuids'] = $tag_uuids;
		}

		return $payload;
	}
}
