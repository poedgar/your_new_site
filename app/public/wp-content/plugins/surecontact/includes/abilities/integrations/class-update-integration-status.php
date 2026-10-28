<?php
/**
 * Update Integration Status Ability
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
 * Update_Integration_Status class
 *
 * Enables or disables a SureContact integration globally.
 *
 * @since 1.3.1
 */
class Update_Integration_Status extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/update-integration-status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Enable/Disable Integration', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Enable or disable a SureContact integration (master on/off switch). This is the ONLY tool that controls whether an integration is active. When disabled, all its automation rules stop processing.

USE THIS TOOL whenever the user wants to:
- Enable or disable an integration
- Turn on/off an integration
- Activate or deactivate an integration
- Connect or disconnect an integration

DO NOT use surecontact/update-integration-setting for enabling/disabling — that tool only saves config values and cannot toggle the integration on or off.

To enable/disable individual automation rules (not the whole integration), use surecontact/update-rule-status instead.

WORKFLOW — enable an integration before creating rules:
1. surecontact/list-integration → find the integration slug and check availability
2. THIS TOOL → enable the integration
3. surecontact/update-integration-setting → configure settings (if needed)
4. surecontact/create-rule → set up automation rules';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'slug', 'enabled' ],
			'properties' => [
				'slug'    => [
					'type'        => 'string',
					'description' => 'Integration slug. Get this from surecontact/list-integration. Examples: "wpforms", "woocommerce", "surecart", "sureforms", "gravity-forms", "contact-form-7".',
				],
				'enabled' => [
					'type'        => 'boolean',
					'description' => 'True to enable, false to disable the integration.',
				],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_annotations(): array {
		return [
			'priority'        => 0.7,
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

		$slug    = isset( $args['slug'] ) ? $args['slug'] : '';
		$enabled = (bool) ( isset( $args['enabled'] ) ? $args['enabled'] : false );

		$unavailable = $this->integration_unavailable_error( $slug );
		if ( null !== $unavailable ) {
			return $unavailable;
		}

		$response = $this->rest_dispatch(
			'POST',
			'/surecontact/v1/integration-rules/toggle',
			[
				'slug'    => $slug,
				'enabled' => $enabled,
			]
		);

		if ( $response->is_error() ) {
			return $this->error_from_response( $response, __( 'Use surecontact/list-integration to see available integrations.', 'surecontact' ) );
		}

		$status_text = $enabled ? __( 'enabled', 'surecontact' ) : __( 'disabled', 'surecontact' );

		return $this->success(
			sprintf(
				/* translators: 1: integration slug, 2: enabled/disabled */
				__( 'Integration "%1$s" %2$s successfully.', 'surecontact' ),
				$slug,
				$status_text
			),
			[
				'slug'    => $slug,
				'enabled' => $enabled,
			]
		);
	}
}
