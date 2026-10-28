<?php
/**
 * Turn raw browser findings into the shape the scan-results pipeline expects.
 *
 * Pure and static: no HTTP or session state, only one option read for the
 * existing-cookie index. It holds the rules that decide whether an assisted scan
 * improves the cookie inventory or quietly corrupts it.
 *
 * Two rules are load-bearing:
 *
 *  1. Signature reuse. {@see Sync::store_all_cookies_from_agent_app()} replaces
 *     strictly by `signature_id`, so a fresh id for a cookie the cloud scanner
 *     already recorded would store BOTH rows and print the cookie twice on the
 *     public policy.
 *  2. No-downgrade merge. Reusing the cloud row's id means the assisted row
 *     replaces it, so anything the browser cannot see (e.g. HttpOnly, invisible
 *     to `document.cookie`) must be inherited, not overwritten.
 *
 * @package SureCookie\Inc\Modules\AssistedScan
 * @since   1.3.0
 */

namespace SureCookie\Inc\Modules\AssistedScan;

use SureCookie\Inc\Functions\Cookie_Identity;
use SureCookie\Inc\Functions\Sanitize;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Normalizer
 *
 * @since 1.3.0
 */
class Normalizer {
	/**
	 * Marks a cookie row as collected by a browser rather than the cloud scanner.
	 *
	 * @since 1.3.0
	 */
	public const SOURCE = 'assisted';

	/**
	 * Prefix for the synthetic signature ids minted for genuinely new cookies.
	 *
	 * A stored id carrying this prefix is what lets a later cloud scan recognise
	 * the row as browser-collected and absorb it instead of duplicating it.
	 *
	 * @since 1.3.0
	 */
	public const SIGNATURE_PREFIX = 'assisted:';

	/**
	 * Cookie names a site's own server or CMS emits. Matched case-insensitively
	 * against the whole stack's session, CSRF and platform cookies.
	 *
	 * A name belongs here when every site that sets it sets its own: the scan
	 * API keys owner, vendor and set_via on the NAME and shares them across
	 * sites, so one bad approval names a stranger on every site at once.
	 *
	 * @since 1.7.0
	 */
	private const SITE_EMITTED_NAMES = '/^(phpsessid|xsrf-token|csrftoken|wordpress_|wp-settings|wp_consent_|wp_woocommerce_session_|woocommerce_|edd_|_lscache_vary)/i';

	/**
	 * Cookie name prefixes that are never collected.
	 *
	 * WordPress auth and admin-preference cookies. An assisted scan runs as a
	 * logged-in admin, so these are always present but never part of a visitor's
	 * consent. Dropped at both layers (here and in the browser) so they cannot
	 * reach the database even if the collector is bypassed.
	 *
	 * @since 1.3.0
	 */
	private const IGNORED_PREFIXES = [
		'wordpress_logged_in_',
		'wordpress_sec_',
		'wordpress_test_cookie',
		'wp-settings-',
		'wp-settings-time-',
	];

	/**
	 * Cookie name prefixes that are never collected, plus any added by filter.
	 *
	 * @since 1.3.0
	 * @return array<int, string> Lower-cased prefixes.
	 */
	public static function ignored_prefixes(): array {
		/**
		 * Filter the cookie-name prefixes an assisted scan refuses to collect.
		 *
		 * @since 1.3.0
		 * @param array<int, string> $prefixes Lower-cased cookie name prefixes.
		 */
		$prefixes = apply_filters( 'surecookie_assisted_scan_ignored_cookies', self::IGNORED_PREFIXES );

		if ( ! is_array( $prefixes ) ) {
			return self::IGNORED_PREFIXES;
		}

		$clean = [];
		foreach ( $prefixes as $prefix ) {
			if ( is_string( $prefix ) && trim( $prefix ) !== '' ) {
				$clean[] = strtolower( trim( $prefix ) );
			}
		}

		return $clean === [] ? self::IGNORED_PREFIXES : $clean;
	}

	/**
	 * Whether a cookie name must never be collected.
	 *
	 * @param string $name Cookie name.
	 * @since 1.3.0
	 * @return bool
	 */
	public static function is_ignored( string $name ): bool {
		$name = strtolower( trim( $name ) );

		if ( $name === '' ) {
			return true;
		}

		foreach ( self::ignored_prefixes() as $prefix ) {
			if ( strncmp( $name, $prefix, strlen( $prefix ) ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse the attribute half of a `document.cookie` assignment.
	 *
	 * The value is already stripped by the browser, so this never sees cookie
	 * contents. Per RFC 6265 `Max-Age` wins over `Expires`.
	 *
	 * `same_site` stays an empty string when no SameSite attribute was present,
	 * rather than the browser's `lax` default: `document.cookie` never reveals the
	 * effective SameSite, so defaulting would make an unobserved value look
	 * observed and let the no-downgrade merge overwrite a cloud `none` with `lax`.
	 *
	 * @param string $attributes Attribute string, e.g. `path=/; Max-Age=63072000; Secure`.
	 * @param int    $now        Reference timestamp, for deterministic tests.
	 * @since 1.3.0
	 * @return array{expires_at: string|null, is_deletion: bool, domain: string, path: string, secure: bool, same_site: string}
	 */
	public static function parse_attributes( string $attributes, int $now = 0 ): array {
		$now = $now > 0 ? $now : time();

		$parsed = [
			'expires_at'  => null,
			'is_deletion' => false,
			'domain'      => '',
			'path'        => '/',
			'secure'      => false,
			'same_site'   => '',
		];

		$max_age = null;
		$expires = null;

		foreach ( explode( ';', $attributes ) as $part ) {
			$part = trim( $part );

			if ( $part === '' ) {
				continue;
			}

			$split = explode( '=', $part, 2 );
			$key   = strtolower( trim( $split[0] ) );
			$value = isset( $split[1] ) ? trim( $split[1] ) : '';

			switch ( $key ) {
				case 'max-age':
					// Only a well-formed integer counts; anything else is ignored
					// so a malformed Max-Age falls through to Expires.
					if ( preg_match( '/^-?\d+$/', $value ) === 1 ) {
						$max_age = (int) $value;
					}
					break;
				case 'expires':
					$timestamp = strtotime( $value );
					if ( $timestamp !== false ) {
						$expires = $timestamp;
					}
					break;
				case 'domain':
					$parsed['domain'] = strtolower( $value );
					break;
				case 'path':
					$parsed['path'] = $value !== '' ? $value : '/';
					break;
				case 'secure':
					$parsed['secure'] = true;
					break;
				case 'samesite':
					$same_site = strtolower( $value );
					if ( in_array( $same_site, [ 'lax', 'strict', 'none' ], true ) ) {
						$parsed['same_site'] = $same_site;
					}
					break;
			}
		}

		// Max-Age takes precedence over Expires (RFC 6265 section 4.1.2.2).
		$absolute = null;
		if ( $max_age !== null ) {
			$absolute = $now + $max_age;
		} elseif ( $expires !== null ) {
			$absolute = $expires;
		}

		if ( $absolute === null ) {
			// Neither attribute: a session cookie. Real, but with no lifetime.
			return $parsed;
		}

		if ( $absolute <= $now ) {
			// A past expiry is how JavaScript deletes a cookie. Recording it
			// would invent a cookie the visitor never actually carries.
			$parsed['is_deletion'] = true;
			return $parsed;
		}

		$parsed['expires_at'] = gmdate( 'c', $absolute );

		return $parsed;
	}

	/**
	 * Index the currently stored scanned cookies by their identity key.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $stored Stored cookies grouped by category.
	 * @since 1.3.0
	 * @return array<string, array<string, mixed>> Identity key => stored row.
	 */
	public static function build_existing_index( array $stored ): array {
		$index = [];

		foreach ( $stored as $cookies ) {
			if ( ! is_array( $cookies ) ) {
				continue;
			}

			foreach ( $cookies as $cookie ) {
				if ( ! is_array( $cookie ) || empty( $cookie['name'] ) ) {
					continue;
				}

				$key = Cookie_Identity::key_for( $cookie );

				// First write wins, so a duplicate left by an older build cannot
				// shadow the row that is actually being matched against.
				if ( ! isset( $index[ $key ] ) ) {
					$index[ $key ] = $cookie;
				}

				// A first-party cookie can be recorded against more than one label
				// of this host, so it also gets a domain-less key that
				// {@see self::find_existing()} falls back to. Two cases, both of
				// which would otherwise store the cookie twice: a JS tag deriving
				// the registrable domain (`_ga` on `.example.com` from
				// shop.example.com), and a host-only cookie the cloud scanner
				// records as `www.example.com` while an assisted observation
				// resolves it to `example.com`. FIRST_PARTY_FLAG alone misses both:
				// it is set only by the catalog paths, never by
				// Sync::transform_cookie_data() on a scanned row, so match on the
				// domain actually being first party instead.
				if ( Cookie_Identity::is_first_party( $cookie )
					|| self::is_first_party_host( Cookie_Identity::normalize_domain( (string) ( $cookie['domain'] ?? '' ) ) ) ) {
					$name_key = Cookie_Identity::name_key( (string) $cookie['name'] );
					if ( ! isset( $index[ $name_key ] ) ) {
						$index[ $name_key ] = $cookie;
					}
				}
			}
		}

		return $index;
	}

	/**
	 * Find the stored row an incoming cookie corresponds to, if any.
	 *
	 * @param array<string, mixed>                $cookie Normalized incoming cookie.
	 * @param array<string, array<string, mixed>> $index  Output of {@see self::build_existing_index()}.
	 * @since 1.3.0
	 * @return array<string, mixed>|null
	 */
	public static function find_existing( array $cookie, array $index ): ?array {
		$key = Cookie_Identity::key_for( $cookie );

		if ( isset( $index[ $key ] ) ) {
			return $index[ $key ];
		}

		// Fall back to the domain-less key so a first-party row recorded on
		// another label of this host is still recognised as the same cookie.
		$name_key = Cookie_Identity::name_key( (string) ( $cookie['name'] ?? '' ) );

		return $index[ $name_key ] ?? null;
	}

	/**
	 * The signature id an assisted cookie must be stored under.
	 *
	 * Reuses the id of the row this cookie already occupies so a re-scan updates it
	 * instead of adding a row. A genuinely new cookie gets a deterministic
	 * synthetic id, so repeated assisted scans stay idempotent.
	 *
	 * @param array<string, mixed>      $cookie   Normalized incoming cookie.
	 * @param array<string, mixed>|null $existing Matching stored row, if any.
	 * @since 1.3.0
	 * @return string
	 */
	public static function signature_id( array $cookie, ?array $existing ): string {
		if ( is_array( $existing ) && ! empty( $existing['signature_id'] ) ) {
			return (string) $existing['signature_id'];
		}

		return self::SIGNATURE_PREFIX . md5( Cookie_Identity::key_for( $cookie ) );
	}

	/**
	 * Whether a signature id was minted by an assisted scan.
	 *
	 * @param string $signature_id Stored signature id.
	 * @since 1.3.0
	 * @return bool
	 */
	public static function is_assisted_signature( string $signature_id ): bool {
		return strncmp( $signature_id, self::SIGNATURE_PREFIX, strlen( self::SIGNATURE_PREFIX ) ) === 0;
	}

	/**
	 * Fill gaps in an assisted cookie from the row it is about to replace.
	 *
	 * Assisted collection is a strict subset of what Playwright sees, so it must
	 * never overwrite a populated field with a weaker value; only name, domain,
	 * path and an intercepted expiry are authoritative here.
	 *
	 * The `http_only` case bites hardest: `document.cookie` cannot see HttpOnly
	 * cookies, so the collector always reports false and a naive replace would
	 * clear the flag on a cookie the cloud scanner correctly marked HttpOnly.
	 *
	 * @param array<string, mixed>      $cookie   Normalized incoming cookie (SaaS input shape).
	 * @param array<string, mixed>|null $existing Matching stored row (stored shape), if any.
	 * @since 1.3.0
	 * @return array<string, mixed>
	 */
	public static function merge_without_downgrade( array $cookie, ?array $existing ): array {
		if ( ! is_array( $existing ) ) {
			return $cookie;
		}

		// Classification the browser cannot determine. The stored row may carry a
		// vendor/purpose from the SaaS canonical or catalog backfill; keep them.
		$inherit_text = [
			// Incoming key => stored key.
			'owner'       => 'provider',
			'purpose'     => 'purpose',
			'description' => 'description',
			'category'    => 'category',
		];

		foreach ( $inherit_text as $incoming_key => $stored_key ) {
			if ( Sanitize::is_blank( $cookie[ $incoming_key ] ?? null ) && ! Sanitize::is_blank( $existing[ $stored_key ] ?? null ) ) {
				$cookie[ $incoming_key ] = $existing[ $stored_key ];
			}
		}

		// A duration the browser did not observe. Keep whatever is already known
		// rather than downgrading a two-year cookie to "session".
		// Scalarised first: an older row may hold the expiry as a number, and a
		// blank test alone would read that as nothing to inherit and downgrade a
		// dated cookie to "session" - the one case here where skipping loses data
		// rather than declining to copy a junk value.
		$inherited_expiry = Sanitize::scalar( $existing['expires'] ?? '' );
		if ( Sanitize::is_blank( $cookie['expires_at'] ?? null ) && ! Sanitize::is_blank( $inherited_expiry ) ) {
			$cookie['expires_at'] = $inherited_expiry;
		}

		// Flags the browser cannot disprove. Never turn a true into a false.
		if ( ! empty( $existing['httpOnly'] ) ) {
			$cookie['http_only'] = true;
		}

		if ( ! empty( $existing['secure'] ) ) {
			$cookie['secure'] = true;
		}

		if ( empty( $cookie['same_site'] ) && ! Sanitize::is_blank( $existing['sameSite'] ?? null ) ) {
			$cookie['same_site'] = $existing['sameSite'];
		}

		// Preserve the resolved-first-party marker so the declared-cookie merge
		// keeps matching this row by name.
		if ( Cookie_Identity::is_first_party( $existing ) ) {
			$cookie[ Cookie_Identity::FIRST_PARTY_FLAG ] = true;
		}

		return $cookie;
	}

	/**
	 * Whether a string is a syntactically valid cookie name.
	 *
	 * The browser is untrusted here, so a name carrying an `=` (a whole
	 * `name=value` pair that escaped the value strip), a `;`, or whitespace is
	 * rejected rather than stored as a cookie whose name would leak a value onto
	 * the public cookie policy. RFC 6265 restricts a cookie name to an HTTP token.
	 *
	 * @param string $name Candidate cookie name.
	 * @since 1.3.0
	 * @return bool
	 */
	public static function is_valid_name( string $name ): bool {
		if ( $name === '' || strlen( $name ) > 256 ) {
			return false;
		}

		// HTTP token: visible ASCII minus the RFC 7230 separators.
		return preg_match( '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name ) === 1;
	}

	/**
	 * The host a cookie with no Domain attribute belongs to.
	 *
	 * Deliberately NOT {@see Cookie_Identity::first_party_domain()}, which strips a
	 * leading `www.`: a host-only cookie set on www.example.com is scoped to that
	 * host and the cloud scanner records it as such, so the stripped form would
	 * misstate the scope and miss the stored row, producing a duplicate.
	 *
	 * @since 1.3.0
	 * @return string Lower-cased host, or an empty string when it cannot be resolved.
	 */
	public static function request_host(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $host ) ) {
			return '';
		}

		// An IPv6 literal arrives bracketed; the brackets are URL syntax, not host.
		return strtolower( trim( $host, " \t\n\r\0\x0B[]" ) );
	}

	/**
	 * Normalize a page's raw browser cookie findings.
	 *
	 * @param array<int, array<string, mixed>>    $raw            Raw entries: `name`, `attributes`, `set_via`.
	 * @param array<string, array<string, mixed>> $index          Output of {@see self::build_existing_index()}.
	 * @param int                                 $now            Reference timestamp, for deterministic tests.
	 * @param string                              $default_domain Host for cookies with no Domain attribute.
	 *                                                            The collector passes the scanned page's own host;
	 *                                                            falls back to {@see self::request_host()}.
	 * @since 1.3.0
	 * @return array<int, array<string, mixed>> Normalized cookies in the SaaS input shape.
	 */
	public static function cookies( array $raw, array $index = [], int $now = 0, string $default_domain = '' ): array {
		$normalized = [];
		$seen       = [];

		$default_domain = self::host_only( $default_domain );
		if ( $default_domain === '' ) {
			$default_domain = self::request_host();
		}

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $entry['name'] ?? '' ) );
			$name = trim( $name );

			if ( ! self::is_valid_name( $name ) || self::is_ignored( $name ) ) {
				continue;
			}

			$attributes = self::parse_attributes( (string) ( $entry['attributes'] ?? '' ), $now );

			if ( $attributes['is_deletion'] ) {
				continue;
			}

			$domain = $attributes['domain'] !== '' ? $attributes['domain'] : $default_domain;

			$cookie = [
				'name'        => $name,
				// Values are never collected. The key is kept because the stored
				// shape has it, and an empty string is the honest answer.
				'value'       => '',
				'domain'      => $domain,
				'path'        => $attributes['path'],
				'expires_at'  => $attributes['expires_at'],
				'secure'      => $attributes['secure'],
				// A browser can never observe this. Left false and only ever
				// raised by merge_without_downgrade().
				'http_only'   => false,
				'same_site'   => $attributes['same_site'],
				'set_via'     => self::host_only( (string) ( $entry['set_via'] ?? '' ) ),
				'category'    => '',
				'owner'       => '',
				'purpose'     => '',
				'description' => '',
				'source'      => self::SOURCE,
			];

			// Deduplicate within the page: the same cookie is often re-set on one
			// load, and the first observation carries the real attributes.
			$key = Cookie_Identity::key_for( $cookie );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$existing               = self::find_existing( $cookie, $index );
			$cookie                 = self::merge_without_downgrade( $cookie, $existing );
			$cookie['signature_id'] = self::signature_id( $cookie, $existing );

			// The browser's own default, applied last: only once neither the
			// observation nor the stored row could tell us anything.
			if ( Sanitize::is_blank( $cookie['same_site'] ) ) {
				$cookie['same_site'] = 'lax';
			}

			$normalized[] = $cookie;
		}

		return $normalized;
	}

	/**
	 * Normalize a page's raw browser resource findings.
	 *
	 * @param array<int, array<string, mixed>> $raw Raw entries: `url`, `kind`.
	 * @since 1.3.0
	 * @return array{scripts: array<int, array<string, mixed>>, iframes: array<int, array<string, mixed>>}
	 */
	public static function resources( array $raw ): array {
		$scripts = [];
		$iframes = [];

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$url = self::clean_url( (string) ( $entry['url'] ?? '' ) );

			if ( $url === '' ) {
				continue;
			}

			$host = self::host_only( $url );

			if ( $host === '' ) {
				continue;
			}

			$is_iframe = (string) ( $entry['kind'] ?? 'script' ) === 'iframe';
			$bucket    = $is_iframe ? 'iframes' : 'scripts';

			if ( $bucket === 'iframes' ) {
				if ( isset( $iframes[ $host ] ) ) {
					continue;
				}
				$iframes[ $host ] = [
					'src'               => $url,
					'domain'            => $host,
					'is_third_party'    => ! self::is_first_party_host( $host ),
					'vendor'            => '',
					'tracking_category' => '',
				];
				continue;
			}

			if ( isset( $scripts[ $host ] ) ) {
				continue;
			}

			$scripts[ $host ] = [
				'url'               => $url,
				'domain'            => $host,
				'is_third_party'    => ! self::is_first_party_host( $host ),
				'vendor_name'       => '',
				'tracking_category' => '',
			];
		}

		return [
			'scripts' => array_values( $scripts ),
			'iframes' => array_values( $iframes ),
		];
	}

	/**
	 * Strip the query string and fragment from a resource URL.
	 *
	 * Tracker query strings routinely carry page URLs, click ids and sometimes
	 * personal data, none of it needed to identify the service. Protocol-relative
	 * URLs are normalized to https, matching
	 * {@see \SureCookie\Inc\Modules\SiteScanner\LoopbackScanner::extract_resources()}.
	 *
	 * @param string $url Raw resource URL.
	 * @since 1.3.0
	 * @return string Scheme, host and path only, or an empty string when unusable.
	 */
	public static function clean_url( string $url ): string {
		$url = trim( $url );

		if ( $url === '' ) {
			return '';
		}

		if ( strpos( $url, '//' ) === 0 ) {
			$url = 'https:' . $url;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = ! empty( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';

		// Only ever describe web resources; data:, blob: and javascript: URLs are
		// not services and must not reach the blocking dataset.
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return '';
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';

		return $scheme . '://' . strtolower( (string) $parts['host'] ) . $path;
	}

	/**
	 * The host of a URL, or the input when it is already a bare host.
	 *
	 * @param string $value URL or hostname.
	 * @since 1.3.0
	 * @return string Lower-cased host, or an empty string.
	 */
	public static function host_only( string $value ): string {
		$value = strtolower( trim( $value ) );

		if ( $value === '' ) {
			return '';
		}

		if ( strpos( $value, '//' ) === 0 ) {
			$value = 'https:' . $value;
		}

		if ( strpos( $value, '://' ) !== false ) {
			$host = wp_parse_url( $value, PHP_URL_HOST );
			return is_string( $host ) ? strtolower( $host ) : '';
		}

		// A bare host may still arrive with a port or a stray path.
		$value = explode( '/', $value )[0];
		$value = explode( ':', $value )[0];

		return preg_match( '/^[a-z0-9.-]+$/', $value ) === 1 ? $value : '';
	}

	/**
	 * Whether a host belongs to this site.
	 *
	 * Compares against the same host {@see Cookie_Identity::first_party_domain()}
	 * resolves, so a resource on a subdomain of the site counts as first party.
	 *
	 * @param string $host Lower-cased host.
	 * @since 1.3.0
	 * @return bool
	 */
	public static function is_first_party_host( string $host ): bool {
		$site = Cookie_Identity::first_party_domain();

		if ( $site === '' || $host === '' ) {
			return false;
		}

		if ( strncmp( $host, 'www.', 4 ) === 0 ) {
			$host = substr( $host, 4 );
		}

		return $host === $site || substr( $host, -strlen( '.' . $site ) ) === '.' . $site;
	}

	/**
	 * Whether a cookie can only be the site's own, so no third-party vendor fits.
	 *
	 * The scan API resolves `owner`, `vendor` and `set_via` from a canonical and a
	 * signature keyed on the cookie NAME and shared by every scanned site, so one
	 * wrong approval publishes an unrelated domain as the provider of a session
	 * cookie on a legal disclosure page (#1274). Names a site's own stack emits
	 * cannot have a global owner; trackers are deliberately absent, because `_ga`
	 * is first party by domain and genuinely Google's.
	 *
	 * @param array<string, mixed> $cookie Cookie row carrying a name and a domain.
	 * @since 1.7.0
	 * @return bool
	 */
	public static function is_site_owned_cookie( array $cookie ): bool {
		$name = Cookie_Identity::normalize_name( (string) ( $cookie['name'] ?? '' ) );

		return preg_match( self::SITE_EMITTED_NAMES, $name ) === 1
			&& self::is_first_party_host( Cookie_Identity::normalize_domain( (string) ( $cookie['domain'] ?? '' ) ) );
	}
}
