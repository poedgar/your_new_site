<?php
/**
 * Assisted Scan utilities.
 *
 * @package SureCookie\Inc\Modules\AssistedScan
 * @since   1.3.0
 */

namespace SureCookie\Inc\Modules\AssistedScan;

use SureCookie\Inc\Modules\SiteScanner\SaasClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Utils
 *
 * @since 1.3.0
 */
class Utils {
	/**
	 * Page allowance when the site has no cached SaaS quota.
	 *
	 * Deliberately higher than the cloud scanner's default of 5: sites that need an
	 * assisted scan are the ones whose registration or scan the host blocked, and an
	 * unregistered site has no quota to read, so quota caching would otherwise cap a
	 * paying customer at the free-tier allowance on the only scanner that works for them.
	 * Assisted scanning costs no SaaS compute, only the admin's browser and time. Ten
	 * covers home, shop, cart, checkout, contact, privacy and a few templates while
	 * keeping the walk short enough that the admin sees it through.
	 *
	 * @since 1.3.0
	 */
	public const DEFAULT_MAX_PAGES = 10;

	/**
	 * Whether the Assisted Scan feature is available on this site.
	 *
	 * The kill switch: it clears cookies and drives a real browser tab, so support must
	 * be able to disable it with a snippet, not a patch release. False removes the REST
	 * routes, front-end collector and admin entry point.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	public static function is_enabled(): bool {
		/**
		 * Filter whether Assisted Scan is available.
		 *
		 * @since 1.3.0
		 * @param bool $enabled Whether the feature is enabled.
		 */
		return (bool) apply_filters( 'surecookie_assisted_scan_enabled', true );
	}

	/**
	 * Max pages per assisted walk: SaaS quota cache, else {@see self::DEFAULT_MAX_PAGES}.
	 *
	 * @since 1.3.0
	 * @return int
	 */
	public static function get_max_pages(): int {
		$cached = SaasClient::get_instance()->get_cached_quota();

		if ( ! empty( $cached['pages_per_scan_limit'] ) ) {
			return (int) $cached['pages_per_scan_limit'];
		}

		/**
		 * Filter the assisted-scan page allowance used when no SaaS quota is cached.
		 *
		 * @since 1.3.0
		 * @param int $max_pages Maximum pages an assisted walk may visit.
		 */
		$max_pages = (int) apply_filters( 'surecookie_assisted_max_scan_pages', self::DEFAULT_MAX_PAGES );

		return $max_pages > 0 ? $max_pages : self::DEFAULT_MAX_PAGES;
	}
}
