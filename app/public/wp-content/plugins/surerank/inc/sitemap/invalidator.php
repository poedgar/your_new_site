<?php
/**
 * Sitemap cache invalidator for the auto generation mode.
 *
 * When content changes, the affected type's chunk files and the sitemap
 * index are deleted so the next request for each page rebuilds just that
 * page (build-on-miss). Deletions are queued and flushed once on
 * shutdown, so bulk edits and imports invalidate each type once per
 * request instead of once per post.
 *
 * In cron mode this class does nothing: the checksum + scheduled rebuild
 * pipeline owns freshness there.
 *
 * Runs on every request type (front-end, REST, admin, CLI) — unlike
 * admin-only watchers, REST and front-end initiated edits invalidate too.
 *
 * @package surerank
 * @since 1.9.3
 */

namespace SureRank\Inc\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\Admin\Sync;
use SureRank\Inc\Functions\Cache;
use SureRank\Inc\Functions\Cron;
use SureRank\Inc\Functions\Helper;
use SureRank\Inc\Traits\Get_Instance;
use WP_Post;
use WP_Term;

/**
 * Invalidator
 *
 * @since 1.9.3
 */
class Invalidator {

	use Get_Instance;

	/**
	 * Chunk-file bases queued for invalidation this request,
	 * e.g. "post-type-post", "taxonomy-type-category".
	 *
	 * @var array<string, bool>
	 */
	private $queued = [];

	/**
	 * Whether the shutdown flusher is hooked already.
	 *
	 * @var bool
	 */
	private $flush_hooked = false;

	/**
	 * Constructor: mirror the checksum triggers, plus settings changes.
	 *
	 * @since 1.9.3
	 */
	public function __construct() {
		add_action( 'wp_after_insert_post', [ $this, 'queue_post' ], 10, 2 );
		add_action( 'before_delete_post', [ $this, 'queue_post_id' ] );
		add_action( 'created_term', [ $this, 'queue_term' ], 10, 3 );
		add_action( 'edited_term', [ $this, 'queue_term' ], 10, 3 );
		add_action( 'delete_term', [ $this, 'queue_term' ], 10, 3 );
		add_action( 'surerank_admin_settings_updated', [ $this, 'settings_changed' ], 10, 3 );

		// SureRank saves a post's per-post noindex via update_post_meta (REST /
		// meta box), which does not fire wp_after_insert_post — so a page toggled
		// to noindex would linger in its cached sitemap chunk. Watch that key.
		add_action( 'added_post_meta', [ $this, 'queue_post_noindex_meta' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'queue_post_noindex_meta' ], 10, 3 );
		add_action( 'deleted_post_meta', [ $this, 'queue_post_noindex_meta' ], 10, 3 );

		// Same for terms: the term SEO save writes the noindex via update_term_meta,
		// which does not fire edited_term.
		add_action( 'added_term_meta', [ $this, 'queue_term_noindex_meta' ], 10, 3 );
		add_action( 'updated_term_meta', [ $this, 'queue_term_noindex_meta' ], 10, 3 );
		add_action( 'deleted_term_meta', [ $this, 'queue_term_noindex_meta' ], 10, 3 );
	}

	/**
	 * Queue a term's taxonomy when its sitemap-indexability meta changes.
	 *
	 * Term counterpart of queue_post_noindex_meta(): only the per-term noindex
	 * key decides whether the term belongs in its taxonomy sitemap.
	 *
	 * @param int|array<int, int> $meta_id   Meta row ID(s) (unused).
	 * @param int                 $object_id Term ID.
	 * @param string              $meta_key  Meta key.
	 * @since 1.10.3
	 * @return void
	 */
	public function queue_term_noindex_meta( $meta_id, $object_id = 0, $meta_key = '' ) {
		if ( 'surerank_settings_post_no_index' !== $meta_key ) {
			return;
		}

		$term = get_term( (int) $object_id );
		if ( $term instanceof WP_Term ) {
			$this->queue_term( (int) $term->term_id, (int) $term->term_taxonomy_id, (string) $term->taxonomy );
		}
	}

	/**
	 * Queue a post's type when its sitemap-indexability meta changes.
	 *
	 * Fires for added/updated/deleted post meta; only the per-post noindex key
	 * (the one Utils::get_indexable_meta_query() filters on) affects whether the
	 * post belongs in the sitemap, so everything else is ignored.
	 *
	 * @param int|array<int, int> $meta_id   Meta row ID(s) (unused).
	 * @param int                 $object_id Post ID.
	 * @param string              $meta_key  Meta key.
	 * @since 1.9.3
	 * @return void
	 */
	public function queue_post_noindex_meta( $meta_id, $object_id = 0, $meta_key = '' ) {
		if ( 'surerank_settings_post_no_index' !== $meta_key ) {
			return;
		}

		$post = get_post( (int) $object_id );
		if ( $post instanceof WP_Post ) {
			$this->queue_post_type( (string) $post->post_type );
		}
	}

	/**
	 * Invalidate the whole sitemap cache when a sitemap-affecting setting changes.
	 *
	 * A settings change (excluded types, noindex, or any sub-sitemap toggle) can
	 * alter the index composition and every page, so the entire sitemap cache is
	 * dropped (inline mode) or a rebuild is scheduled (background mode). The set
	 * of triggering keys is filterable so each sub-sitemap module registers its
	 * own key without a central hardcoded list — this covers free-only installs
	 * as well as every Pro sub-sitemap toggle.
	 *
	 * @param array<string, mixed> $data            Full saved settings (unused).
	 * @param array<string, mixed> $db_options      Prior settings (unused).
	 * @param array<int, string>   $updated_options Top-level keys that changed.
	 * @since 1.9.3
	 * @return void
	 */
	public function settings_changed( $data, $db_options, $updated_options ) {
		if ( ! is_array( $updated_options ) || [] === $updated_options ) {
			return;
		}

		/**
		 * Filter the settings keys that invalidate the sitemap cache on change.
		 *
		 * Each sub-sitemap module adds its own toggle/config key so a new module
		 * participates without editing a central list.
		 *
		 * @param array<int, string> $keys Setting keys that affect sitemap output.
		 * @since 1.9.3
		 */
		$keys = (array) apply_filters(
			'surerank_sitemap_invalidating_settings',
			[ 'no_index', 'enable_xml_sitemap', 'enable_xml_image_sitemap' ]
		);

		if ( [] === array_intersect( $keys, $updated_options ) ) {
			return;
		}

		// Inline mode: drop the whole cache; the next request rebuilds per page.
		if ( Generation_Mode::allows_inline_build() ) {
			Cache::clear_prefix( 'sitemap' );
			return;
		}

		// Background mode: let the scheduled batch rebuild atomically (keeps the
		// live cache serving until the new one is ready).
		if ( ! Helper::are_crons_available() ) {
			return;
		}

		if ( wp_next_scheduled( Cron::SITEMAP_CRON_EVENT, [ 'yes' ] ) ) {
			return;
		}

		wp_schedule_single_event( time() + 10, Cron::SITEMAP_CRON_EVENT, [ 'yes' ] );
	}

	/**
	 * Queue the post's type for invalidation.
	 *
	 * @param int               $post_id Post ID.
	 * @param WP_Post|bool|null $post    Post object when provided by the hook.
	 * @since 1.9.3
	 * @return void
	 */
	public function queue_post( $post_id, $post = null ) {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post instanceof WP_Post || wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return;
		}

		$this->queue_post_type( (string) $post->post_type );
	}

	/**
	 * Queue by post ID only (delete hooks).
	 *
	 * @param int $post_id Post ID.
	 * @since 1.9.3
	 * @return void
	 */
	public function queue_post_id( $post_id ) {
		$this->queue_post( (int) $post_id );
	}

	/**
	 * Queue a taxonomy for invalidation.
	 *
	 * @param int         $term_id  Term ID.
	 * @param int|null    $tt_id    Term taxonomy ID.
	 * @param string|null $taxonomy Taxonomy slug.
	 * @since 1.9.3
	 * @return void
	 */
	public function queue_term( $term_id, $tt_id = null, $taxonomy = null ) {
		if ( ! is_string( $taxonomy ) || '' === $taxonomy ) {
			return;
		}

		if ( ! $this->is_sitemap_taxonomy( $taxonomy ) ) {
			return;
		}

		$this->queue( Sitemap::get_taxonomy_prefix() . '-' . sanitize_key( $taxonomy ) );
	}

	/**
	 * Delete the queued types' chunks plus the index, once per request.
	 *
	 * Auto mode only. Skipped mid-rebuild (backup present): the batch is
	 * already rewriting the live cache and will supersede everything here.
	 *
	 * @since 1.9.3
	 * @return void
	 */
	public function flush() {
		if ( [] === $this->queued ) {
			return;
		}

		if ( ! Generation_Mode::allows_inline_build() ) {
			$this->queued = [];
			return;
		}

		if ( Cache::has_rebuild_backup( 'sitemap' ) ) {
			$this->queued = [];
			return;
		}

		foreach ( array_keys( $this->queued ) as $base ) {
			// Enumerate what exists rather than probing 1..N: lazy build-on-miss
			// caches are sparse (a crawler can build page 2 without page 1), so
			// stopping at the first gap would orphan higher-numbered chunks.
			// Enumerate .last sidecars independently of .json chunks: a write
			// racing this flush can land a sidecar after its chunk was deleted,
			// and an orphan enumerated only via .json would never be reaped.
			$chunk_numbers = array_unique(
				array_merge(
					Cache::get_chunk_numbers( $base ),
					Cache::get_chunk_numbers( $base, 'last' )
				)
			);

			foreach ( $chunk_numbers as $chunk_number ) {
				// The keyset boundary sidecar records this chunk's last post ID
				// under the OLD dataset. A rebuild that read it would start the
				// next chunk from a stale position, so it must die with the chunk.
				// Sidecar first: a crash between the two deletes then leaves a
				// chunk without a boundary (harmless offset fallback), never an
				// orphaned boundary without a chunk.
				Cache::delete_file( 'sitemap/' . $base . '-chunk-' . $chunk_number . '.last' );
				Cache::delete_file( 'sitemap/' . $base . '-chunk-' . $chunk_number . '.json' );
			}
		}

		// Drop the index so page counts are re-derived on the next request.
		if ( Cache::file_exists( 'sitemap/sitemap_index.json' ) ) {
			Cache::delete_file( 'sitemap/sitemap_index.json' );
		}

		$this->queued = [];
	}

	/**
	 * Queue a post type's chunk base, but only when that type is in the sitemap.
	 *
	 * Without this gate, saving any post — a wp_block, a shop_order, a log
	 * entry — would drop the index and force a full recount on the next request.
	 *
	 * @param string $post_type Post type slug.
	 * @since 1.9.3
	 * @return void
	 */
	private function queue_post_type( string $post_type ): void {
		if ( ! $this->is_sitemap_post_type( $post_type ) ) {
			return;
		}

		$this->queue( Sitemap::get_post_type_prefix() . '-' . sanitize_key( $post_type ) );
	}

	/**
	 * Whether a post type contributes to the sitemap (mirrors Checksum).
	 *
	 * @param string $post_type Post type slug.
	 * @since 1.9.3
	 * @return bool
	 */
	private function is_sitemap_post_type( string $post_type ): bool {
		if ( in_array( $post_type, (array) Utils::get_noindex_settings(), true ) ) {
			return false;
		}

		$included = array_keys( (array) Sync::get_instance()->get_included_post_types() );
		return in_array( $post_type, $included, true );
	}

	/**
	 * Whether a taxonomy contributes to the sitemap (mirrors Checksum).
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @since 1.9.3
	 * @return bool
	 */
	private function is_sitemap_taxonomy( string $taxonomy ): bool {
		if ( in_array( $taxonomy, (array) Utils::get_noindex_settings(), true ) ) {
			return false;
		}

		$included = array_column( (array) Sync::get_instance()->get_included_taxonomies(), 'slug' );
		return in_array( $taxonomy, $included, true );
	}

	/**
	 * Queue one chunk base and arm the shutdown flusher.
	 *
	 * @param string $base Chunk filename base.
	 * @since 1.9.3
	 * @return void
	 */
	private function queue( string $base ) {
		$this->queued[ $base ] = true;

		if ( ! $this->flush_hooked ) {
			$this->flush_hooked = true;
			add_action( 'shutdown', [ $this, 'flush' ] );
		}
	}
}
