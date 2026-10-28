<?php
/**
 * Analytics Class
 *
 * @since 1.6.0
 * @package SureMails\Inc\Analytics
 */

namespace SureMails\Inc\Analytics;

use SureMails\Inc\DB\EmailLog;
use SureMails\Inc\Onboarding;
use SureMails\Inc\Traits\Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Analytics
 */
class Analytics {

	use Instance;

	/**
	 * Shared events instance.
	 *
	 * @var \BSF_Analytics_Events|null
	 */
	private static $events = null;

	/**
	 * Constructor: hook analytics filter and state events.
	 */
	public function __construct() {
		// BSF Analytics hooks `maybe_track_analytics()` on `init`, which runs
		// on every request (frontend, cron, REST). Register the stats filter
		// unconditionally so `plugin_data.suremails` is always present when the
		// library decides to POST — otherwise a frontend request can win the
		// 2-day throttle and ship an empty payload, suppressing telemetry.
		add_filter( 'bsf_core_stats', [ $this, 'add_analytics_data' ] );

		// Plugin version change detection — hook into the update lifecycle so the
		// event is queued regardless of which request (admin, frontend, cron) wins
		// the race to run Update::init(). Registered outside the is_admin() gate
		// so the listener is bound on every request.
		add_action( 'suremails_update_before', [ $this, 'handle_plugin_updated' ], 10, 2 );

		// State-event detection only needs to run in admin context.
		if ( ! is_admin() ) {
			return;
		}

		// State-based events — throttled to once per day.
		// IMPORTANT: Transient is set INSIDE detect_state_events() after confirming
		// BSF_Analytics_Events class is loaded. If class isn't ready, it retries next load.
		if ( get_transient( 'suremails_state_events_checked' ) === false ) {
			$this->detect_state_events();
		}
	}

	/**
	 * Get shared events tracker.
	 *
	 * @return \BSF_Analytics_Events|null
	 */
	public static function events() {
		if ( null === self::$events ) {
			if ( ! class_exists( 'BSF_Analytics_Events' ) ) {
				return null;
			}

			self::$events = new \BSF_Analytics_Events( 'suremails' );
		}

		return self::$events;
	}

	/**
	 * Add analytics data to bsf_core_stats.
	 *
	 * @param array<string, mixed> $stats_data Existing stats data.
	 * @return array<string, mixed>
	 */
	public function add_analytics_data( $stats_data ) {
		$events = self::events();

		// One-time events flushed from the pending queue, plus the daily
		// per-provider email breakdown. The breakdown is appended directly
		// (not via BSF_Analytics_Events::track()) so each record can carry the
		// stat-day `date` and skip the one-time dedup — it is a recurring,
		// idempotent daily metric, not a milestone.
		$events_record = $events ? $events->flush_pending() : [];
		$events_record = array_merge( $events_record, $this->get_provider_stats_events() );

		$stats_data['plugin_data']['suremails'] = [
			'version'       => SUREMAILS_VERSION,
			'site_language' => get_locale(),

			// Daily KPIs (last 2 days).
			'kpi_records'   => $this->get_kpi_tracking_data(),

			// One-time events + daily per-provider email breakdown.
			'events_record' => $events_record,
		];

		return $stats_data;
	}

	/**
	 * Get number of days since plugin installation.
	 *
	 * @since 1.9.4
	 * @return int Days since install (0 if unknown).
	 */
	public static function get_days_since_install(): int {
		$install_time = (int) get_site_option( 'suremails_usage_installed_time', 0 );

		if ( $install_time <= 0 ) {
			return 0;
		}

		return (int) floor( ( time() - $install_time ) / DAY_IN_SECONDS );
	}

	/**
	 * Handle the plugin update lifecycle event.
	 *
	 * Hooked to `suremails_update_before`, which fires from Update::init() right
	 * before `suremails-version` is rewritten — so `$from_version` is still the
	 * pre-upgrade value. Queues a `plugin_updated` analytics event and clears
	 * the state-events throttle so other state events re-evaluate on the next
	 * admin load instead of waiting up to 24h.
	 *
	 * @param string $from_version Previously stored plugin version.
	 * @param string $to_version   New plugin version.
	 * @return void
	 */
	public function handle_plugin_updated( string $from_version, string $to_version ): void {
		if ( empty( $from_version ) || $from_version === $to_version ) {
			return;
		}

		delete_transient( 'suremails_state_events_checked' );

		$events = self::events();
		if ( null === $events ) {
			return;
		}

		$events->flush_pushed( [ 'plugin_updated' ] );
		$events->track(
			'plugin_updated',
			$to_version,
			[ 'from_version' => $from_version ]
		);
	}

	/**
	 * Detect and queue state-based events on admin page load.
	 *
	 * Runs on every admin load but throttled by a daily transient.
	 * BSF_Analytics_Events dedup prevents duplicate tracking.
	 *
	 * @since 1.9.4
	 * @return void
	 */
	private function detect_state_events(): void {
		$events = self::events();

		if ( null === $events ) {
			// BSF_Analytics_Events class not loaded — do NOT set transient; retry next load.
			return;
		}

		// Class is available — set throttle transient so we don't re-run for 24h.
		set_transient( 'suremails_state_events_checked', 1, DAY_IN_SECONDS );

		// ── 1. plugin_activated ──────────────────────────────────────────
		$bsf_referrers = get_option( 'bsf_product_referers', [] );
		$source        = ! empty( $bsf_referrers['suremails'] )
			? sanitize_text_field( $bsf_referrers['suremails'] )
			: 'self';
		$events->track( 'plugin_activated', SUREMAILS_VERSION, [ 'source' => $source ] );

		// ── 2. onboarding_skipped ────────────────────────────────────────
		$onboarding_skipped = Onboarding::instance()->get_onboarding_skipped_status();
		if ( $onboarding_skipped ) {
			$events->track( 'onboarding_skipped', 'yes' );
		}

		// ── 3. onboarding_completed ──────────────────────────────────────
		$onboarding_done = Onboarding::instance()->get_onboarding_status();
		if ( $onboarding_done ) {
			$events->flush_pushed( [ 'onboarding_completed' ] );
			$events->track(
				'onboarding_completed',
				'completed',
				[ 'previously_skipped' => (string) (int) $onboarding_skipped ]
			);
		}
	}

	/**
	 * Get KPI tracking data for the last 2 days (excluding today).
	 *
	 * @since 1.9.4
	 * @return array<string, array<string, array<string, int>>> KPI records keyed by date.
	 */
	private function get_kpi_tracking_data(): array {
		$kpi_records = [];

		for ( $i = 1; $i <= 2; $i++ ) {
			$date = wp_date( 'Y-m-d', strtotime( "-{$i} days" ) );

			if ( ! $date ) {
				continue;
			}

			$kpi_records[ $date ] = [
				'numeric_values' => [
					'emails_processed' => $this->get_emails_log_count( $date ),
				],
			];
		}

		return $kpi_records;
	}

	/**
	 * Get daily emails entry count for a specific date.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @since 1.9.4
	 * @return int Count of emails processed for that date.
	 */
	private function get_emails_log_count( string $date ): int {
		global $wpdb;

		$table_name = EmailLog::instance()->get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		if ( $table_exists !== $table_name ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table_name}` WHERE DATE(`created_at`) = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$date
			)
		);
	}

	/**
	 * Build the daily per-provider email breakdown events (last 2 days, excluding today).
	 *
	 * Each closed day with activity becomes a single `email_provider_stats` event whose
	 * `properties` map holds the per-provider counts — keeping one row per site per day
	 * instead of one KPI row per provider. The `date` is set to the stat day so the
	 * warehouse keys (and de-duplicates) the row by day, making re-sends idempotent.
	 * Days with no emails are skipped to avoid empty rows.
	 *
	 * @since 1.9.4
	 * @return array<int, array<string, mixed>> Event records to append to `events_record`.
	 */
	private function get_provider_stats_events(): array {
		$provider_events = [];

		for ( $i = 1; $i <= 2; $i++ ) {
			$date = wp_date( 'Y-m-d', strtotime( "-{$i} days" ) );

			if ( ! $date ) {
				continue;
			}

			$breakdown = $this->get_provider_breakdown( $date );

			if ( empty( $breakdown ) ) {
				continue;
			}

			$properties = [];
			foreach ( $breakdown as $provider => $count ) {
				$properties[ $provider ] = (string) $count;
			}

			$provider_events[] = [
				'event_name'  => 'email_provider_stats',
				'event_value' => (string) array_sum( $breakdown ),
				'properties'  => $properties,
				'date'        => $date . ' 00:00:00',
			];
		}

		return $provider_events;
	}

	/**
	 * Get the per-provider email count for a specific date.
	 *
	 * Mirrors get_emails_log_count() (same table, date column and all-status scope) but
	 * grouped by the `connection` column, so the per-provider counts always sum back to
	 * `emails_processed` for that date. Provider keys are normalized; empty/Default
	 * connections are bucketed as `default`.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @since 1.9.4
	 * @return array<string, int> Map of normalized provider key => count.
	 */
	private function get_provider_breakdown( string $date ): array {
		global $wpdb;

		$table_name = EmailLog::instance()->get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		if ( $table_exists !== $table_name ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `connection` AS provider, COUNT(*) AS cnt FROM `{$table_name}` WHERE DATE(`created_at`) = %s GROUP BY `connection`", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$date
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$breakdown = [];
		foreach ( $rows as $row ) {
			$provider = sanitize_key( (string) ( $row['provider'] ?? '' ) );

			if ( '' === $provider ) {
				$provider = 'default';
			}

			// Distinct raw connection values can normalize to the same key — sum them.
			$breakdown[ $provider ] = ( $breakdown[ $provider ] ?? 0 ) + (int) $row['cnt'];
		}

		return $breakdown;
	}
}
