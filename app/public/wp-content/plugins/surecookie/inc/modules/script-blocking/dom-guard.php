<?php
/**
 * DOM guard injection.
 *
 * The tag-level passes in Blocker only ever see the HTML WordPress sent, so a
 * tracker that a page builder, lazy-loader or tag manager builds in the browser
 * loads no matter what the visitor chose. This prints a small script at the very
 * top of `<head>` that intercepts the assignment of a URL to a `<script>` or
 * `<iframe>` and parks it in the same `data-surecookie-*` shape the PHP blocker
 * produces, so consentManager.js restores it on accept with no extra plumbing.
 *
 * Injected into the buffer rather than hooked onto `wp_head`, because a theme or
 * plugin can print a tag manager into `<head>` before any hook fires; the only
 * reliable "first" is the top of the finished document.
 *
 * The payload is consent-agnostic (patterns only), so it is safe to serve from a
 * full-page cache. The guard reads the consent cookie itself at runtime.
 *
 * @package SureCookie\Inc\Modules\ScriptBlocking
 * @since   1.4.0
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\Services\Pattern_Kinds;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Dom_Guard class.
 *
 * @since 1.4.0
 */
class Dom_Guard {
	use GetInstance;

	/**
	 * Cached guard source, read once per request.
	 *
	 * @var string|null
	 */
	private ?string $source = null;

	/**
	 * Insert the guard at the top of `<head>`.
	 *
	 * @since 1.4.0
	 * @param string $buffer Page HTML.
	 * @return string
	 */
	public function inject( string $buffer ): string {
		/**
		 * Filter whether the client-side DOM guard runs.
		 *
		 * The guard wraps the native `src` accessors, so this is the kill switch
		 * for a site where that conflicts with another plugin.
		 *
		 * @since 1.4.0
		 * @param bool $enabled Whether to inject the guard. Default true.
		 */
		if ( ! apply_filters( 'surecookie_dom_guard_enabled', true ) ) {
			return $buffer;
		}

		$patterns = $this->build_patterns();
		if ( empty( $patterns['s'] ) && empty( $patterns['i'] ) && empty( $patterns['y'] ) ) {
			return $buffer;
		}

		$source = $this->source();
		if ( $source === '' ) {
			return $buffer;
		}

		// Match the opening <head> tag with its attributes, so the guard lands
		// immediately inside it and ahead of every other tag on the page.
		if ( preg_match( '/<head\b[^>]*>/i', $buffer, $match, PREG_OFFSET_CAPTURE ) !== 1 ) {
			return $buffer;
		}

		// Pooling makes the two maps all but identical, and this tag is inlined
		// into every page, so the wire carries what they share once and leaves
		// `p`/`f` for the entries that genuinely differ per kind.
		$shared = array_filter(
			$patterns['s'],
			static fn( $entry, $pattern ) => ( $patterns['i'][ $pattern ] ?? null ) === $entry,
			ARRAY_FILTER_USE_BOTH
		);

		$config = wp_json_encode(
			[
				'a' => $shared,
				'p' => array_diff_key( $patterns['s'], $shared ),
				'f' => array_diff_key( $patterns['i'], $shared ),
				// The link map is `styles` over the pooled script/iframe set, so
				// only the styles bucket and the tag_scoped exclusions have to
				// ship: the guard rebuilds the rest from a/p/f. Sending the whole
				// link map would put every pattern twice into a payload that
				// rides every page.
				'y' => $patterns['y'],
				't' => array_keys( $patterns['t'] ),
				'e' => Blocking_Surface::skippable_categories(),
				'm' => (string) Settings::get( 'consent_model' ),
				// Set when the browser will re-resolve the consent model for the
				// visitor actually reading this page, so the guard holds the
				// permissive side of `m` until then (#1125).
				'v' => Utils::is_consent_decision_provisional(),
				'r' => (int) Settings::get( 'consent_renewed_at' ),
				// Core prefixes plus the host they belong to, so the guard spares
				// core exactly as Blocker::is_core_asset() does. The host is sent,
				// not read from `location`: on a domain-mapped install (WPML
				// domain-per-language) the two would disagree about which is ours.
				'c' => array_values( array_map( static fn( array $b ): string => $b[1], Blocker::core_bases() ) ),
				'h' => (string) ( Blocker::core_bases()[0][0] ?? '' ),
			],
			// HEX_TAG keeps a pattern from closing the script tag; slashes stay
			// unescaped because every pattern is a URL fragment and `\/` would
			// add roughly half a kilobyte to every page for nothing.
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
		);

		if ( $config === false ) {
			return $buffer;
		}

		// data-cfasync stops Cloudflare Rocket Loader from deferring the guard,
		// which would put it behind the scripts it exists to intercept.
		$tag = '<script data-cfasync="false" data-surecookie-guard="1">'
			. 'window.surecookieGuard=' . $config . ';'
			. $source
			. '</script>';

		$offset = $match[0][1] + strlen( $match[0][0] );

		return substr( $buffer, 0, $offset ) . $tag . substr( $buffer, $offset );
	}

	/**
	 * Flatten the blocking catalog into `pattern => [ category, service ]`, one
	 * map per element type.
	 *
	 * Pools a service's `scripts` and `iframes` patterns exactly as the tag
	 * passes do, so the two layers agree: a pattern names a third-party host, and
	 * a vendor shipping both an embed and a JS API is the same connection either
	 * way. Own kind wins a collision, so pooling only adds coverage. A producer
	 * that meant its arrays literally sets `tag_scoped`.
	 *
	 * Reads the same `surecookie_known_scripts` view the tag passes block from,
	 * and applies the same per-resource decisions on top: a resource the admin
	 * excluded becomes an allowance, and a category override wins over the catalog's. Skip
	 * either and the guard would contradict the tag passes - blocking something
	 * the admin allowed, or holding a resource under a category the visitor has
	 * already consented to, which consentManager would then restore and the
	 * guard would immediately park again.
	 *
	 * @since 1.4.0
	 * @return array{s: array<string, array{0: string|null, 1: string}>, i: array<string, array{0: string|null, 1: string}>, y: array<string, array{0: string|null, 1: string}>, t: array<string, bool>}
	 */
	private function build_patterns(): array {
		$catalog = apply_filters( 'surecookie_known_scripts', [] );

		if ( ! is_array( $catalog ) ) {
			return [
				's' => [],
				'i' => [],
				'y' => [],
				't' => [],
			];
		}

		return [
			's' => $this->flatten_for( $catalog, 'script', 'scripts' ),
			'i' => $this->flatten_for( $catalog, 'iframe', 'iframes' ),
			// Scoped as a script: a stylesheet row carries the script kind, so
			// its exclusion and override are keyed that way. Own bucket, so
			// un-pooled - a `<link rel=stylesheet>` IS where a styles pattern
			// is observed. Mirrors Blocker::get_link_patterns().
			'y' => $this->flatten_patterns( $catalog, 'script', 'styles', false ),
			// A tag_scoped rule means its arrays literally, so it must not reach
			// the link map at all; the guard drops these from the pooled set.
			't' => $this->tag_scoped_patterns( $catalog ),
		];
	}

	/**
	 * Build one element type's map, mirroring `Blocker::build_patterns()`: the
	 * own bucket wins, the pooled one only fills patterns it did not declare.
	 * The two have to agree down to the service name, or the guard parks under a
	 * label the server pass never used.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $catalog Known-scripts view.
	 * @param string               $kind    Resource kind Resource_Categories scopes by ('script'|'iframe').
	 * @param string               $own     Catalog key this element type declares under.
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	/**
	 * Patterns whose producer meant its arrays literally, as a set.
	 *
	 * The link map pools scripts and iframes cross-kind, and a `tag_scoped`
	 * rule opts out of pooling - so an admin rule scoped to "Script" must not
	 * gate a `<link>`. The server drops these in `flatten_patterns()`; the
	 * guard rebuilds the pooled set from a/p/f and so needs them by name.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $catalog Known-scripts view.
	 * @return array<string, bool>
	 */
	private function tag_scoped_patterns( array $catalog ): array {
		$scoped = [];

		foreach ( $catalog as $services ) {
			if ( ! is_array( $services ) ) {
				continue;
			}

			foreach ( $services as $service ) {
				if ( ! is_array( $service ) || empty( $service['tag_scoped'] ) ) {
					continue;
				}

				foreach ( Pattern_Kinds::buckets() as $bucket ) {
					foreach ( (array) ( $service[ $bucket ] ?? [] ) as $pattern ) {
						$pattern = (string) $pattern;
						if ( $pattern !== '' ) {
							$scoped[ $pattern ] = true;
						}
					}
				}
			}
		}

		return $scoped;
	}

	/**
	 * Build one element type's map: the own bucket wins, the pooled one only
	 * fills patterns it did not declare.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $catalog Known-scripts view.
	 * @param string               $kind    Resource kind Resource_Categories scopes by ('script'|'iframe').
	 * @param string               $own     Catalog key this element type declares under.
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	private function flatten_for( array $catalog, string $kind, string $own ): array {
		$cross = $own === 'scripts' ? 'iframes' : 'scripts';

		return $this->flatten_patterns( $catalog, $kind, $own, false )
			+ $this->flatten_patterns( $catalog, $kind, $cross, true );
	}

	/**
	 * Collect one bucket of the catalog into `pattern => [ category, service ]`,
	 * with the admin's per-resource decisions for `$kind` already applied.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $catalog    Known-scripts view.
	 * @param string               $kind       Resource kind ('script'|'iframe').
	 * @param string               $bucket     Catalog key to read ('scripts'|'iframes').
	 * @param bool                 $cross_kind Whether this is the pooled pass, which a
	 *                                         `tag_scoped` producer opts out of.
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	private function flatten_patterns( array $catalog, string $kind, string $bucket, bool $cross_kind ): array {
		$patterns = [];

		foreach ( $catalog as $category => $services ) {
			if ( ! is_array( $services ) ) {
				continue;
			}

			foreach ( $services as $service_key => $service ) {
				if ( ! is_array( $service ) || ! is_array( $service[ $bucket ] ?? null ) ) {
					continue;
				}

				// `path` narrows a rule to resources that also contain that
				// fragment. The guard matches on a single substring, so a
				// narrowed rule is skipped rather than over-blocking its host.
				if ( ! empty( $service['path'] ) ) {
					continue;
				}

				// `tag_scoped` means the producer meant its arrays literally -
				// the admin's script/iframe rule type is what sets it.
				if ( $cross_kind && ! empty( $service['tag_scoped'] ) ) {
					continue;
				}

				foreach ( $service[ $bucket ] as $pattern ) {
					$pattern = (string) $pattern;
					if ( $pattern === '' ) {
						continue;
					}

					// An allowance stays in the map as `[ null, service ]` so a narrower
					// allow can outrank a broader block, which an omission never could (#1116).
					if ( Resource_Categories::matches_excluded_src( $pattern, $kind ) ) {
						$patterns[ $pattern ] = [ null, (string) $service_key ];
						continue;
					}

					/**
					 * Filter: let the browser guard allow a pattern instead of blocking it.
					 *
					 * The guard exists to catch what the server pass cannot see,
					 * so anything the server is going to let through has to be
					 * allowed here too or the two layers disagree - which is how
					 * an always-allowed resource ended up loading in the page and
					 * still being intercepted in the browser. Pro uses this for
					 * its whitelist; the free exclusion is handled above.
					 *
					 * The subject here is the catalog PATTERN, not a URL, so an
					 * entry narrower than the pattern cannot drop it: that one
					 * resource keeps its browser-side placeholder rather than the
					 * whole service being released, which is the safe direction.
					 * Closing that gap needs per-URL evaluation in the guard.
					 *
					 * @since 1.5.0
					 * @param bool   $skip    Whether to omit this pattern.
					 * @param string $pattern Blocking pattern.
					 * @param string $kind    Resource kind ('script'|'iframe').
					 */
					if ( apply_filters( 'surecookie_guard_skip_pattern', false, $pattern, $kind ) ) {
						$patterns[ $pattern ] = [ null, (string) $service_key ];
						continue;
					}

					// The pattern carries the host the override keys match on.
					$patterns[ $pattern ] = [
						Resource_Categories::resolve( $pattern, (string) $category, $kind ),
						(string) $service_key,
					];
				}
			}
		}

		return $patterns;
	}

	/**
	 * The guard's built (minified) source.
	 *
	 * @since 1.4.0
	 * @return string
	 */
	private function source(): string {
		if ( $this->source !== null ) {
			return $this->source;
		}

		$path = SURECOOKIE_DIR . 'build/dom-guard.js';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin asset, not a remote resource.
		$contents = is_readable( $path ) ? file_get_contents( $path ) : false;

		$this->source = is_string( $contents ) ? trim( $contents ) : '';

		return $this->source;
	}
}
