<?php
/**
 * WPForms Integration
 *
 * Handles WPForms form submissions with per-form field mapping
 *
 * @since 0.0.1
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
 * Class WPForms_Integration
 *
 * Integrates WPForms with SureContact using the rule engine system.
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
 * @since 0.0.1
 */
class WPForms_Integration extends Base_Integration {

	// Use the database helper trait for item-specific configurations.
	use Integration_DB_Helper;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		$this->slug        = 'wpforms';
		$this->name        = 'WPForms';
		$this->description = __( 'Sync WPForms submissions with per-form field mapping', 'surecontact' );
		$this->docs_url    = '';
		$this->dependency  = 'wpforms';

		parent::__construct();
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 0.0.3
	 */
	protected function init() {
		// Hook into WPForms submission - fires after entry is saved.
		add_action( 'wpforms_process_complete', array( $this, 'handle_form_submission' ), 10, 4 );
	}

	/**
	 * Get all available item types for WPForms.
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
	 * Get item-specific configuration fields for a WPForms form.
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
	 * @param array $fields    WPForms form array of fields.
	 * @param array $entry     Entry data.
	 * @param array $form_data Form configuration data.
	 * @param int   $entry_id  Entry ID.
	 * @return void
	 */
	public function handle_form_submission( $fields, $entry, $form_data, $entry_id = 0 ) {

		// Validate response data.
		if ( empty( $form_data ) || ! is_array( $form_data ) ) {
			Logger::error( 'WPForms Integration', 'Invalid form submission data' );
			return;
		}

		// Extract form ID.
		$form_id = isset( $form_data['id'] ) ? absint( $form_data['id'] ) : 0;

		if ( ! $form_id ) {
			Logger::error( 'WPForms Integration', 'Form ID missing in submission' );
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
			Logger::warning( 'WPForms Integration', "Form {$form_id} has no field mapping configured. Attempting auto-detection." );
		}

		// Prepare contact data using field mapping.
		$contact_data = $this->format_field_mapping_data( $field_mapping, $fields );

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

		// Add entry note if WPForms Pro is active.
		if ( $entry_id && function_exists( 'wpforms' ) && wpforms()->is_pro() ) {
			$this->add_entry_note( $entry_id, $form_id, $result );
		}
	}


	/**
	 * Get field value from WPForms submission data.
	 *
	 * Overrides base class to pass fields array to custom get_field_value() method.
	 *
	 * @since 0.0.3
	 *
	 * @param array  $fields        WPForms fields array.
	 * @param string $form_field_id Field key to retrieve.
	 * @return mixed RAW field value or null
	 */
	protected function get_submission_field_value( $fields, $form_field_id ) {
		return $this->get_field_value( $fields, $form_field_id );
	}

	/**
	 * Get field value from WPForms fields array
	 *
	 * @since 0.0.3
	 *
	 * @param array  $fields       WPForms fields array.
	 * @param string $form_field_id Field ID to retrieve.
	 * @return mixed RAW field value or null
	 */
	private function get_field_value( $fields, $form_field_id ) {
		foreach ( $fields as $field ) {
			// Direct ID match.
			if ( isset( $field['id'] ) && (string) $field['id'] === (string) $form_field_id ) {
				$type = $field['type'] ?? 'text';

				// Handle checkbox fields - return raw array.
				if ( $type === 'checkbox' && isset( $field['value_raw'] ) && ! empty( $field['value_raw'] ) ) {
					return explode( PHP_EOL, $field['value_raw'] );
				}

				// Handle select with multiple values - return raw array.
				if ( $type === 'select' && isset( $field['value_raw'] ) && strpos( $field['value_raw'], PHP_EOL ) !== false ) {
					return explode( PHP_EOL, $field['value_raw'] );
				}

				// Return raw value for all other types.
				return $field['value'] ?? '';
			}

			// Handle name field sub-fields (first/last/middle).
			if ( isset( $field['type'] ) && $field['type'] === 'name' ) {
				$field_id_str = (string) $field['id'];

				// Check for -first suffix.
				if ( $form_field_id === $field_id_str . '-first' ) {
					// Return first name if not empty, otherwise try to parse from full name.
					if ( ! empty( $field['first'] ) ) {
						return $field['first'];
					}
					// If first is empty but value exists (simple format), parse first name.
					if ( ! empty( $field['value'] ) ) {
						$name_parts = $this->parse_full_name( $field['value'] );
						return $name_parts['first_name'] ?? '';
					}
					return null;
				}

				// Check for -last suffix.
				if ( $form_field_id === $field_id_str . '-last' ) {
					// Return last name if not empty, otherwise try to parse from full name.
					if ( ! empty( $field['last'] ) ) {
						return $field['last'];
					}
					// If last is empty but value exists (simple format), parse last name.
					if ( ! empty( $field['value'] ) ) {
						$name_parts = $this->parse_full_name( $field['value'] );
						return $name_parts['last_name'] ?? '';
					}
					return null;
				}

				// Check for -middle suffix.
				if ( $form_field_id === $field_id_str . '-middle' ) {
					// Return middle name if not empty, otherwise try to parse from full name.
					if ( ! empty( $field['middle'] ) ) {
						return $field['middle'];
					}
					// If middle is empty but value exists (simple format), parse middle name.
					if ( ! empty( $field['value'] ) ) {
						$name_parts = $this->parse_full_name( $field['value'] );
						return $name_parts['middle_name'] ?? '';
					}
					return null;
				}
			}
		}

		return null;
	}

	/**
	 * Parse a full name into first, middle, and last name components.
	 *
	 * @since 0.0.3
	 *
	 * @param string $full_name Full name string.
	 * @return array Array with 'first_name', 'middle_name', and 'last_name' keys.
	 */
	private function parse_full_name( $full_name ) {
		$full_name = trim( $full_name );
		if ( empty( $full_name ) ) {
			return array(
				'first_name'  => '',
				'middle_name' => '',
				'last_name'   => '',
			);
		}

		// Split name by spaces.
		$name_parts = preg_split( '/\s+/', $full_name );
		if ( false === $name_parts || empty( $name_parts ) ) {
			return array(
				'first_name'  => $full_name,
				'middle_name' => '',
				'last_name'   => '',
			);
		}

		$num_parts = count( $name_parts );

		$result = array(
			'first_name'  => '',
			'middle_name' => '',
			'last_name'   => '',
		);

		if ( $num_parts === 1 ) {
			// Only one part - treat as first name.
			$result['first_name'] = $name_parts[0];
		} elseif ( $num_parts === 2 ) {
			// Two parts - first and last name.
			$result['first_name'] = $name_parts[0];
			$result['last_name']  = $name_parts[1];
		} else {
			// Three or more parts - first, middle(s), and last name.
			$result['first_name'] = array_shift( $name_parts );
			$result['last_name']  = array_pop( $name_parts );
			// Everything in between is middle name(s).
			$result['middle_name'] = implode( ' ', $name_parts );
		}

		return $result;
	}


	/**
	 * Build CRM data structure from form submission
	 *
	 * This method categorizes fields into primary_fields and custom_fields
	 * based on the CRM's field structure, bypassing the global field mapper.
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
			} elseif ( strpos( $key, 'wpf_' ) === 0 || strpos( $key, '_' ) === 0 ) {
				// Check if it's a metadata field (starts with wpf_ or _).
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
	 * Add entry meta note for sync status
	 *
	 * @since 0.0.1
	 *
	 * @param int   $entry_id Entry ID.
	 * @param int   $form_id  Form ID.
	 * @param mixed $result   Contact ID or WP_Error.
	 * @return void
	 */
	private function add_entry_note( $entry_id, $form_id, $result ) {
		if ( ! function_exists( 'wpforms' ) || ! wpforms()->is_pro() || ! $entry_id ) {
			return;
		}

		if ( is_wp_error( $result ) ) {
			$message = sprintf(
				/* translators: %s: Error message from the sync process. */
				__( 'Error syncing form entry to SureContact: %s', 'surecontact' ),
				$result->get_error_message()
			);
		} else {
			$contact_uuid = isset( $result['contact_uuid'] ) ? $result['contact_uuid'] : $result;
			$message      = sprintf(
				/* translators: %s: Contact UUID. */
				__( 'Entry synced to SureContact (contact UUID: %s)', 'surecontact' ),
				$contact_uuid
			);
		}

		$entry_meta = wpforms()->obj( 'entry_meta' );
		if ( $entry_meta ) {
			$entry_meta->add(
				array(
					'entry_id' => $entry_id,
					'form_id'  => $form_id,
					'user_id'  => 0,
					'type'     => 'note',
					'data'     => wpautop( $message ),
				),
				'entry_meta'
			);
		}
	}

	/**
	 * Get WPForms forms list.
	 *
	 * This method is called by the Integration Rules API when fetching items.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of form items.
	 */
	public function get_forms() {
		// Check if WPForms is active.
		if ( ! function_exists( 'wpforms' ) ) {
			return array();
		}

		$forms = wpforms()->form->get( '', array( 'orderby' => 'title' ) );

		if ( empty( $forms ) || ! is_array( $forms ) ) {
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
	 * Get WPForms item fields.
	 *
	 * This method is called by the Integration Rules API to get fields for a specific form.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id Item ID (form ID).
	 * @return array Array of fields with 'id', 'label', and 'type' keys.
	 */
	public function get_item_fields( $item_id ) {
		// Check if WPForms is active.
		if ( ! function_exists( 'wpforms' ) ) {
			return array();
		}

		$form = wpforms()->form->get( (int) $item_id );

		if ( ! $form || ! is_object( $form ) ) {
			return array();
		}

		$form_data = wpforms_decode( $form->post_content );

		if ( empty( $form_data['fields'] ) ) {
			return array();
		}

		$fields = array();
		foreach ( $form_data['fields'] as $field_id => $field ) {
			$field_type  = $field['type'] ?? 'text';
			$field_label = $field['label'] ?? 'Field ' . $field_id;

			// Add the main field.
			$fields[] = array(
				'id'    => $field_id,
				'label' => $field_label,
				'type'  => $field_type,
			);

			// Handle name fields - add sub-fields for first, middle, and last name.
			if ( $field_type === 'name' ) {
				$fields[] = array(
					'id'    => $field_id . '-first',
					'label' => $field_label . ' - First',
					'type'  => 'text',
				);

				$fields[] = array(
					'id'    => $field_id . '-middle',
					'label' => $field_label . ' - Middle',
					'type'  => 'text',
				);

				$fields[] = array(
					'id'    => $field_id . '-last',
					'label' => $field_label . ' - Last',
					'type'  => 'text',
				);
			}
		}

		return $fields;
	}
}
