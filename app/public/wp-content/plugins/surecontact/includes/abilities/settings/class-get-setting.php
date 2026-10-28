<?php
/**
 * Get SureContact Settings Ability
 *
 * @since 1.3.1
 *
 * @package SureContact\Abilities\Settings
 */

namespace SureContact\Abilities\Settings;

use SureContact\Abilities\Abstract_Ability;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get_Setting class
 *
 * Returns the SureContact connection status and general settings.
 *
 * @since 1.3.1
 */
class Get_Setting extends Abstract_Ability {

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'surecontact/get-setting';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label(): string {
		return __( 'Get SureContact Settings', 'surecontact' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return 'Get SureContact general settings and connection status. Returns whether SureContact is connected to the CRM and the current configuration (auto_create_contacts, auto_sync_updates, sync_roles_as_lists, etc.).

USE: Check this first to verify SureContact is connected before using other surecontact tools. If not connected, the user needs to connect via Settings > SureContact.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_input_schema(): array {
		return [];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_annotations(): array {
		return [
			'priority'        => 1.0,
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_meta(): array {
		return array_merge(
			parent::get_meta(),
			[
				'examples' => [
					'get surecontact settings',
					'show surecontact connection status',
					'is surecontact connected',
					'check surecontact configuration',
					'is CRM connected',
					'show surecontact status',
					'check if surecontact is set up',
					'get surecontact config',
					'get surecontact sync settings',
				],
			]
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Input arguments.
	 */
	public function execute( array $args = [] ) {
		$is_connected = false;

		if ( class_exists( 'SureContact\Auth_Manager' ) ) {
			$is_connected = ( new \SureContact\Auth_Manager() )->is_authenticated();
		}

		$settings = get_option( 'surecontact_general_settings', [] );

		return $this->success(
			$is_connected
				? __( 'SureContact is connected.', 'surecontact' )
				: __( 'SureContact is not connected.', 'surecontact' ),
			[
				'connection_status' => $is_connected,
				'settings'          => $settings,
			]
		);
	}
}
