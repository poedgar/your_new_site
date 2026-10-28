<?php
/**
 * Contact Form 7 Integration
 *
 * Handles Contact Form 7 form submissions with rule-based field mapping
 *
 * @since 0.0.2
 *
 * @package SureContact
 */

namespace SureContact\Integrations;

use SureContact\Field_Mapper;
use SureContact\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Contact_Form_7_Integration
 *
 * Integrates Contact Form 7 with SureContact using the rule engine system.
 *
 * Configuration is managed entirely through the rule engine:
 * - Per-form field mapping configuration
 * - Per-form lists and tags assignment
 * - Enable/disable per form via rule status
 * - Data is built directly in CRM format using build_crm_data()
 *
 * All settings are stored in the integrations database table and managed
 * through the unified rule engine UI.
 *
 * @since 0.0.2
 */
class Contact_Form_7_Integration extends Base_Integration {

	/**
	 * Field types cache for current submission
	 *
	 * @since 0.0.3
	 *
	 * @var array Associative array of field_name => field_type
	 */
	private $current_form_field_types = array();

	/**
	 * Constructor
	 *
	 * @since 0.0.2
	 */
	public function __construct() {
		$this->slug        = 'contact-form-7';
		$this->name        = 'Contact Form 7';
		$this->description = __( 'Sync Contact Form 7 submissions with per-form field mapping', 'surecontact' );
		$this->docs_url    = '';
		$this->dependency  = 'WPCF7_ContactForm';

		parent::__construct();
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 0.0.2
	 *
	 * @return void
	 */
	protected function init() {
		// Hook into form submission.
		add_action( 'wpcf7_mail_sent', array( $this, 'handle_form_submission' ), 10, 1 );
	}

	/**
	 * Get all available item types for Contact Form 7.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of item type definitions with 'key' and 'label' keys.
	 */
	public function get_item_types() {
		return array(
			array(
				'key'   => 'form',
				'label' => __( 'Form', 'surecontact' ),
			),
		);
	}

	/**
	 * Get available events for a specific item type.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_type Item type (e.g., 'form').
	 * @return array Array of event definitions with 'key' and 'label' keys.
	 */
	public function get_events_by_item_type( $item_type ) {
		switch ( $item_type ) {
			case 'form':
				return array(
					array(
						'key'   => 'submission',
						'label' => __( 'Submission', 'surecontact' ),
					),
				);

			default:
				return array();
		}
	}

	/**
	 * Get item-specific configuration fields for a Contact Form 7 form.
	 *
	 * This method returns the configuration fields that will be shown in the UI
	 * when a specific form is selected.
	 *
	 * @since 0.0.3
	 *
	 * @param string      $item_id Form ID.
	 * @param string|null $event   Event name (not used - kept for compatibility).
	 * @return array Configuration fields schema.
	 */
	public function get_item_config_fields( $item_id, $event = null ) {
		// Return common configuration fields for form submissions.
		return array_merge(
			array(
				'field_mapping' => array(
					'label'       => __( 'Field Mapping', 'surecontact' ),
					'description' => __( 'Map form fields to CRM fields. At minimum, you should map the email field.', 'surecontact' ),
					'type'        => 'field-mapping',
					'default'     => array(),
				),
			),
			self::get_standard_list_tag_fields()
		);
	}

	/**
	 * Handle form submission
	 *
	 * @since 0.0.3
	 *
	 * @param \WPCF7_ContactForm $contact_form Contact Form 7 form object.
	 * @return void
	 */
	public function handle_form_submission( $contact_form ) {
		// Validate contact form object.
		if ( ! $contact_form instanceof \WPCF7_ContactForm ) {
			Logger::error( 'Contact Form 7 Integration', 'Invalid contact form object' );
			return;
		}

		$form_id = $contact_form->id();

		if ( ! $form_id ) {
			Logger::error( 'Contact Form 7 Integration', 'Form ID missing in submission' );
			return;
		}

		// Check if this form has a configuration in the rule engine.
		$result = $this->integrations_db->get( $this->slug, (string) $form_id, 'form', 'submission' );

		// Fallback to null event if submission event not found.
		if ( empty( $result ) || empty( $result['config'] ) ) {
			$result = $this->integrations_db->get( $this->slug, (string) $form_id, 'form', null );
		}

		// If no rule engine configuration exists, exit early.
		if ( empty( $result ) || empty( $result['config'] ) ) {
			return;
		}

		// Check if the configuration is enabled.
		if ( empty( $result['status'] ) ) {
			return;
		}

		$config = $result['config'];

		// Get submission data.
		$submission = \WPCF7_Submission::get_instance();

		if ( ! $submission ) {
			Logger::error( 'Contact Form 7 Integration', "Form {$form_id}: No submission instance found" );
			return;
		}

		$posted_data = $submission->get_posted_data();

		if ( empty( $posted_data ) ) {
			Logger::error( 'Contact Form 7 Integration', "Form {$form_id}: No posted data found" );
			return;
		}

		// Get field mapping from config.
		$field_mapping = $config['field_mapping'] ?? array();

		// Validate that at least basic field mapping exists (email is recommended minimum).
		if ( empty( $field_mapping ) ) {
			Logger::warning( 'Contact Form 7 Integration', "Form {$form_id} has no field mapping configured. Attempting auto-detection." );
		}

		// Load field types for this form to properly handle field-specific formatting.
		$this->load_field_types_for_form( $form_id );

		// Prepare contact data using field mapping.
		$contact_data = $this->format_field_mapping_data( $field_mapping, $posted_data );

		// Clear field types cache after processing.
		$this->current_form_field_types = array();

		// Validate we have at least an email.
		if ( ! $this->has_email_in_data( $contact_data ) ) {
			Logger::warning( 'Contact Form 7 Integration', "Form {$form_id}: No email address found in submission data" );
			return;
		}

		// Build CRM data directly (bypass global field mapper since we have per-form mapping).
		$crm_data = $this->build_crm_data_from_form_submission( $contact_data, $form_id );

		// Get form-specific lists and tags from rule engine config.
		$context = array();

		// Add lists and tags from config.
		if ( ! empty( $config['add_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $config['add_lists'] );
			if ( ! empty( $list_uuids ) ) {
				$context['list_uuids'] = $list_uuids;
			}
		}

		if ( ! empty( $config['add_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $config['add_tags'] );
			if ( ! empty( $tag_uuids ) ) {
				$context['tag_uuids'] = $tag_uuids;
			}
		}

		// Get user ID if user is logged in.
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;

		// Send to CRM.
		$result = $this->send_to_crm( $crm_data, $user_id, $context );

		// Apply remove actions if contact was created/updated successfully.
		if ( ! is_wp_error( $result ) && isset( $result['contact_uuid'] ) ) {
			$contact_uuid = $result['contact_uuid'];

			// Remove lists.
			if ( ! empty( $config['remove_lists'] ) ) {
				$list_uuids = $this->extract_uuids( $config['remove_lists'] );
				if ( ! empty( $list_uuids ) ) {
					$this->contact_service->detach_lists_from_contact( $contact_uuid, $list_uuids );
				}
			}

			// Remove tags.
			if ( ! empty( $config['remove_tags'] ) ) {
				$tag_uuids = $this->extract_uuids( $config['remove_tags'] );
				if ( ! empty( $tag_uuids ) ) {
					$this->contact_service->detach_tags_from_contact( $contact_uuid, $tag_uuids );
				}
			}
		}
	}

	/**
	 * Load field types for a specific form
	 *
	 * Populates $current_form_field_types with field_name => field_type mappings
	 *
	 * @since 0.0.3
	 *
	 * @param int $form_id Form ID.
	 * @return void
	 */
	private function load_field_types_for_form( $form_id ) {
		$this->current_form_field_types = array();

		$fields = $this->get_item_fields( (string) $form_id );
		if ( empty( $fields ) ) {
			return;
		}

		foreach ( $fields as $field ) {
			if ( isset( $field['id'] ) && isset( $field['type'] ) ) {
				$this->current_form_field_types[ $field['id'] ] = $field['type'];
			}
		}
	}

	/**
	 * Get field type for a given field key
	 *
	 * @since 0.0.3
	 *
	 * @param string $field_key Field key.
	 * @return string|null Field type or null if not found
	 */
	private function get_field_type( $field_key ) {
		// Direct lookup.
		if ( isset( $this->current_form_field_types[ $field_key ] ) ) {
			return $this->current_form_field_types[ $field_key ];
		}

		// Try hyphenated version.
		$hyphenated_key = str_replace( '_', '-', $field_key );
		if ( $hyphenated_key !== $field_key && isset( $this->current_form_field_types[ $hyphenated_key ] ) ) {
			return $this->current_form_field_types[ $hyphenated_key ];
		}

		// Try underscored version.
		$underscored_key = str_replace( '-', '_', $field_key );
		if ( $underscored_key !== $field_key && isset( $this->current_form_field_types[ $underscored_key ] ) ) {
			return $this->current_form_field_types[ $underscored_key ];
		}

		return null;
	}

	/**
	 * Get field value from Contact Form 7 submission data
	 *
	 * Handles Contact Form 7-specific naming conventions and field type formatting.
	 *
	 * @since 0.0.3
	 *
	 * @param array  $submission Form submission data.
	 * @param string $field_key  Field key to retrieve.
	 * @return mixed Formatted field value or null
	 */
	protected function get_submission_field_value( $submission, $field_key ) {
		// Try to find the value with different key variations.
		$value       = null;
		$actual_key  = null;
		$keys_to_try = array(
			$field_key,
			str_replace( '_', '-', $field_key ),
			str_replace( '-', '_', $field_key ),
		);

		foreach ( array_unique( $keys_to_try ) as $key ) {
			if ( isset( $submission[ $key ] ) ) {
				$value      = $submission[ $key ];
				$actual_key = $key;
				break;
			}
		}

		// If no value found, return null.
		if ( $value === null ) {
			return null;
		}

		// Get field type to determine how to format the value.
		$field_type = $actual_key ? $this->get_field_type( $actual_key ) : $this->get_field_type( $field_key );

		// Handle field type-specific formatting.
		if ( $field_type ) {
			switch ( $field_type ) {
				case 'checkbox':
					// Checkbox can be: boolean (acceptance), array (multiple options), or single value.
					return $this->format_checkbox_value( $value );

				case 'radio':
					// Radio returns single value as string.
					return is_array( $value ) ? implode( ', ', $value ) : $value;

				case 'select':
					// CF7 select fields should always return a single string value.
					// If it's an array (shouldn't happen normally), convert to string.
					return is_array( $value ) ? (string) reset( $value ) : $value;

				case 'phone':
				case 'tel':
					// Phone fields - ensure it's a string.
					return is_array( $value ) ? implode( '', $value ) : $value;

				case 'number':
					// Number fields - convert to numeric if possible.
					return is_numeric( $value ) ? $value : $value;

				case 'email':
				case 'text':
				case 'textarea':
				case 'url':
				case 'date':
				default:
					// Return value as-is for text-based fields.
					return $value;
			}
		}

		// No field type found, return raw value.
		return $value;
	}

	/**
	 * Format checkbox value based on Contact Form 7 data structure
	 *
	 * CF7 checkboxes can return:
	 * - Single value: "1" for acceptance fields
	 * - Array: ["Option 1", "Option 2"] for multiple checkboxes
	 * - Empty string or array for unchecked
	 *
	 * @since 0.0.3
	 *
	 * @param mixed $value Raw checkbox value.
	 * @return bool|array|string Formatted value
	 */
	private function format_checkbox_value( $value ) {
		// Handle empty values.
		if ( empty( $value ) ) {
			return false;
		}

		// Handle array values (multiple checkboxes).
		if ( is_array( $value ) ) {
			// Filter out empty values.
			$filtered = array_filter( $value );

			// If only one value, check if it's a boolean-like acceptance field.
			if ( count( $filtered ) === 1 ) {
				$single_value = reset( $filtered );
				// If it's "1" or similar, treat as boolean.
				if ( $single_value === '1' || $single_value === 1 || $single_value === true ) {
					return true;
				}
			}

			// Return array of values for multiple selections.
			return array_values( $filtered );
		}

		// Handle string values - acceptance fields return "1" when checked.
		if ( is_string( $value ) ) {
			// For acceptance fields that return "1".
			if ( $value === '1' ) {
				return true;
			}

			// For other string values, return as-is.
			return $value;
		}

		// For numeric 1 (checked acceptance).
		if ( $value === 1 ) {
			return true;
		}

		// Return the value as-is for other types.
		return $value;
	}


	/**
	 * Check if contact data contains an email address
	 *
	 * @since 0.0.2
	 *
	 * @param array $contact_data Contact data array.
	 * @return bool
	 */
	private function has_email_in_data( $contact_data ) {
		// Check if email field exists and is valid.
		if ( isset( $contact_data['email'] ) && is_email( $contact_data['email'] ) ) {
			return true;
		}

		// Check all fields for any email value.
		foreach ( $contact_data as $value ) {
			if ( is_email( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build CRM data structure from form submission
	 *
	 * This method categorizes fields into primary_fields and custom_fields
	 * based on the CRM's field structure, bypassing the global field mapper.
	 *
	 * @since 0.0.2
	 *
	 * @param array $contact_data Prepared contact data.
	 * @param int   $form_id      Form ID.
	 * @return array CRM data structure
	 */
	private function build_crm_data_from_form_submission( $contact_data, $form_id ) {
		// Define primary field keys (these are built-in CRM fields).
		$primary_field_keys = Field_Mapper::get_primary_field_keys();

		$primary_fields = array();
		$custom_fields  = array();
		$metadata       = array();

		// Categorize fields.
		foreach ( $contact_data as $key => $value ) {
			// Skip empty values.
			if ( $value === null || $value === '' ) {
				continue;
			}

			// Check if it's a primary field.
			if ( in_array( $key, $primary_field_keys, true ) ) {
				$primary_fields[ $key ] = $value;
			} elseif ( strpos( $key, 'cf7_' ) === 0 || strpos( $key, '_' ) === 0 ) {
				// Check if it's a metadata field (starts with cf7_ or _).
				$metadata[ $key ] = $value;
			} else {
				// Otherwise it's a custom field.
				$custom_fields[ $key ] = $value;
			}
		}

		// Use the base class method to build the final structure.
		return $this->build_crm_data( $primary_fields, $custom_fields, $metadata );
	}

	/**
	 * Get Contact Form 7 forms list.
	 *
	 * This method is called by the Integration Rules API when fetching items.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of form items.
	 */
	public function get_forms() {
		// Check if Contact Form 7 is active.
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return array();
		}

		$forms = get_posts(
			array(
				'post_type'      => 'wpcf7_contact_form',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $forms ) ) {
			return array();
		}

		$items = array();
		foreach ( $forms as $form ) {
			$items[] = array(
				'id'    => $form->ID,
				'title' => $form->post_title,
				'type'  => 'form',
			);
		}

		return $items;
	}

	/**
	 * Get Contact Form 7 item fields.
	 *
	 * This method is called by the Integration Rules API to get fields for a specific form.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id Item ID (form ID).
	 * @return array Array of fields with 'id', 'label', and 'type' keys.
	 */
	public function get_item_fields( $item_id ) {
		// Check if Contact Form 7 is active.
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return array();
		}

		$form = get_post( (int) $item_id );

		if ( ! $form || ! is_object( $form ) || 'wpcf7_contact_form' !== $form->post_type ) {
			return array();
		}

		// Get Contact Form 7 form object.
		$contact_form = \WPCF7_ContactForm::get_instance( (int) $item_id );

		if ( ! $contact_form ) {
			return array();
		}

		// Get form content.
		$form_content = $contact_form->prop( 'form' );

		// Extract fields from form content.
		return $this->extract_form_fields( $form_content );
	}

	/**
	 * Extract form fields from Contact Form 7 form content
	 *
	 * Parses CF7 form markup to extract field names and types
	 *
	 * @since 0.0.2
	 *
	 * @param string $form_content Form content markup.
	 * @return array Array of field definitions with 'id', 'label', and 'type' keys.
	 */
	private function extract_form_fields( $form_content ) {
		$fields = array();

		// Match CF7 field tags: [type* name "options"].
		preg_match_all( '/\[([^\]]+)\]/', $form_content, $matches );

		if ( empty( $matches[1] ) ) {
			return $fields;
		}

		foreach ( $matches[1] as $tag ) {
			// Remove asterisk for required fields.
			$tag = str_replace( '*', '', $tag );

			// Split tag into components.
			$parts = preg_split( '/\s+/', $tag );

			if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
				continue;
			}

			$field_type = $parts[0];
			$field_name = $parts[1];

			// Skip submit buttons and hidden fields.
			if ( in_array( $field_type, array( 'submit', 'hidden' ), true ) ) {
				continue;
			}

			// Map CF7 field types to generic types.
			$type_mapping = array(
				'text'       => 'text',
				'email'      => 'email',
				'tel'        => 'phone',
				'number'     => 'number',
				'date'       => 'date',
				'textarea'   => 'textarea',
				'select'     => 'select',
				'checkbox'   => 'checkbox',
				'radio'      => 'radio',
				'file'       => 'file',
				'url'        => 'url',
				'acceptance' => 'checkbox',
			);

			$mapped_type = isset( $type_mapping[ $field_type ] ) ? $type_mapping[ $field_type ] : $field_type;

			// Create field label from field name.
			$field_label = ucwords( str_replace( array( '-', '_' ), ' ', $field_name ) );

			$fields[] = array(
				'id'    => $field_name,
				'label' => $field_label,
				'type'  => $mapped_type,
			);
		}

		return $fields;
	}

	/**
	 * Get item title by type and ID.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id   Item ID (form ID).
	 * @param string $item_type Item type ('form').
	 * @return string|null Item title or null if not found.
	 */
	public function get_item_title( $item_id, $item_type ) {
		if ( 'form' !== $item_type ) {
			return null;
		}

		$form = get_post( (int) $item_id );

		if ( ! $form instanceof \WP_Post || 'wpcf7_contact_form' !== $form->post_type ) {
			return null;
		}

		return $form->post_title;
	}
}
