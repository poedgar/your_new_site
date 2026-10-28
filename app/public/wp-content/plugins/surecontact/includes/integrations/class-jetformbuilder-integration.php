<?php
/**
 * JetFormBuilder Integration
 *
 * Handles JetFormBuilder form submissions with rule-based field mapping
 *
 * @since 1.4.0
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
 * Class JetFormBuilder_Integration
 *
 * Integrates JetFormBuilder with SureContact using the rule engine system.
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
 * @since 1.4.0
 */
class JetFormBuilder_Integration extends Base_Integration {

	/**
	 * Option value-to-label map for the current form
	 *
	 * Populated by load_option_labels() before field mapping.
	 * Structure: [ 'field_name' => [ 'value' => 'Label', ... ], ... ]
	 *
	 * @since 1.4.0
	 *
	 * @var array
	 */
	private $option_labels_map = array();

	/**
	 * Constructor
	 *
	 * @since 1.4.0
	 */
	public function __construct() {
		$this->slug        = 'jetformbuilder';
		$this->name        = 'JetFormBuilder';
		$this->description = __( 'Sync JetFormBuilder form submissions with per-form field mapping', 'surecontact' );
		$this->docs_url    = '';
		$this->icon_url    = SURECONTACT_PLUGIN_URL . 'assets/images/brands/icons/JetFormBuilder.svg';
		$this->dependency  = 'Jet_Form_Builder\\Plugin';

		parent::__construct();
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	protected function init() {
		add_action( 'jet-form-builder/form-handler/after-send', array( $this, 'handle_form_submission' ), 10, 2 );
	}

	/**
	 * Get all available item types for JetFormBuilder.
	 *
	 * @since 1.4.0
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
	 * @since 1.4.0
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
	 * Get item-specific configuration fields for a JetFormBuilder form.
	 *
	 * @since 1.4.0
	 *
	 * @param string      $item_id Form ID.
	 * @param string|null $event   Event name (not used - kept for compatibility).
	 * @return array Configuration fields schema.
	 */
	public function get_item_config_fields( $item_id, $event = null ) {
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
	 * @since 1.4.0
	 *
	 * @param object $form_handler JetFormBuilder Form_Handler instance.
	 * @param bool   $is_success   Whether the form submission was successful.
	 * @return void
	 */
	public function handle_form_submission( $form_handler, $is_success ) {
		// Only process successful submissions.
		if ( ! $is_success ) {
			return;
		}

		// Validate action handler exists before accessing its properties.
		if ( ! isset( $form_handler->action_handler ) ) {
			Logger::error( 'JetFormBuilder Integration', 'Action handler not available' );
			return;
		}

		$form_id = absint( $form_handler->action_handler->form_id ?? 0 );

		if ( ! $form_id ) {
			Logger::error( 'JetFormBuilder Integration', 'Form ID missing in submission' );
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

		// Get submission data from action handler.
		$posted_data = isset( $form_handler->action_handler->request_data )
			? $form_handler->action_handler->request_data
			: array();

		if ( empty( $posted_data ) ) {
			Logger::error( 'JetFormBuilder Integration', "Form {$form_id}: No posted data found" );
			return;
		}

		// Get field mapping from config.
		$field_mapping = $config['field_mapping'] ?? array();

		if ( empty( $field_mapping ) ) {
			Logger::warning( 'JetFormBuilder Integration', "Form {$form_id} has no field mapping configured. Attempting auto-detection." );
		}

		// Load option value-to-label map for select/radio/checkbox fields.
		// JetFormBuilder submits option values (e.g. 'option_a') but CRM select/multi_select
		// fields expect labels (e.g. 'Option A'). This map resolves them during extraction.
		$this->load_option_labels( $form_id );

		// Prepare contact data using field mapping.
		// Base class format_field_mapping_data() calls get_submission_field_value() for raw values,
		// then applies Field_Formatter formatting based on the CRM field type.
		$contact_data = $this->format_field_mapping_data( $field_mapping, $posted_data );

		// Clear option labels cache.
		$this->option_labels_map = array();

		// Validate we have at least an email.
		if ( ! $this->has_email_in_data( $contact_data ) ) {
			Logger::warning( 'JetFormBuilder Integration', "Form {$form_id}: No email address found in submission data" );
			return;
		}

		// Build CRM data directly (bypass global field mapper since we have per-form mapping).
		$crm_data = $this->build_crm_data_from_form_submission( $contact_data, $form_id );

		// Get form-specific lists and tags from rule engine config.
		$context = array();

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
		$crm_result = $this->send_to_crm( $crm_data, $user_id, $context );

		// Apply remove actions if contact was created/updated successfully.
		if ( ! is_wp_error( $crm_result ) && isset( $crm_result['contact_uuid'] ) ) {
			$contact_uuid = $crm_result['contact_uuid'];

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
	 * Get field value from JetFormBuilder submission data
	 *
	 * Returns raw values as-is. The base class format_field_mapping_data() handles
	 * all formatting via Field_Formatter based on the CRM field type (text, checkbox,
	 * multi_select, boolean, etc.), so we just need to extract the value here.
	 *
	 * @since 1.4.0
	 *
	 * @param array  $submission Form submission data.
	 * @param string $field_key  Field key to retrieve.
	 * @return mixed Raw field value or null if not found
	 */
	protected function get_submission_field_value( $submission, $field_key ) {
		if ( ! isset( $submission[ $field_key ] ) ) {
			return null;
		}

		$value = $submission[ $field_key ];

		// Resolve option values to labels for select/radio/checkbox fields.
		// CRM select/multi_select fields expect labels, not internal values.
		if ( isset( $this->option_labels_map[ $field_key ] ) ) {
			$map = $this->option_labels_map[ $field_key ];

			if ( is_array( $value ) ) {
				// Multi-select: resolve each value to its label, sanitize fallbacks.
				return array_map(
					function ( $v ) use ( $map ) {
						$v = is_scalar( $v ) ? (string) $v : '';
						return $map[ $v ] ?? sanitize_text_field( $v );
					},
					$value
				);
			}

			// Single value: resolve to label, sanitize fallback.
			$value = is_scalar( $value ) ? (string) $value : '';
			return $map[ $value ] ?? sanitize_text_field( $value );
		}

		return $value;
	}

	/**
	 * Load option value-to-label map from form blocks
	 *
	 * Builds a map of field_name => [ value => label ] for all fields
	 * that have field_options (select, radio, checkbox, choices).
	 *
	 * @since 1.4.0
	 *
	 * @param int $form_id Form ID.
	 * @return void
	 */
	private function load_option_labels( $form_id ) {
		$this->option_labels_map = array();

		$form = get_post( (int) $form_id );
		if ( ! $form instanceof \WP_Post || 'jet-form-builder' !== $form->post_type ) {
			return;
		}

		$blocks = parse_blocks( $form->post_content );
		$this->extract_option_labels( $blocks );
	}

	/**
	 * Resolve the field name from block attributes.
	 *
	 * JetFormBuilder omits 'name' from serialized block attrs when it matches the default
	 * (most fields default to 'field_name', hidden fields to 'hidden_field_name').
	 * Falls back to a sanitized slug of the label, matching JetFormBuilder's editor behavior.
	 *
	 * @since 1.4.0
	 *
	 * @param array $attrs Block attributes.
	 * @return string Resolved field name, or empty string if unresolvable.
	 */
	private function resolve_field_name( $attrs ) {
		$default_names = array( '', 'field_name', 'hidden_field_name' );
		$name          = $attrs['name'] ?? '';
		$label         = $attrs['label'] ?? '';

		if ( in_array( $name, $default_names, true ) ) {
			return ! empty( $label ) ? sanitize_title( $label ) : '';
		}
		return $name;
	}

	/**
	 * Recursively extract option labels from blocks
	 *
	 * @since 1.4.0
	 *
	 * @param array $blocks Parsed blocks.
	 * @return void
	 */
	private function extract_option_labels( $blocks ) {
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) && strpos( $block['blockName'], 'jet-forms/' ) === 0 ) {
				$attrs   = $block['attrs'] ?? array();
				$options = $attrs['field_options'] ?? array();

				if ( ! empty( $options ) && is_array( $options ) ) {
					$name = $this->resolve_field_name( $attrs );

					if ( ! empty( $name ) ) {
						$map = array();
						foreach ( $options as $option ) {
							if ( isset( $option['value'] ) && isset( $option['label'] ) ) {
								$map[ $option['value'] ] = sanitize_text_field( $option['label'] );
							}
						}
						if ( ! empty( $map ) ) {
							$this->option_labels_map[ $name ] = $map;
						}
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->extract_option_labels( $block['innerBlocks'] );
			}
		}
	}

	/**
	 * Check if contact data contains an email address
	 *
	 * @since 1.4.0
	 *
	 * @param array $contact_data Contact data array.
	 * @return bool
	 */
	private function has_email_in_data( $contact_data ) {
		return isset( $contact_data['email'] ) && is_email( $contact_data['email'] );
	}

	/**
	 * Build CRM data structure from form submission
	 *
	 * @since 1.4.0
	 *
	 * @param array $contact_data Prepared contact data.
	 * @param int   $form_id      Form ID.
	 * @return array CRM data structure
	 */
	private function build_crm_data_from_form_submission( $contact_data, $form_id ) {
		$primary_field_keys = Field_Mapper::get_primary_field_keys();

		$primary_fields = array();
		$custom_fields  = array();
		$metadata       = array();

		foreach ( $contact_data as $key => $value ) {
			if ( $value === null || $value === '' ) {
				continue;
			}

			if ( in_array( $key, $primary_field_keys, true ) ) {
				$primary_fields[ $key ] = $value;
			} elseif ( strpos( $key, '_' ) === 0 ) {
				// Only internal underscore-prefixed keys go to metadata.
				$metadata[ $key ] = $value;
			} else {
				$custom_fields[ $key ] = $value;
			}
		}

		return $this->build_crm_data( $primary_fields, $custom_fields, $metadata );
	}

	/**
	 * Get JetFormBuilder forms list.
	 *
	 * @since 1.4.0
	 *
	 * @return array Array of form items.
	 */
	public function get_forms() {
		if ( ! class_exists( 'Jet_Form_Builder\\Plugin' ) ) {
			return array();
		}

		$forms = get_posts(
			array(
				'post_type'      => 'jet-form-builder',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
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
	 * Get JetFormBuilder item fields.
	 *
	 * Parses Gutenberg blocks from form post content to extract field definitions.
	 * Returns every block that can capture user input. The 'type' value is a UI hint
	 * for the field mapping drawer; actual formatting is driven by the CRM field type
	 * via Field_Formatter.
	 *
	 * @since 1.4.0
	 *
	 * @param string $item_id Item ID (form ID).
	 * @return array Array of fields with 'id', 'label', and 'type' keys.
	 */
	public function get_item_fields( $item_id ) {
		if ( ! class_exists( 'Jet_Form_Builder\\Plugin' ) ) {
			return array();
		}

		$form = get_post( (int) $item_id );

		if ( ! $form || ! is_object( $form ) || 'jet-form-builder' !== $form->post_type ) {
			return array();
		}

		// Try JetFormBuilder's Block_Helper first, fall back to parse_blocks.
		if ( class_exists( 'Jet_Form_Builder\\Blocks\\Block_Helper' ) ) {
			$blocks = \Jet_Form_Builder\Blocks\Block_Helper::get_blocks_by_post( (int) $item_id );
		} else {
			$blocks = parse_blocks( $form->post_content );
		}

		if ( empty( $blocks ) ) {
			return array();
		}

		return $this->extract_fields_from_blocks( $blocks );
	}

	/**
	 * Extract form fields from JetFormBuilder blocks recursively
	 *
	 * Walks through blocks and inner blocks to find all field definitions.
	 *
	 * @since 1.4.0
	 *
	 * @param array $blocks Array of parsed blocks.
	 * @return array Array of field definitions with 'id', 'label', and 'type' keys.
	 */
	private function extract_fields_from_blocks( $blocks ) {
		$fields = array();

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) && strpos( $block['blockName'], 'jet-forms/' ) === 0 ) {
				$field = $this->parse_block_to_field( $block );
				if ( $field ) {
					$fields[] = $field;
				}
			}

			// Recurse into inner blocks for columns, groups, choices, etc.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$fields = array_merge( $fields, $this->extract_fields_from_blocks( $block['innerBlocks'] ) );
			}
		}

		return $fields;
	}

	/**
	 * Parse a single JetFormBuilder block into a field definition
	 *
	 * Includes every block that can capture and submit user data.
	 * Excludes only pure layout/UI blocks that produce no form data.
	 *
	 * @since 1.4.0
	 *
	 * @param array $block Parsed block data.
	 * @return array|null Field definition or null if not a data-producing field.
	 */
	private function parse_block_to_field( $block ) {
		$attrs = $block['attrs'] ?? array();

		// Extract block type from blockName (e.g., 'jet-forms/text-field' -> 'text-field').
		$block_type = str_replace( 'jet-forms/', '', $block['blockName'] );

		// Skip blocks that produce no form submission data (pure layout/UI).
		$skip_types = array(
			'submit-field',
			'action-button',
			'heading-field',
			'group-break-field',
			'form-break-field',
			'form-break-start',
			'form-block',
			'progress-bar',
			'conditional-block',
			'repeater-field',
			'captcha-container',
		);

		if ( in_array( $block_type, $skip_types, true ) ) {
			return null;
		}

		$label = $attrs['label'] ?? '';

		$name = $this->resolve_field_name( $attrs );
		if ( empty( $name ) ) {
			return null;
		}

		if ( empty( $label ) ) {
			$label = ucwords( str_replace( array( '-', '_' ), ' ', $name ) );
		}

		// Determine display type hint for the field mapping UI.
		// For text-field blocks, check the field_type attr (email, tel, url, etc.).
		$type = $block_type;
		if ( 'text-field' === $block_type && ! empty( $attrs['field_type'] ) ) {
			$type = $attrs['field_type'];
		}

		// Normalize the display type: strip '-field' suffix for cleaner UI display.
		$type = preg_replace( '/-field$/', '', $type );

		return array(
			'id'    => $name,
			'label' => $label,
			'type'  => $type,
		);
	}

	/**
	 * Get item title by type and ID.
	 *
	 * @since 1.4.0
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

		if ( ! $form instanceof \WP_Post || 'jet-form-builder' !== $form->post_type ) {
			return null;
		}

		return $form->post_title;
	}
}
