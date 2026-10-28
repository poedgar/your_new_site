<?php
/**
 * Lists and Tags API Client
 *
 * Handles list and tag-specific operations using the SaaS Client.
 * This class provides a semantic API for list and tag operations
 * and delegates HTTP communication to SaaS_Client.
 *
 * @since 0.0.1
 *
 * @package SureContact\API
 */

namespace SureContact\API;

use SureContact\SaaS_Client;
use SureContact\Traits\API_Retry;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lists_Tags_API
 *
 * Handles all list and tag-related API operations with the external SaaS API.
 * Provides semantic methods for list and tag operations and delegates
 * HTTP communication to SaaS_Client.
 *
 * @since 0.0.1
 */
class Lists_Tags_API {

	use API_Retry;

	/**
	 * SaaS Client instance
	 *
	 * @since 0.0.1
	 *
	 * @var SaaS_Client
	 */
	private $saas_client;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 *
	 * @param SaaS_Client|null $saas_client Optional. SaaS client instance.
	 */
	public function __construct( ?SaaS_Client $saas_client = null ) {
		$this->saas_client = $saas_client ? $saas_client : new SaaS_Client();
	}

	/**
	 * Get all lists from SureContact
	 *
	 * @since 0.0.1
	 *
	 * @param array $args Query arguments.
	 * @return array|WP_Error Response array with lists data or WP_Error on failure.
	 */
	public function get_lists( $args = array() ) {
		$defaults = array(
			'page'       => 1,
			'per_page'   => 100,
			'search'     => '',
			'sort_by'    => 'name',
			'sort_order' => 'asc',
			'list_name'  => '',
			'type'       => 'static',
		);

		$args = wp_parse_args( $args, $defaults );

		// Build query string.
		$query_params = array();
		foreach ( $args as $key => $value ) {
			if ( ! empty( $value ) || 0 === $value ) {
				$query_params[ $key ] = $value;
			}
		}

		$endpoint = 'lists';
		if ( ! empty( $query_params ) ) {
			$endpoint .= '?' . http_build_query( $query_params );
		}

		return $this->saas_client->get( $endpoint );
	}

	/**
	 * Get a specific list by UUID
	 *
	 * @since 0.0.1
	 *
	 * @param array $args Query arguments.
	 * @return array|WP_Error Response array with tags data or WP_Error on failure.
	 */
	public function get_tags( $args = array() ) {
		$defaults = array(
			'page'       => 1,
			'per_page'   => 100,
			'search'     => '',
			'sort_by'    => 'name',
			'sort_order' => 'asc',
			'tag_name'   => '',
		);

		$args = wp_parse_args( $args, $defaults );

		// Build query string.
		$query_params = array();
		foreach ( $args as $key => $value ) {
			if ( ! empty( $value ) || 0 === $value ) {
				$query_params[ $key ] = $value;
			}
		}

		$endpoint = 'tags';
		if ( ! empty( $query_params ) ) {
			$endpoint .= '?' . http_build_query( $query_params );
		}

		return $this->saas_client->get( $endpoint );
	}

	/**
	 * Create a new list in SureContact (private, used internally by sync_metadata)
	 *
	 * @since 0.0.1
	 *
	 * @param array $data    List data.
	 * @param array $options Optional. Additional options including 'source'.
	 * @return array|WP_Error Response array with created list data or WP_Error on failure.
	 */
	public function create_list( $data, $options = array() ) {
		$defaults = array(
			'name'        => '',
			'description' => '',
		);

		$data = wp_parse_args( $data, $defaults );

		if ( empty( $data['name'] ) ) {
			return new WP_Error(
				'missing_list_name',
				__( 'List name is required.', 'surecontact' )
			);
		}

		// Execute with automatic retry logic.
		return $this->execute_with_retry(
			function () use ( $data ) {
				return $this->saas_client->post( 'lists', $data );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'lists',
				'payload'      => $data,
				'operation'    => 'create_list',
			),
			$options
		);
	}

	/**
	 * Create a new tag in SureContact
	 *
	 * @since 0.0.1
	 *
	 * @param array $data {
	 *     Tag data.
	 *
	 *     @type string $name Tag name (required).
	 * }
	 * @param array $options Optional. Additional options including 'source'.
	 * @return array|WP_Error Response array with created tag data or WP_Error on failure.
	 */
	public function create_tag( $data, $options = array() ) {
		$defaults = array(
			'name' => '',
		);

		$data = wp_parse_args( $data, $defaults );

		if ( empty( $data['name'] ) ) {
			return new WP_Error(
				'missing_tag_name',
				__( 'Tag name is required.', 'surecontact' )
			);
		}

		// Execute with automatic retry logic.
		return $this->execute_with_retry(
			function () use ( $data ) {
				return $this->saas_client->post( 'tags', $data );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'tags',
				'payload'      => $data,
				'operation'    => 'create_tag',
			),
			$options
		);
	}

	/**
	 * Search for lists by name
	 *
	 * @since 0.0.1
	 *
	 * @param string $name Search query for list name.
	 * @return array|WP_Error Response array with search results or WP_Error on failure.
	 */
	public function search_lists( $name ) {
		return $this->get_lists(
			array(
				'list_name' => $name,
				'per_page'  => 10,
			)
		);
	}

	/**
	 * Search for tags by name
	 *
	 * @since 0.0.1
	 *
	 * @param string $name Search query for tag name.
	 * @return array|WP_Error Response array with search results or WP_Error on failure.
	 */
	public function search_tags( $name ) {
		return $this->get_tags(
			array(
				'tag_name' => $name,
				'per_page' => 10,
			)
		);
	}

	/**
	 * Sync metadata items (lists or tags) in batch
	 *
	 * Searches for existing items by name. If found, maps to existing UUID.
	 * If not found, creates new item in SureContact and maps to new UUID.
	 *
	 * @since 0.0.1
	 *
	 * @param array  $items Array of items with 'id', 'name', and optional 'description'.
	 * @param string $type  Type of metadata: 'list' or 'tag'.
	 * @return array Mapping array of external_id => crm_uuid.
	 */
	public function sync_metadata( $items, $type = 'list' ) {
		$mappings = array();

		if ( empty( $items ) || ! is_array( $items ) ) {
			return $mappings;
		}

		// Validate type.
		if ( ! in_array( $type, array( 'list', 'tag' ), true ) ) {
			return $mappings;
		}

		$search_method = 'search_' . $type . 's';
		$create_method = 'create_' . $type;

		foreach ( $items as $item ) {
			// Skip items without required data.
			if ( empty( $item['id'] ) || empty( $item['name'] ) ) {
				continue;
			}

			$external_id = 'fc_' . $item['id'];
			$name        = sanitize_text_field( $item['name'] );

			// Search for existing item by name.
			$existing = $this->$search_method( $name );

			if ( ! is_wp_error( $existing ) && ! empty( $existing['data'] ) ) {
				// Match found - use first result.
				$first_match = $existing['data'][0];
				if ( ! empty( $first_match['uuid'] ) ) {
					$mappings[ $external_id ] = $first_match['uuid'];
					continue;
				}
			}

			// No match found - create new item.
			$create_data = array(
				'name' => $name,
			);

			// Add description for lists.
			if ( 'list' === $type && ! empty( $item['description'] ) ) {
				$create_data['description'] = sanitize_textarea_field( $item['description'] );
			}

			$created = $this->$create_method( $create_data );

			if ( ! is_wp_error( $created ) && ! empty( $created['data']['uuid'] ) ) {
				$mappings[ $external_id ] = $created['data']['uuid'];
			}
		}

		return $mappings;
	}
}
