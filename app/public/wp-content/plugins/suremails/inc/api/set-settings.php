<?php
/**
 * Settings class
 *
 * Handles settings and configurations for the SureMails plugin, including retrieving,
 * updating settings, and cleaning up old email logs based on the retention period.
 *
 * @package SureMails\Inc\API
 */

namespace SureMails\Inc\API;

use SureMails\Inc\Settings;
use SureMails\Inc\Traits\Instance;
use SureMails\Inc\Utils\Utils;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Settings
 *
 * @since 0.0.1
 */
class SetSettings extends Api_Base {
	use Instance;

	/**
	 * Option name for storing SureMails connections.
	 */
	public const SUREMAILS_ANALYTICS = 'suremails_usage_optin';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '/set-settings';

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_routes() {
		$namespace = $this->get_api_namespace();

		register_rest_route(
			$namespace,
			$this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::CREATABLE, // POST method.
					'callback'            => [ $this, 'set_settings' ],
					'permission_callback' => [ $this, 'validate_permission' ],
					'args'                => [
						'settings' => [
							'type'        => 'object',
							'required'    => true,
							'description' => __( 'Settings data to update.', 'suremails' ),
						],
					],
				],
			]
		);
	}

	/**
	 * Sets the settings for SureMails.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request object.
	 * @return WP_REST_Response
	 */
	public function set_settings( $request ) {
		$settings = $request->get_param( 'settings' );

		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Settings data is missing or invalid.', 'suremails' ),
				],
				400
			);
		}

		$options      = Settings::instance()->get_raw_settings();
		$is_updated   = false;
		$needs_reload = false;

		// Check if showInSidebar setting is being changed BEFORE updating.
		if ( isset( $settings['showInSidebar'] ) ) {
			$misc                    = Settings::instance()->get_misc_settings();
			$current_sidebar_setting = $misc['show_in_sidebar'] ?? 'no';
			// Frontend sends 'yes'/'no' strings, compare directly.
			// Note: 'no' is truthy in PHP, so we can't use ternary here.
			$new_sidebar_setting = in_array( $settings['showInSidebar'], [ 'yes', '1', 1, true ], true ) ? 'yes' : 'no';
			$needs_reload        = ( $current_sidebar_setting !== $new_sidebar_setting );
		}

		// Check if any misc settings are being changed (to set $is_updated).
		$misc = Settings::instance()->get_misc_settings();
		foreach ( \SureMails\Inc\MISC_SETTINGS_MAPPING as $key => $mapped_key ) {
			if ( isset( $settings[ $key ] ) ) {
				$current_value = $misc[ $mapped_key ] ?? null;
				$new_value     = $settings[ $key ];
				if ( $current_value !== $new_value ) {
					$is_updated = true;
					break;
				}
			}
		}

		// Ensure default_connection and fallback_connection are arrays.
		$default_connection = isset( $settings['default_connection'] ) && is_array( $settings['default_connection'] )
			? $settings['default_connection']
			: null;

		// Validate presence of required fields.
		if ( is_null( $default_connection ) || ! isset( $default_connection['type'], $default_connection['email'], $default_connection['id'] ) ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Invalid or missing default connection data.', 'suremails' ),
				],
				400
			);
		}

		// Sanitize and update the settings.

		// Update default_connection if necessary.
		$sanitized_default = [
			'type'             => sanitize_text_field( $default_connection['type'] ),
			'email'            => sanitize_email( $default_connection['email'] ),
			'id'               => sanitize_text_field( $default_connection['id'] ),
			'connection_title' => sanitize_text_field( $default_connection['connection_title'] ?? '' ),
		];

		if ( $options['default_connection'] !== $sanitized_default ) {
			$options['default_connection'] = $sanitized_default;
			$is_updated                    = true;
		}

		// Update log_emails if provided.
		if ( isset( $settings['log_emails'] ) ) {
			$sanitized_log_emails = sanitize_text_field( $settings['log_emails'] );
			if ( $options['log_emails'] !== $sanitized_log_emails ) {
				$options['log_emails'] = $sanitized_log_emails;
				$is_updated            = true;
			}
		}

		// Update email_simulation if provided.
		if ( isset( $settings['email_simulation'] ) ) {
			$sanitized_email_simulation = sanitize_text_field( $settings['email_simulation'] );
			if ( $options['email_simulation'] !== $sanitized_email_simulation ) {
				$options['email_simulation'] = $sanitized_email_simulation;
				$is_updated                  = true;
			}
		}

		// Update delete_email_logs_after if provided.
		if ( isset( $settings['delete_email_logs_after'] ) ) {
			$sanitized_retention = sanitize_text_field( $settings['delete_email_logs_after'] );
			if ( $options['delete_email_logs_after'] !== $sanitized_retention ) {
				$options['delete_email_logs_after'] = $sanitized_retention;
				$is_updated                         = true;
			}
		}

		$this->update_misc_settings( $settings );

		if ( isset( $settings['analytics'] ) && ! empty( $settings['analytics'] ) ) {
			$analytics = $settings['analytics'];

			update_option( self::SUREMAILS_ANALYTICS, $analytics );
			$is_updated = true;
		}

		// Update the option in the database if any changes were made.
		if ( $is_updated ) {
			$update_result        = update_option( SUREMAILS_CONNECTIONS, $options );
			$options['analytics'] = $analytics ?? get_option( self::SUREMAILS_ANALYTICS, 'no' );

			// Get the updated misc settings to include email summary data.
			$misc                          = Settings::instance()->get_misc_settings();
			$options['emailSummaryActive'] = $misc['email_summary_active'] ?? 'yes';
			$options['emailSummaryDay']    = $misc['email_summary_day'] ?? 'monday';
			$options['showInSidebar']      = $misc['show_in_sidebar'] ?? 'no';

			$response_data = [
				'success' => true,
				'message' => __( 'Settings updated successfully.', 'suremails' ),
				'data'    => $options,
				'reload'  => $needs_reload,
			];

			// Include redirect URL if sidebar setting changed.
			if ( $needs_reload ) {
				$response_data['redirect_url'] = Utils::get_admin_url( 'settings' );
			}

			return new WP_REST_Response( $response_data, 200 );

		}

		// Get the misc settings to include email summary data.
		$misc                          = Settings::instance()->get_misc_settings();
		$options['emailSummaryActive'] = $misc['email_summary_active'] ?? 'no';
		$options['emailSummaryDay']    = $misc['email_summary_day'] ?? 'monday';
		$options['showInSidebar']      = $misc['show_in_sidebar'] ?? 'no';

		$response_data = [
			'success' => true,
			'message' => __( 'No changes made to the settings.', 'suremails' ),
			'data'    => $options,
			'reload'  => $needs_reload,
		];

		// Include redirect URL if sidebar setting changed.
		if ( $needs_reload ) {
			$response_data['redirect_url'] = Utils::get_admin_url( 'settings' );
		}

		return new WP_REST_Response( $response_data, 200 );
	}

	/**
	 * Updates the miscellaneous settings.
	 *
	 * @param array<string, string> $settings The settings to update.
	 * @return void
	 */
	private function update_misc_settings( $settings ) {
		// Update flat array values.

		foreach ( \SureMails\Inc\MISC_SETTINGS_MAPPING as $key => $mapped_key ) {
			if ( isset( $settings[ $key ] ) ) {
				Settings::instance()->update_misc_settings( $mapped_key, $settings[ $key ] );
			}
		}
	}
}

/**
 * Instantiate the Settings class to register actions and filters.
 */
SetSettings::instance();
