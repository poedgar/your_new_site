<?php
/**
 * Schemas class
 *
 * Handles schemas related REST API endpoints for the SureRank plugin.
 *
 * @package SureRank\Inc\API
 */

namespace SureRank\Inc\Schema;

use SureRank\Inc\API\Api_Base;
use SureRank\Inc\Functions\Helper;
use SureRank\Inc\Functions\Sanitize;
use SureRank\Inc\Functions\Send_Json;
use SureRank\Inc\Traits\Get_Instance;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class SchemasApi
 *
 * Handles schemas related REST API endpoints.
 */
class SchemasApi extends Api_Base {
	use Get_Instance;

	/**
	 * Route Get Term Seo Data
	 */
	protected const GET_POST_BY_QUERY = '/admin/posts';

	/**
	 * Route get variables
	 */
	protected const GET_VARIABLES = '/schemas/variables';

	/**
	 * Route generate schema recommendations.
	 */
	protected const GENERATE_SCHEMA_RECOMMENDATIONS = '/schemas/generator';

	/**
	 * Route track schema recommendation events.
	 */
	protected const TRACK_SCHEMA_RECOMMENDATION_EVENT = '/schemas/recommendation-event';

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
	}

	/**
	 * Register API routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->get_api_namespace(),
			self::GET_POST_BY_QUERY,
			[
				'methods'             => WP_REST_Server::CREATABLE, // GET Term Seo Data.
				'callback'            => [ $this, 'get_post_by_query' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'q' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::GET_VARIABLES,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_variables' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::GENERATE_SCHEMA_RECOMMENDATIONS,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'generate_schema_recommendations' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'post_type'            => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'post_title'           => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'post_content'         => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'wp_kses_post',
					],
					'available_schemas'    => [
						'type'              => 'array',
						'required'          => false,
						'sanitize_callback' => [ self::class, 'sanitize_schema_array_param' ],
					],
					'active_schemas'       => [
						'type'              => 'array',
						'required'          => false,
						'sanitize_callback' => [ self::class, 'sanitize_schema_array_param' ],
					],
					'active_schema_titles' => [
						'type'              => 'array',
						'required'          => false,
						'sanitize_callback' => [ self::class, 'sanitize_schema_array_param' ],
					],
					'active_schema_types'  => [
						'type'              => 'array',
						'required'          => false,
						'sanitize_callback' => [ self::class, 'sanitize_schema_array_param' ],
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::TRACK_SCHEMA_RECOMMENDATION_EVENT,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'track_schema_recommendation_event' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'event_key' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
	}

	/**
	 * Get Posts By Query
	 *
	 * Handles the REST API request for posts by query.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST API Request object.
	 * @since 1.0.0
	 * @return void
	 */
	public function get_post_by_query( $request ) {
		$search_string = sanitize_text_field( $request->get_param( 'q' ) );
		$page          = intval( $request->get_param( 'page' ) ) ? intval( $request->get_param( 'page' ) ) : 1;
		$result        = [];

		if ( empty( $search_string ) ) {
			Send_Json::success( [ 'results' => $result ] );
		}

		try {
			$post_types = array_merge(
				[ 'post', 'page' ],
				array_keys(
					get_post_types(
						[
							'public'   => true,
							'_builtin' => false,
						],
						'names'
					)
				)
			);

			foreach ( $post_types as $post_type ) {
				add_filter( 'posts_search', [ $this, 'search_only_titles' ], 10, 2 );

				$query = new \WP_Query(
					[
						's'              => $search_string,
						'post_type'      => $post_type,
						'posts_per_page' => 10,
						'paged'          => $page,
						'fields'         => 'ids',
					]
				);

				$data = [];
				if ( $query->have_posts() ) {
					foreach ( $query->posts as $post_id ) {
						$post_id = intval( is_object( $post_id ) ? $post_id->ID : $post_id );
						$data[]  = [
							'id'   => 'post-' . $post_id,
							'text' => get_the_title( $post_id ),
						];
					}
				}

				if ( ! empty( $data ) ) {
					$result[] = [
						'text'     => ucfirst( $post_type ),
						'children' => $data,
					];
				}

				remove_filter( 'posts_search', [ $this, 'search_only_titles' ] );
			}

			wp_reset_postdata();

			$output   = 'objects'; // names or objects, note names is the default.
			$operator = 'and'; // also supports 'or'.
			$args     = [
				'public' => true,
			];

			$taxonomies = get_taxonomies( $args, $output, $operator );

			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_terms(
					[
						'taxonomy'   => $taxonomy->name,
						'orderby'    => 'count',
						'hide_empty' => 0,
						'name__like' => $search_string,
					]
				);

				$data = [];

				$label = ucwords( $taxonomy->label );

				if ( ! empty( $terms ) && ! is_wp_error( $terms ) && is_array( $terms ) ) {

					foreach ( $terms as $term ) {

						$term_taxonomy_name = ucfirst( str_replace( '_', ' ', $taxonomy->name ) );

						// for tax-{id}, and tax-{id}-single-{taxonomy} type rules.
						$data[] = [
							'id'   => 'tax-' . $term->term_id,
							'text' => ucwords( $term->name . ' (' . $term_taxonomy_name . ')' ),
						];

						$data[] = [
							'id'   => 'tax-' . $term->term_id . '-single-' . $taxonomy->name,
							'text' => 'All singulars from ' . $term->name,
						];

					}
				}

				if ( is_array( $data ) && ! empty( $data ) ) {
					$result[] = [
						'text'     => $label,
						'children' => $data,
					];
				}
			}

			Send_Json::success( [ 'results' => $result ] );
		} catch ( \Exception $e ) {
			Send_Json::success( [ 'results' => [] ] );
		}
	}

	/**
	 * Search Only Titles
	 *
	 * Filters the WP_Query search to look only in post titles.
	 *
	 * @param string    $search   The search SQL for WHERE clause.
	 * @param \WP_Query $wp_query The current WP_Query object.
	 * @since 1.0.0
	 * @return string
	 */
	public function search_only_titles( $search, $wp_query ) {
		global $wpdb;

		if ( ! empty( $search ) && ! empty( $wp_query->query_vars['search_terms'] ) ) {
			$q      = $wp_query->query_vars;
			$n      = ! empty( $q['exact'] ) ? '' : '%';
			$search = [];

			foreach ( (array) $q['search_terms'] as $term ) {
				$search[] = $wpdb->prepare( "{$wpdb->posts}.post_title LIKE %s", $n . $wpdb->esc_like( $term ) . $n );
			}

			if ( ! is_user_logged_in() ) {
				$search[] = "{$wpdb->posts}.post_password = ''";
			}

			$search = ' AND ' . implode( ' AND ', $search );
		}

		return $search;
	}

	/**
	 * Get schema variables.
	 *
	 * @since 1.7.5
	 * @return void
	 */
	public function get_variables() {
		Send_Json::success(
			[
				'variables' => Variables::get_instance()->get_schema_variables(),
			]
		);
	}

	/**
	 * Deep-sanitize an array request parameter before it is forwarded upstream.
	 *
	 * Used as the REST `sanitize_callback` for the schema-context parameters so
	 * their nested values are validated rather than passed through raw.
	 *
	 * @since 1.7.5
	 * @param mixed $value Raw request value.
	 * @return array<mixed> Sanitized array, or an empty array when not an array.
	 */
	public static function sanitize_schema_array_param( $value ) {
		return is_array( $value ) ? Sanitize::array_deep( 'sanitize_text_field', $value ) : [];
	}

	/**
	 * Generate schema recommendations based on content.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST API Request object.
	 * @return void
	 */
	public function generate_schema_recommendations( $request ) {
		$feature_enabled = apply_filters( 'surerank_schema_recommendation_enabled', true );
		if ( ! $feature_enabled ) {
			Send_Json::error(
				[
					'message' => __( 'Schema recommendations are disabled by site configuration.', 'surerank' ),
					'code'    => 'schema_recommendation_disabled',
				]
			);
			return;
		}

		$post_type    = sanitize_text_field( (string) $request->get_param( 'post_type' ) );
		$post_title   = sanitize_text_field( (string) $request->get_param( 'post_title' ) );
		$post_content = (string) $request->get_param( 'post_content' );
		// Remove Gutenberg block delimiter comments so the AI sees prose, not block markup.
		$post_content         = preg_replace( '/<!--\s*\/?wp:.*?-->/s', '', $post_content ) ?? $post_content;
		$post_content         = wp_strip_all_tags( $post_content );
		$post_content         = trim( preg_replace( '/\s+/', ' ', $post_content ) ?? '' );
		$available_schemas    = $request->get_param( 'available_schemas' );
		$active_schemas       = $request->get_param( 'active_schemas' );
		$active_schema_titles = $request->get_param( 'active_schema_titles' );
		$active_schema_types  = $request->get_param( 'active_schema_types' );

		if ( '' === $post_title && '' === $post_content ) {
			Send_Json::error(
				[
					'message' => __( 'Please provide post title or content to generate schema recommendations.', 'surerank' ),
					'code'    => 'missing_content',
				]
			);
			return;
		}

		$api_response = $this->get_ai_schema_recommendations(
			$post_type,
			$post_title,
			$post_content,
			$available_schemas,
			$active_schemas,
			$active_schema_titles,
			$active_schema_types
		);

		if ( is_wp_error( $api_response ) ) {
			Send_Json::error(
				[
					'message' => sanitize_text_field( $api_response->get_error_message() ),
					'code'    => sanitize_key( (string) $api_response->get_error_code() ),
				]
			);
			return;
		}

		if ( ! is_array( $api_response ) ) {
			Send_Json::error(
				[
					'message' => __( 'Unable to generate schema recommendations right now. Please try again.', 'surerank' ),
					'code'    => 'schema_recommendation_failed',
				]
			);
			return;
		}

		$recommendations = [];
		if ( isset( $api_response['recommendations'] ) && is_array( $api_response['recommendations'] ) ) {
			$recommendations = array_values( $api_response['recommendations'] );
		}
		$grouped_recommendations = [];
		if ( isset( $api_response['grouped_recommendations'] ) && is_array( $api_response['grouped_recommendations'] ) ) {
			$grouped_recommendations = array_values( $api_response['grouped_recommendations'] );
		}
		$excluded_existing = [];
		if ( isset( $api_response['excluded_existing'] ) && is_array( $api_response['excluded_existing'] ) ) {
			$excluded_existing = array_values( $api_response['excluded_existing'] );
		}
		$added_companions = [];
		if ( isset( $api_response['added_companions'] ) && is_array( $api_response['added_companions'] ) ) {
			$added_companions = array_values( $api_response['added_companions'] );
		}
		$source = 'ai';
		if ( isset( $api_response['source'] ) && is_string( $api_response['source'] ) ) {
			$source = sanitize_text_field( $api_response['source'] );
		}

		// Flag for analytics: first schema recommendation generated.
		if ( ! get_option( 'surerank_ai_schema_recommendation_used', false ) ) {
			update_option( 'surerank_ai_schema_recommendation_used', true );
		}

		Send_Json::success(
			[
				'recommendations'         => $recommendations,
				'grouped_recommendations' => $grouped_recommendations,
				'excluded_existing'       => $excluded_existing,
				'added_companions'        => $added_companions,
				'source'                  => $source,
			]
		);
	}

	/**
	 * Track schema recommendation related events for analytics.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST API Request object.
	 * @return void
	 */
	public function track_schema_recommendation_event( $request ) {
		$event_key = sanitize_key( (string) $request->get_param( 'event_key' ) );
		$map       = [
			'recommendation_added' => 'surerank_ai_schema_recommendation_added',
			'upgrade_clicked'      => 'surerank_ai_schema_recommendation_upgrade_clicked',
			'group_dismissed'      => 'surerank_ai_schema_recommendation_group_dismissed',
		];

		if ( ! isset( $map[ $event_key ] ) ) {
			Send_Json::error(
				[
					'message' => __( 'Invalid recommendation event key.', 'surerank' ),
					'code'    => 'invalid_recommendation_event_key',
				]
			);
			return;
		}

		$option_name = $map[ $event_key ];
		if ( ! get_option( $option_name, false ) ) {
			update_option( $option_name, true );
		}

		Send_Json::success(
			[
				'tracked' => true,
			]
		);
	}

	/**
	 * Attempt to fetch recommendations from the AI service.
	 *
	 * @param string $post_type    Post type.
	 * @param string $post_title   Post title.
	 * @param string $post_content Post content.
	 * @param mixed  $available_schemas Available schema catalog from client.
	 * @param mixed  $active_schemas Active schemas from client.
	 * @param mixed  $active_schema_titles Active schema parent titles.
	 * @param mixed  $active_schema_types Active schema child types.
	 * @return array<string, mixed>|\WP_Error|null
	 */
	private function get_ai_schema_recommendations( $post_type, $post_title, $post_content, $available_schemas = null, $active_schemas = null, $active_schema_titles = null, $active_schema_types = null ) {
		$content_utils = \SureRank\Inc\Modules\Content_Generation\Utils::get_instance();
		$available     = $this->get_available_schema_tools();

		// Product schema is only meaningful on an actual WooCommerce/SureCart product:
		// SureRank fills its fields from product data that exists only for those post
		// types. Drop Product for any other context so the AI never recommends a
		// Product schema that would render empty.
		$is_product_context =
			( 'product' === $post_type && Helper::wc_status() ) ||
			( 'sc_product' === $post_type && Helper::sc_status() );
		if ( ! $is_product_context ) {
			$available = array_values(
				array_filter(
					$available,
					static fn( $tool ) => 'product' !== strtolower(
						(string) ( $tool['type'] ?? ( $tool['title'] ?? '' ) )
					)
				)
			);
		}

		unset( $available_schemas );

		$active_schema_payload = [];
		if ( is_array( $active_schemas ) && ! empty( $active_schemas ) ) {
			$active_schema_payload = $active_schemas;
		}
		$active_schema_titles_payload = [];
		if ( is_array( $active_schema_titles ) && ! empty( $active_schema_titles ) ) {
			$active_schema_titles_payload = $active_schema_titles;
		}
		$active_schema_types_payload = [];
		if ( is_array( $active_schema_types ) && ! empty( $active_schema_types ) ) {
			$active_schema_types_payload = $active_schema_types;
		}

		$request_data = [
			'post_type'               => $post_type,
			'post_title'              => $post_title,
			'post_content'            => wp_trim_words( $post_content, 700, '' ),
			'available_schemas'       => $available,
			'available_schema_titles' => $this->get_available_schema_titles(),
			'active_schemas'          => $active_schema_payload,
			'active_schema_titles'    => $active_schema_titles_payload,
			'active_schema_types'     => $active_schema_types_payload,
			'source'                  => 'openai',
		];

		// Ask the AI to return the human-readable `reason` in the site language
		// (schema type names stay in English). Mirrors content generation, which
		// only sends the locale for non-default (non en-US) sites.
		$language = get_bloginfo( 'language' );
		if ( 'en-US' !== $language ) {
			$request_data['language'] = $language;
		}

		$response = $content_utils->send_api_request( $request_data, 'surerank/generate/schema-recommendation', 40 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 || $response_code >= 300 ) {
			$body      = wp_remote_retrieve_body( $response );
			$decoded   = json_decode( (string) $body, true );
			$error_msg = __( 'Unable to generate schema recommendations right now. Please try again.', 'surerank' );
			$error_key = 'schema_recommendation_failed';

			if ( is_array( $decoded ) ) {
				$error_msg = sanitize_text_field(
					(string) (
						$decoded['message'] ??
						$decoded['error'] ??
						$error_msg
					)
				);
				$error_key = sanitize_key( (string) ( $decoded['code'] ?? $error_key ) );
			}

			return new \WP_Error( $error_key, $error_msg );
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( (string) $body, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$raw_recommendations = [];
		$source              = sanitize_text_field( (string) ( $decoded['source'] ?? 'ai' ) );

		if ( isset( $decoded['recommendations'] ) && is_array( $decoded['recommendations'] ) ) {
			$raw_recommendations = $decoded['recommendations'];
		} elseif ( isset( $decoded['schemas'] ) && is_array( $decoded['schemas'] ) ) {
			$raw_recommendations = $decoded['schemas'];
		}

		if ( empty( $raw_recommendations ) ) {
			return [
				'recommendations' => [],
				'source'          => $source,
			];
		}

		$available_lookup = [];
		foreach ( $available as $schema ) {
			$schema_title = sanitize_text_field( (string) ( $schema['title'] ?? '' ) );
			$schema_key   = $this->normalize_schema_key( $schema_title );

			if ( '' === $schema_key ) {
				continue;
			}

			$available_lookup[ $schema_key ] = $schema;
		}

		$normalized = [];

		foreach ( $raw_recommendations as $recommendation ) {
			$schema            = '';
			$reason            = '';
			$parent_schema     = '';
			$child_schema_type = '';
			$data              = is_array( $recommendation ) ? $recommendation : [];

			if ( is_string( $recommendation ) ) {
				$schema = sanitize_text_field( $recommendation );
			} elseif ( ! empty( $data ) ) {
				$parent_schema     = sanitize_text_field( (string) ( $data['parent_schema'] ?? '' ) );
				$child_schema_type = sanitize_text_field(
					(string) (
						$data['child_schema_type'] ??
						$data['schema'] ??
						$data['type'] ??
						''
					)
				);
				$schema            = sanitize_text_field(
					(string) (
						$data['schema'] ??
						$data['name'] ??
						$data['title'] ??
						$data['type'] ??
						''
					)
				);
				$reason            = sanitize_text_field( (string) ( $data['reason'] ?? '' ) );
			}

			if ( '' === $schema && '' !== $child_schema_type ) {
				$schema = $child_schema_type;
			}

			if ( '' === $schema ) {
				continue;
			}

			$schema_key      = $this->normalize_schema_key( $schema );
			$available_match = $available_lookup[ $schema_key ] ?? null;
			$can_add         = $this->parse_recommendation_bool( $data['can_add'] ?? null );
			$is_pro          = $this->parse_recommendation_bool( $data['is_pro'] ?? null );
			$tier            = sanitize_text_field( (string) ( $data['tier'] ?? '' ) );
			$upgrade_url     = esc_url_raw( (string) ( $data['upgrade_url'] ?? '' ) );

			if ( null === $can_add ) {
				$can_add = null !== $available_match;
			}

			if ( '' === $tier && is_array( $available_match ) ) {
				$tier = sanitize_text_field( (string) ( $available_match['tier'] ?? '' ) );
			}

			if ( null === $is_pro ) {
				if ( 'pro' === strtolower( $tier ) ) {
					$is_pro = true;
				} elseif ( is_array( $available_match ) ) {
					$is_pro = 'pro' === strtolower( sanitize_text_field( (string) ( $available_match['tier'] ?? 'free' ) ) );
				} else {
					$is_pro = false;
				}
			}

			if ( '' === $tier ) {
				$tier = $is_pro ? 'pro' : 'free';
			}

			$normalized_schema = $schema;
			if ( is_array( $available_match ) && isset( $available_match['title'] ) && is_string( $available_match['title'] ) ) {
				$normalized_schema = $available_match['title'];
			}

			$normalized[ $schema_key ] = [
				'schema'            => $normalized_schema,
				'parent_schema'     => $parent_schema,
				'child_schema_type' => '' !== $child_schema_type ? $child_schema_type : $normalized_schema,
				'reason'            => $reason,
				'can_add'           => (bool) $can_add,
				'is_pro'            => (bool) $is_pro,
				'tier'              => $tier,
				'upgrade_url'       => $upgrade_url,
				'source'            => sanitize_text_field( (string) ( $data['source'] ?? 'ai' ) ),
			];
		}

		$grouped_recommendations = [];
		if ( isset( $decoded['grouped_recommendations'] ) && is_array( $decoded['grouped_recommendations'] ) ) {
			$grouped_recommendations = $decoded['grouped_recommendations'];
		}
		$excluded_existing = [];
		if ( isset( $decoded['excluded_existing'] ) && is_array( $decoded['excluded_existing'] ) ) {
			$excluded_existing = $decoded['excluded_existing'];
		}
		$added_companions = [];
		if ( isset( $decoded['added_companions'] ) && is_array( $decoded['added_companions'] ) ) {
			$added_companions = $decoded['added_companions'];
		}

		return [
			'recommendations'         => array_values( $normalized ),
			'grouped_recommendations' => $grouped_recommendations,
			'excluded_existing'       => $excluded_existing,
			'added_companions'        => $added_companions,
			'source'                  => $source,
		];
	}

	/**
	 * Get available schema titles currently supported in this setup.
	 *
	 * @return array<int, string>
	 */
	private function get_available_schema_titles() {
		$available = $this->get_available_schema_tools();
		$titles    = array_column( $available, 'title' );

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $title ) => is_string( $title ) ? sanitize_text_field( $title ) : '',
						$titles
					)
				)
			)
		);
	}

	/**
	 * Get available schema tools currently supported in this setup.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_available_schema_tools() {
		$schema_options = Utils::get_default_schema_options();
		$tools          = [];

		foreach ( $schema_options as $schema ) {
			if ( ! is_array( $schema ) ) {
				continue;
			}

			$title = sanitize_text_field( (string) ( $schema['title'] ?? '' ) );
			$type  = sanitize_text_field( (string) ( $schema['type'] ?? $title ) );
			$key   = $this->normalize_schema_key( $title );

			if ( '' === $key || '' === $title ) {
				continue;
			}

			$tools[ $key ] = [
				'schema'  => $title,
				'title'   => $title,
				'type'    => $type,
				'tier'    => 'free',
				'can_add' => true,
			];
		}

		/**
		 * Filters the list of schema tools sent to AI schema recommendation APIs.
		 *
		 * @param array<int, array<string, mixed>> $tools Available schema tools.
		 */
		$tools = apply_filters( 'surerank_schema_recommendation_available_schemas', array_values( $tools ) );
		if ( ! is_array( $tools ) ) {
			return [];
		}

		return array_values( $tools );
	}

	/**
	 * Normalize schema labels to a key for deduping/comparison.
	 *
	 * @param string $schema Schema label.
	 * @return string
	 */
	private function normalize_schema_key( $schema ) {
		$normalized = strtolower( trim( (string) $schema ) );
		return sanitize_key( str_replace( [ '-', '_' ], ' ', $normalized ) );
	}

	/**
	 * Parse truthy/falsey recommendation flags from mixed API payloads.
	 *
	 * @param mixed $value Potential boolean value.
	 * @return bool|null
	 */
	private function parse_recommendation_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return 1 === (int) $value;
		}

		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );

			if ( in_array( $normalized, [ 'true', 'yes', 'on' ], true ) ) {
				return true;
			}

			if ( in_array( $normalized, [ 'false', 'no', 'off' ], true ) ) {
				return false;
			}
		}

		return null;
	}
}
