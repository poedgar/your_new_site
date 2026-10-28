<?php
/**
 * Localize Data Utility
 *
 * Centralized utility for managing wp_localize_script data
 *
 * @package SureCookie\Inc\Utils
 */

namespace SureCookie\Inc\Utils;

use SureCookie\Admin\Live_Versions;
use SureCookie\Admin\Product_Promotion;
use SureCookie\Inc\Database\ConsentLog;
use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Integrations\WpConsentApi\Consent_Handler;
use SureCookie\Inc\Modules\AssistedScan\Utils as AssistedScanUtils;
use SureCookie\Inc\Modules\BusinessDetails\Shortcode as Business_Shortcodes;
use SureCookie\Inc\Modules\Nudges\Utils;
use SureCookie\Inc\Modules\PrivacyPolicy\Countries;
use SureCookie\Inc\Modules\ScriptBlocking\Blocker;
use SureCookie\Inc\Modules\ScriptBlocking\Utils as Blocking_Utils;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;
use SureCookie\Inc\Modules\SiteScanner\Utils as SiteScannerUtils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class LocalizeData
 *
 * Handles all localized script data for admin and frontend
 */
class LocalizeData {
	/**
	 * Maximum age, in hourly buckets, for which an expired token may be exchanged for
	 * a fresh one (~30 days). Covers long page-cache TTLs (WP Rocket's 10-day default,
	 * long CDN edge caches) while still requiring a token genuinely issued by this site -
	 * a forged token matches no bucket at any age.
	 *
	 * @since 1.2.1
	 */
	private const STALE_TOKEN_MAX_AGE_BUCKETS = 720;

	/**
	 * The only `website_links` keys the public bundle reads (3 call sites in
	 * build/public.js, both branding anchors). The other 32 are admin-only CTAs,
	 * so the frontend payload ships just these two.
	 *
	 * @since 1.6.0
	 */
	private const FRONTEND_WEBSITE_LINK_KEYS = [ 'banner_branding', 'preferences_modal_branding' ];

	/**
	 * Consent-log REST routes the frontend posts to.
	 *
	 * @since 1.7.0
	 */
	private const CONSENT_LOG_ROUTES = [
		'create_log' => 'surecookie/v1/consent-logs/create-log',
		'token'      => 'surecookie/v1/consent-logs/token',
	];
	/**
	 * Get keyed map of UTM-tagged outbound marketing links.
	 *
	 * Each entry is `[ path, utm_content ]`, expanded via {@see Helper::get_marketing_link()}
	 * into a full URL with the standard source/medium/campaign defaults. Keys are consumed
	 * by the React helper `getSureCookieLink()`. Includes informational links (docs, support,
	 * branding) and Pro pricing CTAs (keys prefixed `pricing_`); marketing reports attribute
	 * by `utm_content`.
	 *
	 * @return array<string, string> Map of edge identifier => URL.
	 * @since 0.0.1-beta.2
	 */
	public static function get_website_links(): array {
		$entries = [
			// Informational / outbound marketing links.
			'help_center'                    => [ 'docs/', 'help_center' ],
			'quick_access_help_center'       => [ 'docs/', 'quick_access_help_center' ],
			'whats_new'                      => [ 'whats-new/', 'whats_new' ],
			'whats_new_rss'                  => [ 'whats-new/feed/', 'whats_new_rss' ],
			'admin_privacy_policy'           => [ 'terms-and-conditions/', 'admin_terms_conditions' ],
			'onboarding_privacy_policy'      => [ 'terms-and-conditions/', 'onboarding_terms_conditions' ],
			'banner_branding'                => [ '', 'banner_branding' ],
			'preferences_modal_branding'     => [ '', 'preferences_modal_branding' ],
			'resource_blocking_docs'         => [ 'docs/resource-blocking/', 'resource_blocking_docs' ],
			'scan_cookies_learn_more'        => [ 'docs/', 'scan_cookies_learn_more' ],
			'scanning_logs_support'          => [ 'support/', 'scanning_logs_support' ],
			'deactivation_survey_support'    => [ 'contact/', 'deactivation_survey_support' ],
			'usage_optin'                    => [ 'share-usage-data/', 'usage_optin' ],
			'scan_booster'                   => [ 'pricing/', 'plugin_limit_banner' ],
			'docs_compliance_check'          => [ 'docs/', 'docs_compliance_check' ],
			'docs_data_requests'             => [ 'docs/handling-data-requests/', 'docs_data_requests' ],

			// Pro upgrade CTAs - all point at /pricing/, attributed by `utm_content`.
			'pricing_submenu_link_upgrade'   => [ 'pricing/', 'pricing_submenu_link_upgrade' ],
			'pricing_admin_toolbar_upgrade'  => [ 'pricing/', 'pricing_admin_toolbar_upgrade' ],
			'pricing_scan_cookies_banner'    => [ 'pricing/', 'pricing_scan_cookies_banner' ],
			'pricing_known_services'         => [ 'pricing/', 'pricing_known_services' ],
			'pricing_hide_branding_settings' => [ 'pricing/', 'pricing_hide_branding_settings' ],
			'pricing_reconsent_banner'       => [ 'pricing/', 'pricing_reconsent_banner' ],
			'pricing_onboarding_scan'        => [ 'pricing/', 'pricing_onboarding_scan' ],
			'pricing_onboarding_finish'      => [ 'pricing/', 'pricing_onboarding_finish' ],
			'pricing_quick_access'           => [ 'pricing/', 'pricing_quick_access' ],
			'pricing_upgrade_cpt'            => [ 'pricing/', 'pricing_upgrade_cpt' ],
			'pricing_upgrade_notice'         => [ 'pricing/', 'pricing_upgrade_notice' ],
			'pricing_geo_rules_banner'       => [ 'pricing/', 'pricing_geo_rules_banner' ],
			'pricing_hide_banner_pages'      => [ 'pricing/', 'pricing_hide_banner_pages' ],
			'pricing_learn_upgrade'          => [ 'pricing/', 'pricing_learn_upgrade' ],
			'pricing_compliance_check'       => [ 'pricing/', 'pricing_compliance_check' ],
			'pricing_data_requests'          => [ 'pricing/', 'pricing_data_requests' ],
			'coming_soon_iab_tcf'            => [ 'pricing/', 'coming_soon_iab_tcf' ],
			'coming_soon_additional_consent' => [ 'pricing/', 'coming_soon_additional_consent' ],
		];

		$links = [];
		foreach ( $entries as $key => [ $path, $content ] ) {
			$links[ $key ] = Helper::get_marketing_link( $path, $content );
		}

		/**
		 * Filter the map of UTM-tagged outbound surecookie.com links before it is exposed to JS.
		 *
		 * Allows pro/addon plugins to override or extend marketing URL attribution.
		 *
		 * @since 0.0.1-beta.2
		 *
		 * @param array<string, string> $links Map of edge identifier => UTM-tagged URL.
		 */
		$filtered = apply_filters( 'surecookie_website_links', $links );
		return is_array( $filtered ) ? $filtered : $links;
	}

	/**
	 * Doc article for each admin page, keyed by its React route path.
	 *
	 * Drives the "Learn More" icon in page headers: a route listed here gets the icon,
	 * a route absent from the map gets none, so a page whose article is not published
	 * yet never shows a link that dead-ends. Pro routes are listed here too, so Pro
	 * inherits the icon without its own map.
	 *
	 * @return array<string, string> Route path => UTM-tagged doc URL.
	 * @since 1.5.0
	 */
	public static function get_page_doc_links(): array {
		$pages = [
			// Tracking Manager.
			'/cookie-manager/scan'                    => 'docs/cookie-scanner/',
			'/cookie-manager/auto-scan'               => 'docs/automatic-scheduled-scanning/',
			'/cookie-manager/known-services'          => 'docs/using-known-services/',
			'/cookie-manager/create'                  => 'docs/cookie-manager/',
			'/cookie-manager/scripts-and-embeds'      => 'docs/resource-script-blocking/',
			'/cookie-manager/geo-rules'               => 'docs/how-to-set-up-geographic-targeting/',
			'/cookie-manager/consent-sharing'         => 'docs/consent-forwarding-multisite-network/',

			// Compliance. No entry for the Compliance Check screen until its
			// article is published: omission is what hides the header icon
			// rather than pointing it at a page that does not exist.
			'/compliance/cookie-policy'               => 'docs/how-to-generate-cookie-policy-page/',
			'/compliance/privacy-policy'              => 'docs/generating-a-privacy-policy-page/',
			'/compliance/business-details'            => 'docs/setting-up-your-business-details/',

			// Settings > Banner.
			'/banner/content'                         => 'docs/customizing-banner-content/',
			'/banner/layout'                          => 'docs/customizing-banner-layout/',
			'/banner/visibility'                      => 'docs/customize-banner-visibility-settings-in-surecookie/',
			'/banner/re-consent'                      => 'docs/re-consent/',
			'/banner/custom-css'                      => 'docs/custom-css-for-banner-styling/',

			// Settings > General.
			'/settings/general/consent-data'          => 'docs/consent-and-data-settings/',
			'/settings/general/preferences'           => 'docs/preferences-settings-menu-branding-analytics/',

			// Settings > Advanced.
			'/settings/advanced/blocked-content-ui'   => 'docs/customizing-the-blocked-content-ui/',
			'/settings/advanced/data-requests'        => 'docs/handling-data-requests/',

			// Settings > Consent Frameworks. The coming-soon pages are left out until they ship.
			'/settings/framework/google-consent-mode' => 'docs/setting-up-google-consent-mode-v2/',

			// Settings > Tools.
			'/settings/tools/mcp'                     => 'docs/connecting-ai-assistants-mcp/',
			'/settings/tools/import-export'           => 'docs/importing-and-exporting-surecookie-settings/',

			// Settings > License (Pro).
			'/settings/license'                       => 'docs/installing-surecookie-pro/',

			// Learn - the checklist points at the docs home rather than one article.
			'/learn'                                  => 'docs/',
		];

		$links = [];
		foreach ( $pages as $route => $path ) {
			// /cookie-manager/scan -> doc_cookie_manager_scan.
			$content         = 'doc_' . trim( str_replace( [ '/', '-' ], '_', $route ), '_' );
			$links[ $route ] = Helper::get_marketing_link( $path, $content );
		}

		/**
		 * Filter the per-page doc links before they are exposed to JS, so pro/addons
		 * can point their own admin routes at an article.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, string> $links Map of route path => UTM-tagged doc URL.
		 */
		$filtered = apply_filters( 'surecookie_page_doc_links', $links );
		return is_array( $filtered ) ? $filtered : $links;
	}

	/**
	 * Translatable default strings exposed to the admin UI as placeholder fallbacks.
	 *
	 * @return array<string, string>
	 * @since 0.0.1-beta.2
	 */
	public static function get_translatable_defaults(): array {
		$keys = [ 'message_heading', 'message_description', 'preferences_modal_heading', 'preferences_modal_description', 'placeholder_description', 'accept_btn_text', 'accept_all_btn_text', 'decline_btn_text', 'preferences_btn_text' ];
		return array_map( 'strval', array_intersect_key( Settings::get_settings_defaults(), array_flip( $keys ) ) );
	}

	/**
	 * Get admin localized data
	 *
	 * @return array<string, mixed> Admin localized data
	 * @since 0.0.1
	 */
	public static function get_admin_data(): array {
		$promotional_plugins = Product_Promotion::get_instance()->get_promotional_plugins_filtered();
		$shuffle_id          = ! empty( $promotional_plugins ) ? wp_rand( 0, count( $promotional_plugins ) - 1 ) : 0;
		$cron_status         = Helper::are_crons_available();
		$installed_time      = (int) get_option( 'surecookie_usage_installed_time', 0 );
		$consent_counts      = ConsentLog::count_all_actions( '', '' );
		$recent_consent_logs = ConsentLog::count_recent_logs();
		$active_threshold    = ConsentLog::active_site_log_threshold();
		$total_consent_logs  = (int) ( $consent_counts['total'] ?? 0 );

		// Show upgrade notice if either the install age gate or the consent logs gate has been passed.
		$has_install_age_gate_passed  = $installed_time > 0 && ( time() - $installed_time ) >= DAY_IN_SECONDS;
		$has_consent_logs_gate_passed = $total_consent_logs >= 10;
		$should_show_upgrade_notice   = $has_install_age_gate_passed || $has_consent_logs_gate_passed;

		$defaults = [
			// AJAX & REST API.
			'ajaxurl'                    => admin_url( 'admin-ajax.php' ),
			'adminUrl'                   => admin_url(),
			'nonce'                      => wp_create_nonce( 'wp_rest' ),

			// Plugin data.
			'promotionalPlugins'         => $promotional_plugins,
			'promotionalShuffleId'       => $shuffle_id,
			'cookieCategories'           => Settings::get( 'cookie_categories' ),
			'colorPalettes'              => Get::color_palette_codes(),
			'nudges'                     => Utils::get_instance()->get_nudges(),
			'site_activity'              => [
				'active'      => $recent_consent_logs >= $active_threshold,
				'recent_logs' => $recent_consent_logs,
				'threshold'   => $active_threshold,
				'window_days' => ConsentLog::active_site_window_days(),
			],
			'is_pro_active'              => Helper::is_pro_active(),
			'is_pro_installed'           => Helper::is_pro_installed(),
			'website_links'              => self::get_website_links(),
			'page_doc_links'             => self::get_page_doc_links(),
			'core_version'               => SURECOOKIE_VERSION,
			'eu_countries'               => Get::eu_countries(),
			'navMenus'                   => Get::nav_menus(),

			// Timezone data.
			'siteTimezone'               => wp_timezone_string(),
			'gmtOffset'                  => (float) get_option( 'gmt_offset' ),

			// Common data. Kept for back-compat with existing consumers; now UTM-tagged via the marketing-link helper.
			'defaults'                   => self::get_translatable_defaults(),
			'helpLink'                   => Helper::get_marketing_link( 'docs/', 'help_center' ),
			'whatsNewLink'               => Helper::get_marketing_link( 'whats-new/', 'whats_new' ),
			'termsConditionsURL'         => Helper::get_marketing_link( 'terms-and-conditions/', 'admin_terms_conditions' ),
			'maxScanPages'               => SiteScannerUtils::get_max_scan_pages(),

			// Assisted Scan: a browser-collected fallback for sites our scanner cannot reach.
			'assistedScanEnabled'        => AssistedScanUtils::is_enabled(),
			'assistedMaxScanPages'       => AssistedScanUtils::get_max_pages(),

			// Cron related data.
			'isCronAvailable'            => (bool) $cron_status,
			'cronType'                   => $cron_status ? $cron_status : 'unavailable',
			'is_local_site'              => SaasClient::is_local_site(),
			'show_upgrade_notice'        => $should_show_upgrade_notice,
			'version_update'             => Live_Versions::get_update_notice(),

			// Admin & user data.
			'wp_dashboard_url'           => admin_url( 'admin.php' ),
			'onboarding_complete_status' => get_option( SURECOOKIE_ONBOARDING_COMPLETED_OPTION, false ) ? 'yes' : 'no',
			'can_manage_settings'        => current_user_can( Helper::capability() ),
			// Core maps this to manage_network on multisite, where
			// the plugin capability does not reach, so the UI hides the
			// "use as WordPress privacy page" action instead of failing it.
			'can_manage_privacy_options' => current_user_can( 'manage_privacy_options' ),
			'business_shortcodes'        => Business_Shortcodes::for_admin(),
			'countries'                  => Countries::options(),
			// False hides the consent-log IP and Country columns, which hold only
			// a redaction marker on a site that opted out of IP capture.
			'consent_log_ip_enabled'     => Helper::consent_log_ip_enabled(),
		];

		$filtered = apply_filters( 'surecookie_localized_admin_data', $defaults );

		return is_array( $filtered ) ? $filtered : $defaults;
	}

	/**
	 * Get frontend localized data
	 *
	 * @return array<string, mixed> Frontend localized data.
	 * @since 0.0.1
	 */
	public static function get_frontend_data(): array {
		$cookie_policy = Get::cookie_policy_page_details();

		$data = array_merge(
			Settings::get_public_settings_dataset(),
			[
				'eu_countries'              => Get::eu_countries(),
				'scanned_cookies'           => Get::all_scanned_cookies(),
				'cookie_policy_page_url'    => $cookie_policy['url'] ?? '',
				'cookie_policy_page_title'  => $cookie_policy['title'] ?? '',
				// Rendered as first-class links inside the consent UI: with the
				// overlay on, nothing outside it is reachable (#1244).
				'policy_links'              => Get::consent_policy_links(),
				// Who receives the data per category, and the third countries it
				// may reach. Empty for a category with nothing to disclose.
				'category_disclosure'       => Get::category_disclosure(),
				'locale'                    => get_locale(),
				'siteTimezone'              => wp_timezone_string(),
				'gmtOffset'                 => (float) get_option( 'gmt_offset' ),
				'consent_log_token'         => self::get_consent_log_token(),
				// Full endpoint URLs (not a root to join) so the frontend fetch() works on plain-permalink sites too.
				'api'                       => self::consent_log_endpoints(),
				// Exposed so frontend banner / preferences modal branding links carry UTM attribution.
				// Narrowed to the keys the public bundle reads - the admin CTAs behind the rest
				// are dead weight in every visitor's HTML.
				'website_links'             => array_intersect_key(
					self::get_website_links(),
					array_flip( self::FRONTEND_WEBSITE_LINK_KEYS )
				),
				// Static UI chrome localized server-side - the public bundle
				// ships without wp-i18n, so it cannot translate at runtime.
				'branding_powered_by'       => __( 'Powered by', 'surecookie' ),
				'placeholder'               => self::get_placeholder_data(),
				// Off for a scan, an excluded region or a bypassed page; vendor hand-offs follow it.
				'blocking'                  => Blocker::get_instance()->should_process(),
				// True while `consent_model` and `blocking` above were resolved for
				// whoever primed a page cache; the runtime holds their permissive
				// side until the browser has re-resolved them (#1125).
				'consent_model_provisional' => Blocking_Utils::is_consent_decision_provisional(),
				'affiliate_carry'           => [
					'home'   => home_url( '/' ),
					'params' => self::get_affiliate_params(),
				],
			]
		);

		// Override consent_model with the geo-resolved value so the frontend JS gets the
		// right model for this visitor's location. Only when the WP Consent API initialized.
		if ( did_action( 'surecookie_wp_consent_api_initialized' ) ) {
			$data['consent_model'] = Consent_Handler::get_active_consent_model();
		}

		// Consent_Handler prints the gtag defaults itself (consent-handler.php:259, :385)
		// straight from Settings, so these three never had a reader on the public bundle.
		unset( $data['gcm_wait_for_update'], $data['gcm_default_consent'], $data['gcm_region_defaults'] );

		// wp_localize_script() html_entity_decode()s every top-level scalar, so
		// pre-empt it here - this payload reaches every anonymous visitor.
		return Sanitize::rich_text_keys_after_decode( $data );
	}

	/**
	 * Query params kept on internal links, each mapped to the catalog service whose
	 * parked tracker reads it; the carry stops once that tracker is released.
	 *
	 * @since 1.6.1
	 * @return array<string, string> Param name => catalog service slug.
	 */
	public static function get_affiliate_params(): array {
		// SureCart's tracker reads `aff` from whichever page it is released on.
		$params = apply_filters( 'surecookie_affiliate_params', defined( 'SURECART_PLUGIN_FILE' ) ? [ 'aff' => 'surecart' ] : [] );

		if ( ! is_array( $params ) ) {
			return [];
		}

		$valid = [];
		foreach ( $params as $param => $service ) {
			if ( is_string( $param ) && is_string( $service ) && preg_match( '/^[A-Za-z0-9_-]{1,32}$/', $param ) && preg_match( '/^[a-z0-9-]{1,64}$/', $service ) ) {
				$valid[ $param ] = $service;
			}
		}

		return $valid;
	}

	/**
	 * Absolute consent-log endpoint URLs for the frontend.
	 *
	 * `create_log_fallback` carries the same route on `/index.php?rest_route=`,
	 * and is present only where that differs from the canonical URL. Host rules
	 * protecting `error-log`/`debug-log` match any path ending in `-log`, so
	 * `/wp-json/.../create-log` is answered before WordPress loads and the write
	 * is lost with nothing to log it; no such rule matches `/index.php` (#1275).
	 *
	 * @since 1.7.0
	 * @return array<string, string>
	 */
	public static function consent_log_endpoints(): array {
		$endpoints = [];
		foreach ( self::CONSENT_LOG_ROUTES as $key => $route ) {
			$endpoints[ $key ] = esc_url_raw( rest_url( $route ) );
		}

		if ( strpos( $endpoints['create_log'], 'rest_route=' ) === false ) {
			$endpoints['create_log_fallback'] = esc_url_raw(
				add_query_arg( 'rest_route', '/' . self::CONSENT_LOG_ROUTES['create_log'], home_url( '/index.php' ) )
			);
		}

		return $endpoints;
	}

	/**
	 * Token issued to the frontend to prove a request came from a real page load.
	 *
	 * Stateless HMAC of the current 1-hour bucket signed with `wp_salt('auth')`.
	 * No DB I/O, no race condition; survives page caching within the validity window.
	 *
	 * @since 0.0.1-beta.2
	 * @return string Hex-encoded HMAC.
	 */
	public static function get_consent_log_token(): string {
		return self::consent_log_token_for_bucket( self::current_consent_log_bucket() );
	}

	/**
	 * Validate a consent-log token against the current and 23 prior buckets.
	 *
	 * 24 buckets (~24 h) covers the typical page-cache TTL on production sites
	 * while still bounding replay if a token leaks.
	 *
	 * @since 0.0.1-beta.2
	 * @param string $token Token from the inbound request.
	 * @return bool
	 */
	public static function is_valid_consent_log_token( string $token ): bool {
		if ( $token === '' ) {
			return false;
		}

		$bucket = self::current_consent_log_bucket();
		for ( $i = 0; $i <= 23; $i++ ) {
			if ( hash_equals( self::consent_log_token_for_bucket( $bucket - $i ), $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a token was genuinely issued by this site within the last ~30 days,
	 * including still-valid tokens.
	 *
	 * Used by the fresh-token exchange endpoint: HTML from a long-lived page cache carries
	 * an expired token that would silently drop the consent-log audit trail. Exchanging it
	 * preserves the "came from a real page load" property without shortening cacheability.
	 *
	 * @since 1.2.1
	 * @param string $token Token from the inbound request.
	 * @return bool
	 */
	public static function is_recently_issued_consent_log_token( string $token ): bool {
		// Tokens are 64-char lowercase-hex HMACs. Reject anything else before the ~720-iteration
		// loop so malformed input costs O(1) instead of a full bucket sweep.
		if ( strlen( $token ) !== 64 || ! ctype_xdigit( $token ) ) {
			return false;
		}

		$bucket = self::current_consent_log_bucket();
		for ( $i = 0; $i <= self::STALE_TOKEN_MAX_AGE_BUCKETS; $i++ ) {
			if ( hash_equals( self::consent_log_token_for_bucket( $bucket - $i ), $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get onboarding localized data
	 *
	 * @return array<string, mixed> Onboarding localized data.
	 * @since 0.0.1
	 */
	public static function get_onboarding_data(): array {
		$cron_status  = Helper::are_crons_available();
		$current_user = wp_get_current_user();

		return [
			'termsConditionsURL' => Helper::get_marketing_link( 'terms-and-conditions/', 'onboarding_terms_conditions' ),
			'is_pro_active'      => Helper::is_pro_active(),
			'is_pro_installed'   => Helper::is_pro_installed(),
			'adminUrl'           => admin_url(),
			'website_links'      => self::get_website_links(),
			'maxScanPages'       => SiteScannerUtils::get_max_scan_pages(),
			'isCronAvailable'    => (bool) $cron_status,
			'cronType'           => $cron_status ? $cron_status : 'unavailable',
			'is_local_site'      => SaasClient::is_local_site(),
			// The Finish step offers the Cookie Preferences menu link, so the
			// menu list has to be on this screen's blob, not the admin one.
			'navMenus'           => Get::nav_menus(),
			// Pre-selects the scan step's picker. Nothing was selected by default, so
			// the usual first scan aimed at whatever page was easiest to click while
			// the home page, which carries most sites' tags, went unscanned. Empty
			// when the front page is the blog index, which is not a post to select.
			'homePage'           => self::get_front_page_option(),
			'websiteLeadDetails' => [
				'firstName' => sanitize_text_field( $current_user->first_name ?? '' ),
				'lastName'  => sanitize_text_field( $current_user->last_name ?? '' ),
				'email'     => sanitize_email( $current_user->user_email ?? '' ),
			],
		];
	}

	/**
	 * The site's front page, shaped like one of the scan picker's options.
	 *
	 * @since 1.7.0
	 * @return array{value: int, label: string, type: string, typeLabel: string}|null Null
	 *                                        when the front page is the blog index.
	 */
	private static function get_front_page_option(): ?array {
		if ( get_option( 'show_on_front' ) !== 'page' ) {
			return null;
		}

		$page_id = absint( get_option( 'page_on_front' ) );

		if ( $page_id <= 0 ) {
			return null;
		}

		$page = get_post( $page_id );

		if ( ! $page || $page->post_status !== 'publish' ) {
			return null;
		}

		// Same labels the search endpoint sends, so the seeded option groups with the
		// results and reads like them (get_the_title()'s entities stay literal in this nested blob).
		$type_object = get_post_type_object( 'page' );

		return [
			'value'     => $page_id,
			'label'     => wp_strip_all_tags( $page->post_title ),
			'type'      => 'page',
			'typeLabel' => $type_object instanceof \WP_Post_Type ? $type_object->labels->name : __( 'Pages', 'surecookie' ),
		];
	}

	/**
	 * Localize admin script
	 *
	 * @param string $script_handle Script handle.
	 * @param string $object_name   Object name for JavaScript.
	 * @return void
	 * @since 0.0.1
	 */
	public static function localize_admin_script( string $script_handle, string $object_name = 'surecookieAdminData' ): void {
		wp_localize_script(
			$script_handle,
			$object_name,
			apply_filters( 'surecookie_admin_localize_data', self::get_admin_data() )
		);
	}

	/**
	 * Localize frontend script
	 *
	 * @param string $script_handle Script handle.
	 * @param string $object_name   Object name for JavaScript.
	 * @return void
	 * @since 0.0.1
	 */
	public static function localize_frontend_script( string $script_handle, string $object_name = 'surecookiePublicSettings' ): void {
		wp_localize_script(
			$script_handle,
			$object_name,
			apply_filters( 'surecookie_frontend_localize_data', self::get_frontend_data() )
		);
	}

	/**
	 * Localize onboarding script
	 *
	 * @param string $script_handle Script handle.
	 * @param string $object_name   Object name for JavaScript.
	 * @return void
	 * @since 0.0.1
	 */
	public static function localize_onboarding_script( string $script_handle, string $object_name = 'surecookieOnboardingData' ): void {
		wp_localize_script(
			$script_handle,
			$object_name,
			apply_filters( 'surecookie_onboarding_localize_data', self::get_onboarding_data() )
		);
	}

	/**
	 * Pieces the frontend needs to render a consent placeholder for an embed the
	 * DOM guard blocked at runtime.
	 *
	 * A server-blocked embed gets its placeholder rendered in PHP, where all of
	 * this is already in scope. One blocked in the browser has no server-rendered
	 * wrapper, so the same copy, palette and vendor names have to travel with the
	 * page. Empty when blocking is off, since nothing can be parked then.
	 *
	 * @since 1.4.0
	 * @return array<string, mixed>
	 */
	private static function get_placeholder_data(): array {
		if ( ! Blocking_Utils::is_blocking_enabled() ) {
			return [];
		}

		return [
			// Carries the `{service}` token; the frontend substitutes the vendor.
			// Sanitized here, not in the browser, so the value that crosses into
			// innerHTML has already been through the same filter the PHP builder
			// applies at render.
			'description' => wp_kses_post( Blocker::placeholder_description_template() ),
			'button'      => Blocker::placeholder_button_text(),
			'blocked'     => __( 'This content is currently blocked.', 'surecookie' ),
			/* translators: %s: Service name (e.g., YouTube, Google Maps) */
			'accept_aria' => __( 'Accept and load %s content', 'surecookie' ),
			'colors'      => Get::banner_display_colors(),
			// Service key => vendor label, so a parked element can name who it is
			// waiting on. The element only carries the key.
			'labels'      => Get::blocking_service_labels(),
		];
	}

	/**
	 * Current 1-hour bucket index.
	 *
	 * @since 0.0.1-beta.2
	 * @return int
	 */
	private static function current_consent_log_bucket(): int {
		return (int) floor( time() / HOUR_IN_SECONDS );
	}

	/**
	 * HMAC of a bucket index keyed with the site's auth salt.
	 *
	 * Includes the current blog id so multisite networks (where wp_salt is
	 * shared network-wide) issue distinct tokens per sub-site.
	 *
	 * @since 0.0.1-beta.2
	 * @param int $bucket Bucket index.
	 * @return string
	 */
	private static function consent_log_token_for_bucket( int $bucket ): string {
		$message = 'surecookie_consent_log|' . get_current_blog_id() . '|' . $bucket;
		return hash_hmac( 'sha256', $message, wp_salt( 'auth' ) );
	}
}
