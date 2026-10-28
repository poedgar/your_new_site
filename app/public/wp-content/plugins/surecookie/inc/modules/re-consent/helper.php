<?php
/**
 * Re-Consent Helper.
 *
 * Shared helpers for the re-consent menu item and shortcode so the button
 * label is resolved and translated in a single place.
 *
 * @package SureCookie\Inc\Modules\ReConsent
 * @since 1.2.1
 */

namespace SureCookie\Inc\Modules\ReConsent;

use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Integrations\Multilingual\Translation_Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Helper
 *
 * @since 1.2.1
 */
class Helper {
	/**
	 * Get the translated re-consent button label.
	 *
	 * Reads the configured `reconsent_button_label` and runs it through the
	 * multilingual integration. Shared by the nav-menu item and the shortcode
	 * so both render the same translated label.
	 *
	 * @return string Translated label, the default label when none is saved.
	 * @since 1.2.1
	 */
	public static function translated_reconsent_label(): string {
		$label = Settings::get( 'reconsent_button_label' );

		// A saved '' beats the default in Settings::get() while the settings screen shows it.
		if ( self::is_blank( $label ) ) {
			$label = __( 'Cookie Preferences', 'surecookie' );
		}

		return Translation_Filter::translate_string( $label, 'surecookie_reconsent_button_label' );
	}

	/**
	 * Whether a label renders as nothing: empty, Unicode spaces or invisible format characters (#957).
	 *
	 * @param mixed $label Label.
	 * @return bool
	 * @since 1.7.0
	 */
	public static function is_blank( $label ): bool {
		return Sanitize::is_blank( $label );
	}
}
