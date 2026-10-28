<?php
/**
 * Automatic Scanning runner.
 *
 * Executes a scheduled scan by reusing the existing scan engine: it never
 * scans itself, it only decides when to fire `surecookie_start_site_scanning`
 * and which pages to scan (scope). It also marks the in-flight scan as
 * auto-triggered so the change-detection recorder can label scan history.
 *
 * @package SureCookie\Inc\Modules\AutomaticScanning
 * @since 1.2.0
 */

namespace SureCookie\Inc\Modules\AutomaticScanning;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;
use SureCookie\Inc\Modules\SiteScanner\Utils;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Runner
 *
 * @since 1.2.0
 */
class Runner {
	use GetInstance;

	/**
	 * Transient that marks the in-flight scan as auto-triggered.
	 *
	 * Read by the change-detection recorder when `surecookie_scan_completed`
	 * fires (minutes later, across cron polling requests) to set the scan
	 * history trigger type.
	 *
	 * @since 1.2.0
	 */
	public const ACTIVE_TRANSIENT = 'surecookie_auto_scan_active';

	/**
	 * One-off retry hook used when the SaaS defers a scheduled scan because the
	 * shared daily page budget is exhausted. Distinct from the recurring
	 * Scheduler::RUN_HOOK so the daily retry can be scheduled/queried without
	 * colliding with the weekly/monthly cadence.
	 *
	 * @since 1.2.0
	 */
	public const RETRY_HOOK = 'surecookie_auto_scan_retry';

	/**
	 * Transient guarding against two overlapping cron ticks both starting a scan.
	 *
	 * @since 1.2.0
	 */
	private const LOCK_TRANSIENT = 'surecookie_auto_scan_lock';

	/**
	 * Whether the current request is executing an automatic (scheduled) run.
	 *
	 * @var bool
	 * @since 1.2.0
	 */
	private $is_auto_run = false;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	private function __construct() {
		add_action( Scheduler::RUN_HOOK, [ $this, 'run_scheduled_scan' ] );
		add_action( self::RETRY_HOOK, [ $this, 'run_scheduled_scan' ] );
		add_action( 'surecookie_scan_deferred', [ $this, 'handle_deferred_scan' ] );
		add_filter( 'surecookie_scanner_page_urls_to_scan', [ $this, 'apply_scope' ] );
	}

	/**
	 * Reschedule a deferred scheduled scan for ~24h out.
	 *
	 * The SaaS defers (rather than partial-runs) a scheduled scan when the
	 * license's shared daily page budget can't fit it today. Retrying daily -
	 * instead of waiting for the next weekly/monthly cycle - means the scan runs
	 * as soon as the budget resets. Nothing actually scanned, so drop the
	 * auto-run marker to avoid mislabeling a later manual scan.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function handle_deferred_scan(): void {
		delete_transient( self::ACTIVE_TRANSIENT );

		if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + DAY_IN_SECONDS, self::RETRY_HOOK );
			Logger::get_instance()->save_log( __( 'Automatic scan deferred: daily page budget reached. Retrying in ~24 hours.', 'surecookie' ) );
		}
	}

	/**
	 * Run a scheduled scan by firing the existing scan trigger.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function run_scheduled_scan(): void {
		if ( ! (bool) Settings::get( 'auto_scan_enabled' ) ) {
			return;
		}

		// Local/dev hosts can't be reached by the cloud scanner - skip before
		// touching transients, analytics flags, or logs so a local site doesn't
		// churn failure entries on every scheduled tick. SaasClient::start_scan()
		// re-checks this as the last line of defense.
		if ( SaasClient::is_local_site() ) {
			return;
		}

		if ( SaasClient::get_instance()->is_scan_in_progress() ) {
			Logger::get_instance()->save_log( __( 'Automatic scan skipped: a scan is already in progress.', 'surecookie' ) );
			return;
		}

		// Short lock so two near-simultaneous cron ticks can't both start a scan.
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

		// Mark this scan as auto-triggered for the completion recorder (TTL > scan ceiling).
		set_transient( self::ACTIVE_TRANSIENT, 1, 45 * MINUTE_IN_SECONDS );

		// Start this run with a clean log, exactly as the manual scan entry point
		// does. Deliberately after the in-progress and lock guards so a duplicate
		// cron tick can never wipe the log of the scan that is already running.
		Logger::get_instance()->cleanup_logs();

		Logger::get_instance()->save_log( __( 'Starting automatic (scheduled) scan.', 'surecookie' ) );

		// Analytics: flag the first automatic (scheduled) scan run + its cadence so
		// the state-based tracker can emit `first_auto_scan_started`.
		if ( ! get_option( 'surecookie_first_auto_scan_started_flag', false ) ) {
			update_option( 'surecookie_first_auto_scan_started_flag', true, false );
			update_option( 'surecookie_first_auto_scan_frequency', (string) Settings::get( 'auto_scan_frequency' ), false );
		}

		$this->is_auto_run = true;
		// Request-scoped signal so the SaaS client tags only this scan-start as
		// 'scheduled'. Avoids the ACTIVE_TRANSIENT (45-min TTL) mislabeling a
		// later manual scan if an auto-run crashes before cleanup.
		add_filter( 'surecookie_saas_scan_trigger', [ $this, 'tag_scheduled' ] );

		try {
			do_action( 'surecookie_start_site_scanning' );
		} catch ( \Throwable $e ) {
			// Scan never started - drop the active marker so a later manual scan isn't mislabeled.
			delete_transient( self::ACTIVE_TRANSIENT );
			Logger::get_instance()->save_log( 'Automatic scan failed to start: ' . $e->getMessage() );
		} finally {
			remove_filter( 'surecookie_saas_scan_trigger', [ $this, 'tag_scheduled' ] );
			$this->is_auto_run = false;
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Filter callback: tag the in-flight SaaS scan request as scheduled.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public function tag_scheduled(): string {
		return 'scheduled';
	}

	/**
	 * Apply the configured page scope to automatic runs only.
	 *
	 * Manual scans are never altered.
	 *
	 * @param array<int, string> $urls Page URLs resolved from the manual selection.
	 * @since 1.2.0
	 * @return array<int, string>
	 */
	public function apply_scope( $urls = [] ) {
		if ( ! $this->is_auto_run ) {
			return $urls;
		}

		$scope = (string) Settings::get( 'auto_scan_scope' );

		if ( $scope === 'all_published' ) {
			$resolved = $this->all_published_urls();
			return ! empty( $resolved ) ? $resolved : $urls;
		}

		if ( $scope === 'selected' ) {
			$selected = $this->selected_pages_urls();
			return ! empty( $selected ) ? $selected : $urls;
		}

		return $urls; // same_as_manual.
	}

	/**
	 * Permalinks of published content, capped to the plan's page limit.
	 *
	 * Covers the `post` + `page` baseline plus any custom post types opted into
	 * the scanner via the `surecookie_searchable_post_types` filter, so "All
	 * Published Content" stays in sync with what the manual picker can select.
	 *
	 * @since 1.2.0
	 * @return array<int, string>
	 */
	private function all_published_urls(): array {
		$max = max( 1, (int) Utils::get_max_scan_pages() );

		$post_types = array_values(
			array_unique(
				array_merge( [ 'post', 'page' ], Get::searchable_post_types( 'scanner' ) )
			)
		);

		$query = new \WP_Query(
			[
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => $max,
				'fields'                 => 'ids',
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false, // Skip term cache for performance.
				'update_post_meta_cache' => false, // Skip meta cache for performance.
			]
		);

		// WP_Query returns int IDs here (fields => 'ids'), but its typed return is
		// int|WP_Post; normalize each entry to an int ID so ids_to_permalinks() receives int[].
		$ids = array_map(
			static function ( $post ) {
				return $post instanceof \WP_Post ? $post->ID : (int) $post;
			},
			is_array( $query->posts ) ? $query->posts : []
		);

		return $this->ids_to_permalinks( $ids );
	}

	/**
	 * Permalinks from the auto_scan_pages selection.
	 *
	 * @since 1.2.0
	 * @return array<int, string>
	 */
	private function selected_pages_urls(): array {
		$pages = Settings::get( 'auto_scan_pages' );

		if ( ! is_array( $pages ) ) {
			return [];
		}

		$ids = [];

		foreach ( $pages as $page ) {
			$id = is_array( $page ) ? absint( $page['value'] ?? 0 ) : absint( $page );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $this->ids_to_permalinks( $ids );
	}

	/**
	 * Resolve published post IDs to permalinks.
	 *
	 * @param array<int, int> $ids Post IDs.
	 * @since 1.2.0
	 * @return array<int, string>
	 */
	private function ids_to_permalinks( array $ids ): array {
		$urls = [];

		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );

			if ( $post && $post->post_status === 'publish' ) {
				$permalink = get_permalink( (int) $id );
				if ( $permalink ) {
					$urls[] = $permalink;
				}
			}
		}

		return $urls;
	}
}
