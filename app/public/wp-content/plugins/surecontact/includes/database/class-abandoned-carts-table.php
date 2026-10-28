<?php
/**
 * SureContact Abandoned Carts Database Table.
 *
 * Creates and manages the custom table for abandoned cart tracking.
 *
 * @since 1.5.0
 *
 * @package SureContact
 */

namespace SureContact\Database;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abandoned Carts Table Class.
 *
 * @since 1.5.0
 */
class Abandoned_Carts_Table {

	/**
	 * Table name suffix.
	 *
	 * @since 1.5.0
	 *
	 * @var string
	 */
	const TABLE_NAME = 'surecontact_abandoned_carts';

	/**
	 * Table version.
	 *
	 * @since 1.5.0
	 *
	 * @var string
	 */
	const VERSION = '1.1';

	/**
	 * Maybe create or update the abandoned carts table.
	 *
	 * Checks the installed table version and runs create_table() if an update is needed.
	 * This method should be called on plugins_loaded to ensure the table is always up to date.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public static function maybe_create_or_update() {
		$installed_version = get_option( 'surecontact_abandoned_carts_table_version', '0.0' );

		if ( version_compare( $installed_version, self::VERSION, '<' ) ) {
			self::create_table();
		}
	}

	/**
	 * Create or update the abandoned carts table.
	 *
	 * Uses dbDelta to create or update the table schema following WordPress core patterns.
	 * Safe to call multiple times - dbDelta only applies necessary changes.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL auto_increment,
			integration varchar(50) NOT NULL default 'woocommerce',
			email varchar(191) default NULL,
			user_id bigint(20) unsigned default 0,
			contact_uuid varchar(64) default NULL,
			cart_data longtext NOT NULL,
			cart_total decimal(19,4) default 0,
			status varchar(20) NOT NULL default 'active',
			abandoned_at datetime default NULL,
			recovered_at datetime default NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_status_updated (status,updated_at),
			KEY idx_email (email),
			KEY idx_user_id (user_id),
			KEY idx_integration (integration)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'surecontact_abandoned_carts_table_version', self::VERSION );
	}
}
