<?php
/**
 * Sanitize
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Functions;

use SureCookie\Inc\Utils\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Sanitize
 *
 * @since 0.0.1
 */
class Sanitize {
	/**
	 * Sanitize text
	 * if the value after sanitize_text is empty, return the default value
	 *
	 * @param string $value   Value to sanitize.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public static function text( $value ) {
		$sanitized = sanitize_text_field( $value );
		return $sanitized !== '' ? $sanitized : '';
	}

	/**
	 * Sanitize textarea
	 * if the value after sanitize_textarea is empty, return the default value
	 *
	 * @param string $value   Value to sanitize.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public static function textarea( $value ) {
		$sanitized = sanitize_textarea_field( $value );
		return $sanitized !== '' ? $sanitized : '';
	}

	/**
	 * Sanitize a cookie domain.
	 *
	 * A cookie domain is a host (optionally with a leading dot), not a URL, so it
	 * must not go through esc_url_raw() - that prepends "http://" and stores a
	 * mangled value (e.g. ".youtube.com" becomes "http://.youtube.com"). This
	 * strips any scheme/path that was pasted in, preserves a leading dot, and
	 * keeps only host-safe characters so custom cookies match the format scanned
	 * and declared cookies already use.
	 *
	 * @param mixed $value Raw domain input.
	 * @since 1.2.5
	 * @return string
	 */
	public static function cookie_domain( $value ): string {
		$domain = trim( (string) $value );

		if ( $domain === '' ) {
			return '';
		}

		// If a full URL was pasted, keep only the host.
		if ( strpos( $domain, '://' ) !== false ) {
			$host   = wp_parse_url( $domain, PHP_URL_HOST );
			$domain = is_string( $host ) && $host !== '' ? $host : $domain;
		}

		// A leading dot is valid in a cookie domain; preserve it across sanitizing.
		$leading_dot = strncmp( $domain, '.', 1 ) === 0 ? '.' : '';
		$domain      = ltrim( $domain, '.' );

		// Keep only host-safe characters (letters, digits, dot, hyphen).
		$domain = preg_replace( '/[^A-Za-z0-9.\-]/', '', $domain ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- Plain character-class strip; no /e modifier.
		$domain = is_string( $domain ) ? $domain : '';

		return $leading_dot . strtolower( $domain );
	}

	/**
	 * Sanitize rich text content for banner/message fields.
	 *
	 * @param mixed $value Value to sanitize.
	 * @since 0.0.1
	 * @return string
	 */
	public static function rich_text( $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return '';
		}

		return self::normalize_rich_text_whitespace( wp_kses_post( $value ) );
	}

	/**
	 * Re-sanitize rich text after an HTML-entity decode has re-armed it.
	 *
	 * Kses only inspects markup between a literal `<` and `>`, so
	 * `&lt;img onerror=...&gt;` is stored verbatim and only goes live once
	 * something decodes it - Helper::decode_html_entities_recursive() on the
	 * settings routes, or WP_Scripts::localize() on every top-level scalar.
	 * Decode to a fixed point (multiply-encoded payloads survive one pass)
	 * before re-running kses. Stabilize loop as in self::stylesheet().
	 *
	 * @param mixed $value Value to sanitize.
	 * @since 1.3.1
	 * @return string
	 */
	public static function rich_text_after_decode( $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return '';
		}

		// Terminates: html_entity_decode() never lengthens the string.
		do {
			$prev  = $value;
			$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		} while ( $value !== $prev );

		return self::normalize_rich_text_whitespace( wp_kses_post( $value ) );
	}

	/**
	 * Apply self::rich_text_after_decode() to every rich-text key in a settings array.
	 *
	 * @param array<string, mixed> $settings Settings dataset.
	 * @since 1.3.1
	 * @return array<string, mixed>
	 */
	public static function rich_text_keys_after_decode( array $settings ): array {
		foreach ( Options::get_rich_text_options() as $key ) {
			if ( is_string( $settings[ $key ] ?? null ) ) {
				$settings[ $key ] = self::rich_text_after_decode( $settings[ $key ] );
			}
		}

		return $settings;
	}

	/**
	 * Sanitize a CSS stylesheet string before it is inlined.
	 *
	 * Blocklist-based. All neutralizing passes run inside a single stabilize
	 * loop so that stripping one class of token (comment, escape, url(...))
	 * cannot reveal another. The loop is required - CSS permits comments and
	 * unicode escapes between any two chars of a keyword (e.g. u/**\/rl(,
	 * \75rl(), so a single pass is always bypassable.
	 *
	 * @param string $value Stylesheet content.
	 * @since 0.0.1
	 * @return string
	 */
	public static function stylesheet( $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return '';
		}

		$stylesheet = wp_strip_all_tags( wp_unslash( $value ), false );

		$strip_to_empty = static function (): string {
			return '';
		};

		// Strip control characters once - not part of the stabilize loop.
		$stylesheet = (string) preg_replace_callback(
			'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',
			$strip_to_empty,
			(string) $stylesheet
		);

		$blocked = [ 'expression(', 'javascript:', '@import', '@charset', 'behavior:', '-moz-binding', '@namespace', '@document', 'url(', 'image-set(', 'cross-fade(' ];

		do {
			$prev = $stylesheet;

			// Strip CSS block comments - parsers drop them during tokenization,
			// so "u/**/rl(" reads as "url(" to the browser. Match unterminated
			// trailing comments too; truncating at an unclosed /* is safer than
			// leaving content the browser might still parse.
			$stylesheet = (string) preg_replace_callback( '#/\*.*?(?:\*/|\z)#s', $strip_to_empty, $stylesheet );

			// Strip CSS unicode escapes (\HEX with optional single trailing
			// whitespace terminator per CSS spec) to prevent \75rl( bypasses.
			$stylesheet = (string) preg_replace_callback( '/\\\\[0-9a-fA-F]{1,6}\s?/u', $strip_to_empty, $stylesheet );

			// Strip whole url()/image-set()/image() expressions - including
			// args and closing paren - so no orphaned data: / //host / string
			// leaks through. These are the CSS URL sinks that can trigger
			// network fetches or XSS. Uses [^()]* (no nested parens) which
			// matches CSS spec: url() args cannot contain unescaped parens.
			$stylesheet = (string) preg_replace_callback( '/(?:url|image-set|image|cross-fade|-webkit-image-set)\s*\([^()]*\)/i', $strip_to_empty, $stylesheet );

			// Literal keyword blocklist (case-insensitive). Acts as a fallback
			// for surviving "url(" / "image-set(" literals where nested parens
			// broke the balanced-paren regex above.
			$stylesheet = str_ireplace( $blocked, '', $stylesheet );
		} while ( $stylesheet !== $prev );

		return trim( $stylesheet );
	}

	/**
	 * Sanitize bool values
	 *
	 * @param mixed $value   Value to sanitize.
	 *
	 * @since 0.0.1
	 * @return bool
	 */
	public static function boolean( $value ) {
		return (bool) $value;
	}

	/**
	 * Read an untrusted leaf as a string, refusing non-scalars.
	 *
	 * Casting an array leaf yields the literal "Array", which then becomes live
	 * data; '' lets the caller's own empty check drop it instead.
	 *
	 * @param mixed $value Leaf value.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	public static function scalar( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Whether a value renders as nothing: not a string, empty, ASCII or Unicode
	 * spaces, or invisible format characters.
	 *
	 * Visible copy is the test, not `=== ''`: a label of U+00A0 or U+200B survives
	 * trim() and renders as a gap, which is how a nameless button (#957, #1421) and
	 * a "would connect to ." placeholder (#1429) each reached visitors.
	 *
	 * @param mixed $value Value to test.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public static function is_blank( $value ): bool {
		return ! is_string( $value ) || preg_match( '/^[\s\p{Z}\p{Cf}]*$/u', $value ) === 1;
	}

	/**
	 * Sanitize a scanned vendor name. A classifier placeholder such as "null" names no vendor.
	 *
	 * @param mixed $value Raw vendor.
	 *
	 * @since 1.6.1
	 * @return string The vendor, or '' when there is none.
	 */
	public static function vendor( $value ): string {
		$vendor = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';

		return in_array( strtolower( $vendor ), [ 'null', 'none', 'unknown', 'n/a', 'undefined' ], true ) ? '' : $vendor;
	}

	/**
	 * Sanitize int values
	 *
	 * @param mixed $value   Value to sanitize.
	 *
	 * @since 0.0.1
	 * @return int
	 */
	public static function integer( $value ) {
		return absint( $value );
	}

	/**
	 * Sanitize multi-dimension array.
	 *
	 * @param array<string, mixed>|array<int, string> $data_array Array what we need to sanitize.
	 * @since  0.0.1
	 * @return array<string, mixed>|array<int, string>
	 */
	public static function array( array $data_array ) {
		/**
		 * Leaf keys whose string values hold rich text and should be sanitized
		 * with wp_kses_post (basic formatting preserved) instead of being
		 * flattened by sanitize_text_field. Extensions register their keys here
		 * - e.g. SureCookie Pro's per-region banner copy (`custom_text`).
		 *
		 * @since 0.0.1
		 * @param array<int, string> $rich_keys Leaf keys treated as rich text.
		 */
		$rich_keys = apply_filters( 'surecookie_rich_text_array_keys', [] );

		return self::sanitize_array_recursive(
			$data_array,
			is_array( $rich_keys ) ? $rich_keys : []
		);
	}

	/**
	 * Sanitize Email address
	 *
	 * @since 0.0.1
	 * @param string $email The email address to sanitize.
	 * @return string The sanitized email address.
	 */
	public static function email( $email ) {
		$sanitized_email = sanitize_email( $email );
		return is_email( $sanitized_email ) ? $sanitized_email : '';
	}

	/**
	 * Sanitize a hex color (3- or 6-digit, with leading '#').
	 *
	 * Returns an empty string for invalid input so consumers can fall back to
	 * defaults without leaking unsafe values into inline styles. Defers to
	 * WordPress' sanitize_hex_color() to keep behaviour consistent with core.
	 *
	 * @since 1.0.0
	 * @param mixed $value Color value to sanitize.
	 * @return string Sanitized hex (e.g. "#aabbcc") or empty string.
	 */
	public static function hex_color( $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return '';
		}
		$sanitized = sanitize_hex_color( $value );
		return is_string( $sanitized ) ? $sanitized : '';
	}

	/**
	 * Sanitize a CSS color value: hex or a strict rgba() with 0-255 channels
	 * and a 0-1 alpha. Anything else (named colors, calc(), var(), injection
	 * attempts) returns an empty string, so consumers can fall back to
	 * defaults without leaking unsafe values into inline styles.
	 *
	 * @since 1.4.0
	 * @param mixed $value Color value to sanitize.
	 * @return string Sanitized color ("#aabbcc" or "rgba(r, g, b, a)") or empty string.
	 */
	public static function css_color( $value ) {
		$hex = self::hex_color( $value );
		if ( $hex !== '' ) {
			return $hex;
		}

		if ( ! is_string( $value ) ) {
			return '';
		}

		if ( ! preg_match( '/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(0|1|1\.0+|0?\.\d{1,4})\s*\)$/i', trim( $value ), $matches ) ) {
			return '';
		}

		$r = (int) $matches[1];
		$g = (int) $matches[2];
		$b = (int) $matches[3];
		$a = (float) $matches[4];

		if ( $r > 255 || $g > 255 || $b > 255 || $a > 1 ) {
			return '';
		}

		$alpha = rtrim( rtrim( number_format( $a, 4, '.', '' ), '0' ), '.' );
		$alpha = $alpha === '' ? '0' : $alpha;

		// Canonical form keeps downstream comparisons and CSS emission stable.
		return sprintf( 'rgba(%d, %d, %d, %s)', $r, $g, $b, $alpha );
	}

	/**
	 * Sanitize settings
	 *
	 * @param array<string, mixed> $data   Data to sanitize.
	 * @since 0.0.1
	 * @return array<string, mixed>
	 */
	public static function settings( $data ) {
		if ( empty( $data ) || ! Validate::array( $data ) ) {
			return [];
		}

		$sanitized_settings = Get::option( SURECOOKIE_SETTINGS_OPTION, [], 'array' );

		foreach ( $data as $key => $value ) {
			// Same rule as the REST patch: a wrong-shaped array value is dropped, never coerced to [].
			if ( ! Settings::accepts_value( $key, $value ) ) {
				continue;
			}

			$sanitized_settings[ $key ] = Settings::get_cleaned_value( $key, $value );
		}

		return $sanitized_settings;
	}

	/**
	 * Normalize non-breaking spaces in rich-text text nodes without changing attributes.
	 *
	 * @param string $value Sanitized rich-text HTML.
	 * @return string
	 */
	private static function normalize_rich_text_whitespace( string $value ): string {
		$result = '';
		$text   = '';
		$in_tag = false;
		$quote  = null;
		$length = strlen( $value );

		for ( $index = 0; $index < $length; $index++ ) {
			$char = $value[ $index ];

			if ( ! $in_tag ) {
				if ( $char === '<' ) {
					$result .= self::normalize_rich_text_segment( $text ) . $char;
					$text    = '';
					$in_tag  = true;
				} else {
					$text .= $char;
				}
				continue;
			}

			$result .= $char;
			if ( $quote !== null ) {
				if ( $char === $quote ) {
					$quote = null;
				}
				continue;
			}

			if ( $char === '"' || $char === "'" ) {
				$quote = $char;
			} elseif ( $char === '>' ) {
				$in_tag = false;
			}
		}

		return $result . self::normalize_rich_text_segment( $text );
	}

	/**
	 * Normalize NBSP variants inside one text segment.
	 *
	 * @param string $value Plain-text segment from sanitized rich text.
	 * @return string
	 */
	private static function normalize_rich_text_segment( string $value ): string {
		$value = str_replace( "\xC2\xA0", ' ', $value );

		return preg_replace_callback(
			'/&(?:nbsp|#0*160|#x0*a0);/i',
			static function () {
				return ' ';
			},
			$value
		) ?? $value;
	}

	/**
	 * Recursively sanitize an array. Leaves are run through sanitize_text_field,
	 * except those whose key is registered as rich text (see Sanitize::array),
	 * which use wp_kses_post so basic formatting survives.
	 *
	 * @param array<string, mixed>|array<int, string> $data_array Array to sanitize.
	 * @param array<int, string>                      $rich_keys  Keys treated as rich text.
	 * @since 0.0.1
	 * @return array<string, mixed>|array<int, string>
	 */
	private static function sanitize_array_recursive( array $data_array, array $rich_keys ) {
		if ( empty( $data_array ) ) {
			return [];
		}

		$response = [];
		foreach ( $data_array as $key => $data ) {
			// is_array, not Validate::array: the latter returns the array, so an empty
			// one is falsy and the branch below would flatten it to '', turning a blank
			// row into a scalar that consumers then index into.
			if ( is_array( $data ) ) {
				$response[ $key ] = self::sanitize_array_recursive( $data, $rich_keys );
			} elseif ( is_string( $data ) && in_array( $key, $rich_keys, true ) ) {
				$response[ $key ] = wp_kses_post( $data );
			} else {
				$response[ $key ] = sanitize_text_field( $data );
			}
		}

		return $response;
	}
}
