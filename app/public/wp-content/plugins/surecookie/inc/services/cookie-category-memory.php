<?php
/**
 * Cookie Category Memory
 *
 * Remembers the category an admin manually assigned to a scanned cookie so it
 * survives later scans. Every scan re-buckets scanned cookies from the scanner's
 * own classification, and a cookie that vanishes (an embed stops loading, a
 * catalog entry is pruned) and later returns comes back as whatever the scanner
 * reports - usually `marketing`, the safe default. Without this store the admin
 * must redo the same categorisation after every scan.
 *
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Services
 * @since      1.3.0
 */

namespace SureCookie\Inc\Services;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Functions\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class CookieCategoryMemory
 *
 * Static store of admin-assigned cookie categories, keyed by a composite of
 * cookie name + domain + provider so two unrelated cookies that happen to
 * share a name never inherit each other's category.
 *
 * @since 1.3.0
 */
class CookieCategoryMemory {
	/**
	 * Maximum remembered assignments. The store is a single option, so it is
	 * bounded; once full, the least recently assigned entries are dropped.
	 *
	 * @since 1.3.0
	 */
	public const MAX_ENTRIES = 1000;

	/**
	 * Correction fields an admin may pin, alongside the category.
	 *
	 * Name and domain are deliberately absent: they are the cookie's identity and
	 * part of the key, so correcting them would orphan the entry.
	 *
	 * @since 1.5.0
	 */
	private const FIELDS = [ 'category', 'purpose', 'description', 'duration', 'provider' ];

	/**
	 * Entry sub-key holding what each pin displaced, so clearing a pin can put the
	 * scanner's own wording back. Deliberately not in {@see self::FIELDS}: it is
	 * never a correction, never replayed onto a row, and never accepted from a caller.
	 *
	 * @since 1.5.0
	 */
	private const ORIGINALS = '_scanned';

	/**
	 * Fields whose displaced value is worth keeping. Category is excluded: a move
	 * is not a correction over scanner wording, and recording one would attach an
	 * empty original to every remembered assignment.
	 *
	 * @since 1.5.0
	 */
	private const RESTORABLE = [ 'purpose', 'description', 'duration', 'provider' ];

	/**
	 * Max characters kept per key segment. Names and domains come from whatever a
	 * third party set, so the cap bounds the option's size, not just its entry
	 * count. Keys are only compared, never displayed.
	 *
	 * @since 1.3.0
	 */
	private const MAX_SEGMENT_LENGTH = 128;

	/**
	 * Record the category an admin assigned to one or more scanned cookies.
	 *
	 * Re-assigning a remembered cookie refreshes its recency, so the cap evicts
	 * genuinely stale entries first. Unregistered target categories are never
	 * recorded - the single-cookie move endpoint does not allow-list its target,
	 * and an unhonourable assignment would only take up room under the cap.
	 *
	 * @param array<int, array<string, mixed>> $cookies  Cookie rows that were moved.
	 * @param string                           $category Category ID they were moved to.
	 * @since 1.3.0
	 * @return void
	 */
	public static function remember( array $cookies, string $category ): void {
		self::remember_corrections( $cookies, [ 'category' => $category ] );
	}

	/**
	 * What the scanner reported for a field before an admin pinned over it.
	 *
	 * Returns null when nothing was displaced - either the field was never
	 * corrected, or the pin has since been cleared and the original released.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $cookie Cookie row, as stored.
	 * @param string               $field  One of self::FIELDS.
	 * @return string|null
	 */
	public static function displaced_value( array $cookie, string $field ): ?string {
		$remembered = self::all();
		$key        = self::existing_key( $cookie, $remembered );

		if ( $key === '' ) {
			return null;
		}

		$originals = $remembered[ $key ][ self::ORIGINALS ] ?? null;

		return is_array( $originals ) && array_key_exists( $field, $originals )
			? (string) $originals[ $field ]
			: null;
	}

	/**
	 * Pin one or more corrections against each cookie's identity.
	 *
	 * Merges into any existing entry, so correcting a purpose does not discard a
	 * category pinned earlier, and reuses that entry's key: a provider correction is
	 * written back onto the row, so re-deriving the key would orphan the entry.
	 *
	 * @since 1.5.0
	 * @param array<int, array<string, mixed>> $cookies     Cookies as stored.
	 * @param array<string, string>            $corrections Field => value, from self::FIELDS.
	 * @return void
	 */
	public static function remember_corrections( array $cookies, array $corrections ): void {
		$corrections = array_intersect_key( $corrections, array_flip( self::FIELDS ) );

		// Enforced here rather than at the call sites: a category that is not
		// registered would orphan the cookie out of the manager and the banner,
		// and every entry point must be held to that, not just remember().
		if ( isset( $corrections['category'] )
			&& ! in_array( $corrections['category'], self::registered_categories(), true ) ) {
			unset( $corrections['category'] );
		}

		if ( empty( $cookies ) || empty( $corrections ) ) {
			return;
		}

		$remembered = self::all();
		$dirty      = false;

		foreach ( $cookies as $cookie ) {
			if ( ! is_array( $cookie ) ) {
				continue;
			}

			$key = self::existing_key( $cookie, $remembered );
			if ( $key === '' ) {
				continue;
			}

			$existing  = $remembered[ $key ] ?? [];
			$merged    = $existing;
			$originals = is_array( $merged[ self::ORIGINALS ] ?? null ) ? $merged[ self::ORIGINALS ] : [];

			foreach ( $corrections as $field => $value ) {
				$value = is_string( $value ) ? trim( $value ) : '';

				// An emptied field clears the pin and falls back to the scanned value.
				if ( $value === '' ) {
					unset( $merged[ $field ], $originals[ $field ] );
					continue;
				}

				// What this correction displaced, kept once: clearing the pin later has
				// nothing else to restore from, because the pin is also written over the
				// scanned row so the change shows up before the next scan.
				if ( in_array( $field, self::RESTORABLE, true )
					&& ! array_key_exists( $field, $originals )
					&& ! array_key_exists( $field, $existing ) ) {
					$originals[ $field ] = (string) ( $cookie[ $field ] ?? '' );
				}

				$merged[ $field ] = $value;
			}

			unset( $merged[ self::ORIGINALS ] );
			if ( ! empty( $originals ) ) {
				$merged[ self::ORIGINALS ] = $originals;
			}

			if ( $merged === $existing ) {
				continue;
			}

			// Unset first so the re-inserted key moves to the end of the map -
			// PHP preserves insertion order, which is what bounds eviction.
			unset( $remembered[ $key ] );

			if ( ! empty( $merged ) ) {
				$remembered[ $key ] = $merged;
			}

			$dirty = true;
		}

		if ( ! $dirty ) {
			return;
		}

		if ( count( $remembered ) > self::MAX_ENTRIES ) {
			$remembered = array_slice( $remembered, -self::MAX_ENTRIES, null, true );
		}

		Update::option( SURECOOKIE_COOKIE_CATEGORY_MEMORY_OPTION, $remembered );
	}

	/**
	 * Move remembered assignments onto new keys.
	 *
	 * A cookie's identity here is `name|domain|provider`, so rewriting a stored
	 * cookie's domain would orphan the admin's manual categorisation - the
	 * assignment stays in the option keyed to a domain no row carries. Whatever
	 * rewrites must hand the key moves to this method in the same pass.
	 *
	 * Insertion order is recency order (what {@see self::MAX_ENTRIES} evicts
	 * against), so a rename keeps each entry in place rather than shuffling it to
	 * the back. A rename landing on an existing key lets the later assignment win
	 * the later position, per {@see self::remember()}.
	 *
	 * @param array<string, string> $renames Old key => new key.
	 * @since 1.3.0
	 * @return bool True when the store is already correct or the write succeeded.
	 */
	public static function rename_keys( array $renames ): bool {
		$remembered = self::all();

		if ( $remembered === [] || $renames === [] ) {
			return true;
		}

		$updated = [];
		$changed = false;

		foreach ( $remembered as $key => $category ) {
			$target = $renames[ $key ] ?? $key;

			if ( $target === '' ) {
				continue;
			}

			if ( $target !== $key ) {
				$changed = true;
			}

			unset( $updated[ $target ] );
			$updated[ $target ] = $category;
		}

		if ( ! $changed ) {
			return true;
		}

		return Update::option( SURECOOKIE_COOKIE_CATEGORY_MEMORY_OPTION, $updated );
	}

	/**
	 * Re-bucket a scan's cookies into their remembered categories.
	 *
	 * Applied to the grouped set a scan is about to persist, so a returning cookie
	 * lands back in the admin's chosen category, not the scanner's. Cookies with no
	 * remembered assignment, or one pointing at a deleted category, pass through
	 * untouched.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $cookies_by_category Cookies grouped by category ID.
	 * @since 1.3.0
	 * @return array<string, array<int, array<string, mixed>>> The same set, re-grouped where an assignment is remembered.
	 */
	public static function apply( array $cookies_by_category ): array {
		$remembered = self::all();

		if ( empty( $remembered ) || empty( $cookies_by_category ) ) {
			return $cookies_by_category;
		}

		$registered = self::registered_categories();
		$fallback   = self::unambiguous_by_name_domain( $remembered );
		$result     = $cookies_by_category;

		foreach ( $cookies_by_category as $category => $cookies ) {
			if ( ! is_array( $cookies ) ) {
				continue;
			}

			foreach ( $cookies as $index => $cookie ) {
				if ( ! is_array( $cookie ) ) {
					continue;
				}

				$entry = self::lookup_entry( $cookie, $remembered, $fallback );

				if ( empty( $entry ) ) {
					continue;
				}

				// Re-apply the admin's field corrections over what the scanner reported.
				// Without this a scan silently reverts them: inherit_from_replaced() only
				// blank-fills, so a non-blank incoming value always wins.
				$corrected = false;
				// Driven off FIELDS so a newly pinnable field cannot be stored and never replayed.
				foreach ( array_diff( self::FIELDS, [ 'category' ] ) as $field ) {
					if ( ! isset( $entry[ $field ] ) || ( $cookie[ $field ] ?? '' ) === $entry[ $field ] ) {
						continue;
					}

					$cookie[ $field ] = $entry[ $field ];
					$corrected        = true;
				}

				$assigned = is_string( $entry['category'] ?? null ) ? $entry['category'] : '';

				// A remembered category the admin has since deleted would orphan
				// the cookie out of the manager and banner - keep the scanner's bucket.
				$moves = $assigned !== '' && $assigned !== $category && in_array( $assigned, $registered, true );

				if ( ! $moves ) {
					if ( $corrected ) {
						$result[ $category ][ $index ] = $cookie;
					}
					continue;
				}

				$cookie['category'] = $assigned;

				if ( ! isset( $result[ $assigned ] ) || ! is_array( $result[ $assigned ] ) ) {
					$result[ $assigned ] = [];
				}

				unset( $result[ $category ][ $index ] );
				$result[ $assigned ][] = $cookie;
			}

			$result[ $category ] = array_values( $result[ $category ] );
		}

		return $result;
	}

	/**
	 * One-time recovery of assignments made before this store existed.
	 *
	 * The last scan's snapshot records the category the scanner reported per
	 * signature. A stored cookie in a different bucket than the scanner reported
	 * can only have been moved by the admin, so the divergence is recorded as a
	 * manual assignment. Cookies with no snapshot entry are skipped: without the
	 * scanner's verdict to compare against, a deliberate choice is
	 * indistinguishable from its default, and recording that would pin a
	 * classification the scanner may since have corrected.
	 *
	 * @since 1.3.0
	 * @return int Number of assignments recovered.
	 */
	public static function backfill_from_reported_snapshot(): int {
		$details  = Get::option( SURECOOKIE_SCANNED_DETAILS_OPTION, [], 'array' );
		$snapshot = is_array( $details ) && isset( $details['reported_snapshot']['signatures'] )
			&& is_array( $details['reported_snapshot']['signatures'] )
				? $details['reported_snapshot']['signatures']
				: [];

		if ( empty( $snapshot ) ) {
			return 0;
		}

		$stored = Get::option( SURECOOKIE_SCANNED_COOKIES_OPTION, [], 'array' );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return 0;
		}

		$recovered = 0;

		foreach ( $stored as $category => $cookies ) {
			if ( ! is_array( $cookies ) || ! is_string( $category ) ) {
				continue;
			}

			foreach ( $cookies as $cookie ) {
				if ( ! is_array( $cookie ) ) {
					continue;
				}

				$signature = (string) ( $cookie['signature_id'] ?? '' );
				$reported  = (string) ( $snapshot[ $signature ]['category'] ?? '' );

				if ( $signature === '' || $reported === '' || $reported === $category ) {
					continue;
				}

				self::remember( [ $cookie ], $category );
				$recovered++;
			}
		}

		return $recovered;
	}

	/**
	 * Drop every assignment pointing at a category, e.g. when it is deleted.
	 *
	 * @param string $category Category ID to forget.
	 * @since 1.3.0
	 * @return void
	 */
	public static function forget_category( string $category ): void {
		$remembered = self::all();
		$dirty      = false;

		foreach ( $remembered as $key => $entry ) {
			if ( ( $entry['category'] ?? '' ) !== $category ) {
				continue;
			}

			// Drop only the deleted category. A purpose or duration the admin pinned
			// against the same cookie is unrelated and must survive.
			unset( $remembered[ $key ]['category'] );

			if ( empty( $remembered[ $key ] ) ) {
				unset( $remembered[ $key ] );
			}

			$dirty = true;
		}

		if ( ! $dirty ) {
			return;
		}

		Update::option( SURECOOKIE_COOKIE_CATEGORY_MEMORY_OPTION, $remembered );
	}

	/**
	 * All remembered assignments (composite key => category ID).
	 *
	 * @since 1.3.0
	 * @return array<string, array<string, string|array<string, string>>> Composite key => correction entry.
	 */
	public static function all(): array {
		$remembered = Get::option( SURECOOKIE_COOKIE_CATEGORY_MEMORY_OPTION, [], 'array' );

		if ( ! is_array( $remembered ) ) {
			return [];
		}

		$assignments = [];

		foreach ( $remembered as $key => $entry ) {
			if ( ! is_string( $key ) || $key === '' ) {
				continue;
			}

			// Entries written before corrections existed are a bare category string.
			$entry = is_string( $entry ) ? [ 'category' => $entry ] : $entry;

			if ( ! is_array( $entry ) ) {
				continue;
			}

			$clean = [];
			foreach ( self::FIELDS as $field ) {
				$value = $entry[ $field ] ?? '';
				if ( is_string( $value ) && $value !== '' ) {
					$clean[ $field ] = $value;
				}
			}

			// Carried through the FIELDS rebuild above, which would otherwise drop it
			// on every read - and this map is what the next write merges into, so a
			// dropped original is a lost one. Empty strings are kept here: "the
			// scanner reported nothing" is exactly what clearing has to restore.
			$originals = $entry[ self::ORIGINALS ] ?? null;
			if ( is_array( $originals ) ) {
				$kept = [];
				foreach ( self::RESTORABLE as $field ) {
					if ( isset( $originals[ $field ] ) && is_string( $originals[ $field ] ) ) {
						$kept[ $field ] = $originals[ $field ];
					}
				}

				if ( ! empty( $kept ) ) {
					$clean[ self::ORIGINALS ] = $kept;
				}
			}

			if ( ! empty( $clean ) ) {
				$assignments[ $key ] = $clean;
			}
		}

		return $assignments;
	}

	/**
	 * The category remembered for one cookie, if any.
	 *
	 * @param array<string, mixed> $cookie Cookie row. Only name, domain and provider are read.
	 * @since 1.3.0
	 * @return string Category ID, or an empty string when nothing is remembered.
	 */
	public static function remembered_category( array $cookie ): string {
		$remembered = self::all();

		if ( empty( $remembered ) ) {
			return '';
		}

		$entry = self::lookup_entry( $cookie, $remembered, self::unambiguous_by_name_domain( $remembered ) );

		return is_string( $entry['category'] ?? null ) ? $entry['category'] : '';
	}

	/**
	 * Build the composite identity of a cookie: name + domain + provider.
	 *
	 * Name alone is not unique - `id`, `uid`, `_ga`-style names are set by many
	 * unrelated services - so the domain and provider are folded in. Compared
	 * case-insensitively with the leading dot stripped, matching scan-time dedup.
	 *
	 * @param array<string, mixed> $cookie Cookie row.
	 * @since 1.3.0
	 * @return string Composite key, or an empty string when the cookie has no name.
	 */
	public static function key( array $cookie ): string {
		$pair = self::name_domain_key( $cookie );

		if ( $pair === '' ) {
			return '';
		}

		return $pair . '|' . self::segment( (string) ( $cookie['provider'] ?? '' ) );
	}

	/**
	 * The key this cookie's corrections belong under.
	 *
	 * The provider is part of the key and is itself correctable, so a second correction
	 * would otherwise land on a new key and orphan the first. An entry already held for
	 * this name and domain keeps its key, on the same unambiguity rule reads use.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed>                                       $cookie     Cookie row as stored.
	 * @param array<string, array<string, string|array<string, string>>> $remembered Entry map.
	 * @return string
	 */
	private static function existing_key( array $cookie, array $remembered ): string {
		$key = self::key( $cookie );

		if ( $key === '' || isset( $remembered[ $key ] ) ) {
			return $key;
		}

		$pair    = self::name_domain_key( $cookie );
		$matches = array_filter(
			array_keys( $remembered ),
			static fn( string $held ): bool => implode( '|', array_slice( explode( '|', $held ), 0, 2 ) ) === $pair
		);

		return count( $matches ) === 1 ? (string) reset( $matches ) : $key;
	}

	/**
	 * The remembered entry for a cookie, or an empty array.
	 *
	 * Exact identity first, then name+domain, but only when that pair resolves to a
	 * single entry - a cookie's provider can change between scans (an observed row only
	 * picks one up once its service enters the catalog, and the scan-history diff
	 * carries no provider at all).
	 *
	 * @since 1.5.0
	 * @param array<string, mixed>                                       $cookie     Cookie to look up.
	 * @param array<string, array<string, string|array<string, string>>> $remembered Entry map.
	 * @param array<string, array<string, string|array<string, string>>> $fallback   name|domain entries.
	 * @return array<string, string|array<string, string>>
	 */
	private static function lookup_entry( array $cookie, array $remembered, array $fallback ): array {
		$key = self::key( $cookie );

		if ( $key === '' ) {
			return [];
		}

		$entry = $remembered[ $key ] ?? ( $fallback[ self::name_domain_key( $cookie ) ] ?? [] );

		return is_array( $entry ) ? $entry : [];
	}

	/**
	 * Collapse the assignment map to name+domain, keeping only pairs every
	 * remembered provider agrees on.
	 *
	 * Two cookies with the same name and domain but different providers can
	 * legitimately differ in category, so an ambiguous pair is dropped rather than
	 * guessed - the exact composite key still matches it.
	 *
	 * @param array<string, array<string, string|array<string, string>>> $remembered Correction entries.
	 * @since 1.3.0
	 * @return array<string, array<string, string|array<string, string>>> name|domain => entry, unambiguous only.
	 */
	private static function unambiguous_by_name_domain( array $remembered ): array {
		$grouped = [];

		foreach ( $remembered as $key => $entry ) {
			// Drop the provider segment of `name|domain|provider`.
			$pair = implode( '|', array_slice( explode( '|', $key ), 0, 2 ) );

			// Compare whole entries, not just categories: two providers agreeing on a
			// category but not on a purpose are still ambiguous.
			ksort( $entry );
			$grouped[ $pair ][ (string) wp_json_encode( $entry ) ] = $entry;
		}

		$unambiguous = [];

		foreach ( $grouped as $pair => $entries ) {
			if ( count( $entries ) === 1 ) {
				$unambiguous[ $pair ] = reset( $entries );
			}
		}

		return $unambiguous;
	}

	/**
	 * IDs of the currently registered cookie categories, defaults and custom.
	 *
	 * @since 1.3.0
	 * @return array<int, string>
	 */
	private static function registered_categories(): array {
		return array_values(
			array_filter(
				array_column( (array) Settings::get( 'cookie_categories' ), 'id' ),
				'is_string'
			)
		);
	}

	/**
	 * The name+domain half of a cookie's identity.
	 *
	 * @param array<string, mixed> $cookie Cookie row.
	 * @since 1.3.0
	 * @return string `name|domain`, or an empty string when the cookie has no name.
	 */
	private static function name_domain_key( array $cookie ): string {
		$name = self::segment( (string) ( $cookie['name'] ?? '' ) );

		if ( $name === '' ) {
			return '';
		}

		// A leading dot is a host-only vs domain-wide marker, not part of identity.
		return $name . '|' . self::segment( ltrim( trim( (string) ( $cookie['domain'] ?? '' ) ), '.' ) );
	}

	/**
	 * Normalise one key segment: trimmed, lowercased and length-capped.
	 *
	 * @param string $value Raw value.
	 * @since 1.3.0
	 * @return string
	 */
	private static function segment( string $value ): string {
		return substr( strtolower( trim( $value ) ), 0, self::MAX_SEGMENT_LENGTH );
	}
}
