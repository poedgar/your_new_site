<?php
/**
 * Google Consent Mode - Whitelist Handler
 *
 * Handles whitelisting of Google scripts when GCM is enabled.
 * Prevents script/content blocking from blocking Google services
 * since GCM handles consent programmatically.
 *
 * @package SureCookie
 * @since 0.0.0-alpha.1
 */

namespace SureCookie\Inc\Modules\GoogleConsentMode;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\ScriptBlocking\Utils as ScriptBlockingUtils;
use SureCookie\Inc\Modules\Services\Known_Scripts;
use SureCookie\Inc\Modules\Services\Pattern_Kinds;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Whitelist_Handler class.
 *
 * Whitelists Google services when Google Consent Mode is active.
 * This prevents double-blocking - GCM handles consent programmatically,
 * so scripts don't need to be blocked by the script blocker.
 *
 * Whitelist is dynamically loaded from server API (Known_Scripts),
 * controlled by 'gcm_compatible' flag on each service.
 *
 * @since 0.0.0-alpha.1
 */
class Whitelist_Handler {
	use GetInstance;

	/**
	 * Cached whitelist data.
	 *
	 * Structure: [
	 *   'services' => ['google-analytics', 'google-tag-manager', ...],
	 *   'patterns' => ['google-analytics.com', 'googletagmanager.com', ...]
	 * ]
	 *
	 * @var array{services: array<string>, patterns: array<string>}|null
	 */
	private ?array $whitelist_cache = null;

	/**
	 * Constructor.
	 *
	 * @since 0.0.0-alpha.1
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Maybe whitelist a script from being blocked.
	 *
	 * @param bool   $skip     Whether to skip blocking (default false).
	 * @param string $src      Script source URL.
	 * @param string $name     Service name from known scripts.
	 * @param string $category Category name from known scripts.
	 * @return bool True to skip blocking, false to block.
	 * @since 0.0.0-alpha.1
	 */
	public function maybe_whitelist_script( $skip = false, $src = '', $name = '', $category = '' ) {
		// If already marked to skip, don't override.
		if ( $skip ) {
			return $skip;
		}

		// Check if whitelisting should apply.
		if ( ! $this->should_whitelist() ) {
			return $skip;
		}

		// Check if this is a Google service.
		if ( $this->is_google_service( $src, $name ) ) {
			/**
			 * Filter: Allow developers to override Google script whitelisting.
			 *
			 * @param bool   $whitelist True to whitelist (skip blocking).
			 * @param string $src       Script source URL.
			 * @param string $name      Service name.
			 * @param string $category  Category name.
			 * @since 0.0.0-alpha.1
			 */
			return apply_filters( 'surecookie_gcm_whitelist_script', true, $src, $name, $category );
		}

		return $skip;
	}

	/**
	 * Maybe whitelist an iframe from being blocked.
	 *
	 * @param bool   $skip     Whether to skip blocking (default false).
	 * @param string $src      Iframe source URL.
	 * @param string $name     Service name from known scripts.
	 * @param string $category Category name from known scripts.
	 * @return bool True to skip blocking, false to block.
	 * @since 0.0.0-alpha.1
	 */
	public function maybe_whitelist_iframe( $skip = false, $src = '', $name = '', $category = '' ) {
		// If already marked to skip, don't override.
		if ( $skip ) {
			return $skip;
		}

		// Check if whitelisting should apply.
		if ( ! $this->should_whitelist() ) {
			return $skip;
		}

		// Check if this is a Google service.
		if ( $this->is_google_service( $src, $name ) ) {
			/**
			 * Filter: Allow developers to override Google iframe whitelisting.
			 *
			 * @param bool   $whitelist True to whitelist (skip blocking).
			 * @param string $src       Iframe source URL.
			 * @param string $name      Service name.
			 * @param string $category  Category name.
			 * @since 0.0.0-alpha.1
			 */
			return apply_filters( 'surecookie_gcm_whitelist_iframe', true, $src, $name, $category );
		}

		return $skip;
	}

	/**
	 * Maybe whitelist an <embed> from being blocked.
	 *
	 * Parity with maybe_whitelist_iframe() - <embed> uses the same src URL
	 * semantics, so the whitelist logic delegates to the same helper.
	 *
	 * @param bool   $skip     Whether to skip blocking (default false).
	 * @param string $src      <embed> source URL.
	 * @param string $name     Service name from known scripts.
	 * @param string $category Category name from known scripts.
	 * @return bool True to skip blocking, false to block.
	 * @since 0.0.1-beta.2
	 */
	public function maybe_whitelist_embed( $skip = false, $src = '', $name = '', $category = '' ) {
		if ( $skip ) {
			return $skip;
		}

		if ( ! $this->should_whitelist() ) {
			return $skip;
		}

		if ( $this->is_google_service( $src, $name ) ) {
			/**
			 * Filter: Allow developers to override Google <embed> whitelisting.
			 *
			 * @param bool   $whitelist True to whitelist (skip blocking).
			 * @param string $src       <embed> source URL.
			 * @param string $name      Service name.
			 * @param string $category  Category name.
			 * @since 0.0.1-beta.2
			 */
			return apply_filters( 'surecookie_gcm_whitelist_embed', true, $src, $name, $category );
		}

		return $skip;
	}

	/**
	 * Maybe whitelist an <object> from being blocked.
	 *
	 * Parity with maybe_whitelist_iframe() - for <object>, the resource URL
	 * arrives via the `data=` attribute but the matching logic against
	 * gcm_compatible patterns is identical.
	 *
	 * @param bool   $skip     Whether to skip blocking (default false).
	 * @param string $data     <object> resource URL.
	 * @param string $name     Service name from known scripts.
	 * @param string $category Category name from known scripts.
	 * @return bool True to skip blocking, false to block.
	 * @since 0.0.1-beta.2
	 */
	public function maybe_whitelist_object( $skip = false, $data = '', $name = '', $category = '' ) {
		if ( $skip ) {
			return $skip;
		}

		if ( ! $this->should_whitelist() ) {
			return $skip;
		}

		if ( $this->is_google_service( $data, $name ) ) {
			/**
			 * Filter: Allow developers to override Google <object> whitelisting.
			 *
			 * @param bool   $whitelist True to whitelist (skip blocking).
			 * @param string $data      <object> resource URL.
			 * @param string $name      Service name.
			 * @param string $category  Category name.
			 * @since 0.0.1-beta.2
			 */
			return apply_filters( 'surecookie_gcm_whitelist_object', true, $data, $name, $category );
		}

		return $skip;
	}

	/**
	 * Flag scan-detected resources that Google Consent Mode manages.
	 *
	 * Hooked on `surecookie_scanned_resources`. Sets `gcmManaged` on each
	 * script/iframe so the scanner UI can replace the (no-op) block toggle with
	 * a notice. Matches the stored resource URL with the same
	 * should_whitelist() + is_google_service() path used by runtime blocking, so
	 * the flag can never disagree with what actually happens.
	 *
	 * @since 1.2.0
	 * @param mixed $resources Scanned resources payload ({ scripts: [], iframes: [] }).
	 * @return mixed
	 */
	public function annotate_scanned_resources( $resources = null ) {
		if ( ! is_array( $resources ) ) {
			return $resources;
		}

		// The saved-state gate is constant for the whole request.
		$managed_state = $this->should_whitelist();

		foreach ( [ 'scripts', 'iframes' ] as $type ) {
			if ( empty( $resources[ $type ] ) || ! is_array( $resources[ $type ] ) ) {
				continue;
			}

			foreach ( $resources[ $type ] as $index => $resource ) {
				if ( ! is_array( $resource ) ) {
					continue;
				}

				// Match on the stored resource URL so this mirrors runtime blocking exactly. The scanner writes it under `url`; read
				// `src` first for parity with the other reader (service-detector), then fall back to the domain when no URL was stored.
				$src        = (string) ( $resource['src'] ?? $resource['url'] ?? '' );
				$identifier = $src !== '' ? $src : (string) ( $resource['domain'] ?? '' );

				// `gcmCompatible` is static (does the resource match a GCM-compatible Google service) so the admin UI can derive the managed state live
				// from the in-flight gcm_enabled toggle. `gcmManaged` is that same flag gated by the saved GCM/blocking state.
				$compatible = $identifier !== '' && $this->is_google_service( $identifier, '' );

				$resources[ $type ][ $index ]['gcmCompatible'] = $compatible;
				$resources[ $type ][ $index ]['gcmManaged']    = $compatible && $managed_state;
			}
		}

		return $resources;
	}

	/**
	 * Whether a resource is currently managed by Google Consent Mode: GCM
	 * whitelisting is active (GCM + blocking enabled) AND the resource matches a
	 * GCM-compatible service/pattern.
	 *
	 * @since 1.2.0
	 * @param string $src  Resource URL (or domain).
	 * @param string $name Optional known-script service name.
	 * @return bool
	 */
	public function is_gcm_managed( string $src, string $name = '' ): bool {
		if ( $src === '' && $name === '' ) {
			return false;
		}

		return $this->should_whitelist() && $this->is_google_service( $src, $name );
	}

	/**
	 * Filter callback: report whether GCM manages the given resource.
	 *
	 * @param bool   $managed Incoming value (unused; we decide from the URL).
	 * @param string $src     Resource URL.
	 * @return bool
	 * @since 1.2.0
	 */
	public function filter_is_gcm_managed( $managed = false, $src = '' ) {
		return $this->is_gcm_managed( (string) $src );
	}

	/**
	 * `surecookie_guard_skip_pattern` callback: leave a GCM-whitelisted pattern
	 * out of the browser guard's map, so both layers make the same decision.
	 *
	 * @param mixed $skip    Whether the pattern is already being omitted.
	 * @param mixed $pattern Blocking pattern.
	 * @param mixed $kind    Resource kind ('script'|'iframe'), unused: a
	 *                       whitelisted host is released on every tag.
	 * @return mixed
	 * @since 1.5.0
	 */
	public function maybe_skip_guard_pattern( $skip = false, $pattern = '', $kind = '' ) {
		if ( $skip || ! is_string( $pattern ) || $pattern === '' || ! $this->should_whitelist() ) {
			return $skip;
		}

		return $this->is_google_service( $pattern, '' ) ? true : $skip;
	}

	/**
	 * Resolved GCM auto-whitelist: the services + URL patterns that are exempt
	 * from blocking while Google Consent Mode is active. Returns empty lists when
	 * whitelisting is not currently in effect (GCM or blocking off), matching the
	 * same gate as runtime blocking. Read-only, for surfacing in the UI.
	 *
	 * @since 1.3.0
	 * @return array{services: array<string>, patterns: array<string>}
	 */
	public function get_whitelisted_scripts(): array {
		$empty = [
			'services' => [],
			'patterns' => [],
		];

		if ( ! $this->should_whitelist() ) {
			$data = $empty;
		} else {
			if ( $this->whitelist_cache === null ) {
				$this->build_whitelist_cache();
			}
			$data = $this->whitelist_cache ?? $empty;
		}

		/**
		 * Filter the resolved GCM auto-whitelist list.
		 *
		 * @since 1.3.0
		 * @param array{services: array<string>, patterns: array<string>} $data Services + URL patterns exempt from blocking.
		 */
		$filtered = apply_filters( 'surecookie_gcm_whitelisted_scripts', $data );
		return is_array( $filtered ) ? $filtered : $data;
	}

	/**
	 * Add the GCM auto-whitelist to the admin localize payload.
	 *
	 * @param mixed $data Existing admin localize data.
	 * @since 1.3.0
	 * @return mixed
	 */
	public function add_whitelist_to_admin_data( $data = null ) {
		if ( is_array( $data ) ) {
			$data['gcmWhitelistedScripts'] = $this->get_whitelisted_scripts();
		}

		return $data;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 0.0.0-alpha.1
	 */
	private function register_hooks(): void {
		// Hook into script and embedded-content blocking filters.
		add_filter( 'surecookie_skip_script', [ $this, 'maybe_whitelist_script' ], 10, 4 );
		add_filter( 'surecookie_skip_iframe', [ $this, 'maybe_whitelist_iframe' ], 10, 4 );
		add_filter( 'surecookie_skip_embed', [ $this, 'maybe_whitelist_embed' ], 10, 4 );
		add_filter( 'surecookie_skip_object', [ $this, 'maybe_whitelist_object' ], 10, 4 );

		// The browser guard has its own always-allow channel, and a release the
		// tag passes make has to be mirrored there or the guard parks the very
		// tags GCM injects. Priority 10 leaves Pro's quarantine veto (20) last.
		add_filter( 'surecookie_guard_skip_pattern', [ $this, 'maybe_skip_guard_pattern' ], 10, 3 );

		// Flag scan-detected resources that GCM manages, for the scanner UI.
		add_filter( 'surecookie_scanned_resources', [ $this, 'annotate_scanned_resources' ] );

		// Let other modules (e.g. the Pro auto-scan guard) ask whether GCM manages a resource, without hard-depending on this class.
		add_filter( 'surecookie_is_gcm_managed', [ $this, 'filter_is_gcm_managed' ], 10, 2 );

		// Expose the resolved GCM auto-whitelist to the admin app so Pro's
		// Always Allowed manager can list it read-only.
		add_filter( 'surecookie_admin_localize_data', [ $this, 'add_whitelist_to_admin_data' ] );
	}

	/**
	 * Check if whitelisting should be applied.
	 *
	 * Whitelisting only applies when:
	 * 1. Google Consent Mode is enabled
	 * 2. Script or Content Blocking is enabled
	 *
	 * @return bool True if whitelisting should apply.
	 * @since 0.0.0-alpha.1
	 */
	private function should_whitelist() {
		// Check if GCM is enabled.
		if ( ! Settings::get( 'gcm_enabled' ) ) {
			return false;
		}

		// Check if script blocking or content blocking is enabled.
		// Use class_exists check since ScriptBlocking module might not be loaded.
		if ( ! class_exists( 'SureCookie\Inc\Modules\ScriptBlocking\Utils' ) ) {
			return false;
		}

		$resource_blocking_enabled = ScriptBlockingUtils::is_blocking_enabled();

		// Only whitelist if at least one blocking feature is enabled.
		if ( ! $resource_blocking_enabled ) {
			return false;
		}

		/**
		 * Filter: Allow developers to control when whitelisting applies.
		 *
		 * @param bool $should_whitelist Whether whitelisting should apply.
		 * @since 0.0.0-alpha.1
		 */
		return apply_filters( 'surecookie_gcm_should_whitelist', true );
	}

	/**
	 * Check if a service is GCM-compatible (should be whitelisted).
	 *
	 * @param string $src  Source URL.
	 * @param string $name Service name.
	 * @return bool True if service is GCM-compatible.
	 * @since 0.0.0-alpha.1
	 */
	private function is_google_service( $src, $name ) {
		// Build whitelist cache if not already built.
		if ( $this->whitelist_cache === null ) {
			$this->build_whitelist_cache();
		}

		// Safety check: Ensure cache was built successfully.
		if ( $this->whitelist_cache === null ) {
			return false;
		}

		// First, check by service name (most efficient - O(1) lookup).
		if ( in_array( $name, $this->whitelist_cache['services'], true ) ) {
			return true;
		}

		// Fallback: Check URL patterns (for external APIs that might not match name).
		if ( ! empty( $src ) ) {
			foreach ( $this->whitelist_cache['patterns'] as $pattern ) {
				if ( stripos( $src, $pattern ) !== false ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Build whitelist cache from Known_Scripts data.
	 *
	 * Extracts all services with 'gcm_compatible' flag and their URL patterns.
	 * This is called once per request and cached in memory.
	 *
	 * @return void
	 * @since 0.0.0-alpha.1
	 */
	private function build_whitelist_cache(): void {
		// Initialize cache structure.
		$this->whitelist_cache = [
			'services' => [],
			'patterns' => [],
		];

		// Check if Known_Scripts class exists (Script Blocking module might not be loaded).
		if ( ! class_exists( 'SureCookie\Inc\Modules\Services\Known_Scripts' ) ) {
			return;
		}

		// Get all known scripts from API/fallback.
		$known_scripts = Known_Scripts::get_instance();
		$all_scripts   = $known_scripts->get_all_scripts();

		if ( empty( $all_scripts ) ) {
			return;
		}

		// Extract GCM-compatible services and patterns.
		foreach ( $all_scripts as $services ) {
			if ( ! is_array( $services ) ) {
				continue;
			}

			foreach ( $services as $service_key => $service ) {
				// Check if service is marked as GCM-compatible.
				if ( empty( $service['gcm_compatible'] ) ) {
					continue;
				}

				// Add service name to whitelist.
				$this->whitelist_cache['services'][] = $service_key;

				// Every bucket, as Service_Matcher reads them: naming
				// `scripts`/`iframes` literally dropped Google Fonts outright
				// when its patterns moved to `styles`.
				foreach ( Pattern_Kinds::buckets() as $bucket ) {
					if ( ! is_array( $service[ $bucket ] ?? null ) ) {
						continue;
					}

					foreach ( $service[ $bucket ] as $pattern ) {
						$this->whitelist_cache['patterns'][] = $pattern;
					}
				}
			}
		}

		// Remove duplicates and re-index.
		$this->whitelist_cache['services'] = array_values( array_unique( $this->whitelist_cache['services'] ) );
		$this->whitelist_cache['patterns'] = array_values( array_unique( $this->whitelist_cache['patterns'] ) );

		/**
		 * Filter: Allow developers to modify the GCM whitelist cache.
		 *
		 * @param array{services: array<string>, patterns: array<string>} $whitelist_cache Whitelist data.
		 * @since 0.0.0-alpha.1
		 */
		$this->whitelist_cache = apply_filters( 'surecookie_gcm_whitelist_cache', $this->whitelist_cache );
	}
}
