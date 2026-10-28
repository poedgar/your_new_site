<?php
/**
 * Create Contact Field Ability
 *
 * @since 1.3.1
 *
 * @package SureContact\Abilities\Fields
 */

namespace SureContact\Abilities\Fields;

use SureContact\Abilities\Abstract_Ability;
use SureContact\API\Fields_API;
use SureContact\Field_Mapper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create_Contact_Field class
 *
 * Creates a custom contact field in the SureContact CRM.
 *
 * @since 1.3.1
 */
class Create_Contact_Field extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/create-contact-field';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Create Custom Contact Field', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Create a custom contact field in SureContact CRM. Custom fields extend the default contact schema with additional data points (e.g., "Customer ID", "Subscription Plan", "Company Size"). After creation, the field becomes available for use in rule field_mapping via surecontact/create-rule.

SUPPORTED TYPES: text, number, date, timestamp, email, url, phone, select, multi_select, checkbox, textarea.

BEFORE CREATING: Check existing fields with surecontact/get-contact-field to see what is already available — primary fields like email, first_name, last_name, phone are built-in.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'name', 'label', 'field_type' ],
			'properties' => [
				'name'        => [
					'type'        => 'string',
					'description' => 'Field key/name (e.g., "customer_id", "subscription_plan"). Must be unique, lowercase with underscores.',
				],
				'label'       => [
					'type'        => 'string',
					'description' => 'Human-readable label (e.g., "Customer ID", "Subscription Plan").',
				],
				'field_type'  => [
					'type'        => 'string',
					'description' => 'Field data type.',
					'enum'        => [ 'text', 'number', 'date', 'timestamp', 'email', 'url', 'phone', 'select', 'multi_select', 'checkbox', 'textarea' ],
				],
				'options'     => [
					'type'        => 'array',
					'description' => 'Options for select/multi_select fields. Required for select and multi_select types.',
					'items'       => [ 'type' => 'string' ],
				],
				'is_required' => [
					'type'        => 'boolean',
					'description' => 'Whether the field is required. Default: false.',
					'default'     => false,
				],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_annotations(): array {
		return [
			'priority'        => 0.5,
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => true,
		];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Input arguments.
	 */
	public function execute( array $args = [] ) {
		if ( ! $this->is_connected() ) {
			return $this->connection_error();
		}

		$name       = isset( $args['name'] ) ? sanitize_key( $args['name'] ) : '';
		$label      = isset( $args['label'] ) ? sanitize_text_field( $args['label'] ) : '';
		$field_type = isset( $args['field_type'] ) ? sanitize_key( $args['field_type'] ) : '';

		if ( empty( $name ) || empty( $label ) || empty( $field_type ) ) {
			return $this->error( __( 'name, label, and field_type are all required.', 'surecontact' ) );
		}

		$valid_types = [ 'text', 'number', 'date', 'timestamp', 'email', 'url', 'phone', 'select', 'multi_select', 'checkbox', 'textarea' ];

		if ( ! in_array( $field_type, $valid_types, true ) ) {
			return $this->error(
				sprintf(
					/* translators: %s: comma-separated list of valid types */
					__( 'Invalid field_type. Must be one of: %s', 'surecontact' ),
					implode( ', ', $valid_types )
				)
			);
		}

		$field_data = [
			'name'  => $name,
			'label' => $label,
			'type'  => $field_type,
		];

		if ( isset( $args['options'] ) && is_array( $args['options'] ) ) {
			$field_data['options'] = array_map( 'sanitize_text_field', $args['options'] );
		}

		if ( isset( $args['is_required'] ) ) {
			$field_data['is_required'] = (bool) $args['is_required'];
		}

		$fields_api = new Fields_API();
		$result     = $fields_api->sync_custom_field( $field_data );

		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		// Refresh local CRM fields cache.
		$field_mapper = new Field_Mapper();
		$field_mapper->sync_crm_fields();

		return $this->success(
			sprintf(
				/* translators: %s: field label */
				__( 'Custom field "%s" created successfully.', 'surecontact' ),
				$label
			),
			[
				'value'      => $name,
				'label'      => $label,
				'field_type' => $field_type,
			]
		);
	}
}
