<?php
/**
 * SureCookie Analytics - BSF Analytics Integration
 *
 * @package SureCookie\Admin
 * @since 0.0.1-beta.1
 */

namespace SureCookie\Admin;

use SureCookie\Inc\Database\ConsentLog;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\AssistedScan\Telemetry as AssistedScanTelemetry;
use SureCookie\Inc\Modules\Auth\Controller as AuthController;
use SureCookie\Inc\Modules\AutomaticScanning\Scheduler;
use SureCookie\Inc\Modules\ScriptBlocking\Matched_Resources as MatchedResources;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;
use SureCookie\Inc\Traits\GetInstance;

defined( 'ABSPATH' ) || exit;

/**
 * Analytics class.
 *
 * Handles BSF Analytics integration: event tracking, stats payload, and KPI collection.
 *
 * @since 0.0.1-beta.1
 */
class Analytics {
	use GetInstance;

	/**
	 * Option holding when a user who can manage SureCookie was last in wp-admin.
	 *
	 * @since 1.6.0
	 */
	public const LAST_LOGIN_OPTION = 'surecookie_last_user_login';

	/**
	 * Analytics events schema version. Bump whenever an existing event's shape
	 * (value or properties) changes, and add a `<new-version> => [ 'event' ]`
	 * entry to RESHAPED_EVENTS_BY_VERSION so the installed base re-emits it once.
	 * v1 = original schema.
	 *
	 * @since 1.2.0
	 */
	private const ANALYTICS_EVENTS_VERSION = 3;

	/**
	 * Events reshaped in each schema version, keyed by the version that introduced
	 * the change. The migration re-emits the events for every version a site is
	 * newly crossing, so each entry fires exactly once per site and older entries
	 * are never replayed - nothing here needs to be removed on a future bump.
	 *
	 * @since 1.2.0
	 */
	private const RESHAPED_EVENTS_BY_VERSION = [
		2 => [ 'banner_configured' ],
		3 => [ 'onboarding_completed', 'account_connected' ],
	];

	/**
	 * Public-content volume bands keyed by their exclusive upper bound. The first
	 * bound a count falls under wins, so a site reports one band and never the
	 * wider ones above it; 1000 and up falls through to `over_1000`.
	 *
	 * @since 1.5.0
	 */
	private const CONTENT_VOLUME_BUCKETS = [
		10   => 'under_10',
		50   => 'under_50',
		200  => 'under_200',
		1000 => 'under_1000',
	];

	/**
	 * Events tracker.
	 *
	 * @var \BSF_Analytics_Events|null
	 */
	private static $events_tracker = null;

	/**
	 * Plugin version recorded before this request, captured on `plugins_loaded`
	 * (before Maintenance updates `surecookie_saved_version` on `admin_init`).
	 * Lets detect_state_events() identify upgrades even though it now runs on
	 * `admin_init`.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private $pre_upgrade_version = '';

	/**
	 * Constructor.
	 *
	 * @since 0.0.1-beta.1
	 */
	public function __construct() {
		// Stats payload filter.
		add_filter( 'bsf_core_stats', [ $this, 'add_analytics_data' ] );

		// Only run analytics in admin context.
		if ( ! is_admin() ) {
			return;
		}

		// Load Astra Notices for opt-in UI.
		if ( ! class_exists( 'BSF_Admin_Notices' ) ) {
			require_once SURECOOKIE_DIR . 'inc/lib/astra-notices/class-bsf-admin-notices.php';
		}

		add_filter(
			'uds_survey_allowed_screens',
			static function () {
				return [ 'plugins' ];
			}
		);

		// Load BSF Analytics library.
		if ( ! class_exists( 'BSF_Analytics_Loader' ) ) {
			require_once SURECOOKIE_DIR . 'inc/lib/bsf-analytics/class-bsf-analytics-loader.php';
		}

		if ( ! class_exists( 'BSF_Analytics_Loader' ) ) {
			return;
		}

		/** @var \BSF_Analytics_Loader $loader */
		$loader = \BSF_Analytics_Loader::get_instance();
		// Upstream docblock types $data as string, but the implementation pushes it onto an array of entity configs - see class-bsf-analytics-loader.php::set_entity().

		$deactivation_surveys = [
			[
				'id'                => 'deactivation-survey-surecookie',
				'popup_logo'        => SURECOOKIE_URL . 'assets/images/surecookie--brand-colored.svg',
				'plugin_slug'       => 'surecookie',
				'popup_title'       => 'Quick Feedback',
				'support_url'       => Helper::get_marketing_link( 'contact/', 'deactivation_survey_support' ),
				'popup_description' => 'If you have a moment, please share why you are deactivating SureCookie:',
				'show_on_screens'   => [ 'plugins', 'plugins-network' ],
				'plugin_version'    => SURECOOKIE_VERSION,
			],
		];

		// Capture Pro deactivations too when Pro is active.
		if ( defined( 'SURECOOKIE_PRO_VERSION' ) ) {
			$deactivation_surveys[] = [
				'id'                => 'deactivation-survey-surecookie-pro',
				'popup_logo'        => SURECOOKIE_URL . 'assets/images/surecookie--brand-colored.svg',
				'plugin_slug'       => 'surecookie-pro',
				'popup_title'       => 'Quick Feedback',
				'support_url'       => Helper::get_marketing_link( 'contact/', 'deactivation_survey_support' ),
				'popup_description' => 'If you have a moment, please share why you are deactivating SureCookie premium version:',
				'show_on_screens'   => [ 'plugins', 'plugins-network' ],
				'plugin_version'    => SURECOOKIE_PRO_VERSION,
			];
		}

		$loader->set_entity(
			[ // @phpstan-ignore argument.type
				'surecookie' => [
					'product_name'        => 'SureCookie',
					'path'                => SURECOOKIE_DIR . 'inc/lib/bsf-analytics',
					'author'              => 'Brainstorm Force',
					'time_to_display'     => '+24 hours',
					// IMPORTANT: Must be array of arrays - library iterates and passes each to show_feedback_form().
					'deactivation_survey' => apply_filters( 'surecookie_deactivation_survey_data', $deactivation_surveys ),
				],
			]
		);

		// Detect version change vs `surecookie_saved_version` (owned by Maintenance), before
		// the daily throttle gate so an update is never missed. Note: a frontend-first upgrade
		// or BSF_Analytics_Events load failure may skip `plugin_updated` - accepted tradeoff.
		$saved_version             = get_option( 'surecookie_saved_version', '' );
		$this->pre_upgrade_version = is_string( $saved_version ) ? $saved_version : '';
		if ( ! empty( $saved_version ) && $saved_version !== SURECOOKIE_VERSION ) {
			delete_transient( 'surecookie_state_events_checked' );
		}

		// One-time re-emit of reshaped events for the installed base (runs before
		// the throttle gate so it can bust the transient and re-queue this load).
		// Behind the same opt-in gate as detection: it writes three options, and a
		// site that declined tracking must not be written to at all.
		if ( $this->is_tracking_enabled() ) {
			$this->maybe_migrate_events_schema();
		}

		// State-based events, throttled once per day. Deferred to `admin_init` (not inline on
		// `plugins_loaded`) so add-ons registering a `surecookie_detect_state_events` listener
		// on `init` are attached before detection fires its extension hook.
		// Stamp first, so the detection this same visit triggers reports it and not the gap before it.
		add_action( 'admin_init', [ $this, 'record_manager_visit' ] );
		add_action( 'admin_init', [ $this, 'maybe_detect_state_events' ] );
	}

	// ============================================
	// Event Tracker
	// ============================================

	/**
	 * Get shared event tracker instance.
	 *
	 * @since 0.0.1-beta.1
	 * @return \BSF_Analytics_Events|null
	 */
	public static function events() {
		if ( ! class_exists( 'BSF_Analytics_Events' ) ) {
			require_once SURECOOKIE_DIR . 'inc/lib/bsf-analytics/class-bsf-analytics-events.php';
		}

		if ( self::$events_tracker === null ) {
			self::$events_tracker = new \BSF_Analytics_Events( 'surecookie' );
		}

		return self::$events_tracker;
	}

	// ============================================
	// Stats Payload
	// ============================================

	/**
	 * Add SureCookie analytics data to the BSF core stats payload.
	 *
	 * @param array<string, mixed> $stats_data Existing stats data.
	 * @return array<string, mixed> Modified stats data.
	 * @since 0.0.1-beta.1
	 */
	public function add_analytics_data( $stats_data = [] ) {
		$events = self::events();

		$stats_data['plugin_data']['surecookie'] = [
			'free_version'  => SURECOOKIE_VERSION,
			'site_language' => get_locale(),

			// One-time events (flushed from pending queue).
			'events_record' => $events ? $events->flush_pending() : [],

			// Daily KPIs (last 2 days).
			'kpi_records'   => $this->get_kpi_tracking_data(),

			// Get some total important data.
			'total_scans'   => $this->get_total_scans(),
			'total_logs'    => (int) Settings::get( 'total_logs' ),
		];

		return $stats_data;
	}

	// ============================================
	// State Event Detection
	// ============================================

	/**
	 * Run state-event detection once per day (throttle gate).
	 *
	 * Hooked to `admin_init` so add-on listeners registered during component load
	 * (on `init`) are attached before detect_state_events() fires its hook.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function maybe_detect_state_events(): void {
		if ( ! $this->is_tracking_enabled() ) {
			// Declined sites must not accumulate a queue: the shared BSF payload carries our
			// events whenever ANY registered product is opted in, so a stale queue would ship.
			// Guarded on existence so an opted-out site is not written to on every admin load.
			if ( get_option( 'surecookie_usage_events_pending', false ) !== false ) {
				delete_option( 'surecookie_usage_events_pending' );
			}
			return;
		}

		if ( get_transient( 'surecookie_state_events_checked' ) === false ) {
			$this->detect_state_events();
		}
	}

	/**
	 * Stamp a wp-admin visit by a user who can manage SureCookie, for `last_user_login`.
	 *
	 * A visit rather than `wp_login`, which a remember-me session skips for 14 days. Other
	 * users are ignored so customer accounts cannot make an unattended site look managed.
	 *
	 * @since 1.6.0
	 * @return void
	 */
	public function record_manager_visit(): void {
		// At most one write an hour, however busy the admin is.
		if ( time() - (int) get_option( self::LAST_LOGIN_OPTION, 0 ) < HOUR_IN_SECONDS ) {
			return;
		}

		if ( current_user_can( Helper::capability() ) && $this->is_tracking_enabled() ) {
			update_option( self::LAST_LOGIN_OPTION, time() );
		}
	}

	/**
	 * Whether this site opted in to SureCookie usage tracking.
	 *
	 * Mirrors BSF_Analytics::is_tracking_enabled() but for SureCookie alone. The library's
	 * own check passes when ANY registered BSF product is opted in, which is why detection
	 * cannot rely on it: our events ride the shared payload and would ship from a site that
	 * declined us while opting in to another plugin.
	 *
	 * @since 1.5.0
	 * @return bool
	 */
	private function is_tracking_enabled(): bool {
		if ( ! apply_filters( 'bsf_usage_tracking_enabled', true ) ) {
			return false;
		}

		// Our own settings REST writer stores this per blog, while the BSF library
		// mirrors a network-level opt-in into sitemeta; on multisite the two never
		// meet, so honour either rather than dropping one signal.
		$opted_in = get_option( 'surecookie_usage_optin', false ) === 'yes'
			|| get_site_option( 'surecookie_usage_optin', false ) === 'yes';

		return (bool) apply_filters( 'surecookie_tracking_enabled', $opted_in );
	}

	/**
	 * Re-emit reshaped events once per analytics schema-version bump.
	 *
	 * When the stored schema version is older than the code's, the affected
	 * events are removed from the pushed dedup list and the throttle transient is
	 * cleared so detect_state_events() re-queues them with their new shape on this
	 * same load. Idempotent: a no-op once the stored version matches.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private function maybe_migrate_events_schema(): void {
		$stored = (int) get_option( 'surecookie_analytics_events_version', 0 );

		// Collect events reshaped in every version newer than the stored one. Already-migrated
		// versions are skipped, so an event never replays - nothing to prune on a future bump.
		$to_flush = [];
		foreach ( self::RESHAPED_EVENTS_BY_VERSION as $version => $event_names ) {
			if ( $version > $stored ) {
				$to_flush = array_merge( $to_flush, $event_names );
			}
		}

		if ( $to_flush === [] ) {
			return; // Already up to date - nothing to re-emit.
		}

		$events = self::events();

		if ( $events === null ) {
			// Tracker class not loaded yet - retry next load without bumping.
			return;
		}

		// flush_pushed() with names re-opens only those events; the early return
		// above guarantees we never pass [] (which would clear ALL dedup).
		$events->flush_pushed( array_values( array_unique( $to_flush ) ) );
		delete_transient( 'surecookie_state_events_checked' );

		update_option( 'surecookie_analytics_events_version', self::ANALYTICS_EVENTS_VERSION, false );
	}

	/**
	 * Detect and queue state-based events on admin page load.
	 *
	 * Runs on every admin load but throttled by a daily transient.
	 * BSF_Analytics_Events dedup prevents duplicate tracking.
	 *
	 * @since 0.0.1-beta.1
	 * @return void
	 */
	private function detect_state_events(): void {
		$events = self::events();

		if ( $events === null ) {
			// BSF_Analytics_Events class not loaded - do NOT set transient; retry next load.
			return;
		}

		// Class is available - set throttle transient so we don't re-run for 24h.
		set_transient( 'surecookie_state_events_checked', 1, DAY_IN_SECONDS );

		/*
		 * Milestones below answer only WHEN something first happened; current configuration
		 * lives on the `feature_config` snapshot and never here (see admin/CLAUDE.md). They
		 * carry no version or count properties: a one-time event freezes them at first fire,
		 * so they would report a years-old version, and raw numbers explode the breakdown.
		 *
		 * Adding or removing an event here means updating the expected-events list in the
		 * instrumentation-health card (Metabase dashboard 140, Features tab), or that card
		 * silently stops reporting the event as missing.
		 */

		// ── plugin_activated ─────────────────────────────────────────────
		$install_time = get_option( 'surecookie_usage_installed_time', 0 );
		if ( ! $install_time ) {
			update_option( 'surecookie_usage_installed_time', time(), false );
		}

		$bsf_referrers = get_option( 'bsf_product_referers', [] );
		$source        = ! empty( $bsf_referrers['surecookie'] )
			? sanitize_text_field( $bsf_referrers['surecookie'] )
			: 'self';

		$events->track( 'plugin_activated', 'activated', [ 'source' => $source ] );

		// ── plugin_updated ───────────────────────────────────────────────
		// Uses the version captured on `plugins_loaded`, before Maintenance::init updates
		// `surecookie_saved_version` on `admin_init`, so the pre-upgrade value survives.
		$saved_version = $this->pre_upgrade_version;
		if ( $saved_version !== SURECOOKIE_VERSION && ! empty( $saved_version ) ) {
			$events->flush_pushed( [ 'plugin_updated' ] );
			$events->track(
				'plugin_updated',
				'updated',
				[
					'from_version' => $saved_version,
					'to_version'   => SURECOOKIE_VERSION,
				]
			);
		}

		// ── user_active_version ──────────────────────────────────────────
		// The one event whose value is a version by design: $force re-queues it every cycle,
		// so it reports what each active site runs today rather than at first fire.
		$events->track( 'user_active_version', SURECOOKIE_VERSION, [], true );

		// ── last_user_login ──────────────────────────────────────────────
		// Forced like the version above, so the two cross-tab per site: is anyone who could
		// act on the dashboard's update notice still coming to wp-admin?
		$events->track( 'last_user_login', $this->resolve_last_login(), [], true );

		// ── onboarding_completed ─────────────────────────────────────────
		// `source` names the screen that recorded it, separating the completion paths.
		if ( get_option( SURECOOKIE_ONBOARDING_COMPLETED_OPTION, false ) ) {
			$events->track(
				'onboarding_completed',
				'completed',
				[ 'source' => (string) get_option( 'surecookie_onboarding_completed_source', 'unknown' ) ]
			);
		}

		// ── onboarding_skipped ───────────────────────────────────────────
		// Not completed 3+ days after install. Whether the wizard was ever opened is
		// `onboarding_state` on the snapshot, which stays current as people come back to it.
		if ( ! get_option( SURECOOKIE_ONBOARDING_COMPLETED_OPTION, false ) && $this->get_days_since_install() >= 3 ) {
			$events->track( 'onboarding_skipped', 'skipped' );
		}

		// ── banner_configured ────────────────────────────────────────────
		// Admin settings saved at least once.
		if ( get_option( SURECOOKIE_SETTINGS_OPTION ) !== false ) {
			$events->track( 'banner_configured', 'configured' );
		}

		// ── first_scan_started / first_scan_completed ────────────────────
		if ( get_option( 'surecookie_first_scan_started_flag', false ) ) {
			$events->track( 'first_scan_started', 'started' );
		}

		if ( get_option( 'surecookie_first_scan_completed_flag', false ) ) {
			$events->track( 'first_scan_completed', 'completed' );
		}

		// ── first_consent_recorded ───────────────────────────────────────
		// The "ever" counterpart to the snapshot's `consent_activity`, which only sees the
		// retention window and so cannot distinguish "never" from "pruned".
		if ( Settings::get( 'total_logs' ) > 0 ) {
			$events->track( 'first_consent_recorded', 'recorded' );
		}

		// ── first_custom_cookie_added ────────────────────────────────────
		$custom_cookies = Settings::get( 'custom_cookies' );
		if ( ! empty( $custom_cookies ) && is_array( $custom_cookies ) ) {
			$events->track( 'first_custom_cookie_added', 'added' );
		}

		// ── first_auto_scan_started ──────────────────────────────────────
		// The scheduler actually ran, as opposed to auto-scanning merely being switched on
		// (which is `auto_scan_state` on the snapshot).
		if ( get_option( 'surecookie_first_auto_scan_started_flag', false ) ) {
			$events->track( 'first_auto_scan_started', 'started' );
		}

		// ── cookie_policy_page_configured ────────────────────────────────
		if ( (int) Settings::get( 'cookie_policy_page_id' ) > 0 ) {
			$events->track( 'cookie_policy_page_configured', 'configured' );
		}

		// ── account_connected ────────────────────────────────────────────
		// Keyed on get_auth_status() - the definition the UI and REST route use - because a
		// Pro-licensed site is connected everywhere yet never stores an account_ref.
		if ( AuthController::get_instance()->get_auth_status() ) {
			$events->track( 'account_connected', 'connected' );
		}

		// ── feature_config (recurring state snapshot) ────────────────────
		// Isolated: a third-party filter on Settings::get() must not take the milestones
		// already queued above, or the Pro hook below, down with it.
		try {
			$snapshot = $this->build_config_snapshot();
			$events->track( 'feature_config', $this->resolve_lifecycle_stage( $snapshot ), $snapshot, true );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		/**
		 * Fires after SureCookie has queued its own state-based events, letting
		 * add-ons (e.g. SureCookie Pro) push their events through the shared
		 * tracker. Runs once per throttled detection cycle.
		 *
		 * @since 1.2.0
		 * @param \BSF_Analytics_Events $events             Shared event tracker.
		 * @param int                   $days_since_install Days since plugin install.
		 */
		do_action( 'surecookie_detect_state_events', $events, $this->get_days_since_install() );
	}

	// ============================================
	// Configuration Snapshot
	// ============================================

	/**
	 * Current configuration as a set of low-cardinality tokens.
	 *
	 * Every value is an enum: raw numbers are deliberately absent because a distinct
	 * value per site turns any breakdown into one row per site. Read `version` alongside
	 * every other property - on a mixed installed base it separates "this state is false"
	 * from "the code reporting it is not on this build".
	 *
	 * @since 1.5.0
	 * @return array<string, string>
	 */
	private function build_config_snapshot(): array {
		$blocking_enabled = (bool) Settings::get( 'blocking_enabled' );
		$scan_details     = (array) get_option( SURECOOKIE_SCANNED_DETAILS_OPTION, [] );

		return [
			'version'            => SURECOOKIE_VERSION,
			'onboarding_state'   => $this->resolve_onboarding_state(),
			'consent_gate'       => $this->resolve_consent_gate(),
			'compliance_regime'  => $this->resolve_compliance_regime(),
			'blocking_state'     => $this->resolve_blocking_state( $blocking_enabled, $scan_details ),
			'blocking_overrides' => $this->resolve_blocking_overrides(),
			'scan_state'         => $this->resolve_scan_state( $scan_details ),
			'scan_recovery'      => $this->resolve_scan_recovery(),
			'scan_freshness'     => $this->resolve_scan_freshness( $scan_details ),
			'auto_scan_state'    => $this->resolve_auto_scan_state(),
			'gcm_state'          => $this->resolve_gcm_state( $blocking_enabled ),
			'consent_activity'   => $this->resolve_consent_activity(),
			'content_volume'     => $this->get_content_volume_bucket( $this->get_public_content_count() ),
			'policy_pages'       => $this->resolve_policy_pages(),
			'reconsent_surface'  => $this->resolve_reconsent_surface(),
			'cookie_declaration' => $this->resolve_cookie_declaration( $scan_details ),
			'connection_state'   => $this->resolve_connection_state(),
		];
	}

	/**
	 * How far along the value journey this site is, derived from the snapshot.
	 *
	 * Carried as the event_value so the composite costs no property slot.
	 *
	 * @param array<string, string> $snapshot Snapshot properties.
	 * @since 1.5.0
	 * @return string One of dormant|configured|inventoried|protecting|proven.
	 */
	private function resolve_lifecycle_stage( array $snapshot ): string {
		if ( in_array( $snapshot['consent_activity'], [ 'mostly_accepted', 'mostly_declined', 'mixed' ], true ) ) {
			return 'proven';
		}

		if ( $snapshot['blocking_state'] === 'matching' ) {
			return 'protecting';
		}

		if ( in_array( $snapshot['scan_state'], [ 'completed_empty', 'completed_with_cookies' ], true ) ) {
			return 'inventoried';
		}

		return get_option( SURECOOKIE_SETTINGS_OPTION ) !== false ? 'configured' : 'dormant';
	}

	/**
	 * Whether the wizard was never found, opened and left, or finished.
	 *
	 * `surecookie_onboarding_opened` only gained a writer in 1.5.0, so `never_opened`
	 * overstates on older builds - read this split by `version`.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_onboarding_state(): string {
		if ( get_option( SURECOOKIE_ONBOARDING_COMPLETED_OPTION, false ) ) {
			return 'completed';
		}

		return (int) get_option( 'surecookie_onboarding_opened', 0 ) > 0 ? 'abandoned' : 'never_opened';
	}

	/**
	 * Whether visitors are asked for consent, and under which model.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_consent_gate(): string {
		if ( ! (bool) Settings::get( 'banner_enabled' ) ) {
			return 'banner_off';
		}

		return Settings::get( 'consent_model' ) === 'opt-out' ? 'opt_out' : 'opt_in';
	}

	/**
	 * Which privacy regime the banner is configured for.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_compliance_regime(): string {
		$law  = Settings::get( 'compliance_law' );
		$name = is_array( $law ) ? strtolower( (string) ( $law['name'] ?? '' ) ) : '';

		if ( $name === '' ) {
			return 'unset';
		}

		return in_array( $name, [ 'gdpr', 'ccpa', 'lgpd' ], true ) ? $name : 'other';
	}

	/**
	 * Whether the blocker is actually gating anything on this site.
	 *
	 * `unknown` is load-bearing: Matched_Resources only ships in 1.5.0, so its option is
	 * absent across the entire installed base and on every site yet to upgrade. Without
	 * this value every healthy site would report `inert_with_trackers`.
	 *
	 * @param bool                 $blocking_enabled Whether blocking is switched on.
	 * @param array<string, mixed> $scan_details     Latest scan record.
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_blocking_state( bool $blocking_enabled, array $scan_details ): string {
		if ( ! $blocking_enabled ) {
			return 'off';
		}

		$matched = get_option( MatchedResources::OPTION, false );

		if ( $matched === false ) {
			return 'unknown';
		}

		if ( ! empty( $matched ) ) {
			return 'matching';
		}

		$has_trackers = ! empty( get_option( SURECOOKIE_SCANNED_RESOURCES_OPTION, [] ) )
			|| (int) ( $scan_details['cookies_count'] ?? 0 ) > 0;

		return $has_trackers ? 'inert_with_trackers' : 'nothing_to_block';
	}

	/**
	 * Which direction the admin is overriding the blocker in.
	 *
	 * `releases` is "you broke my site", `additions` is "you miss things" - opposite
	 * problems that a single "has overrides" flag would merge.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_blocking_overrides(): string {
		$releases  = ! empty( Settings::get( 'excluded_scan_resources' ) );
		$additions = ! empty( Settings::get( 'custom_blocked_scripts' ) );

		if ( $releases && $additions ) {
			return 'both';
		}

		if ( $releases ) {
			return 'releases';
		}

		return $additions ? 'additions' : 'none';
	}

	/**
	 * Where scanning stands, read from the LATEST scan rather than the first.
	 *
	 * The first-scan flags are write-once, so a site whose first scan succeeded and whose
	 * every scan since has hung would otherwise report success forever.
	 *
	 * @param array<string, mixed> $scan_details Latest scan record.
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_scan_state( array $scan_details ): string {
		if ( get_option( SaasClient::BLOCKED_FLAG_OPTION, false ) ) {
			return 'blocked_by_host';
		}

		if ( ! empty( $scan_details['date'] ) ) {
			return (int) ( $scan_details['cookies_count'] ?? 0 ) > 0 ? 'completed_with_cookies' : 'completed_empty';
		}

		return get_option( 'surecookie_first_scan_started_flag', false ) ? 'started_never_completed' : 'never';
	}

	/**
	 * Whether the browser-collected fallback rescued a blocked scan.
	 *
	 * The abandoned flag is a modifier on completion, not a failure: record_completed()
	 * sets both when the rescue pass closed the walk out instead of the browser.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_scan_recovery(): string {
		if ( get_option( AssistedScanTelemetry::COMPLETED_FLAG, false ) ) {
			return get_option( AssistedScanTelemetry::ABANDONED_FLAG, false ) ? 'completed_by_rescue' : 'completed';
		}

		return get_option( AssistedScanTelemetry::STARTED_FLAG, false ) ? 'started' : 'none';
	}

	/**
	 * How stale the published cookie inventory is.
	 *
	 * @param array<string, mixed> $scan_details Latest scan record.
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_scan_freshness( array $scan_details ): string {
		$date = isset( $scan_details['date'] ) ? strtotime( (string) $scan_details['date'] ) : false;

		if ( $date === false ) {
			return 'never';
		}

		// Sync writes this with current_time( 'mysql' ), i.e. site-local, so "now" has to be
		// the same shape: both sides are site-local mysql strings put through the same parser,
		// so the offset cancels. Using time() here skews the age by the site's UTC offset and
		// can read negative for a scan that just finished.
		$days = (int) floor( ( strtotime( current_time( 'mysql' ) ) - $date ) / DAY_IN_SECONDS );

		if ( $days < 30 ) {
			return 'under_30d';
		}

		return $days < 90 ? '30_90d' : 'over_90d';
	}

	/**
	 * Whether automatic scanning is switched on but inert.
	 *
	 * `enabled_cron_disabled` is the DISABLE_WP_CRON class that reaches support as
	 * "my scan is stuck queued forever".
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_auto_scan_state(): string {
		if ( ! (bool) Settings::get( 'auto_scan_enabled' ) ) {
			return 'off';
		}

		if ( Scheduler::next_run() > 0 ) {
			return 'scheduled';
		}

		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'enabled_cron_disabled' : 'enabled_unscheduled';
	}

	/**
	 * Whether Google Consent Mode is on while blocking is off.
	 *
	 * Its whitelist gate requires blocking, and the two toggles live on different
	 * screens, so `on_blocking_off` is a silent no-op the admin cannot see.
	 *
	 * @param bool $blocking_enabled Whether blocking is switched on.
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_gcm_state( bool $blocking_enabled ): string {
		if ( ! (bool) Settings::get( 'gcm_enabled' ) ) {
			return 'off';
		}

		return $blocking_enabled ? 'on' : 'on_blocking_off';
	}

	/**
	 * Whether the banner obtains consent, and which way visitors run.
	 *
	 * Counts only the retention window, so `none_in_window` is not "never" - cross it
	 * with the `first_consent_recorded` milestone.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_consent_activity(): string {
		if ( ! (bool) Settings::get( 'consent_logging_enabled' ) ) {
			return 'logging_off';
		}

		$counts = ConsentLog::count_all_actions( '', '' );
		$total  = (int) ( $counts['total'] ?? 0 );

		if ( $total <= 0 ) {
			return 'none_in_window';
		}

		if ( (int) ( $counts['accepted'] ?? 0 ) / $total >= 0.6 ) {
			return 'mostly_accepted';
		}

		return (int) ( $counts['declined'] ?? 0 ) / $total >= 0.6 ? 'mostly_declined' : 'mixed';
	}

	/**
	 * Which legal pages the banner can point at.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_policy_pages(): string {
		$cookie  = (int) Settings::get( 'cookie_policy_page_id' ) > 0;
		$privacy = (int) Settings::get( 'privacy_policy_page_id' ) > 0
			&& get_post_status( (int) Settings::get( 'privacy_policy_page_id' ) ) === 'publish';

		if ( $cookie && $privacy ) {
			return 'both';
		}

		if ( $cookie ) {
			return 'cookie_only';
		}

		return $privacy ? 'privacy_only' : 'none';
	}

	/**
	 * How visitors can reopen their choices - a GDPR obligation, so worth knowing
	 * across the base and not only for the Pro floating widget.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_reconsent_surface(): string {
		// Guarded rather than cast: a filter returning an array would raise a PHP warning,
		// and a warning is not a Throwable, so the snapshot's catch would not contain it.
		$menu_id   = Settings::get( 'reconsent_menu_id' );
		$menu      = is_scalar( $menu_id ) && (string) $menu_id !== '';
		$shortcode = $this->has_reconsent_shortcode();

		if ( $menu && $shortcode ) {
			return 'both';
		}

		if ( $menu ) {
			return 'menu';
		}

		return $shortcode ? 'shortcode_or_block' : 'none';
	}

	/**
	 * Whether any published content embeds the re-consent shortcode.
	 *
	 * A LIKE scan, so it is bounded to one row and runs at most once a day behind the
	 * detection throttle; 93% of installs publish fewer than 50 entries.
	 *
	 * @since 1.5.0
	 * @return bool
	 */
	private function has_reconsent_shortcode(): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( 'surecookie_reconsent_button' ) . '%'
			)
		);
	}

	/**
	 * Whether the cookie policy rests on catalog declarations or on scan output alone.
	 *
	 * @param array<string, mixed> $scan_details Latest scan record.
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_cookie_declaration( array $scan_details ): string {
		$installed = get_option( SURECOOKIE_INSTALLED_SERVICES_OPTION, [] );
		$slugs     = is_array( $installed ) && is_array( $installed['installed'] ?? null ) ? $installed['installed'] : [];

		if ( $slugs !== [] ) {
			return 'catalog_declared';
		}

		return (int) ( $scan_details['cookies_count'] ?? 0 ) > 0 ? 'scanned_only' : 'none';
	}

	/**
	 * Whether the site is attached to an account right now, and by which route.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_connection_state(): string {
		$auth = AuthController::get_instance();

		return $auth->get_auth_status() ? $this->resolve_connect_method( $auth ) : 'none';
	}

	/**
	 * How this site became connected.
	 *
	 * `portal_legacy` is a real population, not a fallback: account_ref only
	 * arrived in issue #469, and those installs cannot re-run the OAuth flow
	 * because Api::get_auth_payload() short-circuits on get_auth_status().
	 *
	 * @since 1.5.0
	 * @param AuthController $auth Auth controller.
	 * @return string One of 'portal', 'portal_legacy', 'license'.
	 */
	private function resolve_connect_method( AuthController $auth ): string {
		if ( $auth->get_account_ref() !== null ) {
			return 'portal';
		}

		return empty( get_option( AuthController::SETTINGS_KEY, [] ) ) ? 'license' : 'portal_legacy';
	}

	// ============================================
	// KPI Tracking
	// ============================================

	/**
	 * Get KPI tracking data for the last 2 days (excluding today).
	 *
	 * @since 0.0.1-beta.1
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
					'consent_logs' => $this->get_daily_consent_log_count( $date ),
				],
			];
		}

		return $kpi_records;
	}

	/**
	 * Get daily consent log entry count for a specific date.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @since 0.0.1-beta.1
	 * @return int Count of consent log entries for that date.
	 */
	private function get_daily_consent_log_count( string $date ): int {
		global $wpdb;

		$table = $wpdb->prefix . SURECOOKIE_CONSENT_LOG_DB;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE DATE(timestamp) = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$date
			)
		);
	}

	// ============================================
	// Helpers
	// ============================================

	/**
	 * Count published entries across every frontend-reachable post type.
	 *
	 * Mirrors core's sitemap definition - public and viewable, minus attachments -
	 * so the total matches what a full-site crawl would have to visit.
	 *
	 * @since 1.5.0
	 * @return int Published public entries across posts, pages and public CPTs.
	 */
	private function get_public_content_count(): int {
		$post_types = get_post_types( [ 'public' => true ], 'names' );
		unset( $post_types['attachment'] );

		$total = 0;
		foreach ( array_filter( $post_types, 'is_post_type_viewable' ) as $post_type ) {
			$counts = (array) wp_count_posts( $post_type );
			$total += (int) ( $counts['publish'] ?? 0 );
		}

		return $total;
	}

	/**
	 * Resolve a content count to its volume band.
	 *
	 * @param int $count Published public entries.
	 * @since 1.5.0
	 * @return string Band name, e.g. `under_50`.
	 */
	private function get_content_volume_bucket( int $count ): string {
		foreach ( self::CONTENT_VOLUME_BUCKETS as $upper_bound => $bucket ) {
			if ( $count < $upper_bound ) {
				return $bucket;
			}
		}

		return 'over_1000';
	}

	/**
	 * Age band of the last visit record_manager_visit() saw.
	 *
	 * `unknown` means none since this build was installed, not "never".
	 *
	 * @since 1.6.0
	 * @return string
	 */
	private function resolve_last_login(): string {
		$last = (int) get_option( self::LAST_LOGIN_OPTION, 0 );

		if ( $last <= 0 ) {
			return 'unknown';
		}

		$days = ( time() - $last ) / DAY_IN_SECONDS;

		if ( $days < 7 ) {
			return 'under_7d';
		}

		if ( $days < 30 ) {
			return '7_30d';
		}

		return $days < 90 ? '30_90d' : 'over_90d';
	}

	/**
	 * Get number of days since plugin installation.
	 *
	 * @since 0.0.1-beta.1
	 * @return int Days since install (0 if unknown).
	 */
	private function get_days_since_install(): int {
		$install_time = (int) get_option( 'surecookie_usage_installed_time', 0 );

		if ( $install_time <= 0 ) {
			return 0;
		}

		return (int) floor( ( time() - $install_time ) / DAY_IN_SECONDS );
	}

	/**
	 * Get total scans from the database.
	 *
	 * @since 0.0.1-beta.1
	 * @return int Total number of scans.
	 */
	private function get_total_scans(): int {
		$option = get_option(
			SURECOOKIE_SCANNED_DETAILS_OPTION,
			[
				'total_scans' => 0,
			]
		);

		return isset( $option['total_scans'] ) ? (int) $option['total_scans'] : 0;
	}
}
