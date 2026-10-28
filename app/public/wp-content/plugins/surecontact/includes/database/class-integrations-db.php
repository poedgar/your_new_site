<?php
/**
 * SureContact Integrations Database Access Class.
 *
 * Provides CRUD operations for integration configurations.
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
 * Integrations Database Access Class.
 *
 * @since 0.0.3
 */
class Integrations_DB {

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.3
	 *
	 * @var Integrations_DB|null
	 */
	private static $instance = null;

	/**
	 * WordPress database instance.
	 *
	 * @since 0.0.4
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Table name with prefix.
	 *
	 * @since 0.0.3
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Cached workspace UUID.
	 *
	 * @since 0.0.4
	 *
	 * @var string
	 */
	private $workspace_uuid;

	/**
	 * Get singleton instance.
	 *
	 * @since 0.0.3
	 *
	 * @return Integrations_DB
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Private to enforce singleton pattern.
	 *
	 * @since 0.0.3
	 */
	private function __construct() {
		global $wpdb;
		$this->wpdb           = $wpdb;
		$this->table_name     = $this->wpdb->prefix . 'surecontact_integrations';
		$this->workspace_uuid = get_option( 'surecontact_workspace_uuid', '' );
	}

	/**
	 * Get integration configuration.
	 *
	 * @since 0.0.3
	 *
	 * @param string      $name Integration slug (e.g., 'fluentcrm', 'woocommerce').
	 * @param string|null $item_id Item identifier (e.g., '123', '456') or NULL for global.
	 * @param string|null $item_type Item type (e.g., 'form', 'product') or NULL for global.
	 * @param string|null $event Event name (e.g., 'purchase', 'cancellation') or NULL for default.
	 * @return array|null Array with id, name, item_id, item_type, event, config, metadata, status, or null if not found.
	 */
	public function get( $name, $item_id = null, $item_type = null, $event = null ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		if ( is_null( $item_id ) && is_null( $event ) ) {
			// Global configuration (no item, no event).
			$result = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->table_name} WHERE workspace_uuid = %s AND name = %s AND item_id IS NULL AND event IS NULL ORDER BY id DESC LIMIT 1", // @phpstan-ignore argument.type
					$this->workspace_uuid,
					$name
				),
				ARRAY_A
			);
		} elseif ( ! is_null( $item_id ) && is_null( $event ) ) {
			// Item-specific configuration without event.
			// Use IS NULL when $item_type is null because $wpdb->prepare turns
			// null into '' with %s, which never matches a row stored with NULL.
			if ( is_null( $item_type ) ) {
				$result = $this->wpdb->get_row(
					$this->wpdb->prepare(
						"SELECT * FROM {$this->table_name} WHERE workspace_uuid = %s AND name = %s AND item_id = %s AND item_type IS NULL AND event IS NULL", // @phpstan-ignore argument.type
						$this->workspace_uuid,
						$name,
						$item_id
					),
					ARRAY_A
				);
			} else {
				$result = $this->wpdb->get_row(
					$this->wpdb->prepare(
						"SELECT * FROM {$this->table_name} WHERE workspace_uuid = %s AND name = %s AND item_id = %s AND item_type = %s AND event IS NULL", // @phpstan-ignore argument.type
						$this->workspace_uuid,
						$name,
						$item_id,
						$item_type
					),
					ARRAY_A
				);
			}
		} elseif ( ! is_null( $item_id ) && ! is_null( $event ) ) {
			// Item-specific configuration with event.
			// Same null/NULL handling for $item_type as the branch above.
			if ( is_null( $item_type ) ) {
				$result = $this->wpdb->get_row(
					$this->wpdb->prepare(
						"SELECT * FROM {$this->table_name} WHERE workspace_uuid = %s AND name = %s AND item_id = %s AND item_type IS NULL AND event = %s", // @phpstan-ignore argument.type
						$this->workspace_uuid,
						$name,
						$item_id,
						$event
					),
					ARRAY_A
				);
			} else {
				$result = $this->wpdb->get_row(
					$this->wpdb->prepare(
						"SELECT * FROM {$this->table_name} WHERE workspace_uuid = %s AND name = %s AND item_id = %s AND item_type = %s AND event = %s", // @phpstan-ignore argument.type
						$this->workspace_uuid,
						$name,
						$item_id,
						$item_type,
						$event
					),
					ARRAY_A
				);
			}
		} else {
			// Event without item (global event configuration).
			$result = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->table_name} WHERE workspace_uuid = %s AND name = %s AND item_id IS NULL AND event = %s ORDER BY id DESC LIMIT 1", // @phpstan-ignore argument.type
					$this->workspace_uuid,
					$name,
					$event
				),
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $result ) {
			return null;
		}

		// Decode JSON config.
		$result['config'] = json_decode( $result['config'], true );

		// Decode JSON metadata if present.
		if ( ! empty( $result['metadata'] ) ) {
			$result['metadata'] = json_decode( $result['metadata'], true );
		}

		return $result;
	}

	/**
	 * Get all integrations.
	 *
	 * @since 0.0.3
	 *
	 * @param string|null $name Optional. Filter by integration name.
	 * @return array Array of integration configurations.
	 */
	public function get_all( $name = null ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		if ( $name ) {
			$results = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->table_name} WHERE workspace_uuid = %s AND name = %s ORDER BY item_id IS NULL DESC, item_type ASC, item_id ASC", // @phpstan-ignore argument.type
					$this->workspace_uuid,
					$name
				),
				ARRAY_A
			);
		} else {
			$results = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->table_name} WHERE workspace_uuid = %s ORDER BY name ASC, item_id IS NULL DESC", // @phpstan-ignore argument.type
					$this->workspace_uuid
				),
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $results ) ) {
			return array();
		}

		foreach ( $results as &$result ) {
			$result['config'] = json_decode( $result['config'], true );
			// Decode JSON metadata if present.
			if ( ! empty( $result['metadata'] ) ) {
				$result['metadata'] = json_decode( $result['metadata'], true );
			}
		}

		return $results;
	}

	/**
	 * Get all enabled integrations (global only).
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of enabled integration slugs.
	 */
	public function get_all_enabled() {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT name FROM {$this->table_name} WHERE workspace_uuid = %s AND status = 1 AND item_id IS NULL ORDER BY name ASC", // @phpstan-ignore argument.type
				$this->workspace_uuid
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $results ) ) {
			return array();
		}

		return array_column( $results, 'name' );
	}

	/**
	 * Save integration configuration.
	 *
	 * Inserts new record or updates existing one.
	 *
	 * @since 0.0.3
	 *
	 * @param string            $name Integration slug.
	 * @param string|null       $item_id Item identifier or NULL for global.
	 * @param string|null       $item_type Item type or NULL for global.
	 * @param array|string      $config Configuration array or JSON string.
	 * @param int               $status 1 for enabled, 0 for disabled.
	 * @param string|null       $event Event name (e.g., 'purchase', 'cancellation') or NULL for default.
	 * @param array|string|null $metadata Metadata array or JSON string or NULL.
	 * @return int|false Number of rows affected, or false on error.
	 */
	public function save( $name, $item_id, $item_type, $config, $status = 1, $event = null, $metadata = null ) {
		// Convert config to JSON if it's an array.
		$config_json = is_array( $config ) ? wp_json_encode( $config ) : $config;

		// Convert metadata to JSON if it's an array.
		$metadata_json = null;
		if ( ! is_null( $metadata ) ) {
			$metadata_json = is_array( $metadata ) ? wp_json_encode( $metadata ) : $metadata;
		}

		// Check if record exists (only fetch id, not full record).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( is_null( $item_id ) && is_null( $event ) ) {
			$existing_id = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM {$this->table_name} WHERE workspace_uuid = %s AND name = %s AND item_id IS NULL AND item_type IS NULL AND event IS NULL ORDER BY id DESC LIMIT 1", // @phpstan-ignore argument.type
					$this->workspace_uuid,
					$name
				)
			);
		} elseif ( ! is_null( $item_id ) && is_null( $event ) ) {
			// Same null/NULL handling as get(): when $item_type is null we
			// must use IS NULL or the prior NULL-item_type row is invisible
			// and save() inserts a duplicate instead of updating.
			if ( is_null( $item_type ) ) {
				$existing_id = $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT id FROM {$this->table_name} WHERE workspace_uuid = %s AND name = %s AND item_id = %s AND item_type IS NULL AND event IS NULL ORDER BY id DESC LIMIT 1", // @phpstan-ignore argument.type
						$this->workspace_uuid,
						$name,
						$item_id
					)
				);
			} else {
				$existing_id = $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT id FROM {$this->table_name} WHERE workspace_uuid = %s AND name = %s AND item_id = %s AND item_type = %s AND event IS NULL ORDER BY id DESC LIMIT 1", // @phpstan-ignore argument.type
						$this->workspace_uuid,
						$name,
						$item_id,
						$item_type
					)
				);
			}
		} elseif ( ! is_null( $item_id ) && ! is_null( $event ) ) {
			if ( is_null( $item_type ) ) {
				$existing_id = $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT id FROM {$this->table_name} WHERE workspace_uuid = %s AND name = %s AND item_id = %s AND item_type IS NULL AND event = %s ORDER BY id DESC LIMIT 1", // @phpstan-ignore argument.type
						$this->workspace_uuid,
						$name,
						$item_id,
						$event
					)
				);
			} else {
				$existing_id = $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT id FROM {$this->table_name} WHERE workspace_uuid = %s AND name = %s AND item_id = %s AND item_type = %s AND event = %s ORDER BY id DESC LIMIT 1", // @phpstan-ignore argument.type
						$this->workspace_uuid,
						$name,
						$item_id,
						$item_type,
						$event
					)
				);
			}
		} else {
			$existing_id = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM {$this->table_name} WHERE workspace_uuid = %s AND name = %s AND item_id IS NULL AND item_type IS NULL AND event = %s ORDER BY id DESC LIMIT 1", // @phpstan-ignore argument.type
					$this->workspace_uuid,
					$name,
					$event
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing_id ) {
			// Update existing record.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $this->wpdb->update(
				$this->table_name,
				[
					'config'     => $config_json,
					'metadata'   => $metadata_json,
					'status'     => $status,
					'updated_at' => current_time( 'mysql' ),
				],
				[
					'id' => $existing_id,
				],
				[ '%s', '%s', '%d', '%s' ],
				[ '%d' ]
			);
		} else {
			// Insert new record.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $this->wpdb->insert(
				$this->table_name,
				[
					'workspace_uuid' => $this->workspace_uuid,
					'name'           => $name,
					'item_id'        => $item_id,
					'item_type'      => $item_type,
					'event'          => $event,
					'config'         => $config_json,
					'metadata'       => $metadata_json,
					'status'         => $status,
					'created_at'     => current_time( 'mysql' ),
					'updated_at'     => current_time( 'mysql' ),
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
			);
		}

		// Only fire the saved action when the underlying write actually succeeded.
		// $result === false on DB error; 0 means no rows changed (still a "save" — fire).
		if ( false !== $result ) {
			/**
			 * Fires after an integration's configuration is saved.
			 *
			 * Allows other components (e.g. Abandoned_Cart_Manager) to invalidate
			 * cached enabled-state checks.
			 *
			 * @since 1.5.0
			 *
			 * @param string $name Integration slug.
			 */
			do_action( 'surecontact_integration_settings_saved', $name );
		}

		return $result;
	}

	/**
	 * Delete integration configuration.
	 *
	 * @since 0.0.3
	 *
	 * @param string      $name Integration slug.
	 * @param string|null $item_id Item identifier or NULL for global.
	 * @param string|null $item_type Item type or NULL for global.
	 * @param string|null $event Event name or NULL for default.
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public function delete( $name, $item_id = null, $item_type = null, $event = null ) {
		// Build WHERE conditions and format array.
		$where        = array(
			'workspace_uuid' => $this->workspace_uuid,
			'name'           => $name,
		);
		$where_format = array( '%s', '%s' );

		// Handle item_id (can be null).
		if ( ! is_null( $item_id ) ) {
			$where['item_id'] = $item_id;
			$where_format[]   = '%s';
		} else {
			$where['item_id'] = null;
		}

		// Handle item_type (can be null).
		if ( ! is_null( $item_type ) ) {
			$where['item_type'] = $item_type;
			$where_format[]     = '%s';
		} else {
			$where['item_type'] = null;
		}

		// Handle event (can be null).
		if ( ! is_null( $event ) ) {
			$where['event'] = $event;
			$where_format[] = '%s';
		} else {
			$where['event'] = null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->delete( $this->table_name, $where, $where_format );
	}

	/**
	 * Update integration status only.
	 *
	 * @since 0.0.3
	 *
	 * @param string      $name Integration slug.
	 * @param string|null $item_id Item identifier or NULL for global.
	 * @param string|null $item_type Item type or NULL for global.
	 * @param int         $status 1 for enabled, 0 for disabled.
	 * @param string|null $event Event name or NULL for default.
	 * @return int|false Number of rows updated, or false on error.
	 */
	public function update_status( $name, $item_id, $item_type, $status, $event = null ) {
		// Build WHERE conditions and format array.
		$where        = array(
			'workspace_uuid' => $this->workspace_uuid,
			'name'           => $name,
		);
		$where_format = array( '%s', '%s' );

		// Handle item_id (can be null).
		if ( ! is_null( $item_id ) ) {
			$where['item_id'] = $item_id;
			$where_format[]   = '%s';
		} else {
			$where['item_id'] = null;
		}

		// Handle item_type (can be null).
		if ( ! is_null( $item_type ) ) {
			$where['item_type'] = $item_type;
			$where_format[]     = '%s';
		} else {
			$where['item_type'] = null;
		}

		// Handle event (can be null).
		if ( ! is_null( $event ) ) {
			$where['event'] = $event;
			$where_format[] = '%s';
		} else {
			$where['event'] = null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->update(
			$this->table_name,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			$where,
			array( '%d', '%s' ),
			$where_format
		);
	}

	/**
	 * Bulk delete all integration settings for the current workspace.
	 *
	 * Used when disconnecting/resetting all integrations.
	 *
	 * @since 0.0.3
	 *
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public function delete_all() {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->delete(
			$this->table_name,
			[ 'workspace_uuid' => $this->workspace_uuid ],
			[ '%s' ]
		);
	}

	/**
	 * Check if any item-level rules exist for a given integration.
	 *
	 * Returns true if the integration has at least one rule row with a non-null item_id
	 * (i.e., an actual configured rule, not just global settings).
	 *
	 * @since 1.2.0
	 *
	 * @param string $name Integration slug.
	 * @return bool True if at least one rule exists.
	 */
	public function has_rules( $name ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE workspace_uuid = %s AND name = %s AND item_id IS NOT NULL", // @phpstan-ignore argument.type
				$this->workspace_uuid,
				$name
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return (int) $count > 0;
	}

	/**
	 * Get structured state for a single integration, separating global config from item-level rules.
	 *
	 * @since 1.3.0
	 *
	 * @param string $name Integration slug.
	 * @return array Array with 'global_enabled', 'global_config', 'rules'.
	 */
	public function get_integration_state( $name ) {
		$records = $this->get_all( $name );

		$state = array(
			'global_enabled' => false,
			'global_config'  => array(),
			'rules'          => array(),
		);

		foreach ( $records as $record ) {
			if ( is_null( $record['item_id'] ) && is_null( $record['item_type'] ) && is_null( $record['event'] ) ) {
				$state['global_enabled'] = 1 === (int) $record['status'];
				$state['global_config']  = $record['config'] ?? array();
			} else {
				$state['rules'][] = array(
					'item_id'   => $record['item_id'],
					'item_type' => $record['item_type'],
					'event'     => $record['event'],
					'status'    => (int) $record['status'],
				);
			}
		}

		return $state;
	}

	/**
	 * Get structured state for all integrations, separating global config from item-level rules.
	 *
	 * Returns maps keyed by integration slug for efficient lookup.
	 *
	 * @since 1.3.0
	 *
	 * @return array Array with 'global_enabled' (slug => bool), 'rules_count' (slug => int).
	 */
	public function get_all_states() {
		$records = $this->get_all();

		$states = array(
			'global_enabled' => array(),
			'rules_count'    => array(),
		);

		foreach ( $records as $record ) {
			$slug = $record['name'];

			if ( is_null( $record['item_id'] ) && is_null( $record['item_type'] ) && is_null( $record['event'] ) ) {
				$states['global_enabled'][ $slug ] = 1 === (int) $record['status'];
			} else {
				if ( ! isset( $states['rules_count'][ $slug ] ) ) {
					$states['rules_count'][ $slug ] = 0;
				}
				++$states['rules_count'][ $slug ];
			}
		}

		return $states;
	}

	/**
	 * Get enabled integration slugs list.
	 *
	 * Returns integrations that have ANY enabled configuration (global or item-level).
	 * This ensures integrations are loaded if they have any active rules configured.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of integration slugs.
	 */
	public function get_enabled_slugs() {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT DISTINCT name FROM {$this->table_name} WHERE workspace_uuid = %s AND status = 1", // @phpstan-ignore argument.type
				$this->workspace_uuid
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $results ) ) {
			return array();
		}

		return array_column( $results, 'name' );
	}
}
