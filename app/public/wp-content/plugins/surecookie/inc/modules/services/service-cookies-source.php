<?php
/**
 * Service-Cookies Source.
 *
 * The declared-cookie view over the unified service catalog. Since 1.3.0 the
 * fetch, caching and bundled floor live in Services_Source (which reads the
 * unified dataset/services.json); this class is a thin adapter that projects
 * that source into the slug => cookie-rows shape and applies the
 * `surecookie_declared_service_cookies` filter. The public API (get_catalog) is
 * unchanged, so Declared_Cookies and the presets REST endpoint are untouched.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.2.5
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Service_Cookies_Source
 *
 * Projects and filters the declared-cookie catalog from Services_Source.
 *
 * @since 1.2.5
 */
class Service_Cookies_Source {
	use GetInstance;

	/**
	 * Get the declared-cookie catalog (service slug => cookie rows), with the
	 * `surecookie_declared_service_cookies` filter applied.
	 *
	 * @since 1.2.5
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function get_catalog(): array {
		$catalog = Services_Source::get_instance()->get_cookies_view();

		/**
		 * Filter the declared-cookie catalog (service slug => cookie definitions).
		 *
		 * @since 1.2.5
		 * @param array<string, array<int, array<string, mixed>>> $catalog Declared-cookie catalog.
		 */
		return (array) apply_filters( 'surecookie_declared_service_cookies', $catalog );
	}

	/**
	 * Warm the unified catalog from remote (off the request path). Kept for
	 * backward compatibility; the cron now warms Services_Source directly.
	 *
	 * @since 1.2.5
	 * @return void
	 */
	public function refresh_from_remote(): void {
		Services_Source::get_instance()->refresh_from_remote();
	}
}
