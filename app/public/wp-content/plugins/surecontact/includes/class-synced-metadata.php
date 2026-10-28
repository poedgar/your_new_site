<?php
/**
 * Synced Metadata Helper
 *
 * Manages all metadata synced from SureContact (lists, tags, custom fields, etc.)
 * Uses a single consolidated option key for better performance and scalability.
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
 * Class Synced_Metadata
 *
 * Centralized management for all metadata synced from SureContact.
 * Replaces individual option keys with a single consolidated option.
 *
 * @since 0.0.1
 */
class Synced_Metadata {

	/**
	 * Option key for storing all synced metadata
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	const OPTION_KEY = 'surecontact_synced_metadata';

	/**
	 * Get lists synced from SureContact
	 *
	 * @since 0.0.1
	 *
	 * @return array Array of lists
	 */
	public static function get_lists() {
		$metadata = get_option( self::OPTION_KEY, array() );
		return isset( $metadata['lists'] ) ? $metadata['lists'] : array();
	}

	/**
	 * Set lists synced from SureContact
	 *
	 * @since 0.0.1
	 *
	 * @param array $lists Array of lists to store.
	 * @return bool True on success, false on failure
	 */
	public static function set_lists( $lists ) {
		$metadata          = get_option( self::OPTION_KEY, array() );
		$metadata['lists'] = $lists;
		return update_option( self::OPTION_KEY, $metadata, false );
	}

	/**
	 * Get tags synced from SureContact
	 *
	 * @since 0.0.1
	 *
	 * @return array Array of tags
	 */
	public static function get_tags() {
		$metadata = get_option( self::OPTION_KEY, array() );
		return isset( $metadata['tags'] ) ? $metadata['tags'] : array();
	}

	/**
	 * Set tags synced from SureContact
	 *
	 * @since 0.0.1
	 *
	 * @param array $tags Array of tags to store.
	 * @return bool True on success, false on failure
	 */
	public static function set_tags( $tags ) {
		$metadata         = get_option( self::OPTION_KEY, array() );
		$metadata['tags'] = $tags;
		return update_option( self::OPTION_KEY, $metadata, false );
	}

	/**
	 * Get custom fields synced from SureContact
	 *
	 * @since 0.0.1
	 *
	 * @return array Array of custom fields
	 */
	public static function get_custom_fields() {
		$metadata = get_option( self::OPTION_KEY, array() );
		return isset( $metadata['custom_fields'] ) ? $metadata['custom_fields'] : array();
	}

	/**
	 * Set custom fields synced from SureContact
	 *
	 * @since 0.0.1
	 *
	 * @param array $custom_fields Array of custom fields to store.
	 * @return bool True on success, false on failure
	 */
	public static function set_custom_fields( $custom_fields ) {
		$metadata                  = get_option( self::OPTION_KEY, array() );
		$metadata['custom_fields'] = $custom_fields;
		return update_option( self::OPTION_KEY, $metadata, false );
	}

	/**
	 * Get any metadata by key (future-proof for new metadata types)
	 *
	 * @since 0.0.1
	 *
	 * @param string $key     Metadata key (e.g., 'lists', 'tags', 'custom_fields', 'statuses').
	 * @param mixed  $default_metadata Default value if key doesn't exist.
	 * @return mixed Metadata value or default
	 */
	public static function get( $key, $default_metadata = array() ) {
		$metadata = get_option( self::OPTION_KEY, array() );
		return isset( $metadata[ $key ] ) ? $metadata[ $key ] : $default_metadata;
	}

	/**
	 * Set any metadata by key (future-proof for new metadata types)
	 *
	 * @since 0.0.1
	 *
	 * @param string $key   Metadata key (e.g., 'lists', 'tags', 'custom_fields', 'statuses').
	 * @param mixed  $value Value to store.
	 * @return bool True on success, false on failure
	 */
	public static function set( $key, $value ) {
		$metadata         = get_option( self::OPTION_KEY, array() );
		$metadata[ $key ] = $value;
		return update_option( self::OPTION_KEY, $metadata, false );
	}

	/**
	 * Get all synced metadata
	 *
	 * @since 0.0.1
	 *
	 * @return array All metadata
	 */
	public static function get_all() {
		return get_option( self::OPTION_KEY, array() );
	}

	/**
	 * Clear all synced metadata
	 *
	 * @since 0.0.1
	 *
	 * @return bool True on success, false on failure
	 */
	public static function clear_all() {
		return delete_option( self::OPTION_KEY );
	}

	/**
	 * Clear specific metadata by key
	 *
	 * @since 0.0.1
	 *
	 * @param string $key Metadata key to clear.
	 * @return bool True on success, false on failure
	 */
	public static function clear( $key ) {
		$metadata = get_option( self::OPTION_KEY, array() );
		if ( isset( $metadata[ $key ] ) ) {
			unset( $metadata[ $key ] );
			return update_option( self::OPTION_KEY, $metadata, false );
		}
		return true;
	}
}
