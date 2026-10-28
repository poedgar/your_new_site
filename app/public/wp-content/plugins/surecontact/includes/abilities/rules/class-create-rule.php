<?php
/**
 * Create Automation Rule Ability
 *
 * @since 1.3.1
 *
 * @package SureContact\Abilities\Rules
 */

namespace SureContact\Abilities\Rules;

use SureContact\Abilities\Abstract_Ability;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create_Rule class
 *
 * Creates or updates a SureContact automation rule.
 *
 * @since 1.3.1
 */
class Create_Rule extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/create-rule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Create Automation Rule', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Create or update an automation rule in SureContact. Rules define what happens when events occur on specific items — for example, "when WPForms form 123 is submitted, map fields to CRM, add contact to Newsletter list, and tag as Lead".

Rules can be created directly as long as the integration plugin is installed and active. Creating a rule with status=1 (default) automatically activates the integration.

CONFIG STRUCTURE (the "config" object):
- field_mapping: Maps source fields to CRM fields → {"form_field_id": "crm_field_key", ...}
- add_lists: Lists to add contact to → [{"uuid": "...", "name": "Newsletter"}]
- add_tags: Tags to apply → [{"uuid": "...", "name": "Lead"}]
- remove_lists: Lists to remove contact from → [{"uuid": "...", "name": "..."}]
- remove_tags: Tags to remove → [{"uuid": "...", "name": "..."}]

COMPLETE WORKFLOW to create a rule:
1. surecontact/list-integration → find the integration slug and confirm it is available
2. surecontact/get-integration → get supported events and item_types
3. surecontact/list-integration-item → find the item_id (form ID, product ID)
4. surecontact/get-item-field → get source field IDs for field_mapping
5. surecontact/get-contact-field → get CRM field keys for field_mapping targets
6. surecontact/get-list → get list UUIDs for add_lists / remove_lists
7. surecontact/get-tag → get tag UUIDs for add_tags / remove_tags
8. THIS TOOL → create the rule with all the collected data';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'slug', 'item_id', 'item_type', 'config' ],
			'properties' => [
				'slug'      => [
					'type'        => 'string',
					'description' => 'Integration slug. Get this from surecontact/list-integration. Examples: "wpforms", "woocommerce", "sureforms", "surecart", "gravity-forms", "contact-form-7".',
				],
				'item_id'   => [
					'type'        => 'string',
					'description' => 'Item ID as a string (e.g., "123", or "all" for all items of this type). Must be a string even for numeric IDs. Get this from surecontact/list-integration-item.',
				],
				'item_type' => [
					'type'        => 'string',
					'description' => 'Item type (e.g., "form", "product", "coupon"). Get available types from surecontact/get-integration.',
				],
				'event'     => [
					'type'        => 'string',
					'description' => 'Event trigger name (e.g., "submission", "purchase"). Get available events from surecontact/get-integration.',
				],
				'config'    => [
					'type'        => 'object',
					'description' => 'Rule configuration. Keys: field_mapping (source→CRM field map), add_lists (list objects with uuid+name), add_tags (tag objects with uuid+name), remove_lists, remove_tags. Get field IDs from surecontact/get-item-field, CRM fields from surecontact/get-contact-field, list/tag UUIDs from surecontact/get-list and surecontact/get-tag.',
				],
				'status'    => [
					'type'        => 'integer',
					'description' => 'Rule status: 1 = enabled (default), 0 = disabled.',
					'default'     => 1,
				],
				'metadata'  => [
					'type'        => 'object',
					'description' => 'Optional metadata object for additional context.',
				],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_annotations(): array {
		return [
			'priority'        => 0.8,
			'readOnlyHint'    => false,
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

		$slug      = isset( $args['slug'] ) ? $args['slug'] : '';
		$item_id   = isset( $args['item_id'] ) ? (string) $args['item_id'] : '';
		$item_type = isset( $args['item_type'] ) ? $args['item_type'] : '';
		$event     = isset( $args['event'] ) ? $args['event'] : null;
		$config    = isset( $args['config'] ) ? $args['config'] : null;
		$status    = isset( $args['status'] ) ? absint( $args['status'] ) : 1;
		$metadata  = ( isset( $args['metadata'] ) && is_array( $args['metadata'] ) ) ? $args['metadata'] : null;

		$unavailable = $this->integration_unavailable_error( $slug );
		if ( null !== $unavailable ) {
			return $unavailable;
		}

		if ( empty( $item_id ) || empty( $item_type ) ) {
			return $this->error(
				__( 'item_id and item_type are required.', 'surecontact' ),
				__( 'Use surecontact/list-integration-item to find item IDs and types. Use "all" as item_id for a global item rule.', 'surecontact' )
			);
		}

		if ( ! is_array( $config ) ) {
			return $this->error( __( 'Config must be an object.', 'surecontact' ) );
		}

		$params = [
			'slug'      => $slug,
			'item_id'   => $item_id,
			'item_type' => $item_type,
			'config'    => $config,
			'status'    => $status,
		];

		if ( ! is_null( $event ) ) {
			$params['event'] = $event;
		}

		if ( ! is_null( $metadata ) ) {
			$params['metadata'] = $metadata;
		}

		$response = $this->rest_dispatch( 'POST', '/surecontact/v1/integration-rules/config/save', $params );

		if ( $response->is_error() ) {
			return $this->error_from_response( $response, __( 'Use surecontact/get-integration to see valid item types and events, and surecontact/list-integration-item for valid item IDs.', 'surecontact' ) );
		}

		$data = $response->get_data();

		return $this->success(
			__( 'Integration rule saved successfully.', 'surecontact' ),
			[
				'slug'      => $slug,
				'item_id'   => $item_id,
				'item_type' => $item_type,
				'event'     => $event,
				'config'    => isset( $data['config'] ) ? $data['config'] : $config,
				'status'    => $status,
			]
		);
	}
}
