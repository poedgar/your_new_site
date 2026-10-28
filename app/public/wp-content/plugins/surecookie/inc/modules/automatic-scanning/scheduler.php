<?php
/**
 * Automatic Scanning scheduler.
 *
 * Registers and maintains the recurring WP-Cron event that drives unattended
 * scans. Free ships the Monthly frequency; Pro adds Weekly to the allowlist.
 * The allowlist is enforced server-side so a Free user can never run Weekly.
 *
 * @package SureCookie\Inc\Modules\AutomaticScanning
 * @since 1.2.0
 */

namespace SureCookie\Inc\Modules\AutomaticScanning;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Scheduler
 *
 * @since 1.2.0
 */
class Scheduler {
	use GetInstance;

	/**
	 * Recurring cron hook that runs an automatic scan.
	 *
	 * @since 1.2.0
	 */
	public const RUN_HOOK = 'surecookie_auto_scan_run';

	/**
	 * Custom cron schedule key for the monthly cadence.
	 *
	 * @since 1.2.0
	 */
	private const MONTHLY_SCHEDULE = 'surecookie_monthly';

	/**
	 * Custom cron schedule key for the weekly cadence (Pro frequency).
	 *
	 * @since 1.2.0
	 */
	private const WEEKLY_SCHEDULE = 'surecookie_weekly';

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	private function __construct() {
		add_filter( 'cron_schedules', [ $this, 'register_schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Weekly/monthly cadences are well above the 15-minute floor.

		// Reschedule the moment settings are saved - this fires after the option is
		// written, so it reconciles against the new frequency. admin_init is a
		// self-heal fallback (re-creates a lost event); scheduling is admin-driven,
		// so no front-end reconcile is needed.
		add_action( 'update_option_' . SURECOOKIE_SETTINGS_OPTION, [ $this, 'maybe_schedule' ] );
		add_action( 'admin_init', [ $this, 'maybe_schedule' ] );

		// Enforce the frequency allowlist server-side so a disallowed value (e.g. Weekly on Free) reverts to Monthly on save.
		add_filter( 'pre_update_option_' . SURECOOKIE_SETTINGS_OPTION, [ $this, 'gate_frequency' ] );
	}

	/**
	 * Register the weekly and monthly cron intervals (not provided by core).
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules Existing schedules.
	 * @since 1.2.0
	 * @return array<string, array{interval:int, display:string}>
	 */
	public function register_schedules( $schedules = [] ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = [];
		}

		if ( ! isset( $schedules[ self::MONTHLY_SCHEDULE ] ) ) {
			$schedules[ self::MONTHLY_SCHEDULE ] = [
				'interval' => MONTH_IN_SECONDS,
				'display'  => __( 'Once Monthly (SureCookie)', 'surecookie' ),
			];
		}

		if ( ! isset( $schedules[ self::WEEKLY_SCHEDULE ] ) ) {
			$schedules[ self::WEEKLY_SCHEDULE ] = [
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly (SureCookie)', 'surecookie' ),
			];
		}

		return $schedules;
	}

	/**
	 * Allowed scan frequencies. Pro adds 'weekly' only when licensed.
	 *
	 * @since 1.2.0
	 * @return array<int, string>
	 */
	public static function allowed_frequencies(): array {
		/**
		 * Filters the scan frequencies available for Automatic Scanning.
		 *
		 * Free exposes only Monthly. Pro adds 'weekly' (when licensed) - the
		 * Weekly option is otherwise locked in the UI and rejected on save.
		 *
		 * @since 1.2.0
		 * @param array<int, string> $frequencies Allowed frequency keys.
		 */
		$allowed = apply_filters( 'surecookie_auto_scan_frequencies', [ 'monthly' ] );

		if ( ! is_array( $allowed ) || empty( $allowed ) ) {
			return [ 'monthly' ];
		}

		return array_values( array_unique( array_map( 'strval', $allowed ) ) );
	}

	/**
	 * Resolve the effective frequency, clamped to the allowlist.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public static function effective_frequency(): string {
		$frequency = (string) Settings::get( 'auto_scan_frequency' );
		$allowed   = self::allowed_frequencies();

		return in_array( $frequency, $allowed, true ) ? $frequency : ( $allowed[0] ?? 'monthly' );
	}

	/**
	 * Ensure the recurring event matches the current settings.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function maybe_schedule(): void {
		if ( ! (bool) Settings::get( 'auto_scan_enabled' ) ) {
			$this->unschedule();
			return;
		}

		$desired = $this->schedule_key( self::effective_frequency() );
		$current = wp_get_schedule( self::RUN_HOOK );

		if ( $current === $desired ) {
			return; // Already scheduled with the correct recurrence.
		}

		// Recurrence changed (or not scheduled) - reset to the desired cadence,
		// anchored to an off-peak night slot in the site's timezone.
		$this->unschedule();
		wp_schedule_event( $this->first_run_timestamp(), $desired, self::RUN_HOOK );
	}

	/**
	 * Unschedule all instances of the recurring event.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function unschedule(): void {
		wp_unschedule_hook( self::RUN_HOOK );
	}

	/**
	 * Clamp a disallowed frequency to the first allowed value before save.
	 *
	 * @param mixed $value Incoming settings option value.
	 * @since 1.2.0
	 * @return mixed
	 */
	public function gate_frequency( $value = null ) {
		if ( is_array( $value ) && isset( $value['auto_scan_frequency'] ) ) {
			$allowed = self::allowed_frequencies();

			if ( ! in_array( (string) $value['auto_scan_frequency'], $allowed, true ) ) {
				$value['auto_scan_frequency'] = $allowed[0] ?? 'monthly';
			}
		}

		return $value;
	}

	/**
	 * Timestamp of the next scheduled automatic scan (0 when not scheduled).
	 *
	 * @since 1.2.0
	 * @return int
	 */
	public static function next_run(): int {
		$timestamp = wp_next_scheduled( self::RUN_HOOK );

		return $timestamp ? (int) $timestamp : 0;
	}

	/**
	 * Map a frequency to a registered cron schedule key.
	 *
	 * @param string $frequency Frequency key.
	 * @since 1.2.0
	 * @return string
	 */
	private function schedule_key( string $frequency ): string {
		return $frequency === 'weekly' ? self::WEEKLY_SCHEDULE : self::MONTHLY_SCHEDULE;
	}

	/**
	 * Timestamp of the first run: a random slot inside the 01:00–05:00 window in
	 * the SITE's timezone (the next night; tonight if it hasn't passed yet).
	 *
	 * The random minute staggers sites across the 4-hour window so they don't all
	 * hit the SaaS at once (no thundering herd), and it avoids the UTC-midnight
	 * daily-budget reset boundary. The weekly/monthly recurrence then preserves
	 * this night-time slot. Returns a non-immediate time, so enabling the feature
	 * never triggers an instant scan.
	 *
	 * @since 1.2.0
	 * @return int UTC timestamp for wp_schedule_event().
	 */
	private function first_run_timestamp(): int {
		$now  = new \DateTimeImmutable( 'now', wp_timezone() );
		$slot = $now->setTime( 0, 0, 0 )
			->modify( '+' . wp_rand( 1 * HOUR_IN_SECONDS, ( 5 * HOUR_IN_SECONDS ) - 1 ) . ' seconds' );

		if ( $slot <= $now ) {
			$slot = $slot->modify( '+1 day' ); // Tonight's window already passed → tomorrow.
		}

		return $slot->getTimestamp();
	}
}
