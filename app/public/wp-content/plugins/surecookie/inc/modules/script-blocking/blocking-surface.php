<?php
/**
 * What the blocker can actually reach.
 *
 * The admin screens used to read "there is a row for it" as "it is blocked
 * until consent". It is not the same claim: the passes rewrite `<script>`,
 * `<iframe>`, `<embed>`, `<object>` and `<link>` and nothing else, and every
 * pass returns the tag untouched when the resource resolves to a skippable
 * category. An image host therefore shows as a managed, categorised,
 * apparently-blocked resource that no pass can ever act on.
 *
 * One answer, computed the way the passes decide, so every surface reports the
 * same thing and adding a pass flips them all at once.
 *
 * @package SureCookie\Inc\Modules\ScriptBlocking
 * @since 1.5.0
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

use SureCookie\Inc\Modules\Services\Pattern_Kinds;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Blocking_Surface
 *
 * @since 1.5.0
 */
final class Blocking_Surface {
	/**
	 * The resource does not arrive on a tag any pass rewrites.
	 */
	public const UNREACHABLE_TAG = 'unreachable_tag';

	/**
	 * The resource's category is released without consent, so every pass
	 * returns its tag untouched.
	 */
	public const SKIPPABLE_CATEGORY = 'skippable_category';

	/**
	 * Why the blocker will not gate this resource, or '' when it will.
	 *
	 * Reachability is decided first: a resource no pass can rewrite stays
	 * unblocked whatever category it is put in, so reporting the category would
	 * imply that recategorising it fixes something.
	 *
	 * @since 1.5.0
	 * @param string $domain   Resource domain, as stored on the row.
	 * @param string $category Category the resource is actually gated under.
	 * @return string One of the class constants, or ''.
	 */
	public static function unenforced_reason( string $domain, string $category ): string {
		if ( ! self::is_reachable( $domain ) ) {
			return self::UNREACHABLE_TAG;
		}

		if ( self::is_skippable_category( $category ) ) {
			return self::SKIPPABLE_CATEGORY;
		}

		return '';
	}

	/**
	 * Categories every pass releases without consent.
	 *
	 * The single reader of the filter: the tag passes, the browser guard, the
	 * placeholder trait and this verdict all have to agree on it, and four
	 * copies of one `apply_filters` call is how they stop agreeing.
	 *
	 * @since 1.5.0
	 * @return array<int, string>
	 */
	public static function skippable_categories(): array {
		/**
		 * Filter the categories released without consent.
		 *
		 * A resource resolving to one of these is never parked: every tag pass
		 * hands its tag back untouched and the browser guard lets it through.
		 *
		 * @since 0.0.1
		 * @param array<int, string> $categories Category ids. Default `['essential']`.
		 */
		$categories = apply_filters( 'surecookie_skippable_categories', [ 'essential' ] );

		return is_array( $categories ) ? array_values( array_filter( $categories, 'is_string' ) ) : [ 'essential' ];
	}

	/**
	 * Whether a category is released without consent.
	 *
	 * @since 1.5.0
	 * @param string $category Resolved category.
	 * @return bool
	 */
	public static function is_skippable_category( string $category ): bool {
		return in_array( $category, self::skippable_categories(), true );
	}

	/**
	 * Whether some pass can rewrite the tag this host's resources arrive on.
	 *
	 * The catalog bucket is the only evidence used. The URL a scan happened to
	 * sample is NOT: `Scan_Scripts::merge_resource()` files every unknown
	 * scanned domain as a `scripts` pattern, so the script pass reaches the
	 * host whatever that one URL pointed at. Reading `.../logo.png` off a row
	 * and calling the host out of reach contradicted the pass that was parking
	 * `.../tracker.js` on it a moment later.
	 *
	 * @since 1.5.0
	 * @param string $domain Resource domain.
	 * @return bool
	 */
	private static function is_reachable( string $domain ): bool {
		$buckets = Resource_Categories::catalog_buckets( $domain );

		// Unknown to the catalog means scan-merged, and that lands in `scripts`.
		if ( $buckets === [] ) {
			return true;
		}

		foreach ( array_keys( $buckets ) as $bucket ) {
			if ( Pattern_Kinds::is_enforced( $bucket ) ) {
				return true;
			}
		}

		return false;
	}
}
