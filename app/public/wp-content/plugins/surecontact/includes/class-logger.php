<?php
/**
 * Logger utility class for SureContact plugin
 *
 * Writes all log entries to the surecontact_api_queue table (DB only).
 * The database table is the single source of truth for ALL logging.
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact;

use SureContact\Database\Api_Queue_Operations;

/**
 * Logger class
 *
 * @since 0.0.1
 */
class Logger {
	/**
	 * Log levels
	 *
	 * @since 0.0.1
	 */
	const ERROR   = 'ERROR';
	const WARNING = 'WARNING';
	const INFO    = 'INFO';
	const DEBUG   = 'DEBUG';

	/**
	 * Flag to prevent recursive logging
	 *
	 * @since 1.4.0
	 *
	 * @var bool
	 */
	private static $is_writing = false;

	/**
	 * Write log entry to the database
	 *
	 * @since 0.0.1
	 * @since 1.4.0 Writes to DB instead of error_log.
	 *
	 * @param string $level   Log level.
	 * @param string $context Context/component name.
	 * @param string $message Log message.
	 * @param array  $data    Optional data to include.
	 * @return void
	 */
	private static function write_log( $level, $context, $message, $data = array() ) {
		// Guard: table may not exist during early boot.
		if ( ! did_action( 'plugins_loaded' ) ) {
			return;
		}

		// Guard: prevent recursion if insert triggers another log call.
		if ( self::$is_writing ) {
			return;
		}

		self::$is_writing = true;

		$formatted_message = $message;

		// Append data if provided.
		if ( ! empty( $data ) ) {
			$formatted_message .= ' | Data: ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		}

		// Map log levels to status: error/warning → 'error', info/debug → 'success'.
		$status = in_array( $level, array( self::ERROR, self::WARNING ), true ) ? 'error' : 'success';

		$insert_data = array(
			'operation' => sanitize_text_field( $context ),
			'status'    => $status,
		);

		// Store message in appropriate column based on level.
		if ( 'error' === $status ) {
			$insert_data['last_error'] = substr( $formatted_message, 0, 500 );
		} else {
			$insert_data['response_data'] = wp_json_encode(
				array(
					'level'   => strtolower( $level ),
					'message' => $formatted_message,
				)
			);
		}

		Api_Queue_Operations::insert( $insert_data );

		self::$is_writing = false;
	}

	/**
	 * Log error message
	 *
	 * Always logs.
	 *
	 * @since 0.0.1
	 *
	 * @param string $context Context/component name.
	 * @param string $message Error message.
	 * @param array  $data    Optional additional data.
	 * @return void
	 */
	public static function error( $context, $message, $data = array() ) {
		self::write_log( self::ERROR, $context, $message, $data );
	}

	/**
	 * Log warning message
	 *
	 * @since 0.0.1
	 *
	 * @param string $context Context/component name.
	 * @param string $message Warning message.
	 * @param array  $data    Optional additional data.
	 * @return void
	 */
	public static function warning( $context, $message, $data = array() ) {
		self::write_log( self::WARNING, $context, $message, $data );
	}

	/**
	 * Log info message
	 *
	 * @since 0.0.1
	 *
	 * @param string $context Context/component name.
	 * @param string $message Info message.
	 * @param array  $data    Optional additional data.
	 * @return void
	 */
	public static function info( $context, $message, $data = array() ) {
		self::write_log( self::INFO, $context, $message, $data );
	}

	/**
	 * Log debug message
	 *
	 * @since 0.0.1
	 *
	 * @param string $context Context/component name.
	 * @param string $message Debug message.
	 * @param array  $data    Optional additional data.
	 * @return void
	 */
	public static function debug( $context, $message, $data = array() ) {
		self::write_log( self::DEBUG, $context, $message, $data );
	}
}
