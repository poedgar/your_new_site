<?php
/**
 * Re-Consent Shortcode.
 *
 * Registers [surecookie_reconsent_button] shortcode that renders a
 * "Cookie Preferences" button to open the consent preferences modal.
 *
 * @package SureCookie\Inc\Modules\ReConsent
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\ReConsent;

use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Traits\Shortcode as Shortcode_Trait;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Shortcode
 *
 * @since 0.0.1
 */
class Shortcode {
	use GetInstance;
	use Shortcode_Trait;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	private function __construct() {
		$this->register_shortcodes( [ 'surecookie_reconsent_button' => 'button' ] );
	}

	/**
	 * Render the re-consent button shortcode.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 * @since 0.0.1
	 */
	public function render_button( $atts ): string {
		$atts = shortcode_atts(
			[
				'label' => '',
				'class' => '',
			],
			$atts,
			'surecookie_reconsent_button'
		);

		/**
		 * Filter whether to show the cookie preferences shortcode button.
		 *
		 * Return false to completely prevent the button from rendering.
		 *
		 * @param bool                 $show Whether to show the button. Default true.
		 * @param array<string, mixed> $atts Shortcode attributes.
		 *
		 * @since 0.0.1
		 */
		if ( ! apply_filters( 'surecookie_show_reconsent_button', true, $atts ) ) {
			return '';
		}

		// Test after sanitizing: a tag-only label sanitizes to '', and a blank one renders a nameless button.
		$label = sanitize_text_field( $atts['label'] ?? '' );
		$label = Helper::is_blank( $label ) ? Helper::translated_reconsent_label() : $label;

		$extra_class = ! empty( $atts['class'] )
			? ' ' . implode( ' ', array_map( 'sanitize_html_class', explode( ' ', $atts['class'] ) ) )
			: '';

		return sprintf(
			'<a href="#" role="button" class="surecookie-reconsent-btn%s" data-surecookie-reconsent="true">%s</a>',
			esc_attr( $extra_class ),
			esc_html( $label )
		);
	}
}
