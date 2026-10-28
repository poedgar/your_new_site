<?php
/**
 * Helper
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Functions;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Helper
 * This class will handle all helper functions.
 *
 * @since 1.0.0
 */
class Helper {
	/**
	 * Recorded in place of a value a site has opted not to collect.
	 *
	 * Non-empty on purpose: `ConsentLog::upsert()` rejects an empty IP or country
	 * and would drop the whole proof-of-consent row rather than the field.
	 *
	 * @since 1.5.0
	 */
	public const REDACTED = '-';

	/**
	 * Query vars a frontend builder sets on its own edit render.
	 *
	 * @since 1.5.0
	 */
	private const BUILDER_QUERY_VARS = [ 'elementor-preview', 'fl_builder', 'et_fb', 'bricks', 'vc_editable' ];

	/**
	 * The capability every SureCookie admin surface is gated on.
	 *
	 * Resolved per call rather than cached: the filter is the only lever a site
	 * has to keep its own Administrators out of the consent configuration, and
	 * it is registered long after the constant is defined.
	 *
	 * @since 1.6.0
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filter: capability required to manage SureCookie.
		 *
		 * @since 1.6.0
		 * @param string $capability Capability name. Default SURECOOKIE_CAPABILITY.
		 */
		$capability = apply_filters( 'surecookie_capability', SURECOOKIE_CAPABILITY );

		// An empty or non-string value reaches current_user_can() as a capability
		// nobody holds, locking every user out with no way back through the UI.
		return is_string( $capability ) && $capability !== '' ? $capability : SURECOOKIE_CAPABILITY;
	}

	/**
	 * Whether this request is a frontend builder's own edit render.
	 *
	 * The capability is the credential: a query var alone would let any visitor
	 * turn blocking off and hide the banner. Blocking and asset enqueueing both
	 * read this, so the two cannot drift into a canvas whose scripts are already
	 * unblocked but which still gets a consent banner mounted over it.
	 *
	 * @since 1.5.0
	 * @return bool
	 */
	public static function is_builder_edit_render(): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence-only gate; the capability above is the credential.
		$query = wp_unslash( $_GET );

		/**
		 * Filter: query vars that mark a frontend builder's own edit render.
		 *
		 * No list of builders stays complete, so a site on one we do not ship can
		 * name its own rather than turning blocking off site-wide.
		 *
		 * @since 1.5.0
		 * @param array<int, string> $query_vars Builder query vars.
		 */
		$query_vars = apply_filters( 'surecookie_builder_query_vars', self::BUILDER_QUERY_VARS );
		$query_vars = is_array( $query_vars ) ? array_filter( $query_vars, 'is_string' ) : self::BUILDER_QUERY_VARS;

		foreach ( $query_vars as $query_var ) {
			if ( isset( $query[ $query_var ] ) ) {
				return true;
			}
		}

		// Core's preview needs the id or nonce too, or a bare `?preview=1` would
		// turn blocking off site-wide.
		return isset( $query['preview'] ) && ( isset( $query['preview_id'] ) || isset( $query['preview_nonce'] ) );
	}

	/**
	 * Whether visitor IP addresses may be used for consent records.
	 *
	 * Off makes the consent-log path IP-free end to end: nothing is derived from
	 * the address, so no geolocation request leaves the site and both stored
	 * fields become `self::REDACTED`. Pro's Geographic Targeting resolves a
	 * country for a different purpose, is disclosed separately, and is untouched.
	 *
	 * @since 1.5.0
	 * @return bool False once a site opts out via `surecookie_skip_consent_logs_ips`.
	 */
	public static function consent_log_ip_enabled(): bool {
		/**
		 * Filter: skip the visitor IP in consent records entirely.
		 *
		 * True stores no IP and no country, and sends no IP to the geolocation
		 * service. The consent record itself is still written, so Article 7(1)
		 * proof of consent survives.
		 *
		 * @param bool $skip Whether to omit the IP. Default false.
		 * @since 1.5.0
		 */
		return ! apply_filters( 'surecookie_skip_consent_logs_ips', false );
	}

	/**
	 * Check if development mode is enabled.
	 *
	 * @since 0.0.1
	 * @return bool True if development mode is enabled.
	 */
	public static function is_development_mode() {
		return defined( 'SURECOOKIE_DEVELOPMENT_MODE' ) && SURECOOKIE_DEVELOPMENT_MODE;
	}

	/**
	 * Recursively decode HTML entities in arrays, objects or strings.
	 *
	 * @param mixed $value Array, object, string or other.
	 * @return mixed Decoded value of the same type.
	 */
	public static function decode_html_entities_recursive( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::decode_html_entities_recursive( $item );
			}
			return $value;
		}

		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $prop => $item ) {
				$value->{$prop} = self::decode_html_entities_recursive( $item );
			}
			return $value;
		}

		if ( is_string( $value ) ) {
			return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		// leave ints, bools, null, etc. untouched.
		return $value;
	}

	/**
	 * Check if crons are available and working.
	 *
	 * Returns 'wp_cron' when WP-Cron works normally,
	 * 'server_cron' when DISABLE_WP_CRON is true (hosting panel / server-level cron),
	 * or false when cron is genuinely broken.
	 *
	 * @since 0.0.1
	 * @return string|false 'wp_cron', 'server_cron', or false.
	 */
	public static function are_crons_available() {
		global $wp_version;

		// If we're currently running within a cron, it's obviously working.
		if ( wp_doing_cron() ) {
			return 'wp_cron';
		}

		// DISABLE_WP_CRON is commonly set by hosting panels (Plesk, cPanel, etc.)
		// that replace WP-Cron with a real server cron job - this is actually a
		// better setup than default WP-Cron. Treat it as available.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return 'server_cron';
		}

		// ALTERNATE_WP_CRON uses a redirect-based approach - still functional.
		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			return 'wp_cron';
		}

		// Check cached status.
		$cached_status = get_option( 'surecookie_cron_test_ok' );

		if ( $cached_status === 'yes' ) {
			return 'wp_cron';
		}
		if ( $cached_status === 'no' ) {
			return false;
		}

		// Prepare cron test request.
		$ssl_verify    = version_compare( $wp_version, '4.0', '<' );
		$doing_wp_cron = sprintf( '%.22F', microtime( true ) );

		$cron_request = apply_filters(
			'surecookie_cron_request',
			[
				'url'  => site_url( 'wp-cron.php?doing_wp_cron=' . $doing_wp_cron ),
				'key'  => $doing_wp_cron,
				'args' => [
					'timeout'   => 5, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
					'blocking'  => true,
					'sslverify' => apply_filters( 'https_local_ssl_verify', $ssl_verify ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
				],
			]
		);

		// Ensure blocking is set to true.
		$cron_request['args']['blocking'] = true;

		// Test cron spawn by sending a request to wp-cron.php.
		$result = wp_safe_remote_post( $cron_request['url'], $cron_request['args'] );

		// Handle errors - cache failures to prevent performance issues.
		if ( is_wp_error( $result ) ) {
			update_option( 'surecookie_cron_test_ok', 'no' );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $result );

		// Check for non-success HTTP response codes or empty response.
		if ( empty( $response_code ) || $response_code < 200 || $response_code >= 300 ) {
			update_option( 'surecookie_cron_test_ok', 'no' );
			return false;
		}

		// Success - cache the result.
		update_option( 'surecookie_cron_test_ok', 'yes' );
		return 'wp_cron';
	}

	/**
	 * Get SureCookie Agent App API URL.
	 *
	 * Returns the base API URL, handling local development overrides.
	 *
	 * @since 0.0.1
	 * @return string The Agent App API URL.
	 */
	public static function get_agent_app_url(): string {
		$url = defined( 'SURECOOKIE_AGENT_APP_URL' ) ? SURECOOKIE_AGENT_APP_URL : 'https://library.surecookie.com/';

		return rtrim( $url, '/' ) . '/';
	}

	/**
	 * Check if SureCookie Pro is active or not.
	 *
	 * @since 0.0.1
	 * @return bool True if pro is active, false otherwise.
	 */
	public static function is_pro_active() {
		return defined( 'SURECOOKIE_PRO_VERSION' );
	}

	/**
	 * Check if SureCookie Pro is installed, whether or not it is active.
	 *
	 * Lets the UI distinguish "install and activate" from "buy a license" when
	 * nudging users toward premium scan limits.
	 *
	 * @since 1.3.0
	 * @return bool True if the Pro plugin files are present.
	 */
	public static function is_pro_installed(): bool {
		if ( self::is_pro_active() ) {
			return true;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array_keys( get_plugins() ) as $plugin ) {
			if ( strpos( (string) $plugin, 'surecookie-pro/' ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a UTM-ready surecookie.com URL.
	 *
	 * `utm_source` is injected automatically by BSF_UTM_Analytics based on the recorded
	 * plugin install referer; callers only need to supply `utm_medium` (and optionally
	 * `utm_campaign`) in `$utm_args`.
	 *
	 * @since 1.0.0
	 *
	 * @param string                $trail    Path appended to SURECOOKIE_WEBSITE (e.g. 'pricing/'). Leading slash is stripped.
	 * @param array<string, string> $utm_args Extra utm_* query arguments.
	 * @return string Escaped URL.
	 */
	public static function get_website_url( string $trail = '', array $utm_args = [] ): string {
		$url = defined( 'SURECOOKIE_WEBSITE' ) ? SURECOOKIE_WEBSITE : 'https://surecookie.com/';

		if ( $trail !== '' ) {
			$url .= ltrim( $trail, '/' );
		}

		if ( class_exists( 'BSF_UTM_Analytics' ) ) {
			$url = \BSF_UTM_Analytics::get_utm_ready_link( $url, 'surecookie', $utm_args );
		}

		// BSF_UTM_Analytics returns the URL unchanged when no referer has been recorded for the product slug yet - which would drop the caller's utm_medium/utm_campaign.
		// Fall back to appending them directly so attribution works from day one.
		if ( ! empty( $utm_args ) && strpos( $url, 'utm_' ) === false ) {
			$url = add_query_arg( $utm_args, $url );
		}

		return esc_url( $url );
	}

	/**
	 * Build a UTM-tagged marketing link with per-site attribution for outbound CTAs.
	 *
	 * Attribution convention across all outbound surecookie.com links:
	 * `utm_source` is the SITE DOMAIN (so every installation is identifiable
	 * in analytics - which sites drive powered-by clicks, which convert),
	 * `utm_medium=wordpress_plugin`, `utm_campaign=core_plugin`. Callers only
	 * supply the path and an edge identifier for `utm_content`.
	 *
	 * Unlike {@see Helper::get_website_url()}, this does NOT route through
	 * BSF_UTM_Analytics - we need a deterministic `utm_source` even when the
	 * install referer is recorded.
	 *
	 * Uses `esc_url_raw()` instead of `esc_url()`: values returned here are exposed
	 * to JS via wp_localize_script and set as `href` attributes by React - which
	 * doesn't HTML-decode, so `esc_url()`'s `&#038;` entities would break query
	 * strings carrying multiple utm params.
	 *
	 * @since 0.0.1-beta.2
	 * @since 1.3.0 `utm_source` is the site domain instead of the static `surecookie_plugin`.
	 *
	 * @param string                $path        Path appended to SURECOOKIE_WEBSITE (e.g. 'docs/', 'contact/'). Empty for the homepage.
	 * @param string                $utm_content Edge identifier, e.g. 'help_center', 'banner_branding'.
	 * @param array<string, string> $extra       Overrides merged last (rarely needed).
	 * @return string Sanitized URL with UTM query string.
	 */
	public static function get_marketing_link( string $path, string $utm_content, array $extra = [] ): string {
		$base = defined( 'SURECOOKIE_WEBSITE' ) ? SURECOOKIE_WEBSITE : 'https://surecookie.com/';

		if ( $path !== '' ) {
			$base .= ltrim( $path, '/' );
		}

		$utm_args = array_merge(
			[
				'utm_source'   => self::get_utm_source(),
				'utm_medium'   => 'wordpress_plugin',
				'utm_campaign' => 'core_plugin',
				'utm_content'  => $utm_content,
			],
			$extra
		);

		return esc_url_raw( add_query_arg( $utm_args, $base ) );
	}

	/**
	 * The per-site `utm_source`: this installation's domain (host of home_url,
	 * multisite-aware), so analytics can attribute clicks and conversions to
	 * the exact site. Falls back to the legacy static value when the host
	 * cannot be resolved.
	 *
	 * @since 1.3.0
	 * @return string
	 */
	public static function get_utm_source(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $host ) || $host === '' ) {
			return 'surecookie_plugin';
		}

		return strtolower( sanitize_text_field( $host ) );
	}
}
