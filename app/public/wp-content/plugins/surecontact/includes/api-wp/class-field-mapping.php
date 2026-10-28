<?php
/**
 * Field Mapping API
 *
 * Handles all field mapping operations via REST API
 * - Get field mapping data
 * - Save field mappings
 * - Sync CRM fields
 *
 * @since 0.0.1
 *
 * @package SureContact\API_WP
 */

namespace SureContact\API_WP;

use SureContact\Field_Mapper;
use SureContact\API\Fields_API;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field_Mapping class
 *
 * @since 0.0.1
 */
class Field_Mapping extends Api_Base {

	/**
	 * Instance
	 *
	 * @since 0.0.1
	 *
	 * @var Field_Mapping
	 */
	private static $instance = null;

	/**
	 * Field Mapper instance
	 *
	 * @since 0.0.1
	 *
	 * @var Field_Mapper
	 */
	private $field_mapper;

	/**
	 * Fields API instance
	 *
	 * @since 1.0.0
	 *
	 * @var Fields_API
	 */
	private $fields_api;

	/**
	 * Get instance
	 *
	 * @since 0.0.1
	 *
	 * @return Field_Mapping
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
		$this->field_mapper = new Field_Mapper();
		$this->fields_api   = new Fields_API();
	}

	/**
	 * Register API routes
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function register_routes() {
		$namespace = $this->get_api_namespace();

		// Get field mapping data.
		register_rest_route(
			$namespace,
			'/field-mapping/get',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_field_mapping_data' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);

		// Save field mappings.
		register_rest_route(
			$namespace,
			'/field-mapping/save',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_field_mappings' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'mappings' => array(
							'required'          => true,
							'type'              => 'object',
							'description'       => __( 'Field mappings configuration', 'surecontact' ),
							'validate_callback' => array( $this, 'validate_mappings' ),
						),
					),
				),
			)
		);

		// Sync CRM fields.
		register_rest_route(
			$namespace,
			'/field-mapping/sync',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sync_crm_fields' ),
					'permission_callback' => array( $this, 'validate_permission' ),
				),
			)
		);

		// Create custom field.
		register_rest_route(
			$namespace,
			'/field-mapping/create-field',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_custom_field' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'name'        => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => __( 'Field name (unique identifier)', 'surecontact' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'label'       => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => __( 'Field label (display name)', 'surecontact' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'field_type'  => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => __( 'Field type', 'surecontact' ),
							'enum'              => array( 'text', 'number', 'date', 'timestamp', 'email', 'url', 'phone', 'select', 'multi_select', 'checkbox', 'textarea' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'options'     => array(
							'required'          => false,
							'type'              => 'array',
							'description'       => __( 'Options for select/multi_select fields', 'surecontact' ),
							'default'           => array(),
							'items'             => array(
								'type' => 'string',
							),
							'sanitize_callback' => static function ( $value ) {
								if ( ! is_array( $value ) ) {
									return array();
								}
								return array_values( array_map( 'sanitize_text_field', $value ) );
							},
						),
						'is_required' => array(
							'required'          => false,
							'type'              => 'boolean',
							'description'       => __( 'Whether the field is required', 'surecontact' ),
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);
	}

	/**
	 * Get field mapping data
	 *
	 * Returns all data needed for the field mapping UI:
	 * - Available WordPress fields (grouped)
	 * - Available CRM fields (primary + custom)
	 * - Current field mappings
	 * - Last sync time
	 *
	 * @since 0.0.1
	 *
	 * @return \WP_REST_Response
	 */
	public function get_field_mapping_data() {
		try {
			// Get available WordPress fields (grouped).
			$wp_field_groups = $this->field_mapper->get_available_wordpress_fields();

			// Get available CRM fields.
			$crm_fields = $this->field_mapper->get_available_crm_fields();

			// Get current field mappings.
			$current_mappings = $this->field_mapper->get_contact_fields();

			// Get last sync time.
			$last_sync = $this->field_mapper->get_last_sync_time_formatted();

			// Format the data for React UI.
			$data = array(
				'wordpress_fields' => $wp_field_groups,
				'crm_fields'       => $this->format_crm_fields( $crm_fields ),
				'mappings'         => $current_mappings,
				'last_sync'        => $last_sync,
			);

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $data,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				500
			);
		}
	}

	/**
	 * Save field mappings
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function save_field_mappings( $request ) {
		try {
			$mappings = $request->get_param( 'mappings' );

			// Sanitize and save mappings.
			$sanitized_mappings = $this->sanitize_mappings( $mappings );

			// Update field mappings.
			$result = $this->field_mapper->update_contact_fields( $sanitized_mappings );

			if ( $result ) {
				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => __( 'Field mappings saved successfully!', 'surecontact' ),
					),
					200
				);
			} else {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Failed to save field mappings.', 'surecontact' ),
					),
					500
				);
			}
		} catch ( \Exception $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				500
			);
		}
	}

	/**
	 * Sync CRM fields
	 *
	 * Fetches custom fields from SureContact API and updates local cache
	 *
	 * @since 0.0.1
	 *
	 * @return \WP_REST_Response
	 */
	public function sync_crm_fields() {
		try {
			// Sync fields from CRM.
			$result = $this->field_mapper->sync_crm_fields();

			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => $result->get_error_message(),
					),
					400
				);
			}

			// Get updated data.
			$crm_fields = $this->field_mapper->get_available_crm_fields();
			$last_sync  = $this->field_mapper->get_last_sync_time_formatted();

			return new WP_REST_Response(
				array(
					'success'    => true,
					'message'    => __( 'CRM fields synced successfully!', 'surecontact' ),
					'crm_fields' => $this->format_crm_fields( $crm_fields ),
					'last_sync'  => $last_sync,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				500
			);
		}
	}

	/**
	 * Create a custom field in the CRM
	 *
	 * Creates a new custom field and returns the updated CRM fields list
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function create_custom_field( $request ) {
		try {
			$name        = $request->get_param( 'name' );
			$label       = $request->get_param( 'label' );
			$field_type  = $request->get_param( 'field_type' );
			$options     = $request->get_param( 'options' );
			$is_required = $request->get_param( 'is_required' );

			// Validate options for select/multi_select fields.
			if ( in_array( $field_type, array( 'select', 'multi_select' ), true ) && empty( $options ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Options are required for select and multi-select fields.', 'surecontact' ),
					),
					400
				);
			}

			// Prepare field data.
			$field_data = array(
				'name'        => $name,
				'label'       => $label,
				'type'        => $field_type,
				'is_required' => (bool) $is_required,
			);

			// Add options if present.
			if ( ! empty( $options ) ) {
				$field_data['options'] = array_map( 'sanitize_text_field', $options );
			}

			// Create the field via SaaS API.
			$result = $this->fields_api->sync_custom_field( $field_data );

			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => $result->get_error_message(),
					),
					400
				);
			}

			// Sync CRM fields to update local cache.
			$this->field_mapper->sync_crm_fields();

			// Get updated CRM fields.
			$crm_fields = $this->field_mapper->get_available_crm_fields();
			$last_sync  = $this->field_mapper->get_last_sync_time_formatted();

			// Find the newly created field in the synced fields to get the exact value.
			// This ensures the returned value matches what's in the dropdown options.
			$created_field_value = $name;
			$created_field_label = $label;
			$sanitized_input     = strtolower( $name );

			foreach ( $crm_fields as $field_id => $field_data ) {
				// Match by comparing sanitized names (case-insensitive).
				if ( strtolower( $field_id ) === $sanitized_input ) {
					$created_field_value = $field_id;
					$created_field_label = is_array( $field_data ) && isset( $field_data['label'] ) ? $field_data['label'] : $label;
					break;
				}
			}

			// Format the new field data for the response.
			$new_field = array(
				'value'      => $created_field_value,
				'label'      => $created_field_label,
				'field_type' => $field_type,
			);

			return new WP_REST_Response(
				array(
					'success'    => true,
					'message'    => __( 'Custom field created successfully!', 'surecontact' ),
					'field'      => $new_field,
					'crm_fields' => $this->format_crm_fields( $crm_fields ),
					'last_sync'  => $last_sync,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				500
			);
		}
	}

	/**
	 * Validate mappings data
	 *
	 * @since 0.0.1
	 *
	 * @param mixed           $value   Mappings value.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 * @return bool
	 */
	public function validate_mappings( $value, $request, $param ) {
		if ( ! is_array( $value ) ) {
			return false;
		}

		// Validate each mapping entry.
		foreach ( $value as $wp_field => $config ) {
			if ( ! is_array( $config ) ) {
				return false;
			}

			// Ensure required keys exist.
			if ( ! isset( $config['active'], $config['type'] ) ) {
				return false;
			}

			// Validate active is boolean.
			if ( ! is_bool( $config['active'] ) ) {
				return false;
			}

			// Validate type is string.
			if ( ! is_string( $config['type'] ) ) {
				return false;
			}

			// Validate crm_field if present.
			if ( isset( $config['crm_field'] ) && ! is_string( $config['crm_field'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sanitize mappings data
	 *
	 * @since 0.0.1
	 *
	 * @param array $mappings Raw mappings data.
	 * @return array Sanitized mappings.
	 */
	private function sanitize_mappings( $mappings ) {
		$sanitized = array();

		foreach ( $mappings as $wp_field => $config ) {
			$wp_field = sanitize_text_field( $wp_field );

			$sanitized[ $wp_field ] = array(
				'active'    => (bool) $config['active'],
				'type'      => isset( $config['type'] ) ? sanitize_text_field( $config['type'] ) : 'text',
				'crm_field' => isset( $config['crm_field'] ) ? sanitize_text_field( $config['crm_field'] ) : '',
				'label'     => isset( $config['label'] ) ? sanitize_text_field( $config['label'] ) : ucwords( str_replace( '_', ' ', $wp_field ) ),
			);
		}

		return $sanitized;
	}

	/**
	 * Format CRM fields for React UI
	 *
	 * Separates contact fields (default) and custom fields for dropdown usage
	 *
	 * @since 0.0.1
	 *
	 * @param array $crm_fields CRM fields from Field_Mapper.
	 * @return array
	 */
	private function format_crm_fields( $crm_fields ) {
		$contact_fields = array();
		$custom_fields  = array();

		// Contact field keys (default fields in SureContact CRM).
		$contact_field_keys = Field_Mapper::get_primary_field_keys();

		foreach ( $crm_fields as $field_id => $field_data ) {
			if ( in_array( $field_id, $contact_field_keys, true ) ) {
				// Contact field (default CRM field).
				$contact_fields[] = array(
					'value' => $field_id,
					'label' => is_array( $field_data ) ? $field_data['label'] : $field_data,
				);
			} else {
				// Custom field (user-created in the platform).
				$custom_fields[] = array(
					'value'       => $field_id,
					'label'       => is_array( $field_data ) ? $field_data['label'] : $field_data,
					'field_type'  => isset( $field_data['field_type'] ) ? $field_data['field_type'] : 'text',
					'is_required' => isset( $field_data['is_required'] ) ? $field_data['is_required'] : false,
				);
			}
		}

		return array(
			'primary' => $contact_fields,
			'custom'  => $custom_fields,
		);
	}
}
