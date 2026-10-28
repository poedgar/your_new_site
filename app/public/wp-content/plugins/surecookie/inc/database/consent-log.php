<?php
/**
 * SureCookie Database ConsentLog Table Class.
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Database;

use SureCookie\Inc\Traits\GetInstance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SureCookie Database ConsentLog Table Class.
 *
 * @since 0.0.1
 */
class ConsentLog extends Base {
	use GetInstance;

	/**
	 * Object cache group for consent log reporting queries.
	 */
	private const QUERY_CACHE_GROUP = 'surecookie_consent_log';

	/**
	 * Per-user option key storing each admin's last-viewed consent log ID.
	 *
	 * Stored via update_user_option() so it lives in user meta (auto-removed
	 * with the user) yet stays blog-scoped on multisite, matching the per-blog
	 * consent log table.
	 */
	private const LAST_VIEWED_OPTION_KEY = 'surecookie_last_viewed_consent_log';

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $table_suffix = SURECOOKIE_CONSENT_LOG_DB;

	/**
	 * Whether the last upsert() wrote a row, or was swallowed as a duplicate.
	 * Read via {@see self::last_upsert_stored()} so callers only count and
	 * announce writes that actually landed.
	 *
	 * @since 1.3.0
	 * @var bool
	 */
	private static $last_upsert_stored = false;

	/**
	 * Get the rolling activity window for active-site evaluation.
	 *
	 * @since 1.1.0
	 * @return int
	 */
	public static function active_site_window_days(): int {
		/**
		 * Filters the active-site activity window length, in days.
		 *
		 * @since 1.1.0
		 * @param int $window_days Window length in days. Default 30.
		 */
		$window_days = (int) apply_filters( 'surecookie_active_site_window_days', 30 );

		return max( 1, $window_days );
	}

	/**
	 * Get the consent-log threshold required for a site to be active.
	 *
	 * @since 1.1.0
	 * @return int
	 */
	public static function active_site_log_threshold(): int {
		/**
		 * Filters the consent-log count required to treat a site as active.
		 *
		 * @since 1.1.0
		 * @param int $threshold Minimum recent consent logs. Default 30.
		 */
		$threshold = (int) apply_filters( 'surecookie_active_site_log_threshold', 30 );

		return max( 1, $threshold );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_schema() {
		return [
			// Consent log ID.
			'id'                 => [
				'type' => 'number',
			],
			// IP Address.
			'ip_address'         => [
				'type' => 'string',
			],
			// User ID.
			'user_id'            => [
				'type' => 'number',
			],
			// Session ID (UUID for tracking anonymous users across visits).
			'session_id'         => [
				'type' => 'string',
			],
			// Preferences.
			'preferences'        => [
				'type' => 'string',
			],
			// Action.
			'action'             => [
				'type' => 'string',
			],
			// Created timestamp.
			'timestamp'          => [
				'type' => 'string',
			],
			// Country.
			'country'            => [
				'type' => 'string',
			],
			// ISO region name (US state, Indian state), from GeoLite2-City.
			'region'             => [
				'type' => 'string',
			],
			// Geo preset ID (which geo preset was active when consent was recorded).
			'geo_preset_id'      => [
				'type' => 'string',
			],
			// Consent Forwarding: 1 when this row was written by a partner site.
			'is_forwarded'       => [
				'type' => 'boolean',
			],
			// Consent Forwarding: URL of the site that originally captured this consent.
			'origin_site'        => [
				'type' => 'string',
			],
			// Consent Forwarding: the origin's blog id, which survives a site
			// address change. 0 on rows forwarded before this column existed.
			'origin_blog_id'     => [
				'type' => 'number',
			],
			// Consent Forwarding: JSON array of partner site URLs this consent was forwarded to.
			'forwarded_to'       => [
				'type' => 'string',
			],
			// Partner blog ids, which a site address change cannot break. Added
			// alongside forwarded_to rather than replacing it: an older Pro reads
			// that column and would stop cascading if its shape changed.
			'forwarded_blog_ids' => [
				'type' => 'string',
			],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_columns_definition() {
		return [
			'id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
			'ip_address varchar(45) NOT NULL',
			'user_id bigint(20) DEFAULT NULL',
			'session_id varchar(36) NOT NULL',
			'preferences longtext NOT NULL',
			'action ENUM(\'accepted\', \'declined\', \'partially_accepted\') NOT NULL DEFAULT \'partially_accepted\'',
			'country varchar(100) DEFAULT \'Unknown\'',
			// ISO region name (US state, Indian state), from GeoLite2-City.
			'region varchar(100) DEFAULT \'\'',
			'geo_preset_id varchar(100) DEFAULT NULL',
			// Consent Forwarding: is_forwarded/origin_site mark rows received
			// from a partner site; forwarded_to is set on local origin rows
			// after they are forwarded out.
			'is_forwarded tinyint(1) NOT NULL DEFAULT 0',
			'origin_site varchar(255) DEFAULT NULL',
			'origin_blog_id bigint(20) unsigned NOT NULL DEFAULT 0',
			'forwarded_to text DEFAULT NULL',
			'forwarded_blog_ids text DEFAULT NULL',
			'timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
			// Index for geo preset filtering and audit queries.
			'KEY geo_preset_id (geo_preset_id)',
			// Index for the received/shared forwarding filters.
			'KEY is_forwarded (is_forwarded)',
			// (session_id, timestamp) dedupes same-second duplicates via upsert()'s ON DUPLICATE KEY
			// UPDATE, while later re-consents (new second) still create a fresh history row.
			// This unique index also serves ordered session-history queries
			// (WHERE session_id = ? ORDER BY timestamp DESC) - the engine scans it
			// backward - so no separate composite index is needed. A separate index
			// with a column-level `DESC` also broke SQLite's dbDelta translation, so
			// it is intentionally omitted here.
			'UNIQUE KEY session_unique (session_id, timestamp)',
			// Index for date-based queries and cleanup operations.
			'KEY timestamp (timestamp)',
			// Index for logged-in user queries.
			'KEY user_id (user_id)',
			// Index for action-based analytics.
			'KEY action (action)',
			// Index for IP-based admin searches.
			'KEY ip_address (ip_address)',
		];
	}

	/**
	 * Add a new log entry to the database.
	 *
	 * @param array<mixed> $data An associative array of data for the new log. Must include 'id' and 'logs'.
	 * @since 0.0.1
	 * @return int|false The ID of the inserted entry, or false if insertion fails.
	 */
	public static function add( $data ) {
		if ( empty( $data['ip_address'] ) || empty( $data['preferences'] ) ) {
			return false;
		}

		if ( isset( $data['id'] ) ) {
			// Unset ID if exists because we are creating a new entry.
			unset( $data['id'] );
		}

		$instance = self::get_instance();
		$result   = $instance->use_insert( $data );

		if ( $result !== false ) {
			self::invalidate_query_cache();
		}

		return $result;
	}

	/**
	 * Delete an log entry by log ID.
	 *
	 * @param int $log_id Log ID to delete.
	 * @since 0.0.1
	 * @return int|false The number of rows deleted, or false on error.
	 */
	public static function delete_by_log_id( $log_id ) {
		$log_id = absint( $log_id );

		// Fetch before deletion so hook listeners receive the row payload (e.g. forwarded_to).
		$log    = self::get_by_log_id( $log_id );
		$result = self::get_instance()->use_delete( [ 'id' => $log_id ], [ '%d' ] );

		if ( $result !== false ) {
			self::invalidate_query_cache();

			/**
			 * Fires after a consent log row has been deleted.
			 *
			 * Consumed by Pro's Consent Forwarding module to cascade the erasure
			 * to forwarded copies on partner Multisite subsites.
			 *
			 * @since 1.1.0
			 *
			 * @param int                  $log_id Deleted row id.
			 * @param array<string, mixed> $log    Row data at time of deletion (empty if row was not found).
			 */
			do_action( 'surecookie_consent_log_deleted', $log_id, $log );
		}

		return $result;
	}

	/**
	 * Delete multiple log entries by an array of log IDs in a single query.
	 *
	 * @param array<int> $log_ids Array of log IDs to delete.
	 * @since 0.0.1-beta.1
	 * @return int|false The number of rows deleted, or false on error.
	 */
	public static function delete_by_log_ids( array $log_ids ) {
		$ids = array_filter( array_map( 'absint', $log_ids ) );

		if ( empty( $ids ) ) {
			return false;
		}

		global $wpdb;

		$table_name   = self::get_instance()->get_tablename();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// Pre-fetch the rows being deleted - hook listeners need forwarded_to to cascade erasures.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, session_id, timestamp, forwarded_to, forwarded_blog_ids, is_forwarded FROM `{$table_name}` WHERE id IN ({$placeholders})", $ids ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$logs = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$logs[ (int) $row['id'] ] = $row;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Bulk delete with dynamic %d placeholders on custom table.
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table_name}` WHERE id IN ({$placeholders})", $ids ) );

		if ( $result !== false ) {
			self::invalidate_query_cache();

			/**
			 * Fires after multiple consent log rows have been deleted.
			 *
			 * $logs is a partial payload (forwarding-cascade columns only);
			 * for full rows hook the per-row `surecookie_consent_log_deleted`.
			 *
			 * @since 1.1.0
			 *
			 * @param array<int> $ids  Deleted row IDs (re-indexed).
			 * @param array<int, array{
			 *     id: int,
			 *     session_id: string,
			 *     timestamp: string,
			 *     forwarded_to: ?string,
			 *     forwarded_blog_ids: ?string,
			 *     is_forwarded: int
			 * }> $logs Pre-delete rows indexed by id.
			 */
			do_action( 'surecookie_consent_log_deleted_bulk', array_values( $ids ), $logs );
		}

		return $result;
	}

	/**
	 * Retrieve log data by log ID.
	 *
	 * @param int $log_id The log ID of the consent log.
	 * @since 0.0.1
	 * @return array<mixed> An associative array representing the log, or an empty array if not found.
	 */
	public static function get_by_log_id( $log_id ) {
		$results = self::get_instance()->get_results(
			[
				'id' => $log_id,
			]
		);

		return ! empty( $results ) && isset( $results[0] ) ? $results[0] : [];
	}

	/**
	 * Retrieve all log entries for a given session ID, ordered by timestamp descending.
	 *
	 * @param string $session_id The session UUID.
	 * @since 0.0.0-alpha.1
	 * @return array<mixed> Array of log rows, or empty array if none found.
	 */
	public static function get_by_session_id( string $session_id ): array {
		if ( empty( $session_id ) ) {
			return [];
		}

		return self::get_instance()->get_results(
			[
				'session_id' => $session_id,
			],
			'*',
			[
				'ORDER BY `timestamp` DESC',
			]
		);
	}

	/**
	 * Get filtered logs with search, action, country, and pagination.
	 *
	 * @param string $search  Search term matched against ip_address and session_id.
	 * @param string $action  Filter by action (accepted|declined|partially_accepted|received|shared). Empty returns all.
	 * @param string $country Filter by country. Empty string returns all countries.
	 * @param int    $limit   Number of results to return.
	 * @param int    $offset  Number of results to skip.
	 * @param string $date_from Inclusive lower bound as Y-m-d. Empty returns all.
	 * @param string $date_to   Inclusive upper bound as Y-m-d. Empty returns all.
	 * @since 0.0.1
	 * @return array<mixed> Array of log rows.
	 */
	public static function get_filtered( string $search, string $action, string $country, int $limit, int $offset, string $date_from = '', string $date_to = '' ): array {
		global $wpdb;

		$table_name                        = self::get_instance()->get_tablename();
		[ $action_enum, $forwarding_type ] = self::split_action_filter( $action );
		$where_sql                         = self::build_filter_where_clause( $search, $country, $action_enum, $forwarding_type, $date_from, $date_to );
		$cache_key                         = self::get_query_cache_key(
			'filtered',
			[
				'search'    => $search,
				'action'    => $action,
				'country'   => $country,
				'limit'     => $limit,
				'offset'    => $offset,
				'date_from' => $date_from,
				'date_to'   => $date_to,
			]
		);
		$cached                            = wp_cache_get( $cache_key, self::QUERY_CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$query  = 'SELECT * FROM %i' . $where_sql['sql'] . ' ORDER BY %i DESC, %i DESC LIMIT %d OFFSET %d';
		$values = array_merge( [ $table_name ], $where_sql['values'], [ 'timestamp', 'id', $limit, $offset ] );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $wpdb->prepare( $query, ...$values );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_string( $query ) ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table query prepared above; results are cached with a versioned object-cache key.
		$results = $wpdb->get_results( $query, ARRAY_A );

		$results = is_array( $results ) ? $results : [];
		wp_cache_set( $cache_key, $results, self::QUERY_CACHE_GROUP );

		return $results;
	}

	/**
	 * Get total and per-action counts in a single query using conditional aggregation.
	 *
	 * Replaces N+1 separate COUNT queries with one aggregate query.
	 *
	 * @param string $search  Search term matched against ip_address and session_id.
	 * @param string $country Filter by country. Empty string matches all countries.
	 * @param string $action  Filter by action (accepted|declined|partially_accepted|received|shared). Empty matches all.
	 * @param string $date_from Inclusive lower bound as Y-m-d. Empty matches all.
	 * @param string $date_to   Inclusive upper bound as Y-m-d. Empty matches all.
	 * @since 0.0.1
	 * @return array{total: int, accepted: int, declined: int, partially_accepted: int}
	 */
	public static function count_all_actions( string $search, string $country, string $action = '', string $date_from = '', string $date_to = '' ): array {
		global $wpdb;

		$table_name = self::get_instance()->get_tablename();
		$cache_key  = self::get_query_cache_key(
			'action-counts',
			[
				'search'    => $search,
				'country'   => $country,
				'action'    => $action,
				'date_from' => $date_from,
				'date_to'   => $date_to,
			]
		);
		$cached     = wp_cache_get( $cache_key, self::QUERY_CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return self::normalize_action_counts( $cached );
		}

		// Build WHERE with active filters so chart counts match the filtered dataset.
		[ $action_enum, $forwarding_type ] = self::split_action_filter( $action );
		$where_sql                         = self::build_filter_where_clause( $search, $country, $action_enum, $forwarding_type, $date_from, $date_to );
		$query                             = "SELECT
			COUNT(*) AS total,
			SUM(action = 'accepted') AS accepted,
			SUM(action = 'declined') AS declined,
			SUM(action = 'partially_accepted') AS partially_accepted
			FROM %i{$where_sql['sql']}";
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $wpdb->prepare( $query, ...array_merge( [ $table_name ], $where_sql['values'] ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_string( $query ) ) {
			return [
				'total'              => 0,
				'accepted'           => 0,
				'declined'           => 0,
				'partially_accepted' => 0,
			];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table aggregate query prepared above; results are cached with a versioned object-cache key.
		$row = $wpdb->get_row( $query, ARRAY_A );

		$results = self::normalize_action_counts(
			is_array( $row ) ? $row : []
		);

		wp_cache_set( $cache_key, $results, self::QUERY_CACHE_GROUP );

		return $results;
	}

	/**
	 * Count consent logs created inside the rolling activity window.
	 *
	 * @param int|null $days Optional. Override window length in days.
	 * @since 1.1.0
	 * @return int
	 */
	public static function count_recent_logs( ?int $days = null ): int {
		global $wpdb;

		$days = max( 1, (int) ( $days ?? self::active_site_window_days() ) );

		// Key on $days only - a write bumps the version and the TTL ages the rolling window out.
		$cache_key = self::get_query_cache_key( 'recent-log-count', [ 'days' => $days ] );
		$cached    = wp_cache_get( $cache_key, self::QUERY_CACHE_GROUP );

		if ( $cached !== false ) {
			return max( 0, (int) $cached );
		}

		$table_name = self::get_instance()->get_tablename();
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// %i quotes the table and column identifiers.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE %i >= %s',
			$table_name,
			'timestamp',
			$cutoff
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_string( $query ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; cached below with a versioned key plus TTL.
		$count = max( 0, (int) $wpdb->get_var( $query ) );

		wp_cache_set( $cache_key, $count, self::QUERY_CACHE_GROUP, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * Count consent logs created after a specific log ID.
	 *
	 * @param int $after_log_id Log ID threshold. Only newer rows are counted.
	 * @since 1.2.0
	 * @return int
	 */
	public static function count_logs_after_id( int $after_log_id ): int {
		global $wpdb;

		$after_log_id = max( 0, $after_log_id );
		$cache_key    = self::get_query_cache_key( 'logs-after-id-count', [ 'after_log_id' => $after_log_id ] );
		$cached       = wp_cache_get( $cache_key, self::QUERY_CACHE_GROUP );

		if ( $cached !== false ) {
			return max( 0, (int) $cached );
		}

		$table_name = self::get_instance()->get_tablename();

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE %i > %d',
			$table_name,
			'id',
			$after_log_id
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_string( $query ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; cached below with a versioned object-cache key.
		$count = max( 0, (int) $wpdb->get_var( $query ) );

		wp_cache_set( $cache_key, $count, self::QUERY_CACHE_GROUP, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * Get the latest consent log ID currently stored.
	 *
	 * @since 1.2.0
	 * @return int
	 */
	public static function get_latest_log_id(): int {
		global $wpdb;

		$cache_key = self::get_query_cache_key( 'latest-log-id', [] );
		$cached    = wp_cache_get( $cache_key, self::QUERY_CACHE_GROUP );

		if ( $cached !== false ) {
			return max( 0, (int) $cached );
		}

		$table_name = self::get_instance()->get_tablename();

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$query = $wpdb->prepare(
			'SELECT COALESCE( MAX(%i), 0 ) FROM %i',
			'id',
			$table_name
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( ! is_string( $query ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; cached below with a versioned object-cache key.
		$latest_log_id = max( 0, (int) $wpdb->get_var( $query ) );

		wp_cache_set( $cache_key, $latest_log_id, self::QUERY_CACHE_GROUP, HOUR_IN_SECONDS );

		return $latest_log_id;
	}

	/**
	 * Get the last-viewed consent log ID for a specific user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @since 1.2.0
	 * @return int
	 */
	public static function get_last_viewed_log_id_for_user( int $user_id ): int {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return 0;
		}

		return max( 0, (int) get_user_option( self::LAST_VIEWED_OPTION_KEY, $user_id ) );
	}

	/**
	 * Count unread consent logs for a specific user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @since 1.2.0
	 * @return int
	 */
	public static function count_unread_logs_for_user( int $user_id ): int {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return 0;
		}

		return self::count_logs_after_id( self::get_last_viewed_log_id_for_user( $user_id ) );
	}

	/**
	 * Mark the current latest consent log as viewed for a specific user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @since 1.2.0
	 * @return bool
	 */
	public static function mark_logs_viewed_for_user( int $user_id ): bool {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$latest_log_id = self::get_latest_log_id();

		// Already up to date - treat as a successful no-op.
		if ( self::get_last_viewed_log_id_for_user( $user_id ) === $latest_log_id ) {
			return true;
		}

		return (bool) update_user_option( $user_id, self::LAST_VIEWED_OPTION_KEY, $latest_log_id );
	}

	/**
	 * Determine whether the site is active based on recent consent log volume.
	 *
	 * @since 1.1.0
	 * @return bool
	 */
	public static function has_recent_log_activity(): bool {
		return self::count_recent_logs() >= self::active_site_log_threshold();
	}

	/**
	 * Delete consent logs older than specified days.
	 *
	 * @param int $days Number of days. Records older than this will be deleted. Must be positive.
	 * @since 0.0.1
	 * @return int|false The number of rows deleted, or false on error.
	 *                   Returns false if: days <= 0, timestamp column not in schema,
	 *                   strtotime fails, query preparation fails, or query execution fails.
	 */
	public static function delete_logs_older_than( int $days ) {
		if ( $days <= 0 ) {
			return false;
		}

		$result = self::get_instance()->delete_older_than( 'timestamp', $days );

		if ( $result !== false ) {
			self::invalidate_query_cache();
		}

		return $result;
	}

	/**
	 * Partner blog ids a row was forwarded to.
	 *
	 * Prefers `forwarded_blog_ids`, which a partner rename cannot break. Falls back
	 * to resolving `forwarded_to` for rows written before that column existed, or by
	 * an install whose free plugin predates it - that fallback is what the rename
	 * breaks, which is why the backfill exists.
	 *
	 * @since 1.7.0
	 * @param array<string, mixed> $row Consent log row.
	 * @return array<int, int> Partner blog ids, empty when none resolve.
	 */
	public static function forwarded_blog_ids( array $row ): array {
		$ids = json_decode( (string) ( $row['forwarded_blog_ids'] ?? '' ), true );

		if ( is_array( $ids ) && ! empty( $ids ) ) {
			return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		}

		$urls     = json_decode( (string) ( $row['forwarded_to'] ?? '' ), true );
		$resolved = [];

		foreach ( is_array( $urls ) ? $urls : [] as $url ) {
			$blog_id = is_string( $url ) ? self::origin_blog_id( $url ) : 0;
			if ( $blog_id > 0 ) {
				$resolved[] = $blog_id;
			}
		}

		return array_values( array_unique( $resolved ) );
	}

	/**
	 * Resolve a forwarded row's origin URL to its blog id.
	 *
	 * Mirrors Pro's `Forwarder::find_blog_id()`, which is what writes these URLs:
	 * host lowercased, path trailingslashed, because that is the shape `wp_blogs`
	 * stores while `origin_site` is saved untrailingslashed. Returns 0 when no site
	 * answers to that address - an origin already renamed, or a domain-mapped one.
	 *
	 * @since 1.7.0
	 * @param string $url Origin URL as stored on the row.
	 * @return int Blog id, or 0 when it cannot be resolved.
	 */
	public static function origin_blog_id( string $url ): int {
		if ( $url === '' || ! is_multisite() || ! function_exists( 'get_blog_id_from_url' ) ) {
			return 0;
		}

		$parsed = wp_parse_url( $url );

		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return 0;
		}

		$path = isset( $parsed['path'] ) && $parsed['path'] !== '' ? trailingslashit( (string) $parsed['path'] ) : '/';

		return (int) get_blog_id_from_url( strtolower( (string) $parsed['host'] ), $path );
	}

	/**
	 * WHERE fragment matching a forwarded row's origin, with its bindings.
	 *
	 * Rows forwarded before `origin_blog_id` existed carry 0 and can only be
	 * matched on the URL, which is exactly what a site address change breaks
	 * (#1377), so the blog id is preferred and the URL remains the fallback.
	 *
	 * @param string $origin_site    Origin URL as stored on the row.
	 * @param int    $origin_blog_id Origin blog id, 0 when not known.
	 * @since 1.7.0
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function origin_match( string $origin_site, int $origin_blog_id ): array {
		if ( $origin_blog_id > 0 ) {
			return [
				'( origin_blog_id = %d OR ( origin_blog_id = 0 AND origin_site = %s ) )',
				[ $origin_blog_id, $origin_site ],
			];
		}

		return [ 'origin_site = %s', [ $origin_site ] ];
	}

	/**
	 * Delete a session's forwarded rows, optionally constrained by timestamp.
	 *
	 * @param string      $session_id     Session UUID.
	 * @param string      $origin_site    Origin URL as stored on the row.
	 * @param int         $origin_blog_id Origin blog id, 0 when not known.
	 * @param string|null $timestamp      Timestamp to constrain on, null for the whole session.
	 * @param bool        $except         Delete every row but $timestamp instead of only that one.
	 * @since 1.7.0
	 * @return bool True when at least one row was deleted.
	 */
	private static function delete_forwarded( string $session_id, string $origin_site, int $origin_blog_id, ?string $timestamp = null, bool $except = false ): bool {
		global $wpdb;

		[ $sql, $bindings ] = self::origin_match( $origin_site, $origin_blog_id );

		$sql = 'DELETE FROM %i WHERE session_id = %s AND is_forwarded = 1 AND ' . $sql;

		if ( $timestamp !== null ) {
			$sql       .= $except ? ' AND timestamp <> %s' : ' AND timestamp = %s';
			$bindings[] = $timestamp;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Targeted deletion on a custom table inside switch_to_blog; %i is the WP 6.2+ identifier placeholder, every fragment is built from internal placeholders only, and the cache is invalidated below.
		$query = $wpdb->prepare( $sql, array_merge( [ self::get_instance()->get_tablename(), $session_id ], $bindings ) );

		// prepare() returns void when it rejects its input, and query( null ) would
		// report a failed erasure as "nothing matched".
		if ( ! is_string( $query ) ) {
			return false;
		}

		$result = $wpdb->query( $query );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( $result ) {
			self::invalidate_query_cache();
		}

		return (bool) $result;
	}

	/**
	 * Delete forwarded consent rows matching a session ID and origin site.
	 *
	 * Called by Pro's Consent Forwarding module while inside a switch_to_blog()
	 * context to cascade erasures from the origin site to each partner subsite.
	 *
	 * @param string $session_id  Session UUID of the row to delete.
	 * @param string $origin_site Origin site canonical URL stored in the forwarded row.
	 * @param int    $origin_blog_id Origin blog id; 0 falls back to matching origin_site.
	 * @since 1.1.0
	 * @return bool True when at least one row was deleted.
	 */
	public static function delete_forwarded_by_session( string $session_id, string $origin_site, int $origin_blog_id = 0 ): bool {
		if ( $session_id === '' || $origin_site === '' ) {
			return false;
		}
		return self::delete_forwarded( $session_id, $origin_site, $origin_blog_id );
	}

	/**
	 * Delete a session's forwarded rows except the one at a given timestamp.
	 *
	 * Lets Pro replace a partner's forwarded decision by writing first and pruning
	 * after, so a failed replacement leaves the previous proof of consent standing
	 * instead of erasing it and putting nothing back.
	 *
	 * @param string $session_id  Session UUID whose forwarded rows to prune.
	 * @param string $origin_site Origin site canonical URL stored in the forwarded row.
	 * @param string $timestamp   Timestamp of the row to keep.
	 * @param int    $origin_blog_id Origin blog id; 0 falls back to matching origin_site.
	 * @since 1.3.0
	 * @return bool True when at least one row was deleted.
	 */
	public static function delete_forwarded_by_session_except( string $session_id, string $origin_site, string $timestamp, int $origin_blog_id = 0 ): bool {
		if ( $session_id === '' || $origin_site === '' || $timestamp === '' ) {
			return false;
		}
		return self::delete_forwarded( $session_id, $origin_site, $origin_blog_id, $timestamp, true );
	}

	/**
	 * Delete the one forwarded row that mirrors a single origin consent event.
	 *
	 * The forwarded copy carries the origin row's `timestamp` verbatim, and the
	 * origin's own unique key is `(session_id, timestamp)`, so this triple already
	 * identifies one event without a mapping column. Erasing an origin row must
	 * not reach past it: a session accumulates a history row per re-consent, and
	 * the session-wide sweep would take the visitor's CURRENT decision with it.
	 *
	 * @param string $session_id  Session UUID of the row to delete.
	 * @param string $origin_site Origin site canonical URL stored in the forwarded row.
	 * @param string $timestamp   Origin row timestamp, copied onto the forwarded row.
	 * @param int    $origin_blog_id Origin blog id; 0 falls back to matching origin_site.
	 * @since 1.3.0
	 * @return bool True when at least one row was deleted.
	 */
	public static function delete_forwarded_by_session_event( string $session_id, string $origin_site, string $timestamp, int $origin_blog_id = 0 ): bool {
		if ( $session_id === '' || $origin_site === '' || $timestamp === '' ) {
			return false;
		}
		return self::delete_forwarded( $session_id, $origin_site, $origin_blog_id, $timestamp );
	}

	/**
	 * Record the list of partner sites a consent was forwarded to.
	 *
	 * Called by the Pro Consent Forwarding module after a successful forward
	 * so the admin log can show exactly which sites received the consent.
	 *
	 * @param int           $log_id    Row id of the original (local) consent.
	 * @param array<string> $site_urls Ordered list of partner site home URLs.
	 * @return bool
	 * @since 1.1.0
	 */
	public static function update_forwarded_to( int $log_id, array $site_urls ): bool {
		if ( $log_id <= 0 || empty( $site_urls ) ) {
			return false;
		}

		$urls = array_values( $site_urls );
		$json = wp_json_encode( $urls );
		if ( ! is_string( $json ) ) {
			return false;
		}

		// Resolved here, not by the caller, so an older Pro that only knows how to
		// send URLs still gets rename-proof ids written for it.
		$ids = [];
		foreach ( $urls as $url ) {
			$blog_id = is_string( $url ) ? self::origin_blog_id( $url ) : 0;
			if ( $blog_id > 0 ) {
				$ids[] = $blog_id;
			}
		}

		$ids_json = wp_json_encode( array_values( array_unique( $ids ) ) );

		$result = self::get_instance()->use_update(
			[
				'forwarded_to'       => $json,
				'forwarded_blog_ids' => is_string( $ids_json ) ? $ids_json : '[]',
			],
			[ 'id' => $log_id ]
		);

		if ( $result !== false ) {
			self::invalidate_query_cache();
		}

		return $result !== false;
	}

	/**
	 * Whether the most recent {@see self::upsert()} actually wrote a row, as
	 * opposed to being swallowed as a same-second duplicate.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	public static function last_upsert_stored(): bool {
		return self::$last_upsert_stored;
	}

	/**
	 * Insert or update the consent record for a session.
	 *
	 * Uses INSERT … ON DUPLICATE KEY UPDATE so that concurrent requests carrying
	 * the same session_id are collapsed into a single row atomically at the
	 * database level, preventing the race condition where two simultaneous
	 * requests both pass the "does this session exist?" check and both insert.
	 *
	 * The LAST_INSERT_ID(id) trick in the UPDATE clause ensures that
	 * $wpdb->insert_id is populated with the existing row's id on update, not 0.
	 *
	 * @param string      $ip_address    Anonymized IP address.
	 * @param int         $user_id       WordPress user ID (0 for guests).
	 * @param string      $session_id    Session UUID - must be unique per visitor session.
	 * @param string      $preferences   JSON-encoded consent preferences.
	 * @param string      $action        Consent action (accepted|declined|partially_accepted).
	 * @param string      $country       Country name derived from IP.
	 * @param string|null $timestamp     UTC datetime string; defaults to current UTC time.
	 * @param string|null $geo_preset_id Geo preset ID active when consent was recorded.
	 * @param bool        $is_forwarded  Whether this consent was forwarded from a partner site.
	 * @param string|null $origin_site   Origin site URL when this is a forwarded consent.
	 * @param string      $region        Region name for further processing.
	 * @param bool|null   $stored        Out: whether this call actually wrote a row, as opposed to being swallowed as a same-second duplicate.
	 * @param int         $origin_blog_id Origin blog id written onto a forwarded row; 0 for a local row.
	 * @since 0.0.1
	 * @return int|false Row ID of the inserted or updated entry, or false on error.
	 */
	public static function upsert( $ip_address, $user_id, $session_id, $preferences, $action, $country, $timestamp = null, $geo_preset_id = null, bool $is_forwarded = false, ?string $origin_site = null, string $region = '', &$stored = null, int $origin_blog_id = 0 ) {
		global $wpdb;

		// Per-call outcome: a listener re-entering upsert() reassigns the static.
		$stored                   = false;
		self::$last_upsert_stored = false;

		/*
		 * A caller that pins the timestamp is identifying an existing event, not
		 * recording a new one - Pro's forwarding copies the origin row's timestamp
		 * verbatim and matches on it - so only a timestamp we generated ourselves
		 * may be nudged to dodge a collision below.
		 */
		$timestamp_is_ours = $timestamp === null;

		if ( $timestamp === null ) {
			$timestamp = current_time( 'mysql', true );
		}

		if ( empty( $ip_address ) || empty( $preferences ) || empty( $action ) || empty( $country ) ) {
			return false;
		}

		$table_name = self::get_instance()->get_tablename();

		// ON DUPLICATE KEY UPDATE touches only `id = LAST_INSERT_ID(id)` - a forged
		// session_id can't overwrite another visitor's stored consent.
		// geo_preset_id/origin_site bind explicit NULL when empty to preserve DEFAULT NULL.
		$preset         = ! empty( $geo_preset_id ) ? sanitize_text_field( (string) $geo_preset_id ) : null;
		$preset_sql     = $preset === null ? 'NULL' : '%s';
		$preset_binding = $preset === null ? [] : [ $preset ];

		// Consent-forwarding columns. Keep origin_site NULL for local rows
		// so the column default semantic (no origin = local) stays clean.
		$origin         = ! empty( $origin_site ) ? esc_url_raw( (string) $origin_site ) : null;
		$origin_sql     = $origin === null ? 'NULL' : '%s';
		$origin_binding = $origin === null ? [] : [ $origin ];
		$origin_blog    = max( 0, $origin_blog_id );

		// An older Pro passes none, and the one-shot backfill has already run by then,
		// so resolving it here is what stops a new forwarded row being left behind.
		if ( $origin_blog === 0 && $is_forwarded && $origin !== null ) {
			$origin_blog = self::origin_blog_id( $origin );
		}
		$forwarded_flag = $is_forwarded ? 1 : 0;
		$region         = sanitize_text_field( $region );

		$log_id = false;

		// Only a self-generated timestamp may be nudged, and never more than twice -
		// the recorded second then trails the real event by at most 2s, which beats
		// dropping the decision outright.
		$attempts = $timestamp_is_ours ? 3 : 1;

		for ( $attempt = 0; $attempt < $attempts; $attempt++ ) {
			$values = array_merge(
				[ $table_name, $ip_address, (int) $user_id, $session_id, $preferences, $action, $country, $region, $timestamp ],
				$preset_binding,
				[ $forwarded_flag ],
				$origin_binding,
				[ $origin_blog ]
			);

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $preset_sql / $origin_sql are hardcoded switches between 'NULL' (literal) and '%s' (placeholder); not user-controlled.
			$query = $wpdb->prepare(
				'INSERT INTO %i (ip_address, user_id, session_id, preferences, action, country, region, timestamp, geo_preset_id, is_forwarded, origin_site, origin_blog_id)
				 VALUES (%s, %d, %s, %s, %s, %s, %s, %s, ' . $preset_sql . ', %d, ' . $origin_sql . ', %d)
				 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
				$values
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			if ( ! is_string( $query ) ) {
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; INSERT ON DUPLICATE KEY UPDATE requires a direct query for the atomic upsert guarantee.
			$result = $wpdb->query( $query );

			if ( $result === false ) {
				return false;
			}

			// insert_id = new id on INSERT, or existing id via LAST_INSERT_ID(id) on collision
			// (fires even on a no-op update in MySQL 5.7+/8.0).
			$log_id = $wpdb->insert_id > 0 ? $wpdb->insert_id : false;

			// A fresh INSERT affects one row; the no-op `id = LAST_INSERT_ID(id)`
			// update affects none, which is the same-second collision.
			if ( (int) $result !== 0 ) {
				$stored = true;
				break;
			}

			/*
			 * Same session, same second. Resubmitting the same decision is exactly
			 * the duplicate this unique key exists to swallow, so stop. A DIFFERENT
			 * decision is a real event, and the row cannot be updated in place - the
			 * id-only UPDATE clause above is what stops a forged session_id from
			 * overwriting a stranger's consent - so retry a second later instead.
			 */
			if ( ! self::stored_row_differs( $log_id, (string) $preferences, (string) $action ) ) {
				break;
			}

			$timestamp = gmdate( 'Y-m-d H:i:s', (int) strtotime( $timestamp . ' UTC' ) + 1 );
		}

		self::invalidate_query_cache();
		self::$last_upsert_stored = $stored;

		if ( $log_id && $stored ) {
			/**
			 * Fires after a consent log row has been successfully written.
			 *
			 * Consumed by Pro's Consent Forwarding module to replicate the
			 * event to partner Multisite subsites. Listeners MUST NOT block
			 * because the public banner's REST response is waiting.
			 *
			 * @since 1.1.0
			 *
			 * @param int                  $log_id   Row id just written.
			 * @param array<string, mixed> $row_data The full row payload.
			 */
			do_action(
				'surecookie_consent_log_inserted',
				$log_id,
				[
					'ip_address'     => $ip_address,
					'user_id'        => (int) $user_id,
					'session_id'     => $session_id,
					'preferences'    => $preferences,
					'action'         => $action,
					'country'        => $country,
					'region'         => $region,
					'timestamp'      => $timestamp,
					'geo_preset_id'  => $preset,
					'is_forwarded'   => (bool) $forwarded_flag,
					'origin_site'    => $origin,
					'origin_blog_id' => $origin_blog,
				]
			);

			// A listener that writes its own row (Pro's forwarding fallback) leaves
			// the static describing that write, so restore this call's outcome.
			self::$last_upsert_stored = $stored;
		}

		return $log_id;
	}

	/**
	 * Whether a stored row holds a different decision than the one just offered.
	 *
	 * @param int|false $log_id      Row id the collision resolved to.
	 * @param string    $preferences Incoming JSON-encoded preferences.
	 * @param string    $action      Incoming consent action.
	 * @since 1.3.0
	 * @return bool
	 */
	private static function stored_row_differs( $log_id, string $preferences, string $action ): bool {
		if ( ! $log_id ) {
			return false;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Single-row read resolving a write collision, so a cached value would defeat the purpose; %i is the WP 6.2+ identifier placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT preferences, action FROM %i WHERE id = %d', self::get_instance()->get_tablename(), (int) $log_id ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_array( $row ) ) {
			return false;
		}

		return (string) ( $row['preferences'] ?? '' ) !== $preferences
			|| (string) ( $row['action'] ?? '' ) !== $action;
	}

	/**
	 * Normalize action count query results into the expected return shape.
	 *
	 * @param array<string, mixed> $results Raw database results.
	 * @since 0.0.2
	 * @return array{total: int, accepted: int, declined: int, partially_accepted: int}
	 */
	private static function normalize_action_counts( array $results ): array {
		return [
			'total'              => (int) ( $results['total'] ?? 0 ),
			'accepted'           => (int) ( $results['accepted'] ?? 0 ),
			'declined'           => (int) ( $results['declined'] ?? 0 ),
			'partially_accepted' => (int) ( $results['partially_accepted'] ?? 0 ),
		];
	}

	/**
	 * Build a versioned object cache key for reporting queries.
	 *
	 * @param string               $context Cache context.
	 * @param array<string, mixed> $args Cache arguments.
	 * @since 0.0.2
	 * @return string
	 */
	private static function get_query_cache_key( string $context, array $args ): string {
		$payload = wp_json_encode(
			[
				'context' => $context,
				'version' => self::get_query_cache_version(),
				'args'    => $args,
			]
		);

		return md5( $payload === false ? $context : $payload );
	}

	/**
	 * Get the current cache version used to invalidate reporting queries.
	 *
	 * @since 0.0.2
	 * @return int
	 */
	private static function get_query_cache_version(): int {
		$version = wp_cache_get( 'query-version', self::QUERY_CACHE_GROUP );

		if ( $version === false ) {
			$version = 1;
			wp_cache_set( 'query-version', $version, self::QUERY_CACHE_GROUP );
		}

		return (int) $version;
	}

	/**
	 * Invalidate cached reporting queries after a write.
	 *
	 * @since 0.0.2
	 * @return void
	 */
	private static function invalidate_query_cache(): void {
		// wp_cache_incr() is atomic on Memcached/Redis, avoiding the read-modify-write race
		// where two concurrent writes both read version N and both write N+1 (net: only one
		// invalidation instead of two). If the key is absent, initialize it so future reads
		// compute a fresh cache key and always miss the stale entry.
		if ( wp_cache_incr( 'query-version', 1, self::QUERY_CACHE_GROUP ) === false ) {
			wp_cache_set( 'query-version', 1, self::QUERY_CACHE_GROUP );
		}
	}

	/**
	 * Build prepared WHERE clause parts.
	 *
	 * @param string $search          Matched against ip_address and session_id.
	 * @param string $country         Empty matches all countries.
	 * @param string $action_enum     Empty | accepted | declined | partially_accepted.
	 * @param string $forwarding_type Empty | received | shared.
	 * @param string $date_from       Inclusive lower bound as Y-m-d. Empty matches all.
	 * @param string $date_to         Inclusive upper bound as Y-m-d. Empty matches all.
	 * @since 0.0.1
	 * @return array{sql: string, values: array<int, string>} Prepared WHERE clause data.
	 */
	private static function build_filter_where_clause( string $search, string $country, string $action_enum, string $forwarding_type = '', string $date_from = '', string $date_to = '' ): array {
		global $wpdb;

		$where_parts = [];
		$values      = [];

		if ( ! empty( $search ) ) {
			$search_like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where_parts[] = '(ip_address LIKE %s OR session_id LIKE %s)';
			$values[]      = $search_like;
			$values[]      = $search_like;
		}

		if ( ! empty( $country ) ) {
			$where_parts[] = 'country = %s';
			$values[]      = $country;
		}

		if ( ! empty( $action_enum ) ) {
			$where_parts[] = 'action = %s';
			$values[]      = $action_enum;
		}

		if ( $forwarding_type === 'received' ) {
			$where_parts[] = 'is_forwarded = 1';
		} elseif ( $forwarding_type === 'shared' ) {
			$where_parts[] = 'forwarded_to IS NOT NULL AND forwarded_to != %s AND forwarded_to != %s';
			$values[]      = '';
			$values[]      = '[]';
		}

		if ( ! empty( $date_from ) ) {
			$where_parts[] = 'timestamp >= %s';
			$values[]      = self::utc_bound( $date_from, '00:00:00' );
		}

		if ( ! empty( $date_to ) ) {
			$where_parts[] = 'timestamp <= %s';
			$values[]      = self::utc_bound( $date_to, '23:59:59' );
		}

		return [
			'sql'    => ! empty( $where_parts ) ? ' WHERE ' . implode( ' AND ', $where_parts ) : '',
			'values' => $values,
		];
	}

	/**
	 * Turn a picker date into the UTC bound stored in `timestamp`.
	 *
	 * Rows are written with current_time( 'mysql', true ) and listed back in the
	 * site timezone, so a date the user picked means a site-timezone day. The
	 * end of day is included so a datetime column is not cut off at midnight.
	 *
	 * @param string $date Y-m-d in the site timezone.
	 * @param string $time Boundary time, 00:00:00 or 23:59:59.
	 * @since 1.4.0
	 * @return string
	 */
	private static function utc_bound( string $date, string $time ): string {
		$local = $date . ' ' . $time;

		return function_exists( 'get_gmt_from_date' )
			? (string) get_gmt_from_date( $local, 'Y-m-d H:i:s' )
			: $local;
	}

	/**
	 * Translate the overloaded $action REST param into [ action_enum, forwarding_type ].
	 *
	 * The public REST contract accepts a single $action value that's either a
	 * consent-action enum (accepted/declined/partially_accepted) OR a
	 * forwarding-direction filter (received/shared). Internal callers want
	 * these as two clean axes - this is the one place the overload is unpacked.
	 *
	 * @since 1.1.0
	 * @param string $action Raw $action value from the REST request.
	 * @return array{0: string, 1: string} [ action_enum, forwarding_type ] - one is always empty.
	 */
	private static function split_action_filter( string $action ): array {
		if ( $action === 'received' || $action === 'shared' ) {
			return [ '', $action ];
		}
		return [ $action, '' ];
	}
}
