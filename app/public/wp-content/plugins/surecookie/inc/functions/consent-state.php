<?php
/**
 * ConsentState - Server-side reader for the SureCookie consent cookie.
 *
 * Single source of truth for parsing `surecookie_user_consent` at PHP render
 * time. Consumed by the WP Consent API integration and SureCookie Pro's geo
 * rules. Consent Mode reads it in the browser instead (#1254).
 *
 * Privacy-safe defaults: missing or invalid cookie → all categories denied, and
 * a choice older than the last "Re-request Consent" counts as none (#1264).
 *
 * @package SureCookie
 * @since 1.2.4
 */

namespace SureCookie\Inc\Functions;

use SureCookie\Inc\Integrations\WpConsentApi\Consent_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * ConsentState class.
 *
 * @since 1.2.4
 */
final class ConsentState {
	/**
	 * Cookie that stores the visitor's consent payload.
	 */
	private const COOKIE_NAME = 'surecookie_user_consent';

	/**
	 * Default category keys required to consider the cookie structurally valid.
	 */
	private const REQUIRED_KEYS = [ 'essential', 'functional', 'analytics', 'marketing' ];

	/**
	 * Per-request memo of the parsed payload (`null` = no/invalid cookie).
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $payload_cache = null;

	/**
	 * Whether the memo has been populated this request.
	 *
	 * @var bool
	 */
	private static bool $cache_initialized = false;

	/**
	 * Per-request memo of the active model used when no current choice exists.
	 *
	 * @var string|null
	 */
	private static ?string $default_model_cache = null;

	/**
	 * Preferences map as `[ category => bool ]`. Null when no/invalid cookie.
	 *
	 * Uses strict `=== true` rather than `boolval()` because PHP coerces every
	 * non-empty non-"0" string to true - a tampered cookie with `"marketing":"false"`
	 * would otherwise grant marketing. Anything that isn't literally `true` in
	 * the JSON resolves to false (fail-closed). Matches the old strict
	 * `is_bool()` validation the GCM handler used before extraction.
	 *
	 * @since 1.2.4
	 * @return array<string, bool>|null
	 */
	public static function preferences(): ?array {
		$payload = self::payload();
		if ( ! is_array( $payload ) || ! is_array( $payload['preferences'] ?? null ) ) {
			return null;
		}
		return array_map(
			static function ( $value ): bool {
				return $value === true;
			},
			$payload['preferences']
		);
	}

	/**
	 * Has the visitor granted consent for `$category`?
	 *
	 * Fails closed: missing/invalid cookie returns false.
	 *
	 * @since 1.2.4
	 * @param string $category Category key (e.g., 'analytics', 'marketing').
	 * @return bool
	 */
	public static function has_category( string $category ): bool {
		$prefs = self::preferences();
		return is_array( $prefs ) && ! empty( $prefs[ $category ] );
	}

	/**
	 * Resolve one category using the shared GPC, stored-choice and model order.
	 *
	 * @param string $category SureCookie category.
	 * @return bool
	 */
	public static function allows( string $category ): bool {
		if ( $category === 'essential' ) {
			return true;
		}

		if ( self::gpc() ) {
			return false;
		}

		if ( self::has_recorded_choice() ) {
			return self::has_category( $category );
		}

		if ( self::$default_model_cache === null ) {
			self::$default_model_cache = Consent_Handler::get_active_consent_model();
		}

		return self::$default_model_cache === 'opt-out';
	}

	/**
	 * Whether the visitor has ever interacted with the banner. Distinct from
	 * `has_category` - a "decline all" choice still counts as having a cookie.
	 *
	 * @since 1.2.4
	 * @return bool
	 */
	public static function has_recorded_choice(): bool {
		return self::preferences() !== null;
	}

	/**
	 * Whether the request carries Global Privacy Control. The only server-side
	 * read, so every consumer agrees; strict `'1'`, the one value the spec defines.
	 *
	 * @since 1.6.0
	 * @return bool
	 */
	public static function gpc(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Compared against a literal, never used as data.
		return ( $_SERVER['HTTP_SEC_GPC'] ?? null ) === '1';
	}

	/**
	 * Reset the per-request memo. Test-only.
	 *
	 * @since 1.2.4
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$payload_cache       = null;
		self::$cache_initialized   = false;
		self::$default_model_cache = null;
	}

	/**
	 * Full parsed cookie payload, or null when the cookie is missing/invalid.
	 *
	 * Internal helper for preferences() / has_recorded_choice() - kept private
	 * because every consumer pattern is better served by one of the typed
	 * accessors below. Promote to public when a real external need appears.
	 *
	 * @since 1.2.4
	 * @return array<string, mixed>|null
	 */
	private static function payload(): ?array {
		if ( ! self::$cache_initialized ) {
			self::$payload_cache     = self::parse();
			self::$cache_initialized = true;
		}
		return self::$payload_cache;
	}

	/**
	 * Read + validate the cookie.
	 *
	 * Skips `sanitize_text_field` deliberately - it corrupts URL-encoded JSON.
	 * Safety comes from structural validation + boolean coercion of values.
	 *
	 * @since 1.2.4
	 * @return array<string, mixed>|null
	 */
	private static function parse(): ?array {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is validated structurally below; sanitize_text_field corrupts URL-encoded JSON.
		$raw = wp_unslash( $_COOKIE[ self::COOKIE_NAME ] );
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$decoded = json_decode( urldecode( $raw ), true );

		if ( ! is_array( $decoded ) || ! is_array( $decoded['preferences'] ?? null ) ) {
			return null;
		}

		// Require real booleans: a tampered cookie with string values (e.g.
		// "marketing":"false") is rejected outright, so a malformed cookie is
		// treated as "no recorded choice" (fail-closed, region defaults emitted).
		foreach ( self::REQUIRED_KEYS as $key ) {
			if ( ! isset( $decoded['preferences'][ $key ] ) || ! is_bool( $decoded['preferences'][ $key ] ) ) {
				return null;
			}
		}

		// A choice from before the last "Re-request Consent" is no choice, the rule
		// consentManager.isRenewalRequired() applies; an unreadable time counts as stale.
		$renewed_at = (int) Settings::get( 'consent_renewed_at' );
		$recorded   = is_string( $decoded['timestamp'] ?? null ) ? (int) strtotime( $decoded['timestamp'] ) : 0;
		if ( $renewed_at > 0 && $recorded < $renewed_at ) {
			return null;
		}

		return $decoded;
	}
}
