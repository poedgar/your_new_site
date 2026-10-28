<?php
/**
 * Ecommerce API Client
 *
 * Handles ecommerce-specific operations including order tracking,
 * revenue metrics, and purchase events using the SaaS Client.
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact\API;

use SureContact\SaaS_Client;
use SureContact\Traits\API_Retry;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ecommerce_API
 *
 * Handles all ecommerce-related API operations with the external SaaS API.
 * Provides semantic methods for order tracking, revenue metrics, and purchase events.
 *
 * @since 0.0.1
 */
class Ecommerce_API {

	use API_Retry;

	/**
	 * SaaS Client instance
	 *
	 * @since 0.0.1
	 *
	 * @var SaaS_Client
	 */
	private $saas_client;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 *
	 * @param SaaS_Client|null $saas_client Optional. SaaS client instance.
	 */
	public function __construct( ?SaaS_Client $saas_client = null ) {
		$this->saas_client = $saas_client ? $saas_client : new SaaS_Client();
	}

	/**
	 * Track a purchase
	 *
	 * Sends purchase data to CRM for revenue tracking and analytics.
	 * Uses standardized format across all integrations.
	 *
	 * @since 0.0.1
	 *
	 * @param array $purchase_data {
	 *     Purchase information in standardized format.
	 *
	 *     @type string $contact_email   Contact email (required).
	 *     @type string $order_id        External order ID (required).
	 *     @type float  $total_amount    Total purchase amount (required).
	 *     @type string $currency        Currency code (required).
	 *     @type array  $products        Array of products (required).
	 *     @type string $coupon_code     Coupon code used (optional).
	 *     @type float  $shipping_amount Shipping amount (optional).
	 *     @type string $purchased_at    Purchase date in ISO 8601 format (required).
	 * }
	 * @param array $options Optional. Options passed to execute_with_retry.
	 *     @type bool $skip_queue When true, errors are returned directly instead of queueing.
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function track_purchase( $purchase_data, $options = array() ) {
		// Validate required fields.
		if ( empty( $purchase_data['contact_email'] ) ) {
			return new WP_Error(
				'missing_contact_email',
				__( 'Contact email is required to track a purchase.', 'surecontact' )
			);
		}

		if ( empty( $purchase_data['order_id'] ) ) {
			return new WP_Error(
				'missing_order_id',
				__( 'Order ID is required.', 'surecontact' )
			);
		}

		if ( ! isset( $purchase_data['total_amount'] ) ) {
			return new WP_Error(
				'missing_total_amount',
				__( 'Total amount is required.', 'surecontact' )
			);
		}

		if ( empty( $purchase_data['currency'] ) ) {
			return new WP_Error(
				'missing_currency',
				__( 'Currency is required.', 'surecontact' )
			);
		}

		if ( empty( $purchase_data['products'] ) || ! is_array( $purchase_data['products'] ) ) {
			return new WP_Error(
				'missing_products',
				__( 'Purchase must contain at least one product.', 'surecontact' )
			);
		}

		if ( empty( $purchase_data['purchased_at'] ) ) {
			return new WP_Error(
				'missing_purchased_at',
				__( 'Purchase date is required.', 'surecontact' )
			);
		}

		// Set defaults for optional fields.
		if ( ! isset( $purchase_data['coupon_code'] ) ) {
			$purchase_data['coupon_code'] = '';
		}

		if ( ! isset( $purchase_data['shipping_amount'] ) ) {
			$purchase_data['shipping_amount'] = 0.0;
		}

		// Note: workspace_uuid is automatically added by SaaS_Client
		// Send to CRM with retry logic (skip_queue option bypasses retry table).
		return $this->execute_with_retry(
			function () use ( $purchase_data ) {
				return $this->saas_client->post( 'wordpress/track-purchase', $purchase_data );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'wordpress/track-purchase',
				'payload'      => $purchase_data,
				'operation'    => 'track_purchase',
			),
			$options
		);
	}

	/**
	 * Cancel a purchase
	 *
	 * Cancels a previously tracked purchase.
	 *
	 * @since 0.0.1
	 *
	 * @param array $cancel_data {
	 *     Cancellation information in standardized format.
	 *
	 *     @type string $order_id      Order ID (required).
	 *     @type string $reason        Cancellation reason (optional).
	 *     @type string $cancelled_at  Cancellation date in ISO 8601 format (default: now).
	 * }
	 * @param array $options Optional. Options passed to execute_with_retry.
	 *     @type bool $skip_queue When true, errors are returned directly instead of queueing.
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function cancel_purchase( $cancel_data, $options = array() ) {
		// Validate required fields.
		if ( empty( $cancel_data['order_id'] ) ) {
			return new WP_Error(
				'missing_order_id',
				__( 'Order ID is required.', 'surecontact' )
			);
		}

		// Set defaults.
		if ( empty( $cancel_data['cancelled_at'] ) ) {
			$cancel_data['cancelled_at'] = gmdate( 'c' ); // ISO 8601 format.
		}

		if ( ! isset( $cancel_data['reason'] ) ) {
			$cancel_data['reason'] = '';
		}

		// Note: workspace_uuid is automatically added by SaaS_Client
		// Send to CRM with retry logic (skip_queue option bypasses retry table).
		return $this->execute_with_retry(
			function () use ( $cancel_data ) {
				return $this->saas_client->post( 'wordpress/cancel-purchase', $cancel_data );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'wordpress/cancel-purchase',
				'payload'      => $cancel_data,
				'operation'    => 'cancel_purchase',
			),
			$options
		);
	}

	/**
	 * Refund a purchase
	 *
	 * Records a refund for a previously tracked purchase.
	 *
	 * @since 0.0.1
	 *
	 * @param array $refund_data {
	 *     Refund information in standardized format.
	 *
	 *     @type string $order_id      Order ID (required).
	 *     @type string $reason        Refund reason (optional).
	 *     @type float  $refund_amount Amount refunded (required).
	 *     @type string $refunded_at   Refund date in ISO 8601 format (default: now).
	 * }
	 * @param array $options Optional. Options passed to execute_with_retry.
	 *     @type bool $skip_queue When true, errors are returned directly instead of queueing.
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function refund_purchase( $refund_data, $options = array() ) {
		// Validate required fields.
		if ( empty( $refund_data['order_id'] ) ) {
			return new WP_Error(
				'missing_order_id',
				__( 'Order ID is required.', 'surecontact' )
			);
		}

		if ( ! isset( $refund_data['refund_amount'] ) ) {
			return new WP_Error(
				'missing_refund_amount',
				__( 'Refund amount is required.', 'surecontact' )
			);
		}

		// Set defaults.
		if ( empty( $refund_data['refunded_at'] ) ) {
			$refund_data['refunded_at'] = gmdate( 'c' ); // ISO 8601 format.
		}

		if ( ! isset( $refund_data['reason'] ) ) {
			$refund_data['reason'] = '';
		}

		// Note: workspace_uuid is automatically added by SaaS_Client
		// Send to CRM with retry logic (skip_queue option bypasses retry table).
		return $this->execute_with_retry(
			function () use ( $refund_data ) {
				return $this->saas_client->post( 'wordpress/refund-purchase', $refund_data );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'wordpress/refund-purchase',
				'payload'      => $refund_data,
				'operation'    => 'refund_purchase',
			),
			$options
		);
	}
}
