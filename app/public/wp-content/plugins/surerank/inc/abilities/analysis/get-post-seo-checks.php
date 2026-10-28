<?php
/**
 * Get post SEO checks ability.
 *
 * @package SureRank\Inc\Abilities\Analysis
 */

namespace SureRank\Inc\Abilities\Analysis;

use SureRank\Inc\Abilities\Ability_Base;
use SureRank\Inc\API\Analyzer;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves cached/current SureRank SEO checks for posts.
 */
class Get_Post_Seo_Checks extends Ability_Base {
	/**
	 * Constructor.
	 *
	 * @since 1.7.5
	 */
	public function __construct() {
		$this->id          = 'surerank/get-post-seo-checks';
		$this->label       = __( 'Get SureRank Post SEO Checks', 'surerank' );
		$this->description = __( 'Retrieve SureRank SEO checks for one or more posts, pages, or custom post type items.', 'surerank' );
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
				'post_ids' => [
					'type'        => 'array',
					'description' => __( 'One or more post IDs to inspect.', 'surerank' ),
					'items'       => [
						'type' => 'integer',
					],
				],
			],
			'required'             => [ 'post_ids' ],
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
				'status'  => [ 'type' => 'string' ],
				'message' => [ 'type' => 'string' ],
				'data'    => [ 'type' => 'object' ],
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
		$post_ids = isset( $input['post_ids'] ) && is_array( $input['post_ids'] ) ? array_map( 'absint', $input['post_ids'] ) : [];
		$post_ids = array_values(
			array_filter(
				$post_ids,
				static function ( $post_id ) {
					return $post_id > 0 && get_post( $post_id ) instanceof \WP_Post;
				}
			)
		);

		if ( empty( $post_ids ) ) {
			return new \WP_Error(
				'surerank_missing_post_ids',
				__( 'At least one valid post ID is required.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'post_ids', $post_ids );

		$response = Analyzer::get_instance()->get_page_seo_checks( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response->get_data();
	}
}
