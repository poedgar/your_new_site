<?php
/**
 * Elementor Forms Integration Loader
 *
 * Registers the Elementor Forms integration with Elementor Pro
 *
 * @since 0.0.3
 *
 * @package SureContact
 */

namespace SureContact\Integrations;

use SureContact\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Elementor_Forms
 *
 * Integrates Elementor Forms with SureContact using the rule engine system.
 *
 * Configuration is managed entirely through the rule engine:
 * - Per-form field mapping configuration
 * - Per-form lists and tags assignment
 * - Enable/disable per form via rule status
 *
 * All settings are stored in the integrations database table and managed
 * through the unified rule engine UI.
 *
 * @since 0.0.3
 */
class Elementor_Forms extends Base_Integration {

	/**
	 * Constructor
	 *
	 * @since 0.0.3
	 */
	public function __construct() {
		$this->slug        = 'elementor-forms';
		$this->name        = 'Elementor Forms';
		$this->description = __( 'Sync Elementor Forms submissions with per-form field mapping', 'surecontact' );
		$this->docs_url    = '';
		$this->dependency  = 'ElementorPro\Plugin';

		parent::__construct();
	}

	/**
	 * Initialize integration hooks.
	 *
	 * @since 0.0.3
	 */
	protected function init() {
		// Hook into global form submission event (fires for ALL Elementor form submissions).
		add_action( 'elementor_pro/forms/new_record', array( $this, 'handle_form_submission' ), 10, 2 );
	}

	/**
	 * Handle Elementor form submission.
	 *
	 * This method fires for ALL Elementor form submissions, checks if there's a
	 * Rule Engine configuration for the specific form, and processes accordingly.
	 *
	 * @since 0.0.3
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       Elementor form record.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Ajax handler.
	 * @return void
	 */
	public function handle_form_submission( $record, $ajax_handler ) {
		$sent_data     = $record->get( 'fields' );
		$form_settings = $record->get( 'form_settings' );

		// Get form ID from record.
		// Form ID format: {post_id}_{form_widget_id}.
		$post_id = isset( $form_settings['form_post_id'] ) ? $form_settings['form_post_id'] : 0;
		$form_id = isset( $form_settings['id'] ) ? $form_settings['id'] : '';

		if ( empty( $post_id ) || empty( $form_id ) ) {
			Logger::error( 'Elementor Forms Integration', 'Missing post ID or form ID in submission' );
			return;
		}

		// Build unique form identifier.
		$item_id = $post_id . '_' . $form_id;

		// Check if this form has a configuration in the rule engine.
		$result = $this->integrations_db->get( $this->slug, (string) $item_id, 'form', 'submission' );

		// Fallback to null event if submission event not found.
		if ( empty( $result ) || empty( $result['config'] ) ) {
			$result = $this->integrations_db->get( $this->slug, (string) $item_id, 'form', null );
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
			Logger::warning( 'Elementor Forms Integration', "Form {$item_id} has no field mapping configured." );
			return;
		}

		// Prepare contact data using field mapping.
		$contact_data = $this->format_field_mapping_data( $field_mapping, $sent_data );

		if ( empty( $contact_data ) ) {
			Logger::warning( 'Elementor Forms Integration', "Form {$item_id} submission produced no mapped data." );
			return;
		}

		// Build CRM data directly.
		$crm_data = $this->build_crm_data_from_form_submission( $contact_data );

		// Get form-specific lists and tags from rule engine config.
		$context = array( 'trigger' => 'elementor_forms_submission' );

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
		// Use contact_uuid (canonical key) to match the other form integrations
		// (CF7, JFB, WPForms). Contact_Service returns contact_id for BC, but
		// it holds the same value as contact_uuid.
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
	 * Get field value from Elementor submission data.
	 *
	 * Handles Elementor-specific data structure with 'raw_value' vs 'value'.
	 * Converts acceptance field values ('on', '1') to proper boolean values.
	 *
	 * @since 0.0.3
	 *
	 * @param array  $sent_data Form submission data.
	 * @param string $field_id  Field ID to retrieve.
	 * @return mixed RAW field value or null
	 */
	protected function get_submission_field_value( $sent_data, $field_id ) {
		// Direct field access.
		if ( isset( $sent_data[ $field_id ] ) ) {
			$field = $sent_data[ $field_id ];

			// Get the raw value.
			$value = null;
			if ( isset( $field['raw_value'] ) ) {
				$value = $field['raw_value'];
			} elseif ( isset( $field['value'] ) ) {
				$value = $field['value'];
			}

			// Handle Elementor acceptance fields.
			// Elementor acceptance fields return 'on' or '1' when checked, empty string when unchecked.
			// We need to convert these to boolean values for proper CRM field handling.
			if ( isset( $field['type'] ) && $field['type'] === 'acceptance' ) {
				// Checked: convert 'on', '1', or 1 to true.
				if ( $value === 'on' || $value === '1' || $value === 1 ) {
					return true;
				}
				// Unchecked: convert empty or falsy to false.
				return false;
			}

			return $value;
		}

		return null;
	}

	/**
	 * Build CRM data structure from form submission.
	 *
	 * This method categorizes fields into primary_fields and custom_fields
	 * based on the CRM's field structure.
	 *
	 * @since 0.0.3
	 *
	 * @param array $contact_data Prepared contact data.
	 * @return array CRM data structure
	 */
	private function build_crm_data_from_form_submission( $contact_data ) {
		// Define primary field keys (these are built-in CRM fields).
		$primary_field_keys = \SureContact\Field_Mapper::get_primary_field_keys();

		$primary_fields = array();
		$custom_fields  = array();
		$metadata       = array();

		// Categorize fields.
		foreach ( $contact_data as $key => $value ) {
			// Skip empty values.
			if ( null === $value || '' === $value ) {
				continue;
			}

			// Check if it's a primary field.
			if ( in_array( $key, $primary_field_keys, true ) ) {
				$primary_fields[ $key ] = $value;
			} elseif ( strpos( $key, 'elementor_' ) === 0 || strpos( $key, '_' ) === 0 ) {
				// Check if it's a metadata field (starts with elementor_ or _).
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
	 * Get all available item types for Elementor Forms.
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
	 * Get item-specific configuration fields for an Elementor form.
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
	 * Get Elementor Forms list.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of form items.
	 */
	public function get_forms() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return array();
		}

		$items = array();

		// Get all posts that might contain Elementor forms.
		// Include 'elementor_library' explicitly because Elementor registers it with
		// exclude_from_search=true, which means post_type='any' silently skips it.
		// This is needed to discover forms inside Elementor Popups, Theme Builder templates,
		// and other template types stored in the library.
		$post_types = get_post_types( array( 'public' => true ) );

		// Explicitly add Elementor-specific post types that may contain forms.
		// These may have exclude_from_search=true or other settings that exclude them from 'any'.
		$elementor_post_types = array(
			'elementor_library',    // Popups, theme templates, sections, headers, footers, etc.
			'e-floating-buttons',   // Floating buttons and floating bars.
			'e-landing-page',       // Landing pages.
		);

		foreach ( $elementor_post_types as $elementor_cpt ) {
			if ( ! isset( $post_types[ $elementor_cpt ] ) && post_type_exists( $elementor_cpt ) ) {
				$post_types[ $elementor_cpt ] = $elementor_cpt;
			}
		}

		$args = array(
			'post_type'      => array_values( $post_types ),
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to find Elementor pages
			'meta_query'     => array(
				array(
					'key'     => '_elementor_edit_mode',
					'value'   => 'builder',
					'compare' => '=',
				),
			),
		);

		$posts = get_posts( $args );

		if ( empty( $posts ) ) {
			return array();
		}

		foreach ( $posts as $post ) {
			// Get Elementor data from the post.
			$document = \Elementor\Plugin::$instance->documents->get( $post->ID ); // @phpstan-ignore-line

			if ( ! $document ) {
				continue;
			}

			$data = $document->get_elements_data();

			if ( empty( $data ) ) {
				continue;
			}

			// Recursively search for form widgets.
			$forms = $this->find_elementor_forms( $data, $post->ID, $post->post_title );

			if ( ! empty( $forms ) ) {
				$items = array_merge( $items, $forms );
			}
		}

		return $items;
	}

	/**
	 * Get Elementor form fields.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id Item ID (format: post_id_form_id).
	 * @return array Array of fields with 'id', 'label', and 'type' keys.
	 */
	public function get_item_fields( $item_id ) {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return array();
		}

		// Parse item_id (format: post_id_form_id).
		$parts = explode( '_', $item_id, 2 );

		if ( count( $parts ) !== 2 ) {
			return array();
		}

		$post_id = (int) $parts[0];
		$form_id = $parts[1];

		// Get Elementor data from the post.
		$document = \Elementor\Plugin::$instance->documents->get( $post_id ); // @phpstan-ignore-line

		if ( ! $document ) {
			return array();
		}

		$data = $document->get_elements_data();

		if ( empty( $data ) ) {
			return array();
		}

		// Find the specific form widget.
		$form_widget = $this->find_elementor_form_widget( $data, $form_id );

		if ( ! $form_widget ) {
			return array();
		}

		// Extract form fields.
		$fields = array();

		if ( isset( $form_widget['settings']['form_fields'] ) && is_array( $form_widget['settings']['form_fields'] ) ) {
			foreach ( $form_widget['settings']['form_fields'] as $field ) {
				$field_id    = isset( $field['custom_id'] ) ? $field['custom_id'] : ( isset( $field['_id'] ) ? $field['_id'] : '' );
				$field_label = isset( $field['field_label'] ) ? $field['field_label'] : $field_id;
				$field_type  = isset( $field['field_type'] ) ? $field['field_type'] : 'text';

				if ( ! empty( $field_id ) ) {
					$fields[] = array(
						'id'    => $field_id,
						'label' => $field_label,
						'type'  => $field_type,
					);
				}
			}
		}

		return $fields;
	}

	/**
	 * Get item title by type and ID.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id   Item ID (format: post_id_form_id).
	 * @param string $item_type Item type ('form').
	 * @return string|null Item title or null if not found.
	 */
	public function get_item_title( $item_id, $item_type ) {
		if ( 'form' !== $item_type ) {
			return null;
		}

		// Parse item_id (format: post_id_form_id).
		$parts = explode( '_', $item_id, 2 );

		if ( count( $parts ) !== 2 ) {
			return null;
		}

		$post_id = (int) $parts[0];
		$form_id = $parts[1];

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		// Try to get form name from Elementor data.
		if ( did_action( 'elementor/loaded' ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id ); // @phpstan-ignore-line

			if ( $document ) {
				$data        = $document->get_elements_data();
				$form_widget = $this->find_elementor_form_widget( $data, $form_id );

				if ( $form_widget && isset( $form_widget['settings']['form_name'] ) && ! empty( $form_widget['settings']['form_name'] ) ) {
					return $post->post_title . ' - ' . $form_widget['settings']['form_name'];
				}
			}
		}

		return $post->post_title;
	}

	/**
	 * Recursively find Elementor form widgets.
	 *
	 * @since 0.0.3
	 *
	 * @param array  $elements   Elementor elements data.
	 * @param int    $post_id    Post ID.
	 * @param string $post_title Post title.
	 * @return array
	 */
	private function find_elementor_forms( $elements, $post_id, $post_title ) {
		$forms = array();

		foreach ( $elements as $element ) {
			// Check if this is a form widget.
			if ( isset( $element['widgetType'] ) && 'form' === $element['widgetType'] ) {
				$form_name = isset( $element['settings']['form_name'] ) ? $element['settings']['form_name'] : '';
				$form_id   = $element['id'];

				// Create a unique identifier for this form.
				$unique_id = $post_id . '_' . $form_id;

				$items_title = $post_title;
				if ( ! empty( $form_name ) ) {
					$items_title .= ' - ' . $form_name;
				}

				$forms[] = array(
					'id'    => $unique_id,
					'title' => $items_title,
					'type'  => 'form',
				);
			}

			// Recursively search in nested elements.
			if ( ! empty( $element['elements'] ) ) {
				$nested_forms = $this->find_elementor_forms( $element['elements'], $post_id, $post_title );
				if ( ! empty( $nested_forms ) ) {
					$forms = array_merge( $forms, $nested_forms );
				}
			}
		}

		return $forms;
	}

	/**
	 * Find Elementor form widget by ID.
	 *
	 * @since 0.0.3
	 *
	 * @param array  $elements Elementor elements data.
	 * @param string $form_id  Form widget ID.
	 * @return array|null
	 */
	private function find_elementor_form_widget( $elements, $form_id ) {
		foreach ( $elements as $element ) {
			// Check if this is the form widget we're looking for.
			if ( isset( $element['id'] ) && $element['id'] === $form_id && isset( $element['widgetType'] ) && 'form' === $element['widgetType'] ) {
				return $element;
			}

			// Recursively search in nested elements.
			if ( ! empty( $element['elements'] ) ) {
				$found = $this->find_elementor_form_widget( $element['elements'], $form_id );
				if ( $found ) {
					return $found;
				}
			}
		}

		return null;
	}
}
