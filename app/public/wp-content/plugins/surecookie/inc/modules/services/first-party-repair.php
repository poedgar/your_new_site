<?php
/**
 * One-time repair of stored first-party placeholder domains.
 *
 * Until the `.domain.com` placeholder was resolved to the site's own host, two
 * write paths copied it verbatim into stored rows -
 * {@see Declared_Cookies::transform()} (scan-declared) and
 * {@see Installed_Services::install()} (Known Service) - and those rows render to
 * visitors in the cookie policy's Domain column. Nothing converges them:
 *
 *  - A scan-declared row whose service was never observed re-emits with the same
 *    `declared:{slug}:{name}` signature, so it heals on the next scan.
 *  - A declared row WITH an observed twin does not: once the forward fix collides
 *    them the survivor keeps the observed signature, the declared one is never
 *    reported again, and `reconcile_declared_cookies()` keeps the stale row too
 *    (`catalog_lookup()` matches slug+name, never the domain).
 *  - A custom (installed) row has no rewriter; `install()` is one-shot.
 *
 * So this rewrites the domain, folds the duplicates the placeholder created, and
 * moves the affected {@see CookieCategoryMemory} keys with them - converging on
 * the state a fixed install-then-scan would have produced.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.3.0
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\Functions\Cookie_Identity;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Services\CookieCategoryMemory;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * First_Party_Repair
 *
 * @since 1.3.0
 */
class First_Party_Repair {
	/**
	 * Survivor precedence when the repair uncovers two rows for one cookie.
	 *
	 * OBSERVED wins: a later scan keeps rewriting it, so deleting it is futile - it
	 * returns by signature. DECLARED is re-derivable from the catalog; CUSTOM is
	 * what `install()` would have skipped creating had the dedup key matched.
	 *
	 * @since 1.3.0
	 */
	private const RANK_OBSERVED = 0;
	private const RANK_DECLARED = 1;
	private const RANK_CUSTOM   = 2;

	/**
	 * Fields a losing row donates to the survivor when the survivor lacks them.
	 * Exactly the set {@see \SureCookie\Admin\Sync::merge_declared_cookies()}
	 * transplants at scan time, so a repaired site describes the cookie identically
	 * to a freshly scanned one.
	 *
	 * `category` is deliberately absent: it decides whether a cookie survives a
	 * visitor's decline, and silently reclassifying one during an upgrade is a
	 * compliance-visible act nobody asked for.
	 *
	 * @since 1.3.0
	 */
	private const CURATED_FIELDS = [ 'provider', 'purpose', 'description', 'duration' ];

	/**
	 * Repair every stored placeholder domain on this site.
	 *
	 * Idempotent by convergence rather than by a flag: a second pass finds no
	 * placeholder row, returns before touching anything, and writes nothing.
	 *
	 * @since 1.3.0
	 * @throws \RuntimeException When the site host cannot be resolved, or a store cannot be written.
	 * @return void
	 */
	public static function run(): void {
		if ( Cookie_Identity::first_party_domain() === '' ) {
			throw new \RuntimeException( 'SureCookie: cannot resolve this site host, so first-party cookie domains were left untouched.' );
		}

		$scanned = get_option( SURECOOKIE_SCANNED_COOKIES_OPTION, [] );
		$scanned = is_array( $scanned ) ? $scanned : [];
		$custom  = (array) Settings::get( 'custom_cookies' );

		$rows = self::collect_rows( $scanned, $custom );

		if ( ! self::has_placeholder( $rows ) ) {
			return;
		}

		$rows    = self::rewrite_domains( $rows );
		$rows    = self::fold_duplicates( $rows );
		$renames = self::key_moves( $rows );

		[ $scanned, $scanned_changed ]             = self::rebuild_scanned( $scanned, $rows );
		[ $custom, $custom_changed, $dropped_ids ] = self::rebuild_custom( $custom, $rows );

		/*
		 * Memory first, deliberately: the rename map is built from the rows'
		 * PRE-rewrite domains, so a crash after the stores were written would leave
		 * a retry unable to rebuild it. This order is crash-safe - the retry
		 * recomputes the same map and re-applying it to a renamed store is a no-op.
		 */
		if ( ! CookieCategoryMemory::rename_keys( $renames ) ) {
			throw new \RuntimeException( 'SureCookie: could not move the remembered cookie categories.' );
		}

		if ( $scanned_changed && ! Update::option( SURECOOKIE_SCANNED_COOKIES_OPTION, $scanned ) ) {
			throw new \RuntimeException( 'SureCookie: could not write the repaired scanned cookies.' );
		}

		if ( $custom_changed && ! Settings::update( 'custom_cookies', $custom ) ) {
			throw new \RuntimeException( 'SureCookie: could not write the repaired custom cookies.' );
		}

		if ( ! self::prune_registry( $dropped_ids ) ) {
			throw new \RuntimeException( 'SureCookie: could not update the installed-services registry.' );
		}
	}

	/**
	 * Flatten both cookie stores into one addressable working set.
	 *
	 * @param array<string, mixed> $scanned Scanned cookies, grouped by category.
	 * @param array<string, mixed> $custom  Custom cookies, keyed by id.
	 * @since 1.3.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_rows( array $scanned, array $custom ): array {
		$rows = [];
		$seen = 0;

		foreach ( $scanned as $category => $cookies ) {
			foreach ( (array) $cookies as $index => $cookie ) {
				if ( ! is_array( $cookie ) || (string) ( $cookie['name'] ?? '' ) === '' ) {
					continue;
				}

				$declared = ( $cookie['source'] ?? '' ) === 'declared';

				$rows[] = [
					'store'    => 'scanned',
					'category' => (string) $category,
					'index'    => $index,
					'rank'     => $declared ? self::RANK_DECLARED : self::RANK_OBSERVED,
					// Only the plugin writes the placeholder;
					// a hand-typed one is the admin's own value and is never rewritten.
					'managed'  => $declared,
					'cookie'   => $cookie,
					'seen'     => $seen,
					'deleted'  => false,
				];

				++$seen;
			}
		}

		foreach ( $custom as $id => $cookie ) {
			if ( ! is_array( $cookie ) || (string) ( $cookie['name'] ?? '' ) === '' ) {
				continue;
			}

			$rows[] = [
				'store'    => 'custom',
				'category' => (string) ( $cookie['category'] ?? '' ),
				'index'    => (string) $id,
				'rank'     => self::RANK_CUSTOM,
				'managed'  => (string) ( $cookie['service_slug'] ?? '' ) !== '',
				'cookie'   => $cookie,
				'seen'     => $seen,
				'deleted'  => false,
			];

			++$seen;
		}

		return $rows;
	}

	/**
	 * Whether any row is a plugin-written placeholder needing repair.
	 *
	 * @param array<int, array<string, mixed>> $rows Working set.
	 * @since 1.3.0
	 * @return bool
	 */
	private static function has_placeholder( array $rows ): bool {
		foreach ( $rows as $row ) {
			if ( self::is_repairable( $row ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether one row is a plugin-written placeholder.
	 *
	 * @param array<string, mixed> $row Working-set row.
	 * @since 1.3.0
	 * @return bool
	 */
	private static function is_repairable( array $row ): bool {
		/** @var array<string, mixed> $cookie */
		$cookie = $row['cookie'];

		return ! empty( $row['managed'] )
			&& Cookie_Identity::is_placeholder( (string) ( $cookie['domain'] ?? '' ) );
	}

	/**
	 * Point every repairable row at this site's host, remembering the key it had.
	 *
	 * @param array<int, array<string, mixed>> $rows Working set.
	 * @since 1.3.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function rewrite_domains( array $rows ): array {
		foreach ( $rows as $position => $row ) {
			/** @var array<string, mixed> $cookie */
			$cookie = $row['cookie'];

			$rows[ $position ]['memory_key'] = CookieCategoryMemory::key( $cookie );

			if ( ! self::is_repairable( $row ) ) {
				continue;
			}

			$rows[ $position ]['cookie']['domain'] = Cookie_Identity::resolve( (string) ( $cookie['domain'] ?? '' ) );
			$rows[ $position ]['repaired']         = true;
		}

		return $rows;
	}

	/**
	 * Fold the duplicates the placeholder created.
	 *
	 * Grouped by name, but a row only folds away when the repair itself rewrote it
	 * or it already sits on the survivor's domain. Name alone is not identity here:
	 * nothing stops a site declaring one name on two domains, and deleting the row
	 * this repair never touched would destroy an unrelated declaration.
	 *
	 * @param array<int, array<string, mixed>> $rows Working set.
	 * @since 1.3.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function fold_duplicates( array $rows ): array {
		$groups = [];

		foreach ( $rows as $position => $row ) {
			/** @var array<string, mixed> $cookie */
			$cookie = $row['cookie'];
			$groups[ Cookie_Identity::normalize_name( (string) $cookie['name'] ) ][] = $position;
		}

		foreach ( $groups as $positions ) {
			if ( count( $positions ) < 2 ) {
				continue;
			}

			$repaired = false;
			foreach ( $positions as $position ) {
				$repaired = $repaired || ! empty( $rows[ $position ]['repaired'] );
			}

			if ( ! $repaired ) {
				continue;
			}

			usort(
				$positions,
				static function ( int $left, int $right ) use ( $rows ): int {
					// Tie-broken on original position: usort is not stable on PHP 7.4.
					return [ $rows[ $left ]['rank'], $rows[ $left ]['seen'] ]
						<=> [ $rows[ $right ]['rank'], $rows[ $right ]['seen'] ];
				}
			);

			$survivor = array_shift( $positions );

			$survivor_domain = Cookie_Identity::normalize_domain( (string) ( $rows[ $survivor ]['cookie']['domain'] ?? '' ) );

			foreach ( $positions as $position ) {
				// A row the repair never rewrote, on a domain other than the survivor's,
				// is a different cookie that happens to share a name. The forward fix
				// only ever suppresses the row it would have written itself.
				if ( empty( $rows[ $position ]['repaired'] )
					&& Cookie_Identity::normalize_domain( (string) ( $rows[ $position ]['cookie']['domain'] ?? '' ) ) !== $survivor_domain ) {
					continue;
				}

				foreach ( self::CURATED_FIELDS as $field ) {
					if ( empty( $rows[ $survivor ]['cookie'][ $field ] ) && ! empty( $rows[ $position ]['cookie'][ $field ] ) ) {
						$rows[ $survivor ]['cookie'][ $field ] = $rows[ $position ]['cookie'][ $field ];
					}
				}

				$rows[ $position ]['deleted']   = true;
				$rows[ $position ]['successor'] = $survivor;
			}
		}

		return $rows;
	}

	/**
	 * The memory-key moves this repair implies.
	 *
	 * A remembered assignment is keyed `name|domain|provider`, so a rewritten
	 * domain would orphan it. A folded-away row hands its assignment to the row
	 * that replaced it. Provider drift needs no entry: the store already falls
	 * back to name+domain precisely because a provider can change between scans.
	 *
	 * @param array<int, array<string, mixed>> $rows Working set.
	 * @since 1.3.0
	 * @return array<string, string> Old key => new key.
	 */
	private static function key_moves( array $rows ): array {
		$moves = [];

		foreach ( $rows as $position => $row ) {
			$target = isset( $row['successor'] ) ? (int) $row['successor'] : $position;
			$before = (string) ( $row['memory_key'] ?? '' );

			/** @var array<string, mixed> $cookie */
			$cookie = $rows[ $target ]['cookie'];
			$after  = CookieCategoryMemory::key( $cookie );

			if ( $before !== '' && $after !== '' && $before !== $after ) {
				$moves[ $before ] = $after;
			}
		}

		return $moves;
	}

	/**
	 * Write the working set back into the scanned store.
	 *
	 * Deletions and reindexing happen in one final pass: rows address the ORIGINAL
	 * arrays by index, so reindexing mid-iteration would silently repoint survivors.
	 *
	 * @param array<string, mixed>             $scanned Scanned cookies as stored.
	 * @param array<int, array<string, mixed>> $rows    Working set.
	 * @since 1.3.0
	 * @return array{0: array<string, mixed>, 1: bool} Rebuilt store and whether it changed.
	 */
	private static function rebuild_scanned( array $scanned, array $rows ): array {
		$changed = false;

		foreach ( $rows as $row ) {
			if ( $row['store'] !== 'scanned' ) {
				continue;
			}

			$category = (string) $row['category'];
			$index    = $row['index'];

			if ( ! empty( $row['deleted'] ) ) {
				unset( $scanned[ $category ][ $index ] );
				$changed = true;
				continue;
			}

			if ( $scanned[ $category ][ $index ] !== $row['cookie'] ) {
				$scanned[ $category ][ $index ] = $row['cookie'];
				$changed                        = true;
			}
		}

		if ( $changed ) {
			foreach ( $scanned as $category => $cookies ) {
				if ( is_array( $cookies ) ) {
					$scanned[ $category ] = array_values( $cookies );
				}
			}
		}

		return [ $scanned, $changed ];
	}

	/**
	 * Write the working set back into the custom-cookie store.
	 *
	 * @param array<string, mixed>             $custom Custom cookies as stored.
	 * @param array<int, array<string, mixed>> $rows   Working set.
	 * @since 1.3.0
	 * @return array{0: array<string, mixed>, 1: bool, 2: array<int, string>} Store, whether it changed, dropped ids.
	 */
	private static function rebuild_custom( array $custom, array $rows ): array {
		$changed = false;
		$dropped = [];

		foreach ( $rows as $row ) {
			if ( $row['store'] !== 'custom' ) {
				continue;
			}

			$id = (string) $row['index'];

			if ( ! empty( $row['deleted'] ) ) {
				unset( $custom[ $id ] );
				$dropped[] = $id;
				$changed   = true;
				continue;
			}

			if ( $custom[ $id ] !== $row['cookie'] ) {
				$custom[ $id ] = $row['cookie'];
				$changed       = true;
			}
		}

		return [ $custom, $changed, $dropped ];
	}

	/**
	 * Drop folded-away custom ids from the installed-services registry.
	 *
	 * The registry ENTRY stays - the admin did install the service and its cookie
	 * is still on the site, just as the scanned row now. Only the stale id goes, so
	 * `cookie_count` stops over-reporting.
	 *
	 * @param array<int, string> $dropped_ids Custom cookie ids that were removed.
	 * @since 1.3.0
	 * @return bool
	 */
	private static function prune_registry( array $dropped_ids ): bool {
		if ( $dropped_ids === [] ) {
			return true;
		}

		$registry = get_option( SURECOOKIE_INSTALLED_SERVICES_OPTION, [] );

		if ( ! is_array( $registry ) || ! isset( $registry['installed'] ) || ! is_array( $registry['installed'] ) ) {
			return true;
		}

		$changed = false;

		foreach ( $registry['installed'] as $slug => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['cookie_ids'] ) || ! is_array( $entry['cookie_ids'] ) ) {
				continue;
			}

			$kept = array_values( array_diff( $entry['cookie_ids'], $dropped_ids ) );

			if ( $kept !== $entry['cookie_ids'] ) {
				$registry['installed'][ $slug ]['cookie_ids'] = $kept;
				$changed                                      = true;
			}
		}

		return ! $changed || Update::option( SURECOOKIE_INSTALLED_SERVICES_OPTION, $registry );
	}
}
