<?php
/**
 * WooCommerce Order Sync
 *
 * Handles bulk synchronization of WooCommerce orders to SureContact.
 * Each sync type gets its own file to keep complexity manageable.
 *
 * @since 1.2.0
 *
 * @package SureContact
 */

namespace SureContact\Integrations;

use SureContact\Logger;
use SureContact\Bulk_Sync_Service;
use SureContact\API\Ecommerce_API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooCommerce_Order_Sync
 *
 * Manages bulk order synchronization for the WooCommerce integration.
 *
 * @since 1.2.0
 */
class WooCommerce_Order_Sync {

	/**
	 * WooCommerce Integration instance.
	 *
	 * @since 1.2.0
	 *
	 * @var WooCommerce_Integration
	 */
	private $integration;

	/**
	 * Ecommerce API instance.
	 *
	 * @since 1.2.0
	 *
	 * @var Ecommerce_API
	 */
	private $ecommerce_api;

	/**
	 * Batch size for order processing.
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
	 * @param WooCommerce_Integration $integration    Parent integration instance.
	 * @param Ecommerce_API           $ecommerce_api  Ecommerce API instance.
	 */
	public function __construct( WooCommerce_Integration $integration, Ecommerce_API $ecommerce_api ) {
		$this->integration   = $integration;
		$this->ecommerce_api = $ecommerce_api;
		$this->batch_size    = Bulk_Sync_Service::ORDER_BATCH_SIZE;

		// Register with Bulk_Sync_Service for routing.
		Bulk_Sync_Service::register_sync_handler( 'woocommerce_orders', $this );
	}

	/**
	 * Get available sync types for WooCommerce orders.
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of sync type definitions.
	 */
	public function get_sync_types() {
		return array(
			array(
				'type'        => 'woocommerce_orders',
				'title'       => __( 'Orders', 'surecontact' ),
				'description' => __( 'Synchronize all WooCommerce orders and revenue data to SureContact', 'surecontact' ),
			),
		);
	}

	/**
	 * Handle bulk sync for WooCommerce orders.
	 *
	 * @since 1.2.0
	 *
	 * @param string $sync_type Sync type identifier.
	 * @return array Results with job information.
	 */
	public function handle_sync( $sync_type ) {
		$total_count = $this->get_total_order_count();

		if ( 0 === $total_count ) {
			return array(
				'success' => true,
				'message' => __( 'No orders found to sync', 'surecontact' ),
			);
		}

		return $this->start_bulk_sync( $total_count, $sync_type );
	}

	/**
	 * Start the order bulk sync job.
	 *
	 * Creates job metadata and schedules the first batch via Action Scheduler.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $total_count Total number of orders to sync.
	 * @param string $sync_type   Sync type identifier (e.g. 'woocommerce_orders').
	 * @return array Job response data.
	 */
	public function start_bulk_sync( $total_count, $sync_type = '' ) {
		$job_id        = uniqid( 'sync_job_', true );
		$batch_size    = $this->batch_size;
		$total_batches = (int) ceil( $total_count / $batch_size );

		$job_data = array(
			'job_id'            => $job_id,
			'total_users'       => $total_count,
			'total_batches'     => $total_batches,
			'processed_batches' => 0,
			'current_offset'    => 0,
			'batch_size'        => $batch_size,
			'synced'            => 0,
			'failed'            => 0,
			'status'            => 'processing',
			'created_at'        => current_time( 'mysql' ),
			'type'              => $this->integration->get_slug(),
			'sync_type'         => $sync_type,
			'self_tracking'     => true,
		);

		update_option( "surecontact_job_{$job_id}", $job_data );

		// Schedule first batch via Action Scheduler.
		as_enqueue_async_action( Bulk_Sync_Service::BATCH_HOOK, array( 'job_id' => $job_id ), 'surecontact' );

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
	 * Process a batch of WooCommerce orders for bulk sync.
	 *
	 * Fetches a batch of orders from WooCommerce, then processes each order
	 * individually via track_purchase API. Saves offset after each order
	 * so the job can resume from the exact point if interrupted.
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
			Logger::info( 'WooCommerce Order Sync', "Job {$job_id} is cancelled, bypassing execution" );
			Bulk_Sync_Service::safe_update_job( $job_id, array( 'status' => 'cancelled' ) );
			return;
		}

		$job_data = get_option( "surecontact_job_{$job_id}" );

		if ( ! $job_data ) {
			Logger::error( 'WooCommerce Order Sync', "Job {$job_id} not found" );
			return;
		}

		// Check if job has been cancelled or completed.
		if ( in_array( $job_data['status'], array( 'completed', 'cancelled' ), true ) ) {
			Logger::info( 'WooCommerce Order Sync', "Job {$job_id} already {$job_data['status']}" );
			return;
		}

		$current_offset = (int) $job_data['current_offset'];
		$batch_size     = (int) $job_data['batch_size'];
		$total_count    = (int) $job_data['total_users'];

		// Calculate the page number and skip count within the page.
		$current_page = (int) floor( $current_offset / $batch_size ) + 1;
		$skip_in_page = $current_offset % $batch_size;

		// Fetch orders for this page.
		$orders = $this->get_orders_page( $current_page, $batch_size );

		if ( empty( $orders ) ) {
			Bulk_Sync_Service::safe_update_job(
				$job_id,
				array(
					'status'       => 'completed',
					'completed_at' => current_time( 'mysql' ),
				)
			);
			Logger::info( 'WooCommerce Order Sync', "Sync job {$job_id}: No more orders found, marking completed." );
			return;
		}

		// Skip already-processed items within this page (resume scenario).
		if ( $skip_in_page > 0 ) {
			$orders = array_slice( $orders, $skip_in_page );
		}

		// Process each order individually.
		$synced = (int) ( $job_data['synced'] ?? 0 );
		$failed = (int) ( $job_data['failed'] ?? 0 );

		$rate_limited = false;

		// Check cancellation once before processing.
		$cancelled_jobs = get_option( 'surecontact_sync_job_cancelled', array() );
		if ( is_array( $cancelled_jobs ) && in_array( $job_id, $cancelled_jobs, true ) ) {
			Logger::info( 'WooCommerce Order Sync', "Job {$job_id} cancelled mid-batch at offset {$current_offset}" );
			Bulk_Sync_Service::safe_update_job( $job_id, array( 'status' => 'cancelled' ) );
			return;
		}

		// WooCommerce order statuses that should not be tracked (no payment was made).
		$skip_statuses = array( 'pending', 'failed', 'checkout-draft' );

		foreach ( $orders as $order ) {
			$order_status = $order->get_status();

			// Skip orders that never had a successful payment.
			if ( in_array( $order_status, $skip_statuses, true ) ) {
				++$current_offset;
				continue;
			}

			// Step 1: Track the purchase (creates the order in SaaS).
			$result = $this->track_order( $order );

			if ( 'rate_limited' === $result ) {
				$rate_limited = true;
				Logger::info(
					'WooCommerce Order Sync',
					sprintf( 'Rate limit hit at offset %d (track_purchase), scheduling retry in 60 seconds.', $current_offset )
				);
				break;
			}

			if ( true !== $result ) {
				++$failed;
				++$current_offset;

				Bulk_Sync_Service::safe_update_job(
					$job_id,
					array(
						'current_offset'    => $current_offset,
						'synced'            => $synced,
						'failed'            => $failed,
						'processed_batches' => (int) floor( $current_offset / $batch_size ),
					)
				);
				continue;
			}

			// Step 2: Handle order status — cancel or refund if applicable.
			$status_result = $this->process_order_status( $order );

			if ( 'rate_limited' === $status_result ) {
				++$synced;
				++$current_offset;
				$rate_limited = true;
				Logger::info(
					'WooCommerce Order Sync',
					sprintf( 'Rate limit hit at offset %d (status update), scheduling retry in 60 seconds.', $current_offset )
				);
				break;
			}

			++$synced;
			++$current_offset;

			// Save progress after each order for live updates.
			Bulk_Sync_Service::safe_update_job(
				$job_id,
				array(
					'current_offset'    => $current_offset,
					'synced'            => $synced,
					'failed'            => $failed,
					'processed_batches' => (int) floor( $current_offset / $batch_size ),
				)
			);
		}

		// Save final progress for the batch.
		Bulk_Sync_Service::safe_update_job(
			$job_id,
			array(
				'current_offset'    => $current_offset,
				'synced'            => $synced,
				'failed'            => $failed,
				'processed_batches' => (int) floor( $current_offset / $batch_size ),
			)
		);

		// Check if all orders are processed.
		if ( ! $rate_limited && $current_offset >= $total_count ) {
			Bulk_Sync_Service::safe_update_job(
				$job_id,
				array(
					'status'            => 'completed',
					'completed_at'      => current_time( 'mysql' ),
					'processed_batches' => $job_data['total_batches'],
				)
			);
			Logger::info(
				'WooCommerce Order Sync',
				sprintf(
					'Sync job %s completed: %d synced, %d failed out of %d total orders.',
					$job_id,
					$synced,
					$failed,
					$total_count
				)
			);
			return;
		}

		// Free memory.
		unset( $orders );

		// Schedule next batch.
		$job_data_check = get_option( "surecontact_job_{$job_id}" );
		if ( $job_data_check && isset( $job_data_check['status'] ) && 'cancelled' === $job_data_check['status'] ) {
			Logger::info( 'WooCommerce Order Sync', "Job {$job_id} was cancelled, not scheduling next batch" );
			return;
		}

		if ( $rate_limited ) {
			as_schedule_single_action(
				time() + 60,
				Bulk_Sync_Service::BATCH_HOOK,
				array( 'job_id' => $job_id ),
				'surecontact'
			);
			Logger::info( 'WooCommerce Order Sync', "Job {$job_id}: next batch scheduled with 60s delay due to rate limit." );
		} else {
			as_enqueue_async_action(
				Bulk_Sync_Service::BATCH_HOOK,
				array( 'job_id' => $job_id ),
				'surecontact'
			);
		}
	}

	/**
	 * Get order statuses to sync (paid/completed transactions only).
	 *
	 * @since 1.2.0
	 *
	 * @return array Status slugs without 'wc-' prefix.
	 */
	private function get_syncable_statuses() {
		return array( 'completed', 'processing', 'refunded', 'cancelled' );
	}

	/**
	 * Get total number of WooCommerce orders to sync.
	 *
	 * @since 1.2.0
	 *
	 * @return int Total order count.
	 */
	public function get_total_order_count() {
		$args = array(
			'status'   => $this->get_syncable_statuses(),
			'type'     => 'shop_order',
			'limit'    => 1,
			'return'   => 'ids',
			'paginate' => true,
		);

		$result = wc_get_orders( $args );

		return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * Get a page of WooCommerce orders.
	 *
	 * @since 1.2.0
	 *
	 * @param int $page     Page number (1-indexed).
	 * @param int $per_page Orders per page.
	 * @return array Array of WC_Order objects.
	 */
	public function get_orders_page( $page, $per_page ) {
		$args = array(
			'status'  => $this->get_syncable_statuses(),
			'type'    => 'shop_order',
			'limit'   => $per_page,
			'page'    => $page,
			'orderby' => 'ID',
			'order'   => 'ASC',
		);

		$orders = wc_get_orders( $args );

		if ( ! is_array( $orders ) ) {
			return array();
		}

		return $orders;
	}

	/**
	 * Process order status (cancel/refund) for a single order during bulk sync.
	 *
	 * @since 1.2.0
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return bool|string True on success (or no action needed), false on failure, 'rate_limited' on 429.
	 */
	private function process_order_status( $order ) {
		$order_status = $order->get_status();
		$is_cancelled = 'cancelled' === $order_status;
		$is_refunded  = 'refunded' === $order_status;

		if ( ! $is_cancelled && ! $is_refunded ) {
			return true;
		}

		if ( $is_cancelled ) {
			$cancel_result = $this->cancel_order( $order );

			if ( 'rate_limited' === $cancel_result ) {
				return 'rate_limited';
			}
		}

		if ( $is_refunded ) {
			$refund_result = $this->refund_order( $order );

			if ( 'rate_limited' === $refund_result ) {
				return 'rate_limited';
			}
		}

		return true;
	}

	/**
	 * Cancel a single order during bulk sync.
	 *
	 * @since 1.2.0
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return bool|string True on success, false on failure, 'rate_limited' on 429.
	 */
	private function cancel_order( $order ) {
		$order_id = $this->integration->generate_unique_order_id( $order->get_order_number(), 'WOO' );

		$date_modified = $order->get_date_modified();
		$cancelled_at  = $date_modified ? $date_modified->date( 'c' ) : gmdate( 'c' );

		$cancel_data = array(
			'order_id'     => $order_id,
			'reason'       => __( 'Order cancelled', 'surecontact' ),
			'cancelled_at' => $cancelled_at,
		);

		$result = $this->ecommerce_api->cancel_purchase(
			$cancel_data,
			array(
				'skip_queue' => true,
				'source'     => $this->integration->get_slug(),
			)
		);

		if ( $this->is_rate_limited( $result ) ) {
			Logger::info( 'WooCommerce Order Sync', "Rate limit hit on cancel for order {$order_id}, will retry with delay." );
			return 'rate_limited';
		}

		if ( is_wp_error( $result ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Refund a single order during bulk sync.
	 *
	 * @since 1.2.0
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return bool|string True on success, false on failure, 'rate_limited' on 429.
	 */
	private function refund_order( $order ) {
		$order_id = $this->integration->generate_unique_order_id( $order->get_order_number(), 'WOO' );

		// Get total refund amount from all refunds.
		$refunds       = $order->get_refunds();
		$refund_amount = 0;
		$refunded_at   = null;

		foreach ( $refunds as $refund ) {
			$refund_amount += (float) abs( $refund->get_amount() );
			// Use the most recent refund date.
			$refund_date = $refund->get_date_created();
			if ( $refund_date && ( ! $refunded_at || $refund_date > $refunded_at ) ) {
				$refunded_at = $refund_date;
			}
		}

		// If no refunds found, use order total and modified date.
		if ( $refund_amount <= 0 ) {
			$refund_amount = (float) $order->get_total();
		}

		$refunded_at_formatted = $refunded_at ? $refunded_at->date( 'c' ) : gmdate( 'c' );

		$refund_data = array(
			'order_id'      => $order_id,
			'reason'        => __( 'Order refunded', 'surecontact' ),
			'refund_amount' => $refund_amount,
			'refunded_at'   => $refunded_at_formatted,
		);

		$result = $this->ecommerce_api->refund_purchase(
			$refund_data,
			array(
				'skip_queue' => true,
				'source'     => $this->integration->get_slug(),
			)
		);

		if ( $this->is_rate_limited( $result ) ) {
			Logger::info( 'WooCommerce Order Sync', "Rate limit hit on refund for order {$order_id}, will retry with delay." );
			return 'rate_limited';
		}

		if ( is_wp_error( $result ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Track a single order during bulk sync.
	 *
	 * Extracts order data from the WC_Order and sends it to the SaaS API.
	 * Handles rate limiting and duplicate purchase detection.
	 *
	 * @since 1.2.0
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return bool|string True on success, false on failure, 'rate_limited' on 429.
	 */
	private function track_order( $order ) {
		$order_data = $this->integration->prepare_order_data( $order );
		if ( ! $order_data ) {
			return false;
		}

		$result = $this->ecommerce_api->track_purchase(
			$order_data,
			array(
				'skip_queue' => true,
				'source'     => $this->integration->get_slug(),
			)
		);

		if ( $this->is_rate_limited( $result ) ) {
			Logger::info( 'WooCommerce Order Sync', "Rate limit hit on track for order {$order_data['order_id']}, will retry with delay." );
			return 'rate_limited';
		}

		if ( is_wp_error( $result ) ) {
			// DUPLICATE_PURCHASE means the order was already tracked — treat as success.
			$error_data = $result->get_error_data();
			$body       = isset( $error_data['body'] ) ? $error_data['body'] : '';
			if ( ! empty( $body ) && is_string( $body ) ) {
				$body_data = json_decode( $body, true );
				if ( is_array( $body_data ) && isset( $body_data['error_code'] ) && 'DUPLICATE_PURCHASE' === $body_data['error_code'] ) {
					return true;
				}
			}

			return false;
		}

		return true;
	}

	/**
	 * Check if an API result is a rate limit error.
	 *
	 * @since 1.2.0
	 *
	 * @param array|\WP_Error $result API result.
	 * @return bool True if rate limited.
	 */
	private function is_rate_limited( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}

		$error_data  = $result->get_error_data();
		$status_code = $error_data['code'] ?? 0;

		return 429 === $status_code || false !== strpos( strtolower( $result->get_error_message() ), 'rate limit' );
	}
}
