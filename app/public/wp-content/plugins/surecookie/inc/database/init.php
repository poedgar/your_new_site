<?php
/**
 * Database initiator for SureCookie plugin.
 *
 * @package    SureCookie
 * @subpackage SureCookie\Inc\DB
 * @since      0.0.1
 */

namespace SureCookie\Inc\Database;

defined( 'ABSPATH' ) || exit;

/**
 * DB initiator for SureCookie plugin.
 *
 * @since 0.0.1
 */
class Init {
	/**
	 * Returns an array of instances/objects of our custom tables.
	 *
	 * @since 0.0.1
	 * @return array<string,\SureCookie\Inc\Database\Base>
	 */
	public static function get_db_tables() {
		return [
			'consent_log' => ConsentLog::get_instance(),
		];
	}

	/**
	 * Initialize database tables for a new site in multisite.
	 *
	 * @param \WP_Site $site The new site's object.
	 * @since 0.0.1
	 * @return void
	 */
	public static function initialize_new_site( $site ): void {
		foreach ( self::get_db_tables() as $instance ) {
			$instance->initialize_site( $site );
		}
	}

	/**
	 * Initialization
	 *
	 * @since 0.0.1
	 */
	public static function create_db_tables(): void {
		foreach ( self::get_db_tables() as $instance ) {
			$instance->create( $instance->get_columns_definition() );
		}
	}
}
