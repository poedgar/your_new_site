<?php
/**
 * Update Rule Status Ability
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
 * Update_Rule_Status class
 *
 * Enables or disables a specific SureContact automation rule.
 *
 * @since 1.3.1
 */
class Update_Rule_Status extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/update-rule-status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Enable/Disable Rule', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Enable or disable a specific SureContact automation rule. This controls whether a particular form, product, or item processes events through SureContact. When disabled, the rule stops triggering but is not deleted.

NOTE: This is for individual rules. To toggle the entire integration on/off, use surecontact/update-integration-status instead.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'slug', 'item_id', 'item_type', 'status' ],
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
				'status'    => [
					'type'        => 'integer',
					'description' => 'New status: 1 = enabled, 0 = disabled.',
				],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_annotations(): array {
		return [
			'priority'        => 0.6,
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
		$status    = isset( $args['status'] ) ? absint( $args['status'] ) : 0;

		$unavailable = $this->integration_unavailable_error( $slug );
		if ( null !== $unavailable ) {
			return $unavailable;
		}

		if ( empty( $item_id ) || empty( $item_type ) ) {
			return $this->error(
				__( 'item_id and item_type are required to update a rule status.', 'surecontact' ),
				__( 'Use surecontact/list-rule to find the rule. To toggle global integration status, use surecontact/update-integration-status instead.', 'surecontact' )
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
			'status'    => $status,
		];

		if ( ! is_null( $event ) ) {
			$params['event'] = $event;
		}

		$response = $this->rest_dispatch( 'POST', '/surecontact/v1/integration-rules/config/update-status', $params );

		if ( $response->is_error() ) {
			return $this->error_from_response( $response, __( 'Use surecontact/list-rule to see existing rules.', 'surecontact' ) );
		}

		$status_text = $status ? __( 'enabled', 'surecontact' ) : __( 'disabled', 'surecontact' );

		return $this->success(
			sprintf(
				/* translators: %s: enabled/disabled */
				__( 'Rule %s successfully.', 'surecontact' ),
				$status_text
			),
			[
				'slug'      => $slug,
				'item_id'   => $item_id,
				'item_type' => $item_type,
				'event'     => $event,
				'status'    => $status,
			]
		);
	}
}
