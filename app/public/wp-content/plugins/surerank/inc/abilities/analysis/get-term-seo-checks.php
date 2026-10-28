<?php
/**
 * Get term SEO checks ability.
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
 * Retrieves cached/current SureRank SEO checks for terms.
 */
class Get_Term_Seo_Checks extends Ability_Base {
	/**
	 * Constructor.
	 *
	 * @since 1.7.5
	 */
	public function __construct() {
		$this->id          = 'surerank/get-term-seo-checks';
		$this->label       = __( 'Get SureRank Term SEO Checks', 'surerank' );
		$this->description = __( 'Retrieve SureRank SEO checks for one or more taxonomy terms.', 'surerank' );
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
				'term_ids' => [
					'type'        => 'array',
					'description' => __( 'One or more term IDs to inspect.', 'surerank' ),
					'items'       => [
						'type' => 'integer',
					],
				],
			],
			'required'             => [ 'term_ids' ],
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
		$term_ids = isset( $input['term_ids'] ) && is_array( $input['term_ids'] ) ? array_map( 'absint', $input['term_ids'] ) : [];
		$term_ids = array_values(
			array_filter(
				$term_ids,
				static function ( $term_id ) {
					return $term_id > 0 && get_term( $term_id ) instanceof \WP_Term;
				}
			)
		);

		if ( empty( $term_ids ) ) {
			return new \WP_Error(
				'surerank_missing_term_ids',
				__( 'At least one valid term ID is required.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'term_ids', $term_ids );

		$response = Analyzer::get_instance()->get_taxonomy_seo_checks( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response->get_data();
	}
}
