<?php
/**
 * Form Styling Handler.
 *
 * Handles form styling customization for both embed (srfm/form block)
 * and instant forms. Maps block attributes to form styling array and
 * provides filter hooks for Pro to extend with theme functionality.
 *
 * @package sureforms
 * @since 2.7.0
 */

namespace SRFM\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Form_Styling class.
 *
 * @since 2.7.0
 */
class Form_Styling {
	/**
	 * Map block attributes to form styling array.
	 *
	 * Converts camelCase block attributes to snake_case form styling keys.
	 * This allows per-embed customization when formTheme is not 'inherit'.
	 *
	 * @param array<string,mixed> $form_styling Existing form styling from post meta.
	 * @param array<string,mixed> $block_attrs  Block attributes from srfm/form block.
	 * @return array<string,mixed> Modified form styling array.
	 * @since 2.7.0
	 */
	public static function map_block_attrs_to_styling( $form_styling, $block_attrs ) {
		if ( empty( $block_attrs ) ) {
			return $form_styling;
		}

		// Form Theme - allows Pro to add custom themes.
		if ( ! empty( $block_attrs['formTheme'] ) ) {
			$form_styling['form_theme'] = Helper::get_string_value( $block_attrs['formTheme'] );

			// Apply theme styling if a theme is selected.
			$form_styling = self::apply_theme_styling( $form_styling, Helper::get_string_value( $block_attrs['formTheme'] ) );
		}

		// Colors.
		if ( ! empty( $block_attrs['primaryColor'] ) ) {
			$form_styling['primary_color'] = Helper::sanitize_css_value( $block_attrs['primaryColor'] );
		}
		if ( ! empty( $block_attrs['textColor'] ) ) {
			$form_styling['text_color'] = Helper::sanitize_css_value( $block_attrs['textColor'] );
		}
		if ( ! empty( $block_attrs['textOnPrimaryColor'] ) ) {
			$form_styling['text_color_on_primary'] = Helper::sanitize_css_value( $block_attrs['textOnPrimaryColor'] );
		}

		// Padding.
		$form_styling = self::map_dimension_attrs( $form_styling, $block_attrs, 'formPadding', 'form_padding' );

		// Border Radius.
		$form_styling = self::map_dimension_attrs( $form_styling, $block_attrs, 'formBorderRadius', 'form_border_radius' );

		// Background.
		if ( ! empty( $block_attrs['bgType'] ) && in_array( $block_attrs['bgType'], [ 'color', 'gradient', 'image' ], true ) ) {
			$form_styling['bg_type'] = $block_attrs['bgType'];
		}
		if ( ! empty( $block_attrs['bgColor'] ) ) {
			$form_styling['bg_color'] = Helper::sanitize_css_value( $block_attrs['bgColor'] );
		}
		if ( ! empty( $block_attrs['bgGradient'] ) ) {
			$form_styling['bg_gradient'] = Helper::sanitize_css_value( $block_attrs['bgGradient'] );
		}
		if ( ! empty( $block_attrs['bgImage'] ) ) {
			$form_styling['bg_image'] = esc_url_raw( Helper::get_string_value( $block_attrs['bgImage'] ) );
		}
		if ( ! empty( $block_attrs['bgImagePosition'] ) ) {
			$form_styling['bg_image_position'] = $block_attrs['bgImagePosition'];
		}
		if ( ! empty( $block_attrs['bgImageSize'] ) && in_array( $block_attrs['bgImageSize'], [ 'auto', 'cover', 'contain' ], true ) ) {
			$form_styling['bg_image_size'] = $block_attrs['bgImageSize'];
		}
		if ( ! empty( $block_attrs['bgImageRepeat'] ) && in_array( $block_attrs['bgImageRepeat'], [ 'repeat', 'no-repeat', 'repeat-x', 'repeat-y' ], true ) ) {
			$form_styling['bg_image_repeat'] = $block_attrs['bgImageRepeat'];
		}
		if ( ! empty( $block_attrs['bgImageAttachment'] ) && in_array( $block_attrs['bgImageAttachment'], [ 'scroll', 'fixed', 'local' ], true ) ) {
			$form_styling['bg_image_attachment'] = $block_attrs['bgImageAttachment'];
		}

		// Field Spacing and Button Alignment.
		if ( ! empty( $block_attrs['fieldSpacing'] ) && in_array( $block_attrs['fieldSpacing'], [ 'small', 'medium', 'large' ], true ) ) {
			$form_styling['field_spacing'] = $block_attrs['fieldSpacing'];
		}
		if ( ! empty( $block_attrs['buttonAlignment'] ) && in_array( $block_attrs['buttonAlignment'], [ 'left', 'center', 'right', 'full', 'justify' ], true ) ) {
			$form_styling['submit_button_alignment'] = $block_attrs['buttonAlignment'];
		}

		/**
		 * Filter to allow Pro to extend block attribute mapping.
		 *
		 * @param array<string,mixed> $form_styling Modified form styling array.
		 * @param array<string,mixed> $block_attrs  Original block attributes.
		 * @since 2.7.0
		 */
		return apply_filters( 'srfm_embed_block_attrs_to_styling', $form_styling, $block_attrs );
	}

	/**
	 * Apply theme styling to form styling array.
	 *
	 * Returns theme-specific CSS variable values. In the free version,
	 * this returns the original styling. Pro overrides via filter to
	 * apply predefined theme presets.
	 *
	 * @param array<string,mixed> $form_styling Current form styling array.
	 * @param string              $theme_slug   Theme identifier (e.g., 'modern', 'minimal').
	 * @return array<string,mixed> Form styling with theme values applied.
	 * @since 2.7.0
	 */
	public static function apply_theme_styling( $form_styling, $theme_slug ) {
		if ( empty( $theme_slug ) || 'default' === $theme_slug || 'inherit' === $theme_slug ) {
			return $form_styling;
		}

		/**
		 * Filter to apply theme-specific styling.
		 *
		 * Pro uses this filter to merge predefined theme CSS variables
		 * into the form styling array.
		 *
		 * @param array<string,mixed> $form_styling Current form styling array.
		 * @param string              $theme_slug   Theme identifier.
		 * @since 2.7.0
		 */
		return apply_filters( 'srfm_apply_form_theme_styling', $form_styling, $theme_slug );
	}

	/**
	 * Check if embed has custom styling (formTheme is not 'inherit').
	 *
	 * @param array<string,mixed> $block_attrs Block attributes.
	 * @return bool True if using custom embed styling.
	 * @since 2.7.0
	 */
	public static function has_custom_styling( $block_attrs ) {
		return 'inherit' !== ( $block_attrs['formTheme'] ?? 'inherit' );
	}

	/**
	 * Check if the form has default SureForms styling disabled.
	 *
	 * When enabled (via the `_srfm_forms_styling` meta set through REST/MCP, or
	 * the `srfm_disable_default_styles` filter), the form is rendered without the
	 * SureForms frontend stylesheets and inline CSS variables so the site's own
	 * CSS fully controls the form's appearance.
	 *
	 * @param int|string $form_id Form post ID.
	 * @return bool True when default styling is disabled for the form.
	 * @since 2.12.2
	 */
	public static function is_default_styling_disabled( $form_id ) {
		$form_id = absint( $form_id );
		if ( ! $form_id ) {
			return false;
		}

		$form_styling = get_post_meta( $form_id, '_srfm_forms_styling', true );
		$disabled     = is_array( $form_styling ) && ! empty( $form_styling['disable_default_styles'] );

		/**
		 * Filters whether SureForms' default frontend styling is disabled for a form.
		 *
		 * Lets themes/plugins toggle the unstyled mode programmatically, overriding the
		 * stored per-form meta. Return true to render the form without SureForms' default
		 * stylesheets and inline CSS variables.
		 *
		 * @param bool $disabled Whether default styling is disabled (from meta).
		 * @param int  $form_id  Form post ID.
		 * @since 2.12.2
		 */
		return (bool) apply_filters( 'srfm_disable_default_styles', $disabled, $form_id );
	}

	/**
	 * Check whether the SureForms frontend stylesheets can be skipped for a post.
	 *
	 * Returns true only when every SureForms form found on the post has default
	 * styling disabled. When the forms on the post cannot be determined, the
	 * stylesheets are kept as the safe default.
	 *
	 * @param \WP_Post $post Post being rendered.
	 * @return bool True when the frontend stylesheets can be skipped.
	 * @since 2.12.2
	 */
	public static function should_skip_frontend_styles( $post ) {
		if ( SRFM_FORMS_POST_TYPE === $post->post_type ) {
			$form_ids = [ $post->ID ];
		} else {
			$form_ids = self::get_form_ids_from_content( $post->post_content );
		}

		if ( empty( $form_ids ) ) {
			return false;
		}

		foreach ( $form_ids as $form_id ) {
			if ( ! self::is_default_styling_disabled( $form_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Extract SureForms form IDs from post content.
	 *
	 * Looks for srfm/form blocks (including nested ones) and [sureforms]
	 * shortcodes.
	 *
	 * @param string $content Post content.
	 * @return array<int> Unique form IDs found in the content.
	 * @since 2.12.2
	 */
	public static function get_form_ids_from_content( $content ) {
		$form_ids = [];

		// Also parse when the content embeds reusable/synced patterns (core/block
		// refs) — a form inside a pattern appears as wp:block {"ref":N}, which
		// has_block( 'srfm/form' ) alone would never see.
		if ( has_block( 'srfm/form', $content ) || has_block( 'core/block', $content ) ) {
			$visited_refs = [];
			$form_ids     = self::collect_form_block_ids( parse_blocks( $content ), $visited_refs );
		}

		if ( has_shortcode( $content, 'sureforms' ) && preg_match_all( '/' . get_shortcode_regex( [ 'sureforms' ] ) . '/', $content, $matches ) ) {
			foreach ( $matches[3] as $atts_string ) {
				$atts = shortcode_parse_atts( $atts_string );
				if ( is_array( $atts ) && ! empty( $atts['id'] ) ) {
					$form_ids[] = absint( $atts['id'] );
				}
			}
		}

		return array_values( array_unique( array_filter( $form_ids ) ) );
	}

	/**
	 * Recursively collect form IDs from parsed srfm/form blocks, following
	 * reusable/synced pattern references (core/block) into their wp_block posts.
	 *
	 * @param array<mixed>     $blocks       Parsed blocks from parse_blocks().
	 * @param array<int, true> $visited_refs Reusable-block post IDs already expanded,
	 *                                       keyed by ID — guards against reference cycles.
	 * @return array<int> Form IDs found in srfm/form blocks.
	 * @since 2.12.2
	 */
	private static function collect_form_block_ids( $blocks, &$visited_refs = [] ) {
		$form_ids = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
			if ( isset( $block['blockName'] ) && 'srfm/form' === $block['blockName'] && ! empty( $attrs['id'] ) && is_scalar( $attrs['id'] ) ) {
				$form_ids[] = absint( $attrs['id'] );
			}
			// Reusable/synced pattern: expand the referenced wp_block post so a
			// form living inside a pattern is detected like an inline block.
			if ( isset( $block['blockName'] ) && 'core/block' === $block['blockName'] && ! empty( $attrs['ref'] ) && is_scalar( $attrs['ref'] ) ) {
				$ref = absint( $attrs['ref'] );
				if ( $ref && ! isset( $visited_refs[ $ref ] ) ) {
					$visited_refs[ $ref ] = true;
					$ref_post             = get_post( $ref );
					if ( $ref_post instanceof \WP_Post && 'wp_block' === $ref_post->post_type && 'publish' === $ref_post->post_status && '' !== $ref_post->post_content ) {
						$form_ids = array_merge( $form_ids, self::collect_form_block_ids( parse_blocks( $ref_post->post_content ), $visited_refs ) );
					}
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$form_ids = array_merge( $form_ids, self::collect_form_block_ids( $block['innerBlocks'], $visited_refs ) );
			}
		}

		return $form_ids;
	}

	/**
	 * Map dimension block attributes (Top/Right/Bottom/Left/Unit) to form styling.
	 *
	 * @param array<string,mixed> $form_styling Form styling array.
	 * @param array<string,mixed> $block_attrs  Block attributes.
	 * @param string              $attr_prefix  Block attribute prefix (e.g., 'formPadding').
	 * @param string              $style_prefix Form styling key prefix (e.g., 'form_padding').
	 * @return array<string,mixed> Modified form styling array.
	 * @since 2.7.0
	 */
	private static function map_dimension_attrs( $form_styling, $block_attrs, $attr_prefix, $style_prefix ) {
		$sides = [ 'Top', 'Right', 'Bottom', 'Left' ];

		foreach ( $sides as $side ) {
			$attr_key  = $attr_prefix . $side;
			$style_key = $style_prefix . '_' . strtolower( $side );

			if ( isset( $block_attrs[ $attr_key ] ) && is_scalar( $block_attrs[ $attr_key ] ) ) {
				$form_styling[ $style_key ] = floatval( $block_attrs[ $attr_key ] );
			}
		}

		$unit_attr_key  = $attr_prefix . 'Unit';
		$unit_style_key = $style_prefix . '_unit';

		if ( ! empty( $block_attrs[ $unit_attr_key ] ) && in_array( $block_attrs[ $unit_attr_key ], [ 'px', 'em', 'rem', '%', 'vw', 'vh' ], true ) ) {
			$form_styling[ $unit_style_key ] = $block_attrs[ $unit_attr_key ];
		}

		return $form_styling;
	}
}
