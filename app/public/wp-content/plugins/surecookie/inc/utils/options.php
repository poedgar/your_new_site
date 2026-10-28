<?php
/**
 * Options
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Utils;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Validate;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Options - this class is for all plugin configuration settings.
 *
 * @since 0.0.1
 */
class Options {
	/**
	 * Get SureCookie's settings dataset with including type, default.
	 *
	 * `group` names an Import / Export section (see Settings::get_section_registry).
	 * Every section folds into one of five picker groups mirroring the admin
	 * nav: cookies_scripts, geo, banner, general, consent_frameworks. Adding a
	 * group with no registered section puts the key in its own dropdown entry,
	 * so register the section too. Untagged keys fall into General.
	 *
	 * @return array<string, mixed>
	 * @since 0.0.1
	 */
	public static function get_all_configurations() {
		return apply_filters(
			'surecookie_plugin_settings_dataset',
			[
				'banner_enabled'                => [
					'type'    => 'bool',
					'default' => true,
					'group'   => 'banner',
				],
				'preview_enabled'               => [
					'type'    => 'bool',
					'default' => true,
				],
				'message_heading'               => [
					'type'    => 'string',
					'default' => '',
					'group'   => 'banner',
				],
				'message_description'           => [
					'type'    => 'rich_text',
					'default' => __( 'We use cookies to improve your experience. You can change your choice at any time.', 'surecookie' ),
					'group'   => 'banner',
				],

				'banner_position'               => [
					'type'    => 'string',
					'default' => 'bottom',
					'group'   => 'banner',
				],

				'banner_logo'                   => [
					'type'    => 'string',
					'default' => '',
					'group'   => 'banner',
				],
				'accept_btn_text'               => [
					'type'    => 'string',
					'default' => __( 'Only Essential', 'surecookie' ),
					'group'   => 'buttons',
					'max'     => 50,
				],
				'accept_all_enabled'            => [
					'type'    => 'bool',
					'default' => true,
					'group'   => 'buttons',
				],

				'accept_all_btn_text'           => [
					'type'    => 'string',
					'default' => __( 'Accept All', 'surecookie' ),
					'group'   => 'buttons',
					'max'     => 50,
				],
				'decline_btn_text'              => [
					'type'    => 'string',
					'default' => __( 'Decline', 'surecookie' ),
					'group'   => 'buttons',
					'max'     => 50,
				],
				'settings_btn_text'             => [
					'type'    => 'string',
					'default' => __( 'Cookie Settings', 'surecookie' ),
					'group'   => 'buttons',
				],

				'button_order'                  => [
					// A comma string, validated by Settings::normalize_button_order().
					'type'    => 'button_order',
					'default' => 'accept_all,accept,preferences,decline',
					'group'   => 'buttons',
				],
				'policy_links_order'            => [
					// A comma string, validated by Settings::normalize_policy_links_order().
					'type'    => 'policy_links_order',
					'default' => 'cookie_policy,privacy_policy,legal_notice',
					'group'   => 'banner',
				],
				'compliance_law'                => [
					'type'    => 'array',
					'default' => [
						'id'   => '1',
						'name' => 'GDPR',
					],
					'group'   => 'consent',
				],
				'notice_type'                   => [
					'type'    => 'string',
					'default' => 'box',
					'group'   => 'banner',
				],
				'notice_position'               => [
					'type'    => 'string',
					'default' => 'bottom-right',
					'group'   => 'banner',
				],

				'show_preview'                  => [
					'type'    => 'bool',
					'default' => true,
				],
				'cookie_categories'             => [
					'type'    => 'array',
					'default' => Get::default_cookie_categories(),
					'group'   => 'cookie_categories',
				],
				'hide_unused_categories'        => [
					'type'    => 'bool',
					'default' => false,
					'group'   => 'cookie_categories',
				],
				'custom_cookies'                => [
					'type'    => 'array',
					'default' => [],
					'group'   => 'cookies',
				],
				'consent_logging_enabled'       => [
					'type'    => 'bool',
					'default' => true,
					'group'   => 'consent',
				],
				'consent_log_retention'         => [
					'type'    => 'string',
					'default' => '365_days',
					'group'   => 'consent',
				],
				'consent_duration_days'         => [
					'type'    => 'int',
					'default' => 365,
					'group'   => 'consent',
				],
				// Unix timestamp of the last admin "Renew consent" action. Consents
				// recorded before this are treated as stale so the banner reappears.
				'consent_renewed_at'            => [
					'type'    => 'int',
					'default' => 0,
				],
				'color_palette'                 => [
					'type'    => 'string',
					'default' => 'green-lime',
					'group'   => 'banner',
				],
				'banner_width'                  => [
					'type'    => 'int',
					'default' => 650,
					'group'   => 'banner',
					// Enforced on write by Settings::get_cleaned_value(); a stored 0
					// collapsed the banner to its padding (issue #1176).
					'min'     => 300,
					'max'     => 800,
				],
				'preferences_btn_text'          => [
					'type'    => 'string',
					'default' => __( 'Preferences', 'surecookie' ),
					'group'   => 'buttons',
					'max'     => 50,
				],
				'preferences_modal_heading'     => [
					'type'    => 'string',
					'default' => __( 'Privacy Preference', 'surecookie' ),
					'group'   => 'banner',
				],
				'preferences_modal_description' => [
					'type'    => 'rich_text',
					'default' => __( 'We use cookies and similar technologies to help personalize content, tailor and measure ads, and provide a better experience.', 'surecookie' ),
					'group'   => 'banner',
				],
				'scan_pages'                    => [
					'type'    => 'array',
					'default' => [],
				],
				'blocking_enabled'              => [
					'type'    => 'bool',
					'default' => true,
					'group'   => 'blocking',
				],
				'top_level_menu_enabled'        => [
					'type'    => 'bool',
					'default' => true,
					'group'   => 'advanced',
				],
				'banner_animation'              => [
					'type'    => 'string',
					'default' => 'fade',
					'group'   => 'banner',
				],
				'banner_overlay_enabled'        => [
					'type'    => 'bool',
					'default' => false,
					'group'   => 'banner',
				],
				'reconsent_button_label'        => [
					'type'    => 'string',
					'default' => __( 'Cookie Preferences', 'surecookie' ),
					'group'   => 'buttons',
				],
				'reconsent_menu_id'             => [
					'type'    => 'string',
					'default' => '',
				],
				'cookie_policy_page_id'         => [
					'type'    => 'int',
					'default' => 0,
				],
				'privacy_policy_page_id'        => [
					'type'    => 'int',
					'default' => 0,
				],
				'legal_notice_page_id'          => [
					'type'    => 'int',
					'default' => 0,
				],
				'privacy_jurisdictions'         => [
					'type'    => 'array',
					'default' => [],
				],
				// Controller identity, surfaced as "Business Details". The whole
				// set lives under one nested key so the global namespace stays
				// clean; `Settings::update` replaces it wholesale, so every
				// writer must read-merge-write via Validator::sanitize_details().
				'organization_details'          => [
					'type'    => 'array',
					'default' => [
						'legal_entity_name'       => '',
						// A list, not a multi-line string: Sanitize::array()
						// runs sanitize_text_field on every leaf, which strips
						// newlines, and the rich-text opt-out leaves nested
						// leaves decoded-but-unsanitized on read.
						'postal_address_lines'    => [],
						'postal_code'             => '',
						'country'                 => '',
						'privacy_contact_email'   => '',
						'grievance_officer_name'  => '',
						'grievance_officer_email' => '',
						'transfer_safeguard_text' => '',
						'ccpa_sells_or_shares'    => '',
					],
				],
				'custom_css'                    => [
					'type'    => 'stylesheet',
					'default' => '',
					'group'   => 'advanced',
				],
				'delete_data_on_uninstall'      => [
					'type'    => 'bool',
					'default' => false,
					'group'   => 'advanced',
				],
				'enable_mcp'                    => [
					'type'    => 'bool',
					'default' => false,
					'group'   => 'advanced',
				],
				'excluded_scan_resources'       => [
					'type'    => 'array',
					'default' => [],
				],
				// Pro owns the whitelist feature but the free admin bundle reads
				// and writes this key, and an unregistered key sanitizes as a
				// string, so saving settings without Pro flattened it to ''.
				'custom_whitelisted_scripts'    => [
					'type'    => 'array',
					'default' => [],
				],
				'resource_category_overrides'   => [
					'type'    => 'array',
					'default' => [],
					'group'   => 'blocking',
				],
				'placeholder_image'             => [
					'type'    => 'string',
					'default' => '',
					'group'   => 'advanced',
				],
				'placeholder_description'       => [
					'type'    => 'rich_text',
					'default' => __( 'This content is blocked because it would connect to {service}.', 'surecookie' ),
					'group'   => 'advanced',
				],
				'placeholder_button_text'       => [
					'type'    => 'string',
					'default' => __( 'Accept & Load', 'surecookie' ),
					'group'   => 'advanced',
				],
				'placeholder_video_thumbnails'  => [
					'type'    => 'bool',
					'default' => false,
					'group'   => 'advanced',
				],
				'custom_blocked_scripts'        => [
					'type'    => 'array',
					'default' => [],
					'group'   => 'blocking',
				],
				'consent_model'                 => [
					'type'    => 'string',
					'default' => 'opt-in',
					'group'   => 'consent',
				],
				'total_logs'                    => [
					'type'    => 'int',
					'default' => 0,
				],

				// Automatic Scanning (Free base). Pro adds the Weekly frequency + email/apply/guard keys.
				'auto_scan_enabled'             => [
					'type'    => 'bool',
					'default' => false,
					'group'   => 'scanning',
				],
				'auto_scan_frequency'           => [
					'type'    => 'string',
					'default' => 'monthly',
					'group'   => 'scanning',
				],
				'auto_scan_scope'               => [
					'type'    => 'string',
					'default' => 'same_as_manual',
					'group'   => 'scanning',
				],
				'auto_scan_pages'               => [
					'type'    => 'array',
					'default' => [],
				],
			]
		);
	}

	/**
	 * Get frontend related settings. Skipping backend related settings & non-relevant configurations.
	 *
	 * @return array<string>
	 * @since 0.0.1
	 */
	public static function get_frontend_options() {
		return apply_filters(
			'surecookie_frontend_setting_keys',
			[
				'message_heading',
				'message_description',
				'notice_type',
				'notice_position',
				'banner_width',
				'banner_enabled',
				'banner_logo',
				'accept_all_enabled',
				'accept_btn_text',
				'accept_all_btn_text',
				'decline_btn_text',
				'button_order',
				'preferences_btn_text',
				'preferences_modal_heading',
				'preferences_modal_description',
				'cookie_categories',
				'hide_unused_categories',
				'custom_cookies',
				'consent_logging_enabled',
				'consent_duration_days',
				'consent_renewed_at',
				'banner_animation',
				'banner_overlay_enabled',
				'reconsent_button_label',
				'consent_model',
			]
		);
	}

	/**
	 * Get option type.
	 *
	 * @param string $option Option.
	 *
	 * @return string
	 * @since 0.0.1
	 */
	public static function get_option_type( $option ) {
		$settings = self::get_all_configurations();
		return isset( $settings[ $option ]['type'] ) && Validate::not_empty( $settings[ $option ]['type'] ) ? $settings[ $option ]['type'] : 'string';
	}

	/**
	 * Declared value range for an option, as `[ min, max ]`. Either bound is null
	 * when the schema does not set it. For a string option `max` is its length.
	 *
	 * @param string $option Option key.
	 * @since 1.5.1
	 * @return array{0: ?int, 1: ?int}
	 */
	public static function get_option_range( $option ) {
		$settings = self::get_all_configurations();
		$config   = isset( $settings[ $option ] ) && is_array( $settings[ $option ] ) ? $settings[ $option ] : [];

		return [
			isset( $config['min'] ) ? (int) $config['min'] : null,
			isset( $config['max'] ) ? (int) $config['max'] : null,
		];
	}

	/**
	 * Get the keys of every option storing rich text (HTML). Derived, not
	 * hardcoded, so a new rich_text field is covered automatically.
	 *
	 * @return array<string>
	 * @since 1.3.1
	 */
	public static function get_rich_text_options() {
		$configs = array_filter(
			self::get_all_configurations(),
			static fn( $config ) => ( $config['type'] ?? '' ) === 'rich_text'
		);

		return array_map( 'strval', array_keys( $configs ) );
	}
}
