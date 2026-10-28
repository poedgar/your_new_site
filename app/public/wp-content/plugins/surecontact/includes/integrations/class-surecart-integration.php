<?php
/**
 * SureCart Integration
 *
 * Handles SureCart customer contact information synchronization and order purchase tracking
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
 * Class SureCart_Integration
 *
 * Integrates SureCart with SureContact for order purchase tracking
 *
 * @since 0.0.1
 */
class SureCart_Integration extends Base_Integration {

	// Use the database helper trait for item-specific configurations.
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
	 * @var SureCart_Order_Sync
	 */
	private $order_sync;

	/**
	 * Checkout IDs already tracked in this request.
	 *
	 * Prevents double-tracking when both checkout_confirmed and purchase_created
	 * fire for the same checkout in a single request.
	 *
	 * @since 1.4.0
	 *
	 * @var array<string, true>
	 */
	private $tracked_checkouts = array();

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		$this->slug        = 'surecart';
		$this->name        = 'SureCart';
		$this->description = __( 'Sync SureCart customer contact information and track order purchases. Note: SureCart automatically creates WordPress accounts for all customers.', 'surecontact' );
		$this->docs_url    = '';
		$this->dependency  = 'SureCart\\Models\\Purchase';

		parent::__construct();

		// Initialize Ecommerce API and order sync handler.
		$this->ecommerce_api = new Ecommerce_API();
		$this->order_sync    = new SureCart_Order_Sync( $this, $this->ecommerce_api );

		// Register sync types (base class adds integration metadata).
		add_filter( 'surecontact_available_sync_types', array( $this, 'register_sync_type' ) );
	}

	/**
	 * Add SureCart field groups
	 *
	 * @since 0.0.1
	 *
	 * @param array $groups Existing field groups.
	 * @return array Modified field groups.
	 */
	public function add_meta_field_group( $groups ) {
		$groups['surecart'] = array(
			'title' => __( 'SureCart', 'surecontact' ),
			'url'   => '',
		);

		return $groups;
	}

	/**
	 * Add SureCart-specific fields
	 *
	 * @since 0.0.1
	 *
	 * @param array $fields Existing meta fields.
	 * @return array Modified meta fields.
	 */
	public function add_meta_fields( $fields ) {
		// SureCart Customer fields (from checkout and customer data).
		$surecart_customer_fields = array(
			'sc_line_1'      => array(
				'label' => __( 'Address Line 1', 'surecontact' ),
				'type'  => 'text',
			),
			'sc_line_2'      => array(
				'label' => __( 'Address Line 2', 'surecontact' ),
				'type'  => 'text',
			),
			'sc_city'        => array(
				'label' => __( 'City', 'surecontact' ),
				'type'  => 'text',
			),
			'sc_state'       => array(
				'label' => __( 'State', 'surecontact' ),
				'type'  => 'text',
			),
			'sc_country'     => array(
				'label' => __( 'Country', 'surecontact' ),
				'type'  => 'text',
			),
			'sc_postal_code' => array(
				'label' => __( 'Postal Code', 'surecontact' ),
				'type'  => 'text',
			),
			'sc_phone'       => array(
				'label' => __( 'Phone', 'surecontact' ),
				'type'  => 'text',
			),
		);

		// Add group to all customer fields.
		foreach ( $surecart_customer_fields as $key => &$config ) {
			$config['group'] = 'surecart';
			$fields[ $key ]  = $config;
		}
		unset( $config );

		// Add custom fields from SureCart forms.
		$custom_fields = $this->get_surecart_custom_fields();

		// Map custom fields to $fields.
		if ( ! empty( $custom_fields ) ) {
			foreach ( $custom_fields as $field_key => $field_label ) {
				// Skip the wp_created_by field.
				if ( 'wp_created_by' === $field_key ) {
					continue;
				}

				$fields[ 'sc_' . $field_key ] = array(
					'label' => is_string( $field_label ) && ! empty( $field_label ) ? $field_label : ucwords( str_replace( '_', ' ', $field_key ) ),
					'type'  => 'text',
					'group' => 'surecart',
				);
			}
		}

		return $fields;
	}

	/**
	 * Loads SureCart custom fields by parsing block content from forms.
	 *
	 * @since 0.0.3
	 *
	 * @return array Custom fields.
	 */
	public function get_surecart_custom_fields() {
		// Check cache first.
		$cache_key     = 'surecontact_surecart_custom_fields';
		$custom_fields = get_transient( $cache_key );

		if ( false !== $custom_fields ) {
			return $custom_fields;
		}

		$custom_fields = array();

		// Get all posts that might contain SureCart forms.
		$args = array(
			'post_type'      => 'sc_form',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		);

		$posts = get_posts( $args );

		foreach ( $posts as $post ) {
			// Check if this post contains a SureCart form.
			if ( function_exists( 'has_block' ) && has_block( 'surecart/form', $post->post_content ) ) {
				$blocks = parse_blocks( $post->post_content );
				$this->extract_custom_fields_from_blocks( $blocks, $custom_fields );
			}
		}

		// Cache for 1 hour.
		set_transient( $cache_key, $custom_fields, HOUR_IN_SECONDS );

		return $custom_fields;
	}

	/**
	 * Extract custom fields from blocks recursively.
	 *
	 * @since 0.0.3
	 *
	 * @param array $blocks        The blocks to parse.
	 * @param array &$custom_fields The custom fields array to populate.
	 * @return array The custom fields array.
	 */
	private function extract_custom_fields_from_blocks( $blocks, &$custom_fields ) {
		foreach ( $blocks as $block ) {
			// Check for input blocks with custom field names.
			if ( 'surecart/input' === $block['blockName'] && isset( $block['attrs']['name'] ) ) {
				// Skip standard fields.
				if ( ! in_array( $block['attrs']['name'], array( 'email', 'first_name', 'last_name' ), true ) ) {
					$field_name  = $block['attrs']['name'];
					$field_label = isset( $block['attrs']['label'] ) ? $block['attrs']['label'] : ucwords( str_replace( '_', ' ', $field_name ) );

					// Add to custom fields with empty value as placeholder.
					$custom_fields[ $field_name ] = $field_label;
				}
			}

			// Check for checkbox fields.
			if ( 'surecart/checkbox' === $block['blockName'] ) {
				// For checkboxes, the name and content are in the innerHTML.
				if ( isset( $block['innerHTML'] ) ) {
					// Extract name from the innerHTML using regex.
					if ( preg_match( '/name="([^"]*)"/', $block['innerHTML'], $name_matches ) ) {
						$field_name = $name_matches[1];

						// Extract the label (content between opening and closing tags).
						$field_label = '';
						if ( preg_match( '/>([^<]*)<\/sc-checkbox>/', $block['innerHTML'], $label_matches ) ) {
							$field_label = trim( $label_matches[1] );
						}

						// Fallback to name if no label found.
						if ( empty( $field_label ) ) {
							$field_label = ucwords( str_replace( '_', ' ', $field_name ) );
						}

						$custom_fields[ $field_name ] = $field_label;
					}
				}
			}

			// Check for other custom field blocks that might use HTML structure.
			if ( in_array( $block['blockName'], array( 'surecart/textarea', 'surecart/radio', 'surecart/select' ), true ) ) {
				// First try to get from attrs if available.
				if ( isset( $block['attrs']['name'] ) ) {
					$field_name                   = $block['attrs']['name'];
					$field_label                  = isset( $block['attrs']['label'] ) ? $block['attrs']['label'] : ucwords( str_replace( '_', ' ', $field_name ) );
					$custom_fields[ $field_name ] = $field_label;
				}

				// Otherwise try to extract from innerHTML like with checkboxes.
				if ( ! isset( $block['attrs']['name'] ) && isset( $block['innerHTML'] ) ) {
					$tag_name = str_replace( 'surecart/', 'sc-', $block['blockName'] );

					// Extract name from the innerHTML using regex.
					if ( preg_match( '/name="([^"]*)"/', $block['innerHTML'], $name_matches ) ) {
						$field_name = $name_matches[1];

						// Extract the label (content between opening and closing tags).
						$field_label = '';
						if ( preg_match( '/>([^<]*)<\/' . $tag_name . '>/', $block['innerHTML'], $label_matches ) ) {
							$field_label = trim( $label_matches[1] );
						}

						// Fallback to name if no label found.
						if ( empty( $field_label ) ) {
							$field_label = ucwords( str_replace( '_', ' ', $field_name ) );
						}

						$custom_fields[ $field_name ] = $field_label;
					}
				}
			}

			// Recursively check inner blocks.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->extract_custom_fields_from_blocks( $block['innerBlocks'], $custom_fields );
			}
		}

		return $custom_fields;
	}

	/**
	 * Get integration-specific global settings fields
	 *
	 * Returns global plugin-level settings only.
	 * Product-specific settings are handled via get_item_config_fields().
	 *
	 * @since 0.0.1
	 *
	 * @return array Settings fields configuration
	 */
	public function get_settings_fields() {
		$tracking_fields = array(
			'track_orders'        => array(
				'label'       => __( 'Track Order Data', 'surecontact' ),
				'description' => __( 'Send detailed order information including subscription renewals to SureContact for revenue tracking and analytics', 'surecontact' ),
				'type'        => 'checkbox',
				'default'     => true,
			),

			'track_refunds'       => array(
				'label'       => __( 'Track Refunds', 'surecontact' ),
				'description' => __( 'Send refund information to SureContact when purchases are refunded', 'surecontact' ),
				'type'        => 'checkbox',
				'default'     => true,
			),

			'track_cancellations' => array(
				'label'       => __( 'Track Cancellations', 'surecontact' ),
				'description' => __( 'Send cancellation information to SureContact when orders or subscriptions are cancelled', 'surecontact' ),
				'type'        => 'checkbox',
				'default'     => true,
			),
		);

		return array_merge( $tracking_fields, self::get_standard_list_tag_fields() );
	}

	/**
	 * Get all available item types for SureCart.
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
				return array(
					array(
						'key'   => 'purchase',
						'label' => __( 'Purchase', 'surecontact' ),
					),
					array(
						'key'   => 'cancellation',
						'label' => __( 'Cancellation', 'surecontact' ),
					),
					array(
						'key'   => 'refund',
						'label' => __( 'Refund', 'surecontact' ),
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
	 * Get item-specific configuration fields for a SureCart product.
	 *
	 * Uses a common structure for all events with event-based nested data.
	 *
	 * @since 0.0.3
	 *
	 * @param string      $item_id Product ID.
	 * @param string|null $event   Event name (not used - kept for compatibility).
	 * @return array Configuration fields schema.
	 */
	public function get_item_config_fields( $item_id, $event = null ) {
		// Return common configuration fields that work for all events.
		return self::get_standard_list_tag_fields();
	}

	/**
	 * Get SureCart item fields.
	 *
	 * For SureCart, we don't return actual form fields since products/coupons
	 * don't have custom fields to map. The configuration fields are handled
	 * by the integration's get_item_config_fields() method.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id Item ID (product or coupon ID) - unused but required for consistency.
	 * @return array Empty array (no mappable fields for products/coupons).
	 */
	public function get_item_fields( $item_id ) {
		// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		// SureCart products and coupons don't have mappable fields.
		// All configuration is handled through config_fields from the integration.
		return array();
	}

	/**
	 * Initialize integration hooks
	 *
	 * Note: Hooks are registered unconditionally. Settings are checked inside the handlers.
	 *
	 * Hook Architecture:
	 * - checkout_confirmed: Fires ONCE per order - handles both revenue tracking AND rules/tags for all products
	 * - subscription_renewed: Fires on subscription renewal - tracks recurring revenue
	 *
	 * @since 0.0.1
	 */
	protected function init() {
		// Hook for checkout confirmed - handles revenue tracking AND rules/tags for all products.
		// This fires once per order, preventing double-counting while still applying product-specific rules.
		add_action( 'surecart/checkout_confirmed', array( $this, 'handle_checkout_confirmed' ), 10, 2 );

		// Hook for purchase created - catches orders paid via manuallyPay() (retry of failed payments).
		// manuallyPay() fires purchase_created but NOT checkout_confirmed, so this is the fallback.
		// Deduplication with checkout_confirmed is handled via $tracked_checkouts.
		add_action( 'surecart/purchase_created', array( $this, 'handle_purchase_created' ), 10, 1 );

		// Hook for subscription renewed - RECURRING REVENUE TRACKING.
		// This tracks subscription renewal payments that would otherwise be missed.
		add_action( 'surecart/subscription_renewed', array( $this, 'handle_subscription_renewed' ), 10, 1 );

		// Hook for subscription period payment retry success.
		// Period::retryPayment() fires this model event but NOT subscription_renewed,
		// so failed subscription payments that succeed on retry would be missed without this.
		add_action( 'surecart/models/period/payment_retry_success', array( $this, 'handle_period_payment_retry_success' ), 10, 1 );

		// Hook for refund created (when refund is created from admin).
		add_action( 'surecart/models/refund/created', array( $this, 'handle_refund_created' ), 10, 1 );

		// Hook for checkout cancelled (when order is cancelled from admin).
		add_action( 'surecart/models/checkout/cancelled', array( $this, 'handle_checkout_cancelled' ), 10, 1 );
	}

	/**
	 * Handle checkout confirmed
	 *
	 * This fires ONCE per order and handles both:
	 * 1. Revenue tracking (order totals, products, etc.)
	 * 2. Rules/tags application for all products in the order
	 *
	 * @since 1.0.0
	 *
	 * @param \SureCart\Models\Checkout $checkout Checkout object.
	 * @param \WP_REST_Request          $request  Request object.
	 * @return void
	 */
	public function handle_checkout_confirmed( $checkout, $request ) {
		// Fetch checkout with all required relationships.
		$checkout_id = $checkout->id;
		if ( empty( $checkout_id ) ) {
			Logger::error( 'SureCart Integration', 'No checkout ID found' );
			return;
		}

		$checkout = \SureCart\Models\Checkout::with(
			array(
				'purchases',
				'purchases.product',
				'purchases.price',
				'purchases.variant',
				'customer',
				'customer.shipping_address',
				'discount',
				'discount.promotion',
				'discount.promotion.coupon',
			)
		)->find( $checkout_id );

		if ( is_wp_error( $checkout ) ) {
			Logger::error( 'SureCart Integration', 'Failed to fetch checkout: ' . $checkout->get_error_message() );
			return;
		}

		// Skip checkouts that are not paid (e.g. failed payments).
		$checkout_status = $checkout->status ?? '';
		if ( 'paid' !== $checkout_status ) {
			Logger::info( 'SureCart Integration', "Skipping checkout {$checkout_id} with status: {$checkout_status}" );
			return;
		}

		// Mark as tracked to prevent double-tracking from purchase_created.
		$this->tracked_checkouts[ $checkout_id ] = true;

		// Process rules/tags for all products in the checkout.
		$this->process_checkout_rules( $checkout );

		// Track revenue (settings checked inside).
		$this->track_order_from_checkout( $checkout );
	}

	/**
	 * Handle purchase created
	 *
	 * Catches orders paid via manuallyPay() where checkout_confirmed does NOT fire.
	 * This is the fallback for retried/manually-paid failed orders.
	 *
	 * @since 1.4.0
	 *
	 * @param \SureCart\Models\Purchase $purchase Purchase object.
	 * @return void
	 */
	public function handle_purchase_created( $purchase ) {
		// Get the checkout/order ID from the purchase.
		// SureCart's initial_order field holds the checkout ID (string) or expanded object.
		$initial_order = $purchase->initial_order ?? null;
		if ( empty( $initial_order ) ) {
			return;
		}

		// initial_order may be an ID string or an expanded object.
		$checkout_id = is_object( $initial_order ) ? ( $initial_order->id ?? '' ) : (string) $initial_order;
		if ( empty( $checkout_id ) ) {
			return;
		}

		// Skip if checkout_confirmed already tracked this checkout in the same request.
		if ( ! empty( $this->tracked_checkouts[ $checkout_id ] ) ) {
			return;
		}

		// Fetch checkout with all relationships (same as handle_checkout_confirmed).
		$checkout = \SureCart\Models\Checkout::with(
			array(
				'purchases',
				'purchases.product',
				'purchases.price',
				'purchases.variant',
				'customer',
				'customer.shipping_address',
				'discount',
				'discount.promotion',
				'discount.promotion.coupon',
			)
		)->find( $checkout_id );

		if ( is_wp_error( $checkout ) ) {
			return;
		}

		// Only track paid checkouts.
		$checkout_status = $checkout->status ?? '';
		if ( 'paid' !== $checkout_status ) {
			return;
		}

		// Mark as tracked.
		$this->tracked_checkouts[ $checkout_id ] = true;

		Logger::info( 'SureCart Integration', "Tracking order from purchase_created (payment retry): {$checkout_id}" );

		// Process rules/tags for all products in the checkout.
		$this->process_checkout_rules( $checkout );

		// Track revenue (settings checked inside).
		$this->track_order_from_checkout( $checkout );
	}

	/**
	 * Handle subscription period payment retry success
	 *
	 * Fires when Period::retryPayment() succeeds. This catches subscription
	 * renewal payments that failed initially but succeeded on retry.
	 * subscription_renewed does NOT fire for retried payments.
	 *
	 * @since 1.4.0
	 *
	 * @param \SureCart\Models\Period $period Period object.
	 * @return void
	 */
	public function handle_period_payment_retry_success( $period ) {
		// Check if global tracking is enabled.
		if ( ! $this->is_global_enabled() ) {
			return;
		}

		// Check if order tracking is enabled.
		if ( ! $this->get_setting( 'track_orders', true ) ) {
			return;
		}

		$period_id = $period->id ?? '';
		if ( empty( $period_id ) ) {
			return;
		}

		// Prevent duplicate tracking.
		$cache_key = 'surecontact_surecart_renewal_' . $period_id;
		if ( get_transient( $cache_key ) ) {
			Logger::info( 'SureCart Integration', "Period payment retry already tracked: {$period_id}" );
			return;
		}

		// The model event passes the Period object filled with the raw API response.
		// The checkout field may be a string ID (not expanded) or an expanded object.
		// Extract the checkout ID and fetch it directly via Checkout::with()->find().
		$checkout_raw = $period->checkout ?? null;
		if ( empty( $checkout_raw ) ) {
			Logger::error( 'SureCart Integration', "No checkout found on period for payment retry: {$period_id}" );
			return;
		}

		$checkout_id = is_object( $checkout_raw ) ? ( $checkout_raw->id ?? '' ) : (string) $checkout_raw;
		if ( empty( $checkout_id ) ) {
			Logger::error( 'SureCart Integration', "No checkout ID found for period payment retry: {$period_id}" );
			return;
		}

		// Fetch the full checkout with relationships (same pattern as handle_checkout_confirmed).
		$checkout = \SureCart\Models\Checkout::with(
			array(
				'purchases',
				'purchases.product',
				'purchases.price',
				'purchases.variant',
				'customer',
				'customer.shipping_address',
				'discount',
				'discount.promotion',
				'discount.promotion.coupon',
			)
		)->find( $checkout_id );

		if ( is_wp_error( $checkout ) ) {
			Logger::error( 'SureCart Integration', "Failed to fetch checkout for period payment retry {$period_id}: " . $checkout->get_error_message() );
			return;
		}

		// Verify the checkout is actually paid.
		$checkout_status = $checkout->status ?? '';
		if ( 'paid' !== $checkout_status ) {
			Logger::info( 'SureCart Integration', "Skipping period payment retry {$period_id} — checkout status: {$checkout_status}" );
			return;
		}

		// Get renewal amount from checkout.
		$amount_due = $checkout->amount_due ?? 0;
		$currency   = strtoupper( $checkout->currency ?? 'USD' );

		if ( class_exists( '\SureCart\Support\Currency' ) && \SureCart\Support\Currency::isZeroDecimal( $currency ) ) {
			$renewal_amount = (float) $amount_due;
		} else {
			$renewal_amount = (float) $amount_due / 100;
		}

		// Get customer email.
		$customer = $checkout->customer ?? null;
		$email    = '';
		if ( $customer && is_object( $customer ) ) {
			$email = $customer->email ?? '';
		}

		if ( empty( $email ) ) {
			Logger::error( 'SureCart Integration', "No email found for period payment retry: {$period_id}" );
			return;
		}

		if ( $renewal_amount <= 0 ) {
			Logger::warning( 'SureCart Integration', "Zero or negative amount for period payment retry: {$period_id}" );
			return;
		}

		// Set transient to prevent duplicate tracking.
		set_transient( $cache_key, true, DAY_IN_SECONDS );

		// Track as a full order with products (using the hydrated checkout).
		$this->track_order_from_checkout( $checkout );
	}

	/**
	 * Process rules/tags for all products in a checkout
	 *
	 * Iterates through all purchases and applies product-specific rules.
	 *
	 * @since 1.0.0
	 *
	 * @param \SureCart\Models\Checkout $checkout Hydrated checkout object.
	 * @return void
	 */
	private function process_checkout_rules( $checkout ) {
		// Get purchases from checkout.
		$purchases_data = array();
		if ( isset( $checkout->purchases ) ) {
			if ( is_object( $checkout->purchases ) && isset( $checkout->purchases->data ) ) {
				$purchases_data = $checkout->purchases->data;
			} elseif ( is_array( $checkout->purchases ) ) {
				$purchases_data = isset( $checkout->purchases['data'] ) ? $checkout->purchases['data'] : $checkout->purchases;
			}
		}

		if ( empty( $purchases_data ) ) {
			Logger::info( 'SureCart Integration', 'No purchases found in checkout for rules processing' );
			return;
		}

		// Get customer data from checkout for contact creation.
		$customer_data = array(
			'email'      => $checkout->email ?? '',
			'first_name' => $checkout->first_name ?? '',
			'last_name'  => $checkout->last_name ?? '',
			'phone'      => $checkout->phone ?? '',
		);

		if ( empty( $customer_data['email'] ) ) {
			Logger::error( 'SureCart Integration', 'No email found in checkout for rules processing' );
			return;
		}

		// Get customer object for shipping address.
		$customer = $checkout->customer ?? null;

		// Collect all actions from all products (merged).
		$merged_actions = array(
			'add_lists'    => array(),
			'add_tags'     => array(),
			'remove_lists' => array(),
			'remove_tags'  => array(),
		);

		// Process each purchase for rules.
		foreach ( $purchases_data as $purchase_item ) {
			$actions = $this->get_actions_for_purchase_item( $purchase_item );

			// Merge actions (avoid duplicates).
			$merged_actions['add_lists']    = array_merge( $merged_actions['add_lists'], $actions['add_lists'] );
			$merged_actions['add_tags']     = array_merge( $merged_actions['add_tags'], $actions['add_tags'] );
			$merged_actions['remove_lists'] = array_merge( $merged_actions['remove_lists'], $actions['remove_lists'] );
			$merged_actions['remove_tags']  = array_merge( $merged_actions['remove_tags'], $actions['remove_tags'] );
		}

		// Remove duplicates.
		$merged_actions['add_lists']    = array_unique( $merged_actions['add_lists'], SORT_REGULAR );
		$merged_actions['add_tags']     = array_unique( $merged_actions['add_tags'], SORT_REGULAR );
		$merged_actions['remove_lists'] = array_unique( $merged_actions['remove_lists'], SORT_REGULAR );
		$merged_actions['remove_tags']  = array_unique( $merged_actions['remove_tags'], SORT_REGULAR );

		// Check for coupon actions.
		$coupon_id = $this->get_coupon_id_from_checkout( $checkout );
		if ( ! empty( $coupon_id ) ) {
			$coupon_config = $this->get_coupon_config( $coupon_id, 'applied' );
			if ( ! empty( $coupon_config ) && isset( $coupon_config['config'] ) ) {
				$coupon_actions                 = $this->merge_config_defaults( $coupon_config['config'] );
				$merged_actions['add_lists']    = array_merge( $merged_actions['add_lists'], $coupon_actions['add_lists'] );
				$merged_actions['add_tags']     = array_merge( $merged_actions['add_tags'], $coupon_actions['add_tags'] );
				$merged_actions['remove_lists'] = array_merge( $merged_actions['remove_lists'], $coupon_actions['remove_lists'] );
				$merged_actions['remove_tags']  = array_merge( $merged_actions['remove_tags'], $coupon_actions['remove_tags'] );
			}
		}

		// Check if any actions to perform.
		$has_actions = ! empty( $merged_actions['add_lists'] ) || ! empty( $merged_actions['add_tags'] )
			|| ! empty( $merged_actions['remove_lists'] ) || ! empty( $merged_actions['remove_tags'] );

		if ( ! $has_actions ) {
			Logger::info( 'SureCart Integration', 'No rules configured for products in this checkout' );
			return;
		}

		// Get or create contact.
		$contact_id = $this->get_or_create_contact_from_checkout(
			$checkout,
			$customer,
			$merged_actions['add_lists'],
			$merged_actions['add_tags']
		);

		if ( ! $contact_id ) {
			Logger::error( 'SureCart Integration', 'Failed to get or create contact for checkout rules' );
			return;
		}

		// Apply remove actions.
		if ( ! empty( $merged_actions['remove_lists'] ) || ! empty( $merged_actions['remove_tags'] ) ) {
			$this->apply_remove_actions( $contact_id, $merged_actions );
		}
	}

	/**
	 * Get actions for a single purchase item
	 *
	 * @since 1.0.0
	 *
	 * @param object|array $purchase_item Purchase item from checkout.
	 * @return array Actions array.
	 */
	private function get_actions_for_purchase_item( $purchase_item ) {
		$actions = array(
			'add_lists'    => array(),
			'add_tags'     => array(),
			'remove_lists' => array(),
			'remove_tags'  => array(),
		);

		// Extract product, variant, and price IDs.
		$product    = is_object( $purchase_item ) ? ( $purchase_item->product ?? null ) : ( $purchase_item['product'] ?? null );
		$variant    = is_object( $purchase_item ) ? ( $purchase_item->variant ?? null ) : ( $purchase_item['variant'] ?? null );
		$price      = is_object( $purchase_item ) ? ( $purchase_item->price ?? null ) : ( $purchase_item['price'] ?? null );
		$product_id = '';
		$variant_id = '';
		$price_id   = '';

		if ( $product ) {
			$product_id = is_object( $product ) ? ( $product->id ?? '' ) : ( is_string( $product ) ? $product : '' );
		}
		if ( $variant ) {
			$variant_id = is_object( $variant ) ? ( $variant->id ?? '' ) : ( is_string( $variant ) ? $variant : '' );
		}
		if ( $price ) {
			if ( is_object( $price ) ) {
				$price_id = $price->id ?? '';
			} elseif ( is_string( $price ) ) {
				$price_id = $price;
			} elseif ( is_array( $price ) ) {
				$price_id = $price['id'] ?? '';
			}
		}

		// Priority 1: Check Variation Settings.
		if ( ! empty( $variant_id ) ) {
			$variation_result = $this->integrations_db->get( $this->slug, $variant_id, 'product', 'purchase' );
			if ( ! $this->has_valid_config( $variation_result ) ) {
				$variation_result = $this->integrations_db->get( $this->slug, $variant_id, 'product', null );
			}
			if ( $this->has_valid_config( $variation_result ) && isset( $variation_result['config'] ) ) {
				return $this->merge_config_defaults( $variation_result['config'] );
			}
		}

		// Priority 2: Check Price Settings.
		if ( ! empty( $price_id ) && is_string( $price_id ) ) {
			$price_result = $this->integrations_db->get( $this->slug, $price_id, 'product', 'purchase' );
			if ( ! $this->has_valid_config( $price_result ) ) {
				$price_result = $this->integrations_db->get( $this->slug, $price_id, 'product', null );
			}
			if ( $this->has_valid_config( $price_result ) && isset( $price_result['config'] ) ) {
				return $this->merge_config_defaults( $price_result['config'] );
			}
		}

		// Priority 3: Check Product Settings.
		if ( ! empty( $product_id ) ) {
			$product_result = $this->integrations_db->get( $this->slug, $product_id, 'product', 'purchase' );
			if ( ! $this->has_valid_config( $product_result ) ) {
				$product_result = $this->integrations_db->get( $this->slug, $product_id, 'product', null );
			}
			if ( $this->has_valid_config( $product_result ) && isset( $product_result['config'] ) ) {
				return $this->merge_config_defaults( $product_result['config'] );
			}
		}

		// Priority 4: Check All Products Settings.
		$all_products_result = $this->integrations_db->get( $this->slug, 'all', 'product', 'purchase' );
		if ( ! $this->has_valid_config( $all_products_result ) ) {
			$all_products_result = $this->integrations_db->get( $this->slug, 'all', 'product', null );
		}
		if ( $this->has_valid_config( $all_products_result ) && isset( $all_products_result['config'] ) ) {
			return $this->merge_config_defaults( $all_products_result['config'] );
		}

		// Priority 5: Global Settings.
		if ( $this->is_global_enabled() ) {
			$global_config = array(
				'add_lists'    => $this->get_setting( 'add_lists', array() ),
				'add_tags'     => $this->get_setting( 'add_tags', array() ),
				'remove_lists' => $this->get_setting( 'remove_lists', array() ),
				'remove_tags'  => $this->get_setting( 'remove_tags', array() ),
			);
			if ( $this->is_config_not_empty( $global_config ) ) {
				return $this->merge_config_defaults( $global_config );
			}
		}

		return $actions;
	}

	/**
	 * Get coupon ID from checkout object
	 *
	 * @since 1.0.0
	 *
	 * @param \SureCart\Models\Checkout $checkout Checkout object.
	 * @return string|null Coupon ID or null.
	 */
	private function get_coupon_id_from_checkout( $checkout ) {
		$discount = $checkout->discount ?? null;
		if ( ! $discount || ! is_object( $discount ) ) {
			return null;
		}

		$promotion = $discount->promotion ?? null;
		if ( ! $promotion || ! is_object( $promotion ) ) {
			return null;
		}

		$coupon = $promotion->coupon ?? null;
		if ( ! $coupon ) {
			return null;
		}

		return is_object( $coupon ) ? ( $coupon->id ?? null ) : ( is_string( $coupon ) ? $coupon : null );
	}

	/**
	 * Get or create contact from checkout data
	 *
	 * @since 1.0.0
	 *
	 * @param \SureCart\Models\Checkout $checkout  Checkout object.
	 * @param object|null               $customer  Customer object.
	 * @param array                     $add_lists Lists to add.
	 * @param array                     $add_tags  Tags to add.
	 * @return string|null Contact ID or null.
	 */
	private function get_or_create_contact_from_checkout( $checkout, $customer, $add_lists = array(), $add_tags = array() ) {
		$email      = $checkout->email ?? '';
		$first_name = $checkout->first_name ?? '';
		$last_name  = $checkout->last_name ?? '';

		if ( empty( $email ) ) {
			return null;
		}

		// Primary fields.
		$primary_fields = array(
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
		);

		// Build raw data for field mapping.
		$raw_data = array();

		if ( ! empty( $checkout->phone ) ) {
			$raw_data['sc_phone'] = $checkout->phone;
		}

		// Get shipping address from customer if available.
		if ( $customer && is_object( $customer ) ) {
			$shipping_address = $customer->shipping_address ?? null;
			if ( $shipping_address && is_object( $shipping_address ) ) {
				$address_attrs = method_exists( $shipping_address, 'getAttributes' )
					? $shipping_address->getAttributes()
					: (array) $shipping_address;

				if ( ! empty( $address_attrs['line_1'] ) ) {
					$raw_data['sc_line_1'] = $address_attrs['line_1'];
				}
				if ( ! empty( $address_attrs['line_2'] ) ) {
					$raw_data['sc_line_2'] = $address_attrs['line_2'];
				}
				if ( ! empty( $address_attrs['city'] ) ) {
					$raw_data['sc_city'] = $address_attrs['city'];
				}
				if ( ! empty( $address_attrs['state'] ) ) {
					$raw_data['sc_state'] = $address_attrs['state'];
				}
				if ( ! empty( $address_attrs['country'] ) ) {
					$raw_data['sc_country'] = $address_attrs['country'];
				}
				if ( ! empty( $address_attrs['postal_code'] ) ) {
					$raw_data['sc_postal_code'] = $address_attrs['postal_code'];
				}
			}
		}

		// Map fields.
		$mapped_sc_data = $this->normalize_data( $raw_data );

		$mapped_data = array(
			'primary_fields' => array_merge(
				$primary_fields,
				$mapped_sc_data['primary_fields'] ?? array()
			),
			'custom_fields'  => $mapped_sc_data['custom_fields'] ?? array(),
			'metadata'       => $mapped_sc_data['metadata'] ?? array(),
		);

		// Add lists and tags.
		if ( ! empty( $add_lists ) ) {
			$mapped_data['list_uuids'] = $this->extract_uuids( $add_lists );
		}
		if ( ! empty( $add_tags ) ) {
			$mapped_data['tag_uuids'] = $this->extract_uuids( $add_tags );
		}

		// Get WP user ID if available.
		$user_id = 0;
		$wp_user = get_user_by( 'email', $email );
		if ( $wp_user ) {
			$user_id = $wp_user->ID;
		}

		// Create or update contact.
		$result = $this->contact_service->create_contact( $mapped_data, $user_id, array( 'source' => $this->slug ) );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		return $result['contact_uuid'] ?? $result['contact_id'] ?? null;
	}

	/**
	 * Handle subscription renewed - RECURRING REVENUE TRACKING
	 *
	 * Tracks subscription renewal payments. This is critical for accurate revenue
	 * tracking of subscription-based products. Without this, only the initial
	 * subscription purchase would be tracked.
	 *
	 * @since 1.0.0
	 *
	 * @param \SureCart\Models\Subscription $subscription Subscription object.
	 * @return void
	 */
	public function handle_subscription_renewed( $subscription ) {
		// Check if global tracking is enabled.
		if ( ! $this->is_global_enabled() ) {
			return;
		}

		// Check if order tracking is enabled (covers subscriptions renewals too).
		if ( ! $this->get_setting( 'track_orders', true ) ) {
			return;
		}

		// Get subscription ID for logging.
		$subscription_id = $subscription->id;

		// Get the purchase ID from subscription.
		$purchase_id = $subscription->purchase ?? null;
		if ( empty( $purchase_id ) ) {
			Logger::error( 'SureCart Integration', "No purchase ID found in subscription: {$subscription_id}" );
			return;
		}

		// Fetch purchase with all required relationships for renewal tracking.
		// Uses 'period.checkout' pattern from SureCart's AffiliateWP integration.
		$purchase = \SureCart\Models\Purchase::with(
			array(
				'initial_order',
				'subscription',
				'subscription.current_period',
				'period.checkout',
				'product',
				'customer',
			)
		)->find( $purchase_id );

		if ( is_wp_error( $purchase ) || ! $purchase ) {
			Logger::error( 'SureCart Integration', "Failed to fetch purchase for subscription renewal: {$subscription_id}" );
			return;
		}

		// Get the current period's checkout (contains renewal payment details).
		$current_period = $purchase->subscription->current_period ?? null;
		$checkout       = $current_period->checkout ?? null;

		if ( ! $checkout || ! is_object( $checkout ) ) {
			Logger::error( 'SureCart Integration', "No checkout found for subscription renewal: {$subscription_id}" );
			return;
		}

		// Skip renewal if payment hasn't succeeded.
		$checkout_status     = $checkout->status ?? '';
		$subscription_status = $subscription->status ?? '';
		if ( 'paid' !== $checkout_status && 'active' !== $subscription_status ) {
			Logger::info( 'SureCart Integration', "Skipping subscription renewal {$subscription_id} — checkout status: {$checkout_status}, subscription status: {$subscription_status}" );
			return;
		}

		// Prevent duplicate tracking using period/checkout ID.
		$period_id = $current_period->id ?? '';
		$cache_key = 'surecontact_surecart_renewal_' . $period_id;
		if ( ! empty( $period_id ) && get_transient( $cache_key ) ) {
			Logger::info( 'SureCart Integration', "Subscription renewal already tracked: {$period_id}" );
			return;
		}

		// Get renewal amount from checkout (amount_due per AffiliateWP pattern).
		// Handle zero-decimal currencies properly.
		$amount_due = $checkout->amount_due ?? 0;
		$currency   = strtoupper( $checkout->currency ?? 'USD' );

		if ( class_exists( '\SureCart\Support\Currency' ) && \SureCart\Support\Currency::isZeroDecimal( $currency ) ) {
			$renewal_amount = (float) $amount_due;
		} else {
			$renewal_amount = (float) $amount_due / 100;
		}

		// Get customer email.
		$customer = $purchase->customer ?? null;
		$email    = '';
		if ( $customer && is_object( $customer ) ) {
			$email = $customer->email ?? '';
		}

		if ( empty( $email ) ) {
			Logger::error( 'SureCart Integration', "No email found for subscription renewal: {$subscription_id}" );
			return;
		}

		// Validate renewal amount.
		if ( $renewal_amount <= 0 ) {
			Logger::warning( 'SureCart Integration', "Zero or negative renewal amount for subscription: {$subscription_id}" );
			return;
		}

		// Get product information.
		$product      = $purchase->product ?? null;
		$product_id   = $product && is_object( $product ) ? ( $product->id ?? '' ) : '';
		$product_name = $product && is_object( $product ) ? ( $product->name ?? 'Subscription Renewal' ) : 'Subscription Renewal';

		// Generate unique order ID for renewal (use same prefix 'SUR' so refunds match correctly).
		$renewal_order_id = $checkout->order ?? $period_id;
		$order_id         = $this->generate_unique_order_id( $renewal_order_id, 'SUR' );

		// Prepare order data for the API.
		$order_data = array(
			'contact_email' => $email,
			'order_id'      => $order_id,
			'total_amount'  => $renewal_amount,
			'currency'      => $currency,
			'products'      => array(
				array(
					'product_id' => (string) $product_id,
					'name'       => $product_name . ' (Renewal)',
					'quantity'   => 1,
					'price'      => $renewal_amount,
				),
			),
			'purchased_at'  => gmdate( 'c' ),
		);

		// Track the renewal.
		$result = $this->ecommerce_api->track_purchase( $order_data, array( 'source' => $this->slug ) );

		if ( ! is_wp_error( $result ) && ! empty( $period_id ) ) {
			// Mark as tracked to prevent duplicates.
			set_transient( $cache_key, true, DAY_IN_SECONDS );
		}
	}

	/**
	 * Handle refund created
	 *
	 * Tracks refund when a Refund object is created (e.g., from admin refund button)
	 * Also applies lists/tags based on refund event settings.
	 * Note: Refund tracking is a global-level feature, only runs when global integration is enabled.
	 *
	 * @since 0.0.1
	 *
	 * @param \SureCart\Models\Refund $refund Refund object.
	 * @return void
	 */
	public function handle_refund_created( $refund ) {
		// Get refund attributes.
		$refund_attributes = $refund->getAttributes();

		// Get the associated charge to find the checkout.
		$charge_id = $refund_attributes['charge'] ?? null;
		if ( empty( $charge_id ) ) {
			Logger::error( 'SureCart Integration', 'No charge ID found in refund, cannot process' );
			return;
		}

		// Fetch the charge with checkout details.
		$charge = \SureCart\Models\Charge::with( array( 'checkout', 'checkout.purchases' ) )->find( $charge_id );

		if ( is_wp_error( $charge ) ) {
			Logger::error( 'SureCart Integration', 'Failed to fetch charge: ' . $charge->get_error_message() );
			return;
		}

		// Get purchase from checkout.
		$checkout = $charge->checkout ?? null;
		if ( empty( $checkout ) ) {
			Logger::error( 'SureCart Integration', 'No checkout found for charge' );
			return;
		}

		// Checkout is returned as stdClass, access purchases directly
		// Purchases come as a paginated list with a 'data' array.
		$purchase_list = null;

		if ( is_object( $checkout->purchases ) ) {
			// Purchases object has a 'data' property.
			if ( isset( $checkout->purchases->data ) && is_array( $checkout->purchases->data ) ) {
				$purchase_list = $checkout->purchases->data;
			}
		} elseif ( is_array( $checkout->purchases ) ) {
			// Purchases might be an array.
			if ( isset( $checkout->purchases['data'] ) ) {
				$purchase_list = $checkout->purchases['data'];
			}
		}

		if ( empty( $purchase_list ) || ! is_array( $purchase_list ) || count( $purchase_list ) === 0 ) {
			Logger::error( 'SureCart Integration', 'No purchases found in checkout data' );
			return;
		}

		// Get the first purchase (usually there's only one).
		$purchase_data = reset( $purchase_list );

		// Extract purchase ID from the data.
		$purchase_id = null;
		if ( is_array( $purchase_data ) && isset( $purchase_data['id'] ) ) {
			$purchase_id = $purchase_data['id'];
		} elseif ( is_object( $purchase_data ) && isset( $purchase_data->id ) ) {
			$purchase_id = $purchase_data->id;
		} elseif ( is_string( $purchase_data ) ) {
			$purchase_id = $purchase_data;
		}

		if ( empty( $purchase_id ) ) {
			Logger::error( 'SureCart Integration', 'Could not extract purchase ID from checkout data' );
			return;
		}

		$purchase = \SureCart\Models\Purchase::with( array( 'customer', 'product', 'initial_order', 'price' ) )->find( $purchase_id );

		if ( is_wp_error( $purchase ) ) {
			Logger::error( 'SureCart Integration', 'Failed to fetch purchase: ' . $purchase->get_error_message() );
			return;
		}

		// Apply refund event lists/tags.
		$contact_id = $this->get_contact_id_from_purchase( $purchase );
		if ( $contact_id ) {
			// Get the refund actions.
			$actions = $this->get_integration_actions( $purchase, 'refund' );

			// Apply the lists and tags configured for refund event.
			if ( ! empty( $actions['add_lists'] ) || ! empty( $actions['add_tags'] ) ) {
				// Apply "add" actions.
				if ( ! empty( $actions['add_lists'] ) ) {
					$list_uuids = $this->extract_uuids( $actions['add_lists'] );
					$this->apply_or_remove_lists( $contact_id, $list_uuids, 'attach' );
				}
				if ( ! empty( $actions['add_tags'] ) ) {
					$tag_uuids = $this->extract_uuids( $actions['add_tags'] );
					$this->apply_or_remove_tags( $contact_id, $tag_uuids, 'apply' );
				}
			}

			// Apply "remove" actions.
			if ( ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] ) ) {
				$this->apply_remove_actions( $contact_id, $actions );
			}
		}

		// Refund tracking is a global-level setting, only run if global integration is enabled.
		if ( ! $this->is_global_enabled() ) {
			return;
		}

		// Check if refund tracking is enabled.
		if ( ! $this->get_setting( 'track_refunds', true ) ) {
			return;
		}

		// Get refund amount (SureCart stores amounts in cents).
		$refund_amount = isset( $refund_attributes['amount'] ) ? (float) $refund_attributes['amount'] / 100 : 0;

		// Get refund timestamp.
		$refunded_at           = isset( $refund_attributes['created_at'] ) ? $refund_attributes['created_at'] : time();
		$refund_timestamp      = is_numeric( $refunded_at ) ? (int) $refunded_at : strtotime( (string) $refunded_at );
		$refunded_at_formatted = gmdate( 'c', false !== $refund_timestamp ? $refund_timestamp : time() );

		// Get order_id from checkout (must match track_order_from_checkout).
		// New format uses checkout.order, legacy format used purchase.id.
		$checkout_id  = is_object( $checkout ) ? $checkout->id : '';
		$order_id_raw = is_object( $checkout ) ? ( $checkout->order ?? $checkout_id ) : $checkout_id;

		// Prepare refund data with new order_id format.
		$refund_data = array(
			'order_id'      => $this->generate_unique_order_id( $order_id_raw, 'SUR' ),
			'reason'        => isset( $refund_attributes['reason'] ) ? $refund_attributes['reason'] : __( 'Order refunded', 'surecontact' ),
			'refund_amount' => $refund_amount,
			'refunded_at'   => $refunded_at_formatted,
		);

		$result = $this->ecommerce_api->refund_purchase( $refund_data, array( 'source' => $this->slug ) );

		// If new format fails with 404/not_found, try legacy format (purchase.id) for backward compatibility.
		if ( is_wp_error( $result ) && $this->is_order_not_found_error( $result ) && ! empty( $purchase_id ) ) {
			Logger::info( 'SureCart Integration', "Order not found with new format, trying legacy format for refund: {$purchase_id}" );

			$refund_data['order_id'] = $this->generate_unique_order_id( $purchase_id, 'SUR' );
			$result                  = $this->ecommerce_api->refund_purchase( $refund_data, array( 'source' => $this->slug ) );
		}

		if ( is_wp_error( $result ) ) {
			return;
		}
	}

	/**
	 * Handle checkout cancelled
	 *
	 * Tracks cancellation when a Checkout/Order is cancelled from admin
	 * Also applies lists/tags based on cancellation event settings.
	 * Note: Cancellation tracking is a global-level feature, only runs when global integration is enabled.
	 *
	 * @since 0.0.1
	 *
	 * @param \SureCart\Models\Checkout $checkout Checkout object.
	 * @return void
	 */
	public function handle_checkout_cancelled( $checkout ) {
		// Get the purchase to apply cancellation lists and tags.
		$purchase = $this->get_purchase_from_checkout( $checkout );

		// Apply cancellation event lists/tags if we found a purchase.
		if ( $purchase && ! is_wp_error( $purchase ) ) {
			$contact_id = $this->get_contact_id_from_purchase( $purchase );
			if ( $contact_id ) {
				// Get the cancellation actions.
				$actions = $this->get_integration_actions( $purchase, 'cancellation' );

				// Apply the lists and tags configured for cancellation event.
				if ( ! empty( $actions['add_lists'] ) || ! empty( $actions['add_tags'] ) ) {
					// Apply "add" actions.
					if ( ! empty( $actions['add_lists'] ) ) {
						$list_uuids = $this->extract_uuids( $actions['add_lists'] );
						$this->apply_or_remove_lists( $contact_id, $list_uuids, 'attach' );
					}
					if ( ! empty( $actions['add_tags'] ) ) {
						$tag_uuids = $this->extract_uuids( $actions['add_tags'] );
						$this->apply_or_remove_tags( $contact_id, $tag_uuids, 'apply' );
					}
				}

				// Apply "remove" actions.
				if ( ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] ) ) {
					$this->apply_remove_actions( $contact_id, $actions );
				}
			}
		}

		// Cancellation tracking is a global-level setting, only run if global integration is enabled.
		if ( ! $this->is_global_enabled() ) {
			return;
		}

		// Check if cancellation tracking is enabled.
		if ( ! $this->get_setting( 'track_cancellations', true ) ) {
			return;
		}

		// Get checkout attributes.
		$checkout_attrs = $checkout->getAttributes();

		// Get the order ID from checkout.
		$order_id = null;
		if ( isset( $checkout_attrs['order'] ) ) {
			if ( is_array( $checkout_attrs['order'] ) && isset( $checkout_attrs['order']['id'] ) ) {
				$order_id = $checkout_attrs['order']['id'];
			} elseif ( is_object( $checkout_attrs['order'] ) && isset( $checkout_attrs['order']->id ) ) {
				$order_id = $checkout_attrs['order']->id;
			} elseif ( is_string( $checkout_attrs['order'] ) ) {
				$order_id = $checkout_attrs['order'];
			}
		}

		if ( empty( $order_id ) ) {
			Logger::error( 'SureCart Integration', 'No order ID found in cancelled checkout' );
			return;
		}

		// Fetch the order with purchases.
		$order = \SureCart\Models\Order::with( array( 'purchases' ) )->find( $order_id );

		if ( is_wp_error( $order ) ) {
			Logger::error( 'SureCart Integration', 'Failed to fetch order: ' . $order->get_error_message() );
			return;
		}

		// Get purchases from the order.
		$order_attrs    = is_object( $order ) && method_exists( $order, 'getAttributes' ) ? $order->getAttributes() : array();
		$purchases_data = $order_attrs['purchases'] ?? null;

		// Purchases come as a paginated list with a 'data' array.
		$purchase_list = null;
		if ( is_array( $purchases_data ) && isset( $purchases_data['data'] ) ) {
			$purchase_list = $purchases_data['data'];
		} elseif ( is_object( $order->purchases ) && isset( $order->purchases->data ) && is_array( $order->purchases->data ) ) {
			$purchase_list = $order->purchases->data;
		}

		if ( empty( $purchase_list ) || ! is_array( $purchase_list ) || count( $purchase_list ) === 0 ) {
			// Try getting purchase IDs directly from checkout instead.
			$checkout_id = $checkout_attrs['id'] ?? null;
			if ( empty( $checkout_id ) ) {
				Logger::error( 'SureCart Integration', 'No checkout ID available' );
				return;
			}

			// Query purchases by checkout ID.
			$purchase_query = \SureCart\Models\Purchase::where( array( 'checkout_ids' => array( $checkout_id ) ) )->get();

			if ( is_wp_error( $purchase_query ) ) {
				Logger::error( 'SureCart Integration', 'Failed to query purchases: ' . $purchase_query->get_error_message() );
				return;
			}

			$purchase_list = is_array( $purchase_query ) ? $purchase_query : ( isset( $purchase_query->data ) ? $purchase_query->data : array() );

			if ( empty( $purchase_list ) || ! is_array( $purchase_list ) || count( $purchase_list ) === 0 ) {
				Logger::error( 'SureCart Integration', 'No purchases found for this checkout/order' );
				return;
			}
		}

		// Get the first purchase (usually there's only one).
		$purchase_data = reset( $purchase_list );

		// Extract purchase ID from the data.
		$purchase_id = null;
		if ( is_array( $purchase_data ) && isset( $purchase_data['id'] ) ) {
			$purchase_id = $purchase_data['id'];
		} elseif ( is_object( $purchase_data ) && isset( $purchase_data->id ) ) {
			$purchase_id = $purchase_data->id;
		} elseif ( is_string( $purchase_data ) ) {
			$purchase_id = $purchase_data;
		}

		if ( empty( $purchase_id ) ) {
			Logger::error( 'SureCart Integration', 'Could not extract purchase ID from order' );
			return;
		}

		// For cancellations, we track it as a cancellation (not refund).
		$cancelled_at           = isset( $checkout_attrs['updated_at'] ) ? $checkout_attrs['updated_at'] : time();
		$cancel_timestamp       = is_numeric( $cancelled_at ) ? (int) $cancelled_at : strtotime( (string) $cancelled_at );
		$cancelled_at_formatted = gmdate( 'c', false !== $cancel_timestamp ? $cancel_timestamp : time() );

		// Prepare cancellation data (use order_id from checkout to match track_order_from_checkout).
		// New format uses checkout.order, legacy format used purchase.id.
		$cancel_data = array(
			'order_id'     => $this->generate_unique_order_id( $order_id, 'SUR' ),
			'reason'       => __( 'Order cancelled', 'surecontact' ),
			'cancelled_at' => $cancelled_at_formatted,
		);

		$result = $this->ecommerce_api->cancel_purchase( $cancel_data, array( 'source' => $this->slug ) );

		// If new format fails with 404/not_found, try legacy format (purchase.id) for backward compatibility.
		if ( is_wp_error( $result ) && $this->is_order_not_found_error( $result ) && ! empty( $purchase_id ) ) {
			Logger::info( 'SureCart Integration', "Order not found with new format, trying legacy format for cancellation: {$purchase_id}" );

			$cancel_data['order_id'] = $this->generate_unique_order_id( $purchase_id, 'SUR' );
			$result                  = $this->ecommerce_api->cancel_purchase( $cancel_data, array( 'source' => $this->slug ) );
		}

		if ( is_wp_error( $result ) ) {
			return;
		}
	}

	/**
	 * Track order from checkout — real-time revenue tracking.
	 *
	 * Uses the Checkout object as the source of truth for revenue tracking.
	 * Fires ONCE per order (via checkout_confirmed hook) regardless of number of products.
	 *
	 * @since 1.0.0
	 *
	 * @param \SureCart\Models\Checkout $checkout Hydrated checkout object.
	 * @return void
	 */
	private function track_order_from_checkout( $checkout ) {
		if ( ! $this->is_global_enabled() ) {
			return;
		}

		if ( ! $this->get_setting( 'track_orders', true ) ) {
			return;
		}

		$order_data = $this->extract_order_data_from_checkout( $checkout );
		if ( ! $order_data ) {
			return;
		}

		$result = $this->ecommerce_api->track_purchase( $order_data, array( 'source' => $this->slug ) );

		if ( is_wp_error( $result ) ) {
			return;
		}
	}

	/**
	 * Extract order data from a SureCart checkout object.
	 *
	 * Shared data extraction used by both real-time tracking and bulk sync.
	 * Parses products, prices, coupons, amounts, and timestamps from the checkout.
	 *
	 * @since 1.2.0
	 *
	 * @param object $checkout SureCart checkout object with relationships.
	 * @return array|null Order data array ready for track_purchase API, or null on validation failure.
	 */
	public function extract_order_data_from_checkout( $checkout ) {
		$checkout_id = $checkout->id ?? '';
		if ( empty( $checkout_id ) ) {
			return null;
		}

		$email = $checkout->email ?? '';
		if ( empty( $email ) ) {
			return null;
		}

		// Get amounts from checkout (amounts are in cents).
		$total_amount    = isset( $checkout->total_amount ) ? (float) $checkout->total_amount / 100 : 0;
		$subtotal_amount = isset( $checkout->subtotal_amount ) ? (float) $checkout->subtotal_amount / 100 : $total_amount;
		$shipping_amount = isset( $checkout->shipping_amount ) ? (float) $checkout->shipping_amount / 100 : 0;
		$discount_amount = isset( $checkout->discount_amount ) ? (float) $checkout->discount_amount / 100 : 0;
		$tax_amount      = isset( $checkout->tax_amount ) ? (float) $checkout->tax_amount / 100 : 0;
		$currency        = strtoupper( $checkout->currency ?? 'USD' );

		// Build products array from ALL purchases in the checkout.
		$products       = array();
		$purchases_data = array();

		if ( isset( $checkout->purchases ) ) {
			if ( is_object( $checkout->purchases ) && isset( $checkout->purchases->data ) ) {
				$purchases_data = $checkout->purchases->data;
			} elseif ( is_array( $checkout->purchases ) ) {
				$purchases_data = isset( $checkout->purchases['data'] ) ? $checkout->purchases['data'] : $checkout->purchases;
			}
		}

		if ( ! empty( $purchases_data ) && is_array( $purchases_data ) ) {
			foreach ( $purchases_data as $purchase_item ) {
				$product  = null;
				$price    = null;
				$variant  = null;
				$quantity = 1;

				if ( is_object( $purchase_item ) ) {
					$product  = $purchase_item->product ?? null;
					$price    = $purchase_item->price ?? null;
					$variant  = $purchase_item->variant ?? null;
					$quantity = $purchase_item->quantity ?? 1;
				} elseif ( is_array( $purchase_item ) ) {
					$product  = $purchase_item['product'] ?? null;
					$price    = $purchase_item['price'] ?? null;
					$variant  = $purchase_item['variant'] ?? null;
					$quantity = $purchase_item['quantity'] ?? 1;
				}

				// Extract product details.
				$product_id   = '';
				$product_name = 'Unknown Product';

				if ( $product ) {
					if ( is_object( $product ) ) {
						$product_id   = $product->id ?? '';
						$product_name = $product->name ?? 'Unknown Product';
					} elseif ( is_string( $product ) ) {
						$product_id  = $product;
						$product_obj = class_exists( 'SureCart\\Models\\Product' )
							? \SureCart\Models\Product::find( $product )
							: null;
						if ( $product_obj && ! is_wp_error( $product_obj ) && is_object( $product_obj ) ) {
							$product_name = $product_obj->name ?? 'Unknown Product';
						}
					} elseif ( is_array( $product ) ) {
						$product_id   = $product['id'] ?? '';
						$product_name = $product['name'] ?? 'Unknown Product';
					}
				}

				// Extract price amount.
				$item_price = 0;
				if ( $price ) {
					if ( is_object( $price ) && isset( $price->amount ) ) {
						$item_price = (float) $price->amount / 100;
					} elseif ( is_array( $price ) && isset( $price['amount'] ) ) {
						$item_price = (float) $price['amount'] / 100;
					} elseif ( is_string( $price ) ) {
						$price_obj = class_exists( 'SureCart\\Models\\Price' )
							? \SureCart\Models\Price::find( $price )
							: null;
						if ( $price_obj && ! is_wp_error( $price_obj ) && is_object( $price_obj ) ) {
							$item_price = isset( $price_obj->amount ) ? (float) $price_obj->amount / 100 : 0;
						}
					}
				}

				// Extract variant ID if present.
				$variant_id = '';
				if ( $variant ) {
					if ( is_object( $variant ) ) {
						$variant_id = $variant->id ?? '';
					} elseif ( is_string( $variant ) ) {
						$variant_id = $variant;
					} elseif ( is_array( $variant ) ) {
						$variant_id = $variant['id'] ?? '';
					}
				}

				$products[] = array(
					'product_id' => (string) $product_id,
					'name'       => $product_name,
					'quantity'   => (int) $quantity,
					'price'      => $item_price,
					'variant_id' => $variant_id,
				);
			}
		}

		// Fallback if no products found.
		if ( empty( $products ) ) {
			$products[] = array(
				'product_id' => 'unknown',
				'name'       => 'Unknown Product',
				'quantity'   => 1,
				'price'      => $total_amount,
			);
		}

		// Get coupon code if available.
		$coupon_code = '';
		$discount    = $checkout->discount ?? null;
		if ( $discount ) {
			$promotion = is_object( $discount ) ? ( $discount->promotion ?? null ) : null;
			if ( $promotion ) {
				if ( ! empty( $promotion->code ) ) {
					$coupon_code = $promotion->code;
				} else {
					$coupon = is_object( $promotion ) ? ( $promotion->coupon ?? null ) : null;
					if ( $coupon ) {
						if ( is_object( $coupon ) ) {
							$coupon_code = ! empty( $coupon->code ) ? $coupon->code : ( $coupon->name ?? '' );
						} elseif ( is_string( $coupon ) && ! empty( $coupon ) ) {
							$coupon_obj = \SureCart\Models\Coupon::find( $coupon );
							if ( ! is_wp_error( $coupon_obj ) && $coupon_obj ) {
								$coupon_code = ! empty( $coupon_obj->code ) ? $coupon_obj->code : ( $coupon_obj->name ?? '' );
							}
						}
					}
				}
			}
		}

		// Generate order ID.
		$order_id_raw = $checkout->order ?? $checkout_id;
		$order_id     = $this->generate_unique_order_id( $order_id_raw, 'SUR' );

		// Get purchase timestamp.
		$created_at   = $checkout->created_at ?? time();
		$timestamp    = is_numeric( $created_at ) ? (int) $created_at : strtotime( (string) $created_at );
		$purchased_at = gmdate( 'c', false !== $timestamp ? $timestamp : time() );

		return array(
			'contact_email'   => $email,
			'order_id'        => $order_id,
			'total_amount'    => $total_amount,
			'subtotal_amount' => $subtotal_amount,
			'discount_amount' => $discount_amount,
			'shipping_amount' => $shipping_amount,
			'tax_amount'      => $tax_amount,
			'currency'        => $currency,
			'products'        => $products,
			'coupon_code'     => $coupon_code,
			'purchased_at'    => $purchased_at,
		);
	}

	/**
	 * Check if WP_Error is an "order not found" error
	 *
	 * Used for backward compatibility - if order tracked with new format (checkout.order)
	 * is not found, we can try the legacy format (purchase.id).
	 *
	 * WP_Error structure from SaaS_Client:
	 * - Error code: 'saas_api_error'
	 * - Message: 'API returned status code 404'
	 * - Data: array('body' => '{"error_code":"PURCHASE_NOT_FOUND",...}', 'code' => 404)
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Error $error WP_Error object.
	 * @return bool True if this is a "not found" error.
	 */
	private function is_order_not_found_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		// Check if it's a SaaS API error with 404 status code.
		$error_code = $error->get_error_code();
		$error_data = $error->get_error_data();

		// Check HTTP status code 404 in error data.
		if ( 'saas_api_error' === $error_code && is_array( $error_data ) ) {
			$http_code = $error_data['code'] ?? null;
			if ( 404 === $http_code ) {
				return true;
			}

			// Also check the response body for PURCHASE_NOT_FOUND error code.
			$body = $error_data['body'] ?? '';
			if ( ! empty( $body ) && is_string( $body ) ) {
				$body_data = json_decode( $body, true );
				if ( is_array( $body_data ) && isset( $body_data['error_code'] ) ) {
					if ( 'PURCHASE_NOT_FOUND' === $body_data['error_code'] ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Get integration actions based on priority: Variation > Price > Product > All Products > Global.
	 *
	 * @since 0.0.3
	 *
	 * @param \SureCart\Models\Purchase $purchase Purchase object.
	 * @param string                    $event Event name ('purchase', 'refund', 'cancellation').
	 * @return array Array of actions (add_lists, add_tags, remove_lists, remove_tags).
	 */
	private function get_integration_actions( $purchase, $event = 'purchase' ) {
		$actions = array(
			'add_lists'    => array(),
			'add_tags'     => array(),
			'remove_lists' => array(),
			'remove_tags'  => array(),
		);

		// 1. Check Variation Settings.
		// The purchase object contains the variant ID directly in its attributes.
		// Variations are stored in the integration DB as item_type = 'product' with the variation ID.
		$variant_id = $purchase->variant ?? '';

		if ( ! empty( $variant_id ) && is_string( $variant_id ) ) {
			// Check for specific variation config.
			$variation_result = $this->integrations_db->get( $this->slug, $variant_id, 'product', $event );
			// Fallback to null event if not found.
			if ( ! $this->has_valid_config( $variation_result ) ) {
				$variation_result = $this->integrations_db->get( $this->slug, $variant_id, 'product', null );
			}

			if ( $this->has_valid_config( $variation_result ) && isset( $variation_result['config'] ) ) {
				Logger::info( 'SureCart Integration', "Applied settings from Variation: {$variant_id}" );
				return $this->merge_config_defaults( $variation_result['config'] );
			}
		}

		// 2. Check Price Settings.
		$price    = $purchase->price ?? null;
		$price_id = '';
		if ( $price ) {
			if ( is_object( $price ) ) {
				$price_id = $price->id ?? '';
			} elseif ( is_string( $price ) ) {
				$price_id = $price;
			} elseif ( is_array( $price ) ) {
				$price_id = $price['id'] ?? '';
			}
		}

		if ( ! empty( $price_id ) && is_string( $price_id ) ) {
			$price_result = $this->integrations_db->get( $this->slug, $price_id, 'product', $event );
			if ( ! $this->has_valid_config( $price_result ) ) {
				$price_result = $this->integrations_db->get( $this->slug, $price_id, 'product', null );
			}

			if ( $this->has_valid_config( $price_result ) && isset( $price_result['config'] ) ) {
				Logger::info( 'SureCart Integration', "Applied settings from Price: {$price_id}" );
				return $this->merge_config_defaults( $price_result['config'] );
			}
		}

		// 3. Check Product Settings.
		$product    = $purchase->product ?? null;
		$product_id = $product && is_object( $product ) ? ( $product->id ?? '' ) : '';

		if ( ! empty( $product_id ) ) {
			$product_result = $this->integrations_db->get( $this->slug, $product_id, 'product', $event );
			if ( ! $this->has_valid_config( $product_result ) ) {
				$product_result = $this->integrations_db->get( $this->slug, $product_id, 'product', null );
			}

			if ( $this->has_valid_config( $product_result ) && isset( $product_result['config'] ) ) {
				Logger::info( 'SureCart Integration', "Applied settings from Product: {$product_id}" );
				return $this->merge_config_defaults( $product_result['config'] );
			}
		}

		// 4. Check All Products Settings.
		$all_products_result = $this->integrations_db->get( $this->slug, 'all', 'product', $event );
		if ( ! $this->has_valid_config( $all_products_result ) ) {
			$all_products_result = $this->integrations_db->get( $this->slug, 'all', 'product', null );
		}

		if ( $this->has_valid_config( $all_products_result ) && isset( $all_products_result['config'] ) ) {
			Logger::info( 'SureCart Integration', 'Applied settings from "All Products"' );
			return $this->merge_config_defaults( $all_products_result['config'] );
		}

		// 5. Global Settings.
		// Only apply global settings for purchase events.
		// We use the internal setting helper which pulls from the main integration settings.
		if ( $event === 'purchase' && $this->is_global_enabled() ) {
			$global_config = array(
				'add_lists'    => $this->get_setting( 'add_lists', array() ),
				'add_tags'     => $this->get_setting( 'add_tags', array() ),
				'remove_lists' => $this->get_setting( 'remove_lists', array() ),
				'remove_tags'  => $this->get_setting( 'remove_tags', array() ),
			);

			// Check if effectively empty to avoid unnecessary logging/processing?
			// Or just return it.
			if ( $this->is_config_not_empty( $global_config ) ) {
				Logger::info( 'SureCart Integration', 'Applied settings from Global Settings' );
				return $this->merge_config_defaults( $global_config );
			}
		}

		return $actions;
	}

	/**
	 * Get contact ID from a purchase object
	 *
	 * Helper method to extract contact ID from a purchase by getting the associated WP user.
	 *
	 * @since 0.0.3
	 *
	 * @param \SureCart\Models\Purchase $purchase Purchase object.
	 * @return string|null Contact ID or null if not found.
	 */
	private function get_contact_id_from_purchase( $purchase ) {
		$wp_user = $purchase->getWPUser();
		$user_id = $wp_user ? $wp_user->ID : 0;

		if ( $user_id > 0 ) {
			$contact_id = $this->contact_service->get_contact_id_by_user( $user_id );
			return $contact_id ? $contact_id : null;
		}

		return null;
	}

	/**
	 * Get coupon configuration from database
	 *
	 * Helper method to retrieve coupon-specific or "All Coupons" configuration
	 * with proper fallbacks for event and item_id.
	 *
	 * @since 0.0.3
	 *
	 * @param string $coupon_id Coupon ID.
	 * @param string $event Event name ('applied' for coupon usage during purchase).
	 * @return array|null Coupon configuration result or null if not found.
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

		// Return the result if valid, otherwise null.
		return $this->has_valid_config( $coupon_result ) ? $coupon_result : null;
	}

	/**
	 * Get purchase from checkout object
	 *
	 * Helper method to extract the first purchase from a checkout.
	 *
	 * @since 0.0.3
	 *
	 * @param \SureCart\Models\Checkout $checkout Checkout object.
	 * @return \SureCart\Models\Purchase|null Purchase object or null if not found.
	 */
	private function get_purchase_from_checkout( $checkout ) {
		$checkout_attrs = $checkout->getAttributes();

		// Try to get purchase ID from checkout.
		if ( ! isset( $checkout_attrs['id'] ) ) {
			return null;
		}

		$checkout_id = $checkout_attrs['id'];

		// Query purchases by checkout ID.
		$purchase_query = \SureCart\Models\Purchase::where( array( 'checkout_ids' => array( $checkout_id ) ) )->get();

		if ( is_wp_error( $purchase_query ) ) {
			return null;
		}

		$purchase_list = is_array( $purchase_query ) ? $purchase_query : ( isset( $purchase_query->data ) ? $purchase_query->data : array() );

		if ( empty( $purchase_list ) || ! is_array( $purchase_list ) || count( $purchase_list ) === 0 ) {
			return null;
		}

		// Get the first purchase.
		$purchase_data = reset( $purchase_list );

		// Extract purchase ID.
		$purchase_id = $this->extract_id_from_data( $purchase_data );

		if ( empty( $purchase_id ) ) {
			return null;
		}

		// Fetch the full purchase object with product relationship.
		$purchase = \SureCart\Models\Purchase::with( array( 'product', 'price' ) )->find( $purchase_id );

		return is_wp_error( $purchase ) ? null : $purchase;
	}

	/**
	 * Extract ID from various data structures
	 *
	 * Helper method to extract an ID from array, object, or string.
	 *
	 * @since 0.0.3
	 *
	 * @param mixed $data The data to extract ID from.
	 * @return string|null The extracted ID or null.
	 */
	private function extract_id_from_data( $data ) {
		if ( is_array( $data ) && isset( $data['id'] ) ) {
			return $data['id'];
		}

		if ( is_object( $data ) && isset( $data->id ) ) {
			return $data->id;
		}

		if ( is_string( $data ) ) {
			return $data;
		}

		return null;
	}

	/**
	 * Get SureCart products list.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of product items.
	 */
	public function get_products() {
		if ( ! class_exists( 'SureCart\\Models\\Product' ) ) {
			return array();
		}

		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Products', 'surecontact' ),
				'type'  => 'product',
			),
		);

		$products = \SureCart\Models\Product::with( array( 'prices', 'variants', 'variant_options' ) )->where( array( 'archived' => false ) )->get();

		if ( is_wp_error( $products ) || empty( $products ) ) {
			return $items;
		}

		$products_list = array();
		if ( is_object( $products ) && isset( $products->data ) && is_array( $products->data ) ) {
			$products_list = $products->data;
		} elseif ( is_array( $products ) ) {
			$products_list = $products;
		}

		if ( empty( $products_list ) ) {
			return $items;
		}

		foreach ( $products_list as $product ) {
			$product_id   = is_object( $product ) ? ( $product->id ?? '' ) : ( $product['id'] ?? '' );
			$product_name = is_object( $product ) ? ( $product->name ?? 'Untitled Product' ) : ( $product['name'] ?? 'Untitled Product' );

			if ( ! empty( $product_id ) ) {
				$items[] = array(
					'id'    => $product_id,
					'title' => $product_name,
					'type'  => 'product',
				);

				// Get variations for this product.
				$this->add_product_variations( $items, $product, $product_name );

				// Get prices for this product.
				$this->add_product_prices( $items, $product, $product_name );
			}
		}

		return $items;
	}

	/**
	 * Add product variations to items list.
	 *
	 * @since 0.0.3
	 *
	 * @param array  &$items Reference to items array.
	 * @param object $product Product object with expanded relationships.
	 * @param string $product_name Product name.
	 * @return void
	 */
	private function add_product_variations( &$items, $product, $product_name ) {
		// Check if product has variants.
		$variants = null;
		if ( is_object( $product ) && isset( $product->variants ) ) {
			if ( is_object( $product->variants ) && isset( $product->variants->data ) ) {
				$variants = $product->variants->data;
			} elseif ( is_array( $product->variants ) ) {
				$variants = $product->variants;
			}
		}

		// If no variants, return early.
		if ( empty( $variants ) || ! is_array( $variants ) ) {
			return;
		}

		// Add each variant as a separate item.
		foreach ( $variants as $variant ) {
			$variant_id = is_object( $variant ) ? ( $variant->id ?? '' ) : ( $variant['id'] ?? '' );

			if ( empty( $variant_id ) ) {
				continue;
			}

			$variant_name = $this->build_variant_title( $variant );

			if ( ! empty( $variant_name ) ) {
				$items[] = array(
					'id'    => $variant_id,
					'title' => $product_name . ' - ' . $variant_name,
					'type'  => 'product',
				);
			}
		}
	}

	/**
	 * Add product prices to items list.
	 *
	 * Only adds prices when a product has multiple pricing options.
	 * Products with a single price are sufficiently covered by the product-level entry.
	 *
	 * @since 1.4.1
	 *
	 * @param array  &$items       Reference to items array.
	 * @param object $product      Product object with expanded relationships.
	 * @param string $product_name Product name.
	 * @return void
	 */
	private function add_product_prices( &$items, $product, $product_name ) {
		$prices = null;
		if ( is_object( $product ) && isset( $product->prices ) ) {
			if ( is_object( $product->prices ) && isset( $product->prices->data ) ) {
				$prices = $product->prices->data;
			} elseif ( is_array( $product->prices ) ) {
				$prices = $product->prices;
			}
		}

		if ( empty( $prices ) || ! is_array( $prices ) ) {
			return;
		}

		// Only show individual prices when there are multiple pricing options.
		if ( count( $prices ) <= 1 ) {
			return;
		}

		foreach ( $prices as $price ) {
			$price_id   = is_object( $price ) ? ( $price->id ?? '' ) : ( $price['id'] ?? '' );
			$price_name = is_object( $price ) ? ( $price->name ?? '' ) : ( $price['name'] ?? '' );

			if ( empty( $price_id ) || ! is_string( $price_id ) ) {
				continue;
			}

			// Fall back to price ID if name is empty.
			if ( empty( $price_name ) || ! is_string( $price_name ) ) {
				$price_name = $price_id;
			}

			$items[] = array(
				'id'    => $price_id,
				'title' => $product_name . ' - ' . $price_name,
				'type'  => 'product',
			);
		}
	}

	/**
	 * Build variant title from options.
	 *
	 * @since 0.0.3
	 *
	 * @param object|array $variant Variant object or array.
	 * @return string Variant title (e.g. "Option 1 - Option 2").
	 */
	private function build_variant_title( $variant ) {
		$variant_parts = array();

		// Get variant attributes.
		$variant_attrs = is_object( $variant ) && method_exists( $variant, 'getAttributes' )
			? $variant->getAttributes()
			: ( is_object( $variant ) ? (array) $variant : $variant );

		// Extract option values (option_1, option_2, option_3).
		for ( $i = 1; $i <= 3; $i++ ) {
			$option_key   = 'option_' . $i;
			$option_value = '';

			if ( is_array( $variant_attrs ) && isset( $variant_attrs[ $option_key ] ) ) {
				$option_value = $variant_attrs[ $option_key ];
			} elseif ( is_object( $variant ) && isset( $variant->$option_key ) ) {
				$option_value = $variant->$option_key;
			}

			if ( ! empty( $option_value ) ) {
				$variant_parts[] = $option_value;
			}
		}

		return ! empty( $variant_parts ) ? implode( ' - ', $variant_parts ) : '';
	}

	/**
	 * Get SureCart coupons list.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of coupon items.
	 */
	public function get_coupons() {
		if ( ! class_exists( 'SureCart\\Models\\Coupon' ) ) {
			return array();
		}

		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Coupons', 'surecontact' ),
				'type'  => 'coupon',
			),
		);

		$coupons = \SureCart\Models\Coupon::where( array( 'archived' => false ) )->get();

		if ( is_wp_error( $coupons ) || empty( $coupons ) ) {
			return $items;
		}

		$coupons_list = array();
		if ( is_object( $coupons ) && isset( $coupons->data ) && is_array( $coupons->data ) ) {
			$coupons_list = $coupons->data;
		} elseif ( is_array( $coupons ) ) {
			$coupons_list = $coupons;
		}

		if ( empty( $coupons_list ) ) {
			return $items;
		}

		foreach ( $coupons_list as $coupon ) {
			$coupon_id   = is_object( $coupon ) ? ( $coupon->id ?? '' ) : ( $coupon['id'] ?? '' );
			$coupon_name = is_object( $coupon ) ? ( $coupon->name ?? 'Untitled Coupon' ) : ( $coupon['name'] ?? 'Untitled Coupon' );

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
	 * @since 0.0.3
	 *
	 * @param string $item_id   Item ID (product, variant, or coupon ID).
	 * @param string $item_type Item type ('product' or 'coupon').
	 * @return string|null Item title or null if not found.
	 */
	public function get_item_title( $item_id, $item_type ) {
		if ( 'all' === $item_id ) {
			if ( 'product' === $item_type ) {
				return __( 'All Products', 'surecontact' );
			} elseif ( 'coupon' === $item_type ) {
				return __( 'All Coupons', 'surecontact' );
			}
			return null;
		}

		if ( 'product' === $item_type ) {
			// Try to fetch as a variant first.
			if ( class_exists( 'SureCart\\Models\\Variant' ) ) {
				try {
					$variant = \SureCart\Models\Variant::with( array( 'product' ) )->find( $item_id );
					if ( ! is_wp_error( $variant ) && $variant ) {
						$variant_name = $this->build_variant_title( $variant );
						$product_name = is_object( $variant->product ) ? ( $variant->product->name ?? '' ) : ( $variant->product['name'] ?? '' );
						return $product_name ? $product_name . ' - ' . $variant_name : $variant_name;
					}
				} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Not a variant, will try as price below.
				}
			}

			// Try to fetch as a price.
			if ( class_exists( 'SureCart\\Models\\Price' ) ) {
				try {
					$price = \SureCart\Models\Price::find( $item_id );
					if ( ! is_wp_error( $price ) && $price ) {
						$price_name = is_object( $price ) ? ( $price->name ?? '' ) : ( $price['name'] ?? '' );
						if ( ! empty( $price_name ) ) {
							// Resolve the parent product name.
							$product_name  = '';
							$price_product = $price->product ?? null;
							if ( $price_product && is_object( $price_product ) ) {
								$product_name = $price_product->name ?? '';
							} elseif ( is_string( $price_product ) && class_exists( 'SureCart\\Models\\Product' ) ) {
								$product_obj = \SureCart\Models\Product::find( $price_product );
								if ( ! is_wp_error( $product_obj ) && $product_obj ) {
									$product_name = is_object( $product_obj ) ? ( $product_obj->name ?? '' ) : '';
								}
							}
							return $product_name ? $product_name . ' - ' . $price_name : $price_name;
						}
					}
				} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Not a price, will try as product below.
				}
			}

			// Try to fetch as a product.
			if ( ! class_exists( 'SureCart\\Models\\Product' ) ) {
				return null;
			}

			try {
				$product = \SureCart\Models\Product::find( $item_id );
				if ( ! is_wp_error( $product ) && $product ) {
					return is_object( $product ) ? ( $product->name ?? null ) : ( $product['name'] ?? null );
				}
			} catch ( \Exception $e ) {
				return null;
			}
		} elseif ( 'coupon' === $item_type ) {
			if ( ! class_exists( 'SureCart\\Models\\Coupon' ) ) {
				return null;
			}

			try {
				$coupon = \SureCart\Models\Coupon::find( $item_id );
				if ( ! is_wp_error( $coupon ) && $coupon ) {
					return is_object( $coupon ) ? ( $coupon->name ?? null ) : ( $coupon['name'] ?? null );
				}
			} catch ( \Exception $e ) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Get SureCart sync types.
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of sync type definitions.
	 */
	public function get_sync_types() {
		return $this->order_sync->get_sync_types();
	}
}
