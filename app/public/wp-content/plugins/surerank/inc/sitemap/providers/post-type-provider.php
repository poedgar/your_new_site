<?php
/**
 * Post-type sitemap provider.
 *
 * @package surerank
 * @since 1.9.3
 */

namespace SureRank\Inc\Sitemap\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\Admin\Sync;
use SureRank\Inc\BatchProcess\Sync_Posts;
use SureRank\Inc\Functions\Cache;
use SureRank\Inc\Sitemap\Sitemap;
use SureRank\Inc\Traits\Get_Instance;

/**
 * Post_Type_Provider
 *
 * @since 1.9.3
 */
class Post_Type_Provider implements Provider {

	use Get_Instance;

	/**
	 * Included post type slugs (attachment excluded), memoized per request.
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
		return Sitemap::get_post_type_prefix() === $prefix && in_array( $type, $this->get_slugs(), true );
	}

	/**
	 * Index entries derived from indexable counts — no posts are loaded.
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
			$pages = (int) ceil( $sync->get_indexable_posts_count( $slug ) / $threshold );
			for ( $page = 1; $page <= $pages; $page++ ) {
				$entries[] = [
					'link'    => home_url( Sitemap::get_post_type_prefix() . '-' . sanitize_key( $slug ) . '-sitemap-' . $page . '.xml' ),
					'updated' => $now,
				];
			}
		}

		return $entries;
	}

	/**
	 * Build the missing chunks backing one sitemap page, inline.
	 *
	 * Uses the same Sync_Posts class the cron batch uses, so on-demand
	 * chunks are byte-identical to cron-built ones. Bounded: at most
	 * threshold/chunk_size queries of chunk_size posts.
	 *
	 * @param string $type   Post type slug (validated against included types).
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
			( new Sync_Posts( $offset, $type, $chunk_size ) )->import();
		}
	}

	/**
	 * Included post type slugs, attachment excluded.
	 *
	 * @since 1.9.3
	 * @return array<int, string>
	 */
	private function get_slugs(): array {
		if ( null === $this->slugs ) {
			$slugs = array_keys( (array) Sync::get_instance()->get_included_post_types() );

			$this->slugs = array_values(
				array_filter(
					array_map( 'strval', $slugs ),
					static function ( string $slug ): bool {
						return 'attachment' !== $slug;
					}
				)
			);
		}

		return $this->slugs;
	}
}
