<?php
/**
 * Declared Cookies catalog.
 *
 * A blocked third-party embed (YouTube, Vimeo, Google Maps, reCAPTCHA, ...) never
 * loads before consent, so it never sets its cookies and the scanner cannot
 * observe them - leaving them absent from the cookie manager and policy even
 * though the service is present and blocked.
 *
 * This bridges that gap: when a scan detects a known service's script or iframe,
 * the cookies it is documented to set are *declared* from a bundled catalog
 * (data/service-cookies.json), merged into the scanned-cookie store flagged
 * `source => 'declared'`, so they surface to visitors while the embed stays blocked.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.2.5
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\Functions\Cookie_Identity;
use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Declared_Cookies
 *
 * Loads the declared-cookie catalog and seeds cookies for detected services.
 *
 * @since 1.2.5
 */
class Declared_Cookies {
	use GetInstance;

	/**
	 * Shortest literal prefix a catalog wildcard name may match on, so a stray
	 * pattern cannot swallow unrelated cookies.
	 *
	 * @since 1.3.0
	 */
	private const MIN_WILDCARD_PREFIX = 3;

	/**
	 * Memoized catalog detail lookup: [ exact, prefixes ].
	 *
	 * @var array{0: array<string, array<string, string>>, 1: array<string, array<string, string>>}|null
	 * @since 1.3.0
	 */
	private ?array $provider_index = null;

	/**
	 * Get the declared-cookie catalog (service slug => cookie definitions).
	 *
	 * Delegates to Service_Cookies_Source, which resolves the transient / file
	 * cache / bundled floor and applies the `surecookie_declared_service_cookies`
	 * filter exactly once. The `_meta` documentation key is stripped upstream.
	 *
	 * @since 1.2.5
	 * @return array<string, array<int, array<string, mixed>>> Cookies keyed by service slug.
	 */
	public function get_catalog(): array {
		return Service_Cookies_Source::get_instance()->get_catalog();
	}

	/**
	 * Prune stored declared cookies no longer present in the catalog.
	 *
	 * Only `source => 'declared'` rows are considered (observed + custom
	 * untouched). A declared cookie is kept while its `declared:{slug}:{name}`
	 * signature or its name+domain is still in the catalog. Runs after a remote
	 * refresh and each scan so catalog removals propagate.
	 *
	 * @since 1.2.5
	 * @return void
	 */
	public function reconcile_declared_cookies(): void {
		$stored = get_option( SURECOOKIE_SCANNED_COOKIES_OPTION, [] );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return;
		}

		[ $valid_signatures, $valid_name_domain ] = $this->catalog_lookup();

		$changed = false;

		foreach ( $stored as $category => $cookies ) {
			if ( ! is_array( $cookies ) ) {
				continue;
			}

			foreach ( $cookies as $index => $cookie ) {
				// Leave observed + custom cookies untouched.
				if ( ! is_array( $cookie ) || ( $cookie['source'] ?? '' ) !== 'declared' ) {
					continue;
				}

				$signature   = (string) ( $cookie['signature_id'] ?? '' );
				$name_domain = Cookie_Identity::key_for( $cookie );

				$present = ( $signature !== '' && isset( $valid_signatures[ $signature ] ) )
					|| isset( $valid_name_domain[ $name_domain ] );

				if ( ! $present ) {
					unset( $stored[ $category ][ $index ] );
					$changed = true;
				}
			}

			$stored[ $category ] = array_values( $stored[ $category ] );
		}

		if ( $changed ) {
			Update::option( SURECOOKIE_SCANNED_COOKIES_OPTION, $stored );
		}
	}

	/**
	 * Drop the stored declared rows one service owns.
	 *
	 * Suppression alone stops a service being declared again, but rows a scan
	 * already persisted are in the option and stay on the policy, so removing a
	 * service without this clears the read-time half and leaves the stored half
	 * published. Matches the slug field or the older signature prefix, and only
	 * ever on a declared row: an observed cookie is evidence the site set it, and
	 * deleting that here would hide a live tracker.
	 *
	 * @since 1.5.1
	 * @param string $slug Catalog service slug.
	 * @return int Rows removed.
	 */
	public function forget_service( string $slug ): int {
		$stored = get_option( SURECOOKIE_SCANNED_COOKIES_OPTION, [] );
		if ( $slug === '' || ! is_array( $stored ) || empty( $stored ) ) {
			return 0;
		}

		$prefix  = 'declared:' . $slug . ':';
		$removed = 0;

		foreach ( $stored as $category => $cookies ) {
			if ( ! is_array( $cookies ) ) {
				continue;
			}

			foreach ( $cookies as $index => $cookie ) {
				if ( ! is_array( $cookie ) || ! self::is_declared( $cookie ) ) {
					continue;
				}

				$owned = (string) ( $cookie['service_slug'] ?? '' ) === $slug
					|| strncmp( (string) ( $cookie['signature_id'] ?? '' ), $prefix, strlen( $prefix ) ) === 0;

				if ( $owned ) {
					unset( $stored[ $category ][ $index ] );
					++$removed;
				}
			}

			$stored[ $category ] = array_values( $stored[ $category ] );
		}

		if ( $removed > 0 ) {
			Update::option( SURECOOKIE_SCANNED_COOKIES_OPTION, $stored );
		}

		return $removed;
	}

	/**
	 * A service's display name, falling back to its slug.
	 *
	 * Public so a row stored before `service_label` existed can still be named on
	 * the admin read path.
	 *
	 * @since 1.5.1
	 * @param string $service Catalog service slug.
	 * @return string
	 */
	public function label_for( string $service ): string {
		return self::service_label( $service );
	}

	/**
	 * Fill in a provider for stored scanned cookies that have none.
	 *
	 * Earlier scans read the provider from a key the scan API never sends, so
	 * stored rows kept it empty. A re-scan fixes it, but until then match the
	 * bundled catalog by name+domain. Only fills blanks - an existing provider is
	 * never overwritten, so this is safe to run after a scan resolved the vendor.
	 *
	 * @since 1.3.0
	 * @throws \RuntimeException When the repaired cookies cannot be written.
	 * @return int Number of cookies given a provider.
	 */
	public function backfill_missing_providers(): int {
		$stored = get_option( SURECOOKIE_SCANNED_COOKIES_OPTION, [] );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return 0;
		}

		$filled = 0;

		foreach ( $stored as $category => $cookies ) {
			if ( ! is_array( $cookies ) ) {
				continue;
			}

			foreach ( $cookies as $index => $cookie ) {
				// A placeholder ("null") counts as blank, so a row an older ingest stored that way is repaired too.
				if ( ! is_array( $cookie ) || Sanitize::vendor( $cookie['provider'] ?? '' ) !== '' ) {
					continue;
				}

				$provider = $this->catalog_provider_for( $cookie );
				if ( $provider === (string) ( $cookie['provider'] ?? '' ) ) {
					continue;
				}

				$stored[ $category ][ $index ]['provider'] = $provider;
				$filled++;
			}
		}

		if ( $filled > 0 && ! Update::option( SURECOOKIE_SCANNED_COOKIES_OPTION, $stored ) ) {
			throw new \RuntimeException( 'SureCookie: could not write the repaired cookie providers.' );
		}

		return $filled;
	}

	/**
	 * Resolve the catalog's provider for one cookie, or '' when unknown.
	 *
	 * Shared by scan ingest and the one-time backfill so both agree with the
	 * catalog and a re-scan can never downgrade a row the backfill already resolved.
	 *
	 * @param array<string, mixed> $cookie Cookie with at least a name, ideally a domain.
	 * @since 1.3.0
	 * @return string
	 */
	public function catalog_provider_for( array $cookie ): string {
		return (string) ( $this->catalog_details_for( $cookie )['provider'] ?? '' );
	}

	/**
	 * Everything the catalog knows about one cookie, or [] when unknown.
	 *
	 * A pattern row is the only curated text a dynamic name such as
	 * `_ga_<container-id>` will ever have, so the match has to carry more than
	 * the provider. #1130 still bars the pattern itself from becoming a row.
	 *
	 * @param array<string, mixed> $cookie Cookie with at least a name, ideally a domain.
	 * @since 1.5.1
	 * @return array<string, string>
	 */
	public function catalog_details_for( array $cookie ): array {
		$name = Cookie_Identity::normalize_name( (string) ( $cookie['name'] ?? '' ) );
		if ( $name === '' ) {
			return [];
		}

		[ $exact, $prefixes ] = $this->catalog_provider_index();

		// Exact name+domain first, then name-only (a first-party entry's domain is
		// inferred from this host, so it need not match the tag's), then prefixes.
		$details = $exact[ Cookie_Identity::key_for( $cookie ) ] ?? $exact[ $name ] ?? [];
		if ( $details !== [] ) {
			return $details;
		}

		foreach ( $prefixes as $prefix => $prefix_details ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return $prefix_details;
			}
		}

		return [];
	}

	/**
	 * Build declared cookies (grouped by category) for the services detected in a
	 * set of scan-result pages.
	 *
	 * @param array<int, array<string, mixed>> $pages Scan result pages.
	 * @since 1.2.5
	 * @return array<string, array<int, array<string, mixed>>> Declared cookies grouped by category id.
	 */
	public function build_from_pages( array $pages ): array {
		$catalog = $this->get_catalog();
		if ( empty( $catalog ) ) {
			return [];
		}

		$matcher = Service_Matcher::get_instance();
		$urls    = $matcher->collect_resource_urls( $pages );
		if ( empty( $urls ) ) {
			return [];
		}

		$matched_services = $matcher->match_services( array_keys( $catalog ), $urls );

		return $this->build_for_services( $matched_services );
	}

	/**
	 * Declared cookies (grouped by category) for a set of catalog services.
	 *
	 * Split out from build_from_pages() because a service can be known to be
	 * present without a scan having seen it: the blocker matches it at render
	 * time, and by then the resource has been replaced with a placeholder that
	 * carries no URL for any scan to collect.
	 *
	 * @param array<int, string> $services Catalog service slugs.
	 * @since 1.5.0
	 * @return array<string, array<int, array<string, mixed>>> Declared cookies grouped by category id.
	 */
	public function build_for_services( array $services ): array {
		$catalog = $this->get_catalog();
		if ( empty( $catalog ) || empty( $services ) ) {
			return [];
		}

		$installed           = Installed_Services::get_instance();
		$valid_categories    = Get::default_cookie_categories_keys();
		$cookies_by_category = [];

		foreach ( $services as $service ) {
			$service = (string) $service;

			// Suppression sticks: never re-declare a service the admin removed
			// from the Known Services library until they add it back.
			if ( ! isset( $catalog[ $service ] ) || $installed->is_suppressed( $service ) ) {
				continue;
			}

			foreach ( $catalog[ $service ] as $definition ) {
				if ( ! is_array( $definition ) || empty( $definition['name'] )
					|| Cookie_Identity::is_pattern( (string) $definition['name'] ) ) {
					continue;
				}

				$cookie   = $this->transform( $service, $definition, $valid_categories );
				$category = $cookie['category'];

				$cookies_by_category[ $category ][] = $cookie;
			}
		}

		return $cookies_by_category;
	}

	/**
	 * A service's display name, falling back to its slug.
	 *
	 * @since 1.5.1
	 * @param string $service Catalog service slug.
	 * @return string
	 */
	private static function service_label( string $service ): string {
		$entry = Services_Source::get_instance()->get_catalog()[ $service ] ?? null;
		$label = is_array( $entry ) ? (string) ( $entry['label'] ?? '' ) : '';

		return $label !== '' ? $label : $service;
	}

	/**
	 * Whether a stored row came from the catalog rather than an observation.
	 *
	 * Accepts the signature prefix as well as `source`, so rows written before
	 * the marker existed are still recognised. Mirrors `Sync::is_declared_row()`.
	 *
	 * @since 1.5.1
	 * @param array<string, mixed> $cookie Stored cookie row.
	 * @return bool
	 */
	private static function is_declared( array $cookie ): bool {
		return ( $cookie['source'] ?? '' ) === 'declared'
			|| strncmp( (string) ( $cookie['signature_id'] ?? '' ), 'declared:', 9 ) === 0;
	}

	/**
	 * Build (and memoize) the catalog detail lookup, returning [ exact, prefixes ].
	 *
	 * `exact` is keyed by name+domain, plus a name-only key for first-party
	 * entries: their domain is this site's host and the tag may use a different
	 * label of it (Analytics scopes `_ga` to the registrable domain, so
	 * `shop.example.com` observes `.example.com`). The name-only key is unambiguous
	 * - no catalog cookie name is claimed by more than one service.
	 *
	 * `prefixes` handles pattern rows (`_ga_<container-id>`, `_hjSessionUser_*`,
	 * `_dc_gtm_UA-*`) whose literal names never equal a real name: the wildcard
	 * marker is stripped and the prefix matched with str_starts_with. Prefixes
	 * below the minimum length are dropped so a stray pattern swallows nothing.
	 *
	 * @since 1.3.0
	 * @return array{0: array<string, array<string, string>>, 1: array<string, array<string, string>>}
	 */
	private function catalog_provider_index(): array {
		if ( $this->provider_index !== null ) {
			return $this->provider_index;
		}

		$exact    = [];
		$prefixes = [];

		foreach ( $this->get_catalog() as $cookies ) {
			if ( ! is_array( $cookies ) ) {
				continue;
			}

			foreach ( $cookies as $cookie ) {
				if ( ! is_array( $cookie ) || empty( $cookie['name'] ) || empty( $cookie['provider'] ) ) {
					continue;
				}

				// The whole curated set, not just the provider: a pattern row is the
				// only text a `_ga_<container-id>` style name ever gets (#1202). Key
				// names and the day-count mapping mirror `transform()`.
				$details = [
					'provider'    => (string) $cookie['provider'],
					'description' => (string) ( $cookie['description'] ?? '' ),
					'purpose'     => (string) ( $cookie['purpose'] ?? '' ),
					'duration'    => ! empty( $cookie['duration_days'] ) ? (string) absint( $cookie['duration_days'] ) : '',
				];
				$name    = Cookie_Identity::normalize_name( (string) $cookie['name'] );

				$wildcard_at = strcspn( $name, '*<' );

				if ( $wildcard_at < strlen( $name ) ) {
					$prefix = substr( $name, 0, $wildcard_at );

					if ( strlen( $prefix ) >= self::MIN_WILDCARD_PREFIX ) {
						$prefixes[ $prefix ] = $details;
					}

					continue;
				}

				$exact[ Cookie_Identity::key_for( $cookie ) ] = $details;

				if ( Cookie_Identity::is_first_party( $cookie ) ) {
					$exact[ $name ] = $details;
				}
			}
		}

		// Longest prefix first so the most specific pattern wins.
		uksort( $prefixes, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		$this->provider_index = [ $exact, $prefixes ];

		return $this->provider_index;
	}

	/**
	 * Build the signature and name+domain lookup sets from the current catalog.
	 *
	 * @since 1.2.5
	 * @return array{0: array<string, true>, 1: array<string, true>}
	 */
	private function catalog_lookup(): array {
		$valid_signatures  = [];
		$valid_name_domain = [];

		foreach ( $this->get_catalog() as $slug => $cookies ) {
			if ( ! is_array( $cookies ) ) {
				continue;
			}

			foreach ( $cookies as $cookie ) {
				if ( ! is_array( $cookie ) || empty( $cookie['name'] )
					|| Cookie_Identity::is_pattern( (string) $cookie['name'] ) ) {
					continue;
				}

				$name = (string) $cookie['name'];

				$valid_signatures[ 'declared:' . $slug . ':' . $name ]    = true;
				$valid_name_domain[ Cookie_Identity::key_for( $cookie ) ] = true;
			}
		}

		return [ $valid_signatures, $valid_name_domain ];
	}

	/**
	 * Transform a catalog definition into the stored scanned-cookie shape used by
	 * Sync::store_all_cookies_from_agent_app().
	 *
	 * @param string               $service          Service slug.
	 * @param array<string, mixed> $definition       Catalog cookie definition.
	 * @param array<int, string>   $valid_categories Allowed category ids.
	 * @since 1.2.5
	 * @return array<string, mixed>
	 */
	private function transform( string $service, array $definition, array $valid_categories ): array {
		$name     = (string) $definition['name'];
		$category = (string) ( $definition['category'] ?? 'uncategorized' );

		if ( ! in_array( $category, $valid_categories, true ) ) {
			$category = 'uncategorized';
		}

		$duration_days = isset( $definition['duration_days'] ) ? absint( $definition['duration_days'] ) : 0;
		$expires       = $duration_days > 0
			? gmdate( 'Y-m-d H:i:s', (int) strtotime( "+{$duration_days} days" ) )
			: null;

		$cookie = [
			'name'          => $name,
			'value'         => '',
			'domain'        => (string) ( $definition['domain'] ?? '' ),
			'path'          => '/',
			'expires'       => $expires,
			'httpOnly'      => false,
			'secure'        => true,
			// Third-party embed cookies are sent cross-site.
			'sameSite'      => 'none',
			'category'      => $category,
			// The catalog knows the lifetime in days.
			'duration'      => $duration_days > 0 ? (string) $duration_days : '',
			'provider'      => (string) ( $definition['provider'] ?? '' ),
			'description'   => (string) ( $definition['description'] ?? '' ),
			'purpose'       => (string) ( $definition['purpose'] ?? '' ),
			// Deterministic id so re-scans replace (never duplicate) the declared row.
			'signature_id'  => 'declared:' . $service . ':' . $name,
			// Marks the cookie as declared-from-catalog rather than runtime-observed.
			'source'        => 'declared',
			// Named so removal and the admin row resolve the owner from a field
			// rather than parsing signature_id, which is an id and not a contract.
			'service_slug'  => $service,
			// The service's own name, never `provider`: YouTube's PREF row is
			// provided by "Google", and removal is about the service, not the vendor.
			'service_label' => self::service_label( $service ),
		];

		// Carry the marker so the scan-time merge knows this domain was inferred
		// and can match by name; conditional so third-party rows keep their shape.
		if ( Cookie_Identity::is_first_party( $definition ) ) {
			$cookie[ Cookie_Identity::FIRST_PARTY_FLAG ] = true;
		}

		return $cookie;
	}
}
