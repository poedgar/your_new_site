<?php
/**
 * Theme Color Palette - picker presets integration.
 *
 * Surfaces the active theme's color palette as preset swatches inside the
 * Custom Colors pickers (Pro). Source cascade:
 *
 *   1. Astra active            -> Astra's Global Color Palette (all 9 slots).
 *   2. Block theme (theme.json) -> the theme's active editor palette,
 *                                  including user additions from Global Styles.
 *   3. Anything else            -> no presets (the picker hides the section).
 *
 * The palette is read fresh on every admin load, so Customizer / Site Editor
 * changes stay in sync without storing copies.
 *
 * @package SureCookie
 * @since 1.4.0
 */

namespace SureCookie\Inc\Integrations\ThemePalette;

use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Integrations\Astra\Init as Astra;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init class.
 *
 * @since 1.4.0
 */
class Init {
	use GetInstance;

	/**
	 * Hard cap on offered swatches - a poisoned palette option with thousands
	 * of entries must not bloat the localized data on every admin load.
	 *
	 * @since 1.4.0
	 */
	private const MAX_SWATCHES = 50;

	/**
	 * Constructor.
	 *
	 * @since 1.4.0
	 */
	private function __construct() {
		add_filter( 'surecookie_admin_localize_data', [ $this, 'localize_theme_palette' ] );
	}

	/**
	 * Expose the active theme's palette to the admin app under `themePalette`.
	 * Empty colors mean "no palette available" and the UI hides the section.
	 *
	 * @param mixed $data Admin localized data.
	 * @since 1.4.0
	 * @return array<string, mixed>
	 */
	public function localize_theme_palette( $data = null ): array {
		$data = is_array( $data ) ? $data : [];

		$data['themePalette'] = $this->get_palette();

		return $data;
	}

	/**
	 * Resolve the active theme palette through the source cascade.
	 *
	 * @since 1.4.0
	 * @return array{label: string, colors: array<int, array{color: string}>}
	 */
	public function get_palette(): array {
		// 1. Astra's Global Color Palette.
		if ( $this->is_astra_source() ) {
			return [
				'label'  => __( 'Astra Global Palette', 'surecookie' ),
				'colors' => $this->get_astra_swatches(),
			];
		}

		// 2. Kadence stores its palette in its own option; the theme.json
		// entries are var() references, so it needs a dedicated reader.
		if ( $this->is_kadence_source() ) {
			$colors = $this->get_kadence_palette();
			if ( ! empty( $colors ) ) {
				return [
					'label'  => __( 'Kadence Color Palette', 'surecookie' ),
					'colors' => $colors,
				];
			}
		}

		// 3. Any theme exposing an editor palette with real color values:
		// block themes (theme.json) and classic themes registering
		// editor-color-palette both surface here via global settings.
		$colors = $this->get_block_theme_palette();
		if ( ! empty( $colors ) ) {
			return [
				'label'  => __( 'Theme Colors', 'surecookie' ),
				'colors' => $colors,
			];
		}

		// 4. No usable source.
		return [
			'label'  => '',
			'colors' => [],
		];
	}

	/**
	 * Whether Astra provides the palette. Wrapper kept thin for testability.
	 *
	 * @since 1.4.0
	 * @return bool
	 */
	protected function is_astra_source(): bool {
		return Astra::is_astra_active();
	}

	/**
	 * Astra's named swatches. Wrapper kept thin for testability.
	 *
	 * @since 1.4.0
	 * @return array<int, array{color: string}>
	 */
	protected function get_astra_swatches(): array {
		return Astra::get_instance()->get_editor_palette();
	}

	/**
	 * Whether the active theme is Kadence (parent or child).
	 *
	 * @since 1.4.0
	 * @return bool
	 */
	protected function is_kadence_source(): bool {
		return get_template() === 'kadence';
	}

	/**
	 * Kadence's active palette from its `kadence_global_palette` option:
	 * {"palette":[{color,slug,name},...],"second-palette":[...],"third-palette":[...],"active":"palette"}.
	 * Only the active palette's valid colors are offered.
	 *
	 * @since 1.4.0
	 * @return array<int, array{color: string}>
	 */
	protected function get_kadence_palette(): array {
		$raw = get_option( 'kadence_global_palette' );

		// Kadence stores a JSON string; tolerate an already-decoded array
		// (WP-CLI / import tooling) so the real palette isn't mistaken for
		// absent and silently replaced by defaults.
		$decoded = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : $raw;

		$active  = is_array( $decoded ) && is_string( $decoded['active'] ?? null ) ? $decoded['active'] : 'palette';
		$entries = is_array( $decoded ) && isset( $decoded[ $active ] ) && is_array( $decoded[ $active ] ) ? $decoded[ $active ] : [];

		// Fresh installs have no saved option until the Customizer is saved;
		// mirror Kadence's own palette_defaults() so swatches still appear.
		if ( empty( $entries ) ) {
			$entries = $this->get_kadence_default_palette();
		}

		return $this->to_swatches( $entries );
	}

	/**
	 * Kadence's default palette, mirroring the theme's palette_defaults().
	 *
	 * @since 1.4.0
	 * @return array<int, array{color: string}>
	 */
	protected function get_kadence_default_palette(): array {
		$defaults = [
			'#2B6CB0',
			'#215387',
			'#1A202C',
			'#2D3748',
			'#4A5568',
			'#718096',
			'#EDF2F7',
			'#F7FAFC',
			'#ffffff',
		];

		$entries = [];
		foreach ( $defaults as $color ) {
			$entries[] = [ 'color' => $color ];
		}

		return $entries;
	}

	/**
	 * The active theme's color palette from global settings: a block theme's
	 * theme.json merged with the user's Global Styles, or a classic theme's
	 * registered editor palette. User-defined colors come first, then the
	 * theme's own, deduplicated by color value. Entries whose value is not a
	 * real color (e.g. var() references) are skipped.
	 *
	 * @since 1.4.0
	 * @return array<int, array{color: string}>
	 */
	protected function get_block_theme_palette(): array {
		$palette = wp_get_global_settings( [ 'color', 'palette' ] );

		if ( ! is_array( $palette ) ) {
			return [];
		}

		// Origins in priority order: user's Global Styles additions, then the
		// theme's palette. Core's defaults are skipped - they are generic
		// WordPress colors, not the site's brand.
		$entries = array_merge(
			isset( $palette['custom'] ) && is_array( $palette['custom'] ) ? $palette['custom'] : [],
			isset( $palette['theme'] ) && is_array( $palette['theme'] ) ? $palette['theme'] : []
		);

		return $this->to_swatches( $entries );
	}

	/**
	 * Normalize raw palette entries into named swatches: string values only
	 * (non-scalar shapes from corrupt options are dropped without warnings),
	 * colors through Sanitize::css_color, deduplicated by color value, capped
	 * at MAX_SWATCHES.
	 *
	 * @param array<int, mixed> $entries Raw palette entries ({color, name|slug} maps).
	 * @since 1.4.0
	 * @return array<int, array{color: string}>
	 */
	protected function to_swatches( array $entries ): array {
		$swatches = [];
		$seen     = [];

		foreach ( $entries as $entry ) {
			if ( count( $swatches ) >= self::MAX_SWATCHES ) {
				break;
			}

			if ( ! is_array( $entry ) ) {
				continue;
			}

			$raw_color = $entry['color'] ?? '';
			$color     = is_string( $raw_color ) ? Sanitize::css_color( $raw_color ) : '';
			if ( $color === '' || isset( $seen[ $color ] ) ) {
				continue;
			}

			$seen[ $color ] = true;
			$swatches[]     = [ 'color' => $color ];
		}

		return $swatches;
	}
}
