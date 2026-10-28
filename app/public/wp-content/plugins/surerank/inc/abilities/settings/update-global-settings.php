<?php
/**
 * Update global settings ability.
 *
 * @package SureRank\Inc\Abilities\Settings
 */

namespace SureRank\Inc\Abilities\Settings;

use SureRank\Inc\Abilities\Ability_Base;
use SureRank\Inc\API\Admin;
use SureRank\Inc\Functions\Defaults;
use SureRank\Inc\Functions\Sanitize;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates SureRank global settings.
 */
class Update_Global_Settings extends Ability_Base {
	/**
	 * Constructor.
	 *
	 * @since 1.7.5
	 */
	public function __construct() {
		$this->id          = 'surerank/update-global-settings';
		$this->label       = __( 'Update SureRank Global Settings', 'surerank' );
		$this->description = __( 'Update one or more SureRank global SEO settings.', 'surerank' );
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
				'settings' => [
					'type'        => 'object',
					'description' => __( 'Key-value pairs of SureRank global settings to update.', 'surerank' ),
				],
			],
			'required'             => [ 'settings' ],
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
		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];

		if ( empty( $settings ) ) {
			return new \WP_Error(
				'surerank_missing_settings_payload',
				__( 'A settings payload is required.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		$allowed_keys = array_keys( Defaults::get_instance()->get_global_defaults() );
		$settings     = array_intersect_key( $settings, array_flip( $allowed_keys ) );

		if ( empty( $settings ) ) {
			return new \WP_Error(
				'surerank_no_valid_global_keys',
				__( 'No valid SureRank global setting keys were provided.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		/**
		 * Mirror the REST sanitization layer, which the direct save path does not apply.
		 *
		 * @var array<string, mixed> $settings
		 */
		$settings = Sanitize::array_deep( [ Sanitize::class, 'sanitize_with_placeholders' ], $settings );

		$result = Admin::save_admin_settings( $settings );

		return [
			'success' => (bool) $result['success'],
			'message' => (string) $result['message'],
		];
	}
}
