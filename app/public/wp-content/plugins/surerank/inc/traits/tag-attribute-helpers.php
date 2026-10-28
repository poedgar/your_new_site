<?php
/**
 * Trait Tag_Attribute_Helpers
 *
 * @package surerank
 * @since 1.7.4
 */

namespace SureRank\Inc\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers for in-place HTML tag attribute manipulation.
 *
 * Used by Image_Seo and Link_Seo to avoid duplicating the same
 * regex-based replace/inject logic in both classes.
 *
 * @since 1.7.4
 */
trait Tag_Attribute_Helpers {

	/**
	 * Replace the value of an existing attribute in place. Lookbehind on
	 * whitespace/quote prevents matching `alt` inside `data-alt`;
	 * preg_replace_callback prevents PCRE from interpreting $N / \N in
	 * $value as a backreference.
	 *
	 * @param string $tag   Original tag.
	 * @param string $attr  Attribute name.
	 * @param string $value New value.
	 * @return string
	 * @since 1.7.4
	 */
	private function replace_attribute_value( string $tag, string $attr, string $value ): string {
		$replaced = preg_replace_callback(
			'/(?<=[\s"\'])' . preg_quote( $attr, '/' ) . '\s*=\s*(["\'])[^"\']*\1/i',
			static function () use ( $attr, $value ) {
				return esc_attr( $attr ) . '="' . esc_attr( $value ) . '"';
			},
			$tag,
			1
		);
		return $replaced ?? $tag;
	}

	/**
	 * Inject a new attribute before the tag's closing `>` or `/>`.
	 *
	 * @param string $tag   Original tag.
	 * @param string $attr  Attribute name.
	 * @param string $value Attribute value.
	 * @return string
	 * @since 1.7.4
	 */
	private function inject_attribute( string $tag, string $attr, string $value ): string {
		$pair = ' ' . esc_attr( $attr ) . '="' . esc_attr( $value ) . '"';
		return substr( $tag, -2 ) === '/>'
			? substr( $tag, 0, -2 ) . $pair . ' />'
			: substr( $tag, 0, -1 ) . $pair . '>';
	}
}
