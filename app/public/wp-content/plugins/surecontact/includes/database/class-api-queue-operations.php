<?php
/**
 * SureContact API Queue Database Operations.
 *
 * Provides all CRUD operations for the API queue table.
 *
 * @since 1.4.0
 *
 * @package SureContact
 */

namespace SureContact\Database;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API Queue Operations Class.
 *
 * Static operations class for the surecontact_api_queue table.
 * All $wpdb queries for this table live here.
 *
 * @since 1.4.0
 */
class Api_Queue_Operations {

	/**
	 * Table name suffix.
	 *
	 * @since 1.4.0
	 *
	 * @var string
	 */
	const TABLE_NAME = 'surecontact_api_queue';

	/**
	 * Get the full table name with prefix.
	 *
	 * @since 1.4.0
	 *
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Insert a new entry.
	 *
	 * @since 1.4.0
	 *
	 * @param array $data Entry data.
	 * @return int|false Entry ID on success, false on failure.
	 */
	public static function insert( $data ) {
		global $wpdb;

		$now        = current_time( 'mysql' );
		$table_name = self::get_table_name();

		$defaults = array(
			'request_type'  => '',
			'endpoint'      => '',
			'payload'       => '',
			'operation'     => '',
			'retry_count'   => 0,
			'max_retries'   => 5,
			'status'        => 'success',
			'last_error'    => null,
			'next_retry_at' => null,
			'response_data' => null,
			'response_code' => null,
			'created_at'    => $now,
			'updated_at'    => $now,
		);

		$data = wp_parse_args( $data, $defaults );

		// Build format array based on data types.
		$formats = array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom queue table, not cacheable.
		$inserted = $wpdb->insert( $table_name, $data, $formats );

		if ( ! $inserted ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Update an entry by ID.
	 *
	 * @since 1.4.0
	 *
	 * @param int   $id   Entry ID.
	 * @param array $data Data to update.
	 * @return bool True on success, false on failure.
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// Always update updated_at.
		if ( ! isset( $data['updated_at'] ) ) {
			$data['updated_at'] = current_time( 'mysql' );
		}

		// Build format array dynamically.
		$formats = array();
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( 'retry_count', 'max_retries', 'response_code' ), true ) ) {
				$formats[] = is_null( $value ) ? '%s' : '%d';
			} else {
				$formats[] = '%s';
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table, not cacheable.
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
	 * @since 1.4.0
	 *
	 * @param int $id Entry ID.
	 * @return object|null Entry object or null if not found.
	 */
	public static function get( $id ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant.
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Delete a single entry by ID.
	 *
	 * @since 1.4.0
	 *
	 * @param int $id Entry ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete( $id ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table, not cacheable.
		return (bool) $wpdb->delete(
			$table_name,
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	/**
	 * Bulk delete entries by ID array.
	 *
	 * @since 1.4.0
	 *
	 * @param array $ids Array of entry IDs.
	 * @return int Number of deleted rows.
	 */
	public static function delete_many( $ids ) {
		global $wpdb;

		if ( empty( $ids ) ) {
			return 0;
		}

		$table_name   = self::get_table_name();
		$ids          = array_map( 'absint', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant; placeholders are generated.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are dynamically built from sanitized IDs.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE id IN ({$placeholders})",
				...$ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return (int) $deleted;
	}

	/**
	 * Delete all entries matching given statuses.
	 *
	 * @since 1.4.0
	 *
	 * @param array $statuses Array of status strings.
	 * @return int Number of deleted rows.
	 */
	public static function delete_by_status( $statuses ) {
		global $wpdb;

		if ( empty( $statuses ) ) {
			return 0;
		}

		$table_name   = self::get_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant; placeholders are generated.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are dynamically built from sanitized statuses.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE status IN ({$placeholders})",
				...$statuses
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return (int) $deleted;
	}

	/**
	 * Get paginated entries with filters.
	 *
	 * @since 1.4.0
	 *
	 * @param array $args {
	 *     Query arguments.
	 *
	 *     @type array  $statuses Array of status strings to filter by.
	 *     @type string $search   Search term for endpoint/operation.
	 *     @type int    $limit    Number of records per page.
	 *     @type int    $offset   Offset for pagination.
	 * }
	 * @return array Array of entry objects.
	 */
	public static function get_entries( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'statuses' => array(),
			'search'   => '',
			'limit'    => 100,
			'offset'   => 0,
		);

		$args       = wp_parse_args( $args, $defaults );
		$table_name = self::get_table_name();
		$where      = array();
		$values     = array();

		// Filter by statuses.
		if ( ! empty( $args['statuses'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $args['statuses'] ), '%s' ) );
			$where[]      = "status IN ({$placeholders})";
			$values       = array_merge( $values, $args['statuses'] );
		}

		// Search filter.
		if ( ! empty( $args['search'] ) ) {
			$where[] = '(endpoint LIKE %s OR operation LIKE %s)';
			$search  = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$values  = array_merge( $values, array( $search, $search ) );
		}

		$where_clause = '';
		if ( ! empty( $where ) ) {
			$where_clause = 'WHERE ' . implode( ' AND ', $where );
		}

		$values[] = absint( $args['limit'] );
		$values[] = absint( $args['offset'] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name and WHERE clause are safely constructed.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic placeholders from filtered args.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} {$where_clause} ORDER BY id DESC LIMIT %d OFFSET %d",
				...$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Get count of entries with filters.
	 *
	 * @since 1.4.0
	 *
	 * @param array $args Same filter arguments as get_entries (statuses, search).
	 * @return int Count of matching entries.
	 */
	public static function get_count( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'statuses' => array(),
			'search'   => '',
		);

		$args       = wp_parse_args( $args, $defaults );
		$table_name = self::get_table_name();
		$where      = array();
		$values     = array();

		// Filter by statuses.
		if ( ! empty( $args['statuses'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $args['statuses'] ), '%s' ) );
			$where[]      = "status IN ({$placeholders})";
			$values       = array_merge( $values, $args['statuses'] );
		}

		// Search filter.
		if ( ! empty( $args['search'] ) ) {
			$where[] = '(endpoint LIKE %s OR operation LIKE %s)';
			$search  = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$values  = array_merge( $values, array( $search, $search ) );
		}

		$where_clause = '';
		if ( ! empty( $where ) ) {
			$where_clause = 'WHERE ' . implode( ' AND ', $where );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name and WHERE clause are safely constructed.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholders from filtered args.
		if ( ! empty( $values ) ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name} {$where_clause}",
					...$values
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} {$where_clause}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Get counts grouped by status.
	 *
	 * @since 1.4.0
	 *
	 * @param array|null $statuses Optional. Filter to specific statuses.
	 * @return array Associative array of status => count.
	 */
	public static function get_stats( $statuses = null ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table stats, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant.
		if ( ! empty( $statuses ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholders from filtered statuses.
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT status, COUNT(*) as count FROM {$table_name} WHERE status IN ({$placeholders}) GROUP BY status",
					...$statuses
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		} else {
			$results = $wpdb->get_results(
				"SELECT status, COUNT(*) as count FROM {$table_name} GROUP BY status",
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$stats = array();
		$total = 0;

		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				$stats[ $row['status'] ] = (int) $row['count'];
				$total                  += (int) $row['count'];
			}
		}

		$stats['total'] = $total;

		return $stats;
	}

	/**
	 * Delete entries older than N days matching given statuses.
	 *
	 * @since 1.4.0
	 *
	 * @param int   $days     Number of days to keep.
	 * @param array $statuses Array of status strings.
	 * @return int Number of deleted rows.
	 */
	public static function cleanup_by_age( $days, $statuses ) {
		global $wpdb;

		if ( empty( $statuses ) ) {
			return 0;
		}

		$days         = max( 1, absint( $days ) );
		$table_name   = self::get_table_name();
		$cutoff       = wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ), wp_timezone() );
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$values       = array_merge( array( $cutoff ), $statuses );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table cleanup, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant; placeholders are generated.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholders from sanitized statuses.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE updated_at < %s AND status IN ({$placeholders})",
				...$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return (int) $deleted;
	}

	/**
	 * Get entries ready for queue processing.
	 *
	 * Returns failed entries that are due for retry.
	 *
	 * @since 1.4.0
	 *
	 * @param int $batch_size Number of entries to fetch.
	 * @return array Array of entry objects.
	 */
	public static function get_processable_entries( $batch_size = 10 ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$now        = current_time( 'mysql' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table requires direct queries, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name}
				WHERE status = 'failed'
				AND next_retry_at IS NOT NULL
				AND next_retry_at <= %s
				AND retry_count < max_retries
				ORDER BY id ASC
				LIMIT %d",
				$now,
				$batch_size
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Count entries ready for queue processing.
	 *
	 * Uses the same WHERE clause as get_processable_entries but returns a count.
	 *
	 * @since 1.4.0
	 *
	 * @return int Number of retryable entries.
	 */
	public static function get_retryable_count() {
		global $wpdb;

		$table_name = self::get_table_name();
		$now        = current_time( 'mysql' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table requires direct queries, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name}
				WHERE status = 'failed'
				AND next_retry_at IS NOT NULL
				AND next_retry_at <= %s
				AND retry_count < max_retries",
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Reset all failed/error entries with API request data for retry
	 *
	 * Single UPDATE query — no row limit. Only resets entries that have
	 * endpoint and request_type (skips Logger-only entries).
	 *
	 * @since 1.4.0
	 *
	 * @param int $max_retries Max retries to set on reset entries.
	 * @return int Number of entries reset.
	 */
	public static function reset_all_for_retry( $max_retries = 5 ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$now        = current_time( 'mysql' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is composed from $wpdb->prefix and a hardcoded constant.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table_name}
				SET retry_count = 0,
					max_retries = %d,
					status = 'failed',
					next_retry_at = %s
				WHERE status IN ('failed', 'error')
				AND endpoint IS NOT NULL AND endpoint != ''
				AND request_type IS NOT NULL AND request_type != ''",
				$max_retries,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
