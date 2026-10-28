<?php
/**
 * Sitemap provider contract.
 *
 * A provider owns one family of sitemap types (post types, taxonomies,
 * archives, and — via the surerank_sitemap_providers filter — Pro types
 * like video/news/author). The registry consults providers for two
 * things the serving layer cannot know generically:
 *
 *  - which index entries the family contributes (derived from counts,
 *    never from loading content), and
 *  - how to build one bounded sitemap page's chunks inline when the
 *    generation mode allows request-time building.
 *
 * @package surerank
 * @since 1.9.3
 */

namespace SureRank\Inc\Sitemap\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Provider interface.
 *
 * @since 1.9.3
 */
interface Provider {

	/**
	 * Whether this provider serves the given request.
	 *
	 * @param string $type   Requested sitemap type (slug), e.g. "post", "category", "archives".
	 * @param string $prefix Requested prefix, e.g. "post-type", "taxonomy-type", or '' for unprefixed types.
	 * @since 1.9.3
	 * @return bool
	 */
	public function handles( string $type, string $prefix ): bool;

	/**
	 * Index entries this provider contributes, derived from count queries.
	 *
	 * Each entry: [ 'link' => absolute XML url, 'updated' => ISO8601 ].
	 *
	 * @since 1.9.3
	 * @return array<int, array<string, string>>
	 */
	public function get_index_entries(): array;

	/**
	 * Ensure every chunk backing one sitemap page exists, building the
	 * missing ones inline. Bounded work: at most one page's worth of
	 * chunks (threshold / chunk_size queries of chunk_size items).
	 *
	 * @param string $type   Sitemap type (slug).
	 * @param string $prefix Prefix for the chunk filenames.
	 * @param int    $page   1-based sitemap page number.
	 * @since 1.9.3
	 * @return void
	 */
	public function ensure_page_built( string $type, string $prefix, int $page ): void;
}
