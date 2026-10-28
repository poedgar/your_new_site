<?php
/**
 * Logger.
 *
 * @package SureCookie\Inc\Utils;
 * @since 0.0.1
 */

namespace SureCookie\Inc\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Traits\GetInstance;

/**
 * Logger
 *
 * @since 0.0.1
 */
class Logger {
	use GetInstance;

	/**
	 * Maximum number of lines kept in the scan log option.
	 *
	 * The log is appended to on every progress step of every scan but is only
	 * cleared when a manual scan starts, so a site running automatic scans would
	 * otherwise grow this option without bound. Oldest lines are dropped first.
	 *
	 * @since 1.3.0
	 */
	public const MAX_LOG_ENTRIES = 500;

	/**
	 * Log an error message to the WordPress debug log.
	 *
	 * @param string $message The error message to log.
	 * @param string $type    The type of log message. Can be 'log', 'error', or 'warning'.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function log( string $message, string $type = 'log' ): void {
		if ( Helper::is_development_mode() ) {
			error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		if ( defined( 'WP_CLI' ) && class_exists( 'WP_CLI' ) ) {
			if ( $type === 'log' || $type === 'info' ) {
				\WP_CLI::log( $message );
			} elseif ( $type === 'error' ) {
				\WP_CLI::error( $message );
			} else {
				\WP_CLI::warning( $message );
			}
		}
	}

	/**
	 * Save log.
	 *
	 * @param string $message Log message.
	 * @return void
	 * @since 0.0.1
	 */
	public function save_log( $message ) {
		$logs = get_option( SURECOOKIE_SCANNED_LOGS_OPTION, [] );
		if ( ! is_array( $logs ) ) {
			$logs = [];
		}

		$logs[] = $message;

		if ( count( $logs ) > self::MAX_LOG_ENTRIES ) {
			$logs = array_slice( $logs, -self::MAX_LOG_ENTRIES );
		}

		// Non-autoloaded - the log is only ever read by the scanner UI, so it must
		// not ride along in the alloptions cache on every front-end request.
		Update::option( SURECOOKIE_SCANNED_LOGS_OPTION, $logs );
	}

	/**
	 * Get logs.
	 *
	 * @return array<int, string>
	 * @since 0.0.1
	 */
	public function get_log() {
		return get_option( SURECOOKIE_SCANNED_LOGS_OPTION, [] );
	}

	/**
	 * Cleanup logs.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function cleanup_logs() {
		delete_option( SURECOOKIE_SCANNED_LOGS_OPTION );
	}
}
