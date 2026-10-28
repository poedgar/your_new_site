<?php
/**
 * Abstract Sitemap Class
 *
 * This file contains the abstract base class for sitemap generation.
 *
 * @package surerank
 * @since 1.2.0
 */

namespace SureRank\Inc\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\Functions\Get;

/**
 * Abstract Sitemap
 * Base class for sitemap generation with common functionality.
 *
 * @since 1.2.0
 */
abstract class Sitemap {

	/**
	 * Get noindex settings.
	 *
	 * @return array<string, mixed>|array<int, string>
	 */
	public function get_noindex_settings() {
		$settings = Get::option( SURERANK_SETTINGS );
		return $settings['no_index'] ?? [];
	}

	/**
	 * Get post type prefix for sitemap URLs.
	 *
	 * @return string
	 */
	public static function get_post_type_prefix() {
		return apply_filters( 'surerank_sitemap_post_type_prefix', 'post-type' );
	}

	/**
	 * Get taxonomy prefix for sitemap URLs.
	 *
	 * @return string
	 */
	public static function get_taxonomy_prefix() {
		return apply_filters( 'surerank_sitemap_taxonomy_prefix', 'taxonomy-type' );
	}
}
