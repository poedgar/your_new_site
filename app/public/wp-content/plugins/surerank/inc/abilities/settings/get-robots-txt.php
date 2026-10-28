<?php
/**
 * Get robots.txt ability.
 *
 * @package SureRank\Inc\Abilities\Settings
 */

namespace SureRank\Inc\Abilities\Settings;

use SureRank\Inc\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves the custom robots.txt content managed by SureRank.
 */
class Get_Robots_Txt extends Ability_Base {
	/**
	 * Constructor.
	 *
	 * @since 1.7.5
	 */
	public function __construct() {
		$this->id          = 'surerank/get-robots-txt';
		$this->label       = __( 'Get SureRank Robots.txt', 'surerank' );
		$this->description = __( 'Retrieve the custom robots.txt content currently stored by SureRank.', 'surerank' );
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
				'robots_txt_content' => [ 'type' => 'string' ],
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
			'robots_txt_content' => (string) get_option( SURERANK_ROBOTS_TXT_CONTENT, '' ),
		];
	}
}
