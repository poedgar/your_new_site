<?php
/**
 * Service Matcher.
 *
 * Maps detected resource URLs to known-service slugs using the SAME blocking
 * patterns the Blocker uses (read via the `surecookie_known_scripts` filter), so
 * declared-cookie seeding, the Detected Resources overlay, the library's
 * "detected" state, and the "Add as Service" smart-detect CTAs all agree on what
 * a scan actually detected.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.3.0
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Service_Matcher
 *
 * @since 1.3.0
 */
class Service_Matcher {
	use GetInstance;

	/**
	 * Collect the lower-cased third-party script/iframe URLs reported across pages.
	 *
	 * Accepts both shapes this is called with: a live scan page, where an iframe
	 * carries `src`, and the stored resources option, where `admin/sync.php`
	 * files that same value under `url`. Reading only `src` left every
	 * path-gated embed (`youtube.com/embed/`, `youtu.be/`) matched against a
	 * bare host, so no such service was ever detected from the store. Falling
	 * back on an EMPTY string matters too: a row the blocker merged in sets
	 * `url` to '', which `??` treats as present.
	 *
	 * @param array<int, array<string, mixed>> $pages Scan result pages, or stored resource sets.
	 * @since 1.3.0
	 * @return array<int, string>
	 */
	public function collect_resource_urls( array $pages ): array {
		$urls = [];

		foreach ( $pages as $page ) {
			foreach ( [
				'scripts' => [ 'url', 'domain' ],
				'iframes' => [ 'src', 'url', 'domain' ],
			] as $bucket => $keys ) {
				foreach ( (array) ( $page[ $bucket ] ?? [] ) as $resource ) {
					if ( ! is_array( $resource ) ) {
						continue;
					}

					foreach ( $keys as $key ) {
						$candidate = trim( (string) ( $resource[ $key ] ?? '' ) );
						if ( $candidate !== '' ) {
							$urls[] = strtolower( $candidate );
							break;
						}
					}
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Determine which of the given service slugs are present, by testing each
	 * service's script/iframe URL patterns against the detected resource URLs.
	 *
	 * @param array<int, string> $service_slugs Slugs to test (catalog keys). Empty = all.
	 * @param array<int, string> $urls          Detected resource URLs (lower-cased).
	 * @since 1.3.0
	 * @return array<int, string> Matched service slugs.
	 */
	public function match_services( array $service_slugs, array $urls ): array {
		$patterns_by_service = $this->get_service_patterns( $service_slugs );
		$matched             = [];

		foreach ( $patterns_by_service as $service => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( $pattern === '' ) {
					continue;
				}

				foreach ( $urls as $url ) {
					if ( strpos( $url, $pattern ) !== false ) {
						$matched[] = $service;
						continue 3;
					}
				}
			}
		}

		return $matched;
	}

	/**
	 * The first known-service slug whose blocking pattern matches a single URL,
	 * or '' when none does. Used by the per-row "Add as Service" smart-detect CTA.
	 *
	 * @param string $url URL / domain to test.
	 * @since 1.3.0
	 * @return string Matched slug, or '' if no match.
	 */
	public function match_url( string $url ): string {
		$url = strtolower( trim( $url ) );
		if ( $url === '' ) {
			return '';
		}

		$matched = $this->match_services( [], [ $url ] );

		return $matched[0] ?? '';
	}

	/**
	 * Collect every URL pattern for the requested services from the catalog
	 * blocking view, so matching keys off the same patterns the blocker uses.
	 * Reads the catalog view directly (not the surecookie_known_scripts filter)
	 * so it excludes the scan-merged synthetic rows and needs no provider.
	 *
	 * Buckets no pass acts on are included: a font host still identifies its
	 * service, which is what the admin badge and the declared cookies need.
	 *
	 * @param array<int, string> $service_slugs Slugs to look up. Empty = every service.
	 * @since 1.3.0
	 * @return array<string, array<int, string>> Patterns keyed by service slug.
	 */
	public function get_service_patterns( array $service_slugs = [] ): array {
		/** @var array<string, array<string, mixed>> $all_scripts */
		$all_scripts = Services_Source::get_instance()->get_blocking_view();
		$wanted      = $service_slugs === [] ? null : array_fill_keys( $service_slugs, true );
		$patterns    = [];

		foreach ( $all_scripts as $services ) {
			if ( ! is_array( $services ) ) {
				continue;
			}

			foreach ( $services as $slug => $definition ) {
				if ( ( $wanted !== null && ! isset( $wanted[ $slug ] ) ) || ! is_array( $definition ) ) {
					continue;
				}

				$service_patterns = [];

				foreach ( Pattern_Kinds::buckets() as $bucket ) {
					$service_patterns = array_merge(
						$service_patterns,
						is_array( $definition[ $bucket ] ?? null ) ? $definition[ $bucket ] : []
					);
				}

				$patterns[ $slug ] = array_map(
					static fn( $pattern ): string => strtolower( (string) $pattern ),
					$service_patterns
				);
			}
		}

		return $patterns;
	}

	/**
	 * Same as get_service_patterns() but keeps each pattern in its catalog
	 * bucket, so callers that place a pattern (e.g. the Detected Resources
	 * overlay) use the catalog's authoritative delivery kind instead of guessing
	 * it from the URL.
	 *
	 * @param array<int, string> $service_slugs Slugs to look up. Empty = every service.
	 * @since 1.3.0
	 * @return array<string, array<string, array<int, string>>> Patterns by slug, then by Pattern_Kinds bucket.
	 */
	public function get_service_patterns_by_kind( array $service_slugs = [] ): array {
		/** @var array<string, array<string, mixed>> $all_scripts */
		$all_scripts = Services_Source::get_instance()->get_blocking_view();
		$wanted      = $service_slugs === [] ? null : array_fill_keys( $service_slugs, true );
		$lower       = static fn( $list ): array => array_map(
			static fn( $pattern ): string => strtolower( (string) $pattern ),
			is_array( $list ) ? array_values( $list ) : []
		);
		$patterns    = [];

		foreach ( $all_scripts as $services ) {
			if ( ! is_array( $services ) ) {
				continue;
			}

			foreach ( $services as $slug => $definition ) {
				if ( ( $wanted !== null && ! isset( $wanted[ $slug ] ) ) || ! is_array( $definition ) ) {
					continue;
				}

				$patterns[ $slug ] = [];

				foreach ( Pattern_Kinds::buckets() as $bucket ) {
					$patterns[ $slug ][ $bucket ] = $lower( $definition[ $bucket ] ?? [] );
				}
			}
		}

		return $patterns;
	}
}
