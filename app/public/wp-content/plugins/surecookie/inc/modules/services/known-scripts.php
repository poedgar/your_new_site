<?php
/**
 * Known Scripts Database.
 *
 * The blocking view over the unified service catalog. Since 1.3.0 the fetch,
 * caching and bundled floor live in Services_Source (which reads the unified
 * dataset/services.json); this class is a thin adapter that projects that
 * source into the category => slug => {label, scripts[], iframes[]} shape the
 * Blocker and the `surecookie_known_scripts` filter consumers expect. The public
 * API is unchanged.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Known_Scripts
 *
 * Manages the database of known third-party scripts that should be blocked.
 *
 * @since 0.0.1
 */
class Known_Scripts {
	use GetInstance;

	/**
	 * Cached blocking view (category => slug => definition).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $scripts = [];

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	private function __construct() {
		$this->load_data();
	}

	/**
	 * Load the blocking view from the unified Services_Source (transient -> file
	 * cache -> bundled floor; the remote is warmed off-request by the cron).
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function load_data(): void {
		$this->scripts = Services_Source::get_instance()->get_blocking_view();
	}

	/**
	 * Refresh the unified catalog from remote (off the request path) and reload
	 * the projected blocking view.
	 *
	 * @since 1.2.5
	 * @return void
	 */
	public function refresh_from_remote(): void {
		Services_Source::get_instance()->refresh_from_remote();
		$this->load_data();
	}

	/**
	 * Load the plugin-bundled baseline blocking view (offline floor).
	 *
	 * @since 1.2.3
	 * @return array<string, array<string, mixed>>
	 */
	public function get_bundled_data(): array {
		return Services_Source::get_instance()->get_bundled_blocking_view();
	}

	/**
	 * Get all known scripts grouped by category.
	 *
	 * @since 0.0.1
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all_scripts(): array {
		/**
		 * Filter the known scripts database.
		 *
		 * @since 0.0.1
		 * @param array<string, array<string, mixed>> $scripts Known scripts grouped by category.
		 */
		$filtered = apply_filters( 'surecookie_known_scripts', $this->scripts );
		return is_array( $filtered ) ? $filtered : $this->scripts;
	}

	/**
	 * Get scripts for a specific category.
	 *
	 * @param string $category Category name (e.g., 'analytics', 'marketing').
	 * @since 0.0.1
	 * @return array<string, mixed>
	 */
	public function get_scripts_for_category( string $category ): array {
		$all_scripts = $this->get_all_scripts();
		return $all_scripts[ $category ] ?? [];
	}

	/**
	 * Clear the cached catalog, falling back to the bundled floor so blocking
	 * keeps running until the next request repopulates from cache/remote.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function clear_cache(): void {
		Services_Source::get_instance()->clear_cache();
		$this->scripts = $this->get_bundled_data();
	}
}
