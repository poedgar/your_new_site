<?php
/**
 * The one rule for matching a stored exclusion or whitelist entry.
 *
 * @package SureCookie
 * @since   1.5.0
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether one stored entry claims one resource.
 *
 * Free's "Always load" exclusion and Pro's "Always allowed" whitelist are the
 * same question asked of two settings, and they used to be two copies of these
 * rules kept in step by a docblock. They drifted: 694 of 1.6M inputs disagreed.
 * This class is the single implementation both call, so drift is no longer
 * possible. It deliberately depends on nothing but PHP and `wp_parse_url()`, so
 * Pro can load this one file without pulling in the blocking engine.
 *
 * Both sides are parsed, never compared as raw strings, because the subject is
 * a resource URL when the server pass asks and the bare catalog pattern the URL
 * matched on when the browser-guard builder asks. A substring only ever matched
 * the longer of the two, so a URL-shaped entry released a resource server-side
 * and left the guard still wrapping it.
 *
 * The entry is classified once, and that decides everything:
 *
 * - carries a query or fragment: an exact-URL claim, matched as a substring
 * - holds no dot: a keyword such as `fbq`, matched as a substring
 * - its first segment holds no dot: a path fragment such as `assets/app.js`,
 *   matched against the subject. `assets` is not a host
 * - otherwise a host claim, optionally narrowed by a port and a path
 *
 * @since 1.5.0
 */
final class Entry_Match {
	/**
	 * Whether one stored entry claims one resource.
	 *
	 * @since 1.5.0
	 * @param string $entry   Stored exclusion or whitelist value.
	 * @param string $subject Resource URL, or a bare blocking pattern.
	 * @return bool
	 */
	public static function matches( string $entry, string $subject ): bool {
		$needle = strtolower( trim( $entry ) );
		if ( $needle === '' || $subject === '' ) {
			return false;
		}

		// A query or fragment is an exact-URL claim, and a value with no dot was
		// never a host claim: both keep the substring behaviour they were saved
		// under.
		if ( strpbrk( $needle, '?#' ) !== false || strpos( $needle, '.' ) === false ) {
			return stripos( $subject, $needle ) !== false;
		}

		$claim = self::parts( $needle );

		// No host of its own, so it never was a host claim. Both `/wp-content/x.js`
		// and the relative `assets/app.min.js` land here: reading `assets` as a
		// host is what silently killed this shape.
		if ( $claim['host'] === '' ) {
			return stripos( $subject, $needle ) !== false;
		}

		$resource = self::parts( $subject );

		if ( ! self::host_covers( $claim['host'], $resource['host'] ) ) {
			// A dotted entry may name the file being requested rather than a host,
			// so `analytics.js` keeps working. Only the basename counts: searching
			// the whole path released `cdn.tracker.test/google.com/t.js` for a
			// `google.com` entry, one party freed by another's URL.
			return $claim['path'] === '' && self::basename_is( $resource['path'], $claim['host'] );
		}

		// An entry that names a port claims that port alone.
		if ( $claim['port'] !== '' && $claim['port'] !== $resource['port'] ) {
			return false;
		}

		return self::path_covers( $claim['path'], $resource['path'] );
	}

	/**
	 * Whether an entry names this exact host, rather than merely reaching it.
	 *
	 * `matches()` answers "does this entry claim this resource", which a parent
	 * domain, a keyword and a filename all satisfy. A caller that has to tell an
	 * allow aimed at one host from one that happens to cover it needs the
	 * narrower question - the Compliance Guard's hold outranks the second.
	 *
	 * @since 1.5.1
	 * @param string $entry Stored exclusion or whitelist value.
	 * @param string $host  Host to test.
	 * @return bool
	 */
	public static function names_host( string $entry, string $host ): bool {
		$host = strtolower( trim( $host ) );
		if ( $host === '' ) {
			return false;
		}

		$claim = self::parts( strtolower( trim( $entry ) ) )['host'];

		return $claim !== '' && self::without_www( $claim ) === self::without_www( $host );
	}

	/**
	 * A host without its `www.` alias label.
	 *
	 * The one definition; `Blocker::without_www()` forwards here.
	 *
	 * @since 1.5.0
	 * @param string $host Host to normalise.
	 * @return string
	 */
	public static function without_www( string $host ): string {
		return strpos( $host, 'www.' ) === 0 ? substr( $host, 4 ) : $host;
	}

	/**
	 * Host, path and port of an entry or a resource.
	 *
	 * One parser for both sides. Pro used to prepend a scheme for the guard
	 * builder and not for the server pass, which read a leading-slash pattern as
	 * a host and made its two layers disagree.
	 *
	 * @since 1.5.0
	 * @param string $value Entry or subject.
	 * @return array{host: string, path: string, port: string}
	 */
	public static function parts( string $value ): array {
		$value = trim( $value );

		if ( strpos( $value, '//' ) === 0 ) {
			$url = 'https:' . $value;             // Protocol-relative.
		} elseif ( strpos( $value, '://' ) !== false || strpos( $value, '/' ) === 0 ) {
			$url = $value;                        // Absolute URL, or root-relative.
		} elseif ( strpos( self::first_segment( $value ), '.' ) !== false ) {
			$url = 'https://' . $value;           // Bare host, optionally with a path.
		} else {
			// A dotless first segment cannot be a host, so this is a relative path.
			return [
				'host' => '',
				'path' => $value,
				'port' => '',
			];
		}

		$parts = wp_parse_url( $url );
		$parts = is_array( $parts ) ? $parts : [];

		return [
			'host' => strtolower( (string) ( $parts['host'] ?? '' ) ),
			'path' => (string) ( $parts['path'] ?? '' ),
			'port' => isset( $parts['port'] ) ? (string) $parts['port'] : '',
		];
	}

	/**
	 * Everything before the first slash.
	 *
	 * @since 1.5.0
	 * @param string $value Entry or subject.
	 * @return string
	 */
	private static function first_segment( string $value ): string {
		$first = strstr( $value, '/', true );
		return $first === false ? $value : $first;
	}

	/**
	 * Whether an entry's host claim covers a resource host.
	 *
	 * @since 1.5.0
	 * @param string $claim Host taken from the stored entry.
	 * @param string $host  Host taken from the resource.
	 * @return bool
	 */
	private static function host_covers( string $claim, string $host ): bool {
		if ( $host === '' ) {
			return false;
		}

		// Exact, or a parent domain, so `google.com` never covers
		// `evilgoogle.com` or `google.com.attacker.test`.
		if ( $host === $claim || substr( $host, - ( strlen( $claim ) + 1 ) ) === '.' . $claim ) {
			return true;
		}

		// Alias, not subdomain: `www.example.com` and `example.com` are one host,
		// but `www.example.com` still must not claim `maps.example.com`.
		return self::without_www( $host ) === self::without_www( $claim );
	}

	/**
	 * Whether an entry's path claim covers a resource path.
	 *
	 * A path binds on segment boundaries. A raw prefix let `instagram.com/p/`
	 * cover every path starting `/p`, and `google.com/recaptcha` cover
	 * `/recaptcha-evil/`. Compared case-insensitively, because the entry is
	 * lowercased on the way in while a resource path keeps its own casing, as
	 * vendor `/en_US/` paths do.
	 *
	 * A claim spelled without a trailing slash may also stop inside the
	 * filename, which is how the catalog writes `hotjar.com/c/hotjar` for
	 * `/c/hotjar-1234567.js`. That prefix binds to the last segment alone, so
	 * the directory over-matching above stays closed.
	 *
	 * @since 1.5.0
	 * @param string $claim Path taken from the stored entry.
	 * @param string $path  Path taken from the resource.
	 * @return bool
	 */
	private static function path_covers( string $claim, string $path ): bool {
		$directory = str_ends_with( $claim, '/' );
		$claim     = rtrim( $claim, '/' );
		if ( $claim === '' ) {
			return true;
		}

		$path = rtrim( $path, '/' );

		if ( strcasecmp( $path, $claim ) === 0 || stripos( $path, $claim . '/' ) === 0 ) {
			return true;
		}

		$cut = strrpos( $claim, '/' );
		if ( $directory || $cut === false || $cut !== strrpos( $path, '/' ) ) {
			return false;
		}

		$base = substr( $claim, $cut + 1 );

		return $base !== ''
			&& strncasecmp( $claim, $path, $cut ) === 0
			&& strncasecmp( substr( $path, $cut + 1 ), $base, strlen( $base ) ) === 0;
	}

	/**
	 * Whether a resource path requests exactly this file.
	 *
	 * @since 1.5.0
	 * @param string $path  Path taken from the resource.
	 * @param string $claim Filename taken from the stored entry.
	 * @return bool
	 */
	private static function basename_is( string $path, string $claim ): bool {
		if ( $path === '' ) {
			return false;
		}

		$cut  = strrpos( $path, '/' );
		$base = $cut === false ? $path : substr( $path, $cut + 1 );

		return $base !== '' && strcasecmp( $base, $claim ) === 0;
	}
}
