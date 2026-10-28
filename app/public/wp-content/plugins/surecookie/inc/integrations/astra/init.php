<?php
/**
 * Astra Theme - Global Color Palette Integration
 *
 * Surfaces Astra's Global Color Palette (Appearance > Customize > Global >
 * Colors) as a selectable palette in SureCookie's banner Layout settings.
 *
 * The palette is injected through the `surecookie_color_palette_codes` filter,
 * which feeds both the admin picker (localized `colorPalettes`) and the
 * frontend CSS variables (Get::palette_root_css()). Both read the map at
 * request time, so changes to Astra's palette in the Customizer reflect in
 * the banner automatically - no value duplication, no manual sync.
 *
 * @package SureCookie
 * @since 1.4.0
 */

namespace SureCookie\Inc\Integrations\Astra;

use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init class.
 *
 * Initializes the Astra Global Color Palette integration.
 *
 * @since 1.4.0
 */
class Init {
	use GetInstance;

	/**
	 * SureCookie palette id registered for Astra's global palette.
	 *
	 * @since 1.4.0
	 */
	public const PALETTE_ID = 'astra';

	/**
	 * Constructor.
	 *
	 * Components load on `init` (after the theme), so the availability check
	 * is reliable here. Still re-checked inside the filter as a safety net.
	 *
	 * @since 1.4.0
	 */
	private function __construct() {
		if ( ! self::is_astra_active() ) {
			return;
		}

		add_filter( 'surecookie_color_palette_codes', [ $this, 'add_astra_palette' ] );
	}

	/**
	 * Astra palette swatches, one per non-empty slot. Colors only: the picker
	 * labels each swatch with its own hex.
	 *
	 * @since 1.4.0
	 * @return array<int, array{color: string}>
	 */
	public function get_editor_palette(): array {
		$swatches = [];

		foreach ( $this->get_astra_palette_colors() as $color ) {
			if ( $color === '' ) {
				continue;
			}

			$swatches[] = [ 'color' => $color ];
		}

		return $swatches;
	}

	/**
	 * Whether the active theme is Astra with its options API available.
	 *
	 * @since 1.4.0
	 * @return bool
	 */
	public static function is_astra_active(): bool {
		return defined( 'ASTRA_THEME_VERSION' ) && function_exists( 'astra_get_option' );
	}

	/**
	 * Register Astra's global palette as a SureCookie color palette.
	 *
	 * Slot mapping (Astra's documented global palette order):
	 *  0 Brand color        -> accept button
	 *  1 Alternate brand    -> accept button hover
	 *  3 Body text color    -> banner text
	 *  4 or 5 Primary background -> banner background (Astra 4.8.9 swapped
	 *    slots 4/5 on fresh installs; the flag below mirrors Astra's own
	 *    slot resolution so the banner always gets the PRIMARY background).
	 * The decline button mirrors the brand color, matching every built-in
	 * SureCookie palette.
	 *
	 * @param mixed $palettes Existing palette map.
	 * @since 1.4.0
	 * @return array<string, array<string, string>>
	 */
	public function add_astra_palette( $palettes = null ): array {
		$palettes = is_array( $palettes ) ? $palettes : [];

		$colors = $this->get_astra_palette_colors();

		// Without a brand color there is nothing meaningful to offer.
		if ( empty( $colors[0] ) ) {
			return $palettes;
		}

		$brand   = $colors[0];
		$bg_slot = $this->is_reorganized_palette() ? 4 : 5;

		$palettes[ self::PALETTE_ID ] = [
			'id'                => self::PALETTE_ID,
			'name'              => __( 'Astra Global Palette', 'surecookie' ),
			'acceptButton'      => $brand,
			'acceptButtonHover' => ! empty( $colors[1] ) ? $colors[1] : $brand,
			'declineButton'     => $brand,
			'bgColor'           => ! empty( $colors[ $bg_slot ] ) ? $colors[ $bg_slot ] : '#FFFFFF',
			'textColor'         => ! empty( $colors[3] ) ? $colors[3] : '#374151',
		];

		return $palettes;
	}

	/**
	 * Whether Astra runs the 4.8.9+ palette layout, where the primary
	 * background lives in slot 4 instead of slot 5. Mirrors the flag Astra
	 * itself branches on when resolving background slots.
	 *
	 * @since 1.4.0
	 * @return bool
	 */
	protected function is_reorganized_palette(): bool {
		return class_exists( 'Astra_Dynamic_CSS' )
			&& is_callable( [ 'Astra_Dynamic_CSS', 'astra_4_8_9_compatibility' ] )
			&& \Astra_Dynamic_CSS::astra_4_8_9_compatibility();
	}

	/**
	 * Read Astra's global palette colors, sanitized, with slot indexes kept.
	 * Accepts hex and strict rgba() - Astra emits slot values raw into its
	 * CSS variables, so rgba slots are legitimate and must not erase the
	 * palette. Non-integer keys and non-string values (corrupt imports) are
	 * dropped without warnings.
	 *
	 * @since 1.4.0
	 * @return array<int, string> Slot index => color ('' when invalid).
	 */
	protected function get_astra_palette_colors(): array {
		if ( ! self::is_astra_active() ) {
			return [];
		}

		$theme_colors = astra_get_option( 'global-color-palette' );
		$palette      = is_array( $theme_colors ) && ! empty( $theme_colors['palette'] ) && is_array( $theme_colors['palette'] )
			? $theme_colors['palette']
			: [];

		$colors = [];

		foreach ( $palette as $index => $color ) {
			if ( ! is_int( $index ) ) {
				continue;
			}

			$colors[ $index ] = is_string( $color ) ? Sanitize::css_color( $color ) : '';
		}

		return $colors;
	}
}
