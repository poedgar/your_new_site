<?php
/**
 * IpManager.
 *
 * @package SureCookie\Inc\Traits;
 * @since 0.0.1
 */

namespace SureCookie\Inc\Traits;

use SureCookie\Inc\Functions\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * IpManager
 *
 * @since 0.0.1
 */
trait IpManager {
	/**
	 * Static cache for country data to avoid duplicate API calls
	 *
	 * @var array<string, array{code: string, name: string, region: string, region_name: string}>|null
	 */
	private static ?array $country_cache = null;

	/**
	 * Get country from IP address using Laravel MaxMind API
	 *
	 * @param string $ip The IP address to get country for.
	 * @return string The country name (like 'India', 'United States', 'France').
	 * @since 0.0.1
	 */
	public static function get_country_from_ip( string $ip ): string {
		$data = self::get_country_data( $ip );
		return $data['code'];
	}

	/**
	 * Get country name from IP address for consent logs and display
	 *
	 * @param string $ip The IP address to get country for.
	 * @return string The country name (like 'India', 'United States', 'France').
	 * @since 0.0.1
	 */
	public static function get_country_name_from_ip( string $ip ): string {
		$data = self::get_country_data( $ip );
		return $data['name'];
	}

	/**
	 * Get the ISO 3166-2 subdivision (US state) from an IP address.
	 *
	 * Empty when the agent has not been updated to return regions, when the
	 * country has no subdivisions, or when the IP is unresolvable. Callers must
	 * treat empty as "unknown" and fall back to the country rule.
	 *
	 * @param string $ip The IP address to resolve.
	 * @since 1.5.0
	 * @return string Subdivision code such as 'CA', or an empty string.
	 */
	public static function get_region_from_ip( string $ip ): string {
		$data = self::get_country_data( $ip );
		return is_string( $data['region'] ?? null ) ? $data['region'] : '';
	}

	/**
	 * Get the region display name (state) from an IP address.
	 *
	 * Empty under the same conditions as the code: older agent, no
	 * subdivision, unresolvable IP.
	 *
	 * @param string $ip The IP address to resolve.
	 * @since 1.5.0
	 * @return string Region name such as 'California', or an empty string.
	 */
	public static function get_region_name_from_ip( string $ip ): string {
		$data = self::get_country_data( $ip );
		return is_string( $data['region_name'] ?? null ) ? $data['region_name'] : '';
	}

	/**
	 * Anonymize an IP via WordPress core's privacy function.
	 *
	 * Zeros the last IPv4 octet or last 64 IPv6 bits, handles dual-stack, and
	 * returns safe defaults for malformed IPs.
	 *
	 * @param string $ip The IP address to anonymize.
	 * @since 0.0.1
	 * @return string The anonymized IP address.
	 */
	public static function anonymize_ip( string $ip ): string {
		return wp_privacy_anonymize_ip( $ip );
	}

	/**
	 * Resolve the visitor-side pair to record against a consent decision.
	 *
	 * The one place that decides whether an IP is processed at all, so the log
	 * table, CSV and PDF export, consent forwarding and the personal-data
	 * exporter all read a consistent value with no branch of their own. "Origin"
	 * here is the visitor, unrelated to the `origin_site` forwarding column.
	 *
	 * The gate is checked before anything is derived from the address, so an
	 * opted-out site issues no geolocation request.
	 *
	 * @param string $ip The raw client IP.
	 * @since 1.5.0
	 * @return array{ip: string, country: string, region: string} Anonymized IP, country and region name, or the redaction marker for all three.
	 */
	public static function consent_log_origin( string $ip ): array {
		if ( ! Helper::consent_log_ip_enabled() ) {
			return [
				'ip'      => Helper::REDACTED,
				'country' => Helper::REDACTED,
				'region'  => Helper::REDACTED,
			];
		}

		return [
			// Country first: it needs the raw address, which anonymizing destroys.
			'country' => self::get_country_name_from_ip( $ip ),
			// Region is derived from the same address, so it belongs behind the
			// same gate; both read the one cached lookup.
			'region'  => self::get_region_name_from_ip( $ip ),
			'ip'      => self::anonymize_ip( $ip ),
		];
	}

	/**
	 * Which address the plugin ends up treating as the visitor.
	 *
	 * Asks `get_client_ip()` what it resolved rather than re-deriving its
	 * decision, so the answer cannot disagree with the address the rest of the
	 * plugin uses. Re-deriving would report `visitor` for a site that enabled
	 * proxy trust but named a header its proxy never sets, which is the state
	 * most likely to follow the advice this powers.
	 *
	 * `cdn` and `local` mean a proxy's own address is standing in for the
	 * visitor's: geo targeting resolves the proxy's country, consent logs store
	 * it, and the per-IP rate limits collapse into one bucket. Membership is
	 * tested against the filtered trusted-proxy set, so an operator who
	 * declared their own CDN through `surecookie_trusted_proxy_ips` is covered;
	 * the Cloudflare list only picks which remediation to offer.
	 *
	 * A private or loopback peer reports `local` even on an intranet where it
	 * IS the visitor. That matches `is_usable_client_ip()`, which already
	 * refuses those ranges as infrastructure rather than a visitor.
	 *
	 * @since 1.5.1
	 * @return string One of `visitor`, `cdn`, `local`.
	 */
	public static function visitor_ip_source(): string {
		$resolved = self::get_client_ip();

		// Anything the trait already refuses to store as a client IP is
		// infrastructure rather than a visitor, whatever the trust settings say:
		// a proxy on this host or network.
		if ( ! self::is_usable_client_ip( $resolved ) ) {
			return 'local';
		}

		// A routable address known to be a proxy edge. The union matters in both
		// directions: the trusted set alone misses a Cloudflare-fronted site
		// whose operator NARROWED `surecookie_trusted_proxy_ips`, and the
		// Cloudflare list alone misses every CDN declared through that filter.
		$edges = array_merge( self::get_trusted_proxy_ranges(), self::cloudflare_proxy_ranges() );

		return self::ip_in_ranges( $resolved, $edges ) ? 'cdn' : 'visitor';
	}

	/**
	 * Infrastructure ranges trusted as reverse proxies by default.
	 *
	 * Loopback plus RFC 1918 / RFC 4193 / link-local: a REMOTE_ADDR in these came
	 * from the site's own stack and an external attacker cannot spoof it. A method
	 * not a constant because trait constants need PHP 8.2 and we support 7.4.
	 *
	 * @since 1.3.0
	 * @return array<int, string>
	 */
	private static function local_proxy_ranges(): array {
		return [
			'127.0.0.0/8',
			'10.0.0.0/8',
			'172.16.0.0/12',
			'192.168.0.0/16',
			'169.254.0.0/16',
			'::1/128',
			'fc00::/7',
			'fe80::/10',
		];
	}

	/**
	 * Cloudflare edge ranges, trusted as reverse proxies by default.
	 *
	 * Ships so "site behind Cloudflare" resolves real visitor IPs out of the box;
	 * refresh from https://www.cloudflare.com/ips/ when it changes. Other CDNs:
	 * add ranges via `surecookie_trusted_proxy_ips`.
	 *
	 * @since 1.3.0
	 * @return array<int, string>
	 */
	private static function cloudflare_proxy_ranges(): array {
		return [
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		];
	}

	/**
	 * Get country data from IP address - returns code, name and region with caching
	 *
	 * @param string $ip The IP address to get country for.
	 * @return array{code: string, name: string, region: string, region_name: string} Country and subdivision data; the region pair is empty when unresolved.
	 * @since 0.0.1
	 */
	private static function get_country_data( string $ip ): array {
		// Return in-memory cache if available (same PHP request only).
		if ( isset( self::$country_cache[ $ip ] ) ) {
			return self::$country_cache[ $ip ];
		}

		// Check persistent geo cache (single transient array - only 2 rows in wp_options, capped at 500 entries).
		// Key is versioned: entries written before region support carry no region
		// and would read as "region unknown" until they expire.
		$geo_cache = get_transient( 'surecookie_geo_cache_v2' );
		if ( ! is_array( $geo_cache ) ) {
			$geo_cache = [];
		}

		// Use a salted hash as the cache key to avoid storing raw IPs in the DB (GDPR / privacy compliance).
		$ip_hash = self::hash_ip( $ip );

		if ( isset( $geo_cache[ $ip_hash ] ) ) {
			self::$country_cache[ $ip ] = $geo_cache[ $ip_hash ];
			return $geo_cache[ $ip_hash ];
		}

		// Return Localhost for local IPs.
		if ( self::is_local_ip( $ip ) ) {
			$data                       = [
				'code'        => 'Localhost',
				'name'        => 'Localhost',
				'region'      => '',
				'region_name' => '',
			];
			self::$country_cache[ $ip ] = $data;
			return $data;
		}

		// Return special codes for private IPs.
		if ( self::is_private_ip( $ip ) ) {
			$data                       = [
				'code'        => 'XX',
				'name'        => 'Private Network',
				'region'      => '',
				'region_name' => '',
			];
			self::$country_cache[ $ip ] = $data;
			return $data;
		}

		// Never translated: Pro matches this against Manager::UNRESOLVABLE_CODES.
		// Carries the region keys so every return path has the same four.
		$unknown = [
			'code'        => 'Unknown',
			'name'        => 'Unknown',
			'region'      => '',
			'region_name' => '',
		];

		// A cache miss must never become an unbounded wait. This runs on page
		// render (Pro's geo gating) and inside the consent write, so a slow or
		// unreachable API otherwise holds one PHP worker per visitor.
		if ( self::geo_api_unavailable() ) {
			self::$country_cache[ $ip ] = $unknown;
			return $unknown;
		}

		// Raw IP for geolocation accuracy (anonymized .0 may not resolve); sent
		// transiently to SureCookie's own Agent API, never stored (storage layer anonymizes).
		$api_url = Helper::get_agent_app_url() . 'api/geolocation/country';
		$url     = add_query_arg( 'ip', $ip, $api_url );

		/**
		 * Filter: seconds to wait for the geolocation API.
		 *
		 * Deliberately short: the request sits on the visitor's page render and
		 * on the consent write, so whatever you raise this to is added TTFB.
		 *
		 * @since 1.5.0
		 * @param int $timeout Timeout in seconds. Default 2.
		 */
		$timeout = apply_filters( 'surecookie_geolocation_timeout', 2 );
		$timeout = is_numeric( $timeout ) ? (int) $timeout : 2;

		$response = wp_remote_get( $url, [ 'timeout' => max( 1, $timeout ) ] );

		// Only a readable geolocation payload counts as the API being up. A
		// transport error, a non-200 and a 200 carrying an interstitial or an
		// error envelope are the same event: no country, and one more strike.
		$response_data = self::parse_geo_payload( $response );
		self::record_geo_outcome( $response_data !== null );

		if ( $response_data === null ) {
			self::$country_cache[ $ip ] = $unknown;
			return $unknown;
		}

		// The parse guarantees country_code; the name can still be absent.
		$country_code = $response_data['country_code'];
		$country_name = $response_data['country_name'] ?? $country_code;

		// An unresolved country arrives as '' from the current agent and as
		// 'unknown' from older builds and their cached payloads; collapse every
		// variant onto the one 'Unknown' sentinel so no consumer ever compares
		// against the wrong casing (surecookie-saas#223). Never translate it:
		// Pro matches it against Manager::UNRESOLVABLE_CODES.
		if ( ! is_string( $country_code ) || $country_code === '' || strtolower( $country_code ) === 'unknown' ) {
			$country_code = 'Unknown';
			$country_name = 'Unknown';
		}

		$data = [
			'code'        => $country_code,
			'name'        => $country_name,
			'region'      => is_string( $response_data['region_code'] ?? null ) ? $response_data['region_code'] : '',
			'region_name' => is_string( $response_data['region_name'] ?? null ) ? $response_data['region_name'] : '',
		];

		// Cache in memory for this request.
		self::$country_cache[ $ip ] = $data;

		// Persist to single transient (cap at 500 entries to prevent unbounded growth).
		$geo_cache[ $ip_hash ] = $data;
		if ( count( $geo_cache ) > 500 ) {
			$geo_cache = array_slice( $geo_cache, -250, null, true );
		}
		set_transient( 'surecookie_geo_cache_v2', $geo_cache, DAY_IN_SECONDS );

		return $data;
	}

	/**
	 * Decode a geolocation response, or null when the provider did not answer
	 * with a geolocation payload at all.
	 *
	 * The discriminator is the PRESENCE of `country_code`, never its value: an
	 * IP the provider cannot place comes back as `''` (or `'unknown'` from older
	 * builds), which is an answer and must keep the breaker closed.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed>|\WP_Error $response Raw wp_remote_get() result.
	 * @return array<string, mixed>|null
	 */
	private static function parse_geo_payload( $response ): ?array {
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) && array_key_exists( 'country_code', $data ) ? $data : null;
	}

	/**
	 * Transient holding geolocation circuit-breaker state.
	 *
	 * A method not a constant because trait constants need PHP 8.2 and we
	 * support 7.4, same as local_proxy_ranges().
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private static function geo_breaker_key(): string {
		return 'surecookie_geo_breaker';
	}

	/**
	 * Whether the geolocation API is inside a failure cooldown.
	 *
	 * Caching failures per IP cannot bound an outage - visitors are unique, so
	 * every one still pays a lookup. Tripping site-wide is what caps the cost at
	 * one probe per window instead of one per visitor.
	 *
	 * @since 1.5.0
	 * @return bool
	 */
	private static function geo_api_unavailable(): bool {
		$state = get_transient( self::geo_breaker_key() );

		return is_array( $state ) && (int) ( $state['until'] ?? 0 ) > time();
	}

	/**
	 * Record a geolocation request outcome, opening the circuit after three
	 * failures inside one cooldown window.
	 *
	 * The transient expiry is the accumulation window, so sparse failures lapse
	 * before they can add up and only a sustained outage trips it. Twice the
	 * cooldown because the state must outlive the pause it schedules, or the
	 * half-open probe finds a clean slate and a dead API leaks three fresh
	 * calls every window.
	 *
	 * @since 1.5.0
	 * @param bool $succeeded Whether the request returned a usable response.
	 * @return void
	 */
	private static function record_geo_outcome( bool $succeeded ): void {
		if ( $succeeded ) {
			delete_transient( self::geo_breaker_key() );
			return;
		}

		$state    = get_transient( self::geo_breaker_key() );
		$failures = is_array( $state ) ? (int) ( $state['failures'] ?? 0 ) + 1 : 1;
		$cooldown = 5 * MINUTE_IN_SECONDS;

		set_transient(
			self::geo_breaker_key(),
			[
				'failures' => $failures,
				'until'    => $failures >= 3 ? time() + $cooldown : 0,
			],
			$cooldown * 2
		);
	}

	/**
	 * Hash an IP address for use as a privacy-safe cache key.
	 *
	 * Uses wp_hash() (HMAC with site-specific AUTH_KEY salt) so the hash
	 * cannot be reversed via rainbow tables, unlike plain md5/sha256.
	 *
	 * @param string $ip The IP address to hash.
	 * @return string A salted, irreversible hash of the IP.
	 * @since 0.0.1
	 */
	private static function hash_ip( string $ip ): string {
		return wp_hash( $ip );
	}

	/**
	 * Check if IP is localhost.
	 *
	 * @param string $ip IP address to check.
	 * @return bool True if private/localhost.
	 * @since 0.0.1
	 */
	private static function is_local_ip( string $ip ): bool {
		if ( $ip === '127.0.0.1' || $ip === '::1' ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if IP is private.
	 *
	 * @param string $ip IP address to check.
	 * @return bool True if private/localhost.
	 * @since 0.0.1
	 */
	private static function is_private_ip( string $ip ): bool {
		return strpos( $ip, '192.168.' ) === 0
			|| strpos( $ip, '10.' ) === 0
			|| ( strpos( $ip, '172.' ) === 0 && (bool) preg_match( '/^172\.(1[6-9]|2[0-9]|3[01])\./', $ip ) );
	}

	/**
	 * Get client's IP address.
	 *
	 * REMOTE_ADDR by default (cannot be spoofed). Forwarded headers are trusted
	 * only when proxy trust is on AND the peer is in the trusted-proxy set, since
	 * otherwise they are attacker-supplied. The `surecookie_trusted_client_ip_headers`
	 * filter picks the header (default X-Forwarded-For, right-to-left walk);
	 * resolution commits to the first present header, never falling through.
	 *
	 * @since 0.0.1
	 * @return string Client IP address.
	 */
	private static function get_client_ip(): string {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '127.0.0.1';

		// Normalize first: a dual-stack peer can arrive IPv4-mapped
		// (::ffff:127.0.0.1) and must fold to 127.0.0.1 to match IPv4 proxy ranges,
		// else a loopback proxy reads as untrusted and all visitors share one bucket.
		$remote_addr = self::normalize_ip( $remote_addr );
		if ( $remote_addr === '' ) {
			$remote_addr = '127.0.0.1';
		}

		/**
		 * Filter: trust proxy headers (X-Forwarded-For) for the client IP.
		 *
		 * On by default: the peer gate below is the spoofing guard, not this flag.
		 * Off, every CDN-fronted site geolocated its CDN and fell back site-wide.
		 *
		 * @param bool $trust Default true.
		 * @since 0.0.1
		 */
		$trust_proxy = (bool) apply_filters( 'surecookie_trust_proxy_headers', true );

		if ( ! $trust_proxy ) {
			return $remote_addr;
		}

		$trusted_proxies = self::get_trusted_proxy_ranges();

		// The peer must be a recognised proxy before its forwarded headers are
		// believed; otherwise an attacker reaching the origin directly (leaked IP,
		// unproxied hostname) could forge them to pick their rate-limit bucket and geo.
		if ( ! self::ip_in_ranges( $remote_addr, $trusted_proxies ) ) {
			/**
			 * Fires when proxy trust is on but the request came from outside the
			 * trusted-proxy set, so forwarded headers were ignored.
			 *
			 * Surfaces this misconfiguration (all visitors sharing one rate-limit
			 * bucket) instead of leaving it to be diagnosed from dropped consent logs.
			 *
			 * @since 1.3.0
			 * @param string $remote_addr The untrusted peer address that was used instead.
			 */
			do_action( 'surecookie_untrusted_proxy_peer', $remote_addr );

			return $remote_addr;
		}

		/**
		 * Filter: forwarded headers to trust for the client IP, most-trusted first.
		 *
		 * Only consulted once proxy trust is on and the peer is trusted. Defaults to
		 * X-Forwarded-For only (append-only right-to-left walk, see
		 * client_ip_from_forwarded_for()); CF-Connecting-IP and X-Real-IP are
		 * deliberately NOT trusted by default.
		 *
		 * Rules for operators:
		 * - Resolution commits to the FIRST present header (no fall-through), so a
		 *   spoofed value collapses to REMOTE_ADDR instead of being steered onto a
		 *   weaker header. List exactly one unless you have reason for more.
		 * - Only list a header your proxy OVERWRITES; a pass-through header re-opens
		 *   IP spoofing and the per-IP rate-limit bypass this guard closes.
		 * - nginx -> php-fpm (fastcgi_pass): `proxy_set_header` does NOT apply to
		 *   FastCGI, so set `fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;`
		 *   or use ngx_http_realip_module and leave proxy trust off.
		 * - Only X-Forwarded-For is chain-walked; others are read as one bare IP. The
		 *   RFC 7239 `Forwarded` header is not parsed, do not list it. Empty array
		 *   trusts the peer gate only and returns REMOTE_ADDR.
		 *
		 * @since 1.3.0
		 * @param array<int, string> $headers Ordered header names. Default [ 'X-Forwarded-For' ].
		 */
		$headers = apply_filters( 'surecookie_trusted_client_ip_headers', [ 'X-Forwarded-For' ] );
		$headers = is_array( $headers ) ? $headers : [ 'X-Forwarded-For' ];

		foreach ( $headers as $name ) {
			if ( ! is_string( $name ) || trim( $name ) === '' ) {
				continue;
			}

			$key = self::header_to_server_key( $name );

			// Header absent: try the next declared header.
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			// Only X-Forwarded-For is an append-only chain; everything else is a
			// single bare address the proxy set.
			if ( $key === 'HTTP_X_FORWARDED_FOR' ) {
				$candidate = self::client_ip_from_forwarded_for( $trusted_proxies );
			} else {
				$candidate = self::normalize_ip( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );

				if ( ! self::is_usable_client_ip( $candidate ) ) {
					$candidate = '';
				}
			}

			// Commit on the first header that is present - no fall-through to a
			// later, weaker header.
			return $candidate !== '' ? $candidate : $remote_addr;
		}

		return $remote_addr;
	}

	/**
	 * Canonicalize a header name to its $_SERVER key.
	 *
	 * 'X-Forwarded-For' -> 'HTTP_X_FORWARDED_FOR'. The name comes from operator
	 * config (surecookie_trusted_client_ip_headers), never request data, so any
	 * value is safe: at worst it names a header that is never present.
	 *
	 * @since 1.3.0
	 * @param string $name Header name, with or without the HTTP_ prefix.
	 * @return string The corresponding $_SERVER key.
	 */
	private static function header_to_server_key( string $name ): string {
		$key = strtoupper( str_replace( '-', '_', trim( $name ) ) );

		if ( strpos( $key, 'HTTP_' ) !== 0 ) {
			$key = 'HTTP_' . $key;
		}

		return $key;
	}

	/**
	 * Resolve the client address from X-Forwarded-For.
	 *
	 * XFF is append-only: each hop adds the address it received from, so the left
	 * end is client-supplied and the right end is what our infra appended. Hence
	 * the client is found by walking from the right, not the left.
	 *
	 * @since 1.3.0
	 * @param array<int, string> $trusted_proxies Trusted proxy addresses / CIDR ranges.
	 * @return string Client IP, or an empty string when none could be trusted.
	 */
	private static function client_ip_from_forwarded_for( array $trusted_proxies ): string {
		if ( empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			return '';
		}

		$chain = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );

		/**
		 * Filter: number of trusted proxy hops in front of this site.
		 *
		 * For stacks whose proxy addresses cannot be enumerated (rotating egress IPs,
		 * a host-managed balancer). Set it to how many of our own proxies append to
		 * the header: each one appends the address it received from, so N of them
		 * leave the client at `count - N` and our own peer is REMOTE_ADDR, never a
		 * chain entry. 1 therefore means the last entry is the client our single
		 * proxy wrote - not the proxy itself.
		 * Default 0 keeps the address-based walk, preferred when known.
		 *
		 * @since 1.3.0
		 * @param int $hops Trusted hop count. Default 0 (disabled).
		 */
		$hops = (int) apply_filters( 'surecookie_trusted_proxy_hops', 0 );

		if ( $hops > 0 ) {
			$index     = count( $chain ) - $hops;
			$candidate = isset( $chain[ $index ] ) ? self::normalize_ip( $chain[ $index ] ) : '';

			return self::is_usable_client_ip( $candidate ) ? $candidate : '';
		}

		for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
			$candidate = self::normalize_ip( $chain[ $i ] );

			// Unparseable entry at the trust boundary: stop rather than walk further
			// left into client-supplied territory.
			if ( $candidate === '' ) {
				return '';
			}

			// Our own hop, keep walking left past it.
			if ( self::ip_in_ranges( $candidate, $trusted_proxies ) ) {
				continue;
			}

			// First address beyond our proxies: the furthest hop we can vouch for,
			// so it is the answer whether or not it validates (all left of it is spoofable).
			return self::is_usable_client_ip( $candidate ) ? $candidate : '';
		}

		return '';
	}

	/**
	 * Trusted reverse-proxy addresses and CIDR ranges.
	 *
	 * @since 1.3.0
	 * @return array<int, string>
	 */
	private static function get_trusted_proxy_ranges(): array {
		$ranges = array_merge( self::local_proxy_ranges(), self::cloudflare_proxy_ranges() );

		/**
		 * Filter: reverse proxies whose forwarded headers may be trusted.
		 *
		 * Accepts plain IPv4/IPv6 addresses and CIDR ranges. Defaults to loopback,
		 * the private ranges, and Cloudflare's published edges. Replace the array
		 * to restrict trust to just your own proxy, or append your CDN's ranges.
		 *
		 * @since 1.3.0
		 * @param array<int, string> $ranges Trusted proxy addresses / CIDR ranges.
		 */
		$ranges = apply_filters( 'surecookie_trusted_proxy_ips', $ranges );

		if ( ! is_array( $ranges ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map( 'strval', $ranges ),
				static function ( $range ) {
					return trim( $range ) !== '';
				}
			)
		);
	}

	/**
	 * Whether an address is usable as a stored client IP.
	 *
	 * Private and reserved ranges are rejected: they identify infrastructure
	 * rather than a visitor, and would not geolocate.
	 *
	 * @since 1.3.0
	 * @param string $ip Candidate address.
	 * @return bool
	 */
	private static function is_usable_client_ip( string $ip ): bool {
		return $ip !== '' && (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Normalize a forwarded address into a bare, comparable IP.
	 *
	 * Strips surrounding whitespace, an optional port, IPv6 brackets, and folds
	 * IPv4-mapped IPv6 (`::ffff:203.0.113.1`) down to its IPv4 form so it can be
	 * matched against IPv4 ranges on dual-stack servers.
	 *
	 * @since 1.3.0
	 * @param string $value Raw chain entry or header value.
	 * @return string Normalized IP, or an empty string when not a valid address.
	 */
	private static function normalize_ip( string $value ): string {
		$value = trim( $value );

		if ( $value === '' ) {
			return '';
		}

		if ( strpos( $value, '[' ) === 0 ) {
			// Bracketed IPv6 literal, optionally with a port: [2001:db8::1]:443.
			$close = strpos( $value, ']' );

			if ( $close === false ) {
				return '';
			}

			// Only an optional :port may follow the bracket; reject anything else
			// as malformed rather than silently accepting the literal before it.
			$rest = substr( $value, $close + 1 );
			if ( $rest !== '' && strpos( $rest, ':' ) !== 0 ) {
				return '';
			}

			$value = substr( $value, 1, $close - 1 );
		} elseif ( substr_count( $value, ':' ) === 1 ) {
			// Exactly one colon means IPv4 with a port; bare IPv6 always has more.
			$value = substr( $value, 0, (int) strpos( $value, ':' ) );
		}

		if ( ! filter_var( $value, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		if ( stripos( $value, '::ffff:' ) === 0 ) {
			$mapped = substr( $value, 7 );

			if ( filter_var( $mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return $mapped;
			}
		}

		return $value;
	}

	/**
	 * Whether an address falls inside any of the given addresses / CIDR ranges.
	 *
	 * @since 1.3.0
	 * @param string             $ip     Address to test.
	 * @param array<int, string> $ranges Addresses and CIDR ranges.
	 * @return bool
	 */
	private static function ip_in_ranges( string $ip, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( self::ip_matches_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match an address against a single address or CIDR range.
	 *
	 * Compares packed bytes, so IPv4 and IPv6 are handled by the same code path;
	 * a mismatch in address family simply fails to match.
	 *
	 * @since 1.3.0
	 * @param string $ip    Address to test.
	 * @param string $range Address or CIDR range.
	 * @return bool
	 */
	private static function ip_matches_range( string $ip, string $range ): bool {
		$range = trim( $range );

		if ( $ip === '' || $range === '' ) {
			return false;
		}

		if ( strpos( $range, '/' ) === false ) {
			$packed_ip    = self::packed_ip( $ip );
			$packed_range = self::packed_ip( $range );

			return $packed_ip !== '' && $packed_ip === $packed_range;
		}

		[ $subnet, $prefix ] = explode( '/', $range, 2 );

		if ( ! is_numeric( $prefix ) ) {
			return false;
		}

		$packed_ip     = self::packed_ip( $ip );
		$packed_subnet = self::packed_ip( $subnet );

		// Empty means unparseable; differing lengths mean different address families.
		if ( $packed_ip === '' || $packed_subnet === '' || strlen( $packed_ip ) !== strlen( $packed_subnet ) ) {
			return false;
		}

		$prefix   = (int) $prefix;
		$max_bits = strlen( $packed_ip ) * 8;

		if ( $prefix < 0 || $prefix > $max_bits ) {
			return false;
		}

		$whole_bytes = intdiv( $prefix, 8 );
		$odd_bits    = $prefix % 8;

		if ( $whole_bytes > 0 && strncmp( $packed_ip, $packed_subnet, $whole_bytes ) !== 0 ) {
			return false;
		}

		if ( $odd_bits === 0 ) {
			return true;
		}

		// $odd_bits > 0 implies $prefix < $max_bits, so this byte always exists.
		$mask = chr( ( 0xFF << 8 - $odd_bits ) & 0xFF );

		return ( $packed_ip[ $whole_bytes ] & $mask ) === ( $packed_subnet[ $whole_bytes ] & $mask );
	}

	/**
	 * Pack an IP address into its binary representation.
	 *
	 * @since 1.3.0
	 * @param string $ip Address to pack.
	 * @return string Packed bytes, or an empty string when not a valid address.
	 */
	private static function packed_ip( string $ip ): string {
		$ip = trim( $ip );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$packed = inet_pton( $ip );

		return is_string( $packed ) ? $packed : '';
	}

	/**
	 * Check if the given URL is a localhost or development site.
	 *
	 * @param string $url The site URL to check.
	 * @since 0.0.1
	 * @return bool True if localhost, false otherwise.
	 */
	private static function is_local_url( string $url ): bool {
		// Allow local scanning via wp-config.php constant for development/testing.
		if ( defined( 'SURECOOKIE_ALLOW_LOCAL_SCAN' ) && SURECOOKIE_ALLOW_LOCAL_SCAN ) {
			return false;
		}

		if ( apply_filters( 'surecookie_bypass_local_url_check', false ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) || ! is_string( $host ) ) {
			return false;
		}

		// Normalize: lowercase and strip IPv6 brackets so "[::1]" matches "::1".
		$host = strtolower( trim( $host, '[]' ) );

		// IP-literal hosts are local only when loopback (127.0.0.0/8, ::1) or private;
		// anchored so "web10.example.com" and the like are not misclassified.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			// inet_pton() folds every IPv6 loopback form (::1, 0:0:0:0:0:0:0:1) to
			// the same packed bytes; 127.0.0.0/8 covers IPv4 loopback.
			return inet_pton( $host ) === inet_pton( '::1' )
				|| strpos( $host, '127.' ) === 0
				|| self::is_private_ip( $host );
		}

		// Hostnames: exactly "localhost", or a dev-only TLD suffix. Suffix-anchored
		// so "localhostingpros.com" and "shop.localfoods.com" are not misclassified.
		if ( $host === 'localhost' ) {
			return true;
		}

		foreach ( [ '.localhost', '.local' ] as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}
}
