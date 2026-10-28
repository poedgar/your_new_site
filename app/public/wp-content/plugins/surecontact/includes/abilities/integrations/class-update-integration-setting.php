<?php
/**
 * Update Integration Setting Ability
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
 * Update_Integration_Setting class
 *
 * Saves global configuration settings for a SureContact integration.
 *
 * @since 1.3.1
 */
class Update_Integration_Setting extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/update-integration-setting';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Update Integration Settings', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Save configuration settings for a SureContact integration. These are integration-specific config options like WooCommerce order tracking toggles, guest customer sync, refund tracking, etc.

WARNING — THIS TOOL DOES NOT ENABLE OR DISABLE AN INTEGRATION.
To turn an integration on or off, use surecontact/update-integration-status instead.
Do NOT pass "enabled" or "global_enabled" as settings keys — they are not valid settings fields.

Only pass keys that appear in the settings_fields schema from surecontact/get-integration.

WORKFLOW:
1. surecontact/get-integration → see settings_fields schema and current global_config values
2. surecontact/update-integration-status → enable the integration first (if not already enabled)
3. THIS TOOL → save the configuration settings';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'slug', 'settings' ],
			'properties' => [
				'slug'     => [
					'type'        => 'string',
					'description' => 'Integration slug. Get this from surecontact/list-integration. Examples: "wpforms", "woocommerce", "surecart", "easy-digital-downloads".',
				],
				'settings' => [
					'type'        => 'object',
					'description' => 'Settings object to save. Keys depend on the integration — use surecontact/get-integration to see the settings_fields schema and current global_config values.',
				],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_annotations(): array {
		return [
			'priority'        => 0.6,
			'readOnlyHint'    => false,
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

		$slug     = isset( $args['slug'] ) ? $args['slug'] : '';
		$settings = isset( $args['settings'] ) ? $args['settings'] : null;

		$unavailable = $this->integration_unavailable_error( $slug );
		if ( null !== $unavailable ) {
			return $unavailable;
		}

		if ( ! is_array( $settings ) ) {
			return $this->error( __( 'Settings must be an object.', 'surecontact' ) );
		}

		// Strip keys that are not valid settings — these belong to update-integration-status.
		unset( $settings['global_enabled'], $settings['enabled'] );

		if ( empty( $settings ) ) {
			return $this->error(
				__( 'No valid settings keys provided.', 'surecontact' ),
				__( 'Use surecontact/get-integration to see available settings_fields. To enable/disable the integration, use surecontact/update-integration-status instead.', 'surecontact' )
			);
		}

		$response = $this->rest_dispatch(
			'POST',
			'/surecontact/v1/integration-rules/settings/save',
			[
				'slug'     => $slug,
				'settings' => $settings,
			]
		);

		if ( $response->is_error() ) {
			return $this->error_from_response( $response, __( 'Use surecontact/get-integration to see available settings fields.', 'surecontact' ) );
		}

		$data = $response->get_data();

		return $this->success(
			sprintf(
				/* translators: %s: integration slug */
				__( 'Settings saved for "%s" integration.', 'surecontact' ),
				$slug
			),
			[
				'slug'     => $slug,
				'settings' => isset( $data['settings'] ) ? $data['settings'] : $settings,
				'status'   => isset( $data['status'] ) ? $data['status'] : 1,
			]
		);
	}
}
