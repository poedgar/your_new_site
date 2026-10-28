<?php
/**
 * Field Mapper Class
 *
 * Handles mapping of WordPress plugin data to SureContact global format
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact;

use SureContact\API\Fields_API;
use SureContact\Field_Formatter;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Field_Mapper
 *
 * Maps data from various WordPress integrations to a standardized format for SureContact
 *
 * @since 0.0.1
 */
class Field_Mapper {

	/**
	 * Get primary field keys that are always available in the CRM
	 * Derives keys from get_primary_crm_fields() to maintain single source of truth
	 *
	 * @since 0.0.1
	 *
	 * @return array Primary field keys
	 */
	public static function get_primary_field_keys() {
		return array_keys( self::get_primary_crm_fields() );
	}

	/**
	 * Get primary field types that are always available in the CRM
	 * Derives types from get_primary_crm_fields() to maintain single source of truth
	 *
	 * @since 0.0.3
	 *
	 * @return array Primary field types (field_key => type)
	 */
	public static function get_primary_field_types() {
		$primary_fields = self::get_primary_crm_fields();
		$types          = array();

		foreach ( $primary_fields as $field_key => $field_config ) {
			$types[ $field_key ] = $field_config['field_type'];
		}

		return $types;
	}

	/**
	 * Get all CRM field types (primary + custom)
	 *
	 * Returns field types for both primary fields and custom fields.
	 * This is used for type-based formatting in form integrations.
	 *
	 * @since 0.0.3
	 *
	 * @return array All CRM field types (field_key => type)
	 */
	public static function get_all_crm_field_types() {
		$types = array();

		// Start with primary field types.
		$primary_fields = self::get_primary_crm_fields();
		foreach ( $primary_fields as $field_key => $field_config ) {
			$types[ $field_key ] = $field_config['field_type'];
		}

		// Add custom field types (if available).
		$custom_fields = Synced_Metadata::get_custom_fields();
		if ( ! empty( $custom_fields ) && is_array( $custom_fields ) ) {
			foreach ( $custom_fields as $field_key => $field_config ) {
				if ( isset( $field_config['field_type'] ) ) {
					$types[ $field_key ] = $field_config['field_type'];
				}
			}
		}

		return $types;
	}

	/**
	 * Contact fields configuration
	 * Stored in WordPress options as 'surecontact_contact_fields'
	 *
	 * Structure:
	 * [
	 *   'wp_field_key' => [
	 *     'active'    => bool,    // Whether field syncs to CRM
	 *     'crm_field' => string,  // CRM field name
	 *     'type'      => string,  // Field type
	 *     'label'     => string,  // Display label
	 *   ]
	 * ]
	 *
	 * @since 0.0.1
	 *
	 * @var array
	 */
	private $contact_fields = array();

	/**
	 * Fields API instance
	 *
	 * @since 0.0.1
	 *
	 * @var Fields_API
	 */
	private $fields_api;

	/**
	 * Available CRM fields cache
	 * Primary fields are always available
	 * Custom fields are loaded from API
	 *
	 * @since 0.0.1
	 *
	 * @var array
	 */
	private $crm_fields = array();

	/**
	 * Available meta fields cache
	 * All WordPress fields that can be synced
	 *
	 * @since 0.0.1
	 *
	 * @var array
	 */
	private $meta_fields = array();

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		$this->fields_api = new Fields_API( new SaaS_Client() );
		$this->load_meta_fields();
		$this->load_contact_fields();
		$this->load_crm_fields();
	}

	/**
	 * Load available meta fields from wordpress-fields.php and integrations
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function load_meta_fields() {
		// Load base WordPress fields from external file.
		$wp_fields_file = plugin_dir_path( __DIR__ ) . 'includes/admin/wordpress-fields.php';

		if ( file_exists( $wp_fields_file ) ) {
			$this->meta_fields = include $wp_fields_file;
		}

		// Ensure it's an array.
		if ( ! is_array( $this->meta_fields ) ) {
			$this->meta_fields = array();
		}

		// Allow integrations to add their fields via filter.
		$this->meta_fields = apply_filters( 'surecontact_meta_fields', $this->meta_fields );
	}

	/**
	 * Check if email field mapping is configured for a specific integration group
	 *
	 * @since 1.4.0
	 *
	 * @param string $group Integration group slug (e.g. 'fluentcrm', 'woocommerce').
	 * @return bool Whether an active email mapping exists for the given group.
	 */
	public static function has_required_mapping( $group = '' ) {
		$mappings    = get_option( 'surecontact_contact_fields', array() );
		$meta_fields = apply_filters( 'surecontact_meta_fields', array() );

		foreach ( $mappings as $field_key => $field ) {
			if ( empty( $field['active'] ) || ! isset( $field['crm_field'] ) || 'email' !== $field['crm_field'] ) {
				continue;
			}

			// If no group specified, any active email mapping is sufficient.
			if ( empty( $group ) ) {
				return true;
			}

			// Check if this field belongs to the requested integration group.
			$field_group = isset( $meta_fields[ $field_key ]['group'] ) ? $meta_fields[ $field_key ]['group'] : 'wp';
			if ( $field_group === $group ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Load contact fields configuration from WordPress options
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function load_contact_fields() {
		$this->contact_fields = get_option( 'surecontact_contact_fields', array() );

		// Initialize default mappings if empty.
		if ( empty( $this->contact_fields ) ) {
			$this->contact_fields = $this->get_default_contact_fields();
			update_option( 'surecontact_contact_fields', $this->contact_fields );
		}
	}

	/**
	 * Load CRM fields from options
	 *
	 * Primary fields are always available on WordPress side.
	 * Custom fields are loaded from stored API response (if synced).
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function load_crm_fields() {
		// Start with built-in primary fields (always available without API sync).
		$this->crm_fields = self::get_primary_crm_fields();

		// Merge with custom fields fetched from API (if available).
		$custom_fields = Synced_Metadata::get_custom_fields();
		if ( ! empty( $custom_fields ) ) {
			$this->crm_fields = array_merge( $this->crm_fields, $custom_fields );
		}
	}

	/**
	 * Get primary CRM fields that are always available
	 *
	 * These fields are built-in and don't require API sync.
	 * They match the primary_fields structure in the CRM API.
	 * This is the SINGLE SOURCE OF TRUTH for primary fields.
	 *
	 * @since 0.0.1
	 *
	 * @return array Primary CRM fields with labels and types (field_key => ['label' => string, 'type' => string])
	 */
	public static function get_primary_crm_fields() {
		return array(
			'email'       => array(
				'label'      => __( 'Email Address', 'surecontact' ),
				'field_type' => 'email',
			),
			'first_name'  => array(
				'label'      => __( 'First Name', 'surecontact' ),
				'field_type' => 'text',
			),
			'last_name'   => array(
				'label'      => __( 'Last Name', 'surecontact' ),
				'field_type' => 'text',
			),
			'phone'       => array(
				'label'      => __( 'Phone Number', 'surecontact' ),
				'field_type' => 'phone',
			),
			'company'     => array(
				'label'      => __( 'Company Name', 'surecontact' ),
				'field_type' => 'text',
			),
			'job_title'   => array(
				'label'      => __( 'Job Title', 'surecontact' ),
				'field_type' => 'text',
			),
			'birthdate'   => array(
				'label'      => __( 'Birthdate', 'surecontact' ),
				'field_type' => 'date',
			),
			'gender'      => array(
				'label'      => __( 'Gender', 'surecontact' ),
				'field_type' => 'select',
			),
			'anniversary' => array(
				'label'      => __( 'Anniversary', 'surecontact' ),
				'field_type' => 'date',
			),
			'prefix'      => array(
				'label'      => __( 'Prefix', 'surecontact' ),
				'field_type' => 'text',
			),
			'suffix'      => array(
				'label'      => __( 'Suffix', 'surecontact' ),
				'field_type' => 'text',
			),
			'timezone'    => array(
				'label'      => __( 'Timezone', 'surecontact' ),
				'field_type' => 'text',
			),
			'language'    => array(
				'label'      => __( 'Language', 'surecontact' ),
				'field_type' => 'text',
			),
			'created_at'  => array(
				'label'      => __( 'Created At', 'surecontact' ),
				'field_type' => 'datetime',
			),
			'updated_at'  => array(
				'label'      => __( 'Updated At', 'surecontact' ),
				'field_type' => 'datetime',
			),
		);
	}

	/**
	 * Get default contact fields configuration
	 * Creates initial mappings from meta_fields with default CRM field mappings
	 *
	 * Certain fields are active by default for convenience.
	 *
	 * @since 0.0.1
	 *
	 * @return array Default contact fields
	 */
	private function get_default_contact_fields() {
		$default_fields = array();

		// Define which fields should be active by default and their default CRM mappings.
		$default_active_fields = array(
			'user_email' => 'email',      // Maps to CRM 'email' field.
			'first_name' => 'first_name',
			'last_name'  => 'last_name',
			'phone'      => 'phone',
			'company'    => 'company',
			'job_title'  => 'job_title',  // Maps to CRM 'job_title' field.
			'locale'     => 'language',   // Maps to CRM 'language' field (ISO 639-1 code).
		);

		// Build default contact fields from meta fields.
		foreach ( $this->meta_fields as $field_key => $field_config ) {
			// Check if this field should be active by default.
			$is_active = isset( $default_active_fields[ $field_key ] );
			$crm_field = isset( $default_active_fields[ $field_key ] ) ? $default_active_fields[ $field_key ] : '';

			$default_fields[ $field_key ] = array(
				'active'    => $is_active,
				'crm_field' => $crm_field,
				'type'      => $field_config['type'],
				'label'     => $field_config['label'],
			);
		}

		return $default_fields;
	}

	/**
	 * Get registered field groups
	 *
	 * Field group structure:
	 * - Default group is 'wp' for standard WordPress fields
	 * - Integrations register their own groups via filter
	 * - 'custom' group added for manually added fields
	 * - 'extra' group added for auto-discovered fields from wp_usermeta
	 *
	 * @since 0.0.1
	 *
	 * @return array Field groups with titles and optional metadata
	 */
	private function get_field_groups() {
		// Default group for standard WordPress fields.
		$groups = array(
			'wp' => array(
				'title' => __( 'Standard WordPress Fields', 'surecontact' ),
			),
		);

		/**
		 * Filter field groups
		 *
		 * Allows integrations to register their own field groups.
		 * Integrations can add their own groups which will be displayed in the field mapping UI.
		 *
		 * Example:
		 * add_filter( 'surecontact_meta_field_groups', function( $groups ) {
		 *     $groups['my_integration'] = array(
		 *         'title' => 'My Integration',
		 *         'url'   => 'https://example.com/docs/',  // Optional
		 *     );
		 *     return $groups;
		 * } );
		 *
		 * @since 0.0.1
		 *
		 * @param array $groups Field groups array
		 */
		$groups = apply_filters( 'surecontact_meta_field_groups', $groups );

		// Add custom and extra groups after integration groups.
		$groups['custom'] = array(
			'title' => __( 'Custom Field Keys (Added Manually)', 'surecontact' ),
		);

		$groups['extra'] = array(
			'title' => __( 'Additional wp_usermeta Table Fields', 'surecontact' ),
		);

		return $groups;
	}

	/**
	 * Get group title for field grouping
	 *
	 * @since 0.0.1
	 *
	 * @param string $group_key Group key.
	 * @return string Group title
	 */
	private function get_group_title( $group_key ) {
		$groups = $this->get_field_groups();

		// Return group title if exists.
		if ( isset( $groups[ $group_key ]['title'] ) ) {
			return $groups[ $group_key ]['title'];
		}

		// Fallback to formatted group key.
		return ucwords( str_replace( '_', ' ', $group_key ) );
	}

	/**
	 * Get available meta fields
	 * Returns the loaded meta fields (from wordpress-fields.php + integrations)
	 *
	 * @since 0.0.1
	 *
	 * @return array Available meta fields
	 */
	public function get_meta_fields() {
		return $this->meta_fields;
	}

	/**
	 * Get contact fields configuration
	 * Returns the current contact field mappings (what's syncing)
	 *
	 * @since 0.0.1
	 *
	 * @return array Contact fields configuration
	 */
	public function get_contact_fields() {
		return $this->contact_fields;
	}

	/**
	 * Map raw data to CRM format
	 *
	 * Converts data from WordPress plugins to the standardized SureContact format:
	 * - primary_fields: Always available (email, first_name, last_name, phone, company, job_title)
	 * - custom_fields: Available if synced from CRM and mapped in settings UI
	 * - metadata: System/context information (source, timestamp, site_url, etc.)
	 *
	 * @since 0.0.1
	 *
	 * @param array  $raw_data   Raw data from WordPress.
	 * @param string $source     Data source (e.g., 'WordPress', 'woocommerce', 'contact_form_7').
	 * @return array Mapped data in SureContact format
	 */
	public function map_to_crm_format( $raw_data, $source = 'WordPress' ) {
		if ( ! is_array( $raw_data ) || empty( $raw_data ) ) {
			return array();
		}

		// Define primary fields that should go in primary_fields (always available).
		$primary_field_keys = self::get_primary_field_keys();

		$primary_fields = array();
		$custom_fields  = array();
		$metadata       = array();

		// Add source metadata - information that cannot be mapped via settings UI.
		$metadata['_source']    = $source;
		$metadata['_mapped_at'] = current_time( 'mysql' );
		$metadata['site_url']   = get_site_url();

		// Add WordPress user ID if available.
		if ( ! empty( $raw_data['user_id'] ) ) {
			$metadata['wp_user_id'] = (int) $raw_data['user_id'];
		}

		// Add registration date if available.
		if ( ! empty( $raw_data['user_registered'] ) || ! empty( $raw_data['registration_date'] ) ) {
			$metadata['registration_date'] = $raw_data['user_registered'] ?? $raw_data['registration_date'];
		}

		// Get CRM field types for proper formatting based on target field type.
		$crm_field_types = self::get_all_crm_field_types();

		// Iterate through configured contact fields.
		foreach ( $this->contact_fields as $wp_field => $field_config ) {
			// Skip if field is not active.
			if ( empty( $field_config['active'] ) ) {
				continue;
			}

			// Skip if no CRM field mapping.
			if ( empty( $field_config['crm_field'] ) ) {
				continue;
			}

			// Check if this field exists in raw data.
			if ( ! array_key_exists( $wp_field, $raw_data ) ) {
				continue;
			}

			$value     = $raw_data[ $wp_field ];
			$crm_field = $field_config['crm_field'];

			$field_type = isset( $crm_field_types[ $crm_field ] ) ? $crm_field_types[ $crm_field ] : $field_config['type'];

			// Format the value based on CRM field type.
			$formatted_value = Field_Formatter::format_value_by_type( $value, $field_type, $crm_field );

			// Only add non-empty values (but allow 0 and false).
			if ( Field_Formatter::should_include_value( $formatted_value ) ) {
				// Categorize into primary_fields or custom_fields.
				if ( in_array( $crm_field, $primary_field_keys, true ) ) {
					// Primary fields - always available.
					$primary_fields[ $crm_field ] = $formatted_value;
				} else {
					// Custom fields - only sent if available from API and mapped in settings UI.
					$custom_fields[ $crm_field ] = $formatted_value;
				}
			}
		}

		// Additional fallbacks for common primary fields if not already mapped
		// This ensures critical fields are captured even if field mappings aren't fully configured.
		$primary_field_types = self::get_primary_field_types();

		if ( empty( $primary_fields['email'] ) && ! empty( $raw_data['user_email'] ) ) {
			$primary_fields['email'] = Field_Formatter::format_value_by_type( $raw_data['user_email'], $primary_field_types['email'], 'email' );
		}

		if ( empty( $primary_fields['first_name'] ) && ! empty( $raw_data['first_name'] ) ) {
			$primary_fields['first_name'] = Field_Formatter::format_value_by_type( $raw_data['first_name'], $primary_field_types['first_name'], 'first_name' );
		}
		if ( empty( $primary_fields['last_name'] ) && ! empty( $raw_data['last_name'] ) ) {
			$primary_fields['last_name'] = Field_Formatter::format_value_by_type( $raw_data['last_name'], $primary_field_types['last_name'], 'last_name' );
		}

		// Also add phone if available and not mapped.
		if ( empty( $primary_fields['phone'] ) && ! empty( $raw_data['phone'] ) ) {
			$primary_fields['phone'] = Field_Formatter::format_value_by_type( $raw_data['phone'], $primary_field_types['phone'], 'phone' );
		}

		// Build final structure.
		$mapped_data = array(
			'primary_fields' => $primary_fields,
			'custom_fields'  => $custom_fields,
			'metadata'       => $metadata,
		);

		return $mapped_data;
	}

	/**
	 * Update contact fields configuration
	 *
	 * @since 0.0.1
	 *
	 * @param array $contact_fields New contact fields configuration.
	 * @return bool
	 */
	public function update_contact_fields( $contact_fields ) {
		$this->contact_fields = $contact_fields;
		return update_option( 'surecontact_contact_fields', $contact_fields );
	}

	/**
	 * Get all available WordPress meta fields
	 *
	 * Returns standard WordPress fields grouped by category:
	 * 1. Registered fields (from wordpress-fields.php + integrations) - go to their defined group
	 * 2. Custom fields (manually added) - go to 'custom' group
	 * 3. Auto-discovered fields (from wp_usermeta) - go to 'extra' group
	 *
	 * @since 0.0.1
	 *
	 * @return array Available WordPress fields grouped by category
	 */
	public function get_available_wordpress_fields() {
		$field_groups     = array();
		$all_field_groups = $this->get_field_groups();

		// Initialize all registered groups.
		foreach ( $all_field_groups as $group_key => $group_config ) {
			$field_groups[ $group_key ] = array(
				'title'  => $group_config['title'],
				'url'    => isset( $group_config['url'] ) ? $group_config['url'] : '',
				'fields' => array(),
			);
		}

		// Group registered fields (from meta_fields) by their group.
		foreach ( $this->meta_fields as $field_key => $config ) {
			$group = isset( $config['group'] ) ? $config['group'] : 'wp';

			// Ensure group exists.
			if ( ! isset( $field_groups[ $group ] ) ) {
				$field_groups[ $group ] = array(
					'title'  => $this->get_group_title( $group ),
					'fields' => array(),
				);
			}

			// Add field to its group.
			$field_groups[ $group ]['fields'][ $field_key ] = array(
				'label' => $config['label'],
				'type'  => $config['type'],
			);
		}

		// Add custom fields (manually added via custom_metafields option).
		$custom_fields = get_option( 'surecontact_custom_metafields', array() );
		foreach ( $custom_fields as $key ) {
			$field_groups['custom']['fields'][ $key ] = array(
				'label' => ucwords( str_replace( array( '_', '-' ), ' ', $key ) ),
				'type'  => 'text',
			);
		}

		// Add auto-discovered fields to 'extra' group.
		$discovered_fields = $this->discover_custom_meta_fields();
		if ( ! empty( $discovered_fields ) ) {
			foreach ( $discovered_fields as $key => $config ) {
				$field_groups['extra']['fields'][ $key ] = $config;
			}
		}

		/**
		 * Filter all available meta fields
		 *
		 * @since 0.0.1
		 *
		 * @param array $field_groups All field groups with their fields
		 */
		return $field_groups;
	}

	/**
	 * Discover custom user meta fields from wp_usermeta table
	 *
	 * @since 0.0.1
	 *
	 * @return array Discovered meta fields
	 */
	private function discover_custom_meta_fields() {
		// Check cache first.
		$cache_key = 'surecontact_discovered_meta_fields';
		$cached    = wp_cache_get( $cache_key, 'surecontact' );
		if ( false !== $cached ) {
			return $cached;
		}

		// Query distinct meta keys across all users so fields aren't limited
		// to what the current logged-in admin has on their own profile.
		global $wpdb;

		// `closedpostboxes_*` and `metaboxhidden_*` are written by WP per admin
		// screen per user, so they balloon in volume and would flood the
		// field-mapping UI as junk entries.
		$underscore_pattern    = $wpdb->esc_like( '_' ) . '%';
		$prefix_pattern        = $wpdb->esc_like( $wpdb->base_prefix ) . '%';
		$closedpostboxes_match = $wpdb->esc_like( 'closedpostboxes_' ) . '%';
		$metaboxhidden_match   = $wpdb->esc_like( 'metaboxhidden_' ) . '%';

		$meta_keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Need DISTINCT meta_key across all users; no WP API for this.
			$wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$wpdb->usermeta}
				 WHERE meta_key NOT LIKE %s
				 AND meta_key NOT LIKE %s
				 AND meta_key NOT LIKE %s
				 AND meta_key NOT LIKE %s
				 ORDER BY meta_key",
				$underscore_pattern,
				$prefix_pattern,
				$closedpostboxes_match,
				$metaboxhidden_match
			)
		);

		if ( empty( $meta_keys ) ) {
			wp_cache_set( $cache_key, array(), 'surecontact', 300 );
			return array();
		}

		$discovered = array();

		foreach ( $meta_keys as $key ) {
			// Skip if already in meta_fields.
			if ( isset( $this->meta_fields[ $key ] ) ) {
				continue;
			}

			$discovered[ $key ] = array(
				'label' => ucwords( str_replace( array( '_', '-' ), ' ', $key ) ),
				'type'  => 'text',
			);
		}

		// Cache the results for 5 minutes.
		wp_cache_set( $cache_key, $discovered, 'surecontact', 300 );

		return $discovered;
	}

	/**
	 * Get available CRM fields
	 *
	 * @since 0.0.1
	 *
	 * @param bool $force_refresh Force refresh from API.
	 * @return array Available CRM fields
	 */
	public function get_available_crm_fields( $force_refresh = false ) {
		if ( $force_refresh || empty( $this->crm_fields ) ) {
			$this->sync_crm_fields();
		}

		return $this->crm_fields;
	}

	/**
	 * Sync CRM fields from API
	 *
	 * Fetches custom fields from SureContact API and stores them locally.
	 * Primary fields are always available and don't need to be synced.
	 *
	 * @since 0.0.1
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure
	 */
	public function sync_crm_fields() {
		// Get custom fields from CRM API using Fields_API.
		$response = $this->fields_api->get_custom_fields();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse the response.
		$custom_fields = $this->parse_custom_fields_response( $response );

		// Store the custom fields using Synced_Metadata (consolidated option).
		Synced_Metadata::set_custom_fields( $custom_fields );

		// Update last sync time.
		update_option( 'surecontact_last_field_sync', time() );

		// Reload CRM fields with primary + custom.
		$this->load_crm_fields();

		return true;
	}

	/**
	 * Parse custom fields API response
	 *
	 * Parses the response from get_custom_fields API endpoint.
	 * Returns only custom fields since primary fields are always available.
	 *
	 * Expected API response structure:
	 * {
	 *   "success": true,
	 *   "data": {
	 *     "workspace_uuid": "...",
	 *     "workspace_name": "...",
	 *     "custom_fields": [
	 *       {
	 *         "name": "customer_id",
	 *         "label": "Customer ID",
	 *         "field_type": "text",
	 *         "is_required": true,
	 *         "options": null,
	 *         "placeholder": "Enter customer ID",
	 *         "help_text": "Unique customer identifier"
	 *       }
	 *     ]
	 *   }
	 * }
	 *
	 * @since 0.0.1
	 *
	 * @param array $response API response.
	 * @return array Parsed custom fields array with metadata
	 */
	private function parse_custom_fields_response( $response ) {
		$custom_fields = array();

		// Extract fields array from WordPress endpoint response.
		if ( isset( $response['data']['custom_fields'] ) && is_array( $response['data']['custom_fields'] ) ) {
			$fields_array = $response['data']['custom_fields'];
		} else {
			// No valid fields found.
			return $custom_fields;
		}

		foreach ( $fields_array as $field ) {
			// Skip if not a valid field array with required keys.
			if ( ! is_array( $field ) || ! isset( $field['name'], $field['label'] ) ) {
				continue;
			}

			$field_name  = sanitize_text_field( $field['name'] );
			$field_label = sanitize_text_field( $field['label'] );

			// Store complete field metadata for use in UI and mapping.
			$custom_fields[ $field_name ] = array(
				'label'       => $field_label,
				'field_type'  => isset( $field['field_type'] ) ? sanitize_text_field( $field['field_type'] ) : 'text',
				'is_required' => isset( $field['is_required'] ) ? (bool) $field['is_required'] : false,
				'options'     => isset( $field['options'] ) && is_array( $field['options'] ) ? array_map( 'sanitize_text_field', $field['options'] ) : null,
				'placeholder' => isset( $field['placeholder'] ) ? sanitize_text_field( $field['placeholder'] ) : '',
				'help_text'   => isset( $field['help_text'] ) ? sanitize_text_field( $field['help_text'] ) : '',
			);
		}

		// Sort by label for better UI display.
		uasort(
			$custom_fields,
			function ( $a, $b ) {
				return strcmp( $a['label'], $b['label'] );
			}
		);

		return $custom_fields;
	}

	/**
	 * Get last sync time
	 *
	 * @since 0.0.1
	 *
	 * @return int|false Timestamp or false if never synced
	 */
	public function get_last_sync_time() {
		return get_option( 'surecontact_last_field_sync', false );
	}

	/**
	 * Format last sync time for display
	 *
	 * @since 0.0.1
	 *
	 * @return string Formatted time or 'Never'
	 */
	public function get_last_sync_time_formatted() {
		$timestamp = $this->get_last_sync_time();

		if ( ! $timestamp ) {
			return __( 'Never', 'surecontact' );
		}

		return sprintf(
			/* translators: %s: human readable time difference */
			__( '%s ago', 'surecontact' ),
			human_time_diff( $timestamp, time() )
		);
	}

	/**
	 * Bulk update contact fields from settings page
	 *
	 * @since 0.0.1
	 *
	 * @param array $posted_data Posted form data.
	 * @return bool Success status
	 */
	public function save_field_mappings_from_post( $posted_data ) {
		if ( ! isset( $posted_data['surecontact_contact_fields'] ) ) {
			return false;
		}

		$contact_fields_data    = $posted_data['surecontact_contact_fields'];
		$updated_contact_fields = array();

		foreach ( $contact_fields_data as $wp_field => $field_data ) {
			// Sanitize the data
			// IMPORTANT: Checkbox 'active' will only be present if checked
			// If not present, it means the checkbox was unchecked.
			$active     = isset( $field_data['active'] ) && '1' === $field_data['active'];
			$crm_field  = isset( $field_data['crm_field'] ) ? sanitize_text_field( $field_data['crm_field'] ) : '';
			$field_type = isset( $field_data['type'] ) ? sanitize_text_field( $field_data['type'] ) : 'text';

			// Get label from meta_fields or existing contact_fields.
			$label = '';
			if ( isset( $this->meta_fields[ $wp_field ]['label'] ) ) {
				$label = $this->meta_fields[ $wp_field ]['label'];
			} elseif ( isset( $this->contact_fields[ $wp_field ]['label'] ) ) {
				$label = $this->contact_fields[ $wp_field ]['label'];
			} else {
				$label = ucwords( str_replace( '_', ' ', $wp_field ) );
			}

			$updated_contact_fields[ $wp_field ] = array(
				'label'     => $label,
				'type'      => $field_type,
				'crm_field' => $crm_field,
				'active'    => $active,
			);
		}

		return $this->update_contact_fields( $updated_contact_fields );
	}
}
