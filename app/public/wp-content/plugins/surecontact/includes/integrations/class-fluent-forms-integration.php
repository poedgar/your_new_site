<?php
/**
 * Fluent Forms Integration
 *
 * Handles Fluent Forms form submissions with per-form field mapping
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
 * Class Fluent_Forms_Integration
 *
 * Handles Fluent Forms submissions with per-form configuration
 *
 * @since 0.0.3
 */
class Fluent_Forms_Integration extends Base_Integration {

	/**
	 * Form ID currently being processed
	 *
	 * @since 1.5.5
	 *
	 * @var int
	 */
	private $current_form_id = 0;

	/**
	 * Date formats for the current form, keyed by field name
	 *
	 * Null until loaded, so forms without a mapped date field never trigger the lookup.
	 *
	 * @since 1.5.5
	 *
	 * @var array|null
	 */
	private $current_form_field_formats = null;

	/**
	 * Constructor
	 *
	 * @since 0.0.3
	 */
	public function __construct() {
		$this->slug        = 'fluent-forms';
		$this->name        = 'Fluent Forms';
		$this->description = __( 'Sync Fluent Forms submissions with per-form field mapping', 'surecontact' );
		$this->docs_url    = '';
		$this->dependency  = 'FluentForm\App\Http\Controllers\IntegrationManagerController';

		parent::__construct();
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 0.0.3
	 */
	protected function init() {
		add_action( 'fluentform/submission_inserted', array( $this, 'handle_form_submission' ), 10, 3 );
	}

	/**
	 * Get available item types.
	 *
	 * @since 0.0.3
	 *
	 * @return array Item type definitions.
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
	 * Get available events for item type.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_type Item type.
	 * @return array Event definitions.
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
	 * Get item configuration fields.
	 *
	 * @since 0.0.3
	 *
	 * @param string      $item_id Form ID.
	 * @param string|null $event   Event name.
	 * @return array Configuration fields.
	 */
	public function get_item_config_fields( $item_id, $event = null ) {
		unset( $item_id, $event );

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
	 * Handle form submission.
	 *
	 * @since 0.0.3
	 *
	 * @param int    $insert_id Submission ID.
	 * @param array  $form_data Form submission data.
	 * @param object $form      Form object.
	 * @return void
	 */
	public function handle_form_submission( $insert_id, $form_data, $form ) {
		unset( $insert_id );

		if ( empty( $form ) || ! is_object( $form ) || ! isset( $form->id ) ) {
			Logger::error( 'Fluent Forms Integration', 'Invalid form object' );
			return;
		}

		$form_id = absint( $form->id );
		if ( ! $form_id ) {
			Logger::error( 'Fluent Forms Integration', 'Form ID missing in submission' );
			return;
		}

		$config = $this->integrations_db->get( $this->slug, (string) $form_id, 'form', 'submission' );

		if ( empty( $config ) || empty( $config['config'] ) ) {
			$config = $this->integrations_db->get( $this->slug, (string) $form_id, 'form', null );
		}

		if ( empty( $config ) || empty( $config['config'] ) || empty( $config['status'] ) ) {
			return;
		}

		$settings      = $config['config'];
		$field_mapping = $settings['field_mapping'] ?? array();

		if ( empty( $field_mapping ) ) {
			Logger::warning( 'Fluent Forms Integration', "Form {$form_id} has no field mapping configured. Attempting auto-detection." );
		}

		$this->current_form_id            = $form_id;
		$this->current_form_field_formats = null;

		$submission_data = is_array( $form_data ) ? $form_data : array();
		$contact_data    = $this->format_field_mapping_data( $field_mapping, $submission_data );
		$crm_data        = $this->build_crm_data_from_form_submission( $contact_data );
		$context         = array( 'trigger' => 'fluent_forms_submission' );

		if ( ! empty( $settings['add_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $settings['add_lists'] );
			if ( ! empty( $list_uuids ) ) {
				$context['list_uuids'] = $list_uuids;
			}
		}

		if ( ! empty( $settings['add_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $settings['add_tags'] );
			if ( ! empty( $tag_uuids ) ) {
				$context['tag_uuids'] = $tag_uuids;
			}
		}

		$user_id = get_current_user_id();
		$result  = $this->send_to_crm( $crm_data, $user_id, $context );

		if ( ! is_wp_error( $result ) && isset( $result['contact_id'] ) ) {
			$contact_id = $result['contact_id'];

			if ( ! empty( $settings['remove_lists'] ) ) {
				$list_uuids = $this->extract_uuids( $settings['remove_lists'] );
				if ( ! empty( $list_uuids ) ) {
					// Use contact_service instead of direct API instantiation.
					$this->contact_service->detach_lists_from_contact( $contact_id, $list_uuids );
				}
			}

			if ( ! empty( $settings['remove_tags'] ) ) {
				$tag_uuids = $this->extract_uuids( $settings['remove_tags'] );
				if ( ! empty( $tag_uuids ) ) {
					$this->contact_service->detach_tags_from_contact( $contact_id, $tag_uuids );
				}
			}
		}
	}

	/**
	 * Get field value from Fluent Forms submission data.
	 *
	 * Handles Fluent Forms-specific naming conventions and nested field structures.
	 *
	 * @since 0.0.3
	 *
	 * @param array  $submission Form submission data.
	 * @param string $field_key  Field key.
	 * @return mixed RAW field value or null.
	 */
	protected function get_submission_field_value( $submission, $field_key ) {
		if ( isset( $submission[ $field_key ] ) ) {
			return $submission[ $field_key ];
		}

		// Handle nested fields (e.g., 'address[street]').
		if ( preg_match( '/^([a-zA-Z0-9_-]+)\[([a-zA-Z0-9_-]+)\]$/', $field_key, $matches ) ) {
			$parent_key = $matches[1];
			$child_key  = $matches[2];

			if ( isset( $submission[ $parent_key ][ $child_key ] ) ) {
				return $submission[ $parent_key ][ $child_key ];
			}

			if ( isset( $submission[ $field_key ] ) ) {
				return $submission[ $field_key ];
			}
		}

		// Try underscored version.
		$underscored_key = str_replace( '-', '_', $field_key );
		if ( $underscored_key !== $field_key && isset( $submission[ $underscored_key ] ) ) {
			return $submission[ $underscored_key ];
		}

		// Try hyphenated version.
		$hyphenated_key = str_replace( '_', '-', $field_key );
		if ( $hyphenated_key !== $field_key && isset( $submission[ $hyphenated_key ] ) ) {
			return $submission[ $hyphenated_key ];
		}

		return null;
	}


	/**
	 * Build CRM data structure from form submission.
	 *
	 * @since 0.0.3
	 *
	 * @param array $contact_data Contact data.
	 * @return array CRM data structure.
	 */
	private function build_crm_data_from_form_submission( $contact_data ) {
		$primary_field_keys = Field_Mapper::get_primary_field_keys();

		$primary_fields = array();
		$custom_fields  = array();

		foreach ( $contact_data as $key => $value ) {
			if ( $value === null || $value === '' ) {
				continue;
			}

			if ( in_array( $key, $primary_field_keys, true ) ) {
				$primary_fields[ $key ] = $value;
			} else {
				$custom_fields[ $key ] = $value;
			}
		}

		return $this->build_crm_data( $primary_fields, $custom_fields, array() );
	}

	/**
	 * Get form title by ID.
	 *
	 * @since 0.0.3
	 *
	 * @param int $form_id Form ID.
	 * @return string Form title.
	 */
	private function get_form_title( $form_id ) {
		if ( ! class_exists( 'FluentForm\App\Models\Form' ) ) {
			return '';
		}

		try {
			$form = \FluentForm\App\Models\Form::select( array( 'title' ) )
				->where( 'id', absint( $form_id ) )
				->first();

			return ( $form && isset( $form->title ) ) ? $form->title : '';
		} catch ( \Exception $e ) {
			Logger::error( 'Fluent Forms Integration', 'Error fetching form title: ' . $e->getMessage(), array( 'form_id' => $form_id ) );
			return '';
		}
	}

	/**
	 * Get item title by ID and type.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id   Item ID.
	 * @param string $item_type Item type.
	 * @return string|null Item title.
	 */
	public function get_item_title( $item_id, $item_type ) {
		if ( 'form' !== $item_type ) {
			return null;
		}

		return $this->get_form_title( (int) $item_id );
	}

	/**
	 * Get forms list.
	 *
	 * @since 0.0.3
	 *
	 * @return array Form items.
	 */
	public function get_forms() {
		if ( ! class_exists( 'FluentForm\App\Models\Form' ) ) {
			return array();
		}

		try {
			$forms = \FluentForm\App\Models\Form::select( array( 'id', 'title' ) )
				->orderBy( 'title', 'ASC' )
				->get();

			if ( empty( $forms ) ) {
				return array();
			}

			$items = array();
			foreach ( $forms as $form ) {
				$items[] = array(
					'id'    => $form->id,
					'title' => $form->title,
					'type'  => 'form',
				);
			}

			return $items;
		} catch ( \Exception $e ) {
			Logger::error( 'Fluent Forms Integration', 'Error fetching forms: ' . $e->getMessage() );
			return array();
		}
	}

	/**
	 * Get form fields.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id Form ID.
	 * @return array Form fields.
	 */
	public function get_item_fields( $item_id ) {
		if ( ! class_exists( 'FluentForm\App\Models\Form' ) ) {
			return array();
		}

		if ( ! class_exists( 'FluentForm\App\Modules\Form\FormFieldsParser' ) ) {
			Logger::error( 'Fluent Forms Integration', 'FormFieldsParser class not found. Please ensure Fluent Forms is properly installed.' );
			return array();
		}

		try {
			$form = \FluentForm\App\Models\Form::where( 'id', absint( $item_id ) )->first();

			if ( ! $form ) {
				return array();
			}

			$inputs = \FluentForm\App\Modules\Form\FormFieldsParser::getInputs(
				$form,
				array( 'element', 'attributes', 'settings' )
			);

			if ( empty( $inputs ) ) {
				return array();
			}

			$fields = array();

			foreach ( $inputs as $input ) {
				$element    = $input['element'] ?? '';
				$attributes = $input['attributes'] ?? array();
				$settings   = $input['settings'] ?? array();

				$field_name = $attributes['name'] ?? '';
				if ( empty( $field_name ) ) {
					continue;
				}

				if ( $this->is_container_field( $element, $field_name ) ) {
					continue;
				}

				$field_label = $this->get_field_label( $settings, $attributes, $field_name );

				$field = array(
					'id'    => $field_name,
					'label' => $field_label,
					'type'  => $element,
				);

				// Fluent Forms stores date_format as a PHP format string and submits
				// the value in it, so carry it through rather than guessing later.
				if ( 'input_date' === $element && ! empty( $settings['date_format'] ) ) {
					$field['date_format'] = $settings['date_format'];
				}

				$fields[] = $field;
			}

			return $fields;
		} catch ( \Exception $e ) {
			Logger::error(
				'Fluent Forms Integration',
				'Error fetching form fields: ' . $e->getMessage(),
				array( 'form_id' => $item_id )
			);
			return array();
		}
	}

	/**
	 * Get the date format a form field submits its value in
	 *
	 * Loaded lazily: only date-mapped fields ask for it, so forms without one
	 * never pay for the lookup.
	 *
	 * @since 1.5.5
	 *
	 * @param string $form_field Form field key.
	 * @return string PHP date format (e.g. 'd/m/Y'), or empty string when unknown
	 */
	protected function get_field_source_format( $form_field ) {
		if ( ! $this->current_form_id ) {
			return '';
		}

		if ( null === $this->current_form_field_formats ) {
			$this->current_form_field_formats = array();

			foreach ( $this->get_item_fields( (string) $this->current_form_id ) as $field ) {
				if ( ! empty( $field['id'] ) && ! empty( $field['date_format'] ) ) {
					// Fluent Forms stores PHP format strings apart from the flatpickr
					// 'K' meridiem token, which PHP spells 'A'.
					$this->current_form_field_formats[ $field['id'] ] = str_replace( 'K', 'A', $field['date_format'] );
				}
			}
		}

		return $this->current_form_field_formats[ $form_field ] ?? '';
	}

	/**
	 * Check if field is a container field.
	 *
	 * @since 0.0.3
	 *
	 * @param string $element    Field element type.
	 * @param string $field_name Field name.
	 * @return bool True if container field.
	 */
	private function is_container_field( $element, $field_name ) {
		$container_types = array( 'input_name', 'address' );

		if ( in_array( $element, $container_types, true ) && strpos( $field_name, '[' ) === false ) {
			return true;
		}

		return false;
	}

	/**
	 * Get field label.
	 *
	 * @since 0.0.3
	 *
	 * @param array  $settings   Field settings.
	 * @param array  $attributes Field attributes.
	 * @param string $field_name Field name.
	 * @return string Field label.
	 */
	private function get_field_label( $settings, $attributes, $field_name ) {
		$field_label = $settings['admin_field_label'] ?? $settings['label'] ?? $attributes['placeholder'] ?? '';

		if ( ! empty( $field_label ) ) {
			// Ensure we're working with a string before preg_replace to avoid array return type.
			$field_label = is_string( $field_label ) ? $field_label : '';
			if ( ! empty( $field_label ) ) {
				$result      = preg_replace( '/^[a-zA-Z0-9_-]+\[(.+?)\]$/', '$1', $field_label );
				$field_label = is_string( $result ) ? $result : $field_label;
			}
		}

		if ( empty( $field_label ) ) {
			if ( preg_match( '/\[([a-zA-Z0-9_-]+)\]$/', $field_name, $matches ) ) {
				$field_label = ucwords( str_replace( array( '_', '-' ), ' ', $matches[1] ) );
			} else {
				$field_label = ucwords( str_replace( array( '_', '-' ), ' ', $field_name ) );
			}
		}

		return $field_label;
	}
}
