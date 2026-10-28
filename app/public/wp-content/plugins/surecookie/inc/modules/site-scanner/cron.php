<?php
/**
 * Scanner trigger functionality for SureCookie plugin.
 *
 * Handles manual triggering of site scanning from the dashboard.
 * Integrates with SureCookie SaaS API for cookie scanning.
 *
 * @since 0.0.1
 * @package SureCookie
 */

namespace SureCookie\Inc\Modules\SiteScanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\AssistedScan\Session as AssistedSession;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\Logger;

/**
 * Cron
 *
 * Handles site scanning triggers (user-initiated only).
 *
 * @since 0.0.1
 */
class Cron {
	use GetInstance;

	/**
	 * Action hook name for site scanning.
	 *
	 * @since 0.0.1
	 */
	public const START_SITE_SCANNING = 'surecookie_start_site_scanning';

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function __construct() {
		// Wrapped in a closure so the action callback returns void - the
		// method itself returns the scan-start result for direct callers.
		add_action(
			self::START_SITE_SCANNING,
			function (): void {
				$this->start_site_scanning();
			}
		);

		// Initialize SaaS Client for polling functionality.
		SaasClient::get_instance();
	}

	/**
	 * Start site scanning process.
	 *
	 * Triggered manually from dashboard when user clicks scan button.
	 * Uses SaaS API for actual cookie scanning.
	 *
	 * @since 0.0.1
	 * @return array{success: bool, message?: string, code?: string, group_id?: string} Result of the scan-start attempt.
	 */
	public function start_site_scanning(): array {
		Logger::get_instance()->save_log( __( 'Starting site scan (triggered by user).', 'surecookie' ) );

		// Get pages to scan.
		$pages_to_scan = $this->get_pages_urls_to_scan();

		if ( empty( $pages_to_scan ) ) {
			$message = __( 'No pages selected for scanning.', 'surecookie' );
			Logger::get_instance()->save_log( $message );
			return [
				'success' => false,
				'code'    => 'no_pages',
				'message' => $message,
			];
		}

		// Limit pages based on plan.
		$max_pages     = Utils::get_max_scan_pages();
		$pages_to_scan = array_slice( $pages_to_scan, 0, $max_pages );

		// Log plan and pages info.
		$plan        = Utils::get_plan();
		$pages_count = count( $pages_to_scan );
		Logger::get_instance()->save_log( sprintf( /* translators: %s: Plan name */ __( 'Plan: %s.', 'surecookie' ), ucfirst( $plan ) ) );
		Logger::get_instance()->save_log( sprintf( /* translators: %d: Number of pages */ __( 'Pages selected: %d.', 'surecookie' ), $pages_count ) );
		Logger::get_instance()->save_log( '' ); // Blank line.
		Logger::get_instance()->save_log( __( 'Sending scan request…', 'surecookie' ) );

		$saas_client = SaasClient::get_instance();

		// Check if scan already in progress.
		if ( $saas_client->is_scan_in_progress() ) {
			$message = __( 'Scan already in progress. Skipping.', 'surecookie' );
			Logger::get_instance()->save_log( $message );
			return [
				'success' => false,
				'code'    => 'scan_in_progress',
				'message' => $message,
			];
		}

		// Start scan via SaaS API.
		$result = $saas_client->start_scan( $pages_to_scan );

		if ( $result['success'] ) {
			Logger::get_instance()->save_log( __( 'Scan started successfully.', 'surecookie' ) );
			Logger::get_instance()->save_log( sprintf( /* translators: %s: Group ID */ __( 'Group ID: %s', 'surecookie' ), $result['group_id'] ?? '' ) );
			Logger::get_instance()->save_log( '' ); // Blank line.
			Logger::get_instance()->save_log( __( 'Checking scan progress…', 'surecookie' ) );

			// Note: the first-scan-started analytics flag is written in
			// SaasClient::start_scan() so every scan entry point is covered.
		} else {
			Logger::get_instance()->save_log( 'Scanning status: ' . ( $result['message'] ?? __( 'Scan failed.', 'surecookie' ) ) );
		}

		return $result;
	}

	/**
	 * Get current scan status.
	 *
	 * @since 0.0.1
	 * @return array<string, mixed> Scan status data.
	 */
	public function get_scan_status(): array {
		// An assisted walk is answered first, without touching the SaaS: the sites that
		// need one are the sites our scanner can't reach, so polling during the walk would
		// add a doomed request to every poll. Reported in the cloud scan's shape so the
		// progress UI needs no changes.
		$assisted = AssistedSession::get_instance();
		if ( $assisted->is_active() ) {
			return $assisted->status_payload();
		}

		// A walk that just ended keeps reporting a terminal state briefly, so the
		// screen can run its completion path before the status settles to idle.
		$just_finished = $assisted->peek_just_finished();
		if ( $just_finished !== null ) {
			return $just_finished;
		}

		$saas_client = SaasClient::get_instance();

		// Pull the latest status from the SaaS on read so an in-flight scan resolves even
		// when WP-Cron isn't firing the poll hook (DISABLE_WP_CRON with no server cron, or
		// full-page caching). Throttled internally; a no-op when idle/terminal.
		$saas_client->sync_active_scan_status();

		$active_scan = $saas_client->get_active_scan();

		if ( empty( $active_scan ) ) {
			// A cloud scan that just ended reports its finish once, like an assisted walk.
			$just_finished = $saas_client->peek_just_finished();
			if ( $just_finished !== null ) {
				return $just_finished;
			}

			$last_outcome = get_transient( SaasClient::LAST_OUTCOME_TRANSIENT );

			return [
				'in_progress'  => false,
				'status'       => 'idle',
				'message'      => __( 'No active scan.', 'surecookie' ),
				// Lets the UI show honest guidance after a blocked/empty scan
				// instead of a bare "0 cookies found".
				'last_outcome' => is_array( $last_outcome ) ? $last_outcome : null,
			];
		}

		$elapsed = time() - ( $active_scan['started_at'] ?? time() );

		return [
			'in_progress'                 => true,
			'status'                      => $active_scan['status'] ?? 'unknown',
			'group_id'                    => $active_scan['group_id'] ?? '',
			'pages_count'                 => $active_scan['pages_count'] ?? 0,
			'elapsed'                     => $elapsed,
			'poll_attempts'               => $active_scan['poll_attempts'] ?? 0,
			'progress'                    => $active_scan['progress'] ?? null,
			'error_details'               => $active_scan['error_details'] ?? [],
			'estimated_remaining_seconds' => $active_scan['estimated_remaining_seconds'] ?? null,
			'seconds_since_last_progress' => time() - ( $active_scan['last_progress_change_at'] ?? ( $active_scan['started_at'] ?? time() ) ),
			'message'                     => __( 'Scan in progress...', 'surecookie' ),
		];
	}

	/**
	 * Cancel the current scan, whichever kind is running.
	 *
	 * Handles both modes so the existing Cancel control and its React hook keep
	 * working without needing to know which scanner produced the run.
	 *
	 * @since 0.0.1
	 * @since 1.3.0 Also cancels an assisted walk.
	 * @return bool True if cancelled, false if no scan was active.
	 */
	public function cancel_scan(): bool {
		$assisted = AssistedSession::get_instance();

		// Cleared unconditionally when any session state exists, so a stale walk the
		// administrator can no longer reach from its scan window is still clearable.
		if ( ! empty( $assisted->get() ) ) {
			$assisted->clear();
			// A cancelled walk must not inherit an earlier walk's terminal state and
			// report itself as completed.
			$assisted->clear_just_finished();
			Logger::get_instance()->log( __( 'Assisted scan cancelled by user.', 'surecookie' ) );

			return true;
		}

		$saas_client = SaasClient::get_instance();

		if ( ! $saas_client->is_scan_in_progress() ) {
			return false;
		}

		// Cleanup local state. A cancelled scan must not inherit an earlier run's
		// terminal marker and report itself as completed.
		$saas_client->cleanup_active_scan();
		$saas_client->clear_just_finished();

		Logger::get_instance()->log( __( 'Scan cancelled by user.', 'surecookie' ) );

		return true;
	}

	/**
	 * Get page URLs to scan based on user selection.
	 *
	 * @since 0.0.1
	 * @return array<int, string> Array of page URLs.
	 */
	public function get_pages_urls_to_scan(): array {
		$scan_pages = Settings::get( 'scan_pages' );
		$scan_pages = is_array( $scan_pages ) ? $scan_pages : [];
		$urls       = [];

		// The filter below always runs (even with no manual selection) so
		// extensions like Automatic Scanning can inject the scan scope.
		foreach ( $scan_pages as $page ) {
			if ( empty( $page['value'] ) ) {
				continue;
			}

			$post_id = absint( $page['value'] );

			if ( $post_id <= 0 ) {
				continue;
			}

			$post = get_post( $post_id );

			if ( ! $post || $post->post_status !== 'publish' ) {
				continue;
			}

			$permalink = get_permalink( $post_id );

			if ( $permalink ) {
				$urls[] = $permalink;
			}
		}

		/**
		 * Filter the page URLs to scan.
		 *
		 * @since 0.0.1
		 * @param array<int, string> $urls Array of page URLs.
		 */
		$filtered = apply_filters( 'surecookie_scanner_page_urls_to_scan', $urls );
		return is_array( $filtered ) ? $filtered : $urls;
	}
}
