<?php
/**
 * Meta Resolver
 *
 * Resolves the effective schema set for an object (global schemas merged with
 * page-level overrides and exclusions) and diffs incoming save payloads down
 * to override-only meta, so page-level meta never freezes a full snapshot of
 * the global schema set.
 *
 * @package surerank
 * @since 1.10.1
 */

namespace SureRank\Inc\Schema;

use SureRank\Inc\Functions\Get;
use SureRank\Inc\Functions\Settings;

/**
 * Meta_Resolver class
 *
 * Page-level schema meta stores only page-added schemas, per-schema overrides
 * of global entries, and a list of excluded global schema keys. Global schemas
 * always remain the inherited baseline.
 *
 * @since 1.10.1
 */
class Meta_Resolver {

	/**
	 * Meta key (inside the schemas meta row) holding excluded global schema keys.
	 *
	 * Its presence also marks the new override-only meta format; legacy
	 * full-snapshot metas do not carry it.
	 */
	public const EXCLUDED_KEY = 'excluded_schemas';

	public const CONTEXT_POST = 'post';
	public const CONTEXT_TERM = 'term';
	public const CONTEXT_USER = 'user';

	/**
	 * Resolve the effective schema set for an object.
	 *
	 * @param array<string, mixed> $global Global schema map (key => schema).
	 * @param array<string, mixed> $meta   Raw schemas meta row: ['schemas' => map, 'excluded_schemas' => keys] or legacy ['schemas' => full snapshot].
	 * @return array<string, mixed> Effective schema map.
	 * @since 1.10.1
	 */
	public static function resolve( array $global, array $meta ) {
		$overrides = isset( $meta['schemas'] ) && is_array( $meta['schemas'] ) ? $meta['schemas'] : [];

		if ( array_key_exists( self::EXCLUDED_KEY, $meta ) ) {
			$excluded = self::sanitize_excluded( $meta[ self::EXCLUDED_KEY ] );
			return array_diff_key( array_merge( $global, $overrides ), array_flip( $excluded ) );
		}

		if ( empty( $overrides ) ) {
			return $global;
		}

		return self::reconcile_legacy( $global, $overrides )['schemas'];
	}

	/**
	 * Global schema keys shadowed by an object-level override.
	 *
	 * Provenance for the client: an entry in the effective set whose key is
	 * listed here carries page-level values even though the key exists in the
	 * global map, so the UI must not present it as inherited. Page-added keys
	 * (absent from the global map) are detectable client-side and are not
	 * included. For legacy full-snapshot rows every snapshot entry that
	 * reconciles onto a global key counts as an override — the stored copy is
	 * what renders, whatever its values.
	 *
	 * @param array<string, mixed> $global Global schema map (key => schema).
	 * @param array<string, mixed> $meta   Raw schemas meta row (same shape as resolve()).
	 * @return array<int, string> Overridden global schema keys.
	 * @since 1.10.1
	 */
	public static function overridden_keys( array $global, array $meta ) {
		$overrides = isset( $meta['schemas'] ) && is_array( $meta['schemas'] ) ? $meta['schemas'] : [];

		if ( empty( $overrides ) ) {
			return [];
		}

		if ( array_key_exists( self::EXCLUDED_KEY, $meta ) ) {
			$excluded = self::sanitize_excluded( $meta[ self::EXCLUDED_KEY ] );
			$shadowed = array_diff_key( array_intersect_key( $overrides, $global ), array_flip( $excluded ) );
			return array_map( 'strval', array_keys( $shadowed ) );
		}

		return self::reconcile_legacy( $global, $overrides )['overridden'];
	}

	/**
	 * Get the raw schemas meta row for an object.
	 *
	 * Raw access is required: the flattened prep pipeline drops empty arrays,
	 * which would erase the excluded_schemas marker that distinguishes the new
	 * format from legacy snapshots.
	 *
	 * @param string $object_type 'post' | 'term' | 'user'.
	 * @param int    $object_id   Object ID.
	 * @return array<string, mixed> Raw meta row or empty array.
	 * @since 1.10.1
	 */
	public static function get_raw_meta( string $object_type, int $object_id ) {
		switch ( $object_type ) {
			case self::CONTEXT_POST:
				$meta = Get::post_meta( $object_id, 'surerank_settings_schemas', true );
				break;
			case self::CONTEXT_TERM:
				$meta = Get::term_meta( $object_id, 'surerank_settings_schemas', true );
				break;
			case self::CONTEXT_USER:
				$meta = Get::user_meta( $object_id, 'surerank_settings_schemas', true );
				break;
			default:
				return [];
		}

		return is_array( $meta ) ? $meta : [];
	}

	/**
	 * Diff a full client payload against the global schema set.
	 *
	 * Entries equal to their global counterpart are dropped (they stay
	 * inherited); differing or page-added entries are kept as overrides with
	 * parent => true; global schemas visible in this context but absent from
	 * the payload become exclusions.
	 *
	 * $baseline is the globals snapshot the client session was seeded with.
	 * The client round-trips the full effective set, so an editor left open
	 * across a global edit would otherwise re-send stale values that read as
	 * page-level overrides, freezing them. An entry equal to its baseline was
	 * never touched by the user and keeps inheriting the live global; and only
	 * globals present in the baseline may be excluded — a global added (or
	 * rule-revealed) after the session loaded is missing from the payload
	 * because the client never saw it, not because the user deleted it. An
	 * EMPTY baseline is meaningful: the session saw zero globals, so nothing
	 * may be excluded.
	 *
	 * Null means no baseline was sent at all (REST, MCP, or any other
	 * non-editor caller), and no new exclusion may be made. A caller that
	 * never told us which globals it saw cannot be read as having deleted the
	 * ones it left out: inferring deletion from silence let a partial payload
	 * silently strip every global schema from the object. Such callers add and
	 * override only; removing an inherited global is an editor operation.
	 * Exclusions the object already stores are kept, including ones for globals
	 * its display rules hide right now, unless the payload sends that global
	 * back, so such a save never restores a removed schema.
	 *
	 * @param array<string, mixed>      $payload        Full schema map sent by the client.
	 * @param array<string, mixed>      $global         Global schema map.
	 * @param string                    $context        'post' | 'term' | 'user'.
	 * @param string                    $object_subtype Post type or taxonomy (unused for user).
	 * @param int                       $object_id      Object ID.
	 * @param array<string, mixed>|null $baseline       Global schema map as the client saw it at load time, or null when not sent.
	 * @return array{schemas: array<string, mixed>, excluded_schemas: array<int, string>}
	 * @since 1.10.1
	 */
	public static function diff( array $payload, array $global, string $context, string $object_subtype = '', int $object_id = 0, ?array $baseline = null ) {
		$overrides = [];

		foreach ( $payload as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( isset( $global[ $key ] ) && is_array( $global[ $key ] ) && self::equals_global( $entry, $global[ $key ], $context ) ) {
				continue; // Unchanged — keep inheriting.
			}

			if ( isset( $baseline[ $key ] ) && is_array( $baseline[ $key ] ) && self::equals_global( $entry, $baseline[ $key ], $context ) ) {
				continue; // Untouched since the session loaded — the live global wins.
			}

			// Overrides render unconditionally on their own object; display
			// conditions only apply to inherited globals.
			unset( $entry['show_on'], $entry['not_show_on'] );
			$entry['parent']   = true;
			$overrides[ $key ] = $entry;
		}

		$excluded = [];
		if ( null === $baseline ) {
			// Keep every stored exclusion, visible here or not: a display rule that
			// hides the global today may match this object again later.
			foreach ( self::stored_excluded_keys( $context, $object_id ) as $stored_key ) {
				if ( isset( $payload[ $stored_key ] ) && is_array( $payload[ $stored_key ] ) ) {
					continue; // Sent back on purpose; restore it.
				}
				$excluded[] = $stored_key;
			}
		} else {
			foreach ( self::visible_global_keys( $global, $context, $object_subtype, $object_id ) as $global_key ) {
				if ( array_key_exists( $global_key, $payload ) ) {
					continue;
				}
				if ( ! array_key_exists( $global_key, $baseline ) ) {
					continue; // The client never saw this global; absence is not a deletion.
				}
				$excluded[] = (string) $global_key;
			}
		}

		return [
			'schemas'          => $overrides,
			self::EXCLUDED_KEY => $excluded,
		];
	}

	/**
	 * Normalize a schema entry for comparison.
	 *
	 * Strips keys that differ between the stored global entry and what the
	 * editor round-trips (parent flag, display conditions) and decodes HTML
	 * entities, since GET responses are entity-decoded before reaching the
	 * client.
	 *
	 * @param array<int|string, mixed> $schema Schema entry.
	 * @return array<int|string, mixed> Normalized entry.
	 * @since 1.10.1
	 */
	public static function normalize( array $schema ) {
		unset( $schema['parent'], $schema['show_on'], $schema['not_show_on'] );

		$normalized = self::comparable( $schema );
		return is_array( $normalized ) ? $normalized : [];
	}

	/**
	 * Reduce a value to a strictly comparable canonical form.
	 *
	 * Scalars become strings the way the meta pipeline itself coerces them
	 * (true => '1', false/null => ''), keys are sorted so array order never
	 * matters, and entities are decoded to a fixpoint — GET responses are
	 * entity-decoded before reaching the client, so payload and stored global
	 * can differ by one decode level. Canonicalizing both sides lets
	 * equals_global() use strict comparison, so numeric-string edits like
	 * '1' -> '1.0' are still detected as real changes.
	 *
	 * @param mixed $value Value to canonicalize.
	 * @return mixed Canonical form.
	 * @since 1.10.1
	 */
	private static function comparable( $value ) {
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::comparable( $item );
			}
			ksort( $out );
			return $out;
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}

		if ( null === $value ) {
			return '';
		}

		if ( is_string( $value ) ) {
			// Decode to a fixpoint (bounded): a double-encoded stored value and
			// a once-decoded payload must land on the same canonical string.
			for ( $i = 0; $i < 3; $i++ ) {
				$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( $decoded === $value ) {
					break;
				}
				$value = $decoded;
			}
			// REST sanitization trims payload values; a global written outside
			// that pipeline may carry stray edge whitespace and must not read
			// as a page-level edit.
			return trim( $value );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Reconcile a legacy full-snapshot meta with the current global set.
	 *
	 * Legacy snapshots may hold keys that no longer (or never did) match the
	 * global map — default schema keys were regenerated per request before
	 * they were first persisted. Snapshot entries replace globals by key, then
	 * by title, so the effective set never renders the same schema twice.
	 * Exclusions cannot be derived (a snapshot cannot distinguish a deleted
	 * global from one added later), so no entry is dropped.
	 *
	 * Exact-key matches are claimed in a first pass so a title match can never
	 * steal a global key that a later snapshot entry owns literally. Globals
	 * sharing a title stay individually claimable: each title maps to a list
	 * of candidate keys, and a snapshot entry takes the first unclaimed one,
	 * preferring a candidate whose schema type also matches.
	 *
	 * @param array<string, mixed> $global   Global schema map.
	 * @param array<string, mixed> $snapshot Legacy snapshot map.
	 * @return array{schemas: array<string, mixed>, overridden: array<int, string>} Effective map plus the global keys the snapshot claimed.
	 * @since 1.10.1
	 */
	private static function reconcile_legacy( array $global, array $snapshot ) {
		$result  = $global;
		$claimed = [];

		$titles = [];
		foreach ( $global as $global_key => $global_entry ) {
			if ( ! is_array( $global_entry ) ) {
				continue;
			}
			$title = self::entry_title( $global_entry );
			if ( '' !== $title ) {
				$titles[ $title ][] = (string) $global_key;
			}
		}

		// Pass 1: literal key matches own their global key unconditionally.
		foreach ( $snapshot as $key => $entry ) {
			if ( is_array( $entry ) && isset( $global[ $key ] ) ) {
				$result[ $key ]           = $entry;
				$claimed[ (string) $key ] = true;
			}
		}

		// Pass 2: remaining entries match by title (type as tie-break), else
		// they are page-added schemas with no global counterpart.
		foreach ( $snapshot as $key => $entry ) {
			if ( ! is_array( $entry ) || isset( $global[ $key ] ) ) {
				continue;
			}

			$candidates = $titles[ self::entry_title( $entry ) ] ?? [];
			$global_key = '';
			$fallback   = '';
			foreach ( $candidates as $candidate ) {
				if ( isset( $claimed[ $candidate ] ) ) {
					continue;
				}
				if ( '' === $fallback ) {
					$fallback = $candidate;
				}
				$candidate_entry = isset( $global[ $candidate ] ) && is_array( $global[ $candidate ] ) ? $global[ $candidate ] : [];
				if ( self::entry_type( $candidate_entry ) === self::entry_type( $entry ) ) {
					$global_key = $candidate;
					break;
				}
			}
			if ( '' === $global_key ) {
				$global_key = $fallback;
			}

			if ( '' !== $global_key ) {
				$result[ $global_key ]  = $entry;
				$claimed[ $global_key ] = true;
				continue;
			}

			$result[ $key ] = $entry; // Page-added schema, no global counterpart.
		}

		return [
			'schemas'    => $result,
			'overridden' => array_map( 'strval', array_keys( $claimed ) ),
		];
	}

	/**
	 * Normalized title of a schema entry for legacy matching.
	 *
	 * @param array<string, mixed> $entry Schema entry.
	 * @return string Lowercased trimmed title, '' when absent.
	 * @since 1.10.1
	 */
	private static function entry_title( array $entry ) {
		return isset( $entry['title'] ) && is_scalar( $entry['title'] ) ? strtolower( trim( (string) $entry['title'] ) ) : '';
	}

	/**
	 * Normalized schema type of an entry for legacy tie-breaking.
	 *
	 * @param array<string, mixed> $entry Schema entry.
	 * @return string Lowercased type ('@type' field first, then type), '' when absent.
	 * @since 1.10.1
	 */
	private static function entry_type( array $entry ) {
		$fields = isset( $entry['fields'] ) && is_array( $entry['fields'] ) ? $entry['fields'] : [];
		$type   = isset( $fields['@type'] ) && is_scalar( $fields['@type'] ) ? $fields['@type'] : ( $entry['type'] ?? '' );
		return is_scalar( $type ) ? strtolower( trim( (string) $type ) ) : '';
	}

	/**
	 * Compare a payload entry against its global counterpart.
	 *
	 * Term and user GET responses rewrite meta variables (%title% =>
	 * %term_title% / %author_name%) before the client sees them, so equality
	 * is accepted against the plain or the context-transformed global entry.
	 *
	 * @param array<string, mixed> $entry        Payload entry.
	 * @param array<string, mixed> $global_entry Global entry.
	 * @param string               $context      'post' | 'term' | 'user'.
	 * @return bool
	 * @since 1.10.1
	 */
	private static function equals_global( array $entry, array $global_entry, string $context ) {
		$normalized = self::normalize( $entry );

		// Strict comparison on canonical forms: comparable() already absorbs
		// key order, bool/null coercion, and entity-decode level, so numeric
		// strings keep their exact value ('1' !== '1.0' stays an edit).
		if ( $normalized === self::normalize( $global_entry ) ) {
			return true;
		}

		if ( self::CONTEXT_TERM === $context ) {
			$transformed = $global_entry;
			$transformed = Settings::replace_meta_variables( $transformed );
			return is_array( $transformed ) && $normalized === self::normalize( $transformed );
		}

		if ( self::CONTEXT_USER === $context ) {
			$transformed = $global_entry;
			$transformed = Settings::replace_user_meta_variables( $transformed );
			return is_array( $transformed ) && $normalized === self::normalize( $transformed );
		}

		return false;
	}

	/**
	 * Global schema keys visible to the client in the given context.
	 *
	 * Post and term GET responses rule-filter global schemas before the client
	 * sees them; user responses do not, so every global key counts as visible
	 * there. Only visible keys can be excluded — an invisible one was never in
	 * the payload to begin with.
	 *
	 * @param array<string, mixed> $global         Global schema map.
	 * @param string               $context        'post' | 'term' | 'user'.
	 * @param string               $object_subtype Post type or taxonomy.
	 * @param int                  $object_id      Object ID.
	 * @return array<int, string> Visible global schema keys.
	 * @since 1.10.1
	 */
	private static function visible_global_keys( array $global, string $context, string $object_subtype, int $object_id ) {
		$keys = [];

		foreach ( $global as $key => $schema ) {
			if ( ! is_array( $schema ) ) {
				continue;
			}

			if ( self::CONTEXT_USER === $context ) {
				$keys[] = (string) $key;
				continue;
			}

			if ( Validator::validate_schema_rules( $schema, $object_subtype, $object_id, self::CONTEXT_TERM === $context ) ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}

	/**
	 * Excluded global schema keys the object already stores.
	 *
	 * Legacy full-snapshot rows carry no exclusion list and never hide a
	 * global (resolve() restores missing ones), so they return none.
	 *
	 * @param string $context   'post' | 'term' | 'user'.
	 * @param int    $object_id Object ID.
	 * @return array<int, string>
	 * @since 1.10.3
	 */
	private static function stored_excluded_keys( string $context, int $object_id ) {
		if ( $object_id <= 0 ) {
			return [];
		}

		$meta = self::get_raw_meta( $context, $object_id );

		return array_key_exists( self::EXCLUDED_KEY, $meta ) ? self::sanitize_excluded( $meta[ self::EXCLUDED_KEY ] ) : [];
	}

	/**
	 * Sanitize an excluded-keys list to a flat array of strings.
	 *
	 * @param mixed $excluded Raw excluded value from meta.
	 * @return array<int, string>
	 * @since 1.10.1
	 */
	private static function sanitize_excluded( $excluded ) {
		if ( ! is_array( $excluded ) ) {
			return [];
		}

		$keys = [];
		foreach ( $excluded as $key ) {
			if ( is_scalar( $key ) && '' !== (string) $key ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}
}
