<?php
/**
 * Daily Sync Manager
 *
 * Handles daily synchronization of plugin status to the SaaS API
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Daily_Sync_Manager
 *
 * Manages daily synchronization of plugin version to the SaaS API
 *
 * @since 0.0.1
 */
class Daily_Sync_Manager {


	/**
	 * Cron hook name
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	const CRON_HOOK = 'surecontact_daily_sync';

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
	 */
	public function __construct() {
		$this->saas_client = new SaaS_Client();
		$this->setup_hooks();
	}

	/**
	 * Setup WordPress hooks
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function setup_hooks() {
		// Only schedule on admin or cron requests so the cron-array check
		// doesn't run on every frontend pageload.
		if ( is_admin() || wp_doing_cron() ) {
			add_action( 'init', array( $this, 'schedule_daily_sync' ) );
		}

		// Hook the sync function to the cron event.
		add_action( self::CRON_HOOK, array( $this, 'perform_daily_sync' ) );

		// Deactivation cleanup is registered from the main plugin file at
		// file-load time (see SureContact::deactivate); registering it here at
		// 'init' is unreliable because the deactivation hook only fires when
		// it was attached during the same request that performs deactivation.
	}

	/**
	 * Schedule daily sync if not already scheduled
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function schedule_daily_sync() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear scheduled sync event
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function clear_scheduled_sync() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Perform the daily sync to SaaS API
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function perform_daily_sync() {
		// Check if connected to SaaS platform.
		$workspace_uuid = sanitize_text_field( get_option( 'surecontact_workspace_uuid' ) );
		$bearer_token   = get_option( 'surecontact_bearer_token' );

		if ( empty( $workspace_uuid ) || empty( $bearer_token ) ) {
			// Not connected, skip sync.
			return;
		}

		// Get plugin version.
		$plugin_version = SURECONTACT_VERSION;

		// Send update to SaaS API.
		$response = $this->saas_client->update_connection_status(
			$workspace_uuid,
			$plugin_version
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		// Update last sync timestamp.
		update_option( 'surecontact_last_status_sync', current_time( 'mysql' ) );
	}
}
