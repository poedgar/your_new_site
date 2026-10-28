<?php
/**
 * Base Integration Class
 *
 * Base class for all plugin integrations
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact\Integrations;

use SureContact\Field_Mapper;
use SureContact\Field_Formatter;
use SureContact\Contact_Service;
use SureContact\Database\Integrations_DB;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Base_Integration
 *
 * Abstract base class that all integrations extend
 *
 * @since 0.0.1
 */
abstract class Base_Integration {

	/**
	 * Integration slug
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	protected $slug;

	/**
	 * Integration name
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Integration description
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	protected $description;

	/**
	 * Integration documentation URL
	 *
	 * @since 0.0.2
	 *
	 * @var string
	 */
	protected $docs_url = '';

	/**
	 * Integration icon URL
	 *
	 * @since 1.2.0
	 *
	 * @var string
	 */
	protected $icon_url = '';

	/**
	 * Whether this integration requires field mapping
	 *
	 * @since 0.0.2
	 *
	 * @var bool
	 */
	protected $require_field_mapping = false;

	/**
	 * Dependency class name to check if plugin is active
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	protected $dependency;

	/**
	 * Field Mapper instance
	 *
	 * @since 0.0.1
	 *
	 * @var Field_Mapper
	 */
	protected $field_mapper;

	/**
	 * Contact Service instance
	 *
	 * @since 0.0.1
	 *
	 * @var Contact_Service
	 */
	protected $contact_service;

	/**
	 * Whether this integration is enabled
	 *
	 * @since 0.0.1
	 *
	 * @var bool
	 */
	protected $enabled = false;

	/**
	 * Integrations database instance.
	 *
	 * @since 0.0.4
	 *
	 * @var Integrations_DB
	 */
	protected $integrations_db;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		// Cache database instance for reuse across methods.
		$this->integrations_db = Integrations_DB::get_instance();

		// Lazy load Field_Mapper to avoid translation issues before textdomain is loaded.
		// $this->field_mapper will be instantiated on first use via get_field_mapper().
		$this->contact_service = new Contact_Service();

		// Check if integration is enabled in database.
		$this->enabled = $this->is_enabled();

		// Register field groups and fields hooks (always register for field mapping UI).
		// These hooks allow integrations to register their own field groups and fields.
		add_filter( 'surecontact_meta_field_groups', array( $this, 'add_meta_field_group' ), 10 );
		add_filter( 'surecontact_meta_fields', array( $this, 'add_meta_fields' ), 10 );

		// Only initialize hooks if integration is enabled.
		if ( $this->enabled ) {
			$this->init();
		}
	}

	/**
	 * Get Field_Mapper instance (lazy loaded)
	 *
	 * @since 0.0.1
	 *
	 * @return Field_Mapper
	 */
	public function get_field_mapper() {
		if ( is_null( $this->field_mapper ) ) {
			$this->field_mapper = new Field_Mapper();
		}
		return $this->field_mapper;
	}

	/**
	 * Initialize integration
	 * Must be implemented by child classes
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	abstract protected function init();

	/**
	 * Add integration field group to the global field groups
	 *
	 * Each integration can register its own field group that will be displayed
	 * in the field mapping UI.
	 *
	 * Override in child classes to register integration-specific field groups.
	 *
	 * @since 0.0.1
	 *
	 * @param array $groups Existing field groups.
	 * @return array Modified field groups
	 */
	public function add_meta_field_group( $groups ) {
		// Override in child class to add custom field groups.
		return $groups;
	}

	/**
	 * Add integration-specific fields to the global meta fields registry
	 *
	 * Each integration adds fields with metadata including group assignment,
	 * type, and mapping info.
	 *
	 * Override in child classes to register integration-specific fields.
	 *
	 * @since 0.0.1
	 *
	 * @param array $fields Existing meta fields.
	 * @return array Modified meta fields
	 */
	public function add_meta_fields( $fields ) {
		// Override in child class to add custom fields.
		return $fields;
	}

	/**
	 * Check if integration is enabled in settings
	 *
	 * @since 0.0.1
	 *
	 * @return bool
	 */
	protected function is_enabled() {
		// WordPress integration is always enabled (it's the base system, not an integration).
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- This is a slug identifier, not the brand name.
		if ( $this->slug === 'wordpress' ) {
			return true;
		}

		$result = $this->integrations_db->get( $this->slug, null );

		// Check if global integration is enabled.
		if ( $result && ! empty( $result['status'] ) ) {
			return true;
		}

		// If global integration is not enabled, check if ANY product-level configs are enabled.
		// This allows product-specific integrations to work even when global integration is disabled.
		$all_configs = $this->integrations_db->get_all( $this->slug );
		foreach ( $all_configs as $config ) {
			// Skip global config (already checked above).
			if ( ! isset( $config['item_id'] ) || is_null( $config['item_id'] ) || $config['item_id'] === '' ) {
				continue;
			}

			// If any product-level config is enabled, enable the integration.
			if ( ! empty( $config['status'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if GLOBAL integration is enabled (not product/item-level)
	 *
	 * Use this to check if global-level settings should be applied,
	 * such as track_orders, track_refunds, customer_registration_lists, etc.
	 *
	 * @since 0.0.3
	 *
	 * @return bool True if global integration is enabled, false otherwise.
	 */
	protected function is_global_enabled() {
		// WordPress integration is always enabled (it's the base system, not an integration).
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- This is a slug identifier, not the brand name.
		if ( $this->slug === 'wordpress' ) {
			return true;
		}

		$result = $this->integrations_db->get( $this->slug, null );

		return $result && ! empty( $result['status'] );
	}

	/**
	 * Check if integration dependency (plugin) is active
	 *
	 * @since 0.0.1
	 *
	 * @return bool
	 */
	protected function is_dependency_active() {
		if ( empty( $this->dependency ) ) {
			return true; // No dependency required.
		}

		// Check if class exists.
		if ( class_exists( $this->dependency ) ) {
			return true;
		}

		// Check if function exists.
		if ( function_exists( $this->dependency ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalize data from integration to standard format
	 *
	 * @since 0.0.1
	 *
	 * @param array $raw_data Raw data from integration.
	 * @return array Normalized data
	 */
	public function normalize_data( $raw_data ) {
		return $this->get_field_mapper()->map_to_crm_format( $raw_data, $this->slug );
	}

	/**
	 * Format field mapping data with basic validation
	 *
	 * This helper method applies basic formatting to field-mapped data
	 * to ensure common issues are resolved before sending to CRM.
	 *
	 * IMPORTANT: Always includes mapped fields in the request, even with false/empty values,
	 * to ensure the backend can properly override existing data.
	 *
	 * @since 0.0.3
	 *
	 * @param array $field_mapping Associative array of form_field => crm_field mappings.
	 * @param array $submission_data Raw submission data.
	 * @return array Formatted contact data ready for CRM
	 */
	protected function format_field_mapping_data( $field_mapping, $submission_data ) {
		$contact_data = array();

		if ( ! empty( $field_mapping ) && is_array( $field_mapping ) ) {
			// Get ALL CRM field types (primary + custom) for type-based formatting.
			$crm_field_types = Field_Mapper::get_all_crm_field_types();

			foreach ( $field_mapping as $form_field => $crm_field ) {
				// Skip if CRM field is not set or is empty.
				if ( empty( $crm_field ) ) {
					continue;
				}

				// Get value from submission.
				$value = $this->get_submission_field_value( $submission_data, $form_field );

				// Only exclude null (field not found in submission).
				// Include all other values: strings, booleans (including false), arrays (including empty), numbers (including 0).
				if ( $value !== null ) {
					$source_format = $this->resolve_date_source_format( $form_field, $crm_field, $crm_field_types[ $crm_field ] ?? '' );

					// Apply type-based formatting if field type is known, otherwise use name-based formatting.
					if ( isset( $crm_field_types[ $crm_field ] ) ) {
						// Pass $crm_field as third parameter for field-specific validation (gender, prefix, suffix).
						$formatted_value = Field_Formatter::format_value_by_type( $value, $crm_field_types[ $crm_field ], $crm_field, $source_format );
					} else {
						$formatted_value = Field_Formatter::format_field_value( $value, $crm_field, $source_format );
					}

					// Always include mapped fields to ensure backend can override existing values.
					if ( Field_Formatter::should_include_value( $formatted_value ) ) {
						$contact_data[ $crm_field ] = $formatted_value;
					}
				}
			}
		}

		return $contact_data;
	}

	/**
	 * Resolve the date format a form field submits its value in
	 *
	 * Only date-like CRM fields need this. Without it the formatter has to guess,
	 * and strtotime() reads every slash-separated date as American m/d/Y.
	 *
	 * @since 1.5.5
	 *
	 * @param string $form_field      Form field key.
	 * @param string $crm_field       CRM field key.
	 * @param string $crm_field_type  CRM field type, empty when unknown.
	 * @return string PHP date format (e.g. 'd/m/Y'), or empty string when unknown
	 */
	protected function resolve_date_source_format( $form_field, $crm_field, $crm_field_type ) {
		$date_types = array( 'date', 'datetime', 'timestamp' );

		// An unknown type falls back to name-based formatting, which also formats dates.
		if ( '' !== $crm_field_type && ! in_array( $crm_field_type, $date_types, true ) ) {
			return '';
		}

		/**
		 * Filters the PHP date format a form field submits its value in.
		 *
		 * Integrations that know the declared format supply it through
		 * get_field_source_format(). Use this filter for the ones that do not.
		 *
		 * @since 1.5.5
		 *
		 * @param string $source_format PHP date format (e.g. 'd/m/Y'), empty when unknown.
		 * @param string $form_field    Form field key.
		 * @param string $crm_field     CRM field key.
		 * @param string $slug          Integration slug.
		 */
		$source_format = apply_filters(
			'surecontact_date_source_format',
			$this->get_field_source_format( $form_field ),
			$form_field,
			$crm_field,
			$this->slug
		);

		return is_string( $source_format ) ? $source_format : '';
	}

	/**
	 * Get the date format a form field submits its value in
	 *
	 * Integrations that can read the declared format of a date field should
	 * override this. Returning an empty string leaves the formatter to resolve
	 * the value on its own.
	 *
	 * @since 1.5.5
	 *
	 * @param string $form_field Form field key.
	 * @return string PHP date format (e.g. 'd/m/Y'), or empty string when unknown
	 */
	protected function get_field_source_format( $form_field ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $form_field is used by the child implementations.
		return '';
	}

	/**
	 * Get field value from submission data (helper method)
	 *
	 * Override this method in child classes to handle integration-specific
	 * data structures (e.g., nested arrays, prefixed keys, etc.)
	 *
	 * @since 0.0.3
	 *
	 * @param array  $submission_data Raw submission data.
	 * @param string $field_key       Field key to retrieve.
	 * @return mixed RAW field value or null
	 */
	protected function get_submission_field_value( $submission_data, $field_key ) {
		// Default implementation: direct array access.
		return isset( $submission_data[ $field_key ] ) ? $submission_data[ $field_key ] : null;
	}

	/**
	 * Build CRM data structure directly (for integrations with their own field mapping)
	 *
	 * USE THIS METHOD WHEN:
	 * - Your integration has its own field mapping UI (like per-form settings)
	 * - You already know which fields are primary vs custom
	 * - You want to bypass the global field mapper (surecontact_contact_fields)
	 *
	 * Examples: SureForms, Contact Form 7, WPForms, Gravity Forms
	 *
	 * USE normalize_data() INSTEAD WHEN:
	 * - Syncing WordPress user data
	 * - Need to respect global field mapping settings
	 * - Don't have integration-specific field mapping
	 *
	 * Examples: User registration, profile updates, WooCommerce customer data
	 *
	 * @since 0.0.1
	 *
	 * @param array $primary_fields Primary fields (email, first_name, last_name, phone, etc.) - already sanitized.
	 * @param array $custom_fields  Custom fields (optional) - already sanitized.
	 * @param array $metadata       Metadata (optional, auto-adds source, timestamp, site_url).
	 * @return array CRM data structure
	 */
	public function build_crm_data( $primary_fields = array(), $custom_fields = array(), $metadata = array() ) {
		// Add default metadata.
		$default_metadata = array(
			'_source'    => $this->slug,
			'_mapped_at' => current_time( 'mysql' ),
			'site_url'   => get_site_url(),
		);

		$metadata = array_merge( $default_metadata, $metadata );

		return array(
			'primary_fields' => $primary_fields,
			'custom_fields'  => $custom_fields,
			'metadata'       => $metadata,
		);
	}

	/**
	 * Send data to CRM
	 *
	 * @since 0.0.1
	 *
	 * @param array $data     Normalized data.
	 * @param int   $user_id  User ID (if applicable).
	 * @param array $context  Additional context (can include 'list_uuids', 'tag_uuids').
	 * @return array|\WP_Error Response or error
	 */
	public function send_to_crm( $data, $user_id = 0, $context = array() ) {
		if ( empty( $data ) ) {
			return new \WP_Error( 'empty_data', 'No data to send to CRM' );
		}

		$data = $this->prepare_lists_and_tags( $data, $user_id, $context );

		$result = $this->contact_service->create_contact( $data, $user_id, array( 'source' => $this->slug ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Handle queued responses (requests that failed immediately and were queued for retry).
		if ( is_array( $result ) && isset( $result['queued'] ) && $result['queued'] === true ) {
			return $result;
		}

		/**
		 * Fires after a contact has been successfully synced to the CRM.
		 *
		 * Only fires on immediate success (not queued, not errored).
		 *
		 * @since 1.2.0
		 *
		 * @param string $slug    Integration slug.
		 * @param int    $user_id WordPress user ID (0 if not applicable).
		 * @param array  $data    Normalized data sent to the CRM.
		 * @param array  $result  API response from the CRM.
		 */
		do_action( 'surecontact_contact_synced', $this->slug, $user_id, $data, $result );

		return $result;
	}

	/**
	 * Get integration-specific settings fields
	 *
	 * Override this method in child classes to register settings for the integration.
	 * Settings will be displayed in an expandable section in the integration card.
	 *
	 * @since 0.0.1
	 *
	 * @return array Array of settings fields with structure:
	 *               [
	 *                   'field_key' => [
	 *                       'label' => 'Field Label',
	 *                       'description' => 'Optional description',
	 *                       'type' => 'text|select|checkbox|multi-select|number',
	 *                       'default' => 'default value',
	 *                       'options' => [], // For select/multi-select types
	 *                       'placeholder' => 'Optional placeholder'
	 *                   ]
	 *               ]
	 */
	public function get_settings_fields() {
		return array();
	}

	/**
	 * Get available events for a specific item type.
	 *
	 * Override this method in child classes to provide item-type-specific events.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_type Item type (e.g., 'product', 'order', 'form').
	 * @return array Array of event definitions with 'key' and 'label' keys.
	 *               Example: array( array( 'key' => 'purchase', 'label' => 'Purchase' ) )
	 */
	public function get_events_by_item_type( $item_type ) {
		// Prevent unused parameter warning.
		unset( $item_type );

		// Default: return empty array. Override in child classes.
		return array();
	}

	/**
	 * Get all available item types for this integration.
	 *
	 * Override this method in child classes to define supported item types.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of item type definitions with 'key' and 'label' keys.
	 *               Example: array( array( 'key' => 'product', 'label' => 'Product' ) )
	 */
	public function get_item_types() {
		return array();
	}

	/**
	 * Get additional plugin dependencies for this integration.
	 *
	 * Override in integrations that have item types requiring plugins beyond
	 * the integration's default plugin_file. Each entry defines a plugin once
	 * by a unique key — item types reference these keys via get_item_type_plugin_requirements().
	 *
	 * @since 1.2.0
	 *
	 * @return array Keyed array of plugin_key => array( plugin_file, plugin_name, plugin_dependencies ).
	 */
	public function get_additional_plugins() {
		return array();
	}

	/**
	 * Get item type to plugin requirement mapping.
	 *
	 * Maps item_type keys to plugin keys defined in get_additional_plugins().
	 * Item types NOT listed here use the integration's default plugin.
	 *
	 * @since 1.2.0
	 *
	 * @return array Map of item_type_key => plugin_key.
	 */
	public function get_item_type_plugin_requirements() {
		return array();
	}

	/**
	 * Build the resolved item type plugin map.
	 *
	 * Combines get_additional_plugins() definitions with get_item_type_plugin_requirements()
	 * mappings into a flat map consumed by the REST API.
	 *
	 * @since 1.2.0
	 *
	 * @return array Map of item_type_key => array( plugin_file, plugin_name, plugin_dependencies ).
	 */
	final public function build_item_type_plugin_map() {
		$plugins      = $this->get_additional_plugins();
		$requirements = $this->get_item_type_plugin_requirements();

		if ( empty( $plugins ) || empty( $requirements ) ) {
			return array();
		}

		$resolved = array();
		foreach ( $requirements as $item_type => $plugin_key ) {
			if ( isset( $plugins[ $plugin_key ] ) ) {
				$resolved[ $item_type ] = $plugins[ $plugin_key ];
			}
		}

		return $resolved;
	}

	/**
	 * Get integration slug
	 *
	 * @since 1.2.0
	 *
	 * @return string Integration slug
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Get integration name
	 *
	 * @since 1.2.0
	 *
	 * @return string Integration name
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Get integration description
	 *
	 * @since 0.0.2
	 *
	 * @return string Integration description
	 */
	public function get_description() {
		return $this->description;
	}

	/**
	 * Get integration documentation URL
	 *
	 * @since 0.0.2
	 *
	 * @return string Integration documentation URL
	 */
	public function get_docs_url() {
		return $this->docs_url;
	}

	/**
	 * Get integration icon URL
	 *
	 * @since 1.2.0
	 *
	 * @return string Integration icon URL
	 */
	public function get_icon_url() {
		return $this->icon_url;
	}

	/**
	 * Get whether integration requires field mapping
	 *
	 * @since 0.0.2
	 *
	 * @return bool Whether integration requires field mapping
	 */
	public function get_require_field_mapping() {
		return $this->require_field_mapping;
	}

	/**
	 * Get integration settings from database
	 *
	 * @since 0.0.1
	 *
	 * @return array Integration settings
	 */
	protected function get_settings() {
		$result = $this->integrations_db->get( $this->slug, null );

		$settings = ( $result && ! empty( $result['config'] ) ) ? $result['config'] : array();

		// Merge with defaults from field definitions.
		$fields   = $this->get_settings_fields();
		$defaults = array();

		foreach ( $fields as $key => $field ) {
			$defaults[ $key ] = isset( $field['default'] ) ? $field['default'] : $this->get_default_for_type( $field['type'] );
		}

		return array_merge( $defaults, $settings );
	}

	/**
	 * Get a specific setting value
	 *
	 * @since 0.0.1
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default_value Default value if setting doesn't exist.
	 * @return mixed Setting value
	 */
	public function get_setting( $key, $default_value = null ) {
		$settings = $this->get_settings();

		if ( isset( $settings[ $key ] ) ) {
			return $settings[ $key ];
		}

		return $default_value;
	}

	/**
	 * Check if a setting is enabled (handles multiple truthy formats)
	 *
	 * This helper method checks if a checkbox/boolean setting is enabled,
	 * handling various truthy formats that may be stored in the database.
	 *
	 * @since 0.0.3
	 *
	 * @param string $setting_key Setting key to check.
	 * @param mixed  $default_value Default value if setting doesn't exist.
	 * @return bool True if enabled
	 */
	public function is_setting_enabled( $setting_key, $default_value = true ) {
		$value = $this->get_setting( $setting_key, $default_value );
		return $value === true || $value === 1 || $value === '1' || $value === 'on';
	}

	/**
	 * Get default value for a field type
	 *
	 * @since 0.0.1
	 *
	 * @param string $type Field type.
	 * @return mixed Default value
	 */
	private function get_default_for_type( $type ) {
		switch ( $type ) {
			case 'checkbox':
				return false;
			case 'multi-select':
				return array();
			case 'number':
				return 0;
			default:
				return '';
		}
	}



	/**
	 * Prepare lists, tags, and custom fields to be included in contact data
	 *
	 * @since 0.0.1
	 *
	 * @param array $data     Contact data.
	 * @param int   $user_id  User ID.
	 * @param array $context  Context with optional list_uuids, tag_uuids, custom_fields.
	 * @return array Modified contact data with lists, tags, and custom fields
	 */
	protected function prepare_lists_and_tags( $data, $user_id, $context ) {
		$list_uuids = $this->extract_uuids( isset( $context['list_uuids'] ) ? $context['list_uuids'] : array() );
		if ( ! empty( $list_uuids ) ) {
			$data['list_uuids'] = $list_uuids;
		}

		$tag_uuids = $this->extract_uuids( isset( $context['tag_uuids'] ) ? $context['tag_uuids'] : array() );
		if ( ! empty( $tag_uuids ) ) {
			$data['tag_uuids'] = $tag_uuids;
		}

		// Add custom fields from context if present.
		if ( ! empty( $context['custom_fields'] ) && is_array( $context['custom_fields'] ) ) {
			if ( ! isset( $data['custom_fields'] ) ) {
				$data['custom_fields'] = array();
			}
			$data['custom_fields'] = array_merge( $data['custom_fields'], $context['custom_fields'] );
		}

		return $data;
	}


	/**
	 * Get metadata mapping from integration settings
	 *
	 * Retrieves stored mappings between external system IDs and CRM UUIDs.
	 * Used for lists, tags, and custom fields mapping.
	 *
	 * @since 0.0.1
	 *
	 * @param string $type Mapping type: 'lists', 'tags', or 'custom_fields'.
	 * @return array Mappings array (external_id => crm_uuid).
	 */
	public function get_metadata_mapping( $type ) {
		$mappings = $this->get_setting( 'metadata_mappings', array() );
		return isset( $mappings[ $type ] ) ? $mappings[ $type ] : array();
	}

	/**
	 * Update metadata mapping in integration settings
	 *
	 * Merges new mappings with existing ones and stores in settings.
	 * Useful for incremental mapping updates.
	 *
	 * @since 0.0.1
	 *
	 * @param string $type     Mapping type: 'lists', 'tags', or 'custom_fields'.
	 * @param array  $mappings New mappings to merge (external_id => crm_uuid).
	 * @return void
	 */
	public function update_metadata_mapping( $type, $mappings ) {
		$all_mappings = $this->get_setting( 'metadata_mappings', array() );

		if ( ! isset( $all_mappings[ $type ] ) ) {
			$all_mappings[ $type ] = array();
		}

		$all_mappings[ $type ] = array_merge( $all_mappings[ $type ], $mappings );

		$this->update_setting( 'metadata_mappings', $all_mappings );
	}

	/**
	 * Convert external IDs to CRM UUIDs using stored mappings
	 *
	 * Useful for converting integration-specific IDs (e.g., FluentCRM list IDs)
	 * to SureContact UUIDs before sending to API.
	 *
	 * @since 0.0.1
	 *
	 * @param array  $external_ids Array of external IDs.
	 * @param string $type         Mapping type: 'lists' or 'tags'.
	 * @return array Array of CRM UUIDs (duplicates removed).
	 */
	public function convert_ids_to_uuids( $external_ids, $type ) {
		if ( empty( $external_ids ) || ! is_array( $external_ids ) ) {
			return array();
		}

		$mappings = $this->get_metadata_mapping( $type );
		$uuids    = array();

		foreach ( $external_ids as $external_id ) {
			if ( isset( $mappings[ $external_id ] ) ) {
				$uuids[] = $mappings[ $external_id ];
			}
		}

		return array_values( array_unique( $uuids ) );
	}

	/**
	 * Update a specific setting value and save
	 *
	 * Helper method to update individual settings without loading/merging manually.
	 *
	 * @since 0.0.1
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 * @return void
	 */
	protected function update_setting( $key, $value ) {
		$settings         = $this->get_settings();
		$settings[ $key ] = $value;

		$this->integrations_db->save( $this->slug, null, null, $settings, $this->is_enabled() ? 1 : 0 );
	}


	/**
	 * Get persistent store identifier based on site URL
	 *
	 * This ID is deterministic and based on the site URL, ensuring:
	 * - Survives disconnect/reconnect cycles
	 * - Survives plugin deactivation/reactivation
	 * - Survives plugin deletion/reinstallation (as long as site URL stays same)
	 * - Unique per WordPress installation
	 *
	 * @since 0.0.1
	 *
	 * @return string 5-character store identifier
	 */
	protected function get_persistent_store_id() {
		// Get the site URL (this is stable and unique per WordPress installation).
		$site_url = get_site_url();

		// Generate a hash from the site URL.
		// Using md5 for consistency and taking first 10 alphanumeric characters.
		$hash = md5( $site_url );

		// Take first 10 characters for the store ID.
		$store_id = substr( $hash, 0, 10 );

		return $store_id;
	}

	/**
	 * Generate unique order ID for CRM tracking
	 *
	 * Creates a standardized order ID format across all integrations.
	 * Format: INTEGRATION-storeId-orderId
	 * Example: WOO-a1b2c-12345, SUR-d3e4f-67890, EDD-g5h6i-11111
	 *
	 * The store ID is based on the site URL hash, making it:
	 * - Persistent across disconnect/reconnect cycles
	 * - Persistent across plugin reinstalls (as long as site URL doesn't change)
	 * - Unique per WordPress installation
	 * - Ensures refunds/cancellations work even after reconnecting
	 *
	 * @since 0.0.1
	 *
	 * @param string|int $order_id The original order ID from the integration.
	 * @param string     $prefix   The integration prefix (e.g., 'WOO', 'SUR', 'EDD').
	 * @return string Formatted unique order ID
	 */
	public function generate_unique_order_id( $order_id, $prefix = '' ) {
		// Get persistent store ID based on site URL hash.
		$store_id = $this->get_persistent_store_id();

		// Format: PREFIX-storeId-orderId.
		return $prefix . '-' . $store_id . '-' . $order_id;
	}

	/**
	 * Extract UUIDs from list or tag array
	 *
	 * Helper method to extract UUIDs from arrays that may contain objects or strings.
	 * Handles both formats:
	 * - Array of objects with 'uuid' property: [['uuid' => 'abc'], ['uuid' => 'def']]
	 * - Array of UUID strings: ['abc', 'def']
	 * - Mixed arrays: [['uuid' => 'abc'], 'def']
	 *
	 * @since 1.0.0
	 *
	 * @param array $items Array of lists or tags (objects with uuid property or strings).
	 * @return array Array of unique UUID strings
	 */
	public function extract_uuids( $items ) {
		if ( empty( $items ) || ! is_array( $items ) ) {
			return array();
		}

		$uuids = array_map(
			function ( $item ) {
				if ( is_array( $item ) && isset( $item['uuid'] ) ) {
					return $item['uuid'];
				}
				return $item;
			},
			$items
		);

		return array_values( array_unique( array_filter( $uuids ) ) );
	}

	/**
	 * Check if DB result has valid config with actions.
	 *
	 * @since 0.0.1
	 *
	 * @param array|null $result DB result.
	 * @return bool
	 */
	protected function has_valid_config( $result ) {
		return $result && ! empty( $result['status'] ) && ! empty( $result['config'] ) && $this->is_config_not_empty( $result['config'] );
	}

	/**
	 * Check if config array has any actions (lists or tags to add/remove).
	 *
	 * @since 0.0.1
	 *
	 * @param array $config Config array.
	 * @return bool
	 */
	protected function is_config_not_empty( $config ) {
		return ! empty( $config['add_lists'] ) || ! empty( $config['add_tags'] ) || ! empty( $config['remove_lists'] ) || ! empty( $config['remove_tags'] );
	}

	/**
	 * Merge config with defaults to ensure all list/tag keys exist.
	 *
	 * @since 0.0.1
	 *
	 * @param array $config Config array.
	 * @return array Config with guaranteed add_lists, add_tags, remove_lists, remove_tags keys.
	 */
	protected function merge_config_defaults( $config ) {
		return array(
			'add_lists'    => $config['add_lists'] ?? array(),
			'add_tags'     => $config['add_tags'] ?? array(),
			'remove_lists' => $config['remove_lists'] ?? array(),
			'remove_tags'  => $config['remove_tags'] ?? array(),
		);
	}

	/**
	 * Apply or remove lists for a contact with consistent error handling and logging.
	 *
	 * @since 1.2.0
	 *
	 * @param string $contact_id Contact UUID.
	 * @param array  $list_uuids Array of list UUIDs.
	 * @param string $action     Action to perform: 'attach' or 'detach'.
	 * @return bool True on success, false on error.
	 */
	protected function apply_or_remove_lists( $contact_id, $list_uuids, $action = 'attach' ) {
		if ( empty( $list_uuids ) ) {
			return false;
		}

		$source_options = array( 'source' => $this->slug );

		if ( $action === 'attach' ) {
			$result = $this->contact_service->attach_lists_to_contact( $contact_id, $list_uuids, $source_options );
		} else {
			$result = $this->contact_service->detach_lists_from_contact( $contact_id, $list_uuids, $source_options );
		}

		return ! is_wp_error( $result );
	}

	/**
	 * Apply or remove tags for a contact with consistent error handling and logging.
	 *
	 * @since 1.2.0
	 *
	 * @param string $contact_id Contact UUID.
	 * @param array  $tag_uuids  Array of tag UUIDs.
	 * @param string $action     Action to perform: 'apply' or 'remove'.
	 * @return bool True on success, false on error.
	 */
	protected function apply_or_remove_tags( $contact_id, $tag_uuids, $action = 'apply' ) {
		if ( empty( $tag_uuids ) ) {
			return false;
		}

		$source_options = array( 'source' => $this->slug );

		if ( $action === 'apply' ) {
			$result = $this->contact_service->attach_tags_to_contact( $contact_id, $tag_uuids, $source_options );
		} else {
			$result = $this->contact_service->detach_tags_from_contact( $contact_id, $tag_uuids, $source_options );
		}

		if ( is_wp_error( $result ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Apply remove actions for a contact from an actions array.
	 *
	 * Used by integrations that store remove_lists/remove_tags in an actions array
	 * (e.g., SureCart, LatePoint).
	 *
	 * @since 1.2.0
	 *
	 * @param string $contact_id Contact UUID.
	 * @param array  $actions    Actions array containing 'remove_lists' and 'remove_tags'.
	 * @return void
	 */
	protected function apply_remove_actions( $contact_id, $actions ) {
		if ( ! empty( $actions['remove_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $actions['remove_lists'] );
			$this->apply_or_remove_lists( $contact_id, $list_uuids, 'detach' );
		}

		if ( ! empty( $actions['remove_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $actions['remove_tags'] );
			$this->apply_or_remove_tags( $contact_id, $tag_uuids, 'remove' );
		}
	}

	/**
	 * Apply remove actions for a contact with explicit list/tag arrays.
	 *
	 * Used by integrations that pass remove_lists/remove_tags as separate parameters
	 * (e.g., WooCommerce, EDD).
	 *
	 * @since 0.0.1
	 *
	 * @param string $contact_id   Contact UUID.
	 * @param array  $remove_lists Lists to remove (raw config arrays with UUIDs).
	 * @param array  $remove_tags  Tags to remove (raw config arrays with UUIDs).
	 * @return void
	 */
	protected function apply_remove_actions_with_config( $contact_id, $remove_lists, $remove_tags ) {
		$source_options = array( 'source' => $this->slug );

		if ( ! empty( $remove_lists ) ) {
			$list_uuids = $this->extract_uuids( $remove_lists );
			if ( ! empty( $list_uuids ) ) {
				$this->contact_service->detach_lists_from_contact( $contact_id, $list_uuids, $source_options );
			}
		}

		if ( ! empty( $remove_tags ) ) {
			$tag_uuids = $this->extract_uuids( $remove_tags );
			if ( ! empty( $tag_uuids ) ) {
				$this->contact_service->detach_tags_from_contact( $contact_id, $tag_uuids, $source_options );
			}
		}
	}

	/**
	 * Get sync types for this integration
	 *
	 * Override in child classes to define sync types.
	 * Only 'type', 'title', and 'description' are needed —
	 * 'integration' and 'integration_name' are added automatically.
	 *
	 * @since 0.0.1
	 *
	 * @return array Array of sync type definitions.
	 */
	public function get_sync_types() {
		return array();
	}

	/**
	 * Get the display title for a given sync type identifier.
	 *
	 * @since 0.0.1
	 *
	 * @param string $sync_type Sync type identifier (e.g. 'woocommerce_customers').
	 * @return string Sync type title or empty string if not found.
	 */
	public function get_title_for_sync_type( $sync_type ) {
		if ( empty( $sync_type ) ) {
			return '';
		}

		foreach ( $this->get_sync_types() as $registered ) {
			if ( isset( $registered['type'] ) && $registered['type'] === $sync_type ) {
				return $registered['title'] ?? '';
			}
		}

		return '';
	}

	/**
	 * Register sync types for bulk sync
	 *
	 * @since 0.0.1
	 *
	 * @param array $sync_types Existing sync types.
	 * @return array Modified sync types.
	 */
	public function register_sync_type( $sync_types ) {
		foreach ( $this->get_sync_types() as $sync_type ) {
			$sync_type['integration']           = $this->slug;
			$sync_type['integration_name']      = $this->name;
			$sync_type['require_field_mapping'] = $this->require_field_mapping;
			$sync_types[]                       = $sync_type;
		}

		return $sync_types;
	}

	/**
	 * Get standard list/tag field definitions for rule-based integrations
	 *
	 * Returns the standard field definitions used by integrations that support
	 * adding/removing contacts to/from lists and tags. This provides consistency
	 * across all integrations and centralizes field definitions in one place.
	 *
	 * @since 0.0.3
	 *
	 * @return array Standard field definitions for add_lists, add_tags, remove_lists, remove_tags
	 */
	protected static function get_standard_list_tag_fields() {
		return array(
			'add_lists'    => array(
				'label'       => __( 'Add to Lists', 'surecontact' ),
				'description' => __( 'Select lists to add contacts to', 'surecontact' ),
				'type'        => 'list-select',
				'default'     => array(),
			),
			'add_tags'     => array(
				'label'       => __( 'Add Tags', 'surecontact' ),
				'description' => __( 'Select tags to add to contacts', 'surecontact' ),
				'type'        => 'tag-select',
				'default'     => array(),
			),
			'remove_lists' => array(
				'label'       => __( 'Remove from Lists', 'surecontact' ),
				'description' => __( 'Select lists to remove contacts from', 'surecontact' ),
				'type'        => 'list-select',
				'default'     => array(),
			),
			'remove_tags'  => array(
				'label'       => __( 'Remove Tags', 'surecontact' ),
				'description' => __( 'Select tags to remove from contacts', 'surecontact' ),
				'type'        => 'tag-select',
				'default'     => array(),
			),
		);
	}
}
