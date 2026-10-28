<?php
/**
 * Consent Log Retention Cron functionality for SureCookie plugin.
 *
 * Handles automatic cleanup of consent logs based on retention settings.
 *
 * @since 0.0.1
 * @package SureCookie
 */

namespace SureCookie\Inc\Modules\ConsentLogs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureCookie\Inc\Database\ConsentLog;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

/**
 * Cron
 *
 * Handles consent log retention cleanup via WordPress cron.
 *
 * @since 0.0.1
 */
class Cron {
	use GetInstance;

	/**
	 * Action hook name for consent log cleanup.
	 *
	 * @since 0.0.1
	 */
	public const CLEANUP_CONSENT_LOGS = 'surecookie_cleanup_consent_logs';

	/**
	 * Retention period mapping (option value => days).
	 *
	 * @since 0.0.1
	 */
	private const RETENTION_DAYS = [
		'30_days'  => 30,
		'60_days'  => 60,
		'90_days'  => 90,
		'365_days' => 365,
	];

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function __construct() {
		add_action( self::CLEANUP_CONSENT_LOGS, [ $this, 'cleanup_old_logs' ] );

		// Modules boot from `init` priority 999, so an `init` callback here would
		// never fire. Schedule directly - schedule_cleanup() is idempotent.
		$this->schedule_cleanup();

		// Reschedule when settings change.
		add_action( 'update_option_' . SURECOOKIE_SETTINGS_OPTION, [ $this, 'maybe_reschedule_on_settings_change' ], 10, 2 );
	}

	/**
	 * Schedule the cleanup cron event if not already scheduled.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function schedule_cleanup(): void {
		$retention = Settings::get( 'consent_log_retention' );

		// If retention is 'never', unschedule any existing cron.
		if ( $retention === 'never' || ! isset( self::RETENTION_DAYS[ $retention ] ) ) {
			$this->unschedule_cleanup();
			return;
		}

		// Schedule daily cleanup if not already scheduled.
		if ( ! wp_next_scheduled( self::CLEANUP_CONSENT_LOGS ) ) {
			wp_schedule_event( time(), 'daily', self::CLEANUP_CONSENT_LOGS );
		}
	}

	/**
	 * Unschedule the cleanup cron event.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function unschedule_cleanup(): void {
		$timestamp = wp_next_scheduled( self::CLEANUP_CONSENT_LOGS );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CLEANUP_CONSENT_LOGS );
		}
	}

	/**
	 * Handle settings change to reschedule cron if needed.
	 *
	 * @param mixed $old_value Old settings value.
	 * @param mixed $new_value New settings value.
	 * @since 0.0.1
	 * @return void
	 */
	public function maybe_reschedule_on_settings_change( $old_value = null, $new_value = null ): void {
		$old_retention = $old_value['consent_log_retention'] ?? 'never';
		$new_retention = $new_value['consent_log_retention'] ?? 'never';

		// If retention setting changed, reschedule.
		if ( $old_retention !== $new_retention ) {
			$this->unschedule_cleanup();
			$this->schedule_cleanup();
		}
	}

	/**
	 * Clean up consent logs older than the retention period.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function cleanup_old_logs(): void {
		$retention = Settings::get( 'consent_log_retention' );

		// Skip if retention is 'never' or invalid.
		if ( $retention === 'never' || ! isset( self::RETENTION_DAYS[ $retention ] ) ) {
			return;
		}

		$days = self::RETENTION_DAYS[ $retention ];

		// Delete logs older than the retention period.
		ConsentLog::delete_logs_older_than( $days );
	}

	/**
	 * Get retention days from setting value.
	 *
	 * @param string $retention The retention setting value.
	 * @since 0.0.1
	 * @return int|null Days or null if 'never'.
	 */
	public static function get_retention_days( string $retention ): ?int {
		if ( $retention === 'never' ) {
			return null;
		}

		return self::RETENTION_DAYS[ $retention ] ?? null;
	}
}
