<?php
/**
 * SureCart Order Sync
 *
 * Handles bulk synchronization of SureCart orders to SureContact.
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
 * Class SureCart_Order_Sync
 *
 * Manages bulk order synchronization for the SureCart integration.
 *
 * @since 1.2.0
 */
class SureCart_Order_Sync {

	/**
	 * SureCart Integration instance.
	 *
	 * @since 1.2.0
	 *
	 * @var SureCart_Integration
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
	 * @param SureCart_Integration $integration    Parent integration instance.
	 * @param Ecommerce_API        $ecommerce_api  Ecommerce API instance.
	 */
	public function __construct( SureCart_Integration $integration, Ecommerce_API $ecommerce_api ) {
		$this->integration   = $integration;
		$this->ecommerce_api = $ecommerce_api;
		$this->batch_size    = Bulk_Sync_Service::ORDER_BATCH_SIZE;

		// Register with Bulk_Sync_Service for routing.
		Bulk_Sync_Service::register_sync_handler( 'surecart_orders', $this );
	}

	/**
	 * Get available sync types for SureCart orders.
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of sync type definitions.
	 */
	public function get_sync_types() {
		return array(
			array(
				'type'        => 'surecart_orders',
				'title'       => __( 'Orders', 'surecontact' ),
				'description' => __( 'Synchronize all SureCart orders and customer data to SureContact', 'surecontact' ),
			),
		);
	}

	/**
	 * Handle bulk sync for SureCart orders.
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
	 * @param string $sync_type   Sync type identifier (e.g. 'surecart_orders').
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
	 * Process a batch of SureCart orders for bulk sync.
	 *
	 * Fetches a batch of orders from SureCart, then processes each order
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
			Logger::info( 'SureCart Order Sync', "Job {$job_id} is cancelled, bypassing execution" );
			Bulk_Sync_Service::safe_update_job( $job_id, array( 'status' => 'cancelled' ) );
			return;
		}

		$job_data = get_option( "surecontact_job_{$job_id}" );

		if ( ! $job_data ) {
			Logger::error( 'SureCart Order Sync', "Job {$job_id} not found" );
			return;
		}

		// Check if job has been cancelled or completed.
		if ( in_array( $job_data['status'], array( 'completed', 'cancelled' ), true ) ) {
			Logger::info( 'SureCart Order Sync', "Job {$job_id} already {$job_data['status']}" );
			return;
		}

		$current_offset = (int) $job_data['current_offset'];
		$batch_size     = (int) $job_data['batch_size'];
		$total_count    = (int) $job_data['total_users'];

		// Calculate the page number and skip count within the page.
		$current_page = (int) floor( $current_offset / $batch_size ) + 1;
		$skip_in_page = $current_offset % $batch_size;

		// Fetch order items (checkout + status) for this page.
		$order_items = $this->get_checkouts_page( $current_page, $batch_size );

		if ( empty( $order_items ) ) {
			Bulk_Sync_Service::safe_update_job(
				$job_id,
				array(
					'status'       => 'completed',
					'completed_at' => current_time( 'mysql' ),
				)
			);
			Logger::info( 'SureCart Order Sync', "Sync job {$job_id}: No more orders found, marking completed." );
			return;
		}

		// Skip already-processed items within this page (resume scenario).
		if ( $skip_in_page > 0 ) {
			$order_items = array_slice( $order_items, $skip_in_page );
		}

		// Process each order individually.
		$synced = (int) ( $job_data['synced'] ?? 0 );
		$failed = (int) ( $job_data['failed'] ?? 0 );

		$rate_limited = false;

		// Check cancellation once before processing.
		$cancelled_jobs = get_option( 'surecontact_sync_job_cancelled', array() );
		if ( is_array( $cancelled_jobs ) && in_array( $job_id, $cancelled_jobs, true ) ) {
			Logger::info( 'SureCart Order Sync', "Job {$job_id} cancelled mid-batch at offset {$current_offset}" );
			Bulk_Sync_Service::safe_update_job( $job_id, array( 'status' => 'cancelled' ) );
			return;
		}

		// Order statuses that should not be tracked (no payment was made).
		$skip_statuses = array( 'draft', 'payment_failed' );

		foreach ( $order_items as $order_item ) {
			$checkout     = $order_item['checkout'];
			$order_status = $order_item['status'];

			// Skip orders that never had a successful payment.
			if ( in_array( $order_status, $skip_statuses, true ) ) {
				++$current_offset;
				continue;
			}

			// Step 1: Track the purchase (creates the order in SaaS).
			$result = $this->track_order( $checkout );

			if ( 'rate_limited' === $result ) {
				$rate_limited = true;
				Logger::info(
					'SureCart Order Sync',
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
			$status_result = $this->process_order_status( $checkout, $order_status );

			if ( 'rate_limited' === $status_result ) {
				++$synced;
				++$current_offset;
				$rate_limited = true;
				Logger::info(
					'SureCart Order Sync',
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
				'SureCart Order Sync',
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
		unset( $order_items );

		// Schedule next batch.
		$job_data_check = get_option( "surecontact_job_{$job_id}" );
		if ( $job_data_check && isset( $job_data_check['status'] ) && 'cancelled' === $job_data_check['status'] ) {
			Logger::info( 'SureCart Order Sync', "Job {$job_id} was cancelled, not scheduling next batch" );
			return;
		}

		if ( $rate_limited ) {
			as_schedule_single_action(
				time() + 60,
				Bulk_Sync_Service::BATCH_HOOK,
				array( 'job_id' => $job_id ),
				'surecontact'
			);
			Logger::info( 'SureCart Order Sync', "Job {$job_id}: next batch scheduled with 60s delay due to rate limit." );
		} else {
			as_enqueue_async_action(
				Bulk_Sync_Service::BATCH_HOOK,
				array( 'job_id' => $job_id ),
				'surecontact'
			);
		}
	}

	/**
	 * Get total number of SureCart orders.
	 *
	 * @since 1.2.0
	 *
	 * @return int Total order count.
	 */
	public function get_total_order_count() {
		if ( ! class_exists( 'SureCart\\Models\\Order' ) ) {
			return 0;
		}

		try {
			$orders = \SureCart\Models\Order::paginate(
				array(
					'per_page' => 1,
					'page'     => 1,
				)
			);

			if ( is_wp_error( $orders ) ) {
				Logger::error( 'SureCart Order Sync', 'Failed to count orders: ' . $orders->get_error_message() );
				return 0;
			}

			if ( is_object( $orders ) && method_exists( $orders, 'total' ) ) {
				return (int) $orders->total();
			}

			return 0;
		} catch ( \Exception $e ) {
			Logger::error( 'SureCart Order Sync', 'Failed to count orders: ' . $e->getMessage() );
			return 0;
		}
	}

	/**
	 * Get a page of SureCart orders with checkout relationships and status info.
	 *
	 * @since 1.2.0
	 *
	 * @param int $page     Page number (1-indexed).
	 * @param int $per_page Number of items per page.
	 * @return array Array of order items with 'checkout' and 'status' keys.
	 */
	public function get_checkouts_page( $page, $per_page ) {
		if ( ! class_exists( 'SureCart\\Models\\Order' ) ) {
			return array();
		}

		try {
			$orders = \SureCart\Models\Order::with(
				array(
					'checkout',
					'checkout.purchases',
					'checkout.purchases.product',
					'checkout.purchases.price',
					'checkout.customer',
					'checkout.customer.shipping_address',
					'checkout.discount',
					'checkout.discount.promotion',
					'checkout.discount.promotion.coupon',
					'checkout.charge',
				)
			)->paginate(
				array(
					'per_page' => $per_page,
					'page'     => $page,
				)
			);

			if ( is_wp_error( $orders ) ) {
				Logger::error( 'SureCart Order Sync', 'Failed to fetch orders page ' . $page . ': ' . $orders->get_error_message() );
				return array();
			}

			$orders_data = is_object( $orders ) ? $orders->data : null;
			$orders_list = ! empty( $orders_data ) && is_array( $orders_data ) ? $orders_data : array();

			$order_items = array();
			foreach ( $orders_list as $order ) {
				$checkout = is_object( $order ) ? ( $order->checkout ?? null ) : ( $order['checkout'] ?? null );
				if ( $checkout ) {
					$status        = is_object( $order ) ? ( $order->status ?? 'paid' ) : ( $order['status'] ?? 'paid' );
					$order_items[] = array(
						'checkout' => $checkout,
						'status'   => $status,
					);
				}
			}

			return $order_items;
		} catch ( \Exception $e ) {
			Logger::error( 'SureCart Order Sync', 'Failed to fetch orders page ' . $page . ': ' . $e->getMessage() );
			return array();
		}
	}

	/**
	 * Process order status (cancel/refund) for a single order during bulk sync.
	 *
	 * @since 1.2.0
	 *
	 * @param object $checkout     SureCart checkout object with relationships.
	 * @param string $order_status SureCart order status (paid, void, processing, etc.).
	 * @return bool|string True on success (or no action needed), false on failure, 'rate_limited' on 429.
	 */
	private function process_order_status( $checkout, $order_status ) {
		$is_cancelled = 'void' === $order_status;
		$charge       = $checkout->charge ?? null;
		$is_refunded  = $charge && ! empty( $charge->fully_refunded );

		if ( ! $is_cancelled && ! $is_refunded ) {
			return true;
		}

		if ( $is_cancelled ) {
			$cancel_result = $this->cancel_order( $checkout );

			if ( 'rate_limited' === $cancel_result ) {
				return 'rate_limited';
			}
		}

		if ( $is_refunded ) {
			$refund_result = $this->refund_order( $checkout );

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
	 * @param object $checkout SureCart checkout object with relationships.
	 * @return bool|string True on success, false on failure, 'rate_limited' on 429.
	 */
	private function cancel_order( $checkout ) {
		$checkout_id = $checkout->id ?? '';
		if ( empty( $checkout_id ) ) {
			return false;
		}

		$order_id_raw = $checkout->order ?? $checkout_id;
		$order_id     = $this->integration->generate_unique_order_id( $order_id_raw, 'SUR' );

		$cancelled_at           = $checkout->updated_at ?? time();
		$cancel_timestamp       = is_numeric( $cancelled_at ) ? (int) $cancelled_at : strtotime( (string) $cancelled_at );
		$cancelled_at_formatted = gmdate( 'c', false !== $cancel_timestamp ? $cancel_timestamp : time() );

		$cancel_data = array(
			'order_id'     => $order_id,
			'reason'       => __( 'Order cancelled', 'surecontact' ),
			'cancelled_at' => $cancelled_at_formatted,
		);

		$result = $this->ecommerce_api->cancel_purchase(
			$cancel_data,
			array(
				'skip_queue' => true,
				'source'     => $this->integration->get_slug(),
			)
		);

		if ( $this->is_rate_limited( $result ) ) {
			Logger::info( 'SureCart Order Sync', "Rate limit hit on cancel for order {$order_id}, will retry with delay." );
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
	 * @param object $checkout SureCart checkout object with charge relationship.
	 * @return bool|string True on success, false on failure, 'rate_limited' on 429.
	 */
	private function refund_order( $checkout ) {
		$checkout_id = $checkout->id ?? '';
		if ( empty( $checkout_id ) ) {
			return false;
		}

		$order_id_raw = $checkout->order ?? $checkout_id;
		$order_id     = $this->integration->generate_unique_order_id( $order_id_raw, 'SUR' );

		// Get refund amount from the charge or fall back to checkout total.
		$charge        = $checkout->charge ?? null;
		$refund_amount = 0;

		if ( $charge ) {
			if ( isset( $charge->refunded_amount ) && $charge->refunded_amount > 0 ) {
				$refund_amount = (float) $charge->refunded_amount / 100;
			} elseif ( isset( $charge->amount ) ) {
				$refund_amount = (float) $charge->amount / 100;
			}
		}

		if ( $refund_amount <= 0 && isset( $checkout->total_amount ) ) {
			$refund_amount = (float) $checkout->total_amount / 100;
		}

		// Get refund timestamp.
		$refunded_at = null;
		if ( $charge && isset( $charge->updated_at ) ) {
			$refunded_at = $charge->updated_at;
		}
		if ( ! $refunded_at ) {
			$refunded_at = $checkout->updated_at ?? time();
		}
		$refund_timestamp      = is_numeric( $refunded_at ) ? (int) $refunded_at : strtotime( (string) $refunded_at );
		$refunded_at_formatted = gmdate( 'c', false !== $refund_timestamp ? $refund_timestamp : time() );

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
			Logger::info( 'SureCart Order Sync', "Rate limit hit on refund for order {$order_id}, will retry with delay." );
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
	 * Extracts order data from the checkout and sends it to the SaaS API.
	 * Handles rate limiting and duplicate purchase detection.
	 *
	 * @since 1.2.0
	 *
	 * @param object $checkout SureCart checkout object with relationships.
	 * @return bool|string True on success, false on failure, 'rate_limited' on 429.
	 */
	private function track_order( $checkout ) {
		$order_data = $this->integration->extract_order_data_from_checkout( $checkout );
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
			Logger::info( 'SureCart Order Sync', "Rate limit hit on track for order {$order_data['order_id']}, will retry with delay." );
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
