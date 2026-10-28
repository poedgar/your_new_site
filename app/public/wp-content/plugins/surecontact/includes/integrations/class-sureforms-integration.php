<?php
/**
 * SureForms Integration
 *
 * Handles SureForms form submissions with per-form field mapping
 *
 * @since 0.0.1
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
 * Class SureForms_Integration
 *
 * Integrates SureForms with SureContact using the rule engine system.
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
class SureForms_Integration extends Base_Integration {

	/**
	 * Field types cache for current submission
	 *
	 * @since 0.0.3
	 *
	 * @var array Associative array of field_id => field_type
	 */
	private $current_form_field_types = array();

	/**
	 * Date formats for current submission
	 *
	 * @since 1.5.5
	 *
	 * @var array Associative array of field_id => PHP date format
	 */
	private $current_form_field_formats = array();

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		$this->slug        = 'sureforms';
		$this->name        = 'SureForms';
		$this->description = __( 'Sync SureForms submissions with per-form field mapping', 'surecontact' );
		$this->docs_url    = '';
		$this->dependency  = 'SRFM\Plugin_Loader';

		parent::__construct();
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 0.0.1
	 */
	protected function init() {
		// Hook into SureForms submission.
		add_action( 'srfm_form_submit', array( $this, 'handle_form_submission' ), 10, 1 );
	}

	/**
	 * Get all available item types for SureForms.
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
	 * Get item-specific configuration fields for a SureForms form.
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
	 * @since 0.0.1
	 *
	 * @param array $response Form submission response data.
	 * @return void
	 */
	public function handle_form_submission( $response ) {

		// Validate response data.
		if ( empty( $response ) || ! is_array( $response ) ) {
			Logger::error( 'SureForms Integration', 'Invalid form submission data' );
			return;
		}

		// Extract form ID - SureForms uses 'form_id' (with underscore).
		$form_id = 0;
		if ( isset( $response['form_id'] ) ) {
			$form_id = absint( $response['form_id'] );
		} elseif ( isset( $response['form-id'] ) ) {
			// Fallback to hyphenated version.
			$form_id = absint( $response['form-id'] );
		}

		if ( ! $form_id ) {
			Logger::error( 'SureForms Integration', 'Form ID missing in submission' );
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
			Logger::warning( 'SureForms Integration', "Form {$form_id} has no field mapping configured. Attempting auto-detection." );
		}

		// Load field types for this form to properly handle multi-select fields.
		$this->load_field_types_for_form( $form_id );

		// Step 2: Format field data with basic validation.
		$contact_data = $this->format_field_mapping_data( $field_mapping, $response );

		// Step 3: Build CRM data structure.
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
		$user_id = isset( $response['user_id'] ) ? absint( $response['user_id'] ) : get_current_user_id();

		// Send to CRM.
		$result = $this->send_to_crm( $crm_data, $user_id, $context );

		// Apply remove actions if contact was created/updated successfully.
		if ( ! is_wp_error( $result ) && isset( $result['contact_id'] ) ) {
			$contact_id = $result['contact_id'];

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
		}
	}

	/**
	 * Load field types for a specific form
	 *
	 * Populates $current_form_field_types with field_id => field_type mappings
	 *
	 * @since 0.0.3
	 *
	 * @param int $form_id Form ID.
	 * @return void
	 */
	private function load_field_types_for_form( $form_id ) {
		$this->current_form_field_types   = array();
		$this->current_form_field_formats = array();

		$fields = $this->get_item_fields( (string) $form_id );
		if ( empty( $fields ) ) {
			return;
		}

		foreach ( $fields as $field ) {
			if ( ! isset( $field['id'] ) || ! isset( $field['type'] ) ) {
				continue;
			}

			$this->current_form_field_types[ $field['id'] ] = $field['type'];

			if ( ! empty( $field['date_format'] ) ) {
				$this->current_form_field_formats[ $field['id'] ] = self::to_php_date_format( $field['date_format'] );
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
		$resolved_key = $this->resolve_field_key( $field_key );

		return null !== $resolved_key ? $this->current_form_field_types[ $resolved_key ] : null;
	}

	/**
	 * Resolve a field key against the loaded form fields
	 *
	 * @since 1.5.5
	 *
	 * @param string $field_key Field key.
	 * @return string|null Matching key from the loaded fields, or null when not found
	 */
	private function resolve_field_key( $field_key ) {
		$keys_to_try = array( $field_key );

		// Try without 'srfm-' prefix.
		if ( strpos( $field_key, 'srfm-' ) === 0 ) {
			$keys_to_try[] = substr( $field_key, 5 );
		}

		// Try with 'srfm-' prefix.
		$keys_to_try[] = 'srfm-' . $field_key;

		foreach ( $keys_to_try as $key ) {
			if ( isset( $this->current_form_field_types[ $key ] ) ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Get the date format a form field submits its value in
	 *
	 * The date picker submits its value in the author-configured display format,
	 * so the format has to travel with the value or dd/mm/yyyy input is read as m/d/Y.
	 *
	 * @since 1.5.5
	 *
	 * @param string $form_field Form field key.
	 * @return string PHP date format (e.g. 'd/m/Y'), or empty string when unknown
	 */
	protected function get_field_source_format( $form_field ) {
		$resolved_key = $this->resolve_field_key( $form_field );

		return null !== $resolved_key && isset( $this->current_form_field_formats[ $resolved_key ] )
			? $this->current_form_field_formats[ $resolved_key ]
			: '';
	}

	/**
	 * Convert a SureForms dateFormat attribute to a PHP date format
	 *
	 * Mirrors the map SureForms uses itself when it renders and sorts the field.
	 *
	 * @since 1.5.5
	 *
	 * @param string $date_format SureForms dateFormat attribute.
	 * @return string PHP date format, or empty string for an unrecognised attribute
	 */
	private static function to_php_date_format( $date_format ) {
		$format_map = array(
			'mm/dd/yyyy' => 'm/d/Y',
			'dd/mm/yyyy' => 'd/m/Y',
			'yyyy/mm/dd' => 'Y/m/d',
		);

		return $format_map[ $date_format ] ?? '';
	}

	/**
	 * Get field value from SureForms submission data
	 *
	 * Converts checkbox to boolean, multi-choice to array, handles missing fields.
	 *
	 * @since 0.0.3
	 *
	 * @param array  $submission_data Raw submission data.
	 * @param string $field_key       Field key to retrieve.
	 * @return mixed Field value (boolean for checkbox, array for multi-choice)
	 */
	protected function get_submission_field_value( $submission_data, $field_key ) {
		$data = isset( $submission_data['data'] ) && is_array( $submission_data['data'] ) ? $submission_data['data'] : $submission_data;

		// Try multiple key variations to find value.
		$keys_to_try = array(
			$field_key,
			strpos( $field_key, 'srfm-' ) === 0 ? substr( $field_key, 5 ) : 'srfm-' . $field_key,
			str_replace( '_', '-', $field_key ),
			str_replace( '-', '_', $field_key ),
		);

		$value      = null;
		$actual_key = null;

		foreach ( array_unique( $keys_to_try ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$value      = $data[ $key ];
				$actual_key = $key;
				break;
			}
		}

		$field_type = $actual_key ? $this->get_field_type( $actual_key ) : $this->get_field_type( $field_key );

		// Handle missing or empty values with defaults.
		if ( $value === null || $value === '' ) {
			if ( $field_type === 'checkbox' ) {
				return false;
			}
			if ( $field_type === 'multi-choice' ) {
				return array();
			}
			return $value === null ? null : '';
		}

		// Convert checkbox to boolean.
		if ( $field_type === 'checkbox' ) {
			return $this->convert_to_boolean( $value );
		}

		// Convert multi-choice to array.
		if ( $field_type === 'multi-choice' ) {
			return $this->convert_to_array( $value );
		}

		return $value;
	}

	/**
	 * Convert value to boolean for checkbox fields
	 *
	 * @since 0.0.3
	 *
	 * @param mixed $value Field value.
	 * @return bool|array Boolean or array for multiple selections
	 */
	private function convert_to_boolean( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			$count = count( $value );
			if ( $count > 1 ) {
				return $value; // Multiple selections.
			}
			if ( $count === 1 ) {
				return ! empty( $value[0] );
			}
			return false;
		}

		if ( is_string( $value ) && strpos( $value, '|' ) !== false ) {
			$values = array_filter( array_map( 'trim', explode( '|', $value ) ) );
			return count( $values ) > 1 ? $values : ! empty( $values );
		}

		if ( is_string( $value ) ) {
			return ! empty( $value ) && $value !== '0' && $value !== 'false';
		}

		return (bool) $value;
	}

	/**
	 * Convert value to array for multi-choice fields
	 *
	 * @since 0.0.3
	 *
	 * @param mixed $value Field value.
	 * @return array Array of values
	 */
	private function convert_to_array( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			if ( strpos( $value, '|' ) !== false ) {
				return array_filter( array_map( 'trim', explode( '|', $value ) ) );
			}
			if ( ! empty( $value ) ) {
				return array( $value );
			}
		}

		return array();
	}


	/**
	 * Build CRM data structure from form submission
	 *
	 * This method categorizes fields into primary_fields and custom_fields
	 * based on the CRM's field structure. Data is already formatted.
	 *
	 * @since 0.0.1
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

		// Categorize fields (data is already formatted).
		foreach ( $contact_data as $key => $value ) {
			// Skip empty values.
			if ( $value === null || $value === '' ) {
				continue;
			}

			// Check if it's a primary field.
			if ( in_array( $key, $primary_field_keys, true ) ) {
				$primary_fields[ $key ] = $value;
			} elseif ( strpos( $key, 'sf_' ) === 0 || strpos( $key, '_' ) === 0 ) {
				// Check if it's a metadata field (starts with sf_ or _).
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
	 * Get SureForms forms list.
	 *
	 * This method is called by the Integration Rules API when fetching items.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of form items.
	 */
	public function get_forms() {
		// Check if SureForms is active using the dependency class.
		if ( ! class_exists( 'SRFM\\Plugin_Loader' ) ) {
			return array();
		}

		$forms = get_posts(
			array(
				'post_type'      => 'sureforms_form',
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
	 * Get SureForms item fields.
	 *
	 * This method is called by the Integration Rules API to get fields for a specific form.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id Item ID (form ID).
	 * @return array Array of fields with 'id', 'label', and 'type' keys.
	 */
	public function get_item_fields( $item_id ) {
		// Check if SureForms is active.
		if ( ! class_exists( 'SRFM\\Plugin_Loader' ) ) {
			return array();
		}

		$form = get_post( (int) $item_id );

		if ( ! $form || ! is_object( $form ) || 'sureforms_form' !== $form->post_type ) {
			return array();
		}

		// Parse blocks to extract fields.
		$blocks = parse_blocks( $form->post_content );
		$fields = array();

		// Recursively extract fields from all blocks and inner blocks.
		$this->extract_fields_from_blocks_recursive( $blocks, $fields );

		return $fields;
	}

	/**
	 * Recursively extract fields from blocks.
	 *
	 * @since 0.0.3
	 *
	 * @param array $blocks Blocks to parse.
	 * @param array &$fields Fields array (passed by reference).
	 * @return void
	 */
	private function extract_fields_from_blocks_recursive( $blocks, &$fields ) {
		foreach ( $blocks as $block ) {
			// Check if block is a SureForms field.
			$block_name = isset( $block['blockName'] ) ? $block['blockName'] : '';
			if ( strpos( $block_name, 'srfm/' ) === 0 ) {
				$field_type = str_replace( 'srfm/', '', $block_name );

				// Extract field attributes.
				$attrs = isset( $block['attrs'] ) ? $block['attrs'] : array();

				// Get field name/ID - prioritize 'name', then 'fieldName', then 'slug', finally fallback to field_type.
				$field_name = isset( $attrs['name'] ) ? $attrs['name'] :
								( isset( $attrs['fieldName'] ) ? $attrs['fieldName'] :
								( isset( $attrs['slug'] ) ? $attrs['slug'] : $field_type ) );

				// Get field label - prioritize 'label', then 'fieldLabel', finally fallback to ucfirst field_type.
				$field_label = isset( $attrs['label'] ) ? $attrs['label'] :
								( isset( $attrs['fieldLabel'] ) ? $attrs['fieldLabel'] : ucfirst( $field_type ) );

				// Add to fields array.
				$field = array(
					'id'    => $field_name,
					'label' => $field_label,
					'type'  => $field_type,
				);

				// Carry the declared date format so the value is not guessed later.
				// parse_blocks() does not apply block.json defaults, so fall back to
				// the date picker's own default when the attribute is absent.
				if ( 'date-picker' === $field_type ) {
					$field['date_format'] = isset( $attrs['dateFormat'] ) && is_string( $attrs['dateFormat'] )
						? $attrs['dateFormat']
						: 'mm/dd/yyyy';
				}

				$fields[] = $field;
			}

			// Process inner blocks recursively.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->extract_fields_from_blocks_recursive( $block['innerBlocks'], $fields );
			}
		}
	}
}
