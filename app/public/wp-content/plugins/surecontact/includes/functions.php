<?php
/**
 * Global Helper Functions
 *
 * Contains global helper functions for the SureContact plugin.
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get SureContact plugin instance
 *
 * Helper function to access the main plugin instance.
 *
 * @since 0.0.1
 *
 * @return SureContact Main plugin instance.
 */
function surecontact() {
	return $GLOBALS['surecontact'];
}

/**
 * Format date consistently across the application
 *
 * Formats date strings in a consistent format: MMM DD, YYYY HH:MM AM/PM
 * Example: Mar 27, 2025 11:25 PM
 *
 * @since 0.0.2
 *
 * @param string $date_string MySQL datetime string or any valid date string.
 * @return string Formatted date string.
 */
function surecontact_format_date( $date_string ) {
	if ( empty( $date_string ) ) {
		return '';
	}

	$timestamp = is_numeric( $date_string ) ? $date_string : strtotime( $date_string );

	if ( ! $timestamp ) {
		return '';
	}

	// Format: MMM DD, YYYY HH:MM AM/PM.
	return gmdate( 'M d, Y g:i A', (int) ( $timestamp + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) );
}

/**
 * Format MySQL datetime for REST API responses (ISO 8601 with timezone)
 *
 * Converts MySQL datetime to ISO 8601 format with timezone offset.
 * This follows WordPress core REST API standards (same as post dates) and
 * ensures dates work correctly for users in any timezone globally.
 *
 * Examples:
 * - Input:  "2025-12-02 14:30:00" (MySQL format in site timezone)
 * - Output: "2025-12-02T14:30:00+05:30" (ISO 8601 with timezone)
 *
 * @since 0.0.2
 *
 * @param string $mysql_date MySQL datetime string in site timezone.
 * @return string|null ISO 8601 formatted date with timezone, or null if empty.
 */
function surecontact_format_date_for_api( $mysql_date ) {
	if ( empty( $mysql_date ) ) {
		return null;
	}

	try {
		// Create DateTime object in WordPress site timezone.
		$date = new DateTime( $mysql_date, wp_timezone() );

		// Format as ISO 8601 with timezone (RFC 3339).
		// Example: "2025-12-02T14:30:00+05:30".
		return $date->format( 'c' );
	} catch ( Exception $e ) {
		// Return null if date parsing fails.
		return null;
	}
}
