<?php
/**
 * Integration Rules API Class
 *
 * Unified REST API controller for all integration-related endpoints.
 * Handles both legacy routes (global settings) and modern rule engine-based routes
 * (item-specific and event-based configurations).
 *
 * This class consolidates functionality from the deprecated Integrations_API class
 * to provide a single source of truth for integration management.
 *
 * @since 0.0.3
 *
 * @package SureContact\API_WP
 */

namespace SureContact\API_WP;

use WP_REST_Server;
use WP_Error;
use WP_REST_Request;
use SureContact\Database\Integrations_DB;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integration_Rules_API class
 *
 * Provides REST API endpoints for:
 * - Getting integration metadata and configuration schema
 * - Fetching form/item lists for integrations
 * - Fetching form fields for mapping
 * - Saving integration configurations with rule support
 * - Deleting configurations
 * - Managing item-specific settings
 *
 * @since 0.0.3
 */
class Integration_Rules_API extends Api_Base {

	/**
	 * Instance
	 *
	 * @since 0.0.3
	 *
	 * @var Integration_Rules_API
	 */
	private static $instance = null;

	/**
	 * Cached database instance.
	 *
	 * @since 0.0.4
	 *
	 * @var Integrations_DB
	 */
	private $integrations_db;

	/**
	 * Get instance
	 *
	 * @since 0.0.3
	 *
	 * @return Integration_Rules_API
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 0.0.4
	 */
	private function __construct() {
		parent::__construct();
		$this->integrations_db = Integrations_DB::get_instance();
	}

	/**
	 * Register routes
	 *
	 * @since 0.0.3
	 *
	 * @return void
	 */
	public function register_routes() {
		$namespace = $this->get_api_namespace();

		// ========================================
		// Available Integrations Routes
		// ========================================

		// Get all available integrations (installed plugins with integration support).
		register_rest_route(
			$namespace,
			'/integration-rules/available-integrations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_available_integrations' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'filter' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'description'       => 'Filter: item_types (with item types), global_settings (with settings fields), or all (default)',
						),
					),
				),
			)
		);

		// Activate a WordPress plugin for an integration.
		register_rest_route(
			$namespace,
			'/integration-rules/activate-plugin',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'activate_integration_plugin' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'slug'      => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'item_type' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		// Toggle integration enabled status.
		register_rest_route(
			$namespace,
			'/integration-rules/toggle',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'toggle_integration' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'slug'    => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'enabled' => array(
							'required' => true,
							'type'     => 'boolean',
						),
					),
				),
			)
		);

		// Get integration global settings.
		register_rest_route(
			$namespace,
			'/integration-rules/settings/(?P<slug>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_integration_settings' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);

		// Save integration global settings.
		register_rest_route(
			$namespace,
			'/integration-rules/settings/save',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_integration_settings' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'slug'     => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'settings' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
			)
		);

		// ========================================
		// Rule Engine Routes (Item & Event-based)
		// ========================================

		// Get integration metadata (type, settings schema, available options).
		register_rest_route(
			$namespace,
			'/integration-rules/metadata/(?P<slug>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_integration_metadata' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);

		// Get all items for an integration (forms, products, etc.).
		register_rest_route(
			$namespace,
			'/integration-rules/items/(?P<slug>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_integration_items' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'type' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'description'       => 'Filter items by type (e.g., product, coupon)',
						),
					),
				),
			)
		);

		// Get fields for a specific item (e.g., form fields).
		// Uses query parameters for slug and item_id to support special characters (e.g., colons in EDD price variations).
		register_rest_route(
			$namespace,
			'/integration-rules/fields',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item_fields' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'slug'    => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'description'       => 'Integration slug',
						),
						'item_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Item ID (supports special characters like colons)',
						),
						'type'    => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'description'       => 'Item type (e.g., product, coupon, form)',
						),
						'event'   => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'description'       => 'Event key for event-based integrations (e.g., order_completed)',
						),
					),
				),
			)
		);

		// Get configuration for an integration (global or item-specific).
		register_rest_route(
			$namespace,
			'/integration-rules/config/(?P<slug>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_configuration' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'item_id'   => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'item_type' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'description'       => 'Item type (e.g., product, coupon, form)',
						),
						'event'     => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		// Save configuration (global or item-specific).
		register_rest_route(
			$namespace,
			'/integration-rules/config/save',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_configuration' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'slug'      => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'item_id'   => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'item_type' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'event'     => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'config'    => array(
							'required' => true,
							'type'     => 'object',
						),
						'status'    => array(
							'required' => false,
							'type'     => 'integer',
							'default'  => 1,
						),
						'metadata'  => array(
							'required' => false,
							'type'     => 'object',
						),
					),
				),
			)
		);

		// Update configuration status only.
		register_rest_route(
			$namespace,
			'/integration-rules/config/update-status',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_configuration_status' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'slug'      => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'item_id'   => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'item_type' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'event'     => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'status'    => array(
							'required' => true,
							'type'     => 'integer',
						),
					),
				),
			)
		);

		// Delete configuration.
		register_rest_route(
			$namespace,
			'/integration-rules/config/delete',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'delete_configuration' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'slug'      => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'item_id'   => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'item_type' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'event'     => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		// Get all configured integrations (global and item-specific) from database.
		register_rest_route(
			$namespace,
			'/integration-rules/configured-integrations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_configured_integrations' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);
	}

	/**
	 * Get integration metadata for rule engine.
	 *
	 * @since 0.0.3
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function get_integration_metadata( $request ) {
		global $surecontact;

		$slug = $request->get_param( 'slug' );

		// Validate integration exists.
		$validation = $this->validate_integration( $slug );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Delegate to the loader which handles method_exists checks and event map building.
		$metadata = $surecontact->integrations_loader->get_integration_metadata( $slug );

		if ( is_null( $metadata ) ) {
			return new WP_Error(
				'metadata_not_available',
				__( 'Integration metadata not available.', 'surecontact' ),
				array( 'status' => 404 )
			);
		}

		$metadata['slug']     = $slug;
		$metadata['icon_url'] = esc_url( $metadata['icon_url'] );

		return rest_ensure_response(
			array(
				'success'  => true,
				'metadata' => $metadata,
			)
		);
	}

	/**
	 * Get all items for an integration (forms, products, etc.).
	 *
	 * @since 0.0.3
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function get_integration_items( $request ) {
		global $surecontact;

		$slug = $request->get_param( 'slug' );
		$type = $request->get_param( 'type' );

		// Validate integration exists.
		$validation = $this->validate_integration( $slug );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$items = $surecontact->integrations_loader->fetch_integration_items( $slug, $type );

		return rest_ensure_response(
			array(
				'success' => true,
				'items'   => $items,
			)
		);
	}

	/**
	 * Get fields for a specific item.
	 *
	 * @since 0.0.3
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function get_item_fields( $request ) {
		global $surecontact;

		$slug    = $request->get_param( 'slug' );
		$item_id = $request->get_param( 'item_id' );
		$event   = $request->get_param( 'event' );

		// Validate integration exists.
		$validation = $this->validate_integration( $slug );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Default event for item config fields when not explicitly provided.
		// For event-based integrations (e.g., SureCart), this determines which
		// event-specific keys (add_lists_on_{event}, etc.) are returned.
		$event_key = is_string( $event ) && ! empty( $event ) ? $event : null;

		// Delegate to the loader which handles method_exists checks internally.
		$result = $surecontact->integrations_loader->get_item_fields( $slug, $item_id, $event_key );

		if ( is_null( $result ) ) {
			return rest_ensure_response(
				array(
					'success'       => true,
					'fields'        => array(),
					'config_fields' => array(),
				)
			);
		}

		$fields        = $result['fields'] ?? array();
		$config_fields = $result['config_fields'] ?? array();

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		return rest_ensure_response(
			array(
				'success'       => true,
				'fields'        => $fields,
				'config_fields' => is_array( $config_fields ) ? $config_fields : array(),
			)
		);
	}

	/**
	 * Get configuration for an integration.
	 *
	 * @since 0.0.3
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function get_configuration( $request ) {
		$slug      = $request->get_param( 'slug' );
		$item_id   = $request->get_param( 'item_id' );
		$item_type = $request->get_param( 'item_type' );
		$event     = $request->get_param( 'event' );

		// Get configuration using separate item_id, item_type, and event parameters.
		$result = $this->integrations_db->get( $slug, $item_id, $item_type, $event );

		if ( ! $result ) {
			return rest_ensure_response(
				array(
					'success'  => true,
					'config'   => array(),
					'exists'   => false,
					'metadata' => null,
				)
			);
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'config'   => $result['config'] ?? array(),
				'status'   => $result['status'] ?? 1,
				'event'    => $result['event'] ?? null,
				'exists'   => true,
				'metadata' => $result['metadata'] ?? null,
			)
		);
	}

	/**
	 * Save configuration for an integration.
	 *
	 * @since 0.0.3
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function save_configuration( $request ) {
		global $surecontact;

		$slug      = $request->get_param( 'slug' );
		$item_id   = $request->get_param( 'item_id' );
		$item_type = $request->get_param( 'item_type' );
		$event     = $request->get_param( 'event' );
		$config    = $request->get_param( 'config' );
		$status    = $request->get_param( 'status' );
		$metadata  = $request->get_param( 'metadata' );

		// Validate rule parameters against the integration's registered types, items, and events.
		if ( isset( $surecontact->integrations_loader ) && ! is_null( $item_id ) ) {
			$validation = $surecontact->integrations_loader->validate_rule_params( $slug, $item_type, $event, $item_id );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		// Sanitize config.
		$sanitized_config = $this->sanitize_config( $config );

		// Normalize list/tag fields to UUID-only arrays so the DB format is
		// consistent regardless of whether the data came from the UI or abilities API.
		$sanitized_config = $this->normalize_list_tag_fields( $sanitized_config );

		// Sanitize metadata if provided.
		$sanitized_metadata = null;
		if ( ! empty( $metadata ) && is_array( $metadata ) ) {
			$sanitized_metadata = $this->sanitize_config( $metadata );
		}

		// Pass item_id, item_type, event, and metadata as separate parameters to the save method.
		$result = $this->integrations_db->save( $slug, $item_id, $item_type, $sanitized_config, $status, $event, $sanitized_metadata );

		if ( false === $result ) {
			return new WP_Error(
				'save_config_failed',
				__( 'Failed to save integration configuration.', 'surecontact' ),
				array( 'status' => 500 )
			);
		}

		// Persist third-party integration metadata when a rule (item-level config) is saved
		// so the integration can be shown and re-activated if the plugin is later deactivated.
		if ( ! is_null( $item_id ) ) {
			$this->maybe_persist_third_party( $slug );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Configuration saved successfully.', 'surecontact' ),
				'config'  => $sanitized_config,
			)
		);
	}

	/**
	 * Update configuration status only.
	 *
	 * @since 0.0.3
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function update_configuration_status( $request ) {
		$slug      = $request->get_param( 'slug' );
		$item_id   = $request->get_param( 'item_id' );
		$item_type = $request->get_param( 'item_type' );
		$event     = $request->get_param( 'event' );
		$status    = $request->get_param( 'status' );

		$result = $this->integrations_db->update_status( $slug, $item_id, $item_type, $status, $event );

		if ( false === $result ) {
			return new WP_Error(
				'update_status_failed',
				__( 'Failed to update integration status.', 'surecontact' ),
				array( 'status' => 500 )
			);
		}

		if ( 0 === $result ) {
			return new WP_Error(
				'config_not_found',
				__( 'Configuration not found.', 'surecontact' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Integration status updated successfully.', 'surecontact' ),
				'status'  => $status,
			)
		);
	}

	/**
	 * Delete a specific integration configuration.
	 *
	 * @since 0.0.3
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function delete_configuration( $request ) {
		$slug      = $request->get_param( 'slug' );
		$item_id   = $request->get_param( 'item_id' );
		$item_type = $request->get_param( 'item_type' );
		$event     = $request->get_param( 'event' );

		// Pass item_id, item_type, and event as separate parameters to the delete method.
		$result = $this->integrations_db->delete( $slug, $item_id, $item_type, $event );

		if ( false === $result || 0 === $result ) {
			return new WP_Error(
				'delete_config_failed',
				__( 'Failed to delete integration configuration or configuration not found.', 'surecontact' ),
				array( 'status' => 404 )
			);
		}

		// Remove persisted third-party integration metadata when the last rule is deleted,
		// since the "Activate" button will never be shown without any rules in the table.
		if ( ! is_null( $item_id ) ) {
			$this->maybe_remove_third_party( $slug );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Configuration deleted successfully.', 'surecontact' ),
			)
		);
	}

	/**
	 * Get all configured integrations from the database.
	 *
	 * Returns only item-specific and global item configurations.
	 * Excludes traditional global settings (NULL item_id AND NULL item_type)
	 * which are handled separately via get_settings_fields().
	 *
	 * @since 0.0.3
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function get_configured_integrations( $request ) {
		global $surecontact;

		$results = $this->integrations_db->get_all();

		$configured_integrations = array();

		foreach ( $results as $result ) {
			$slug      = $result['name'];
			$item_id   = $result['item_id'] ?? null;
			$item_type = $result['item_type'] ?? null;
			$event     = $result['event'] ?? null;
			$config    = $result['config'] ?? array();
			$status    = $result['status'] ?? 1;

			if ( is_null( $item_id ) && is_null( $item_type ) ) {
				continue;
			}

			$integration_name = $slug;
			$item_title       = null;
			$event_label      = $event; // Default to event key if label not found.

			if ( isset( $surecontact->integrations_loader ) ) {
				$integration_config = $surecontact->integrations_loader->get_integration_config( $slug );
				if ( $integration_config && isset( $integration_config['name'] ) ) {
					$integration_name = $integration_config['name'];
				}
			}

			if ( ! empty( $item_id ) && ! empty( $item_type ) ) {
				$item_title = $this->get_item_title( $slug, $item_id, $item_type );
			}

			// Get event label if event is specified.
			if ( ! empty( $event ) && ! empty( $item_type ) ) {
				$event_label = $this->get_event_label( $slug, $item_type, $event );
			}

			// Get item type label from integration metadata.
			$item_type_label = ! empty( $item_type ) ? $this->get_item_type_label( $slug, $item_type ) : null;

			$configured_integrations[] = array(
				'id'               => $result['id'],
				'slug'             => $slug,
				'integration_name' => $integration_name,
				'item_id'          => $item_id,
				'item_type'        => $item_type,
				'item_type_label'  => $item_type_label,
				'item_title'       => $item_title,
				'event'            => $event,
				'event_label'      => $event_label,
				'config'           => $config,
				'status'           => $status,
				'is_global_item'   => 'all' === $item_id,
				'metadata'         => $result['metadata'] ?? null,
				'created_at'       => surecontact_format_date_for_api( $result['created_at'] ?? null ),
				'updated_at'       => surecontact_format_date_for_api( $result['updated_at'] ?? null ),
			);
		}

		return rest_ensure_response(
			array(
				'success'                 => true,
				'configured_integrations' => $configured_integrations,
			)
		);
	}

	// ========================================
	// Available Integrations Methods
	// ========================================

	/**
	 * Get all available integrations with optional filtering.
	 *
	 * Returns installed integrations with their metadata, configuration schema, and capabilities.
	 * Supports filtering by item_types (rule engine support) or global_settings (global config support).
	 *
	 * @since 0.0.3
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function get_available_integrations( $request ) {
		$filter = $request->get_param( 'filter' );

		$integrations = $this->fetch_available_integrations_data();

		if ( is_wp_error( $integrations ) ) {
			return $integrations;
		}

		// Apply filter if specified.
		if ( $filter ) {
			$integrations = $this->apply_integration_filter( $integrations, $filter );
		}

		return rest_ensure_response(
			array(
				'success'      => true,
				'integrations' => $integrations,
			)
		);
	}

	/**
	 * Fetch all available integrations with metadata.
	 *
	 * @since 0.0.3
	 *
	 * @return array|\WP_Error Array of integrations or WP_Error on failure.
	 */
	private function fetch_available_integrations_data() {
		global $surecontact;

		if ( ! isset( $surecontact->integrations_loader ) ) {
			return new WP_Error(
				'integrations_loader_not_found',
				__( 'Integrations loader not initialized.', 'surecontact' ),
				array( 'status' => 500 )
			);
		}

		$integrations = $surecontact->integrations_loader->get_available_integrations();

		// Get global-only enabled integrations for accurate status display.
		$global_enabled_slugs = $this->integrations_db->get_all_enabled();
		$global_enabled_map   = array_flip( $global_enabled_slugs );

		// Enhance with descriptions, documentation URLs, and settings fields.
		$enhanced = array();
		foreach ( $integrations as $slug => $integration ) {
			// Get the integration instance to access settings fields, description, and docs URL.
			$integration_instance = $this->get_integration_instance( $slug );

			$settings_fields       = array();
			$description           = '';
			$docs_url              = '';
			$icon_url              = '';
			$require_field_mapping = false;
			$item_types            = array();

			// Config array value (works even when dependency is not available).
			if ( ! empty( $integration['icon_url'] ) ) {
				$icon_url = $integration['icon_url'];
			}

			if ( $integration_instance && is_object( $integration_instance ) ) {
				if ( method_exists( $integration_instance, 'get_settings_fields' ) ) {
					$settings_fields = $integration_instance->get_settings_fields();
				}

				if ( method_exists( $integration_instance, 'get_description' ) ) {
					$description = $integration_instance->get_description();
				}

				if ( method_exists( $integration_instance, 'get_docs_url' ) ) {
					$docs_url = $integration_instance->get_docs_url();
				}

				if ( method_exists( $integration_instance, 'get_require_field_mapping' ) ) {
					$require_field_mapping = $integration_instance->get_require_field_mapping();
				}

				if ( method_exists( $integration_instance, 'get_item_types' ) ) {
					$item_types = $integration_instance->get_item_types();
				}

				// Class method override (when instance is available).
				if ( method_exists( $integration_instance, 'get_icon_url' ) ) {
					$instance_icon = $integration_instance->get_icon_url();
					if ( ! empty( $instance_icon ) ) {
						$icon_url = $instance_icon;
					}
				}
			} else {
				// Fallback to persisted metadata for deactivated third-party integrations.
				if ( ! empty( $integration['item_types'] ) ) {
					$item_types = $integration['item_types'];
				}
				if ( ! empty( $integration['description'] ) ) {
					$description = $integration['description'];
				}
				if ( ! empty( $integration['docs_url'] ) ) {
					$docs_url = $integration['docs_url'];
				}
			}

			// Build item type plugin map for per-rule plugin requirements.
			$item_type_plugin_map = array();
			if ( $integration_instance instanceof \SureContact\Integrations\Base_Integration ) {
				$item_type_plugin_map = $integration_instance->build_item_type_plugin_map();
				foreach ( $item_type_plugin_map as &$info ) {
					$info['available'] = is_plugin_active( $info['plugin_file'] );
					$info['installed'] = 0 === validate_plugin( $info['plugin_file'] );
				}
				unset( $info );
			}

			$enhanced[ $slug ] = array_merge(
				$integration,
				array(
					'slug'                  => $slug,
					'description'           => $description,
					'docs_url'              => esc_url( $docs_url ),
					'icon_url'              => esc_url( $icon_url ),
					'settings_fields'       => $settings_fields,
					'require_field_mapping' => $require_field_mapping,
					'item_types'            => $item_types,
					'item_type_plugin_map'  => $item_type_plugin_map,
					// Override enabled status with global-only status for accurate display.
					'enabled'               => isset( $global_enabled_map[ $slug ] ),
				)
			);
		}

		return $enhanced;
	}

	/**
	 * Apply filter to integrations array.
	 *
	 * @since 0.0.3
	 *
	 * @param array  $integrations Array of integrations.
	 * @param string $filter Filter type: 'item_types', 'global_settings', or 'all'.
	 * @return array Filtered integrations.
	 */
	private function apply_integration_filter( $integrations, $filter ) {
		switch ( $filter ) {
			case 'item_types':
				// Only integrations that support item-specific configuration.
				return array_filter(
					$integrations,
					function ( $integration ) {
						return ! empty( $integration['item_types'] );
					}
				);

			case 'global_settings':
				// Only integrations that have global settings fields.
				return array_filter(
					$integrations,
					function ( $integration ) {
						return ! empty( $integration['settings_fields'] );
					}
				);

			default:
				// Return all integrations.
				return $integrations;
		}
	}

	/**
	 * Toggle integration enabled status
	 *
	 * @since 0.0.3
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function toggle_integration( $request ) {
		$slug    = $request->get_param( 'slug' );
		$enabled = $request->get_param( 'enabled' );

		if ( $enabled ) {
			$result = $this->enable_integration( $slug );
		} else {
			$result = $this->disable_integration( $slug );
		}

		if ( ! $result ) {
			return new WP_Error(
				'integration_toggle_failed',
				sprintf(
					/* translators: %s: integration slug */
					__( 'Failed to toggle integration: %s', 'surecontact' ),
					$slug
				),
				array( 'status' => 400 )
			);
		}

		$status_text = $enabled ? __( 'enabled', 'surecontact' ) : __( 'disabled', 'surecontact' );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => sprintf(
					// translators: %1$s: integration slug, %2$s: enabled/disabled.
					__( 'Integration "%1$s" %2$s successfully.', 'surecontact' ),
					$slug,
					$status_text
				),
			)
		);
	}

	/**
	 * Activate the WordPress plugin required by an integration.
	 *
	 * Looks up the plugin file from the integrations registry and activates it
	 * using WordPress core's activate_plugin() function.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object with 'slug' parameter.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function activate_integration_plugin( $request ) {
		global $surecontact;

		$slug      = $request->get_param( 'slug' );
		$item_type = $request->get_param( 'item_type' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to activate plugins.', 'surecontact' ),
				array( 'status' => 403 )
			);
		}

		if ( ! isset( $surecontact->integrations_loader ) ) {
			return new WP_Error(
				'integrations_loader_not_found',
				__( 'Integrations loader not initialized.', 'surecontact' ),
				array( 'status' => 500 )
			);
		}

		$plugin_file         = null;
		$plugin_dependencies = array();

		// If item_type is provided, check if it has a specific plugin requirement.
		if ( ! empty( $item_type ) ) {
			$integration_instance = $this->get_integration_instance( $slug );
			if ( $integration_instance && method_exists( $integration_instance, 'build_item_type_plugin_map' ) ) {
				$plugin_map = $integration_instance->build_item_type_plugin_map();
				if ( isset( $plugin_map[ $item_type ] ) ) {
					$plugin_file         = $plugin_map[ $item_type ]['plugin_file'];
					$plugin_dependencies = $plugin_map[ $item_type ]['plugin_dependencies'] ?? array();
				}
			}
		}

		// Fallback to integration-level plugin_file and plugin_dependencies.
		if ( ! $plugin_file ) {
			$plugin_file = $surecontact->integrations_loader->get_plugin_file( $slug );

			$integration_config = $surecontact->integrations_loader->get_integration_config( $slug );
			if ( $integration_config ) {
				$plugin_dependencies = $integration_config['plugin_dependencies'] ?? array();
			}
		}

		if ( ! $plugin_file ) {
			return new WP_Error(
				'plugin_file_not_found',
				__( 'No plugin file registered for this integration.', 'surecontact' ),
				array( 'status' => 404 )
			);
		}

		// Check if plugin file exists (i.e., plugin is installed).
		if ( 0 !== validate_plugin( $plugin_file ) ) {
			return new WP_Error(
				'plugin_not_installed',
				__( 'This plugin is not installed. Please install it first.', 'surecontact' ),
				array( 'status' => 404 )
			);
		}

		// Activate plugin dependencies first.
		if ( ! empty( $plugin_dependencies ) ) {
			foreach ( $plugin_dependencies as $dep_file ) {
				// Skip if already active.
				if ( is_plugin_active( $dep_file ) ) {
					continue;
				}

				// Check if dependency is installed.
				if ( 0 !== validate_plugin( $dep_file ) ) {
					return new WP_Error(
						'dependency_not_installed',
						sprintf(
							/* translators: %s: plugin file path */
							__( 'Required dependency "%s" is not installed.', 'surecontact' ),
							$dep_file
						),
						array( 'status' => 404 )
					);
				}

				$dep_result = activate_plugin( $dep_file );
				if ( is_wp_error( $dep_result ) ) {
					return new WP_Error(
						'dependency_activation_failed',
						sprintf(
							/* translators: %1$s: plugin file path, %2$s: error message */
							__( 'Failed to activate dependency "%1$s": %2$s', 'surecontact' ),
							$dep_file,
							$dep_result->get_error_message()
						),
						array( 'status' => 500 )
					);
				}
			}
		}

		// Check if already active.
		if ( is_plugin_active( $plugin_file ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Plugin is already active.', 'surecontact' ),
				)
			);
		}

		// Activate the plugin.
		$result = activate_plugin( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'plugin_activation_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Plugin activated successfully.', 'surecontact' ),
			)
		);
	}

	/**
	 * Enable an integration
	 *
	 * Creates or updates the integration's database record with enabled status.
	 * Also loads the integration instance if not already loaded.
	 *
	 * @since 0.0.3
	 *
	 * @param string $slug Integration slug.
	 * @return bool True on success, false if integration not found.
	 */
	private function enable_integration( $slug ) {
		global $surecontact;

		// Validate integration exists.
		$config = $surecontact->integrations_loader->get_integration_config( $slug );
		if ( ! $config ) {
			return false;
		}

		// Get current settings or create with defaults.
		$result = $this->integrations_db->get( $slug, null );

		if ( $result ) {
			// Just update status.
			$this->integrations_db->update_status( $slug, null, null, 1 );
		} else {
			// Create new with default settings.
			$integration      = $this->get_integration_instance( $slug );
			$default_settings = array();

			if ( $integration && method_exists( $integration, 'get_settings_fields' ) ) {
				$fields = $integration->get_settings_fields();
				foreach ( $fields as $key => $field ) {
					$default_settings[ $key ] = isset( $field['default'] ) ? $field['default'] : '';
				}
			}

			$this->integrations_db->save( $slug, null, null, $default_settings, 1 );
		}

		return true;
	}

	/**
	 * Disable an integration
	 *
	 * Updates or creates the integration's database record with disabled status.
	 *
	 * @since 0.0.3
	 *
	 * @param string $slug Integration slug.
	 * @return bool True on success, false if integration not found.
	 */
	private function disable_integration( $slug ) {
		global $surecontact;

		// Validate integration exists.
		$config = $surecontact->integrations_loader->get_integration_config( $slug );
		if ( ! $config ) {
			return false;
		}

		// Check if a global configuration record exists.
		$result = $this->integrations_db->get( $slug, null );

		if ( $result ) {
			// Update existing record to disabled status.
			$this->integrations_db->update_status( $slug, null, null, 0 );
		} else {
			// Create a new record with disabled status and empty settings.
			// This ensures the integration shows as explicitly disabled in the UI.
			$this->integrations_db->save( $slug, null, null, array(), 0 );
		}

		return true;
	}

	/**
	 * Get integration settings (legacy method)
	 *
	 * @since 0.0.3
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function get_integration_settings( $request ) {
		$slug = $request->get_param( 'slug' );

		// Get settings from custom database table (global settings without event).
		$result = $this->integrations_db->get( $slug, null, null );

		// Return config array or empty array if not found.
		$settings = $result && isset( $result['config'] ) ? $result['config'] : array();
		$status   = $result && isset( $result['status'] ) ? (int) $result['status'] : 1;

		return rest_ensure_response(
			array(
				'success'  => true,
				'settings' => $settings,
				'status'   => $status,
			)
		);
	}

	/**
	 * Save integration settings (legacy method)
	 *
	 * @since 0.0.3
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function save_integration_settings( $request ) {
		$slug     = $request->get_param( 'slug' );
		$settings = $request->get_param( 'settings' );

		// Sanitize settings.
		$sanitized = $this->sanitize_config( $settings );

		// Normalize list/tag fields to UUID-only arrays.
		$sanitized = $this->normalize_list_tag_fields( $sanitized );

		// Get current status or default to enabled (1).
		$existing = $this->integrations_db->get( $slug, null, null );
		$status   = $existing && isset( $existing['status'] ) ? (int) $existing['status'] : 1;

		// Save the configuration with current status (no event for global settings).
		$result = $this->integrations_db->save( $slug, null, null, $sanitized, $status, null );

		if ( false === $result ) {
			return new WP_Error(
				'save_settings_failed',
				__( 'Failed to save integration settings.', 'surecontact' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'message'  => __( 'Integration settings saved successfully.', 'surecontact' ),
				'settings' => $sanitized,
				'status'   => $status,
			)
		);
	}

	// ========================================
	// Helper Methods
	// ========================================

	/**
	 * Persist a third-party integration's metadata if not already persisted.
	 *
	 * Called after a rule is saved. Only writes to the option on the first rule
	 * for a given third-party slug.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Integration slug.
	 * @return void
	 */
	private function maybe_persist_third_party( $slug ) {
		global $surecontact;

		if ( ! isset( $surecontact->integrations_loader ) ) {
			return;
		}

		if ( ! $surecontact->integrations_loader->is_third_party( $slug ) ) {
			return;
		}

		// Only persist if not already in the option.
		$persisted = get_option( 'surecontact_third_party_integrations', array() );
		if ( is_array( $persisted ) && isset( $persisted[ $slug ] ) ) {
			return;
		}

		$surecontact->integrations_loader->persist_third_party_integration( $slug );
	}

	/**
	 * Remove a third-party integration's metadata if no rules remain.
	 *
	 * Called after a rule is deleted. Checks whether the integration still has
	 * any item-level rules in the database; if not, removes it from the option.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Integration slug.
	 * @return void
	 */
	private function maybe_remove_third_party( $slug ) {
		global $surecontact;

		if ( ! isset( $surecontact->integrations_loader ) ) {
			return;
		}

		if ( ! $surecontact->integrations_loader->is_third_party( $slug ) ) {
			return;
		}

		// Only remove if no item-level rules remain.
		if ( $this->integrations_db->has_rules( $slug ) ) {
			return;
		}

		$surecontact->integrations_loader->remove_third_party_integration( $slug );
	}

	/**
	 * Get item title by ID and type.
	 *
	 * @since 0.0.3
	 *
	 * @param string $slug Integration slug.
	 * @param string $item_id Item ID.
	 * @param string $item_type Item type (form, product, coupon, etc.).
	 * @return string|null Item title or null if not found.
	 */
	private function get_item_title( $slug, $item_id, $item_type ) {
		$title = null;

		// Try to get title from integration-specific method first.
		$integration = $this->get_integration_instance( $slug );
		if ( $integration && method_exists( $integration, 'get_item_title' ) ) {
			$result = $integration->get_item_title( $item_id, $item_type );
			if ( ! empty( $result ) ) {
				$title = $result;
			}
		}

		// Fallback: Get from WordPress post if valid ID.
		if ( is_null( $title ) && is_numeric( $item_id ) ) {
			$post = get_post( (int) $item_id );
			if ( $post instanceof \WP_Post ) {
				$title = $post->post_title;
			}
		}

		// Decode HTML entities so React renders the actual characters
		// (e.g., &#8211; → –) instead of displaying the raw entity string.
		if ( ! is_null( $title ) ) {
			$title = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		return $title;
	}

	/**
	 * Get event label by integration slug, item type, and event key.
	 *
	 * @since 0.0.3
	 *
	 * @param string $slug Integration slug.
	 * @param string $item_type Item type (form, product, video, etc.).
	 * @param string $event_key Event key (e.g., 'start', 'complete', 'email_submit').
	 * @return string Event label or event key if label not found.
	 */
	private function get_event_label( $slug, $item_type, $event_key ) {
		// Get integration instance.
		$integration = $this->get_integration_instance( $slug );

		if ( ! $integration || ! method_exists( $integration, 'get_events_by_item_type' ) ) {
			return $event_key;
		}

		// Get events for this item type.
		$events = $integration->get_events_by_item_type( $item_type );

		if ( empty( $events ) || ! is_array( $events ) ) {
			return $event_key;
		}

		// Find the event with matching key.
		foreach ( $events as $event ) {
			if ( isset( $event['key'] ) && $event['key'] === $event_key ) {
				return $event['label'] ?? $event_key;
			}
		}

		return $event_key;
	}

	/**
	 * Get the human-readable label for an item type from integration metadata.
	 *
	 * @since 1.3.0
	 *
	 * @param string $slug      Integration slug.
	 * @param string $item_type Item type key (e.g., 'module').
	 * @return string|null Human-readable label, or null if not found.
	 */
	private function get_item_type_label( $slug, $item_type ) {
		$integration = $this->get_integration_instance( $slug );

		if ( ! $integration || ! method_exists( $integration, 'get_item_types' ) ) {
			return null;
		}

		$item_types = $integration->get_item_types();

		if ( empty( $item_types ) || ! is_array( $item_types ) ) {
			return null;
		}

		foreach ( $item_types as $type ) {
			if ( isset( $type['key'] ) && $type['key'] === $item_type ) {
				return $type['label'] ?? null;
			}
		}

		return null;
	}


	/**
	 * Validate that an integration exists and return its instance.
	 *
	 * @since 1.3.0
	 *
	 * @param string $slug Integration slug.
	 * @return \SureContact\Integrations\Base_Integration|\WP_Error Integration instance or WP_Error.
	 */
	private function validate_integration( $slug ) {
		global $surecontact;

		if ( ! isset( $surecontact->integrations_loader ) ) {
			return new WP_Error(
				'integrations_loader_not_found',
				__( 'Integrations loader not initialized.', 'surecontact' ),
				array( 'status' => 500 )
			);
		}

		$config = $surecontact->integrations_loader->get_integration_config( $slug );

		if ( ! $config ) {
			return new WP_Error(
				'integration_not_found',
				sprintf(
					/* translators: %s: integration slug */
					__( 'Integration not found: %s', 'surecontact' ),
					$slug
				),
				array( 'status' => 404 )
			);
		}

		$instance = $this->get_integration_instance( $slug );

		return $instance ? $instance : new WP_Error(
			'integration_unavailable',
			sprintf(
				/* translators: %s: integration slug */
				__( 'Integration "%s" is not available. The required plugin may not be installed or active.', 'surecontact' ),
				$slug
			),
			array( 'status' => 404 )
		);
	}

	/**
	 * Get integration instance via the loader.
	 *
	 * Thin typed wrapper around the loader's get_or_create_instance() so that
	 * PHPStan can resolve the return type through the untyped global.
	 *
	 * @since 1.3.0
	 *
	 * @param string $slug Integration slug.
	 * @return \SureContact\Integrations\Base_Integration|null Integration instance or null.
	 */
	private function get_integration_instance( $slug ) {
		global $surecontact;

		if ( ! isset( $surecontact->integrations_loader ) ) {
			return null;
		}

		return $surecontact->integrations_loader->get_or_create_instance( $slug );
	}

	/**
	 * Normalize list/tag config fields to validated UUID-only arrays.
	 *
	 * @since 1.3.1
	 *
	 * @param array $config Configuration array.
	 * @return array
	 */
	private function normalize_list_tag_fields( $config ) {
		$list_uuids = array_column( \SureContact\Synced_Metadata::get_lists(), 'uuid' );
		$tag_uuids  = array_column( \SureContact\Synced_Metadata::get_tags(), 'uuid' );

		$keys = [
			'add_lists'    => $list_uuids,
			'add_tags'     => $tag_uuids,
			'remove_lists' => $list_uuids,
			'remove_tags'  => $tag_uuids,
		];

		foreach ( $keys as $key => $valid_uuids ) {
			if ( ! empty( $config[ $key ] ) && is_array( $config[ $key ] ) ) {
				$config[ $key ] = array_values(
					array_filter(
						array_map(
							function ( $item ) use ( $valid_uuids ) {
								$uuid = null;
								if ( is_array( $item ) && isset( $item['uuid'] ) ) {
									$uuid = $item['uuid'];
								} elseif ( is_string( $item ) ) {
									$uuid = $item;
								}

								if ( $uuid && in_array( $uuid, $valid_uuids, true ) ) {
									return $uuid;
								}

								return null;
							},
							$config[ $key ]
						)
					)
				);
			}
		}

		return $config;
	}

	/**
	 * Sanitize configuration data.
	 *
	 * @since 0.0.3
	 *
	 * @param array  $config Configuration array.
	 * @param string $parent_key Parent key for nested arrays (used for field_mapping detection).
	 * @return array
	 */
	private function sanitize_config( $config, $parent_key = '' ) {
		if ( ! is_array( $config ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $config as $key => $value ) {
			// Preserve original keys for field_mapping because they represent form field IDs
			// that may contain special characters like brackets (e.g., "names[first_name]").
			// These must match exactly with the form field IDs from the integration.
			$is_field_mapping = ( $parent_key === 'field_mapping' );

			// Only sanitize the key if it's not part of field_mapping.
			$sanitized_key = $is_field_mapping ? $key : sanitize_key( $key );

			if ( is_array( $value ) ) {
				// Pass the current key as parent_key for nested recursion.
				$sanitized[ $sanitized_key ] = $this->sanitize_config( $value, $sanitized_key );
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $sanitized_key ] = (bool) $value;
			} elseif ( is_numeric( $value ) ) {
				$sanitized[ $sanitized_key ] = is_float( $value ) ? (float) $value : (int) $value;
			} else {
				// Decode HTML entities before sanitizing to prevent entity-encoded
				// characters (e.g., &#8211; for em dash) from being stored as literals.
				// sanitize_text_field() runs after decoding, so any decoded HTML tags
				// are still stripped — security is preserved.
				$sanitized[ $sanitized_key ] = sanitize_text_field( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
		}

		return $sanitized;
	}
}
