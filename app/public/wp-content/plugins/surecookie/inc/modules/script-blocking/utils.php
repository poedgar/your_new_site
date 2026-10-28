<?php
/**
 * Utils Script Blocking.
 *
 * @package SureCookie\Inc\Modules\ScriptBlocking
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Utils
 *
 * @since 0.0.1
 */
class Utils {
	/**
	 * Check whether script and content blocking feature is enabled.
	 *
	 * @since 0.0.1
	 * @return bool
	 */
	public static function is_blocking_enabled(): bool {
		static $cached = null;

		if ( $cached !== null ) {
			return $cached;
		}

		$banner_enabled  = (bool) Settings::get( 'banner_enabled' );
		$feature_enabled = (bool) Settings::get( 'blocking_enabled' );
		$status          = $banner_enabled && $feature_enabled;

		$cached = (bool) apply_filters( 'surecookie_is_blocking_enabled', $status );

		return $cached;
	}

	/**
	 * Check if blocking should be processed based on geo-location rules.
	 *
	 * @since 0.0.1
	 * @return bool True if blocking should proceed, false to bypass.
	 */
	public static function should_process_based_on_geo(): bool {
		return (bool) apply_filters( 'surecookie_should_process_blocking_geo', true );
	}

	/**
	 * Whether the consent model baked into this render was resolved for one
	 * visitor while the browser will resolve it again for whoever reads the page.
	 *
	 * Pro's geographic rules answer true: the region decides the consent model,
	 * a full-page cache hands the render to a visitor in another region, and
	 * `geolocation.js` re-resolves it. Until it has, no layer may act on the
	 * permissive side of that model - an opt-out default released a cached
	 * EU page's trackers before consent (#1125). One read, so the page payload
	 * and the DOM guard cannot disagree about it.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public static function is_consent_decision_provisional(): bool {
		/**
		 * Filter whether this render's consent model is provisional.
		 *
		 * @since 1.7.0
		 * @param bool $provisional Whether the browser will re-resolve it.
		 */
		return (bool) apply_filters( 'surecookie_consent_decision_is_provisional', false );
	}

	/**
	 * Whether this request is the SaaS scanner presenting a valid bypass token.
	 *
	 * Lives here rather than in the Blocker because gating happens in more than
	 * one place: the Blocker rewrites the output buffer, while integrations such
	 * as Presto Player replace their markup in `render_block`, well before the
	 * buffer is filtered. Any code that hides a third-party resource must honour
	 * the same bypass, otherwise a scan sees a consent placeholder instead of the
	 * embed and the resource is never detected.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	public static function is_scan_bypass_request(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Strict regex below rejects anything but [0-9a-f]{64}.
		$header_value = $_SERVER['HTTP_X_SURECOOKIE_SCAN'] ?? '';

		if ( ! is_string( $header_value ) || ! preg_match( '/^[0-9a-f]{64}$/i', $header_value ) ) {
			return false;
		}

		$stored_token = get_transient( SaasClient::SCAN_BYPASS_TRANSIENT_KEY );

		// Fail closed if the transient is corrupted or returns a non-string (object cache edge cases).
		if ( ! is_string( $stored_token ) || ! preg_match( '/^[0-9a-f]{64}$/i', $stored_token ) ) {
			return false;
		}

		return hash_equals( $stored_token, $header_value );
	}

	/**
	 * Whether this request is a scan looking at the site the way a consenting
	 * visitor would see it.
	 *
	 * True for the remote scanner's bypass request and, via the filter, for an
	 * assisted scan running in the admin's own browser. Both already stand the
	 * blocker down so the real third-party tags run; this is what tells the rest
	 * of the plugin that they should also be allowed to finish their work.
	 *
	 * @since 1.4.0
	 * @return bool
	 */
	public static function is_scan_probe(): bool {
		/**
		 * Filter whether the current request is a scan probing the site.
		 *
		 * The assisted-scan collector answers true for its own requests. Return
		 * false to keep a scan gated exactly as a first-time visitor would be,
		 * accepting that anything consent withholds stays undetectable.
		 *
		 * @since 1.4.0
		 * @param bool $is_probe Whether this request is a scan probe.
		 */
		return (bool) apply_filters( 'surecookie_is_scan_probe', self::is_scan_bypass_request() );
	}
}
