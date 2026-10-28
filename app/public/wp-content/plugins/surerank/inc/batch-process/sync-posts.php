<?php
/**
 * Synchronize Posts
 *
 * @package surerank
 * @since 1.2.0
 */

namespace SureRank\Inc\BatchProcess;

use SureRank\Inc\Functions\Cache;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Sitemap\Sitemap;
use SureRank\Inc\Sitemap\Utils;
use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Traits\Logger;
use WP_Post;
use WP_Query;

/**
 * Synchronize Posts
 *
 * @since 1.2.0
 */
class Sync_Posts extends Sitemap {

	use Get_Instance;
	use Logger;

	/**
	 * Offset
	 *
	 * @var int
	 */
	private $offset = 0;

	/**
	 * Post Type
	 *
	 * @var string
	 */
	private $post_type = '';

	/**
	 * Chunk Size
	 *
	 * @var int
	 */
	private $chunk_size = 20;

	/**
	 * Constructor
	 *
	 * @since 1.2.0
	 * @param int    $offset The offset for pagination.
	 * @param string $post_type The post type to process.
	 * @param int    $chunk_size The chunk size for pagination.
	 * @return void
	 */
	public function __construct( $offset = 0, $post_type = '', $chunk_size = 20 ) {
		$this->offset     = $offset;
		$this->post_type  = $post_type;
		$this->chunk_size = $chunk_size;
		Cache::init();
	}

	/**
	 * Import
	 *
	 * @since 1.2.0
	 * @return array<string, mixed>
	 */
	public function import() {
		$post_type      = ! empty( $this->post_type ) ? $this->post_type : 'any';
		$current_offset = $this->offset;

		$posts = $this->get_posts( $current_offset, $this->chunk_size );

		if ( empty( $posts ) ) {
			return [
				'success' => true,
				'msg'     => __( 'No posts found for processing.', 'surerank' ),
			];
		}

		$json_data = $this->generate_posts_json( $posts );

		$file_index = ( $current_offset / $this->chunk_size ) + 1;
		$saved      = $this->save_json_cache( $json_data, $post_type, $file_index );

		// The boundary sidecar must never exist without its chunk JSON (an
		// orphan survives the invalidator, which enumerates .json files, and
		// would seed later rebuilds with a stale position). Misaligned offsets
		// would record a boundary under the wrong chunk number, so they never
		// write one either.
		$aligned = $this->chunk_size > 0 && 0 === $current_offset % $this->chunk_size;
		if ( $saved && $aligned ) {
			$this->save_chunk_boundary( $post_type, $file_index, $posts );
		} else {
			Cache::delete_file( 'sitemap/' . $this->get_chunk_base( $post_type ) . '-chunk-' . absint( $file_index ) . '.last' );
		}

		if ( ! $saved ) {
			return [
				'success' => false,
				/* translators: %d: chunk number */
				'msg'     => sprintf( __( 'Failed to write sitemap chunk %d.', 'surerank' ), absint( $file_index ) ),
			];
		}
		/* translators: %d: number of posts */
		$message = sprintf( __( 'JSON generation completed for %d posts.', 'surerank' ), count( $posts ) );

		return [
			'success' => true,
			'msg'     => $message,
		];
	}

	/**
	 * Get posts with pagination.
	 *
	 * @param int $offset Offset for pagination.
	 * @param int $chunk_size Number of posts to retrieve.
	 * @return array<int|WP_Post> Array of post IDs.
	 */
	private function get_posts( $offset, $chunk_size ) {
		$post_type         = ! empty( $this->post_type ) ? $this->post_type : 'any';
		$no_index_settings = $this->get_noindex_settings();

		$args = [
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => $chunk_size,
			'offset'                 => $offset,
			// ASC: an immutable, append-only order. New posts land only in
			// the last chunk, so previously built chunk files stay valid.
			// DESC shifted every offset on each publish, producing duplicate
			// or missing URLs across separately built sitemap pages.
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'ignore_sticky_posts'    => true,
			// The chunk builder only reads $query->posts, never found_posts, so
			// skip SQL_CALC_FOUND_ROWS. With it, MySQL evaluates the entire
			// filtered set (all posts through the postmeta joins) on every
			// chunk just to compute a total nobody uses. On large sites this
			// is slow enough to drop the DB connection ("server has gone away").
			'no_found_rows'          => true,
			// Read only the fields the chunk builder needs per post, not every
			// post's full meta (avoids priming heavy page-builder blobs per chunk).
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			// Every sitemap page, on the fly or cron pre-built, is chunked by this
			// class, so the sitemap-wide post exclusion has to be applied here.
			'post__not_in'           => Utils::get_excluded_post_ids(),
		];

		$args['meta_query'] = Utils::get_indexable_meta_query( $post_type ); //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		$args = apply_filters( 'surerank_sitemap_posts_cache_args', $args, $post_type );

		// Keyset pagination: a deep OFFSET makes MySQL walk and discard every
		// skipped row (with the postmeta joins) on each chunk, which times out
		// on very large sites. When the previous chunk recorded its last post
		// ID, seek directly past it instead. Only valid while the query is
		// still ordered by ID ASC with the offset we set, so a filter that
		// changes either falls back to the plain OFFSET query.
		$after_id   = $offset > 0 ? $this->get_previous_chunk_last_id( $post_type, $offset, $chunk_size ) : 0;
		$use_keyset = $after_id > 0
			&& 'ID' === ( $args['orderby'] ?? '' )
			&& 'ASC' === strtoupper( (string) ( $args['order'] ?? '' ) )
			&& $offset === (int) ( $args['offset'] ?? -1 )
			// A changed page size shifts every chunk boundary, so a recorded
			// boundary from another size would silently skip posts.
			&& $chunk_size === (int) ( $args['posts_per_page'] ?? -1 )
			// suppress_filters would drop the posts_where seek clause below,
			// silently rebuilding this chunk with page-1 contents.
			&& empty( $args['suppress_filters'] );

		$where_filter = null;
		$offset_args  = $args;
		if ( $use_keyset ) {
			$args['offset'] = 0;
			// Marker query var: hooks firing inside WP_Query (pre_get_posts,
			// the_posts, ...) may run nested queries of their own, and the
			// seek clause must apply to this chunk query alone.
			$args['surerank_keyset_after'] = $after_id;

			$where_filter = static function ( $where, $query ) use ( $after_id ) {
				if ( ! $query instanceof WP_Query || $after_id !== (int) $query->get( 'surerank_keyset_after' ) ) {
					return $where;
				}
				global $wpdb;
				return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
			};
			add_filter( 'posts_where', $where_filter, 10, 2 );
		}

		try {
			$query = new WP_Query( $args );
		} finally {
			// A hook throwing inside WP_Query must not leak the seek clause
			// into later queries of the same request (cron runs many events).
			if ( $where_filter ) {
				remove_filter( 'posts_where', $where_filter );
			}
		}

		// pre_get_posts fires inside WP_Query, after the guards above, and a
		// hook there can drop the seek clause (suppress_filters) or change the
		// order after the offset was already zeroed, which would mislabel the
		// chunk's contents. Verify the seek actually landed; if not, re-run
		// once with the untouched OFFSET args.
		if ( $use_keyset && ! $this->keyset_applied( $query, $after_id ) ) {
			$query = new WP_Query( $offset_args );
		}

		return $query->posts;
	}

	/**
	 * Whether the keyset seek survived into the executed chunk query.
	 *
	 * Checks the executed SQL, not the query vars: posts_orderby and
	 * posts_clauses filters rewrite the SQL without touching the vars, so the
	 * vars can claim ID ASC while the actual ORDER BY says otherwise.
	 *
	 * @param WP_Query $query The executed chunk query.
	 * @param int      $after_id The boundary post ID the query had to seek past.
	 * @since 1.10.0
	 * @return bool
	 */
	private function keyset_applied( $query, $after_id ) {
		global $wpdb;
		$sql = (string) $query->request;

		return false !== strpos( $sql, ".ID > {$after_id}" )
			&& false !== strpos( $sql, "ORDER BY {$wpdb->posts}.ID ASC" );
	}

	/**
	 * Get the last post ID of the previous chunk, if recorded.
	 *
	 * Chunk boundaries are persisted as sidecar files next to the chunk JSON,
	 * so they share its lifecycle: written together, cleared together by the
	 * cache invalidator, rotated together by the atomic-rebuild directory move.
	 *
	 * @param string $post_type The post type being chunked.
	 * @param int    $offset Offset of the chunk being built.
	 * @param int    $chunk_size Number of posts per chunk.
	 * @since 1.10.0
	 * @return int Last post ID of the previous chunk, or 0 when unknown.
	 */
	private function get_previous_chunk_last_id( $post_type, $offset, $chunk_size ) {
		if ( $chunk_size < 1 || $offset < $chunk_size || 0 !== $offset % $chunk_size ) {
			return 0;
		}

		$previous_index = (int) ( $offset / $chunk_size );
		$chunk_base     = $this->get_chunk_base( $post_type );

		// An orphaned boundary (a .last whose .json was invalidated in a race)
		// describes the old dataset; never trust it without its sibling chunk.
		if ( ! Cache::file_exists( 'sitemap/' . $chunk_base . '-chunk-' . $previous_index . '.json' ) ) {
			return 0;
		}

		$boundary = Cache::get_file( 'sitemap/' . $chunk_base . '-chunk-' . $previous_index . '.last' );

		if ( false === $boundary ) {
			return 0;
		}

		// Format "{chunk_size}:{last_post_id}". A boundary recorded under a
		// different chunk size marks a different position, so it is unusable.
		$parts = explode( ':', trim( (string) $boundary ) );
		if ( 2 !== count( $parts ) || (int) $parts[0] !== $chunk_size ) {
			return 0;
		}

		return absint( $parts[1] );
	}

	/**
	 * Persist the last queried post ID of a chunk for keyset pagination.
	 *
	 * Uses the raw query result, not the generated JSON: the
	 * `surerank_sitemap_sync_posts_post_data` filter may drop or expand
	 * entries, so the JSON's last `id` is not a reliable query boundary.
	 *
	 * @param string             $post_type The post type being chunked.
	 * @param int                $file_index 1-based chunk number.
	 * @param array<int|WP_Post> $posts Posts returned by the chunk query.
	 * @since 1.10.0
	 * @return void
	 */
	private function save_chunk_boundary( $post_type, $file_index, $posts ) {
		$last    = end( $posts );
		$last_id = 0;

		if ( $last instanceof WP_Post ) {
			$last_id = (int) $last->ID;
		} elseif ( is_numeric( $last ) ) {
			$last_id = (int) $last;
		}

		if ( $last_id <= 0 ) {
			return;
		}

		// A concurrent invalidation between this chunk's query and this write
		// can leave one chunk built from the pre-change dataset; it self-heals
		// on the next invalidation of the type, matching the pre-keyset window.
		Cache::store_file(
			'sitemap/' . $this->get_chunk_base( $post_type ) . '-chunk-' . absint( $file_index ) . '.last',
			$this->chunk_size . ':' . $last_id
		);
	}

	/**
	 * Chunk-file base name for a post type, e.g. "post-type-page".
	 *
	 * @param string $post_type The post type.
	 * @since 1.10.0
	 * @return string
	 */
	private function get_chunk_base( $post_type ) {
		return self::get_post_type_prefix() . '-' . sanitize_key( $post_type );
	}

	/**
	 * Generate JSON data for posts
	 *
	 * @param array<string, mixed> $posts Array of post data.
	 * @since 1.2.0
	 * @return array<int, array<string, mixed>>
	 */
	private function generate_posts_json( $posts ) {
		$json_data = [];

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$permalink = get_permalink( $post->ID );

			$post_data = [
				'id'          => $post->ID,
				'title'       => get_the_title( $post->ID ),
				'link'        => $permalink,
				'post_type'   => $post->post_type,
				'updated'     => get_the_modified_date( 'c', $post->ID ),
				'images'      => 0,
				'images_data' => [],
			];

			if ( Settings::get( 'enable_xml_image_sitemap' ) ) {
				$images = Utils::get_images_from_post( $post->ID );

				if ( is_array( $images ) && ! empty( $images ) ) {
					$post_data['images']      = count( $images );
					$post_data['images_data'] = array_map(
						static function ( $image_url ) use ( $post ) {
							return [
								'link'    => esc_url( $image_url ),
								'updated' => get_the_modified_date( 'c', $post->ID ),
							];
						},
						$images
					);
				}
			}

			$post_data = apply_filters( 'surerank_sitemap_sync_posts_post_data', $post_data, $post );

			if ( empty( $post_data ) ) {
				continue;
			}

			if ( isset( $post_data[0] ) && is_array( $post_data[0] ) ) {
				foreach ( $post_data as $entry ) {
					$json_data[] = $entry;
				}
			} else {
				$json_data[] = $post_data;
			}
		}

		return $json_data;
	}

	/**
	 * Save JSON data to cache
	 *
	 * @param array<int, array<string, mixed>> $json_data The JSON data.
	 * @param string                           $post_type The post type.
	 * @param int                              $file_index The file index.
	 * @since 1.2.0
	 * @return bool Whether the chunk JSON was stored.
	 */
	private function save_json_cache( array $json_data, string $post_type, int $file_index ) {
		$chunk_base = $this->get_chunk_base( $post_type );
		$filename   = $chunk_base . '-chunk-' . absint( $file_index ) . '.json';

		self::log( 'Saving JSON cache for ' . $post_type . ' (file: ' . $filename . ')' );

		$json_string = wp_json_encode( $json_data );
		if ( false === $json_string ) {
			self::log( 'Failed to JSON-encode sitemap chunk ' . $filename );
			return false;
		}

		// Only advertise the chunk in the sitemap index once it actually
		// exists on disk; an index entry backed by a missing chunk would be
		// committed as authoritative by a background rebuild.
		if ( ! Cache::store_file( 'sitemap/' . $filename, $json_string ) ) {
			self::log( 'Failed to store sitemap chunk ' . $filename );
			return false;
		}

		Cache::update_sitemap_index( $chunk_base, $file_index, count( $json_data ) );

		return true;
	}

}

/**
 * Kicking this off by calling 'get_instance()' method
 */
Sync_Posts::get_instance();
