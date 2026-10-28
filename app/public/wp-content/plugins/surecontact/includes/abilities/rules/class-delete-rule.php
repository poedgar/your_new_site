<?php
/**
 * Delete Automation Rule Ability
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
 * Delete_Rule class
 *
 * Permanently deletes a SureContact automation rule.
 *
 * @since 1.3.1
 */
class Delete_Rule extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/delete-rule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Delete Automation Rule', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Permanently delete a SureContact automation rule. Removes the rule for the specified integration, item, and event combination.

IMPORTANT: Always confirm with the user before running this — it permanently removes the rule and cannot be undone.

ALTERNATIVE: To temporarily stop a rule without deleting it, use surecontact/update-rule-status to disable it instead.';
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
					'description' => 'Item ID (e.g., form ID "123", or "all" for the global item rule).',
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
			'priority'        => 0.3,
			'readOnlyHint'    => false,
			'destructiveHint' => true,
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
				__( 'item_id and item_type are required to delete a rule.', 'surecontact' ),
				__( 'Use surecontact/list-rule to find the rule you want to delete. To manage global integration settings, use surecontact/update-integration-status instead.', 'surecontact' )
			);
		}

		// Resolve event from existing rules when not explicitly provided.
		if ( is_null( $event ) ) {
			$event = $this->resolve_event( $slug, $item_id, $item_type );
		}

		$params = [
			'slug'      => $slug,
			'item_id'   => $item_id,
			'item_type' => $item_type,
		];

		if ( ! is_null( $event ) ) {
			$params['event'] = $event;
		}

		$response = $this->rest_dispatch( 'POST', '/surecontact/v1/integration-rules/config/delete', $params );

		if ( $response->is_error() ) {
			return $this->error_from_response( $response, __( 'Use surecontact/list-rule to see existing rules.', 'surecontact' ) );
		}

		return $this->success(
			__( 'Integration rule deleted successfully.', 'surecontact' ),
			[
				'slug'      => $slug,
				'item_id'   => $item_id,
				'item_type' => $item_type,
				'event'     => $event,
			]
		);
	}
}
