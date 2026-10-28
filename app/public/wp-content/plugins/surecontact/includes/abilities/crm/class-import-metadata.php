<?php
/**
 * Import Metadata (Sync Lists & Tags) Ability
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
 * Import_Metadata class
 *
 * Syncs all lists and tags from the SureContact CRM to the local WordPress cache.
 *
 * @since 1.3.1
 */
class Import_Metadata extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/import-metadata';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Sync Lists & Tags from CRM', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Sync all lists and tags from SureContact CRM to the local WordPress cache. Fetches the latest data from the CRM API and stores it locally so surecontact/get-list and surecontact/get-tag return up-to-date results.

WHEN TO USE:
- When surecontact/get-list or surecontact/get-tag returns empty
- After lists or tags were created directly in the SureContact dashboard (not via this AI)
- To refresh the local cache before creating automation rules

NO INPUT REQUIRED — syncs everything automatically.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_annotations(): array {
		return [
			'priority'        => 0.5,
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => true,
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

		$api = new Lists_Tags_API();

		$all_lists = $this->fetch_all_pages( $api, 'lists' );
		$all_tags  = $this->fetch_all_pages( $api, 'tags' );

		$lists_synced = false;
		$tags_synced  = false;

		if ( ! is_wp_error( $all_lists ) && is_array( $all_lists ) ) {
			$cached_lists = [];
			foreach ( $all_lists as $list ) {
				if ( isset( $list['uuid'], $list['name'] ) ) {
					$cached_lists[] = [
						'uuid' => $list['uuid'],
						'name' => $list['name'],
					];
				}
			}
			Synced_Metadata::set_lists( $cached_lists );
			$lists_synced = true;
		}

		if ( ! is_wp_error( $all_tags ) && is_array( $all_tags ) ) {
			$cached_tags = [];
			foreach ( $all_tags as $tag ) {
				if ( isset( $tag['uuid'], $tag['name'] ) ) {
					$cached_tags[] = [
						'uuid' => $tag['uuid'],
						'name' => $tag['name'],
					];
				}
			}
			Synced_Metadata::set_tags( $cached_tags );
			$tags_synced = true;
		}

		if ( ! $lists_synced && ! $tags_synced ) {
			$details = [];
			if ( is_wp_error( $all_lists ) ) {
				$details[] = 'Lists: ' . $all_lists->get_error_message();
			}
			if ( is_wp_error( $all_tags ) ) {
				$details[] = 'Tags: ' . $all_tags->get_error_message();
			}

			$message = __( 'Failed to sync lists and tags from CRM.', 'surecontact' );
			if ( ! empty( $details ) ) {
				$message .= ' ' . implode( ' ', $details );
			}

			return $this->error( $message );
		}

		$lists_count = ( $lists_synced && is_array( $all_lists ) ) ? count( $all_lists ) : 0;
		$tags_count  = ( $tags_synced && is_array( $all_tags ) ) ? count( $all_tags ) : 0;

		/* translators: %d: number of lists */
		$lists_text = sprintf( _n( '%d list', '%d lists', $lists_count, 'surecontact' ), $lists_count );
		/* translators: %d: number of tags */
		$tags_text = sprintf( _n( '%d tag', '%d tags', $tags_count, 'surecontact' ), $tags_count );

		return $this->success(
			sprintf(
				/* translators: 1: formatted list count (e.g. "3 lists"), 2: formatted tag count (e.g. "5 tags") */
				__( 'Synced %1$s and %2$s from CRM.', 'surecontact' ),
				$lists_text,
				$tags_text
			),
			[
				'lists_count' => $lists_count,
				'tags_count'  => $tags_count,
			]
		);
	}

	/**
	 * Fetch all pages of lists or tags from the SaaS API.
	 *
	 * @since 1.3.1
	 *
	 * @param Lists_Tags_API $api  The API client.
	 * @param string         $type 'lists' or 'tags'.
	 * @return array|\WP_Error All items or WP_Error on failure.
	 */
	private function fetch_all_pages( Lists_Tags_API $api, string $type ) {
		$all_items = [];
		$page      = 1;
		$per_page  = 100;
		$last_page = 1;

		do {
			$params = [
				'page'     => $page,
				'per_page' => $per_page,
			];

			$response = ( 'lists' === $type ) ? $api->get_lists( $params ) : $api->get_tags( $params );

			if ( is_wp_error( $response ) ) {
				if ( 1 === $page ) {
					return $response;
				}
				break;
			}

			$items = isset( $response['data'] ) ? $response['data'] : [];

			if ( ! empty( $items ) ) {
				$all_items = array_merge( $all_items, $items );
			}

			if ( isset( $response['meta']['last_page'] ) ) {
				$last_page = (int) $response['meta']['last_page'];
			}

			++$page;

		} while ( $page <= $last_page );

		return $all_items;
	}
}
