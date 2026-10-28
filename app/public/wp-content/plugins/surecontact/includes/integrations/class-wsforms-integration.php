<?php
/**
 * WS Form Integration
 *
 * Handles WS Form form submissions with per-form field mapping
 *
 * @since 1.5.3
 *
 * @package SureContact
 */

namespace SureContact\Integrations;

use SureContact\Field_Mapper;
use SureContact\Logger;
use SureContact\Traits\Integration_DB_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WSForms_Integration
 *
 * Integrates WS Form with SureContact using the rule engine system.
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
 * @since 1.5.3
 */
class WSForms_Integration extends Base_Integration {

	// Use the database helper trait for item-specific configurations.
	use Integration_DB_Helper;

	/**
	 * WS Form field types that do not carry a submittable value and should be
	 * excluded from the field-mapping UI (layout, action and display fields).
	 *
	 * @since 1.5.3
	 *
	 * @var array
	 */
	private $non_data_field_types = array(
		'message',
		'divider',
		'html',
		'texteditor',
		'button',
		'submit',
		'save',
		'clear',
		'previous',
		'next',
		'progress',
		'columns',
		'tab',
		'section',
		'group',
	);

	/**
	 * Constructor
	 *
	 * @since 1.5.3
	 */
	public function __construct() {
		$this->slug        = 'ws-form';
		$this->name        = 'WS Form';
		$this->description = __( 'Sync WS Form submissions with per-form field mapping', 'surecontact' );
		$this->docs_url    = '';
		$this->dependency  = 'WS_Form_Form';

		parent::__construct();
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 1.5.3
	 */
	protected function init() {
		// Hook into WS Form submission - fires after a submission is saved.
		add_action( 'wsf_submit_post_complete', array( $this, 'handle_form_submission' ), 10, 1 );
	}

	/**
	 * Get all available item types for WS Form.
	 *
	 * @since 1.5.3
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
	 * @since 1.5.3
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
	 * Get item-specific configuration fields for a WS Form form.
	 *
	 * This method returns the configuration fields that will be shown in the UI
	 * when a specific form is selected.
	 *
	 * @since 1.5.3
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
	 * @since 1.5.3
	 *
	 * @param object $ws_form_submit WS_Form_Submit object for the saved submission.
	 * @return void
	 */
	public function handle_form_submission( $ws_form_submit ) {

		// Validate submission object.
		if ( ! is_object( $ws_form_submit ) || empty( $ws_form_submit->meta ) ) {
			return;
		}

		// Extract form ID.
		$form_id = isset( $ws_form_submit->form_id ) ? absint( $ws_form_submit->form_id ) : 0;

		if ( ! $form_id ) {
			Logger::error( 'WS Form Integration', 'Form ID missing in submission' );
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

		// Get field mapping from config.
		$field_mapping = $config['field_mapping'] ?? array();

		// Validate that at least basic field mapping exists (email is recommended minimum).
		if ( empty( $field_mapping ) ) {
			Logger::warning( 'WS Form Integration', "Form {$form_id} has no field mapping configured." );
		}

		// Prepare contact data using field mapping. WS Form stores submitted
		// values in the submit object's meta array, keyed by `field_{id}`.
		$contact_data = $this->format_field_mapping_data( $field_mapping, $ws_form_submit->meta );

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
	 * Get field value from WS Form submission data.
	 *
	 * Overrides base class to read values from the WS_Form_Submit meta array,
	 * which is keyed by `field_{id}` and stores each value under a 'value' key.
	 *
	 * @since 1.5.3
	 *
	 * @param array  $submission_data WS Form submit meta array.
	 * @param string $form_field_id   Field ID to retrieve.
	 * @return mixed RAW field value or null
	 */
	protected function get_submission_field_value( $submission_data, $form_field_id ) {
		if ( empty( $submission_data ) || ! is_array( $submission_data ) ) {
			return null;
		}

		$meta = $submission_data;

		// WS Form keys submitted values as `field_{id}`; also try the raw key.
		$meta_key = 'field_' . $form_field_id;
		if ( ! isset( $meta[ $meta_key ] ) && isset( $meta[ $form_field_id ] ) ) {
			$meta_key = $form_field_id;
		}

		if ( ! isset( $meta[ $meta_key ] ) || ! is_array( $meta[ $meta_key ] ) || ! array_key_exists( 'value', $meta[ $meta_key ] ) ) {
			return null;
		}

		$value = $meta[ $meta_key ]['value'];
		$type  = $meta[ $meta_key ]['type'] ?? '';

		// File, signature and media-capture fields store an array of file objects.
		if ( in_array( $type, array( 'file', 'signature', 'mediacapture' ), true ) ) {
			if ( is_array( $value ) ) {
				$urls = array();
				foreach ( $value as $file_object ) {
					if ( is_array( $file_object ) && ! empty( $file_object['url'] ) ) {
						$urls[] = $file_object['url'];
					}
				}
				return ! empty( $urls ) ? implode( ', ', $urls ) : null;
			}
			return null;
		}

		// Multi-value fields (checkbox, select) return their array as-is so the
		// base formatter can handle them; scalar values pass through unchanged.
		return $value;
	}

	/**
	 * Build CRM data structure from form submission
	 *
	 * This method categorizes fields into primary_fields and custom_fields
	 * based on the CRM's field structure, bypassing the global field mapper.
	 *
	 * @since 1.5.3
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
			} elseif ( strpos( $key, 'wsf_' ) === 0 || strpos( $key, '_' ) === 0 ) {
				// Check if it's a metadata field (starts with wsf_ or _).
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
	 * Get WS Form forms list.
	 *
	 * This method is called by the Integration Rules API when fetching items.
	 *
	 * @since 1.5.3
	 *
	 * @return array Array of form items.
	 */
	public function get_forms() {
		// Check if WS Form is active.
		if ( ! function_exists( 'wsf_form_get_all' ) ) {
			return array();
		}

		// Only published forms — drafts/unpublished forms should not be selectable.
		$forms = wsf_form_get_all( true, 'label', 'ASC' );

		if ( empty( $forms ) || ! is_array( $forms ) ) {
			return array();
		}

		$items = array();
		foreach ( $forms as $form ) {
			$items[] = array(
				'id'    => $form['id'],
				'title' => $form['label'],
				'type'  => 'form',
			);
		}

		return $items;
	}

	/**
	 * Get the title of a WS Form form by ID.
	 *
	 * Required so the rule engine displays the correct form name. WS Form stores
	 * forms in its own table (not as WordPress posts), so without this the Rules
	 * API would fall back to get_post() and show an unrelated post's title.
	 *
	 * @since 1.5.3
	 *
	 * @param string $item_id   Item ID (form ID).
	 * @param string $item_type Item type.
	 * @return string|null Form title, or null if unavailable.
	 */
	public function get_item_title( $item_id, $item_type ) {
		if ( 'form' !== $item_type ) {
			return null;
		}

		if ( ! function_exists( 'wsf_form_get_object' ) ) {
			return null;
		}

		// Lightweight load: no meta, no groups — we only need the label.
		$form = wsf_form_get_object( (int) $item_id, false, false );

		return ( is_object( $form ) && ! empty( $form->label ) ) ? $form->label : null;
	}

	/**
	 * Get WS Form item fields.
	 *
	 * This method is called by the Integration Rules API to get fields for a specific form.
	 *
	 * @since 1.5.3
	 *
	 * @param string $item_id Item ID (form ID).
	 * @return array Array of fields with 'id', 'label', and 'type' keys.
	 */
	public function get_item_fields( $item_id ) {
		// Check if WS Form is active.
		if ( ! function_exists( 'wsf_form_get_object' ) || ! function_exists( 'wsf_field_get_objects' ) ) {
			return array();
		}

		$form_object = wsf_form_get_object( (int) $item_id, true, true );

		if ( ! is_object( $form_object ) ) {
			return array();
		}

		// Flat array of field objects keyed by field ID.
		$field_objects = wsf_field_get_objects( $form_object );

		if ( empty( $field_objects ) || ! is_array( $field_objects ) ) {
			return array();
		}

		$fields = array();
		foreach ( $field_objects as $field ) {
			if ( ! is_object( $field ) || ! isset( $field->id, $field->type ) ) {
				continue;
			}

			// Skip layout, action and display fields that carry no submittable value.
			if ( in_array( $field->type, $this->non_data_field_types, true ) ) {
				continue;
			}

			$fields[] = array(
				'id'    => $field->id,
				'label' => $field->label ?? 'Field ' . $field->id,
				'type'  => $field->type,
			);
		}

		return $fields;
	}
}
