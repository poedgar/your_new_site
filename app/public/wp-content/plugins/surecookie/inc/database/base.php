<?php
/**
 * SureCookie Database Tables Base Class.
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Database;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SureCookie Database Tables Base Class
 *
 * @since 0.0.1
 */
abstract class Base {
	/**
	 * WordPress Database class instance.
	 *
	 * @var \wpdb
	 * @since 0.0.1
	 */
	protected $wpdb;

	/**
	 * Current database table.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $table_prefix;

	/**
	 * Custom table suffix without any prefix. This needs to be overridden from child class. Final named as example 'surecookie_consent_log'.
	 *
	 * @var string
	 * @since 0.0.1
	 * @override
	 */
	protected $table_suffix;

	/**
	 * Current table database result caches.
	 *
	 * @var array<mixed>
	 * @since 0.0.1
	 */
	private $caches = [];

	/**
	 * Init class.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function __construct() {
		global $wpdb;

		$this->wpdb         = $wpdb;
		$this->table_prefix = $this->wpdb->prefix;
	}

	/**
	 * Fired when a new site is activated with a WPMU environment.
	 *
	 * @param \WP_Site $site The new site's object.
	 * @since 0.0.1
	 * @return void
	 */
	public function initialize_site( $site ): void {
		switch_to_blog( intval( $site->blog_id ) );
		$columns = $this->get_columns_definition();
		if ( ! empty( $columns ) ) {
			$this->create( $columns );
		}

		restore_current_blog();
	}

	/**
	 * Returns the current table schema.
	 *
	 * @since 0.0.1
	 * @return array<string,array<mixed>>
	 */
	abstract public function get_schema();

	/**
	 * Current table columns definition to create table.
	 *
	 * @since 0.0.1
	 * @return array<string>
	 */
	abstract public function get_columns_definition();

	/**
	 * Returns full table name.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public function get_tablename() {
		// Derive from the current $wpdb->prefix so switch_to_blog() is
		// respected - the cached $table_name is stale after a blog switch.
		return $this->wpdb->prefix . $this->table_suffix;
	}

	/**
	 * Conditionally returns current database charset or collate.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public function get_charset_collate() {
		$charset_collate = '';

		if ( $this->wpdb->has_cap( 'collation' ) ) {
			if ( ! empty( $this->wpdb->charset ) ) {
				$charset_collate = "DEFAULT CHARACTER SET {$this->wpdb->charset}";
			}
			if ( ! empty( $this->wpdb->collate ) ) {
				$charset_collate .= " COLLATE {$this->wpdb->collate}";
			}
		}

		return $charset_collate;
	}

	/**
	 * Create table if it doesn't exist.
	 *
	 * @param array<string> $columns Array of columns.
	 * @since 0.0.1
	 * @return int|bool
	 */
	public function create( $columns = [] ) {
		if ( empty( $columns ) || ! is_array( $columns ) ) {
			return false;
		}

		if ( is_multisite() ) {
			return $this->network_create_tables( $columns );
		}

		return $this->create_table( $this->get_tablename(), $columns );
	}

	/**
	 * Insert data.
	 *
	 * @param array<mixed>              $data Data to insert.
	 * @param array<string>|string|null $format Optional format.
	 * @since 0.0.1
	 * @return int|false The id of the inserted entry, or false on error.
	 */
	public function use_insert( $data, $format = null ) {
		$prepared_data = $this->prepare_data( $data );

		if ( is_null( $format ) ) {
			$format = $prepared_data['format'];
		}

		$result = $this->wpdb->insert( $this->get_tablename(), $prepared_data['data'], $format );

		if ( $result ) {
			$this->cache_reset();
		}

		return $result ? $this->wpdb->insert_id : false;
	}

	/**
	 * Update a row data of current table.
	 *
	 * @param array<string,mixed> $data Data to update.
	 * @param array<string,mixed> $where WHERE clauses.
	 * @since 0.0.1
	 * @return int|false The number of rows updated, or false on error.
	 */
	public function use_update( $data, $where ) {
		$prepared_data = $this->prepare_data( $data, true );
		$format        = $prepared_data['format'];

		// Reset the cache on update.
		$this->cache_reset();

		return $this->wpdb->update(
			$this->get_tablename(),
			$prepared_data['data'],
			$where,
			$format
		);
	}

	/**
	 * Delete a row data of current table.
	 *
	 * @param array<string,mixed>  $where WHERE clauses.
	 * @param array<string>|string $where_format Optional format.
	 * @since 0.0.1
	 * @return int|false The number of rows deleted, or false on error.
	 */
	public function use_delete( $where, $where_format = null ) {
		$result = $this->wpdb->delete( $this->get_tablename(), $where, $where_format );

		if ( $result ) {
			$this->cache_reset();
		}

		return $result;
	}

	/**
	 * Delete all data of current table.
	 *
	 * Uses a prepared DELETE: wpdb::delete() with an empty WHERE builds
	 * invalid SQL ("DELETE FROM t WHERE ") and always fails.
	 *
	 * @since 0.0.1
	 * @return int|false The number of rows deleted, or false on error.
	 */
	public function use_delete_all() {
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- %i is the WP 6.2+ identifier placeholder.
		$query = $this->wpdb->prepare( 'DELETE FROM %i', $this->get_tablename() );
		if ( ! is_string( $query ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; intentional full-table delete.
		$result = $this->wpdb->query( $query );

		if ( $result === false ) {
			return false;
		}

		$result = (int) $result;

		if ( $result > 0 ) {
			$this->cache_reset();
		}

		return $result;
	}

	/**
	 * Delete records older than a specified date based on a timestamp column.
	 *
	 * @param string $column   The timestamp column name to compare against.
	 * @param int    $days     Number of days. Records older than this will be deleted.
	 * @since 0.0.1
	 * @return int|false The number of rows deleted, or false on error.
	 */
	public function delete_older_than( string $column, int $days ) {
		if ( $days <= 0 ) {
			return false;
		}

		// Whitelist validation: only allow columns defined in the schema.
		$schema          = $this->get_schema();
		$allowed_columns = array_keys( $schema );
		$column          = sanitize_key( $column );

		if ( ! in_array( $column, $allowed_columns, true ) ) {
			return false;
		}

		$table_name = $this->get_tablename();
		$timestamp  = strtotime( "-{$days} days" );

		if ( $timestamp === false ) {
			return false;
		}

		$cutoff_date = gmdate( 'Y-m-d H:i:s', $timestamp );

		// Reset cache as data will change.
		$this->cache_reset();

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			'DELETE FROM %i WHERE %i < %s',
			$table_name,
			$column,
			$cutoff_date
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( $query === null ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query prepared above.
		$result = $this->wpdb->query( $query );

		return is_int( $result ) ? $result : false;
	}

	/**
	 * Retrieve results from the database.
	 *
	 * @param array<mixed>  $where_clauses Optional WHERE clauses.
	 * @param string        $columns Optional columns to select.
	 * @param array<string> $extra_queries Optional extra queries (must be sanitized SQL clauses like ORDER BY, LIMIT).
	 * @param bool          $decode Optional whether to decode results.
	 * @since 0.0.1
	 * @return array<mixed> Results array.
	 */
	public function get_results( $where_clauses = [], $columns = '*', $extra_queries = [], $decode = true ) {
		$wpdb       = $this->wpdb;
		$table_name = $this->get_tablename();
		$select_sql = $this->prepare_select_columns( $columns );
		$where_sql  = $this->prepare_where_clauses( $where_clauses );

		$query  = 'SELECT ' . $select_sql['sql'] . ' FROM %i';
		$values = array_merge( $select_sql['values'], [ $table_name ], $where_sql['values'] );
		$query .= $where_sql['sql'];

		if ( ! empty( $extra_queries ) ) {
			$sanitized_extras = $this->sanitize_extra_queries( $extra_queries );
			if ( ! empty( $sanitized_extras ) ) {
				$query .= ' ' . implode( ' ', $sanitized_extras );
			}
		}

		$query = rtrim( trim( $query ), ';' ) . ';';
		// @phpstan-ignore-next-line argument.type -- Query is assembled from validated SQL fragments and placeholder strings.
		$query = $wpdb->prepare( $query, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared dynamically with validated identifiers and placeholders.

		if ( ! is_string( $query ) ) {
			return [];
		}

		$cached_results = $this->cache_get( $query );
		if ( $cached_results !== null ) {
			return is_array( $cached_results ) ? $cached_results : [];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query prepared above; extra_queries sanitized via sanitize_extra_queries() allowlist; caching handled via cache_get/cache_set.
		$results = $wpdb->get_results( $query, ARRAY_A );

		if ( $decode && ! empty( $results ) && is_array( $results ) ) {
			foreach ( $results as &$result ) {
				$result = $this->decode_by_datatype( $result );
			}
		}

		return is_array( $this->cache_set( $query, $results ) ) ? $this->cache_set( $query, $results ) : [];
	}

	/**
	 * Retrieve a cached value by its key.
	 *
	 * @param string $key The cache key.
	 * @since 0.0.2
	 * @return mixed|null The cached value or null.
	 */
	protected function cache_get( $key ) {
		$cache_key = $this->get_cache_key( (string) $key );

		if ( array_key_exists( $cache_key, $this->caches ) ) {
			return $this->caches[ $cache_key ];
		}

		$cached_value = wp_cache_get( $cache_key, $this->get_cache_group() );
		if ( $cached_value === false ) {
			return null;
		}

		$this->caches[ $cache_key ] = $cached_value;

		return $cached_value;
	}

	/**
	 * Store a value in the cache.
	 *
	 * @param string $key The cache key.
	 * @param mixed  $value The value to store.
	 * @since 0.0.2
	 * @return mixed The stored value.
	 */
	protected function cache_set( $key, $value ) {
		$cache_key                  = $this->get_cache_key( (string) $key );
		$this->caches[ $cache_key ] = $value;
		wp_cache_set( $cache_key, $value, $this->get_cache_group() );
		return $value;
	}

	/**
	 * Reset the cache.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	protected function cache_reset(): void {
		$this->caches = [];
		wp_cache_set( $this->get_cache_version_key(), $this->get_cache_version() + 1, $this->get_cache_group() );
	}

	/**
	 * Prepare WHERE clauses for a SQL query.
	 *
	 * @param array<mixed> $where_clauses WHERE conditions.
	 * @since 0.0.1
	 * @return array{sql: string, values: array<int, mixed>} The prepared WHERE clause data.
	 */
	protected function prepare_where_clauses( $where_clauses = [] ): array {
		if ( empty( $where_clauses ) ) {
			return [
				'sql'    => '',
				'values' => [],
			];
		}

		if ( is_array( $where_clauses ) ) {
			$where  = [];
			$values = [];
			$schema = $this->get_schema();

			foreach ( $where_clauses as $key => $value ) {
				if ( ! isset( $schema[ $key ] ) ) {
					continue;
				}

				$where[]  = '%i = ' . $this->get_format_by_datatype( $schema[ $key ]['type'] );
				$values[] = $key;
				$values[] = $value;
			}

			if ( empty( $where ) ) {
				return [
					'sql'    => '',
					'values' => [],
				];
			}

			return [
				'sql'    => ' WHERE ' . implode( ' AND ', $where ),
				'values' => $values,
			];
		}

		return [
			'sql'    => '',
			'values' => [],
		];
	}

	/**
	 * Sanitize extra query clauses to prevent SQL injection.
	 *
	 * Only allows safe SQL clauses: ORDER BY, LIMIT, OFFSET, ASC, DESC.
	 * Column names in ORDER BY are validated against the schema.
	 *
	 * @param array<string> $extra_queries Array of extra SQL clauses.
	 * @since 0.0.2
	 * @return array<string> Sanitized query clauses.
	 */
	protected function sanitize_extra_queries( array $extra_queries ): array {
		$sanitized = [];
		$schema    = $this->get_schema();

		foreach ( $extra_queries as $clause ) {
			$clause = trim( (string) $clause );

			// Skip empty clauses.
			if ( $clause === '' ) {
				continue;
			}

			// Handle ORDER BY clauses.
			if ( preg_match( '/^ORDER\s+BY\s+(.+)$/i', $clause, $matches ) ) {
				$order_parts     = array_map( 'trim', explode( ',', $matches[1] ) );
				$valid_orderings = [];

				foreach ( $order_parts as $order_part ) {
					// Match column name with optional ASC/DESC.
					if ( preg_match( '/^([a-z_][a-z0-9_]*)\s*(ASC|DESC)?$/i', $order_part, $order_match ) ) {
						$column    = sanitize_key( $order_match[1] );
						$direction = isset( $order_match[2] ) ? strtoupper( $order_match[2] ) : 'ASC';

						// Only allow columns from schema.
						if ( isset( $schema[ $column ] ) ) {
							$valid_orderings[] = esc_sql( $column ) . ' ' . $direction;
						}
					}
				}

				if ( ! empty( $valid_orderings ) ) {
					$sanitized[] = 'ORDER BY ' . implode( ', ', $valid_orderings );
				}
				continue;
			}

			// Handle LIMIT clauses.
			if ( preg_match( '/^LIMIT\s+(\d+)(?:\s*,\s*(\d+))?$/i', $clause, $matches ) ) {
				$sanitized[] = isset( $matches[2] )
					? 'LIMIT ' . absint( $matches[1] ) . ', ' . absint( $matches[2] )
					: 'LIMIT ' . absint( $matches[1] );
				continue;
			}

			// Handle OFFSET clauses.
			if ( preg_match( '/^OFFSET\s+(\d+)$/i', $clause, $matches ) ) {
				$sanitized[] = 'OFFSET ' . absint( $matches[1] );
				continue;
			}
		}

		return $sanitized;
	}

	/**
	 * Prepare and format data based on the schema.
	 *
	 * @param array<mixed> $data Data to prepare.
	 * @param bool         $skip_defaults Whether to skip defaults.
	 * @since 0.0.1
	 * @return array<array<mixed>> Prepared data and format.
	 */
	protected function prepare_data( $data, $skip_defaults = false ) {
		$_data  = [];
		$format = [];
		foreach ( $this->get_schema() as $key => $value ) {
			// Process defaults.
			if ( ! isset( $data[ $key ] ) ) {
				if ( $skip_defaults || ! isset( $value['default'] ) ) {
					continue;
				}
				$data[ $key ] = $value['default'];
			}

			$format[]      = $this->get_format_by_datatype( $value['type'] );
			$_data[ $key ] = $this->encode_by_datatype( $data[ $key ], $value['type'] );
		}
		return [
			'data'   => $_data,
			'format' => $format,
		];
	}

	/**
	 * Get the SQL format specifier based on data type.
	 *
	 * @param string $type The data type.
	 * @since 0.0.1
	 * @return string The SQL format specifier.
	 */
	protected function get_format_by_datatype( $type ) {
		switch ( $type ) {
			case 'string':
			case 'array':
				return '%s';
			case 'number':
			case 'boolean':
				return '%d';
			default:
				return '%s';
		}
	}

	/**
	 * Decode data based on schema data types.
	 *
	 * @param array<mixed> $data Data to decode.
	 * @since 0.0.1
	 * @return array<mixed> Decoded data.
	 */
	protected function decode_by_datatype( $data ) {
		$_data = [];
		foreach ( $this->get_schema() as $key => $schema ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			$_data[ $key ] = $schema['type'] === 'array' ? json_decode( $data[ $key ], true ) : $data[ $key ];
		}
		return $_data;
	}

	/**
	 * Encode a value based on data type.
	 *
	 * @param mixed  $value The value to encode.
	 * @param string $type The data type.
	 * @since 0.0.1
	 * @return mixed The encoded value.
	 */
	protected function encode_by_datatype( $value, $type ) {
		switch ( $type ) {
			case 'string':
				return (string) $value;
			case 'number':
				return (int) $value;
			case 'boolean':
				return (bool) $value;
			case 'array':
				return wp_json_encode( is_array( $value ) ? $value : [] );
			default:
				return $value;
		}
	}

	/**
	 * Get the object cache group used for database query results.
	 *
	 * @since 0.0.2
	 * @return string
	 */
	private function get_cache_group(): string {
		return 'surecookie_database';
	}

	/**
	 * Get the object cache key used to version this table's query cache.
	 *
	 * @since 0.0.2
	 * @return string
	 */
	private function get_cache_version_key(): string {
		// get_tablename() (not a cached value) keeps keys blog-specific under switch_to_blog().
		return 'table-version:' . md5( $this->get_tablename() );
	}

	/**
	 * Get the current object cache version for this table.
	 *
	 * @since 0.0.2
	 * @return int
	 */
	private function get_cache_version(): int {
		$version = wp_cache_get( $this->get_cache_version_key(), $this->get_cache_group() );

		if ( $version === false ) {
			$version = 1;
			wp_cache_set( $this->get_cache_version_key(), $version, $this->get_cache_group() );
		}

		return (int) $version;
	}

	/**
	 * Build a versioned cache key for a query.
	 *
	 * @param string $key Query string.
	 * @since 0.0.2
	 * @return string
	 */
	private function get_cache_key( string $key ): string {
		// get_tablename() carries the live blog prefix, so the in-request $caches memo
		// never serves one blog's rows to another after switch_to_blog() on this singleton.
		return md5( $this->get_tablename() . '|' . $this->get_cache_version() . '|' . $key );
	}

	/**
	 * Get all active blog IDs in multisite network.
	 *
	 * @since 0.0.1
	 * @return array<int> Array of blog IDs.
	 */
	private function get_active_blog_ids() {
		global $wpdb;

		$cache_key = 'network-active-blog-ids:' . md5( (string) $wpdb->blogs );
		$cached    = wp_cache_get( $cache_key, $this->get_cache_group() );

		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$blog_ids_query = $wpdb->prepare(
			'SELECT blog_id FROM %i WHERE archived = %d AND spam = %d AND deleted = %d',
			$wpdb->blogs,
			0,
			0,
			0
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_string( $blog_ids_query ) ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Query prepared above; result cached below.
		$blog_ids = $wpdb->get_col( $blog_ids_query );

		$blog_ids = is_array( $blog_ids ) ? array_map( 'intval', $blog_ids ) : [];
		wp_cache_set( $cache_key, $blog_ids, $this->get_cache_group() );

		return $blog_ids;
	}

	/**
	 * Run network-wide table creation for multisite.
	 *
	 * @param array<string> $columns Column definitions.
	 * @since 0.0.1
	 * @return bool True if all tables created successfully, false otherwise.
	 */
	private function network_create_tables( array $columns ) {
		$blog_ids = $this->get_active_blog_ids();

		if ( empty( $blog_ids ) ) {
			return false;
		}

		$results = [];
		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( intval( $blog_id ) );
			// get_tablename() is dynamic - it reads $wpdb->prefix, which
			// switch_to_blog() has already updated to the target blog.
			$results[] = $this->create_table( $this->get_tablename(), $columns );
			restore_current_blog();
		}

		return ! in_array( false, $results, true );
	}

	/**
	 * Prepare the selected columns clause.
	 *
	 * @param array<string>|string $columns Columns to select.
	 * @since 0.0.1
	 * @return array{sql: string, values: array<int, string>}
	 */
	private function prepare_select_columns( $columns ): array {
		if ( $columns === '*' ) {
			return [
				'sql'    => '*',
				'values' => [],
			];
		}

		$schema = $this->get_schema();
		$keys   = is_array( $columns ) ? $columns : explode( ',', (string) $columns );
		$valid  = [];

		foreach ( $keys as $column ) {
			$column = sanitize_key( trim( (string) $column ) );
			if ( isset( $schema[ $column ] ) ) {
				$valid[] = $column;
			}
		}

		if ( empty( $valid ) ) {
			return [
				'sql'    => '*',
				'values' => [],
			];
		}

		return [
			'sql'    => implode( ', ', array_fill( 0, count( $valid ), '%i' ) ),
			'values' => $valid,
		];
	}

	/**
	 * Create a database table using dbDelta.
	 *
	 * @param string        $table_name Table name.
	 * @param array<string> $columns    Column definitions.
	 * @since 0.0.1
	 * @return bool
	 */
	private function create_table( string $table_name, array $columns ): bool {
		if ( empty( $columns ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$columns_sql     = implode( ",\n", array_map( 'trim', $columns ) );
		$charset_collate = $this->get_charset_collate();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$create_table_query = "CREATE TABLE %i (\n{$columns_sql}\n) {$charset_collate}";
		// @phpstan-ignore-next-line argument.type -- Table DDL is assembled from internal column definitions and charset metadata.
		$create_table_sql = $this->wpdb->prepare( $create_table_query, $table_name ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table DDL is prepared from internal column definitions.
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( ! is_string( $create_table_sql ) ) {
			return false;
		}

		dbDelta( $create_table_sql );

		return $this->table_exists( $table_name );
	}

	/**
	 * Check whether a database table exists.
	 *
	 * @param string $table_name Table name.
	 * @since 0.0.1
	 * @return bool
	 */
	private function table_exists( string $table_name ): bool {
		$query = $this->wpdb->prepare(
			'SHOW TABLES LIKE %s',
			$this->wpdb->esc_like( $table_name )
		);

		if ( ! is_string( $query ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query prepared above.
		return (string) $this->wpdb->get_var( $query ) === $table_name;
	}
}
