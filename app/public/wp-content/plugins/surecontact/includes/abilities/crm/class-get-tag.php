<?php
/**
 * Get All Tags Ability
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
 * Get_Tag class
 *
 * Retrieves all tags from the local SureContact cache.
 *
 * @since 1.3.1
 */
class Get_Tag extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/get-tag';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Get All Tags', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Get all tags from SureContact. Tags are labels applied to contacts for categorization — for example "Lead", "Customer", "VIP", "Churned", "High Value".

RETURNS: Array of tags, each with uuid and name.

WORKFLOW:
- Use this before surecontact/create-rule to find tag UUIDs for the add_tags and remove_tags config
- If empty, run surecontact/import-metadata to sync from the CRM
- To create a new tag, use surecontact/create-tag';
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
					'description' => 'Optional search term to filter tags by name (case-insensitive partial match).',
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
		$tags   = Synced_Metadata::get_tags();

		if ( empty( $tags ) ) {
			return $this->success(
				__( 'No tags found. Run surecontact/import-metadata to sync from CRM, or create one with surecontact/create-tag.', 'surecontact' ),
				[
					'tags'  => [],
					'total' => 0,
				]
			);
		}

		if ( ! empty( $search ) ) {
			$tags = array_values(
				array_filter(
					$tags,
					function ( $tag ) use ( $search ) {
						return isset( $tag['name'] ) && stripos( $tag['name'], $search ) !== false;
					}
				)
			);
		}

		return $this->success(
			sprintf(
				/* translators: %d: number of tags found */
				_n( 'Found %d tag.', 'Found %d tags.', count( $tags ), 'surecontact' ),
				count( $tags )
			),
			[
				'tags'  => $tags,
				'total' => count( $tags ),
			]
		);
	}
}
