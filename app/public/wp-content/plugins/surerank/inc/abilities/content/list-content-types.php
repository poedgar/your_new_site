<?php
/**
 * List content types ability.
 *
 * @package SureRank\Inc\Abilities\Content
 */

namespace SureRank\Inc\Abilities\Content;

use SureRank\Inc\Abilities\Ability_Base;
use SureRank\Inc\Functions\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Lists post types and taxonomies available to SureRank.
 */
class List_Content_Types extends Ability_Base {
	/**
	 * Constructor.
	 *
	 * @since 1.7.5
	 */
	public function __construct() {
		$this->id          = 'surerank/list-content-types';
		$this->label       = __( 'List SureRank Content Types', 'surerank' );
		$this->description = __( 'List the post types, taxonomies, and archives that SureRank can optimize.', 'surerank' );
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
			// Lets null/omitted-input invocations validate (WP_Ability::normalize_input()
			// substitutes this; mcp-adapter can deliver omitted params as null).
			'default'              => [],
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
				'post_types' => [ 'type' => 'object' ],
				'taxonomies' => [ 'type' => 'object' ],
				'archives'   => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Execute ability.
	 *
	 * @since 1.7.5
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>
	 */
	public function execute( $input ) {
		return [
			'post_types' => Helper::get_formatted_post_types(),
			'taxonomies' => Helper::get_formatted_taxonomies(),
			'archives'   => [
				'author' => __( 'Author pages', 'surerank' ),
				'date'   => __( 'Date archives', 'surerank' ),
				'search' => __( 'Search pages', 'surerank' ),
			],
		];
	}
}
