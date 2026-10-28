<?php
/**
 * Gravity Forms Integration
 *
 * Handles Gravity Forms submissions with rule-based field mapping
 *
 * @since 0.0.3
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
 * Class Gravity_Forms_Integration
 *
 * Integrates Gravity Forms with SureContact using the rule engine system.
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
 * @since 0.0.3
 */
class Gravity_Forms_Integration extends Base_Integration {

	/**
	 * Current form being processed
	 *
	 * @since 0.0.3
	 *
	 * @var array|null Current form configuration
	 */
	private $current_form = null;

	/**
	 * Field types cache for current submission
	 *
	 * @since 0.0.3
	 *
	 * @var array Associative array of field_id => field_type
	 */
	private $current_form_field_types = array();

	/**
	 * Constructor
	 *
	 * @since 0.0.3
	 */
	public function __construct() {
		$this->slug        = 'gravity-forms';
		$this->name        = 'Gravity Forms';
		$this->description = __( 'Sync Gravity Forms submissions with per-form field mapping', 'surecontact' );
		$this->docs_url    = '';
		$this->dependency  = 'GFForms';

		parent::__construct();
	}

	/**
	 * Check whether both Gravity Forms classes we depend on are loaded.
	 *
	 * The `$this->dependency` check in Base_Integration only verifies GFForms;
	 * several methods here also call \GFAPI, so both must be present.
	 *
	 * @since 1.4.2
	 *
	 * @return bool True when both GFForms and GFAPI classes are available.
	 */
	private function is_gravity_forms_ready() {
		return class_exists( 'GFForms' ) && class_exists( 'GFAPI' );
	}

	/**
	 * Get integration-specific global settings fields
	 *
	 * Gravity Forms does not use global settings.
	 * All configurations are done at the form level.
	 *
	 * @since 0.0.3
	 *
	 * @return array Settings fields configuration
	 */
	public function get_settings_fields() {
		return array();
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 0.0.3
	 */
	protected function init() {
		// Hook into Gravity Forms submission.
		add_action( 'gform_after_submission', array( $this, 'handle_form_submission' ), 10, 2 );

		// Add custom column to entry list.
		add_filter( 'gform_entry_list_columns', array( $this, 'add_entry_status_column' ) );
		add_filter( 'gform_entries_column_filter', array( $this, 'entry_status_column_content' ), 10, 5 );

		// Add meta box to entry detail page.
		add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'register_meta_box' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'maybe_process_entry' ) );
	}

	/**
	 * Get all available item types for Gravity Forms.
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
	 * Get item-specific configuration fields for a Gravity Forms form.
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
	 * @param array $entry Entry data.
	 * @param array $form  Form configuration.
	 * @return void
	 */
	public function handle_form_submission( $entry, $form ) {
		// Validate data.
		if ( empty( $entry ) || ! is_array( $entry ) || empty( $form ) || ! is_array( $form ) ) {
			Logger::error( 'Gravity Forms Integration', 'Invalid form submission data' );
			return;
		}

		// Extract form ID.
		$form_id = isset( $form['id'] ) ? absint( $form['id'] ) : 0;

		if ( ! $form_id ) {
			Logger::error( 'Gravity Forms Integration', 'Form ID missing in submission' );
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
			Logger::warning( 'Gravity Forms Integration', "Form {$form_id} has no field mapping configured. Attempting auto-detection." );
		}

		// Store form and load field types for this form to properly handle checkbox/multi-select fields.
		$this->current_form = $form;
		$this->load_field_types_for_form( $form );

		// Prepare contact data using field mapping.
		$contact_data = $this->format_field_mapping_data( $field_mapping, $entry );

		// Clear current form after processing.
		$this->current_form             = null;
		$this->current_form_field_types = array();

		// Build CRM data structure.
		$crm_data = $this->build_crm_data_from_form_submission( $contact_data, $form_id );

		// Prepare context with lists and tags from rule engine config.
		$context = array();

		// Add lists from config.
		if ( ! empty( $config['add_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $config['add_lists'] );
			if ( ! empty( $list_uuids ) ) {
				$context['list_uuids'] = $list_uuids;
			}
		}

		// Add tags from config.
		if ( ! empty( $config['add_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $config['add_tags'] );
			if ( ! empty( $tag_uuids ) ) {
				$context['tag_uuids'] = $tag_uuids;
			}
		}

		// Get user ID if user is logged in.
		$user_id = isset( $entry['created_by'] ) ? absint( $entry['created_by'] ) : 0;

		// Send to CRM.
		$result = $this->send_to_crm( $crm_data, $user_id, $context );

		// Apply remove actions if contact was created/updated successfully.
		if ( ! is_wp_error( $result ) && isset( $result['contact_id'] ) ) {
			$contact_id = $result['contact_id'];

			// Mark entry as synced.
			gform_update_meta( $entry['id'], 'surecontact_complete', current_time( 'Y-m-d H:i:s' ) );
			gform_update_meta( $entry['id'], 'surecontact_contact_id', $contact_id );

			// Add success note.
			$this->add_note( $entry['id'], 'Entry synced to SureContact (contact ID: ' . $contact_id . ')' );

			// Remove lists.
			if ( ! empty( $config['remove_lists'] ) ) {
				$list_uuids = $this->extract_uuids( $config['remove_lists'] );
				if ( ! empty( $list_uuids ) ) {
					$this->contact_service->detach_lists_from_contact( $contact_id, $list_uuids );
				}
			}

			// Remove tags.
			if ( ! empty( $config['remove_tags'] ) ) {
				$tag_uuids = $this->extract_uuids( $config['remove_tags'] );
				if ( ! empty( $tag_uuids ) ) {
					$this->contact_service->detach_tags_from_contact( $contact_id, $tag_uuids );
				}
			}
		} elseif ( is_wp_error( $result ) ) {
			// Log error.
			$this->add_note( $entry['id'], 'Failed to sync to SureContact: ' . $result->get_error_message() );
		}
	}


	/**
	 * Load field types for a specific form
	 *
	 * Populates $current_form_field_types with field_id => field_type mappings
	 *
	 * @since 0.0.3
	 *
	 * @param array $form Form configuration array.
	 * @return void
	 */
	private function load_field_types_for_form( $form ) {
		$this->current_form_field_types = array();

		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return;
		}

		foreach ( $form['fields'] as $field ) {
			$field_id   = isset( $field->id ) ? $field->id : '';
			$field_type = isset( $field->type ) ? $field->type : 'text';

			if ( $field_id ) {
				$this->current_form_field_types[ $field_id ] = $field_type;
			}
		}
	}

	/**
	 * Get field type for a given field ID
	 *
	 * @since 0.0.3
	 *
	 * @param string|int $field_id Field ID.
	 * @return string|null Field type or null if not found
	 */
	private function get_field_type( $field_id ) {
		// For sub-fields (e.g., "3.3"), extract the parent field ID.
		if ( is_numeric( $field_id ) && strpos( (string) $field_id, '.' ) !== false ) {
			$parent_id = floor( (float) $field_id );
			if ( isset( $this->current_form_field_types[ $parent_id ] ) ) {
				return $this->current_form_field_types[ $parent_id ];
			}
		}

		// Direct field ID lookup.
		if ( isset( $this->current_form_field_types[ $field_id ] ) ) {
			return $this->current_form_field_types[ $field_id ];
		}

		return null;
	}

	/**
	 * Get field value from Gravity Forms submission data.
	 *
	 * Uses the current form stored in $this->current_form to avoid redundant API calls.
	 * For checkbox and multiselect fields, extracts only values (not keys).
	 *
	 * @since 0.0.3
	 *
	 * @param array  $entry    Entry data.
	 * @param string $field_id Field key to retrieve.
	 * @return mixed RAW field value or null
	 */
	protected function get_submission_field_value( $entry, $field_id ) {
		// Use the current form (already loaded in handle_form_submission).
		if ( empty( $this->current_form ) ) {
			return null;
		}

		// For sub-fields (e.g., "3.3", "3.4" for Name field inputs),
		// access the entry array directly to get the specific sub-field value.
		// This prevents concatenation of all sub-fields that GFFormsModel::get_lead_field_value() does.
		if ( is_numeric( $field_id ) && strpos( (string) $field_id, '.' ) !== false ) {
			return isset( $entry[ $field_id ] ) ? $entry[ $field_id ] : null;
		}

		// Get the field type.
		$field_type = $this->get_field_type( $field_id );

		// Get value using GF's native method if available, otherwise use direct access.
		if ( class_exists( 'GFFormsModel' ) ) {
			$value = \GFFormsModel::get_lead_field_value( $entry, \GFAPI::get_field( $this->current_form, $field_id ) );
		} else {
			$value = isset( $entry[ $field_id ] ) ? $entry[ $field_id ] : null;
		}

		// Handle checkbox and multiselect fields - extract only values.
		if ( ( $field_type === 'checkbox' || $field_type === 'multiselect' ) && $value !== null ) {
			return $this->extract_checkbox_values( $value );
		}

		return $value;
	}

	/**
	 * Extract values from checkbox/multiselect field data
	 *
	 * Gravity Forms often returns checkbox data as an associative array with keys and values.
	 * This method extracts only the values, which is what the backend expects.
	 *
	 * @since 0.0.3
	 *
	 * @param mixed $value Field value (can be array, string, etc.).
	 * @return mixed Array of values or original value
	 */
	private function extract_checkbox_values( $value ) {
		// If it's not an array, return as-is.
		if ( ! is_array( $value ) ) {
			return $value;
		}

		// If it's an empty array, return as-is.
		if ( empty( $value ) ) {
			return $value;
		}

		// Check if this is an associative array with numeric keys (GF checkbox format).
		// GF checkboxes often come as: array('1' => 'Option 1', '2' => 'Option 2').
		// We need to extract just the values: array('Option 1', 'Option 2').
		$is_associative = false;
		foreach ( $value as $key => $val ) {
			if ( ! is_numeric( $key ) || (int) $key !== array_search( $val, array_values( $value ), true ) ) {
				$is_associative = true;
				break;
			}
		}

		// If associative, extract only values and filter out empty ones.
		if ( $is_associative ) {
			return array_values( array_filter( $value ) );
		}

		// Otherwise, return the array as-is (it's already a simple indexed array).
		return array_filter( $value );
	}

	/**
	 * Build CRM data structure from form submission
	 *
	 * This method categorizes fields into primary_fields and custom_fields
	 * based on the CRM's field structure.
	 *
	 * @since 0.0.3
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
			} elseif ( strpos( $key, 'gf_' ) === 0 || strpos( $key, '_' ) === 0 ) {
				// Check if it's a metadata field (starts with gf_ or _).
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
	 * Get Gravity Forms forms list.
	 *
	 * This method is called by the Integration Rules API when fetching items.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of form items.
	 */
	public function get_forms() {
		// Check if Gravity Forms is active.
		if ( ! $this->is_gravity_forms_ready() ) {
			return array();
		}

		$forms = \GFAPI::get_forms();

		if ( empty( $forms ) ) {
			return array();
		}

		$items = array();
		foreach ( $forms as $form ) {
			$items[] = array(
				'id'    => $form['id'],
				'title' => $form['title'],
				'type'  => 'form',
			);
		}

		return $items;
	}

	/**
	 * Get Gravity Forms item fields.
	 *
	 * This method is called by the Integration Rules API to get fields for a specific form.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id Item ID (form ID).
	 * @return array Array of fields with 'id', 'label', and 'type' keys.
	 */
	public function get_item_fields( $item_id ) {
		// Check if Gravity Forms is active.
		if ( ! $this->is_gravity_forms_ready() ) {
			return array();
		}

		$form = \GFAPI::get_form( (int) $item_id );

		if ( ! $form || ! is_array( $form ) || empty( $form['fields'] ) ) {
			return array();
		}

		$fields = array();

		foreach ( $form['fields'] as $field ) {
			$field_id    = isset( $field->id ) ? $field->id : '';
			$field_label = isset( $field->label ) ? $field->label : '';
			$field_type  = isset( $field->type ) ? $field->type : 'text';

			if ( ! $field_id ) {
				continue;
			}

			// Handle name fields with multiple inputs.
			if ( $field_type === 'name' && ! empty( $field->inputs ) ) {
				foreach ( $field->inputs as $input ) {
					$input_id    = isset( $input['id'] ) ? $input['id'] : '';
					$input_label = isset( $input['label'] ) ? $input['label'] : '';

					if ( $input_id ) {
						$fields[] = array(
							'id'    => $input_id,
							'label' => $field_label . ' - ' . $input_label,
							'type'  => 'text',
						);
					}
				}
			} elseif ( $field_type === 'address' && ! empty( $field->inputs ) ) {
				// Handle address fields with multiple inputs.
				foreach ( $field->inputs as $input ) {
					$input_id    = isset( $input['id'] ) ? $input['id'] : '';
					$input_label = isset( $input['label'] ) ? $input['label'] : '';

					if ( $input_id ) {
						$fields[] = array(
							'id'    => $input_id,
							'label' => $field_label . ' - ' . $input_label,
							'type'  => 'text',
						);
					}
				}
			} else {
				// Add regular field.
				$fields[] = array(
					'id'    => $field_id,
					'label' => $field_label,
					'type'  => $field_type,
				);
			}
		}

		return $fields;
	}

	/**
	 * Get item title by type and ID.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id   Item ID.
	 * @param string $item_type Item type ('form').
	 * @return string|null Item title or null if not found.
	 */
	public function get_item_title( $item_id, $item_type ) {
		if ( 'form' !== $item_type ) {
			return null;
		}

		if ( ! $this->is_gravity_forms_ready() ) {
			return null;
		}

		$form = \GFAPI::get_form( (int) $item_id );

		if ( ! $form || ! is_array( $form ) ) {
			return null;
		}

		return isset( $form['title'] ) ? $form['title'] : null;
	}

	/**
	 * Add custom column to entry list
	 *
	 * @since 0.0.3
	 *
	 * @param array $columns The columns.
	 * @return array The columns.
	 */
	public function add_entry_status_column( $columns ) {
		$columns['surecontact'] = __( 'SureContact', 'surecontact' );
		return $columns;
	}

	/**
	 * Display custom column content
	 *
	 * @since 0.0.3
	 *
	 * @param string $value        The value.
	 * @param int    $form_id      The form ID.
	 * @param int    $field_id     The field ID.
	 * @param array  $entry        The entry.
	 * @param string $query_string The query string.
	 * @return string Column content
	 */
	public function entry_status_column_content( $value, $form_id, $field_id, $entry, $query_string ) {
		if ( 'surecontact' !== $field_id ) {
			return $value;
		}

		$complete   = gform_get_meta( $entry['id'], 'surecontact_complete' );
		$contact_id = gform_get_meta( $entry['id'], 'surecontact_contact_id' );

		if ( $complete && $contact_id ) {
			return sprintf(
				'<span class="dashicons dashicons-yes-alt" style="color:#46b450;" aria-hidden="true"></span> %s',
				sprintf(
					/* translators: %s: contact ID returned by SureContact. */
					esc_html__( 'Synced (ID: %s)', 'surecontact' ),
					esc_html( $contact_id )
				)
			);
		}

		return '<span aria-hidden="true">&mdash;</span>';
	}

	/**
	 * Register meta box for entry detail
	 *
	 * @since 0.0.3
	 *
	 * @param array $meta_boxes The meta boxes.
	 * @param array $entry      The entry.
	 * @param array $form       The form.
	 * @return array Modified meta boxes
	 */
	public function register_meta_box( $meta_boxes, $entry, $form ) {
		// Check if this form has rule engine configuration.
		$result = $this->integrations_db->get( $this->slug, (string) $form['id'], 'form', null );

		if ( ! empty( $result ) && ! empty( $result['status'] ) ) {
			$meta_boxes['surecontact-gforms'] = array(
				'title'    => esc_html__( 'SureContact', 'surecontact' ),
				'callback' => array( $this, 'add_details_meta_box' ),
				'context'  => 'side',
			);
		}

		return $meta_boxes;
	}

	/**
	 * Add meta box content
	 *
	 * @since 0.0.3
	 *
	 * @param array $args Meta box args.
	 * @return void
	 */
	public function add_details_meta_box( $args ) {
		$entry = $args['entry'];
		?>
		<strong><?php esc_html_e( 'Synced to SureContact:', 'surecontact' ); ?></strong>&nbsp;

		<?php if ( gform_get_meta( $entry['id'], 'surecontact_complete' ) ) : ?>
			<span><?php esc_html_e( 'Yes', 'surecontact' ); ?></span>
			<span class="dashicons dashicons-yes-alt"></span>
		<?php else : ?>
			<span><?php esc_html_e( 'No', 'surecontact' ); ?></span>
			<span class="dashicons dashicons-no"></span>
		<?php endif; ?>

		<br /><br />

		<?php $contact_id = gform_get_meta( $entry['id'], 'surecontact_contact_id' ); ?>

		<?php if ( $contact_id ) : ?>
			<strong><?php esc_html_e( 'Contact ID:', 'surecontact' ); ?></strong>&nbsp;
			<span><?php echo esc_html( $contact_id ); ?></span>
			<br /><br />
		<?php endif; ?>

		<?php
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'sc_gf' => 'process',
					'lid'   => $entry['id'],
				)
			),
			'sc_gf_process_entry'
		);
		?>

		<a href="<?php echo esc_url( $url ); ?>" class="button"><?php esc_html_e( 'Process SureContact actions again', 'surecontact' ); ?></a>
		<?php
	}

	/**
	 * Maybe process entry manually from detail page
	 *
	 * @since 0.0.3
	 *
	 * @return void
	 */
	public function maybe_process_entry() {
		// If we're not on the entry view page, return.
		if ( rgget( 'page' ) !== 'gf_entries' || rgget( 'view' ) !== 'entry' || rgget( 'sc_gf' ) !== 'process' ) {
			return;
		}

		// GF admins get `gform_full_access` dynamically (not individual caps),
		// so we must check both the specific cap and the full-access cap.
		if ( ! current_user_can( 'gravityforms_view_entries' ) && ! current_user_can( 'gform_full_access' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- GF custom caps registered by the GF plugin itself.
			return;
		}

		check_admin_referer( 'sc_gf_process_entry' );

		// Get the current form and entry.
		$form  = \GFAPI::get_form( rgget( 'id' ) );
		$entry = \GFAPI::get_entry( rgget( 'lid' ) );

		if ( is_wp_error( $form ) || is_wp_error( $entry ) ) {
			return;
		}

		// Process the submission.
		$this->handle_form_submission( $entry, $form );

		// Redirect back to entry view (remove process parameter).
		$redirect_url = remove_query_arg( 'sc_gf' );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Add note to entry
	 *
	 * @since 0.0.3
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $note     Note text.
	 * @return void
	 */
	private function add_note( $entry_id, $note ) {
		if ( ! class_exists( 'GFFormsModel' ) ) {
			return;
		}

		\GFFormsModel::add_note(
			$entry_id,
			0,
			'SureContact',
			$note,
			'surecontact'
		);
	}
}
