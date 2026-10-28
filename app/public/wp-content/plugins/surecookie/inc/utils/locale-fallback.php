<?php
/**
 * Locale Fallback
 *
 * WordPress loads plugin translations by exact locale match, so a regional
 * variant of a language we ship (de_CH, fr_BE, es_MX) got English while the
 * base-language catalog sat unused in languages/ (issue #1034). When the
 * requested file is missing, these filters substitute the shipped variant of
 * the same base language - for the PHP .mo catalogs and the script-translation
 * .json files alike, for both the free and the Pro text domain.
 *
 * @package SureCookie
 * @since   1.5.0
 */

namespace SureCookie\Inc\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Locale_Fallback class.
 *
 * @since 1.5.0
 */
class Locale_Fallback {
	/**
	 * Text domains the fallback applies to. Pro is included here because the
	 * two-domain split is exactly what cost the reporting customer hours - and
	 * Pro cannot run without free, so one registration covers both.
	 *
	 * @since 1.5.0
	 */
	private const DOMAINS = [ 'surecookie', 'surecookie-pro' ];

	/**
	 * Base languages never substituted across regions. Chinese variants differ
	 * by script (Simplified vs Traditional), not by region: zh_TW readers are
	 * not served by a zh_CN catalog the way de_CH readers are by de_DE. English
	 * is the untranslated source, so no en_* catalog may stand in for en_US.
	 *
	 * @since 1.5.0
	 */
	private const EXCLUDED_BASES = [ 'en', 'zh' ];

	/**
	 * Glob metacharacters rewritten as literals. A bracket anywhere in the
	 * install path would otherwise read as a character class and resolve the
	 * fallback against a different directory, or against none at all.
	 *
	 * @since 1.5.0
	 */
	private const GLOB_LITERALS = [
		'[' => '[[]',
		']' => '[]]',
		'*' => '[*]',
		'?' => '[?]',
	];

	/**
	 * Register both fallback filters. Idempotent.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'load_textdomain_mofile', [ self::class, 'substitute_mofile' ], 10, 2 );
		add_filter( 'load_script_translation_file', [ self::class, 'substitute_script_file' ], 10, 3 );
	}

	/**
	 * Substitute a missing regional .mo with the shipped base-language variant.
	 *
	 * Only fires when the exact file is absent, so a real regional catalog -
	 * hand-made, community-shipped, or added by us later - always wins.
	 *
	 * @param mixed $mofile Path WordPress is about to load.
	 * @param mixed $domain Text domain.
	 * @since 1.5.0
	 * @return mixed
	 */
	public static function substitute_mofile( $mofile = '', $domain = '' ) {
		if ( ! is_string( $mofile ) || ! is_string( $domain )
			|| ! in_array( $domain, self::DOMAINS, true ) || self::has_catalog( $mofile ) ) {
			return $mofile;
		}

		// The optional tail covers WordPress's formality and orthography
		// variants, so de_DE_formal and pt_PT_ao90 inherit like de_CH does.
		if ( ! preg_match( '/^(.*[\/\\\\]' . preg_quote( $domain, '/' ) . '-)([a-z]{2,3})_[A-Z]{2}(?:_[a-z0-9]+)?(\.mo)$/', $mofile, $m ) ) {
			return $mofile;
		}

		$fallback = self::shipped_variant( $m[1], $m[2], $m[3] );

		return $fallback !== '' ? $fallback : $mofile;
	}

	/**
	 * Substitute a missing regional script-translation .json the same way.
	 *
	 * The trailing hash is the md5 of the script path, so it is identical
	 * across locales and survives the locale swap.
	 *
	 * @param mixed $file   Path WordPress is about to load.
	 * @param mixed $handle Script handle (unused).
	 * @param mixed $domain Text domain.
	 * @since 1.5.0
	 * @return mixed
	 */
	public static function substitute_script_file( $file = '', $handle = '', $domain = '' ) {
		if ( ! is_string( $file ) || ! is_string( $domain )
			|| ! in_array( $domain, self::DOMAINS, true ) || self::has_catalog( $file ) ) {
			return $file;
		}

		if ( ! preg_match( '/^(.*[\/\\\\]' . preg_quote( $domain, '/' ) . '-)([a-z]{2,3})_[A-Z]{2}(?:_[a-z0-9]+)?(-[0-9a-f]{32}\.json)$/', $file, $m ) ) {
			return $file;
		}

		$fallback = self::shipped_variant( $m[1], $m[2], $m[3] );

		return $fallback !== '' ? $fallback : $file;
	}

	/**
	 * The shipped file for a base language, in the directory of the request.
	 *
	 * Prefers the bare base locale (`{prefix}de{suffix}`), then the canonical
	 * region (`{prefix}de_DE{suffix}`), which is the variant we ship wherever a
	 * language has one; byte order alone would hand back de_AT instead. Only
	 * then does it settle for any other sibling. Searching the request's own
	 * directory keeps the two plugins and the wp-content languages folder each
	 * resolving against their own files.
	 *
	 * @param string $prefix Path up to and including the domain and hyphen.
	 * @param string $base   Base language code (e.g. 'de').
	 * @param string $suffix Filename tail after the locale.
	 * @since 1.5.0
	 * @return string Path of an existing fallback file, or ''.
	 */
	private static function shipped_variant( string $prefix, string $base, string $suffix ): string {
		if ( in_array( $base, self::EXCLUDED_BASES, true ) ) {
			return '';
		}

		foreach ( [ $base, $base . '_' . strtoupper( $base ) ] as $locale ) {
			$candidate = $prefix . $locale . $suffix;
			if ( self::has_catalog( $candidate ) ) {
				return $candidate;
			}
		}

		$variants = glob( strtr( $prefix, self::GLOB_LITERALS ) . $base . '_*' . $suffix );
		if ( is_array( $variants ) ) {
			sort( $variants );

			foreach ( $variants as $variant ) {
				if ( self::has_catalog( $variant ) ) {
					return $variant;
				}
			}
		}

		return '';
	}

	/**
	 * Whether WordPress would find a catalog at this path.
	 *
	 * Core prefers the `.l10n.php` form and derives it from the path this filter
	 * hands back, so a locale carried only in that form is present as far as the
	 * caller is concerned. Treating it as missing would override a real regional
	 * catalog with the base language, which is the opposite of the intent.
	 *
	 * @param string $path Catalog path WordPress asked for.
	 * @since 1.5.0
	 * @return bool
	 */
	private static function has_catalog( string $path ): bool {
		if ( is_file( $path ) && is_readable( $path ) ) {
			return true;
		}

		if ( substr( $path, -3 ) !== '.mo' ) {
			return false;
		}

		$php = substr_replace( $path, '.l10n.php', -3 );

		return is_file( $php ) && is_readable( $php );
	}
}
