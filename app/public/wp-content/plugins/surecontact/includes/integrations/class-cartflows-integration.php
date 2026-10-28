<?php
/**
 * CartFlows Integration
 *
 * Handles CartFlows funnel completions, offer conversions, and order bump tracking
 *
 * @since 1.1.0
 *
 * @package SureContact
 */

namespace SureContact\Integrations;

use SureContact\Logger;
use SureContact\Traits\Integration_DB_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CartFlows_Integration
 *
 * Integrates CartFlows with SureContact for funnel and offer tracking
 *
 * @since 1.1.0
 */
class CartFlows_Integration extends Base_Integration {

	// Use the database helper trait for item-specific configurations.
	use Integration_DB_Helper;


	/**
	 * Constructor
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		$this->slug        = 'cartflows';
		$this->name        = 'CartFlows';
		$this->description = __( 'Sync CartFlows step completions, track upsell/downsell conversions, and order bump additions.', 'surecontact' );
		$this->docs_url    = '';
		$this->dependency  = 'Cartflows_Loader';

		parent::__construct();
	}

	/**
	 * Add CartFlows field groups
	 *
	 * @since 1.1.0
	 *
	 * @param array $groups Existing field groups.
	 * @return array Modified field groups.
	 */
	public function add_meta_field_group( $groups ) {
		$groups['cartflows'] = array(
			'title' => __( 'CartFlows', 'surecontact' ),
			'url'   => '',
		);

		return $groups;
	}

	/**
	 * Add CartFlows-specific fields
	 *
	 * @since 1.1.0
	 *
	 * @param array $fields Existing meta fields.
	 * @return array Modified meta fields.
	 */
	public function add_meta_fields( $fields ) {
		$cartflows_fields = array(
			'cf_flow_id'            => array(
				'label' => __( 'Last Flow ID', 'surecontact' ),
				'type'  => 'text',
			),
			'cf_flow_name'          => array(
				'label' => __( 'Last Flow Name', 'surecontact' ),
				'type'  => 'text',
			),
			'cf_last_step_id'       => array(
				'label' => __( 'Last Step ID', 'surecontact' ),
				'type'  => 'text',
			),
			'cf_last_step_name'     => array(
				'label' => __( 'Last Step Name', 'surecontact' ),
				'type'  => 'text',
			),
			'cf_last_step_type'     => array(
				'label' => __( 'Last Step Type', 'surecontact' ),
				'type'  => 'text',
			),
			'cf_upsells_accepted'   => array(
				'label' => __( 'Upsells Accepted Count', 'surecontact' ),
				'type'  => 'number',
			),
			'cf_downsells_accepted' => array(
				'label' => __( 'Downsells Accepted Count', 'surecontact' ),
				'type'  => 'number',
			),
			'cf_order_bumps_added'  => array(
				'label' => __( 'Order Bumps Added Count', 'surecontact' ),
				'type'  => 'number',
			),
		);

		// Add group to all fields.
		foreach ( $cartflows_fields as $key => &$config ) {
			$config['group'] = 'cartflows';
			$fields[ $key ]  = $config;
		}
		unset( $config );

		return $fields;
	}

	/**
	 * Get integration-specific global settings fields
	 *
	 * CartFlows integration uses rule-based configuration only.
	 * No global settings are needed - tracking is controlled by the presence of rules.
	 *
	 * @since 1.1.0
	 *
	 * @return array Empty array (no global settings).
	 */
	public function get_settings_fields() {
		return array();
	}

	/**
	 * Get all available item types for CartFlows.
	 *
	 * @since 1.1.0
	 *
	 * @return array Array of item type definitions with 'key' and 'label' keys.
	 */
	public function get_item_types() {
		$types = array(
			array(
				'key'   => 'step',
				'label' => __( 'Step', 'surecontact' ),
			),
		);

		// Pro-only item types.
		if ( $this->is_cartflows_pro_active() ) {
			$types[] = array(
				'key'   => 'upsell',
				'label' => __( 'Upsell', 'surecontact' ),
			);
			$types[] = array(
				'key'   => 'downsell',
				'label' => __( 'Downsell', 'surecontact' ),
			);
			$types[] = array(
				'key'   => 'order_bump',
				'label' => __( 'Order Bump', 'surecontact' ),
			);
			$types[] = array(
				'key'   => 'pre_checkout_offer',
				'label' => __( 'Pre-Checkout Offer', 'surecontact' ),
			);
		}

		return $types;
	}

	/**
	 * Get additional plugin dependencies for CartFlows.
	 *
	 * CartFlows Pro item types (upsell, downsell, order_bump) require the Pro plugin.
	 *
	 * @since 1.1.0
	 *
	 * @return array Keyed array of plugin_key => array( plugin_file, plugin_name, plugin_dependencies ).
	 */
	public function get_additional_plugins() {
		return array(
			'cartflows-pro' => array(
				'plugin_file'         => 'cartflows-pro/cartflows-pro.php',
				'plugin_name'         => __( 'CartFlows Pro', 'surecontact' ),
				'plugin_dependencies' => array( 'cartflows/cartflows.php' ),
			),
		);
	}

	/**
	 * Get item type to plugin requirement mapping for CartFlows.
	 *
	 * Pro-only item types map to the 'cartflows-pro' plugin key.
	 * The 'step' item type is not listed and uses the default CartFlows free plugin.
	 *
	 * @since 1.1.0
	 *
	 * @return array Map of item_type_key => plugin_key.
	 */
	public function get_item_type_plugin_requirements() {
		return array(
			'upsell'             => 'cartflows-pro',
			'downsell'           => 'cartflows-pro',
			'order_bump'         => 'cartflows-pro',
			'pre_checkout_offer' => 'cartflows-pro',
		);
	}

	/**
	 * Get available events for a specific item type.
	 *
	 * @since 1.1.0
	 *
	 * @param string $item_type Item type (e.g., 'flow', 'step', 'upsell').
	 * @return array Array of event definitions with 'key', 'label', and optional 'description' keys.
	 */
	public function get_events_by_item_type( $item_type ) {
		// Description for "started" events explaining email identification.
		$started_description = __( 'Email will be identified from: 1) URL parameter (billing_email, email), 2) CartFlows Pro session, 3) WooCommerce customer data, 4) Logged-in user. If no email can be identified, this rule will not trigger.', 'surecontact' );

		switch ( $item_type ) {
			case 'step':
				return array(
					array(
						'key'         => 'started',
						'label'       => __( 'Started', 'surecontact' ),
						'description' => $started_description,
					),
					array(
						'key'   => 'completed',
						'label' => __( 'Completed', 'surecontact' ),
					),
				);

			case 'upsell':
				return array(
					array(
						'key'   => 'accepted',
						'label' => __( 'Accepted', 'surecontact' ),
					),
					array(
						'key'   => 'rejected',
						'label' => __( 'Rejected', 'surecontact' ),
					),
				);

			case 'downsell':
				return array(
					array(
						'key'   => 'accepted',
						'label' => __( 'Accepted', 'surecontact' ),
					),
					array(
						'key'   => 'rejected',
						'label' => __( 'Rejected', 'surecontact' ),
					),
				);

			case 'order_bump':
				return array(
					array(
						'key'   => 'added',
						'label' => __( 'Added', 'surecontact' ),
					),
				);

			case 'pre_checkout_offer':
				return array(
					array(
						'key'   => 'accepted',
						'label' => __( 'Accepted', 'surecontact' ),
					),
				);

			default:
				return array();
		}
	}

	/**
	 * Get item-specific configuration fields.
	 *
	 * @since 1.1.0
	 *
	 * @param string      $item_id Item ID.
	 * @param string|null $event   Event name.
	 * @return array Configuration fields schema.
	 */
	public function get_item_config_fields( $item_id, $event = null ) {
		return self::get_standard_list_tag_fields();
	}

	/**
	 * Get CartFlows item fields.
	 *
	 * @since 1.1.0
	 *
	 * @param string $item_id Item ID - unused but required for consistency.
	 * @return array Empty array (no mappable fields for flows/steps).
	 */
	public function get_item_fields( $item_id ) {
		return array();
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 1.1.0
	 */
	protected function init() {
		// Core CartFlows hooks - Step view (started events).
		add_action( 'cartflows_wp', array( $this, 'handle_step_started' ), 10, 1 );

		// Core CartFlows hooks - Thank You page (funnel completion).
		add_action( 'cartflows_thankyou_details_before', array( $this, 'handle_funnel_completed' ), 10, 1 );

		// Pro hooks for offer tracking.
		if ( $this->is_cartflows_pro_active() ) {
			// Upsell hooks.
			add_action( 'cartflows_offer_accepted', array( $this, 'handle_offer_accepted' ), 10, 2 );
			add_action( 'cartflows_offer_rejected', array( $this, 'handle_offer_rejected' ), 10, 2 );

			// Pre-checkout offer hook.
			add_action( 'wcf_pre_checkout_offer_item_added', array( $this, 'handle_pre_checkout_offer_accepted' ), 10, 2 );

			// Persist PCO cart item data as order item meta for e-commerce tracking.
			add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'persist_pco_order_item_meta' ), 10, 4 );

			// Note: Order bumps and pre-checkout offers are tracked at order completion
			// in handle_funnel_completed, not via AJAX hooks, as those fire before order/email is available.
		}

		// Optin completion hook (priority 20 to ensure CartFlows has written _wcf_optin_id order meta).
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'handle_optin_completed' ), 20, 3 );

		// Add CartFlows product type suffix to WooCommerce e-commerce tracking product names.
		add_filter( 'surecontact_woocommerce_tracking_product_name', array( $this, 'add_cartflows_product_suffix' ), 10, 3 );
	}

	/**
	 * Handle funnel completion (Thank You page reached)
	 *
	 * This fires when customer reaches the thank you page after checkout.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return void
	 */
	public function handle_funnel_completed( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order_id = $order->get_id();

		// Prevent duplicate processing.
		$cache_key = 'surecontact_cartflows_funnel_' . $order_id;
		if ( get_transient( $cache_key ) ) {
			Logger::info( 'CartFlows Integration', "Funnel completion already tracked for order: {$order_id}" );
			return;
		}

		// Get CartFlows-specific data from order.
		$flow_id     = $this->get_flow_id_from_order( $order );
		$checkout_id = $this->get_checkout_id_from_order( $order );
		$step_id     = $this->get_current_step_id();

		// Get flow and step details.
		$flow_name = $flow_id ? get_the_title( $flow_id ) : '';
		$step_name = $step_id ? get_the_title( $step_id ) : '';
		$step_type = $step_id ? get_post_meta( $step_id, 'wcf-step-type', true ) : 'thankyou';

		// Build customer data from order.
		$email = $order->get_billing_email();
		if ( empty( $email ) ) {
			Logger::error( 'CartFlows Integration', "No email found in order: {$order_id}" );
			return;
		}

		// Collect actions from step and global settings.
		// The checkout step type is 'checkout' - this is what was completed when the order was placed.
		$checkout_step_type = $checkout_id ? get_post_meta( $checkout_id, 'wcf-step-type', true ) : 'checkout';
		$actions            = $this->get_actions_for_step_completion( $checkout_id, $checkout_step_type );

		// Check if any actions to perform.
		$has_actions = ! empty( $actions['add_lists'] ) || ! empty( $actions['add_tags'] )
			|| ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] );

		if ( ! $has_actions ) {
			Logger::info( 'CartFlows Integration', 'No rules configured for this funnel completion' );
			return;
		}

		// Build raw data for field mapping.
		$raw_data = array(
			'cf_flow_id'        => (string) $flow_id,
			'cf_flow_name'      => $flow_name,
			'cf_last_step_id'   => (string) $step_id,
			'cf_last_step_name' => $step_name,
			'cf_last_step_type' => $step_type,
		);

		// Get or create contact.
		$contact_id = $this->get_or_create_contact_from_order( $order, $raw_data, $actions );

		if ( ! $contact_id ) {
			Logger::error( 'CartFlows Integration', 'Failed to get or create contact for funnel completion' );
			return;
		}

		// Apply remove actions.
		if ( ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] ) ) {
			$this->apply_remove_actions( $contact_id, $actions );
		}

		// Mark as tracked.
		set_transient( $cache_key, true, DAY_IN_SECONDS );

		Logger::info(
			'CartFlows Integration',
			"Funnel completion tracked for order: {$order_id}, Flow: {$flow_name}, Step: {$step_name}"
		);

		// Process any order bumps that were purchased (read from order meta).
		$this->process_order_bumps_from_order( $order, $contact_id );

		// Process pre-checkout offer if accepted (read from order item meta).
		$this->process_pco_from_order( $order, $contact_id );
	}

	/**
	 * Process order bumps from order meta.
	 *
	 * CartFlows Pro stores purchased order bumps in order meta '_wcf_bump_products'.
	 * This is more reliable than session-based tracking.
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order $order      WooCommerce order object.
	 * @param string    $contact_id Contact UUID.
	 * @return void
	 */
	private function process_order_bumps_from_order( $order, $contact_id ) {
		// Get order bumps from order meta (stored by CartFlows Pro).
		$bump_products = $order->get_meta( '_wcf_bump_products' );

		if ( empty( $bump_products ) || ! is_array( $bump_products ) ) {
			return;
		}

		$order_id    = $order->get_id();
		$checkout_id = $this->get_checkout_id_from_order( $order );

		// Build a set of product IDs actually present in the order items.
		// This is the source of truth for what was purchased, since CartFlows Pro
		// removes the bump product from the cart when the checkbox is unchecked.
		$order_item_product_ids = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$order_item_product_ids[] = $item->get_product_id();
				if ( $item->get_variation_id() ) {
					$order_item_product_ids[] = $item->get_variation_id();
				}
			}
		}

		Logger::info(
			'CartFlows Integration',
			"Processing order bumps from order meta. Order: {$order_id}, Bumps found: " . count( $bump_products )
		);

		foreach ( $bump_products as $ob_id => $bump_data ) {
			// Get the product ID from bump data (CartFlows stores as 'id' or 'product').
			$product_id = isset( $bump_data['product'] ) ? (int) $bump_data['product'] : 0;
			if ( ! $product_id && isset( $bump_data['id'] ) ) {
				$product_id = (int) $bump_data['id'];
			}

			// Verify the bump product is actually in the order items.
			// If the customer unchecked the bump before checkout, CartFlows removes the
			// product from the cart, so it won't be in the order items.
			if ( $product_id && ! in_array( $product_id, $order_item_product_ids, true ) ) {
				Logger::info(
					'CartFlows Integration',
					"Skipping order bump {$ob_id}: product {$product_id} not found in order items for order {$order_id}"
				);
				continue;
			}

			// Get actions for this specific order bump.
			$actions = $this->get_actions_for_order_bump( $ob_id );

			// Check if any actions to perform.
			$has_actions = ! empty( $actions['add_lists'] ) || ! empty( $actions['add_tags'] )
				|| ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] );

			if ( ! $has_actions ) {
				Logger::info( 'CartFlows Integration', "No rules configured for order bump: {$ob_id}" );
				continue;
			}

			// Apply add actions (lists and tags).
			if ( ! empty( $actions['add_lists'] ) || ! empty( $actions['add_tags'] ) ) {
				$list_uuids = ! empty( $actions['add_lists'] ) ? $this->extract_uuids( $actions['add_lists'] ) : array();
				$tag_uuids  = ! empty( $actions['add_tags'] ) ? $this->extract_uuids( $actions['add_tags'] ) : array();

				if ( ! empty( $list_uuids ) ) {
					$this->contact_service->attach_lists_to_contact( $contact_id, $list_uuids );
				}

				if ( ! empty( $tag_uuids ) ) {
					$this->contact_service->attach_tags_to_contact( $contact_id, $tag_uuids );
				}
			}

			// Apply remove actions.
			if ( ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] ) ) {
				$this->apply_remove_actions( $contact_id, $actions );
			}

			$product_name = $product_id ? get_the_title( $product_id ) : 'Unknown';
			Logger::info(
				'CartFlows Integration',
				"Order bump tracked for order: {$order_id}, Product: {$product_name}, OB ID: {$ob_id}"
			);
		}
	}

	/**
	 * Process pre-checkout offer tags/lists at order completion.
	 *
	 * The AJAX hook (wcf_pre_checkout_offer_item_added) fires before checkout is submitted,
	 * so guest users have no email available yet. This method runs at order completion time
	 * and detects PCO items via the _cartflows_pre_checkout_offer order item meta written by
	 * persist_pco_order_item_meta(). This mirrors process_order_bumps_from_order().
	 *
	 * @since 1.4.0
	 *
	 * @param \WC_Order $order      WooCommerce order object.
	 * @param string    $contact_id Contact UUID.
	 * @return void
	 */
	private function process_pco_from_order( $order, $contact_id ) {
		$order_id = $order->get_id();

		// Prevent duplicate processing.
		$cache_key = 'surecontact_cartflows_pco_order_' . $order_id;
		if ( get_transient( $cache_key ) ) {
			Logger::info( 'CartFlows Integration', "PCO already processed at order completion for order: {$order_id}" );
			return;
		}

		// Check order items for the PCO meta written by persist_pco_order_item_meta().
		$pco_found = false;
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && 'yes' === $item->get_meta( '_cartflows_pre_checkout_offer' ) ) {
				$pco_found = true;
				break;
			}
		}

		if ( ! $pco_found ) {
			return;
		}

		$checkout_id = $this->get_checkout_id_from_order( $order );
		if ( ! $checkout_id ) {
			Logger::info( 'CartFlows Integration', "PCO found in order {$order_id} but checkout ID not available" );
			return;
		}

		// Get actions for this pre-checkout offer.
		$actions = $this->get_actions_for_pre_checkout_offer( $checkout_id );

		// Check if any actions to perform.
		$has_actions = ! empty( $actions['add_lists'] ) || ! empty( $actions['add_tags'] )
			|| ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] );

		if ( ! $has_actions ) {
			Logger::info( 'CartFlows Integration', 'No rules configured for pre-checkout offer' );
			return;
		}

		// Apply add actions (lists and tags).
		if ( ! empty( $actions['add_lists'] ) || ! empty( $actions['add_tags'] ) ) {
			$list_uuids = ! empty( $actions['add_lists'] ) ? $this->extract_uuids( $actions['add_lists'] ) : array();
			$tag_uuids  = ! empty( $actions['add_tags'] ) ? $this->extract_uuids( $actions['add_tags'] ) : array();

			if ( ! empty( $list_uuids ) ) {
				$result = $this->contact_service->attach_lists_to_contact( $contact_id, $list_uuids );
				if ( is_wp_error( $result ) ) {
					Logger::error( 'CartFlows Integration', 'Failed to add lists for PCO: ' . $result->get_error_message() );
				}
			}

			if ( ! empty( $tag_uuids ) ) {
				$result = $this->contact_service->attach_tags_to_contact( $contact_id, $tag_uuids );
				if ( is_wp_error( $result ) ) {
					Logger::error( 'CartFlows Integration', 'Failed to add tags for PCO: ' . $result->get_error_message() );
				}
			}
		}

		// Apply remove actions.
		if ( ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] ) ) {
			$this->apply_remove_actions( $contact_id, $actions );
		}

		// Mark as processed.
		set_transient( $cache_key, true, DAY_IN_SECONDS );

		Logger::info(
			'CartFlows Integration',
			"Pre-checkout offer processed at order completion. Order: {$order_id}, Checkout: {$checkout_id}"
		);
	}

	/**
	 * Handle offer accepted (upsell or downsell)
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order $order         WooCommerce order object.
	 * @param array     $offer_product Offer product data.
	 * @return void
	 */
	public function handle_offer_accepted( $order, $offer_product ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order_id   = $order->get_id();
		$step_id    = $offer_product['step_id'] ?? 0;
		$product_id = $offer_product['id'] ?? 0;

		// Determine offer type from step.
		$offer_type = $this->get_offer_type_from_step( $step_id );

		// Prevent duplicate processing.
		$cache_key = "surecontact_cartflows_{$offer_type}_accepted_{$order_id}_{$step_id}";
		if ( get_transient( $cache_key ) ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( empty( $email ) ) {
			Logger::error( 'CartFlows Integration', "No email found in order for offer: {$order_id}" );
			return;
		}

		// Get actions for this offer.
		$actions = $this->get_actions_for_offer( $step_id, $offer_type, 'accepted' );

		// Check if any actions to perform.
		$has_actions = ! empty( $actions['add_lists'] ) || ! empty( $actions['add_tags'] )
			|| ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] );

		if ( ! $has_actions ) {
			Logger::info( 'CartFlows Integration', "No rules configured for {$offer_type} acceptance" );
			// Still track the conversion even without tags.
		}

		// Build raw data.
		$step_name    = $step_id ? get_the_title( $step_id ) : '';
		$product_name = $product_id ? get_the_title( $product_id ) : '';

		$raw_data = array(
			'cf_last_step_id'   => (string) $step_id,
			'cf_last_step_name' => $step_name,
			'cf_last_step_type' => $offer_type,
		);

		// Increment acceptance counter.
		$counter_field = 'upsell' === $offer_type ? 'cf_upsells_accepted' : 'cf_downsells_accepted';

		// Get or create contact.
		$contact_id = $this->get_or_create_contact_from_order( $order, $raw_data, $actions );

		if ( ! $contact_id ) {
			Logger::error( 'CartFlows Integration', "Failed to get or create contact for {$offer_type} acceptance" );
			return;
		}

		// Apply remove actions.
		if ( ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] ) ) {
			$this->apply_remove_actions( $contact_id, $actions );
		}

		// Mark as tracked.
		set_transient( $cache_key, true, DAY_IN_SECONDS );

		$offer_price = $offer_product['price'] ?? 0;
		Logger::info(
			'CartFlows Integration',
			ucfirst( $offer_type ) . " accepted: Order {$order_id}, Product: {$product_name}, Price: {$offer_price}"
		);
	}

	/**
	 * Handle offer rejected (upsell or downsell)
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order $order         WooCommerce order object.
	 * @param array     $offer_product Offer product data.
	 * @return void
	 */
	public function handle_offer_rejected( $order, $offer_product ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order_id = $order->get_id();
		$step_id  = $offer_product['step_id'] ?? 0;

		// Determine offer type from step.
		$offer_type = $this->get_offer_type_from_step( $step_id );

		// Prevent duplicate processing.
		$cache_key = "surecontact_cartflows_{$offer_type}_rejected_{$order_id}_{$step_id}";
		if ( get_transient( $cache_key ) ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( empty( $email ) ) {
			return;
		}

		// Get actions for rejection event.
		$actions = $this->get_actions_for_offer( $step_id, $offer_type, 'rejected' );

		// Check if any actions to perform.
		$has_actions = ! empty( $actions['add_lists'] ) || ! empty( $actions['add_tags'] )
			|| ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] );

		if ( ! $has_actions ) {
			Logger::info( 'CartFlows Integration', "No rules configured for {$offer_type} rejection" );
			return;
		}

		// Get or create contact.
		$contact_id = $this->get_or_create_contact_from_order( $order, array(), $actions );

		if ( ! $contact_id ) {
			return;
		}

		// Apply remove actions.
		if ( ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] ) ) {
			$this->apply_remove_actions( $contact_id, $actions );
		}

		// Mark as tracked.
		set_transient( $cache_key, true, DAY_IN_SECONDS );

		Logger::info( 'CartFlows Integration', ucfirst( $offer_type ) . " rejected: Order {$order_id}, Step: {$step_id}" );
	}

	/**
	 * Handle step started (step page view)
	 *
	 * This fires when a user lands on any CartFlows step page.
	 *
	 * @since 1.1.0
	 *
	 * @param int $step_id Step ID being viewed.
	 * @return void
	 */
	public function handle_step_started( $step_id ) {
		if ( empty( $step_id ) ) {
			return;
		}

		// Get step and flow info.
		$step_type = get_post_meta( $step_id, 'wcf-step-type', true );
		$flow_id   = wp_get_post_parent_id( $step_id );
		$flow_id   = $flow_id ? (int) $flow_id : null;

		// Try to identify the visitor's email.
		$email = $this->get_visitor_email( $flow_id );

		if ( empty( $email ) ) {
			Logger::info(
				'CartFlows Integration',
				"Step started but no email available for identification. Step: {$step_id}, Type: {$step_type}"
			);
			return;
		}

		// Prevent duplicate processing within the same session.
		$cache_key = 'surecontact_cartflows_step_started_' . $step_id . '_' . md5( $email );
		if ( get_transient( $cache_key ) ) {
			return;
		}

		// Get actions for step started event.
		$actions = $this->get_actions_for_step_started( $step_id, $step_type );

		// Check if any actions to perform.
		$has_actions = ! empty( $actions['add_lists'] ) || ! empty( $actions['add_tags'] )
			|| ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] );

		if ( ! $has_actions ) {
			Logger::info( 'CartFlows Integration', 'No rules configured for step started event' );
			return;
		}

		// Build raw data for field mapping.
		$step_name = get_the_title( $step_id );
		$flow_name = $flow_id ? get_the_title( $flow_id ) : '';

		$raw_data = array(
			'cf_flow_id'        => (string) $flow_id,
			'cf_flow_name'      => $flow_name,
			'cf_last_step_id'   => (string) $step_id,
			'cf_last_step_name' => $step_name,
			'cf_last_step_type' => $step_type,
		);

		// Get or create contact.
		$contact_id = $this->get_or_create_contact_from_email( $email, $raw_data, $actions );

		if ( ! $contact_id ) {
			Logger::error( 'CartFlows Integration', 'Failed to get or create contact for step started' );
			return;
		}

		// Apply remove actions.
		if ( ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] ) ) {
			$this->apply_remove_actions( $contact_id, $actions );
		}

		// Mark as tracked (1 hour cache to prevent repeated tracking on page refresh).
		set_transient( $cache_key, true, HOUR_IN_SECONDS );

		Logger::info(
			'CartFlows Integration',
			"Step started tracked for email: {$email}, Flow: {$flow_name}, Step: {$step_name}, Type: {$step_type}"
		);
	}

	/**
	 * Handle pre-checkout offer accepted
	 *
	 * Fires when a customer accepts the pre-checkout offer popup.
	 *
	 * @param int    $checkout_id Checkout step ID.
	 * @param string $cart_hash   Cart hash.
	 * @return void
	 */
	public function handle_pre_checkout_offer_accepted( $checkout_id, $cart_hash ) {
		if ( empty( $checkout_id ) ) {
			return;
		}

		// Prevent duplicate processing.
		$cache_key = 'surecontact_cartflows_pco_accepted_' . $checkout_id . '_' . $cart_hash;
		if ( get_transient( $cache_key ) ) {
			return;
		}

		// Get flow ID.
		$flow_id = wp_get_post_parent_id( $checkout_id );
		if ( ! $flow_id ) {
			$flow_id = (int) get_post_meta( $checkout_id, 'wcf-flow-id', true );
		}

		// Get product info.
		$pco_product_meta = get_post_meta( $checkout_id, 'wcf-pre-checkout-offer-product', true );
		$product_id       = is_array( $pco_product_meta ) && ! empty( $pco_product_meta[0] ) ? (int) $pco_product_meta[0] : 0;
		$product_name     = $product_id ? get_the_title( $product_id ) : '';

		// Identify the visitor's email.
		$email = $this->get_visitor_email( $flow_id ? $flow_id : null );

		if ( empty( $email ) ) {
			Logger::info(
				'CartFlows Integration',
				"Pre-checkout offer accepted but no email available. Checkout: {$checkout_id}"
			);
			return;
		}

		// Get actions for this pre-checkout offer.
		$actions = $this->get_actions_for_pre_checkout_offer( $checkout_id );

		// Check if any actions to perform.
		$has_actions = ! empty( $actions['add_lists'] ) || ! empty( $actions['add_tags'] )
			|| ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] );

		if ( ! $has_actions ) {
			Logger::info( 'CartFlows Integration', 'No rules configured for pre-checkout offer acceptance' );
			return;
		}

		// Build raw data for field mapping.
		$flow_name = $flow_id ? get_the_title( $flow_id ) : '';
		$step_name = get_the_title( $checkout_id );

		$raw_data = array(
			'cf_flow_id'        => (string) $flow_id,
			'cf_flow_name'      => $flow_name,
			'cf_last_step_id'   => (string) $checkout_id,
			'cf_last_step_name' => $step_name,
			'cf_last_step_type' => 'checkout',
		);

		// Get or create contact.
		$contact_id = $this->get_or_create_contact_from_email( $email, $raw_data, $actions );

		if ( ! $contact_id ) {
			Logger::error( 'CartFlows Integration', 'Failed to get or create contact for pre-checkout offer' );
			return;
		}

		// Apply remove actions.
		if ( ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] ) ) {
			$this->apply_remove_actions( $contact_id, $actions );
		}

		// Mark as tracked.
		set_transient( $cache_key, true, DAY_IN_SECONDS );

		Logger::info(
			'CartFlows Integration',
			"Pre-checkout offer accepted: Checkout {$checkout_id}, Product: {$product_name}, Email: {$email}"
		);
	}

	/**
	 * Handle optin step completion
	 *
	 * Optin orders redirect to the next step instead of the thank-you page,
	 * so handle_funnel_completed never fires. This catches optin completions
	 * via the WooCommerce checkout order processed hook.
	 *
	 * @param int       $order_id    Order ID.
	 * @param array     $posted_data Posted checkout data.
	 * @param \WC_Order $order       WooCommerce order object.
	 * @return void
	 */
	public function handle_optin_completed( $order_id, $posted_data, $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Only process optin orders (CartFlows sets _wcf_optin_id on optin orders).
		$optin_id = $order->get_meta( '_wcf_optin_id' );
		if ( empty( $optin_id ) ) {
			return;
		}

		// Verify this is actually an optin step.
		$step_type = get_post_meta( $optin_id, 'wcf-step-type', true );
		if ( 'optin' !== $step_type ) {
			return;
		}

		// Prevent duplicate processing.
		$cache_key = 'surecontact_cartflows_optin_completed_' . $order_id;
		if ( get_transient( $cache_key ) ) {
			Logger::info( 'CartFlows Integration', "Optin completion already tracked for order: {$order_id}" );
			return;
		}

		// Get flow ID from order meta.
		$flow_id = $this->get_flow_id_from_order( $order );

		// Get step and flow details.
		$flow_name = $flow_id ? get_the_title( $flow_id ) : '';
		$step_name = get_the_title( $optin_id );

		// Build customer data from order.
		$email = $order->get_billing_email();
		if ( empty( $email ) ) {
			Logger::error( 'CartFlows Integration', "No email found in optin order: {$order_id}" );
			return;
		}

		// Get actions for optin step completion.
		$actions = $this->get_actions_for_step_completion( (int) $optin_id, 'optin' );

		// Check if any actions to perform.
		$has_actions = ! empty( $actions['add_lists'] ) || ! empty( $actions['add_tags'] )
			|| ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] );

		if ( ! $has_actions ) {
			Logger::info( 'CartFlows Integration', 'No rules configured for optin completion' );
			return;
		}

		// Build raw data for field mapping.
		$raw_data = array(
			'cf_flow_id'        => (string) $flow_id,
			'cf_flow_name'      => $flow_name,
			'cf_last_step_id'   => (string) $optin_id,
			'cf_last_step_name' => $step_name,
			'cf_last_step_type' => 'optin',
		);

		// Get or create contact.
		$contact_id = $this->get_or_create_contact_from_order( $order, $raw_data, $actions );

		if ( ! $contact_id ) {
			Logger::error( 'CartFlows Integration', 'Failed to get or create contact for optin completion' );
			return;
		}

		// Apply remove actions.
		if ( ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] ) ) {
			$this->apply_remove_actions( $contact_id, $actions );
		}

		// Mark as tracked.
		set_transient( $cache_key, true, DAY_IN_SECONDS );

		Logger::info(
			'CartFlows Integration',
			"Optin completion tracked for order: {$order_id}, Flow: {$flow_name}, Step: {$step_name}"
		);
	}

	/**
	 * Get visitor email from available sources.
	 *
	 * Tries to identify the current visitor's email using multiple sources
	 * in the following priority order:
	 * 1. URL Parameters (highest priority)
	 * 2. CartFlows Pro Session (Pro Only)
	 * 3. WooCommerce Customer Object
	 * 4. WooCommerce Session Data
	 * 5. Logged-in WordPress User (lowest priority)
	 *
	 * @since 1.1.0
	 *
	 * @param int|null $flow_id Optional flow ID for CartFlows Pro session lookup.
	 * @return string|null Email address or null if not found.
	 */
	public function get_visitor_email( $flow_id = null ) {
		$email = null;

		// Priority 1: URL Parameters.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$url_params = array( 'billing_email', 'email', 'subscriber', 'e', 'contact_email' );
		foreach ( $url_params as $param ) {
			if ( isset( $_GET[ $param ] ) && ! empty( $_GET[ $param ] ) ) {
				$email = sanitize_email( wp_unslash( $_GET[ $param ] ) );
				if ( is_email( $email ) ) {
					Logger::info( 'CartFlows Integration', "Email identified from URL parameter: {$param}" );
					return $email;
				}
				$email = null;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Priority 2: CartFlows Pro Session (if available).
		if ( $this->is_cartflows_pro_active() && ! empty( $flow_id ) && class_exists( 'Cartflows_Pro_Session' ) ) {
			$session      = \Cartflows_Pro_Session::get_instance();
			$session_data = $session->get_data( $flow_id );

			if ( is_array( $session_data ) && ! empty( $session_data['billing_email'] ) ) {
				$email = sanitize_email( $session_data['billing_email'] );
				if ( is_email( $email ) ) {
					Logger::info( 'CartFlows Integration', 'Email identified from CartFlows Pro session' );
					return $email;
				}
				$email = null;
			}
		}

		// Priority 3: WooCommerce Customer Object.
		if ( function_exists( 'WC' ) ) {
			$wc_instance = WC();
			if ( $wc_instance && $wc_instance->customer ) {
				// Try account email first.
				$email = $wc_instance->customer->get_email();
				if ( ! empty( $email ) && is_email( $email ) ) {
					Logger::info( 'CartFlows Integration', 'Email identified from WooCommerce customer account' );
					return $email;
				}

				// Try billing email.
				$email = $wc_instance->customer->get_billing_email();
				if ( ! empty( $email ) && is_email( $email ) ) {
					Logger::info( 'CartFlows Integration', 'Email identified from WooCommerce customer billing' );
					return $email;
				}
				$email = null;
			}
		}

		// Priority 4: WooCommerce Session Data.
		if ( function_exists( 'WC' ) ) {
			$wc_instance = WC();
			if ( $wc_instance && $wc_instance->session ) {
				$customer_data = $wc_instance->session->get( 'customer' );
				if ( is_array( $customer_data ) && ! empty( $customer_data['email'] ) ) {
					$email = sanitize_email( $customer_data['email'] );
					if ( is_email( $email ) ) {
						Logger::info( 'CartFlows Integration', 'Email identified from WooCommerce session' );
						return $email;
					}
					$email = null;
				}
			}
		}

		// Priority 5: Logged-in WordPress User.
		if ( is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$email = $user->user_email;
			if ( ! empty( $email ) && is_email( $email ) ) {
				Logger::info( 'CartFlows Integration', 'Email identified from logged-in WordPress user' );
				return $email;
			}
		}

		return null;
	}

	/**
	 * Get actions for step started event
	 *
	 * @since 1.1.0
	 *
	 * @param int|null    $step_id   Step ID.
	 * @param string|null $step_type Step type (landing, checkout, thankyou).
	 * @return array Actions.
	 */
	private function get_actions_for_step_started( $step_id, $step_type = null ) {
		$actions = array(
			'add_lists'    => array(),
			'add_tags'     => array(),
			'remove_lists' => array(),
			'remove_tags'  => array(),
		);

		// Priority 1: Specific step config with 'started' event.
		if ( ! empty( $step_id ) ) {
			$step_result = $this->integrations_db->get( $this->slug, (string) $step_id, 'step', 'started' );
			if ( $this->has_valid_config( $step_result ) && isset( $step_result['config'] ) ) {
				return $this->merge_config_defaults( $step_result['config'] );
			}
		}

		// Priority 2: All steps of this type config (e.g., all_checkout, all_landing, all_thankyou).
		if ( ! empty( $step_type ) ) {
			$type_item_name   = 'all_' . $step_type;
			$type_step_result = $this->integrations_db->get( $this->slug, $type_item_name, 'step', 'started' );
			if ( $this->has_valid_config( $type_step_result ) && isset( $type_step_result['config'] ) ) {
				return $this->merge_config_defaults( $type_step_result['config'] );
			}
		}

		// Priority 3: All steps config with 'started' event.
		$all_steps_result = $this->integrations_db->get( $this->slug, 'all', 'step', 'started' );
		if ( $this->has_valid_config( $all_steps_result ) && isset( $all_steps_result['config'] ) ) {
			return $this->merge_config_defaults( $all_steps_result['config'] );
		}

		return $actions;
	}

	/**
	 * Get or create contact from email address
	 *
	 * @since 1.1.0
	 *
	 * @param string $email    Email address.
	 * @param array  $raw_data Raw data for field mapping.
	 * @param array  $actions  Actions containing add_lists and add_tags.
	 * @return string|null Contact ID or null.
	 */
	private function get_or_create_contact_from_email( $email, $raw_data = array(), $actions = array() ) {
		if ( empty( $email ) || ! is_email( $email ) ) {
			return null;
		}

		// Primary fields.
		$primary_fields = array(
			'email' => $email,
		);

		// Try to get additional info from logged-in user.
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( $user->user_email === $email ) {
				if ( ! empty( $user->first_name ) ) {
					$primary_fields['first_name'] = $user->first_name;
				}
				if ( ! empty( $user->last_name ) ) {
					$primary_fields['last_name'] = $user->last_name;
				}
			}
		}

		// Try to get additional info from WooCommerce customer.
		if ( function_exists( 'WC' ) ) {
			$wc_instance = WC();
			if ( $wc_instance && $wc_instance->customer ) {
				$wc_email = $wc_instance->customer->get_email();
				if ( $wc_email === $email || $wc_instance->customer->get_billing_email() === $email ) {
					$first_name = $wc_instance->customer->get_billing_first_name();
					$last_name  = $wc_instance->customer->get_billing_last_name();
					$phone      = $wc_instance->customer->get_billing_phone();

					if ( ! empty( $first_name ) && empty( $primary_fields['first_name'] ) ) {
						$primary_fields['first_name'] = $first_name;
					}
					if ( ! empty( $last_name ) && empty( $primary_fields['last_name'] ) ) {
						$primary_fields['last_name'] = $last_name;
					}
					if ( ! empty( $phone ) ) {
						$raw_data['phone'] = $phone;
					}
				}
			}
		}

		// Map fields.
		$mapped_data = $this->normalize_data( $raw_data );

		$contact_data = array(
			'primary_fields' => array_merge(
				$primary_fields,
				$mapped_data['primary_fields'] ?? array()
			),
			'custom_fields'  => $mapped_data['custom_fields'] ?? array(),
			'metadata'       => $mapped_data['metadata'] ?? array(),
		);

		// Add lists and tags.
		if ( ! empty( $actions['add_lists'] ) ) {
			$contact_data['list_uuids'] = $this->extract_uuids( $actions['add_lists'] );
		}
		if ( ! empty( $actions['add_tags'] ) ) {
			$contact_data['tag_uuids'] = $this->extract_uuids( $actions['add_tags'] );
		}

		// Get WP user ID if email matches current user.
		$user_id = 0;
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( $user->user_email === $email ) {
				$user_id = $user->ID;
			}
		}

		// Create or update contact.
		$result = $this->contact_service->create_contact( $contact_data, $user_id );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		return $result['contact_uuid'] ?? $result['contact_id'] ?? null;
	}

	/**
	 * Get actions for step completion
	 *
	 * @since 1.1.0
	 *
	 * @param int|null    $step_id   Step ID (e.g., checkout step).
	 * @param string|null $step_type Step type (landing, checkout, thankyou).
	 * @return array Actions.
	 */
	private function get_actions_for_step_completion( $step_id, $step_type = null ) {
		$actions = array(
			'add_lists'    => array(),
			'add_tags'     => array(),
			'remove_lists' => array(),
			'remove_tags'  => array(),
		);

		// Priority 1: Specific step config with 'completed' event.
		if ( ! empty( $step_id ) ) {
			$step_result = $this->integrations_db->get( $this->slug, (string) $step_id, 'step', 'completed' );
			if ( ! $this->has_valid_config( $step_result ) ) {
				$step_result = $this->integrations_db->get( $this->slug, (string) $step_id, 'step', null );
			}
			if ( $this->has_valid_config( $step_result ) && isset( $step_result['config'] ) ) {
				return $this->merge_config_defaults( $step_result['config'] );
			}
		}

		// Priority 2: All steps of this type config (e.g., all_checkout, all_landing, all_thankyou).
		if ( ! empty( $step_type ) ) {
			$type_item_name   = 'all_' . $step_type;
			$type_step_result = $this->integrations_db->get( $this->slug, $type_item_name, 'step', 'completed' );
			if ( ! $this->has_valid_config( $type_step_result ) ) {
				$type_step_result = $this->integrations_db->get( $this->slug, $type_item_name, 'step', null );
			}
			if ( $this->has_valid_config( $type_step_result ) && isset( $type_step_result['config'] ) ) {
				return $this->merge_config_defaults( $type_step_result['config'] );
			}
		}

		// Priority 3: All steps config with 'completed' event.
		$all_steps_result = $this->integrations_db->get( $this->slug, 'all', 'step', 'completed' );
		if ( ! $this->has_valid_config( $all_steps_result ) ) {
			$all_steps_result = $this->integrations_db->get( $this->slug, 'all', 'step', null );
		}
		if ( $this->has_valid_config( $all_steps_result ) && isset( $all_steps_result['config'] ) ) {
			return $this->merge_config_defaults( $all_steps_result['config'] );
		}

		return $actions;
	}

	/**
	 * Get actions for offer (upsell/downsell)
	 *
	 * @since 1.1.0
	 *
	 * @param int    $step_id    Step ID.
	 * @param string $offer_type Offer type ('upsell' or 'downsell').
	 * @param string $event      Event ('accepted' or 'rejected').
	 * @return array Actions.
	 */
	private function get_actions_for_offer( $step_id, $offer_type, $event ) {
		$actions = array(
			'add_lists'    => array(),
			'add_tags'     => array(),
			'remove_lists' => array(),
			'remove_tags'  => array(),
		);

		// Priority 1: Specific offer step config.
		if ( ! empty( $step_id ) ) {
			$step_result = $this->integrations_db->get( $this->slug, (string) $step_id, $offer_type, $event );
			if ( ! $this->has_valid_config( $step_result ) ) {
				$step_result = $this->integrations_db->get( $this->slug, (string) $step_id, $offer_type, null );
			}
			if ( $this->has_valid_config( $step_result ) && isset( $step_result['config'] ) ) {
				return $this->merge_config_defaults( $step_result['config'] );
			}
		}

		// Priority 2: All offers of this type.
		$all_offers_result = $this->integrations_db->get( $this->slug, 'all', $offer_type, $event );
		if ( ! $this->has_valid_config( $all_offers_result ) ) {
			$all_offers_result = $this->integrations_db->get( $this->slug, 'all', $offer_type, null );
		}
		if ( $this->has_valid_config( $all_offers_result ) && isset( $all_offers_result['config'] ) ) {
			return $this->merge_config_defaults( $all_offers_result['config'] );
		}

		return $actions;
	}

	/**
	 * Get actions for order bump
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $ob_id Order bump ID (e.g., 'ob-{uuid}').
	 * @return array Actions.
	 */
	private function get_actions_for_order_bump( $ob_id ) {
		$actions = array(
			'add_lists'    => array(),
			'add_tags'     => array(),
			'remove_lists' => array(),
			'remove_tags'  => array(),
		);

		// Priority 1: Specific order bump config.
		if ( ! empty( $ob_id ) ) {
			$bump_result = $this->integrations_db->get( $this->slug, (string) $ob_id, 'order_bump', 'added' );
			if ( ! $this->has_valid_config( $bump_result ) ) {
				$bump_result = $this->integrations_db->get( $this->slug, (string) $ob_id, 'order_bump', null );
			}
			if ( $this->has_valid_config( $bump_result ) && isset( $bump_result['config'] ) ) {
				return $this->merge_config_defaults( $bump_result['config'] );
			}
		}

		// Priority 2: All order bumps.
		$all_bumps_result = $this->integrations_db->get( $this->slug, 'all', 'order_bump', 'added' );
		if ( ! $this->has_valid_config( $all_bumps_result ) ) {
			$all_bumps_result = $this->integrations_db->get( $this->slug, 'all', 'order_bump', null );
		}
		if ( $this->has_valid_config( $all_bumps_result ) && isset( $all_bumps_result['config'] ) ) {
			return $this->merge_config_defaults( $all_bumps_result['config'] );
		}

		return $actions;
	}

	/**
	 * Get actions for pre-checkout offer
	 *
	 * @param int $checkout_id Checkout step ID where the pre-checkout offer is configured.
	 * @return array Actions.
	 */
	private function get_actions_for_pre_checkout_offer( $checkout_id ) {
		$actions = array(
			'add_lists'    => array(),
			'add_tags'     => array(),
			'remove_lists' => array(),
			'remove_tags'  => array(),
		);

		// Priority 1: Specific checkout step config.
		if ( ! empty( $checkout_id ) ) {
			$step_result = $this->integrations_db->get( $this->slug, (string) $checkout_id, 'pre_checkout_offer', 'accepted' );
			if ( ! $this->has_valid_config( $step_result ) ) {
				$step_result = $this->integrations_db->get( $this->slug, (string) $checkout_id, 'pre_checkout_offer', null );
			}
			if ( $this->has_valid_config( $step_result ) && isset( $step_result['config'] ) ) {
				return $this->merge_config_defaults( $step_result['config'] );
			}
		}

		// Priority 2: All pre-checkout offers.
		$all_result = $this->integrations_db->get( $this->slug, 'all', 'pre_checkout_offer', 'accepted' );
		if ( ! $this->has_valid_config( $all_result ) ) {
			$all_result = $this->integrations_db->get( $this->slug, 'all', 'pre_checkout_offer', null );
		}
		if ( $this->has_valid_config( $all_result ) && isset( $all_result['config'] ) ) {
			return $this->merge_config_defaults( $all_result['config'] );
		}

		return $actions;
	}

	/**
	 * Get or create contact from order data
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order $order     WooCommerce order.
	 * @param array     $raw_data  Raw data for field mapping.
	 * @param array     $actions   Actions containing add_lists and add_tags.
	 * @return string|null Contact ID or null.
	 */
	private function get_or_create_contact_from_order( $order, $raw_data = array(), $actions = array() ) {
		$email      = $order->get_billing_email();
		$first_name = $order->get_billing_first_name();
		$last_name  = $order->get_billing_last_name();

		if ( empty( $email ) ) {
			return null;
		}

		// Primary fields.
		$primary_fields = array(
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
		);

		// Add phone if available.
		$phone = $order->get_billing_phone();
		if ( ! empty( $phone ) ) {
			$raw_data['phone'] = $phone;
		}

		// Map fields.
		$mapped_data = $this->normalize_data( $raw_data );

		$contact_data = array(
			'primary_fields' => array_merge(
				$primary_fields,
				$mapped_data['primary_fields'] ?? array()
			),
			'custom_fields'  => $mapped_data['custom_fields'] ?? array(),
			'metadata'       => $mapped_data['metadata'] ?? array(),
		);

		// Add lists and tags.
		if ( ! empty( $actions['add_lists'] ) ) {
			$contact_data['list_uuids'] = $this->extract_uuids( $actions['add_lists'] );
		}
		if ( ! empty( $actions['add_tags'] ) ) {
			$contact_data['tag_uuids'] = $this->extract_uuids( $actions['add_tags'] );
		}

		// Get WP user ID if available.
		$user_id = $order->get_user_id();

		// Create or update contact.
		$result = $this->contact_service->create_contact( $contact_data, $user_id );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		return $result['contact_uuid'] ?? $result['contact_id'] ?? null;
	}

	/**
	 * Check if CartFlows Pro is active
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	private function is_cartflows_pro_active() {
		return class_exists( 'Cartflows_Pro_Loader' );
	}

	/**
	 * Get flow ID from order
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return int|null Flow ID or null.
	 */
	private function get_flow_id_from_order( $order ) {
		$flow_id = $order->get_meta( '_wcf_flow_id' );
		if ( ! empty( $flow_id ) ) {
			return (int) $flow_id;
		}

		// Try alternative meta key.
		$flow_id = $order->get_meta( '_cartflows_flow_id' );
		return ! empty( $flow_id ) ? (int) $flow_id : null;
	}

	/**
	 * Get checkout step ID from order
	 *
	 * @since 1.1.0
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return int|null Checkout step ID or null.
	 */
	private function get_checkout_id_from_order( $order ) {
		$checkout_id = $order->get_meta( '_cartflows_checkout_id' );
		if ( ! empty( $checkout_id ) ) {
			return (int) $checkout_id;
		}

		// Try using CartFlows utility if available.
		if ( function_exists( 'wcf' ) ) {
			$wcf_instance = wcf();
			if ( is_object( $wcf_instance ) && isset( $wcf_instance->utils ) && is_object( $wcf_instance->utils ) && method_exists( $wcf_instance->utils, 'get_checkout_id_from_order' ) ) {
				return $wcf_instance->utils->get_checkout_id_from_order( $order );
			}
		}

		return null;
	}

	/**
	 * Get current step ID from global context
	 *
	 * @since 1.1.0
	 *
	 * @return int|null Step ID or null.
	 */
	private function get_current_step_id() {
		global $post;

		// Check if we're on a CartFlows step.
		if ( $post && 'cartflows_step' === $post->post_type ) {
			return $post->ID;
		}

		// Try CartFlows global.
		if ( function_exists( 'wcf' ) ) {
			$wcf_instance = wcf();
			if ( is_object( $wcf_instance ) && isset( $wcf_instance->utils ) && is_object( $wcf_instance->utils ) && method_exists( $wcf_instance->utils, 'get_current_step_id' ) ) {
				return $wcf_instance->utils->get_current_step_id();
			}
		}

		return null;
	}

	/**
	 * Get offer type from step
	 *
	 * @since 1.1.0
	 *
	 * @param int $step_id Step ID.
	 * @return string Offer type ('upsell' or 'downsell').
	 */
	private function get_offer_type_from_step( $step_id ) {
		if ( empty( $step_id ) ) {
			return 'upsell';
		}

		$step_type = get_post_meta( $step_id, 'wcf-step-type', true );

		if ( 'downsell' === $step_type ) {
			return 'downsell';
		}

		return 'upsell';
	}

	/**
	 * Add CartFlows product type suffix to WooCommerce e-commerce tracking product names.
	 *
	 * Appends (Upsell), (Downsell), or (Order Bump) to product names
	 * when tracked via WooCommerce e-commerce integration.
	 *
	 * @since 1.1.0
	 *
	 * @param string                 $name  Product name.
	 * @param \WC_Order_Item_Product $item  Order item.
	 * @param \WC_Order              $order Order object.
	 * @return string Modified product name with CartFlows suffix.
	 */
	public function add_cartflows_product_suffix( $name, $item, $order ) {
		// Check per-item meta for upsells/downsells (set by CartFlows Pro on the order item).
		if ( 'yes' === $item->get_meta( '_cartflows_upsell' ) ) {
			return $name . ' (Upsell)';
		}

		if ( 'yes' === $item->get_meta( '_cartflows_downsell' ) ) {
			return $name . ' (Downsell)';
		}

		if ( 'yes' === $item->get_meta( '_cartflows_pre_checkout_offer' ) ) {
			return $name . ' (Checkout Offer)';
		}

		// Check if this is a separate CartFlows offer order (all items are upsell/downsell).
		$offer_type = $order->get_meta( '_cartflows_offer_type' );
		if ( ! empty( $offer_type ) ) {
			$suffix = 'downsell' === $offer_type ? 'Downsell' : 'Upsell';
			return $name . ' (' . $suffix . ')';
		}

		// Check if product is an order bump via _wcf_bump_products order meta.
		$bump_products = $order->get_meta( '_wcf_bump_products' );
		if ( ! empty( $bump_products ) && is_array( $bump_products ) ) {
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();

			foreach ( $bump_products as $bump_data ) {
				$bump_id = isset( $bump_data['id'] ) ? (int) $bump_data['id'] : 0;
				if ( $bump_id && ( $bump_id === $product_id || $bump_id === $variation_id ) ) {
					return $name . ' (Order Bump)';
				}
			}
		}

		return $name;
	}

	/**
	 * Persist pre-checkout offer cart item data as order item meta.
	 *
	 * CartFlows Pro sets cartflows_pre_checkout_offer in cart item data but does not
	 * persist it to order item meta. This hook saves it so e-commerce tracking can
	 * append the "(Checkout Offer)" suffix to the product name.
	 *
	 * @since 1.4.0
	 *
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $values        Cart item values.
	 * @param \WC_Order              $order         Order object.
	 */
	public function persist_pco_order_item_meta( $item, $cart_item_key, $values, $order ): void {
		if ( ! empty( $values['cartflows_pre_checkout_offer'] ) ) {
			$item->add_meta_data( '_cartflows_pre_checkout_offer', 'yes', true );
		}
	}

	/**
	 * Get CartFlows steps list.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $step_type Optional step type filter.
	 * @return array Array of step items.
	 */
	public function get_steps( $step_type = null ) {
		$items = array();

		// Only add "All" options when getting steps for the "step" item type (no type filter).
		// When called with a specific type filter (upsell, downsell, checkout),
		// we're getting steps for other item types that have their own "All" options.
		if ( empty( $step_type ) ) {
			$items = array(
				array(
					'id'        => 'all',
					'title'     => __( 'All Steps', 'surecontact' ),
					'type'      => 'step',
					'step_type' => null,
				),
				array(
					'id'        => 'all_landing',
					'title'     => __( 'All Landing Pages', 'surecontact' ),
					'type'      => 'step',
					'step_type' => 'landing',
				),
				array(
					'id'        => 'all_checkout',
					'title'     => __( 'All Checkouts', 'surecontact' ),
					'type'      => 'step',
					'step_type' => 'checkout',
				),
				array(
					'id'        => 'all_thankyou',
					'title'     => __( 'All Thank You Pages', 'surecontact' ),
					'type'      => 'step',
					'step_type' => 'thankyou',
				),
				array(
					'id'        => 'all_optin',
					'title'     => __( 'All Optin Pages', 'surecontact' ),
					'type'      => 'step',
					'step_type' => 'optin',
				),
			);
		}

		$args = array(
			'post_type'      => 'cartflows_step',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( $step_type ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to filter steps by type.
			$args['meta_query'] = array(
				array(
					'key'   => 'wcf-step-type',
					'value' => $step_type,
				),
			);
		}

		$steps = get_posts( $args );

		foreach ( $steps as $step ) {
			$type      = get_post_meta( $step->ID, 'wcf-step-type', true );
			$flow_id   = wp_get_post_parent_id( $step->ID );
			$flow_name = $flow_id ? get_the_title( $flow_id ) : '';

			// Skip upsell/downsell steps when getting general steps (they have their own item types).
			// But don't skip when we're specifically filtering for upsell or downsell.
			if ( empty( $step_type ) && in_array( $type, array( 'upsell', 'downsell' ), true ) ) {
				continue;
			}

			$title = $step->post_title ? $step->post_title : __( 'Untitled Step', 'surecontact' );
			if ( $flow_name ) {
				$title = $flow_name . ' - ' . $title;
			}

			$items[] = array(
				'id'        => $step->ID,
				'title'     => $title,
				'type'      => 'step',
				'step_type' => $type,
				'flow_id'   => $flow_id,
			);
		}

		return $items;
	}

	/**
	 * Get upsell steps list.
	 *
	 * @since 1.1.0
	 *
	 * @return array Array of upsell items.
	 */
	public function get_upsells() {
		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Upsells', 'surecontact' ),
				'type'  => 'upsell',
			),
		);

		$steps = $this->get_steps( 'upsell' );
		foreach ( $steps as $step ) {
			$step['type'] = 'upsell';
			$items[]      = $step;
		}

		return $items;
	}

	/**
	 * Get downsell steps list.
	 *
	 * @since 1.1.0
	 *
	 * @return array Array of downsell items.
	 */
	public function get_downsells() {
		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Downsells', 'surecontact' ),
				'type'  => 'downsell',
			),
		);

		$steps = $this->get_steps( 'downsell' );
		foreach ( $steps as $step ) {
			$step['type'] = 'downsell';
			$items[]      = $step;
		}

		return $items;
	}

	/**
	 * Get order bumps list (individual order bumps from checkout steps).
	 *
	 * @since 1.1.0
	 *
	 * @return array Array of order bump items.
	 */
	public function get_order_bumps() {
		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Order Bumps', 'surecontact' ),
				'type'  => 'order_bump',
			),
		);

		// Get checkout steps (order bumps are configured on checkout steps).
		$checkout_steps = $this->get_steps( 'checkout' );
		foreach ( $checkout_steps as $step ) {
			// Get order bumps array from the checkout step (CartFlows Pro stores multiple OBs per step).
			$order_bumps = get_post_meta( $step['id'], 'wcf-order-bumps', true );

			if ( ! is_array( $order_bumps ) || empty( $order_bumps ) ) {
				continue;
			}

			foreach ( $order_bumps as $ob_data ) {
				if ( empty( $ob_data['id'] ) ) {
					continue;
				}

				$ob_title = ! empty( $ob_data['title'] ) ? $ob_data['title'] : __( 'Untitled Order Bump', 'surecontact' );

				// Include the checkout step name for context.
				$full_title = $step['title'] . ' - ' . $ob_title;

				$items[] = array(
					'id'          => $ob_data['id'],
					'title'       => $full_title,
					'type'        => 'order_bump',
					'checkout_id' => $step['id'],
					'flow_id'     => $step['flow_id'] ?? null,
				);
			}
		}

		return $items;
	}

	/**
	 * Get pre-checkout offers list.
	 *
	 * Pre-checkout offers are configured on checkout steps in CartFlows Pro.
	 *
	 * @return array Array of pre-checkout offer items.
	 */
	public function get_pre_checkout_offers() {
		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Pre-Checkout Offers', 'surecontact' ),
				'type'  => 'pre_checkout_offer',
			),
		);

		// Get checkout steps that have pre-checkout offer enabled.
		$args = array(
			'post_type'      => 'cartflows_step',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to filter checkout steps with pre-checkout offers.
			'meta_query'     => array(
				array(
					'key'   => 'wcf-step-type',
					'value' => 'checkout',
				),
				array(
					'key'   => 'wcf-pre-checkout-offer',
					'value' => 'yes',
				),
			),
		);

		$steps = get_posts( $args );

		foreach ( $steps as $step ) {
			$flow_id   = wp_get_post_parent_id( $step->ID );
			$flow_name = $flow_id ? get_the_title( $flow_id ) : '';

			$title = $step->post_title ? $step->post_title : __( 'Untitled Checkout', 'surecontact' );
			if ( $flow_name ) {
				$title = $flow_name . ' - ' . $title;
			}

			$items[] = array(
				'id'          => $step->ID,
				'title'       => $title,
				'type'        => 'pre_checkout_offer',
				'checkout_id' => $step->ID,
				'flow_id'     => $flow_id,
			);
		}

		return $items;
	}

	/**
	 * Get item title by type and ID.
	 *
	 * @since 1.1.0
	 *
	 * @param string $item_id   Item ID.
	 * @param string $item_type Item type.
	 * @return string|null Item title or null if not found.
	 */
	public function get_item_title( $item_id, $item_type ) {
		// Handle "all" options.
		if ( 'all' === $item_id ) {
			switch ( $item_type ) {
				case 'step':
					return __( 'All Steps', 'surecontact' );
				case 'upsell':
					return __( 'All Upsells', 'surecontact' );
				case 'downsell':
					return __( 'All Downsells', 'surecontact' );
				case 'order_bump':
					return __( 'All Order Bumps', 'surecontact' );
				case 'pre_checkout_offer':
					return __( 'All Pre-Checkout Offers', 'surecontact' );
				default:
					return null;
			}
		}

		// Handle step type-specific "all" options.
		if ( 'step' === $item_type && strpos( $item_id, 'all_' ) === 0 ) {
			switch ( $item_id ) {
				case 'all_landing':
					return __( 'All Landing Pages', 'surecontact' );
				case 'all_checkout':
					return __( 'All Checkouts', 'surecontact' );
				case 'all_thankyou':
					return __( 'All Thank You Pages', 'surecontact' );
				case 'all_optin':
					return __( 'All Optin Pages', 'surecontact' );
				default:
					return null;
			}
		}

		// Pre-checkout offers use checkout step post IDs.
		if ( 'pre_checkout_offer' === $item_type ) {
			return $this->get_pre_checkout_offer_title( (int) $item_id );
		}

		// Order bumps have string IDs (e.g., 'ob-{uuid}'), not post IDs.
		if ( 'order_bump' === $item_type ) {
			return $this->get_order_bump_title( $item_id );
		}

		// Get post title.
		$post_id = (int) $item_id;
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$title = $post->post_title ? $post->post_title : __( 'Untitled', 'surecontact' );

		// Add flow name for steps.
		if ( in_array( $item_type, array( 'step', 'upsell', 'downsell' ), true ) ) {
			$flow_id   = wp_get_post_parent_id( $post_id );
			$flow_name = $flow_id ? get_the_title( $flow_id ) : '';
			if ( $flow_name ) {
				$title = $flow_name . ' - ' . $title;
			}
		}

		return $title;
	}

	/**
	 * Get pre-checkout offer title by checkout step ID.
	 *
	 * @param int $checkout_id Checkout step ID.
	 * @return string|null Title or null if not found.
	 */
	private function get_pre_checkout_offer_title( $checkout_id ) {
		$post = get_post( $checkout_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$title     = $post->post_title ? $post->post_title : __( 'Untitled Checkout', 'surecontact' );
		$flow_id   = wp_get_post_parent_id( $checkout_id );
		$flow_name = $flow_id ? get_the_title( $flow_id ) : '';

		if ( $flow_name ) {
			$title = $flow_name . ' - ' . $title;
		}

		return $title;
	}

	/**
	 * Get order bump title by its ID.
	 *
	 * Order bumps are stored in checkout step meta, not as separate posts.
	 *
	 * @since 1.1.0
	 *
	 * @param string $ob_id Order bump ID (e.g., 'ob-{uuid}').
	 * @return string|null Order bump title or null if not found.
	 */
	private function get_order_bump_title( $ob_id ) {
		// Search through all checkout steps to find this order bump.
		$checkout_steps = $this->get_steps( 'checkout' );

		foreach ( $checkout_steps as $step ) {
			$order_bumps = get_post_meta( $step['id'], 'wcf-order-bumps', true );

			if ( ! is_array( $order_bumps ) ) {
				continue;
			}

			foreach ( $order_bumps as $ob_data ) {
				if ( isset( $ob_data['id'] ) && $ob_data['id'] === $ob_id ) {
					$ob_title = ! empty( $ob_data['title'] ) ? $ob_data['title'] : __( 'Untitled Order Bump', 'surecontact' );
					return $step['title'] . ' - ' . $ob_title;
				}
			}
		}

		return null;
	}
}
