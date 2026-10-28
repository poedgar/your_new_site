<?php
/**
 * SaaS API Client for Cookie Scanning.
 *
 * Handles all communication with the SureCookie SaaS API for cookie scanning.
 *
 * @package SureCookie\Inc\Modules\SiteScanner
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\SiteScanner;

use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Traits\IpManager;
use SureCookie\Inc\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * SaaS Client
 *
 * Handles API communication with the SureCookie SaaS server.
 *
 * @since 0.0.1
 */
class SaasClient {
	use GetInstance;
	use IpManager;

	/**
	 * Option key for storing active scan data.
	 */
	public const SCAN_OPTION_KEY = 'surecookie_active_scan';

	/**
	 * Set once this site's host has ever blocked our remote scanner.
	 *
	 * Durable on purpose: the outcome itself lives in a transient, but the analytics
	 * state event needs the fact to survive so the installed base can be counted.
	 *
	 * @since 1.3.0
	 */
	public const BLOCKED_FLAG_OPTION = 'surecookie_cloud_scan_blocked_flag';

	/**
	 * Transient key storing the temporary scan bypass token.
	 *
	 * Referenced by Blocker::is_scan_bypass_request(). Mirrored verbatim in
	 * uninstall.php (no autoloader there - keep the literal in sync).
	 *
	 * @since 0.0.1-beta.3
	 */
	public const SCAN_BYPASS_TRANSIENT_KEY = 'surecookie_scan_bypass_token';

	/**
	 * Transient key for the live quota snapshot returned by SaaS.
	 *
	 * @since 0.0.1-beta.3
	 */
	public const QUOTA_TRANSIENT_KEY = 'surecookie_scan_quota';

	/**
	 * Long-lived option holding the last successfully-fetched quota (offline fallback).
	 *
	 * @since 0.0.1-beta.3
	 */
	public const LAST_KNOWN_QUOTA_OPTION = 'surecookie_last_known_quota';

	/**
	 * Quota transient TTL in seconds (10 minutes).
	 *
	 * @since 0.0.1-beta.3
	 */
	public const QUOTA_TRANSIENT_TTL = 600;

	/**
	 * Cron hook name for polling scan status.
	 */
	public const POLL_SCAN_HOOK = 'surecookie_poll_scan_status';

	/**
	 * Single-event hook that runs the first-party loopback fallback in the
	 * background after a host-blocked scan (see run_first_party_detection).
	 *
	 * @since 1.3.0
	 */
	public const FIRST_PARTY_DETECT_HOOK = 'surecookie_first_party_detect';

	/**
	 * Transient counting scanner page-loads that actually reached WordPress
	 * during the active scan. Zero reach on a 0-cookie scan means the host
	 * firewall blocked the crawl at the edge (see classify_scan_outcome).
	 *
	 * @since 1.3.0
	 */
	public const SCAN_REACH_TRANSIENT = 'surecookie_scan_reach';

	/**
	 * Transient holding the last scan's outcome classification, surfaced to the
	 * admin UI so a blocked/empty scan reports honestly instead of "0 cookies".
	 *
	 * @since 1.3.0
	 */
	public const LAST_OUTCOME_TRANSIENT = 'surecookie_last_scan_outcome';

	/**
	 * Polling interval in seconds.
	 * Backend polling via WP Cron - 60 seconds to avoid WP_CRON_LOCK_TIMEOUT conflicts.
	 * Frontend polling (TanStack Query) still runs every 6 seconds for real-time user feedback.
	 */
	public const POLL_INTERVAL = 60;

	/**
	 * Maximum polling attempts (30 minutes = 30 attempts at 60 second intervals).
	 */
	public const MAX_POLL_ATTEMPTS = 30;

	/**
	 * Option that claims the poll, held through result fetching so only one
	 * poll runs. Its value is "claim time:owner token"; see acquire_poll_lock().
	 *
	 * @since 1.7.0
	 */
	public const POLL_LOCK_OPTION = 'surecookie_scan_poll_lock';

	/**
	 * A claim older than this was left by a request that died mid-poll and is
	 * taken over. Longer than any healthy poll, short enough to retry in minutes.
	 *
	 * @since 1.7.0
	 */
	public const POLL_LOCK_TTL = 300;

	/**
	 * Marks a cloud scan that just ended, so the UI's first status read after the
	 * active scan clears can run its completion path. The assisted walk keeps its own.
	 *
	 * @since 1.7.0
	 */
	public const JUST_FINISHED_TRANSIENT = 'surecookie_scan_just_finished';

	/**
	 * Outlives the UI's status poll interval, nothing more.
	 *
	 * @since 1.7.0
	 */
	public const JUST_FINISHED_TTL = 45;

	/**
	 * Transient that locks concurrent registration calls.
	 *
	 * @since 0.0.1-beta.3
	 */
	public const REGISTERING_LOCK_TRANSIENT = 'surecookie_registering_site';

	/**
	 * Transient holding the pending verification token (consumed by VerificationHandler).
	 *
	 * @since 0.0.1-beta.3
	 */
	public const PENDING_VERIFICATION_TRANSIENT = 'surecookie_pending_verification';

	/**
	 * Lock TTL - long enough for the round-trip register → verify → complete.
	 *
	 * @since 0.0.1-beta.3
	 */
	public const REGISTERING_LOCK_TTL = 30;

	/**
	 * Verification transient TTL - short, single-use after the SaaS hits the REST endpoint.
	 *
	 * @since 0.0.1-beta.3
	 */
	public const PENDING_VERIFICATION_TTL = 60;

	/**
	 * Handshake kept alive after a failed verification so Retry can re-run step 2
	 * against the same token; step 1 would mint a new one and void the TXT record.
	 *
	 * @since 1.5.0
	 */
	public const PENDING_HANDSHAKE_TRANSIENT = 'surecookie_pending_handshake';

	/**
	 * Handshake TTL - DNS can take a day. The nonce alone grants nothing, since
	 * completing still requires proving domain control.
	 *
	 * @since 1.5.0
	 */
	public const PENDING_HANDSHAKE_TTL = DAY_IN_SECONDS;

	/**
	 * Value of the poll claim this request holds, so release removes only its own.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private string $poll_lock_token = '';

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( self::POLL_SCAN_HOOK, [ $this, 'poll_scan_status' ] );
		add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Safe to adjust for plugin needs.
		add_action( 'surecookie_scanner_request_detected', [ $this, 'record_scanner_reach' ] );
		add_action( self::FIRST_PARTY_DETECT_HOOK, [ $this, 'run_first_party_detection' ] );
	}

	/**
	 * Record that a scanner page request actually reached WordPress. Fired by
	 * the Blocker when it sees a valid X-SureCookie-Scan request. Presence
	 * (not the exact count) is what classify_scan_outcome cares about.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function record_scanner_reach(): void {
		$count = (int) get_transient( self::SCAN_REACH_TRANSIENT );

		// Cap so a large crawl can't grow the value unbounded.
		if ( $count >= 100000 ) {
			return;
		}

		$ttl = ( self::MAX_POLL_ATTEMPTS * self::POLL_INTERVAL ) + 600;
		set_transient( self::SCAN_REACH_TRANSIENT, $count + 1, $ttl );
	}

	/**
	 * How many scanner requests reached WordPress during the active scan.
	 *
	 * @since 1.3.0
	 * @return int
	 */
	public function get_scanner_reach(): int {
		return (int) get_transient( self::SCAN_REACH_TRANSIENT );
	}

	/**
	 * Classify a finished scan so the UI can report honestly:
	 *  - ok              : cookies were found.
	 *  - blocked_by_host : nothing found AND the scanner never reached WP - the
	 *                      host firewall/anti-bot blocked the crawl at the edge.
	 *  - empty           : nothing found but the scanner did reach WP (genuinely
	 *                      cookie-free, or scripts only load after consent).
	 *
	 * @param int $findings Number of cookies discovered.
	 * @param int $reach    Scanner requests that reached WordPress.
	 * @since 1.3.0
	 * @return string
	 */
	public static function classify_scan_outcome( int $findings, int $reach ): string {
		if ( $findings > 0 ) {
			return 'ok';
		}

		return $reach === 0 ? 'blocked_by_host' : 'empty';
	}

	/**
	 * Persist the last scan's outcome for the admin UI to surface.
	 *
	 * @param int $findings Number of cookies discovered.
	 * @since 1.3.0
	 * @return void
	 */
	public function store_scan_outcome( int $findings ): void {
		$reach   = $this->get_scanner_reach();
		$outcome = self::classify_scan_outcome( $findings, $reach );

		$data = [
			'outcome'  => $outcome,
			'findings' => $findings,
			'reach'    => $reach,
			'at'       => time(),
		];

		// When the host blocked the crawl, run the first-party loopback fallback so the
		// user still learns which known services are present (and can declare them). Deferred
		// to a background event, not run inline: the loopback fetches several pages and can
		// take seconds, while this method runs in the scan-results pipeline reachable from
		// the foreground status poll, which must stay fast.
		if ( $outcome === 'blocked_by_host' ) {
			$data['first_party_pending'] = true;
			$this->schedule_first_party_detection();

			// Durable flag for the analytics state event. The outcome lives in a transient
			// that expires, but "this host blocked our scanner" is the fleet-wide number that
			// should decide how much effort goes into scanner reachability, so it must survive.
			if ( ! get_option( self::BLOCKED_FLAG_OPTION, false ) ) {
				update_option( self::BLOCKED_FLAG_OPTION, true, false );
			}
		}

		set_transient( self::LAST_OUTCOME_TRANSIENT, $data, DAY_IN_SECONDS );
	}

	/**
	 * Background handler: run the first-party loopback detector and merge the
	 * services it finds into the stored outcome, so the admin UI can list them
	 * after a host-blocked scan. Idempotent and self-guarding - a no-op unless a
	 * blocked scan is still awaiting its fallback pass.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function run_first_party_detection(): void {
		$outcome = get_transient( self::LAST_OUTCOME_TRANSIENT );

		if ( ! is_array( $outcome ) || ( $outcome['outcome'] ?? '' ) !== 'blocked_by_host' ) {
			return;
		}

		$outcome['detected_services'] = $this->detect_services_first_party();
		unset( $outcome['first_party_pending'] );

		set_transient( self::LAST_OUTCOME_TRANSIENT, $outcome, DAY_IN_SECONDS );
	}

	/**
	 * Add custom cron interval for polling.
	 *
	 * Untyped by design: cron_schedules is heavily filtered, and a third-party
	 * callback that forgets to return hands us null on every cron spawn.
	 *
	 * @param mixed $schedules Expected array<string, array<string, mixed>> of cron schedules.
	 * @return mixed Schedules with ours added, or $schedules untouched.
	 */
	public function add_cron_interval( $schedules = [] ) {
		if ( ! is_array( $schedules ) ) {
			return $schedules;
		}

		$schedules['surecookie_poll_interval'] = [
			'interval' => self::POLL_INTERVAL,
			'display'  => __( 'Every 1 Minute (SureCookie Polling)', 'surecookie' ),
		];
		return $schedules;
	}

	/**
	 * Get the SaaS API base URL.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public function get_api_base_url(): string {
		return Helper::get_agent_app_url() . 'api/';
	}

	/**
	 * Get the plaintext API key for HMAC signing.
	 *
	 * Issue #473: replaced the deterministic `md5(base64_encode($domain))` with a per-site
	 * `sk_*` key from the SaaS register flow. Stored in a dedicated non-autoloaded option
	 * bound to the registered domain - a clone/migration to a new domain invalidates it.
	 *
	 * @since 0.0.1-beta.3
	 * @return string The plaintext sk_* key, or empty string when not registered.
	 */
	public function get_api_key(): string {
		$creds = $this->get_credentials();
		if ( empty( $creds['api_key'] ) ) {
			return '';
		}

		return (string) $creds['api_key'];
	}

	/**
	 * Public key prefix (e.g. `sk_a1b2c3de`) used by the SaaS for indexed lookup.
	 *
	 * @since 0.0.1-beta.3
	 * @return string
	 */
	public function get_key_prefix(): string {
		$creds = $this->get_credentials();
		return (string) ( $creds['key_prefix'] ?? '' );
	}

	/**
	 * Check if a scan is already in progress.
	 *
	 * @since 0.0.1
	 * @return bool
	 */
	public function is_scan_in_progress(): bool {
		$active_scan = get_option( self::SCAN_OPTION_KEY, [] );

		if ( empty( $active_scan ) || empty( $active_scan['group_id'] ) ) {
			return false;
		}

		// Check if scan is stale (stuck/abandoned).
		// Timeout = max expected duration (30 min) + buffer (10 min) = 40 min.
		if ( isset( $active_scan['started_at'] ) ) {
			$max_duration  = self::MAX_POLL_ATTEMPTS * self::POLL_INTERVAL; // 1800 seconds (30 min).
			$stale_timeout = $max_duration + 600; // Add 10 min buffer = 2400 seconds (40 min).
			$elapsed       = time() - $active_scan['started_at'];

			if ( $elapsed > $stale_timeout ) {
				Logger::get_instance()->save_log( sprintf( 'Stale scan detected (running for %d minutes). Cleaning up.', round( $elapsed / 60 ) ) );
				$this->cleanup_active_scan();
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the active scan data.
	 *
	 * @since 0.0.1
	 * @return array<string, mixed>
	 */
	public function get_active_scan(): array {
		return get_option( self::SCAN_OPTION_KEY, [] );
	}

	/**
	 * Whether the current site runs on a localhost / development host that the
	 * remote scanner cannot reach. Honors the SURECOOKIE_ALLOW_LOCAL_SCAN
	 * constant and the `surecookie_bypass_local_url_check` filter.
	 *
	 * @since 1.2.1
	 * @return bool
	 */
	public static function is_local_site(): bool {
		return self::is_local_url( Utils::get_home_url() );
	}

	/**
	 * Start a new scan.
	 *
	 * @param array<int, string> $pages Array of page URLs to scan.
	 * @since 0.0.1
	 * @return array{success: bool, message?: string, code?: string, group_id?: string, deferred?: bool, limit?: int|null, used?: int|null, remaining?: int|null, plan?: string|null, pages_scanned?: int|null, pages_dropped?: int}
	 */
	public function start_scan( array $pages ): array {
		if ( self::is_local_site() ) {
			Logger::get_instance()->save_log( 'Scanning blocked: localhost sites are not supported.' );
			return [
				'success' => false,
				'code'    => 'local_site',
				'message' => __( 'Cookie scanning is not available for localhost or development sites. Please use a live domain.', 'surecookie' ),
			];
		}

		// Check for existing scan.
		if ( $this->is_scan_in_progress() ) {
			Logger::get_instance()->save_log( 'Scan already in progress. Bailing early.' );
			return [
				'success' => false,
				'message' => __( 'A scan is already in progress. Please wait for it to complete.', 'surecookie' ),
			];
		}

		if ( empty( $pages ) ) {
			return [
				'success' => false,
				'message' => __( 'No pages selected for scanning.', 'surecookie' ),
			];
		}

		$site_id = Utils::generate_site_id();
		// The Site Address, not the WordPress Address: this is the base the scanner
		// crawls and the one the SaaS registered the site under.
		$base_url = Utils::get_home_url();

		// 64-char hex token; site-context-bound while staying compatible with
		// Blocker::is_scan_bypass_request() strict token format validation.
		try {
			$scan_bypass_token = $this->generate_scan_bypass_token( $base_url );
		} catch ( \Throwable $e ) {
			Logger::get_instance()->save_log( 'Could not generate scan bypass token: ' . $e->getMessage() );
			return [
				'success' => false,
				'message' => __( 'Could not generate a secure scan token. Please retry.', 'surecookie' ),
			];
		}
		// TTL must cover the full scan window: max polling duration + stale buffer.
		// 30 attempts × 60s = 1800s + 600s buffer = 2400s (40 min).
		$bypass_ttl = ( self::MAX_POLL_ATTEMPTS * self::POLL_INTERVAL ) + 600;
		set_transient( self::SCAN_BYPASS_TRANSIENT_KEY, $scan_bypass_token, $bypass_ttl );

		// Reset per-scan reach + outcome so this scan's block detection is clean.
		delete_transient( self::SCAN_REACH_TRANSIENT );
		delete_transient( self::LAST_OUTCOME_TRANSIENT );

		$request_body = [
			'site_id'           => $site_id,
			'base_url'          => $base_url,
			'pages'             => $pages,
			'scan_bypass_token' => $scan_bypass_token,
		];

		$body = wp_json_encode( $request_body );

		if ( $body === false ) {
			Logger::get_instance()->save_log( 'Failed to encode request body.' );
			$this->clear_scan_bypass_token();
			return [
				'success' => false,
				'message' => __( 'Failed to encode scan request.', 'surecookie' ),
			];
		}

		// Issue #473: lazily register on first scan - a domain-control verification
		// challenge issues the key here, the only place the network handshake happens.
		$registration = $this->ensure_registered();
		if ( ! $registration['success'] ) {
			$this->clear_scan_bypass_token();
			return $registration;
		}

		$start_url = $this->get_api_base_url() . 'scan/start';
		$response  = wp_remote_post(
			$start_url,
			[
				'headers' => $this->get_request_headers( 'POST', $start_url, (string) $body ),
				'body'    => $body,
				'timeout' => 30,
			]
		);

		// One-shot retry if the SaaS reports an auth problem (e.g. our stored key was
		// invalidated by the server's MD5-key-cleanup migration); ensure_registered() rebuilds.
		if ( $this->handle_unauthorized_response( $response ) ) {
			$retry = $this->ensure_registered();
			if ( $retry['success'] ) {
				$response = wp_remote_post(
					$start_url,
					[
						'headers' => $this->get_request_headers( 'POST', $start_url, (string) $body ),
						'body'    => $body,
						'timeout' => 30,
					]
				);
			}
		}

		if ( is_wp_error( $response ) ) {
			Logger::get_instance()->save_log( 'Scan API request failed: ' . $response->get_error_message() );
			$this->clear_scan_bypass_token();
			return [
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Error message */
					__( 'Failed to start scan: %s', 'surecookie' ),
					$response->get_error_message()
				),
			];
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		// SaaS API returns group_id instead of scan_id (group-based scanning system).
		if ( $response_code !== 201 || empty( $data['group_id'] ) ) {
			// Scheduled scan the SaaS deferred because the daily page budget is exhausted
			// (see X-Scan-Trigger). Not a hard failure - signal the auto-scanning runner to
			// retry in ~24h instead of waiting for the next weekly/monthly cycle.
			if ( is_array( $data ) && ( $data['reason'] ?? null ) === 'defer' ) {
				$this->clear_scan_bypass_token();

				/**
				 * Fires when a scheduled scan is deferred by the SaaS for budget.
				 *
				 * @since 1.2.0
				 * @param array<string, mixed> $data Decoded SaaS response.
				 */
				do_action( 'surecookie_scan_deferred', $data );

				Logger::get_instance()->save_log( __( 'Scan deferred by server: daily page budget reached. It will retry automatically.', 'surecookie' ) );

				return [
					'success'   => false,
					'deferred'  => true,
					'message'   => $data['message'] ?? __( 'Daily page budget reached. The scheduled scan will retry automatically.', 'surecookie' ),
					'code'      => 'scan_deferred',
					'remaining' => $data['remaining'] ?? 0,
					'plan'      => $data['plan'] ?? null,
				];
			}

			$error_message = $data['message'] ?? __( 'Unknown error occurred.', 'surecookie' );
			$error_code    = $data['error'] ?? null; // Extract error code from response.

			// If error code is missing, use error message as fallback.
			if ( ! $error_code ) {
				$error_code = $data['error'] ?? 'unknown_error';
			}

			// Log detailed error information for debugging.
			Logger::get_instance()->save_log( sprintf( 'Scan API validation failed - Missing group_id or wrong status code. Error: %s (Code: %s)', $error_message, $error_code ) );

			// Log validation errors if present.
			if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
				foreach ( $data['errors'] as $field => $errors ) {
					if ( is_array( $errors ) ) {
						Logger::get_instance()->save_log( sprintf( 'Validation error - %s: %s', $field, implode( ', ', $errors ) ) );
					}
				}
			}

			$this->clear_scan_bypass_token();

			return [
				'success'   => false,
				'message'   => $error_message,
				'code'      => $error_code, // Pass error code to API response.
				'limit'     => $data['limit'] ?? null,
				'used'      => $data['used'] ?? null,
				'remaining' => $data['remaining'] ?? 0,
				'plan'      => $data['plan'] ?? null,
			];
		}

		// Persist the live quota snapshot from the start response so the banner shows the
		// just-decremented remaining-count without a second round-trip.
		if ( ! empty( $data['quota'] ) && is_array( $data['quota'] ) ) {
			$this->persist_quota( $data['quota'] );
		}

		// Store active scan data using group_id.
		$scan_data = [
			'group_id'      => $data['group_id'],
			'site_id'       => $site_id,
			'pages'         => $pages,
			'pages_count'   => count( $pages ),
			'total_scans'   => $data['total_scans'] ?? count( $pages ),
			'status'        => 'queued',
			'started_at'    => time(),
			'poll_attempts' => 0,
		];

		// A new run must not inherit the previous run's terminal marker.
		$this->clear_just_finished();

		// Non-autoloaded: this payload carries the full page list and is only read by the
		// scanner UI and poll cron, so it must not bloat the alloptions cache every request.
		Update::option( self::SCAN_OPTION_KEY, $scan_data );

		// Analytics: flag the first scan started for the state-based tracker
		// (`first_scan_started`). Written here - the single entry point both the manual
		// REST scan and scheduled cron funnel through - so it's set however the scan began.
		if ( ! get_option( 'surecookie_first_scan_started_flag', false ) ) {
			update_option( 'surecookie_first_scan_started_flag', true, false );
			update_option( 'surecookie_first_scan_pages_count', count( $pages ), false );
		}

		// Schedule polling cron.
		$this->schedule_polling();

		return [
			'success'       => true,
			'message'       => __( 'Scan has been started. You will be notified when it completes.', 'surecookie' ),
			'group_id'      => $data['group_id'],
			// Auto-trim feedback: when this site's daily page budget was
			// short, the SaaS scans what fits and reports how many pages it dropped.
			'pages_scanned' => $data['pages_scanned'] ?? null,
			'pages_dropped' => $data['pages_dropped'] ?? 0,
		];
	}

	/**
	 * Check the status of an active scan group.
	 *
	 * @param string $group_id The scan group ID to check.
	 * @since 0.0.1
	 * @return array{status: string, progress?: array{total_pages: int, completed_pages: int, failed_pages: int, pending_pages: int, percent?: int, pages_processed?: int, current_phase?: string}, error?: string, error_details?: array<int, array{url: string, error: string}>, estimated_remaining_seconds?: int}
	 */
	public function check_scan_status( string $group_id ): array {
		$status_url = $this->get_api_base_url() . 'scan/group/' . $this->sanitize_api_id( $group_id );
		$response   = wp_remote_get(
			$status_url,
			[
				'headers' => $this->get_request_headers( 'GET', $status_url ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			Logger::get_instance()->save_log( 'Status check failed: ' . $response->get_error_message() );
			return [
				'status' => 'error',
				'error'  => $response->get_error_message(),
			];
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( $response_code !== 200 ) {
			Logger::get_instance()->save_log( sprintf( 'Status check failed (HTTP %d): %s', $response_code, $response_body ) );
			return [
				'status' => 'error',
				'error'  => $data['message'] ?? $data['error'] ?? __( 'Failed to check scan status.', 'surecookie' ),
			];
		}

		// Parse group status and summary.
		$status  = (string) ( $data['status'] ?? 'unknown' );
		$summary = $data['summary'] ?? [];

		// Persist quota snapshot if the SaaS included one (PR 4 - quota cache stays warm during polling).
		if ( ! empty( $data['quota'] ) && is_array( $data['quota'] ) ) {
			$this->persist_quota( $data['quota'] );
		}

		// Build progress from summary.
		$result = [ 'status' => $status ];

		$scans = $data['scans'] ?? [];

		if ( ! empty( $summary ) ) {
			$result['progress'] = [
				'total_pages'     => (int) ( $summary['total'] ?? 0 ),
				'completed_pages' => (int) ( $summary['completed'] ?? 0 ),
				'failed_pages'    => (int) ( $summary['failed'] ?? 0 ),
				'pending_pages'   => (int) ( ( $summary['queued'] ?? 0 ) + ( $summary['running'] ?? 0 ) ),
			];

			/**
			 * Derive phase-aware progress from the SaaS per-scan payload (PR 1 Phase A).
			 * Each scan entry carries current_phase + progress_percent + pages_processed;
			 * aggregate across scans for a sensible multi-URL group bar:
			 * - percent = average of per-scan percents.
			 * - phase   = most-active phase among running scans (capturing > analyzing …).
			 *
			 * @since 0.0.1-beta.3
			 */
			if ( ! empty( $scans ) ) {
				$percents        = [];
				$pages_processed = 0;
				$phase_priority  = [
					'capturing'    => 5,
					'analyzing'    => 4,
					'initializing' => 3,
					'finalizing'   => 2,
					'queued'       => 1,
					'completed'    => 0,
					'failed'       => 0,
				];
				$active_phase    = null;
				$active_priority = -1;

				foreach ( $scans as $scan_entry ) {
					if ( isset( $scan_entry['progress_percent'] ) ) {
						$percents[] = (int) $scan_entry['progress_percent'];
					}
					if ( isset( $scan_entry['pages_processed'] ) ) {
						$pages_processed += (int) $scan_entry['pages_processed'];
					}
					$phase = isset( $scan_entry['current_phase'] ) ? (string) $scan_entry['current_phase'] : '';
					if ( $phase !== '' && isset( $phase_priority[ $phase ] ) && $phase_priority[ $phase ] > $active_priority ) {
						$active_priority = $phase_priority[ $phase ];
						$active_phase    = $phase;
					}
				}

				if ( ! empty( $percents ) ) {
					$result['progress']['percent'] = (int) round( array_sum( $percents ) / count( $percents ) );
				}
				$result['progress']['pages_processed'] = $pages_processed;
				if ( $active_phase !== null ) {
					$result['progress']['current_phase'] = $active_phase;
				}
			}

			// Extract estimated remaining time from SaaS response.
			if ( ! empty( $summary['estimated_remaining_seconds'] ) ) {
				$result['estimated_remaining_seconds'] = (int) $summary['estimated_remaining_seconds'];
			}
		}

		// Extract per-scan error details for failed pages.
		if ( ! empty( $scans ) ) {
			$error_details = [];
			foreach ( $scans as $scan ) {
				if ( ! empty( $scan['error'] ) ) {
					$error_details[] = [
						'url'   => (string) ( $scan['url'] ?? '' ),
						'error' => (string) $scan['error'],
					];
				}
			}
			if ( ! empty( $error_details ) ) {
				$result['error_details'] = $error_details;
			}
		}

		return $result;
	}

	/**
	 * Get scan results for a group.
	 *
	 * Fetches the group data, then retrieves individual scan results
	 * for each scan in the group to get complete cookie data.
	 *
	 * @param string $group_id The scan group ID to get results for.
	 * @since 0.0.1
	 * @return array{success: bool, data?: array<string, mixed>, message?: string}
	 */
	public function get_scan_results( string $group_id ): array {
		Logger::get_instance()->save_log( 'Fetching scan results for group: ' . $group_id );

		// First, get the group data to find individual scan IDs.
		$group_url = $this->get_api_base_url() . 'scan/group/' . $this->sanitize_api_id( $group_id );
		$response  = wp_remote_get(
			$group_url,
			[
				'headers' => $this->get_request_headers( 'GET', $group_url ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			Logger::get_instance()->save_log( 'Group fetch failed: ' . $response->get_error_message() );
			return [
				'success' => false,
				'message' => $response->get_error_message(),
			];
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$group_data    = json_decode( $response_body, true );

		if ( $response_code !== 200 ) {
			Logger::get_instance()->save_log( sprintf( 'Group fetch failed (HTTP %d): %s', $response_code, $response_body ) );
			return [
				'success' => false,
				'message' => $group_data['message'] ?? $group_data['error'] ?? __( 'Failed to get scan group.', 'surecookie' ),
			];
		}

		// Extract individual scans.
		$scans = $group_data['scans'] ?? [];

		if ( empty( $scans ) ) {
			Logger::get_instance()->save_log( 'No scans found in group.' );
			return [
				'success' => true,
				'data'    => [ 'pages' => [] ],
			];
		}

		// Fetch detailed results for each scan and combine into pages array.
		$pages = [];

		foreach ( $scans as $scan ) {
			$scan_id = $scan['scan_id'] ?? null;

			if ( empty( $scan_id ) ) {
				continue;
			}

			Logger::get_instance()->save_log( sprintf( 'Fetching detailed results for scan #%d', $scan_id ) );

			$scan_url      = $this->get_api_base_url() . 'scan/results/' . $this->sanitize_api_id( $scan_id );
			$scan_response = wp_remote_get(
				$scan_url,
				[
					'headers' => $this->get_request_headers( 'GET', $scan_url ),
					'timeout' => 30,
				]
			);

			if ( is_wp_error( $scan_response ) ) {
				Logger::get_instance()->save_log( sprintf( 'Scan #%d fetch failed: %s', $scan_id, $scan_response->get_error_message() ) );
				continue;
			}

			$scan_response_code = wp_remote_retrieve_response_code( $scan_response );
			$scan_response_body = wp_remote_retrieve_body( $scan_response );
			$scan_data          = json_decode( $scan_response_body, true );

			if ( $scan_response_code !== 200 || empty( $scan_data ) ) {
				Logger::get_instance()->save_log( sprintf( 'Scan #%d fetch failed (HTTP %d)', $scan_id, $scan_response_code ) );
				continue;
			}

			// The individual scan results endpoint returns a 'pages' array; merge into ours.
			if ( isset( $scan_data['pages'] ) && is_array( $scan_data['pages'] ) ) {
				foreach ( $scan_data['pages'] as $page ) {
					$pages[] = $page;
				}
				Logger::get_instance()->save_log( sprintf( 'Added %d page(s) from scan #%d', count( $scan_data['pages'] ), $scan_id ) );
			}
		}

		Logger::get_instance()->save_log( sprintf( 'Fetched results for %d scans.', count( $pages ) ) );

		return [
			'success' => true,
			'data'    => [ 'pages' => $pages ],
		];
	}

	/**
	 * Sync the active scan status from the SaaS on-demand (read-path trigger).
	 *
	 * The admin UI polls status every few seconds while a scan runs. Normally the
	 * WP-Cron hook {@see self::poll_scan_status()} advances the stored status, but where
	 * WP-Cron is unreliable (DISABLE_WP_CRON with no server cron, or full-page caching)
	 * it never runs and the scan looks stuck at "queued" though the SaaS already finished.
	 *
	 * This bridges the gap: on a status read with a non-terminal scan active, pull the
	 * live status (throttled to one call per {@see self::POLL_INTERVAL}s) so the scan
	 * resolves regardless of WP-Cron health. The cron poll stays as a backup.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function sync_active_scan_status(): void {
		$active_scan = $this->get_active_scan();

		// Nothing to sync without an active scan tied to a SaaS group. A completed/failed
		// scan is already cleaned up, so this is also a no-op once terminal.
		if ( empty( $active_scan['group_id'] ) ) {
			return;
		}

		// Throttle: at most one on-demand SaaS pull per poll interval, so the UI's frequent
		// polling neither hammers the SaaS nor burns MAX_POLL_ATTEMPTS faster than cron.
		$last_sync = (int) ( $active_scan['last_on_demand_sync_at'] ?? 0 );
		if ( time() - $last_sync < self::POLL_INTERVAL ) {
			return;
		}

		$active_scan['last_on_demand_sync_at'] = time();
		Update::option( self::SCAN_OPTION_KEY, $active_scan );

		// Reuse the full cron poll routine so progress, completion, failure handling and
		// result fetching behave identically whether triggered by WP-Cron or this sync.
		$this->poll_scan_status();
	}

	/**
	 * Poll scan status (cron callback, also run by the on-demand sync).
	 *
	 * One poll at a time, held through result fetching: WP-Cron and the on-demand
	 * sync both land here, and a second entry that also saw "completed" fetched and
	 * processed the same results again and interleaved the progress log.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function poll_scan_status(): void {
		if ( ! $this->acquire_poll_lock() ) {
			return;
		}

		try {
			// Read the scan under the claim, from the table: the poll that just released
			// it may have finished the scan, and this request's option cache would not know.
			wp_cache_delete( self::SCAN_OPTION_KEY, 'options' );
			$active_scan = $this->get_active_scan();

			if ( empty( $active_scan['group_id'] ) ) {
				$this->unschedule_polling();
				return;
			}

			$this->run_poll( $active_scan );
		} finally {
			$this->release_poll_lock();
		}
	}

	/**
	 * One status pull for the claimed scan, through completion or failure.
	 *
	 * @param array<string, mixed> $active_scan The active scan option value.
	 * @since 1.7.0
	 * @return void
	 */
	private function run_poll( array $active_scan ): void {
		$group_id      = $active_scan['group_id'];
		$poll_attempts = $active_scan['poll_attempts'] ?? 0;

		Logger::get_instance()->save_log( sprintf( 'Status check: attempt %d of %d.', $poll_attempts + 1, self::MAX_POLL_ATTEMPTS ) );

		// Check if max attempts exceeded.
		if ( $poll_attempts >= self::MAX_POLL_ATTEMPTS ) {
			Logger::get_instance()->save_log( 'Max polling attempts reached. Marking scan as failed.' );
			$this->handle_scan_failure( __( 'Scan timed out. Please try again.', 'surecookie' ) );
			return;
		}

		// Increment poll attempts.
		$active_scan['poll_attempts'] = $poll_attempts + 1;
		Update::option( self::SCAN_OPTION_KEY, $active_scan );

		// Check status.
		$status_result = $this->check_scan_status( $group_id );

		if ( $status_result['status'] === 'error' ) {
			Logger::get_instance()->save_log( 'Status check returned error: ' . ( $status_result['error'] ?? 'Unknown' ) );
			// Continue polling, might be temporary network issue.
			return;
		}

		Logger::get_instance()->save_log( sprintf( 'Current status: %s.', $status_result['status'] ) );

		// Persist progress, errors, and ETA into active scan option for frontend display.
		$needs_update = false;

		if ( ! empty( $status_result['progress'] ) ) {
			$prev_completed = $active_scan['progress']['completed_pages'] ?? 0;
			$new_completed  = $status_result['progress']['completed_pages'] ?? 0;

			$active_scan['progress'] = $status_result['progress'];
			$needs_update            = true;

			// Track when progress last changed for smart stuck detection.
			if ( $new_completed !== $prev_completed ) {
				$active_scan['last_progress_change_at'] = time();
			}

			// Log user-friendly progress message.
			Logger::get_instance()->save_log(
				sprintf(
					/* translators: 1: completed pages count, 2: total pages count */
					__( 'Progress: %1$d of %2$d pages scanned.', 'surecookie' ),
					$status_result['progress']['completed_pages'],
					$status_result['progress']['total_pages']
				)
			);

			if ( $status_result['progress']['failed_pages'] > 0 ) {
				Logger::get_instance()->save_log(
					sprintf(
						/* translators: %d: number of failed pages */
						__( '%d page(s) failed to scan.', 'surecookie' ),
						$status_result['progress']['failed_pages']
					)
				);
			}
		}

		if ( ! empty( $status_result['error_details'] ) ) {
			$active_scan['error_details'] = $status_result['error_details'];
			$needs_update                 = true;
		}

		if ( ! empty( $status_result['estimated_remaining_seconds'] ) ) {
			$active_scan['estimated_remaining_seconds'] = $status_result['estimated_remaining_seconds'];
			$needs_update                               = true;
		}

		if ( $needs_update ) {
			Update::option( self::SCAN_OPTION_KEY, $active_scan );
		}

		switch ( $status_result['status'] ) {
			case 'completed':
				Logger::get_instance()->save_log( __( 'Scan completed!', 'surecookie' ) );
				Logger::get_instance()->save_log( '' ); // Blank line.
				$this->handle_scan_completion( $group_id );
				break;

			case 'failed':
				// Log per-page error details so users can see what went wrong.
				if ( ! empty( $status_result['error_details'] ) ) {
					foreach ( $status_result['error_details'] as $detail ) {
						Logger::get_instance()->save_log(
							sprintf(
								/* translators: 1: page URL, 2: error message */
								__( 'Page failed: %1$s - %2$s', 'surecookie' ),
								$detail['url'],
								$detail['error']
							)
						);
					}
				}
				$this->handle_scan_failure( $status_result['error'] ?? __( 'Scan failed on server.', 'surecookie' ) );
				break;

			case 'queued':
			case 'running':
				// Continue polling.
				Logger::get_instance()->save_log( __( 'Scan is in progress. Checking again…', 'surecookie' ) );
				Logger::get_instance()->save_log( '' ); // Blank line.
				break;

			default:
				Logger::get_instance()->save_log( 'Unknown status: ' . $status_result['status'] );
				break;
		}
	}

	/**
	 * Cleanup active scan data.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function cleanup_active_scan(): void {
		delete_option( self::SCAN_OPTION_KEY );
		$this->clear_scan_bypass_token();
		$this->unschedule_polling();
	}

	/**
	 * Record that a cloud scan just ended. Set before the active scan clears, so
	 * the first status read that finds it idle reports the finish.
	 *
	 * @param array<string, mixed>              $progress      Final progress payload.
	 * @param array<int, array<string, string>> $error_details Per-page failures.
	 * @since 1.7.0
	 * @return void
	 */
	public function mark_just_finished( array $progress, array $error_details = [] ): void {
		set_transient(
			self::JUST_FINISHED_TRANSIENT,
			[
				'progress' => $progress,
				'errors'   => $error_details,
				'at'       => time(),
			],
			self::JUST_FINISHED_TTL
		);
	}

	/**
	 * Drop the terminal marker, so a new or cancelled scan cannot report an
	 * earlier one as finished.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function clear_just_finished(): void {
		delete_transient( self::JUST_FINISHED_TRANSIENT );
	}

	/**
	 * Terminal status for a cloud scan that just ended, or null when there is none.
	 *
	 * Mirrors AssistedScan\Session::peek_just_finished(). Not consumed on read: two
	 * polls landing together would otherwise report finished then idle faster than
	 * the screen can see the transition, so it expires on its own. Status stays
	 * `idle` so the progress bar clears; `just_finished` is what completion keys on.
	 *
	 * @since 1.7.0
	 * @return array<string, mixed>|null
	 */
	public function peek_just_finished(): ?array {
		$marker = get_transient( self::JUST_FINISHED_TRANSIENT );

		if ( ! is_array( $marker ) ) {
			return null;
		}

		$progress = is_array( $marker['progress'] ?? null ) ? $marker['progress'] : [];
		$errors   = is_array( $marker['errors'] ?? null ) ? $marker['errors'] : [];
		$outcome  = get_transient( self::LAST_OUTCOME_TRANSIENT );

		return [
			'in_progress'   => false,
			'status'        => 'idle',
			'just_finished' => true,
			'scan_mode'     => 'cloud',
			'pages_count'   => (int) ( $progress['total_pages'] ?? 0 ),
			'progress'      => $progress,
			'error_details' => $errors,
			'message'       => __( 'Scan finished.', 'surecookie' ),
			'last_outcome'  => is_array( $outcome ) ? $outcome : null,
		];
	}

	/**
	 * Fetch the daily quota from SaaS (GET /api/scan/quota).
	 *
	 * Network errors keep the cached value (offline fallback). Auth errors
	 * (401/403/auth_*) invalidate the cache. Other HTTP errors keep the cache
	 * and surface an `error_code` so the UI can react.
	 *
	 * @since 0.0.1-beta.3
	 * @return array{success: bool, quota: array<string, mixed>, plan?: string, message?: string, error_code?: string}
	 */
	public function get_quota(): array {
		$site_url = Utils::get_home_url();

		// Localhost / dev sites can't reach the SaaS - the request would 404 every poll
		// and spam the log. Surface a dedicated error_code so the UI can react.
		if ( self::is_local_url( $site_url ) ) {
			Logger::get_instance()->save_log( 'Quota fetch blocked: localhost sites are not supported.' );
			return [
				'success'    => false,
				'message'    => __( 'Quota is not available on localhost or development sites.', 'surecookie' ),
				'error_code' => 'local_site',
				'quota'      => $this->get_cached_quota(),
			];
		}

		// Issue #473: a fresh install has no credentials yet. Surface a typed error_code so
		// the UI renders a "register first" state instead of an unhelpful 401. We do NOT
		// auto-register here - registration needs an explicit user action (clicking Scan),
		// not a passive quota fetch on page load.
		if ( $this->get_api_key() === '' ) {
			return [
				'success'    => false,
				'message'    => __( 'Scanner credentials are not yet issued. Run your first scan to register.', 'surecookie' ),
				'error_code' => 'not_registered',
				'quota'      => $this->get_cached_quota(),
			];
		}

		// AuthenticateScanRequest derives the site from the signed key prefix -
		// base_url/site_id in the query are kept for log/debug usefulness only.
		$url = add_query_arg(
			[
				'base_url' => $site_url,
				'site_id'  => Utils::generate_site_id(),
			],
			$this->get_api_base_url() . 'scan/quota'
		);

		$response = wp_remote_get(
			$url,
			[
				'headers' => $this->get_request_headers( 'GET', $url ),
				'timeout' => 10,
			]
		);

		// Genuine network error - keep the cached value so the user can keep working.
		if ( is_wp_error( $response ) ) {
			Logger::get_instance()->log( 'Quota fetch failed (network): ' . $response->get_error_message(), 'error' );
			return [
				'success' => false,
				'message' => $response->get_error_message(),
				'quota'   => $this->get_cached_quota(),
			];
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		// Treat malformed/empty JSON as a transient hiccup (keep cache, distinct error code).
		if ( ! is_array( $data ) ) {
			Logger::get_instance()->log(
				sprintf( 'Quota fetch returned HTTP %d with non-JSON body', $response_code ),
				'error'
			);
			return [
				'success'    => false,
				'message'    => __( 'Unexpected response from server.', 'surecookie' ),
				'error_code' => 'invalid_json',
				'quota'      => $this->get_cached_quota(),
			];
		}

		if ( $response_code !== 200 || empty( $data['success'] ) ) {
			$saas_error_code = isset( $data['error'] ) ? (string) $data['error'] : '';
			$saas_message    = isset( $data['message'] ) ? (string) $data['message'] : '';

			// Build a useful detail string for the dev log: error code → message → first 120 chars of body.
			if ( $saas_error_code !== '' ) {
				$log_detail = $saas_error_code;
			} elseif ( $saas_message !== '' ) {
				$log_detail = $saas_message;
			} else {
				$body_snippet = trim( wp_strip_all_tags( (string) $response_body ) );
				$log_detail   = $body_snippet !== ''
					? 'no_error_code (body: ' . mb_substr( $body_snippet, 0, 120 ) . ')'
					: 'no_error_code';
			}

			// Dev-only: route to error_log via Logger::log() instead of the user-facing scan progress log.
			Logger::get_instance()->log(
				sprintf( 'Quota fetch returned HTTP %d: %s', $response_code, $log_detail ),
				'error'
			);

			// Distinguish auth failures (plan/connection actually changed - bust cache so we
			// don't show stale tier numbers) from transient HTTP errors like 5xx/429/JSON
			// parse failures (cache stays so the banner doesn't flicker during a hiccup).
			$is_auth_error = in_array( $response_code, [ 401, 403 ], true )
				|| in_array(
					$saas_error_code,
					[
						'auth_required',
						'auth_token_invalid',
						'license_verification_failed',
						'user_verification_failed',
					],
					true
				);

			if ( $is_auth_error ) {
				$this->invalidate_quota_cache();
				return [
					'success'    => false,
					'message'    => $data['message'] ?? __( 'Authentication required.', 'surecookie' ),
					'error_code' => $saas_error_code !== '' ? $saas_error_code : 'http_' . $response_code,
					'quota'      => [],
				];
			}

			// Non-auth HTTP error: keep the cache, surface the failure but let the
			// banner stay at its prior reading via the cached value.
			return [
				'success'    => false,
				'message'    => $data['message'] ?? __( 'Failed to fetch quota.', 'surecookie' ),
				'error_code' => $saas_error_code !== '' ? $saas_error_code : 'http_' . $response_code,
				'quota'      => $this->get_cached_quota(),
			];
		}

		$quota = is_array( $data['quota'] ?? null ) ? $data['quota'] : [];
		$plan  = (string) ( $data['plan'] ?? '' );
		// Persist plan alongside quota so cache-hit reads return the SaaS-reported plan
		// instead of the local `surecookie_plan_type` default ("free"), mislabeling paid tiers.
		$this->persist_quota( $quota, $plan );

		return [
			'success' => true,
			'quota'   => $quota,
			'plan'    => $plan,
		];
	}

	/**
	 * Wipe the cached quota (transient + option) on auth failures so stale tier numbers don't survive a license/auth change.
	 *
	 * @since 0.0.1-beta.3
	 * @return void
	 */
	public function invalidate_quota_cache(): void {
		delete_transient( self::QUOTA_TRANSIENT_KEY );
		delete_option( self::LAST_KNOWN_QUOTA_OPTION );
	}

	/**
	 * Persist a quota snapshot to the 10-min transient and long-lived fallback option.
	 * Optionally caches the SaaS-reported plan tier (under `_plan`) so cache-hit reads
	 * return the actual plan instead of the local `surecookie_plan_type` default.
	 *
	 * @param array<string, mixed> $quota Quota snapshot from SaaS.
	 * @param string|null          $plan  Plan tier reported by SaaS (e.g. 'free', 'team', 'agency', 'solo').
	 * @since 0.0.1-beta.3
	 * @return void
	 */
	public function persist_quota( array $quota, ?string $plan = null ): void {
		if ( empty( $quota ) ) {
			return;
		}
		if ( $plan !== null && $plan !== '' ) {
			$quota['_plan'] = $plan;
		}
		set_transient( self::QUOTA_TRANSIENT_KEY, $quota, self::QUOTA_TRANSIENT_TTL );
		// Non-autoloaded so the quota array doesn't bloat the alloptions cache on every request.
		if ( get_option( self::LAST_KNOWN_QUOTA_OPTION, false ) === false ) {
			add_option( self::LAST_KNOWN_QUOTA_OPTION, $quota, '', false );
		} else {
			update_option( self::LAST_KNOWN_QUOTA_OPTION, $quota );
		}
	}

	/**
	 * Read cached quota: transient first, last-known option as fallback.
	 *
	 * @since 0.0.1-beta.3
	 * @return array<string, mixed>
	 */
	public function get_cached_quota(): array {
		$transient = get_transient( self::QUOTA_TRANSIENT_KEY );
		if ( is_array( $transient ) && ! empty( $transient ) ) {
			return $transient;
		}
		$option = get_option( self::LAST_KNOWN_QUOTA_OPTION, [] );
		return is_array( $option ) ? $option : [];
	}

	/**
	 * Return the SaaS-reported plan stored in the cached quota, or empty string if absent.
	 *
	 * @since 0.0.1-beta.3
	 * @return string
	 */
	public function get_cached_plan(): string {
		$cached = $this->get_cached_quota();
		// Scan-path writes re-persist the snapshot without the `_plan` sentinel, so fall back
		// to the plan the SaaS snapshot itself carries rather than reporting nothing.
		return (string) ( $cached['_plan'] ?? $cached['plan'] ?? '' );
	}

	/**
	 * Push the billing-account / license linkage to the SaaS (issue #469).
	 *
	 * @since 0.0.1-beta.3
	 *
	 * @param string      $account_ref Opaque UUID from billing portal OAuth.
	 * @param string|null $license_id  SureCart license UUID (Pro path only).
	 * @param string|null $license_key SureCart license key - one-time only.
	 * @return bool True only when the SaaS confirmed the link. Everything else
	 *              (unregistered, nothing to send, transport, auth, quota or
	 *              validation failure) is false, so the caller keeps retrying.
	 */
	public function link_billing_account( string $account_ref, ?string $license_id = null, ?string $license_key = null ): bool {

		if ( $this->get_api_key() === '' ) {
			return false; // Site not registered; caller re-fires after registration.
		}

		$payload = [];
		if ( $account_ref !== '' ) {
			$payload['billing_account_ref'] = $account_ref;
		}
		if ( $license_id !== null && $license_id !== '' ) {
			$payload['license_id'] = $license_id;
		}
		if ( $license_key !== null && $license_key !== '' ) {
			$payload['license_key'] = $license_key;
		}

		if ( $payload === [] ) {
			return false;
		}

		$body = (string) wp_json_encode( $payload );
		$url  = $this->get_api_base_url() . 'site/billing-link';

		$response = wp_remote_post(
			$url,
			[
				// Only a license_key makes the SaaS verify with SureCart, so the Pro path
				// gets room for that hop while the OAuth callback keeps a short ceiling.
				'timeout' => isset( $payload['license_key'] ) ? 15 : 5,
				'headers' => $this->get_request_headers( 'POST', $url, $body ),
				'body'    => $body,
			]
		);

		// Warning, not error: Logger routes 'error' to WP_CLI::error(), which exits 1.
		if ( is_wp_error( $response ) ) {
			Logger::get_instance()->log( 'Billing link failed: ' . $response->get_error_message(), 'warning' );
			return false;
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );
		$data          = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		// A 401 is deliberately not routed through handle_unauthorized_response(): it treats
		// site_not_verified as recoverable and clears credentials, which from this background
		// push would destroy a registration handshake that is still in progress.
		if ( $response_code !== 200 || ! is_array( $data ) || empty( $data['success'] ) ) {
			$error_code = is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : 'no_error_code';
			Logger::get_instance()->log( sprintf( 'Billing link failed (HTTP %d): %s', $response_code, $error_code ), 'warning' );
			return false;
		}

		return true;
	}

	/**
	 * Re-run step 2 after the user has published the DNS record.
	 *
	 * @since 1.5.0
	 * @return array{success: bool, message?: string, code?: string, diagnosis?: string, dns_verification?: array<string, string>|null, verify_url?: string|null}
	 */
	public function retry_registration(): array {
		if ( $this->get_api_key() !== '' ) {
			return [ 'success' => true ];
		}

		// Same lock register_site() runs behind: two Retry clicks inside the window
		// would otherwise both complete the handshake and mint a second key.
		if ( get_transient( self::REGISTERING_LOCK_TRANSIENT ) !== false ) {
			return [
				'success' => false,
				'code'    => 'registration_in_progress',
				'message' => __( 'A verification attempt is already running. Please try again in a moment.', 'surecookie' ),
			];
		}
		set_transient( self::REGISTERING_LOCK_TRANSIENT, 1, self::REGISTERING_LOCK_TTL );

		try {
			return $this->run_pending_handshake();
		} finally {
			delete_transient( self::REGISTERING_LOCK_TRANSIENT );
		}
	}

	/**
	 * Queue the first-party loopback fallback to run once in the background.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	private function schedule_first_party_detection(): void {
		if ( ! wp_next_scheduled( self::FIRST_PARTY_DETECT_HOOK ) ) {
			wp_schedule_single_event( time(), self::FIRST_PARTY_DETECT_HOOK );
		}
	}

	/**
	 * Run the first-party loopback detector over the configured scan pages.
	 * Never throws - detection is best-effort and must not break the results
	 * pipeline.
	 *
	 * @since 1.3.0
	 * @return array<string, string> Detected services as id => label.
	 */
	private function detect_services_first_party(): array {
		try {
			$urls = Cron::get_instance()->get_pages_urls_to_scan();
			if ( empty( $urls ) ) {
				return [];
			}
			return LoopbackScanner::get_instance()->detect_services( $urls );
		} catch ( \Throwable $e ) {
			Logger::get_instance()->save_log( 'First-party fallback detection failed: ' . $e->getMessage() );
			return [];
		}
	}

	/**
	 * Diagnose why domain verification (the SaaS's inbound GET to our public
	 * /wp-json verify route) failed, by probing that route over a loopback
	 * request to ourselves:
	 *  - An SSL-validating HTTPS request failing with a cert error means the certificate
	 *    is self-signed, expired, or wrong-domain. A common silent cause: humans click
	 *    past the browser warning, but our server cannot.
	 *  - A reachable route replying 404 {"error":"not_found"} to a bad token means an
	 *    EXTERNAL block (host firewall/anti-bot).
	 *  - Anything else (timeout, non-JSON, redirect) means the REST API is unreachable
	 *    (permalinks off / security plugin disabling REST).
	 *
	 * @since 1.3.0
	 * @return string 'ssl_invalid' | 'external_block' | 'rest_unreachable'
	 */
	private function diagnose_verification_failure(): string {
		$probe_url = rest_url( 'surecookie/v1/site-verify/' . str_repeat( '0', 64 ) );

		// First validate the certificate the way the SaaS does (HTTPS, SSL verify on): a
		// self-signed/expired/mismatched cert fails with a cert-specific error, never reaching the route.
		$ssl_probe = wp_remote_get(
			$probe_url,
			[
				'timeout'   => 10,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $ssl_probe ) ) {
			$error = strtolower( $ssl_probe->get_error_message() );
			if ( strpos( $error, 'certificate' ) !== false || strpos( $error, 'ssl' ) !== false ) {
				return 'ssl_invalid';
			}
		}

		// Then check reachability with SSL verification off, so a bad certificate
		// does not mask an actual block or an unreachable REST API.
		$response = wp_remote_get(
			$probe_url,
			[
				'timeout'   => 10,
				// Loopback to ourselves; some local/staging stacks use self-signed certs.
				'sslverify' => false,
			]
		);

		if ( is_wp_error( $response ) ) {
			return 'rest_unreachable';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code === 404 && is_array( $body ) && ( $body['error'] ?? '' ) === 'not_found' ) {
			return 'external_block';
		}

		return 'rest_unreachable';
	}

	/**
	 * Generate a site-context-bound scan bypass token.
	 *
	 * Keeps a 64-char hex shape so blocker validation remains unchanged.
	 *
	 * @param string $base_url Site URL context for binding.
	 * @since 0.0.1-beta.3
	 * @throws \Exception When CSPRNG bytes cannot be generated.
	 * @return string
	 */
	private function generate_scan_bypass_token( string $base_url ): string {
		$token_input = $base_url . ':' . microtime( true ) . ':' . bin2hex( random_bytes( 32 ) );
		return hash_hmac( 'sha256', 'scan-bypass:' . $token_input, wp_salt( 'auth' ) );
	}

	/**
	 * Remove the temporary scan bypass token transient.
	 *
	 * @since 0.0.1-beta.3
	 * @return void
	 */
	private function clear_scan_bypass_token(): void {
		delete_transient( self::SCAN_BYPASS_TRANSIENT_KEY );
	}

	/**
	 * Read the stored credentials, returning [] if absent or if the registered
	 * domain no longer matches the current site URL (clone/migration drift).
	 *
	 * The domain-drift purge is critical: copying a WP install to a new host must not let
	 * it use the original's credentials. The next scan triggers a fresh registration.
	 *
	 * @since 0.0.1-beta.3
	 * @return array<string, mixed>
	 */
	private function get_credentials(): array {
		$creds = get_option( SURECOOKIE_SITE_CREDENTIALS_OPTION, [] );
		if ( ! is_array( $creds ) || empty( $creds['api_key'] ) || empty( $creds['registered_domain'] ) ) {
			return [];
		}

		// The Site Address is what the SaaS keys identity on and what every scanned
		// permalink carries. Watching the WordPress Address instead meant a site whose
		// two addresses differ never detected drift, so it kept signing as a site the
		// same-origin check then refused - permanently, with no way back.
		$current_domain = wp_parse_url( Utils::get_home_url(), PHP_URL_HOST );
		if ( $creds['registered_domain'] !== $current_domain ) {
			Logger::get_instance()->save_log(
				sprintf(
					'Domain drift detected (registered=%s, current=%s). Clearing credentials.',
					(string) $creds['registered_domain'],
					(string) $current_domain
				)
			);
			delete_option( SURECOOKIE_SITE_CREDENTIALS_OPTION );
			return [];
		}

		return $creds;
	}

	/**
	 * Wipe stored credentials. Called on 401 responses indicating the SaaS
	 * has invalidated the key (rotation, MD5 invalidation migration, etc.).
	 *
	 * @since 0.0.1-beta.3
	 * @return void
	 */
	private function clear_credentials(): void {
		delete_option( SURECOOKIE_SITE_CREDENTIALS_OPTION );
	}

	/**
	 * Ensure the site is registered with the SaaS. Idempotent; only performs
	 * the network flow when credentials are missing.
	 *
	 * Uses a transient lock to prevent a "Scan now" double-click from kicking
	 * off two concurrent registrations.
	 *
	 * @since 0.0.1-beta.3
	 * @return array{success: bool, message?: string}
	 */
	private function ensure_registered(): array {
		if ( $this->get_api_key() !== '' ) {
			return [ 'success' => true ];
		}

		// Lock to serialize concurrent ensure_registered() invocations.
		if ( get_transient( self::REGISTERING_LOCK_TRANSIENT ) === false ) {
			set_transient( self::REGISTERING_LOCK_TRANSIENT, 1, self::REGISTERING_LOCK_TTL );
		} else {
			return [
				'success' => false,
				'message' => __( 'Another registration is already in progress. Please try again in a moment.', 'surecookie' ),
			];
		}

		try {
			return $this->register_site();
		} finally {
			delete_transient( self::REGISTERING_LOCK_TRANSIENT );
		}
	}

	/**
	 * Run the two-step registration handshake with the SaaS.
	 *
	 *  Step 1: POST /api/register with site_url + admin_email + install_nonce.
	 *          SaaS returns a verification_token and the path it expects to find it at.
	 *  Step 2: Stash the token in a transient that the REST verification handler reads.
	 *  Step 3: POST /api/register/complete - SaaS HTTP-GETs the REST handler, verifies
	 *          the response matches, then issues the sk_* key.
	 *  Step 4: Persist the issued key to the dedicated wp_option.
	 *
	 * @since 0.0.1-beta.3
	 * @return array{success: bool, message?: string, code?: string, diagnosis?: string, dns_verification?: array<string, string>|null, verify_url?: string|null}
	 */
	private function register_site(): array {
		$site_url = Utils::get_site_url();
		// Identity is the Site Address; $site_url still goes on the wire so the SaaS
		// can record the WordPress Address alongside it.
		$domain = wp_parse_url( Utils::get_home_url(), PHP_URL_HOST );
		if ( empty( $domain ) ) {
			return [
				'success' => false,
				'message' => __( 'Could not determine site domain.', 'surecookie' ),
			];
		}

		// random_bytes() throws on CSPRNG unavailability - fail gracefully
		// instead of fataling the whole scan-start flow.
		try {
			$install_nonce = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			Logger::get_instance()->save_log( 'Could not generate install_nonce: ' . $e->getMessage() );
			return [
				'success' => false,
				'message' => __( 'Could not generate a secure registration nonce. Please retry.', 'surecookie' ),
			];
		}
		$admin_email = (string) get_option( 'admin_email', '' );

		$register_body = [
			'site_url'       => $site_url,
			// Where the REST API actually is. site_url() is the WordPress
			// Address and the two differ on a subdirectory install, so sending
			// only the former left the SaaS guessing (surecookie-saas#250).
			'home_url'       => Utils::get_home_url(),
			'admin_email'    => $admin_email,
			'install_nonce'  => $install_nonce,
			'plugin_version' => SURECOOKIE_VERSION,
		];

		/**
		 * Filter the registration request body.
		 *
		 * Allows Pro (or third parties) to inject additional metadata.
		 * Does NOT permit short-circuiting the key - only augmenting the request.
		 *
		 * @since 0.0.1-beta.3
		 * @param array<string, mixed> $register_body Body sent to /api/register.
		 * @param string               $site_url       Current site URL.
		 */
		$register_body = (array) apply_filters( 'surecookie_register_site_args', $register_body, $site_url );

		$encoded = wp_json_encode( $register_body );
		if ( $encoded === false ) {
			return [
				'success' => false,
				'message' => __( 'Failed to encode registration request.', 'surecookie' ),
			];
		}

		$register_resp = wp_remote_post(
			$this->get_api_base_url() . 'register',
			[
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => $encoded,
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $register_resp ) ) {
			Logger::get_instance()->save_log( 'Registration step 1 failed: ' . $register_resp->get_error_message() );
			return [
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message from the network call */
					__( 'Could not reach the SureCookie service: %s', 'surecookie' ),
					$register_resp->get_error_message()
				),
			];
		}

		$step1_code = (int) wp_remote_retrieve_response_code( $register_resp );
		$step1_data = json_decode( (string) wp_remote_retrieve_body( $register_resp ), true );
		if ( $step1_code !== 202 || ! is_array( $step1_data ) || empty( $step1_data['verification_token'] ) || empty( $step1_data['site_id'] ) ) {
			Logger::get_instance()->save_log( sprintf( 'Registration step 1 returned unexpected response (HTTP %d).', $step1_code ) );
			return [
				'success' => false,
				'message' => is_array( $step1_data ) && ! empty( $step1_data['message'] )
					? (string) $step1_data['message']
					: __( 'Registration could not be initiated.', 'surecookie' ),
			];
		}

		$verification_token = (string) $step1_data['verification_token'];
		$wp_site_id         = (string) $step1_data['site_id'];

		// The REST verification route only matches 64-char lowercase hex (see
		// VerificationHandler::register_routes). A non-conforming token (uppercase, wrong
		// length, base64) would never match the route, hanging registration for the full
		// 5s timeout - surface the mismatch immediately.
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $verification_token ) ) {
			Logger::get_instance()->save_log( 'Registration step 1 returned a malformed verification token.' );
			return [
				'success' => false,
				'message' => __( 'Received an invalid verification token. Please retry.', 'surecookie' ),
			];
		}

		$completion = $this->complete_registration( $wp_site_id, $install_nonce, $verification_token );
		if ( ! $completion['success'] ) {
			return $completion;
		}

		return $this->finalize_registration( $completion['data'], (string) $domain );
	}

	/**
	 * Run step 2 of the handshake: ask the SaaS to verify and issue the key.
	 *
	 * Split out so the DNS-fallback retry can re-run it without re-running step 1, which
	 * would mint a fresh token and invalidate any TXT record the user has published.
	 *
	 * @param string $wp_site_id         Site id issued by step 1.
	 * @param string $install_nonce      Nonce the pending registration was opened with.
	 * @param string $verification_token Token the REST handler should serve.
	 * @since 1.5.0
	 * @return array{success: true, data: array<string, mixed>}|array{success: false, message?: string, code?: string, diagnosis?: string, dns_verification?: array<string, string>|null, verify_url?: string|null} Success with `data`, or a failure describing why.
	 */
	private function complete_registration( string $wp_site_id, string $install_nonce, string $verification_token ): array {
		$complete_body = wp_json_encode(
			[
				'wp_site_id'    => $wp_site_id,
				'install_nonce' => $install_nonce,
			]
		);
		if ( $complete_body === false ) {
			delete_transient( self::PENDING_VERIFICATION_TRANSIENT );
			return [
				'success' => false,
				'message' => __( 'Failed to encode verification completion.', 'surecookie' ),
			];
		}

		// Make the token discoverable by the REST verification handler. The handler
		// returns this value when the SaaS GETs /site-verify/{token}. Set on every
		// attempt, so a retry still tries HTTP before falling back to DNS.
		set_transient( self::PENDING_VERIFICATION_TRANSIENT, $verification_token, self::PENDING_VERIFICATION_TTL );

		$complete_resp = wp_remote_post(
			$this->get_api_base_url() . 'register/complete',
			[
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => $complete_body,
				'timeout' => 15,
			]
		);

		// Verification token is one-shot regardless of outcome.
		delete_transient( self::PENDING_VERIFICATION_TRANSIENT );

		if ( is_wp_error( $complete_resp ) ) {
			Logger::get_instance()->save_log( 'Registration step 2 failed: ' . $complete_resp->get_error_message() );
			return [
				'success' => false,
				'message' => $complete_resp->get_error_message(),
			];
		}

		$step2_code = (int) wp_remote_retrieve_response_code( $complete_resp );
		$step2_data = json_decode( (string) wp_remote_retrieve_body( $complete_resp ), true );
		$step2_data = is_array( $step2_data ) ? $step2_data : [];

		if ( $step2_code === 201 && ! empty( $step2_data['api_key'] ) && ! empty( $step2_data['key_prefix'] ) ) {
			delete_transient( self::PENDING_HANDSHAKE_TRANSIENT );
			return [
				'success' => true,
				'data'    => $step2_data,
			];
		}

		return $this->describe_completion_failure(
			$step2_code,
			$step2_data,
			$wp_site_id,
			$install_nonce
		);
	}

	/**
	 * Turn a failed step 2 into a message the user can act on.
	 *
	 * The server distinguishes five causes and only one of them is about a firewall, so the
	 * server's own `error` decides the wording. The local self-test is a refinement for that
	 * one case, never a substitute: it has been observed reporting a block on a site whose
	 * REST API answered fine from outside.
	 *
	 * @param int                  $status        HTTP status returned by step 2.
	 * @param array<string, mixed> $data          Decoded response body.
	 * @param string               $wp_site_id    Site id issued by step 1.
	 * @param string               $install_nonce Nonce the pending registration was opened with.
	 * @since 1.5.0
	 * @return array{success: false, message?: string, code?: string, diagnosis?: string, dns_verification?: array<string, string>|null, verify_url?: string|null}
	 */
	private function describe_completion_failure( int $status, array $data, string $wp_site_id, string $install_nonce ): array {
		$error = isset( $data['error'] ) ? (string) $data['error'] : '';

		Logger::get_instance()->save_log(
			sprintf(
				'Registration step 2 failed (HTTP %1$d, %2$s): %3$s',
				$status,
				$error !== '' ? $error : 'no error code',
				isset( $data['message'] ) ? (string) $data['message'] : 'no message'
			)
		);

		$messages = [
			'validation_failed'       => __( 'The registration request was rejected as malformed. This is a bug in the plugin rather than something you can fix - please contact support.', 'surecookie' ),
			'site_not_found'          => __( 'No pending registration matches this site. Start registration again.', 'surecookie' ),
			'install_nonce_mismatch'  => __( 'The registration handshake got out of step, usually because two attempts overlapped. Wait a moment and start registration again.', 'surecookie' ),
			'no_pending_verification' => __( 'Registration was never started for this site. Start it again.', 'surecookie' ),
		];

		if ( isset( $messages[ $error ] ) ) {
			return [
				'success' => false,
				'code'    => $error,
				'message' => $messages[ $error ],
			];
		}

		if ( $error !== 'verification_failed' ) {
			return [
				'success' => false,
				'code'    => $error !== '' ? $error : 'registration_failed',
				'message' => __( 'Registration could not be completed. Please retry, and contact support if it keeps failing.', 'surecookie' ),
			];
		}

		$dns = isset( $data['dns_verification'] ) && is_array( $data['dns_verification'] )
			? $data['dns_verification']
			: null;

		// Keep the handshake alive so Retry can re-run step 2 against the same token.
		if ( $dns !== null ) {
			set_transient(
				self::PENDING_HANDSHAKE_TRANSIENT,
				[
					'wp_site_id'    => $wp_site_id,
					'install_nonce' => $install_nonce,
					'token'         => $this->token_from_dns_value( (string) ( $dns['value'] ?? '' ) ),
				],
				self::PENDING_HANDSHAKE_TTL
			);
		}

		$diagnosis = $this->diagnose_verification_failure();

		$message = __( 'We could not confirm that you control this domain, because our verification request to your site did not come back with the expected token.', 'surecookie' );

		if ( $diagnosis === 'ssl_invalid' ) {
			$message .= ' ' . __( 'Your site\'s SSL certificate also failed validation from here, so that is the likely cause: it may be self-signed, expired, or issued for a different domain.', 'surecookie' );
		} elseif ( $diagnosis === 'rest_unreachable' ) {
			$message .= ' ' . __( 'The REST API at /wp-json/ did not respond from here either, so check that pretty permalinks are on and no security plugin has disabled it.', 'surecookie' );
		} else {
			$message .= ' ' . __( 'A firewall or security plugin blocking our request is the most common cause.', 'surecookie' );
		}

		if ( $dns !== null ) {
			$message .= ' ' . __( 'You can prove ownership with a DNS record instead, which works even when a firewall blocks us.', 'surecookie' );
		}

		return [
			'success'          => false,
			'code'             => 'verification_failed',
			'diagnosis'        => $diagnosis,
			'message'          => $message,
			'dns_verification' => $dns,
			'verify_url'       => isset( $data['verify_url'] ) ? (string) $data['verify_url'] : null,
		];
	}

	/**
	 * Pull the raw token out of the TXT value the SaaS hands back.
	 *
	 * @param string $value TXT record value, `surecookie-verification=<token>`.
	 * @since 1.5.0
	 * @return string
	 */
	private function token_from_dns_value( string $value ): string {
		$token = substr( $value, strlen( 'surecookie-verification=' ) );
		return strncmp( $value, 'surecookie-verification=', 24 ) === 0 && preg_match( '/^[a-f0-9]{64}$/', $token ) === 1
			? $token
			: '';
	}

	/**
	 * Complete a handshake that is already pending, using the stored token.
	 *
	 * @since 1.5.0
	 * @return array{success: bool, message?: string, code?: string, diagnosis?: string, dns_verification?: array<string, string>|null, verify_url?: string|null}
	 */
	private function run_pending_handshake(): array {
		$pending = get_transient( self::PENDING_HANDSHAKE_TRANSIENT );
		if ( ! is_array( $pending ) || empty( $pending['wp_site_id'] ) || empty( $pending['install_nonce'] ) ) {
			return [
				'success' => false,
				'code'    => 'no_pending_handshake',
				'message' => __( 'There is no registration waiting to be completed. Start a scan to register again.', 'surecookie' ),
			];
		}

		$domain = wp_parse_url( Utils::get_home_url(), PHP_URL_HOST );
		if ( empty( $domain ) ) {
			return [
				'success' => false,
				'message' => __( 'Could not determine site domain.', 'surecookie' ),
			];
		}

		$completion = $this->complete_registration(
			(string) $pending['wp_site_id'],
			(string) $pending['install_nonce'],
			(string) ( $pending['token'] ?? '' )
		);
		if ( ! $completion['success'] ) {
			return $completion;
		}

		return $this->finalize_registration( $completion['data'], (string) $domain );
	}

	/**
	 * Persist the issued key and announce the registration.
	 *
	 * @param array<string, mixed> $step2_data Successful step 2 body.
	 * @param string               $domain     Registered domain.
	 * @since 1.5.0
	 * @return array{success: bool}
	 */
	private function finalize_registration( array $step2_data, string $domain ): array {
		// install_nonce is intentionally NOT persisted - it's only meaningful during the
		// in-flight handshake, so keeping it out of wp_options shrinks the sensitive surface.
		$payload = [
			'api_key'           => (string) $step2_data['api_key'],
			'key_prefix'        => (string) $step2_data['key_prefix'],
			'registered_domain' => $domain,
			'registered_at'     => time(),
		];

		if ( get_option( SURECOOKIE_SITE_CREDENTIALS_OPTION, false ) === false ) {
			add_option( SURECOOKIE_SITE_CREDENTIALS_OPTION, $payload, '', false );
		} else {
			update_option( SURECOOKIE_SITE_CREDENTIALS_OPTION, $payload, false );
		}

		/**
		 * Fires after the site successfully registers and obtains its sk_ key.
		 *
		 * @since 0.0.1-beta.3
		 * @param string $key_prefix Public key prefix (sk_xxxxxxxx).
		 * @param string $domain     Registered domain.
		 */
		do_action( 'surecookie_site_registered', (string) $payload['key_prefix'], $domain );

		Logger::get_instance()->save_log( 'Site registered successfully with SaaS scanner.' );

		return [ 'success' => true ];
	}

	/**
	 * Build the HMAC-signed headers for an outgoing scan request.
	 *
	 * Signs the canonical request: METHOD\nPATH\nTIMESTAMP\nNONCE\nsha256(body).
	 * PATH is signed without query string - CDN/proxy normalization in transit
	 * would otherwise diverge from what the SaaS reconstructs. Matches the
	 * verification performed in AuthenticateScanRequest on the SaaS.
	 *
	 * @since 0.0.1-beta.3
	 * @param string $method  HTTP method (GET, POST, …).
	 * @param string $path    Path with leading slash, no query string (e.g. /api/scan/start, /api/scan/quota).
	 * @param string $body    Request body (empty string for GET/HEAD).
	 * @return array<string, string> Signature headers.
	 */
	private function sign_request( string $method, string $path, string $body = '' ): array {
		$api_key    = $this->get_api_key();
		$key_prefix = $this->get_key_prefix();
		if ( $api_key === '' || $key_prefix === '' ) {
			return [];
		}

		$timestamp = (string) time();
		// random_bytes() can throw if the CSPRNG is unavailable. Returning an empty header
		// set lets the SaaS reject with a clean 401 (which handle_unauthorized_response
		// retries on) rather than fataling the whole scan request.
		try {
			$nonce = bin2hex( random_bytes( 16 ) ); // 32 hex chars matching the SaaS validator.
		} catch ( \Throwable $e ) {
			Logger::get_instance()->save_log( 'Could not generate signing nonce: ' . $e->getMessage() );
			return [];
		}
		$canonical = strtoupper( $method ) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', $body );
		$signature = base64_encode( hash_hmac( 'sha256', $canonical, $api_key, true ) );

		return [
			'X-SureCookie-Key-Prefix' => $key_prefix,
			'X-SureCookie-Timestamp'  => $timestamp,
			'X-SureCookie-Nonce'      => $nonce,
			'X-SureCookie-Signature'  => $signature,
		];
	}

	/**
	 * Inspect a wp_remote response for auth failures that warrant re-registration.
	 *
	 * Returns true if credentials were cleared and a retry is appropriate.
	 *
	 * @since 0.0.1-beta.3
	 * @param array<string, mixed>|\WP_Error $response The response from wp_remote_*.
	 */
	private function handle_unauthorized_response( $response ): bool {
		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( (int) wp_remote_retrieve_response_code( $response ) !== 401 ) {
			return false;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return false;
		}

		$error       = (string) ( $body['error'] ?? '' );
		$recoverable = [
			'invalid_credentials',
			'invalid_signature',
			'site_not_verified',
			'requires_reregistration',
			'invalid_key_prefix',
		];

		if ( ! in_array( $error, $recoverable, true ) ) {
			return false;
		}

		Logger::get_instance()->save_log( sprintf( 'Clearing credentials after 401 (%s) and re-registering.', $error ) );
		$this->clear_credentials();
		return true;
	}

	/**
	 * Sanitize a SaaS-provided ID for safe use in URL paths.
	 *
	 * Prevents path traversal if the SaaS ever returns a crafted ID.
	 *
	 * @param string|int $id The ID to sanitize.
	 * @since 0.0.0-alpha.2
	 * @return string Alphanumeric-only ID.
	 */
	private function sanitize_api_id( $id ): string {
		return (string) preg_replace_callback(
			'/[^a-zA-Z0-9]/',
			static function () {
				return '';
			},
			(string) $id
		);
	}

	/**
	 * Build the headers for an outgoing SaaS request, including HMAC signature.
	 *
	 * Issue #473: the deterministic `X-API-Key` header is replaced by a timestamped/nonced
	 * HMAC signature. The Pro filter (`surecookie_saas_request_args`) composes cleanly - Pro
	 * adds X-License-Id + X-License-Sig (HMAC over the same canonical request), proving
	 * license-key possession without ever transmitting it.
	 *
	 * @since 0.0.1-beta.3
	 * @param string $method HTTP method for the request being signed.
	 * @param string $url    Full URL (path is extracted for signing; query is ignored).
	 * @param string $body   Request body (empty for GET/HEAD).
	 * @return array<string, string>
	 */
	private function get_request_headers( string $method = 'GET', string $url = '', string $body = '' ): array {
		$headers = [
			'Content-Type'     => 'application/json',
			'Accept'           => 'application/json',
			'X-Plugin-Version' => SURECOOKIE_VERSION,
			// 'scheduled' only while the auto-scanning runner is firing this request (it adds
			// the filter for that window); 'manual' otherwise, so the SaaS can defer/route scheduled scans.
			'X-Scan-Trigger'   => apply_filters( 'surecookie_saas_scan_trigger', 'manual' ),
		];

		if ( $url !== '' && $this->get_api_key() !== '' ) {
			// Sign path only - query strings get normalized in transit (Cloudflare reorders/re-encodes), breaking signatures. Replay/forgery protection comes from the api_key secret, nonce, and body hash in canonical.
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );

			$headers = array_merge( $headers, $this->sign_request( $method, $path, $body ) );
		}

		// Signal SaaS to allow local/private URLs when local scanning is enabled.
		if ( defined( 'SURECOOKIE_ALLOW_LOCAL_SCAN' ) && SURECOOKIE_ALLOW_LOCAL_SCAN ) {
			$headers['X-Allow-Local'] = 'true';
		}

		/**
		 * Filter the full SaaS request context.
		 *
		 * Replaces `surecookie_scanning_request_header`. Hookers (notably Pro)
		 * receive method/url/body alongside headers so they can sign the same
		 * canonical request the site HMAC signed.
		 *
		 * @since 0.0.1-beta.3
		 *
		 * @param array{headers: array<string,string>, method: string, url: string, body: string} $args Request context.
		 */
		$args = apply_filters(
			'surecookie_saas_request_args',
			[
				'headers' => $headers,
				'method'  => $method,
				'url'     => $url,
				'body'    => $body,
			]
		);

		return is_array( $args ) && isset( $args['headers'] ) && is_array( $args['headers'] )
			? $args['headers']
			: $headers;
	}

	/**
	 * Schedule the polling cron job.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	private function schedule_polling(): void {
		if ( ! wp_next_scheduled( self::POLL_SCAN_HOOK ) ) {
			wp_schedule_event( time() + self::POLL_INTERVAL, 'surecookie_poll_interval', self::POLL_SCAN_HOOK );
		}
	}

	/**
	 * Unschedule the polling cron job.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	private function unschedule_polling(): void {
		$timestamp = wp_next_scheduled( self::POLL_SCAN_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::POLL_SCAN_HOOK );
		}
	}

	/**
	 * Claim the poll, or report that another request holds it.
	 *
	 * INSERT IGNORE on the options table's unique key is the atomic claim WordPress
	 * itself uses (WP_Upgrader::create_lock) and works on MySQL and the SQLite drop-in.
	 * The stale check reads the table: an object cache may still say the row is
	 * missing after this request lost the insert, and acting on that steals a live claim.
	 * The claim is only ever read raw, so the option cache is never consulted.
	 *
	 * @since 1.7.0
	 * @return bool True when this request now holds the claim.
	 */
	private function acquire_poll_lock(): bool {
		global $wpdb;

		$token = time() . ':' . wp_generate_password( 12, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The row is the claim, so it is never cached.
		if ( ! $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::POLL_LOCK_OPTION, $token ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The claim must be read from the table, see above.
			$seen = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::POLL_LOCK_OPTION ) );

			// A claim older than the TTL was left by a request that died mid-poll. Swap
			// only the exact value seen, so of two requests taking it over one wins.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap on the claim row.
			if ( time() - (int) $seen < self::POLL_LOCK_TTL || 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, self::POLL_LOCK_OPTION, $seen ) ) ) {
				return false;
			}
		}

		$this->poll_lock_token = $token;
		return true;
	}

	/**
	 * Release the poll claim, only while it is still this request's: a poll that
	 * outlived the TTL must not delete the claim of the poll that took over.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private function release_poll_lock(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete of the claim row, see acquire_poll_lock().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::POLL_LOCK_OPTION, $this->poll_lock_token ) );
	}

	/**
	 * Handle scan completion.
	 *
	 * @param string $group_id The completed scan group ID.
	 * @since 0.0.1
	 * @return void
	 */
	private function handle_scan_completion( string $group_id ): void {
		Logger::get_instance()->save_log( 'Fetching results for group: ' . $group_id );

		// Get results.
		$results = $this->get_scan_results( $group_id );

		// The fetch is slow: a scan cancelled (or replaced) meanwhile is not this run's to report or clean up.
		wp_cache_delete( self::SCAN_OPTION_KEY, 'options' );
		$active_scan = $this->get_active_scan();
		if ( ( $active_scan['group_id'] ?? '' ) !== $group_id ) {
			return;
		}

		if ( ! $results['success'] ) {
			$this->handle_scan_failure( $results['message'] ?? __( 'Failed to fetch results.', 'surecookie' ) );
			return;
		}

		// Process and save results.
		if ( ! empty( $results['data'] ) ) {
			$this->process_scan_results( $results['data'] );
		}

		// Both before the cleanup that flips the UI to idle: on its first status read
		// after that the drawer fetches the log tail and runs its completion path.
		Logger::get_instance()->save_log( '' ); // Blank line.
		Logger::get_instance()->save_log( 'Cookies scanning workflow completed successfully.' );

		$this->mark_just_finished(
			is_array( $active_scan['progress'] ?? null ) ? $active_scan['progress'] : [],
			is_array( $active_scan['error_details'] ?? null ) ? $active_scan['error_details'] : []
		);

		$this->cleanup_active_scan();
	}

	/**
	 * Handle scan failure.
	 *
	 * @param string $error_message The error message.
	 * @since 0.0.1
	 * @return void
	 */
	private function handle_scan_failure( string $error_message ): void {
		Logger::get_instance()->save_log( 'Scan failed: ' . $error_message );

		// Cleanup local state.
		$this->cleanup_active_scan();
	}

	/**
	 * Process and save scan results.
	 *
	 * @param array<string, mixed> $data The scan results data.
	 * @since 0.0.1
	 * @return void
	 */
	private function process_scan_results( array $data ): void {
		Logger::get_instance()->save_log( 'Processing scan results...' );

		// Fire the action so sync.php handles the data storage.
		do_action( 'surecookie_saas_scan_results_received', $data );

		Logger::get_instance()->save_log( 'Scan results processed.' );
	}
}
