<?php
/**
 * Sitemap Utilities
 *
 * Utility functions to manage XML headers, stylesheets, and other reusable functions for sitemaps.
 *
 * @since 0.0.1
 * @package SureRank
 */

namespace SureRank\Inc\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use DOMDocument;
use DOMElement;
use DOMProcessingInstruction;
use RuntimeException;
use SureRank\Inc\Functions\Get;
use SureRank\Inc\Traits\Get_Instance;

/**
 * Sitemap Utility Functions Class
 *
 * Provides methods for generating XML for sitemap indexes and main sitemaps.
 *
 * @since 1.0.0
 */
class Utils {
	use Get_Instance;

	/**
	 * Generates the XML for the sitemap index.
	 *
	 * Pure: returns a string; does not send headers or exit. Callers are
	 * responsible for transport (headers + echo). Keeps this method unit-testable.
	 *
	 * @param array<string, mixed>|array<int, string> $sitemap List of sitemap URLs to include in the index.
	 * @return string XML string for the sitemap index.
	 * @throws RuntimeException If XML generation fails.
	 */
	public static function sitemap_index( array $sitemap ): string {
		$dom            = self::create_dom();
		$xsl_stylesheet = self::add_stylesheet( $dom, 'sitemap-stylesheet.xsl' );
		$dom->appendChild( $xsl_stylesheet );

		$sitemap_index = self::create_urlset( 'sitemapindex', $dom );

		// Add each sitemap URL to the <sitemapindex>.
		foreach ( $sitemap as $url ) {
			$sitemap_element   = $dom->createElement( 'sitemap' );
			$loc               = $dom->createElement( 'loc', esc_url( (string) $url['link'] ) );
			$formatted_lastmod = (string) $url['updated'];
			$lastmod           = $dom->createElement( 'lastmod', esc_xml( (string) $formatted_lastmod ) );
			$sitemap_element->appendChild( $loc );
			$sitemap_element->appendChild( $lastmod );
			$sitemap_index->appendChild( $sitemap_element );
		}

		// Return the XML as a string or throw an exception if saveXML fails.
		$xml_string = $dom->saveXML();
		if ( false === $xml_string ) {
			throw new RuntimeException( 'Failed to generate sitemap index XML.' );
		}
		return $xml_string;
	}

	/**
	 * Generates the XML for the main sitemap with individual URLs.
	 *
	 * Pure: returns a string; does not send headers or exit. Callers are
	 * responsible for transport (headers + echo). Keeps this method unit-testable.
	 *
	 * @param array<string, mixed>|array<int, string> $sitemap Array of URL data, each containing 'link', 'updated', and optional 'images'.
	 * @return string XML string for the main sitemap.
	 * @throws RuntimeException If XML generation fails.
	 */
	public static function sitemap_main( array $sitemap ): string {
		$dom  = self::create_dom();
		$xslt = self::add_stylesheet( $dom, 'sitemap-stylesheet.xsl' );
		$dom->appendChild( $xslt );

		// Create the root <urlset> element.
		$urlset = self::create_urlset( 'urlset', $dom );
		$urlset->setAttribute( 'xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
		$urlset->setAttribute( 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance' );
		$urlset->setAttribute( 'xsi:schemaLocation', 'http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd' );
		$urlset->setAttribute( 'xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1' );

		if ( apply_filters( 'surerank_sitemap_has_hreflang', true ) ) {
			$urlset->setAttribute( 'xmlns:xhtml', 'http://www.w3.org/1999/xhtml' );
		}

		foreach ( $sitemap as $url ) {
			$url_element = $dom->createElement( 'url' );

			$loc = $dom->createElement( 'loc', esc_url( (string) $url['link'] ) );
			$url_element->appendChild( $loc );

			$lastmod = $dom->createElement( 'lastmod', esc_xml( (string) $url['updated'] ) );
			$url_element->appendChild( $lastmod );

			$url_element = apply_filters( 'surerank_sitemap_url_element', $url_element, $url, $dom );

			// Add each image if available. Normalized here as well as at
			// collection time, so rows cached before the normalizer existed
			// (empty locs from data: placeholders, protocol-relative URLs)
			// render valid without waiting for a sitemap rebuild.
			if ( isset( $url['images_data'] ) ) {
				foreach ( $url['images_data'] as $image ) {
					$image_link = self::normalize_image_url( (string) $image['link'] );
					if ( '' === $image_link ) {
						continue;
					}

					$image_element = $dom->createElement( 'image:image' );

					$image_loc = $dom->createElement( 'image:loc', esc_url( $image_link ) );
					$image_element->appendChild( $image_loc );

					$url_element->appendChild( $image_element );
				}
			}

			// Append the <url> element to <urlset>.
			$urlset->appendChild( $url_element );
		}

		// Return the XML as a string or throw an exception if saveXML fails.
		$xml_string = $dom->saveXML();
		if ( false === $xml_string ) {
			throw new RuntimeException( 'Failed to generate main sitemap XML.' );
		}
		return $xml_string;
	}

	/**
	 * Ensure XML/XSL output starts with the declaration at byte 0.
	 *
	 * Closes any active output buffers and strips a leading UTF-8 BOM and
	 * any leading whitespace from the body. Must be called before
	 * {@see self::output_headers()} so headers_sent() reflects the
	 * post-cleanup state.
	 *
	 * @param string $body XML or XSL body to be emitted.
	 * @return string Cleaned body.
	 * @since 1.7.4
	 */
	public static function strip_leading_noise( string $body ): string {
		while ( ob_get_level() > 0 ) {
			if ( ! @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- guard against handlers that refuse to close.
				break;
			}
		}

		if ( substr( $body, 0, 3 ) === "\xEF\xBB\xBF" ) {
			$body = substr( $body, 3 );
		}

		return ltrim( $body );
	}

	/**
	 * Outputs necessary headers for XML display.
	 *
	 * @return void
	 */
	public static function output_headers() {
		if ( headers_sent() ) {
			return;
		}

		$headers = [
			'Pragma'        => 'public',
			'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0',
			'Content-Type'  => 'application/xml; charset=UTF-8',
		];
		foreach ( $headers as $header => $value ) {
			header( "{$header}: {$value}" );
		}
	}

	/**
	 * Creates and initializes a new DOMDocument for XML.
	 *
	 * @return DOMDocument Initialized DOMDocument instance.
	 */
	public static function create_dom(): DOMDocument {
		$doc               = new DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;
		return $doc;
	}

	/**
	 * Creates and appends a root XML element for the sitemap.
	 *
	 * @param string      $element Name of the root element (e.g., 'sitemapindex' or 'urlset').
	 * @param DOMDocument $dom     The DOMDocument instance to append to.
	 * @return DOMElement The created root element.
	 */
	public static function create_urlset( string $element, DOMDocument $dom ): DOMElement {
		$urlset = $dom->createElement( $element );
		$urlset->setAttribute( 'xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
		$dom->appendChild( $urlset );
		return $urlset;
	}

	/**
	 * Adds an XSL stylesheet to the DOM.
	 *
	 * @param DOMDocument $dom The DOMDocument instance to add the stylesheet to.
	 * @param string      $url Path to the XSL stylesheet.
	 * @return DOMProcessingInstruction The created stylesheet instruction.
	 */
	public static function add_stylesheet( DOMDocument $dom, string $url ): DOMProcessingInstruction {
		return $dom->createProcessingInstruction( 'xml-stylesheet', 'type="text/xsl" href="' . esc_url( site_url( '/' ) . $url ) . '"' );
	}

	/**
	 * Retrieves all images associated with a specific post.
	 *
	 * @param int $post_id The ID of the post.
	 * @return array<int, string> Array of image URLs.
	 */
	public static function get_images_from_post( int $post_id ) {
		$images        = [];
		$thumbnail_url = self::get_thumbnail_image( $post_id );

		if ( ! empty( $thumbnail_url ) ) {
			$images[] = $thumbnail_url;
		}

		$images = array_merge( $images, self::get_images_from_post_content( $post_id ) );
		$images = array_merge( $images, self::get_images_from_gallery_shortcode( $post_id ) );

		$attachment_image = self::get_attachment_image( $post_id );
		if ( ! empty( $attachment_image ) ) {
			$images[] = $attachment_image;
		}

		// Content srcs are not always usable sitemap URLs: lazy-load data:
		// placeholders become empty strings through esc_url, and protocol- or
		// root-relative srcs are rejected by Google as invalid URLs.
		$images = array_map( [ self::class, 'normalize_image_url' ], $images );
		$images = array_filter( $images );

		return array_values( array_unique( $images ) );
	}

	/**
	 * Normalize an image URL into a fully qualified URL the sitemap spec accepts.
	 *
	 * Protocol-relative (//host/path) and root-relative (/path) URLs are made
	 * absolute against the site's home URL. Anything that cannot become a valid
	 * http(s) URL (data: placeholders, empty or malformed values) returns ''.
	 *
	 * @param string $url Raw image URL, as found in content or attachments.
	 * @since 1.10.3
	 * @return string Fully qualified URL, or '' when the value is not usable.
	 */
	public static function normalize_image_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$home   = wp_parse_url( home_url() );
		$scheme = is_array( $home ) && ! empty( $home['scheme'] ) ? $home['scheme'] : 'https';

		if ( str_starts_with( $url, '//' ) ) {
			$url = $scheme . ':' . $url;
		} elseif ( str_starts_with( $url, '/' ) ) {
			$host = is_array( $home ) && ! empty( $home['host'] ) ? $home['host'] : '';
			if ( '' === $host ) {
				return '';
			}
			$url = $scheme . '://' . $host . $url;
		}

		if ( ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), [ 'http', 'https' ], true ) ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	/**
	 * Retrieves the post's thumbnail image.
	 *
	 * @param int $post_id Post ID.
	 * @return string Thumbnail image URL or empty string if no thumbnail.
	 */
	public static function get_thumbnail_image( int $post_id ): string {
		$thumbnail_id  = get_post_thumbnail_id( $post_id );
		$thumbnail_url = $thumbnail_id ? wp_get_attachment_url( $thumbnail_id ) : '';
		return $thumbnail_url ? $thumbnail_url : ''; // Ensure it returns a string.
	}

	/**
	 * Extracts images from the post content.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|array<int, string> Array of image URLs.
	 */
	public static function get_images_from_post_content( int $post_id ) {
		$images  = [];
		$content = get_post_field( 'post_content', $post_id );
		if ( ! is_string( $content ) ) {
			return $images;
		}

		preg_match_all( '/<img[^>]+src="([^">]+)"/i', $content, $matches );
		if ( ! empty( $matches[1] ) ) {
			$images = $matches[1];
		}
		return $images;
	}

	/**
	 * Retrieves image URL if post is an image attachment.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Image URL if attachment is an image, null otherwise.
	 */
	public static function get_attachment_image( int $post_id ): ?string {
		if ( 'attachment' === get_post_type( $post_id ) && wp_attachment_is_image( $post_id ) ) {
			$attachment_url = wp_get_attachment_url( $post_id );
			return $attachment_url ? $attachment_url : null; // Ensure it returns string or null.
		}
		return null;
	}

	/**
	 * Extracts images from gallery shortcodes in the post content.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|array<int, string> Array of image URLs from the gallery shortcode.
	 */
	public static function get_images_from_gallery_shortcode( int $post_id ) {
		$images  = [];
		$content = get_post_field( 'post_content', $post_id );
		if ( ! is_string( $content ) ) {
			return $images;
		}

		if ( preg_match_all( '/' . get_shortcode_regex( [ 'gallery' ] ) . '/s', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $shortcode ) {
				if ( 'gallery' === $shortcode[2] ) {
					$attributes = shortcode_parse_atts( $shortcode[3] );
					if ( ! empty( $attributes['ids'] ) ) {
						$ids = explode( ',', $attributes['ids'] );
						foreach ( $ids as $id ) {
							$image_url = wp_get_attachment_url( (int) $id );
							if ( $image_url ) {
								$images[] = $image_url;
							}
						}
					}
				}
			}
		}
		return $images;
	}

	/**
	 * Outputs the stylesheet for the sitemap.
	 *
	 * @param string $stylesheet The stylesheet to output.
	 * @since 1.0.0
	 * @return void
	 */
	public static function output_stylesheet( $stylesheet ) {
		$sitemap_title = esc_html( get_bloginfo( 'name' ) . ' Sitemap' );
		$sitemap_slug  = Xml_Sitemap::get_slug();

		$stylesheet_obj     = new Stylesheet();
		$stylesheet_content = $stylesheet_obj->generate( $sitemap_title, $sitemap_slug );
		$stylesheet_content = apply_filters( 'surerank_sitemap_output_stylesheet', $stylesheet_content, $stylesheet );
		$stylesheet_content = self::strip_leading_noise( (string) $stylesheet_content );

		self::output_headers();

		echo $stylesheet_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Get noindex settings.
	 *
	 * @return array<string, mixed>|array<int, string>
	 */
	public static function get_noindex_settings() {
		$settings = Get::option( SURERANK_SETTINGS );
		return $settings['no_index'] ?? [];
	}

	/**
	 * Get meta query for indexable content based on no_index settings
	 *
	 * @param string $content_type The post type or taxonomy name.
	 * @return array<int|string, mixed> The meta query array.
	 */
	public static function get_indexable_meta_query( string $content_type ): array {
		$no_index_settings = self::get_noindex_settings();

		if ( in_array( $content_type, $no_index_settings, true ) ) {
			return [
				[
					'key'     => 'surerank_settings_post_no_index',
					'value'   => 'no',
					'compare' => '=',
				],
			];
		}

		return [
			'relation' => 'OR',
			[
				'key'     => 'surerank_settings_post_no_index',
				'value'   => 'yes',
				'compare' => '!=',
			],
			[
				'key'     => 'surerank_settings_post_no_index',
				'compare' => 'NOT EXISTS',
			],
		];
	}

	/**
	 * Get the post IDs excluded from the sitemap, ready for post__not_in.
	 *
	 * Used by both the chunk builder and the index count query so the index
	 * never advertises a page the chunks do not fill.
	 *
	 * @since 1.10.0
	 * @return array<int> Post IDs to exclude.
	 */
	public static function get_excluded_post_ids(): array {
		/**
		 * Filter the post IDs excluded from the sitemap.
		 *
		 * Applies to every sitemap query, so it covers the cron pre-build and
		 * the on-the-fly mode alike, and the HTML sitemap inherits it too.
		 *
		 * The rebuild checksum is driven by post-save events, not by this
		 * filter's output, so changing it leaves an already warm cache stale
		 * and `wp surerank generate_cache` reports matching checksums. Run
		 * `wp surerank generate_cache --force` after changing it.
		 *
		 * @param array<int>|string $ids Post IDs to exclude. A comma-separated string is also accepted.
		 * @since 1.2.0
		 */
		return wp_parse_id_list( apply_filters( 'surerank_exclude_posts_from_sitemap', [] ) );
	}

}
