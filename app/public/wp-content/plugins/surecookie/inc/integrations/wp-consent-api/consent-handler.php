<?php
/**
 * WP Consent API - Consent Handler
 *
 * Reads the SureCookie consent cookie on page load and bridges the consent
 * state to the WP Consent API so other plugins can check consent via
 * wp_has_consent().
 *
 * @package SureCookie\Inc\Integrations\WpConsentApi
 * @since 0.0.1-beta.1
 */

namespace SureCookie\Inc\Integrations\WpConsentApi;

use SureCookie\Inc\Functions\ConsentState;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Consent_Handler class.
 *
 * @since 0.0.1-beta.1
 */
class Consent_Handler {
	use GetInstance;

	/**
	 * Map SureCookie categories to WP Consent API consent types.
	 *
	 * WP Consent API types: functional, preferences, statistics,
	 * statistics-anonymous, marketing.
	 *
	 * @since 0.0.1-beta.1
	 */
	private const CATEGORY_MAP = [
		'essential'  => 'functional',
		'functional' => 'preferences',
		'analytics'  => 'statistics',
		'marketing'  => 'marketing',
	];

	/**
	 * Constructor.
	 *
	 * @since 0.0.1-beta.1
	 */
	private function __construct() {
		// `wp_loaded`, not `init`: the hub that builds this class is itself on
		// `init` priority 999, so an `init` callback here would never fire.
		add_action( 'wp_loaded', [ $this, 'sync_consent_state' ] );
	}

	/**
	 * Read the SureCookie consent cookie and push state to WP Consent API.
	 *
	 * Runs on every request so that other plugins calling
	 * wp_has_consent('marketing') get the correct answer based on
	 * the user's SureCookie preferences.
	 *
	 * @return void
	 * @since 0.0.1-beta.1
	 */
	public function sync_consent_state(): void {
		$category_map      = self::get_full_category_map();
		$essential_wp_type = $category_map['essential'] ?? null;

		// The map is many-to-one and wp_set_consent() is one cookie per WP type,
		// so first mapped category wins - as updateWpConsentApi() already does
		// in consentManager.js. Otherwise built-in `uncategorized` (which falls
		// back to `marketing`) re-allows marketing for a visitor who declined it.
		$assigned = [];

		foreach ( $category_map as $surecookie_cat => $wp_type ) {
			if ( isset( $assigned[ $wp_type ] ) ) {
				continue;
			}
			$assigned[ $wp_type ] = true;

			$value = $essential_wp_type !== null && $wp_type === $essential_wp_type
				? 'allow'
				: ( ConsentState::allows( $surecookie_cat ) ? 'allow' : 'deny' );
			self::set_consent( $wp_type, $value );
		}
	}

	/**
	 * Get the active consent model for the current visitor.
	 *
	 * Returns the core setting by default. The pro plugin's geo-location module
	 * can override this via the `surecookie_active_consent_model` filter to
	 * return the consent model from the matched geo preset.
	 *
	 * @return string 'opt-in' or 'opt-out'.
	 * @since 0.0.1-beta.1
	 */
	public static function get_active_consent_model(): string {
		$consent_model = Settings::get( 'consent_model' );

		if ( empty( $consent_model ) ) {
			$consent_model = 'opt-in';
		}

		/**
		 * Filter: Override the active consent model for the current visitor.
		 *
		 * Used by the geo-location module to return the consent model from
		 * the matched geo preset (e.g., 'opt-out' for CCPA regions).
		 *
		 * @param string $consent_model Current consent model ('opt-in' or 'opt-out').
		 * @since 0.0.1-beta.1
		 */
		$filtered = apply_filters( 'surecookie_active_consent_model', $consent_model );

		// Validate: only 'opt-in' or 'opt-out' are valid consent models.
		return in_array( $filtered, [ 'opt-in', 'opt-out' ], true ) ? $filtered : 'opt-in';
	}

	/**
	 * Get the WP Consent API category for a SureCookie category.
	 *
	 * @param string $surecookie_category SureCookie category slug.
	 * @return string WP Consent API consent type.
	 * @since 0.0.1-beta.1
	 */
	public static function map_category( string $surecookie_category ): string {
		/**
		 * Filter: Customize SureCookie → WP Consent API category mapping.
		 *
		 * @param array<string, string> $map Category mapping array.
		 * @since 0.0.1-beta.1
		 */
		$map = apply_filters( 'surecookie_wp_consent_api_category_map', self::CATEGORY_MAP );

		return $map[ $surecookie_category ] ?? 'marketing';
	}

	/**
	 * Build the full category map including any custom categories.
	 *
	 * Merges the default CATEGORY_MAP with custom categories from settings,
	 * mapping each custom category via the filterable map_category() method.
	 *
	 * @return array<string, string> Full SureCookie → WP Consent API category map.
	 * @since 0.0.1-beta.1
	 */
	public static function get_full_category_map(): array {
		$map        = self::CATEGORY_MAP;
		$categories = Settings::get( 'cookie_categories' );

		if ( ! is_array( $categories ) ) {
			return $map;
		}

		foreach ( $categories as $category ) {
			$cat_id = $category['id'] ?? '';
			if ( empty( $cat_id ) || isset( $map[ $cat_id ] ) ) {
				continue;
			}
			$map[ $cat_id ] = self::map_category( $cat_id );
		}

		return $map;
	}

	/**
	 * Write one WP Consent API consent type.
	 *
	 * `wp_set_consent()` only calls setcookie() while `wp_has_consent()` only
	 * reads `$_COOKIE`, so the superglobal is mirrored too or the answer stays
	 * one request behind. Values the browser already sent are skipped, keeping
	 * Set-Cookie off responses a page cache would otherwise refuse to store.
	 *
	 * The mirror and the cookie are deliberately separate: the mirror is always
	 * safe, while setcookie() needs headers. Core sends them in `wp-cron.php`
	 * before WordPress loads, so an unguarded write warns on every cron spawn.
	 *
	 * @param string $wp_type WP Consent API consent type.
	 * @param string $value   'allow' or 'deny'.
	 * @return void
	 * @since 1.5.0
	 */
	private static function set_consent( string $wp_type, string $value ): void {
		if ( ! function_exists( 'wp_set_consent' ) ) {
			return;
		}

		$prefix = apply_filters( 'wp_consent_cookie_prefix', 'wp_consent' );
		$cookie = ( is_string( $prefix ) ? $prefix : 'wp_consent' ) . '_' . $wp_type;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared against our own literal to skip a redundant Set-Cookie; the value is never used as data.
		if ( ( $_COOKIE[ $cookie ] ?? null ) === $value ) {
			return;
		}

		$_COOKIE[ $cookie ] = $value;

		// Unqualified on purpose: the test harness shadows it in this namespace.
		if ( headers_sent() ) {
			return;
		}

		wp_set_consent( $wp_type, $value );
	}
}
