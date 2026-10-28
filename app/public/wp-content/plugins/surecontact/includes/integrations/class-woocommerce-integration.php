<?php
/**
 * WooCommerce Integration
 *
 * Handles WooCommerce customer contact information synchronization
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact\Integrations;

use SureContact\Logger;
use SureContact\API\Ecommerce_API;
use SureContact\Synced_Metadata;
use SureContact\Traits\Integration_DB_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooCommerce_Integration
 *
 * Integrates WooCommerce with SureContact using rule engine system
 *
 * @since 0.0.1
 */
class WooCommerce_Integration extends Base_Integration {

	use Integration_DB_Helper;

	/**
	 * Ecommerce API instance
	 *
	 * @since 0.0.1
	 *
	 * @var Ecommerce_API
	 */
	private $ecommerce_api;

	/**
	 * Order sync handler instance.
	 *
	 * @since 1.2.0
	 *
	 * @var WooCommerce_Order_Sync
	 */
	private $order_sync;

	/**
	 * Customer sync handler instance.
	 *
	 * @since 1.2.0
	 *
	 * @var WooCommerce_Customer_Sync
	 */
	private $customer_sync;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		$this->slug                  = 'woocommerce';
		$this->name                  = 'WooCommerce';
		$this->description           = __( 'Sync WooCommerce customer contact information', 'surecontact' );
		$this->docs_url              = '';
		$this->require_field_mapping = true;
		$this->dependency            = 'WooCommerce';

		parent::__construct();

		// Initialize Ecommerce API and sync handlers.
		$this->ecommerce_api = new Ecommerce_API();
		$this->order_sync    = new WooCommerce_Order_Sync( $this, $this->ecommerce_api );
		$this->customer_sync = new WooCommerce_Customer_Sync( $this, $this->contact_service );

		// Register sync types (base class adds integration metadata).
		add_filter( 'surecontact_available_sync_types', array( $this, 'register_sync_type' ) );
	}

	/**
	 * Add WooCommerce field groups
	 *
	 * Registers two field groups:
	 * 1. WooCommerce Customer - for billing/shipping fields
	 * 2. WooCommerce Order - for order-related calculated fields
	 *
	 * @since 0.0.1
	 *
	 * @param array $groups Existing field groups.
	 * @return array Modified field groups.
	 */
	public function add_meta_field_group( $groups ) {
		$groups['woocommerce'] = array(
			'title' => __( 'WooCommerce Customer', 'surecontact' ),
			'url'   => '', // Optional: Add documentation URL.
		);

		$groups['woocommerce_order'] = array(
			'title' => __( 'WooCommerce Order', 'surecontact' ),
			'url'   => '', // Optional: Add documentation URL.
		);

		return $groups;
	}

	/**
	 * Add WooCommerce-specific fields
	 *
	 * @since 0.0.1
	 *
	 * @param array $fields Existing meta fields.
	 * @return array Modified meta fields.
	 */
	public function add_meta_fields( $fields ) {
		$wc_customer_fields = array(
			'billing_first_name'  => array(
				'label' => __( 'Billing First Name', 'surecontact' ),
				'type'  => 'text',
			),
			'billing_last_name'   => array(
				'label' => __( 'Billing Last Name', 'surecontact' ),
				'type'  => 'text',
			),
			'billing_company'     => array(
				'label' => __( 'Billing Company', 'surecontact' ),
				'type'  => 'text',
			),
			'billing_address_1'   => array(
				'label' => __( 'Billing Address 1', 'surecontact' ),
				'type'  => 'text',
			),
			'billing_address_2'   => array(
				'label' => __( 'Billing Address 2', 'surecontact' ),
				'type'  => 'text',
			),
			'billing_city'        => array(
				'label' => __( 'Billing City', 'surecontact' ),
				'type'  => 'text',
			),
			'billing_postcode'    => array(
				'label' => __( 'Billing Postcode', 'surecontact' ),
				'type'  => 'text',
			),
			'billing_state'       => array(
				'label' => __( 'Billing State', 'surecontact' ),
				'type'  => 'text',
			),
			'billing_country'     => array(
				'label' => __( 'Billing Country', 'surecontact' ),
				'type'  => 'text',
			),
			'billing_phone'       => array(
				'label' => __( 'Billing Phone', 'surecontact' ),
				'type'  => 'phone',
			),
			'billing_email'       => array(
				'label' => __( 'Billing Email', 'surecontact' ),
				'type'  => 'email',
			),
			'shipping_first_name' => array(
				'label' => __( 'Shipping First Name', 'surecontact' ),
				'type'  => 'text',
			),
			'shipping_last_name'  => array(
				'label' => __( 'Shipping Last Name', 'surecontact' ),
				'type'  => 'text',
			),
			'shipping_company'    => array(
				'label' => __( 'Shipping Company', 'surecontact' ),
				'type'  => 'text',
			),
			'shipping_address_1'  => array(
				'label' => __( 'Shipping Address 1', 'surecontact' ),
				'type'  => 'text',
			),
			'shipping_address_2'  => array(
				'label' => __( 'Shipping Address 2', 'surecontact' ),
				'type'  => 'text',
			),
			'shipping_city'       => array(
				'label' => __( 'Shipping City', 'surecontact' ),
				'type'  => 'text',
			),
			'shipping_postcode'   => array(
				'label' => __( 'Shipping Postcode', 'surecontact' ),
				'type'  => 'text',
			),
			'shipping_state'      => array(
				'label' => __( 'Shipping State', 'surecontact' ),
				'type'  => 'text',
			),
			'shipping_country'    => array(
				'label' => __( 'Shipping Country', 'surecontact' ),
				'type'  => 'text',
			),
		);

		// Optimized: Add group to all customer fields in-place (O(n) with single assignment per field).
		foreach ( $wc_customer_fields as $key => &$config ) {
			$config['group'] = 'woocommerce';
			$fields[ $key ]  = $config;
		}
		unset( $config ); // Break reference.

		// WooCommerce Order fields (calculated/computed values).
		$wc_order_fields = array(
			'wc_order_count'         => array(
				'label' => __( 'Total Order Count', 'surecontact' ),
				'type'  => 'integer',
			),
			'wc_total_spent'         => array(
				'label' => __( 'Total Amount Spent', 'surecontact' ),
				'type'  => 'decimal',
			),
			'wc_last_order_date'     => array(
				'label' => __( 'Last Order Date', 'surecontact' ),
				'type'  => 'date',
			),
			'wc_first_order_date'    => array(
				'label' => __( 'First Order Date', 'surecontact' ),
				'type'  => 'date',
			),
			'wc_average_order_value' => array(
				'label' => __( 'Average Order Value', 'surecontact' ),
				'type'  => 'decimal',
			),
			'order_note'             => array(
				'label' => __( 'Order Notes', 'surecontact' ),
				'type'  => 'textarea',
			),
		);

		// Optimized: Add group and pseudo flag to all order fields in-place (O(n) with single assignment per field).
		foreach ( $wc_order_fields as $key => &$config ) {
			$config['group']  = 'woocommerce_order';
			$config['pseudo'] = true;
			$fields[ $key ]   = $config;
		}
		unset( $config ); // Break reference.

		return $fields;
	}

	/**
	 * Get integration-specific settings fields
	 *
	 * @since 0.0.1
	 *
	 * @return array Settings fields configuration
	 */
	public function get_settings_fields() {
		$order_statuses = wc_get_order_statuses();

		$settings = array();

		$settings['sync_guest_customers'] = array(
			'label'       => __( 'Sync Guest Customers', 'surecontact' ),
			'description' => __( 'Sync guest checkout customers to SureContact (customers without WordPress accounts)', 'surecontact' ),
			'type'        => 'checkbox',
			'default'     => true,
		);

		// Revenue tracking settings.
		$settings['track_orders'] = array(
			'label'       => __( 'Track Order Data', 'surecontact' ),
			'description' => __( 'Send order information to SureContact for revenue tracking', 'surecontact' ),
			'type'        => 'checkbox',
			'default'     => true,
		);

		$settings['track_refunds'] = array(
			'label'       => __( 'Track Refunds', 'surecontact' ),
			'description' => __( 'Send refund information to SureContact', 'surecontact' ),
			'type'        => 'checkbox',
			'default'     => true,
		);

		$settings['track_cancellations'] = array(
			'label'       => __( 'Track Cancellations', 'surecontact' ),
			'description' => __( 'Send cancellation information to SureContact when orders are cancelled', 'surecontact' ),
			'type'        => 'checkbox',
			'default'     => true,
		);

		$settings = array_merge( $settings, self::get_standard_list_tag_fields() );

		$settings['auto_tag_categories'] = array(
			'label'       => __( 'Auto-Tag Product Categories', 'surecontact' ),
			'description' => __( 'Automatically create and apply tags based on product category names when purchased', 'surecontact' ),
			'type'        => 'checkbox',
			'default'     => false,
		);

		$settings['auto_tag_products'] = array(
			'label'       => __( 'Auto-Tag Product Names', 'surecontact' ),
			'description' => __( 'Automatically create and apply tags based on product names when purchased', 'surecontact' ),
			'type'        => 'checkbox',
			'default'     => false,
		);

		$settings['auto_tag_sku'] = array(
			'label'       => __( 'Auto-Tag Product SKUs', 'surecontact' ),
			'description' => __( 'Automatically create and apply tags based on product SKUs when purchased', 'surecontact' ),
			'type'        => 'checkbox',
			'default'     => false,
		);

		$settings['auto_tag_prefix'] = array(
			'label'       => __( 'Auto-Tag Prefix', 'surecontact' ),
			'description' => __( 'Prefix to add to automatically generated tags (e.g., "Purchased -")', 'surecontact' ),
			'type'        => 'text',
			'default'     => '',
		);

		$settings['review_tags'] = array(
			'label'       => __( 'Product Review Tags', 'surecontact' ),
			'description' => __( 'Apply these tags when a customer leaves a product review', 'surecontact' ),
			'type'        => 'tag-select',
			'default'     => array(),
		);

		// Abandoned Cart settings.
		$settings['abandoned_cart_enabled'] = array(
			'label'       => __( 'Enable Abandoned Cart Tracking', 'surecontact' ),
			'description' => __( 'Track abandoned carts and apply lists/tags to contacts who abandon their carts', 'surecontact' ),
			'type'        => 'checkbox',
			'default'     => false,
		);

		$settings['abandoned_cart_threshold'] = array(
			'label'       => __( 'Abandonment Threshold (minutes)', 'surecontact' ),
			'description' => __( 'Minutes of inactivity before a cart is considered abandoned (minimum 5, maximum 10080)', 'surecontact' ),
			'type'        => 'number',
			'default'     => 60,
			'min'         => 5,
			'max'         => 10080,
		);

		$settings['abandoned_cart_add_lists'] = array(
			'label'       => __( 'Abandoned Cart Lists', 'surecontact' ),
			'description' => __( 'Add contacts to these lists when their cart is abandoned', 'surecontact' ),
			'type'        => 'list-select',
			'default'     => array(),
		);

		$settings['abandoned_cart_add_tags'] = array(
			'label'       => __( 'Abandoned Cart Tags', 'surecontact' ),
			'description' => __( 'Apply these tags to contacts when their cart is abandoned', 'surecontact' ),
			'type'        => 'tag-select',
			'default'     => array(),
		);

		$settings['abandoned_cart_retention_days'] = array(
			'label'       => __( 'Cart Data Retention (days)', 'surecontact' ),
			'description' => __( 'Number of days to keep abandoned and recovered cart records before cleanup', 'surecontact' ),
			'type'        => 'number',
			'default'     => 30,
			'min'         => 7,
			'max'         => 30,
		);

		foreach ( $order_statuses as $status_key => $status_label ) {
			/* translators: %s: WooCommerce order status label. */
			$label = sprintf( __( 'Order Status: %s', 'surecontact' ), $status_label );

			$description = sprintf(
				/* translators: %s: WooCommerce order status label. */
				__( 'Select lists to add contacts to when order status changes to %s.', 'surecontact' ),
				$status_label
			);

			$settings[ 'order_status_trigger_' . $status_key ] = array(
				'label'       => $label,
				'description' => $description,
				'type'        => 'list-select',
				'default'     => array(),
			);
		}

		return $settings;
	}

	/**
	 * Get all available item types for WooCommerce.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of item type definitions with 'key' and 'label' keys.
	 */
	public function get_item_types() {
		return array(
			array(
				'key'   => 'product',
				'label' => __( 'Product', 'surecontact' ),
			),
			array(
				'key'   => 'product_category',
				'label' => __( 'Product Category', 'surecontact' ),
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
	 * @since 0.0.3
	 *
	 * @param string $item_type Item type (e.g., 'product', 'coupon').
	 * @return array Array of event definitions with 'key' and 'label' keys.
	 */
	public function get_events_by_item_type( $item_type ) {
		switch ( $item_type ) {
			case 'product':
			case 'product_category':
				return array(
					array(
						'key'   => 'purchase',
						'label' => __( 'Purchase', 'surecontact' ),
					),
					array(
						'key'   => 'refund',
						'label' => __( 'Refund', 'surecontact' ),
					),
					array(
						'key'   => 'cancellation',
						'label' => __( 'Cancellation', 'surecontact' ),
					),
				);

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
	 * Get item-specific configuration fields for a WooCommerce product or coupon.
	 *
	 * @since 0.0.3
	 *
	 * @param string      $item_id Product or coupon ID.
	 * @param string|null $event   Event name (not used - kept for compatibility).
	 * @return array Configuration fields schema.
	 */
	public function get_item_config_fields( $item_id, $event = null ) {
		return self::get_standard_list_tag_fields();
	}

	/**
	 * Get WooCommerce item fields.
	 *
	 * For WooCommerce, products/coupons don't have custom fields to map.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id Item ID (product or coupon ID).
	 * @return array Empty array (no mappable fields for products/coupons).
	 */
	public function get_item_fields( $item_id ) {
		return array();
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 0.0.1
	 */
	protected function init() {
		add_action( 'woocommerce_created_customer', array( $this, 'handle_customer_registration' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'process_woo_order' ), 10, 3 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'process_woo_order' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'process_woo_order' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_based_lists' ), 2, 4 );
		add_action( 'woocommerce_payment_complete', array( $this, 'track_order_revenue' ), 10, 1 );
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_order_refund' ), 20, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_order_cancellation' ), 10, 2 );
		add_action( 'comment_post', array( $this, 'handle_product_review' ), 10, 2 );
		add_action( 'wp_set_comment_status', array( $this, 'handle_product_review_approved' ), 10, 2 );

		// Abandoned cart tracking.
		if ( $this->get_setting( 'abandoned_cart_enabled', false ) ) {
			$manager = \SureContact::get_instance()->get_abandoned_cart_manager();
			if ( $manager ) {
				new Woocommerce_Abandoned_Cart( $this, $manager );
			}
		}
	}

	/**
	 * Process WooCommerce order
	 *
	 * @since 0.0.1
	 *
	 * @param int   $order_id Order ID.
	 * @param mixed $arg2     Hook-specific argument.
	 * @param mixed $arg3     Optional third argument.
	 * @param bool  $force    Force processing even if already processed.
	 * @return bool True if processed successfully, false otherwise.
	 */
	public function process_woo_order( $order_id, $arg2 = null, $arg3 = null, $force = false ) {
		if ( ! $force && get_transient( 'surecontact_woo_processing_' . $order_id ) ) {
			return true;
		}

		set_transient( 'surecontact_woo_processing_' . $order_id, true, HOUR_IN_SECONDS );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			delete_transient( 'surecontact_woo_processing_' . $order_id );
			Logger::error( 'WooCommerce Integration', "Unable to find order {$order_id}" );
			return false;
		}

		if ( ! $force && $order->get_meta( '_surecontact_complete', true ) ) {
			delete_transient( 'surecontact_woo_processing_' . $order_id );
			return true;
		}

		$order_status   = $order->get_status();
		$valid_statuses = $this->get_setting( 'valid_order_statuses', array( 'processing', 'completed' ) );

		$normalized_statuses = array_map(
			function ( $status ) {
				return str_replace( 'wc-', '', $status );
			},
			$valid_statuses
		);

		if ( ! in_array( $order_status, $normalized_statuses, true ) ) {
			delete_transient( 'surecontact_woo_processing_' . $order_id );
			return false;
		}

		$user_id              = $order->get_customer_id();
		$sync_guest_customers = $this->is_global_enabled() && $this->get_setting( 'sync_guest_customers', true );
		$track_orders         = $this->is_global_enabled() && $this->get_setting( 'track_orders', true );

		// Track variables to determine what succeeded.
		$contact_synced = false;
		$order_tracked  = false;
		$order_notes    = array();

		// Handle contact sync (upsert) for registered users or guest customers.
		$should_sync_contact = ( $user_id > 0 ) || $sync_guest_customers;

		if ( $should_sync_contact ) {
			$contact_synced = $this->sync_contact_from_order( $order, $user_id, $order_notes );
		} else {
			// Guest checkout but sync disabled.
			$order_notes[] = __( 'SureContact: Guest customer sync disabled.', 'surecontact' );
		}

		// Track order independently (even if contact sync failed).
		if ( $track_orders ) {
			$this->track_order_if_not_tracked( $order_id, $order );
			$order_tracked = true;
			$order_notes[] = __( 'SureContact: Order tracked.', 'surecontact' );
		}

		// Mark as complete regardless of individual failures.
		$order->update_meta_data( '_surecontact_complete', current_time( 'mysql' ) );

		// Add consolidated order note.
		if ( ! empty( $order_notes ) ) {
			$order->add_order_note( implode( ' ', $order_notes ) );
		}

		$order->save();

		delete_transient( 'surecontact_woo_processing_' . $order_id );

		// Return true if at least one operation succeeded.
		return $contact_synced || $order_tracked;
	}

	/**
	 * Sync contact from order data (create or update)
	 *
	 * Uses create_contact which handles both create and update via the same
	 * API endpoint (wordpress/sync-contact). The API automatically detects
	 * if the contact exists and updates it, or creates a new one.
	 *
	 * @since 0.0.2
	 *
	 * @param \WC_Order $order       Order object.
	 * @param int       $user_id     User ID (0 for guest).
	 * @param array     &$order_notes Order notes array (passed by reference).
	 * @return bool True if contact synced successfully, false otherwise.
	 */
	private function sync_contact_from_order( $order, $user_id, &$order_notes ) {
		// Get product/coupon-level actions first.
		$actions = $this->get_integration_actions_for_order( $order, 'purchase' );

		// Get order status lists (only if global enabled).
		$order_status          = $order->get_status();
		$order_status_prefixed = strpos( $order_status, 'wc-' ) === 0 ? $order_status : 'wc-' . $order_status;
		$status_lists          = $this->is_global_enabled() ? $this->get_setting( 'order_status_trigger_' . $order_status_prefixed, array() ) : array();

		// Get auto-tags (only if global enabled - already checked inside method).
		$auto_tags = $this->get_auto_tags_for_order( $order );

		// Check if there are any coupon-specific settings.
		$has_coupon_settings = $this->order_has_coupon_settings( $order );

		// Calculate all lists and tags.
		$all_lists = array_merge(
			$this->extract_uuids( $status_lists ),
			$this->extract_uuids( $actions['add_lists'] )
		);

		$all_tags = array_merge(
			$this->extract_uuids( $actions['add_tags'] ),
			$auto_tags
		);

		$remove_lists = $actions['remove_lists'];
		$remove_tags  = $actions['remove_tags'];

		// Check if there are any actions to perform.
		$has_any_actions = ! empty( $all_lists )
			|| ! empty( $all_tags )
			|| ! empty( $remove_lists )
			|| ! empty( $remove_tags )
			|| $has_coupon_settings;

		// If no actions found, don't sync the contact.
		if ( ! $has_any_actions ) {
			$order_notes[] = __( 'SureContact: No actions configured, contact not synced.', 'surecontact' );
			return false;
		}

		$crm_data = $this->get_customer_data( $user_id, $order );

		$customer_note = $order->get_customer_note();
		if ( ! empty( $customer_note ) ) {
			$crm_data['order_note'] = $customer_note;
		}

		$mapped_data = $this->normalize_data( $crm_data );

		if ( ! empty( $all_lists ) ) {
			$mapped_data['list_uuids'] = array_values( array_unique( $all_lists ) );
		}

		if ( ! empty( $all_tags ) ) {
			$mapped_data['tag_uuids'] = array_values( array_unique( $all_tags ) );
		}

		$result = $this->contact_service->create_contact( $mapped_data, $user_id, array( 'source' => $this->slug ) );

		if ( is_wp_error( $result ) ) {
			$order_notes[] = __( 'SureContact: Contact sync failed.', 'surecontact' );
			return false;
		}

		$contact_id = $result['contact_id'] ?? null;

		if ( $contact_id ) {
			$this->apply_remove_actions_with_config( $contact_id, $remove_lists, $remove_tags );

			// Apply coupon-specific settings independently (if a coupon was used).
			$this->apply_coupon_settings_for_order( $order, $contact_id, 'applied' );
		}

		$order_notes[] = __( 'SureContact: Contact synced and lists/tags applied.', 'surecontact' );
		return true;
	}

	/**
	 * Handle status-based list assignment
	 *
	 * @since 0.0.1
	 *
	 * @param int       $order_id   Order ID.
	 * @param string    $old_status Old order status.
	 * @param string    $new_status New order status.
	 * @param \WC_Order $order      Order object.
	 * @return void
	 */
	public function handle_status_based_lists( $order_id, $old_status, $new_status, $order ) {
		if ( $old_status === 'pending' ) {
			return;
		}

		// Only apply order status triggers if global settings are enabled.
		if ( ! $this->is_global_enabled() ) {
			return;
		}

		$prefixed_status  = strpos( $new_status, 'wc-' ) === 0 ? $new_status : 'wc-' . $new_status;
		$lists_for_status = $this->get_setting( 'order_status_trigger_' . $prefixed_status, array() );

		if ( empty( $lists_for_status ) ) {
			return;
		}

		$user_id = $order->get_customer_id();

		if ( $user_id > 0 ) {
			$contact_id = $this->contact_service->get_contact_id_by_user( $user_id );

			if ( $contact_id ) {
				$this->add_contact_to_lists( $contact_id, $lists_for_status, $user_id );
			}
		} elseif ( $this->get_setting( 'sync_guest_customers', true ) ) {
				$email      = $order->get_billing_email();
				$contact_id = $this->contact_service->find_contact_id_by_email( $email );

			if ( $contact_id ) {
				$this->add_contact_to_lists( $contact_id, $lists_for_status, 0 );
			}
		}
	}

	/**
	 * Track order revenue in CRM
	 *
	 * @since 0.0.1
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function track_order_revenue( $order_id ) {
		if ( ! $this->is_global_enabled() || ! $this->get_setting( 'track_orders', true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Reuse existing method (already has duplicate prevention via _surecontact_order_tracked meta).
		$this->track_order_if_not_tracked( $order_id, $order );
	}

	/**
	 * Handle customer registration via WooCommerce
	 *
	 * @since 0.0.1
	 *
	 * @param int   $customer_id        Customer ID.
	 * @param array $new_customer_data  Customer data.
	 * @param bool  $password_generated Whether password was generated.
	 * @return void
	 */
	public function handle_customer_registration( $customer_id, $new_customer_data, $password_generated ) {
		// Only process if global settings are enabled.
		if ( ! $this->is_global_enabled() ) {
			return;
		}

		if ( doing_action( 'woocommerce_checkout_order_processed' ) ) {
			return;
		}

		$user = get_userdata( $customer_id );
		if ( ! $user && ! $this->get_setting( 'sync_guest_customers', true ) ) {
			return;
		}

		// Get customer data from WooCommerce.
		$crm_data = $this->get_customer_data( $customer_id );

		$mapped_data = $this->normalize_data( $crm_data );

		$this->add_registration_lists_and_tags( $mapped_data );

		$result = $this->contact_service->create_contact( $mapped_data, $customer_id, array( 'source' => $this->slug ) );

		if ( is_wp_error( $result ) ) {
			return;
		} else {
			$contact_id = $result['contact_id'] ?? null;
			if ( $contact_id ) {
				$this->apply_global_remove_actions( $contact_id );
			}
		}
	}

	/**
	 * Add contact to lists
	 *
	 * @since 0.0.1
	 *
	 * @param string $contact_id       Contact UUID in CRM.
	 * @param array  $lists_for_status Lists to add contact to.
	 * @param int    $user_id          User ID.
	 * @return void
	 */
	private function add_contact_to_lists( $contact_id, $lists_for_status, $user_id ) {
		$list_uuids = $this->extract_uuids( $lists_for_status );

		if ( empty( $list_uuids ) ) {
			return;
		}

		$result = $this->contact_service->attach_lists_to_contact( $contact_id, $list_uuids, array( 'source' => $this->slug ) );
	}

	/**
	 * Track order if not already tracked
	 *
	 * @since 0.0.1
	 *
	 * @param int       $order_id Order ID.
	 * @param \WC_Order $order    Order object.
	 * @return void
	 */
	private function track_order_if_not_tracked( $order_id, $order ) {
		$tracked = $order->get_meta( '_surecontact_order_tracked', true );
		if ( 'yes' === $tracked ) {
			return;
		}

		$order_data = $this->prepare_order_data( $order );
		if ( null === $order_data ) {
			Logger::error( 'WooCommerce Integration', "Failed to prepare order data for order {$order_id}" );
			return;
		}

		$result = $this->ecommerce_api->track_purchase( $order_data, array( 'source' => $this->slug ) );

		if ( is_wp_error( $result ) ) {
			return;
		} else {
			$order->update_meta_data( '_surecontact_order_tracked', 'yes' );
			$order->save();
		}
	}

	/**
	 * Prepare order data for tracking.
	 *
	 * Extracts order data from a WC_Order object for the track_purchase API.
	 * Used by both real-time tracking and bulk order sync.
	 *
	 * @since 0.0.1
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return array|null Order data array ready for track_purchase API, or null on validation failure.
	 */
	public function prepare_order_data( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		// For registered users, use their account email for consistency with customer sync.
		// This ensures orders are linked to the same contact as customer data.
		$user_id = $order->get_customer_id();
		if ( $user_id > 0 ) {
			$user          = get_userdata( $user_id );
			$contact_email = $user ? $user->user_email : $order->get_billing_email();
		} else {
			$contact_email = $order->get_billing_email();
		}

		if ( empty( $contact_email ) ) {
			return null;
		}

		// Build products array.
		$products = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$variation_id = $item->get_variation_id();

			$products[] = array(
				'product_id' => (string) $item->get_product_id(),
				'name'       => apply_filters( 'surecontact_woocommerce_tracking_product_name', $item->get_name(), $item, $order ),
				'quantity'   => $item->get_quantity(),
				'price'      => (float) $order->get_item_subtotal( $item, false, false ),
				'variant_id' => $variation_id ? (string) $variation_id : '',
			);
		}

		// Fallback if no products found.
		if ( empty( $products ) ) {
			$products[] = array(
				'product_id' => 'unknown',
				'name'       => 'Unknown Product',
				'quantity'   => 1,
				'price'      => (float) $order->get_total(),
			);
		}

		// Get coupon code (use first one if multiple).
		$coupon_codes = $order->get_coupon_codes();
		$coupon_code  = ! empty( $coupon_codes ) ? $coupon_codes[0] : '';

		$date_created = $order->get_date_created();

		return array(
			'contact_email'   => $contact_email,
			'order_id'        => $this->generate_unique_order_id( $order->get_order_number(), 'WOO' ),
			'total_amount'    => (float) $order->get_total(),
			'subtotal_amount' => (float) $order->get_subtotal(),
			'discount_amount' => (float) $order->get_discount_total(),
			'shipping_amount' => (float) $order->get_shipping_total(),
			'tax_amount'      => (float) $order->get_total_tax(),
			'currency'        => $order->get_currency(),
			'products'        => $products,
			'coupon_code'     => $coupon_code,
			'purchased_at'    => $date_created ? $date_created->date( 'c' ) : gmdate( 'c' ),
		);
	}

	/**
	 * Handle order cancellation
	 *
	 * @since 0.0.1
	 *
	 * @param int       $order_id Order ID.
	 * @param \WC_Order $order    Order object.
	 * @return void
	 */
	public function handle_order_cancellation( $order_id, $order ) {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return;
			}
		}

		// Get contact ID from order or customer.
		$contact_id = $this->get_contact_id_from_order( $order );

		if ( $contact_id ) {
			// Apply cancellation-specific lists/tags based on product settings.
			$actions = $this->get_integration_actions_for_order( $order, 'cancellation' );

			// Apply add actions.
			if ( ! empty( $actions['add_lists'] ) ) {
				$list_uuids = $this->extract_uuids( $actions['add_lists'] );
				if ( ! empty( $list_uuids ) ) {
					$this->contact_service->attach_lists_to_contact( $contact_id, $list_uuids, array( 'source' => $this->slug ) );
				}
			}

			if ( ! empty( $actions['add_tags'] ) ) {
				$tag_uuids = $this->extract_uuids( $actions['add_tags'] );
				if ( ! empty( $tag_uuids ) ) {
					$this->contact_service->attach_tags_to_contact( $contact_id, $tag_uuids, array( 'source' => $this->slug ) );
				}
			}

			// Apply remove actions.
			if ( ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] ) ) {
				$this->apply_remove_actions_with_config( $contact_id, $actions['remove_lists'], $actions['remove_tags'] );
			}
		}

		// Track cancellation in ecommerce API (global setting).
		if ( ! $this->is_global_enabled() || ! $this->get_setting( 'track_cancellations', true ) ) {
			return;
		}

		$cancel_data = array(
			'order_id'     => $this->generate_unique_order_id( $order->get_order_number(), 'WOO' ),
			'reason'       => $order->get_customer_note() ? $order->get_customer_note() : 'Order cancelled',
			'cancelled_at' => gmdate( 'c' ),
		);

		$result = $this->ecommerce_api->cancel_purchase( $cancel_data, array( 'source' => $this->slug ) );
	}

	/**
	 * Handle order refund
	 *
	 * @since 0.0.1
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 * @return void
	 */
	public function handle_order_refund( $order_id, $refund_id ) {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );

		if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		// Get contact ID from order or customer.
		$contact_id = $this->get_contact_id_from_order( $order );

		if ( $contact_id ) {
			// Apply refund-specific lists/tags based on product settings.
			$actions = $this->get_integration_actions_for_order( $order, 'refund' );

			// Apply add actions.
			if ( ! empty( $actions['add_lists'] ) ) {
				$list_uuids = $this->extract_uuids( $actions['add_lists'] );
				if ( ! empty( $list_uuids ) ) {
					$this->contact_service->attach_lists_to_contact( $contact_id, $list_uuids, array( 'source' => $this->slug ) );
				}
			}

			if ( ! empty( $actions['add_tags'] ) ) {
				$tag_uuids = $this->extract_uuids( $actions['add_tags'] );
				if ( ! empty( $tag_uuids ) ) {
					$this->contact_service->attach_tags_to_contact( $contact_id, $tag_uuids, array( 'source' => $this->slug ) );
				}
			}

			// Apply remove actions.
			if ( ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] ) ) {
				$this->apply_remove_actions_with_config( $contact_id, $actions['remove_lists'], $actions['remove_tags'] );
			}
		}

		// Track refund in ecommerce API (global setting).
		if ( ! $this->is_global_enabled() || ! $this->get_setting( 'track_refunds', true ) ) {
			return;
		}

		$refund_date_created = $refund->get_date_created();
		$refund_data         = array(
			'order_id'      => $this->generate_unique_order_id( $order->get_order_number(), 'WOO' ),
			'reason'        => $refund->get_reason() ? $refund->get_reason() : '',
			'refund_amount' => (float) abs( $refund->get_amount() ),
			'refunded_at'   => $refund_date_created ? $refund_date_created->date( 'c' ) : gmdate( 'c' ),
		);

		$result = $this->ecommerce_api->refund_purchase( $refund_data, array( 'source' => $this->slug ) );
	}

	/**
	 * Get contact ID from a WooCommerce order.
	 *
	 * @since 0.0.3
	 *
	 * @param \WC_Order $order Order object.
	 * @return string|null Contact ID or null if not found.
	 */
	private function get_contact_id_from_order( $order ) {
		$user_id = $order->get_user_id();

		if ( $user_id > 0 ) {
			$contact_id = $this->contact_service->get_contact_id_by_user( $user_id );
			return $contact_id ? $contact_id : null;
		}

		// For guest orders, try to find contact by email.
		$email = $order->get_billing_email();
		if ( $email ) {
			$contact_id = $this->contact_service->find_contact_id_by_email( $email );

			if ( $contact_id ) {
				return $contact_id;
			}
		}

		return null;
	}

	/**
	 * Get customer data - single source of truth for all WooCommerce customer data extraction
	 *
	 * This method provides consistent customer data for both real-time and bulk sync operations.
	 * All fields available in field mapping are included.
	 *
	 * @since 0.0.4
	 *
	 * @param int            $user_id  WordPress user ID (0 for guests).
	 * @param \WC_Order|null $order    Optional order object for additional context.
	 * @return array Customer data array with all available fields
	 */
	public function get_customer_data( $user_id, $order = null ) {
		$data = array(
			'user_role' => 'customer',
			'user_id'   => $user_id,
		);

		$is_guest = ( 0 === $user_id );
		if ( $is_guest ) {
			$data['is_guest'] = true;
		}

		// Add WordPress user data for registered customers.
		if ( ! $is_guest ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$data['user_login']      = $user->user_login;
				$data['user_registered'] = $user->user_registered;
				$data['user_email']      = $user->user_email;
			}
		}

		// Define all billing and shipping fields.
		$billing_fields = array(
			'billing_first_name',
			'billing_last_name',
			'billing_email',
			'billing_phone',
			'billing_company',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_country',
		);

		$shipping_fields = array(
			'shipping_first_name',
			'shipping_last_name',
			'shipping_company',
			'shipping_address_1',
			'shipping_address_2',
			'shipping_city',
			'shipping_state',
			'shipping_postcode',
			'shipping_country',
		);

		// If order is provided, extract data from order first.
		if ( $order instanceof \WC_Order ) {
			foreach ( $billing_fields as $field ) {
				$method = 'get_' . $field;
				if ( method_exists( $order, $method ) ) {
					$value = $order->$method();
					if ( ! empty( $value ) ) {
						$data[ $field ] = $value;
					}
				}
			}

			foreach ( $shipping_fields as $field ) {
				$method = 'get_' . $field;
				if ( method_exists( $order, $method ) ) {
					$value = $order->$method();
					if ( ! empty( $value ) ) {
						$data[ $field ] = $value;
					}
				}
			}
		}

		// For registered customers, enrich with WC_Customer data (fills any gaps).
		if ( ! $is_guest && $user_id > 0 ) {
			$wc_customer = new \WC_Customer( $user_id );
			if ( $wc_customer->get_id() ) {
				// Fill in any missing billing fields from WC_Customer.
				foreach ( $billing_fields as $field ) {
					if ( empty( $data[ $field ] ) ) {
						$method = 'get_' . $field;
						if ( method_exists( $wc_customer, $method ) ) {
							$value = $wc_customer->$method();
							if ( ! empty( $value ) ) {
								$data[ $field ] = $value;
							}
						}
					}
				}

				// Fill in any missing shipping fields from WC_Customer.
				foreach ( $shipping_fields as $field ) {
					if ( empty( $data[ $field ] ) ) {
						$method = 'get_' . $field;
						if ( method_exists( $wc_customer, $method ) ) {
							$value = $wc_customer->$method();
							if ( ! empty( $value ) ) {
								$data[ $field ] = $value;
							}
						}
					}
				}

				// Add order stats from WC_Customer.
				$data['wc_total_spent'] = $wc_customer->get_total_spent();
				$data['wc_order_count'] = $wc_customer->get_order_count();

				// Calculate average order value.
				$order_count = (int) $data['wc_order_count'];
				if ( $order_count > 0 ) {
					$data['wc_average_order_value'] = (float) $data['wc_total_spent'] / $order_count;
				}

				// Get first and last order dates for registered customers.
				$first_order_ids = wc_get_orders(
					array(
						'customer_id' => $user_id,
						'limit'       => 1,
						'orderby'     => 'date',
						'order'       => 'ASC',
						'return'      => 'ids',
					)
				);

				$last_order_ids = wc_get_orders(
					array(
						'customer_id' => $user_id,
						'limit'       => 1,
						'orderby'     => 'date',
						'order'       => 'DESC',
						'return'      => 'ids',
					)
				);

				if ( is_array( $first_order_ids ) && ! empty( $first_order_ids ) ) {
					$first_order = wc_get_order( $first_order_ids[0] );
					if ( $first_order instanceof \WC_Order ) {
						$data['wc_first_order_date'] = $first_order->get_date_created() ? $first_order->get_date_created()->format( 'Y-m-d' ) : '';
					}
				}
				if ( is_array( $last_order_ids ) && ! empty( $last_order_ids ) ) {
					$last_order = wc_get_order( $last_order_ids[0] );
					if ( $last_order instanceof \WC_Order ) {
						$data['wc_last_order_date'] = $last_order->get_date_created() ? $last_order->get_date_created()->format( 'Y-m-d' ) : '';
					}
				}
			}
		}

		// For guests, calculate order stats from orders.
		if ( $is_guest && ! empty( $data['billing_email'] ) ) {
			$order_ids = wc_get_orders(
				array(
					'billing_email' => $data['billing_email'],
					'customer_id'   => 0,
					'limit'         => -1,
					'return'        => 'ids',
				)
			);

			if ( is_array( $order_ids ) && ! empty( $order_ids ) ) {
				$order_count = count( $order_ids );

				// Calculate total spent (iterate required — WC has no aggregate API).
				$total_spent = 0;
				foreach ( $order_ids as $order_id ) {
					$o = wc_get_order( $order_id );
					if ( $o instanceof \WC_Order ) {
						$total_spent += (float) $o->get_total();
					}
				}

				$data['wc_total_spent']         = $total_spent;
				$data['wc_order_count']         = $order_count;
				$data['wc_average_order_value'] = $order_count > 0 ? $total_spent / $order_count : 0;

				// First/last order dates via targeted queries.
				$first_order_ids = wc_get_orders(
					array(
						'billing_email' => $data['billing_email'],
						'customer_id'   => 0,
						'limit'         => 1,
						'orderby'       => 'date',
						'order'         => 'ASC',
						'return'        => 'ids',
					)
				);
				$last_order_ids  = wc_get_orders(
					array(
						'billing_email' => $data['billing_email'],
						'customer_id'   => 0,
						'limit'         => 1,
						'orderby'       => 'date',
						'order'         => 'DESC',
						'return'        => 'ids',
					)
				);

				if ( is_array( $first_order_ids ) && ! empty( $first_order_ids ) ) {
					$first_order = wc_get_order( $first_order_ids[0] );
					if ( $first_order instanceof \WC_Order ) {
						$data['wc_first_order_date'] = $first_order->get_date_created() ? $first_order->get_date_created()->format( 'Y-m-d' ) : '';
					}
				}
				if ( is_array( $last_order_ids ) && ! empty( $last_order_ids ) ) {
					$last_order = wc_get_order( $last_order_ids[0] );
					if ( $last_order instanceof \WC_Order ) {
						$data['wc_last_order_date'] = $last_order->get_date_created() ? $last_order->get_date_created()->format( 'Y-m-d' ) : '';
					}
				}
			}
		}

		return $data;
	}

	/**
	 * Apply global remove actions for a contact.
	 *
	 * Reads remove_lists/remove_tags from global settings and applies them.
	 *
	 * @since 1.2.0
	 *
	 * @param string $contact_id Contact UUID.
	 * @return void
	 */
	private function apply_global_remove_actions( $contact_id ) {
		// Only apply global remove actions if global settings are enabled.
		if ( ! $this->is_global_enabled() ) {
			return;
		}

		$remove_lists = $this->get_setting( 'remove_lists', array() );
		$remove_tags  = $this->get_setting( 'remove_tags', array() );

		$this->apply_remove_actions_with_config( $contact_id, $remove_lists, $remove_tags );
	}

	/**
	 * Add registration lists and tags to mapped data.
	 *
	 * @since 0.0.1
	 *
	 * @param array $mapped_data Mapped data array (passed by reference).
	 * @return void
	 */
	private function add_registration_lists_and_tags( &$mapped_data ) {
		// Only apply global lists/tags if global settings are enabled.
		if ( ! $this->is_global_enabled() ) {
			return;
		}

		$global_lists = $this->get_setting( 'add_lists', array() );
		if ( ! empty( $global_lists ) ) {
			$list_uuids = $this->extract_uuids( $global_lists );
			if ( ! empty( $list_uuids ) ) {
				$mapped_data['list_uuids'] = $list_uuids;
			}
		}

		$global_tags = $this->get_setting( 'add_tags', array() );
		if ( ! empty( $global_tags ) ) {
			$tag_uuids = $this->extract_uuids( $global_tags );
			if ( ! empty( $tag_uuids ) ) {
				$mapped_data['tag_uuids'] = $tag_uuids;
			}
		}
	}

	/**
	 * Get automatically generated tags for an order based on products purchased.
	 *
	 * @since 0.0.3
	 *
	 * @param \WC_Order $order Order object.
	 * @return array Array of tag UUIDs to apply.
	 */
	private function get_auto_tags_for_order( $order ) {
		// Only apply auto-tags if global settings are enabled.
		if ( ! $this->is_global_enabled() ) {
			return array();
		}

		$auto_tag_names = array();
		$prefix         = $this->get_setting( 'auto_tag_prefix', '' );

		$auto_tag_categories = $this->get_setting( 'auto_tag_categories', false );
		$auto_tag_products   = $this->get_setting( 'auto_tag_products', false );
		$auto_tag_sku        = $this->get_setting( 'auto_tag_sku', false );

		if ( ! $auto_tag_categories && ! $auto_tag_products && ! $auto_tag_sku ) {
			return array();
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			if ( $auto_tag_products ) {
				$tag_name = $prefix . $product->get_name();
				if ( ! in_array( $tag_name, $auto_tag_names, true ) ) {
					$auto_tag_names[] = $tag_name;
				}
			}

			if ( $auto_tag_sku && $product->get_sku() ) {
				$tag_name = $prefix . $product->get_sku();
				if ( ! in_array( $tag_name, $auto_tag_names, true ) ) {
					$auto_tag_names[] = $tag_name;
				}
			}

			if ( $auto_tag_categories ) {
				// For variations, get the parent product ID since categories are assigned to the parent product.
				$product_id_for_categories = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
				$terms                     = get_the_terms( $product_id_for_categories, 'product_cat' );

				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$tag_name = $prefix . $term->name;
						if ( ! in_array( $tag_name, $auto_tag_names, true ) ) {
							$auto_tag_names[] = $tag_name;
						}
					}
				}
			}
		}

		// Convert tag names to UUIDs (create tags if they don't exist).
		return $this->convert_tag_names_to_uuids( $auto_tag_names );
	}

	/**
	 * Convert tag names to UUIDs, creating tags in CRM if they don't exist.
	 *
	 * @since 0.0.3
	 *
	 * @param array $tag_names Array of tag names.
	 * @return array Array of tag UUIDs.
	 */
	private function convert_tag_names_to_uuids( $tag_names ) {
		if ( empty( $tag_names ) ) {
			return array();
		}

		$tag_uuids    = array();
		$cached_tags  = Synced_Metadata::get_tags();
		$tags_to_find = array();

		// First, check metadata cache for existing tags.
		foreach ( $tag_names as $tag_name ) {
			$found_in_cache = false;

			foreach ( $cached_tags as $cached_tag ) {
				$cached_name = is_array( $cached_tag ) && isset( $cached_tag['name'] ) ? $cached_tag['name'] : '';
				if ( strcasecmp( $cached_name, $tag_name ) === 0 ) {
					// Tag found in cache.
					$tag_uuid = is_array( $cached_tag ) && isset( $cached_tag['uuid'] ) ? $cached_tag['uuid'] : null;
					if ( $tag_uuid ) {
						$tag_uuids[]    = $tag_uuid;
						$found_in_cache = true;
						break;
					}
				}
			}

			// If not found in cache, add to list for API lookup.
			if ( ! $found_in_cache ) {
				$tags_to_find[] = $tag_name;
			}
		}

		// If all tags were found in cache, return early.
		if ( empty( $tags_to_find ) ) {
			return $tag_uuids;
		}

		// For tags not in cache, search CRM and create if needed.
		foreach ( $tags_to_find as $tag_name ) {
			// Search for existing tag in CRM by name.
			$existing = $this->contact_service->search_tags( $tag_name );

			if ( ! is_wp_error( $existing ) && ! empty( $existing['data'] ) ) {
				// Tag exists in CRM - use the first match.
				$first_match = $existing['data'][0];
				if ( ! empty( $first_match['uuid'] ) ) {
					$tag_uuids[] = $first_match['uuid'];

					// Add to cache for future use.
					$this->add_tag_to_metadata_cache( $first_match );
					continue;
				}
			}

			// Tag doesn't exist in CRM - create it.
			$created = $this->contact_service->create_tag( array( 'name' => $tag_name ), array( 'source' => $this->slug ) );

			if ( ! is_wp_error( $created ) && ! empty( $created['data']['uuid'] ) ) {
				$tag_uuids[] = $created['data']['uuid'];

				// Add the newly created tag to the metadata cache.
				$this->add_tag_to_metadata_cache( $created['data'] );
			}
		}

		return $tag_uuids;
	}

	/**
	 * Add a newly created tag to the metadata cache.
	 *
	 * @since 0.0.3
	 *
	 * @param array $tag_data Tag data from API response.
	 * @return void
	 */
	private function add_tag_to_metadata_cache( $tag_data ) {
		if ( empty( $tag_data['uuid'] ) || empty( $tag_data['name'] ) ) {
			return;
		}

		// Get current tags from metadata.
		$cached_tags = Synced_Metadata::get_tags();

		// Check if tag already exists in cache.
		foreach ( $cached_tags as $cached_tag ) {
			if ( isset( $cached_tag['uuid'] ) && $cached_tag['uuid'] === $tag_data['uuid'] ) {
				return; // Tag already in cache.
			}
		}

		// Add the new tag to cache.
		$cached_tags[] = $tag_data;

		// Update the metadata cache.
		Synced_Metadata::set_tags( $cached_tags );
	}

	/**
	 * Apply coupon-specific settings to a contact.
	 *
	 * @since 0.0.3
	 *
	 * @param int    $coupon_id  Coupon ID.
	 * @param string $contact_id Contact UUID.
	 * @param string $event      Event name (e.g., 'applied').
	 * @return bool True if settings were applied, false otherwise.
	 */
	private function apply_coupon_settings( $coupon_id, $contact_id, $event = 'applied' ) {
		if ( empty( $coupon_id ) ) {
			return false;
		}

		$db = $this->get_db();

		$coupon_result = $db->get( $this->slug, (string) $coupon_id, 'coupon', $event );
		if ( ! $this->has_valid_config( $coupon_result ) ) {
			$coupon_result = $db->get( $this->slug, (string) $coupon_id, 'coupon', null );
		}

		if ( ! $this->has_valid_config( $coupon_result ) ) {
			$coupon_result = $db->get( $this->slug, 'all', 'coupon', $event );
			if ( ! $this->has_valid_config( $coupon_result ) ) {
				$coupon_result = $db->get( $this->slug, 'all', 'coupon', null );
			}
		}

		if ( ! $this->has_valid_config( $coupon_result ) || empty( $coupon_result['config'] ) ) {
			return false;
		}

		$config  = $this->merge_config_defaults( $coupon_result['config'] );
		$context = "coupon {$coupon_id}";

		if ( ! empty( $config['add_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $config['add_lists'] );
			if ( ! empty( $list_uuids ) ) {
				$this->contact_service->attach_lists_to_contact( $contact_id, $list_uuids, array( 'source' => $this->slug ) );
			}
		}

		if ( ! empty( $config['add_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $config['add_tags'] );
			if ( ! empty( $tag_uuids ) ) {
				$this->contact_service->attach_tags_to_contact( $contact_id, $tag_uuids, array( 'source' => $this->slug ) );
			}
		}

		if ( ! empty( $config['remove_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $config['remove_lists'] );
			if ( ! empty( $list_uuids ) ) {
				$this->contact_service->detach_lists_from_contact( $contact_id, $list_uuids, array( 'source' => $this->slug ) );
			}
		}

		if ( ! empty( $config['remove_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $config['remove_tags'] );
			if ( ! empty( $tag_uuids ) ) {
				$this->contact_service->detach_tags_from_contact( $contact_id, $tag_uuids, array( 'source' => $this->slug ) );
			}
		}

		Logger::info( 'WooCommerce Integration', "Applied settings for {$context}" );
		return true;
	}

	/**
	 * Apply coupon-specific settings for an order independently.
	 *
	 * This method applies coupon tags/lists ADDITIVELY (not as part of priority chain).
	 * If the order has multiple coupons, all coupon settings are applied.
	 *
	 * @since 0.0.3
	 *
	 * @param \WC_Order $order      Order object.
	 * @param string    $contact_id Contact UUID.
	 * @param string    $event      Event name (e.g., 'applied').
	 * @return bool True if any coupon settings were applied, false otherwise.
	 */
	private function apply_coupon_settings_for_order( $order, $contact_id, $event = 'applied' ) {
		$coupons = $order->get_coupon_codes();

		if ( empty( $coupons ) ) {
			return false;
		}

		$applied_any = false;

		foreach ( $coupons as $coupon_code ) {
			$coupon    = new \WC_Coupon( $coupon_code );
			$coupon_id = $coupon->get_id();

			if ( $coupon_id ) {
				$applied = $this->apply_coupon_settings( $coupon_id, $contact_id, $event );
				if ( $applied ) {
					$applied_any = true;
				}
			}
		}

		return $applied_any;
	}

	/**
	 * Check if an order has any coupon-specific settings configured.
	 *
	 * This method checks if any of the coupons used in the order have
	 * settings configured in the database, without actually applying them.
	 *
	 * @since 0.0.3
	 *
	 * @param \WC_Order $order Order object.
	 * @return bool True if any coupon has settings configured, false otherwise.
	 */
	private function order_has_coupon_settings( $order ) {
		$coupons = $order->get_coupon_codes();

		if ( empty( $coupons ) ) {
			return false;
		}

		$db = $this->get_db();

		foreach ( $coupons as $coupon_code ) {
			$coupon    = new \WC_Coupon( $coupon_code );
			$coupon_id = $coupon->get_id();

			if ( ! $coupon_id ) {
				continue;
			}

			// Check for specific coupon settings.
			$coupon_result = $db->get( $this->slug, (string) $coupon_id, 'coupon', 'applied' );
			if ( $this->has_valid_config( $coupon_result ) ) {
				return true;
			}

			$coupon_result = $db->get( $this->slug, (string) $coupon_id, 'coupon', null );
			if ( $this->has_valid_config( $coupon_result ) ) {
				return true;
			}

			// Check for "all coupons" settings.
			$all_coupons_result = $db->get( $this->slug, 'all', 'coupon', 'applied' );
			if ( $this->has_valid_config( $all_coupons_result ) ) {
				return true;
			}

			$all_coupons_result = $db->get( $this->slug, 'all', 'coupon', null );
			if ( $this->has_valid_config( $all_coupons_result ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Handle product review submission.
	 *
	 * @since 0.0.3
	 *
	 * @param int        $comment_id Comment ID.
	 * @param int|string $approved   Comment approval status.
	 * @return void
	 */
	public function handle_product_review( $comment_id, $approved ) {
		// Only process if approved immediately (1 or '1').
		if ( '1' !== $approved && 1 !== $approved ) {
			return;
		}

		$this->apply_review_tags( $comment_id );
	}

	/**
	 * Handle product review approval (when review is approved after moderation).
	 *
	 * @since 0.0.3
	 *
	 * @param int    $comment_id Comment ID.
	 * @param string $status     New comment status ('approve', 'spam', 'trash', etc.).
	 * @return void
	 */
	public function handle_product_review_approved( $comment_id, $status ) {
		// Only process when the comment is being approved.
		if ( 'approve' !== $status ) {
			return;
		}

		$this->apply_review_tags( $comment_id );
	}

	/**
	 * Apply review tags to a contact based on a product review comment.
	 *
	 * @since 0.0.3
	 *
	 * @param int $comment_id Comment ID.
	 * @return void
	 */
	private function apply_review_tags( $comment_id ) {
		// Only apply review tags if global settings are enabled.
		if ( ! $this->is_global_enabled() ) {
			return;
		}

		$comment = get_comment( $comment_id );

		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		// Check if this is a product review (post type is 'product' and comment type is 'review' or empty for older reviews).
		$post_type    = get_post_type( (int) $comment->comment_post_ID );
		$comment_type = $comment->comment_type;

		if ( 'product' !== $post_type ) {
			return;
		}

		// WooCommerce sets comment_type to 'review' for product reviews (WP 5.5+), but older reviews may have empty type.
		if ( ! empty( $comment_type ) && 'review' !== $comment_type ) {
			return;
		}

		// Check if we've already processed this review.
		$already_tagged = get_comment_meta( $comment_id, '_surecontact_review_tagged', true );
		if ( 'yes' === $already_tagged ) {
			return;
		}

		$review_tags = $this->get_setting( 'review_tags', array() );
		if ( empty( $review_tags ) ) {
			return;
		}

		// Find contact by email (works for both guests and registered users).
		$email = $comment->comment_author_email;
		if ( empty( $email ) ) {
			return;
		}

		$contact_id = $this->contact_service->find_contact_id_by_email( $email );
		if ( ! $contact_id ) {
			return;
		}

		$tag_uuids = $this->extract_uuids( $review_tags );
		if ( ! empty( $tag_uuids ) ) {
			$result = $this->contact_service->attach_tags_to_contact( $contact_id, $tag_uuids, array( 'source' => $this->slug ) );
			if ( ! is_wp_error( $result ) ) {
				// Mark the review as tagged to prevent duplicate tagging.
				update_comment_meta( $comment_id, '_surecontact_review_tagged', 'yes' );
				Logger::info( 'WooCommerce Integration', "Applied review tags to contact {$contact_id} for review {$comment_id}" );
			}
		}
	}

	/**
	 * Get integration actions for an order based on products.
	 *
	 * Priority: Variation > Product > Product Category > All Products > All Product Categories > Global
	 * Note: Coupons are handled independently (not part of priority chain).
	 *
	 * @since 0.0.3
	 *
	 * @param \WC_Order $order Order object.
	 * @param string    $event Event name ('purchase', 'refund', etc.).
	 * @return array Array with add_lists, add_tags, remove_lists, remove_tags.
	 */
	private function get_integration_actions_for_order( $order, $event = 'purchase' ) {
		$actions = array(
			'add_lists'    => array(),
			'add_tags'     => array(),
			'remove_lists' => array(),
			'remove_tags'  => array(),
		);

		$db = $this->get_db();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();

			if ( $variation_id ) {
				$variation_result = $db->get( $this->slug, (string) $variation_id, 'product', $event );
				if ( ! $this->has_valid_config( $variation_result ) ) {
					$variation_result = $db->get( $this->slug, (string) $variation_id, 'product', null );
				}

				if ( $this->has_valid_config( $variation_result ) && isset( $variation_result['config'] ) ) {
					Logger::info( 'WooCommerce Integration', "Applied settings from Variation: {$variation_id}" );
					return $this->merge_config_defaults( $variation_result['config'] );
				}
			}

			if ( $product_id ) {
				$product_result = $db->get( $this->slug, (string) $product_id, 'product', $event );
				if ( ! $this->has_valid_config( $product_result ) ) {
					$product_result = $db->get( $this->slug, (string) $product_id, 'product', null );
				}

				if ( $this->has_valid_config( $product_result ) && isset( $product_result['config'] ) ) {
					Logger::info( 'WooCommerce Integration', "Applied settings from Product: {$product_id}" );
					return $this->merge_config_defaults( $product_result['config'] );
				}
			}
		}

		// Check product category rules.
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();
			$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

			if ( is_wp_error( $categories ) || empty( $categories ) ) {
				continue;
			}

			foreach ( $categories as $category_id ) {
				$category_result = $db->get( $this->slug, (string) $category_id, 'product_category', $event );
				if ( ! $this->has_valid_config( $category_result ) ) {
					$category_result = $db->get( $this->slug, (string) $category_id, 'product_category', null );
				}

				if ( $this->has_valid_config( $category_result ) && isset( $category_result['config'] ) ) {
					Logger::info( 'WooCommerce Integration', "Applied settings from Product Category: {$category_id}" );
					return $this->merge_config_defaults( $category_result['config'] );
				}
			}
		}

		$all_products_result = $db->get( $this->slug, 'all', 'product', $event );
		if ( ! $this->has_valid_config( $all_products_result ) ) {
			$all_products_result = $db->get( $this->slug, 'all', 'product', null );
		}

		if ( $this->has_valid_config( $all_products_result ) && isset( $all_products_result['config'] ) ) {
			Logger::info( 'WooCommerce Integration', 'Applied settings from "All Products"' );
			return $this->merge_config_defaults( $all_products_result['config'] );
		}

		// Check "All Product Categories" rule.
		$all_categories_result = $db->get( $this->slug, 'all', 'product_category', $event );
		if ( ! $this->has_valid_config( $all_categories_result ) ) {
			$all_categories_result = $db->get( $this->slug, 'all', 'product_category', null );
		}

		if ( $this->has_valid_config( $all_categories_result ) && isset( $all_categories_result['config'] ) ) {
			Logger::info( 'WooCommerce Integration', 'Applied settings from "All Product Categories"' );
			return $this->merge_config_defaults( $all_categories_result['config'] );
		}

		// Only apply global settings for purchase events.
		if ( $event === 'purchase' && $this->is_global_enabled() ) {
			$global_config = array(
				'add_lists'    => $this->get_setting( 'add_lists', array() ),
				'add_tags'     => $this->get_setting( 'add_tags', array() ),
				'remove_lists' => $this->get_setting( 'remove_lists', array() ),
				'remove_tags'  => $this->get_setting( 'remove_tags', array() ),
			);

			if ( $this->is_config_not_empty( $global_config ) ) {
				Logger::info( 'WooCommerce Integration', 'Applied settings from Global Settings' );
				return $this->merge_config_defaults( $global_config );
			}
		}

		return $actions;
	}

	/**
	 * Get WooCommerce products list.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of product items.
	 */
	public function get_products() {
		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Products', 'surecontact' ),
				'type'  => 'product',
			),
		);

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$products = get_posts( $args );

		if ( empty( $products ) ) {
			return $items;
		}

		foreach ( $products as $product_post ) {
			$product = wc_get_product( $product_post->ID );

			if ( ! $product ) {
				continue;
			}

			$items[] = array(
				'id'    => $product->get_id(),
				'title' => $product->get_name(),
				'type'  => 'product',
			);

			if ( $product->is_type( 'variable' ) && $product instanceof \WC_Product_Variable ) {
				$variations = $product->get_available_variations();

				foreach ( $variations as $variation_data ) {
					if ( ! is_array( $variation_data ) || ! isset( $variation_data['variation_id'] ) ) {
						continue;
					}
					$variation = wc_get_product( $variation_data['variation_id'] );

					if ( ! $variation instanceof \WC_Product_Variation ) {
						continue;
					}

					// Get the full variation title (includes parent name and attributes).
					$variation_title = $this->build_variation_title( $variation );

					$items[] = array(
						'id'    => $variation->get_id(),
						'title' => $variation_title,
						'type'  => 'product',
					);
				}
			}
		}

		return $items;
	}

	/**
	 * Build variation title.
	 *
	 * @since 0.0.3
	 *
	 * @param \WC_Product_Variation $variation Variation object.
	 * @return string Variation title.
	 */
	private function build_variation_title( $variation ) {
		// Use the variation's formatted name if available.
		$variation_name = $variation->get_name();

		// If the variation name is empty or same as parent, use the variation description.
		if ( empty( $variation_name ) ) {
			$variation_name = $variation->get_description();
		}

		// If still empty, fall back to ID.
		if ( empty( $variation_name ) ) {
			$variation_name = '#' . $variation->get_id();
		}

		return $variation_name;
	}

	/**
	 * Get WooCommerce product categories list.
	 *
	 * @since 1.4.0
	 *
	 * @return array Array of product category items.
	 */
	public function get_product_categorys() {
		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Product Categories', 'surecontact' ),
				'type'  => 'product_category',
			),
		);

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return $items;
		}

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$items[] = array(
				'id'    => $term->term_id,
				'title' => $term->name,
				'type'  => 'product_category',
			);
		}

		return $items;
	}

	/**
	 * Get WooCommerce coupons list.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of coupon items.
	 */
	public function get_coupons() {
		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Coupons', 'surecontact' ),
				'type'  => 'coupon',
			),
		);

		$args = array(
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$coupons = get_posts( $args );

		if ( empty( $coupons ) ) {
			return $items;
		}

		foreach ( $coupons as $coupon_post ) {
			$items[] = array(
				'id'    => $coupon_post->ID,
				'title' => $coupon_post->post_title,
				'type'  => 'coupon',
			);
		}

		return $items;
	}

	/**
	 * Get item title by type and ID.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id   Item ID (product, variation, or coupon ID).
	 * @param string $item_type Item type ('product' or 'coupon').
	 * @return string|null Item title or null if not found.
	 */
	public function get_item_title( $item_id, $item_type ) {
		if ( 'all' === $item_id ) {
			if ( 'product' === $item_type ) {
				return __( 'All Products', 'surecontact' );
			} elseif ( 'product_category' === $item_type ) {
				return __( 'All Product Categories', 'surecontact' );
			} elseif ( 'coupon' === $item_type ) {
				return __( 'All Coupons', 'surecontact' );
			}
			return null;
		}

		if ( 'product' === $item_type ) {
			$product = wc_get_product( $item_id );

			if ( ! $product ) {
				return null;
			}

			if ( $product->is_type( 'variation' ) && $product instanceof \WC_Product_Variation ) {
				// build_variation_title() already includes the parent name and attributes.
				return $this->build_variation_title( $product );
			}

			return $product->get_name();
		} elseif ( 'product_category' === $item_type ) {
			$term = get_term( (int) $item_id, 'product_cat' );

			if ( ! $term instanceof \WP_Term ) {
				return null;
			}

			return $term->name;
		} elseif ( 'coupon' === $item_type ) {
			$coupon = get_post( (int) $item_id );

			if ( ! $coupon instanceof \WP_Post || 'shop_coupon' !== $coupon->post_type ) {
				return null;
			}

			return $coupon->post_title;
		}

		return null;
	}

	/**
	 * Get WooCommerce sync types
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of sync type definitions.
	 */
	public function get_sync_types() {
		// Merge sync types from dedicated handlers.
		return array_merge(
			$this->customer_sync->get_sync_types(),
			$this->order_sync->get_sync_types()
		);
	}
}
