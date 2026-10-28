<?php
/**
 * Settings
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Functions;

use SureCookie\Inc\Utils\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Settings - this class will handle all setting related values.
 *
 * @since 0.0.1
 */
class Settings {
	/**
	 * Banner button tokens in their default `button_order`. Each has a
	 * `{token}_btn_text` label setting. Mirrored in src/shared/utils/acceptAllVisibility.js.
	 *
	 * @since 1.6.0
	 */
	public const BUTTON_TOKENS = [ 'accept_all', 'accept', 'preferences', 'decline' ];

	/**
	 * Legal links the preferences modal can offer, in shipped order.
	 *
	 * @since 1.7.0
	 */
	public const POLICY_LINK_TOKENS = [ 'cookie_policy', 'privacy_policy', 'legal_notice' ];

	/**
	 * Default values.
	 *
	 * @var array<string, mixed>
	 */
	private static $defaults = [];

	/**
	 * Settings dataset - with defaults + DB merged preference.
	 *
	 * @var array<string, mixed>
	 */
	private static $settings_dataset = [];

	/**
	 * String `max` lengths by key; memoized apart from $defaults, as they hold no translated text.
	 *
	 * @var array<string, int>|null
	 */
	private static $max_lengths = null;

	/**
	 * Get plugin default settings.
	 *
	 * @return array<string, mixed>
	 * @since 0.0.1
	 */
	public static function get_settings_defaults() {
		if ( Validate::array( self::$defaults ) && Validate::not_empty( self::$defaults ) ) {
			return apply_filters( 'surecookie_settings_defaults', self::$defaults );
		}

		$settings_dataset = Options::get_all_configurations();

		$default_settings = [];

		foreach ( $settings_dataset as $key => $value ) {
			$default_settings[ $key ] = $value['default'];
		}

		self::$defaults = $default_settings;

		return apply_filters( 'surecookie_settings_defaults', self::$defaults );
	}

	/**
	 * Get settings dataset - with defaults + DB merged preference.
	 *
	 * @return array<string, mixed>
	 * @since 0.0.1
	 */
	public static function get_settings_dataset() {
		if ( Validate::array( self::$settings_dataset ) && Validate::not_empty( self::$settings_dataset ) ) {
			return apply_filters( 'surecookie_settings_dataset', self::$settings_dataset );
		}

		self::$settings_dataset = wp_parse_args( Get::option( SURECOOKIE_SETTINGS_OPTION, [], 'array' ), self::get_settings_defaults() );
		return apply_filters( 'surecookie_settings_dataset', self::$settings_dataset );
	}

	/**
	 * Get public settings dataset - Retrieve only frontend oriented settings.
	 *
	 * @return array<string, mixed>
	 * @since 0.0.1
	 */
	public static function get_public_settings_dataset() {
		$settings = self::get();

		$public_settings = [];
		$public_keys     = Options::get_frontend_options();

		foreach ( $public_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$public_settings[ $key ] = $settings[ $key ];
			} else {
				$public_settings[ $key ] = '';
			}
		}

		// Repairs sites already holding a 0, which reaches the page as the truthy
		// string "0" and renders the banner at 0px.
		$defaults = self::get_settings_defaults();
		if ( (int) ( $public_settings['banner_width'] ?? 0 ) <= 0 ) {
			$public_settings['banner_width'] = $defaults['banner_width'] ?? 650;
		}

		/*
		 * Display-only companion to `hide_unused_categories`: the ids the consent UI
		 * may render. `cookie_categories` itself is never filtered, because the
		 * consent model, the WP Consent API map and the multilingual pass all derive
		 * from it. Computed here rather than in LocalizeData so the localized page
		 * payload and the public REST route cannot disagree. Skipped entirely when
		 * the setting is off, which keeps the option reads off the default path.
		 */
		$public_settings['categories_in_use'] = ! empty( $public_settings['hide_unused_categories'] )
			? Get::categories_in_use()
			: [];

		// Empty label = translated default (#957); a pre-validation order is repaired. Done here,
		// before the multilingual pass, so the page payload and the public REST route agree.
		$live = null;
		foreach ( self::BUTTON_TOKENS as $token ) {
			$label_key = "{$token}_btn_text";
			$label     = $public_settings[ $label_key ] ?? '';

			// Unicode spaces and invisible format characters too: either renders a blank, unnamed button.
			if ( ! is_string( $label ) || preg_match( '/^[\s\p{Z}\p{Cf}]*$/u', $label ) === 1 ) {
				// Read fresh, not from $defaults: a multilingual plugin can set the page language after
				// that cache fills (Polylang does on `wp`), and a blank label has no registered string.
				$live  = $live ?? Options::get_all_configurations();
				$label = (string) ( $live[ $label_key ]['default'] ?? '' );
			}

			$public_settings[ $label_key ] = self::clamp_length( $label_key, $label );
		}

		$button_order                    = self::normalize_button_order( $public_settings['button_order'] ?? '' );
		$public_settings['button_order'] = $button_order !== '' ? $button_order : (string) ( $defaults['button_order'] ?? '' );

		// Trimmed here, not in LocalizeData, because this dataset also answers the
		// anonymous `get-frontend-settings` route: trimming one surface would leave
		// the full rows a single unauthenticated GET away and let the two disagree.
		if ( ! empty( $public_settings['custom_cookies'] ) && is_array( $public_settings['custom_cookies'] ) ) {
			$public_settings['custom_cookies'] = array_map( [ Get::class, 'public_cookie_row' ], $public_settings['custom_cookies'] );
		}

		return apply_filters( 'surecookie_public_settings', $public_settings );
	}

	/**
	 * Get settings as per configuration wise.
	 *
	 * @param string $key Key to get the value.
	 * @since 0.0.1
	 * @return mixed
	 */
	public static function get( $key = '' ) {
		$default_values = self::get_settings_defaults();

		if ( ! Validate::array( $default_values ) ) {
			return [];
		}

		$setting_db = Get::option( SURECOOKIE_SETTINGS_OPTION, [], 'array' );
		$response   = Validate::array( $setting_db ) ? array_merge( $default_values, $setting_db ) : $default_values;

		return $key === '' ? $response : ( $response[ $key ] ?? null );
	}

	/**
	 * Update settings.
	 *
	 * @param  string $key      The option key.
	 * @param  mixed  $value    Option value to update.
	 * @return bool             Return the option value
	 *
	 * @since 0.0.1
	 */
	public static function update( $key, $value = true ) {
		if ( empty( $key ) ) {
			return false;
		}

		$setting_db = Get::option( SURECOOKIE_SETTINGS_OPTION, [], 'array' );

		if ( ! Validate::array( $setting_db ) ) {
			$setting_db = [];
		}

		if ( ! self::accepts_value( $key, $value ) ) {
			return false;
		}

		// A value that cleans to what is already in effect is a no-op, not a refusal (update_option() would say false).
		$cleaned = self::get_cleaned_value( $key, $value );
		if ( self::get( $key ) === $cleaned ) {
			return true;
		}

		$setting_db[ $key ] = $cleaned;
		return Update::option( SURECOOKIE_SETTINGS_OPTION, $setting_db );
	}

	/**
	 * Whether a value matches its key's declared shape.
	 *
	 * A mismatch is silent data loss, not an error: get_cleaned_value() coerces, so
	 * an array-typed key collapses to [] and a scalar-typed one to '' or absint()'s
	 * 1. Patch loops skip the key instead, leaving the stored value in place.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 *
	 * @since 1.5.0
	 * @return bool
	 */
	public static function accepts_value( $key, $value ): bool {
		$type = Options::get_option_type( $key );

		if ( $type === 'button_order' ) {
			return self::normalize_button_order( $value ) !== '';
		}

		return $type === 'array'
			? is_array( $value )
			: ( is_scalar( $value ) || $value === null );
	}

	/**
	 * Get cleaned value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 *
	 * @return mixed
	 */
	public static function get_cleaned_value( $key, $value ) {
		$option_type = Options::get_option_type( $key );

		switch ( $option_type ) {
			case 'bool':
				return Sanitize::boolean( $value );
			case 'int':
				return self::clamp_to_range( $key, Sanitize::integer( $value ) );
			case 'array':
				// Sanitize::array() hard-hints array; writers that must not lose the stored value drop the key before calling.
				return Sanitize::array( is_array( $value ) ? $value : [] );
			case 'stylesheet':
				return Sanitize::stylesheet( $value );
			case 'hex_color':
				return Sanitize::hex_color( $value );
			case 'rich_text':
				return Sanitize::rich_text( $value );
			case 'button_order':
				return self::normalize_button_order( $value );
			case 'policy_links_order':
				return self::normalize_policy_links_order( $value );
			case 'string':
			default:
				return self::clamp_length( $key, Sanitize::text( $value ) );
		}
	}

	/**
	 * Known, unique tokens, keeping Preferences and a reject button (accept or decline) so no
	 * writer leaves visitors unable to choose or refuse. A Pro region's `button_config` never passes here.
	 *
	 * @param mixed $value Comma-separated order.
	 * @since 1.6.0
	 * @return string Normalized order, or '' when it names no known token.
	 */
	public static function normalize_button_order( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$tokens = array_values( array_unique( array_intersect( array_map( 'trim', explode( ',', (string) $value ) ), self::BUTTON_TOKENS ) ) );

		if ( empty( $tokens ) ) {
			return '';
		}

		if ( ! in_array( 'preferences', $tokens, true ) ) {
			$tokens[] = 'preferences';
		}

		if ( empty( array_intersect( [ 'accept', 'decline' ], $tokens ) ) ) {
			$tokens[] = 'decline';
		}

		return implode( ',', $tokens );
	}

	/**
	 * Known, unique policy-link tokens in stored order. Presence is visibility,
	 * exactly as it is for `button_order`.
	 *
	 * Unlike the buttons, an empty result is a legitimate state and is kept: a
	 * site that links its legal pages from a theme footer may hide all three,
	 * and forcing one back would override that. Only an absent setting falls
	 * back to the shipped default, through `Settings::get()`.
	 *
	 * @param mixed $value Comma-separated order.
	 * @since 1.7.0
	 * @return string Normalized order.
	 */
	public static function normalize_policy_links_order( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return implode(
			',',
			array_unique( array_intersect( array_map( 'trim', explode( ',', (string) $value ) ), self::POLICY_LINK_TOKENS ) )
		);
	}

	/**
	 * Cut a sanitized string to the length its schema declares as `max`, counted as visitors see it.
	 *
	 * @param string $key   Option key.
	 * @param string $value Sanitized string.
	 * @since 1.6.0
	 * @return string
	 */
	public static function clamp_length( $key, string $value ): string {
		if ( self::$max_lengths === null ) {
			self::$max_lengths = array_map(
				static fn( $config ) => (int) $config['max'],
				array_filter( Options::get_all_configurations(), static fn( $config ) => isset( $config['max'] ) && ( $config['type'] ?? 'string' ) === 'string' )
			);
		}

		$max = self::$max_lengths[ $key ] ?? null;
		if ( $max === null ) {
			return $value;
		}

		// sanitize_text_field() stores '<' as '&lt;': count and cut the decoded text, then re-sanitize.
		$visible = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );

		return mb_strlen( $visible ) <= $max ? $value : Sanitize::text( mb_substr( $visible, 0, $max ) );
	}

	/**
	 * Hold an integer inside the range its schema declares.
	 *
	 * `Sanitize::integer()` is `absint()`, so an emptied field arrives as 0. Every
	 * writer cleans through here, so this is the one place that keeps it out of the
	 * database.
	 *
	 * @param string $key   Option key.
	 * @param int    $value Sanitized integer.
	 * @since 1.5.1
	 * @return int
	 */
	private static function clamp_to_range( $key, $value ) {
		[ $min, $max ] = Options::get_option_range( $key );

		if ( $min !== null && $value < $min ) {
			return $min;
		}

		if ( $max !== null && $value > $max ) {
			return $max;
		}

		return $value;
	}
}
