<?php
/**
 * Settings API Class
 *
 * Handles REST API endpoints for managing general settings
 *
 * @since 0.0.1
 *
 * @package SureContact\API_WP
 */

namespace SureContact\API_WP;

use WP_REST_Server;
use WP_Error;
use WP_REST_Request;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings_API class
 *
 * Provides REST API endpoints for:
 * - Getting general settings
 * - Saving general settings
 *
 * @since 0.0.1
 */
class Settings_API extends Api_Base {

	/**
	 * Instance
	 *
	 * @since 0.0.1
	 *
	 * @var Settings_API
	 */
	private static $instance = null;

	/**
	 * Settings option key
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	const SETTINGS_KEY = 'surecontact_general_settings';

	/**
	 * Get instance
	 *
	 * @since 0.0.1
	 *
	 * @return Settings_API
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register routes
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function register_routes() {
		$namespace = $this->get_api_namespace();

		// Get general settings.
		register_rest_route(
			$namespace,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'settings_keys' => array(
							'type'        => 'array',
							'default'     => array(),
							'description' => 'Specific setting keys to retrieve',
						),
					),
				),
			)
		);

		// Save general settings.
		register_rest_route(
			$namespace,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'validate_permission' ),
					'args'                => array(
						'settings' => array(
							'required'    => true,
							'type'        => 'object',
							'description' => 'Settings object to save',
						),
					),
				),
			)
		);
	}

	/**
	 * Get general settings
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error Response data or error.
	 */
	public function get_settings( $request ) {
		$settings_keys = $request->get_param( 'settings_keys' );
		$all_settings  = get_option( self::SETTINGS_KEY, array() );

		// Clean up deprecated settings keys.
		$all_settings = $this->cleanup_deprecated_settings( $all_settings );

		// If no specific keys requested, return all settings with field definitions.
		if ( empty( $settings_keys ) ) {
			return rest_ensure_response(
				array(
					'success'         => true,
					'settings'        => $this->get_default_settings( $all_settings ),
					'settings_fields' => $this->get_settings_fields(),
				)
			);
		}

		// Return only requested settings.
		$return_settings = array();
		foreach ( $settings_keys as $key ) {
			if ( isset( $all_settings[ $key ] ) ) {
				$return_settings[ $key ] = $all_settings[ $key ];
			}
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'settings' => $return_settings,
			)
		);
	}

	/**
	 * Save general settings
	 *
	 * @since 0.0.1
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error Response data or error.
	 */
	public function save_settings( $request ) {
		$new_settings = $request->get_param( 'settings' );

		if ( empty( $new_settings ) || ! is_array( $new_settings ) ) {
			return new WP_Error(
				'invalid_settings',
				__( 'Invalid settings data provided.', 'surecontact' ),
				array( 'status' => 400 )
			);
		}

		$existing_settings = get_option( self::SETTINGS_KEY, array() );

		// Merge new settings with existing ones.
		foreach ( $new_settings as $key => $value ) {
			$existing_settings[ $key ] = $value;
		}

		// Clean up to ensure only allowed keys are saved.
		$existing_settings = $this->cleanup_deprecated_settings( $existing_settings );

		// Update the option.
		update_option( self::SETTINGS_KEY, $existing_settings );

		return rest_ensure_response(
			array(
				'success'  => true,
				'message'  => __( 'Settings updated successfully.', 'surecontact' ),
				'settings' => $existing_settings,
			)
		);
	}

	/**
	 * Get default settings structure
	 *
	 * @since 0.0.1
	 *
	 * @param array $existing_settings Existing settings to merge with defaults.
	 * @return array Default settings.
	 */
	private function get_default_settings( $existing_settings = array() ) {
		$defaults = array(
			'sync_settings' => $this->get_default_sync_settings(),
			'log_settings'  => $this->get_default_log_settings(),
		);

		return wp_parse_args( $existing_settings, $defaults );
	}

	/**
	 * Get default sync settings
	 *
	 * @since 0.0.1
	 *
	 * @return array Default sync settings.
	 */
	private function get_default_sync_settings() {
		return array(
			'auto_create_contacts' => false,
			'auto_sync_updates'    => false,
			'sync_roles_as_lists'  => false,
			'sync_roles_as_tags'   => false,
			'assigned_lists'       => array(),
			'assigned_tags'        => array(),
		);
	}

	/**
	 * Get default log settings
	 *
	 * @since 1.4.0
	 *
	 * @return array Default log settings.
	 */
	private function get_default_log_settings() {
		return array(
			'log_retention_days' => 1,
		);
	}

	/**
	 * Get settings field definitions for dynamic rendering
	 *
	 * Returns field configurations that the frontend can use to dynamically
	 * render the settings form, similar to IntegrationSettings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings field definitions.
	 */
	public function get_settings_fields() {
		return array(
			'sync_settings' => array(
				'auto_create_contacts' => array(
					'label'       => __( 'Auto-Create Contacts on Registration', 'surecontact' ),
					'description' => __( 'Automatically create a contact in SureContact when a new user registers on your WordPress site.', 'surecontact' ),
					'type'        => 'checkbox',
					'default'     => false,
				),
				'auto_sync_updates'    => array(
					'label'       => __( 'Auto-Sync Contact Updates', 'surecontact' ),
					'description' => __( 'Automatically sync all contact changes to SureContact, including profile updates, custom field changes, role changes, and login activity.', 'surecontact' ),
					'type'        => 'checkbox',
					'default'     => false,
				),
				'sync_roles_as_lists'  => array(
					'label'       => __( 'Sync User Roles as Lists', 'surecontact' ),
					'description' => __( 'Automatically create lists based on WordPress user roles and assign contacts to them. A list will be created for each role if it doesn\'t exist.', 'surecontact' ),
					'type'        => 'checkbox',
					'default'     => false,
				),
				'sync_roles_as_tags'   => array(
					'label'       => __( 'Sync User Roles as Tags', 'surecontact' ),
					'description' => __( 'Automatically create tags based on WordPress user roles and apply them to contacts. A tag will be created for each role if it doesn\'t exist.', 'surecontact' ),
					'type'        => 'checkbox',
					'default'     => false,
				),
				'assigned_lists'       => array(
					'label'       => __( 'Assign Lists', 'surecontact' ),
					'description' => __( 'Select lists to automatically apply to new users who register an account in WordPress. These contacts will be added to the selected lists in SureContact.', 'surecontact' ),
					'type'        => 'list-select',
					'default'     => array(),
				),
				'assigned_tags'        => array(
					'label'       => __( 'Assign Tags', 'surecontact' ),
					'description' => __( 'Select tags to automatically apply to new users who register an account in WordPress. These contacts will be tagged with the selected tags in SureContact.', 'surecontact' ),
					'type'        => 'tag-select',
					'default'     => array(),
				),
			),
			'log_settings'  => array(
				'log_retention_days' => array(
					'label'       => __( 'Log Retention Period', 'surecontact' ),
					'description' => __( 'How long to keep success and error log entries before automatic cleanup. Failed queue items are always kept for 7 days.', 'surecontact' ),
					'type'        => 'select',
					'default'     => 1,
					'options'     => array(
						1  => __( '1 day', 'surecontact' ),
						2  => __( '2 days', 'surecontact' ),
						3  => __( '3 days', 'surecontact' ),
						5  => __( '5 days', 'surecontact' ),
						7  => __( '7 days', 'surecontact' ),
						14 => __( '14 days', 'surecontact' ),
						30 => __( '30 days', 'surecontact' ),
					),
				),
			),
		);
	}

	/**
	 * Clean up deprecated settings keys
	 *
	 * Removes any settings keys that are not defined in get_default_settings().
	 * This ensures the database only contains keys that are actually used in the code.
	 *
	 * @since 0.0.1
	 *
	 * @param array $settings Current settings array.
	 * @return array Cleaned settings array with only allowed keys.
	 */
	private function cleanup_deprecated_settings( $settings ) {
		// Get the allowed keys from the default settings structure.
		$allowed_keys     = array_keys( $this->get_default_settings() );
		$needs_update     = false;
		$cleaned_settings = array();

		// Only keep settings that are in the allowed keys list.
		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, $allowed_keys, true ) ) {
				// For nested settings, validate nested keys too.
				if ( is_array( $value ) && $key === 'sync_settings' ) {
					$cleaned_settings[ $key ] = $this->cleanup_sync_settings( $value );
				} elseif ( is_array( $value ) && $key === 'log_settings' ) {
					$cleaned_settings[ $key ] = $this->cleanup_log_settings( $value );
				} else {
					$cleaned_settings[ $key ] = $value;
				}
			} else {
				// This key is not allowed, mark for database update.
				$needs_update = true;
			}
		}

		// Update database if we removed any deprecated keys.
		if ( $needs_update ) {
			update_option( self::SETTINGS_KEY, $cleaned_settings );
		}

		return $cleaned_settings;
	}

	/**
	 * Clean up sync settings to only include allowed keys
	 *
	 * @since 0.0.1
	 *
	 * @param array $sync_settings Current sync settings.
	 * @return array Cleaned sync settings.
	 */
	private function cleanup_sync_settings( $sync_settings ) {
		$allowed_keys = array_keys( $this->get_default_sync_settings() );
		$cleaned      = array();

		foreach ( $sync_settings as $key => $value ) {
			if ( in_array( $key, $allowed_keys, true ) ) {
				$cleaned[ $key ] = $value;
			}
		}

		return $cleaned;
	}

	/**
	 * Clean up log settings to only include allowed keys
	 *
	 * @since 1.4.0
	 *
	 * @param array $log_settings Current log settings.
	 * @return array Cleaned log settings.
	 */
	private function cleanup_log_settings( $log_settings ) {
		$allowed_keys = array_keys( $this->get_default_log_settings() );
		$cleaned      = array();

		foreach ( $log_settings as $key => $value ) {
			if ( in_array( $key, $allowed_keys, true ) ) {
				$cleaned[ $key ] = $value;
			}
		}

		// Enforce valid range for log_retention_days (1-30).
		if ( isset( $cleaned['log_retention_days'] ) ) {
			$cleaned['log_retention_days'] = max( 1, min( 30, absint( $cleaned['log_retention_days'] ) ) );
		}

		return $cleaned;
	}
}
