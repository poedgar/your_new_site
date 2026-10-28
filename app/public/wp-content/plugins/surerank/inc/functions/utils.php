<?php
/**
 * Utils.
 *
 * Utils module class for handling utils functions.
 *
 * @package SureRank\Inc\Functions;
 * @since 1.5.0
 */

namespace SureRank\Inc\Functions;

use SureRank\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Utils class
 *
 * Main module class for utils functions.
 */
class Utils {

	use Get_Instance;

	/**
	 * Convert absolute URL to relative path.
	 *
	 * Removes the home URL and trailing slashes from the given URL.
	 *
	 * @param string $url Full URL.
	 * @return string Relative path without leading/trailing slashes.
	 * @since 1.5.0
	 */
	public static function get_relative_url( $url ) {
		$home_url = trailingslashit( home_url() );
		$relative = str_replace( $home_url, '', trailingslashit( $url ) );
		return rtrim( $relative, '/' );
	}

	/**
	 * Build a surerank.com URL with standard UTM tracking parameters.
	 *
	 * If the URL already contains any UTM parameter it is returned unchanged.
	 * Asset URLs (wp-content/uploads) are also returned unchanged.
	 * The URL is normalised to HTTPS before appending parameters.
	 *
	 * Standard UTM set:
	 *   utm_source   = surerank_plugin
	 *   utm_medium   = in_product
	 *   utm_campaign = <surface>   (e.g. 'admin_dashboard', 'sitemap')
	 *   utm_content  = <context>   (e.g. 'help_link', 'support_link')
	 *
	 * @since 1.7.4
	 * @param string $url      Base surerank.com URL.
	 * @param string $campaign UTM campaign value (surface identifier).
	 * @param string $content  UTM content value (CTA / context identifier).
	 * @return string Raw (unescaped) URL with UTM parameters appended. Callers
	 *                must apply esc_url() when outputting in HTML attributes.
	 */
	public static function get_utm_url( $url, $campaign, $content ) {
		// Guard: return empty string for a missing URL.
		if ( empty( $url ) ) {
			return '';
		}

		// Normalize to https.
		$url = (string) preg_replace( '#^http://#i', 'https://', $url );

		$parsed_url = wp_parse_url( $url );
		$host       = isset( $parsed_url['host'] ) ? strtolower( (string) $parsed_url['host'] ) : '';
		$path       = isset( $parsed_url['path'] ) ? (string) $parsed_url['path'] : '';

		// Only tag website links on surerank.com, not other subdomains such as API endpoints.
		if ( ! in_array( $host, [ 'surerank.com', 'www.surerank.com' ], true ) ) {
			return esc_url_raw( $url );
		}

		// Leave asset URLs untouched.
		if ( strpos( $path, '/wp-content/uploads' ) !== false ) {
			return esc_url_raw( $url );
		}

		// Leave URLs that already carry any UTM parameter untouched.
		if ( ! empty( $parsed_url['query'] ) ) {
			parse_str( (string) $parsed_url['query'], $query_args );

			foreach ( array_keys( $query_args ) as $query_key ) {
				if ( 0 === strpos( (string) $query_key, 'utm_' ) ) {
					return esc_url_raw( $url );
				}
			}
		}

		return esc_url_raw(
			add_query_arg(
				[
					'utm_source'   => 'surerank_plugin',
					'utm_medium'   => 'in_product',
					'utm_campaign' => sanitize_key( $campaign ),
					'utm_content'  => sanitize_key( $content ),
				],
				$url
			)
		);
	}
}
