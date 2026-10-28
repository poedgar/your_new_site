<?php
/**
 * Get Item Fields Ability
 *
 * @since 1.3.1
 *
 * @package SureContact\Abilities\Integrations
 */

namespace SureContact\Abilities\Integrations;

use SureContact\Abilities\Abstract_Ability;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get_Item_Field class
 *
 * Gets available fields for a specific integration item (form, product, etc.)
 * for building field_mapping when creating automation rules.
 *
 * @since 1.3.1
 */
class Get_Item_Field extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/get-item-field';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Get Item Fields', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Get available fields for a specific integration item (e.g., form fields for a WPForms form, product fields for a WooCommerce product). Returns the source fields that can be mapped to CRM contact fields in a rule.

RETURNS:
- fields: Array of available fields for this item (these are the KEYs/source side of field_mapping)
- config_fields: Additional config fields for the rule (list/tag selectors, etc.)

WORKFLOW — use this to build the field_mapping for surecontact/create-rule:
1. surecontact/list-integration-item → find the item_id (form ID, product ID)
2. THIS TOOL → get available source fields for that item
3. surecontact/get-contact-field → get available CRM target fields
4. Build field_mapping: {"source_field_id": "crm_field_key", ...}
5. surecontact/create-rule → create the rule with the field_mapping';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'slug', 'item_id' ],
			'properties' => [
				'slug'    => [
					'type'        => 'string',
					'description' => 'Integration slug (e.g., "wpforms", "sureforms", "gravity-forms", "woocommerce"). Get this from surecontact/list-integration.',
				],
				'item_id' => [
					'type'        => 'string',
					'description' => 'Item ID (e.g., form ID "123", product ID). Get this from surecontact/list-integration-item.',
				],
				'event'   => [
					'type'        => 'string',
					'description' => 'Event name (e.g., "submission", "purchase"). Optional — used for event-specific config fields. Get available events from surecontact/get-integration.',
				],
			],
		];
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

		$slug    = isset( $args['slug'] ) ? $args['slug'] : '';
		$item_id = isset( $args['item_id'] ) ? (string) $args['item_id'] : '';
		$event   = isset( $args['event'] ) ? $args['event'] : null;

		$unavailable = $this->integration_unavailable_error( $slug );
		if ( null !== $unavailable ) {
			return $unavailable;
		}

		$params = [
			'slug'    => $slug,
			'item_id' => $item_id,
		];

		if ( ! is_null( $event ) ) {
			$params['event'] = $event;
		}

		$response = $this->rest_dispatch( 'GET', '/surecontact/v1/integration-rules/fields', $params );

		if ( $response->is_error() ) {
			return $this->error_from_response( $response, __( 'The required plugin must be installed and active. Use surecontact/list-integration to check.', 'surecontact' ) );
		}

		$data          = $response->get_data();
		$fields        = isset( $data['fields'] ) ? $data['fields'] : [];
		$config_fields = isset( $data['config_fields'] ) ? $data['config_fields'] : [];

		return $this->success(
			sprintf(
				/* translators: 1: number of fields, 2: item ID, 3: integration slug */
				_n( 'Found %1$d field for item %2$s in "%3$s" integration.', 'Found %1$d fields for item %2$s in "%3$s" integration.', count( $fields ), 'surecontact' ),
				count( $fields ),
				$item_id,
				$slug
			),
			[
				'fields'        => $fields,
				'config_fields' => $config_fields,
			]
		);
	}
}
