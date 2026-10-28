<?php
/**
 * Simple Field Formatter
 *
 * Basic field formatting for form submissions
 *
 * HOW IT WORKS:
 * 1. Each form integration processes its data differently
 * 2. They extract field values using get_submission_field_value()
 * 3. They map form fields to CRM fields using field_mapping array
 * 4. format_field_mapping_data() applies basic formatting based on CRM field names
 * 5. Data is sent to CRM in proper format
 *
 * FORMATTING RULES:
 * - Email fields: sanitized with sanitize_email()
 * - Phone fields: numbers only, keep + for international
 * - Date fields: converted to Y-m-d H:i:s format if possible
 * - Other fields: basic text sanitization
 *
 * @since 0.0.3
 *
 * @package SureContact
 */

namespace SureContact;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Field_Formatter
 *
 * Simple formatting for common field types
 *
 * @since 0.0.3
 */
class Field_Formatter {

	/**
	 * Get all supported CRM field types
	 *
	 * Single source of truth for field types supported by SureContact CRM.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of field type names
	 */
	public static function get_supported_field_types() {
		return array(
			'text',
			'textarea',
			'number',
			'decimal',
			'email',
			'phone',
			'url',
			'date',
			'datetime',
			'timestamp',
			'select',
			'multi_select',
			'checkbox',
			'boolean',
		);
	}

	/**
	 * Format field value based on field name patterns
	 *
	 * @since 0.0.3
	 * @since 1.5.5 Accepts the source date format for name-matched date fields.
	 *
	 * @param mixed  $value         Raw field value.
	 * @param string $field_name    Field name.
	 * @param string $source_format Optional PHP date format the value was submitted in (e.g. 'd/m/Y').
	 * @return mixed Formatted value
	 */
	public static function format_field_value( $value, $field_name, $source_format = '' ) {
		// Preserve boolean values (for checkbox fields).
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( empty( $value ) ) {
			return $value;
		}

		// If value is already an array, sanitize it as an array.
		if ( is_array( $value ) ) {
			return self::format_array_value( $value );
		}

		$field_name_lower = strtolower( $field_name );

		if ( strpos( $field_name_lower, 'email' ) !== false ) {
			return sanitize_email( $value );
		}

		if ( strpos( $field_name_lower, 'url' ) !== false || strpos( $field_name_lower, 'website' ) !== false || strpos( $field_name_lower, 'link' ) !== false ) {
			return esc_url_raw( $value );
		}

		if ( strpos( $field_name_lower, 'phone' ) !== false || strpos( $field_name_lower, 'mobile' ) !== false ) {
			return self::format_phone( $value );
		}

		if ( strpos( $field_name_lower, 'date' ) !== false || strpos( $field_name_lower, 'birth' ) !== false ) {
			return self::format_date( $value, $source_format );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Format value based on field type (for global field mapping)
	 *
	 * @since 0.0.3
	 * @since 1.5.5 Accepts the source date format for date, datetime and timestamp fields.
	 *
	 * @param mixed  $value         Raw value.
	 * @param string $field_type    Field type.
	 * @param string $field_key     Optional field key for special handling.
	 * @param string $source_format Optional PHP date format the value was submitted in (e.g. 'd/m/Y').
	 * @return mixed Formatted value
	 */
	public static function format_value_by_type( $value, $field_type, $field_key = '', $source_format = '' ) {
		// Some integrations (e.g., SureForms, WPForms) send single-value fields as arrays.
		// Normalize indexed arrays (list of values) to a scalar string.
		// Preserve: multiselect arrays (expected), associative arrays (non-numeric keys, e.g. Carbon datetime).
		if ( is_array( $value ) && array_values( $value ) === $value && ! in_array( $field_type, array( 'multiselect', 'multi_select' ), true ) ) {
			$value = array_values(
				array_filter(
					$value,
					function ( $v ) {
						return is_string( $v ) && '' !== $v;
					}
				)
			);
			if ( empty( $value ) ) {
				$value = '';
			} elseif ( 1 === count( $value ) || ! in_array( $field_type, array( 'text', 'textarea' ), true ) ) {
				$value = (string) reset( $value );
			} else {
				$value = implode( ', ', $value );
			}
		}

		switch ( $field_type ) {
			case 'date':
				return self::format_date( $value, $source_format );

			case 'datetime':
			case 'timestamp':
				return self::format_datetime( $value, $source_format );

			case 'email':
				return self::format_email( $value );

			case 'url':
				return esc_url_raw( $value );

			case 'phone':
				return self::format_phone( $value );

			case 'select':
				// Special handling for gender field.
				if ( 'gender' === $field_key ) {
					return self::validate_gender( $value );
				}
				return sanitize_text_field( $value );

			case 'multiselect':
			case 'multi_select':
				return self::format_array_value( $value );

			case 'checkbox':
			case 'boolean':
				return self::format_boolean_value( $value );

			case 'number':
			case 'integer':
				return is_numeric( $value ) ? (int) $value : 0;

			case 'decimal':
			case 'float':
				return is_numeric( $value ) ? (float) $value : 0.0;

			case 'textarea':
				return sanitize_textarea_field( $value );

			case 'text':
			default:
				// Special handling for prefix/suffix fields (max 20 chars).
				if ( 'prefix' === $field_key || 'suffix' === $field_key ) {
					return self::validate_text_length( $value, 20 );
				}
				// Special handling for primary fields with max length validation.
				if ( 'first_name' === $field_key || 'last_name' === $field_key || 'company' === $field_key || 'job_title' === $field_key ) {
					return self::validate_text_length( $value, 255 );
				}
				if ( 'phone' === $field_key ) {
					return self::validate_text_length( self::format_phone( $value ), 50 );
				}
				// Special handling for language field (2-letter ISO 639-1 code).
				if ( 'language' === $field_key ) {
					return self::format_language( $value );
				}
				// Special handling for timezone field (IANA format).
				if ( 'timezone' === $field_key ) {
					return self::format_timezone( $value );
				}
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Sanitize email address
	 *
	 * Passes email through with basic sanitization, letting the backend
	 * handle validation and return appropriate error messages.
	 *
	 * @since 0.0.3
	 *
	 * @param string $email Email address.
	 * @return string Sanitized email
	 */
	private static function format_email( $email ) {
		return is_email( $email ) ? $email : sanitize_email( $email );
	}

	/**
	 * Format phone number - remove non-numeric except +
	 *
	 * @since 0.0.3
	 *
	 * @param string $phone Phone number.
	 * @return string Formatted phone
	 */
	private static function format_phone( $phone ) {
		$result = preg_replace( '/[^0-9+]/', '', $phone );
		return $result ?? '';
	}

	/**
	 * Format date to Y-m-d (YYYY-MM-DD) format
	 *
	 * @since 0.0.3
	 * @since 1.5.5 Accepts the source format so slash-separated dates are no longer guessed as m/d/Y.
	 *
	 * @param string $date          Date string.
	 * @param string $source_format Optional PHP date format the value was submitted in (e.g. 'd/m/Y').
	 * @return string Formatted date, or empty string when the value cannot be resolved
	 */
	private static function format_date( $date, $source_format = '' ) {
		// Carbon/DateTime objects and their serialized array form reach date fields
		// too (FluentCRM, Eloquent); format_datetime() already unwraps them.
		if ( is_object( $date ) || is_array( $date ) ) {
			$date = self::format_datetime( $date );
		}

		$date = is_scalar( $date ) ? trim( (string) $date ) : '';

		if ( '' === $date ) {
			return '';
		}

		// If already in date format (YYYY-MM-DD), return as-is.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}

		// If already in datetime format, extract just the date part.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date ) ) {
			return substr( $date, 0, 10 );
		}

		// Parse using the format the source field declared, when the integration knows it.
		$declared = self::parse_with_source_format( $date, $source_format );
		if ( $declared instanceof \DateTimeImmutable ) {
			return $declared->format( 'Y-m-d' );
		}

		// Resolve numeric dates here: strtotime() reads every slash-separated date as
		// American m/d/Y, which transposes day and month for dd/mm/yyyy input.
		$numeric = self::parse_numeric_date( $date );
		if ( '' !== $numeric ) {
			return $numeric;
		}

		// Textual dates ('January 15, 2025') and ISO 8601 are unambiguous to strtotime().
		$timestamp = strtotime( $date );
		if ( $timestamp ) {
			return gmdate( 'Y-m-d', $timestamp );
		}

		// Dropping the value keeps the existing date intact; passing a non-ISO string
		// through would write it into a date column as-is.
		Logger::warning(
			'Field Formatter',
			'Unable to resolve date value - omitting it from the payload.',
			array(
				'value'         => $date,
				'source_format' => $source_format,
			)
		);

		return '';
	}

	/**
	 * Parse a value using the format its source field declared
	 *
	 * Strict on purpose: DateTime::createFromFormat() otherwise rolls invalid dates
	 * over silently, turning 25/13/2026 into 2027-01-25.
	 *
	 * @since 1.5.5
	 *
	 * @param string $value         Raw value.
	 * @param string $source_format PHP date format (e.g. 'd/m/Y').
	 * @return \DateTimeImmutable|null Parsed date, or null when the value does not match
	 */
	private static function parse_with_source_format( $value, $source_format ) {
		if ( ! is_string( $source_format ) || '' === $source_format ) {
			return null;
		}

		$parsed = self::strict_create_from_format( $source_format, $value );
		if ( $parsed instanceof \DateTimeImmutable ) {
			return $parsed;
		}

		// The picker can emit a different separator than the format declares, so
		// retry once with both normalised.
		$separators = array( '-', '.' );

		return self::strict_create_from_format(
			str_replace( $separators, '/', $source_format ),
			str_replace( $separators, '/', $value )
		);
	}

	/**
	 * Create a date from a format, rejecting anything PHP had to correct
	 *
	 * @since 1.5.5
	 *
	 * @param string $format PHP date format.
	 * @param string $value  Raw value.
	 * @return \DateTimeImmutable|null Parsed date, or null on any warning or error
	 */
	private static function strict_create_from_format( $format, $value ) {
		$parsed = \DateTimeImmutable::createFromFormat( '!' . $format, $value );
		if ( ! $parsed instanceof \DateTimeImmutable ) {
			return null;
		}

		$errors = \DateTimeImmutable::getLastErrors();
		if ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) {
			return null;
		}

		return $parsed;
	}

	/**
	 * Resolve a purely numeric date (07/09/2026, 07-09-2026, 2026/09/07) to Y-m-d
	 *
	 * Where one component is above 12 the order is unambiguous and resolved here.
	 * Where it is genuinely ambiguous PHP's own separator convention is kept, so
	 * existing m/d/Y forms are unaffected - integrations should declare a source
	 * format to remove the guess entirely.
	 *
	 * @since 1.5.5
	 *
	 * @param string $date Date string.
	 * @return string Y-m-d date, or empty string when this is not a resolvable numeric date
	 */
	private static function parse_numeric_date( $date ) {
		if ( ! preg_match( '#^(\d{1,4})([/.\-])(\d{1,2})\2(\d{1,4})$#', $date, $matches ) ) {
			return '';
		}

		$first     = (int) $matches[1];
		$separator = $matches[2];
		$middle    = (int) $matches[3];
		$last      = (int) $matches[4];

		// Year first (YYYY-MM-DD, YYYY/MM/DD) is unambiguous.
		if ( 4 === strlen( $matches[1] ) ) {
			return self::build_date( $first, $middle, $last );
		}

		// Two digit years stay with strtotime() rather than guessing a century.
		if ( 4 !== strlen( $matches[4] ) ) {
			return '';
		}

		// Only one of the two leading components can be a month. Where both are
		// ambiguous, keep PHP's own convention: '/' reads month first, '-' and
		// '.' read day first. build_date() rejects whatever is left impossible.
		$month_first = $middle > 12 || ( $first <= 12 && '/' === $separator );

		return $month_first
			? self::build_date( $last, $first, $middle )
			: self::build_date( $last, $middle, $first );
	}

	/**
	 * Build a Y-m-d string from date parts
	 *
	 * @since 1.5.5
	 *
	 * @param int $year  Year.
	 * @param int $month Month.
	 * @param int $day   Day.
	 * @return string Y-m-d date, or empty string when the parts are not a real date
	 */
	private static function build_date( $year, $month, $day ) {
		if ( ! checkdate( $month, $day, $year ) ) {
			return '';
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * Format datetime to Y-m-d H:i:s format
	 *
	 * @since 0.0.3
	 * @since 1.5.5 Accepts the source format so slash-separated dates are no longer guessed as m/d/Y.
	 *
	 * @param string $datetime      Datetime string.
	 * @param string $source_format Optional PHP date format the value was submitted in (e.g. 'd/m/Y H:i').
	 * @return string Formatted datetime, or empty string when a string value cannot be resolved
	 */
	private static function format_datetime( $datetime, $source_format = '' ) {
		// Handle null or empty values.
		if ( $datetime === null || $datetime === '' ) {
			return $datetime;
		}

		// Handle Carbon/DateTime objects (FluentCRM, Laravel Eloquent).
		if ( is_object( $datetime ) ) {
			// Carbon objects have a toDateTimeString() method.
			if ( method_exists( $datetime, 'toDateTimeString' ) ) {
				return $datetime->toDateTimeString();
			}
			// DateTime objects can be formatted.
			if ( method_exists( $datetime, 'format' ) ) {
				return $datetime->format( 'Y-m-d H:i:s' );
			}
			// Try to cast to string (Carbon __toString returns 'Y-m-d H:i:s' format).
			$datetime = (string) $datetime;
		}

		// Handle Carbon objects serialized to arrays (happens in some contexts).
		// Carbon serializes to: {"date":"2026-01-02 19:59:52.000000","timezone_type":1,"timezone":"+05:30"}.
		if ( is_array( $datetime ) && isset( $datetime['date'] ) ) {
			// Extract the date string and remove microseconds.
			$datetime = substr( $datetime['date'], 0, 19 ); // Gets 'Y-m-d H:i:s' from 'Y-m-d H:i:s.uuuuuu'.
		}

		// If already in datetime format, return as-is.
		if ( is_string( $datetime ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $datetime ) ) {
			return $datetime;
		}

		if ( is_string( $datetime ) ) {
			$datetime = trim( $datetime );

			// Parse using the format the source field declared, when the integration knows it.
			$declared = self::parse_with_source_format( $datetime, $source_format );
			if ( $declared instanceof \DateTimeImmutable ) {
				return $declared->format( 'Y-m-d H:i:s' );
			}

			// Resolve the date half here so a dd/mm/yyyy value is not read as m/d/Y.
			if ( preg_match( '#^(\d{1,4}[/.\-]\d{1,2}[/.\-]\d{1,4})(?:[T\s]+(.*))?$#', $datetime, $matches ) ) {
				$date_part = self::parse_numeric_date( $matches[1] );
				if ( '' !== $date_part ) {
					$time_part = isset( $matches[2] ) ? trim( $matches[2] ) : '';
					$timestamp = strtotime( '' === $time_part ? $date_part : $date_part . ' ' . $time_part );
					if ( $timestamp ) {
						return gmdate( 'Y-m-d H:i:s', $timestamp );
					}
				}
			}
		}

		// Try to parse and format.
		if ( is_string( $datetime ) || is_numeric( $datetime ) ) {
			$timestamp = strtotime( $datetime );
			if ( $timestamp ) {
				return gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		if ( is_string( $datetime ) ) {
			// Dropping the value keeps the existing datetime intact; passing a non-ISO
			// string through would write it into a datetime column as-is.
			Logger::warning(
				'Field Formatter',
				'Unable to resolve datetime value - omitting it from the payload.',
				array(
					'value'         => $datetime,
					'source_format' => $source_format,
				)
			);

			return '';
		}

		return $datetime;
	}

	/**
	 * Format array values (for multiselect, tags, etc.)
	 *
	 * @since 0.0.3
	 *
	 * @param mixed $value Array or delimited string.
	 * @return array Formatted array or comma-separated string
	 */
	private static function format_array_value( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'sanitize_text_field', array_filter( $value ) );
		}

		// If it's a delimited string, convert to array.
		if ( is_string( $value ) && strpos( $value, ',' ) !== false ) {
			$array = array_map( 'trim', explode( ',', $value ) );
			return array_map( 'sanitize_text_field', array_filter( $array ) );
		}

		// If it's a single string value, wrap it in an array.
		if ( is_string( $value ) ) {
			$sanitized = sanitize_text_field( $value );
			return ! empty( $sanitized ) ? array( $sanitized ) : array();
		}

		return array();
	}

	/**
	 * Format boolean values (for checkbox/acceptance fields)
	 *
	 * @since 0.0.3
	 *
	 * @param mixed $value Raw checkbox/boolean value.
	 * @return bool Formatted boolean value
	 */
	private static function format_boolean_value( $value ) {
		// Already a boolean - return as-is.
		if ( is_bool( $value ) ) {
			return $value;
		}

		// Handle common truthy string values.
		if ( is_string( $value ) ) {
			$value_lower = strtolower( trim( $value ) );
			if ( in_array( $value_lower, array( 'on', '1', 'true', 'yes' ), true ) ) {
				return true;
			}
			if ( in_array( $value_lower, array( '', '0', 'false', 'no', 'off' ), true ) ) {
				return false;
			}
		}

		// Handle numeric values.
		if ( is_numeric( $value ) ) {
			return (bool) $value;
		}

		// Default: cast to boolean.
		return (bool) $value;
	}

	/**
	 * Check if value should be included
	 *
	 * @since 0.0.3
	 *
	 * @param mixed $value The value to check.
	 * @return bool
	 */
	public static function should_include_value( $value ) {
		// Always include numeric values, booleans (including false), and arrays (including empty).
		// This ensures mapped fields always send a value to override existing data.
		if ( is_numeric( $value ) || is_bool( $value ) || is_array( $value ) ) {
			return true;
		}

		// Exclude only null and empty strings.
		return ! empty( $value );
	}

	/**
	 * Validate gender enum value
	 *
	 * Matches backend enum: male, female, other, prefer_not_to_say
	 *
	 * @since 0.0.3
	 *
	 * @param string $value Gender value.
	 * @return string Validated gender value or empty string if invalid
	 */
	private static function validate_gender( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		// Valid gender values from backend enum.
		$valid_genders = array( 'male', 'female', 'other', 'prefer_not_to_say' );

		// Normalize to lowercase and trim.
		$value_normalized = strtolower( trim( $value ) );

		// Check if value is valid.
		if ( in_array( $value_normalized, $valid_genders, true ) ) {
			return $value_normalized;
		}

		// Return empty string for invalid values (will be excluded by should_include_value).
		return '';
	}

	/**
	 * Get language name to ISO 639-1 code mapping
	 *
	 * Uses WordPress available translations to dynamically build the map,
	 * with a minimal fallback for common languages not in WP translations.
	 *
	 * @since 1.0.0
	 *
	 * @return array Language name (lowercase) => ISO 639-1 code mapping
	 */
	private static function get_language_map() {
		static $language_map = null;

		if ( null !== $language_map ) {
			return $language_map;
		}

		$language_map = array();

		// Try to get WordPress translations (includes 200+ languages).
		// The function is in wp-admin/includes/translation-install.php which isn't always loaded.
		if ( ! function_exists( 'wp_get_available_translations' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}

		if ( function_exists( 'wp_get_available_translations' ) ) {
			$translations = wp_get_available_translations();

			foreach ( $translations as $translation ) {
				if ( ! empty( $translation['english_name'] ) && ! empty( $translation['language'] ) ) {
					// Extract base language code (e.g., 'es' from 'es_ES').
					$code = $translation['language'];
					if ( preg_match( '/^([a-z]{2})(?:_|$)/i', $code, $matches ) ) {
						$code = strtolower( $matches[1] );
					}

					// Map english name to code.
					$english_name = strtolower( $translation['english_name'] );
					if ( ! isset( $language_map[ $english_name ] ) ) {
						$language_map[ $english_name ] = $code;
					}
				}
			}
		}

		// Minimal fallback for languages that might not be in WP translations
		// or when wp_get_available_translations() is not available.
		$fallback = array(
			'english' => 'en',
			'chinese' => 'zh',
			'hindi'   => 'hi',
			'arabic'  => 'ar',
			'malay'   => 'ms',
		);

		// Merge fallback (don't override WP translations).
		$language_map = array_merge( $fallback, $language_map );

		/**
		 * Filter the language name to ISO code mapping.
		 *
		 * @since 1.0.0
		 *
		 * @param array $language_map Language name (lowercase) => ISO 639-1 code.
		 */
		$language_map = apply_filters( 'surecontact_language_map', $language_map );

		return $language_map;
	}

	/**
	 * Format language to ISO 639-1 code (2-letter lowercase)
	 *
	 * Validates and formats language codes to match backend expectations.
	 * Backend validation: 'string', 'size:2' (exactly 2 characters).
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Language value.
	 * @return string Formatted 2-letter language code or empty string if invalid
	 */
	private static function format_language( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		// Sanitize and normalize to lowercase.
		$value = strtolower( trim( sanitize_text_field( $value ) ) );

		// If already a valid 2-letter code, return it.
		if ( preg_match( '/^[a-z]{2}$/', $value ) ) {
			return $value;
		}

		// Get the language map (WordPress translations + fallback).
		$language_map = self::get_language_map();

		// Check if it's a language name we can map.
		if ( isset( $language_map[ $value ] ) ) {
			return $language_map[ $value ];
		}

		// If value is longer than 2 chars but starts with valid code, extract it.
		// e.g., "en-US" or "en_US" -> "en".
		if ( preg_match( '/^([a-z]{2})[-_]/', $value, $matches ) ) {
			return $matches[1];
		}

		// Return empty for invalid values (will be excluded by should_include_value).
		return '';
	}

	/**
	 * Get timezone abbreviation to IANA mapping
	 *
	 * Uses PHP's DateTimeZone::listAbbreviations() to dynamically build the map,
	 * with priority overrides for ambiguous abbreviations.
	 *
	 * @since 1.0.0
	 *
	 * @return array Abbreviation (lowercase) => IANA timezone mapping
	 */
	private static function get_timezone_abbreviation_map() {
		static $timezone_map = null;

		if ( null !== $timezone_map ) {
			return $timezone_map;
		}

		// Priority overrides for ambiguous abbreviations.
		// These take precedence over PHP's listAbbreviations().
		$priority_map = array(
			// Ambiguous: CST = US Central, China Standard, Cuba Standard.
			'cst'       => 'America/Chicago',
			// Ambiguous: IST = India, Ireland, Israel.
			'ist'       => 'Asia/Kolkata',
			// Ambiguous: BST = British Summer, Bangladesh Standard.
			'bst'       => 'Europe/London',
			// Friendly aliases (not in PHP's list).
			'eastern'   => 'America/New_York',
			'central'   => 'America/Chicago',
			'mountain'  => 'America/Denver',
			'pacific'   => 'America/Los_Angeles',
			'alaska'    => 'America/Anchorage',
			'hawaii'    => 'Pacific/Honolulu',
			'india'     => 'Asia/Kolkata',
			'japan'     => 'Asia/Tokyo',
			'korea'     => 'Asia/Seoul',
			'china'     => 'Asia/Shanghai',
			'singapore' => 'Asia/Singapore',
			'cst_china' => 'Asia/Shanghai',
		);

		// Build map from PHP's timezone abbreviations.
		$timezone_map  = array();
		$abbreviations = \DateTimeZone::listAbbreviations();

		foreach ( $abbreviations as $abbr => $zones ) {
			// Skip if already in priority map.
			if ( isset( $priority_map[ $abbr ] ) ) {
				continue;
			}

			// Get the first valid timezone_id for this abbreviation.
			foreach ( $zones as $zone ) {
				if ( ! empty( $zone['timezone_id'] ) ) {
					$timezone_map[ $abbr ] = $zone['timezone_id'];
					break;
				}
			}
		}

		// Merge priority map (takes precedence).
		$timezone_map = array_merge( $timezone_map, $priority_map );

		/**
		 * Filter the timezone abbreviation to IANA mapping.
		 *
		 * @param array $timezone_map Abbreviation (lowercase) => IANA timezone.
		 */
		$timezone_map = apply_filters( 'surecontact_timezone_map', $timezone_map );

		return $timezone_map;
	}

	/**
	 * Format timezone to IANA format
	 *
	 * Validates and formats timezone identifiers to match backend expectations.
	 * Backend validation: 'timezone:all' (validates against IANA timezone database).
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Timezone value.
	 * @return string Formatted IANA timezone or empty string if invalid
	 */
	private static function format_timezone( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		// Sanitize the input.
		$value = trim( sanitize_text_field( $value ) );

		// Check if it's already a valid IANA timezone.
		if ( in_array( $value, timezone_identifiers_list(), true ) ) {
			return $value;
		}

		// Get the timezone abbreviation map (PHP abbreviations + priority overrides).
		$timezone_map = self::get_timezone_abbreviation_map();

		// Try lowercase lookup.
		$value_lower = strtolower( $value );
		if ( isset( $timezone_map[ $value_lower ] ) ) {
			return $timezone_map[ $value_lower ];
		}

		// Try to match case-insensitively against IANA list.
		$iana_timezones = timezone_identifiers_list();
		foreach ( $iana_timezones as $tz ) {
			if ( strtolower( $tz ) === $value_lower ) {
				return $tz;
			}
		}

		// Try to parse UTC offset format such as UTC plus or minus hours and minutes.
		$utc_timezone = self::parse_utc_offset_to_timezone( $value );
		if ( $utc_timezone ) {
			return $utc_timezone;
		}

		// Return empty for invalid values (will be excluded by should_include_value).
		return '';
	}

	/**
	 * Parse UTC offset string to closest IANA timezone
	 *
	 * Supported formats (sign is optional, defaults to positive):
	 * - With prefix: "UTC+5:30", "GMT-8", "UTC+05"
	 * - With sign: "+05:30", "-8", "+5"
	 * - Without sign: "5:30", "05", "5" (treated as positive offset)
	 *
	 * @since 1.0.0
	 *
	 * @param string $offset UTC offset string.
	 * @return string|null IANA timezone or null if not parseable
	 */
	private static function parse_utc_offset_to_timezone( $offset ) {
		// Remove common prefixes (UTC, GMT).
		$offset = preg_replace( '/^(utc|gmt)/i', '', $offset );
		$offset = trim( (string) $offset );

		// Match offset pattern: optional sign + 1-2 digit hours + optional :minutes.
		// Examples: "+05:30", "-8", "5:30", "05", "5".
		if ( ! preg_match( '/^([+-])?(\d{1,2})(?::(\d{2}))?$/', $offset, $matches ) ) {
			return null;
		}

		$sign    = ( '-' === $matches[1] ) ? -1 : 1;
		$hours   = (int) $matches[2];
		$minutes = isset( $matches[3] ) ? (int) $matches[3] : 0;

		$total_offset_hours = $sign * ( $hours + $minutes / 60 );

		// Map common offsets to representative IANA timezones.
		// IMPORTANT: Use string keys because PHP truncates float keys to integers!
		// e.g., array(5.5 => 'x') becomes array(5 => 'x'), causing incorrect lookups.
		$offset_map = array(
			'-12'  => 'Pacific/Kwajalein',
			'-11'  => 'Pacific/Pago_Pago',
			'-10'  => 'Pacific/Honolulu',
			'-9'   => 'America/Anchorage',
			'-8'   => 'America/Los_Angeles',
			'-7'   => 'America/Denver',
			'-6'   => 'America/Chicago',
			'-5'   => 'America/New_York',
			'-4'   => 'America/Halifax',
			'-3.5' => 'America/St_Johns',
			'-3'   => 'America/Sao_Paulo',
			'-2'   => 'Atlantic/South_Georgia',
			'-1'   => 'Atlantic/Azores',
			'0'    => 'UTC',
			'1'    => 'Europe/Paris',
			'2'    => 'Europe/Helsinki',
			'3'    => 'Europe/Moscow',
			'3.5'  => 'Asia/Tehran',
			'4'    => 'Asia/Dubai',
			'4.5'  => 'Asia/Kabul',
			'5'    => 'Asia/Karachi',
			'5.5'  => 'Asia/Kolkata',
			'5.75' => 'Asia/Kathmandu',
			'6'    => 'Asia/Dhaka',
			'6.5'  => 'Asia/Yangon',
			'7'    => 'Asia/Bangkok',
			'8'    => 'Asia/Shanghai',
			'9'    => 'Asia/Tokyo',
			'9.5'  => 'Australia/Darwin',
			'10'   => 'Australia/Sydney',
			'10.5' => 'Australia/Lord_Howe',
			'11'   => 'Pacific/Noumea',
			'12'   => 'Pacific/Auckland',
			'13'   => 'Pacific/Tongatapu',
			'14'   => 'Pacific/Kiritimati',
		);

		// Convert to string for lookup (preserves decimal precision).
		$offset_key = (string) $total_offset_hours;
		if ( isset( $offset_map[ $offset_key ] ) ) {
			return $offset_map[ $offset_key ];
		}

		// For unmatched offsets, return null (better to reject than guess wrong).
		return null;
	}

	/**
	 * Validate text length with maximum limit
	 *
	 * @since 0.0.3
	 *
	 * @param string $value     Text value.
	 * @param int    $max_length Maximum allowed length.
	 * @return string Validated and truncated text
	 */
	private static function validate_text_length( $value, $max_length ) {
		if ( empty( $value ) ) {
			return '';
		}

		// Sanitize first.
		$sanitized_value = sanitize_text_field( $value );

		// Check length and truncate if needed.
		if ( mb_strlen( $sanitized_value ) > $max_length ) {
			return mb_substr( $sanitized_value, 0, $max_length );
		}

		return $sanitized_value;
	}
}
