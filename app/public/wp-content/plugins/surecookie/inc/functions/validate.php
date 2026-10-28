<?php
/**
 * Validate
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Functions;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Validate
 * This class contains functions to validate the given value.
 *
 * @since 1.0.0
 */
class Validate {
	/**
	 * This function checks if the given value is a string or not.
	 * If it is a string, it returns the string, else it returns the default value.
	 *
	 * @param mixed  $value The value to check.
	 * @param string $default Default value to return if the given value is not a string.
	 * @since 0.0.1
	 * @return string
	 */
	public static function string( $value, $default = '' ) {
		return is_string( $value ) ? $value : $default;
	}

	/**
	 * This function checks if the given value is empty or not.
	 * If it is not empty, it returns the value, else it returns the default value.
	 *
	 * @param mixed  $value The value to check.
	 * @param string $default Default value to return if the given value is not a string.
	 * @since 0.0.1
	 * @return string
	 */
	public static function not_empty( $value, $default = '' ) {
		return ! empty( $value ) ? $value : $default;
	}

	/**
	 * This function checks if the given value is an array or not.
	 * If it is an array, it returns the array, else it returns the default value.
	 *
	 * @param mixed                                                 $value The value to check.
	 * @param array<string, mixed>|array<int, array<string, mixed>> $default Default value to return if the given value is not an array.
	 * @since 0.0.1
	 * @return array<string, mixed>|array<int, array<string, mixed>>
	 */
	public static function array( $value, $default = [] ) {
		return is_array( $value ) ? $value : $default;
	}

	/**
	 * This function checks if the given value is empty string or not.
	 * It will only return false if the value is an empty string. Not for other falsy values like 0, [], etc.
	 *
	 * @param mixed $value The value to check.
	 * @since 0.0.1
	 * @return bool
	 */
	public static function empty_string( $value ) {
		if ( is_array( $value ) ) {
			return empty( $value );
		}

		if ( is_bool( $value ) ) {
			return false; // Booleans are not considered empty strings.
		}

		// Check if it's a string and if it's empty after trimming.
		if ( is_string( $value ) ) {
			return trim( $value ) === '';
		}

		// For other types (numbers, objects, etc.), check if empty.
		return empty( $value );
	}

	/**
	 * This function checks if the given value is null or not.
	 *
	 * @param mixed $value The value to check.
	 * @since 0.0.1
	 * @return bool
	 */
	public static function is_null( $value ) {
		return is_null( $value );
	}
}
