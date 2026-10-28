<?php
/**
 * LatePoint Integration
 *
 * Handles LatePoint customer contact information synchronization and booking/order tracking
 *
 * @since 1.0.0
 *
 * @package SureContact
 */

namespace SureContact\Integrations;

use SureContact\Logger;
use SureContact\API\Ecommerce_API;
use SureContact\Traits\Integration_DB_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LatePoint_Integration
 *
 * Integrates LatePoint with SureContact for booking and order tracking
 *
 * @since 1.0.0
 */
class LatePoint_Integration extends Base_Integration {

	// Use the database helper trait for item-specific configurations.
	use Integration_DB_Helper;

	/**
	 * Ecommerce API instance
	 *
	 * @since 1.0.0
	 *
	 * @var Ecommerce_API
	 */
	private $ecommerce_api;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->slug        = 'latepoint';
		$this->name        = 'LatePoint';
		$this->description = __( 'Sync LatePoint customer contact information and track bookings, orders, and payments.', 'surecontact' );
		$this->docs_url    = '';
		$this->dependency  = 'LatePoint';

		parent::__construct();

		// Initialize Ecommerce API.
		$this->ecommerce_api = new Ecommerce_API();
	}

	/**
	 * Get additional plugin dependencies for LatePoint.
	 *
	 * LatePoint Pro is required for advanced booking features.
	 *
	 * @since 1.0.0
	 *
	 * @return array Keyed array of plugin_key => array( plugin_file, plugin_name, plugin_dependencies ).
	 */
	public function get_additional_plugins() {
		return array(
			'latepoint-pro' => array(
				'plugin_file'         => 'latepoint-pro-features/latepoint-pro-features.php',
				'plugin_name'         => __( 'LatePoint Pro', 'surecontact' ),
				'plugin_dependencies' => array( 'latepoint/latepoint.php' ),
			),
		);
	}

	/**
	 * Get item type to plugin requirement mapping for LatePoint.
	 *
	 * Only coupon rules require LatePoint Pro; service and location are free.
	 *
	 * @since 1.0.0
	 *
	 * @return array Map of item_type_key => plugin_key.
	 */
	public function get_item_type_plugin_requirements() {
		return array(
			'coupon' => 'latepoint-pro',
		);
	}

	/**
	 * Add LatePoint field groups
	 *
	 * @since 1.0.0
	 *
	 * @param array $groups Existing field groups.
	 * @return array Modified field groups.
	 */
	public function add_meta_field_group( $groups ) {
		$groups['latepoint'] = array(
			'title' => __( 'LatePoint', 'surecontact' ),
			'url'   => '',
		);

		return $groups;
	}

	/**
	 * Add LatePoint-specific fields
	 *
	 * @since 1.0.0
	 *
	 * @param array $fields Existing meta fields.
	 * @return array Modified meta fields.
	 */
	public function add_meta_fields( $fields ) {
		// LatePoint Customer fields.
		$latepoint_customer_fields = array(
			'lp_phone'       => array(
				'label' => __( 'Phone', 'surecontact' ),
				'type'  => 'text',
			),
			'lp_notes'       => array(
				'label' => __( 'Customer Notes', 'surecontact' ),
				'type'  => 'textarea',
			),
			'lp_admin_notes' => array(
				'label' => __( 'Admin Notes', 'surecontact' ),
				'type'  => 'textarea',
			),
		);

		// LatePoint Booking context fields.
		$latepoint_booking_fields = array(
			'lp_last_booking_code' => array(
				'label' => __( 'Last Booking Code', 'surecontact' ),
				'type'  => 'text',
			),
			'lp_last_service'      => array(
				'label' => __( 'Last Service', 'surecontact' ),
				'type'  => 'text',
			),
			'lp_last_agent'        => array(
				'label' => __( 'Last Agent', 'surecontact' ),
				'type'  => 'text',
			),
			'lp_last_location'     => array(
				'label' => __( 'Last Location', 'surecontact' ),
				'type'  => 'text',
			),
			'lp_last_booking_date' => array(
				'label' => __( 'Last Booking Date', 'surecontact' ),
				'type'  => 'date',
			),
			'lp_total_bookings'    => array(
				'label' => __( 'Total Bookings', 'surecontact' ),
				'type'  => 'number',
			),
		);

		// Add group to all customer fields.
		foreach ( $latepoint_customer_fields as $key => &$config ) {
			$config['group'] = 'latepoint';
			$fields[ $key ]  = $config;
		}
		unset( $config );

		// Add group to all booking fields.
		foreach ( $latepoint_booking_fields as $key => &$config ) {
			$config['group'] = 'latepoint';
			$fields[ $key ]  = $config;
		}
		unset( $config );

		// Add custom fields from LatePoint if available.
		$custom_fields = $this->get_latepoint_custom_fields();

		if ( ! empty( $custom_fields ) ) {
			foreach ( $custom_fields as $field_key => $field_label ) {
				$fields[ 'lp_' . $field_key ] = array(
					'label' => is_string( $field_label ) && ! empty( $field_label ) ? $field_label : ucwords( str_replace( '_', ' ', $field_key ) ),
					'type'  => 'text',
					'group' => 'latepoint',
				);
			}
		}

		return $fields;
	}

	/**
	 * Get LatePoint custom fields from customer meta
	 *
	 * @since 1.0.0
	 *
	 * @return array Custom fields.
	 */
	public function get_latepoint_custom_fields() {
		// Check cache first.
		$cache_key     = 'surecontact_latepoint_custom_fields';
		$custom_fields = get_transient( $cache_key );

		if ( false !== $custom_fields ) {
			return $custom_fields;
		}

		$custom_fields = array();

		// Check if LatePoint custom fields helper exists (external LatePoint classes).
		// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar -- PHPStan directive.
		// @phpstan-ignore function.impossibleType
		if ( class_exists( 'OsCustomFieldsHelper' ) && method_exists( 'OsCustomFieldsHelper', 'get_custom_fields_arr' ) ) {
			$lp_custom_fields = \OsCustomFieldsHelper::get_custom_fields_arr( 'customer' );

			if ( ! empty( $lp_custom_fields ) && is_array( $lp_custom_fields ) ) {
				foreach ( $lp_custom_fields as $field ) {
					$field_id    = isset( $field['id'] ) ? $field['id'] : '';
					$field_label = isset( $field['label'] ) ? $field['label'] : '';

					if ( ! empty( $field_id ) ) {
						$custom_fields[ $field_id ] = $field_label;
					}
				}
			}
		}

		// Cache for 1 hour.
		set_transient( $cache_key, $custom_fields, HOUR_IN_SECONDS );

		return $custom_fields;
	}

	/**
	 * Get integration-specific global settings fields
	 *
	 * Returns global plugin-level settings only.
	 * Product-specific settings are handled via get_item_config_fields().
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings fields configuration
	 */
	public function get_settings_fields() {
		$tracking_fields = array(
			'sync_customers'      => array(
				'label'       => __( 'Sync Customer Data', 'surecontact' ),
				'description' => __( 'Automatically sync LatePoint customers to SureContact when they are created or updated', 'surecontact' ),
				'type'        => 'checkbox',
				'default'     => true,
			),

			'track_bookings'      => array(
				'label'       => __( 'Track Bookings', 'surecontact' ),
				'description' => __( 'Track booking events (created, status changes) in SureContact', 'surecontact' ),
				'type'        => 'checkbox',
				'default'     => true,
			),

			'track_orders'        => array(
				'label'       => __( 'Track Order Data', 'surecontact' ),
				'description' => __( 'Send detailed order information to SureContact for revenue tracking and analytics', 'surecontact' ),
				'type'        => 'checkbox',
				'default'     => true,
			),

			'track_transactions'  => array(
				'label'       => __( 'Track Transactions', 'surecontact' ),
				'description' => __( 'Send payment transaction information to SureContact', 'surecontact' ),
				'type'        => 'checkbox',
				'default'     => true,
			),

			'track_cancellations' => array(
				'label'       => __( 'Track Cancellations', 'surecontact' ),
				'description' => __( 'Send cancellation information to SureContact when bookings are cancelled', 'surecontact' ),
				'type'        => 'checkbox',
				'default'     => true,
			),

			'track_refunds'       => array(
				'label'       => __( 'Track Refunds', 'surecontact' ),
				'description' => __( 'Send refund information to SureContact when transactions are refunded', 'surecontact' ),
				'type'        => 'checkbox',
				'default'     => true,
			),
		);

		return array_merge( $tracking_fields, self::get_standard_list_tag_fields() );
	}

	/**
	 * Get all available item types for LatePoint.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of item type definitions with 'key' and 'label' keys.
	 */
	public function get_item_types() {
		return array(
			array(
				'key'   => 'service',
				'label' => __( 'Service', 'surecontact' ),
			),
			array(
				'key'   => 'location',
				'label' => __( 'Location', 'surecontact' ),
			),
			array(
				'key'   => 'coupon',
				'label' => __( 'Coupon', 'surecontact' ),
			),
		);
	}

	/**
	 * Get available events for a specific item type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $item_type Item type (e.g., 'service', 'location', 'coupon').
	 * @return array Array of event definitions with 'key' and 'label' keys.
	 */
	public function get_events_by_item_type( $item_type ) {
		// Booking-related events for services and locations.
		$booking_events = array(
			array(
				'key'   => 'booking_created',
				'label' => __( 'Booking Created', 'surecontact' ),
			),
			array(
				'key'   => 'booking_approved',
				'label' => __( 'Booking Approved', 'surecontact' ),
			),
			array(
				'key'   => 'booking_pending',
				'label' => __( 'Booking Pending', 'surecontact' ),
			),
			array(
				'key'   => 'booking_cancelled',
				'label' => __( 'Booking Cancelled', 'surecontact' ),
			),
			array(
				'key'   => 'booking_completed',
				'label' => __( 'Booking Completed', 'surecontact' ),
			),
			array(
				'key'   => 'booking_no_show',
				'label' => __( 'Booking No Show', 'surecontact' ),
			),
		);

		switch ( $item_type ) {
			case 'service':
			case 'location':
				return $booking_events;

			case 'coupon':
				return array(
					array(
						'key'   => 'applied',
						'label' => __( 'Applied', 'surecontact' ),
					),
				);

			default:
				return array();
		}
	}

	/**
	 * Get item-specific configuration fields for a LatePoint item.
	 *
	 * Uses a common structure for all events with event-based nested data.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $item_id Item ID.
	 * @param string|null $event   Event name (not used - kept for compatibility).
	 * @return array Configuration fields schema.
	 */
	public function get_item_config_fields( $item_id, $event = null ) {
		// Return common configuration fields that work for all events.
		return self::get_standard_list_tag_fields();
	}

	/**
	 * Get LatePoint item fields.
	 *
	 * For LatePoint, we don't return actual form fields since services/locations
	 * don't have custom fields to map. The configuration fields are handled
	 * by the integration's get_item_config_fields() method.
	 *
	 * @since 1.0.0
	 *
	 * @param string $item_id Item ID (service or location ID) - unused but required for consistency.
	 * @return array Empty array (no mappable fields for services/locations).
	 */
	public function get_item_fields( $item_id ) {
		// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		// LatePoint services and locations don't have mappable fields.
		// All configuration is handled through config_fields from the integration.
		return array();
	}

	/**
	 * Initialize integration hooks
	 *
	 * Note: Hooks are registered unconditionally. Settings are checked inside the handlers.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function init() {
		// Customer lifecycle hooks.
		add_action( 'latepoint_customer_created', array( $this, 'handle_customer_created' ), 10, 1 );
		add_action( 'latepoint_customer_updated', array( $this, 'handle_customer_updated' ), 10, 2 );

		// Booking lifecycle hooks.
		add_action( 'latepoint_booking_created', array( $this, 'handle_booking_created' ), 10, 1 );
		add_action( 'latepoint_booking_updated', array( $this, 'handle_booking_updated' ), 10, 2 );
		add_action( 'latepoint_booking_change_status', array( $this, 'handle_booking_status_change' ), 10, 2 );

		// Order and payment hooks.
		add_action( 'latepoint_order_created', array( $this, 'handle_order_created' ), 10, 1 );
		add_action( 'latepoint_order_updated', array( $this, 'handle_order_updated' ), 10, 2 );
		add_action( 'latepoint_transaction_created', array( $this, 'handle_transaction_created' ), 10, 1 );

		// Refund hook - fires when a transaction refund is created.
		add_action( 'latepoint_transaction_refund_created', array( $this, 'handle_transaction_refund_created' ), 10, 1 );
	}

	/**
	 * Handle customer created
	 *
	 * Syncs customer data when a new customer is created in LatePoint
	 *
	 * @since 1.0.0
	 *
	 * @param object $customer Customer object.
	 * @return void
	 */
	public function handle_customer_created( $customer ) {
		// Check if customer sync is enabled.
		if ( ! $this->is_global_enabled() || ! $this->get_setting( 'sync_customers', true ) ) {
			return;
		}

		$this->sync_customer_to_crm( $customer, 'customer_created' );
	}

	/**
	 * Handle customer updated
	 *
	 * Syncs customer data when a customer is updated in LatePoint
	 *
	 * @since 1.0.0
	 *
	 * @param object $customer         Customer object.
	 * @param array  $old_customer_data Old customer data.
	 * @return void
	 */
	public function handle_customer_updated( $customer, $old_customer_data ) {
		// Prevent unused parameter warning.
		unset( $old_customer_data );

		// Check if customer sync is enabled.
		if ( ! $this->is_global_enabled() || ! $this->get_setting( 'sync_customers', true ) ) {
			return;
		}

		$this->sync_customer_to_crm( $customer, 'customer_updated' );
	}

	/**
	 * Handle booking created
	 *
	 * Tracks booking and applies configured actions when a new booking is created
	 *
	 * @since 1.0.0
	 *
	 * @param object $booking Booking object.
	 * @return void
	 */
	public function handle_booking_created( $booking ) {
		$this->process_booking_event( $booking, 'booking_created' );
	}

	/**
	 * Handle booking updated
	 *
	 * Tracks booking changes when a booking is updated
	 *
	 * @since 1.0.0
	 *
	 * @param object $booking     Current booking object.
	 * @param object $old_booking Old booking object.
	 * @return void
	 */
	public function handle_booking_updated( $booking, $old_booking ) {
		// Prevent unused parameter warning.
		unset( $old_booking );

		// Only process if booking tracking is enabled.
		if ( ! $this->get_setting( 'track_bookings', true ) ) {
			return;
		}

		// Log the update but don't apply actions (status changes are handled separately).
		// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar -- PHPStan directive.
		// @phpstan-ignore property.notFound
		Logger::info( 'LatePoint Integration', 'Booking updated: ' . $booking->id );
	}

	/**
	 * Handle booking status change
	 *
	 * Applies configured actions when booking status changes
	 *
	 * @since 1.0.0
	 *
	 * @param object $booking     Current booking object.
	 * @param object $old_booking Old booking object (for comparison).
	 * @return void
	 */
	public function handle_booking_status_change( $booking, $old_booking ) {
		// Map LatePoint status to our event names.
		$status       = $booking->status ?? '';
		$old_status   = $old_booking->status ?? '';
		$status_event = $this->map_status_to_event( $status );

		// Skip if status didn't actually change or no valid event.
		if ( $status === $old_status || empty( $status_event ) ) {
			return;
		}

		// Process the booking event (applies lists/tags).
		$this->process_booking_event( $booking, $status_event );

		// Track cancellation in ecommerce API if status changed to cancelled.
		if ( 'booking_cancelled' === $status_event ) {
			$this->track_booking_cancellation( $booking );
		}
	}

	/**
	 * Handle order created
	 *
	 * Tracks order data when a new order is created in LatePoint.
	 * Also applies coupon-specific settings if a coupon was used.
	 *
	 * @since 1.0.0
	 *
	 * @param object $order Order object.
	 * @return void
	 */
	public function handle_order_created( $order ) {
		// Apply coupon settings if a coupon was used (works independently of global integration).
		$coupon_code = $order->coupon_code ?? '';
		if ( ! empty( $coupon_code ) ) {
			$this->process_coupon_applied( $order, $coupon_code );
		}

		// Check if order tracking is enabled.
		if ( ! $this->is_global_enabled() || ! $this->get_setting( 'track_orders', true ) ) {
			return;
		}

		$this->track_order( $order );
	}

	/**
	 * Handle transaction created
	 *
	 * Tracks payment transaction when created
	 *
	 * @since 1.0.0
	 *
	 * @param object $transaction Transaction object.
	 * @return void
	 */
	public function handle_transaction_created( $transaction ) {
		// Check if transaction tracking is enabled.
		if ( ! $this->is_global_enabled() || ! $this->get_setting( 'track_transactions', true ) ) {
			return;
		}

		Logger::info(
			'LatePoint Integration',
			'Transaction created',
			array(
				'transaction_id' => $transaction->id ?? 'unknown',
				'amount'         => $transaction->amount ?? 0,
				'status'         => $transaction->status ?? 'unknown',
			)
		);
	}

	/**
	 * Handle transaction refund created
	 *
	 * Tracks refunds when a transaction refund is issued in LatePoint.
	 * Fires on 'latepoint_transaction_refund_created' hook.
	 *
	 * @since 1.0.0
	 *
	 * @param object $transaction_refund Transaction refund object (OsTransactionRefundModel).
	 * @return void
	 */
	public function handle_transaction_refund_created( $transaction_refund ) {
		$this->track_transaction_refund( $transaction_refund );
	}

	/**
	 * Handle order updated
	 *
	 * Tracks order cancellations when order status changes
	 *
	 * @since 1.0.0
	 *
	 * @param object $order     Current order object.
	 * @param object $old_order Old order object.
	 * @return void
	 */
	public function handle_order_updated( $order, $old_order ) {
		$status     = $order->status ?? '';
		$old_status = $old_order->status ?? '';

		// Check if status changed to cancelled.
		if ( $status === $old_status || 'cancelled' !== $status ) {
			return;
		}

		$this->track_order_cancellation( $order );
	}

	/**
	 * Track booking cancellation in ecommerce API
	 *
	 * @since 1.0.0
	 *
	 * @param object $booking Booking object.
	 * @return void
	 */
	private function track_booking_cancellation( $booking ) {
		// Check if cancellation tracking is enabled.
		if ( ! $this->is_global_enabled() || ! $this->get_setting( 'track_cancellations', true ) ) {
			return;
		}

		// Get the order ID associated with this booking.
		$order_id = $booking->order_id ?? '';

		if ( empty( $order_id ) ) {
			// If no order, use booking ID for tracking.
			$order_id = 'booking_' . ( $booking->id ?? '' );
		}

		// Build descriptive reason.
		$service = $this->get_service_from_booking( $booking );
		$reason  = sprintf(
			/* translators: %s: service name or 'Booking' */
			__( '%s cancelled', 'surecontact' ),
			$service && ! empty( $service->name ) ? $service->name : __( 'Booking', 'surecontact' )
		);

		$cancel_data = array(
			'order_id'     => $this->generate_unique_order_id( $order_id, 'LPT' ),
			'reason'       => $reason,
			'cancelled_at' => gmdate( 'c' ),
		);

		$this->ecommerce_api->cancel_purchase( $cancel_data, array( 'source' => $this->slug ) );
	}

	/**
	 * Track order cancellation in ecommerce API
	 *
	 * @since 1.0.0
	 *
	 * @param object $order Order object.
	 * @return void
	 */
	private function track_order_cancellation( $order ) {
		// Check if cancellation tracking is enabled.
		if ( ! $this->is_global_enabled() || ! $this->get_setting( 'track_cancellations', true ) ) {
			return;
		}

		$order_id = $order->id ?? '';

		if ( empty( $order_id ) ) {
			Logger::error( 'LatePoint Integration', 'Cannot track order cancellation - no order ID' );
			return;
		}

		$cancel_data = array(
			'order_id'     => $this->generate_unique_order_id( $order_id, 'LPT' ),
			'reason'       => __( 'Order cancelled', 'surecontact' ),
			'cancelled_at' => gmdate( 'c' ),
		);

		$this->ecommerce_api->cancel_purchase( $cancel_data, array( 'source' => $this->slug ) );
	}

	/**
	 * Track transaction refund in ecommerce API
	 *
	 * @since 1.0.0
	 *
	 * @param object $transaction_refund Transaction refund object (OsTransactionRefundModel).
	 * @return void
	 */
	private function track_transaction_refund( $transaction_refund ) {
		// Check if refund tracking is enabled.
		if ( ! $this->is_global_enabled() || ! $this->get_setting( 'track_refunds', true ) ) {
			return;
		}

		$transaction_id = $transaction_refund->transaction_id ?? '';
		$refund_amount  = (float) ( $transaction_refund->amount ?? 0 );

		if ( empty( $transaction_id ) ) {
			Logger::error( 'LatePoint Integration', 'Cannot track refund - no transaction ID' );
			return;
		}

		// Load the transaction to get the order ID.
		$order_id = '';
		if ( class_exists( 'OsTransactionModel' ) ) {
			$transaction = new \OsTransactionModel( $transaction_id );
			$order_id    = $transaction->order_id ?? '';
		}

		if ( empty( $order_id ) ) {
			// Fallback to using transaction ID if order ID not available.
			$order_id = 'txn_' . $transaction_id;
		}

		$refund_data = array(
			'order_id'      => $this->generate_unique_order_id( $order_id, 'LPT' ),
			'reason'        => __( 'Transaction refunded', 'surecontact' ),
			'refund_amount' => $refund_amount,
			'refunded_at'   => gmdate( 'c' ),
		);

		$this->ecommerce_api->refund_purchase( $refund_data, array( 'source' => $this->slug ) );
	}

	/**
	 * Sync customer to CRM
	 *
	 * @since 1.0.0
	 *
	 * @param object $customer Customer object.
	 * @param string $trigger  Trigger event name.
	 * @return string|null Contact ID or null on failure.
	 */
	private function sync_customer_to_crm( $customer, $trigger = 'customer_created' ) {
		// Get WordPress user ID if linked.
		$user_id = ! empty( $customer->wordpress_user_id ) ? (int) $customer->wordpress_user_id : 0;

		// Extract customer data.
		$email      = $customer->email ?? '';
		$first_name = $customer->first_name ?? '';
		$last_name  = $customer->last_name ?? '';

		if ( empty( $email ) ) {
			Logger::error( 'LatePoint Integration', 'No email found for customer ID: ' . ( $customer->id ?? 'unknown' ) );
			return null;
		}

		// Step 1: Set static primary fields.
		$primary_fields = array(
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
		);

		// Step 2: Prepare LatePoint-specific fields for field mapping.
		$raw_data = array();

		// Add phone if available.
		if ( ! empty( $customer->phone ) ) {
			$raw_data['lp_phone'] = $customer->phone;
		}

		// Add notes if available.
		if ( ! empty( $customer->notes ) ) {
			$raw_data['lp_notes'] = $customer->notes;
		}

		// Add admin notes if available.
		if ( ! empty( $customer->admin_notes ) ) {
			$raw_data['lp_admin_notes'] = $customer->admin_notes;
		}

		// Add custom fields from customer meta.
		$custom_field_keys = $this->get_latepoint_custom_fields();
		if ( ! empty( $custom_field_keys ) && method_exists( $customer, 'get_meta_by_key' ) ) {
			foreach ( $custom_field_keys as $field_key => $field_label ) {
				$value = $customer->get_meta_by_key( $field_key );
				if ( ! empty( $value ) || $value === '0' || $value === 0 ) {
					$raw_data[ 'lp_' . $field_key ] = $value;
				}
			}
		}

		// Step 3: Map LatePoint-specific fields through normalize_data.
		$mapped_lp_data = $this->normalize_data( $raw_data );

		// Step 4: Merge static primary fields with mapped LatePoint fields.
		$mapped_data = array(
			'primary_fields' => array_merge(
				$primary_fields,
				$mapped_lp_data['primary_fields'] ?? array()
			),
			'custom_fields'  => $mapped_lp_data['custom_fields'] ?? array(),
			'metadata'       => array_merge(
				$mapped_lp_data['metadata'] ?? array(),
				array(
					'latepoint_customer_id' => $customer->id ?? '',
					'trigger'               => $trigger,
				)
			),
		);

		// Note: Global lists and tags are NOT applied here.
		// They are applied as a fallback through booking events via get_integration_actions().
		// Priority: Specific Service > All Services > Global Settings.

		// Create or update contact.
		$result = $this->contact_service->create_contact( $mapped_data, $user_id, array( 'source' => $this->slug ) );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		$contact_id = $result['contact_uuid'] ?? $result['contact_id'] ?? null;

		return $contact_id;
	}

	/**
	 * Process booking event
	 *
	 * Applies configured actions for a booking event (created, status change)
	 *
	 * @since 1.0.0
	 *
	 * @param object $booking Booking object.
	 * @param string $event   Event name (e.g., 'booking_created', 'booking_approved').
	 * @return void
	 */
	private function process_booking_event( $booking, $event ) {
		// Get customer for this booking.
		$customer = $this->get_customer_from_booking( $booking );

		if ( ! $customer ) {
			Logger::error( 'LatePoint Integration', 'No customer found for booking ID: ' . ( $booking->id ?? 'unknown' ) );
			return;
		}

		// Determine integration actions based on priority.
		$actions = $this->get_integration_actions( $booking, $event );

		// Check if any actions exist.
		$has_actions = ! empty( $actions['add_lists'] ) || ! empty( $actions['add_tags'] ) ||
						! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] );

		if ( ! $has_actions ) {
			Logger::info( 'LatePoint Integration', "No actions configured for event: {$event}" );

			// Still track the booking if enabled.
			if ( $this->get_setting( 'track_bookings', true ) ) {
				$this->track_booking_activity( $booking, $event );
			}
			return;
		}

		// Sync customer and apply actions.
		$contact_id = $this->get_or_create_contact_from_customer( $customer, $booking, $actions['add_lists'], $actions['add_tags'] );

		if ( ! $contact_id ) {
			Logger::error( 'LatePoint Integration', 'Failed to get or create contact for booking event' );
			return;
		}

		// Apply "remove" actions.
		$this->apply_remove_actions( $contact_id, $actions );

		// Track booking activity.
		if ( $this->get_setting( 'track_bookings', true ) ) {
			$this->track_booking_activity( $booking, $event );
		}
	}

	/**
	 * Get or create contact from customer
	 *
	 * @since 1.0.0
	 *
	 * @param object $customer  Customer object.
	 * @param object $booking   Booking object for context.
	 * @param array  $add_lists Lists to add.
	 * @param array  $add_tags  Tags to add.
	 * @return string|null Contact ID or null on failure.
	 */
	private function get_or_create_contact_from_customer( $customer, $booking, $add_lists = array(), $add_tags = array() ) {
		$user_id = ! empty( $customer->wordpress_user_id ) ? (int) $customer->wordpress_user_id : 0;

		// Extract customer data.
		$email      = $customer->email ?? '';
		$first_name = $customer->first_name ?? '';
		$last_name  = $customer->last_name ?? '';

		if ( empty( $email ) ) {
			Logger::error( 'LatePoint Integration', 'No email found in customer object' );
			return null;
		}

		// Build primary fields.
		$primary_fields = array(
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
		);

		// Build raw data for field mapping.
		$raw_data = array();

		if ( ! empty( $customer->phone ) ) {
			$raw_data['lp_phone'] = $customer->phone;
		}

		// Add booking context fields.
		if ( $booking ) {
			$raw_data['lp_last_booking_code'] = $booking->booking_code ?? '';
			$raw_data['lp_last_booking_date'] = $booking->start_date ?? '';

			// Get service name.
			$service = $this->get_service_from_booking( $booking );
			if ( $service ) {
				$raw_data['lp_last_service'] = $service->name ?? '';
			}

			// Get agent name.
			$agent = $this->get_agent_from_booking( $booking );
			if ( $agent ) {
				$raw_data['lp_last_agent'] = $agent->full_name ?? ( ( $agent->first_name ?? '' ) . ' ' . ( $agent->last_name ?? '' ) );
			}

			// Get location name.
			$location = $this->get_location_from_booking( $booking );
			if ( $location ) {
				$raw_data['lp_last_location'] = $location->name ?? '';
			}
		}

		// Get total bookings count.
		if ( method_exists( $customer, 'get_bookings' ) ) {
			$bookings = $customer->get_bookings();
			if ( is_array( $bookings ) ) {
				$raw_data['lp_total_bookings'] = count( $bookings );
			}
		}

		// Map through field mapper.
		$mapped_lp_data = $this->normalize_data( $raw_data );

		// Build final data structure.
		$mapped_data = array(
			'primary_fields' => array_merge(
				$primary_fields,
				$mapped_lp_data['primary_fields'] ?? array()
			),
			'custom_fields'  => $mapped_lp_data['custom_fields'] ?? array(),
			'metadata'       => $mapped_lp_data['metadata'] ?? array(),
		);

		// Add lists and tags.
		if ( ! empty( $add_lists ) ) {
			$mapped_data['list_uuids'] = $this->extract_uuids( $add_lists );
		}
		if ( ! empty( $add_tags ) ) {
			$mapped_data['tag_uuids'] = $this->extract_uuids( $add_tags );
		}

		// Create or update contact.
		$result = $this->contact_service->create_contact( $mapped_data, $user_id, array( 'source' => $this->slug ) );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		return $result['contact_uuid'] ?? $result['contact_id'] ?? null;
	}

	/**
	 * Track order
	 *
	 * Sends order data to CRM for revenue tracking
	 *
	 * @since 1.0.0
	 *
	 * @param object $order Order object.
	 * @return void
	 */
	private function track_order( $order ) {
		// Check if already tracked.
		$order_id = $order->id ?? '';
		$tracked  = get_transient( 'surecontact_latepoint_tracked_' . $order_id );
		if ( $tracked ) {
			return;
		}

		// Get customer.
		$customer = null;
		if ( ! empty( $order->customer_id ) && class_exists( 'OsCustomerModel' ) ) {
			$customer = new \OsCustomerModel( $order->customer_id );
		}

		$email = $customer ? ( $customer->email ?? '' ) : '';

		if ( empty( $email ) ) {
			Logger::error( 'LatePoint Integration', 'Cannot track order - no email found' );
			return;
		}

		// Get order items/bookings using multiple approaches.
		$products = $this->extract_products_from_order( $order );

		// Get currency.
		$currency = 'USD';
		// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar -- PHPStan directive.
		// @phpstan-ignore function.impossibleType
		if ( class_exists( 'OsSettingsHelper' ) && method_exists( 'OsSettingsHelper', 'get_settings_value' ) ) {
			$currency = \OsSettingsHelper::get_settings_value( 'currency_iso_code', 'USD' );
		}

		// Build order data.
		$order_data = array(
			'contact_email'   => $email,
			'order_id'        => $this->generate_unique_order_id( $order_id, 'LPT' ),
			'total_amount'    => (float) ( $order->total ?? 0 ),
			'currency'        => strtoupper( $currency ),
			'products'        => $products,
			'coupon_code'     => $order->coupon_code ?? '',
			'shipping_amount' => 0,
			'purchased_at'    => gmdate( 'c', strtotime( $order->created_at ?? 'now' ) ),
		);

		// Track the order.
		$result = $this->ecommerce_api->track_purchase( $order_data, array( 'source' => $this->slug ) );

		if ( ! is_wp_error( $result ) ) {
			// Mark as tracked (24 hour expiry).
			set_transient( 'surecontact_latepoint_tracked_' . $order_id, true, DAY_IN_SECONDS );
		}
	}

	/**
	 * Track booking activity
	 *
	 * Logs booking event for activity tracking
	 *
	 * @since 1.0.0
	 *
	 * @param object $booking Booking object.
	 * @param string $event   Event name.
	 * @return void
	 */
	private function track_booking_activity( $booking, $event ) {
		$service = $this->get_service_from_booking( $booking );

		Logger::info(
			'LatePoint Integration',
			"Booking event: {$event}",
			array(
				'booking_id'   => $booking->id ?? 'unknown',
				'booking_code' => $booking->booking_code ?? '',
				'service_id'   => $booking->service_id ?? '',
				'service_name' => $service ? ( $service->name ?? '' ) : '',
				'status'       => $booking->status ?? '',
				'start_date'   => $booking->start_date ?? '',
				'start_time'   => $booking->start_time ?? '',
			)
		);
	}

	/**
	 * Get integration actions by merging all matching rules.
	 *
	 * Rules are merged in order of specificity (most specific first):
	 * 1. Specific Service + Specific Location (both merged)
	 * 2. "All Services" + "All Locations" (fallbacks, both merged)
	 * 3. Global Settings (final fallback)
	 *
	 * @since 1.0.0
	 *
	 * @param object $booking Booking object.
	 * @param string $event   Event name ('booking_created', 'booking_approved', etc.).
	 * @return array Array of actions (add_lists, add_tags, remove_lists, remove_tags).
	 */
	private function get_integration_actions( $booking, $event = 'booking_created' ) {
		$actions = array(
			'add_lists'    => array(),
			'add_tags'     => array(),
			'remove_lists' => array(),
			'remove_tags'  => array(),
		);

		$has_specific_match = false;

		// 1. Check Service-specific Settings.
		$service_id = (string) ( $booking->service_id ?? '' );
		if ( ! empty( $service_id ) ) {
			$service_result = $this->integrations_db->get( $this->slug, $service_id, 'service', $event );
			if ( ! $this->has_valid_config( $service_result ) ) {
				$service_result = $this->integrations_db->get( $this->slug, $service_id, 'service', null );
			}

			if ( $this->has_valid_config( $service_result ) && isset( $service_result['config'] ) ) {
				Logger::info( 'LatePoint Integration', "Merging settings from Service: {$service_id}" );
				$actions            = $this->merge_actions( $actions, $service_result['config'] );
				$has_specific_match = true;
			}
		}

		// 2. Check Location-specific Settings (merged with service if both exist).
		$location_id = (string) ( $booking->location_id ?? '' );
		if ( ! empty( $location_id ) ) {
			$location_result = $this->integrations_db->get( $this->slug, $location_id, 'location', $event );
			if ( ! $this->has_valid_config( $location_result ) ) {
				$location_result = $this->integrations_db->get( $this->slug, $location_id, 'location', null );
			}

			if ( $this->has_valid_config( $location_result ) && isset( $location_result['config'] ) ) {
				Logger::info( 'LatePoint Integration', "Merging settings from Location: {$location_id}" );
				$actions            = $this->merge_actions( $actions, $location_result['config'] );
				$has_specific_match = true;
			}
		}

		// If we found specific matches, return the merged result.
		if ( $has_specific_match ) {
			return $actions;
		}

		// 3. Check "All Services" Settings (fallback when no specific service matched).
		$all_services_result = $this->integrations_db->get( $this->slug, 'all', 'service', $event );
		if ( ! $this->has_valid_config( $all_services_result ) ) {
			$all_services_result = $this->integrations_db->get( $this->slug, 'all', 'service', null );
		}

		if ( $this->has_valid_config( $all_services_result ) && isset( $all_services_result['config'] ) ) {
			Logger::info( 'LatePoint Integration', 'Merging settings from "All Services"' );
			$actions = $this->merge_actions( $actions, $all_services_result['config'] );
		}

		// 4. Check "All Locations" Settings (fallback when no specific location matched).
		$all_locations_result = $this->integrations_db->get( $this->slug, 'all', 'location', $event );
		if ( ! $this->has_valid_config( $all_locations_result ) ) {
			$all_locations_result = $this->integrations_db->get( $this->slug, 'all', 'location', null );
		}

		if ( $this->has_valid_config( $all_locations_result ) && isset( $all_locations_result['config'] ) ) {
			Logger::info( 'LatePoint Integration', 'Merging settings from "All Locations"' );
			$actions = $this->merge_actions( $actions, $all_locations_result['config'] );
		}

		// If we have any fallback matches, return them.
		if ( $this->is_config_not_empty( $actions ) ) {
			return $actions;
		}

		// 5. Global Settings (final fallback, only for booking_created event).
		if ( $event === 'booking_created' && $this->is_global_enabled() ) {
			$global_config = array(
				'add_lists'    => $this->get_setting( 'add_lists', array() ),
				'add_tags'     => $this->get_setting( 'add_tags', array() ),
				'remove_lists' => $this->get_setting( 'remove_lists', array() ),
				'remove_tags'  => $this->get_setting( 'remove_tags', array() ),
			);

			if ( $this->is_config_not_empty( $global_config ) ) {
				Logger::info( 'LatePoint Integration', 'Applied settings from Global Settings' );
				return $this->merge_config_defaults( $global_config );
			}
		}

		return $actions;
	}

	/**
	 * Merge two action configurations together.
	 *
	 * Combines add_lists, add_tags, remove_lists, and remove_tags arrays,
	 * removing duplicates.
	 *
	 * @since 1.0.0
	 *
	 * @param array $existing    Existing actions array.
	 * @param array $new_actions New actions to merge in.
	 * @return array Merged actions array.
	 */
	private function merge_actions( $existing, $new_actions ) {
		$new_actions = $this->merge_config_defaults( $new_actions );

		foreach ( array( 'add_lists', 'add_tags', 'remove_lists', 'remove_tags' ) as $key ) {
			if ( ! empty( $new_actions[ $key ] ) && is_array( $new_actions[ $key ] ) ) {
				$existing[ $key ] = array_merge( $existing[ $key ], $new_actions[ $key ] );
				// Remove duplicates by uuid.
				$existing[ $key ] = $this->deduplicate_by_uuid( $existing[ $key ] );
			}
		}

		return $existing;
	}

	/**
	 * Remove duplicate items from array by uuid key.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items Array of items (each may have 'uuid' key or be a string uuid).
	 * @return array Deduplicated array.
	 */
	private function deduplicate_by_uuid( $items ) {
		$seen   = array();
		$result = array();

		foreach ( $items as $item ) {
			$uuid = is_array( $item ) && isset( $item['uuid'] ) ? $item['uuid'] : $item;
			if ( ! isset( $seen[ $uuid ] ) ) {
				$seen[ $uuid ] = true;
				$result[]      = $item;
			}
		}

		return $result;
	}

	/**
	 * Map LatePoint status to event name
	 *
	 * @since 1.0.0
	 *
	 * @param string $status LatePoint booking status.
	 * @return string Event name or empty string.
	 */
	private function map_status_to_event( $status ) {
		$status_map = array(
			'pending'   => 'booking_pending',
			'approved'  => 'booking_approved',
			'cancelled' => 'booking_cancelled',
			'completed' => 'booking_completed',
			'no_show'   => 'booking_no_show',
		);

		return $status_map[ $status ] ?? '';
	}

	/**
	 * Get customer from booking
	 *
	 * @since 1.0.0
	 *
	 * @param object $booking Booking object.
	 * @return object|null Customer object or null.
	 */
	private function get_customer_from_booking( $booking ) {
		$customer_id = $booking->customer_id ?? '';

		if ( empty( $customer_id ) || ! class_exists( 'OsCustomerModel' ) ) {
			return null;
		}

		$customer = new \OsCustomerModel( $customer_id );

		return ! empty( $customer->id ) ? $customer : null;
	}

	/**
	 * Get service from booking
	 *
	 * @since 1.0.0
	 *
	 * @param object $booking Booking object.
	 * @return object|null Service object or null.
	 */
	private function get_service_from_booking( $booking ) {
		$service_id = $booking->service_id ?? '';

		if ( empty( $service_id ) || ! class_exists( 'OsServiceModel' ) ) {
			return null;
		}

		$service = new \OsServiceModel();

		// LatePoint models require explicit load_by_id() call.
		if ( method_exists( $service, 'load_by_id' ) ) {
			$service->load_by_id( $service_id );
		} else {
			// Fallback: try setting id and loading.
			$service->id = $service_id;
			if ( method_exists( $service, 'load' ) ) {
				$service->load();
			}
		}

		return ! empty( $service->id ) && ! empty( $service->name ) ? $service : null;
	}

	/**
	 * Get agent from booking
	 *
	 * @since 1.0.0
	 *
	 * @param object $booking Booking object.
	 * @return object|null Agent object or null.
	 */
	private function get_agent_from_booking( $booking ) {
		$agent_id = $booking->agent_id ?? '';

		if ( empty( $agent_id ) || ! class_exists( 'OsAgentModel' ) ) {
			return null;
		}

		$agent = new \OsAgentModel();

		// LatePoint models require explicit load_by_id() call.
		if ( method_exists( $agent, 'load_by_id' ) ) {
			$agent->load_by_id( $agent_id );
		} else {
			$agent->id = $agent_id;
			if ( method_exists( $agent, 'load' ) ) {
				$agent->load();
			}
		}

		return ! empty( $agent->id ) ? $agent : null;
	}

	/**
	 * Get location from booking
	 *
	 * @since 1.0.0
	 *
	 * @param object $booking Booking object.
	 * @return object|null Location object or null.
	 */
	private function get_location_from_booking( $booking ) {
		$location_id = $booking->location_id ?? '';

		if ( empty( $location_id ) || ! class_exists( 'OsLocationModel' ) ) {
			return null;
		}

		$location = new \OsLocationModel();

		// LatePoint models require explicit load_by_id() call.
		if ( method_exists( $location, 'load_by_id' ) ) {
			$location->load_by_id( $location_id );
		} else {
			$location->id = $location_id;
			if ( method_exists( $location, 'load' ) ) {
				$location->load();
			}
		}

		return ! empty( $location->id ) && ! empty( $location->name ) ? $location : null;
	}

	/**
	 * Extract products from order using LatePoint's order item system
	 *
	 * LatePoint orders contain OrderItems which store booking data as JSON.
	 * Each OrderItem has a build_original_object_from_item_data() method
	 * that creates a proper booking model with all relationships.
	 *
	 * @since 1.0.0
	 *
	 * @param object $order Order object (OsOrderModel).
	 * @return array Array of product data for ecommerce tracking.
	 */
	private function extract_products_from_order( $order ) {
		$products = array();
		$order_id = $order->id ?? '';

		// Primary approach: Use LatePoint's get_items() to get OrderItem models.
		if ( method_exists( $order, 'get_items' ) ) {
			$items = $order->get_items();

			if ( ! empty( $items ) && is_array( $items ) ) {
				foreach ( $items as $item ) {
					$product_data = $this->extract_product_from_order_item( $item );
					if ( $product_data ) {
						$products[] = $product_data;
					}
				}
			}
		}

		// If we got products, return them.
		if ( ! empty( $products ) ) {
			return $products;
		}

		// Final fallback: Create generic entry with order total.
		$products[] = array(
			'product_id' => 'order_' . $order_id,
			'name'       => __( 'Booking Order', 'surecontact' ),
			'quantity'   => 1,
			'price'      => (float) ( $order->total ?? 0 ),
		);

		return $products;
	}

	/**
	 * Extract product data from a LatePoint OrderItem
	 *
	 * OrderItems store booking data as JSON in item_data field.
	 * The build_original_object_from_item_data() method creates
	 * a proper booking model with service_id, agent_id, etc.
	 *
	 * @since 1.0.0
	 *
	 * @param object $item OrderItem object (OsOrderItemModel).
	 * @return array|null Product data array or null.
	 */
	private function extract_product_from_order_item( $item ) {
		if ( ! is_object( $item ) ) {
			return null;
		}

		// Check if this is a booking item (not a bundle).
		$is_booking = false;
		if ( method_exists( $item, 'is_booking' ) ) {
			$is_booking = $item->is_booking();
		} elseif ( isset( $item->variant ) ) {
			// LATEPOINT_ITEM_VARIANT_BOOKING = 'booking'.
			$is_booking = ( 'booking' === $item->variant );
		}

		$product_name = __( 'Booking', 'surecontact' );
		$booking      = null;

		if ( $is_booking && method_exists( $item, 'build_original_object_from_item_data' ) ) {
			// Build booking model from the stored item_data JSON.
			$booking = $item->build_original_object_from_item_data();

			if ( $booking && ! empty( $booking->service_id ) ) {
				// Use LatePoint's magic property to get service with name loaded.
				// $booking->service triggers get_service() which loads the service model.
				$service = $booking->service ?? null;
				if ( $service && ! empty( $service->name ) ) {
					$parts = array( $service->name );

					// Add location if available.
					$location = $booking->location ?? null;
					if ( $location && ! empty( $location->name ) ) {
						$parts[] = '@ ' . $location->name;
					}

					// Add agent if available.
					$agent = $booking->agent ?? null;
					if ( $agent ) {
						$agent_name = $agent->full_name ?? '';
						if ( empty( $agent_name ) ) {
							$agent_name = trim( ( $agent->first_name ?? '' ) . ' ' . ( $agent->last_name ?? '' ) );
						}
						if ( ! empty( $agent_name ) ) {
							$parts[] = '(with ' . $agent_name . ')';
						}
					}

					$product_name = implode( ' ', $parts );
				}
			}
		}

		// Get price from item.
		$price = 0;
		if ( method_exists( $item, 'get_total' ) ) {
			$price = (float) $item->get_total();
		} elseif ( isset( $item->total ) ) {
			$price = (float) $item->total;
		} elseif ( isset( $item->subtotal ) ) {
			$price = (float) $item->subtotal;
		}

		return array(
			'product_id' => isset( $item->id ) ? (string) $item->id : '',
			'name'       => $product_name,
			'quantity'   => 1,
			'price'      => $price,
		);
	}

	/**
	 * Get LatePoint services list.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of service items.
	 */
	public function get_services() {
		if ( ! class_exists( 'OsServiceModel' ) ) {
			return array();
		}

		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Services', 'surecontact' ),
				'type'  => 'service',
			),
		);

		$services_model = new \OsServiceModel();
		$services       = $services_model->should_be_active()->get_results_as_models();

		if ( empty( $services ) || ! is_array( $services ) ) {
			return $items;
		}

		foreach ( $services as $service ) {
			$service_id   = (string) ( $service->id ?? '' );
			$service_name = $service->name ?? __( 'Untitled Service', 'surecontact' );

			if ( ! empty( $service_id ) ) {
				$items[] = array(
					'id'    => $service_id,
					'title' => $service_name,
					'type'  => 'service',
				);
			}
		}

		return $items;
	}

	/**
	 * Get LatePoint locations list.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of location items.
	 */
	public function get_locations() {
		if ( ! class_exists( 'OsLocationModel' ) ) {
			return array();
		}

		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Locations', 'surecontact' ),
				'type'  => 'location',
			),
		);

		$locations_model = new \OsLocationModel();
		$locations       = $locations_model->should_be_active()->get_results_as_models();

		if ( empty( $locations ) || ! is_array( $locations ) ) {
			return $items;
		}

		foreach ( $locations as $location ) {
			$location_id   = (string) ( $location->id ?? '' );
			$location_name = $location->name ?? __( 'Untitled Location', 'surecontact' );

			if ( ! empty( $location_id ) ) {
				$items[] = array(
					'id'    => $location_id,
					'title' => $location_name,
					'type'  => 'location',
				);
			}
		}

		return $items;
	}

	/**
	 * Get LatePoint coupons list.
	 *
	 * Note: Coupons are a LatePoint Pro feature.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of coupon items.
	 */
	public function get_coupons() {
		if ( ! class_exists( 'OsCouponModel' ) ) {
			return array();
		}

		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Coupons', 'surecontact' ),
				'type'  => 'coupon',
			),
		);

		$coupons_model = new \OsCouponModel();
		// Get active coupons only.
		$coupons = $coupons_model->where( array( 'status' => 'active' ) )->get_results_as_models();

		if ( empty( $coupons ) || ! is_array( $coupons ) ) {
			return $items;
		}

		foreach ( $coupons as $coupon ) {
			$coupon_id   = (string) ( $coupon->id ?? '' );
			$coupon_code = $coupon->code ?? '';
			$coupon_name = $coupon->name ?? $coupon_code;

			// Use code as name if name is empty.
			if ( empty( $coupon_name ) && ! empty( $coupon_code ) ) {
				$coupon_name = $coupon_code;
			} elseif ( empty( $coupon_name ) ) {
				$coupon_name = __( 'Untitled Coupon', 'surecontact' );
			}

			if ( ! empty( $coupon_id ) ) {
				$items[] = array(
					'id'    => $coupon_id,
					'title' => $coupon_name,
					'type'  => 'coupon',
				);
			}
		}

		return $items;
	}

	/**
	 * Get item title by type and ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $item_id   Item ID (service, location, or coupon ID).
	 * @param string $item_type Item type ('service', 'location', or 'coupon').
	 * @return string|null Item title or null if not found.
	 */
	public function get_item_title( $item_id, $item_type ) {
		if ( 'all' === $item_id ) {
			switch ( $item_type ) {
				case 'service':
					return __( 'All Services', 'surecontact' );
				case 'location':
					return __( 'All Locations', 'surecontact' );
				case 'coupon':
					return __( 'All Coupons', 'surecontact' );
				default:
					return null;
			}
		}

		switch ( $item_type ) {
			case 'service':
				if ( ! class_exists( 'OsServiceModel' ) ) {
					return null;
				}
				$service = new \OsServiceModel( $item_id );
				return ! empty( $service->id ) ? ( $service->name ?? null ) : null;

			case 'location':
				if ( ! class_exists( 'OsLocationModel' ) ) {
					return null;
				}
				$location = new \OsLocationModel( $item_id );
				return ! empty( $location->id ) ? ( $location->name ?? null ) : null;

			case 'coupon':
				if ( ! class_exists( 'OsCouponModel' ) ) {
					return null;
				}
				$coupon = new \OsCouponModel( $item_id );
				if ( ! empty( $coupon->id ) ) {
					// Prefer name, fallback to code.
					return ! empty( $coupon->name ) ? $coupon->name : ( $coupon->code ?? null );
				}
				return null;

			default:
				return null;
		}
	}

	/**
	 * Process coupon applied event
	 *
	 * Applies configured actions when a coupon is used in an order.
	 *
	 * @since 1.0.0
	 *
	 * @param object $order       Order object.
	 * @param string $coupon_code Coupon code used.
	 * @return void
	 */
	private function process_coupon_applied( $order, $coupon_code ) {
		// Get the coupon ID from the code.
		$coupon_id = $this->get_coupon_id_by_code( $coupon_code );

		if ( empty( $coupon_id ) ) {
			return;
		}

		// Get coupon configuration.
		$coupon_config = $this->get_coupon_config( $coupon_id, 'applied' );

		if ( empty( $coupon_config ) ) {
			return;
		}

		// Get customer from order.
		$customer = null;
		if ( ! empty( $order->customer_id ) && class_exists( 'OsCustomerModel' ) ) {
			$customer = new \OsCustomerModel( $order->customer_id );
		}

		if ( ! $customer || empty( $customer->id ) ) {
			Logger::error( 'LatePoint Integration', 'No customer found for order when processing coupon' );
			return;
		}

		// Get or create contact for this customer.
		$user_id    = ! empty( $customer->wordpress_user_id ) ? (int) $customer->wordpress_user_id : 0;
		$contact_id = null;

		if ( $user_id > 0 ) {
			$contact_id = $this->contact_service->get_contact_id_by_user( $user_id );
		}

		// If no contact found, create one.
		if ( empty( $contact_id ) ) {
			$contact_id = $this->sync_customer_to_crm( $customer, 'coupon_applied' );
		}

		if ( empty( $contact_id ) ) {
			Logger::error( 'LatePoint Integration', 'Failed to get or create contact for coupon application' );
			return;
		}

		// Apply coupon settings.
		$this->apply_coupon_settings( $contact_id, $coupon_config, $coupon_id );
	}

	/**
	 * Get coupon ID by coupon code
	 *
	 * Uses LatePoint's helper method when available, otherwise falls back
	 * to direct database query matching LatePoint's implementation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $coupon_code Coupon code.
	 * @return string|null Coupon ID or null if not found.
	 */
	private function get_coupon_id_by_code( $coupon_code ) {
		if ( empty( $coupon_code ) || ! class_exists( 'OsCouponModel' ) ) {
			return null;
		}

		// LatePoint stores coupon codes as uppercase (see OsCouponModel::before_save).
		$coupon_code_upper = strtoupper( trim( $coupon_code ) );

		$coupon_model = new \OsCouponModel();

		// Build query - must include status filter like LatePoint does.
		$query_params = array( 'code' => $coupon_code_upper );

		// Add status filter if the constant exists (LatePoint Pro).
		if ( defined( 'LATEPOINT_COUPON_STATUS_ACTIVE' ) ) {
			$query_params['status'] = LATEPOINT_COUPON_STATUS_ACTIVE;
		}

		$coupon = $coupon_model->where( $query_params )->set_limit( 1 )->get_results_as_models();

		if ( empty( $coupon ) ) {
			return null;
		}

		// Handle both single object and array returns for compatibility.
		if ( is_array( $coupon ) ) {
			$coupon = reset( $coupon );
		}

		return ! empty( $coupon->id ) ? (string) $coupon->id : null;
	}

	/**
	 * Get coupon configuration from database
	 *
	 * Helper method to retrieve coupon-specific or "All Coupons" configuration
	 * with proper fallbacks for event and item_id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $coupon_id Coupon ID.
	 * @param string $event     Event name ('applied' for coupon usage).
	 * @return array|null Coupon configuration or null if not found.
	 */
	private function get_coupon_config( $coupon_id, $event = 'applied' ) {
		if ( empty( $coupon_id ) ) {
			return null;
		}

		// Check for specific coupon configuration with event.
		$coupon_result = $this->integrations_db->get( $this->slug, $coupon_id, 'coupon', $event );

		// Fallback to null event if not found.
		if ( ! $this->has_valid_config( $coupon_result ) ) {
			$coupon_result = $this->integrations_db->get( $this->slug, $coupon_id, 'coupon', null );
		}

		// If no specific coupon config found, check for "All Coupons" config.
		if ( ! $this->has_valid_config( $coupon_result ) ) {
			$coupon_result = $this->integrations_db->get( $this->slug, 'all', 'coupon', $event );

			// Fallback to null event for "All Coupons".
			if ( ! $this->has_valid_config( $coupon_result ) ) {
				$coupon_result = $this->integrations_db->get( $this->slug, 'all', 'coupon', null );
			}
		}

		// Return the config if valid, otherwise null.
		if ( $this->has_valid_config( $coupon_result ) && isset( $coupon_result['config'] ) ) {
			return $coupon_result['config'];
		}

		return null;
	}

	/**
	 * Apply coupon-specific settings to a contact
	 *
	 * Applies lists and tags configured for a coupon.
	 *
	 * @since 1.0.0
	 *
	 * @param string $contact_id    Contact UUID.
	 * @param array  $coupon_config Coupon configuration array.
	 * @param string $coupon_id     Coupon ID for logging context.
	 * @return bool True if any actions were applied, false otherwise.
	 */
	private function apply_coupon_settings( $contact_id, $coupon_config, $coupon_id ) {
		if ( empty( $coupon_config ) ) {
			return false;
		}

		$config  = $this->merge_config_defaults( $coupon_config );
		$context = "coupon {$coupon_id}";
		$applied = false;

		// Apply "add" actions.
		if ( ! empty( $config['add_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $config['add_lists'] );
			if ( $this->apply_or_remove_lists( $contact_id, $list_uuids, 'attach' ) ) {
				$applied = true;
			}
		}

		if ( ! empty( $config['add_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $config['add_tags'] );
			if ( $this->apply_or_remove_tags( $contact_id, $tag_uuids, 'apply' ) ) {
				$applied = true;
			}
		}

		// Apply "remove" actions.
		if ( ! empty( $config['remove_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $config['remove_lists'] );
			if ( $this->apply_or_remove_lists( $contact_id, $list_uuids, 'detach' ) ) {
				$applied = true;
			}
		}

		if ( ! empty( $config['remove_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $config['remove_tags'] );
			if ( $this->apply_or_remove_tags( $contact_id, $tag_uuids, 'remove' ) ) {
				$applied = true;
			}
		}

		return $applied;
	}
}
