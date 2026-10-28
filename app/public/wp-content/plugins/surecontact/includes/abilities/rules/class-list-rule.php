<?php
/**
 * List All Automation Rules Ability
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
 * List_Rule class
 *
 * Lists all configured SureContact automation rules.
 *
 * @since 1.3.1
 */
class List_Rule extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/list-rule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'List All Automation Rules', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'List all configured SureContact automation rules. Each rule defines what happens when events occur on specific items (e.g., "when WPForms form 123 is submitted → add to Newsletter list and tag as Lead").

RETURNS for each rule: integration slug, item_id, item_type, item_title, event, config (field_mapping, lists, tags), and status.

NOTE: This shows item-level automation rules only. For global integration settings, use surecontact/get-integration.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug' => [
					'type'        => 'string',
					'description' => 'Filter rules by integration slug (e.g., "wpforms", "woocommerce"). Optional — returns all rules if omitted.',
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

		$filter_slug = isset( $args['slug'] ) ? $args['slug'] : null;

		// When filtering by a specific slug, gate on that integration's availability.
		if ( ! empty( $filter_slug ) ) {
			$unavailable = $this->integration_unavailable_error( $filter_slug );
			if ( null !== $unavailable ) {
				return $unavailable;
			}
		}

		$response = $this->rest_dispatch( 'GET', '/surecontact/v1/integration-rules/configured-integrations' );

		if ( $response->is_error() ) {
			return $this->error_from_response( $response );
		}

		$data  = $response->get_data();
		$items = isset( $data['configured_integrations'] ) ? $data['configured_integrations'] : [];

		// Apply slug filter if specified.
		if ( ! empty( $filter_slug ) ) {
			$items = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $filter_slug ) {
						return ( isset( $item['slug'] ) ? $item['slug'] : '' ) === $filter_slug;
					}
				)
			);
		} elseif ( class_exists( 'SureContact\Integrations_Loader' ) ) {
			// Without a slug filter, silently exclude rules for inactive integrations.
			$loader = \SureContact::get_instance()->integrations_loader;
			if ( null === $loader ) {
				return $items;
			}
			$unavailable_slugs = [];

			foreach ( $items as $item ) {
				$item_slug = isset( $item['slug'] ) ? $item['slug'] : '';
				if ( ! isset( $unavailable_slugs[ $item_slug ] ) ) {
					$config                          = $loader->get_integration_config( $item_slug );
					$unavailable_slugs[ $item_slug ] = ( null !== $config && empty( $config['available'] ) );
				}
			}

			$items = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $unavailable_slugs ) {
						$s = isset( $item['slug'] ) ? $item['slug'] : '';
						return empty( $unavailable_slugs[ $s ] );
					}
				)
			);
		}

		$rules = [];
		foreach ( $items as $item ) {
			$item_id = isset( $item['item_id'] ) ? $item['item_id'] : null;

			$rules[] = [
				'id'               => isset( $item['id'] ) ? $item['id'] : null,
				'slug'             => isset( $item['slug'] ) ? $item['slug'] : '',
				'integration_name' => isset( $item['integration_name'] ) ? $item['integration_name'] : ( isset( $item['slug'] ) ? $item['slug'] : '' ),
				'item_id'          => $item_id,
				'item_type'        => isset( $item['item_type'] ) ? $item['item_type'] : null,
				'item_title'       => isset( $item['item_title'] ) ? $item['item_title'] : null,
				'event'            => isset( $item['event'] ) ? $item['event'] : null,
				'event_label'      => isset( $item['event_label'] ) ? $item['event_label'] : ( isset( $item['event'] ) ? $item['event'] : null ),
				'config'           => isset( $item['config'] ) ? $item['config'] : [],
				'metadata'         => isset( $item['metadata'] ) ? $item['metadata'] : null,
				'status'           => (int) ( isset( $item['status'] ) ? $item['status'] : 1 ),
				'is_global_item'   => 'all' === $item_id,
				'created_at'       => isset( $item['created_at'] ) ? $item['created_at'] : null,
				'updated_at'       => isset( $item['updated_at'] ) ? $item['updated_at'] : null,
			];
		}

		return $this->success(
			sprintf(
				/* translators: %d: number of rules */
				_n( 'Found %d integration rule.', 'Found %d integration rules.', count( $rules ), 'surecontact' ),
				count( $rules )
			),
			[ 'rules' => $rules ]
		);
	}
}
