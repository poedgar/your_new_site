<?php
/**
 * Multilingual Integration - String Registration
 *
 * Registers translatable banner strings with the active
 * translation plugin so they appear in its admin UI.
 *
 * Supports both Polylang (pll_register_string) and WPML
 * (wpml_register_single_string). Uses merged settings
 * (DB + defaults) so default strings not yet saved to DB
 * are still registered and translatable.
 *
 * @package SureCookie\Inc\Integrations\Multilingual
 * @since 0.0.1-beta.1
 */

namespace SureCookie\Inc\Integrations\Multilingual;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * String_Registration class.
 *
 * @since 0.0.1-beta.1
 */
class String_Registration {
	use GetInstance;

	/**
	 * WPML/Polylang string context.
	 */
	private const STRING_CONTEXT = 'SureCookie';

	/**
	 * Constructor.
	 *
	 * Registers strings immediately since this integration is loaded
	 * on init priority 999 (via Integrations_Initializer), which is
	 * late enough for both Polylang and WPML to be fully available.
	 *
	 * @since 0.0.1-beta.1
	 */
	private function __construct() {
		$this->register_strings();
	}

	/**
	 * Register all banner strings with the active translation plugin.
	 *
	 * Uses merged settings (DB values + defaults) so that both
	 * admin-customized and default strings appear in the
	 * String Translations UI for translation.
	 *
	 * @return void
	 * @since 0.0.1-beta.1
	 */
	public function register_strings(): void {
		$settings = Settings::get();

		if ( ! is_array( $settings ) ) {
			return;
		}

		$this->register_setting_strings( $settings );
		$this->register_category_strings( $settings );
	}

	/**
	 * Register translatable setting strings with Polylang and/or WPML.
	 *
	 * @param array<string, mixed> $settings Merged settings.
	 * @return void
	 * @since 0.0.1-beta.1
	 */
	private function register_setting_strings( array $settings ): void {
		foreach ( Init::TRANSLATABLE_KEYS as $key ) {
			if ( empty( $settings[ $key ] ) || ! is_string( $settings[ $key ] ) ) {
				continue;
			}

			// Rich-text fields hold multi-line HTML and must be registered as
			// multiline so translators can edit the full markup.
			$is_multiline = Init::is_multiline( $key );
			$string_name  = 'surecookie_' . $key;
			// Register the text the page renders, so Polylang's lookup by source string matches it.
			$value = Settings::clamp_length( $key, $settings[ $key ] );

			if ( Init::is_polylang_active() ) {
				pll_register_string( $string_name, $value, self::STRING_CONTEXT, $is_multiline );
			}

			if ( Init::is_wpml_active() ) {
				do_action( 'wpml_register_single_string', self::STRING_CONTEXT, $string_name, $value );
			}
		}
	}

	/**
	 * Register cookie category names and descriptions.
	 *
	 * @param array<string, mixed> $settings Merged settings.
	 * @return void
	 * @since 0.0.1-beta.1
	 */
	private function register_category_strings( array $settings ): void {
		if ( empty( $settings['cookie_categories'] ) || ! is_array( $settings['cookie_categories'] ) ) {
			return;
		}

		foreach ( $settings['cookie_categories'] as $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}

			$cat_id = sanitize_key( $category['id'] ?? '' );

			if ( empty( $cat_id ) ) {
				continue;
			}

			if ( ! empty( $category['name'] ) && is_string( $category['name'] ) ) {
				$name_key = 'surecookie_cat_' . $cat_id . '_name';

				if ( Init::is_polylang_active() ) {
					pll_register_string( $name_key, $category['name'], self::STRING_CONTEXT );
				}

				if ( Init::is_wpml_active() ) {
					do_action( 'wpml_register_single_string', self::STRING_CONTEXT, $name_key, $category['name'] );
				}
			}

			if ( ! empty( $category['description'] ) && is_string( $category['description'] ) ) {
				$desc_key = 'surecookie_cat_' . $cat_id . '_description';

				if ( Init::is_polylang_active() ) {
					pll_register_string( $desc_key, $category['description'], self::STRING_CONTEXT, true );
				}

				if ( Init::is_wpml_active() ) {
					do_action( 'wpml_register_single_string', self::STRING_CONTEXT, $desc_key, $category['description'] );
				}
			}
		}
	}
}
