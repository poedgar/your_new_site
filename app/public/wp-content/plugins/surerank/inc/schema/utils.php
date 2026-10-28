<?php
/**
 * Utils
 *
 * This file handles functionality for all Utils.
 *
 * @package SureRank
 * @since 1.0.0
 */

namespace SureRank\Inc\Schema;

use SureRank\Inc\Schema\Types\Article;
use SureRank\Inc\Schema\Types\BreadcrumbList;
use SureRank\Inc\Schema\Types\Organization;
use SureRank\Inc\Schema\Types\Person;
use SureRank\Inc\Schema\Types\Search_Action;
use SureRank\Inc\Schema\Types\WebPage;
use SureRank\Inc\Schema\Types\WebSite;
use SureRank\Inc\Traits\Get_Instance;

/**
 * Class Utils
 *
 * Provides utility functions for handling schema data.
 *
 * @package SureRank\Inc\Schema
 * @since 1.0.0
 */
class Utils {
	use Get_Instance;

	/**
	 * Get Default Schemas
	 *
	 * Retrieves default schema data with unique IDs as keys.
	 *
	 * @return array<string, mixed> Default schema data with unique IDs as keys.
	 * @since 1.0.0
	 */
	public static function get_default_schemas() {
		/*
		 * Guard against re-entrancy. The surerank_schema_types and
		 * surerank_default_schemas filters call Helper::woocommerce_enabled(),
		 * which reads Settings::get(). When the settings cache is cold, that call
		 * rebuilds the global defaults — which calls this method again, recursing
		 * until memory is exhausted (only on sites with WooCommerce active, since
		 * woocommerce_enabled() short-circuits before Settings::get() otherwise).
		 *
		 * While a build is already in progress, return an empty set: the
		 * re-entrant caller only needs a scalar setting, not the schemas, and the
		 * outer (first) call still produces the complete, filtered schema list.
		 */
		static $building = false;
		if ( $building ) {
			return [];
		}

		$building = true;
		try {
			return self::build_final_schemas( apply_filters( 'surerank_default_schemas', self::build_schemas_from_types( self::get_default_schema_types() ) ) );
		} finally {
			$building = false;
		}
	}

	/**
	 * Get Default Schema Options
	 *
	 * Retrieves all schema data (including Pro schemas) with unique IDs as keys.
	 *
	 * @return array<string, mixed> Schema data with unique IDs as keys.
	 * @since 1.0.0
	 */
	public static function get_default_schema_options() {
		return self::build_final_schemas( self::build_schemas_from_types( self::get_schema_types() ) );
	}

	/**
	 * Build final schemas array with unique IDs as keys.
	 *
	 * Takes an array of schemas and assigns each a unique UUID key for
	 * identification in the frontend and database storage.
	 *
	 * @param array<int|string, mixed> $schemas Array of schema data.
	 * @return array<string, mixed> Schema data with unique IDs as keys.
	 * @since 1.0.0
	 */
	public static function build_final_schemas( $schemas ) {
		$result = [];
		foreach ( $schemas as $key => $schema ) {
			$title                = is_array( $schema ) && isset( $schema['title'] ) && is_scalar( $schema['title'] ) ? (string) $schema['title'] : (string) $key;
			$unique_id            = self::generate_stable_id( $title, $result );
			$result[ $unique_id ] = $schema;
		}

		return $result;
	}

	/**
	 * Build schemas array from schema type classes.
	 *
	 * Iterates through schema type class mappings, instantiates each class,
	 * and calls their schema_data() method to generate schema configurations.
	 *
	 * @param array<string, string> $schema_types Schema type class mappings (type => class).
	 * @return array<int, mixed> Array of schema data from each type class.
	 * @since 1.0.0
	 */
	public static function build_schemas_from_types( $schema_types ) {
		$schemas = [];
		foreach ( $schema_types as $key => $class ) {
			$instance  = $class::get_instance();
			$schemas[] = $instance->schema_data();
		}
		return $schemas;
	}

	/**
	 * Get Schema Type Data
	 *
	 * Retrieves schema type data.
	 *
	 * @return array<string, mixed> Schema type data.
	 */
	public static function get_schema_type_data() {
		$schema_data = [];
		foreach ( self::get_default_schema_types() as $key => $class ) {
			$instance            = $class::get_instance();
			$schema_data[ $key ] = $instance->get();
		}

		return apply_filters( 'surerank_schema_type_data', $schema_data );
	}

	/**
	 * Parse Schema Fields
	 *
	 * Parses schema fields recursively to include only required data.
	 *
	 * @param array<string, mixed>|array<int, array<string, mixed>> $fields Schema fields.
	 * @return array<string, mixed> Parsed fields.
	 */
	public static function parse_fields( array $fields ) {
		$parsed = [];
		foreach ( $fields as $field ) {
			if ( empty( $field['id'] ) || ( empty( $field['required'] ) && empty( $field['show'] ) ) ) {
				continue;
			}

			$type = $field['type'] ?? 'Text';
			if ( $type === 'Group' && isset( $field['fields'] ) ) {
				$default_type = null;
				foreach ( $field['fields'] as $subfield ) {
					if ( isset( $subfield['id'] ) && $subfield['id'] === '@type' && isset( $subfield['std'] ) ) {
						$default_type = $subfield['std'];
						break;
					}
				}

				if ( $default_type ) {
					$filtered_subfields = [];
					foreach ( $field['fields'] as $subfield ) {
						/**
						 * If the field is not a main field, or the main field is the default type, add it to the filtered subfields
						 */
						if ( ! isset( $subfield['main'] ) || $subfield['main'] === $default_type ) {
							$filtered_subfields[] = $subfield;
						}
					}
					$field['fields'] = $filtered_subfields;
				}

				$value = self::parse_group( $field );
			} else {
				$value = $field['std'] ?? '';
			}

			if ( ! empty( $value ) ) {
				$parsed[ $field['id'] ] = $value;
			}
		}
		return $parsed;
	}

	/**
	 * Get Schema Type Options
	 *
	 * @return array<string, mixed> Schema type options.
	 */
	public static function get_schema_type_options() {
		return [
			'WebPage'      => WebPage::get_instance()->get_schema_type_options(),
			'Article'      => Article::get_instance()->get_schema_type_options(),
			'Organization' => Organization::get_instance()->get_schema_type_options(),
		];
	}

	/**
	 * Schema Types
	 *
	 * List of schema type classes.
	 *
	 * @return array<string, mixed> Schema type class mappings.
	 */
	public static function get_schema_types() {
		return apply_filters(
			'surerank_schema_types',
			self::get_default_schema_types()
		);
	}

	/**
	 * Get Default Schema Types
	 *
	 * @return array<string, mixed> Default schema type class mappings.
	 */
	public static function get_default_schema_types() {
		return [
			'WebSite'        => WebSite::class,
			'WebPage'        => WebPage::class,
			'Organization'   => Organization::class,
			'BreadcrumbList' => BreadcrumbList::class,
			'Article'        => Article::class,
			'SearchAction'   => Search_Action::class,
			'Person'         => Person::class,
		];
	}

	/**
	 * Generate a deterministic key for a default schema.
	 *
	 * Default schema keys must be stable across requests: page-level meta
	 * stores overrides and exclusions by key, and on installs where the
	 * settings option was never persisted these defaults are rebuilt on every
	 * request — random keys there could never match stored meta.
	 *
	 * @param string               $seed     Schema title (or positional key) to derive the ID from.
	 * @param array<string, mixed> $existing Already-assigned IDs, to suffix duplicate titles.
	 * @return string The deterministic ID.
	 * @since 1.10.1
	 */
	private static function generate_stable_id( string $seed, array $existing ): string {
		$base = 'srdef-' . substr( md5( 'surerank-default-schema-' . strtolower( trim( $seed ) ) ), 0, 16 );

		$unique_id = $base;
		$suffix    = 2;
		while ( isset( $existing[ $unique_id ] ) ) {
			$unique_id = $base . '-' . $suffix;
			$suffix++;
		}

		return $unique_id;
	}

	/**
	 * Parse Group Fields
	 *
	 * Parses group fields recursively.
	 *
	 * @param array<string, mixed>|array<int, array<string, mixed>> $field Group field data.
	 * @return array<string, mixed>|array<int, array<string, mixed>> Parsed group fields.
	 */
	private static function parse_group( array $field ) {
		$group_fields = self::parse_fields( $field['fields'] );
		if ( 1 === count( $group_fields ) && isset( $group_fields['@type'] ) ) { // Yoda condition.
			return [];
		}

		if ( ! empty( $field['cloneable'] ) ) {
			return [ $group_fields ];
		}

		return $group_fields;
	}
}
