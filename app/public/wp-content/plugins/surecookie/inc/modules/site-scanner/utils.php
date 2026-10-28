<?php
/**
 * Utils Site Scanner.
 *
 * @package SureCookie\Inc\Modules\SiteScanner
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\SiteScanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Utils
 *
 * @since 0.0.1
 */
class Utils {
	/**
	 * Get Site URL for Verification
	 *
	 * Gets the site URL to use for verification
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public static function get_site_url(): string {
		return str_replace( 'http://', 'https://', rtrim( get_site_url(), '/' ) ) . '/';
	}

	/**
	 * The address WordPress serves the site from, which is where `rest_url()`
	 * builds from and therefore where the SaaS must fetch the verification
	 * token. Equal to get_site_url() except on a "WordPress in its own
	 * directory" install, where core lives under a path the REST API does not.
	 *
	 * @since 1.5.1
	 * @return string
	 */
	public static function get_home_url(): string {
		return str_replace( 'http://', 'https://', rtrim( home_url(), '/' ) ) . '/';
	}

	/**
	 * Generate a unique site ID based on site URL.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public static function generate_site_id(): string {
		return substr( hash( 'sha256', self::get_site_url() ), 0, 32 );
	}

	/**
	 * Get the current plan type for further processing.
	 *
	 * @since 0.0.1
	 * @return string Plan type
	 */
	public static function get_plan(): string {
		$filtered = apply_filters( 'surecookie_plan_type', 'free' );
		return is_string( $filtered ) ? $filtered : 'free';
	}

	/**
	 * Max pages per scan: SaaS quota cache (transient → option) → `surecookie_max_scan_pages` filter (default 5).
	 *
	 * @since 0.0.1-beta.2
	 * @return int
	 */
	public static function get_max_scan_pages(): int {
		$cached = SaasClient::get_instance()->get_cached_quota();
		if ( ! empty( $cached['pages_per_scan_limit'] ) ) {
			return (int) $cached['pages_per_scan_limit'];
		}

		return (int) apply_filters( 'surecookie_max_scan_pages', 5 );
	}
}
