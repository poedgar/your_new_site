<?php
/**
 * Integration Database Helper Trait
 *
 * Provides standardized methods for managing integration settings
 * in the custom database table (wp_surecontact_integrations).
 *
 * This trait eliminates the need for post_meta and ensures all
 * integration settings are stored in a single, queryable location.
 *
 * @since 0.0.3
 *
 * @package SureContact
 */

namespace SureContact\Traits;

use SureContact\Database\Integrations_DB;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Integration_DB_Helper
 *
 * Use this trait in any integration class to get standardized
 * database operations for both global and item-specific settings.
 *
 * @since 0.0.3
 */
trait Integration_DB_Helper {

	/**
	 * Get database instance
	 *
	 * @since 0.0.3
	 *
	 * @return Integrations_DB
	 */
	protected function get_db() {
		return Integrations_DB::get_instance();
	}

	/**
	 * Get item-specific settings from database
	 *
	 * Use this for form-specific, product-specific, or any other
	 * item-level configuration.
	 *
	 * @since 0.0.3
	 *
	 * @param string|int  $item_id Item identifier (e.g., form_123, product_456).
	 * @param string      $prefix  Optional prefix to add to item_id (e.g., 'form_', 'product_').
	 * @param string|null $event   Optional event name (e.g., 'purchase', 'cancellation').
	 * @return array Item settings
	 */
	protected function get_item_settings( $item_id, $prefix = '', $event = null ) {
		$item_name = $prefix . $item_id;
		$db        = $this->get_db();
		$result    = $db->get( $this->slug, $item_name, null, $event );

		if ( ! $result || empty( $result['config'] ) ) {
			return array();
		}

		return is_array( $result['config'] ) ? $result['config'] : array();
	}

	/**
	 * Save item-specific settings to database
	 *
	 * @since 0.0.3
	 *
	 * @param string|int  $item_id   Item identifier.
	 * @param array       $settings  Settings array.
	 * @param int         $status    1 for enabled, 0 for disabled.
	 * @param string      $prefix    Optional prefix to add to item_id.
	 * @param string|null $event     Optional event name (e.g., 'purchase', 'cancellation').
	 * @param string|null $item_type Optional item type (e.g., 'product', 'form').
	 * @param array|null  $metadata  Optional metadata array (e.g., title, notes).
	 * @return int|false Number of rows affected, or false on error.
	 */
	protected function save_item_settings( $item_id, $settings, $status = 1, $prefix = '', $event = null, $item_type = null, $metadata = null ) {
		$item_name = $prefix . $item_id;
		$db        = $this->get_db();

		return $db->save( $this->slug, $item_name, $item_type, $settings, $status, $event, $metadata );
	}

	/**
	 * Delete item-specific settings from database
	 *
	 * @since 0.0.3
	 *
	 * @param string|int  $item_id Item identifier.
	 * @param string      $prefix  Optional prefix to add to item_id.
	 * @param string|null $event   Optional event name (e.g., 'purchase', 'cancellation').
	 * @return int|false Number of rows deleted, or false on error.
	 */
	protected function delete_item_settings( $item_id, $prefix = '', $event = null ) {
		$item_name = $prefix . $item_id;
		$db        = $this->get_db();

		return $db->delete( $this->slug, $item_name, null, $event );
	}

	/**
	 * Check if item is enabled for sync
	 *
	 * @since 0.0.3
	 *
	 * @param string|int  $item_id Item identifier.
	 * @param string      $prefix  Optional prefix to add to item_id.
	 * @param string|null $event   Optional event name (e.g., 'purchase', 'cancellation').
	 * @return bool True if enabled, false otherwise.
	 */
	protected function is_item_enabled( $item_id, $prefix = '', $event = null ) {
		$item_name = $prefix . $item_id;
		$db        = $this->get_db();
		$result    = $db->get( $this->slug, $item_name, null, $event );

		if ( ! $result ) {
			return false;
		}

		return ! empty( $result['status'] );
	}

	/**
	 * Get all items for this integration
	 *
	 * Returns all item-specific configurations (excludes global settings).
	 *
	 * @since 0.0.3
	 *
	 * @param string $prefix Optional prefix filter (e.g., 'form_' to get only forms).
	 * @return array Array of item configurations.
	 */
	protected function get_all_items( $prefix = '' ) {
		$db      = $this->get_db();
		$results = $db->get_all( $this->slug );

		// Filter out global settings (item_id is NULL).
		$items = array();
		foreach ( $results as $result ) {
			if ( ! empty( $result['item_id'] ) ) {
				// If prefix is specified, filter by prefix.
				if ( ! empty( $prefix ) ) {
					if ( strpos( $result['item_id'], $prefix ) === 0 ) {
						$items[] = $result;
					}
				} else {
					$items[] = $result;
				}
			}
		}

		return $items;
	}

	/**
	 * Get enabled items for this integration
	 *
	 * Returns only enabled item-specific configurations.
	 *
	 * @since 0.0.3
	 *
	 * @param string $prefix Optional prefix filter.
	 * @return array Array of enabled item configurations.
	 */
	protected function get_enabled_items( $prefix = '' ) {
		$all_items = $this->get_all_items( $prefix );

		return array_filter(
			$all_items,
			function ( $item ) {
				return ! empty( $item['status'] );
			}
		);
	}

	/**
	 * Get item setting value
	 *
	 * Get a specific setting from item configuration with fallback.
	 *
	 * @since 0.0.3
	 *
	 * @param string|int  $item_id       Item identifier.
	 * @param string      $key           Setting key.
	 * @param mixed       $default_value Default value if not found.
	 * @param string      $prefix        Optional prefix to add to item_id.
	 * @param string|null $event         Optional event name (e.g., 'purchase', 'cancellation').
	 * @return mixed Setting value or default.
	 */
	protected function get_item_setting( $item_id, $key, $default_value = null, $prefix = '', $event = null ) {
		$settings = $this->get_item_settings( $item_id, $prefix, $event );

		if ( isset( $settings[ $key ] ) ) {
			return $settings[ $key ];
		}

		return $default_value;
	}

	/**
	 * Update a specific item setting
	 *
	 * Updates a single setting key without overwriting the entire config.
	 *
	 * @since 0.0.3
	 *
	 * @param string|int  $item_id Item identifier.
	 * @param string      $key     Setting key.
	 * @param mixed       $value   Setting value.
	 * @param string      $prefix  Optional prefix to add to item_id.
	 * @param string|null $event   Optional event name (e.g., 'purchase', 'cancellation').
	 * @return int|false Number of rows affected, or false on error.
	 */
	protected function update_item_setting( $item_id, $key, $value, $prefix = '', $event = null ) {
		$settings         = $this->get_item_settings( $item_id, $prefix, $event );
		$settings[ $key ] = $value;

		// Preserve existing status.
		$is_enabled = $this->is_item_enabled( $item_id, $prefix, $event );
		$status     = $is_enabled ? 1 : 0;

		return $this->save_item_settings( $item_id, $settings, $status, $prefix, $event );
	}

	/**
	 * Enable/disable an item
	 *
	 * @since 0.0.3
	 *
	 * @param string|int  $item_id   Item identifier.
	 * @param bool        $enabled   True to enable, false to disable.
	 * @param string      $prefix    Optional prefix to add to item_id.
	 * @param string|null $event     Optional event name (e.g., 'purchase', 'cancellation').
	 * @param string|null $item_type Optional item type (e.g., 'product', 'form').
	 * @return int|false Number of rows affected, or false on error.
	 */
	protected function set_item_status( $item_id, $enabled, $prefix = '', $event = null, $item_type = null ) {
		$item_name = $prefix . $item_id;
		$db        = $this->get_db();
		$status    = $enabled ? 1 : 0;

		return $db->update_status( $this->slug, $item_name, $item_type, $status, $event );
	}

	/**
	 * Bulk save multiple items
	 *
	 * Useful for bulk imports or syncing.
	 *
	 * @since 0.0.3
	 *
	 * @param array  $items  Array of items with 'id' and 'settings' keys.
	 * @param string $prefix Optional prefix to add to item IDs.
	 * @return int Number of items saved.
	 */
	protected function bulk_save_items( $items, $prefix = '' ) {
		$count = 0;

		foreach ( $items as $item ) {
			if ( ! isset( $item['id'] ) || ! isset( $item['settings'] ) ) {
				continue;
			}

			$status = isset( $item['status'] ) ? (int) $item['status'] : 1;
			$result = $this->save_item_settings( $item['id'], $item['settings'], $status, $prefix );

			if ( $result !== false ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Get item metadata
	 *
	 * Retrieves metadata for an item (e.g., title, notes, custom attributes).
	 *
	 * @since 0.0.3
	 *
	 * @param string|int  $item_id Item identifier.
	 * @param string      $prefix  Optional prefix to add to item_id.
	 * @param string|null $event   Optional event name (e.g., 'purchase', 'cancellation').
	 * @return array Item metadata array or empty array if not found.
	 */
	protected function get_item_metadata( $item_id, $prefix = '', $event = null ) {
		$item_name = $prefix . $item_id;
		$db        = $this->get_db();
		$result    = $db->get( $this->slug, $item_name, null, $event );

		if ( ! $result || empty( $result['metadata'] ) ) {
			return array();
		}

		return is_array( $result['metadata'] ) ? $result['metadata'] : array();
	}

	/**
	 * Get a specific metadata field value
	 *
	 * Retrieves a single metadata field with optional default value.
	 *
	 * @since 0.0.3
	 *
	 * @param string|int  $item_id       Item identifier.
	 * @param string      $key           Metadata key (e.g., 'title', 'notes').
	 * @param mixed       $default_value Default value if not found.
	 * @param string      $prefix        Optional prefix to add to item_id.
	 * @param string|null $event         Optional event name (e.g., 'purchase', 'cancellation').
	 * @return mixed Metadata value or default.
	 */
	protected function get_item_metadata_field( $item_id, $key, $default_value = null, $prefix = '', $event = null ) {
		$metadata = $this->get_item_metadata( $item_id, $prefix, $event );

		if ( isset( $metadata[ $key ] ) ) {
			return $metadata[ $key ];
		}

		return $default_value;
	}

	/**
	 * Update item metadata
	 *
	 * Updates or sets metadata for an item without affecting settings.
	 * This merges with existing metadata if present.
	 *
	 * @since 0.0.3
	 *
	 * @param string|int  $item_id   Item identifier.
	 * @param array       $metadata  Metadata array to save or merge.
	 * @param bool        $merge     Whether to merge with existing metadata (default: true).
	 * @param string      $prefix    Optional prefix to add to item_id.
	 * @param string|null $event     Optional event name (e.g., 'purchase', 'cancellation').
	 * @param string|null $item_type Optional item type (e.g., 'product', 'form').
	 * @return int|false Number of rows affected, or false on error.
	 */
	protected function update_item_metadata( $item_id, $metadata, $merge = true, $prefix = '', $event = null, $item_type = null ) {
		$item_name = $prefix . $item_id;
		$db        = $this->get_db();

		// Get existing record to preserve settings and status.
		$existing = $db->get( $this->slug, $item_name, $item_type, $event );

		if ( ! $existing ) {
			// If no existing record, create one with empty settings.
			return $db->save( $this->slug, $item_name, $item_type, array(), 1, $event, $metadata );
		}

		// Merge or replace metadata.
		$final_metadata = $metadata;
		if ( $merge && ! empty( $existing['metadata'] ) ) {
			$final_metadata = array_merge( $existing['metadata'], $metadata );
		}

		// Save with existing settings and status preserved.
		$settings = ! empty( $existing['config'] ) ? $existing['config'] : array();
		$status   = isset( $existing['status'] ) ? (int) $existing['status'] : 1;

		return $db->save( $this->slug, $item_name, $item_type, $settings, $status, $event, $final_metadata );
	}

	/**
	 * Update a specific metadata field
	 *
	 * Updates a single metadata field without affecting other metadata.
	 *
	 * @since 0.0.3
	 *
	 * @param string|int  $item_id   Item identifier.
	 * @param string      $key       Metadata key (e.g., 'title', 'notes').
	 * @param mixed       $value     Metadata value.
	 * @param string      $prefix    Optional prefix to add to item_id.
	 * @param string|null $event     Optional event name (e.g., 'purchase', 'cancellation').
	 * @param string|null $item_type Optional item type (e.g., 'product', 'form').
	 * @return int|false Number of rows affected, or false on error.
	 */
	protected function update_item_metadata_field( $item_id, $key, $value, $prefix = '', $event = null, $item_type = null ) {
		return $this->update_item_metadata( $item_id, array( $key => $value ), true, $prefix, $event, $item_type );
	}

	/**
	 * Get item settings with rule processing
	 *
	 * Returns settings with evaluated rules based on provided data context.
	 * Use this when you need to determine lists, tags, and custom fields
	 * based on conditional rules.
	 *
	 * @since 0.0.3
	 *
	 * @param string|int  $item_id Item identifier.
	 * @param array       $data    Data context for rule evaluation.
	 * @param string      $prefix  Optional prefix to add to item_id.
	 * @param string|null $event   Optional event name (e.g., 'purchase', 'cancellation').
	 * @return array Processed settings with resolved lists, tags, custom_fields.
	 */
	protected function get_item_settings_with_rules( $item_id, $data = array(), $prefix = '', $event = null ) {
		$settings = $this->get_item_settings( $item_id, $prefix, $event );

		if ( empty( $settings ) ) {
			return array();
		}

		// Start with default assignments.
		$lists         = isset( $settings['default_lists'] ) ? $settings['default_lists'] : array();
		$tags          = isset( $settings['default_tags'] ) ? $settings['default_tags'] : array();
		$custom_fields = isset( $settings['default_custom_fields'] ) ? $settings['default_custom_fields'] : array();

		// Process rules if present.
		if ( ! empty( $settings['rules'] ) && is_array( $settings['rules'] ) ) {
			foreach ( $settings['rules'] as $rule ) {
				if ( $this->evaluate_rule( $rule, $data ) ) {
					// Merge lists.
					if ( ! empty( $rule['actions']['add_lists'] ) ) {
						$lists = array_merge( $lists, $rule['actions']['add_lists'] );
					}

					// Merge tags.
					if ( ! empty( $rule['actions']['add_tags'] ) ) {
						$tags = array_merge( $tags, $rule['actions']['add_tags'] );
					}

					// Merge custom fields.
					if ( ! empty( $rule['actions']['set_custom_fields'] ) ) {
						$custom_fields = array_merge( $custom_fields, $rule['actions']['set_custom_fields'] );
					}
				}
			}
		}

		// Return settings with processed rules.
		return array_merge(
			$settings,
			array(
				'resolved_lists'         => array_values( array_unique( $lists ) ),
				'resolved_tags'          => array_values( array_unique( $tags ) ),
				'resolved_custom_fields' => $custom_fields,
			)
		);
	}

	/**
	 * Evaluate a rule against provided data
	 *
	 * @since 0.0.3
	 *
	 * @param array $rule Rule configuration with 'conditions' and 'actions'.
	 * @param array $data Data context to evaluate against.
	 * @return bool True if rule conditions are met, false otherwise.
	 */
	protected function evaluate_rule( $rule, $data ) {
		if ( empty( $rule['conditions'] ) || ! is_array( $rule['conditions'] ) ) {
			return false;
		}

		$conditions = $rule['conditions'];
		$match_type = isset( $conditions['match_type'] ) ? $conditions['match_type'] : 'all';
		$items      = isset( $conditions['items'] ) ? $conditions['items'] : array();
		$results    = array();

		// Evaluate each condition.
		foreach ( $items as $condition ) {
			$field    = isset( $condition['field'] ) ? $condition['field'] : '';
			$operator = isset( $condition['operator'] ) ? $condition['operator'] : 'equals';
			$value    = isset( $condition['value'] ) ? $condition['value'] : '';

			// Get field value from data.
			$field_value = isset( $data[ $field ] ) ? $data[ $field ] : null;

			// Evaluate condition.
			$results[] = $this->evaluate_condition( $field_value, $operator, $value );
		}

		// Apply match type.
		if ( $match_type === 'any' ) {
			return in_array( true, $results, true );
		}

		// Default: 'all' - all conditions must be true.
		return ! in_array( false, $results, true ) && count( $results ) > 0;
	}

	/**
	 * Evaluate a single condition
	 *
	 * @since 0.0.3
	 *
	 * @param mixed  $field_value Actual field value from data.
	 * @param string $operator    Comparison operator.
	 * @param mixed  $value       Expected value from condition.
	 * @return bool True if condition is met, false otherwise.
	 */
	protected function evaluate_condition( $field_value, $operator, $value ) {
		switch ( $operator ) {
			case 'equals':
				return $field_value === $value;

			case 'not_equals':
				return $field_value !== $value;

			case 'contains':
				return is_string( $field_value ) && stripos( $field_value, $value ) !== false;

			case 'not_contains':
				return is_string( $field_value ) && stripos( $field_value, $value ) === false;

			case 'starts_with':
				return is_string( $field_value ) && stripos( $field_value, $value ) === 0;

			case 'ends_with':
				return is_string( $field_value ) && substr( $field_value, -strlen( $value ) ) === $value;

			case 'greater_than':
				return is_numeric( $field_value ) && (float) $field_value > (float) $value;

			case 'less_than':
				return is_numeric( $field_value ) && (float) $field_value < (float) $value;

			case 'greater_than_or_equal':
				return is_numeric( $field_value ) && (float) $field_value >= (float) $value;

			case 'less_than_or_equal':
				return is_numeric( $field_value ) && (float) $field_value <= (float) $value;

			case 'in':
				// Value should be an array for 'in' operator.
				if ( is_array( $value ) ) {
					return in_array( $field_value, $value, true );
				}
				return false;

			case 'not_in':
				// Value should be an array for 'not_in' operator.
				if ( is_array( $value ) ) {
					return ! in_array( $field_value, $value, true );
				}
				return true;

			case 'is_empty':
				return empty( $field_value );

			case 'is_not_empty':
				return ! empty( $field_value );

			default:
				return false;
		}
	}
}
