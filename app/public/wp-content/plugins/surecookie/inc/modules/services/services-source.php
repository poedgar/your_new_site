<?php
/**
 * Services Source (unified catalog).
 *
 * Single fetch/cache authority for the unified `dataset/services.json` catalog
 * (1.3.0+), carrying each service's blocking patterns AND declared cookies under
 * one slug. Replaces the two near-identical pipelines that fetched
 * `blocking-scripts.json` and `service-cookies.json` separately: `Known_Scripts`
 * and `Service_Cookies_Source` now project their views from this one source - one
 * HTTP fetch, one transient, one file cache, one bundled floor.
 *
 * Resolution order (hot path first): request transient -> file cache (merged over
 * the bundled floor, remote wins per slug) -> bundled floor. The remote is never
 * fetched inline; it is warmed off-request by the script-blocking cron.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.3.0
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\Functions\Cache;
use SureCookie\Inc\Functions\Cookie_Identity;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Services_Source
 *
 * Loads, caches and refreshes the unified service catalog and projects it into
 * the blocking view (category => slug => patterns) and the declared-cookie view
 * (slug => cookies).
 *
 * @since 1.3.0
 */
class Services_Source {
	use GetInstance;

	/**
	 * Remote dataset path (appended to the agent app URL).
	 */
	protected const REMOTE_FILE_PATH = 'dataset/services.json';

	/**
	 * Transient key for the merged unified catalog, stamped `{version, catalog}`.
	 * Not `surecookie_services`: a rolled-back release reads that key as a bare catalog.
	 */
	protected const CACHE_KEY = 'surecookie_services_v2';

	/**
	 * File cache path (relative to the uploads cache dir).
	 */
	protected const CACHE_FILE = 'services/services.json';

	/**
	 * Bundled baseline catalog shipped with the plugin (full core embed set:
	 * patterns + declared cookies). Guarantees blocking + declared cookies work
	 * offline / before the first remote warm; the remote catalog wins per slug.
	 */
	protected const BUNDLED_FILE = 'inc/modules/services/data/services.json';

	/**
	 * Transient duration in seconds (24 hours).
	 */
	protected const CACHE_DURATION = DAY_IN_SECONDS;

	/**
	 * File cache duration in seconds (7 days).
	 */
	protected const FILE_CACHE_DURATION = WEEK_IN_SECONDS;

	/**
	 * Per-request cache of the resolved unified catalog (slug => entry).
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $catalog = null;

	/**
	 * Resolve the unified catalog: slug => {label, category, gcm_compatible?,
	 * cookieless?, patterns keyed by Pattern_Kinds bucket, cookies:[...]}. `_meta`
	 * is stripped.
	 *
	 * First-party placeholder domains are resolved here, on the way out of the
	 * caches, so every consumer (Known Services REST, install(), declared-cookie
	 * seeding, the provider index) sees this site's host. See
	 * {@see self::resolve_first_party()} for why here and not one layer down.
	 *
	 * @since 1.3.0
	 * @return array<string, array<string, mixed>>
	 */
	public function get_catalog(): array {
		if ( $this->catalog !== null ) {
			return $this->catalog;
		}

		$this->catalog = $this->resolve_first_party( $this->load_catalog() );

		return $this->catalog;
	}

	/**
	 * Project the catalog into the blocking view consumed by Known_Scripts /
	 * Blocker: category => slug => {label, one array per Pattern_Kinds bucket,
	 * gcm_compatible?, cookieless?}. Only services with at least one pattern are
	 * emitted.
	 *
	 * @since 1.3.0
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function get_blocking_view(): array {
		return $this->blocking_view( $this->get_catalog() );
	}

	/**
	 * Blocking view built from the bundled floor only (offline baseline). Used as
	 * the guaranteed floor and by the bundled-fallback tests.
	 *
	 * @since 1.3.0
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function get_bundled_blocking_view(): array {
		return $this->blocking_view( $this->get_bundled_data() );
	}

	/**
	 * Project the catalog into the declared-cookie view consumed by
	 * Service_Cookies_Source / Declared_Cookies: slug => [cookie rows]. Only
	 * services that declare at least one cookie are emitted.
	 *
	 * @since 1.3.0
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function get_cookies_view(): array {
		$view = [];

		foreach ( $this->get_catalog() as $slug => $service ) {
			if ( ! is_array( $service ) ) {
				continue;
			}

			$cookies = $service['cookies'] ?? [];
			if ( is_array( $cookies ) && $cookies !== [] ) {
				$view[ $slug ] = array_values( $cookies );
			}
		}

		return $view;
	}

	/**
	 * Fetch the remote catalog, validate it, merge over the bundled floor and
	 * persist it to the file cache. Called off the request path by the cron.
	 *
	 * On any failure the existing cache/floor is left untouched so neither
	 * blocking nor declared cookies regress. Busts the downstream transients so
	 * the projected views and the presets REST response rebuild.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function refresh_from_remote(): void {
		$api_data = $this->fetch_from_api();

		if ( $api_data === null || empty( $api_data ) ) {
			return;
		}

		$merged = $this->merge_catalogs( $this->get_bundled_data(), $api_data );

		$this->set_file_cache( $merged );

		delete_transient( self::CACHE_KEY );

		$this->catalog = null;
	}

	/**
	 * Clear the cached catalog, falling back to the bundled floor so blocking and
	 * declared cookies keep running until the next request repopulates.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
		Cache::delete_file( self::CACHE_FILE );
		$this->catalog = null;
	}

	/**
	 * Load the plugin-bundled unified catalog (with `_meta` stripped).
	 *
	 * @since 1.3.0
	 * @return array<string, array<string, mixed>>
	 */
	public function get_bundled_data(): array {
		$path = SURECOOKIE_DIR . self::BUNDLED_FILE;

		if ( ! file_exists( $path ) ) {
			return [];
		}

		$data = wp_json_file_decode( $path, [ 'associative' => true ] );
		$data = is_array( $data ) ? $data : [];
		unset( $data['_meta'] );

		return $data;
	}

	/**
	 * One catalog entry's patterns, counted the way the admin may state them.
	 *
	 * `blockable` is true only when a pass can act on something the service
	 * declares. A font or image host is a real pattern and still belongs in the
	 * listing, but calling it blocked would promise gating the engine cannot do,
	 * so it is counted separately as `unblockableCount`.
	 *
	 * @param array<string, mixed> $service Catalog entry.
	 * @since 1.5.0
	 * @return array{scriptCount: int, iframeCount: int, styleCount: int, unblockableCount: int, blockable: bool}
	 */
	public static function pattern_summary( array $service ): array {
		$resources   = self::pattern_lists( $service );
		$blockable   = 0;
		$unblockable = 0;

		foreach ( array_keys( Pattern_Kinds::enforced() ) as $bucket ) {
			$blockable += count( $resources[ $bucket ] );
		}

		foreach ( array_keys( Pattern_Kinds::unenforced() ) as $bucket ) {
			$unblockable += count( $resources[ $bucket ] );
		}

		return [
			'scriptCount'      => count( $resources['scripts'] ),
			'iframeCount'      => count( $resources['iframes'] ),
			'styleCount'       => count( $resources['styles'] ),
			'unblockableCount' => $unblockable,
			'blockable'        => $blockable > 0,
		];
	}

	/**
	 * One catalog entry's patterns, by bucket, with every bucket present.
	 *
	 * @param array<string, mixed> $service Catalog entry.
	 * @since 1.5.0
	 * @return array<string, array<int, string>>
	 */
	public static function pattern_lists( array $service ): array {
		$resources = [];

		foreach ( Pattern_Kinds::buckets() as $bucket ) {
			$resources[ $bucket ] = array_values( (array) ( $service['patterns'][ $bucket ] ?? [] ) );
		}

		return $resources;
	}

	/**
	 * Load the catalog as authored, from the transient, the file cache or the
	 * bundled floor.
	 *
	 * @since 1.3.0
	 * @return array<string, array<string, mixed>>
	 */
	private function load_catalog(): array {
		// 1. Transient already holds the merged catalog - the hot path. It is
		// stamped with the release whose bundled floor it was merged over, so an
		// upgrade rebuilds it instead of serving the previous floor for a day.
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && ( $cached['version'] ?? '' ) === SURECOOKIE_VERSION && is_array( $cached['catalog'] ?? null ) && $cached['catalog'] !== [] ) {
			return $cached['catalog'];
		}

		$bundled = $this->get_bundled_data();

		// 2. File cache, merged over the bundled floor (remote wins per slug).
		$file_cached = $this->get_file_cache();
		$catalog     = $file_cached !== null ? $this->merge_catalogs( $bundled, $file_cached ) : $bundled;

		set_transient(
			self::CACHE_KEY,
			[
				'version' => SURECOOKIE_VERSION,
				'catalog' => $catalog,
			],
			self::CACHE_DURATION
		);

		// 3. Both caches cold: serve the bundled floor now and warm the remote
		// off-request (a near-immediate one-off, so a fresh install does not wait
		// for the daily cron) - the page load is never blocked on the network.
		if ( $file_cached === null ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Cron::REFRESH_HOOK );
		}

		return $catalog;
	}

	/**
	 * Substitute this site's host for the catalog's first-party placeholder.
	 *
	 * Applied AFTER the caches are populated, never before: the transient and
	 * 7-day file cache are shared across every blog of a multisite network, so
	 * baking one blog's host in would leak it to the others and survive a domain
	 * change for a week. Resolving on the way out means a moved or cloned site is
	 * correct on its next request.
	 *
	 * Touched rows are flagged {@see Cookie_Identity::FIRST_PARTY_FLAG}: the
	 * substituted host is still not necessarily where the tag wrote the cookie
	 * (Analytics scopes `_ga` to the registrable domain, so `shop.example.com`
	 * observes `.example.com`), so the dedup paths must know which domains were
	 * inferred rather than authored.
	 *
	 * @param array<string, array<string, mixed>> $catalog Catalog as authored.
	 * @since 1.3.0
	 * @return array<string, array<string, mixed>>
	 */
	private function resolve_first_party( array $catalog ): array {
		foreach ( $catalog as $slug => $service ) {
			if ( ! is_array( $service ) || ! is_array( $service['cookies'] ?? null ) ) {
				continue;
			}

			foreach ( $service['cookies'] as $index => $cookie ) {
				if ( ! is_array( $cookie ) || ! Cookie_Identity::is_placeholder( (string) ( $cookie['domain'] ?? '' ) ) ) {
					continue;
				}

				$catalog[ $slug ]['cookies'][ $index ]['domain']                            = Cookie_Identity::resolve( (string) $cookie['domain'] );
				$catalog[ $slug ]['cookies'][ $index ][ Cookie_Identity::FIRST_PARTY_FLAG ] = true;
			}
		}

		return $catalog;
	}

	/**
	 * Project a unified catalog into the blocking view (category => slug =>
	 * {label, one array per Pattern_Kinds bucket, gcm_compatible?, cookieless?});
	 * services without patterns are omitted.
	 *
	 * Every bucket is emitted, including the ones no pass reads: a consumer that
	 * only cares what gets rewritten filters on `Pattern_Kinds::enforced()`,
	 * while the admin surfaces need the rest to say what is NOT blocked.
	 *
	 * @param array<string, array<string, mixed>> $catalog Unified catalog.
	 * @since 1.3.0
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function blocking_view( array $catalog ): array {
		$view = [];

		foreach ( $catalog as $slug => $service ) {
			if ( ! is_array( $service ) ) {
				continue;
			}

			$entry = [ 'label' => (string) ( $service['label'] ?? $slug ) ];
			$empty = true;

			foreach ( Pattern_Kinds::buckets() as $bucket ) {
				$entry[ $bucket ] = array_values( (array) ( $service['patterns'][ $bucket ] ?? [] ) );
				$empty            = $empty && $entry[ $bucket ] === [];
			}

			if ( $empty ) {
				continue;
			}

			$category = is_string( $service['category'] ?? null ) ? $service['category'] : 'uncategorized';

			if ( isset( $service['gcm_compatible'] ) ) {
				$entry['gcm_compatible'] = (bool) $service['gcm_compatible'];
			}

			if ( isset( $service['cookieless'] ) ) {
				$entry['cookieless'] = (bool) $service['cookieless'];
			}

			if ( ! empty( $service['transfer_countries'] ) ) {
				$entry['transfer_countries'] = array_values( (array) $service['transfer_countries'] );
			}

			$view[ $category ][ $slug ] = $entry;
		}

		return $view;
	}

	/**
	 * Merge two unified catalogs at the slug level, `$override` winning per slug.
	 *
	 * Deliberately a replace and not a deep merge: the remote has to stay able to
	 * retire a cookie row or a pattern that has gone wrong. Two things are floors
	 * instead. Blocking patterns, because the validator admits a remote row on
	 * valid cookies alone, so a cookies-only row would otherwise silently stop
	 * blocking a service that every bundled entry has patterns for - emptiness is
	 * treated as an incomplete row, never as "stop blocking this". And a bundled
	 * `cookieless` assertion, which survives a remote row that says nothing about
	 * it. `gcm_compatible` runs the other way for the same reason: the floor caps
	 * it, so no remote row can release a service from consent gating on its own.
	 *
	 * @param array<string, array<string, mixed>> $base     Bundled floor.
	 * @param array<string, array<string, mixed>> $override Remote/cached catalog.
	 * @since 1.3.0
	 * @return array<string, array<string, mixed>>
	 */
	private function merge_catalogs( array $base, array $override ): array {
		unset( $override['_meta'] );

		foreach ( $override as $slug => $entry ) {
			if ( is_array( $entry ) && isset( $base[ $slug ] ) && ! empty( $base[ $slug ]['patterns'] ) ) {
				$patterns = is_array( $entry['patterns'] ?? null ) ? $entry['patterns'] : [];
				$declared = false;

				// Only the ENFORCED buckets count, as they did before `styles`
				// and `media` existed. A remote row carrying nothing but a
				// media classification is still an incomplete row, and letting
				// it satisfy this guard would drop the floor's blocking
				// patterns for that service entirely.
				foreach ( array_keys( Pattern_Kinds::enforced() ) as $bucket ) {
					$declared = $declared || ! empty( $patterns[ $bucket ] );
				}

				$entry['patterns'] = $declared
					? self::reclassify_patterns( $patterns, (array) $base[ $slug ]['patterns'] )
					: $base[ $slug ]['patterns'];
			}

			// A floor like patterns: a remote that has never heard of the key must
			// not revoke the claim, but an explicit remote value still retires it.
			if ( is_array( $entry ) && ! array_key_exists( 'cookieless', $entry ) && ! empty( $base[ $slug ]['cookieless'] ) ) {
				$entry['cookieless'] = true;
			}

			// Same floor, same reason: a transfer disclosure narrows what the
			// visitor is told is safe, so a remote silent about it must not be
			// able to delete one. An explicit empty array still retires it.
			if ( is_array( $entry ) && ! array_key_exists( 'transfer_countries', $entry ) && ! empty( $base[ $slug ]['transfer_countries'] ) ) {
				$entry['transfer_countries'] = $base[ $slug ]['transfer_countries'];
			}

			// The mirror of that, because releasing a resource from consent gating is
			// a one-way compliance decision: the floor CAPS this one, so a remote may
			// revoke the release but never grant one the bundled catalog withholds.
			if ( is_array( $entry ) && ( isset( $entry['gcm_compatible'] ) || ! empty( $base[ $slug ]['gcm_compatible'] ) ) ) {
				$entry['gcm_compatible'] = ! empty( $base[ $slug ]['gcm_compatible'] ) && ( $entry['gcm_compatible'] ?? true );
			}

			$base[ $slug ] = $entry;
		}

		return $base;
	}

	/**
	 * Move a remote pattern into the bucket the bundled floor files it under.
	 *
	 * The remote catalog still publishes every pattern as a script, so a
	 * stylesheet or image host would come back as one and the admin would again
	 * report it as blocked by a pass that cannot see a `<link>` or an `<img>`.
	 * The remote stays free to add and retire patterns; only where it repeats a
	 * pattern this plugin has already classified does the floor win.
	 *
	 * A pattern is moved only when the floor files it in exactly ONE bucket and
	 * the remote put it somewhere else. Nine bundled services deliberately
	 * declare the same host under both `scripts` and `iframes` (reCAPTCHA,
	 * Wistia, Stripe and the video players): with a single-valued home the
	 * later bucket won and every remote copy was routed to it, emptying the
	 * other array. Pooling means blocking survived that, but the counts the
	 * admin screens read did not.
	 *
	 * @param array<string, mixed> $remote  Remote patterns, by bucket.
	 * @param array<string, mixed> $bundled Bundled patterns, by bucket.
	 * @since 1.5.0
	 * @return array<string, array<int, string>>
	 */
	private static function reclassify_patterns( array $remote, array $bundled ): array {
		$home = [];

		foreach ( Pattern_Kinds::buckets() as $bucket ) {
			foreach ( (array) ( $bundled[ $bucket ] ?? [] ) as $pattern ) {
				$home[ strtolower( trim( (string) $pattern ) ) ][ $bucket ] = true;
			}
		}

		$merged = array_fill_keys( Pattern_Kinds::buckets(), [] );

		foreach ( Pattern_Kinds::buckets() as $bucket ) {
			foreach ( (array) ( $remote[ $bucket ] ?? [] ) as $pattern ) {
				$pattern = (string) $pattern;
				$buckets = $home[ strtolower( trim( $pattern ) ) ] ?? [];

				// Unknown to the floor, or filed there under this same bucket:
				// leave it where the remote put it. Only an unambiguous
				// disagreement relocates, so a floor entry that spans buckets
				// never collapses the remote's placement into one of them.
				$target = count( $buckets ) === 1 && ! isset( $buckets[ $bucket ] )
					? (string) array_key_first( $buckets )
					: $bucket;

				$merged[ $target ][] = $pattern;
			}
		}

		return array_map(
			static fn( array $patterns ): array => array_values( array_unique( $patterns ) ),
			$merged
		);
	}

	/**
	 * Retrieve the catalog from the file cache.
	 *
	 * @since 1.3.0
	 * @return array<string, array<string, mixed>>|null
	 */
	private function get_file_cache(): ?array {
		$cache_raw = Cache::get_file( self::CACHE_FILE );

		if ( ! is_string( $cache_raw ) || $cache_raw === '' ) {
			return null;
		}

		$decoded = json_decode( $cache_raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return null;
		}

		if ( ! isset( $decoded['timestamp'] ) || ! is_int( $decoded['timestamp'] ) ) {
			return null;
		}

		if ( time() - $decoded['timestamp'] > self::FILE_CACHE_DURATION ) {
			return null;
		}

		return $decoded['data'];
	}

	/**
	 * Store the catalog in the file cache.
	 *
	 * @param array<string, array<string, mixed>> $catalog Catalog data.
	 * @since 1.3.0
	 * @return void
	 */
	private function set_file_cache( array $catalog ): void {
		$payload = wp_json_encode(
			[
				'timestamp' => time(),
				'data'      => $catalog,
			]
		);

		if ( ! is_string( $payload ) ) {
			return;
		}

		if ( ! Cache::store_file( self::CACHE_FILE, $payload ) ) {
			Logger::get_instance()->log( 'Unable to write services file cache.' );
		}
	}

	/**
	 * Fetch and validate the remote unified catalog.
	 *
	 * @since 1.3.0
	 * @return array<string, array<string, mixed>>|null Validated catalog, or null on failure.
	 */
	private function fetch_from_api(): ?array {
		$url = Helper::get_agent_app_url() . self::REMOTE_FILE_PATH;

		if ( ! Dataset_Validator::is_allowed_url( $url ) ) {
			Logger::get_instance()->log( 'Services API URL not allowed: ' . $url );
			return null;
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 10,
				'headers' => [
					'Accept' => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			Logger::get_instance()->log( 'Services API request failed: ' . $response->get_error_message() );
			return null;
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code !== 200 ) {
			Logger::get_instance()->log( 'Services API returned status: ' . $response_code );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			Logger::get_instance()->log( 'Services API returned invalid JSON.' );
			return null;
		}

		return Dataset_Validator::validate_services( $data );
	}
}
