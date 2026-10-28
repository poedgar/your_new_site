<?php
/**
 * Migration Manager
 *
 * Handles data migrations with version tracking.
 *
 * IMPORTANT:
 * - Table creation is handled by activation hook (see surecontact.php)
 * - This class is ONLY for DATA migrations (moving/transforming existing data)
 * - For a fresh plugin with no existing users, this class remains mostly empty
 * - Add migrations here only when you need to transform existing user data
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Migration_Manager
 *
 * Handles data migrations using static methods.
 * Follows the same pattern as table classes.
 *
 * @since 0.0.1
 */
class Migration_Manager {

	/**
	 * Option key for storing current migration version
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'surecontact_db_version';

	/**
	 * Current migration version
	 * Increment this when adding new data migrations
	 *
	 * @since 0.0.3
	 *
	 * @var string
	 */
	const CURRENT_DB_VERSION = '1.1.0';

	/**
	 * Maybe run migrations if needed.
	 *
	 * Static entry point called from main plugin file.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function maybe_run_migrations() {
		if ( ! is_admin() ) {
			return;
		}

		$current_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );

		// Fresh install - just set the version, no migrations needed.
		if ( '0.0.0' === $current_version ) {
			update_option( self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION, false );
			return;
		}

		// Already up to date.
		if ( version_compare( $current_version, self::CURRENT_DB_VERSION, '>=' ) ) {
			return;
		}

		self::run_migrations( $current_version );
	}

	/**
	 * Run all pending migrations.
	 *
	 * @since 1.0.0
	 *
	 * @param string $current_version Current DB version.
	 * @return void
	 */
	private static function run_migrations( $current_version ) {
		$migrations = self::get_migrations();

		foreach ( $migrations as $migration ) {
			// Skip if already applied.
			if ( version_compare( $current_version, $migration['version'], '>=' ) ) {
				continue;
			}

			$method = $migration['method'];

			if ( ! method_exists( self::class, $method ) ) {
				continue;
			}

			Logger::info( 'Migration', sprintf( 'Running migration: %s (v%s)', $method, $migration['version'] ) );

			$result = self::$method();

			if ( is_wp_error( $result ) ) {
				Logger::error( 'Migration', sprintf( 'Migration failed: %s - %s', $method, $result->get_error_message() ) );
				return; // Stop on failure.
			}

			Logger::info( 'Migration', sprintf( 'Migration completed: %s', $method ) );
		}

		// All migrations successful - update version.
		update_option( self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION, false );
	}

	/**
	 * Get all migrations.
	 *
	 * Add new migrations here. Each migration runs once based on version comparison.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of migrations.
	 */
	private static function get_migrations() {
		return array(
			array(
				'version' => '1.1.0',
				'method'  => 'migrate_presto_player_progress_thresholds',
			),
		);
	}

	/**
	 * Migrate Presto Player progress thresholds from old format to new format.
	 *
	 * Old format: 'lists', 'tags'
	 * New format: 'add_lists', 'add_tags', 'remove_lists', 'remove_tags'
	 *
	 * @since 1.0.0
	 *
	 * @return true True on success.
	 */
	private static function migrate_presto_player_progress_thresholds() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, config FROM ' . $wpdb->prefix . 'surecontact_integrations WHERE name = %s AND event = %s',
				'presto-player',
				'progress'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $results ) ) {
			Logger::info( 'Migration', 'No Presto Player progress configurations found to migrate' );
			return true;
		}

		$updated_count = 0;

		foreach ( $results as $row ) {
			$config = json_decode( $row['config'], true );

			// Skip rows with malformed JSON or unexpected structure so a
			// single bad row doesn't take down the whole migration.
			if ( ! is_array( $config ) || empty( $config['progress_thresholds'] ) || ! is_array( $config['progress_thresholds'] ) ) {
				continue;
			}

			$needs_update       = false;
			$updated_thresholds = array();

			foreach ( $config['progress_thresholds'] as $threshold ) {
				$updated_threshold = $threshold;

				// Rename old keys to new format.
				if ( isset( $threshold['lists'] ) && ! isset( $threshold['add_lists'] ) ) {
					$updated_threshold['add_lists'] = $threshold['lists'];
					unset( $updated_threshold['lists'] );
					$needs_update = true;
				}

				if ( isset( $threshold['tags'] ) && ! isset( $threshold['add_tags'] ) ) {
					$updated_threshold['add_tags'] = $threshold['tags'];
					unset( $updated_threshold['tags'] );
					$needs_update = true;
				}

				// Ensure remove keys exist.
				if ( ! isset( $updated_threshold['remove_lists'] ) ) {
					$updated_threshold['remove_lists'] = array();
				}
				if ( ! isset( $updated_threshold['remove_tags'] ) ) {
					$updated_threshold['remove_tags'] = array();
				}

				$updated_thresholds[] = $updated_threshold;
			}

			if ( $needs_update ) {
				$config['progress_thresholds'] = $updated_thresholds;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'surecontact_integrations',
					array( 'config' => wp_json_encode( $config ) ),
					array( 'id' => $row['id'] ),
					array( '%s' ),
					array( '%d' )
				);

				++$updated_count;
			}
		}

		Logger::info( 'Migration', sprintf( 'Presto Player migration: %d configurations updated', $updated_count ) );

		return true;
	}
}
