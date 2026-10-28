<?php
/**
 * Common Meta Data
 *
 * This file will handle functionality to print meta_data in frontend for different requests.
 *
 * @package surerank
 * @since 0.0.1
 */

namespace SureRank\Inc\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\Functions\Helper;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Functions\Validate;
use SureRank\Inc\Functions\Variables;
use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Traits\Reset_Meta_Data;

/**
 * Single Page SEO
 * This class will handle functionality to print meta_data in frontend for different requests.
 *
 * @since 1.0.0
 */
class Single {

	use Get_Instance;
	use Reset_Meta_Data;

	/**
	 * Meta Data
	 *
	 * @var array<string, mixed>|null $meta_data Post meta data.
	 * @since 1.0.0
	 */
	private $meta_data = null;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_filter( 'surerank_set_meta', [ $this, 'get_meta_data' ], 1 );
		$this->register_meta_reset();
	}

	/**
	 * Add meta data
	 *
	 * @param array<string, mixed> $meta_data Meta Data.
	 * @since 1.0.0
	 * @return array<string, mixed> $meta_data
	 */
	public function get_meta_data( $meta_data ) {
		$shop_page_id = Helper::get_shop_page_id();

		if ( ! is_singular() && ! $shop_page_id ) {
			return $meta_data;
		}

		// The WooCommerce shop page renders as a product archive, so
		// is_singular() is false and get_the_ID() returns a product from the
		// archive loop. Use the shop page's own ID instead so its stored SEO
		// meta (title/description edited in the meta box) applies on the front end.
		$post_id = $shop_page_id ? $shop_page_id : get_the_ID();

		if ( empty( $post_id ) ) {
			return $meta_data;
		}

		if ( null !== $this->meta_data ) {
			return $meta_data;
		}

		$post_type       = get_post_type( $post_id ) ? get_post_type( $post_id ) : '';
		$this->meta_data = Variables::replace( Validate::array( Settings::prep_post_meta( intval( $post_id ), $post_type, false ) ), intval( $post_id ) );
		return $this->meta_data;
	}
}
