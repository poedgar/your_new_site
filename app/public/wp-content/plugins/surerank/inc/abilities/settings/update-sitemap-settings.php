<?php
/**
 * Update sitemap settings ability.
 *
 * @package SureRank\Inc\Abilities\Settings
 */

namespace SureRank\Inc\Abilities\Settings;

use SureRank\Inc\Abilities\Ability_Base;
use SureRank\Inc\API\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updates SureRank sitemap-related settings.
 */
class Update_Sitemap_Settings extends Ability_Base {
	/**
	 * Allowed sitemap keys for update.
	 */
	private const SITEMAP_KEYS = [
		'enable_xml_sitemap',
		'enable_xml_image_sitemap',
		'sitemap_excluded_post_types',
		'sitemap_excluded_taxonomies',
	];

	/**
	 * Constructor.
	 *
	 * @since 1.7.5
	 */
	public function __construct() {
		$this->id          = 'surerank/update-sitemap-settings';
		$this->label       = __( 'Update SureRank Sitemap Settings', 'surerank' );
		$this->description = __( 'Update SureRank XML sitemap settings, including sitemap toggles and excluded post types/taxonomies.', 'surerank' );
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
					'description' => __( 'Sitemap settings to update.', 'surerank' ),
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
				'surerank_missing_sitemap_settings_payload',
				__( 'A sitemap settings payload is required.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		$filtered = array_intersect_key( $settings, array_flip( self::SITEMAP_KEYS ) );
		$filtered = $this->sanitize_sitemap_settings( $filtered );

		if ( empty( $filtered ) ) {
			return new \WP_Error(
				'surerank_no_valid_sitemap_setting_keys',
				__( 'No valid sitemap setting keys were provided.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		$result = Admin::save_admin_settings( $filtered );

		return [
			'success' => (bool) $result['success'],
			'message' => (string) $result['message'],
		];
	}

	/**
	 * Sanitize sitemap payload values.
	 *
	 * @since 1.7.5
	 * @param array<string, mixed> $settings Raw input.
	 * @return array<string, mixed>
	 */
	private function sanitize_sitemap_settings( array $settings ) {
		if ( array_key_exists( 'enable_xml_sitemap', $settings ) ) {
			$settings['enable_xml_sitemap'] = (bool) $settings['enable_xml_sitemap'];
		}

		if ( array_key_exists( 'enable_xml_image_sitemap', $settings ) ) {
			$settings['enable_xml_image_sitemap'] = (bool) $settings['enable_xml_image_sitemap'];
		}

		if ( array_key_exists( 'sitemap_excluded_post_types', $settings ) ) {
			$values = is_array( $settings['sitemap_excluded_post_types'] ) ? $settings['sitemap_excluded_post_types'] : [];

			$settings['sitemap_excluded_post_types'] = array_values( array_map( 'sanitize_key', $values ) );
		}

		if ( array_key_exists( 'sitemap_excluded_taxonomies', $settings ) ) {
			$values = is_array( $settings['sitemap_excluded_taxonomies'] ) ? $settings['sitemap_excluded_taxonomies'] : [];

			$settings['sitemap_excluded_taxonomies'] = array_values( array_map( 'sanitize_key', $values ) );
		}

		return $settings;
	}
}
