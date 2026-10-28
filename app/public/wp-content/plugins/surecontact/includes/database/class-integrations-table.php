<?php
/**
 * SureContact Integrations Database Table.
 *
 * Creates and manages the custom table for storing integration configurations.
 *
 * @since 0.0.3
 *
 * @package SureContact
 */

namespace SureContact\Database;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrations Table Class.
 *
 * @since 0.0.3
 */
class Integrations_Table {

	/**
	 * Table name suffix.
	 *
	 * @since 0.0.3
	 *
	 * @var string
	 */
	const TABLE_NAME = 'surecontact_integrations';

	/**
	 * Table version.
	 *
	 * @since 0.0.3
	 *
	 * @var string
	 */
	const VERSION = '1.4';

	/**
	 * Maybe create or update the integrations table.
	 *
	 * Checks the installed table version and runs create_table() if an update is needed.
	 * This method should be called on plugins_loaded to ensure the table is always up to date.
	 *
	 * @since 0.0.3
	 *
	 * @return void
	 */
	public static function maybe_create_or_update() {
		$installed_version = get_option( 'surecontact_integrations_table_version', '0.0' );

		if ( version_compare( $installed_version, self::VERSION, '<' ) ) {
			// Check if we need to migrate existing data (upgrading from 1.3 to 1.4).
			$needs_migration = version_compare( $installed_version, '1.4', '<' ) && version_compare( $installed_version, '1.0', '>=' );

			self::create_table();

			// Migrate existing integrations to current workspace after schema update.
			if ( $needs_migration ) {
				self::migrate_existing_integrations();
			}
		}
	}

	/**
	 * Migrate existing integrations to the current workspace.
	 *
	 * Assigns the current workspace_uuid to all existing integrations that have an empty workspace_uuid.
	 * This ensures backward compatibility when upgrading from versions without workspace support.
	 *
	 * @since 0.0.4
	 *
	 * @return void
	 */
	private static function migrate_existing_integrations() {
		global $wpdb;

		$table_name     = $wpdb->prefix . self::TABLE_NAME;
		$workspace_uuid = get_option( 'surecontact_workspace_uuid', '' );

		// Only migrate if we have a workspace UUID configured.
		if ( empty( $workspace_uuid ) ) {
			return;
		}

		// Update all rows with empty workspace_uuid to use the current workspace.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE `{$table_name}` SET workspace_uuid = %s WHERE workspace_uuid = ''",
				$workspace_uuid
			)
		);
	}

	/**
	 * Create or update the integrations table.
	 *
	 * Uses dbDelta to create or update the table schema following WordPress core patterns.
	 * Safe to call multiple times - dbDelta only applies necessary changes.
	 *
	 * @since 0.0.3
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL auto_increment,
			workspace_uuid varchar(36) NOT NULL default '',
			name varchar(255) NOT NULL default '',
			item_id varchar(191) default NULL,
			item_type varchar(50) default NULL,
			event varchar(100) default NULL,
			config longtext NOT NULL,
			metadata longtext,
			status tinyint(1) NOT NULL default 1,
			created_at datetime NOT NULL default CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_workspace_name_item_event (workspace_uuid,name(191),item_id(191),item_type(50),event(100)),
			KEY idx_workspace_uuid (workspace_uuid),
			KEY idx_name (name(191)),
			KEY idx_item_type (item_type),
			KEY idx_status (status),
			KEY idx_event (event)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'surecontact_integrations_table_version', self::VERSION );
	}
}
