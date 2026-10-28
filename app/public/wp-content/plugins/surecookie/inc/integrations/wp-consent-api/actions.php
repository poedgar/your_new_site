<?php
/**
 * WP Consent API - Actions
 *
 * Handles settings registration, frontend option injection,
 * and cookie registration with the WP Consent API.
 *
 * @package SureCookie\Inc\Integrations\WpConsentApi
 * @since 0.0.1-beta.1
 */

namespace SureCookie\Inc\Integrations\WpConsentApi;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Actions class.
 *
 * @since 0.0.1-beta.1
 */
class Actions {
	use GetInstance;

	/**
	 * Whether cookies have already been registered.
	 *
	 * @var bool
	 */
	private bool $cookies_registered = false;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1-beta.1
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Register SureCookie's own cookies with WP Consent API.
	 *
	 * This tells the WP Consent API what cookies SureCookie sets,
	 * their purpose, and expiry - visible in consent API dashboards.
	 *
	 * @return void
	 * @since 0.0.1-beta.1
	 */
	public function register_cookies(): void {
		if ( $this->cookies_registered || ! function_exists( 'wp_add_cookie_info' ) ) {
			return;
		}

		$this->cookies_registered = true;

		$consent_duration = Settings::get( 'consent_duration_days' );
		$consent_expiry   = ( ! empty( $consent_duration ) ? absint( $consent_duration ) : 365 ) . ' ' . __( 'days', 'surecookie' );

		wp_add_cookie_info(
			'surecookie_user_consent',
			__( 'SureCookie', 'surecookie' ),
			'functional',
			$consent_expiry,
			__( 'Stores the user\'s cookie consent preferences.', 'surecookie' )
		);

		wp_add_cookie_info(
			'surecookie_session_id',
			__( 'SureCookie', 'surecookie' ),
			'functional',
			__( '1 year', 'surecookie' ),
			__( 'Tracks consent log sessions for audit purposes.', 'surecookie' )
		);
	}

	/**
	 * Add the WP Consent API category map to frontend localized data.
	 *
	 * Makes PHP the single source of truth for the SureCookie → WP Consent API
	 * category mapping. JS reads `window.surecookiePublicSettings.wpConsentCategoryMap`.
	 *
	 * @param mixed $data Expected array<string, mixed> of frontend localized data.
	 * @return mixed Data with the category map added, or $data untouched.
	 * @since 0.0.1-beta.1
	 */
	public function add_category_map_to_frontend( $data = [] ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$data['wpConsentCategoryMap'] = Consent_Handler::get_full_category_map();
		return $data;
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 * @since 0.0.1-beta.1
	 */
	private function init_hooks(): void {
		// Register SureCookie's cookies with WP Consent API. Hooked to wp_loaded, NOT init.
		add_action( 'wp_loaded', [ $this, 'register_cookies' ] );

		// Pass the category map to the frontend so JS uses PHP as single source of truth.
		add_filter( 'surecookie_frontend_localize_data', [ $this, 'add_category_map_to_frontend' ] );
	}
}
