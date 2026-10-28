<?php
/**
 * Get Contact Fields Ability
 *
 * @since 1.3.1
 *
 * @package SureContact\Abilities\Fields
 */

namespace SureContact\Abilities\Fields;

use SureContact\Abilities\Abstract_Ability;
use SureContact\Field_Mapper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get_Contact_Field class
 *
 * Returns all available CRM contact fields (primary + custom).
 *
 * @since 1.3.1
 */
class Get_Contact_Field extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/get-contact-field';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Get Contact Fields', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Get all available CRM contact fields from SureContact. Returns both primary fields (email, first_name, last_name, phone, company, job_title, etc.) and custom fields synced from the CRM.

RETURNS: Two arrays — primary fields and custom fields. Each field has value (key used in field_mapping), label, and field_type.

WORKFLOW:
- Use this before surecontact/create-rule to know which CRM field keys are available for field_mapping in the rule config
- The field "value" is what you use as the VALUE side of field_mapping (e.g., {"form_field_id": "email"})
- To add new custom fields, use surecontact/create-contact-field';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_annotations(): array {
		return [
			'priority'        => 0.7,
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
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

		$field_mapper = new Field_Mapper();
		$crm_fields   = $field_mapper->get_available_crm_fields();
		$primary_keys = Field_Mapper::get_primary_field_keys();

		$primary_fields = [];
		$custom_fields  = [];

		foreach ( $crm_fields as $key => $config ) {
			$field_data = [
				'value'      => $key,
				'label'      => isset( $config['label'] ) ? $config['label'] : $key,
				'field_type' => isset( $config['field_type'] ) ? $config['field_type'] : 'text',
			];

			if ( in_array( $key, $primary_keys, true ) ) {
				$primary_fields[] = $field_data;
			} else {
				$custom_fields[] = $field_data;
			}
		}

		return $this->success(
			sprintf(
				/* translators: 1: number of primary fields, 2: number of custom fields */
				_n( 'Found %1$d primary and %2$d custom field.', 'Found %1$d primary and %2$d custom fields.', count( $custom_fields ), 'surecontact' ),
				count( $primary_fields ),
				count( $custom_fields )
			),
			[
				'primary' => $primary_fields,
				'custom'  => $custom_fields,
			]
		);
	}
}
