<?php
/**
 * Headless REST API.
 *
 * Public, opt-in endpoint that exposes SureRank SEO metadata and schema for a
 * given public URL, for use with headless WordPress front-ends. The output is
 * produced by re-using the existing front-end print pipeline against a
 * simulated main query, so it stays byte-for-byte identical to what wp_head
 * would emit for the same URL.
 *
 * @package surerank
 * @since 1.9.0
 */

namespace SureRank\Inc\API;

use SureRank\Inc\Frontend\Meta_Data;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Traits\Get_Instance;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Headless
 *
 * @since 1.9.0
 */
class Headless extends Api_Base {

	use Get_Instance;

	/**
	 * Per-resource cache meta key (postmeta / termmeta).
	 */
	public const CACHE_KEY = '_surerank_rest_cache';

	/**
	 * Cache option for the latest-posts homepage, which has no post or term
	 * to attach meta to.
	 */
	public const HOME_CACHE_OPTION = 'surerank_rest_cache_home';

	/**
	 * Route: GET /surerank/v1/get_head
	 */
	protected const GET_HEAD = '/get_head';

	/**
	 * Per-resource SEO meta prefix written by the SureRank meta box / bulk
	 * edit / importers. A write to any key under this prefix invalidates the
	 * resource's cached head.
	 */
	private const SEO_META_PREFIX = 'surerank_settings_';

	/**
	 * Constructor.
	 *
	 * Registers the controller with the REST controller list and wires the
	 * per-resource cache invalidation hooks. Loaded as a core component, so
	 * the invalidation hooks are present on every request (not just REST).
	 *
	 * @since 1.9.0
	 */
	public function __construct() {
		add_filter( 'surerank_api_controllers', [ $this, 'register_controller' ] );
		add_filter( 'surerank_role_manager_option_mappings', [ $this, 'register_role_manager_mapping' ] );
		add_action( 'save_post', [ $this, 'invalidate_post_cache' ] );
		add_action( 'edited_term', [ $this, 'invalidate_term_cache' ] );

		// The SureRank meta box (and bulk edit, importers, sync, CLI) writes
		// surerank_settings_* meta directly without firing save_post /
		// edited_term, so invalidate on the meta writes themselves.
		add_action( 'added_post_meta', [ $this, 'maybe_invalidate_post_meta_cache' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'maybe_invalidate_post_meta_cache' ], 10, 3 );
		add_action( 'deleted_post_meta', [ $this, 'maybe_invalidate_post_meta_cache' ], 10, 3 );
		add_action( 'added_term_meta', [ $this, 'maybe_invalidate_term_meta_cache' ], 10, 3 );
		add_action( 'updated_term_meta', [ $this, 'maybe_invalidate_term_meta_cache' ], 10, 3 );
		add_action( 'deleted_term_meta', [ $this, 'maybe_invalidate_term_meta_cache' ], 10, 3 );
	}

	/**
	 * Allow the headless toggle through the SureRank Pro role-manager allowlist.
	 *
	 * The Pro role-manager filters global settings down to a per-capability
	 * allowlist on both save and read, dropping any unmapped key. Register the
	 * toggle under the global-settings capability so it persists. No-op when
	 * Pro (and therefore the filter) is not present.
	 *
	 * @param array<string, array<int, string>> $mappings Capability => option keys.
	 * @return array<string, array<int, string>>
	 * @since 1.9.0
	 */
	public function register_role_manager_mapping( $mappings ) {
		if ( isset( $mappings['surerank_global_setting'] ) ) {
			$mappings['surerank_global_setting'][] = 'enable_headless_rest_api';
		}

		return $mappings;
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
			self::GET_HEAD,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_head' ],
				'permission_callback' => [ $this, 'public_permission' ],
				'args'                => [
					'url' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && '' !== trim( $value );
						},
					],
				],
			]
		);
	}

	/**
	 * Public permission gate.
	 *
	 * The endpoint is intentionally public, but only when the site owner has
	 * enabled it. This never falls back to a capability check, so it does not
	 * expose anything an anonymous front-end visitor could not already see.
	 *
	 * @return bool|WP_Error True when enabled, WP_Error (403) otherwise.
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
	 * GET /get_head callback.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|WP_Error
	 * @since 1.9.0
	 */
	public function get_head( $request ) {
		$url = (string) $request->get_param( 'url' );

		$resolved = $this->resolve_url( $url );
		if ( null === $resolved ) {
			return $this->not_found();
		}

		if ( ! $this->is_publicly_viewable( $resolved ) ) {
			return $this->not_found();
		}

		$data = $this->get_data_for_object( $resolved );
		if ( null === $data ) {
			return $this->not_found();
		}

		return $this->respond( $data, $resolved );
	}

	/**
	 * Cached head + json for a resolved object: serve from cache, else render
	 * and cache. Shared by the get_head endpoint and the register_rest_field
	 * fields (Rest_Fields) so both produce identical output and share one cache.
	 *
	 * Callers are responsible for the visibility gate (is_publicly_viewable);
	 * noindex content is still rendered here, exactly as wp_head emits it on the
	 * public front-end (with its robots:noindex directive in the head/json).
	 *
	 * @param array{id:int,type:string,taxonomy?:string} $resolved Resolved object.
	 * @return array<string, mixed>|null Head + json, or null when render fails.
	 * @since 1.9.0
	 */
	public function get_data_for_object( array $resolved ) {
		$cached = $this->get_cache( $resolved );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = $this->render_for_object( $resolved );
		if ( null === $data ) {
			return null;
		}

		$this->set_cache( $resolved, $data );

		return $data;
	}

	/**
	 * Invalidate the cached head for a post on save.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 * @since 1.9.0
	 */
	public function invalidate_post_cache( $post_id ) {
		delete_post_meta( (int) $post_id, self::CACHE_KEY );
	}

	/**
	 * Invalidate the cached head for a term on edit.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 * @since 1.9.0
	 */
	public function invalidate_term_cache( $term_id ) {
		delete_term_meta( (int) $term_id, self::CACHE_KEY );
	}

	/**
	 * Invalidate the cached head when a SureRank SEO meta key is written on a
	 * post. Covers the meta box save path (own REST endpoint, no save_post).
	 *
	 * @param int|array<int> $meta_id   Meta ID(s) — unused.
	 * @param int            $object_id Post ID.
	 * @param string         $meta_key  Meta key being written.
	 * @return void
	 * @since 1.9.0
	 */
	public function maybe_invalidate_post_meta_cache( $meta_id, $object_id, $meta_key ) {
		if ( $this->is_seo_meta_key( $meta_key ) ) {
			$this->invalidate_post_cache( (int) $object_id );
		}
	}

	/**
	 * Invalidate the cached head when a SureRank SEO meta key is written on a
	 * term. Covers the term SEO popup save path (own REST endpoint, no
	 * edited_term).
	 *
	 * @param int|array<int> $meta_id   Meta ID(s) — unused.
	 * @param int            $object_id Term ID.
	 * @param string         $meta_key  Meta key being written.
	 * @return void
	 * @since 1.9.0
	 */
	public function maybe_invalidate_term_meta_cache( $meta_id, $object_id, $meta_key ) {
		if ( $this->is_seo_meta_key( $meta_key ) ) {
			$this->invalidate_term_cache( (int) $object_id );
		}
	}

	/**
	 * Whether the resolved object may be exposed publicly.
	 *
	 * @param array{id:int,type:string,taxonomy?:string} $resolved Resolved object.
	 * @return bool
	 * @since 1.9.0
	 */
	public function is_publicly_viewable( array $resolved ) {
		// The latest-posts homepage is always public.
		if ( 'home' === $resolved['type'] ) {
			return true;
		}

		if ( 'term' === $resolved['type'] ) {
			$term = get_term( $resolved['id'] );
			return $term instanceof \WP_Term && is_taxonomy_viewable( $term->taxonomy );
		}

		$post = get_post( $resolved['id'] );
		if ( ! $post ) {
			return false;
		}

		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		if ( '' !== $post->post_password ) {
			return false;
		}

		return is_post_type_viewable( $post->post_type );
	}

	/**
	 * Whether a meta key holds SureRank per-resource SEO settings.
	 *
	 * @param mixed $meta_key Meta key from the meta hooks.
	 * @return bool
	 * @since 1.9.2
	 */
	private function is_seo_meta_key( $meta_key ) {
		return is_string( $meta_key ) && 0 === strpos( $meta_key, self::SEO_META_PREFIX );
	}

	/**
	 * Apply output filters and build the REST response.
	 *
	 * @param array<string, mixed>                       $data     Assembled head + json.
	 * @param array{id:int,type:string,taxonomy?:string} $resolved Resolved object.
	 * @return WP_REST_Response
	 * @since 1.9.0
	 */
	private function respond( array $data, array $resolved ) {
		$json = apply_filters( 'surerank_rest_head_data', $data['json'], $resolved['id'], $resolved['type'] );
		$head = apply_filters( 'surerank_rest_head_html', $data['head'], $resolved['id'], $resolved['type'] );

		return new WP_REST_Response(
			[
				'success' => true,
				'head'    => $head,
				'json'    => $json,
			],
			200
		);
	}

	/**
	 * Standard 404 error.
	 *
	 * @return WP_Error
	 * @since 1.9.0
	 */
	private function not_found() {
		return new WP_Error(
			'surerank_headless_not_found',
			__( 'No public SEO data found for the requested URL.', 'surerank' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Resolve a URL to a local post or term.
	 *
	 * @param string $url Requested URL.
	 * @return array{id:int,type:string,taxonomy?:string}|null
	 * @since 1.9.0
	 */
	private function resolve_url( $url ) {
		// Reject anything that is not on this site.
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		if ( $url_host && $home_host && strtolower( $url_host ) !== strtolower( $home_host ) ) {
			return null;
		}

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$vars  = [];
		if ( '' !== $query ) {
			parse_str( $query, $vars );
		}

		// 0. Site homepage (see resolve_home).
		$home = $this->resolve_home( $url, $query );
		if ( null !== $home ) {
			return $home;
		}

		// 1. Explicit post query (?p= / ?page_id=). Scalar-only: array-style
		// vars (?p[]=1) skip this branch and fall through to the front-page
		// guard below, which rejects the false url_to_postid() match.
		if ( ( ! empty( $vars['p'] ) && is_scalar( $vars['p'] ) )
			|| ( ! empty( $vars['page_id'] ) && is_scalar( $vars['page_id'] ) ) ) {
			$post_id = url_to_postid( $url ); // @phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid
			return $post_id ? [
				'id'   => (int) $post_id,
				'type' => 'post',
			] : null;
		}

		// 2. Query-string term archives (?cat=ID, ?tag=slug, ?taxonomy=..&term=..).
		// Resolved BEFORE url_to_postid because url_to_postid() maps a root path
		// with any query to the front page, mis-resolving these archive URLs.
		$from_query = $this->resolve_term_from_query( $vars );
		if ( null !== $from_query ) {
			return $from_query;
		}

		// 3. Pretty post/page URL.
		$post_id = url_to_postid( $url ); // @phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid
		if ( $post_id ) {
			// Guard the front-page false match: url_to_postid() returns the
			// static front page for any "/?<archive query>" URL. The real front
			// page carries no query string, so reject that combination.
			$front_id = (int) get_option( 'page_on_front' );
			if ( $front_id && $post_id === $front_id && '' !== $query ) {
				return null;
			}

			return [
				'id'   => (int) $post_id,
				'type' => 'post',
			];
		}

		// 4. Pretty term path: match the final path segment as a term slug, then
		// confirm the full permalink matches (validates hierarchical paths too).
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$slug = sanitize_title( basename( untrailingslashit( $path ) ) );
		if ( '' === $slug ) {
			return null;
		}

		foreach ( get_taxonomies( [ 'public' => true ], 'names' ) as $taxonomy ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$link = get_term_link( $term );
			if ( is_wp_error( $link ) || ! $this->same_path( $link, $url ) ) {
				continue;
			}

			return [
				'id'       => (int) $term->term_id,
				'type'     => 'term',
				'taxonomy' => $taxonomy,
			];
		}

		return null;
	}

	/**
	 * Resolve the site homepage.
	 *
	 * WordPress's url_to_postid() resolves the home URL only when a static
	 * front page is configured; with "latest posts" it returns 0 and the
	 * homepage would 404. Resolve both front-page modes here.
	 *
	 * @param string $url   Requested URL.
	 * @param string $query Query-string portion of the URL ('' when none).
	 * @return array{id:int,type:string}|null Resolved object, or null when
	 *                                        the URL is not the homepage.
	 * @since 1.9.0
	 */
	private function resolve_home( $url, $query ) {
		if ( '' !== $query || ! $this->same_path( home_url( '/' ), $url ) ) {
			return null;
		}

		$front_id = (int) get_option( 'page_on_front' );
		if ( 'page' === get_option( 'show_on_front' ) && $front_id ) {
			return [
				'id'   => $front_id,
				'type' => 'post',
			];
		}

		return [
			'id'   => 0,
			'type' => 'home',
		];
	}

	/**
	 * Resolve a term from parsed query vars (?cat=, ?tag=, ?taxonomy=&term=).
	 *
	 * Only public (viewable) taxonomies are resolved, so non-public taxonomy
	 * archives (e.g. nav_menu) cannot be exposed.
	 *
	 * @param array<int|string, mixed> $vars Parsed query variables.
	 * @return array{id:int,type:string,taxonomy:string}|null
	 * @since 1.9.0
	 */
	private function resolve_term_from_query( $vars ) {
		// parse_str() honors PHP array syntax (?tag[]=x), so any var may be an
		// array. Arrays are never valid archive queries — guard each branch so
		// they fall through to a 404 instead of fataling in sanitize_title().
		$term = null;
		if ( ! empty( $vars['cat'] ) && is_scalar( $vars['cat'] ) ) {
			$term = get_term( (int) $vars['cat'], 'category' );
		} elseif ( ! empty( $vars['tag'] ) && is_string( $vars['tag'] ) ) {
			$term = get_term_by( 'slug', sanitize_title( $vars['tag'] ), 'post_tag' );
		} elseif (
			! empty( $vars['taxonomy'] ) && is_string( $vars['taxonomy'] )
			&& ! empty( $vars['term'] ) && is_string( $vars['term'] )
			&& taxonomy_exists( $vars['taxonomy'] )
		) {
			$term = get_term_by( 'slug', sanitize_title( $vars['term'] ), $vars['taxonomy'] );
		}

		if ( $term && ! is_wp_error( $term ) && is_taxonomy_viewable( $term->taxonomy ) ) {
			return [
				'id'       => (int) $term->term_id,
				'type'     => 'term',
				'taxonomy' => $term->taxonomy,
			];
		}

		return null;
	}

	/**
	 * Compare two URLs by path (ignoring scheme/host/trailing slash).
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 * @return bool
	 * @since 1.9.0
	 */
	private function same_path( $a, $b ) {
		$pa = untrailingslashit( (string) wp_parse_url( $a, PHP_URL_PATH ) );
		$pb = untrailingslashit( (string) wp_parse_url( $b, PHP_URL_PATH ) );
		return $pa === $pb;
	}

	/**
	 * Render the head markup + parsed JSON for the resolved object by
	 * simulating the main query and re-using the front-end print pipeline.
	 *
	 * @param array{id:int,type:string,taxonomy?:string} $resolved Resolved object.
	 * @return array<string, mixed>|null
	 * @since 1.9.0
	 */
	private function render_for_object( array $resolved ) {
		global $wp_query, $post, $wp;

		$original_query   = $wp_query;
		$original_post    = $post;
		$original_request = $wp->request ?? null;

		$simulated = $this->build_query( $resolved );
		if ( null === $simulated ) {
			return null;
		}

		// Swap in the simulated main query so is_singular()/is_tax()/
		// get_queried_object() resolve to the requested object.
		$wp_query = $simulated; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// Point $wp->request at the object's path so schema placeholders that
		// derive the "current URL" (home_url( $wp->request )) match the
		// front-end render instead of the REST route.
		if ( $wp instanceof \WP ) {
			if ( 'home' === $resolved['type'] ) {
				$link = home_url( '/' );
			} elseif ( 'term' === $resolved['type'] ) {
				$link = get_term_link( $resolved['id'] );
			} else {
				$link = get_permalink( $resolved['id'] );
			}
			$wp->request = is_string( $link ) ? trim( (string) wp_parse_url( $link, PHP_URL_PATH ), '/' ) : '';
		}

		if ( 'post' === $resolved['type'] && $simulated->have_posts() ) {
			$simulated->the_post();
		}

		/**
		 * Reset the per-request caches of the surerank_set_meta providers, so
		 * a second render in the same request (REST collections, repeated
		 * calls after invalidation) doesn't reuse stale data. Providers that
		 * cache register via Reset_Meta_Data::register_meta_reset().
		 *
		 * @since 1.9.0
		 */
		do_action( 'surerank_reset_frontend_meta' );

		Meta_Data::get_instance()->reset_meta_data();
		Meta_Data::get_instance()->set_meta_data();

		ob_start();
		// Schemas::print_schema_data() is hooked to surerank_print_meta, so a
		// single buffer captures both the meta tags and the JSON-LD script.
		Meta_Data::get_instance()->print_meta_data();
		$markup = (string) ob_get_clean();

		$title = wp_get_document_title();

		// Restore the original main query state.
		$wp_query = $original_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post     = $original_post;  // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $wp instanceof \WP ) {
			$wp->request = $original_request;
		}
		wp_reset_postdata();

		$json          = $this->parse_markup( $markup );
		$json['title'] = $title;

		$head = '<title>' . esc_html( $title ) . '</title>' . $markup;

		return [
			'head' => $head,
			'json' => $json,
		];
	}

	/**
	 * Build a fresh WP_Query for the resolved object with the correct
	 * conditional flags and queried object.
	 *
	 * @param array{id:int,type:string,taxonomy?:string} $resolved Resolved object.
	 * @return WP_Query|null
	 * @since 1.9.0
	 */
	private function build_query( array $resolved ) {
		if ( 'home' === $resolved['type'] ) {
			// With no specific query vars, parse_query() flags is_home; under
			// show_on_front = posts, is_front_page() is true as well — the
			// same conditional state the front-end homepage render sees.
			return new WP_Query(
				[
					'posts_per_page'      => 1,
					'ignore_sticky_posts' => true,
				]
			);
		}

		if ( 'term' === $resolved['type'] ) {
			$term = get_term( $resolved['id'] );
			if ( ! $term || is_wp_error( $term ) ) {
				return null;
			}

			return new WP_Query(
				[
					'taxonomy'            => $resolved['taxonomy'] ?? $term->taxonomy,
					'term'                => $term->slug,
					'posts_per_page'      => 1,
					'ignore_sticky_posts' => true,
				]
			);
		}

		$object = get_post( $resolved['id'] );
		if ( ! $object ) {
			return null;
		}

		$args = 'page' === $object->post_type
			? [ 'page_id' => $resolved['id'] ]
			: [
				'p'         => $resolved['id'],
				'post_type' => $object->post_type,
			];

		return new WP_Query( $args );
	}

	/**
	 * Parse the rendered head markup into a structured JSON view.
	 *
	 * @param string $markup Rendered head markup.
	 * @return array<string, mixed>
	 * @since 1.9.0
	 */
	private function parse_markup( $markup ) {
		$json = [
			'title'       => '',
			'description' => '',
			'canonical'   => '',
			'robots'      => '',
			'og'          => [],
			'twitter'     => [],
			'schema'      => [],
		];

		if ( preg_match( '/<meta name="description" content="([^"]*)"/', $markup, $m ) ) {
			$json['description'] = $this->decode( $m[1] );
		}

		if ( preg_match( '/<link rel="canonical" href="([^"]*)"/', $markup, $m ) ) {
			$json['canonical'] = $this->decode( $m[1] );
		}

		if ( preg_match( '/<meta name="robots" content="([^"]*)"/', $markup, $m ) ) {
			$json['robots'] = $this->decode( $m[1] );
		}

		if ( preg_match_all( '/<meta property="(og:[^"]+)" content="([^"]*)"/', $markup, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$json['og'][ $match[1] ] = $this->decode( $match[2] );
			}
		}

		if ( preg_match_all( '/<meta name="(twitter:[^"]+)" content="([^"]*)"/', $markup, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$json['twitter'][ $match[1] ] = $this->decode( $match[2] );
			}
		}

		if ( preg_match( '/<script type="application\/ld\+json" id="surerank-schema">(.*?)<\/script>/s', $markup, $m ) ) {
			$decoded = json_decode( $m[1], true );
			if ( is_array( $decoded ) ) {
				$json['schema'] = $decoded['@graph'] ?? $decoded;
			}
		}

		return $json;
	}

	/**
	 * Decode HTML entities produced by esc_attr() for the JSON view.
	 *
	 * @param string $value Encoded value.
	 * @return string
	 * @since 1.9.0
	 */
	private function decode( $value ) {
		return html_entity_decode( $value, ENT_QUOTES, get_bloginfo( 'charset' ) );
	}

	/**
	 * Read the cached head data for the resolved object.
	 *
	 * @param array{id:int,type:string,taxonomy?:string} $resolved Resolved object.
	 * @return array<string, mixed>|null
	 * @since 1.9.0
	 */
	private function get_cache( array $resolved ) {
		if ( 'home' === $resolved['type'] ) {
			$value = get_option( self::HOME_CACHE_OPTION );
		} elseif ( 'term' === $resolved['type'] ) {
			$value = get_term_meta( $resolved['id'], self::CACHE_KEY, true );
		} else {
			$value = get_post_meta( $resolved['id'], self::CACHE_KEY, true );
		}

		if ( ! is_array( $value ) || ! isset( $value['head'], $value['json'] ) ) {
			return null;
		}

		// Invalidate when global SEO config changed since this entry was cached.
		// Per-post and per-term edits are handled by save_post and edited_term.
		if ( (int) ( $value['_ver'] ?? -1 ) !== $this->cache_version() ) {
			return null;
		}

		return $value;
	}

	/**
	 * Current global cache version — the last-updated timestamp of SureRank's
	 * global SEO settings. Bumped whenever global settings (or core site
	 * identity options) change, so all cached heads invalidate together.
	 *
	 * @return int
	 * @since 1.9.0
	 */
	private function cache_version() {
		return (int) get_option( SURERANK_SEO_LAST_UPDATED, 0 );
	}

	/**
	 * Store the head data for the resolved object.
	 *
	 * @param array{id:int,type:string,taxonomy?:string} $resolved Resolved object.
	 * @param array<string, mixed>                       $data     Assembled head + json.
	 * @return void
	 * @since 1.9.0
	 */
	private function set_cache( array $resolved, array $data ) {
		$data['_ver'] = $this->cache_version();

		if ( 'home' === $resolved['type'] ) {
			// Stale entries are ignored via the _ver check; global SEO and
			// site-identity changes bump SURERANK_SEO_LAST_UPDATED.
			update_option( self::HOME_CACHE_OPTION, $data, false );
			return;
		}

		if ( 'term' === $resolved['type'] ) {
			update_term_meta( $resolved['id'], self::CACHE_KEY, $data );
			return;
		}

		update_post_meta( $resolved['id'], self::CACHE_KEY, $data );
	}
}
