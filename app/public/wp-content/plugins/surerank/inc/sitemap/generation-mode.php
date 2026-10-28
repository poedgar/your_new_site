<?php
/**
 * Sitemap generation mode.
 *
 * SureRank produces the sitemap cache in one of two ways:
 *
 *  - On the fly (default): cache-first with build-on-miss. When a requested
 *    sitemap page has no cache, it is built inline in that request — the
 *    index from count queries, each sub-sitemap's chunks only when that URL
 *    is visited. No background scheduling; the sitemap always serves.
 *
 *  - Background: strict pre-build. A scheduled cron pipeline builds the whole
 *    cache ahead of time and sitemap requests only ever read it (live, then
 *    rebuild backup, then the 503 miss response) — no request-time building
 *    at all. For very large sites or admins who want zero front-end database
 *    work. Opt in with the surerank_sitemap_background_generation filter.
 *
 * @package surerank
 * @since 1.9.3
 */

namespace SureRank\Inc\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Generation_Mode
 *
 * @since 1.9.3
 */
class Generation_Mode {

	/**
	 * Whether the sitemap cache is pre-built in the background via cron
	 * instead of on the fly per request.
	 *
	 * Not a stored setting: this is a developer escape hatch for very large
	 * sites. Return true from the filter to switch to background pre-build.
	 *
	 * @since 1.9.3
	 * @return bool
	 */
	public static function is_background_generation(): bool {
		/**
		 * Filter whether the sitemap is generated in the background via cron.
		 *
		 * Default false: the sitemap is built on the fly (index from counts,
		 * each sub-sitemap's chunks on the visited request). Return true to
		 * pre-build the entire cache in a scheduled cron and serve cache-only.
		 *
		 * @param bool $background Whether to pre-build in the background. Default false.
		 * @since 1.9.3
		 */
		return (bool) apply_filters( 'surerank_sitemap_background_generation', false );
	}

	/**
	 * Whether sitemap requests may build missing/stale cache inline.
	 *
	 * @since 1.9.3
	 * @return bool
	 */
	public static function allows_inline_build(): bool {
		return ! self::is_background_generation();
	}

	/**
	 * Whether the scheduled cron pipeline should build the cache.
	 *
	 * Background mode only. On-the-fly mode builds every sitemap per request
	 * (index from counts, chunks per visited page) and schedules no cron.
	 *
	 * @since 1.9.3
	 * @return bool
	 */
	public static function cron_prebuild_enabled(): bool {
		return self::is_background_generation();
	}
}
