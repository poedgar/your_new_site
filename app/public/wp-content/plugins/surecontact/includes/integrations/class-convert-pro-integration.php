<?php
/**
 * Convert Pro Integration
 *
 * Handles Convert Pro module submissions with per-module field mapping
 *
 * @since 1.3.0
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
 * Class Convert_Pro_Integration
 *
 * Integrates Convert Pro with SureContact using the rule engine system.
 *
 * Configuration is managed entirely through the rule engine:
 * - Per-module field mapping configuration
 * - Per-module lists and tags assignment
 * - Enable/disable per module via rule status
 * - Data is built directly in CRM format using build_crm_data()
 *
 * All settings are stored in the integrations database table and managed
 * through the unified rule engine UI.
 *
 * @since 1.3.0
 */
class Convert_Pro_Integration extends Base_Integration {

	/**
	 * Map of Convert Pro field types to their name property keys in cp_modal_data.
	 *
	 * @since 1.3.0
	 *
	 * @var array
	 */
	private static $field_name_keys = array(
		'cp_text'         => 'input_text_name',
		'cp_email'        => 'input_text_name',
		'cp_number'       => 'input_text_name',
		'cp_textarea'     => 'input_text_name',
		'cp_dropdown'     => 'dropdown_name',
		'cp_radio'        => 'radio_name',
		'cp_checkbox'     => 'checkbox_name',
		'cp_date'         => 'date_name',
		'cp_hidden_input' => 'hidden_input_name',
	);

	/**
	 * Map of Convert Pro field types to the property key that holds the human-readable label.
	 *
	 * @since 1.3.0
	 *
	 * @var array
	 */
	private static $field_label_keys = array(
		'cp_email'        => 'email_text_placeholder',
		'cp_text'         => 'input_text_placeholder',
		'cp_number'       => 'input_text_placeholder',
		'cp_textarea'     => 'input_text_placeholder',
		'cp_dropdown'     => 'input_text_placeholder',
		'cp_radio'        => 'input_text_placeholder',
		'cp_checkbox'     => 'input_text_placeholder',
		'cp_date'         => 'date_name',
		'cp_hidden_input' => 'hidden_input_name',
	);

	/**
	 * Constructor
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		$this->slug        = 'convert-pro';
		$this->name        = 'Convert Pro';
		$this->description = __( 'Sync Convert Pro call-to-action submissions with per call-to-action field mapping', 'surecontact' );
		$this->docs_url    = '';
		$this->dependency  = 'Cp_V2_Loader';

		parent::__construct();
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	protected function init() {
		// Core path: fires when no addon mailer service is connected to the form.
		add_action( 'cpro_form_submit', array( $this, 'handle_form_submission' ), 10, 2 );

		// Addon path: fires when a mailer service is connected via Convert Pro Addon.
		add_filter( 'cpro_form_submit_settings', array( $this, 'handle_addon_form_submission' ), 10, 1 );
	}

	/**
	 * Get all available item types for Convert Pro.
	 *
	 * @since 1.3.0
	 *
	 * @return array Array of item type definitions with 'key' and 'label' keys.
	 */
	public function get_item_types() {
		return array(
			array(
				'key'   => 'module',
				'label' => __( 'Call-to-action', 'surecontact' ),
			),
		);
	}

	/**
	 * Get available events for a specific item type.
	 *
	 * @since 1.3.0
	 *
	 * @param string $item_type Item type (e.g., 'module').
	 * @return array Array of event definitions with 'key' and 'label' keys.
	 */
	public function get_events_by_item_type( $item_type ) {
		switch ( $item_type ) {
			case 'module':
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
	 * Get item-specific configuration fields for a Convert Pro module.
	 *
	 * @since 1.3.0
	 *
	 * @param string      $item_id Module ID.
	 * @param string|null $event   Event name (not used – kept for interface compatibility).
	 * @return array Configuration fields schema.
	 */
	public function get_item_config_fields( $item_id, $event = null ) {
		return array_merge(
			array(
				'field_mapping' => array(
					'label'       => __( 'Field Mapping', 'surecontact' ),
					'description' => __( 'Map call-to-action fields to CRM fields. At minimum, map the email field.', 'surecontact' ),
					'type'        => 'field-mapping',
					'default'     => array(),
				),
			),
			self::get_standard_list_tag_fields()
		);
	}

	/**
	 * Handle Convert Pro form submission (core path).
	 *
	 * Fires on the `cpro_form_submit` action, which only fires after the
	 * submission has passed all of Convert Pro's own validations (MX, reCAPTCHA).
	 * This action is triggered only when no mailer addon service is connected.
	 *
	 * @since 1.3.0
	 *
	 * @param array $response  Response data: ['error' => bool|string, 'style_slug' => string].
	 * @param array $post_data Submission data: ['style_id' => int, 'param' => array, ...].
	 * @return void
	 */
	public function handle_form_submission( $response, $post_data ) {
		if ( empty( $post_data ) || ! is_array( $post_data ) ) {
			Logger::error( 'Convert Pro Integration', 'Invalid form submission data' );
			return;
		}

		$module_id = isset( $post_data['style_id'] ) ? absint( $post_data['style_id'] ) : 0;

		if ( ! $module_id ) {
			Logger::error( 'Convert Pro Integration', 'Module ID missing in submission' );
			return;
		}

		$submission_data = isset( $post_data['param'] ) && is_array( $post_data['param'] )
			? $post_data['param']
			: array();

		$this->process_submission( $module_id, $submission_data );
	}

	/**
	 * Handle Convert Pro form submission (addon path).
	 *
	 * Fires on the `cpro_form_submit_settings` filter inside
	 * ConvertPlugServices::add_subscriber(), which is called when a mailer
	 * addon service (e.g. MailChimp, ActiveCampaign) is connected to the form.
	 * In this path, `cpro_form_submit` never fires.
	 *
	 * Must return $settings unchanged — this is a filter.
	 *
	 * @since 1.3.0
	 *
	 * @param array $settings Settings array: ['style_id' => int, 'param' => array, ...].
	 * @return array Unmodified $settings.
	 */
	public function handle_addon_form_submission( $settings ) {
		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return $settings;
		}

		$module_id       = isset( $settings['style_id'] ) ? absint( $settings['style_id'] ) : 0;
		$submission_data = isset( $settings['param'] ) && is_array( $settings['param'] )
			? $settings['param']
			: array();

		if ( $module_id && ! empty( $submission_data ) ) {
			$this->process_submission( $module_id, $submission_data );
		}

		return $settings;
	}

	/**
	 * Process a Convert Pro submission through the rule engine.
	 *
	 * Shared by both the core path (cpro_form_submit) and the addon path
	 * (cpro_form_submit_settings). Handles rule engine config lookup,
	 * field mapping, CRM data building, list/tag resolution, and CRM sync.
	 *
	 * @since 1.3.0
	 *
	 * @param int   $module_id       Convert Pro module (style) ID.
	 * @param array $submission_data Flat key => value array of submitted field values.
	 * @return void
	 */
	private function process_submission( $module_id, $submission_data ) {
		// Check if this module has a rule engine configuration.
		$result = $this->integrations_db->get( $this->slug, (string) $module_id, 'module', 'submission' );

		// Fallback to null event.
		if ( empty( $result ) || empty( $result['config'] ) ) {
			$result = $this->integrations_db->get( $this->slug, (string) $module_id, 'module', null );
		}

		if ( empty( $result ) || empty( $result['config'] ) ) {
			return;
		}

		if ( empty( $result['status'] ) ) {
			return;
		}

		$config        = $result['config'];
		$field_mapping = $config['field_mapping'] ?? array();

		if ( empty( $field_mapping ) ) {
			Logger::warning( 'Convert Pro Integration', "Module {$module_id} has no field mapping configured." );
		}

		// Normalize legacy cp_email-* source keys to 'email'.
		// cp_email always submits as param[email], but older saved rules may
		// store the panel ID (e.g. "cp_email-2-3855") as the source key.
		$normalized_mapping = array();
		foreach ( $field_mapping as $source => $target ) {
			$normalized_source                        = ( strpos( $source, 'cp_email' ) === 0 ) ? 'email' : $source;
			$normalized_mapping[ $normalized_source ] = $target;
		}

		// Apply field mapping (base class handles the flat array directly).
		$contact_data = $this->format_field_mapping_data( $normalized_mapping, $submission_data );

		// Build CRM data structure.
		$crm_data = $this->build_crm_data_from_form_submission( $contact_data );

		// Resolve lists and tags from rule engine config.
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

		$user_id    = is_user_logged_in() ? get_current_user_id() : 0;
		$crm_result = $this->send_to_crm( $crm_data, $user_id, $context );

		// Apply remove actions after successful contact creation/update.
		if ( ! is_wp_error( $crm_result ) && isset( $crm_result['contact_uuid'] ) ) {
			$contact_uuid = $crm_result['contact_uuid'];

			if ( ! empty( $config['remove_lists'] ) ) {
				$list_uuids = $this->extract_uuids( $config['remove_lists'] );
				if ( ! empty( $list_uuids ) ) {
					$this->contact_service->detach_lists_from_contact( $contact_uuid, $list_uuids );
				}
			}

			if ( ! empty( $config['remove_tags'] ) ) {
				$tag_uuids = $this->extract_uuids( $config['remove_tags'] );
				if ( ! empty( $tag_uuids ) ) {
					$this->contact_service->detach_tags_from_contact( $contact_uuid, $tag_uuids );
				}
			}
		}
	}

	/**
	 * Build CRM data structure from field-mapped contact data.
	 *
	 * @since 1.3.0
	 *
	 * @param array $contact_data Prepared contact data.
	 * @return array CRM data structure.
	 */
	private function build_crm_data_from_form_submission( $contact_data ) {
		$primary_field_keys = Field_Mapper::get_primary_field_keys();
		$primary_fields     = array();
		$custom_fields      = array();
		$metadata           = array();

		foreach ( $contact_data as $key => $value ) {
			if ( $value === null || $value === '' ) {
				continue;
			}

			if ( in_array( $key, $primary_field_keys, true ) ) {
				$primary_fields[ $key ] = $value;
			} elseif ( strpos( $key, 'cp_' ) === 0 || strpos( $key, '_' ) === 0 ) {
				$metadata[ $key ] = $value;
			} else {
				$custom_fields[ $key ] = $value;
			}
		}

		return $this->build_crm_data( $primary_fields, $custom_fields, $metadata );
	}

	/**
	 * Get field value from submission data.
	 *
	 * Overrides the base implementation to handle Convert Pro's indexed
	 * checkbox keys. CP renders each checkbox option with a unique name
	 * like `param[field-0]`, `param[field-1]`, etc. Only checked options
	 * appear in the submission. This method aggregates those indexed values
	 * into an array when a direct key match is not found.
	 *
	 * @since 1.3.0
	 *
	 * @param array  $submission_data Raw submission data.
	 * @param string $field_key       Field key to retrieve.
	 * @return mixed Field value, array of checkbox values, or null.
	 */
	protected function get_submission_field_value( $submission_data, $field_key ) {
		// Direct match — works for text, email, dropdown, radio, etc.
		if ( isset( $submission_data[ $field_key ] ) ) {
			return $submission_data[ $field_key ];
		}

		// Aggregate indexed checkbox keys: {field_key}-0, {field_key}-1, etc.
		$prefix = $field_key . '-';
		$values = array();

		foreach ( $submission_data as $key => $value ) {
			if ( strpos( $key, $prefix ) === 0 ) {
				$values[] = $value;
			}
		}

		return ! empty( $values ) ? $values : null;
	}

	/**
	 * Get Convert Pro modules list.
	 *
	 * Called by the Integration Rules API when fetching items.
	 *
	 * @since 1.3.0
	 *
	 * @return array Array of module items with 'id', 'title', and 'type' keys.
	 */
	public function get_modules() {
		if ( ! class_exists( 'Cp_V2_Loader' ) ) {
			return array();
		}

		$modules = get_posts(
			array(
				'post_type'      => 'cp_popups',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $modules ) ) {
			return array();
		}

		$items = array();
		foreach ( $modules as $module ) {
			$items[] = array(
				'id'    => $module->ID,
				'title' => $module->post_title,
				'type'  => 'module',
			);
		}

		return $items;
	}

	/**
	 * Get Convert Pro module fields for field mapping.
	 *
	 * Called by the Integration Rules API to populate the field mapping UI.
	 * Parses the cp_modal_data JSON stored on the module post to extract
	 * form field names and labels.
	 *
	 * @since 1.3.0
	 *
	 * @param string $item_id Module post ID.
	 * @return array Array of fields with 'id', 'label', and 'type' keys.
	 */
	public function get_item_fields( $item_id ) {
		if ( ! class_exists( 'Cp_V2_Loader' ) ) {
			return array();
		}

		$module = get_post( (int) $item_id );
		if ( ! $module instanceof \WP_Post || 'cp_popups' !== $module->post_type ) {
			return array();
		}

		$modal_data_raw = get_post_meta( (int) $item_id, 'cp_modal_data', true );
		if ( empty( $modal_data_raw ) ) {
			return array();
		}

		$modal_data = json_decode( $modal_data_raw, true );
		if ( ! is_array( $modal_data ) ) {
			return array();
		}

		$fields = array();
		$seen   = array();

		foreach ( $modal_data as $step_key => $step_data ) {
			// Skip the 'common' key – it holds shared properties, not per-field panels.
			if ( 'common' === $step_key || ! is_array( $step_data ) ) {
				continue;
			}

			foreach ( $step_data as $panel_id => $panel_props ) {
				if ( ! is_array( $panel_props ) ) {
					continue;
				}

				$type = $panel_props['type'] ?? null;

				if ( empty( $type ) || ! isset( self::$field_name_keys[ $type ] ) ) {
					continue;
				}

				$name_key = self::$field_name_keys[ $type ];
				$field_id = $panel_props[ $name_key ] ?? null;

				// Some CP field types (e.g. cp_email) may not store the name key;
				// fall back to the panel ID which is always present (e.g. "cp_email-2-3855").
				if ( empty( $field_id ) ) {
					$field_id = $panel_id;
				}

				// cp_email always submits as param[email] regardless of config.
				if ( 'cp_email' === $type ) {
					$field_id = 'email';
				}

				if ( isset( $seen[ $field_id ] ) ) {
					continue;
				}

				$seen[ $field_id ] = true;

				// Extract label from the type-specific placeholder property.
				$label_key   = self::$field_label_keys[ $type ] ?? null;
				$field_label = $label_key ? ( $panel_props[ $label_key ] ?? '' ) : '';

				$fields[] = array(
					'id'    => $field_id,
					'label' => ! empty( $field_label ) ? $field_label : $field_id,
					'type'  => $type,
				);
			}
		}

		return $fields;
	}
}
