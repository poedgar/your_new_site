<?php
/**
 * Easy Digital Downloads Integration
 *
 * Handles Easy Digital Downloads customer contact information synchronization and order purchase tracking
 *
 * @since 0.0.1
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
 * Class EDD_Integration
 *
 * Integrates Easy Digital Downloads with SureContact for order purchase tracking
 *
 * @since 0.0.1
 */
class EDD_Integration extends Base_Integration {

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
	 * @var EDD_Order_Sync
	 */
	private $order_sync;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		$this->slug                  = 'easy_digital_downloads';
		$this->name                  = 'Easy Digital Downloads';
		$this->description           = __( 'Sync Easy Digital Downloads customer contact information and track order purchases', 'surecontact' );
		$this->docs_url              = '';
		$this->require_field_mapping = false;
		$this->dependency            = 'Easy_Digital_Downloads';

		parent::__construct();

		// Initialize Ecommerce API and order sync handler.
		$this->ecommerce_api = new Ecommerce_API();
		$this->order_sync    = new EDD_Order_Sync( $this, $this->ecommerce_api );

		// Register sync types (base class adds integration metadata).
		add_filter( 'surecontact_available_sync_types', array( $this, 'register_sync_type' ) );
	}

	/**
	 * Get integration-specific settings fields
	 *
	 * @since 0.0.1
	 *
	 * @return array Settings fields configuration
	 */
	public function get_settings_fields() {
		// Get payment statuses.
		$payment_statuses = edd_get_payment_statuses();

		$settings = array();

		// === Order Tracking Settings ===
		$settings['track_orders'] = array(
			'label'       => __( 'Track Order Data', 'surecontact' ),
			'description' => __( 'Send detailed order information (products, amounts, dates) to SureContact for revenue tracking and analytics', 'surecontact' ),
			'type'        => 'checkbox',
			'default'     => true,
		);

		$settings['track_refunds'] = array(
			'label'       => __( 'Track Refunds', 'surecontact' ),
			'description' => __( 'Send refund information to SureContact when payments are refunded', 'surecontact' ),
			'type'        => 'checkbox',
			'default'     => true,
		);

		// === Contact Sync Settings ===
		$settings['sync_guest_customers'] = array(
			'label'       => __( 'Sync Guest Customers', 'surecontact' ),
			'description' => __( 'Create contacts in SureContact for guest checkout customers (customers without WordPress accounts)', 'surecontact' ),
			'type'        => 'checkbox',
			'default'     => true,
		);

		// === Default Lists & Tags (Applied on Every Order) ===
		$settings = array_merge( $settings, self::get_standard_list_tag_fields() );

		// === Abandoned Cart Settings ===
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

		// === Status-Specific Lists ===
		// Add a list selector field for each payment status
		foreach ( $payment_statuses as $status_key => $status_label ) {
			$settings[ 'payment_status_trigger_' . $status_key ] = array(
				// translators: %s is the payment status label.
				'label'       => sprintf( __( 'Lists for "%s" Status', 'surecontact' ), $status_label ),
				// translators: %s is the payment status label in lowercase.
				'description' => sprintf( __( 'Additional lists to add contacts to when payment status is %s (combined with default lists above)', 'surecontact' ), strtolower( $status_label ) ),
				'type'        => 'list-select',
				'default'     => array(),
			);
		}

		return $settings;
	}

	/**
	 * Get all available item types for EDD.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of item type definitions with 'key' and 'label' keys.
	 */
	public function get_item_types() {
		return array(
			array(
				'key'   => 'download',
				'label' => __( 'Download', 'surecontact' ),
			),
			array(
				'key'   => 'discount',
				'label' => __( 'Discount Code', 'surecontact' ),
			),
		);
	}

	/**
	 * Get available events for a specific item type.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_type Item type (e.g., 'download').
	 * @return array Array of event definitions with 'key' and 'label' keys.
	 */
	public function get_events_by_item_type( $item_type ) {
		switch ( $item_type ) {
			case 'download':
				return array(
					array(
						'key'   => 'purchase',
						'label' => __( 'Purchase', 'surecontact' ),
					),
					array(
						'key'   => 'refund',
						'label' => __( 'Refund', 'surecontact' ),
					),
				);

			case 'discount':
				return array(
					array(
						'key'   => 'discount_applied',
						'label' => __( 'Discount Applied', 'surecontact' ),
					),
				);

			default:
				return array();
		}
	}

	/**
	 * Get item-specific configuration fields for an EDD download.
	 *
	 * This method returns the configuration fields that will be shown in the UI
	 * when a specific download is selected.
	 *
	 * @since 0.0.3
	 *
	 * @param string      $item_id Item ID (download ID).
	 * @param string|null $event   Event name ('purchase' or 'refund').
	 * @return array Configuration fields schema.
	 */
	public function get_item_config_fields( $item_id, $event = null ) {
		// Return common configuration fields for downloads.
		return self::get_standard_list_tag_fields();
	}

	/**
	 * Get EDD downloads list.
	 *
	 * This method is called by the Integration Rules API when fetching items.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of download items.
	 */
	public function get_downloads() {
		$items = array();

		// Add "All Downloads" as the first item (catch-all rule).
		$items[] = array(
			'id'    => 'all',
			'title' => __( 'All Downloads', 'surecontact' ),
			'type'  => 'download',
		);

		$downloads = get_posts(
			array(
				'post_type'      => 'download',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $downloads ) ) {
			return $items;
		}

		foreach ( $downloads as $download ) {
			if ( ! $download instanceof \WP_Post ) {
				continue;
			}

			$items[] = array(
				'id'    => $download->ID,
				'title' => $download->post_title,
				'type'  => 'download',
			);

			// Check if download has variable pricing.
			if ( edd_has_variable_prices( $download->ID ) ) {
				$prices = edd_get_variable_prices( $download->ID );
				if ( ! empty( $prices ) && is_array( $prices ) ) {
					foreach ( $prices as $price_id => $price ) {
						$items[] = array(
							'id'    => $download->ID . ':' . $price_id,
							'title' => $download->post_title . ' - ' . $price['name'],
							'type'  => 'download',
						);
					}
				}
			}
		}

		return $items;
	}

	/**
	 * Get EDD discount codes list.
	 *
	 * This method is called by the Integration Rules API when fetching items.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of discount code items.
	 */
	public function get_discounts() {
		$items = array();

		// Add "All Discounts" as the first item (catch-all rule).
		$items[] = array(
			'id'    => 'all',
			'title' => __( 'All Discount Codes', 'surecontact' ),
			'type'  => 'discount',
		);

		// Check if EDD version supports new discount system (3.0+).
		if ( function_exists( 'edd_get_discounts' ) ) {
			$discounts = edd_get_discounts(
				array(
					'status' => array( 'active', 'inactive', 'archived' ),
					'number' => 999,
					'order'  => 'ASC',
				)
			);

			if ( ! empty( $discounts ) ) {
				foreach ( $discounts as $discount ) {
					$items[] = array(
						'id'    => $discount->id,
						'title' => $discount->name . ' (' . $discount->code . ')',
						'type'  => 'discount',
					);
				}
			}
		}

		return $items;
	}

	/**
	 * Get EDD download item fields.
	 *
	 * This method is called by the Integration Rules API to get fields for a specific download.
	 * Note: EDD doesn't have custom fields per download like forms do, so we return empty array.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id Item ID (download ID or download:price_id for variations).
	 * @return array Array of fields with 'id', 'label', and 'type' keys.
	 */
	public function get_item_fields( $item_id ) {
		// EDD doesn't have per-download custom fields like forms do.
		// All customer data comes from checkout fields which are global.
		return array();
	}

	/**
	 * Get the title for a specific item.
	 *
	 * This method is called by the Integration Rules UI to display item titles.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id   Item ID (e.g., 'all', '123', '123:0').
	 * @param string $item_type Item type (e.g., 'download').
	 * @return string|null Item title or null if not found.
	 */
	public function get_item_title( $item_id, $item_type ) {
		// Handle "All Downloads" catch-all.
		if ( 'all' === $item_id ) {
			if ( 'download' === $item_type ) {
				return __( 'All Downloads', 'surecontact' );
			}
			if ( 'discount' === $item_type ) {
				return __( 'All Discount Codes', 'surecontact' );
			}
			return null;
		}

		if ( 'download' === $item_type ) {
			// Check if this is a variation (format: download_id:price_id).
			if ( strpos( $item_id, ':' ) !== false ) {
				$parts       = explode( ':', $item_id );
				$download_id = (int) $parts[0];
				$price_id    = isset( $parts[1] ) ? (int) $parts[1] : null;

				// Get the download.
				$download = get_post( $download_id );
				if ( ! $download instanceof \WP_Post || 'download' !== $download->post_type ) {
					return null;
				}

				// Get variation name.
				if ( $price_id !== null && edd_has_variable_prices( $download_id ) ) {
					$prices = edd_get_variable_prices( $download_id );
					if ( isset( $prices[ $price_id ]['name'] ) ) {
						return $download->post_title . ' - ' . $prices[ $price_id ]['name'];
					}
				}

				return $download->post_title;
			}

			// Regular download (no variation).
			$download = get_post( (int) $item_id );

			if ( ! $download instanceof \WP_Post || 'download' !== $download->post_type ) {
				return null;
			}

			return $download->post_title;
		}

		if ( 'discount' === $item_type ) {
			// Get discount by ID.
			if ( function_exists( 'edd_get_discount' ) ) {
				$discount = edd_get_discount( (int) $item_id );

				if ( $discount instanceof \EDD_Discount && $discount->name ) {
					return $discount->name . ' (' . $discount->code . ')';
				}
			}

			return null;
		}

		return null;
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 0.0.3
	 *
	 * @return void
	 */
	protected function init() {
		// Hook for initial purchase completion - handles contact sync, product/global rules, and order tracking.
		add_action( 'edd_complete_purchase', array( $this, 'handle_purchase_complete' ), 20, 3 );

		// Hook for order status changes - handles status-specific list assignments.
		add_action( 'edd_transition_order_status', array( $this, 'handle_status_change' ), 20, 3 );

		// Hook for refunds - use modern EDD 3.0+ hook.
		add_action( 'edd_refund_order', array( $this, 'handle_payment_refund' ), 20, 3 );

		// Abandoned cart tracking.
		if ( $this->get_setting( 'abandoned_cart_enabled', false ) ) {
			$manager = \SureContact::get_instance()->get_abandoned_cart_manager();
			if ( $manager ) {
				new EDD_Abandoned_Cart( $this, $manager );
			}
		}
	}

	/**
	 * Get integration actions for an order with priority resolution.
	 *
	 * Priority: Variation > Product > All Downloads > Global Settings
	 * Returns the FIRST matching rule configuration (follows WooCommerce pattern).
	 *
	 * @since 0.0.3
	 *
	 * @param \EDD_Order $order EDD Order object.
	 * @param string     $event Event type ('purchase' or 'refund').
	 * @return array Actions with add_lists, add_tags, remove_lists, remove_tags keys.
	 */
	private function get_integration_actions_for_order( $order, $event = 'purchase' ) {
		$actions = array(
			'add_lists'    => array(),
			'add_tags'     => array(),
			'remove_lists' => array(),
			'remove_tags'  => array(),
		);

		$db = $this->get_db();

		// Get order items.
		$order_items = edd_get_order_items(
			array(
				'order_id' => $order->id,
				'number'   => 999,
			)
		);

		if ( empty( $order_items ) ) {
			return $actions;
		}

		// Check each item for rules (return FIRST match).
		foreach ( $order_items as $item ) {
			$download_id = $item->product_id;
			$price_id    = $item->price_id !== null && $item->price_id !== '' ? (int) $item->price_id : null;

			// Priority 1: Check for variation-specific rule (download:price_id).
			if ( $price_id !== null ) {
				$variation_id = $download_id . ':' . $price_id;
				$result       = $db->get( $this->slug, (string) $variation_id, 'download', $event );

				// Fallback: Try without event if event-specific not found.
				if ( ! $this->has_valid_config( $result ) ) {
					$result = $db->get( $this->slug, (string) $variation_id, 'download', null );
				}

				if ( $this->has_valid_config( $result ) && isset( $result['config'] ) ) {
					Logger::info( 'EDD Integration', "Applied settings from Variation: {$variation_id}" );
					return $this->merge_config_defaults( $result['config'] );
				}
			}

			// Priority 2: Check for product-specific rule.
			if ( $download_id ) {
				$result = $db->get( $this->slug, (string) $download_id, 'download', $event );

				// Fallback: Try without event if event-specific not found.
				if ( ! $this->has_valid_config( $result ) ) {
					$result = $db->get( $this->slug, (string) $download_id, 'download', null );
				}

				if ( $this->has_valid_config( $result ) && isset( $result['config'] ) ) {
					Logger::info( 'EDD Integration', "Applied settings from Download: {$download_id}" );
					return $this->merge_config_defaults( $result['config'] );
				}
			}
		}

		// Priority 3: Check for "all downloads" catch-all rule.
		$all_downloads_result = $db->get( $this->slug, 'all', 'download', $event );

		// Fallback: Try without event if event-specific not found.
		if ( ! $this->has_valid_config( $all_downloads_result ) ) {
			$all_downloads_result = $db->get( $this->slug, 'all', 'download', null );
		}

		if ( $this->has_valid_config( $all_downloads_result ) && isset( $all_downloads_result['config'] ) ) {
			Logger::info( 'EDD Integration', 'Applied settings from "All Downloads"' );
			return $this->merge_config_defaults( $all_downloads_result['config'] );
		}

		// Priority 4: Apply global settings (only for purchase events).
		if ( 'purchase' === $event && $this->is_global_enabled() ) {
			$global_config = array(
				'add_lists'    => $this->get_setting( 'add_lists', array() ),
				'add_tags'     => $this->get_setting( 'add_tags', array() ),
				'remove_lists' => $this->get_setting( 'remove_lists', array() ),
				'remove_tags'  => $this->get_setting( 'remove_tags', array() ),
			);

			if ( $this->is_config_not_empty( $global_config ) ) {
				Logger::info( 'EDD Integration', 'Applied settings from Global Settings' );
				return $this->merge_config_defaults( $global_config );
			}
		}

		return $actions;
	}

	/**
	 * Find contact ID by order information
	 *
	 * @since 0.0.1
	 *
	 * @param \EDD_Order $order EDD Order object.
	 * @return string|false|null Contact ID if found, false or null otherwise.
	 */
	private function find_contact_id_by_order( $order ) {
		$user_id = $order->user_id;
		$email   = $order->email;

		// Try by user ID first.
		if ( $user_id > 0 ) {
			$contact_id = $this->contact_service->get_contact_id_by_user( $user_id );
			if ( $contact_id ) {
				return $contact_id;
			}
		}

		// Fallback to email lookup.
		if ( ! empty( $email ) ) {
			return $this->contact_service->find_contact_id_by_email( $email );
		}

		return null;
	}

	/**
	 * Track order purchase
	 *
	 * @since 0.0.1
	 *
	 * @param int               $order_id   Order ID.
	 * @param string|false|null $contact_id Contact ID (optional, will be looked up if not provided).
	 * @return void
	 */
	private function track_order_purchase( $order_id, $contact_id = null ) {
		// Check if already tracked.
		$tracked = edd_get_order_meta( $order_id, '_surecontact_order_tracked', true );
		if ( 'yes' === $tracked ) {
			return;
		}

		// Get order.
		$order = edd_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Only look up contact if not already provided.
		if ( ! $contact_id ) {
			$contact_id = $this->find_contact_id_by_order( $order );
		}

		// Prepare order data.
		$order_data = $this->prepare_order_data( $order, $contact_id );

		// Track the purchase.
		$result = $this->ecommerce_api->track_purchase( $order_data, array( 'source' => $this->slug ) );

		if ( ! is_wp_error( $result ) ) {
			// Mark as tracked.
			edd_add_order_meta( $order_id, '_surecontact_order_tracked', 'yes' );
		}
	}

	/**
	 * Prepare order data for tracking
	 *
	 * @since 0.0.1
	 *
	 * @param \EDD_Order        $order      EDD Order object.
	 * @param string|false|null $contact_id Contact ID in CRM.
	 * @return array Order data array
	 */
	public function prepare_order_data( $order, $contact_id = null ) {
		// Prepare products array.
		$products    = array();
		$order_items = edd_get_order_items(
			array(
				'order_id' => $order->id,
				'number'   => 999,
			)
		);

		if ( ! empty( $order_items ) ) {
			foreach ( $order_items as $item ) {
				$products[] = array(
					'product_id' => (string) $item->product_id,
					'name'       => $item->product_name,
					'quantity'   => (int) $item->quantity,
					'price'      => (float) $item->amount,
				);
			}
		}

		// Get discount code.
		$discount_code = '';
		$adjustments   = edd_get_order_adjustments(
			array(
				'object_id'   => $order->id,
				'object_type' => 'order',
				'type'        => 'discount',
			)
		);
		if ( ! empty( $adjustments ) ) {
			$discount_code = $adjustments[0]->description;
		}

		// Prepare order data.
		$purchased_timestamp = strtotime( $order->date_created );
		$order_data          = array(
			'contact_email'   => $order->email,
			'order_id'        => $this->generate_unique_order_id( $order->id, 'EDD' ),
			'total_amount'    => (float) $order->total,
			'currency'        => $order->currency,
			'products'        => $products,
			'coupon_code'     => $discount_code,
			'shipping_amount' => 0,
			'purchased_at'    => gmdate( 'c', $purchased_timestamp !== false ? $purchased_timestamp : time() ),
		);

		return $order_data;
	}

	/**
	 * Handle initial purchase completion
	 * Triggered by edd_complete_purchase - runs ONCE when order is first created
	 *
	 * @since 0.0.3
	 *
	 * @param int                $order_id   Order ID.
	 * @param object|null        $payment    Payment object (legacy).
	 * @param \EDD_Customer|null $customer   Customer object.
	 * @return void
	 */
	public function handle_purchase_complete( $order_id, $payment = null, $customer = null ) {
		// Get order.
		$order = edd_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Skip processing for refund orders.
		if ( 'refund' === $order->type ) {
			return;
		}

		// Prevent duplicate processing.
		$already_processed = edd_get_order_meta( $order_id, '_surecontact_purchase_processed', true );
		if ( 'yes' === $already_processed ) {
			Logger::info( 'EDD Integration', "Order #{$order_id} purchase already processed - skipping duplicate" );
			return;
		}

		// Get product-based rules or global settings.
		$actions            = $this->get_integration_actions_for_order( $order, 'purchase' );
		$has_list_tag_rules = $this->is_config_not_empty( $actions );
		$should_track_order = $this->get_setting( 'track_orders', true ) && $this->is_global_enabled();

		// Check if we have discount code rules.
		$has_discount_rules = $this->has_discount_code_rules( $order );

		// Initialize contact_id.
		$contact_id = null;

		// Sync contact and apply list/tag operations if we have matching rules.
		if ( $has_list_tag_rules ) {
			$contact_id = $this->sync_contact_on_purchase( $order );
			if ( $contact_id ) {
				$this->apply_list_tag_operations( $contact_id, $actions );
			}
		}

		// Process discount codes separately (even if no product rules exist).
		if ( $has_discount_rules ) {
			// Sync contact if not already synced.
			if ( ! $contact_id ) {
				$contact_id = $this->sync_contact_on_purchase( $order );
			}
			if ( $contact_id ) {
				$this->process_discount_codes( $order, $contact_id );
			}
		}

		// Track order purchase if enabled.
		if ( $should_track_order ) {
			$this->track_order_purchase( $order_id, $contact_id );
		}

		// Mark as processed.
		edd_update_order_meta( $order_id, '_surecontact_purchase_processed', 'yes' );
	}

	/**
	 * Handle order status changes
	 * Triggered by edd_transition_order_status - runs when order status changes
	 *
	 * @since 0.0.3
	 *
	 * @param string $old_status Old payment status.
	 * @param string $new_status New payment status.
	 * @param int    $order_id   Order ID.
	 * @return void
	 */
	public function handle_status_change( $old_status, $new_status, $order_id ) {

		if ( ! $this->is_global_enabled() ) {
			return;
		}

		// Get order.
		$order = edd_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Skip processing for refund orders.
		if ( 'refund' === $order->type ) {
			return;
		}

		// Get status-specific lists for the new payment status.
		$status_lists = $this->get_setting( 'payment_status_trigger_' . $new_status, array() );

		// If no status-specific lists configured, skip.
		if ( empty( $status_lists ) ) {
			Logger::info( 'EDD Integration', "No status-specific lists configured for status '{$new_status}' - skipping" );
			return;
		}

		// Get or create contact.
		$contact_id = $this->find_contact_id_by_order( $order );
		if ( $contact_id ) {
			$list_uuids = $this->extract_uuids( $status_lists );
			if ( ! empty( $list_uuids ) ) {
				$result = $this->contact_service->attach_lists_to_contact( $contact_id, $list_uuids, array( 'source' => $this->slug ) );
				if ( ! is_wp_error( $result ) ) {
					Logger::info( 'EDD Integration', "Successfully applied status-specific lists for order #{$order_id} status '{$new_status}'" );
				}
			}
		}
	}

	/**
	 * Sync (create or update) contact on purchase
	 * Syncs customer data to CRM without applying lists/tags
	 * Lists/tags are applied separately by apply_list_tag_operations()
	 *
	 * @since 0.0.3
	 *
	 * @param \EDD_Order $order EDD Order object.
	 * @return string|null Contact ID if synced successfully, null otherwise.
	 */
	private function sync_contact_on_purchase( $order ) {
		$user_id = $order->user_id;
		$email   = $order->email;

		// Check if guest sync is enabled.
		if ( $user_id === 0 && ! $this->get_setting( 'sync_guest_customers', true ) ) {
			return null;
		}

		// Get customer data.
		$customer = edd_get_customer( $order->customer_id );
		if ( ! $customer ) {
			return null;
		}

		// Prepare contact data.
		$crm_data = array(
			'user_email' => $email,
			'first_name' => $customer->name ? explode( ' ', $customer->name )[0] : '',
			'last_name'  => $customer->name && strpos( $customer->name, ' ' ) !== false ? substr( $customer->name, strpos( $customer->name, ' ' ) + 1 ) : '',
			'user_role'  => 'customer',
			'source'     => $user_id > 0 ? 'edd_order' : 'edd_order_guest',
			'user_id'    => $user_id,
		);

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$crm_data['user_login']      = $user->user_login;
				$crm_data['user_registered'] = $user->user_registered;
			}
		} else {
			$crm_data['is_guest'] = true;
		}

		// Sync contact (create or update).
		$result = $this->contact_service->create_contact( $this->normalize_data( $crm_data ), $user_id, array( 'source' => $this->slug ) );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		return $result['contact_id'] ?? null;
	}

	/**
	 * Apply list and tag operations to a contact
	 *
	 * @since 0.0.3
	 *
	 * @param string $contact_id Contact ID.
	 * @param array  $actions    Actions array with add_lists, add_tags, remove_lists, remove_tags.
	 * @return void
	 */
	private function apply_list_tag_operations( $contact_id, $actions ) {
		// Apply add operations.
		if ( ! empty( $actions['add_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $actions['add_lists'] );
			if ( ! empty( $list_uuids ) ) {
				$result = $this->contact_service->attach_lists_to_contact( $contact_id, $list_uuids, array( 'source' => $this->slug ) );
			}
		}

		if ( ! empty( $actions['add_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $actions['add_tags'] );
			if ( ! empty( $tag_uuids ) ) {
				$result = $this->contact_service->attach_tags_to_contact( $contact_id, $tag_uuids, array( 'source' => $this->slug ) );
			}
		}

		// Apply remove operations.
		if ( ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] ) ) {
			$this->apply_remove_actions_with_config( $contact_id, $actions['remove_lists'], $actions['remove_tags'] );
		}
	}

	/**
	 * Check if order has any discount code rules configured.
	 *
	 * @since 0.0.3
	 *
	 * @param \EDD_Order $order EDD Order object.
	 * @return bool True if discount rules exist, false otherwise.
	 */
	private function has_discount_code_rules( $order ) {
		// Get order adjustments (discounts).
		$adjustments = edd_get_order_adjustments(
			array(
				'object_id'   => $order->id,
				'object_type' => 'order',
				'type'        => 'discount',
			)
		);

		if ( empty( $adjustments ) ) {
			return false;
		}

		$db = $this->get_db();

		// Check if any discount code has rules configured.
		foreach ( $adjustments as $adjustment ) {
			$discount_id = $adjustment->type_id;

			if ( empty( $discount_id ) ) {
				continue;
			}

			// Check for specific discount rule.
			$result = $db->get( $this->slug, (string) $discount_id, 'discount', 'discount_applied' );
			if ( ! $this->has_valid_config( $result ) ) {
				$result = $db->get( $this->slug, (string) $discount_id, 'discount', null );
			}

			if ( $this->has_valid_config( $result ) ) {
				return true;
			}

			// Check for "all discounts" catch-all rule.
			$all_discounts_result = $db->get( $this->slug, 'all', 'discount', 'discount_applied' );
			if ( ! $this->has_valid_config( $all_discounts_result ) ) {
				$all_discounts_result = $db->get( $this->slug, 'all', 'discount', null );
			}

			if ( $this->has_valid_config( $all_discounts_result ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Process discount codes used in an order
	 *
	 * @since 0.0.3
	 *
	 * @param \EDD_Order $order      EDD Order object.
	 * @param string     $contact_id Contact ID.
	 * @return void
	 */
	private function process_discount_codes( $order, $contact_id ) {
		// Get order adjustments (discounts).
		$adjustments = edd_get_order_adjustments(
			array(
				'object_id'   => $order->id,
				'object_type' => 'order',
				'type'        => 'discount',
			)
		);

		if ( empty( $adjustments ) ) {
			return;
		}

		$db = $this->get_db();

		// Process each discount code used.
		foreach ( $adjustments as $adjustment ) {
			$discount_id = $adjustment->type_id;

			if ( empty( $discount_id ) ) {
				continue;
			}

			// Priority 1: Check for specific discount rule.
			$result = $db->get( $this->slug, (string) $discount_id, 'discount', 'discount_applied' );

			// Fallback: Try without event if event-specific not found.
			if ( ! $this->has_valid_config( $result ) ) {
				$result = $db->get( $this->slug, (string) $discount_id, 'discount', null );
			}

			if ( $this->has_valid_config( $result ) && isset( $result['config'] ) ) {
				Logger::info( 'EDD Integration', "Applied settings from Discount Code: {$discount_id}" );
				$actions = $this->merge_config_defaults( $result['config'] );
				$this->apply_list_tag_operations( $contact_id, $actions );
				continue;
			}

			// Priority 2: Check for "all discounts" catch-all rule.
			$all_discounts_result = $db->get( $this->slug, 'all', 'discount', 'discount_applied' );

			// Fallback: Try without event if event-specific not found.
			if ( ! $this->has_valid_config( $all_discounts_result ) ) {
				$all_discounts_result = $db->get( $this->slug, 'all', 'discount', null );
			}

			if ( $this->has_valid_config( $all_discounts_result ) && isset( $all_discounts_result['config'] ) ) {
				Logger::info( 'EDD Integration', 'Applied settings from "All Discount Codes"' );
				$actions = $this->merge_config_defaults( $all_discounts_result['config'] );
				$this->apply_list_tag_operations( $contact_id, $actions );
			}
		}
	}


	/**
	 * Handle payment refund
	 *
	 * @since 1.0.0
	 *
	 * @param int  $order_id     Order ID of the original order.
	 * @param int  $refund_id    ID of the new refund object.
	 * @param bool $all_refunded Whether or not the entire order was refunded.
	 * @return void
	 */
	public function handle_payment_refund( $order_id, $refund_id, $all_refunded ) {
		// Get the original order.
		$order = edd_get_order( $order_id );
		if ( empty( $order ) ) {
			Logger::error( 'EDD Integration', sprintf( 'Refund failed: Unable to retrieve order #%d', $order_id ) );
			return;
		}

		// Get the refund object.
		$refund = edd_get_order( $refund_id );
		if ( empty( $refund ) || empty( $refund->total ) ) {
			Logger::error( 'EDD Integration', sprintf( 'Refund failed: Unable to retrieve refund #%d for order #%d', $refund_id, $order_id ) );
			return;
		}

		// Check if we need to do anything (refund rules or tracking).
		$track_refunds = $this->get_setting( 'track_refunds', true );
		$actions       = $this->get_integration_actions_for_order( $order, 'refund' );
		$has_rules     = $this->is_config_not_empty( $actions );

		// Skip if no rules and no tracking.
		if ( ! $has_rules && ! $track_refunds ) {
			Logger::info( 'EDD Integration', "No refund rules and tracking disabled for order #{$order_id} - skipping" );
			return;
		}

		// Only find contact if we have rules to apply.
		$contact_id = null;
		if ( $has_rules ) {
			$contact_id = $this->find_contact_id_by_order( $order );
			if ( $contact_id ) {
				$this->apply_list_tag_operations( $contact_id, $actions );
			}
		}

		// Track refund in ecommerce API if enabled.
		if ( $this->get_setting( 'track_refunds', true ) && $this->is_global_enabled() ) {
			$refund_data = array(
				'order_id'      => $this->generate_unique_order_id( $order->id, 'EDD' ),
				'reason'        => $all_refunded ? __( 'Full refund', 'surecontact' ) : __( 'Partial refund', 'surecontact' ),
				'refund_amount' => (float) abs( $refund->total ), // Refund total is negative in EDD, convert to positive.
				'refunded_at'   => gmdate( 'c' ),
			);

			$result = $this->ecommerce_api->refund_purchase( $refund_data, array( 'source' => $this->slug ) );

		}
	}

	/**
	 * Get EDD sync types
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of sync type definitions.
	 */
	public function get_sync_types() {
		return $this->order_sync->get_sync_types();
	}
}
