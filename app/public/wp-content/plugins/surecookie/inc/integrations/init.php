<?php
/**
 * Integrations Initializer
 *
 * Hub for all third-party and platform integrations.
 * Currently handles WordPress Abilities API (WP 6.9+).
 * Future integrations can be added here with their own feature gates.
 *
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations
 * @since      0.0.0-alpha.1
 */

namespace SureCookie\Inc\Integrations;

use SureCookie\Inc\Integrations\Astra\Init as Astra;
use SureCookie\Inc\Integrations\Cache\Init as Cache;
use SureCookie\Inc\Integrations\Elementor\Init as Elementor;
use SureCookie\Inc\Integrations\Multilingual\Init as Multilingual;
use SureCookie\Inc\Integrations\PixelYourSite\Init as PixelYourSite;
use SureCookie\Inc\Integrations\PrestoPlayer\Init as Presto_Player;
use SureCookie\Inc\Integrations\SiteHealth\Consent_Write;
use SureCookie\Inc\Integrations\SiteHealth\Init as Site_Health;
use SureCookie\Inc\Integrations\ThemePalette\Init as Theme_Palette;
use SureCookie\Inc\Integrations\Wordpress\Init as Wordpress_Abilities;
use SureCookie\Inc\Integrations\WpConsentApi\Init as Wp_Consent_Api;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Init
 *
 * Conditionally initializes available integrations.
 *
 * @since 0.0.0-alpha.1
 */
class Init {
	use GetInstance;

	/**
	 * Constructor.
	 *
	 * Feature-gates each integration behind its own availability check.
	 *
	 * @since 0.0.0-alpha.1
	 */
	public function __construct() {
		// WordPress Abilities API (WP 6.9+).
		if ( function_exists( 'wp_register_ability' ) ) {
			Wordpress_Abilities::get_instance();
		}

		// Multilingual compatibility (WPML, Polylang).
		if ( Multilingual::is_multilingual_plugin_active() ) {
			Multilingual::get_instance();
		}

		// WP Consent API integration.
		Wp_Consent_Api::get_instance();

		// Presto Player block-level content blocker (gated inside Init).
		Presto_Player::get_instance();

		// PixelYourSite server-side consent checks and cache-mode switches.
		PixelYourSite::get_instance();

		// Elementor widgets that build their embed client-side (gated inside Init).
		Elementor::get_instance();

		// Astra Global Color Palette (gated inside Init).
		Astra::get_instance();

		// Theme palette presets for the Custom Colors pickers (Astra or
		// block-theme cascade resolved inside).
		Theme_Palette::get_instance();

		// Cache and optimisation plugin compatibility. Always on: the exclusions
		// are filters the host plugins simply never call when absent.
		Cache::get_instance();

		// Site Health reporting only. Both its filters fire in wp-admin or the
		// weekly check, so it never boots on a front-end request.
		if ( is_admin() || wp_doing_cron() ) {
			Site_Health::get_instance();
			Consent_Write::get_instance();
		}
	}
}
