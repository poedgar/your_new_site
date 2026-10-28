<?php
/**
 * Update post SEO ability.
 *
 * @package SureRank\Inc\Abilities\Content
 */

namespace SureRank\Inc\Abilities\Content;

use SureRank\Inc\Abilities\Ability_Base;
use SureRank\Inc\API\Post;
use SureRank\Inc\Functions\Defaults;
use SureRank\Inc\Functions\Sanitize;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates SureRank SEO data for a post object.
 */
class Update_Post_Seo extends Ability_Base {
	/**
	 * Constructor.
	 *
	 * @since 1.7.5
	 */
	public function __construct() {
		$this->id          = 'surerank/update-post-seo';
		$this->label       = __( 'Update SureRank Post SEO', 'surerank' );
		$this->description = __( 'Update SureRank SEO metadata for a post, page, or custom post type item.', 'surerank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.7.5
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => true,
			'idempotent'    => true,
			'priority'      => 2.0,
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
				'post_id'  => [
					'type'        => 'integer',
					'description' => __( 'The target post ID.', 'surerank' ),
				],
				'metaData' => [
					'type'        => 'object',
					'description' => __( 'Key-value pairs of SureRank post SEO metadata to update.', 'surerank' ),
				],
			],
			'required'             => [ 'post_id', 'metaData' ],
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
				'success' => [ 'type' => 'boolean' ],
				'message' => [ 'type' => 'string' ],
				'post_id' => [ 'type' => 'integer' ],
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
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		$meta_data = isset( $input['metaData'] ) && is_array( $input['metaData'] ) ? $input['metaData'] : [];

		if ( ! $post_id || empty( $meta_data ) ) {
			return new \WP_Error(
				'surerank_invalid_post_update',
				__( 'A valid post ID and metadata payload are required.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! get_post( $post_id ) instanceof \WP_Post ) {
			return new \WP_Error(
				'surerank_post_not_found',
				__( 'No post was found for the provided ID.', 'surerank' ),
				[ 'status' => 404 ]
			);
		}

		$allowed_keys = array_keys( Defaults::get_instance()->get_post_defaults() );
		$meta_data    = array_intersect_key( $meta_data, array_flip( $allowed_keys ) );

		if ( empty( $meta_data ) ) {
			return new \WP_Error(
				'surerank_no_valid_post_meta_keys',
				__( 'No valid SureRank post SEO keys were provided.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		/**
		 * Mirror the REST sanitization layer, which the direct save path does not apply.
		 *
		 * @var array<string, mixed> $meta_data
		 */
		$meta_data = Sanitize::array_deep( [ Sanitize::class, 'sanitize_with_placeholders' ], $meta_data );

		$result = Post::save_post_seo_meta( $post_id, $meta_data );

		return [
			'success' => (bool) $result['success'],
			'message' => (string) $result['message'],
			'post_id' => $post_id,
		];
	}
}
