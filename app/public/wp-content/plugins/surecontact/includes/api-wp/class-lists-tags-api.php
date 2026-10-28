<?php
/**
 * Lists and Tags REST API Controller
 *
 * WordPress REST API endpoints for lists and tags.
 * This class provides WordPress REST API endpoints and delegates
 * SureContact API calls to the Lists_Tags_API client in the api folder.
 *
 * @since 0.0.1
 *
 * @package SureContact\API_WP
 */

namespace SureContact\API_WP;

use WP_REST_Server;
use WP_Error;
use WP_REST_Request;
use SureContact\API\Lists_Tags_API as Lists_Tags_Client;
use SureContact\Synced_Metadata;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists_Tags_API class
 *
 * WordPress REST API controller for lists and tags.
 * Provides REST API endpoints and uses Lists_Tags_Client for SureContact API calls.
 *
 * @since 0.0.1
 */
class Lists_Tags_API extends Api_Base {

	/**
	 * Instance
	 *
	 * @since 0.0.1
	 *
	 * @var Lists_Tags_API
	 */
	private static $instance = null;

	/**
	 * Lists and Tags API Client
	 *
	 * @since 0.0.1
	 *
	 * @var Lists_Tags_Client
	 */
	private $lists_tags_client;

	/**
	 * Get instance
	 *
	 * @since 0.0.1
	 *
	 * @return Lists_Tags_API
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	private function __construct() {
		parent::__construct();
		$this->lists_tags_client = new Lists_Tags_Client();
	}

	/**
	 * Register routes
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function register_routes() {
		$namespace = $this->get_api_namespace();

		$search_arg = array(
			'search' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Optional search term to filter results.', 'surecontact' ),
			),
		);

		// Get all lists from cache or create a new list.
		register_rest_route(
			$namespace,
			'/crm/lists',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_lists' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => $search_arg,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_list' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'name' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'The name of the list to create.', 'surecontact' ),
						),
					),
				),
			)
		);

		// Get all tags from cache or create a new tag.
		register_rest_route(
			$namespace,
			'/crm/tags',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tags' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => $search_arg,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_tag' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'name' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'The name of the tag to create.', 'surecontact' ),
						),
					),
				),
			)
		);

		// Sync lists and tags to cache.
		register_rest_route(
			$namespace,
			'/crm/sync-lists-tags',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sync_lists_and_tags' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);
	}

	/**
	 * Get lists from WordPress cache
	 *
	 * This endpoint returns cached lists from WordPress options
	 * to avoid API calls to SaaS server on every request.
	 * Supports search filtering.
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error Response data or error.
	 */
	public function get_lists( $request ) {
		// Get query parameters.
		$search = $request->get_param( 'search' ) ? sanitize_text_field( $request->get_param( 'search' ) ) : '';

		// Get cached lists from WordPress options.
		$lists_data = Synced_Metadata::get_lists();

		// If cache is empty, return empty array with success.
		if ( empty( $lists_data ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(),
					'message' => __( 'No cached lists found. Please sync lists and tags.', 'surecontact' ),
				)
			);
		}

		// Apply search filter if provided.
		if ( ! empty( $search ) ) {
			$lists_data = array_filter(
				$lists_data,
				function ( $list_item ) use ( $search ) {
					return isset( $list_item['name'] ) && stripos( $list_item['name'], $search ) !== false;
				}
			);
			// Re-index array after filtering.
			$lists_data = array_values( $lists_data );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $lists_data,
			)
		);
	}

	/**
	 * Get tags from WordPress cache
	 *
	 * This endpoint returns cached tags from WordPress options
	 * to avoid API calls to SaaS server on every request.
	 * Supports search filtering.
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error Response data or error.
	 */
	public function get_tags( $request ) {
		// Get query parameters.
		$search = $request->get_param( 'search' ) ? sanitize_text_field( $request->get_param( 'search' ) ) : '';

		// Get cached tags from WordPress options.
		$tags_data = Synced_Metadata::get_tags();

		// If cache is empty, return empty array with success.
		if ( empty( $tags_data ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(),
					'message' => __( 'No cached tags found. Please sync lists and tags.', 'surecontact' ),
				)
			);
		}

		// Apply search filter if provided.
		if ( ! empty( $search ) ) {
			$tags_data = array_filter(
				$tags_data,
				function ( $tag ) use ( $search ) {
					return isset( $tag['name'] ) && stripos( $tag['name'], $search ) !== false;
				}
			);
			// Re-index array after filtering.
			$tags_data = array_values( $tags_data );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $tags_data,
			)
		);
	}

	/**
	 * Manually sync lists and tags to cache
	 *
	 * Fetches all pages of lists and tags from SaaS API and caches them locally.
	 * Handles pagination to ensure all items are synced regardless of total count.
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error Response data or error.
	 */
	public function sync_lists_and_tags( $request ) {
		// Fetch all lists with pagination.
		$all_lists = $this->fetch_all_pages( 'lists' );

		// Fetch all tags with pagination.
		$all_tags = $this->fetch_all_pages( 'tags' );

		$lists_synced = false;
		$tags_synced  = false;

		// Cache lists if successful (even if empty array).
		if ( ! is_wp_error( $all_lists ) && is_array( $all_lists ) ) {
			$this->cache_lists( $all_lists );
			$lists_synced = true;
		}

		// Cache tags if successful (even if empty array).
		if ( ! is_wp_error( $all_tags ) && is_array( $all_tags ) ) {
			$this->cache_tags( $all_tags );
			$tags_synced = true;
		}

		// Determine overall success.
		if ( ! $lists_synced && ! $tags_synced ) {
			return new WP_Error(
				'sync_failed',
				__( 'Failed to sync lists and tags from CRM.', 'surecontact' ),
				array( 'status' => 500 )
			);
		}

		$message = array();
		if ( $lists_synced ) {
			$list_count = count( $all_lists );
			$message[]  = sprintf(
				/* translators: %d: number of lists */
				_n( '%d list synced', '%d lists synced', $list_count, 'surecontact' ),
				$list_count
			);
		}

		if ( $tags_synced ) {
			$tag_count = count( $all_tags );
			$message[] = sprintf(
				/* translators: %d: number of tags */
				_n( '%d tag synced', '%d tags synced', $tag_count, 'surecontact' ),
				$tag_count
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => implode( ', ', $message ),
				'data'    => array(
					'lists_count' => $lists_synced && is_array( $all_lists ) ? count( $all_lists ) : 0,
					'tags_count'  => $tags_synced && is_array( $all_tags ) ? count( $all_tags ) : 0,
				),
			)
		);
	}

	/**
	 * Fetch all pages of lists or tags from SaaS API
	 *
	 * Handles pagination automatically by fetching pages until reaching last_page.
	 * Based on Laravel pagination response structure with meta.last_page.
	 *
	 * @since 0.0.2
	 *
	 * @param string $type Type of data to fetch ('lists' or 'tags').
	 * @return array|WP_Error Array of all items or WP_Error on failure.
	 */
	private function fetch_all_pages( $type ) {
		$all_items = array();
		$page      = 1;
		$per_page  = 100;
		$last_page = 1;

		do {
			// Fetch page based on type.
			if ( 'lists' === $type ) {
				$response = $this->lists_tags_client->get_lists(
					array(
						'page'     => $page,
						'per_page' => $per_page,
					)
				);
			} else {
				$response = $this->lists_tags_client->get_tags(
					array(
						'page'     => $page,
						'per_page' => $per_page,
					)
				);
			}

			// Handle errors.
			if ( is_wp_error( $response ) ) {
				// If first page fails, return error. Otherwise return what we have.
				if ( 1 === $page ) {
					return $response;
				}
				break;
			}

			// Extract data from response.
			$items = isset( $response['data'] ) ? $response['data'] : array();

			// Add items to collection.
			if ( ! empty( $items ) ) {
				$all_items = array_merge( $all_items, $items );
			}

			// Get last page from meta (Laravel pagination structure).
			if ( isset( $response['meta']['last_page'] ) ) {
				$last_page = (int) $response['meta']['last_page'];
			}

			++$page;

		} while ( $page <= $last_page );

		return $all_items;
	}

	/**
	 * Cache lists in WordPress options
	 *
	 * @since 0.0.1
	 *
	 * @param array $lists_data Lists data from API.
	 * @return void
	 */
	private function cache_lists( $lists_data ) {
		if ( ! is_array( $lists_data ) ) {
			return;
		}

		// Format lists for storage.
		$cached_lists = array();
		foreach ( $lists_data as $list ) {
			if ( isset( $list['uuid'] ) && isset( $list['name'] ) ) {
				$cached_lists[] = array(
					'uuid' => $list['uuid'],
					'name' => $list['name'],
				);
			}
		}

		// Store using consolidated metadata option (even if empty array).
		Synced_Metadata::set_lists( $cached_lists );
	}

	/**
	 * Cache tags in WordPress options
	 *
	 * @since 0.0.1
	 *
	 * @param array $tags_data Tags data from API.
	 * @return void
	 */
	private function cache_tags( $tags_data ) {
		if ( ! is_array( $tags_data ) ) {
			return;
		}

		// Format tags for storage.
		$cached_tags = array();
		foreach ( $tags_data as $tag ) {
			if ( isset( $tag['uuid'] ) && isset( $tag['name'] ) ) {
				$cached_tags[] = array(
					'uuid' => $tag['uuid'],
					'name' => $tag['name'],
				);
			}
		}

		// Store using consolidated metadata option (even if empty array).
		Synced_Metadata::set_tags( $cached_tags );
	}

	/**
	 * Create a new list in SureContact
	 *
	 * Creates a list via the SaaS API and adds it to the local cache.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error Response data or error.
	 */
	public function create_list( $request ) {
		$name = $request->get_param( 'name' );

		if ( empty( $name ) ) {
			return new WP_Error(
				'missing_name',
				__( 'List name is required.', 'surecontact' ),
				array( 'status' => 400 )
			);
		}

		// Create the list via SaaS API.
		$result = $this->lists_tags_client->create_list(
			array(
				'name' => $name,
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'create_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Extract the created list data.
		$created_list = isset( $result['data'] ) ? $result['data'] : $result;

		if ( empty( $created_list['uuid'] ) ) {
			return new WP_Error(
				'invalid_response',
				__( 'Invalid response from SureContact API.', 'surecontact' ),
				array( 'status' => 500 )
			);
		}

		// Add to local cache.
		$cached_lists   = Synced_Metadata::get_lists();
		$cached_lists[] = array(
			'uuid' => $created_list['uuid'],
			'name' => $created_list['name'],
		);
		Synced_Metadata::set_lists( $cached_lists );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'uuid' => $created_list['uuid'],
					'name' => $created_list['name'],
				),
				'message' => __( 'List created successfully.', 'surecontact' ),
			)
		);
	}

	/**
	 * Create a new tag in SureContact
	 *
	 * Creates a tag via the SaaS API and adds it to the local cache.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error Response data or error.
	 */
	public function create_tag( $request ) {
		$name = $request->get_param( 'name' );

		if ( empty( $name ) ) {
			return new WP_Error(
				'missing_name',
				__( 'Tag name is required.', 'surecontact' ),
				array( 'status' => 400 )
			);
		}

		// Create the tag via SaaS API.
		$result = $this->lists_tags_client->create_tag(
			array(
				'name' => $name,
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'create_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Extract the created tag data.
		$created_tag = isset( $result['data'] ) ? $result['data'] : $result;

		if ( empty( $created_tag['uuid'] ) ) {
			return new WP_Error(
				'invalid_response',
				__( 'Invalid response from SureContact API.', 'surecontact' ),
				array( 'status' => 500 )
			);
		}

		// Add to local cache.
		$cached_tags   = Synced_Metadata::get_tags();
		$cached_tags[] = array(
			'uuid' => $created_tag['uuid'],
			'name' => $created_tag['name'],
		);
		Synced_Metadata::set_tags( $cached_tags );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'uuid' => $created_tag['uuid'],
					'name' => $created_tag['name'],
				),
				'message' => __( 'Tag created successfully.', 'surecontact' ),
			)
		);
	}
}
