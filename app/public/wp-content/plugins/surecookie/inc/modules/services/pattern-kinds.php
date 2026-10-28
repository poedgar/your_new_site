<?php
/**
 * The catalog's blocking-pattern buckets.
 *
 * A service's patterns are grouped by the tag family the resource arrives on,
 * because that - not the domain - decides whether the blocker can act on it.
 * Every consumer of the blocking view reads this table instead of naming
 * buckets itself; hard-coding `scripts`/`iframes` in each of them is what let a
 * stylesheet host be filed as a script and reported as blocked.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.5.0
 */

namespace SureCookie\Inc\Modules\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Pattern_Kinds
 *
 * @since 1.5.0
 */
final class Pattern_Kinds {
	/**
	 * Bucket => { resource, enforced }.
	 *
	 * `enforced` mirrors the passes in `Blocker`, which rewrites `<script>`,
	 * `<iframe>`, `<embed>`, `<object>` and `<link>` and nothing else, and the
	 * DOM guard, which wraps the same element types. `media` has no planned
	 * pass: an `<img>` is not a tag any pass can rewrite, and the image CDNs
	 * that live there are essential, so they are never gated anyway.
	 * `BlockingSurfaceTest` fails if this drifts from the passes.
	 *
	 * `styles` covers the whole stylesheet delivery chain: the
	 * `<link rel=stylesheet>` itself and the font or asset files that
	 * stylesheet then fetches. Both are gated by the link pass - the second
	 * because a theme that preloads a font hits it directly, and because with
	 * the stylesheet parked nothing ever reads the `@font-face`.
	 *
	 * `resource` is the kind a scan row for such a pattern is stored under:
	 * every scanner files a non-frame resource as a script, so a stylesheet or
	 * image host arrives on the `scripts` side of the scanned-resources option.
	 */
	private const KINDS = [
		'scripts' => [
			'resource' => 'script',
			'enforced' => true,
		],
		'iframes' => [
			'resource' => 'iframe',
			'enforced' => true,
		],
		'styles'  => [
			'resource' => 'script',
			'enforced' => true,
		],
		'media'   => [
			'resource' => 'script',
			'enforced' => false,
		],
	];

	/**
	 * Every bucket name, in catalog order.
	 *
	 * @since 1.5.0
	 * @return array<int, string>
	 */
	public static function buckets(): array {
		return array_keys( self::KINDS );
	}

	/**
	 * Buckets a blocking pass can act on, as bucket => resource kind.
	 *
	 * @since 1.5.0
	 * @return array<string, string>
	 */
	public static function enforced(): array {
		return self::filter_by_enforcement( true );
	}

	/**
	 * Buckets no blocking pass reaches, as bucket => resource kind.
	 *
	 * @since 1.5.0
	 * @return array<string, string>
	 */
	public static function unenforced(): array {
		return self::filter_by_enforcement( false );
	}

	/**
	 * Whether a blocking pass acts on this bucket.
	 *
	 * @since 1.5.0
	 * @param string $bucket Bucket name.
	 * @return bool
	 */
	public static function is_enforced( string $bucket ): bool {
		return ! empty( self::KINDS[ $bucket ]['enforced'] );
	}

	/**
	 * The resource kind ('script'|'iframe') a bucket's rows are stored under.
	 *
	 * @since 1.5.0
	 * @param string $bucket Bucket name.
	 * @return string Resource kind, or '' for an unknown bucket.
	 */
	public static function resource_kind( string $bucket ): string {
		return (string) ( self::KINDS[ $bucket ]['resource'] ?? '' );
	}

	/**
	 * Buckets whose enforcement matches, as bucket => resource kind.
	 *
	 * @since 1.5.0
	 * @param bool $enforced Enforcement to select.
	 * @return array<string, string>
	 */
	private static function filter_by_enforcement( bool $enforced ): array {
		$buckets = [];

		foreach ( self::KINDS as $bucket => $meta ) {
			if ( $meta['enforced'] === $enforced ) {
				$buckets[ $bucket ] = $meta['resource'];
			}
		}

		return $buckets;
	}
}
