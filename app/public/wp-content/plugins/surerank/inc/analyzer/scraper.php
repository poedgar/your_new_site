<?php
/**
 * Scraper class.
 *
 * Handles HTTP requests for SEO analysis.
 *
 * @package SureRank\Inc\Analyzer
 */

namespace SureRank\Inc\Analyzer;

use SureRank\Inc\Functions\Requests;
use SureRank\Inc\Traits\Get_Instance;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Scraper
 *
 * Fetches HTML content from URLs.
 */
class Scraper {
	use Get_Instance;

	/**
	 * Last HTTP response.
	 *
	 * @var array<string,mixed>|WP_Error|null
	 */
	private $last_response = null;

	/**
	 * Last response body.
	 *
	 * @var string|WP_Error|null
	 */
	private $last_body = null;

	/**
	 * Last response code.
	 *
	 * @var int|null
	 */
	private $response_code = null;

	/**
	 * Fetch HTML content from a URL.
	 *
	 * @param string $url The URL to scrape.
	 * @return string|WP_Error HTML content or error on failure.
	 */
	public function fetch( string $url ) {
		$this->last_response = $this->call_request( $url );
		if ( is_wp_error( $this->last_response ) ) {
			return $this->last_response;
		}

		// An error page parses as valid HTML with no meta tags in it, which would otherwise be
		// reported as "tag missing" rather than as a failure to read the page at all.
		$this->response_code = (int) wp_remote_retrieve_response_code( $this->last_response );
		if ( $this->response_code >= 400 ) {
			return new WP_Error(
				'unexpected_status',
				sprintf(
					/* translators: %d: HTTP status code returned by the page. */
					__( 'The page could not be read. It returned HTTP status %d.', 'surerank' ),
					$this->response_code
				)
			);
		}

		$this->last_body = wp_remote_retrieve_body( $this->last_response );
		if ( empty( $this->last_body ) ) {
			$this->last_body = new WP_Error(
				'empty_response',
				__( 'Empty response from URL.', 'surerank' )
			);
		}

		return $this->last_body;
	}

	/**
	 * Get a specific header from the last HTTP response.
	 *
	 * @param string $header The header name.
	 * @return string|WP_Error Header value or WP_Error if unavailable.
	 */
	public function get_header( string $header ) {
		if ( ! $this->last_response || is_wp_error( $this->last_response ) ) {
			return new WP_Error(
				'no_response',
				__( 'No response available to retrieve header.', 'surerank' )
			);
		}

		$value = wp_remote_retrieve_header( $this->last_response, $header );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Get the body from the last HTTP response.
	 *
	 * @return string|WP_Error Response body or WP_Error if unavailable.
	 */
	public function get_body() {
		if ( ! $this->last_body ) {
			return new WP_Error(
				'no_body',
				__( 'No response body available.', 'surerank' )
			);
		}

		return $this->last_body;
	}

	/**
	 * Make HTTP request to URL.
	 *
	 * @param string $url The URL to request.
	 * @return array<string,mixed>|WP_Error HTTP response or WP_Error on failure.
	 */
	public function call_request( string $url ) {
		// The filter result is the header list, not the whole request args, so that a value
		// returned by it actually reaches the request.
		$args = [
			'headers' => (array) apply_filters( 'surerank_scraper_headers', [] ),
		];

		return Requests::get( $url, $args );
	}

	/**
	 * Fetch HTML content from a URL, bypassing any shared cache in front of it.
	 *
	 * Audits read the URL as the public sees it, cache and all. This is for telling a stale
	 * cached copy apart from markup the site genuinely is not producing. Request headers do not
	 * defeat every edge cache, so the cache key is varied instead.
	 *
	 * @param string $url The URL to scrape.
	 * @return string|WP_Error HTML content or error on failure.
	 * @since 1.10.1
	 */
	public function fetch_from_origin( string $url ) {
		// Deliberately does not store the response: callers read headers off the audited request,
		// and this is a side probe that must not become "the last response".
		$response = $this->call_request(
			add_query_arg( 'surerank_check', (int) floor( time() / MINUTE_IN_SECONDS ), $url )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new WP_Error(
				'unexpected_status',
				__( 'The page could not be read.', 'surerank' )
			);
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Fetch HTTP status code from a URL.
	 *
	 * @param string $url The URL to check status for.
	 * @return int|WP_Error Status code or WP_Error on failure.
	 */
	public function fetch_status( string $url ) {
		$this->last_response = $this->call_request( $url );
		if ( is_wp_error( $this->last_response ) ) {
			return new WP_Error(
				'no_response',
				__( 'No response available to retrieve status.', 'surerank' )
			);
		}
		$this->response_code = (int) wp_remote_retrieve_response_code( $this->last_response );
		return $this->response_code;
	}
}
