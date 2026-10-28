<?php
/**
 * Automatic Scanning classifier.
 *
 * Rule-based suggestion of a category (Necessary / Analytics / Marketing /
 * Preferences) for newly-detected cookies, with a confidence score. Pure
 * functions - they only suggest; applying the category (auto-apply) is a Pro
 * feature. The `surecookie_auto_scan_classify` filter is the AI extension hook.
 *
 * @package SureCookie\Inc\Modules\AutomaticScanning
 * @since 1.2.0
 */

namespace SureCookie\Inc\Modules\AutomaticScanning;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Classifier
 *
 * @since 1.2.0
 */
class Classifier {
	/**
	 * Cached rules so the array is built only once per request.
	 *
	 * @var array<int, array{name_regex:string, category:string, confidence:int}>|null
	 * @since 1.2.0
	 */
	private static $rules_cache = null;

	/**
	 * Built-in classification rules, evaluated in order (first match wins).
	 *
	 * Each rule: name_regex (matched against the cookie name), the plugin
	 * category key it maps to, and a confidence score (0-100).
	 *
	 * @since 1.2.0
	 * @return array<int, array{name_regex:string, category:string, confidence:int}>
	 */
	public static function default_rules(): array {
		if ( self::$rules_cache !== null ) {
			return self::$rules_cache;
		}

		self::$rules_cache = [
			// Strictly necessary (session, auth, security, commerce sessions).
			[
				'name_regex' => '/^(PHPSESSID|XSRF-TOKEN|csrftoken|__cf_bm|cf_clearance|wordpress_(logged_in_|sec_)?|wp-settings|woocommerce_|wp_woocommerce_session_|edd_)/i',
				'category'   => 'essential',
				'confidence' => 95,
			],
			// Analytics (GA, Hotjar, Clarity, Segment).
			[
				'name_regex' => '/^(_ga(_.+)?|_gid|_gat.*|__utm.*|_hj.*|_clck|_clsk|ajs_)/i',
				'category'   => 'analytics',
				'confidence' => 90,
			],
			// Marketing / advertising (Meta, Google Ads, DoubleClick, TikTok, LinkedIn, Bing).
			[
				'name_regex' => '/^(_fbp|fr|_gcl_.+|IDE|test_cookie|_ttp|personalization_id|li_sugr|bcookie|bscookie|MUID|_uetsid|_uetvid)/i',
				'category'   => 'marketing',
				'confidence' => 90,
			],
			// Preferences (language / localization).
			[
				'name_regex' => '/^(pll_language|wp-wpml_current_language|_icl_.+|googtrans|wp_lang)/i',
				'category'   => 'functional',
				'confidence' => 85,
			],
		];

		return self::$rules_cache;
	}

	/**
	 * Classify a cookie into a suggested category with a confidence score.
	 *
	 * Falls back to the cookie's existing (SaaS-assigned) category with zero
	 * confidence when no rule matches.
	 *
	 * @param array<string, mixed> $cookie Cookie data (expects at least 'name').
	 * @since 1.2.0
	 * @return array{category:string, confidence:int, matched_rule:string}
	 */
	public static function classify( array $cookie ): array {
		$name = (string) ( $cookie['name'] ?? '' );

		$result = [
			'category'     => (string) ( $cookie['category'] ?? 'uncategorized' ),
			'confidence'   => 0,
			'matched_rule' => '',
		];

		if ( $name !== '' ) {
			foreach ( self::default_rules() as $rule ) {
				$pattern = $rule['name_regex'] ?? '';

				if ( $pattern !== '' && preg_match( $pattern, $name ) ) {
					$result = [
						'category'     => (string) $rule['category'],
						'confidence'   => (int) $rule['confidence'],
						'matched_rule' => (string) $pattern,
					];
					break;
				}
			}
		}

		/**
		 * Filters the classification result for a cookie.
		 *
		 * The future AI classification module (or the MCP classify ability) can
		 * hook this to resolve low-confidence / unmatched cookies.
		 *
		 * @since 1.2.0
		 *
		 * @param array{category:string, confidence:int, matched_rule:string} $result Rule-based result.
		 * @param array<string, mixed>                                        $cookie The cookie being classified.
		 */
		$filtered = apply_filters( 'surecookie_auto_scan_classify', $result, $cookie );
		return is_array( $filtered ) ? $filtered : $result;
	}
}
