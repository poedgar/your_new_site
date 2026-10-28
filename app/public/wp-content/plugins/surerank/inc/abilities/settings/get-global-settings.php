<?php
/**
 * Get global settings ability.
 *
 * @package SureRank\Inc\Abilities\Settings
 */

namespace SureRank\Inc\Abilities\Settings;

use SureRank\Inc\Abilities\Ability_Base;
use SureRank\Inc\Functions\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves SureRank global settings.
 */
class Get_Global_Settings extends Ability_Base {
	/**
	 * Constructor.
	 *
	 * @since 1.7.5
	 */
	public function __construct() {
		$this->id          = 'surerank/get-global-settings';
		$this->label       = __( 'Get SureRank Global Settings', 'surerank' );
		$this->description = __( 'Retrieve the current SureRank global SEO settings.', 'surerank' );
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
				'keys' => [
					'type'        => 'array',
					'description' => __( 'Optional list of specific setting keys to return. Omit to return all SureRank settings.', 'surerank' ),
					'items'       => [
						'type' => 'string',
					],
				],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.7.5
	 */
	public function get_output_schema() {
		return [
			'type' => 'object',
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
		$settings = Settings::get();
		$keys     = isset( $input['keys'] ) && is_array( $input['keys'] ) ? array_map( 'sanitize_key', $input['keys'] ) : [];

		if ( empty( $keys ) ) {
			return is_array( $settings ) ? $settings : [];
		}

		$result = [];
		foreach ( $keys as $key ) {
			if ( is_array( $settings ) && array_key_exists( $key, $settings ) ) {
				$result[ $key ] = $settings[ $key ];
			}
		}

		return $result;
	}
}
