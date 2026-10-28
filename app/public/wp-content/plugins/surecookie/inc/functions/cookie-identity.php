<?php
/**
 * Cookie identity helpers.
 *
 * Two jobs that must agree, so they share a file: (1) resolving the catalog's
 * first-party placeholder domain `.domain.com` - a bundled service def cannot
 * know its install host, and left alone the placeholder reaches stored rows, the
 * Domain column and the cookie policy, never matching what the scanner observed;
 * (2) building the case-insensitive `name|domain` identity key every dedup path
 * compares on, consolidated here from four drifted private copies.
 *
 * Deliberately split from {@see Sanitize::cookie_domain()}: that PRESERVES a
 * leading dot (the host-only vs domain-wide marker, which belongs in
 * stored/displayed values); the keys here STRIP it, as it is not part of identity.
 *
 * @package SureCookie\Inc\Functions
 * @since 1.3.0
 */

namespace SureCookie\Inc\Functions;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Cookie_Identity
 *
 * @since 1.3.0
 */
class Cookie_Identity {
	/**
	 * Domain the catalog uses to stand in for "the site's own host".
	 *
	 * @since 1.3.0
	 */
	public const FIRST_PARTY_PLACEHOLDER = 'domain.com';

	/**
	 * Flag on a catalog row whose placeholder domain was resolved to this site's
	 * host. Marks the domain inferred, not authored, letting dedup fall back to name.
	 *
	 * @since 1.3.0
	 */
	public const FIRST_PARTY_FLAG = 'first_party';

	/**
	 * Whether a catalog name describes the SHAPE of a name instead of naming a cookie.
	 *
	 * `_ga_<container-id>`, `_dc_gtm_UA-*` and `_hjSessionUser_*` exist so an observed
	 * cookie can be attributed to its service. Publishing one lists, on a legal
	 * disclosure page, a cookie the site does not set. Shared because three paths
	 * reach the catalog and each has to make the same call.
	 *
	 * @param string $name Catalog cookie name.
	 * @since 1.5.0
	 * @return bool
	 */
	public static function is_pattern( string $name ): bool {
		return strcspn( $name, '*<' ) < strlen( $name );
	}

	/**
	 * Whether a domain is the catalog's first-party placeholder.
	 *
	 * Exact match on the normalised value, never str_contains: a real host like
	 * `mydomain.com` or `shop.domain.com` must not be swallowed by the rule.
	 *
	 * @param string $domain Cookie domain.
	 * @since 1.3.0
	 * @return bool
	 */
	public static function is_placeholder( string $domain ): bool {
		return self::normalize_domain( $domain ) === self::FIRST_PARTY_PLACEHOLDER;
	}

	/**
	 * Whether a cookie row carries the resolved-first-party marker.
	 *
	 * @param array<string, mixed> $cookie Cookie row.
	 * @since 1.3.0
	 * @return bool
	 */
	public static function is_first_party( array $cookie ): bool {
		return ! empty( $cookie[ self::FIRST_PARTY_FLAG ] );
	}

	/**
	 * The host first-party cookies belong to on this site.
	 *
	 * `home_url()` not `get_site_url()`: per-blog on multisite and the host
	 * visitors are served from, matching the two closest consumers (the blocker's
	 * first-party test and the policy's provider fallback).
	 *
	 * Leading `www.` is dropped: placeholder rows are tag scripts that derive the
	 * registrable domain in JS, so a browser on `www.example.com` reports the
	 * cookie on `.example.com`; `.www.example.com` would be wrong and never match.
	 *
	 * @since 1.3.0
	 * @return string Lower-cased host, or an empty string when it cannot be resolved.
	 */
	public static function first_party_domain(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $host ) ) {
			return '';
		}

		// An IPv6 literal arrives bracketed; the brackets are URL syntax, not host.
		$host = strtolower( trim( $host, " \t\n\r\0\x0B[]" ) );

		if ( strncmp( $host, 'www.', 4 ) === 0 && strpos( substr( $host, 4 ), '.' ) !== false ) {
			$host = substr( $host, 4 );
		}

		return $host;
	}

	/**
	 * Resolve a catalog domain: the placeholder becomes this site's host, anything
	 * else is returned untouched.
	 *
	 * The leading dot is preserved (these cookies really are set domain-wide) but
	 * only for a host a Domain attribute can name; on `localhost` or an IP literal
	 * the dot is dropped rather than fabricated.
	 *
	 * @param string $domain Catalog cookie domain.
	 * @since 1.3.0
	 * @return string Resolved domain, or an empty string when the host is unknown.
	 */
	public static function resolve( string $domain ): string {
		if ( ! self::is_placeholder( $domain ) ) {
			return $domain;
		}

		$host = self::first_party_domain();

		if ( $host === '' ) {
			return '';
		}

		$dotted = strpos( $host, '.' ) !== false && filter_var( $host, FILTER_VALIDATE_IP ) === false;
		$prefix = $dotted && strncmp( ltrim( $domain ), '.', 1 ) === 0 ? '.' : '';

		return $prefix . $host;
	}

	/**
	 * Case-insensitive `name|domain` identity key (leading dot ignored).
	 *
	 * @param string $name   Cookie name.
	 * @param string $domain Cookie domain.
	 * @since 1.3.0
	 * @return string
	 */
	public static function key( string $name, string $domain ): string {
		return self::normalize_name( $name ) . '|' . self::normalize_domain( $domain );
	}

	/**
	 * The identity key of a cookie row.
	 *
	 * @param array<string, mixed> $cookie Cookie row.
	 * @since 1.3.0
	 * @return string
	 */
	public static function key_for( array $cookie ): string {
		return self::key(
			(string) ( $cookie['name'] ?? '' ),
			(string) ( $cookie['domain'] ?? '' )
		);
	}

	/**
	 * The domain-less identity key, used where the catalog cannot know the domain.
	 *
	 * Deliberately the same shape as {@see self::key()} with an empty domain, so
	 * the two can share one index without colliding with a real key.
	 *
	 * @param string $name Cookie name.
	 * @since 1.3.0
	 * @return string
	 */
	public static function name_key( string $name ): string {
		return self::key( $name, '' );
	}

	/**
	 * Normalise a cookie name for comparison.
	 *
	 * @param string $name Cookie name.
	 * @since 1.3.0
	 * @return string
	 */
	public static function normalize_name( string $name ): string {
		return strtolower( trim( $name ) );
	}

	/**
	 * Normalise a cookie domain for comparison: trimmed, lower-cased, no leading dot.
	 *
	 * @param string $domain Cookie domain.
	 * @since 1.3.0
	 * @return string
	 */
	public static function normalize_domain( string $domain ): string {
		return strtolower( ltrim( trim( $domain ), '.' ) );
	}
}
