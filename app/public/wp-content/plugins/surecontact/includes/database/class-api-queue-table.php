<?php
/**
 * SureContact API Queue Database Table.
 *
 * Creates and manages the custom table for API retry queue.
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
 * API Queue Table Class.
 *
 * @since 0.0.3
 */
class API_Queue_Table {

	/**
	 * Table name suffix.
	 *
	 * @since 0.0.3
	 *
	 * @var string
	 */
	const TABLE_NAME = 'surecontact_api_queue';

	/**
	 * Table version.
	 *
	 * @since 0.0.3
	 *
	 * @var string
	 */
	const VERSION = '2.0';

	/**
	 * Maybe create or update the API queue table.
	 *
	 * Checks the installed table version and runs create_table() if an update is needed.
	 * This method should be called on plugins_loaded to ensure the table is always up to date.
	 *
	 * @since 0.0.3
	 *
	 * @return void
	 */
	public static function maybe_create_or_update() {
		$installed_version = get_option( 'surecontact_api_queue_table_version', '0.0' );

		if ( version_compare( $installed_version, self::VERSION, '<' ) ) {
			self::create_table();
		}
	}

	/**
	 * Create or update the API queue table.
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
			request_type varchar(10) NOT NULL default '',
			endpoint varchar(255) NOT NULL default '',
			payload longtext NOT NULL,
			operation varchar(50) NOT NULL default '',
			retry_count tinyint unsigned default 0,
			max_retries tinyint unsigned default 5,
			status varchar(20) default 'failed',
			last_error text,
			next_retry_at datetime,
			response_data longtext,
			response_code smallint unsigned default NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_status_retry (status,next_retry_at),
			KEY idx_endpoint (endpoint(191)),
			KEY idx_operation (operation),
			KEY idx_created_at (created_at),
			KEY idx_status_created (status,created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'surecontact_api_queue_table_version', self::VERSION );
	}
}
