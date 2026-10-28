<?php
/**
 * Headless REST fields.
 *
 * Yoast-style core REST exposure: registers a `surerank` (structured JSON) and
 * `surerank_head` (raw HTML) field on every public, REST-enabled post type and
 * taxonomy, so headless consumers get SureRank's SEO output directly from the
 * core /wp/v2/* responses without a second request to the get_head endpoint.
 *
 * Output and cache are shared with the Headless controller: both call
 * Headless::get_data_for_object(), so the JSON shape is identical and a single
 * per-resource cache (and its save_post / edited_term invalidation) serves both.
 *
 * Gated by the same Settings toggle as the get_head endpoint
 * (enable_headless_rest_api, default off): the fields are not registered at all
 * when the toggle is off, so they never appear in core responses.
 *
 * @package surerank
 * @since 1.9.0
 */

namespace SureRank\Inc\API;

use SureRank\Inc\Functions\Helper;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Traits\Get_Instance;
use WP_Post;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Rest_Fields
 *
 * @since 1.9.0
 */
class Rest_Fields {

	use Get_Instance;

	/**
	 * Structured JSON field name.
	 */
	public const FIELD = 'surerank';

	/**
	 * Raw head HTML field name.
	 */
	public const FIELD_HEAD = 'surerank_head';

	/**
	 * Constructor.
	 *
	 * Registers the fields late on rest_api_init (priority 20) so all custom
	 * post types and taxonomies are registered first.
	 *
	 * @since 1.9.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_fields' ], 20 );
	}

	/**
	 * Register the surerank fields on public, REST-enabled objects.
	 *
	 * No-op when the headless toggle is off, so the fields never appear in core
	 * REST responses unless the site owner opts in.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public function register_fields() {
		if ( ! Settings::get( 'enable_headless_rest_api' ) ) {
			return;
		}

		foreach ( $this->rest_post_types() as $post_type ) {
			register_rest_field(
				$post_type,
				self::FIELD,
				[
					'get_callback' => [ $this, 'get_post_field' ],
					'schema'       => $this->field_schema(),
				]
			);
			register_rest_field(
				$post_type,
				self::FIELD_HEAD,
				[
					'get_callback' => [ $this, 'get_post_head_field' ],
					'schema'       => $this->head_schema(),
				]
			);
		}

		foreach ( $this->rest_taxonomies() as $taxonomy ) {
			register_rest_field(
				$taxonomy,
				self::FIELD,
				[
					'get_callback' => [ $this, 'get_term_field' ],
					'schema'       => $this->field_schema(),
				]
			);
			register_rest_field(
				$taxonomy,
				self::FIELD_HEAD,
				[
					'get_callback' => [ $this, 'get_term_head_field' ],
					'schema'       => $this->head_schema(),
				]
			);
		}
	}

	/**
	 * SureRank JSON for a post REST response.
	 *
	 * @param array<string, mixed> $object Prepared post response data.
	 * @return array<string, mixed>|null
	 * @since 1.9.0
	 */
	public function get_post_field( $object ) {
		$data = $this->post_data( $object );
		return null === $data ? null : $data['json'];
	}

	/**
	 * Raw head HTML (surerank_head) for a post REST response.
	 *
	 * @param array<string, mixed> $object Prepared post response data.
	 * @return string|null
	 * @since 1.9.0
	 */
	public function get_post_head_field( $object ) {
		$data = $this->post_data( $object );
		return null === $data ? null : $data['head'];
	}

	/**
	 * SureRank JSON for a term REST response.
	 *
	 * @param array<string, mixed> $object Prepared term response data.
	 * @return array<string, mixed>|null
	 * @since 1.9.0
	 */
	public function get_term_field( $object ) {
		$data = $this->term_data( $object );
		return null === $data ? null : $data['json'];
	}

	/**
	 * Raw head HTML (surerank_head) for a term REST response.
	 *
	 * @param array<string, mixed> $object Prepared term response data.
	 * @return string|null
	 * @since 1.9.0
	 */
	public function get_term_head_field( $object ) {
		$data = $this->term_data( $object );
		return null === $data ? null : $data['head'];
	}

	/**
	 * Public post types exposed in REST.
	 *
	 * @return array<int, string>
	 * @since 1.9.0
	 */
	private function rest_post_types() {
		$types = [];
		foreach ( Helper::get_public_cpts() as $name => $object ) {
			if ( ! empty( $object->show_in_rest ) ) {
				$types[] = (string) $name;
			}
		}

		return $types;
	}

	/**
	 * Public taxonomies exposed in REST.
	 *
	 * @return array<int, string>
	 * @since 1.9.0
	 */
	private function rest_taxonomies() {
		$taxonomies = [];
		foreach ( Helper::get_public_taxonomies() as $name => $object ) {
			if ( ! empty( $object->show_in_rest ) ) {
				$taxonomies[] = (string) $name;
			}
		}

		return $taxonomies;
	}

	/**
	 * Resolve + render a post object, honouring the public visibility gate.
	 *
	 * @param array<string, mixed> $object Prepared post response data.
	 * @return array<string, mixed>|null Head + json, or null when withheld.
	 * @since 1.9.0
	 */
	private function post_data( $object ) {
		$id = $this->object_id( $object );
		if ( 0 === $id ) {
			return null;
		}

		$resolved = [
			'id'   => $id,
			'type' => 'post',
		];

		return $this->data( $resolved );
	}

	/**
	 * Resolve + render a term object, honouring the public visibility gate.
	 *
	 * @param array<string, mixed> $object Prepared term response data.
	 * @return array<string, mixed>|null Head + json, or null when withheld.
	 * @since 1.9.0
	 */
	private function term_data( $object ) {
		$id = $this->object_id( $object );
		if ( 0 === $id ) {
			return null;
		}

		$term = get_term( $id );
		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		$resolved = [
			'id'       => $id,
			'type'     => 'term',
			'taxonomy' => $term->taxonomy,
		];

		return $this->data( $resolved );
	}

	/**
	 * Shared visibility gate + render for a resolved object.
	 *
	 * @param array{id:int,type:string,taxonomy?:string} $resolved Resolved object.
	 * @return array<string, mixed>|null
	 * @since 1.9.0
	 */
	private function data( array $resolved ) {
		$headless = Headless::get_instance();

		// Never expose non-public content (draft / private / password /
		// non-public taxonomy), even to authenticated editor requests.
		if ( ! $headless->is_publicly_viewable( $resolved ) ) {
			return null;
		}

		return $headless->get_data_for_object( $resolved );
	}

	/**
	 * Extract the object ID from a prepared REST response array.
	 *
	 * @param mixed $object Prepared post/term response data.
	 * @return int
	 * @since 1.9.0
	 */
	private function object_id( $object ) {
		if ( $object instanceof WP_Post || $object instanceof WP_Term ) {
			return (int) ( $object instanceof WP_Post ? $object->ID : $object->term_id );
		}

		if ( is_array( $object ) && isset( $object['id'] ) ) {
			return (int) $object['id'];
		}

		return 0;
	}

	/**
	 * Schema for the structured surerank field.
	 *
	 * @return array<string, mixed>
	 * @since 1.9.0
	 */
	private function field_schema() {
		return [
			'description' => __( 'SureRank SEO metadata and schema for this resource.', 'surerank' ),
			'type'        => [ 'object', 'null' ],
			'context'     => [ 'view', 'edit' ],
			'readonly'    => true,
		];
	}

	/**
	 * Schema for the raw head HTML field.
	 *
	 * @return array<string, mixed>
	 * @since 1.9.0
	 */
	private function head_schema() {
		return [
			'description' => __( 'SureRank SEO head HTML for drop-in server-side rendering.', 'surerank' ),
			'type'        => [ 'string', 'null' ],
			'context'     => [ 'view', 'edit' ],
			'readonly'    => true,
		];
	}
}
