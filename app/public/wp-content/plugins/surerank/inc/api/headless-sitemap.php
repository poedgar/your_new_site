<?php
/**
 * Headless JSON sitemap.
 *
 * Public, opt-in JSON variant of the XML sitemap for headless consumers:
 *   GET /surerank/v1/sitemap            — index of sub-sitemaps
 *   GET /surerank/v1/sitemap/{slug}     — entries for one sub-sitemap page
 *
 * Reads the exact same cached chunks the XML sitemap renders (via
 * Xml_Sitemap::get_entries_for_base), so the JSON output is in parity with the
 * XML sitemap: same items, same noindex/exclusion filtering applied at build
 * time. Gated by the headless toggle (enable_headless_rest_api, default off)
 * and backed by the XML sitemap feature (enable_xml_sitemap) as its data source.
 *
 * @package surerank
 * @since 1.9.0
 */

namespace SureRank\Inc\API;

use SureRank\Inc\Functions\Cache;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Sitemap\Generation_Mode;
use SureRank\Inc\Sitemap\Providers\Registry;
use SureRank\Inc\Sitemap\Xml_Sitemap;
use SureRank\Inc\Traits\Get_Instance;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Headless_Sitemap
 *
 * @since 1.9.0
 */
class Headless_Sitemap extends Api_Base {

	use Get_Instance;

	/**
	 * Unified sitemap index cache file (relative to the cache root).
	 */
	private const INDEX_FILE = 'sitemap/sitemap_index.json';

	/**
	 * Constructor. Self-registers with the SureRank REST controller list.
	 *
	 * @since 1.9.0
	 */
	public function __construct() {
		add_filter( 'surerank_api_controllers', [ $this, 'register_controller' ] );
	}

	/**
	 * Add this controller to the SureRank REST controller list.
	 *
	 * @param array<int, string> $controllers List of API controllers.
	 * @return array<int, string>
	 * @since 1.9.0
	 */
	public function register_controller( $controllers ) {
		$controllers[] = self::class;
		return $controllers;
	}

	/**
	 * Register API routes.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->get_api_namespace(),
			'/sitemap',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_index' ],
				'permission_callback' => [ $this, 'public_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			'/sitemap/(?P<slug>[a-z0-9_-]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_sub_sitemap' ],
				'permission_callback' => [ $this, 'public_permission' ],
				'args'                => [
					'slug' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);
	}

	/**
	 * Public permission gate.
	 *
	 * Public only when the site owner has enabled the headless API. Never falls
	 * back to a capability check — exposes only what the XML sitemap already
	 * serves publicly.
	 *
	 * @return bool|WP_Error
	 * @since 1.9.0
	 */
	public function public_permission() {
		if ( Settings::get( 'enable_headless_rest_api' ) ) {
			return true;
		}

		return new WP_Error(
			'surerank_headless_disabled',
			__( 'The Headless REST API is disabled.', 'surerank' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * GET /sitemap — list the sub-sitemaps from the unified index.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.9.0
	 */
	public function get_index() {
		$disabled = $this->sitemap_disabled();
		if ( $disabled instanceof WP_Error ) {
			return $disabled;
		}

		$index = $this->read_json( self::INDEX_FILE );
		if ( ! is_array( $index ) ) {
			// Auto mode: synthesize the index on the fly, matching the XML path.
			if ( Generation_Mode::allows_inline_build() ) {
				Registry::get_instance()->build_index();
				$index = $this->read_json( self::INDEX_FILE );
			}
			if ( ! is_array( $index ) ) {
				return $this->not_built();
			}
		}

		$sitemaps = [];
		foreach ( $index as $entry ) {
			if ( empty( $entry['link'] ) ) {
				continue;
			}

			$slug = $this->slug_from_xml_url( (string) $entry['link'] );
			if ( '' === $slug ) {
				continue;
			}

			$sitemaps[] = [
				'slug'    => $slug,
				'url'     => rest_url( $this->get_api_namespace() . '/sitemap/' . $slug ),
				'source'  => (string) $entry['link'],
				'lastmod' => $entry['updated'] ?? null,
			];
		}

		return new WP_REST_Response(
			[
				'success'  => true,
				'sitemaps' => $sitemaps,
			],
			200
		);
	}

	/**
	 * GET /sitemap/{slug} — entries for one sub-sitemap page.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|WP_Error
	 * @since 1.9.0
	 */
	public function get_sub_sitemap( $request ) {
		$disabled = $this->sitemap_disabled();
		if ( $disabled instanceof WP_Error ) {
			return $disabled;
		}

		$slug   = (string) $request->get_param( 'slug' );
		$parsed = $this->parse_slug( $slug );
		if ( null === $parsed ) {
			return $this->not_found();
		}

		$rows    = Xml_Sitemap::get_instance()->get_entries_for_base( $parsed['base'], $parsed['page'] );
		$entries = [];
		foreach ( $rows as $row ) {
			if ( empty( $row['link'] ) ) {
				continue;
			}

			$entry = [
				'loc'     => (string) $row['link'],
				'lastmod' => $row['updated'] ?? null,
			];

			if ( ! empty( $row['images_data'] ) && is_array( $row['images_data'] ) ) {
				$entry['images'] = array_values(
					array_filter(
						array_map(
							static function ( $image ) {
								return isset( $image['link'] ) ? (string) $image['link'] : null;
							},
							$row['images_data']
						)
					)
				);
			}

			$entries[] = $entry;
		}

		if ( empty( $entries ) ) {
			return $this->not_found();
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'slug'    => $slug,
				'entries' => $entries,
			],
			200
		);
	}

	/**
	 * Guard: the JSON sitemap is backed by the XML sitemap cache, so it is only
	 * available when the XML sitemap feature is enabled.
	 *
	 * @return WP_Error|null WP_Error when disabled, null when available.
	 * @since 1.9.0
	 */
	private function sitemap_disabled() {
		if ( Settings::get( 'enable_xml_sitemap' ) ) {
			return null;
		}

		return new WP_Error(
			'surerank_sitemap_disabled',
			__( 'The XML sitemap is disabled, so the JSON sitemap is unavailable.', 'surerank' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Standard 404 for an unknown sub-sitemap.
	 *
	 * @return WP_Error
	 * @since 1.9.0
	 */
	private function not_found() {
		return new WP_Error(
			'surerank_sitemap_not_found',
			__( 'No sitemap found for the requested slug.', 'surerank' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * 503 when the sitemap cache has not been built yet.
	 *
	 * @return WP_Error
	 * @since 1.9.0
	 */
	private function not_built() {
		return new WP_Error(
			'surerank_sitemap_not_built',
			__( 'The sitemap has not been generated yet. Try again shortly.', 'surerank' ),
			[ 'status' => 503 ]
		);
	}

	/**
	 * Read and decode a cached JSON file.
	 *
	 * @param string $path Cache-relative path.
	 * @return mixed Decoded value, or null on miss/invalid.
	 * @since 1.9.0
	 */
	private function read_json( string $path ) {
		$raw = Cache::get_file( $path );
		if ( ! $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Derive a sub-sitemap slug from its XML URL.
	 *
	 * Example: http://site/post-type-page-sitemap-1.xml maps to the slug
	 * post-type-page-sitemap-1.
	 *
	 * @param string $url Sub-sitemap XML URL.
	 * @return string Slug, or empty string when it does not match.
	 * @since 1.9.0
	 */
	private function slug_from_xml_url( string $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$file = basename( $path );
		if ( '.xml' !== substr( $file, -4 ) ) {
			return '';
		}

		$slug = substr( $file, 0, -4 );
		return preg_match( '/^[a-z0-9_-]+-sitemap-\d+$/', $slug ) ? $slug : '';
	}

	/**
	 * Parse a sub-sitemap slug into its chunk base + page.
	 *
	 * The slug is "{base}-sitemap-{page}"; the trailing "-sitemap-{digits}" is
	 * stripped to recover the chunk-file base shared by "{base}-chunk-{n}.json".
	 *
	 * @param string $slug Sub-sitemap slug.
	 * @return array{base:string,page:int}|null
	 * @since 1.9.0
	 */
	private function parse_slug( string $slug ) {
		if ( ! preg_match( '/^([a-z0-9_-]+)-sitemap-(\d+)$/', $slug, $m ) ) {
			return null;
		}

		return [
			'base' => $m[1],
			'page' => max( 1, (int) $m[2] ),
		];
	}
}
