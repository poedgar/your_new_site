<?php
/**
 * List content items ability.
 *
 * @package SureRank\Inc\Abilities\Content
 */

namespace SureRank\Inc\Abilities\Content;

use SureRank\Inc\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Lists posts/pages or terms that can be targeted by SureRank abilities.
 */
class List_Content_Items extends Ability_Base {
	/**
	 * Constructor.
	 *
	 * @since 1.7.5
	 */
	public function __construct() {
		$this->id          = 'surerank/list-content-items';
		$this->label       = __( 'List SureRank Content Items', 'surerank' );
		$this->description = __( 'List posts, pages, custom post types, or taxonomy terms to find the IDs needed for SureRank SEO actions.', 'surerank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.7.5
	 */
	public function get_annotations() {
		return [
			'readonly'      => true,
			'destructive'   => false,
			'idempotent'    => true,
			'priority'      => 1.0,
			'openWorldHint' => false,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.7.5
	 */
	public function get_input_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'object_type' => [
					'type'        => 'string',
					'enum'        => [ 'post', 'term' ],
					'default'     => 'post',
					'description' => __( 'Whether to list post objects or taxonomy terms.', 'surerank' ),
				],
				'type_name'   => [
					'type'        => 'string',
					'description' => __( 'The post type slug or taxonomy slug to list.', 'surerank' ),
				],
				'search'      => [
					'type'        => 'string',
					'description' => __( 'Optional search query for titles or term names.', 'surerank' ),
				],
				'page'        => [
					'type'        => 'integer',
					'default'     => 1,
					'minimum'     => 1,
					'description' => __( 'Results page number.', 'surerank' ),
				],
				'per_page'    => [
					'type'        => 'integer',
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => __( 'Results per page.', 'surerank' ),
				],
			],
			'required'             => [ 'type_name' ],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.7.5
	 */
	public function get_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'object_type' => [ 'type' => 'string' ],
				'type_name'   => [ 'type' => 'string' ],
				'items'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'     => [ 'type' => 'integer' ],
							'label'  => [ 'type' => 'string' ],
							'status' => [ 'type' => 'string' ],
							'slug'   => [ 'type' => 'string' ],
						],
					],
				],
			],
		];
	}

	/**
	 * Execute ability.
	 *
	 * @since 1.7.5
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$object_type = isset( $input['object_type'] ) ? sanitize_key( (string) $input['object_type'] ) : 'post';
		$type_name   = isset( $input['type_name'] ) ? sanitize_key( (string) $input['type_name'] ) : '';
		$search      = isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';
		$page        = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$per_page    = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;

		if ( empty( $type_name ) ) {
			return new \WP_Error(
				'surerank_missing_type_name',
				__( 'A post type or taxonomy slug is required.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		if ( 'term' === $object_type && ! taxonomy_exists( $type_name ) ) {
			return new \WP_Error(
				'surerank_invalid_taxonomy',
				__( 'The provided taxonomy slug does not exist.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		if ( 'term' !== $object_type && ! post_type_exists( $type_name ) ) {
			return new \WP_Error(
				'surerank_invalid_post_type',
				__( 'The provided post type slug does not exist.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		$items = 'term' === $object_type
			? $this->list_terms( $type_name, $search, $page, $per_page )
			: $this->list_posts( $type_name, $search, $page, $per_page );

		return [
			'object_type' => $object_type,
			'type_name'   => $type_name,
			'items'       => $items,
		];
	}

	/**
	 * List posts for a post type.
	 *
	 * @since 1.7.5
	 * @param string $post_type Post type slug.
	 * @param string $search Search query.
	 * @param int    $page Page number.
	 * @param int    $per_page Results per page.
	 * @return array<int, array<string, int|string>>
	 */
	private function list_posts( $post_type, $search, $page, $per_page ) {
		$query = new \WP_Query(
			[
				'post_type'      => $post_type,
				'post_status'    => [ 'publish', 'draft', 'private', 'future' ],
				'posts_per_page' => $per_page,
				'paged'          => $page,
				's'              => $search,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		$items = [];

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$items[] = [
				'id'     => (int) $post->ID,
				'label'  => $post->post_title,
				'status' => $post->post_status,
				'slug'   => $post->post_name,
			];
		}

		return $items;
	}

	/**
	 * List terms for a taxonomy.
	 *
	 * @since 1.7.5
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $search Search query.
	 * @param int    $page Page number.
	 * @param int    $per_page Results per page.
	 * @return array<int, array<string, int|string>>
	 */
	private function list_terms( $taxonomy, $search, $page, $per_page ) {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'search'     => $search,
				'number'     => $per_page,
				'offset'     => ( $page - 1 ) * $per_page,
			]
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		$items = [];

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$items[] = [
				'id'     => (int) $term->term_id,
				'label'  => $term->name,
				'status' => 'active',
				'slug'   => $term->slug,
			];
		}

		return $items;
	}
}
