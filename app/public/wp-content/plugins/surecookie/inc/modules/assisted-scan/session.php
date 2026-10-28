<?php
/**
 * Assisted Scan session state.
 *
 * Owns the page queue for one assisted walk; the server holds it and the browser
 * only asks "what next?", so the existing scan-status/scan-log UI reports an
 * assisted run with no new polling endpoints.
 *
 * Stored in a non-autoloaded option, not a transient: an object cache may evict a
 * transient and abandon the walk, so the 30-minute TTL is enforced in code
 * (matching `surecookie_active_scan` in the site-scanner module).
 *
 * Findings are stored already-normalized and deduplicated, so the option is
 * bounded by unique cookies/resources, not pages times payload size.
 *
 * @package SureCookie\Inc\Modules\AssistedScan
 * @since   1.3.0
 */

namespace SureCookie\Inc\Modules\AssistedScan;

use SureCookie\Inc\Functions\Cookie_Identity;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Session
 *
 * @since 1.3.0
 */
class Session {
	use GetInstance;

	/**
	 * Option holding the active session. Non-autoloaded; deleted when the walk ends.
	 *
	 * @since 1.3.0
	 */
	public const STATE_OPTION = 'surecookie_assisted_scan';

	/**
	 * How long a session may stay open, in seconds.
	 *
	 * @since 1.3.0
	 */
	public const TTL = 1800;

	/**
	 * Cron hook that finalizes a session the browser never finished.
	 *
	 * @since 1.3.0
	 */
	public const FINALIZE_HOOK = 'surecookie_assisted_scan_finalize';

	/**
	 * Query argument carrying the session token on a scan page request.
	 *
	 * @since 1.3.0
	 */
	public const TOKEN_ARG = 'surecookie_scan';

	/**
	 * Query argument carrying the index of the page being collected.
	 *
	 * @since 1.3.0
	 */
	public const PAGE_ARG = 'surecookie_scan_page';

	/**
	 * Query argument marking the post-cookie-reset load of the first page.
	 *
	 * @since 1.3.0
	 */
	public const RESET_ARG = 'surecookie_scan_reset';

	/**
	 * Per-page ceilings on what the browser may submit.
	 *
	 * A page carrying more is pathological; the caps stop a compromised or looping
	 * collector from growing the option without bound.
	 *
	 * @since 1.3.0
	 */
	public const MAX_COOKIES_PER_PAGE   = 150;
	public const MAX_RESOURCES_PER_PAGE = 300;

	/**
	 * Marks a walk that has just ended, so the screen reports a terminal state for a
	 * moment instead of jumping straight to idle. The UI runs its completion path
	 * (final log fetch, toast, cache invalidation) off that transition, and an
	 * assisted walk deletes its session the instant it finishes.
	 *
	 * @since 1.3.0
	 */
	public const JUST_FINISHED_TRANSIENT = 'surecookie_assisted_scan_just_finished';

	/**
	 * How long the marker lives. It only has to outlast one status poll (3-6s), so
	 * this is generous already; the screen ignores it unless it saw the walk running.
	 *
	 * @since 1.3.0
	 */
	public const JUST_FINISHED_TTL = 45;

	/**
	 * Read the stored session.
	 *
	 * @since 1.3.0
	 * @return array<string, mixed> Session state, or an empty array when none is stored.
	 */
	public function get(): array {
		$state = get_option( self::STATE_OPTION, [] );

		return is_array( $state ) ? $state : [];
	}

	/**
	 * Whether a usable session is open.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	public function is_active(): bool {
		$state = $this->get();

		return ! empty( $state['token'] ) && ! $this->is_stale( $state );
	}

	/**
	 * Whether a session has outlived {@see self::TTL}.
	 *
	 * Measured from last activity, not start, so a slow but progressing walk is
	 * never cut off mid-way.
	 *
	 * @param array<string, mixed>|null $state Session state, read when omitted.
	 * @since 1.3.0
	 * @return bool
	 */
	public function is_stale( ?array $state = null ): bool {
		$state = $state ?? $this->get();

		if ( empty( $state['token'] ) ) {
			return false;
		}

		$updated = (int) ( $state['updated_at'] ?? $state['started_at'] ?? 0 );

		return $updated > 0 && ( time() - $updated ) > self::TTL;
	}

	/**
	 * Open a session for the given pages.
	 *
	 * Callers must reject a start while {@see self::is_active()} (so the admin sees
	 * "already running"); this method does not adopt an in-flight walk's state.
	 *
	 * @param array<int, array<string, mixed>> $pages Pages to walk: `url`, `post_id`, `title`.
	 * @since 1.3.0
	 * @return array<string, mixed> The new session state, or an empty array when there is nothing to walk.
	 */
	public function start( array $pages ): array {
		$queue = [];

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) || empty( $page['url'] ) ) {
				continue;
			}

			$queue[] = [
				'url'     => esc_url_raw( (string) $page['url'] ),
				'post_id' => absint( $page['post_id'] ?? 0 ),
				'title'   => sanitize_text_field( (string) ( $page['title'] ?? '' ) ),
				'status'  => 'pending',
				'cookies' => 0,
				'scripts' => 0,
				'iframes' => 0,
				'error'   => '',
			];
		}

		// Nothing walkable: persist nothing. A token with an empty queue keeps
		// is_active() true for the whole TTL, locking the admin out of scanning.
		if ( $queue === [] ) {
			return [];
		}

		$now = time();

		$state = [
			'token'      => bin2hex( random_bytes( 32 ) ),
			// Bound to the administrator who started it; enforced in verify_token().
			'user_id'    => get_current_user_id(),
			'started_at' => $now,
			'updated_at' => $now,
			'current'    => 0,
			'pages'      => $queue,
			'cookies'    => [],
			'scripts'    => [],
			'iframes'    => [],
			'services'   => [],
		];

		Update::option( self::STATE_OPTION, $state );
		$this->arm_safety_net( $now );

		return $state;
	}

	/**
	 * Whether the request may drive the current walk.
	 *
	 * Constant-time token comparison plus an owner check. The owner check matters
	 * because the token travels in the scan page's query string, so it leaks into
	 * server access logs and the `Referer` of every third-party request that page
	 * makes; it narrows a leaked token from any administrator to the one who
	 * started the walk.
	 *
	 * @param string $token Token from the request.
	 * @since 1.3.0
	 * @return bool
	 */
	public function verify_token( string $token ): bool {
		if ( preg_match( '/^[0-9a-f]{64}$/', $token ) !== 1 ) {
			return false;
		}

		$state = $this->get();
		$known = (string) ( $state['token'] ?? '' );

		if ( $known === '' || $this->is_stale( $state ) ) {
			return false;
		}

		if ( ! hash_equals( $known, $token ) ) {
			return false;
		}

		$owner = (int) ( $state['user_id'] ?? 0 );

		return $owner > 0 && $owner === get_current_user_id();
	}

	/**
	 * The index of the page the walk expects next.
	 *
	 * @since 1.3.0
	 * @return int
	 */
	public function current_index(): int {
		return (int) ( $this->get()['current'] ?? 0 );
	}

	/**
	 * The public URL a given page must be collected from.
	 *
	 * The token doubles as a cache buster: unique per session, so a page cache
	 * cannot serve a copy that lacks the collector.
	 *
	 * @param int                       $index Page index.
	 * @param array<string, mixed>|null $state Session state, read when omitted.
	 * @since 1.3.0
	 * @return string Scan URL, or an empty string when the index is out of range.
	 */
	public function scan_url( int $index, ?array $state = null ): string {
		$state = $state ?? $this->get();
		$page  = $state['pages'][ $index ] ?? null;

		if ( ! is_array( $page ) || empty( $page['url'] ) ) {
			return '';
		}

		return add_query_arg(
			[
				self::TOKEN_ARG => (string) ( $state['token'] ?? '' ),
				self::PAGE_ARG  => $index,
			],
			(string) $page['url']
		);
	}

	/**
	 * The URL of the next uncollected page, if the walk continues.
	 *
	 * @param array<string, mixed>|null $state Session state, read when omitted.
	 * @since 1.3.0
	 * @return string Next scan URL, or an empty string when the walk is done.
	 */
	public function next_url( ?array $state = null ): string {
		$state = $state ?? $this->get();
		$index = (int) ( $state['current'] ?? 0 );

		if ( ! isset( $state['pages'][ $index ] ) ) {
			return '';
		}

		return $this->scan_url( $index, $state );
	}

	/**
	 * Record one page's findings and advance the queue.
	 *
	 * Rejects any index other than the one expected, which blocks a replayed or
	 * out-of-order submission from corrupting the walk.
	 *
	 * @param int                              $index     Page index being reported.
	 * @param array<int, array<string, mixed>> $cookies   Normalized cookies for this page.
	 * @param array<string, mixed>             $resources Normalized `scripts` and `iframes` for this page.
	 * @param string                           $error     Failure reason, when the page could not be collected.
	 * @since 1.3.0
	 * @return bool True when the page was recorded.
	 */
	public function record_page( int $index, array $cookies, array $resources, string $error = '' ): bool {
		$state = $this->get();

		if ( empty( $state['token'] ) || ! isset( $state['pages'][ $index ] ) ) {
			return false;
		}

		if ( (int) ( $state['current'] ?? 0 ) !== $index ) {
			return false;
		}

		$scripts = is_array( $resources['scripts'] ?? null ) ? $resources['scripts'] : [];
		$iframes = is_array( $resources['iframes'] ?? null ) ? $resources['iframes'] : [];

		// Accumulate deduplicated, so the option tracks unique findings rather
		// than growing with every page that repeats the same tags.
		foreach ( $cookies as $cookie ) {
			if ( is_array( $cookie ) && ! empty( $cookie['name'] ) ) {
				$state['cookies'][ Cookie_Identity::key_for( $cookie ) ] = $cookie;
			}
		}

		foreach ( [ 'scripts', 'iframes' ] as $kind ) {
			$entries = $kind === 'scripts' ? $scripts : $iframes;
			foreach ( $entries as $entry ) {
				if ( is_array( $entry ) && ! empty( $entry['domain'] ) ) {
					$domain = (string) $entry['domain'];
					if ( ! isset( $state[ $kind ][ $domain ] ) ) {
						$state[ $kind ][ $domain ] = $entry;
					}
				}
			}
		}

		$state['pages'][ $index ]['status']  = $error === '' ? 'done' : 'failed';
		$state['pages'][ $index ]['error']   = sanitize_text_field( $error );
		$state['pages'][ $index ]['cookies'] = count( $cookies );
		$state['pages'][ $index ]['scripts'] = count( $scripts );
		$state['pages'][ $index ]['iframes'] = count( $iframes );

		$now = time();

		$state['current']    = $index + 1;
		$state['updated_at'] = $now;

		Update::option( self::STATE_OPTION, $state );

		// Push the rescue event past the new deadline; staleness is measured
		// from this write, so a stale one-shot would fire while still live.
		$this->arm_safety_net( $now );

		return true;
	}

	/**
	 * Whether every page in the queue has been attempted.
	 *
	 * @param array<string, mixed>|null $state Session state, read when omitted.
	 * @since 1.3.0
	 * @return bool
	 */
	public function is_complete( ?array $state = null ): bool {
		$state = $state ?? $this->get();

		if ( empty( $state['token'] ) ) {
			return false;
		}

		return (int) ( $state['current'] ?? 0 ) >= count( $state['pages'] ?? [] );
	}

	/**
	 * Progress in the shape the existing scan-status payload uses.
	 *
	 * Mirrors the SaaS status contract so `useScanStatus` and the scanning-logs
	 * drawer render an assisted walk without changes.
	 *
	 * @param array<string, mixed>|null $state Session state, read when omitted.
	 * @since 1.3.0
	 * @return array<string, mixed>
	 */
	public function progress( ?array $state = null ): array {
		$state = $state ?? $this->get();
		$pages = is_array( $state['pages'] ?? null ) ? $state['pages'] : [];

		$completed = 0;
		$failed    = 0;

		foreach ( $pages as $page ) {
			$status = (string) ( $page['status'] ?? 'pending' );
			if ( $status === 'done' ) {
				$completed++;
			} elseif ( $status === 'failed' ) {
				$failed++;
			}
		}

		return [
			'total_pages'     => count( $pages ),
			'completed_pages' => $completed,
			'failed_pages'    => $failed,
			'current_phase'   => $this->is_complete( $state ) ? 'finalizing' : 'capturing',
		];
	}

	/**
	 * The walk's status, in the shape the scanner status endpoint already returns.
	 *
	 * Mirrors the cloud contract key for key, so the existing progress bar, stall
	 * detection, scanning-logs drawer and completion toast report an assisted walk
	 * with no new hooks or React state; `scan_mode` and `pages` are additive.
	 *
	 * @param array<string, mixed>|null $state Session state, read when omitted.
	 * @since 1.3.0
	 * @return array<string, mixed>
	 */
	public function status_payload( ?array $state = null ): array {
		$state = $state ?? $this->get();
		$pages = is_array( $state['pages'] ?? null ) ? $state['pages'] : [];

		$errors = [];
		foreach ( $pages as $page ) {
			if ( ( $page['status'] ?? '' ) === 'failed' ) {
				$errors[] = [
					'url'   => (string) ( $page['url'] ?? '' ),
					'error' => (string) ( $page['error'] ?? '' ),
				];
			}
		}

		$started = (int) ( $state['started_at'] ?? time() );
		$updated = (int) ( $state['updated_at'] ?? $started );

		return [
			'in_progress'                 => true,
			'status'                      => 'running',
			// Lets the UI label the run honestly and keep the scan-window handle
			// alive, rather than presenting a browser walk as a cloud scan.
			'scan_mode'                   => 'assisted',
			// The page the walk is waiting on. Stated, not derived, because the stall
			// watchdog must submit exactly this index (anything else is rejected).
			'current_index'               => (int) ( $state['current'] ?? 0 ),
			'pages_count'                 => count( $pages ),
			'elapsed'                     => max( 0, time() - $started ),
			'progress'                    => $this->progress( $state ),
			'error_details'               => $errors,
			'seconds_since_last_progress' => max( 0, time() - $updated ),
			// The per-page list the admin screen shows beneath the controls.
			'pages'                       => array_values( $pages ),
			'message'                     => __( 'Assisted scan in progress...', 'surecookie' ),
		];
	}

	/**
	 * The deduplicated cookies collected so far.
	 *
	 * @param array<string, mixed>|null $state Session state, read when omitted.
	 * @since 1.3.0
	 * @return array<int, array<string, mixed>>
	 */
	public function collected_cookies( ?array $state = null ): array {
		$state = $state ?? $this->get();

		return array_values( is_array( $state['cookies'] ?? null ) ? $state['cookies'] : [] );
	}

	/**
	 * The deduplicated resources collected so far.
	 *
	 * @param array<string, mixed>|null $state Session state, read when omitted.
	 * @since 1.3.0
	 * @return array{scripts: array<int, array<string, mixed>>, iframes: array<int, array<string, mixed>>}
	 */
	public function collected_resources( ?array $state = null ): array {
		$state = $state ?? $this->get();

		return [
			'scripts' => array_values( is_array( $state['scripts'] ?? null ) ? $state['scripts'] : [] ),
			'iframes' => array_values( is_array( $state['iframes'] ?? null ) ? $state['iframes'] : [] ),
		];
	}

	/**
	 * Close the session and drop the safety-net event.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function clear(): void {
		delete_option( self::STATE_OPTION );
		wp_clear_scheduled_hook( self::FINALIZE_HOOK );
	}

	/**
	 * Record that a walk just ended.
	 *
	 * @since 1.3.0
	 * @param array<string, mixed>              $progress Final progress payload.
	 * @param array<int, array<string, string>> $errors   Per-page failures.
	 * @return void
	 */
	public function mark_just_finished( array $progress, array $errors = [] ): void {
		set_transient(
			self::JUST_FINISHED_TRANSIENT,
			[
				'progress' => $progress,
				'errors'   => $errors,
				'at'       => time(),
			],
			self::JUST_FINISHED_TTL
		);
	}

	/**
	 * Drop the terminal marker, so a cancelled walk cannot report itself finished.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function clear_just_finished(): void {
		delete_transient( self::JUST_FINISHED_TRANSIENT );
	}

	/**
	 * Terminal status for a walk that just ended, or null when there isn't one.
	 *
	 * Same shape as a finished cloud scan, so the screen's completion handling works
	 * for both. Deliberately not consumed on read: clearing it here would make the
	 * status non-idempotent, and two polls landing together would report `completed`
	 * then `idle` fast enough for the screen to miss the transition entirely, which
	 * is the failure this exists to prevent. It expires on its own, and the screen
	 * already guards against running its completion path twice.
	 *
	 * @since 1.3.0
	 * @return array<string, mixed>|null
	 */
	public function peek_just_finished(): ?array {
		$marker = get_transient( self::JUST_FINISHED_TRANSIENT );

		if ( ! is_array( $marker ) ) {
			return null;
		}

		$progress = is_array( $marker['progress'] ?? null ) ? $marker['progress'] : [];
		$errors   = is_array( $marker['errors'] ?? null ) ? $marker['errors'] : [];
		$outcome  = get_transient( SaasClient::LAST_OUTCOME_TRANSIENT );

		return [
			'in_progress'   => false,
			// Deliberately still 'idle'. A terminal status keeps the progress section
			// on screen showing a finished bar for as long as the marker lives, which
			// reads as a scan that never clears. The dedicated flag below is what the
			// completion handling keys on.
			'status'        => 'idle',
			'just_finished' => true,
			'scan_mode'     => 'assisted',
			'pages_count'   => (int) ( $progress['total_pages'] ?? 0 ),
			'progress'      => $progress,
			'error_details' => $errors,
			'message'       => __( 'Assisted scan finished.', 'surecookie' ),
			'last_outcome'  => is_array( $outcome ) ? $outcome : null,
		];
	}

	/**
	 * (Re)schedule the finalize event that rescues an abandoned walk.
	 *
	 * Cleared first because WordPress silently refuses to schedule a duplicate hook
	 * within ten minutes, so a stale event would otherwise fire against the session
	 * that replaced it.
	 *
	 * Re-armed on every recorded page so it sits just past the staleness deadline,
	 * which is measured from last activity; anchoring to the start would let this
	 * one-shot fire while the session is still live and then never fire again.
	 *
	 * @param int $from Timestamp the deadline is measured from.
	 * @since 1.3.0
	 * @return void
	 */
	private function arm_safety_net( int $from ): void {
		wp_clear_scheduled_hook( self::FINALIZE_HOOK );
		wp_schedule_single_event( $from + self::TTL + 60, self::FINALIZE_HOOK );
	}
}
