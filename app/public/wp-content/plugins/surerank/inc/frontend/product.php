<?php
/**
 * Product Meta Data
 *
 * This file will handle functionality to print product-specific meta_data in the frontend for WooCommerce product pages.
 *
 * @package surerank
 * @since 0.0.1
 */

namespace SureRank\Inc\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\Functions\Helper;
use SureRank\Inc\ThirdPartyIntegrations\Fluentcart;
use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Traits\Reset_Meta_Data;

/**
 * Product SEO
 * This class will handle functionality to print product-specific meta_data in the frontend.
 *
 * @since 1.0.0
 */
class Product {

	use Get_Instance;
	use Reset_Meta_Data;

	/**
	 * Meta Data
	 *
	 * @var array<string, mixed>|null $meta_data Product meta data.
	 * @since 1.0.0
	 */
	private $meta_data = null;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		// Check if a supported e-commerce integration is active.
		if ( ! Helper::woocommerce_enabled() && ! Helper::fluentcart_enabled() && ! Helper::sc_status() ) {
			return;
		}
		// Set product-specific meta data.
		add_filter( 'surerank_set_meta', [ $this, 'get_meta_data' ], 1 );
		$this->register_meta_reset();
	}

	/**
	 * Get and set product-specific meta data
	 *
	 * @param array<string, mixed> $meta_data Meta Data.
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public function get_meta_data( $meta_data ) {
		if ( ! Helper::is_ecommerce_product_page() ) {
			return $meta_data;
		}

		$product_id = get_the_ID();
		if ( empty( $product_id ) ) {
			return $meta_data;
		}

		if ( null !== $this->meta_data ) {
			return $meta_data;
		}

		$this->meta_data = $this->prepare_product_meta( $product_id );

		if ( empty( $this->meta_data ) ) {
			return $meta_data;
		}

		if ( ! $meta_data ) {
			return $this->meta_data;
		}

		return array_merge( $meta_data, $this->meta_data );
	}

	/**
	 * Prepare product-specific meta data
	 *
	 * @param int $product_id Product ID.
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	private function prepare_product_meta( $product_id ) {
		// FluentCart products read from FluentCart's models (custom tables).
		if ( Helper::fluentcart_enabled() && Helper::is_fluentcart_product() ) {
			$data = Fluentcart::get_product_data( $product_id );

			if ( empty( $data ) ) {
				return [];
			}

			return [
				'product_price'        => $data['price'],
				'product_currency'     => $data['currency'],
				'product_availability' => $data['availability'],
			];
		}

		// SureCart products read from the `product` post meta (same source as schema).
		if ( Helper::sc_status() && Helper::is_sc_product() ) {
			return $this->prepare_surecart_meta( $product_id );
		}

		$product = \wc_get_product( $product_id );

		if ( ! $product ) {
			return [];
		}

		return [
			'product_price'        => $product->get_price(),
			'product_currency'     => get_woocommerce_currency(),
			'product_availability' => $product->is_in_stock() ? 'instock' : 'outofstock',
		];
	}

	/**
	 * Prepare product meta for a SureCart product.
	 *
	 * Reads the `product` post meta (the same source SureRank already uses for
	 * SureCart Product schema). Prices are stored in cents.
	 *
	 * @since 1.9.2
	 * @param int $product_id Product ID.
	 * @return array<string, mixed>
	 */
	private function prepare_surecart_meta( $product_id ) {
		$product = get_post_meta( $product_id, 'product', true );

		if ( empty( $product ) || ! is_array( $product ) ) {
			return [];
		}

		$price         = isset( $product['initial_amount'] ) ? $product['initial_amount'] / 100 : '';
		$currency      = $product['initial_price']['currency'] ?? 'usd';
		$stock_enabled = $product['stock_enabled'] ?? false;
		$out_of_stock  = $stock_enabled && ( $product['available_stock'] ?? 0 ) <= 0;

		return [
			'product_price'        => $price,
			'product_currency'     => strtoupper( (string) $currency ),
			'product_availability' => $out_of_stock ? 'outofstock' : 'instock',
		];
	}
}
