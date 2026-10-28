<?php
/**
 * List All Integrations Ability
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
 * List_Integration class
 *
 * Lists all available SureContact integrations with availability and status.
 *
 * @since 1.3.1
 */
class List_Integration extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/list-integration';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'List All Integrations', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'List all available SureContact integrations with their status. Shows which WordPress plugins can connect to SureContact for automatic contact syncing (form submissions, purchases, etc.).

RETURNS for each integration:
- slug: Integration identifier (use this in other surecontact tools)
- available: Whether the required plugin is installed and active
- global_enabled: Whether the integration is turned on
- rules_count: Number of automation rules configured
- item_types: What items it supports (forms, products, coupons, etc.)
- settings_fields: Global settings schema

WORKFLOW — this is usually the first step:
1. THIS TOOL → see what integrations are available
2. surecontact/update-integration-status → enable the integration
3. surecontact/get-integration → get full details (events, settings)
4. surecontact/list-integration-item → find specific items (forms, products)
5. surecontact/create-rule → set up automation';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'filter' => [
					'type'        => 'string',
					'description' => 'Filter integrations: "all" (default), "item_types" (only those supporting rules), "global_settings" (only those with global settings).',
					'enum'        => [ 'all', 'item_types', 'global_settings' ],
					'default'     => 'all',
				],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_annotations(): array {
		return [
			'priority'        => 1.0,
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

		$filter = isset( $args['filter'] ) ? $args['filter'] : 'all';

		$integrations_request = new \WP_REST_Request( 'GET', '/surecontact/v1/integration-rules/available-integrations' );
		$integrations_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		if ( 'all' !== $filter ) {
			$integrations_request->set_param( 'filter', $filter );
		}

		$integrations_response = rest_do_request( $integrations_request );

		if ( $integrations_response->is_error() ) {
			return $this->error_from_response( $integrations_response );
		}

		$integrations_data = $integrations_response->get_data();
		$integrations      = isset( $integrations_data['integrations'] ) ? $integrations_data['integrations'] : [];

		// Gather rules count per integration.
		$rules_request = new \WP_REST_Request( 'GET', '/surecontact/v1/integration-rules/configured-integrations' );
		$rules_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$rules_response = rest_do_request( $rules_request );
		$rules_count    = [];

		if ( ! $rules_response->is_error() ) {
			$rules_data = $rules_response->get_data();
			foreach ( ( isset( $rules_data['configured_integrations'] ) ? $rules_data['configured_integrations'] : [] ) as $rule ) {
				$rule_slug = isset( $rule['slug'] ) ? $rule['slug'] : '';
				if ( ! empty( $rule_slug ) ) {
					$rules_count[ $rule_slug ] = ( isset( $rules_count[ $rule_slug ] ) ? $rules_count[ $rule_slug ] : 0 ) + 1;
				}
			}
		}

		$results = [];
		foreach ( $integrations as $integration ) {
			$slug      = isset( $integration['slug'] ) ? $integration['slug'] : '';
			$results[] = [
				'slug'            => $slug,
				'name'            => isset( $integration['name'] ) ? $integration['name'] : $slug,
				'available'       => isset( $integration['available'] ) ? (bool) $integration['available'] : false,
				'global_enabled'  => isset( $integration['enabled'] ) ? (bool) $integration['enabled'] : false,
				'rules_count'     => isset( $rules_count[ $slug ] ) ? $rules_count[ $slug ] : 0,
				'item_types'      => isset( $integration['item_types'] ) ? $integration['item_types'] : [],
				'settings_fields' => isset( $integration['settings_fields'] ) ? $integration['settings_fields'] : [],
			];
		}

		return $this->success(
			sprintf(
				/* translators: %d: number of integrations */
				_n( 'Found %d integration.', 'Found %d integrations.', count( $results ), 'surecontact' ),
				count( $results )
			),
			[ 'integrations' => $results ]
		);
	}
}
