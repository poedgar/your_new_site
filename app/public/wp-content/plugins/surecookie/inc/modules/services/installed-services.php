<?php
/**
 * Installed Services registry.
 *
 * Tracks which known services the admin explicitly ADDED (declared cookies + a
 * policy entry). Blocking is independent - the catalog blocks every service
 * regardless - so "installed" is purely the manage/declared state the Known
 * Services library surfaces.
 *
 * Stored in the `surecookie_installed_services` option as:
 *   [ 'installed' => [ slug => {slug,label,category,added_at,cookie_ids[],version} ],
 *     'suppressed' => [ slug, ... ] ]
 * `suppressed` records removed services, so scan-time auto-seeding does not
 * re-declare them until they are re-added.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.3.0
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\Functions\Cookie_Identity;
use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Services\CookieService;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Installed_Services
 *
 * @since 1.3.0
 */
class Installed_Services {
	use GetInstance;

	/**
	 * Constructor: wire the read-time Detected Resources overlay.
	 *
	 * @since 1.3.0
	 */
	private function __construct() {
		add_filter( 'surecookie_scanned_resources', [ $this, 'annotate_scanned_resources' ] );
	}

	/**
	 * Normalised registry: { installed: slug => entry, suppressed: slug[] }.
	 *
	 * @since 1.3.0
	 * @return array{installed: array<string, array<string, mixed>>, suppressed: array<int, string>}
	 */
	public function get_data(): array {
		$raw = get_option( SURECOOKIE_INSTALLED_SERVICES_OPTION, [] );
		$raw = is_array( $raw ) ? $raw : [];

		return [
			'installed'  => isset( $raw['installed'] ) && is_array( $raw['installed'] ) ? $raw['installed'] : [],
			'suppressed' => isset( $raw['suppressed'] ) && is_array( $raw['suppressed'] ) ? array_values( $raw['suppressed'] ) : [],
		];
	}

	/**
	 * Sanitize an untrusted registry shape (settings import boundary): every
	 * entry field validated by type, unknown fields dropped, malformed
	 * entries removed. Mirrors the stored shape documented on this class.
	 *
	 * @param mixed $raw Untrusted registry value.
	 * @since 1.4.0
	 * @return array{installed: array<string, array<string, mixed>>, suppressed: array<int, string>}
	 */
	public static function sanitize_registry( $raw ): array {
		$raw       = is_array( $raw ) ? $raw : [];
		$installed = [];

		$entries = isset( $raw['installed'] ) && is_array( $raw['installed'] ) ? $raw['installed'] : [];
		foreach ( $entries as $slug => $entry ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug === '' || ! is_array( $entry ) ) {
				continue;
			}

			$cookie_ids = isset( $entry['cookie_ids'] ) && is_array( $entry['cookie_ids'] ) ? $entry['cookie_ids'] : [];
			$cookie_ids = array_values(
				array_filter(
					array_map(
						static function ( $id ): string {
							return is_string( $id ) ? sanitize_text_field( $id ) : '';
						},
						$cookie_ids
					)
				)
			);

			$installed[ $slug ] = [
				'slug'       => $slug,
				'label'      => isset( $entry['label'] ) && is_string( $entry['label'] ) ? sanitize_text_field( $entry['label'] ) : $slug,
				'category'   => isset( $entry['category'] ) && is_string( $entry['category'] ) ? sanitize_key( $entry['category'] ) : '',
				'added_at'   => isset( $entry['added_at'] ) ? absint( $entry['added_at'] ) : 0,
				'version'    => isset( $entry['version'] ) && is_string( $entry['version'] ) ? sanitize_text_field( $entry['version'] ) : '',
				'cookie_ids' => $cookie_ids,
			];
		}

		$suppressed = isset( $raw['suppressed'] ) && is_array( $raw['suppressed'] ) ? $raw['suppressed'] : [];
		$suppressed = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $slug ): string {
							return is_string( $slug ) ? sanitize_key( $slug ) : '';
						},
						$suppressed
					)
				)
			)
		);

		return [
			'installed'  => $installed,
			'suppressed' => $suppressed,
		];
	}

	/**
	 * The installed-service entries, keyed by slug.
	 *
	 * @since 1.3.0
	 * @return array<string, array<string, mixed>>
	 */
	public function get_installed(): array {
		return $this->get_data()['installed'];
	}

	/**
	 * Whether a service is currently installed (manually added).
	 *
	 * @param string $slug Catalog service slug.
	 * @since 1.3.0
	 * @return bool
	 */
	public function is_installed( string $slug ): bool {
		return isset( $this->get_data()['installed'][ $slug ] );
	}

	/**
	 * Whether a service was removed and should not be auto-re-declared by scans.
	 *
	 * @param string $slug Catalog service slug.
	 * @since 1.3.0
	 * @return bool
	 */
	public function is_suppressed( string $slug ): bool {
		return in_array( $slug, $this->get_data()['suppressed'], true );
	}

	/**
	 * One-time backfill: mark a catalog service installed when EVERY one of its
	 * declared cookies is already present (custom + scan-declared), so services a
	 * user declared by hand before this feature show as installed.
	 *
	 * Conservative - a service with no declared cookies, already installed, or
	 * suppressed is skipped. install() applies the same presence test, so a
	 * fully-present service records its entry without minting a duplicate cookie.
	 *
	 * @since 1.3.0
	 * @return array<int, string> Slugs that were backfilled.
	 */
	public function backfill_from_existing_cookies(): array {
		$catalog       = Services_Source::get_instance()->get_catalog();
		$existing_keys = $this->existing_cookie_keys();
		$backfilled    = [];

		foreach ( $catalog as $slug => $service ) {
			if ( ! is_string( $slug ) || ! is_array( $service ) ) {
				continue;
			}
			if ( $this->is_installed( $slug ) || $this->is_suppressed( $slug ) ) {
				continue;
			}

			$cookies = array_values( (array) ( $service['cookies'] ?? [] ) );
			if ( $cookies === [] ) {
				continue; // No declared cookies - nothing to match against.
			}

			$all_present = true;
			foreach ( $cookies as $cookie ) {
				if ( ! is_array( $cookie ) || (string) ( $cookie['name'] ?? '' ) === '' ) {
					$all_present = false;
					break;
				}
				if ( ! $this->cookie_present( $existing_keys, $cookie ) ) {
					$all_present = false;
					break;
				}
			}

			if ( $all_present ) {
				$result = $this->install( $slug );
				if ( ! empty( $result['success'] ) ) {
					$backfilled[] = $slug;
				}
			}
		}

		return $backfilled;
	}

	/**
	 * Add a known service: declare its cookies (deduped against cookies already
	 * present, see {@see self::cookie_present()}) and record the registry entry.
	 * The Pro gate is enforced by the REST layer, which knows the site's Pro status.
	 *
	 * @param string $slug Catalog service slug.
	 * @since 1.3.0
	 * @return array<string, mixed> { success, added[], skipped[], cookie_count } or { success:false, code }
	 */
	public function install( string $slug ): array {
		$catalog = Services_Source::get_instance()->get_catalog();
		if ( ! isset( $catalog[ $slug ] ) || ! is_array( $catalog[ $slug ] ) ) {
			return [
				'success' => false,
				'code'    => 'unknown_service',
			];
		}

		$service = $catalog[ $slug ];
		$data    = $this->get_data();
		$entry   = $data['installed'][ $slug ] ?? [
			'slug'       => $slug,
			'label'      => (string) ( $service['label'] ?? $slug ),
			'category'   => (string) ( $service['category'] ?? 'uncategorized' ),
			'added_at'   => time(),
			'cookie_ids' => [],
			'version'    => 1,
		];

		$existing_keys = $this->existing_cookie_keys();
		$service_obj   = new CookieService();
		$added         = [];
		$skipped       = [];

		foreach ( (array) ( $service['cookies'] ?? [] ) as $cookie ) {
			if ( ! is_array( $cookie ) ) {
				continue;
			}

			$name = (string) ( $cookie['name'] ?? '' );
			// A catalog pattern is a matcher, not a cookie: minting one publishes a
			// cookie the site does not set, on a legal disclosure page. Not recorded
			// as skipped - `skipped` means "already on this site", and a pattern was
			// never a candidate.
			if ( $name === '' || Cookie_Identity::is_pattern( $name ) ) {
				continue;
			}

			$domain = (string) ( $cookie['domain'] ?? '' );
			// Dedup across everything already declared (custom + scan-declared) so
			// a service cookie never doubles a visitor's list.
			if ( $this->cookie_present( $existing_keys, $cookie ) ) {
				$skipped[] = $name;
				continue;
			}

			$result = $service_obj->create_custom_cookie(
				[
					'name'         => $name,
					'domain'       => $domain,
					'category'     => $this->resolve_category( (string) ( $cookie['category'] ?? 'uncategorized' ) ),
					'duration'     => (string) ( $cookie['duration_days'] ?? 0 ),
					'provider'     => (string) ( $cookie['provider'] ?? '' ),
					'purpose'      => (string) ( $cookie['purpose'] ?? '' ),
					'description'  => (string) ( $cookie['description'] ?? '' ),
					'type'         => 'custom',
					'service_slug' => $slug,
				]
			);

			if ( ! empty( $result['success'] ) && isset( $result['cookie']['id'] ) ) {
				$added[]               = $name;
				$entry['cookie_ids'][] = $result['cookie']['id'];
				$this->index_cookie( $existing_keys, $name, $domain );
			} else {
				$skipped[] = $name;
			}
		}

		$entry['cookie_ids']        = array_values( array_unique( $entry['cookie_ids'] ) );
		$data['installed'][ $slug ] = $entry;
		$data['suppressed']         = array_values( array_diff( $data['suppressed'], [ $slug ] ) );
		$this->save( $data );

		return [
			'success'      => true,
			'installed'    => true,
			'added'        => $added,
			'skipped'      => $skipped,
			'cookie_count' => count( $entry['cookie_ids'] ),
		];
	}

	/**
	 * Delete pattern rows a previous install() published as cookies.
	 *
	 * Nothing else converges them: the rows are `custom`, so no scan reconciles
	 * them away, and they stay on the public cookie policy indefinitely. Scoped to
	 * rows a service owns (`service_slug`), so a cookie an admin added by hand is
	 * never touched even if its name happens to hold a wildcard.
	 *
	 * @since 1.5.0
	 * @return int Rows removed.
	 */
	public function prune_pattern_cookies(): int {
		$custom  = (array) Settings::get( 'custom_cookies' );
		$removed = 0;

		foreach ( $custom as $id => $cookie ) {
			if ( ! is_array( $cookie ) ) {
				continue;
			}

			if ( (string) ( $cookie['service_slug'] ?? '' ) === '' ) {
				continue;
			}

			if ( ! Cookie_Identity::is_pattern( (string) ( $cookie['name'] ?? '' ) ) ) {
				continue;
			}

			unset( $custom[ $id ] );
			++$removed;
		}

		if ( $removed > 0 ) {
			Settings::update( 'custom_cookies', $custom );
		}

		return $removed;
	}

	/**
	 * Remove a known service: delete only the cookies it added (still carrying its
	 * service_slug and not declared by another installed service), drop the
	 * registry entry, and suppress future scan-time re-seeding until re-added.
	 *
	 * @param string $slug Catalog service slug.
	 * @since 1.3.0
	 * @return array<string, mixed> { success, removed }
	 */
	public function uninstall( string $slug ): array {
		$data = $this->get_data();

		// Names still owned by OTHER installed services must never be deleted.
		$protected = $this->names_owned_by_other_services( $slug, $data['installed'] );

		// Names this service declares in the catalog. install() dedups a shared
		// cookie by name+domain and never re-tags it, so a cookie two services
		// declare stays tagged with whichever installed FIRST; the last one to
		// uninstall must still remove it or it orphans (KS-018). So match managed
		// cookies by declared-name too, not only by service_slug.
		$declared = [];
		foreach ( (array) ( Services_Source::get_instance()->get_catalog()[ $slug ]['cookies'] ?? [] ) as $cookie ) {
			if ( is_array( $cookie ) && ! empty( $cookie['name'] ) ) {
				$declared[ strtolower( (string) $cookie['name'] ) ] = true;
			}
		}

		$custom  = (array) Settings::get( 'custom_cookies' );
		$removed = 0;
		foreach ( $custom as $id => $cookie ) {
			if ( ! is_array( $cookie ) ) {
				continue;
			}
			$name        = strtolower( (string) ( $cookie['name'] ?? '' ) );
			$tagged_slug = (string) ( $cookie['service_slug'] ?? '' );
			// Owned by this service: tagged with its slug, or a managed cookie
			// (tagged by some service) whose name this service declares. A
			// hand-added cookie (no service_slug) sharing a name is never touched.
			$owned = $tagged_slug === $slug || ( $tagged_slug !== '' && isset( $declared[ $name ] ) );
			if ( ! $owned ) {
				continue;
			}
			if ( isset( $protected[ $name ] ) ) {
				continue;
			}
			unset( $custom[ $id ] );
			++$removed;
		}
		Settings::update( 'custom_cookies', $custom );

		// Suppression only stops the next declaration; rows a scan already stored
		// are in the option and would stay on the policy (issue #1141).
		$removed += Declared_Cookies::get_instance()->forget_service( $slug );

		unset( $data['installed'][ $slug ] );
		if ( ! in_array( $slug, $data['suppressed'], true ) ) {
			$data['suppressed'][] = $slug;
		}
		$this->save( $data );

		return [
			'success' => true,
			'removed' => $removed,
		];
	}

	/**
	 * Read-time overlay: inject an installed service's script/iframe patterns as
	 * non-interactive info rows, and tag scanner-detected rows with the matching
	 * catalog slug (for the "Add as Service" CTA). Never persisted - runs on the
	 * `surecookie_scanned_resources` filter, so it survives rescans by construction.
	 *
	 * @param array<string, mixed> $resources { scripts:[], iframes:[], metadata:{} }.
	 * @since 1.3.0
	 * @return array<string, mixed>
	 */
	public function annotate_scanned_resources( $resources = [] ) {
		if ( ! is_array( $resources ) ) {
			return $resources;
		}

		$matcher   = Service_Matcher::get_instance();
		$installed = $this->get_installed();

		// Tag existing scanner rows with their catalog slug + installed state.
		foreach ( [ 'scripts', 'iframes' ] as $kind ) {
			if ( empty( $resources[ $kind ] ) || ! is_array( $resources[ $kind ] ) ) {
				continue;
			}
			foreach ( $resources[ $kind ] as $i => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$match = $matcher->match_url( (string) ( $row['url'] ?? $row['domain'] ?? '' ) );
				if ( $match !== '' ) {
					$resources[ $kind ][ $i ]['service_slug']      = $match;
					$resources[ $kind ][ $i ]['service_installed'] = isset( $installed[ $match ] );
				}
			}
		}

		// Inject one info row per installed service pattern not already listed,
		// using the catalog's authoritative kind (a script host like
		// `cdnjs.cloudflare.com` must not be guessed into the iframe bucket).
		// Dedup is per (kind, host): a service can declare both a script and an
		// iframe on the SAME host (e.g. Google Sign-In under accounts.google.com),
		// so a host seen as a script must not suppress the iframe row.
		$existing_hosts   = $this->hosts_index( $resources );
		$patterns_by_kind = $matcher->get_service_patterns_by_kind( array_keys( $installed ) );
		foreach ( $installed as $slug => $entry ) {
			$service_patterns = $patterns_by_kind[ $slug ] ?? [];
			foreach ( Pattern_Kinds::buckets() as $bucket ) {
				// A stylesheet or media pattern still deserves a row - the admin
				// needs to see it, and the row reports that it is not blocked.
				// Scanners file those under scripts, so use the same bucket.
				$kind = Pattern_Kinds::resource_kind( $bucket ) === 'iframe' ? 'iframes' : 'scripts';
				// Grouped by host first, because one row stands for the whole
				// service on that host. Emitting per pattern and deduping by host
				// kept only the first, so an always-allow rule written from the row
				// covered `instagram.com/p/` and left `/reel/` and `/embed` blocked.
				$by_host = [];
				foreach ( (array) ( $service_patterns[ $bucket ] ?? [] ) as $pattern ) {
					$host = $this->pattern_host( (string) $pattern );
					if ( $host === '' ) {
						continue;
					}

					$by_host[ $host ][] = (string) $pattern;
				}

				foreach ( $by_host as $host => $patterns ) {
					if ( isset( $existing_hosts[ $kind ][ $host ] ) ) {
						continue;
					}

					$patterns                         = array_values( array_unique( $patterns ) );
					$resources[ $kind ][]             = [
						'domain'            => $host,
						'url'               => $patterns[0],
						'patterns'          => $patterns,
						'vendor'            => (string) ( $entry['label'] ?? $slug ),
						'category'          => (string) ( $entry['category'] ?? 'uncategorized' ),
						'source'            => 'service',
						'service_slug'      => $slug,
						'service_installed' => true,
					];
					$existing_hosts[ $kind ][ $host ] = true;
				}
			}
		}

		return $resources;
	}

	/**
	 * Map a catalog cookie category onto the site's categories, falling back to
	 * `uncategorized` when it is absent (renamed/removed). PHP mirror of the JS
	 * resolveCategoryId.
	 *
	 * @param string $preset Catalog cookie category key.
	 * @since 1.3.0
	 * @return string A category id present on the site, or 'uncategorized'.
	 */
	private function resolve_category( string $preset ): string {
		$ids        = [];
		$categories = (array) Settings::get( 'cookie_categories' );
		foreach ( $categories as $category ) {
			if ( is_array( $category ) && isset( $category['id'] ) ) {
				$ids[] = (string) $category['id'];
			}
		}
		if ( $ids === [] ) {
			$ids = Get::default_cookie_categories_keys();
		}

		return in_array( $preset, $ids, true ) ? $preset : 'uncategorized';
	}

	/**
	 * Index every cookie already declared (custom + scan-declared) for dedup.
	 *
	 * @since 1.3.0
	 * @return array<string, true>
	 */
	private function existing_cookie_keys(): array {
		$keys = [];

		foreach ( (array) Settings::get( 'custom_cookies' ) as $cookie ) {
			if ( is_array( $cookie ) && ! empty( $cookie['name'] ) ) {
				$this->index_cookie( $keys, (string) $cookie['name'], (string) ( $cookie['domain'] ?? '' ) );
			}
		}

		foreach ( (array) get_option( SURECOOKIE_SCANNED_COOKIES_OPTION, [] ) as $cookies ) {
			foreach ( (array) $cookies as $cookie ) {
				if ( is_array( $cookie ) && ! empty( $cookie['name'] ) ) {
					$this->index_cookie( $keys, (string) $cookie['name'], (string) ( $cookie['domain'] ?? '' ) );
				}
			}
		}

		return $keys;
	}

	/**
	 * Record one stored cookie under both its name+domain and name-only keys. Only
	 * a first-party catalog row ever LOOKS UP the name-only key, so third-party
	 * cookies still need a matching domain to count as present.
	 *
	 * @param array<string, true> $keys   Index being built, by reference.
	 * @param string              $name   Cookie name.
	 * @param string              $domain Cookie domain.
	 * @since 1.3.0
	 * @return void
	 */
	private function index_cookie( array &$keys, string $name, string $domain ): void {
		$keys[ Cookie_Identity::key( $name, $domain ) ] = true;
		$keys[ Cookie_Identity::name_key( $name ) ]     = true;
	}

	/**
	 * Whether a catalog cookie is already present on the site.
	 *
	 * A first-party row's domain is this site's host (substituted for the
	 * placeholder), still not necessarily where the tag wrote the cookie -
	 * Analytics and friends scope to the registrable domain, so `shop.example.com`
	 * observes `.example.com`. A site has one `_ga`, so name alone is the identity
	 * for those rows. Third-party rows keep the strict name+domain test: `PREF` on
	 * `.google.com` is not YouTube's `PREF`.
	 *
	 * @param array<string, true>  $keys   Index from {@see self::existing_cookie_keys()}.
	 * @param array<string, mixed> $cookie Catalog cookie row.
	 * @since 1.3.0
	 * @return bool
	 */
	private function cookie_present( array $keys, array $cookie ): bool {
		$name = (string) ( $cookie['name'] ?? '' );

		if ( isset( $keys[ Cookie_Identity::key_for( $cookie ) ] ) ) {
			return true;
		}

		return Cookie_Identity::is_first_party( $cookie )
			&& isset( $keys[ Cookie_Identity::name_key( $name ) ] );
	}

	/**
	 * Lower-cased cookie names declared by installed services OTHER than $slug.
	 *
	 * @param string                              $slug      Service being uninstalled (excluded).
	 * @param array<string, array<string, mixed>> $installed Installed registry entries.
	 * @since 1.3.0
	 * @return array<string, true>
	 */
	private function names_owned_by_other_services( string $slug, array $installed ): array {
		$catalog = Services_Source::get_instance()->get_catalog();
		$names   = [];

		foreach ( $installed as $other => $entry ) {
			if ( $other === $slug || ! isset( $catalog[ $other ]['cookies'] ) ) {
				continue;
			}
			foreach ( (array) $catalog[ $other ]['cookies'] as $cookie ) {
				if ( is_array( $cookie ) && ! empty( $cookie['name'] ) ) {
					$names[ strtolower( (string) $cookie['name'] ) ] = true;
				}
			}
		}

		return $names;
	}

	/**
	 * Derive a display host from a path-y catalog pattern ("youtube.com/embed/"
	 * -> "youtube.com").
	 *
	 * @param string $pattern Catalog blocking pattern.
	 * @since 1.3.0
	 * @return string
	 */
	private function pattern_host( string $pattern ): string {
		$pattern = strtolower( trim( $pattern ) );

		// Strip a leading scheme ("https://host/..." -> "host/...") without regex.
		$scheme = strpos( $pattern, '://' );
		if ( $scheme !== false && $scheme <= 6 ) {
			$pattern = substr( $pattern, $scheme + 3 );
		}

		$pattern = ltrim( $pattern, '.*' );
		$slash   = strpos( $pattern, '/' );

		return $slash === false ? $pattern : substr( $pattern, 0, $slash );
	}

	/**
	 * Index of hosts already present in the resource list, keyed by kind, so the
	 * injection dedup can treat a script and an iframe on the same host as
	 * distinct rows.
	 *
	 * @param array<string, mixed> $resources Resource payload.
	 * @since 1.3.0
	 * @return array<string, array<string, true>> [ scripts => [host=>true], iframes => [host=>true] ]
	 */
	private function hosts_index( array $resources ): array {
		$hosts = [
			'scripts' => [],
			'iframes' => [],
		];
		foreach ( [ 'scripts', 'iframes' ] as $kind ) {
			foreach ( (array) ( $resources[ $kind ] ?? [] ) as $row ) {
				if ( is_array( $row ) && ! empty( $row['domain'] ) ) {
					$hosts[ $kind ][ strtolower( (string) $row['domain'] ) ] = true;
				}
			}
		}

		return $hosts;
	}

	/**
	 * @param array{installed: array<string, mixed>, suppressed: array<int, string>} $data Registry.
	 * @since 1.3.0
	 */
	private function save( array $data ): void {
		update_option( SURECOOKIE_INSTALLED_SERVICES_OPTION, $data, false );
	}
}
