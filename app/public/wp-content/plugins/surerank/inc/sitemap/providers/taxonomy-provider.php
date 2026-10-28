<?php
/**
 * Taxonomy sitemap provider.
 *
 * @package surerank
 * @since 1.9.3
 */

namespace SureRank\Inc\Sitemap\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\Admin\Sync;
use SureRank\Inc\BatchProcess\Sync_Taxonomies;
use SureRank\Inc\Functions\Cache;
use SureRank\Inc\Sitemap\Sitemap;
use SureRank\Inc\Traits\Get_Instance;

/**
 * Taxonomy_Provider
 *
 * @since 1.9.3
 */
class Taxonomy_Provider implements Provider {

	use Get_Instance;

	/**
	 * Included taxonomy slugs (post_format excluded), memoized per request.
	 *
	 * @var array<int, string>|null
	 */
	private $slugs = null;

	/**
	 * Whether this provider serves the given request.
	 *
	 * @param string $type   Requested sitemap type (slug).
	 * @param string $prefix Requested prefix.
	 * @since 1.9.3
	 * @return bool
	 */
	public function handles( string $type, string $prefix ): bool {
		return Sitemap::get_taxonomy_prefix() === $prefix && in_array( $type, $this->get_slugs(), true );
	}

	/**
	 * Index entries derived from indexable term counts.
	 *
	 * @since 1.9.3
	 * @return array<int, array<string, string>>
	 */
	public function get_index_entries(): array {
		$threshold = max( 1, (int) apply_filters( 'surerank_sitemap_threshold', 200 ) );
		$sync      = Sync::get_instance();
		$now       = current_time( 'c' );
		$entries   = [];

		foreach ( $this->get_slugs() as $slug ) {
			$pages = (int) ceil( $sync->get_indexable_terms_count( $slug ) / $threshold );
			for ( $page = 1; $page <= $pages; $page++ ) {
				$entries[] = [
					'link'    => home_url( Sitemap::get_taxonomy_prefix() . '-' . sanitize_key( $slug ) . '-sitemap-' . $page . '.xml' ),
					'updated' => $now,
				];
			}
		}

		return $entries;
	}

	/**
	 * Build the missing chunks backing one sitemap page, inline.
	 *
	 * @param string $type   Taxonomy slug (validated against included taxonomies).
	 * @param string $prefix Chunk filename prefix.
	 * @param int    $page   1-based sitemap page number.
	 * @since 1.9.3
	 * @return void
	 */
	public function ensure_page_built( string $type, string $prefix, int $page ): void {
		if ( ! $this->handles( $type, $prefix ) ) {
			return;
		}

		$threshold  = max( 1, (int) apply_filters( 'surerank_sitemap_threshold', 200 ) );
		$chunk_size = max( 1, (int) apply_filters( 'surerank_sitemap_json_chunk_size', 20 ) );

		$chunks_per_sitemap = (int) ceil( $threshold / $chunk_size );
		$start_chunk        = ( max( 1, $page ) - 1 ) * $chunks_per_sitemap + 1;
		$end_chunk          = max( 1, $page ) * $chunks_per_sitemap;

		for ( $chunk_number = $start_chunk; $chunk_number <= $end_chunk; $chunk_number++ ) {
			$cache_path = 'sitemap/' . $prefix . '-' . $type . '-chunk-' . $chunk_number . '.json';
			if ( Cache::file_exists( $cache_path ) ) {
				continue;
			}

			$offset = ( $chunk_number - 1 ) * $chunk_size;
			( new Sync_Taxonomies( $offset, $type, $chunk_size ) )->import();
		}
	}

	/**
	 * Included taxonomy slugs, post_format excluded.
	 *
	 * @since 1.9.3
	 * @return array<int, string>
	 */
	private function get_slugs(): array {
		if ( null === $this->slugs ) {
			$slugs = [];
			foreach ( (array) Sync::get_instance()->get_included_taxonomies() as $taxonomy ) {
				$slug = is_array( $taxonomy ) ? (string) ( $taxonomy['slug'] ?? '' ) : (string) $taxonomy;
				if ( '' !== $slug && 'post_format' !== $slug ) {
					$slugs[] = $slug;
				}
			}
			$this->slugs = $slugs;
		}

		return $this->slugs;
	}
}
