<?php
/**
 * Create Tag Ability
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
 * Create_Tag class
 *
 * Creates a new tag in the SureContact CRM and updates the local cache.
 *
 * @since 1.3.1
 */
class Create_Tag extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/create-tag';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Create Tag', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Create a new tag in SureContact. Tags are labels applied to contacts for categorization — for example "Lead", "Customer", "VIP", "Churned". The created tag is immediately available for use in automation rules via surecontact/create-rule.

BEFORE CREATING: Check existing tags with surecontact/get-tag to avoid duplicates.';
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
					'description' => 'Name of the tag to create. Use a descriptive label like "Lead", "Customer", "VIP", "Hot Lead", "Churned".',
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
			return $this->error( __( 'Tag name is required.', 'surecontact' ) );
		}

		$api    = new Lists_Tags_API();
		$result = $api->create_tag( [ 'name' => $name ] );

		if ( is_wp_error( $result ) ) {
			return $this->error_from_wp_error( $result );
		}

		if ( is_array( $result ) && ! empty( $result['queued'] ) ) {
			return $this->success(
				__( 'Tag creation request queued for background processing. The CRM API is temporarily unavailable — the tag will be created automatically when connectivity is restored.', 'surecontact' ),
				[ 'queued' => true ]
			);
		}

		$created_tag = isset( $result['data'] ) ? $result['data'] : $result;

		if ( empty( $created_tag['uuid'] ) ) {
			return $this->error( __( 'Invalid response from SureContact API.', 'surecontact' ) );
		}

		$cached_tags   = Synced_Metadata::get_tags();
		$cached_tags[] = [
			'uuid' => $created_tag['uuid'],
			'name' => $created_tag['name'],
		];
		Synced_Metadata::set_tags( $cached_tags );

		return $this->success(
			sprintf(
				/* translators: %s: tag name */
				__( 'Tag "%s" created successfully.', 'surecontact' ),
				$created_tag['name']
			),
			[
				'uuid' => $created_tag['uuid'],
				'name' => $created_tag['name'],
			]
		);
	}
}
