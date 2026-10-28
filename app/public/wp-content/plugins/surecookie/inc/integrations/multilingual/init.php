<?php
/**
 * Multilingual Integration - Initialization
 *
 * Bootstraps multilingual compatibility for the consent banner.
 * Only activates when WPML or Polylang is installed and active.
 *
 * @package SureCookie\Inc\Integrations\Multilingual
 * @since 0.0.1-beta.1
 */

namespace SureCookie\Inc\Integrations\Multilingual;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init class.
 *
 * @since 0.0.1-beta.1
 */
class Init {
	use GetInstance;

	/**
	 * Translatable string setting keys.
	 *
	 * The registration inventory, NOT a map of the frontend payload:
	 * String_Registration registers all of these, but READ_SITE_TRANSLATED_KEYS
	 * are nested under `placeholder` in the payload, so Translation_Filter's
	 * top-level loop cannot reach them. `TranslatableKeyInventoryTest` pins it.
	 *
	 * @since 0.0.1-beta.1
	 */
	public const TRANSLATABLE_KEYS = [
		'message_heading',
		'message_description',
		'accept_btn_text',
		'accept_all_btn_text',
		'decline_btn_text',
		'preferences_btn_text',
		'reconsent_button_label',
		'preferences_modal_heading',
		'preferences_modal_description',
		'consent_forwarding_description',
		'placeholder_description',
		'placeholder_button_text',
	];

	/**
	 * Keys translated where they are read, not on the frontend payload.
	 *
	 * The server-rendered placeholder overlay never passes through
	 * `surecookie_frontend_localize_data`, so the getters are the only point both
	 * builders share; flattening these into the payload would translate twice and
	 * double-fire `surecookie_translate_string`.
	 *
	 * @since 1.6.0
	 */
	public const READ_SITE_TRANSLATED_KEYS = [
		'placeholder_description',
		'placeholder_button_text',
	];

	/**
	 * Keys whose value is rich text and must survive translation as markup.
	 *
	 * The same single source of truth as TRANSLATABLE_KEYS, for the same
	 * reason: registration and both translation passes each restated it and
	 * two of them were wrong, so multilingual sites lost their markup (#1171).
	 *
	 * @since 1.5.1
	 */
	public const MULTILINE_KEYS = [
		'message_description',
		'preferences_modal_description',
		'placeholder_description',
	];

	/**
	 * Constructor.
	 *
	 * @since 0.0.1-beta.1
	 */
	private function __construct() {
		if ( ! self::is_multilingual_plugin_active() ) {
			return;
		}

		// Both WPML and Polylang use explicit registration + filter-based translation.
		// WPML admin-text (wpml-config.xml) only translates values saved in DB.
		// Explicit registration via wpml_register_single_string also covers defaults.
		String_Registration::get_instance();
		Translation_Filter::get_instance();

		/**
		 * Action: Fires after the multilingual integration is initialized.
		 *
		 * @since 0.0.1-beta.1
		 */
		do_action( 'surecookie_multilingual_initialized' );
	}

	/**
	 * Whether a translatable key holds rich text rather than plain text.
	 *
	 * @param string $key Setting key.
	 * @since 1.5.1
	 * @return bool
	 */
	public static function is_multiline( string $key ): bool {
		return in_array( $key, self::MULTILINE_KEYS, true );
	}

	/**
	 * Check if any supported multilingual plugin is active.
	 *
	 * @return bool
	 * @since 0.0.1-beta.1
	 */
	public static function is_multilingual_plugin_active(): bool {
		return self::is_wpml_active() || self::is_polylang_active();
	}

	/**
	 * Check if WPML is active.
	 *
	 * @return bool
	 * @since 0.0.1-beta.1
	 */
	public static function is_wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	/**
	 * Check if Polylang is active.
	 *
	 * @return bool
	 * @since 0.0.1-beta.1
	 */
	public static function is_polylang_active(): bool {
		return function_exists( 'pll_register_string' ) && function_exists( 'pll__' );
	}
}
