<?php
/**
 * Fields API Client
 *
 * Handles field-specific operations using the SaaS Client.
 * This class provides a semantic API for custom field operations
 * and delegates HTTP communication to SaaS_Client.
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact\API;

use SureContact\SaaS_Client;
use SureContact\Traits\API_Retry;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Fields_API
 *
 * Handles all custom field-related API operations with the external SaaS API.
 * Provides semantic methods for field CRUD operations and delegates
 * HTTP communication to SaaS_Client.
 *
 * @since 0.0.1
 */
class Fields_API {

	use API_Retry;

	/**
	 * SaaS Client instance
	 *
	 * @since 0.0.1
	 *
	 * @var SaaS_Client
	 */
	private $saas_client;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 *
	 * @param SaaS_Client|null $saas_client Optional. SaaS client instance.
	 */
	public function __construct( ?SaaS_Client $saas_client = null ) {
		$this->saas_client = $saas_client ? $saas_client : new SaaS_Client();
	}

	/**
	 * Get custom fields from CRM
	 *
	 * Fetches available custom fields that can be mapped in the settings UI.
	 * Uses WordPress-specific endpoint to ensure compatibility.
	 * Primary fields (email, first_name, last_name, phone, company, job_title) are
	 * always available on the WordPress side and don't need to be fetched from the API.
	 *
	 * @since 0.0.1
	 *
	 * @return array|WP_Error Array of custom fields or WP_Error on failure
	 */
	public function get_custom_fields() {
		return $this->saas_client->post( 'wordpress/custom-fields', array() );
	}

	/**
	 * Sync custom field to CRM
	 *
	 * Creates a custom field in SureContact if it doesn't exist.
	 * The API will return the existing field if a field with the same name already exists.
	 *
	 * @since 0.0.3
	 *
	 * @param array $field_data {
	 *     Field configuration.
	 *
	 *     @type string $name         Field name (required) - must be unique within workspace.
	 *     @type string $label        Field label (required) - human-readable label.
	 *     @type string $type         Field type (required) - text, number, select, date, boolean, etc.
	 *     @type bool   $is_required  Whether field is required (optional, default false).
	 *     @type string $description  Field description (optional).
	 *     @type array  $options      Options for select/multi-select fields (optional).
	 *     @type mixed  $default_value Default value (optional).
	 * }
	 * @param array $options    Optional. Additional options including 'source'.
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function sync_custom_field( $field_data, $options = array() ) {
		// Validate required fields.
		if ( empty( $field_data['name'] ) ) {
			return new WP_Error(
				'missing_field_name',
				__( 'Field name is required.', 'surecontact' )
			);
		}

		if ( empty( $field_data['label'] ) ) {
			return new WP_Error(
				'missing_field_label',
				__( 'Field label is required.', 'surecontact' )
			);
		}

		if ( empty( $field_data['type'] ) ) {
			return new WP_Error(
				'missing_field_type',
				__( 'Field type is required.', 'surecontact' )
			);
		}

		// Map 'type' to 'field_type' for API compatibility.
		$api_field_data = array(
			'name'        => sanitize_key( $field_data['name'] ),
			'label'       => sanitize_text_field( $field_data['label'] ),
			'field_type'  => sanitize_text_field( $field_data['type'] ),
			'is_required' => isset( $field_data['is_required'] ) ? (bool) $field_data['is_required'] : false,
		);

		// Add optional fields if present.
		if ( ! empty( $field_data['description'] ) ) {
			$api_field_data['description'] = sanitize_textarea_field( $field_data['description'] );
		}

		if ( ! empty( $field_data['options'] ) && is_array( $field_data['options'] ) ) {
			$api_field_data['options'] = array_map( 'sanitize_text_field', $field_data['options'] );
		}

		if ( isset( $field_data['default_value'] ) ) {
			$api_field_data['default_value'] = $field_data['default_value'];
		}

		// Execute with automatic retry logic.
		return $this->execute_with_retry(
			function () use ( $api_field_data ) {
				return $this->saas_client->post( 'custom-fields', $api_field_data );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'custom-fields',
				'payload'      => $api_field_data,
				'operation'    => 'sync_custom_field',
			),
			$options
		);
	}
}
