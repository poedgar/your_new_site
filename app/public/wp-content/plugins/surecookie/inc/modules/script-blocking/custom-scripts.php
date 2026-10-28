<?php
/**
 * Custom Blocked Scripts.
 *
 * Lets an admin manually add a script/iframe URL pattern to block under a
 * chosen cookie category (the mirror of the Pro whitelist, which always
 * allows). Entries are merged into the known-scripts dataset through the
 * `surecookie_known_scripts` filter, so the whole existing pipeline - blocker
 * rewriting, placeholder rendering, per-category consent gating and
 * restore-on-consent - applies to them with no extra blocker logic.
 *
 * @package SureCookie\Inc\Modules\ScriptBlocking
 * @since 1.3.0
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Custom_Scripts
 *
 * @since 1.3.0
 */
class Custom_Scripts {
	use GetInstance;

	/**
	 * Setting key storing the admin's custom blocked-script entries
	 * ({ name, value, category } rows).
	 *
	 * @since 1.3.0
	 */
	private const SETTING_KEY = 'custom_blocked_scripts';

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	private function __construct() {
		// Priority 25: after the bundled/remote dataset (10) and the
		// scan-detected merge (20), so an admin's explicit entry wins when the
		// same pattern already exists (the blocker's pattern map is keyed by
		// pattern - last write wins).
		add_filter( 'surecookie_known_scripts', [ $this, 'merge_custom_blocked_scripts' ], 25 );
	}

	/**
	 * Merge the admin's custom blocked scripts into the known-scripts dataset.
	 *
	 * Each entry becomes a service under its chosen category. A rule the admin
	 * typed as script-only or iframe-only, or one carrying keywords, is marked
	 * `tag_scoped` so the blocker takes its arrays literally instead of pooling
	 * them across both passes.
	 *
	 * @param mixed $scripts Known scripts grouped by category.
	 * @since 1.3.0
	 * @return mixed
	 */
	public function merge_custom_blocked_scripts( $scripts = null ) {
		if ( ! is_array( $scripts ) ) {
			return $scripts;
		}

		foreach ( $this->get_entries() as $entry ) {
			$category = $entry['category'];

			if ( ! isset( $scripts[ $category ] ) || ! is_array( $scripts[ $category ] ) ) {
				$scripts[ $category ] = [];
			}

			// Honor the rule's resource type: script-only, iframe-only, or both.
			$service = [
				'label' => $entry['name'] !== '' ? $entry['name'] : $entry['value'],
			];
			if ( $entry['type'] !== 'iframe' ) {
				// Dependent JS keywords (e.g. "fbq, fbq.push") become extra
				// patterns; the blocker matches them against inline content.
				$service['scripts'] = array_merge( [ $entry['value'] ], $entry['keywords'] );
			}
			if ( $entry['type'] !== 'script' ) {
				$service['iframes'] = [ $entry['value'] ];
			}
			if ( $entry['type'] !== 'any' || $entry['keywords'] !== [] ) {
				// Keep the blocker from pooling these patterns across kinds the
				// way it pools a catalog host: the admin picked a resource type,
				// or the rule carries keywords, which are inline-JS identifiers
				// and would match an unrelated first-party URL as a substring.
				$service['tag_scoped'] = true;
			}
			if ( $entry['location'] !== 'any' ) {
				// Region hint (head|body|footer): the blocker only matches the
				// rule's script patterns inside that page section.
				$service['location'] = $entry['location'];
			}
			if ( $entry['path'] !== '' ) {
				// Narrowing constraint: the resource must ALSO contain this
				// path/pattern, so a rule can target one file on a host.
				$service['path'] = $entry['path'];
			}

			$scripts[ $category ][ 'custom-' . md5( $entry['value'] ) ] = $service;
		}

		return $scripts;
	}

	/**
	 * Read + normalize the custom blocked-script entries.
	 *
	 * Rows are { name, value, category, type, location, keywords }; the value
	 * (URL/domain pattern) is required, the category falls back to
	 * `uncategorized` (blocked until consent by default) when missing or
	 * unknown-typed, the type limits the rule to scripts, iframes, or both
	 * (`any`, the default), the location optionally restricts matching to a
	 * page region, and keywords are dependent-JS names blocked alongside.
	 *
	 * @since 1.3.0
	 * @return array<int, array{name: string, value: string, category: string, type: string, location: string, keywords: array<int, string>, path: string}>
	 */
	private function get_entries(): array {
		$list = Settings::get( self::SETTING_KEY );
		if ( ! is_array( $list ) ) {
			return [];
		}

		$out = [];
		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$value = strtolower( trim( Sanitize::scalar( $entry['value'] ?? '' ) ) );
			if ( $value === '' ) {
				continue;
			}

			$category = sanitize_key( Sanitize::scalar( $entry['category'] ?? '' ) );
			$type     = Sanitize::scalar( $entry['type'] ?? '' );
			$location = Sanitize::scalar( $entry['location'] ?? '' );
			$path     = strtolower( trim( Sanitize::scalar( $entry['path'] ?? '' ) ) );

			// Comma-separated dependent-JS keywords, normalized to a clean list.
			$keywords = array_values(
				array_filter(
					array_map( 'trim', explode( ',', Sanitize::scalar( $entry['keywords'] ?? '' ) ) )
				)
			);

			$out[] = [
				'name'     => trim( Sanitize::scalar( $entry['name'] ?? '' ) ),
				'value'    => $value,
				'category' => $category !== '' ? $category : 'uncategorized',
				'type'     => in_array( $type, [ 'script', 'iframe' ], true ) ? $type : 'any',
				'location' => in_array( $location, [ 'head', 'body', 'footer' ], true ) ? $location : 'any',
				'keywords' => $keywords,
				'path'     => $path,
			];
		}

		return $out;
	}
}
