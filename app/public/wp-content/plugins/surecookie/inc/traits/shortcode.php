<?php
/**
 * Shortcode registration for the classes that own tags.
 *
 * Every shortcode this plugin ships is declared by a module's `Shortcode`
 * class, which maps its tags to `render_*` methods. Registration lived in each
 * constructor as its own loop, so the guards below only existed where somebody
 * had thought to add them. This puts the mapping in one place and gives every
 * module the same contract: a tag routes to `render_` plus its suffix.
 *
 * @package SureCookie\Inc\Traits
 * @since 1.5.0
 */

namespace SureCookie\Inc\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Shortcode
 *
 * @since 1.5.0
 */
trait Shortcode {
	/**
	 * Register a tag map, routing each tag to its render method.
	 *
	 * Several tags may share one suffix: the callback receives the tag that
	 * matched as its third argument, so a class exposing a family of values
	 * can answer them all from one method.
	 *
	 * @since 1.5.0
	 * @param array<string, string> $tags Tag => suffix of the `render_` method that answers it.
	 * @return void
	 */
	protected function register_shortcodes( array $tags ): void {
		foreach ( $tags as $tag => $suffix ) {
			$method = 'render_' . $suffix;

			// An empty tag is unusable, and a tag with no method behind it
			// would replace its own literal text with nothing - harder to
			// spot than an unregistered tag left standing in the content.
			// Both are reachable because some tag maps come from a filter.
			if ( $tag === '' || ! method_exists( $this, $method ) ) {
				continue;
			}

			add_shortcode(
				$tag,
				/**
				 * Dispatch through a closure so the callback carries the
				 * signature WordPress documents. A dynamic [$this, $method]
				 * pair cannot be checked against it, because which method
				 * runs is only known at runtime.
				 *
				 * @param array<string> $atts          Shortcode attributes.
				 * @param string|null   $content       Enclosed content.
				 * @param string        $shortcode_tag Tag that matched.
				 */
				function ( $atts, $content, $shortcode_tag ) use ( $method ): string {
					$rendered = $this->{$method}( $atts, $content, $shortcode_tag );

					// Every render method declares a string return, so this
					// only catches one added later that does not.
					return is_string( $rendered ) ? $rendered : '';
				}
			);
		}
	}
}
