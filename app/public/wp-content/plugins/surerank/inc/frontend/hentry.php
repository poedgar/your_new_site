<?php
/**
 * Hentry Class
 * Removes the legacy hAtom `hentry` class from post wrappers so search engines
 * don't try to parse the page as (incomplete) hAtom structured data.
 *
 * @since 1.9.3
 * @package surerank
 */

namespace SureRank\Inc\Frontend;

use SureRank\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Hentry
 * Filter-controlled removal of the `hentry` post class.
 *
 * @since 1.9.3
 */
class Hentry {

	use Get_Instance;

	/**
	 * Constructor.
	 *
	 * @since 1.9.3
	 */
	protected function __construct() {
		/**
		 * Filter to remove the legacy hAtom `hentry` class from post wrappers.
		 *
		 * WordPress adds `hentry` via `post_class()`. It signals hAtom microformat
		 * structured data, but most themes don't output the matching fields
		 * (entry-title, updated, author), so search engines report incomplete
		 * structured data. Return true to strip `hentry` from the class list.
		 *
		 * Defaults to false: some themes style `.hentry` (e.g. for post spacing),
		 * so removing it can change layout. Enable it knowingly.
		 *
		 * @since 1.9.3
		 *
		 * @param bool $remove Whether to remove the `hentry` class. Default false.
		 */
		if ( apply_filters( 'surerank_remove_hentry', false ) ) {
			add_filter( 'post_class', [ $this, 'remove_hentry_class' ] );
		}
	}

	/**
	 * Remove the `hentry` class from the post class list.
	 *
	 * @param array<int, string> $classes Post classes assembled by get_post_class().
	 * @return array<int, string> Classes with `hentry` removed and re-indexed.
	 * @since 1.9.3
	 */
	public function remove_hentry_class( $classes ) {
		if ( ! is_array( $classes ) || ! in_array( 'hentry', $classes, true ) ) {
			return $classes;
		}

		return array_values( array_diff( $classes, [ 'hentry' ] ) );
	}
}
