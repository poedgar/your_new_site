<?php
/**
 * Scan-Detected Scripts Merger.
 *
 * Merges scan-detected third-party resources into the known scripts
 * database via the surecookie_known_scripts filter.
 *
 * @package SureCookie\Inc\Modules\ScriptBlocking
 * @since 0.0.0-alpha.2
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Modules\Services\Pattern_Kinds;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Scan_Scripts
 *
 * Hooks into the known_scripts filter to merge scan-detected resources.
 *
 * @since 0.0.0-alpha.2
 */
class Scan_Scripts {
	use GetInstance;

	/**
	 * Cached scanned resources data.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cached_resources = null;

	/**
	 * Host-grouped pattern index, keyed by a hash of the pattern set.
	 *
	 * @var array<string, array<string, array<int, string>>>
	 */
	private static array $host_index_cache = [];

	/**
	 * Constructor.
	 *
	 * @since 0.0.0-alpha.2
	 */
	private function __construct() {
		add_filter( 'surecookie_known_scripts', [ $this, 'merge_scan_detected_resources' ], 20 );

		// Skip blocking for scripts/iframes whose src matches an excluded domain.
		// Kind-specific callbacks so a "script"-scoped exclusion never skips an
		// iframe on the same host, and vice versa.
		add_filter( 'surecookie_skip_script', [ $this, 'should_skip_excluded_script' ], 10, 5 );
		add_filter( 'surecookie_skip_iframe', [ $this, 'should_skip_excluded_iframe' ], 10, 5 );
	}

	/**
	 * Merge scan-detected resources into the known scripts dataset.
	 *
	 * @param mixed $scripts Expected array<string, array<string, mixed>> of known scripts by category.
	 * @since 0.0.0-alpha.2
	 * @return mixed Merged scripts, or $scripts untouched.
	 */
	public function merge_scan_detected_resources( $scripts = [] ) {
		if ( ! is_array( $scripts ) ) {
			return $scripts;
		}

		$resources = $this->get_scanned_resources();

		if ( empty( $resources ) ) {
			return $scripts;
		}

		// Build a flat list of all existing patterns to avoid duplicates.
		$existing_patterns = $this->build_existing_pattern_index( $scripts );

		// Merge scan-detected scripts.
		foreach ( $resources['scripts'] ?? [] as $resource ) {
			$this->merge_resource( $scripts, $resource, 'scripts', $existing_patterns );
		}

		// Merge scan-detected iframes.
		foreach ( $resources['iframes'] ?? [] as $resource ) {
			$this->merge_resource( $scripts, $resource, 'iframes', $existing_patterns );
		}

		return $scripts;
	}

	/**
	 * Clear the static cache (useful after scan results are updated).
	 *
	 * @since 0.0.0-alpha.2
	 * @return void
	 */
	public static function clear_cache(): void {
		self::$cached_resources = null;
		self::$host_index_cache = [];
		Resource_Categories::clear_cache();
	}

	/**
	 * `surecookie_skip_script` callback: skip a script whose src matches a
	 * script-scoped (or legacy bare-domain) exclusion.
	 *
	 * @since 1.3.0
	 * Untyped by design: this is a public filter, so the incoming values are
	 * whatever the previous callback returned. A boolean filter answers yes or
	 * no, so unusable input is coerced rather than passed through.
	 *
	 * @param mixed $skip     Expected bool, whether the resource is already marked to skip.
	 * @param mixed $src      Expected string, the script src.
	 * @param mixed $name     Expected string, matched service key.
	 * @param mixed $category Expected string, matched service category.
	 * @param mixed $pattern  Expected string, pattern that matched, for resources with no src.
	 * @return bool
	 */
	public function should_skip_excluded_script( $skip = false, $src = '', $name = '', $category = '', $pattern = '' ): bool {
		return $this->should_skip_excluded_resource(
			(bool) $skip,
			is_string( $src ) ? $src : '',
			'script',
			is_string( $pattern ) ? $pattern : ''
		);
	}

	/**
	 * `surecookie_skip_iframe` callback: skip an iframe whose src matches an
	 * iframe-scoped (or legacy bare-domain) exclusion.
	 *
	 * @since 1.3.0
	 * Untyped by design: see should_skip_excluded_script().
	 *
	 * @param mixed $skip     Expected bool, whether the resource is already marked to skip.
	 * @param mixed $src      Expected string, the iframe src.
	 * @param mixed $name     Expected string, matched service key.
	 * @param mixed $category Expected string, matched service category.
	 * @param mixed $pattern  Expected string, pattern that matched, for resources with no src.
	 * @return bool
	 */
	public function should_skip_excluded_iframe( $skip = false, $src = '', $name = '', $category = '', $pattern = '' ): bool {
		return $this->should_skip_excluded_resource(
			(bool) $skip,
			is_string( $src ) ? $src : '',
			'iframe',
			is_string( $pattern ) ? $pattern : ''
		);
	}

	/**
	 * Skip blocking when the resource src matches an excluded entry of the same
	 * kind (or a legacy bare-domain entry, which applies to any kind).
	 *
	 * Exclusions are keyed per (kind, domain) so the per-resource "Do not block"
	 * toggle on a script does not also unblock the iframe on the same host.
	 *
	 * @since 0.0.0-alpha.2
	 * @param bool   $skip    Whether the resource is already marked to skip.
	 * @param string $src     The resource URL (script src or iframe src).
	 * @param string $kind    Resource kind ('script'|'iframe').
	 * @param string $pattern Pattern that matched, for resources with no src.
	 * @return bool
	 */
	public function should_skip_excluded_resource( bool $skip, string $src, string $kind = 'any', string $pattern = '' ): bool {
		if ( $skip ) {
			return $skip;
		}

		return Resource_Categories::matches_excluded_any( [ $src, $pattern ], $kind );
	}

	/**
	 * Merge a single resource into the scripts array.
	 *
	 * @param array<mixed>         $scripts          Known scripts (by reference).
	 * @param array<string, mixed> $resource         Scan-detected resource.
	 * @param string               $type             Resource type ('scripts' or 'iframes').
	 * @param array<string, bool>  $existing_patterns  Index of existing patterns.
	 * @since 0.0.0-alpha.2
	 * @return void
	 */
	private function merge_resource( array &$scripts, array $resource, string $type, array $existing_patterns ): void {
		$domain   = $resource['domain'] ?? '';
		$category = $resource['category'] ?? 'marketing';

		if ( empty( $domain ) ) {
			return;
		}

		// Skip if excluded by admin. Kind-scoped: a script exclusion does not
		// stop the iframe on the same host from being blocked, and vice versa.
		$kind = $type === 'iframes' ? 'iframe' : 'script';
		if ( Resource_Categories::is_excluded_domain( (string) $domain, $kind ) ) {
			return;
		}

		// Skip if this domain already exists in known-scripts patterns.
		if ( isset( $existing_patterns[ $domain ] ) ) {
			return;
		}

		// Or if a catalog HOST already covers the URL this row was recorded from: the
		// catalog's own category then governs that host. A catalog PATH (`paypal.com/sdk`)
		// does not, because the scan keeps one sample URL per host, so dropping the row
		// would leave the rest of the host with no pattern at all (#1313).
		if ( self::catalog_covers_observed_url( $resource, $existing_patterns ) ) {
			return;
		}

		// Ensure the category exists.
		if ( ! isset( $scripts[ $category ] ) ) {
			$scripts[ $category ] = [];
		}

		// Build a unique service key from the domain.
		$service_key = 'scan_' . str_replace( [ '.', '-' ], '_', $domain );

		// Add to the appropriate type array. The label is visitor-facing copy, so the
		// domain fallback has to cover every way the scan says "no vendor": an empty
		// string (`??` kept it, rendering "would connect to ."), a classifier
		// placeholder like "Unknown", and a Unicode-blank that survives trim (#1429).
		$vendor = Sanitize::vendor( $resource['vendor'] ?? '' );
		$entry  = [
			'label' => Sanitize::is_blank( $vendor ) ? $domain : $vendor,
		];

		if ( $type === 'iframes' ) {
			$entry['iframes'] = [ $domain ];
		} else {
			$entry['scripts'] = [ $domain ];
		}

		$scripts[ $category ][ $service_key ] = $entry;
	}

	/**
	 * Whether a catalog host pattern already covers the URL this row was seen at.
	 *
	 * A host the catalog names (`b-cdn.net`) is already governed by its category, so a
	 * row for one of its zones can only compete with that category. A path
	 * (`facebook.com/tr`, `paypal.com/sdk`) governs just that file, and a row on the
	 * same host is the only pattern for the rest of it, so it must survive (#1313).
	 *
	 * Patterns are indexed by host so a row tests two or three candidates
	 * instead of the whole catalog. Measured on the shipped catalog, the naive
	 * form cost 21.8ms per page because the filter runs several times per
	 * request; this is 0.7ms.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $resource          Scanned resource row.
	 * @param array<string, bool>  $existing_patterns Known-scripts pattern index.
	 * @return bool
	 */
	private static function catalog_covers_observed_url( array $resource, array $existing_patterns ): bool {
		// Iframe rows store the observed src, script rows the url.
		$url = trim( (string) ( $resource['url'] ?? $resource['src'] ?? '' ) );

		// Nothing observed to compare against, so the row still carries meaning.
		if ( $url === '' ) {
			return false;
		}

		foreach ( self::candidate_patterns( $existing_patterns, $url ) as $pattern ) {
			if ( ! Blocker::names_path( $pattern ) && Entry_Match::matches( $pattern, $url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Catalog patterns worth testing against one URL: those claiming its host or
	 * a parent of it, plus the host-less ones, which can match any path.
	 *
	 * @since 1.5.0
	 * @param array<string, bool> $existing_patterns Known-scripts pattern index.
	 * @param string              $url               Observed resource URL.
	 * @return array<int, string>
	 */
	private static function candidate_patterns( array $existing_patterns, string $url ): array {
		$index = self::patterns_by_host( $existing_patterns );
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		$candidates = $index[''] ?? [];

		while ( $host !== '' ) {
			if ( ! empty( $index[ $host ] ) ) {
				$candidates = array_merge( $candidates, $index[ $host ] );
			}

			$dot = strpos( $host, '.' );
			if ( $dot === false ) {
				break;
			}

			$host = substr( $host, $dot + 1 );
		}

		return $candidates;
	}

	/**
	 * Group patterns by the host they claim, memoized for the request.
	 *
	 * @since 1.5.0
	 * @param array<string, bool> $existing_patterns Known-scripts pattern index.
	 * @return array<string, array<int, string>>
	 */
	private static function patterns_by_host( array $existing_patterns ): array {
		$key = md5( (string) wp_json_encode( array_keys( $existing_patterns ) ) );

		if ( isset( self::$host_index_cache[ $key ] ) ) {
			return self::$host_index_cache[ $key ];
		}

		$index = [ '' => [] ];

		foreach ( array_keys( $existing_patterns ) as $pattern ) {
			$pattern                                   = (string) $pattern;
			$index[ self::pattern_host( $pattern ) ][] = $pattern;
		}

		self::$host_index_cache[ $key ] = $index;

		return $index;
	}

	/**
	 * The host a pattern claims, or an empty string when it claims none.
	 *
	 * Mirrors how {@see Entry_Match} decides the same thing: a query or fragment
	 * is an exact-URL claim, and a first segment without a dot is a path, not a
	 * host.
	 *
	 * @since 1.5.0
	 * @param string $pattern Catalog blocking pattern.
	 * @return string
	 */
	private static function pattern_host( string $pattern ): string {
		if ( strpbrk( $pattern, '?#' ) !== false || strpos( $pattern, '.' ) === false ) {
			return '';
		}

		$first = strstr( $pattern, '/', true );
		$first = $first === false ? $pattern : $first;

		if ( strpos( $first, '.' ) === false ) {
			return '';
		}

		$url = strpos( $pattern, '//' ) === 0 ? 'https:' . $pattern : 'https://' . ltrim( $pattern, '/' );

		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * Build an index of existing patterns for fast duplicate checks.
	 *
	 * @param array<string, array<string, mixed>> $scripts Known scripts by category.
	 * @since 0.0.0-alpha.2
	 * @return array<string, bool>
	 */
	private function build_existing_pattern_index( array $scripts ): array {
		$index = [];

		foreach ( $scripts as $services ) {
			if ( ! is_array( $services ) ) {
				continue;
			}

			foreach ( $services as $service ) {
				// Every bucket, including the ones no pass reads: re-adding a
				// known stylesheet host as a scan-detected script pattern would
				// put it back in front of the passes that cannot match it.
				foreach ( Pattern_Kinds::buckets() as $bucket ) {
					foreach ( $service[ $bucket ] ?? [] as $pattern ) {
						$index[ $pattern ] = true;
					}
				}
			}
		}

		return $index;
	}

	/**
	 * Get scanned resources from the database (with static cache).
	 *
	 * @since 0.0.0-alpha.2
	 * @return array<string, mixed>
	 */
	private function get_scanned_resources(): array {
		if ( self::$cached_resources !== null ) {
			return self::$cached_resources;
		}

		$resources = get_option( SURECOOKIE_SCANNED_RESOURCES_OPTION, [] );

		if ( ! is_array( $resources ) ) {
			$resources = [];
		}

		self::$cached_resources = $resources;

		return self::$cached_resources;
	}
}
