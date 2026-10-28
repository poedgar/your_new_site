<?php
/**
 * Scoped resource exclusion and category-override resolution.
 *
 * Single source of truth for the two admin settings that decide which category a
 * scan-detected resource is actually gated under: `excluded_scan_resources`
 * ("Do not block") and `resource_category_overrides` (recategorize a domain).
 *
 * Extracted from Blocker and Scan_Scripts so the preferences-modal "is this
 * category in use?" predicate resolves categories the same way the blocker does.
 * A second copy of the scoped-key precedence would drift, and the UI would then
 * hide a category whose resources are still being gated.
 *
 * @package SureCookie\Inc\Modules\ScriptBlocking
 * @since 1.4.0
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\Services\Pattern_Kinds;
use SureCookie\Inc\Modules\Services\Services_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Resource_Categories
 *
 * @since 1.4.0
 */
final class Resource_Categories {
	/**
	 * Cached exclusion entries.
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $excluded = null;

	/**
	 * Cached category overrides ({ scoped key => category }).
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $overrides = null;

	/**
	 * Cached catalog pattern index ([ kind => [ pattern => category ] ]).
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private static ?array $catalog_categories = null;

	/**
	 * Cached pattern => [ catalog bucket => true ] index.
	 *
	 * @var array<string, array<string, bool>>|null
	 */
	private static ?array $catalog_buckets = null;

	/**
	 * Split a scoped key into [ kind, domain ].
	 *
	 * Keys are stored as "script::host" or "iframe::host" so a script and an
	 * iframe on the same host stay independent; a bare "host" (no "::") is a
	 * legacy key that applies to any kind. Shared by both settings.
	 *
	 * @since 1.4.0
	 * @param string $key Stored key.
	 * @return array{0: string, 1: string} [ kind ('script'|'iframe'|'any'), domain ].
	 */
	public static function parse_scoped_key( string $key ): array {
		$key = trim( $key );
		$pos = strpos( $key, '::' );
		if ( $pos === false ) {
			return [ 'any', $key ];
		}
		return [ substr( $key, 0, $pos ), substr( $key, $pos + 2 ) ];
	}

	/**
	 * Exclusion entries from the `excluded_scan_resources` setting.
	 *
	 * @since 1.4.0
	 * @return array<int, string>
	 */
	public static function excluded_entries(): array {
		if ( self::$excluded !== null ) {
			return self::$excluded;
		}

		$stored         = Settings::get( 'excluded_scan_resources' );
		self::$excluded = is_array( $stored ) ? array_values( $stored ) : [];

		return self::$excluded;
	}

	/**
	 * Whether a scan-detected domain of a given kind is excluded from blocking.
	 *
	 * Exact domain comparison: the caller already holds the stored domain, not a
	 * full URL. Used when merging scan results and when deciding category usage.
	 *
	 * @since 1.4.0
	 * @param string $domain Scan-detected resource domain.
	 * @param string $kind   Resource kind ('script'|'iframe').
	 * @return bool
	 */
	public static function is_excluded_domain( string $domain, string $kind ): bool {
		if ( $domain === '' ) {
			return false;
		}

		foreach ( self::excluded_entries() as $entry ) {
			[ $entry_kind, $entry_domain ] = self::parse_scoped_key( Sanitize::scalar( $entry ) );
			if ( $entry_domain === '' || ( $entry_kind !== 'any' && $entry_kind !== $kind ) ) {
				continue;
			}
			if ( strcasecmp( $entry_domain, $domain ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a resource matches an exclusion entry of the same kind.
	 *
	 * Case-insensitive to agree with Blocker::match_pattern(), which is where the
	 * resource was matched in the first place, and host-boundary aware so
	 * excluding `google.com` cannot also release `evilgoogle.com`.
	 *
	 * @since 1.4.0
	 * @param string $src  Resource URL, or a bare blocking pattern.
	 * @param string $kind Resource kind ('script'|'iframe').
	 * @return bool
	 */
	public static function matches_excluded_src( string $src, string $kind ): bool {
		if ( $src === '' ) {
			return false;
		}

		foreach ( self::excluded_entries() as $entry ) {
			[ $entry_kind, $domain ] = self::parse_scoped_key( Sanitize::scalar( $entry ) );
			if ( $domain === '' || ( $entry_kind !== 'any' && $entry_kind !== $kind ) ) {
				continue;
			}
			if ( self::entry_matches( $domain, $src ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether any candidate key matches an exclusion entry of the same kind.
	 *
	 * @since 1.5.0
	 * @param array<int, string> $keys Candidate keys, most specific first.
	 * @param string             $kind Resource kind ('script'|'iframe').
	 * @return bool
	 */
	public static function matches_excluded_any( array $keys, string $kind ): bool {
		foreach ( $keys as $key ) {
			if ( self::matches_excluded_src( (string) $key, $kind ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether one stored entry matches a resource.
	 *
	 * The rules live in {@see Entry_Match}, which Pro's whitelist calls too, so
	 * the two settings answer identically by construction rather than by a
	 * docblock asking two copies to stay in step.
	 *
	 * @since 1.5.0
	 * @param string $entry   Stored exclusion value.
	 * @param string $subject Resource URL, or a bare blocking pattern.
	 * @return bool
	 */
	public static function entry_matches( string $entry, string $subject ): bool {
		return Entry_Match::matches( $entry, $subject );
	}

	/**
	 * Admin per-domain category overrides, read once per request. Invalid rows
	 * are skipped.
	 *
	 * @since 1.4.0
	 * @return array<string, string>
	 */
	public static function overrides(): array {
		if ( self::$overrides !== null ) {
			return self::$overrides;
		}

		self::$overrides = [];
		$stored          = Settings::get( 'resource_category_overrides' );
		if ( is_array( $stored ) ) {
			foreach ( $stored as $domain => $category ) {
				// Integer keys mean a list-shaped store; find_override() matches keys as
				// substrings, so '0' would recategorize every URL containing a zero.
				if ( ! is_string( $domain ) ) {
					continue;
				}

				$domain   = trim( $domain );
				$category = trim( Sanitize::scalar( $category ) );
				if ( $domain !== '' && $category !== '' ) {
					self::$overrides[ $domain ] = $category;
				}
			}
		}

		return self::$overrides;
	}

	/**
	 * Apply an admin category override when the resource src contains an
	 * overridden domain, so consent gating follows the admin choice.
	 *
	 * An exact-kind override wins; a legacy bare-domain override applies to any
	 * kind. `$kind` is 'script' for scripts and 'iframe' for iframe/embed/object
	 * resources.
	 *
	 * @since 1.4.0
	 * @param string $src      Resource URL, or a bare domain.
	 * @param string $category Category resolved from the pattern match.
	 * @param string $kind     Resource kind ('script'|'iframe').
	 * @return string Overridden category, or the original when no override matches.
	 */
	public static function resolve( string $src, string $category, string $kind = 'any' ): string {
		return self::find_override( $src, $kind ) ?? $category;
	}

	/**
	 * The override target for one key, or null when no entry matches.
	 *
	 * Separate from resolve() so a caller can tell "no entry matched" from "an
	 * entry matched and named the category we already had".
	 *
	 * @since 1.5.0
	 * @param string $key  Resource URL, or a bare domain.
	 * @param string $kind Resource kind ('script'|'iframe').
	 * @return string|null
	 */
	public static function find_override( string $key, string $kind = 'any' ): ?string {
		if ( $key === '' ) {
			return null;
		}

		$legacy = null;
		foreach ( self::overrides() as $entry_key => $target ) {
			[ $entry_kind, $domain ] = self::parse_scoped_key( (string) $entry_key );
			if ( $domain === '' || stripos( $key, $domain ) === false ) {
				continue;
			}
			if ( $entry_kind === $kind ) {
				return $target; // Exact-kind override wins.
			}
			if ( $entry_kind === 'any' && $legacy === null ) {
				$legacy = $target; // Legacy bare-domain key applies to any kind.
			}
		}

		return $legacy;
	}

	/**
	 * First override matching any candidate key.
	 *
	 * Ordered so a src-keyed override still beats the broader pattern that matched
	 * it: `google.com/recaptcha` must win over a bare `google.com` rule.
	 *
	 * @since 1.5.0
	 * @param array<int, string> $keys     Candidate keys, most specific first.
	 * @param string             $category Category resolved from the pattern match.
	 * @param string             $kind     Resource kind ('script'|'iframe').
	 * @return string
	 */
	public static function resolve_first( array $keys, string $category, string $kind = 'any' ): string {
		foreach ( $keys as $key ) {
			$override = self::find_override( (string) $key, $kind );
			if ( $override !== null ) {
				return $override;
			}
		}

		return $category;
	}

	/**
	 * The category a scan-detected resource is actually GATED under.
	 *
	 * `Scan_Scripts::merge_resource()` drops a scan row whose domain is already a
	 * catalog pattern, so for those domains the blocker uses the catalog's category
	 * and the scanner's own guess is never applied. Reading the stored category
	 * would then credit the wrong bucket, and a category still gating a resource
	 * could be reported unused.
	 *
	 * @since 1.4.0
	 * @param string $domain Scan-detected resource domain.
	 * @param string $stored Category recorded on the scan row.
	 * @param string $kind   Resource kind ('script'|'iframe').
	 * @return string
	 */
	public static function gated_category( string $domain, string $stored, string $kind ): string {
		return self::resolve( $domain, self::catalog_category( $domain, $stored, $kind ), $kind );
	}

	/**
	 * The same resolution WITHOUT the admin override applied.
	 *
	 * The admin screen layers overrides on top itself, from the settings context,
	 * so the table reflects an edit before it is saved. Handing it a value that
	 * already had the saved override folded in would make an unsaved change
	 * invisible, so the two halves stay separate.
	 *
	 * @since 1.5.0
	 * @param string $domain Scan-detected resource domain.
	 * @param string $stored Category recorded on the scan row.
	 * @param string $kind   Resource kind ('script'|'iframe').
	 * @return string
	 */
	public static function catalog_category( string $domain, string $stored, string $kind ): string {
		$catalog = self::catalog_categories();

		return $catalog[ $kind ][ $domain ] ?? $catalog['any'][ $domain ] ?? $stored;
	}

	/**
	 * Drop the per-request caches. Called after scan results are stored.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public static function clear_cache(): void {
		self::$excluded           = null;
		self::$overrides          = null;
		self::$catalog_categories = null;
		self::$catalog_buckets    = null;
	}

	/**
	 * The catalog buckets a pattern is declared in, as [ bucket => true ].
	 *
	 * Empty when the catalog does not know the pattern. Lets a caller tell a
	 * pattern the blocker can act on from one it only ever sees on a `<link>`
	 * or an `<img>`, which no pass rewrites.
	 *
	 * @since 1.5.0
	 * @param string $pattern Catalog pattern, normally a scan row's domain.
	 * @return array<string, bool>
	 */
	public static function catalog_buckets( string $pattern ): array {
		self::catalog_categories();

		return self::$catalog_buckets[ trim( $pattern ) ] ?? [];
	}

	/**
	 * Catalog blocking patterns indexed as [ kind => [ pattern => category ] ],
	 * plus an 'any' bucket mirroring the kind-blind duplicate check in
	 * `Scan_Scripts::build_existing_pattern_index()`. Builds the pattern =>
	 * bucket index in the same walk, so both share one cache.
	 *
	 * @since 1.4.0
	 * @return array<string, array<string, string>>
	 */
	private static function catalog_categories(): array {
		if ( self::$catalog_categories !== null ) {
			return self::$catalog_categories;
		}

		$index   = [
			'script' => [],
			'iframe' => [],
			'any'    => [],
		];
		$buckets = [];

		foreach ( Services_Source::get_instance()->get_blocking_view() as $category => $services ) {
			if ( ! is_array( $services ) ) {
				continue;
			}
			foreach ( $services as $service ) {
				foreach ( Pattern_Kinds::buckets() as $bucket ) {
					$kind = Pattern_Kinds::resource_kind( $bucket );
					foreach ( (array) ( $service[ $bucket ] ?? [] ) as $pattern ) {
						$pattern = trim( (string) $pattern );
						if ( $pattern !== '' ) {
							$index[ $kind ][ $pattern ]     = (string) $category;
							$index['any'][ $pattern ]       = (string) $category;
							$buckets[ $pattern ][ $bucket ] = true;
						}
					}
				}
			}
		}

		self::$catalog_categories = $index;
		self::$catalog_buckets    = $buckets;

		return $index;
	}
}
