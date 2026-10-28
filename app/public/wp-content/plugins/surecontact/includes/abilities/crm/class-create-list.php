<?php
/**
 * Create List Ability
 *
 * @since 1.3.1
 *
 * @package SureContact\Abilities\Crm
 */

namespace SureContact\Abilities\Crm;

use SureContact\Abilities\Abstract_Ability;
use SureContact\API\Lists_Tags_API;
use SureContact\Synced_Metadata;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create_List class
 *
 * Creates a new list in the SureContact CRM and updates the local cache.
 *
 * @since 1.3.1
 */
class Create_List extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/create-list';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Create List', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Create a new list in SureContact. Lists organize and segment contacts — for example "Newsletter Subscribers", "Customers", "Leads". The created list is immediately available for use in automation rules via surecontact/create-rule.

BEFORE CREATING: Check existing lists with surecontact/get-list to avoid duplicates.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'name' ],
			'properties' => [
				'name' => [
					'type'        => 'string',
					'description' => 'Name of the list to create. Use a descriptive name like "Newsletter Subscribers", "Customers", "Leads", "VIP Members".',
				],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_annotations(): array {
		return [
			'priority'        => 0.5,
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => true,
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

		$name = isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : '';

		if ( empty( $name ) ) {
			return $this->error( __( 'List name is required.', 'surecontact' ) );
		}

		$api    = new Lists_Tags_API();
		$result = $api->create_list( [ 'name' => $name ] );

		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		if ( is_array( $result ) && ! empty( $result['queued'] ) ) {
			return $this->success(
				__( 'List creation request queued for background processing. The CRM API is temporarily unavailable — the list will be created automatically when connectivity is restored.', 'surecontact' ),
				[ 'queued' => true ]
			);
		}

		$created_list = isset( $result['data'] ) ? $result['data'] : $result;

		if ( empty( $created_list['uuid'] ) ) {
			return $this->error( __( 'Invalid response from SureContact API.', 'surecontact' ) );
		}

		$cached_lists   = Synced_Metadata::get_lists();
		$cached_lists[] = [
			'uuid' => $created_list['uuid'],
			'name' => $created_list['name'],
		];
		Synced_Metadata::set_lists( $cached_lists );

		return $this->success(
			sprintf(
				/* translators: %s: list name */
				__( 'List "%s" created successfully.', 'surecontact' ),
				$created_list['name']
			),
			[
				'uuid' => $created_list['uuid'],
				'name' => $created_list['name'],
			]
		);
	}
}
