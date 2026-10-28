<?php
/**
 * Update term SEO ability.
 *
 * @package SureRank\Inc\Abilities\Content
 */

namespace SureRank\Inc\Abilities\Content;

use SureRank\Inc\Abilities\Ability_Base;
use SureRank\Inc\API\Term;
use SureRank\Inc\Functions\Defaults;
use SureRank\Inc\Functions\Sanitize;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates SureRank SEO data for a taxonomy term.
 */
class Update_Term_Seo extends Ability_Base {
	/**
	 * Constructor.
	 *
	 * @since 1.7.5
	 */
	public function __construct() {
		$this->id          = 'surerank/update-term-seo';
		$this->label       = __( 'Update SureRank Term SEO', 'surerank' );
		$this->description = __( 'Update SureRank SEO metadata for a taxonomy term.', 'surerank' );
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
				'term_id'  => [
					'type'        => 'integer',
					'description' => __( 'The target term ID.', 'surerank' ),
				],
				'metaData' => [
					'type'        => 'object',
					'description' => __( 'Key-value pairs of SureRank term SEO metadata to update.', 'surerank' ),
				],
			],
			'required'             => [ 'term_id', 'metaData' ],
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
				'term_id' => [ 'type' => 'integer' ],
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
		$term_id   = isset( $input['term_id'] ) ? absint( $input['term_id'] ) : 0;
		$meta_data = isset( $input['metaData'] ) && is_array( $input['metaData'] ) ? $input['metaData'] : [];

		if ( ! $term_id || empty( $meta_data ) ) {
			return new \WP_Error(
				'surerank_invalid_term_update',
				__( 'A valid term ID and metadata payload are required.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! get_term( $term_id ) instanceof \WP_Term ) {
			return new \WP_Error(
				'surerank_term_not_found',
				__( 'No term was found for the provided ID.', 'surerank' ),
				[ 'status' => 404 ]
			);
		}

		$allowed_keys = array_keys( Defaults::get_instance()->get_post_defaults() );
		$meta_data    = array_intersect_key( $meta_data, array_flip( $allowed_keys ) );

		if ( empty( $meta_data ) ) {
			return new \WP_Error(
				'surerank_no_valid_term_meta_keys',
				__( 'No valid SureRank term SEO keys were provided.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		/**
		 * Mirror the REST sanitization layer, which the direct save path does not apply.
		 *
		 * @var array<string, mixed> $meta_data
		 */
		$meta_data = Sanitize::array_deep( [ Sanitize::class, 'sanitize_with_placeholders' ], $meta_data );

		$result = Term::save_term_seo_meta( $term_id, $meta_data );

		return [
			'success' => (bool) $result['success'],
			'message' => (string) $result['message'],
			'term_id' => $term_id,
		];
	}
}
