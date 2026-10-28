<?php
/**
 * Synchronize Post-Type Archives
 *
 * Builds the cached chunk for the archive sitemap: one entry per sitemap-included
 * post type that has an explicit archive and at least one indexable post, e.g.
 * /products/. See get_archive_entries() for the full inclusion criteria.
 *
 * @package surerank
 * @since 1.9.0
 */

namespace SureRank\Inc\BatchProcess;

use SureRank\Inc\Admin\Sync;
use SureRank\Inc\Functions\Cache;
use SureRank\Inc\Sitemap\Sitemap;
use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Traits\Logger;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Synchronize Post-Type Archives
 *
 * @since 1.9.0
 */
class Sync_Archives extends Sitemap {

	use Get_Instance;
	use Logger;

	/**
	 * Sitemap type identifier (also the cache/url slug: archives-sitemap-1.xml).
	 */
	public const TYPE = 'archives';

	/**
	 * Constructor.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public function __construct() {
		Cache::init();
	}

	/**
	 * Build and cache the archive sitemap chunk.
	 *
	 * @since 1.9.0
	 * @return array<string, mixed>
	 */
	public function import() {
		$entries = $this->get_archive_entries();

		if ( empty( $entries ) ) {
			return [
				'success' => true,
				'msg'     => __( 'No post-type archives found for processing.', 'surerank' ),
			];
		}

		$json_string = wp_json_encode( $entries );
		if ( false === $json_string ) {
			return [
				'success' => false,
				'msg'     => __( 'Failed to encode archive sitemap JSON.', 'surerank' ),
			];
		}

		// A single chunk is sufficient: the entry count equals the number of
		// public has_archive post types (a handful), which can never approach
		// the 200-per-sitemap split threshold or the 50k spec limit. Unlike
		// posts/terms, this set does not paginate.
		Cache::store_file( 'sitemap/' . self::TYPE . '-chunk-1.json', $json_string );
		Cache::update_sitemap_index( self::TYPE, 1, count( $entries ) );

		/* translators: %d: number of archive URLs */
		$message = sprintf( __( 'Archive sitemap generated for %d post-type archives.', 'surerank' ), count( $entries ) );

		return [
			'success' => true,
			'msg'     => $message,
		];
	}

	/**
	 * Collect archive landing-page entries for qualifying post types.
	 *
	 * Mirrors how the post-type sitemaps decide inclusion, so archives never
	 * diverge from the rest of the sitemap. A post type qualifies when it is in
	 * the sitemap's included set (Sync::get_included_post_types(), which honors
	 * surerank_sitemap_enabled_cpts), is registered with an explicit archive
	 * (excludes post/page, whose "archive" is the home/blog page), is not
	 * SureRank-noindexed, and has at least one indexable post (so an empty
	 * archive of a post-less type is not listed). Type slugs are never hardcoded.
	 *
	 * @since 1.9.0
	 * @return array<int, array<string, string>>
	 */
	public function get_archive_entries() {
		if ( apply_filters( 'surerank_exclude_archives_from_sitemap', false ) ) {
			return [];
		}

		$sync     = Sync::get_instance();
		$no_index = $this->get_noindex_settings();
		$types    = [];

		foreach ( (array) $sync->get_included_post_types() as $cpt ) {
			$name = $cpt->name ?? '';

			if ( '' === $name || empty( $cpt->has_archive ) ) {
				continue;
			}
			if ( in_array( $name, $no_index, true ) ) {
				continue;
			}
			if ( $sync->get_indexable_posts_count( $name ) <= 0 ) {
				continue;
			}

			$types[] = $name;
		}

		/**
		 * Filter the post-type slugs whose archive pages are listed in the sitemap.
		 *
		 * @param array<int, string> $types Qualifying post-type slugs.
		 * @since 1.9.0
		 */
		$types   = apply_filters( 'surerank_sitemap_archive_post_types', $types );
		$entries = [];

		foreach ( $types as $post_type ) {
			if ( ! is_string( $post_type ) ) {
				continue;
			}

			$link = get_post_type_archive_link( $post_type );
			if ( empty( $link ) ) {
				continue;
			}

			$entries[] = [
				'link'    => $link,
				'updated' => $this->get_archive_lastmod( $post_type ),
			];
		}

		return $entries;
	}

	/**
	 * Last-modified date for a post type's archive: the most recently modified
	 * published post of that type. Falls back to the current time.
	 *
	 * @param string $post_type Post type slug.
	 * @since 1.9.0
	 * @return string ISO 8601 (c) date.
	 */
	private function get_archive_lastmod( string $post_type ) {
		$query = new WP_Query(
			[
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			]
		);

		if ( ! empty( $query->posts ) ) {
			$modified = get_post_modified_time( 'c', false, $query->posts[0] );
			if ( $modified ) {
				return (string) $modified;
			}
		}

		return (string) current_time( 'c' );
	}

}

/**
 * Kicking this off by calling 'get_instance()' method
 */
Sync_Archives::get_instance();
