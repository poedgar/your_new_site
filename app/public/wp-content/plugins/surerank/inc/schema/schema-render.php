<?php
/**
 * Schema_Render
 *
 * This file will handle functionality for rendering schema data with placeholders and merging it with a specific schema type.
 *
 * @package surerank
 * @since 1.0.0
 */

namespace SureRank\Inc\Schema;

/**
 * Class Schema_Render
 *
 * Responsible for rendering schema data with placeholders and merging it with a specific schema type.
 */
class Schema_Render {
	/**
	 * Feature marker: this version understands cloneable Group definitions
	 * carrying a `legacyScalarField` migration hint (editor shim + render
	 * support). Add-ons can check this constant with defined() before
	 * registering such definitions, so older core versions never receive them.
	 *
	 * @since 1.10.0
	 */
	public const SUPPORTS_LEGACY_SCALAR_GROUP = true;

	/**
	 * The schema type (e.g., Article, Product, etc.).
	 *
	 * @var string
	 */
	private $type;

	/**
	 * The fields or data associated with the schema.
	 *
	 * @var array<string, mixed>
	 */
	private $fields;

	/**
	 * The variable renderer for processing dynamic placeholders.
	 *
	 * @var Render
	 */
	private $variable_renderer;

	/**
	 * The schema registry key used to resolve field definitions.
	 *
	 * @var string
	 */
	private $registry_key;

	/**
	 * Cached field definitions for the current schema type.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private $field_definitions = null;

	/**
	 * Schema_Render constructor.
	 *
	 * @param string               $type              The schema type.
	 * @param array<string, mixed> $fields            The fields to render.
	 * @param Render               $variable_renderer The renderer for processing placeholders.
	 * @param string               $registry_key      The key the schema is registered under (e.g. LocalBusiness). May differ from $type when a sub-type is selected.
	 */
	public function __construct( string $type, array $fields, Render $variable_renderer, string $registry_key = '' ) {
		$this->type              = $type;
		$this->fields            = $fields;
		$this->variable_renderer = $variable_renderer;
		$this->registry_key      = $registry_key;
	}

	/**
	 * Render the schema data with resolved placeholders.
	 *
	 * @return array<string, mixed> The rendered schema data merged with the schema type.
	 */
	public function render() {
		foreach ( $this->fields as &$value ) {
			$this->variable_renderer->render( $value );
		}

		if ( isset( $this->fields['sameAs'] ) && is_array( $this->fields['sameAs'] ) ) {
			$this->fields['sameAs'] = array_values( $this->fields['sameAs'] );
		}

		$this->resolve_reference_nodes();
		$this->normalize_datetime_fields();
		$this->flatten_cloneable_fields();

		$final_type = $this->type;
		/**
		 * Combine @type and @sub_type if @sub_type is set.
		 */
		if ( isset( $this->fields['@sub_type'] ) && is_array( $this->fields['@sub_type'] ) && ! empty( $this->fields['@sub_type'] ) ) {
			$sub_types = array_filter( $this->fields['@sub_type'] );
			if ( ! empty( $sub_types ) ) {
				$final_type = array_merge( [ $this->type ], $sub_types );
			}
			unset( $this->fields['@sub_type'] );
		}

		$this->fields['@type'] = $final_type;
		$schema                = array_merge( [ '@type' => $final_type ], $this->fields );

		/**
		 * The top level keeps its string keys (@type is always present),
		 * so reindexing never applies to it.
		 *
		 * @var array<string, mixed> $schema
		 */
		$schema = $this->remove_empty( $this->remove_schema_name( $schema ) );

		return $schema;
	}

	/**
	 * Normalize DateTime fields to ISO 8601 output before rendering.
	 *
	 * @return void
	 */
	private function normalize_datetime_fields() {
		$field_definitions = $this->get_schema_field_definitions();

		if ( empty( $field_definitions ) ) {
			return;
		}

		$this->fields = $this->normalize_fields_by_definition( $this->fields, $field_definitions );
	}

	/**
	 * Get field definitions for the current schema type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_schema_field_definitions() {
		if ( null !== $this->field_definitions ) {
			return $this->field_definitions;
		}

		$schema_types = Utils::get_schema_types();
		$schema_class = $schema_types[ $this->registry_key ] ?? $schema_types[ $this->type ] ?? null;

		if ( ! $schema_class || ! class_exists( $schema_class ) ) {
			$this->field_definitions = [];
			return $this->field_definitions;
		}

		$this->field_definitions = $schema_class::get_instance()->get();

		return $this->field_definitions;
	}

	/**
	 * Normalize fields using their schema definitions.
	 *
	 * @param array<string, mixed>             $values            Current schema field values.
	 * @param array<int, array<string, mixed>> $field_definitions Schema field definitions.
	 * @return array<string, mixed>
	 */
	private function normalize_fields_by_definition( array $values, array $field_definitions ) {
		foreach ( $field_definitions as $field ) {
			$field_id = $field['id'] ?? '';

			if ( '' === $field_id || ! array_key_exists( $field_id, $values ) ) {
				continue;
			}

			$field_type = $field['type'] ?? 'Text';

			if ( 'DateTime' === $field_type ) {
				if ( is_array( $values[ $field_id ] ) ) {
					foreach ( $values[ $field_id ] as $uuid => $date_value ) {
						$values[ $field_id ][ $uuid ] = $this->normalize_datetime_value( $date_value );
					}
				} else {
					$values[ $field_id ] = $this->normalize_datetime_value( $values[ $field_id ] );
				}
				continue;
			}

			if ( 'Group' !== $field_type || empty( $field['fields'] ) || ! is_array( $values[ $field_id ] ) ) {
				continue;
			}

			if ( ! empty( $field['cloneable'] ) ) {
				foreach ( $values[ $field_id ] as $index => $group_item ) {
					if ( is_array( $group_item ) ) {
						$values[ $field_id ][ $index ] = $this->normalize_fields_by_definition( $group_item, $field['fields'] );
					}
				}
				continue;
			}

			$values[ $field_id ] = $this->normalize_fields_by_definition( $values[ $field_id ], $field['fields'] );
		}

		return $values;
	}

	/**
	 * Normalize a date-time string to ISO 8601 when possible.
	 *
	 * @param mixed $value Date-time value to normalize.
	 * @return mixed
	 */
	private function normalize_datetime_value( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$value = trim( $value );

		if ( '' === $value || str_contains( $value, '%' ) ) {
			return $value;
		}

		try {
			return ( new \DateTimeImmutable( $value, wp_timezone() ) )->format( DATE_ATOM );
		} catch ( \Exception $exception ) {
			return $value;
		}
	}

	/**
	 * Remove empty or null values from an array recursively.
	 *
	 * Nested nodes left with only a @type (no actual data) are removed, and
	 * numerically keyed lists are re-indexed so they encode as JSON arrays.
	 *
	 * @param array<string, mixed>|array<int, mixed> $data The array to filter.
	 * @return array<string, mixed>|array<int, mixed> Filtered array with non-empty values.
	 */
	private function remove_empty( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->remove_empty( $value );
				if ( empty( $data[ $key ] ) || $this->is_type_only( $data[ $key ] ) ) {
					unset( $data[ $key ] );
				}
			} elseif ( empty( $value ) && 0 !== $value ) {
				unset( $data[ $key ] );
			}
		}
		return $this->reindex_list( $data );
	}

	/**
	 * Collapse group nodes onto schema references.
	 *
	 * A %schemas.*% variable resolves to an @id reference object. When a user
	 * inserts one into a plain text subfield of a group (e.g. the Name field),
	 * the reference stands for the whole node, so the containing node is
	 * replaced by the reference instead of nesting an object under a text key.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	private function resolve_reference_nodes() {
		$field_definitions = $this->get_schema_field_definitions();

		if ( empty( $field_definitions ) ) {
			return;
		}

		foreach ( $field_definitions as $field ) {
			$field_id = $field['id'] ?? '';

			if ( '' === $field_id || 'Group' !== ( $field['type'] ?? 'Text' ) || empty( $field['fields'] ) || ! isset( $this->fields[ $field_id ] ) || ! is_array( $this->fields[ $field_id ] ) ) {
				continue;
			}

			if ( ! empty( $field['cloneable'] ) ) {
				foreach ( $this->fields[ $field_id ] as $index => $group_item ) {
					if ( is_array( $group_item ) ) {
						$this->fields[ $field_id ][ $index ] = $this->collapse_reference_node( $group_item, $field['fields'] );
					}
				}
				continue;
			}

			$this->fields[ $field_id ] = $this->collapse_reference_node( $this->fields[ $field_id ], $field['fields'] );
		}
	}

	/**
	 * Collapse a single group node onto a schema reference held by one of its
	 * plain (non-group) subfields, recursing into nested groups first.
	 *
	 * @param array<string, mixed>             $node  Group node values.
	 * @param array<int, array<string, mixed>> $definitions Subfield definitions of the group.
	 * @return array<string, mixed> The reference when found, otherwise the node.
	 * @since 1.10.0
	 */
	private function collapse_reference_node( array $node, array $definitions ) {
		foreach ( $definitions as $definition ) {
			$sub_id = $definition['id'] ?? '';

			if ( '' === $sub_id || ! isset( $node[ $sub_id ] ) || ! is_array( $node[ $sub_id ] ) ) {
				continue;
			}

			if ( 'Group' === ( $definition['type'] ?? 'Text' ) && ! empty( $definition['fields'] ) ) {
				if ( ! empty( $definition['cloneable'] ) ) {
					foreach ( $node[ $sub_id ] as $index => $group_item ) {
						if ( is_array( $group_item ) ) {
							$node[ $sub_id ][ $index ] = $this->collapse_reference_node( $group_item, $definition['fields'] );
						}
					}
				} else {
					$node[ $sub_id ] = $this->collapse_reference_node( $node[ $sub_id ], $definition['fields'] );
				}
				continue;
			}

			if ( $this->is_reference( $node[ $sub_id ] ) ) {
				return $node[ $sub_id ];
			}
		}

		return $node;
	}

	/**
	 * Check whether a value is a schema reference ({"@id": ...} only).
	 *
	 * @param array<string, mixed>|array<int, mixed> $data Value to check.
	 * @return bool True when @id is the only key.
	 * @since 1.10.0
	 */
	private function is_reference( array $data ) {
		return 1 === count( $data ) && isset( $data['@id'] );
	}

	/**
	 * Check whether a rendered node holds only a @type and no actual data.
	 *
	 * @param array<string, mixed>|array<int, mixed> $data Node to check.
	 * @return bool True when @type is the only remaining key.
	 * @since 1.10.0
	 */
	private function is_type_only( array $data ) {
		return 1 === count( $data ) && isset( $data['@type'] );
	}

	/**
	 * Re-index a numerically keyed list so gaps left by removed items do not
	 * make wp_json_encode emit an object ({"0":...,"2":...}) instead of an array.
	 *
	 * @param array<string, mixed>|array<int, mixed> $data Array to normalize.
	 * @return array<string, mixed>|array<int, mixed> Re-indexed list, or the array unchanged when it has string keys.
	 * @since 1.10.0
	 */
	private function reindex_list( array $data ) {
		if ( [] === $data ) {
			return $data;
		}

		foreach ( array_keys( $data ) as $key ) {
			if ( ! is_int( $key ) ) {
				return $data;
			}
		}

		return array_values( $data );
	}

	/**
	 * Flatten cloneable fields that have flatten=true.
	 *
	 * Converts UUID-based objects to simple arrays for fields marked with flatten=true.
	 *
	 * @return void
	 */
	private function flatten_cloneable_fields() {
		$field_definitions = $this->get_schema_field_definitions();

		if ( empty( $field_definitions ) ) {
			return;
		}

		foreach ( $field_definitions as $field ) {
			if ( ! empty( $field['cloneable'] ) && ! empty( $field['flatten'] ) && isset( $field['id'] ) ) {
				$field_id = $field['id'];

				if ( isset( $this->fields[ $field_id ] ) && is_array( $this->fields[ $field_id ] ) ) {
					$this->fields[ $field_id ] = array_values( $this->fields[ $field_id ] );
				}
			}
		}
	}

	/**
	 * Remove the schema name and metadata fields from the array.
	 *
	 * @param array<string, mixed> $schema The schema to remove the name from.
	 * @return array<string, mixed> The schema with the name and metadata removed.
	 */
	private function remove_schema_name( array $schema ) {
		if ( isset( $schema['schema_name'] ) ) {
			unset( $schema['schema_name'] );
		}

		// Remove custom fields metadata (used for tracking custom fields in admin).
		if ( isset( $schema['_customFields'] ) ) {
			unset( $schema['_customFields'] );
		}

		return $schema;
	}
}
