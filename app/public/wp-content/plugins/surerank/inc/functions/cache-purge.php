<?php
/**
 * Page-cache purge helpers.
 *
 * Page cache plugins serve cached HTML to visitors. When SureRank changes a
 * page's SEO meta, or global SEO defaults that affect every page, that cached
 * HTML stays stale until purged. SureRank persists data via update_option() /
 * update_post_meta(), which do NOT trigger most cache plugins' save_post-based
 * auto-purge, so we purge explicitly here.
 *
 * Purges are queued during the request and executed once on `shutdown` (after
 * the response is sent), so cache I/O never adds latency to a save. Requests are
 * deduplicated, and a full flush supersedes any queued targeted purges.
 *
 * Third-party APIs are called only when present (function/class guards), so a
 * missing plugin is a harmless no-op. Listen to `surerank_purge_cache`,
 * `surerank_purge_post` or `surerank_purge_url` to purge additional layers.
 *
 * @package surerank
 * @since 1.9.3
 */

namespace SureRank\Inc\Functions;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Cache_Purge
 *
 * @since 1.9.3
 */
class Cache_Purge {

	/**
	 * Whether a full-site flush has been requested this request.
	 *
	 * @var bool
	 */
	private static $flush_all = false;

	/**
	 * Post IDs queued for a targeted purge, keyed by ID to dedupe.
	 *
	 * @var array<int, int>
	 */
	private static $post_ids = [];

	/**
	 * URLs queued for a targeted purge, keyed by URL to dedupe.
	 *
	 * @var array<string, string>
	 */
	private static $urls = [];

	/**
	 * Whether the shutdown flush has been scheduled.
	 *
	 * @var bool
	 */
	private static $scheduled = false;

	/**
	 * Queue a full-site page-cache flush.
	 *
	 * Use for changes with site-wide blast radius (e.g. global SEO defaults).
	 *
	 * @since 1.9.3
	 * @return void
	 */
	public static function purge_all() {
		self::$flush_all = true;
		self::schedule();
	}

	/**
	 * Queue a targeted purge for a single post.
	 *
	 * @param int $post_id Post ID whose cached page should be refreshed.
	 * @since 1.9.3
	 * @return void
	 */
	public static function purge_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		self::$post_ids[ $post_id ] = $post_id;
		self::schedule();
	}

	/**
	 * Queue a targeted purge for a single URL (e.g. a term or author archive).
	 *
	 * @param string $url Absolute URL whose cached output should be refreshed.
	 * @since 1.9.3
	 * @return void
	 */
	public static function purge_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}
		self::$urls[ $url ] = $url;
		self::schedule();
	}

	/**
	 * Execute all queued purges. Hooked to `shutdown`; not called directly.
	 *
	 * @since 1.9.3
	 * @return void
	 */
	public static function flush() {
		if ( self::$flush_all ) {
			self::flush_all_caches();
		} else {
			foreach ( self::$post_ids as $post_id ) {
				self::flush_post_caches( $post_id );
			}
			foreach ( self::$urls as $url ) {
				self::flush_url_caches( $url );
			}
		}

		// Reset so a second flush in the same request is a no-op.
		self::$flush_all = false;
		self::$post_ids  = [];
		self::$urls      = [];
	}

	/**
	 * Defer the flush to shutdown so cache I/O never blocks the save response.
	 *
	 * @since 1.9.3
	 * @return void
	 */
	private static function schedule() {
		if ( self::$scheduled ) {
			return;
		}
		self::$scheduled = true;
		add_action( 'shutdown', [ self::class, 'flush' ] );
	}

	/**
	 * Flush the entire page cache across supported plugins.
	 *
	 * @since 1.9.3
	 * @return void
	 */
	private static function flush_all_caches() {
		/**
		 * Fires when SureRank flushes all page caches.
		 *
		 * @since 1.9.3
		 */
		do_action( 'surerank_purge_cache' );

		// LiteSpeed Cache.
		do_action( 'litespeed_purge_all' );

		// SiteGround SG Optimizer.
		do_action( 'sg_cachepress_purge_cache' );

		// Nginx Helper.
		do_action( 'rt_nginx_helper_purge_all' );

		// Breeze (Cloudways).
		do_action( 'breeze_clear_all_cache' );

		// Cache Enabler.
		do_action( 'cache_enabler_clear_complete_cache' );

		// Hummingbird.
		do_action( 'wphb_clear_page_cache' );

		// WP Rocket / W3 Total Cache / WP Super Cache / WP Fastest Cache.
		self::call_if_exists( 'rocket_clean_domain' );
		self::call_if_exists( 'w3tc_flush_all' );
		self::call_if_exists( 'wp_cache_clear_cache' );
		self::call_if_exists( 'wpfc_clear_all_cache', [ true ] );
	}

	/**
	 * Flush the cached output for a single post.
	 *
	 * Plugins without a post-level API are left to their own auto-purge rather
	 * than full-flushing the whole site.
	 *
	 * @param int $post_id Post ID.
	 * @since 1.9.3
	 * @return void
	 */
	private static function flush_post_caches( $post_id ) {
		/**
		 * Fires when SureRank purges a single post's page cache.
		 *
		 * @param int $post_id Post ID.
		 * @since 1.9.3
		 */
		do_action( 'surerank_purge_post', $post_id );

		// LiteSpeed Cache.
		do_action( 'litespeed_purge_post', $post_id );

		// WP Rocket / W3 Total Cache / WP Super Cache.
		self::call_if_exists( 'rocket_clean_post', [ $post_id ] );
		self::call_if_exists( 'w3tc_flush_post', [ $post_id ] );
		self::call_if_exists( 'wpsc_delete_post_cache', [ $post_id ] );

		// Cache Enabler.
		$cache_enabler = [ '\Cache_Enabler', 'clear_page_cache_by_post_id' ];
		if ( is_callable( $cache_enabler ) ) {
			call_user_func( $cache_enabler, $post_id );
		}
	}

	/**
	 * Flush the cached output for a single URL.
	 *
	 * Plugins without a per-URL API are left to their own auto-purge.
	 *
	 * @param string $url Absolute URL.
	 * @since 1.9.3
	 * @return void
	 */
	private static function flush_url_caches( $url ) {
		/**
		 * Fires when SureRank purges a single URL's page cache.
		 *
		 * @param string $url Absolute URL.
		 * @since 1.9.3
		 */
		do_action( 'surerank_purge_url', $url );

		// LiteSpeed Cache.
		do_action( 'litespeed_purge_url', $url );

		// WP Rocket.
		self::call_if_exists( 'rocket_clean_files', [ $url ] );
	}

	/**
	 * Invoke a third-party function only when it exists.
	 *
	 * @param string            $function Function name to call when available.
	 * @param array<int, mixed> $args     Positional arguments to pass.
	 * @since 1.9.3
	 * @return void
	 */
	private static function call_if_exists( string $function, array $args = [] ) {
		if ( function_exists( $function ) ) {
			call_user_func_array( $function, $args );
		}
	}
}
