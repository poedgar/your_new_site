<?php
/**
 * SureContact Abandoned Carts Database Operations.
 *
 * Provides all CRUD operations for the abandoned carts table.
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
 * Abandoned Carts Operations Class.
 *
 * Static operations class for the surecontact_abandoned_carts table.
 * All $wpdb queries for this table live here.
 *
 * @since 1.5.0
 */
class Abandoned_Carts_Operations {

	/**
	 * Table name suffix.
	 *
	 * @since 1.5.0
	 *
	 * @var string
	 */
	const TABLE_NAME = 'surecontact_abandoned_carts';

	/**
	 * Status: cart is being tracked but not yet abandoned.
	 *
	 * @since 1.5.0
	 *
	 * @var string
	 */
	const STATUS_ACTIVE = 'active';

	/**
	 * Status: cart was past threshold and the customer was tagged.
	 *
	 * @since 1.5.0
	 *
	 * @var string
	 */
	const STATUS_ABANDONED = 'abandoned';

	/**
	 * Status: customer completed an order — cart is recovered.
	 *
	 * @since 1.5.0
	 *
	 * @var string
	 */
	const STATUS_RECOVERED = 'recovered';

	/**
	 * Maximum iterations for batched DELETE loop in delete_old_carts.
	 *
	 * Safety break against infinite loops if the DB consistently returns exactly
	 * batch_size rows. With BATCH_SIZE=1000 this caps a single cleanup at 100k rows.
	 *
	 * @since 1.5.0
	 *
	 * @var int
	 */
	const DELETE_MAX_ITERATIONS = 100;

	/**
	 * Get the full table name with prefix.
	 *
	 * @since 1.5.0
	 *
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Insert a new abandoned cart entry.
	 *
	 * @since 1.5.0
	 *
	 * @param array $data Entry data.
	 * @return int|false Entry ID on success, false on failure.
	 */
	public static function insert( $data ) {
		global $wpdb;

		$now        = current_time( 'mysql', true );
		$table_name = self::get_table_name();

		$defaults = array(
			'integration'  => 'woocommerce',
			'email'        => null,
			'user_id'      => 0,
			'contact_uuid' => null,
			'cart_data'    => '{}',
			'cart_total'   => 0,
			'status'       => self::STATUS_ACTIVE,
			'abandoned_at' => null,
			'recovered_at' => null,
			'created_at'   => $now,
			'updated_at'   => $now,
		);

		$data = wp_parse_args( $data, $defaults );

		$formats = array( '%s', '%s', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom abandoned carts table, not cacheable.
		$inserted = $wpdb->insert( $table_name, $data, $formats );

		if ( ! $inserted ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Update an entry by ID.
	 *
	 * @since 1.5.0
	 *
	 * @param int   $id   Entry ID.
	 * @param array $data Data to update.
	 * @return bool True on success, false on failure.
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$table_name = self::get_table_name();

		if ( ! isset( $data['updated_at'] ) ) {
			$data['updated_at'] = current_time( 'mysql', true );
		}

		$formats = array();
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( 'user_id', 'id' ), true ) ) {
				$formats[] = '%d';
			} elseif ( $key === 'cart_total' ) {
				$formats[] = '%f';
			} else {
				$formats[] = '%s';
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom abandoned carts table, not cacheable.
		$result = $wpdb->update(
			$table_name,
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get a single entry by ID.
	 *
	 * @since 1.5.0
	 *
	 * @param int $id Entry ID.
	 * @return object|null Entry object or null if not found.
	 */
	public static function get( $id ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom abandoned carts table, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant.
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Get active or abandoned cart by email for a given integration.
	 *
	 * Returns the most recent row matching the email with active or abandoned status.
	 * Active rows are preferred over abandoned (for cart-modified-after-abandoned scenario).
	 * Note on ORDER BY direction: lexicographically `abandoned` < `active`, so we sort
	 * status DESC to return active first when both exist. Switching to ASC would invert
	 * the priority and is locked against by the regression test in the operations test.
	 * Selects only the columns callers need (skips cart_data longtext).
	 *
	 * @since 1.5.0
	 *
	 * @param string $email       Email address.
	 * @param string $integration Integration slug.
	 * @return object|null Entry object or null if not found.
	 */
	public static function get_active_by_email( $email, $integration ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom abandoned carts table, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, integration, email, user_id, contact_uuid, status, updated_at
				FROM {$table_name}
				WHERE email = %s
				AND integration = %s
				AND status IN ('active', 'abandoned')
				ORDER BY status DESC, id DESC
				LIMIT 1",
				$email,
				$integration
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Get active or abandoned cart by user ID for a given integration.
	 *
	 * Selects only the columns callers need (skips cart_data longtext).
	 *
	 * @since 1.5.0
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $integration Integration slug.
	 * @return object|null Entry object or null if not found.
	 */
	public static function get_active_by_user_id( $user_id, $integration ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom abandoned carts table, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, integration, email, user_id, contact_uuid, status, updated_at
				FROM {$table_name}
				WHERE user_id = %d
				AND integration = %s
				AND status IN ('active', 'abandoned')
				ORDER BY status DESC, id DESC
				LIMIT 1",
				$user_id,
				$integration
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Get active carts past the abandonment threshold.
	 *
	 * Used by the detection job to find carts that should be marked as abandoned.
	 * Optionally scoped to a single integration so callers can apply each
	 * integration's own threshold without amplifying work in PHP.
	 *
	 * @since 1.5.0
	 *
	 * @param int         $threshold_minutes Minutes of inactivity before considering abandoned.
	 * @param int         $batch_size        Number of entries to fetch.
	 * @param string|null $integration       Integration slug to scope the query, or null for all.
	 * @return array Array of entry objects.
	 */
	public static function get_abandoned_past_threshold( $threshold_minutes, $batch_size = 10, $integration = null ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( $threshold_minutes * MINUTE_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom abandoned carts table, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant.
		if ( null !== $integration ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, integration, email, user_id, contact_uuid, updated_at
					FROM {$table_name}
					WHERE status = %s
					AND integration = %s
					AND email IS NOT NULL
					AND updated_at <= %s
					ORDER BY id ASC
					LIMIT %d",
					self::STATUS_ACTIVE,
					$integration,
					$cutoff,
					$batch_size
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, integration, email, user_id, contact_uuid, updated_at
				FROM {$table_name}
				WHERE status = %s
				AND email IS NOT NULL
				AND updated_at <= %s
				ORDER BY id ASC
				LIMIT %d",
				self::STATUS_ACTIVE,
				$cutoff,
				$batch_size
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Count active carts past the abandonment threshold.
	 *
	 * Used to determine if more batches need to be chained. Optionally scoped
	 * to a single integration.
	 *
	 * @since 1.5.0
	 *
	 * @param int         $threshold_minutes Minutes of inactivity before considering abandoned.
	 * @param string|null $integration       Integration slug to scope the query, or null for all.
	 * @return int Number of carts past threshold.
	 */
	public static function get_abandoned_count( $threshold_minutes, $integration = null ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( $threshold_minutes * MINUTE_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom abandoned carts table, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant.
		if ( null !== $integration ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name}
					WHERE status = %s
					AND integration = %s
					AND email IS NOT NULL
					AND updated_at <= %s",
					self::STATUS_ACTIVE,
					$integration,
					$cutoff
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name}
				WHERE status = %s
				AND email IS NOT NULL
				AND updated_at <= %s",
				self::STATUS_ACTIVE,
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Mark carts as recovered by email for a given integration.
	 *
	 * Updates both active and abandoned rows to recovered status.
	 * Returns rows that had a contact_uuid set (those need tag removal).
	 *
	 * @since 1.5.0
	 *
	 * @param string $email       Email address.
	 * @param string $integration Integration slug.
	 * @return array Array of objects with contact_uuid that need tag removal.
	 */
	public static function mark_recovered_by_email( $email, $integration ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$now        = current_time( 'mysql', true );

		// First, get rows that have contact_uuid (already tagged — need tag removal).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom abandoned carts table, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant.
		$tagged_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, contact_uuid FROM {$table_name}
				WHERE email = %s
				AND integration = %s
				AND status IN (%s, %s)
				AND contact_uuid IS NOT NULL",
				$email,
				$integration,
				self::STATUS_ACTIVE,
				self::STATUS_ABANDONED
			)
		);

		// Update all active/abandoned rows to recovered.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table_name}
				SET status = %s, recovered_at = %s, updated_at = %s
				WHERE email = %s
				AND integration = %s
				AND status IN (%s, %s)",
				self::STATUS_RECOVERED,
				$now,
				$now,
				$email,
				$integration,
				self::STATUS_ACTIVE,
				self::STATUS_ABANDONED
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return is_array( $tagged_rows ) ? $tagged_rows : array();
	}

	/**
	 * Delete old carts by retention period.
	 *
	 * Removes recovered and abandoned carts older than the specified number of days.
	 * Deletes in batches to avoid long-running table locks on high-traffic sites.
	 *
	 * @since 1.5.0
	 *
	 * @param int $retention_days Number of days to retain.
	 * @param int $batch_size     Rows to delete per batch.
	 * @return int Number of deleted rows.
	 */
	public static function delete_old_carts( $retention_days, $batch_size = 1000 ) {
		global $wpdb;

		$retention_days = max( 1, absint( $retention_days ) );
		$batch_size     = max( 1, absint( $batch_size ) );
		$table_name     = self::get_table_name();
		$cutoff         = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$total_deleted  = 0;
		$iterations     = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom abandoned carts table, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant.
		do {
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table_name}
					WHERE status IN (%s, %s)
					AND updated_at < %s
					LIMIT %d",
					self::STATUS_RECOVERED,
					self::STATUS_ABANDONED,
					$cutoff,
					$batch_size
				)
			);

			$total_deleted += $deleted;
			++$iterations;
		} while ( $deleted >= $batch_size && $iterations < self::DELETE_MAX_ITERATIONS );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $total_deleted;
	}

	/**
	 * Bump the updated_at timestamp on a single entry.
	 *
	 * Used to apply natural backoff after a transient API failure so the
	 * detection scan does not re-pick the same row immediately.
	 *
	 * @since 1.5.0
	 *
	 * @param int $id Entry ID.
	 * @return bool True on success, false on failure.
	 */
	public static function touch( $id ) {
		return self::update( $id, array( 'updated_at' => current_time( 'mysql', true ) ) );
	}

	/**
	 * Delete a single entry by ID.
	 *
	 * @since 1.5.0
	 *
	 * @param int $id Entry ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete( $id ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom abandoned carts table, not cacheable.
		return (bool) $wpdb->delete(
			$table_name,
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	/**
	 * Delete entries by email for GDPR compliance.
	 *
	 * @since 1.5.0
	 *
	 * @param string $email Email address to delete entries for.
	 * @return int Number of deleted rows.
	 */
	public static function delete_by_email( $email ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom abandoned carts table, not cacheable.
		return (int) $wpdb->delete(
			$table_name,
			array( 'email' => $email ),
			array( '%s' )
		);
	}
}
