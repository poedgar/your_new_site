<?php
/**
 * WooCommerce Abandoned Cart
 *
 * Handles WooCommerce-specific cart tracking hooks for abandoned cart detection.
 * Delegates all business logic to Abandoned_Cart_Manager.
 *
 * @since 1.5.0
 *
 * @package SureContact
 */

namespace SureContact\Integrations;

use SureContact\Abandoned_Cart_Manager;
use SureContact\Traits\Abandoned_Cart_Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Woocommerce_Abandoned_Cart
 *
 * Registers WooCommerce hooks for cart tracking and recovery.
 *
 * @since 1.5.0
 */
class Woocommerce_Abandoned_Cart {

	use Abandoned_Cart_Helpers;


	/**
	 * WooCommerce Integration instance.
	 *
	 * @since 1.5.0
	 *
	 * @var WooCommerce_Integration
	 */
	private $integration;

	/**
	 * Abandoned Cart Manager instance.
	 *
	 * @since 1.5.0
	 *
	 * @var Abandoned_Cart_Manager
	 */
	private $manager;

	/**
	 * Integration slug.
	 *
	 * @since 1.5.0
	 *
	 * @var string
	 */
	const INTEGRATION_SLUG = 'woocommerce';

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 *
	 * @param WooCommerce_Integration $integration Parent integration instance.
	 * @param Abandoned_Cart_Manager  $manager     Shared manager instance.
	 */
	public function __construct( WooCommerce_Integration $integration, Abandoned_Cart_Manager $manager ) {
		$this->integration = $integration;
		$this->manager     = $manager;

		$this->register_hooks();
	}

	/**
	 * Register WooCommerce hooks for cart tracking.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	private function register_hooks() {
		// Cart changes (logged-in users only — guests handled via checkout email capture).
		add_action( 'woocommerce_add_to_cart', array( $this, 'track_cart' ) );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'track_cart' ) );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'track_cart' ) );

		// Checkout email capture (sole entry point for guests).
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'capture_checkout_email' ) );

		// Recovery on order completion.
		// Priority 5 (before default 10) so the row is flipped to recovered and
		// abandoned tags are detached before any priority-10 analytics/email
		// hooks observe the contact's tag set.
		add_action( 'woocommerce_thankyou', array( $this, 'handle_cart_recovery' ), 5 );

		// GDPR: delete cart data when personal data is erased.
		add_action( 'woocommerce_delete_order_personal_data', array( $this, 'handle_gdpr_erasure' ) );
	}

	/**
	 * Track cart changes for logged-in users.
	 *
	 * Only acts for logged-in users who have an email. Guests are tracked
	 * via capture_checkout_email() when they enter their billing email.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function track_cart() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID || empty( $user->user_email ) ) {
			return;
		}

		$cart_data = $this->get_cart_data();

		// Empty cart = not an abandoned cart. Treat as recovery so any
		// already-tagged abandoned row is closed out and tags are detached
		// (otherwise the abandoned tag would stay on the contact forever).
		if ( empty( $cart_data ) ) {
			$this->recover_for_email( $user->user_email );
			return;
		}

		$cart_total = $this->get_cart_total();

		$this->manager->track_cart(
			$user->user_email,
			$user->ID,
			$cart_data,
			$cart_total,
			self::INTEGRATION_SLUG
		);
	}

	/**
	 * Capture billing email from checkout form and track cart for guests.
	 *
	 * WooCommerce fires woocommerce_checkout_update_order_review on every
	 * checkout field change via AJAX. The $posted_data is a URL-encoded string
	 * containing all form fields including billing_email.
	 *
	 * @since 1.5.0
	 *
	 * @param string $posted_data URL-encoded checkout form data.
	 * @return void
	 */
	public function capture_checkout_email( $posted_data ) {
		// Cheap early-exit: this hook fires on every keystroke during checkout.
		// Skip parse_str + cart walk when the payload doesn't even contain an
		// email field (e.g. when only shipping/billing-name fields changed).
		if ( ! is_string( $posted_data ) || false === strpos( $posted_data, 'billing_email=' ) ) {
			return;
		}

		$data = array();
		parse_str( wp_unslash( $posted_data ), $data );

		$email = isset( $data['billing_email'] ) && is_string( $data['billing_email'] ) ? sanitize_email( $data['billing_email'] ) : '';
		if ( empty( $email ) || ! is_email( $email ) ) {
			return;
		}

		$user_id = get_current_user_id();

		$cart_data = $this->get_cart_data();
		if ( empty( $cart_data ) ) {
			return;
		}

		$cart_total = $this->get_cart_total();

		$this->manager->track_cart(
			$email,
			$user_id,
			$cart_data,
			$cart_total,
			self::INTEGRATION_SLUG
		);
	}

	/**
	 * Handle cart recovery when an order is completed.
	 *
	 * Marks all active/abandoned rows as recovered and removes abandoned tags
	 * from contacts that were already tagged.
	 *
	 * @since 1.5.0
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function handle_cart_recovery( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Prevent duplicate recovery on thank-you page refresh.
		if ( $order->get_meta( '_surecontact_cart_recovered', true ) ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( empty( $email ) ) {
			return;
		}

		$order->update_meta_data( '_surecontact_cart_recovered', 'yes' );
		$order->save();

		$this->recover_for_email( $email );
	}

	/**
	 * Handle GDPR personal data erasure.
	 *
	 * @since 1.5.0
	 *
	 * @param \WC_Order $order Order being erased.
	 * @return void
	 */
	public function handle_gdpr_erasure( $order ) {
		$email = $order->get_billing_email();
		if ( ! empty( $email ) ) {
			\SureContact\Database\Abandoned_Carts_Operations::delete_by_email( $email );
		}
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Get current WooCommerce cart data as an array.
	 *
	 * @since 1.5.0
	 *
	 * @return array Cart data or empty array if cart is empty.
	 */
	private function get_cart_data() {
		if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
			return array();
		}

		$cart = WC()->cart;

		if ( $cart->is_empty() ) {
			return array();
		}

		$items = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$items[] = array(
				'product_id'   => $cart_item['product_id'],
				'variation_id' => $cart_item['variation_id'] ?? 0,
				'quantity'     => $cart_item['quantity'],
				'name'         => $product->get_name(),
				'price'        => $product->get_price(),
			);
		}

		return array(
			'items'    => $items,
			'currency' => get_woocommerce_currency(),
			'coupons'  => $cart->get_applied_coupons(),
		);
	}

	/**
	 * Get current WooCommerce cart total.
	 *
	 * @since 1.5.0
	 *
	 * @return float Cart total.
	 */
	private function get_cart_total() {
		if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
			return 0;
		}

		return (float) WC()->cart->get_total( 'edit' );
	}
}
