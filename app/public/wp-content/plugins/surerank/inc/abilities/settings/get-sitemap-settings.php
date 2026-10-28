<?php
/**
 * Get sitemap settings ability.
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
 * Retrieves SureRank sitemap-related global settings.
 */
class Get_Sitemap_Settings extends Ability_Base {
	/**
	 * Sitemap setting keys exposed by this ability.
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
		$this->id          = 'surerank/get-sitemap-settings';
		$this->label       = __( 'Get SureRank Sitemap Settings', 'surerank' );
		$this->description = __( 'Retrieve SureRank XML sitemap settings, including sitemap toggles and excluded post types/taxonomies.', 'surerank' );
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
				'enable_xml_sitemap'          => [ 'type' => 'boolean' ],
				'enable_xml_image_sitemap'    => [ 'type' => 'boolean' ],
				'sitemap_excluded_post_types' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'sitemap_excluded_taxonomies' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
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
		$settings = Settings::get();
		$result   = [];

		foreach ( self::SITEMAP_KEYS as $key ) {
			$value = is_array( $settings ) && array_key_exists( $key, $settings ) ? $settings[ $key ] : null;

			if ( in_array( $key, [ 'enable_xml_sitemap', 'enable_xml_image_sitemap' ], true ) ) {
				$result[ $key ] = (bool) $value;
				continue;
			}

			$result[ $key ] = is_array( $value ) ? array_values( array_map( 'sanitize_key', $value ) ) : [];
		}

		return $result;
	}
}
