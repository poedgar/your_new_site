<?php
/**
 * Admin Sync
 *
 * Handles processing and storage of cookie scan results from SaaS API.
 *
 * @since 0.0.1
 * @package SureCookie
 */

namespace SureCookie\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureCookie\Inc\Functions\Cookie_Identity;
use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Modules\AssistedScan\Normalizer;
use SureCookie\Inc\Modules\Services\Declared_Cookies;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;
use SureCookie\Inc\Services\CookieCategoryMemory;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\Logger;

/**
 * Admin Sync
 *
 * @since 0.0.1
 */
class Sync {
	use GetInstance;

	/**
	 * Category mapping from SaaS tracking categories to plugin categories.
	 *
	 * @since 0.0.0-alpha.2
	 */
	private const CATEGORY_MAP = [
		'analytics'    => 'analytics',
		'advertising'  => 'marketing',
		'social'       => 'marketing',
		'social_media' => 'marketing',
		'video'        => 'marketing',
		'functional'   => 'functional',
		'payment'      => 'functional',
		'consent'      => 'essential',
		// Security and necessary are strictly necessary (captchas, CSRF tokens, bot protection).
		// Without these the fallback made them `marketing`, so declining marketing broke logins and forms.
		'security'     => 'essential',
		'necessary'    => 'essential',
		// `preferences` stays marketing by explicit choice, not by falling through.
		'preferences'  => 'marketing',
		// Only categories the plugin defines.
		'essential'    => 'essential',
		'marketing'    => 'marketing',
	];

	/**
	 * Maximum number of scan-detected resources to store.
	 *
	 * @since 0.0.0-alpha.2
	 */
	private const MAX_RESOURCES = 500;

	/**
	 * Constructor - Hook into SaaS API results.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function __construct() {
		add_action( 'surecookie_saas_scan_results_received', [ $this, 'process_saas_results' ], 10, 1 );
	}

	/**
	 * Process results from a scan, storing cookies grouped by category.
	 *
	 * Two optional keys let a non-cloud scanner reuse this pipeline:
	 *  - `source` names the scanner ('saas' default, 'assisted' for a browser walk).
	 *    Becomes the recorded `scan_type` and suppresses cloud outcome classification,
	 *    which keys off scanner reach - always zero for a browser walk, so it would
	 *    misreport a good assisted scan as `blocked_by_host`.
	 *  - `partial` marks one page of a multi-part walk. Cookies/resources are stored
	 *    immediately (nothing lost if abandoned), but once-per-scan work (declared-cookie
	 *    seeding, reconcile, first-scan flags, scan-completed diff) defers to the final
	 *    non-partial call - else a five-page walk fires five diffs and five digest emails.
	 *
	 * Untyped by design: this is an action boundary, so a third-party caller can
	 * emit it with anything. Nothing to return, so an unusable payload is ignored.
	 *
	 * @param mixed $data Expected array<string, mixed> of scan results.
	 * @since 0.0.1
	 * @since 1.3.0 Added the `source` and `partial` keys.
	 * @return void
	 */
	public function process_saas_results( $data = [] ): void {
		if ( ! is_array( $data ) ) {
			return;
		}

		$source     = isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : 'saas';
		$is_cloud   = $source === 'saas';
		$is_partial = ! empty( $data['partial'] );

		Logger::get_instance()->save_log( 'Processing scanned results...' );

		// Extract pages data from API response.
		$pages = $data['pages'] ?? [];

		if ( empty( $pages ) ) {
			Logger::get_instance()->save_log( 'No pages found in scan results.' );
			$this->store_all_cookies_from_agent_app( [], $source );
			if ( $is_cloud ) {
				// Classify so the UI can distinguish a host-blocked crawl from a
				// genuinely empty result instead of silently reporting 0 cookies.
				SaasClient::get_instance()->store_scan_outcome( 0 );
			}
			return;
		}

		// Group cookies by category and deduplicate.
		$cookies_by_category = $this->group_cookies_by_category( $pages );

		$cookies_count = $this->get_cookies_count( $cookies_by_category );

		// Record the honest outcome (ok / blocked_by_host / empty) for the UI.
		if ( $is_cloud ) {
			SaasClient::get_instance()->store_scan_outcome( $cookies_count );
		}
		Logger::get_instance()->save_log( '' ); // Blank line.
		Logger::get_instance()->save_log( sprintf( 'Found %d unique cookies in this scan.', $cookies_count ) );

		// Deferred on a partial page: seeding needs the whole walk's resources, and
		// running it per page would declare cookies a later page contradicts.
		if ( ! $is_partial ) {
			$declared_by_category = Declared_Cookies::get_instance()->build_from_pages( $pages );
			if ( ! empty( $declared_by_category ) ) {
				$cookies_by_category = $this->merge_declared_cookies( $cookies_by_category, $declared_by_category );
				Logger::get_instance()->save_log(
					sprintf( 'Declared %d cookie(s) for blocked third-party services.', $this->get_cookies_count( $declared_by_category ) )
				);
			}
		}

		// Store cookies (merges with existing).
		$this->store_all_cookies_from_agent_app( $cookies_by_category, $source, ! $is_partial );

		// Store scan-detected scripts and iframes. A non-cloud scan merges rather than
		// replaces: browser collection is a strict subset (an ad blocker suppresses
		// trackers outright), so replacing would drop previously-detected domains and
		// unblock trackers the site had covered.
		$this->process_scanned_resources( $pages, ! $is_cloud );

		// Everything below happens once per scan, not once per collected page.
		if ( $is_partial ) {
			return;
		}

		// Prune previously-declared cookies whose service/definition left the catalog,
		// so catalog removals propagate on a scan. Observed and custom cookies are kept.
		Declared_Cookies::get_instance()->reconcile_declared_cookies();

		// Analytics: flag first scan completed for state detection on next admin load.
		if ( ! get_option( 'surecookie_first_scan_completed_flag', false ) ) {
			update_option( 'surecookie_first_scan_completed_flag', true, false );
			update_option( 'surecookie_first_scan_pages_scanned', count( $pages ), false );
		}

		/**
		 * Fires after a scan's results have been processed and persisted.
		 *
		 * Automatic Scanning subscribes to diff this scan's reported set against the
		 * previous scan. Cookies merge "sticky" into the option (one absent from this
		 * scan is never auto-removed), so the diff must use this reported set, not the
		 * accumulated option. Not fired on a no-pages scan (avoids mistaking empty for
		 * "everything removed"); fired exactly once per scan (a multi-page walk defers
		 * to its final call), so subscribers see one diff and send one digest.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, array<int, array<string, mixed>>> $cookies_by_category This scan's reported cookies, grouped by category and deduped by signature_id.
		 * @param array<string, mixed>                            $context             Scan context: cookies_count, scan_type, scanned_at, pages_scanned, domains.
		 */
		do_action(
			'surecookie_scan_completed',
			$cookies_by_category,
			[
				'cookies_count' => $cookies_count,
				'scan_type'     => $source,
				'scanned_at'    => current_time( 'mysql' ),
				'pages_scanned' => count( $pages ),
				'domains'       => $this->extract_third_party_domains( $pages ),
			]
		);
	}

	/**
	 * Extract the unique third-party script/iframe domains reported in this scan.
	 *
	 * Used by the scan-completed diff to surface newly-introduced tracker domains.
	 *
	 * @param array<int, array<string, mixed>> $pages Scan result pages.
	 * @since 1.2.0
	 * @return array<int, string> Unique third-party domains.
	 */
	private function extract_third_party_domains( array $pages ): array {
		$domains = [];

		foreach ( $pages as $page ) {
			foreach ( $page['scripts'] ?? [] as $script ) {
				if ( ! empty( $script['is_third_party'] ) && ! empty( $script['domain'] ) ) {
					$domains[ sanitize_text_field( $script['domain'] ) ] = true;
				}
			}

			foreach ( $page['iframes'] ?? [] as $iframe ) {
				if ( ! empty( $iframe['is_third_party'] ) && ! empty( $iframe['domain'] ) ) {
					$domains[ sanitize_text_field( $iframe['domain'] ) ] = true;
				}
			}
		}

		return array_keys( $domains );
	}

	/**
	 * Process and store scan-detected scripts and iframes.
	 *
	 * Extracts third-party scripts/iframes from scan results, maps categories,
	 * deduplicates by domain, and stores for the blocking engine.
	 *
	 * @param array<int, array<string, mixed>> $pages Scan result pages.
	 * @param bool                             $merge Union into the stored snapshot instead of replacing it.
	 * @since 0.0.0-alpha.2
	 * @since 1.3.0 Added the `$merge` mode for scanners whose view is a strict subset.
	 * @return void
	 */
	private function process_scanned_resources( array $pages, bool $merge = false ): void {
		$scripts             = [];
		$iframes             = [];
		$seen_script_domains = [];
		$seen_iframe_domains = [];
		// Domains this call actually added, so merge mode can tell "found nothing
		// new" apart from "found nothing at all".
		$discovered = 0;

		// In merge mode, stored domains seed the "seen" sets so an existing entry is never
		// duplicated or overwritten by a thinner one (it may carry a vendor this scanner missed).
		if ( $merge ) {
			$stored  = get_option( SURECOOKIE_SCANNED_RESOURCES_OPTION, [] );
			$stored  = is_array( $stored ) ? $stored : [];
			$scripts = is_array( $stored['scripts'] ?? null ) ? array_values( $stored['scripts'] ) : [];
			$iframes = is_array( $stored['iframes'] ?? null ) ? array_values( $stored['iframes'] ) : [];

			foreach ( $scripts as $script ) {
				if ( ! empty( $script['domain'] ) ) {
					$seen_script_domains[ (string) $script['domain'] ] = true;
				}
			}

			foreach ( $iframes as $iframe ) {
				if ( ! empty( $iframe['domain'] ) ) {
					$seen_iframe_domains[ (string) $iframe['domain'] ] = true;
				}
			}
		}

		foreach ( $pages as $page ) {
			// Process scripts (graceful: skip if not present in response).
			foreach ( $page['scripts'] ?? [] as $script ) {
				if ( empty( $script['is_third_party'] ) ) {
					continue;
				}

				$domain = sanitize_text_field( $script['domain'] ?? '' );
				if ( empty( $domain ) || isset( $seen_script_domains[ $domain ] ) ) {
					continue;
				}

				$seen_script_domains[ $domain ] = true;
				$discovered++;

				$scripts[] = [
					'domain'     => $domain,
					'url'        => esc_url_raw( $script['url'] ?? '' ),
					'vendor'     => sanitize_text_field( $script['vendor_name'] ?? '' ),
					'category'   => $this->map_saas_category( $script['tracking_category'] ?? '' ),
					'scanned_at' => gmdate( 'c' ),
				];
			}

			// Process iframes (graceful: skip if not present in response).
			foreach ( $page['iframes'] ?? [] as $iframe ) {
				if ( empty( $iframe['is_third_party'] ) ) {
					continue;
				}

				$domain = sanitize_text_field( $iframe['domain'] ?? '' );
				if ( empty( $domain ) || isset( $seen_iframe_domains[ $domain ] ) ) {
					continue;
				}

				$seen_iframe_domains[ $domain ] = true;
				$discovered++;

				$iframes[] = [
					'domain'     => $domain,
					'url'        => esc_url_raw( $iframe['src'] ?? '' ),
					'vendor'     => sanitize_text_field( $iframe['vendor'] ?? '' ),
					'category'   => $this->map_saas_category( $iframe['tracking_category'] ?? '' ),
					'scanned_at' => gmdate( 'c' ),
				];
			}
		}

		// Nothing new. For a cloud scan this means the SaaS reported no scripts/iframes
		// (older version); in merge mode only already-stored domains surfaced. Either way
		// rewriting the option would bust the blocking caches for nothing.
		if ( $discovered === 0 ) {
			return;
		}

		// A cloud scan replaces the stored snapshot (the table always reflects current
		// third parties); merge mode only adds, since with its subset view removing a
		// domain would unblock a covered tracker. Per-domain block choices
		// (excluded_scan_resources) and installed Known Services live in separate stores
		// and survive either way.

		// Cap each type independently so iframes aren't silently dropped when scripts are large.
		$half_cap = (int) floor( self::MAX_RESOURCES / 2 );
		$scripts  = array_slice( $scripts, 0, $half_cap );
		$iframes  = array_slice( $iframes, 0, $half_cap );

		$resource_data = [
			'scripts'  => $scripts,
			'iframes'  => $iframes,
			'metadata' => [
				'last_scan_at' => gmdate( 'c' ),
				'version'      => 1,
			],
		];

		update_option( SURECOOKIE_SCANNED_RESOURCES_OPTION, $resource_data, false );

		// Bust the known-scripts cache so the merged dataset rebuilds on next page load.
		delete_transient( 'surecookie_known_scripts' );

		// Clear the Scan_Scripts static cache.
		\SureCookie\Inc\Modules\ScriptBlocking\Scan_Scripts::clear_cache();

		// Fire action so GCM service detector clears its cache and re-detects Google services.
		do_action( 'surecookie_scanner_results_updated' );

		Logger::get_instance()->save_log(
			sprintf( 'Stored %d scripts and %d iframes from scan results.', count( $scripts ), count( $iframes ) )
		);
	}

	/**
	 * Map a SaaS category onto a plugin category.
	 *
	 * Unknown values fall to `uncategorized`, not `marketing`: both are withheld
	 * until consent, but guessing `marketing` silently mislabels whatever the SaaS
	 * adds next, which is exactly how `security` ended up there.
	 *
	 * @param string $saas_category SaaS tracking category or cookie purpose.
	 * @since 0.0.0-alpha.2
	 * @return string Plugin category.
	 */
	private function map_saas_category( string $saas_category ): string {
		return self::CATEGORY_MAP[ $saas_category ] ?? 'uncategorized';
	}

	/**
	 * Group cookies by category from scan results.
	 *
	 * @param array<int, array<string, mixed>> $pages Scan results pages.
	 * @since 0.0.1
	 * @return array<string, array<int, array<string, mixed>>> Cookies grouped by category.
	 */
	private function group_cookies_by_category( array $pages ): array {
		$cookies_by_category = array_fill_keys( Get::default_cookie_categories_keys(), [] );
		$seen_signatures     = [];

		foreach ( $pages as $page ) {
			foreach ( $page['cookies'] ?? [] as $cookie ) {
				$signature_id = $cookie['signature_id'] ?? '';

				// Skip if no signature or already seen.
				if ( empty( $signature_id ) || isset( $seen_signatures[ $signature_id ] ) ) {
					continue;
				}

				$seen_signatures[ $signature_id ] = true;

				// Project through the same map the scripts/embeds path uses, so a SaaS-only
				// category resolves to its plugin equivalent instead of being flattened to
				// uncategorized (security cookies such as captcha tokens are essential).
				$category = $this->map_saas_category( (string) ( $cookie['category'] ?? '' ) );

				if ( ! isset( $cookies_by_category[ $category ] ) ) {
					$category = 'uncategorized';
				}

				// Add transformed cookie.
				$cookies_by_category[ $category ][] = $this->transform_cookie_data( $cookie, $category );
			}
		}

		return $cookies_by_category;
	}

	/**
	 * Merge declared (catalog-seeded) cookies into the observed set, curated
	 * classification winning on conflict.
	 *
	 * On a name+domain match the merged cookie keeps the observed runtime attributes
	 * (value, expires, httpOnly, secure, sameSite, signature_id, source) but takes the
	 * curated category / provider / purpose / description and is re-bucketed into the
	 * declared category. Cookies in only one set pass through unchanged; a purely-declared
	 * cookie (its service was blocked, never observed) is added as-is.
	 *
	 * A first-party declared cookie also matches on name alone: its domain is this host
	 * substituted for the catalog placeholder, but a tag may scope the cookie to a
	 * different label (Analytics writes `_ga` on the registrable domain, so
	 * `shop.example.com` observes `.example.com`). Without that fallback both rows store,
	 * duplicating `_ga` in the manager and on the public cookie policy.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $observed Observed cookies grouped by category.
	 * @param array<string, array<int, array<string, mixed>>> $declared Declared cookies grouped by category.
	 * @since 1.2.5
	 * @return array<string, array<int, array<string, mixed>>> Merged cookies grouped by category.
	 */
	private function merge_declared_cookies( array $observed, array $declared ): array {
		// Index declared cookies by name+domain, remembering their curated
		// category, so an observed match can adopt the curated classification.
		$declared_by_key = [];
		foreach ( $declared as $category => $cookies ) {
			foreach ( $cookies as $cookie ) {
				$entry = [
					'category' => $category,
					'cookie'   => $cookie,
				];

				$declared_by_key[ $this->cookie_dedupe_key( $cookie ) ] = $entry;

				if ( Cookie_Identity::is_first_party( $cookie ) ) {
					$declared_by_key[ Cookie_Identity::name_key( (string) ( $cookie['name'] ?? '' ) ) ] = $entry;
				}
			}
		}

		$merged       = array_fill_keys( Get::default_cookie_categories_keys(), [] );
		$applied_keys = [];

		// Pass 1: observed cookies. A curated match re-classifies and re-buckets;
		// everything else passes through in its observed category.
		foreach ( $observed as $category => $cookies ) {
			foreach ( $cookies as $cookie ) {
				$key = $this->cookie_dedupe_key( $cookie );

				if ( ! isset( $declared_by_key[ $key ] ) ) {
					$key = Cookie_Identity::name_key( (string) ( $cookie['name'] ?? '' ) );
				}

				if ( ! isset( $declared_by_key[ $key ] ) ) {
					// No literal catalog row, but a pattern row may still describe it.
					// Blanks only, so a scan-resolved value and an admin's correction win.
					$merged[ $category ][] = $this->fill_from_catalog_pattern( $cookie );
					continue;
				}

				$curated         = $declared_by_key[ $key ]['cookie'];
				$target_category = $declared_by_key[ $key ]['category'];

				// Keep runtime attributes; take the curated classification. The catalog always sets
				// these keys (possibly ''), so test emptiness not null - a blank entry must not overwrite a scan-resolved value.
				$cookie['category']    = $target_category;
				$cookie['provider']    = ! empty( $curated['provider'] ) ? $curated['provider'] : ( $cookie['provider'] ?? '' );
				$cookie['purpose']     = ! empty( $curated['purpose'] ) ? $curated['purpose'] : ( $cookie['purpose'] ?? '' );
				$cookie['description'] = ! empty( $curated['description'] ) ? $curated['description'] : ( $cookie['description'] ?? '' );
				// The catalog's day count is authored, so it beats deriving one from the observed expiry - which only yields the time REMAINING.
				$cookie['duration'] = ! empty( $curated['duration'] ) ? $curated['duration'] : ( $cookie['duration'] ?? '' );

				if ( ! isset( $merged[ $target_category ] ) ) {
					$merged[ $target_category ] = [];
				}

				$merged[ $target_category ][] = $cookie;
				$applied_keys[ $key ]         = true;
			}
		}

		// Pass 2: declared cookies with no observed counterpart.
		foreach ( $declared as $category => $cookies ) {
			foreach ( $cookies as $cookie ) {
				$key       = $this->cookie_dedupe_key( $cookie );
				$name_only = Cookie_Identity::name_key( (string) ( $cookie['name'] ?? '' ) );

				// Mirrors pass 1: a first-party row absorbed by an observed cookie
				// on another label of this host was applied under its name-only key.
				if ( isset( $applied_keys[ $key ] )
					|| ( Cookie_Identity::is_first_party( $cookie ) && isset( $applied_keys[ $name_only ] ) ) ) {
					continue;
				}

				if ( ! isset( $merged[ $category ] ) ) {
					$merged[ $category ] = [];
				}

				$merged[ $category ][] = $cookie;
				$applied_keys[ $key ]  = true;
			}
		}

		return $merged;
	}

	/**
	 * Build a case-insensitive name+domain key for cookie de-duplication.
	 *
	 * @param array<string, mixed> $cookie Cookie data.
	 * @since 1.2.5
	 * @return string
	 */
	private function cookie_dedupe_key( array $cookie ): string {
		return Cookie_Identity::key_for( $cookie );
	}

	/**
	 * The cookie's lifetime in days, taken from the scan's own clock.
	 *
	 * Derived here rather than at render time: `expires_at` is the instant observed
	 * during the scan, so re-deriving it later counts down and a stale row eventually
	 * reads 0. A session cookie has no expiry at all, hence the empty string and the
	 * separately carried bucket.
	 *
	 * @param array<string, mixed> $cookie Raw cookie data from API.
	 * @since 1.5.0
	 * @return string Day count, or an empty string.
	 */
	private function scan_duration( array $cookie ): string {
		$expires_at = strtotime( (string) ( $cookie['expires_at'] ?? '' ) );

		if ( $expires_at === false ) {
			return '';
		}

		$days = (int) ceil( ( $expires_at - time() ) / DAY_IN_SECONDS );

		return $days > 0 ? (string) $days : '';
	}

	/**
	 * Transform cookie data to minimal required format.
	 *
	 * @param array<string, mixed> $cookie Raw cookie data from API.
	 * @param string               $category Cookie category.
	 * @since 0.0.1
	 * @return array<string, mixed> Minimal cookie data.
	 */
	private function transform_cookie_data( array $cookie, string $category ): array {
		// Use signature_id as the unique identifier.
		$signature_id = $cookie['signature_id'] ?? '';

		$transformed = [
			// Cookie properties.
			'name'          => $cookie['name'] ?? '',
			'value'         => $cookie['value'] ?? '',
			'domain'        => $cookie['domain'] ?? '',
			'path'          => $cookie['path'] ?? '/',
			'expires'       => $cookie['expires_at'] ?? null,
			'duration'      => $this->scan_duration( $cookie ),
			'expiry_bucket' => sanitize_key( (string) ( $cookie['expiry_bucket'] ?? '' ) ),
			'httpOnly'      => ! empty( $cookie['http_only'] ),
			'secure'        => ! empty( $cookie['secure'] ),
			'sameSite'      => $cookie['same_site'] ?? 'lax',
			'category'      => $category,

			// Cookie policy display fields.
			// Scan API exposes the vendor as 'owner' ('vendor' kept for back-compat), never
			// 'provider'; read those first, fall back to the setter domain ('set_via').
			'provider'      => $this->resolve_cookie_provider( $cookie ),
			'description'   => $cookie['description'] ?? '',
			'purpose'       => $cookie['purpose'] ?? '',

			// Unique identifier for deduplication.
			'signature_id'  => $signature_id,
		];

		// Which scanner observed this cookie. Carried only when the scan declares it, so a
		// cloud row keeps its exact stored shape. Mirrors Declared_Cookies::transform().
		if ( ! empty( $cookie['source'] ) ) {
			$transformed['source'] = sanitize_key( (string) $cookie['source'] );
		}

		// Carry the resolved-first-party marker so the declared-cookie merge and the
		// assisted-scan index can still match by name alone when the domain is on a different label.
		if ( Cookie_Identity::is_first_party( $cookie ) ) {
			$transformed[ Cookie_Identity::FIRST_PARTY_FLAG ] = true;
		}

		return $transformed;
	}

	/**
	 * Fill a scanned cookie's blank curated fields from a catalog pattern.
	 *
	 * The exact name+domain key a literal match needs is one a pattern row can
	 * never satisfy, so a dynamic name kept the blank Purpose (#1202).
	 *
	 * @param array<string, mixed> $cookie Scanned cookie.
	 * @since 1.5.1
	 * @return array<string, mixed>
	 */
	private function fill_from_catalog_pattern( array $cookie ): array {
		$details = Declared_Cookies::get_instance()->catalog_details_for( $cookie );

		if ( $details === [] ) {
			return $cookie;
		}

		foreach ( [ 'provider', 'purpose', 'description', 'duration' ] as $field ) {
			if ( empty( $cookie[ $field ] ) && ! empty( $details[ $field ] ) ) {
				$cookie[ $field ] = $details[ $field ];
			}
		}

		return $cookie;
	}

	/**
	 * Resolve the display provider for a scanned cookie.
	 *
	 * The scan API reports the vendor under 'owner' (mirrored in 'vendor'); both are null
	 * when no approved canonical exists yet. 'set_via' (the setter script's domain) is a
	 * usable last resort so the column is not left blank.
	 *
	 * @param array<string, mixed> $cookie Raw cookie data from API.
	 * @since 1.3.0
	 * @since 1.7.0 Site-owned cookies take the site's own identity over the canonical.
	 * @return string Provider name, or an empty string when nothing is known.
	 */
	private function resolve_cookie_provider( array $cookie ): string {
		// Classified vendor wins, then the bundled catalog (a real name like "Google
		// Analytics" where set_via only has a hostname; consulting it keeps a re-scan from
		// downgrading a row the catalog backfill resolved). set_via is the last resort.
		// A cookie only this site can own takes none of the API's three attributions: every
		// one is keyed on the cookie NAME and shared across sites, so a single bad approval
		// named a stranger as the provider of PHPSESSID (#1274).
		$candidates = Normalizer::is_site_owned_cookie( $cookie )
			? [
				Declared_Cookies::get_instance()->catalog_provider_for( $cookie ),
				Cookie_Identity::first_party_domain(),
			]
			: [
				$cookie['owner'] ?? null,
				$cookie['vendor'] ?? null,
				Declared_Cookies::get_instance()->catalog_provider_for( $cookie ),
				$cookie['set_via'] ?? null,
			];

		foreach ( $candidates as $candidate ) {
			$provider = Sanitize::vendor( $candidate );
			if ( $provider !== '' ) {
				return $provider;
			}
		}

		return '';
	}

	/**
	 * Store cookies from scan, merging with existing ones (same signature_id replaces,
	 * otherwise add).
	 *
	 * A cookie is re-bucketed into the category this scan reports, which would discard a
	 * category the admin assigned by hand (even for a cookie gone for a few scans and now
	 * back). So remembered assignments are re-applied to the reported set before merging.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $new_cookies    New cookies by category.
	 * @param string                                          $scan_type      Scanner that produced this set.
	 * @param bool                                            $record_history Whether to stamp the scan-history record.
	 *                                                                        False for one page of a multi-part walk, whose
	 *                                                                        history belongs to the walk, not to the page -
	 *                                                                        otherwise `total_scans` would count every page
	 *                                                                        as a separate scan.
	 * @since 0.0.1
	 * @since 1.3.0 Added `$scan_type` and `$record_history`.
	 * @return void
	 */
	private function store_all_cookies_from_agent_app( array $new_cookies, string $scan_type = 'saas', bool $record_history = true ): void {
		$new_cookies = CookieCategoryMemory::apply( $new_cookies );

		// Get existing cookies.
		$existing_cookies = get_option( SURECOOKIE_SCANNED_COOKIES_OPTION, [] );
		if ( ! is_array( $existing_cookies ) ) {
			$existing_cookies = [];
		}

		// Initialize all categories.
		$default_categories = Get::default_cookie_categories_keys();
		foreach ( $default_categories as $category ) {
			if ( ! isset( $existing_cookies[ $category ] ) ) {
				$existing_cookies[ $category ] = [];
			}
		}

		// Track changes.
		$new_count     = 0;
		$updated_count = 0;

		// Process each new cookie.
		foreach ( $new_cookies as $category => $cookies ) {
			foreach ( $cookies as $new_cookie ) {
				$signature_id = $new_cookie['signature_id'] ?? '';

				if ( empty( $signature_id ) ) {
					continue; // Skip cookies without signature.
				}

				// A catalog stand-in for a cookie already observed for real adds
				// nothing and would sit beside it as a duplicate.
				if ( $this->is_covered_by_observation( $existing_cookies, $new_cookie ) ) {
					continue;
				}

				// A richer cloud scan absorbs the browser-collected row it supersedes. An
				// assisted scan mints its own id for a cookie it saw first, and the cloud
				// scanner derives ids server-side from data a browser can't see, so the ids
				// never agree - without this the cookie stores under both and prints twice.
				$incoming_identity = Cookie_Identity::key_for( $new_cookie );
				$incoming_assisted = Normalizer::is_assisted_signature( (string) $signature_id );
				$incoming_observed = self::is_observation_row( $new_cookie );
				$absorb_assisted   = $scan_type === 'saas' && ! $incoming_assisted;

				// Remove the rows this cookie replaces, from ALL categories: the same
				// signature_id (same cookie seen again); a catalog-declared row for the same
				// cookie (stored under the catalog's `declared:<service>:<name>` id, so a
				// signature match never finds it and it used to survive alongside the observed
				// row, listing the cookie twice - merge_declared_cookies() absorbs it when both
				// land in one scan); and a browser-collected `assisted:` row a cloud scan
				// supersedes. Collected first, removed after: re-indexing mid-iteration would
				// invalidate the indexes still to be visited.
				$found_existing = false;
				$to_remove      = [];
				$replaced       = null;

				foreach ( $existing_cookies as $existing_category => $existing_category_cookies ) {
					foreach ( $existing_category_cookies as $index => $existing_cookie ) {
						$existing_signature = (string) ( $existing_cookie['signature_id'] ?? '' );
						$same_signature     = $existing_signature === $signature_id;
						$superseded         = $absorb_assisted
							&& Normalizer::is_assisted_signature( $existing_signature )
							&& Cookie_Identity::key_for( $existing_cookie ) === $incoming_identity;

						// Same cookie, new signature id: the scan API hashes a TTL bucket
						// into that id, so an unchanged cookie can return a different one
						// and evicting by id alone appended a second row every scan.
						// Same-provenance only, so assisted never overwrites cloud.
						$resurveyed = $incoming_observed
							&& self::is_observation_row( $existing_cookie )
							&& $incoming_assisted === Normalizer::is_assisted_signature( $existing_signature )
							&& Cookie_Identity::key_for( $existing_cookie ) === $incoming_identity;

						if ( $same_signature || $superseded || $resurveyed || $this->supersedes_declared( $existing_cookie, $new_cookie ) ) {
							$to_remove[ $existing_category ][] = $index;
							$found_existing                    = true;
							$replaced                          = $replaced ?? $existing_cookie;
						}
					}
				}

				foreach ( $to_remove as $existing_category => $indexes ) {
					foreach ( $indexes as $index ) {
						unset( $existing_cookies[ $existing_category ][ $index ] );
					}
					$existing_cookies[ $existing_category ] = array_values( $existing_cookies[ $existing_category ] );
				}

				if ( is_array( $replaced ) ) {
					$new_cookie = self::inherit_from_replaced( $new_cookie, $replaced );
				}

				// Bucket by the possibly-inherited category, not the scan's.
				$target_category = (string) ( $new_cookie['category'] ?? $category );

				if ( ! isset( $existing_cookies[ $target_category ] ) ) {
					$existing_cookies[ $target_category ] = [];
				}

				$existing_cookies[ $target_category ][] = $new_cookie;

				// Track if new or updated.
				if ( $found_existing ) {
					$updated_count++;
				} else {
					$new_count++;
				}
			}
		}

		// Save to database.
		Update::option( SURECOOKIE_SCANNED_COOKIES_OPTION, $existing_cookies );

		// Update scan history.
		$total_cookies = $this->get_cookies_count( $existing_cookies );
		if ( $record_history ) {
			$this->update_scan_history( $total_cookies, $scan_type );
		}

		// Log results.
		if ( $new_count > 0 && $updated_count > 0 ) {
			Logger::get_instance()->save_log( sprintf( '%d new, %d updated (total: %d).', $new_count, $updated_count, $total_cookies ) );
		} elseif ( $new_count > 0 ) {
			Logger::get_instance()->save_log( sprintf( '%d new (total: %d).', $new_count, $total_cookies ) );
		} elseif ( $updated_count > 0 ) {
			Logger::get_instance()->save_log( sprintf( '%d updated (total: %d).', $updated_count, $total_cookies ) );
		} else {
			Logger::get_instance()->save_log( sprintf( 'No changes (total: %d).', $total_cookies ) );
		}
	}

	/**
	 * Whether one of two rows for the same cookie supersedes the other, so only one is kept.
	 *
	 * Exactly one of the pair may be catalog-declared, and the observed row always wins:
	 * it carries the runtime attributes, and merge_declared_cookies() already folded the
	 * curated classification onto it when both landed in one scan. Checked BOTH directions
	 * since either can be the stored one - declared first then observed (embed blocked, then
	 * loaded), or observed first then declared (script seen but cookie unset: consent-gated
	 * tags, delay-until-interaction optimisers, challenge pages).
	 *
	 * Identity is the exact name+domain key only. An earlier revision also matched name
	 * alone for a first-party declared row (to catch shop.example.com scoping `_ga` to
	 * .example.com), but absorbing it deletes a row the admin may have categorised while
	 * CookieCategoryMemory still keys the pin to the old domain, orphaning the pin so the
	 * category silently reverts next scan. Losing a compliance decision beats a duplicate
	 * row, so that case waits on a change that moves the memory key too - see issue #876.
	 *
	 * @param array<string, mixed> $existing Stored cookie row.
	 * @param array<string, mixed> $incoming Cookie about to be stored.
	 * @since 1.3.0
	 * @return bool
	 */
	private function supersedes_declared( array $existing, array $incoming ): bool {
		// Stored row is the catalog stand-in and incoming is a real observation, so the
		// observation replaces it. Two declared rows dedupe by deterministic signature;
		// two observed rows pair up on identity via `$resurveyed` in the caller.
		if ( ! self::is_declared_row( $existing ) || self::is_declared_row( $incoming ) ) {
			return false;
		}

		return Cookie_Identity::key_for( $existing ) === Cookie_Identity::key_for( $incoming );
	}

	/**
	 * Whether an incoming catalog-declared cookie is already covered by a stored
	 * observation, and so should not be stored at all.
	 *
	 * Mirror image of {@see self::supersedes_declared()}. A scan can detect a service's
	 * script without the cookie being set (consent-gated tag, delay-until-interaction
	 * optimiser, challenge page), then the catalog declares a cookie a previous scan
	 * already observed - the same duplicate seen from the other side.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $existing_cookies Stored cookies by category.
	 * @param array<string, mixed>                            $incoming         Cookie about to be stored.
	 * @since 1.3.0
	 * @return bool
	 */
	private function is_covered_by_observation( array $existing_cookies, array $incoming ): bool {
		if ( ! self::is_declared_row( $incoming ) ) {
			return false;
		}

		$identity = Cookie_Identity::key_for( $incoming );

		foreach ( $existing_cookies as $rows ) {
			foreach ( $rows as $existing ) {
				if ( self::is_declared_row( $existing ) ) {
					continue;
				}

				if ( Cookie_Identity::key_for( $existing ) === $identity ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a stored row came from the Known Services catalog rather than from an
	 * observation or the administrator.
	 *
	 * Checks the signature prefix as well as `source`, so rows written before the
	 * `source` marker existed are still recognised.
	 *
	 * @param array<string, mixed> $row Stored cookie row.
	 * @since 1.3.0
	 * @return bool
	 */
	private static function is_declared_row( array $row ): bool {
		if ( ( $row['source'] ?? '' ) === 'declared' ) {
			return true;
		}

		return strncmp( (string) ( $row['signature_id'] ?? '' ), 'declared:', 9 ) === 0;
	}

	/**
	 * Fill blanks in a row from the row it replaces, so a re-scan enriches rather
	 * than strips it.
	 *
	 * The scan API sends classification only when the SaaS resolved a canonical, so
	 * a re-scan can arrive with no provider/purpose/description and an
	 * `uncategorized` category. Cloud-path counterpart of
	 * {@see Normalizer::merge_without_downgrade()}, on the stored row shape.
	 *
	 * @param array<string, mixed> $incoming Row about to be stored.
	 * @param array<string, mixed> $existing Row it replaces.
	 * @since 1.3.1
	 * @return array<string, mixed>
	 */
	private static function inherit_from_replaced( array $incoming, array $existing ): array {
		// 'duration' carries the catalog's authored day count, which no scan reports.
		// Omitting it dropped that value whenever a scan replaced the row, while
		// First_Party_Repair::CURATED_FIELDS had always carried it. A session-scoped
		// observation is a retraction, so it must not inherit a day count back.
		$fields = [ 'provider', 'purpose', 'description', 'expires', 'duration', 'expiry_bucket' ];

		if ( ( $incoming['expiry_bucket'] ?? '' ) === 'session' ) {
			$fields = array_diff( $fields, [ 'duration' ] );
		}

		foreach ( $fields as $field ) {
			if ( self::is_blank_field( $incoming[ $field ] ?? null ) && ! self::is_blank_field( $existing[ $field ] ?? null ) ) {
				$incoming[ $field ] = $existing[ $field ];
			}
		}

		// An omitted flag and an explicit false are indistinguishable after
		// transform, so never turn a stored true into a false.
		foreach ( [ 'httpOnly', 'secure' ] as $flag ) {
			if ( ! empty( $existing[ $flag ] ) ) {
				$incoming[ $flag ] = true;
			}
		}

		// `uncategorized` means the scan had no classification, not a decision. An
		// admin pin already sits on $incoming via CookieCategoryMemory and wins.
		$existing_category = (string) ( $existing['category'] ?? '' );

		if ( ( $incoming['category'] ?? '' ) === 'uncategorized'
			&& $existing_category !== ''
			&& $existing_category !== 'uncategorized'
			&& CookieCategoryMemory::remembered_category( $incoming ) === ''
		) {
			$incoming['category'] = $existing_category;
		}

		return $incoming;
	}

	/**
	 * Whether a stored field carries no usable value.
	 *
	 * @param mixed $value Field value.
	 * @since 1.3.1
	 * @return bool
	 */
	private static function is_blank_field( $value ): bool {
		return $value === null || ( is_string( $value ) && trim( $value ) === '' );
	}

	/**
	 * Whether a row is a scan observation, and so may be replaced when a later scan
	 * re-observes the same cookie.
	 *
	 * Excludes catalog-declared rows (paired by {@see self::supersedes_declared()})
	 * and hand-added `custom_` rows, which a scan must never drop.
	 *
	 * @param array<string, mixed> $row Cookie row.
	 * @since 1.3.1
	 * @return bool
	 */
	private static function is_observation_row( array $row ): bool {
		if ( self::is_declared_row( $row ) ) {
			return false;
		}

		return strncmp( (string) ( $row['signature_id'] ?? '' ), 'custom_', 7 ) !== 0;
	}

	/**
	 * Get cookies count.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $all_cookies All cookies array.
	 * @since 0.0.1
	 * @return int
	 */
	private function get_cookies_count( array $all_cookies ): int {
		if ( empty( $all_cookies ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $all_cookies as $cookies ) {
			$count += count( $cookies );
		}

		return $count;
	}

	/**
	 * Update scan history option.
	 *
	 * Stores only the latest scan record.
	 *
	 * @param int    $cookies_count Number of cookies found.
	 * @param string $scan_type     Scanner that produced this set.
	 * @since 0.0.1
	 * @since 1.3.0 Added `$scan_type`.
	 * @return void
	 */
	private function update_scan_history( int $cookies_count, string $scan_type = 'saas' ): void {
		$option = get_option( SURECOOKIE_SCANNED_DETAILS_OPTION, [] );

		if ( ! is_array( $option ) ) {
			$option = [];
		}

		$total_scans = isset( $option['total_scans'] ) ? (int) $option['total_scans'] : 0;

		// Merge (don't replace) so the change-detection keys (reported_snapshot/changes)
		// written by the Automatic Scanning recorder survive this basic-field update and
		// stay available as the next scan's diff baseline.
		$history = array_merge(
			$option,
			[
				'date'          => current_time( 'mysql' ),
				'cookies_count' => $cookies_count,
				'scan_type'     => $scan_type,
				'total_scans'   => $total_scans + 1, // Required for future analytics.
				'success'       => true,
			]
		);

		Update::option( SURECOOKIE_SCANNED_DETAILS_OPTION, $history );
	}
}
