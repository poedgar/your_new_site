<?php
/**
 * Get Automation Rule Ability
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
 * Get_Rule class
 *
 * Gets a specific SureContact integration rule's configuration.
 *
 * @since 1.3.1
 */
class Get_Rule extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/get-rule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Get Automation Rule', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Get a specific SureContact automation rule configuration. Returns the field mappings, lists, tags, and status for a given integration, item, and event combination.

RETURNS: config (field_mapping, add_lists, add_tags, etc.), status, event, and metadata.

USE: To check an existing rule before updating it via surecontact/create-rule, or to verify what automation is configured for a specific form/product.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'slug', 'item_id', 'item_type' ],
			'properties' => [
				'slug'      => [
					'type'        => 'string',
					'description' => 'Integration slug (e.g., "wpforms", "woocommerce").',
				],
				'item_id'   => [
					'type'        => 'string',
					'description' => 'Item ID (e.g., form ID "123", product ID, or "all" for the global item rule).',
				],
				'item_type' => [
					'type'        => 'string',
					'description' => 'Item type (e.g., "form", "product", "coupon").',
				],
				'event'     => [
					'type'        => 'string',
					'description' => 'Event name (e.g., "submission", "purchase"). Required if the rule was created with an event.',
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

		$slug      = isset( $args['slug'] ) ? $args['slug'] : '';
		$item_id   = isset( $args['item_id'] ) ? (string) $args['item_id'] : '';
		$item_type = isset( $args['item_type'] ) ? $args['item_type'] : '';
		$event     = isset( $args['event'] ) ? $args['event'] : null;

		$unavailable = $this->integration_unavailable_error( $slug );
		if ( null !== $unavailable ) {
			return $unavailable;
		}

		if ( empty( $item_id ) || empty( $item_type ) ) {
			return $this->error(
				__( 'item_id and item_type are required.', 'surecontact' ),
				__( 'Use surecontact/list-rule to see existing rules, or surecontact/list-integration-item to find item IDs.', 'surecontact' )
			);
		}

		// Resolve event from existing rules when not explicitly provided.
		if ( is_null( $event ) ) {
			$event = $this->resolve_event( $slug, $item_id, $item_type );
		}

		$params = [
			'item_id'   => $item_id,
			'item_type' => $item_type,
		];

		if ( ! is_null( $event ) ) {
			$params['event'] = $event;
		}

		$response = $this->rest_dispatch( 'GET', '/surecontact/v1/integration-rules/config/' . $slug, $params );

		if ( $response->is_error() ) {
			return $this->error_from_response( $response, __( 'Use surecontact/list-rule to see existing rules.', 'surecontact' ) );
		}

		$data = $response->get_data();

		if ( empty( $data['exists'] ) ) {
			return $this->success(
				__( 'No rule configuration found for this combination.', 'surecontact' ),
				[
					'config'   => [],
					'exists'   => false,
					'metadata' => null,
				]
			);
		}

		return $this->success(
			__( 'Rule configuration retrieved successfully.', 'surecontact' ),
			[
				'config'   => isset( $data['config'] ) ? $data['config'] : [],
				'status'   => isset( $data['status'] ) ? $data['status'] : 1,
				'event'    => isset( $data['event'] ) ? $data['event'] : null,
				'exists'   => true,
				'metadata' => isset( $data['metadata'] ) ? $data['metadata'] : null,
			]
		);
	}
}
