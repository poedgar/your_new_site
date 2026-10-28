<?php
/**
 * Update robots.txt ability.
 *
 * @package SureRank\Inc\Abilities\Settings
 */

namespace SureRank\Inc\Abilities\Settings;

use SureRank\Inc\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates the custom robots.txt content managed by SureRank.
 */
class Update_Robots_Txt extends Ability_Base {
	/**
	 * Constructor.
	 *
	 * @since 1.7.5
	 */
	public function __construct() {
		$this->id          = 'surerank/update-robots-txt';
		$this->label       = __( 'Update SureRank Robots.txt', 'surerank' );
		$this->description = __( 'Update the custom robots.txt content stored by SureRank.', 'surerank' );
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
				'robots_txt_content' => [
					'type'        => 'string',
					'description' => __( 'The full robots.txt content to store.', 'surerank' ),
				],
			],
			'required'             => [ 'robots_txt_content' ],
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
				'success'            => [ 'type' => 'boolean' ],
				'robots_txt_content' => [ 'type' => 'string' ],
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
		$content = isset( $input['robots_txt_content'] ) ? sanitize_textarea_field( (string) $input['robots_txt_content'] ) : '';

		update_option( SURERANK_ROBOTS_TXT_CONTENT, $content );

		return [
			'success'            => true,
			'robots_txt_content' => $content,
		];
	}
}
