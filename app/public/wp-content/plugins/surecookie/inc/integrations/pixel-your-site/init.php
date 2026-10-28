<?php
/**
 * PixelYourSite server-event integration.
 *
 * @package SureCookie\Inc\Integrations\PixelYourSite
 * @since 1.6.0
 */

namespace SureCookie\Inc\Integrations\PixelYourSite;

use SureCookie\Inc\Functions\ConsentState;
use SureCookie\Inc\Modules\ScriptBlocking\Blocker;
use SureCookie\Inc\Modules\ScriptBlocking\Utils;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Adds SureCookie consent only while PYS builds a per-request server response.
 */
class Init {
	use GetInstance;

	/**
	 * PYS pixel slug => SureCookie category.
	 *
	 * Keyed by the slug each PYS module passes to `parent::__construct()`, which
	 * is what reaches `pys_check_consent_by_gdpr`. A slug missing here is gated
	 * as marketing rather than refused, so a pixel from a PYS add-on we do not
	 * know about still runs once the visitor allows the strictest category.
	 */
	private const PIXEL_CATEGORIES = [
		'facebook'    => 'marketing',
		'ga'          => 'analytics',
		'gatags'      => 'analytics',
		'gtm'         => 'analytics',
		'google_ads'  => 'marketing',
		'pinterest'   => 'marketing',
		'bing'        => 'marketing',
		'reddit'      => 'marketing',
		'openai'      => 'marketing',
		'tiktok'      => 'marketing',
		'head_footer' => 'marketing',
	];

	/** Category for a PYS pixel slug this version does not know. */
	private const UNKNOWN_PIXEL_CATEGORY = 'marketing';

	private const MARKETING_FILTERS = [
		'pys_disable_facebook_by_gdpr',
		'pys_disable_google_ads_by_gdpr',
		'pys_disable_externalID_by_gdpr',
		'pys_disable_pinterest_by_gdpr',
		'pys_disable_bing_by_gdpr',
		'pys_disable_reddit_by_gdpr',
		'pys_disable_openai_by_gdpr',
		'pys_disable_advanced_form_data_cookie',
		'pys_disable_advance_data_cookie',
	];

	private const ANALYTICS_FILTERS = [
		'pys_disable_analytics_by_gdpr',
		'pys_disable_ga_by_gdpr',
		'pys_disable_gtm_by_gdpr',
		'pys_disabled_start_session_cookie',
		'pys_disable_landing_page_cookie',
		'pys_disable_first_visit_cookie',
		'pys_disable_trafficsource_cookie',
		'pys_disable_utmTerms_cookie',
		'pys_disable_utmId_cookie',
	];

	/**
	 * Whether the server-response filter scope is currently open.
	 *
	 * @var bool
	 */
	private bool $scope_active = false;

	/**
	 * Register the scopes that bracket PYS's per-request server responses.
	 */
	private function __construct() {
		if ( ! function_exists( 'PixelYourSite\\PYS' ) ) {
			return;
		}

		// PYS registers its own wp_enqueue_scripts:14 callback from
		// template_redirect:1, and WordPress runs a priority bucket in
		// registration order. Registering ours here (init:999) would close the
		// scope before PYS ran, so the pair is registered at template_redirect:2.
		add_action( 'template_redirect', [ $this, 'register_render_scope' ], 2, 0 );
		add_action( 'woocommerce_add_to_cart', [ $this, 'begin_server_event_scope' ], 39, 0 );
		add_action( 'woocommerce_add_to_cart', [ $this, 'end_server_event_scope' ], 41, 0 );
		add_action( 'wp_ajax_pys_get_gdpr_filters_values', [ $this, 'begin_server_event_scope' ], -PHP_INT_MAX, 0 );
		add_action( 'wp_ajax_nopriv_pys_get_gdpr_filters_values', [ $this, 'begin_server_event_scope' ], -PHP_INT_MAX, 0 );
		add_action( 'wp_ajax_pys_get_pbid', [ $this, 'begin_server_event_scope' ], -PHP_INT_MAX, 0 );
		add_action( 'wp_ajax_nopriv_pys_get_pbid', [ $this, 'begin_server_event_scope' ], -PHP_INT_MAX, 0 );
		add_filter( 'rest_request_before_callbacks', [ $this, 'begin_dynamic_options_scope' ], -PHP_INT_MAX, 3 );
		add_filter( 'rest_request_after_callbacks', [ $this, 'end_dynamic_options_scope' ], PHP_INT_MAX, 3 );
		add_action( 'wp_footer', [ $this, 'begin_noscript_scope' ], 9, 0 );
		add_action( 'wp_footer', [ $this, 'end_noscript_scope' ], 11, 0 );
	}

	/**
	 * Register the page-render scope once PYS has registered its own callbacks.
	 *
	 * @return void
	 */
	public function register_render_scope(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'begin_server_event_scope' ], 13 );
		add_action( 'wp_enqueue_scripts', [ $this, 'end_server_event_scope' ], 14, 0 );
	}

	/**
	 * Begin the temporary filter scope around a PYS server response.
	 *
	 * @return void
	 */
	public function begin_server_event_scope(): void {
		if ( $this->scope_active || self::has_recognized_cmp() || ! Blocker::get_instance()->should_enforce_consent() ) {
			return;
		}

		$this->scope_active = true;
		add_filter( 'pys_check_consent_by_gdpr', [ $this, 'filter_consent' ], 10, 2 );
		add_filter( 'pys_pixel_restricted_mode', [ $this, 'filter_restricted_mode' ], 10, 2 );
		add_filter( 'pys_disable_by_gdpr', [ $this, 'disable_all_pixels' ] );

		foreach ( self::MARKETING_FILTERS as $hook ) {
			add_filter( $hook, [ $this, 'disable_marketing' ] );
		}
		foreach ( self::ANALYTICS_FILTERS as $hook ) {
			add_filter( $hook, [ $this, 'disable_analytics' ] );
		}
		add_filter( 'pys_disable_all_cookie', [ $this, 'disable_all_cookies' ] );
	}

	/**
	 * PYS writes noscript images into cacheable page HTML, and a noscript <img>
	 * cannot be parked and restored the way a script can. Suppress them for
	 * everyone rather than per visitor: the gate here is deliberately
	 * site-level, because anything per-visitor (geo, a scan probe) decides what
	 * gets stored in the cache and then served to somebody else.
	 *
	 * @return void
	 */
	public function begin_noscript_scope(): void {
		if ( self::has_recognized_cmp() || ! Utils::is_blocking_enabled() ) {
			return;
		}
		add_filter( 'pys_disable_by_gdpr', [ $this, 'disable_noscript' ], PHP_INT_MAX );
	}

	/**
	 * Remove the cache-invariant noscript suppression after PYS renders its footer.
	 *
	 * @return void
	 */
	public function end_noscript_scope(): void {
		remove_filter( 'pys_disable_by_gdpr', [ $this, 'disable_noscript' ], PHP_INT_MAX );
	}

	/**
	 * End the temporary PYS filter scope.
	 *
	 * @return void
	 */
	public function end_server_event_scope(): void {
		if ( ! $this->scope_active ) {
			return;
		}

		remove_filter( 'pys_check_consent_by_gdpr', [ $this, 'filter_consent' ], 10 );
		remove_filter( 'pys_pixel_restricted_mode', [ $this, 'filter_restricted_mode' ], 10 );
		remove_filter( 'pys_disable_by_gdpr', [ $this, 'disable_all_pixels' ], 10 );
		foreach ( self::MARKETING_FILTERS as $hook ) {
			remove_filter( $hook, [ $this, 'disable_marketing' ], 10 );
		}
		foreach ( self::ANALYTICS_FILTERS as $hook ) {
			remove_filter( $hook, [ $this, 'disable_analytics' ], 10 );
		}
		remove_filter( 'pys_disable_all_cookie', [ $this, 'disable_all_cookies' ], 10 );
		$this->scope_active = false;
	}

	/**
	 * Open the scope only for PYS's per-request dynamic-options route.
	 *
	 * @param mixed $response Existing REST response.
	 * @param mixed $handler Route handler.
	 * @param mixed $request REST request.
	 * @return mixed
	 */
	public function begin_dynamic_options_scope( $response, $handler = null, $request = null ) {
		unset( $handler );
		if ( self::is_dynamic_options_request( $request ) ) {
			$this->begin_server_event_scope();
		}
		return $response;
	}

	/**
	 * Close the dynamic-options scope after its uncached response is built.
	 *
	 * @param mixed $response REST response.
	 * @param mixed $handler Route handler.
	 * @param mixed $request REST request.
	 * @return mixed
	 */
	public function end_dynamic_options_scope( $response, $handler = null, $request = null ) {
		unset( $handler );
		if ( self::is_dynamic_options_request( $request ) ) {
			$this->end_server_event_scope();
		}
		return $response;
	}

	/**
	 * PYS checkConsent callback; unknown slugs fail closed.
	 *
	 * @param mixed $consented PYS's existing value.
	 * @param mixed $pixel PYS pixel slug.
	 * @return bool
	 */
	public function filter_consent( $consented, $pixel = '' ) {
		if ( ! $this->scope_active ) {
			return $consented;
		}

		return ConsentState::allows( self::category_for( $pixel ) );
	}

	/**
	 * Do not let PYS's restricted-mode fallback bypass an explicit refusal.
	 *
	 * @param mixed $restricted Existing PYS restricted-mode decision.
	 * @param mixed $pixel PYS pixel slug.
	 * @return mixed
	 */
	public function filter_restricted_mode( $restricted, $pixel = '' ) {
		if ( ! $this->scope_active ) {
			return $restricted;
		}

		return ConsentState::allows( self::category_for( $pixel ) ) ? $restricted : false;
	}

	/**
	 * Disable every PYS pixel only when neither analytics nor marketing is allowed.
	 *
	 * @param mixed $disabled Existing PYS value.
	 * @return bool
	 */
	public function disable_all_pixels( $disabled ) {
		if ( ! $this->scope_active ) {
			return $disabled;
		}
		return (bool) $disabled || ( ! ConsentState::allows( 'analytics' ) && ! ConsentState::allows( 'marketing' ) );
	}

	/**
	 * Hold pixels governed by marketing consent.
	 *
	 * @param mixed $disabled Existing PYS value.
	 * @return bool
	 */
	public function disable_marketing( $disabled ) {
		if ( ! $this->scope_active ) {
			return $disabled;
		}
		return (bool) $disabled || ! ConsentState::allows( 'marketing' );
	}

	/**
	 * Hold pixels governed by analytics consent.
	 *
	 * @param mixed $disabled Existing PYS value.
	 * @return bool
	 */
	public function disable_analytics( $disabled ) {
		if ( ! $this->scope_active ) {
			return $disabled;
		}
		return (bool) $disabled || ! ConsentState::allows( 'analytics' );
	}

	/**
	 * Disable every PYS cookie only when neither analytics nor marketing is allowed.
	 *
	 * @param mixed $disabled Existing PYS value.
	 * @return bool
	 */
	public function disable_all_cookies( $disabled ) {
		if ( ! $this->scope_active ) {
			return $disabled;
		}
		return (bool) $disabled || ( ! ConsentState::allows( 'analytics' ) && ! ConsentState::allows( 'marketing' ) );
	}

	/**
	 * Disable every PYS pixel when loading cacheable noscript markup.
	 *
	 * @param mixed $disabled Existing PYS value.
	 * @return bool
	 */
	public function disable_noscript( $disabled ) {
		unset( $disabled );
		return true;
	}

	/**
	 * Category governing a PYS pixel slug.
	 *
	 * @param mixed $pixel PYS pixel slug.
	 * @return string Category.
	 */
	private static function category_for( $pixel ): string {
		if ( ! is_string( $pixel ) ) {
			return self::UNKNOWN_PIXEL_CATEGORY;
		}

		return self::PIXEL_CATEGORIES[ $pixel ] ?? self::UNKNOWN_PIXEL_CATEGORY;
	}

	/**
	 * Check if recognized CMP there.
	 *
	 * @return bool True if found else false.
	 * @since 1.6.0
	 */
	private static function has_recognized_cmp(): bool {
		/**
		 * Callables that report whether a consent plugin PYS recognises is active.
		 *
		 * PYS keeps that verdict private, so the detectors it uses are the only
		 * way to ask. A site running a CMP PYS does not list can add its own,
		 * which is also what keeps this list from being a closed set.
		 *
		 * @since 1.6.0
		 * @param array<int, string> $detectors Callable names.
		 */
		$detectors = apply_filters(
			'surecookie_pys_cmp_detectors',
			[
				'PixelYourSite\\isCookiebotPluginActivated',
				'PixelYourSite\\isCookieNoticePluginActivated',
				'PixelYourSite\\isRealCookieBannerPluginActivated',
				'PixelYourSite\\isConsentMagicPluginActivated',
				'PixelYourSite\\isCookieLawInfoPluginActivated',
			]
		);

		foreach ( (array) $detectors as $detector ) {
			if ( is_string( $detector ) && is_callable( $detector ) && $detector() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate if its dynamic request.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The REST request object.
	 * @return bool True if valid else false.
	 * @since 1.6.0
	 */
	private static function is_dynamic_options_request( $request ): bool {
		return is_object( $request ) && method_exists( $request, 'get_route' )
			&& ltrim( (string) $request->get_route(), '/' ) === 'pys/v1/dynamic-options';
	}
}
