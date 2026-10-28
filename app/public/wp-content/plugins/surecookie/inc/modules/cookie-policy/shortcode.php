<?php
/**
 * Cookie Policy Content Shortcode.
 *
 * Registers [surecookie_cookie_policy_content] shortcode that renders
 * dynamically generated cookie tables grouped by category and provider.
 *
 * @package SureCookie\Inc\Modules\CookiePolicy
 * @since 0.0.0-alpha.1
 */

namespace SureCookie\Inc\Modules\CookiePolicy;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Integrations\Multilingual\Translation_Filter;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Traits\Shortcode as Shortcode_Trait;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Shortcode
 *
 * @since 0.0.0-alpha.1
 */
class Shortcode {
	use GetInstance;
	use Shortcode_Trait;

	/**
	 * Constructor.
	 *
	 * @since 0.0.0-alpha.1
	 */
	private function __construct() {
		$this->register_shortcodes( [ 'surecookie_cookie_policy_content' => 'content' ] );
	}

	/**
	 * Render the cookie policy content shortcode.
	 *
	 * Supports a `show_timestamp` attribute (default "true", accepts
	 * true/false/yes/no/on/off/1/0) to toggle the last updated notice.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 * @since 0.0.0-alpha.1
	 */
	public function render_content( $atts ): string {
		$atts = shortcode_atts(
			[
				'show_timestamp' => 'true',
			],
			$atts,
			'surecookie_cookie_policy_content'
		);

		wp_enqueue_style(
			'surecookie-cookie-policy',
			SURECOOKIE_URL . 'assets/css/' . Get::cookie_policy_style_css(),
			[],
			SURECOOKIE_VERSION
		);

		$category_data = self::build_category_data();

		if ( empty( $category_data ) ) {
			return '';
		}

		$output  = '<div class="surecookie-cookie-policy">';
		$output .= self::build_table_of_contents( $category_data );
		$output .= self::build_cookie_tables( $category_data );

		if ( filter_var( $atts['show_timestamp'], FILTER_VALIDATE_BOOLEAN ) ) {
			$output .= self::build_last_updated();
		}

		$output .= '</div>';

		return $output;
	}

	/**
	 * Build category data with merged cookies for non-empty categories.
	 *
	 * Pre-computes the list of categories that have cookies, so both the
	 * table of contents and the cookie tables share the same data.
	 *
	 * @since 0.0.0-alpha.1
	 * @return array<int, array{id: string, name: string, description: string, cookies: array<int, array<string, mixed>>}> Categories with cookies.
	 */
	private static function build_category_data(): array {
		$categories     = Settings::get( 'cookie_categories' );
		$scanned_raw    = Get::scanned_cookies_unshadowed();
		$custom_grouped = Get::formatted_custom_cookies();

		if ( ! is_array( $categories ) || empty( $categories ) ) {
			return [];
		}

		$data = [];

		foreach ( $categories as $category ) {
			if ( ! is_array( $category ) || empty( $category['id'] ) ) {
				continue;
			}

			$category_id = (string) $category['id'];
			$cat_key     = sanitize_key( $category_id );
			$cookies     = self::get_merged_cookies( $category_id, $scanned_raw, $custom_grouped );

			if ( empty( $cookies ) ) {
				continue;
			}

			$data[] = [
				'id'          => $category_id,
				'name'        => Translation_Filter::translate_string( (string) ( $category['name'] ?? '' ), 'surecookie_cat_' . $cat_key . '_name' ),
				'description' => Translation_Filter::translate_string( (string) ( $category['description'] ?? '' ), 'surecookie_cat_' . $cat_key . '_description' ),
				'cookies'     => $cookies,
			];
		}

		return $data;
	}

	/**
	 * Build the table of contents navigation.
	 *
	 * Generates anchor links to each cookie category that has cookies.
	 *
	 * @param array<int, array{id: string, name: string, description: string, cookies: array<int, array<string, mixed>>}> $category_data Category data.
	 * @since 0.0.0-alpha.2
	 * @return string HTML for the table of contents.
	 */
	private static function build_table_of_contents( array $category_data ): string {
		if ( count( $category_data ) < 2 ) {
			return '';
		}

		$html  = '<nav class="surecookie-cookie-policy-toc" aria-label="' . esc_attr__( 'Table of Contents', 'surecookie' ) . '">';
		$html .= '<h3>' . esc_html__( 'Table of Contents', 'surecookie' ) . '</h3>';
		$html .= '<ol>';

		foreach ( $category_data as $cat ) {
			$html .= '<li><a href="#surecookie-cat-' . esc_attr( $cat['id'] ) . '">' . esc_html( $cat['name'] ) . '</a></li>';
		}

		$html .= '</ol>';
		$html .= '</nav>';

		return $html;
	}

	/**
	 * Build HTML cookie tables grouped by category and provider.
	 *
	 * @param array<int, array{id: string, name: string, description: string, cookies: array<int, array<string, mixed>>}> $category_data Category data.
	 * @since 0.0.0-alpha.2
	 * @return string HTML tables.
	 */
	private static function build_cookie_tables( array $category_data ): string {
		$html = '';

		foreach ( $category_data as $cat ) {
			$html .= '<div class="surecookie-cookie-policy-category">';
			$html .= '<h3 id="surecookie-cat-' . esc_attr( $cat['id'] ) . '">' . esc_html( $cat['name'] ) . '</h3>';

			if ( ! empty( $cat['description'] ) ) {
				$html .= '<p>' . esc_html( $cat['description'] ) . '</p>';
			}

			$provider_groups    = self::group_cookies_by_provider( $cat['cookies'] );
			$has_multi_provider = count( $provider_groups ) > 1;

			foreach ( $provider_groups as $provider_name => $cookies ) {
				$html .= '<div class="surecookie-cookie-policy-provider">';

				if ( $has_multi_provider ) {
					$html .= '<h4 class="surecookie-cookie-policy-provider-name">' . esc_html( $provider_name ) . '</h4>';
				}

				$html .= self::build_single_table( $cookies );
				$html .= '</div>';
			}

			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * Build a single cookie table.
	 *
	 * @param array<int, array<string, mixed>> $cookies Cookies to display.
	 * @since 0.0.0-alpha.2
	 * @return string HTML table.
	 */
	private static function build_single_table( array $cookies ): string {
		$label_name     = esc_html__( 'Cookie Name', 'surecookie' );
		$label_purpose  = esc_html__( 'Purpose', 'surecookie' );
		$label_duration = esc_html__( 'Duration', 'surecookie' );
		$label_domain   = esc_html__( 'Domain', 'surecookie' );

		$html  = '<div class="surecookie-cookie-policy-table-wrap">';
		$html .= '<table class="surecookie-cookie-policy-table">';
		$html .= '<thead><tr>';
		$html .= '<th scope="col">' . $label_name . '</th>';
		$html .= '<th scope="col">' . $label_purpose . '</th>';
		$html .= '<th scope="col">' . $label_duration . '</th>';
		$html .= '<th scope="col">' . $label_domain . '</th>';
		$html .= '</tr></thead>';
		$html .= '<tbody>';

		foreach ( $cookies as $cookie ) {
			$name = esc_html( $cookie['name'] ?? '' );
			// The scan writer always sets 'description', usually to '', so prefer non-empty over non-null.
			$described = (string) ( $cookie['description'] ?? '' );
			$purpose   = esc_html( $described !== '' ? $described : (string) ( $cookie['purpose'] ?? '' ) );
			$duration  = esc_html( self::format_duration( $cookie ) );
			$domain    = esc_html( self::format_domain( $cookie ) );

			$html .= '<tr>';
			$html .= '<td data-label="' . esc_attr( $label_name ) . '">' . ( ! empty( $name ) ? $name : '-' ) . '</td>';
			$html .= '<td data-label="' . esc_attr( $label_purpose ) . '">' . ( ! empty( $purpose ) ? $purpose : '-' ) . '</td>';
			$html .= '<td data-label="' . esc_attr( $label_duration ) . '">' . ( $duration !== '' ? $duration : '-' ) . '</td>';
			$html .= '<td data-label="' . esc_attr( $label_domain ) . '">' . ( ! empty( $domain ) ? $domain : '-' ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Build the last updated notice.
	 *
	 * @since 0.0.0-alpha.2
	 * @return string HTML for the last updated date.
	 */
	private static function build_last_updated(): string {
		/**
		 * Filters the resolved "Last updated" timestamp for the cookie policy.
		 *
		 * Return a falsy value to hide the notice.
		 *
		 * @since 1.1.0
		 * @param int|null $timestamp Unix timestamp, or null when unavailable.
		 * @param int      $page_id   Configured cookie policy page ID.
		 */
		$timestamp = apply_filters(
			'surecookie_cookie_policy_last_updated_date',
			self::get_last_updated_timestamp(),
			absint( Settings::get( 'cookie_policy_page_id' ) )
		);

		if ( ! is_numeric( $timestamp ) || (int) $timestamp <= 0 ) {
			return '';
		}

		$formatted_date = date_i18n( get_option( 'date_format' ), (int) $timestamp );

		return '<p class="surecookie-cookie-policy-last-updated">'
			. esc_html(
				sprintf(
					/* translators: %s: formatted date string. */
					__( 'Last updated: %s', 'surecookie' ),
					$formatted_date
				)
			)
			. '</p>';
	}

	/**
	 * Resolve the last updated timestamp.
	 *
	 * Uses the later of the policy page's modified date and the last scan
	 * date, so manual page edits and fresh scans both refresh the notice.
	 *
	 * @since 1.1.0
	 * @return int|null Unix timestamp, or null when none is available.
	 */
	private static function get_last_updated_timestamp(): ?int {
		$timestamps = array_filter(
			[
				self::get_modified_post_timestamp(),
				self::get_scan_timestamp(),
			]
		);

		return empty( $timestamps ) ? null : max( $timestamps );
	}

	/**
	 * Get the modified timestamp of the rendered post, falling back to the
	 * configured cookie policy page when it is published.
	 *
	 * @since 1.1.0
	 * @return int|null Unix timestamp, or null when unavailable.
	 */
	private static function get_modified_post_timestamp(): ?int {
		$timestamp = get_post_modified_time( 'U' );

		if ( $timestamp === false ) {
			$page_id = absint( Settings::get( 'cookie_policy_page_id' ) );

			if ( $page_id > 0 && get_post_status( $page_id ) === 'publish' ) {
				$timestamp = get_post_modified_time( 'U', false, $page_id );
			}
		}

		return $timestamp === false ? null : (int) $timestamp;
	}

	/**
	 * Get the last cookie scan timestamp.
	 *
	 * @since 1.1.0
	 * @return int|null Unix timestamp, or null when unavailable.
	 */
	private static function get_scan_timestamp(): ?int {
		$scan_details = Get::option( SURECOOKIE_SCANNED_DETAILS_OPTION, [], 'array' );
		$scan_date    = $scan_details['date'] ?? '';
		$timestamp    = is_string( $scan_date ) && $scan_date !== '' ? strtotime( $scan_date ) : false;

		return $timestamp === false ? null : $timestamp;
	}

	/**
	 * Group cookies by provider.
	 *
	 * Uses a fallback chain: provider field → domain extraction → site host.
	 *
	 * @param array<int, array<string, mixed>> $cookies Flat list of cookies.
	 * @since 0.0.0-alpha.2
	 * @return array<string, array<int, array<string, mixed>>> Cookies grouped by provider name.
	 */
	private static function group_cookies_by_provider( array $cookies ): array {
		$groups = [];

		foreach ( $cookies as $cookie ) {
			// Resolved here, not trusted from the producer: this is the sink that
			// uses the leaf as an array key, and an array one is a fatal.
			$provider = Sanitize::scalar( $cookie['provider'] ?? '' );

			if ( empty( $provider ) ) {
				$provider = ltrim( Sanitize::scalar( $cookie['domain'] ?? '' ), '.' );
			}

			if ( empty( $provider ) ) {
				$provider = wp_parse_url( home_url(), PHP_URL_HOST );

				if ( empty( $provider ) ) {
					$provider = __( 'This website', 'surecookie' );
				}
			}

			$groups[ $provider ][] = $cookie;
		}

		return $groups;
	}

	/**
	 * Merge scanned and custom cookies for a given category.
	 *
	 * @param string                                    $category_id    Category identifier.
	 * @param array<string, array<int, mixed>>          $scanned_raw    Raw scanned cookies grouped by category.
	 * @param array<string, list<array<string, mixed>>> $custom_grouped Custom cookies grouped by category.
	 * @return array<int, array<string, mixed>> Merged cookie list.
	 * @since 0.0.0-alpha.2
	 */
	private static function get_merged_cookies( string $category_id, array $scanned_raw, array $custom_grouped ): array {
		$scanned = [];
		$custom  = [];

		if ( isset( $scanned_raw[ $category_id ] ) && is_array( $scanned_raw[ $category_id ] ) ) {
			$scanned = $scanned_raw[ $category_id ];
		}

		if ( isset( $custom_grouped[ $category_id ] ) && is_array( $custom_grouped[ $category_id ] ) ) {
			$custom = $custom_grouped[ $category_id ];
		}

		return array_merge( $scanned, $custom );
	}

	/**
	 * Format a cookie duration for display.
	 *
	 * @param array<string, mixed> $cookie Cookie data.
	 * @return string Formatted duration string.
	 * @since 0.0.0-alpha.1
	 */
	private static function format_duration( array $cookie ): string {
		$session = __( 'Session', 'surecookie' );

		// Checked before any day count: a row the scanner marked as session-scoped is
		// session-scoped whatever number a catalog pinned alongside it.
		if ( ( $cookie['expiry_bucket'] ?? '' ) === 'session' ) {
			return $session;
		}

		$duration = (string) ( $cookie['duration'] ?? '' );

		if ( $duration !== '' ) {
			// A sub-day lifetime floors to zero days - true of catalog entries such as
			// DoubleClick's test_cookie. "0" reads as an error to a visitor, and it is
			// the same figure the old decaying count produced, so neither may ship.
			return is_numeric( $duration ) && (float) $duration <= 0 ? $session : $duration;
		}

		// Rows stored before the day count was derived at scan time still carry only the
		// absolute expiry, which must not reach the table as a raw ISO-8601 string.
		if ( ! empty( $cookie['expires'] ) ) {
			$days = self::expires_to_days( $cookie['expires'] );

			return $days === '0' ? $session : $days;
		}

		return '';
	}

	/**
	 * Convert an absolute cookie expiry into a whole number of days from now.
	 *
	 * Accepts the ISO-8601 timestamps relayed from the scan API, the
	 * 'Y-m-d H:i:s' UTC strings written for custom and declared cookies, and
	 * bare Unix timestamps from older records.
	 *
	 * @param mixed $expires Raw expiry value.
	 * @since 1.3.0
	 * @return string Day count, or an empty string when the value is unusable.
	 */
	private static function expires_to_days( $expires ): string {
		if ( is_numeric( $expires ) ) {
			$timestamp = (int) $expires;
		} elseif ( is_string( $expires ) ) {
			// 'Y-m-d H:i:s' carries no timezone but is stored as UTC, so pin it
			// to UTC instead of letting strtotime() assume server-local time.
			$normalized = preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', trim( $expires ) )
				? str_replace( ' ', 'T', trim( $expires ) ) . '+00:00'
				: trim( $expires );

			$timestamp = strtotime( $normalized );
		} else {
			return '';
		}

		if ( empty( $timestamp ) ) {
			return '';
		}

		$diff = $timestamp - time();

		return $diff <= 0 ? '0' : (string) (int) ceil( $diff / DAY_IN_SECONDS );
	}

	/**
	 * Format a cookie domain for display.
	 *
	 * @param array<string, mixed> $cookie Cookie data.
	 * @return string Formatted domain string.
	 * @since 0.0.0-alpha.1
	 */
	private static function format_domain( array $cookie ): string {
		$domain = $cookie['domain'] ?? '';

		if ( ! empty( $domain ) ) {
			return (string) $domain;
		}

		return '';
	}
}
