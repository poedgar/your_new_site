<?php
/**
 * List Integration Items Ability
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
 * List_Integration_Item class
 *
 * Lists items (forms, products, etc.) for a specific SureContact integration.
 *
 * @since 1.3.1
 */
class List_Integration_Item extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/list-integration-item';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'List Integration Items', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'List available items (forms, products, coupons, etc.) for a specific SureContact integration. Returns the items that can have automation rules attached to them.

RETURNS: Array of items with their IDs and names.

WORKFLOW — use this to find item IDs for automation rules:
1. surecontact/list-integration → find the integration slug
2. THIS TOOL → get available items and their IDs
3. surecontact/get-item-field → get fields for a specific item
4. surecontact/create-rule → create rule using the item_id';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'slug' ],
			'properties' => [
				'slug' => [
					'type'        => 'string',
					'description' => 'Integration slug. Get this from surecontact/list-integration. Examples: "wpforms", "woocommerce", "sureforms", "surecart", "gravity-forms", "contact-form-7".',
				],
				'type' => [
					'type'        => 'string',
					'description' => 'Item type filter (e.g., "form", "product", "coupon"). Optional — omit to get all item types. Get available types from surecontact/get-integration.',
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

		$slug = isset( $args['slug'] ) ? $args['slug'] : '';
		$type = isset( $args['type'] ) ? $args['type'] : null;

		$unavailable = $this->integration_unavailable_error( $slug );
		if ( null !== $unavailable ) {
			return $unavailable;
		}

		$params = [];
		if ( ! is_null( $type ) ) {
			$params['type'] = $type;
		}

		$response = $this->rest_dispatch( 'GET', '/surecontact/v1/integration-rules/items/' . $slug, $params );

		if ( $response->is_error() ) {
			return $this->error_from_response( $response, __( 'The required plugin must be installed and active. Use surecontact/list-integration to check.', 'surecontact' ) );
		}

		$data  = $response->get_data();
		$items = isset( $data['items'] ) ? $data['items'] : [];

		// Rename keys to match create-rule input schema so the AI can map them directly.
		$formatted_items = [];
		foreach ( $items as $item ) {
			$formatted_items[] = [
				'item_id'   => isset( $item['id'] ) ? (string) $item['id'] : '',
				'title'     => isset( $item['title'] ) ? $item['title'] : '',
				'item_type' => isset( $item['type'] ) ? $item['type'] : '',
			];
		}

		return $this->success(
			sprintf(
				/* translators: 1: number of items, 2: integration slug */
				_n( 'Found %1$d item for "%2$s" integration.', 'Found %1$d items for "%2$s" integration.', count( $formatted_items ), 'surecontact' ),
				count( $formatted_items ),
				$slug
			),
			[ 'items' => $formatted_items ]
		);
	}
}
