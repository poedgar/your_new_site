<?php
/**
 * Get Integration Details Ability
 *
 * @since 1.3.1
 *
 * @package SureContact\Abilities\Integrations
 */

namespace SureContact\Abilities\Integrations;

use SureContact\Abilities\Abstract_Ability;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get_Integration class
 *
 * Returns detailed information for a specific SureContact integration.
 *
 * @since 1.3.1
 */
class Get_Integration extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/get-integration';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Get Integration Details', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Get detailed information for a specific SureContact integration. Returns the full schema, supported events, settings, and current configuration.

RETURNS:
- item_types: What items this integration supports (forms, products, coupons, etc.)
- events_by_item_type: Available trigger events per item type (submission, purchase, etc.)
- settings_fields: Schema for global integration settings
- require_field_mapping: Whether rules need field_mapping in config
- global_enabled: Whether the integration master switch is on
- global_config: Current global settings values
- rules: List of existing automation rules for this integration

WORKFLOW — this is a critical step when setting up an integration:
1. surecontact/list-integration → find the integration slug
2. THIS TOOL → get events, item types, and settings schema
3. surecontact/list-integration-item → get available items (forms, products)
4. surecontact/get-item-field → get field mapping options for a specific item
5. surecontact/create-rule → create the automation rule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'slug' ],
			'properties' => [
				'slug' => [
					'type'        => 'string',
					'description' => 'Integration slug. Get this from surecontact/list-integration. Common values: "wpforms", "woocommerce", "surecart", "sureforms", "contact-form-7", "gravity-forms".',
				],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_annotations(): array {
		return [
			'priority'        => 0.8,
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Input arguments.
	 */
	public function execute( array $args = [] ) {
		if ( ! $this->is_connected() ) {
			return $this->connection_error();
		}

		$slug = isset( $args['slug'] ) ? $args['slug'] : '';

		$unavailable = $this->integration_unavailable_error( $slug );
		if ( null !== $unavailable ) {
			return $unavailable;
		}

		// Get integration metadata.
		$metadata_request = new \WP_REST_Request( 'GET', '/surecontact/v1/integration-rules/metadata/' . $slug );
		$metadata_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$metadata_response = rest_do_request( $metadata_request );

		$metadata  = null;
		$available = false;

		if ( ! $metadata_response->is_error() ) {
			$metadata_data = $metadata_response->get_data();
			$metadata      = isset( $metadata_data['metadata'] ) ? $metadata_data['metadata'] : null;
			$available     = true;
		} else {
			$error      = $metadata_response->as_error();
			$error_code = $error instanceof \WP_Error ? $error->get_error_code() : '';
			if ( 'integration_not_found' === $error_code ) {
				return $this->error(
					sprintf(
						/* translators: %s: integration slug */
						__( 'Integration not found: %s', 'surecontact' ),
						$slug
					),
					__( 'Use surecontact/list-integration to see available integrations and their slugs.', 'surecontact' )
				);
			}
		}

		if ( $metadata ) {
			$message = sprintf(
				/* translators: %s: integration name */
				__( 'Details retrieved for "%s" integration.', 'surecontact' ),
				isset( $metadata['name'] ) ? $metadata['name'] : $slug
			);
		} elseif ( ! $available ) {
			$message = sprintf(
				/* translators: %s: integration slug */
				__( 'Integration "%s" is registered but its required plugin is not active. Install and activate the plugin first.', 'surecontact' ),
				$slug
			);
		} else {
			$message = sprintf(
				/* translators: %s: integration slug */
				__( 'Integration "%s" is available but could not be loaded. Check for plugin conflicts.', 'surecontact' ),
				$slug
			);
		}

		// Get accurate global_enabled state from the available-integrations endpoint.
		$global_enabled = false;

		$avail_request = new \WP_REST_Request( 'GET', '/surecontact/v1/integration-rules/available-integrations' );
		$avail_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$avail_response = rest_do_request( $avail_request );

		if ( ! $avail_response->is_error() ) {
			$avail_data = $avail_response->get_data();
			foreach ( ( isset( $avail_data['integrations'] ) ? $avail_data['integrations'] : [] ) as $integration ) {
				if ( isset( $integration['slug'] ) && $integration['slug'] === $slug ) {
					$global_enabled = ! empty( $integration['enabled'] );
					break;
				}
			}
		}

		// Get current global config values.
		$global_config = [];

		$settings_request = new \WP_REST_Request( 'GET', '/surecontact/v1/integration-rules/settings/' . $slug );
		$settings_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$settings_response = rest_do_request( $settings_request );

		if ( ! $settings_response->is_error() ) {
			$settings_data = $settings_response->get_data();
			$global_config = isset( $settings_data['settings'] ) ? $settings_data['settings'] : [];
		}

		// Get existing rules for this integration.
		$rules_request = new \WP_REST_Request( 'GET', '/surecontact/v1/integration-rules/configured-integrations' );
		$rules_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$rules_response = rest_do_request( $rules_request );
		$rules          = [];

		if ( ! $rules_response->is_error() ) {
			$rules_data = $rules_response->get_data();
			foreach ( ( isset( $rules_data['configured_integrations'] ) ? $rules_data['configured_integrations'] : [] ) as $rule ) {
				if ( isset( $rule['slug'] ) && $rule['slug'] === $slug ) {
					$rules[] = $rule;
				}
			}
		}

		return $this->success(
			$message,
			[
				'slug'                  => $slug,
				'name'                  => $metadata ? ( isset( $metadata['name'] ) ? $metadata['name'] : $slug ) : $slug,
				'description'           => $metadata ? ( isset( $metadata['description'] ) ? $metadata['description'] : '' ) : '',
				'available'             => $available,
				'global_enabled'        => $global_enabled,
				'item_types'            => $metadata ? ( isset( $metadata['item_types'] ) ? $metadata['item_types'] : [] ) : [],
				'events_by_item_type'   => $metadata ? ( isset( $metadata['events_by_item_type'] ) ? $metadata['events_by_item_type'] : [] ) : [],
				'settings_fields'       => $metadata ? ( isset( $metadata['settings_fields'] ) ? $metadata['settings_fields'] : [] ) : [],
				'require_field_mapping' => $metadata ? ( isset( $metadata['require_field_mapping'] ) ? (bool) $metadata['require_field_mapping'] : false ) : false,
				'global_config'         => $global_config,
				'rules'                 => $rules,
			]
		);
	}
}
