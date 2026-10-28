<?php
/**
 * Get term SEO ability.
 *
 * @package SureRank\Inc\Abilities\Content
 */

namespace SureRank\Inc\Abilities\Content;

use SureRank\Inc\Abilities\Ability_Base;
use SureRank\Inc\API\Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves SureRank SEO data for a taxonomy term.
 */
class Get_Term_Seo extends Ability_Base {
	/**
	 * Constructor.
	 *
	 * @since 1.7.5
	 */
	public function __construct() {
		$this->id          = 'surerank/get-term-seo';
		$this->label       = __( 'Get SureRank Term SEO', 'surerank' );
		$this->description = __( 'Retrieve SureRank SEO settings and inherited defaults for a taxonomy term.', 'surerank' );
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
				'term_id'   => [
					'type'        => 'integer',
					'description' => __( 'The target term ID.', 'surerank' ),
				],
				'post_type' => [
					'type'        => 'string',
					'description' => __( 'The taxonomy slug for the term.', 'surerank' ),
				],
			],
			'required'             => [ 'term_id', 'post_type' ],
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
				'data'           => [ 'type' => 'object' ],
				'global_default' => [ 'type' => 'object' ],
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
		$term_id = isset( $input['term_id'] ) ? absint( $input['term_id'] ) : 0;

		$taxonomy = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';

		if ( ! $term_id || empty( $taxonomy ) ) {
			return new \WP_Error(
				'surerank_invalid_term_target',
				__( 'A valid term ID and taxonomy slug are required.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error(
				'surerank_invalid_taxonomy',
				__( 'The provided taxonomy slug does not exist.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error(
				'surerank_term_not_found',
				__( 'The provided term ID does not exist for this taxonomy.', 'surerank' ),
				[ 'status' => 404 ]
			);
		}

		// Object-level authorization: the ability framework already gates this at manage_options, but guard the per-term read here too so the shared
		// helper is never reached without an edit_term check (parity with the REST/AJAX save paths, and defense-in-depth if the capability is lowered).
		if ( ! Term::can_manage_term_seo( $term_id ) ) {
			return new \WP_Error(
				'surerank_forbidden',
				__( 'You are not allowed to view SEO settings for this term.', 'surerank' ),
				[ 'status' => 403 ]
			);
		}

		return Term::get_term_data_by_id( $term_id, $taxonomy, true );
	}
}
