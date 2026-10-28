<?php
/**
 * SureContact Uninstall
 *
 * Fired when the plugin is deleted (not deactivated).
 * Cleans up all plugin data from the database.
 *
 * @since 0.0.4
 *
 * @package SureContact
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

global $wpdb;

// Cancel any pending Action Scheduler jobs in the 'surecontact' group before
// dropping our tables, so AS doesn't keep retrying actions whose targets are gone.
$surecontact_action_scheduler_path = __DIR__ . '/lib/action-scheduler/action-scheduler.php';
if ( ! function_exists( 'as_unschedule_all_actions' ) && file_exists( $surecontact_action_scheduler_path ) ) {
	require_once $surecontact_action_scheduler_path;
}
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'surecontact' );
}

// Delete plugin options.
delete_option( 'surecontact_bearer_token' );
delete_option( 'surecontact_connection_id' );
delete_option( 'surecontact_workspace_uuid' );
delete_option( 'surecontact_last_status_sync' );
delete_option( 'surecontact_last_bulk_sync' );
delete_option( 'surecontact_last_field_sync' );
delete_option( 'surecontact_sync_batches' );
delete_option( 'surecontact_sync_job_cancelled' );
delete_option( 'surecontact_synced_metadata' );
delete_option( 'surecontact_contact_fields' );
delete_option( 'surecontact_custom_metafields' );
delete_option( 'surecontact_general_settings' );
delete_option( 'surecontact_db_version' );
delete_option( 'surecontact_api_queue_table_version' );
delete_option( 'surecontact_integrations_table_version' );
delete_option( 'surecontact_abandoned_carts_table_version' );
delete_option( 'surecontact_third_party_integrations' );

// Clear any wp-cron schedules registered by the plugin (Action Scheduler jobs
// are already cancelled above; these cover the wp-cron fallbacks).
wp_clear_scheduled_hook( 'surecontact_daily_sync' );
wp_clear_scheduled_hook( 'surecontact_suremembers_check_expirations' );

// Delete site options (for multisite).
delete_site_option( 'surecontact_bearer_token' );
delete_site_option( 'surecontact_connection_id' );
delete_site_option( 'surecontact_workspace_uuid' );

// Delete dynamic options (surecontact_job_*).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'surecontact_job_' ) . '%'
	)
);

// Delete transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		WHERE option_name LIKE %s
		OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_surecontact_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_surecontact_' ) . '%'
	)
);

// Drop custom database tables.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}surecontact_integrations" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}surecontact_api_queue" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}surecontact_abandoned_carts" );
