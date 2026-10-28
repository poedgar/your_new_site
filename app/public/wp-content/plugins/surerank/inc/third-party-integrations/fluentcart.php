<?php
/**
 * FluentCart integration
 *
 * Reads SEO-relevant product data from FluentCart so SureRank can output
 * Product schema, Open Graph product tags, and product meta variables for
 * the `fluent-products` post type.
 *
 * FluentCart stores products as a WordPress CPT (`fluent-products`) but keeps
 * pricing/stock in custom tables, exposed via its own models. Prices are stored
 * as integer cents. We read through FluentCart's public classes (guarded by
 * is_callable, referenced dynamically so the plugin remains an optional
 * dependency) and convert cents to a decimal string for output.
 *
 * @package surerank
 * @since 1.9.2
 */

namespace SureRank\Inc\ThirdPartyIntegrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\Functions\Helper;
use SureRank\Inc\Traits\Get_Instance;

/**
 * FluentCart integration class.
 *
 * @since 1.9.2
 */
class Fluentcart {

	use Get_Instance;

	/**
	 * FluentCart store settings option key.
	 */
	private const STORE_SETTINGS_OPTION = 'fluent_cart_store_settings';

	/**
	 * FluentCart product model (Eloquent-style).
	 */
	private const PRODUCT_MODEL = '\FluentCart\App\Models\Product';

	/**
	 * FluentCart currency settings API.
	 */
	private const CURRENCY_API = '\FluentCart\Api\CurrencySettings';

	/**
	 * FluentCart currency helper (cents -> decimal, zero-decimal aware).
	 */
	private const CURRENCY_HELPER = '\FluentCart\App\Helpers\CurrenciesHelper';

	/**
	 * Constructor.
	 *
	 * Registers frontend hooks. Only the instance side needs FluentCart active;
	 * the static data helpers are usable independently when called directly.
	 *
	 * @since 1.9.2
	 */
	public function __construct() {
		if ( ! Helper::fluentcart_status() ) {
			return;
		}

		add_filter( 'surerank_prep_post_meta', [ $this, 'prep_post_meta' ], 10, 4 );
		add_filter( 'fluent_cart/product_admin_items', [ $this, 'add_seo_admin_item' ], 10, 2 );
	}

	/**
	 * Add a "SEO" item to FluentCart's product editor sub-nav.
	 *
	 * FluentCart's product editor is a separate React app with no render slot
	 * for third-party panels, but its `fluent_cart/product_admin_items` filter
	 * accepts nav items. This links to the WordPress product edit screen where
	 * the SureRank SEO metabox/popup is available.
	 *
	 * @since 1.9.2
	 * @param mixed $items   FluentCart product admin nav items.
	 * @param mixed $context Filter context (includes `product_id`).
	 * @return mixed
	 */
	public function add_seo_admin_item( $items, $context = [] ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}

		$product_id = is_array( $context ) ? (int) ( $context['product_id'] ?? 0 ) : 0;

		if ( ! $product_id ) {
			return $items;
		}

		/* Open the SureRank SEO popup on the WP product edit screen. */
		$items['surerank_seo'] = [
			'label' => __( 'SEO', 'surerank' ),
			'link'  => add_query_arg(
				[
					'post'          => $product_id,
					'action'        => 'edit',
					'surerank_open' => 'true',
				],
				admin_url( 'post.php' )
			),
		];

		return $items;
	}

	/**
	 * Force noindex on FluentCart transactional pages (checkout, cart, receipt).
	 *
	 * The shop page is intentionally left indexable.
	 *
	 * @since 1.9.2
	 * @param array<string, mixed> $meta        Post meta.
	 * @param int                  $post_id     Post id.
	 * @param string               $post_type   Post type.
	 * @param bool                 $is_taxonomy Whether the object is a taxonomy term.
	 * @return array<string, mixed>
	 */
	public function prep_post_meta( $meta, $post_id, $post_type, $is_taxonomy ): array {
		if ( $is_taxonomy ) {
			return $meta;
		}

		$settings = get_option( self::STORE_SETTINGS_OPTION, [] );

		if ( ! is_array( $settings ) ) {
			return $meta;
		}

		$transactional_ids = [
			(int) ( $settings['checkout_page_id'] ?? 0 ),
			(int) ( $settings['cart_page_id'] ?? 0 ),
			(int) ( $settings['receipt_page_id'] ?? 0 ),
		];

		if ( ! in_array( (int) $post_id, $transactional_ids, true ) ) {
			return $meta;
		}

		if ( empty( $meta['post_no_index'] ) ) {
			$meta['post_no_index'] = 'yes';
		}

		return $meta;
	}

	/**
	 * Get the store's default currency code (e.g. "USD").
	 *
	 * @since 1.9.2
	 * @return string
	 */
	public static function get_currency() {
		$callback = [ self::CURRENCY_API, 'get' ];

		if ( ! is_callable( $callback ) ) {
			return '';
		}

		$currency = $callback( 'currency' );

		return is_string( $currency ) ? $currency : '';
	}

	/**
	 * Get SEO-relevant data for a FluentCart product by its WP post ID.
	 *
	 * @since 1.9.2
	 * @param int $post_id Product post ID.
	 * @return array<string, mixed>|null Normalized product data, or null if unavailable.
	 */
	public static function get_product_data( $post_id ) {
		$with = [ self::PRODUCT_MODEL, 'with' ];

		if ( ! Helper::fluentcart_status() || ! is_callable( $with ) ) {
			return null;
		}

		try {
			$query   = $with( [ 'detail', 'variants' ] );
			$product = $query->find( $post_id );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( empty( $product ) || empty( $product->detail ) ) {
			return null;
		}

		$detail   = $product->detail;
		$currency = self::get_currency();

		$low_price  = self::to_decimal( $detail->min_price ?? 0, $currency );
		$high_price = self::to_decimal( $detail->max_price ?? 0, $currency );
		$in_stock   = ( $detail->stock_availability ?? '' ) === 'in-stock';

		return [
			'price'        => $low_price,
			'low_price'    => $low_price,
			'high_price'   => $high_price,
			'currency'     => $currency,
			'availability' => $in_stock ? 'instock' : 'outofstock',
			'in_stock'     => $in_stock,
			'is_variable'  => $low_price !== $high_price,
			'sku'          => self::get_first_sku( $product ),
			'offer_count'  => self::get_variant_count( $product ),
		];
	}

	/**
	 * Convert a FluentCart cents value to a decimal price string.
	 *
	 * Uses FluentCart's own helper when present (handles zero-decimal
	 * currencies such as JPY); falls back to a simple cents->decimal divide.
	 *
	 * @since 1.9.2
	 * @param mixed  $cents    Amount in integer cents.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private static function to_decimal( $cents, $currency ) {
		if ( ! is_numeric( $cents ) ) {
			return '';
		}

		$callback = [ self::CURRENCY_HELPER, 'centsToDecimal' ];

		if ( is_callable( $callback ) ) {
			return (string) $callback( $cents, $currency );
		}

		return number_format( ( (float) $cents ) / 100, 2, '.', '' );
	}

	/**
	 * Get the SKU of a product's first variation.
	 *
	 * @since 1.9.2
	 * @param mixed $product FluentCart product model instance.
	 * @return string
	 */
	private static function get_first_sku( $product ) {
		if ( empty( $product->variants ) ) {
			return '';
		}

		$first = $product->variants->first();

		if ( empty( $first ) || empty( $first->sku ) ) {
			return '';
		}

		return (string) $first->sku;
	}

	/**
	 * Count a product's purchasable variations.
	 *
	 * @since 1.9.2
	 * @param mixed $product FluentCart product model instance.
	 * @return int
	 */
	private static function get_variant_count( $product ) {
		if ( empty( $product->variants ) ) {
			return 0;
		}

		$variants = $product->variants;

		if ( is_object( $variants ) && method_exists( $variants, 'count' ) ) {
			return (int) $variants->count();
		}

		return is_countable( $variants ) ? count( $variants ) : 0;
	}
}
