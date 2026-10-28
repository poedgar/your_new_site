<?php
/**
 * EDD Abandoned Cart
 *
 * Handles Easy Digital Downloads cart tracking hooks for abandoned cart detection.
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
 * Class EDD_Abandoned_Cart
 *
 * Registers EDD hooks for cart tracking and recovery.
 *
 * @since 1.5.0
 */
class EDD_Abandoned_Cart {

	use Abandoned_Cart_Helpers;


	/**
	 * EDD Integration instance.
	 *
	 * @since 1.5.0
	 *
	 * @var EDD_Integration
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
	const INTEGRATION_SLUG = 'easy_digital_downloads';

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 *
	 * @param EDD_Integration        $integration Parent integration instance.
	 * @param Abandoned_Cart_Manager $manager     Shared manager instance.
	 */
	public function __construct( EDD_Integration $integration, Abandoned_Cart_Manager $manager ) {
		$this->integration = $integration;
		$this->manager     = $manager;

		$this->register_hooks();
	}

	/**
	 * Register EDD hooks for cart tracking.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	private function register_hooks() {
		// Cart changes (logged-in users only — guests handled via checkout email capture).
		add_action( 'edd_post_add_to_cart', array( $this, 'track_cart' ) );
		add_action( 'edd_post_remove_from_cart', array( $this, 'track_cart' ) );
		add_action( 'edd_after_set_cart_item_quantity', array( $this, 'track_cart' ) );

		// Cart emptied — delete active row.
		add_action( 'edd_empty_cart', array( $this, 'handle_empty_cart' ) );

		// Checkout email capture (sole entry point for guests).
		add_action( 'edd_checkout_before_gateway', array( $this, 'capture_checkout_email' ), 10, 3 );

		// Recovery on order completion.
		// Priority 5 (before default 10 / EDD_Integration::handle_purchase_complete at 20)
		// so the row is flipped to recovered and abandoned tags are detached before
		// any other handler reads the contact's tag set.
		add_action( 'edd_complete_purchase', array( $this, 'handle_cart_recovery' ), 5 );

		// GDPR: delete cart data when an EDD customer is deleted/anonymized.
		add_action( 'edd_pre_delete_customer', array( $this, 'handle_gdpr_erasure' ) );
		add_action( 'edd_pre_anonymize_customer', array( $this, 'handle_gdpr_erasure' ) );
	}

	/**
	 * Track cart changes for logged-in users.
	 *
	 * Only acts for logged-in users who have an email. Guests are tracked
	 * via capture_checkout_email() when they submit the checkout form.
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
	 * Handle cart emptied event.
	 *
	 * Deletes any active abandoned cart row for the current logged-in user.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function handle_empty_cart() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID || empty( $user->user_email ) ) {
			return;
		}

		$this->recover_for_email( $user->user_email );
	}

	/**
	 * Capture email from checkout form and track cart.
	 *
	 * EDD fires edd_checkout_before_gateway after form validation but before
	 * payment processing. The $user_info array contains the parsed email.
	 * This is the sole entry point for guest cart tracking.
	 *
	 * @since 1.5.0
	 *
	 * @param array $post_data  The POST data from the checkout form.
	 * @param array $user_info  Parsed user information including email.
	 * @param array $valid_data Validated checkout data.
	 * @return void
	 */
	public function capture_checkout_email( $post_data, $user_info, $valid_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $valid_data required by edd_checkout_before_gateway hook.
		$email = isset( $user_info['email'] ) && is_string( $user_info['email'] ) ? sanitize_email( $user_info['email'] ) : '';
		if ( empty( $email ) || ! is_email( $email ) ) {
			return;
		}

		$cart_data = $this->get_cart_data();
		if ( empty( $cart_data ) ) {
			return;
		}

		$user_id    = get_current_user_id();
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
	 * @param int $order_id EDD order ID.
	 * @return void
	 */
	public function handle_cart_recovery( $order_id ) {
		if ( ! function_exists( 'edd_get_order' ) ) {
			return;
		}

		$order = edd_get_order( $order_id );
		if ( ! $order || empty( $order->email ) ) {
			return;
		}

		$this->recover_for_email( sanitize_email( $order->email ) );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Get current EDD cart data as an array.
	 *
	 * @since 1.5.0
	 *
	 * @return array Cart data or empty array if cart is empty.
	 */
	private function get_cart_data() {
		if ( ! function_exists( 'edd_get_cart_contents' ) ) {
			return array();
		}

		$contents = edd_get_cart_contents();

		if ( empty( $contents ) ) {
			return array();
		}

		$items = array();
		foreach ( $contents as $cart_item ) {
			$download_id = isset( $cart_item['id'] ) ? absint( $cart_item['id'] ) : 0;
			if ( ! $download_id ) {
				continue;
			}

			$items[] = array(
				'product_id' => $download_id,
				'quantity'   => isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 1,
				'name'       => get_the_title( $download_id ),
				'price'      => isset( $cart_item['item_price'] ) ? (float) $cart_item['item_price'] : 0.0,
				'options'    => isset( $cart_item['options'] ) ? $cart_item['options'] : array(),
			);
		}

		return array(
			'items'     => $items,
			'currency'  => function_exists( 'edd_get_currency' ) ? edd_get_currency() : 'USD',
			'discounts' => function_exists( 'edd_get_cart_discounts' ) ? edd_get_cart_discounts() : array(),
		);
	}

	/**
	 * Get current EDD cart total.
	 *
	 * @since 1.5.0
	 *
	 * @return float Cart total.
	 */
	private function get_cart_total() {
		if ( ! function_exists( 'edd_get_cart_total' ) ) {
			return 0;
		}

		return (float) edd_get_cart_total();
	}

	/**
	 * Handle GDPR personal data erasure for an EDD customer.
	 *
	 * Triggered by edd_pre_delete_customer / edd_pre_anonymize_customer.
	 * Cross-integration scope is intentional — GDPR right-to-erasure should wipe
	 * all PII for the user, not just the slice belonging to one integration.
	 *
	 * @since 1.5.0
	 *
	 * @param int $customer_id EDD customer ID.
	 * @return void
	 */
	public function handle_gdpr_erasure( $customer_id ) {
		if ( ! function_exists( 'edd_get_customer' ) ) {
			return;
		}

		$customer = edd_get_customer( $customer_id );
		if ( ! $customer || empty( $customer->email ) ) {
			return;
		}

		\SureContact\Database\Abandoned_Carts_Operations::delete_by_email( $customer->email );
	}
}
