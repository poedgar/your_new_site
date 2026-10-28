<?php
/**
 * Get All Lists Ability
 *
 * @since 1.3.1
 *
 * @package SureContact\Abilities\Crm
 */

namespace SureContact\Abilities\Crm;

use SureContact\Abilities\Abstract_Ability;
use SureContact\Synced_Metadata;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get_List class
 *
 * Retrieves all lists from the local SureContact cache.
 *
 * @since 1.3.1
 */
class Get_List extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/get-list';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Get All Lists', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Get all lists from SureContact. Lists are used to organize and segment contacts — for example "Newsletter Subscribers", "Customers", "Leads", "VIP Members".

RETURNS: Array of lists, each with uuid and name.

WORKFLOW:
- Use this before surecontact/create-rule to find list UUIDs for the add_lists and remove_lists config
- If empty, run surecontact/import-metadata to sync from the CRM
- To create a new list, use surecontact/create-list';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'search' => [
					'type'        => 'string',
					'description' => 'Optional search term to filter lists by name (case-insensitive partial match).',
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

		$search = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
		$lists  = Synced_Metadata::get_lists();

		if ( empty( $lists ) ) {
			return $this->success(
				__( 'No lists found. Run surecontact/import-metadata to sync from CRM, or create one with surecontact/create-list.', 'surecontact' ),
				[
					'lists' => [],
					'total' => 0,
				]
			);
		}

		if ( ! empty( $search ) ) {
			$lists = array_values(
				array_filter(
					$lists,
					function ( $item ) use ( $search ) {
						return isset( $item['name'] ) && stripos( $item['name'], $search ) !== false;
					}
				)
			);
		}

		return $this->success(
			sprintf(
				/* translators: %d: number of lists found */
				_n( 'Found %d list.', 'Found %d lists.', count( $lists ), 'surecontact' ),
				count( $lists )
			),
			[
				'lists' => $lists,
				'total' => count( $lists ),
			]
		);
	}
}
