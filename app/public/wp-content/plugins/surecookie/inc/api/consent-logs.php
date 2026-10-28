<?php
/**
 * ConsentLogs class
 *
 * Handles installed products related REST API endpoints for the SureCookie plugin.
 *
 * @package SureCookie\Inc\API
 */

namespace SureCookie\Inc\API;

use SureCookie\Inc\Database\ConsentLog as DBConsentLog;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Functions\Validate;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Traits\IpManager;
use SureCookie\Inc\Utils\LocalizeData;
use WP_Error;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ConsentLogs
 *
 * Handles this related REST API endpoints.
 */
class ConsentLogs extends Base {
	use GetInstance;
	use IpManager;

	/**
	 * Route Get Logs
	 */
	protected const GET_LOGS = '/consent-logs/get-logs';

	/**
	 * Mark logs viewed route.
	 */
	protected const MARK_LOGS_VIEWED = '/consent-logs/mark-logs-viewed';

	/**
	 * Route Create a Log
	 */
	protected const CREATE_LOG = '/consent-logs/create-log';

	/**
	 * Fresh consent-log token exchange endpoint (see get_fresh_token()).
	 */
	protected const GET_TOKEN = '/consent-logs/token';

	/**
	 * Route generate a pdf
	 */
	protected const GENERATE_PDF = '/consent-logs/generate-pdf';

	/**
	 * Route get unique countries
	 */
	protected const UNIQUE_COUNTRIES = '/consent-logs/unique-countries';

	/**
	 * Delete a log. The path must not end in `-log`: host rules protecting
	 * error-log/debug-log match that suffix and answer before WordPress loads.
	 * Unlike create-log, which is pinned by cached visitor HTML and so carries a
	 * `?rest_route=` fallback instead, this route ships with the admin bundle and
	 * could simply move (#1275).
	 */
	protected const DELETE_LOG = '/consent-logs/delete';

	/**
	 * Delete all log
	 */
	protected const DELETE_ALL_LOG = '/consent-logs/delete-all-logs';

	/**
	 * Maximum allowed size for the JSON-encoded preferences payload.
	 */
	private const MAX_PREFERENCES_LENGTH = 4096;

	/**
	 * Maximum consent log submissions allowed per IP in one window.
	 */
	private const RATE_LIMIT_MAX_ATTEMPTS = 10;

	/**
	 * Fixed window length (in seconds) for consent log rate limiting.
	 */
	private const RATE_LIMIT_WINDOW_SECONDS = 300;

	/**
	 * Number of transient shards used for rate limit counters.
	 *
	 * Using shard buckets keeps transient row count bounded (constant) instead of
	 * creating one transient key per IP address.
	 */
	private const RATE_LIMIT_SHARDS = 64;

	/**
	 * Object-cache group for rate limit counters.
	 */
	private const RATE_LIMIT_CACHE_GROUP = 'surecookie_rate';

	/**
	 * Transient key prefix for fixed-window consent log rate limiting.
	 */
	private const RATE_LIMIT_TRANSIENT_PREFIX = 'surecookie_consent_rate_';

	/**
	 * Separate rate-limit pool for the token exchange endpoint. Page-cache
	 * expiry is correlated across visitors, so recovery bursts behind one
	 * NAT egress IP must not consume the create-log budget and starve the
	 * very consent POSTs the exchange exists to save.
	 */
	private const RATE_LIMIT_TOKEN_TRANSIENT_PREFIX = 'surecookie_token_rate_';

	/**
	 * Daily ceiling for the rejected-token counter. Once reached, further
	 * rejections stop writing the transient so an unauthenticated
	 * forged-token flood cannot drive unbounded wp_options writes.
	 */
	private const TOKEN_REJECTION_COUNTER_CAP = 10000;

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			self::GET_LOGS,
			[
				'methods'             => WP_REST_Server::READABLE, // GET method.
				'callback'            => [ $this, 'get_consent_logs' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'page'       => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'limit'      => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'search'     => [
						'required'          => false,
						'type'              => 'string',
						'description'       => __( 'Search by IP address or Session ID', 'surecookie' ),
						'sanitize_callback' => 'sanitize_text_field',
					],
					'log_action' => [
						'required'          => false,
						'type'              => 'string',
						'enum'              => [ '', 'accepted', 'declined', 'partially_accepted', 'received', 'shared' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'country'    => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'date_from'  => [
						'required'          => false,
						'type'              => 'string',
						'format'            => 'date',
						'description'       => __( 'Inclusive start of the log date range (Y-m-d).', 'surecookie' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => [ $this, 'validate_date_param' ],
					],
					'date_to'    => [
						'required'          => false,
						'type'              => 'string',
						'format'            => 'date',
						'description'       => __( 'Inclusive end of the log date range (Y-m-d).', 'surecookie' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => [ $this, 'validate_date_param' ],
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::MARK_LOGS_VIEWED,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'mark_logs_viewed' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::CREATE_LOG,
			[
				'methods'             => WP_REST_Server::CREATABLE, // POST method.
				'callback'            => [ $this, 'update_consent_log' ],
				'permission_callback' => [ $this, 'validate_origin' ],
				'args'                => [
					'preferences'       => [
						'required' => true,
						'type'     => 'string',
					],
					'action_type'       => [
						'required' => true,
						'type'     => 'string',
					],
					'session_id'        => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'consent_log_token' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// POST (not GET): keeps the stale token out of access/proxy logs and
		// browser history, and the response is uncacheable by design anyway.
		register_rest_route(
			$this->get_api_namespace(),
			self::GET_TOKEN,
			[
				'methods'             => WP_REST_Server::CREATABLE, // Public -- POST method.
				'callback'            => [ $this, 'get_fresh_token' ],
				'permission_callback' => [ $this, 'validate_token_request' ],
				'args'                => [
					'stale_token' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::GENERATE_PDF,
			[
				'methods'             => WP_REST_Server::READABLE, // Admin -- GET method.
				'callback'            => [ $this, 'get_pdf_prerequisites' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'log_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::UNIQUE_COUNTRIES,
			[
				'methods'             => WP_REST_Server::READABLE, // Admin -- GET method.
				'callback'            => [ $this, 'get_unique_countries' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::DELETE_LOG,
			[
				'methods'             => WP_REST_Server::DELETABLE, // Admin -- DELETE method.
				'callback'            => [ $this, 'delete_consent_log' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'log_id' => [
						'required' => true,
						'oneOf'    => [
							[
								'type' => 'integer',
							],
							[
								'type'     => 'array',
								'items'    => [
									'type' => 'integer',
								],
								'maxItems' => 500,
							],
						],
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::DELETE_ALL_LOG,
			[
				'methods'             => WP_REST_Server::DELETABLE, // Admin -- GET method.
				'callback'            => [ $this, 'delete_all_consent_logs' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);
	}

	/**
	 * Validate request origin for public consent logging endpoint.
	 *
	 * Requires a valid consent_log_token on every request, and additionally
	 * enforces same-origin when Referer or Origin is present. The token gate
	 * defends against spoofed-header forgery; the host check defends against
	 * any token leaked via cached HTML being replayed cross-origin.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @since 0.0.0-alpha.1
	 * @return bool|WP_Error True when valid, error on invalid origin.
	 */
	public function validate_origin( $request ) {
		$token = sanitize_text_field( (string) $request->get_param( 'consent_log_token' ) );
		if ( ! LocalizeData::is_valid_consent_log_token( $token ) ) {
			self::record_token_rejection();

			/**
			 * Fires when a consent-log request is rejected for an invalid or
			 * expired token - most commonly HTML served from a page cache
			 * older than the token validity window. The frontend retries once
			 * with a freshly exchanged token; this hook and the daily counter
			 * make any remaining silent consent-log loss measurable.
			 *
			 * @since 1.2.1
			 */
			do_action( 'surecookie_consent_log_token_rejected' );

			return new WP_Error(
				'surecookie_invalid_token',
				__( 'Request origin could not be verified.', 'surecookie' ),
				[ 'status' => 403 ]
			);
		}

		return $this->check_same_origin_headers( $request );
	}

	/**
	 * Permission gate for the fresh-token exchange endpoint.
	 *
	 * Requires a token genuinely issued by this site within the last ~30
	 * days - typically an expired one embedded in long-lived page-cache
	 * HTML - so the endpoint proves the same "came from a real page load"
	 * property as validate_origin() without accepting forged tokens. The
	 * same-origin header check applies on top when headers are present.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @since 1.2.1
	 * @return bool|WP_Error True when valid, error otherwise.
	 */
	public function validate_token_request( $request ) {
		$stale_token = sanitize_text_field( (string) $request->get_param( 'stale_token' ) );

		if ( ! LocalizeData::is_recently_issued_consent_log_token( $stale_token ) ) {
			return new WP_Error(
				'surecookie_invalid_stale_token',
				__( 'Request origin could not be verified.', 'surecookie' ),
				[ 'status' => 403 ]
			);
		}

		return $this->check_same_origin_headers( $request );
	}

	/**
	 * Issue a fresh consent-log token in exchange for a genuinely-issued
	 * stale one (permission gate: validate_token_request()).
	 *
	 * Explicitly uncacheable: the whole point is recovering when cached page
	 * HTML carries an expired token, so this response must never be served
	 * from a page/CDN cache itself.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 1.2.1
	 * @return void
	 */
	public function get_fresh_token( $request ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required REST callback signature.
		// Set the uncacheable headers first so every response - including the
		// 429 below - carries them; this endpoint must never be cached.
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		}

		if ( $this->is_rate_limited( self::get_client_ip(), self::RATE_LIMIT_TOKEN_TRANSIENT_PREFIX ) ) {
			SendJson::error(
				[
					'message' => __( 'Too many requests. Please try again later.', 'surecookie' ),
					'code'    => 'too_many_requests',
				],
				429
			);
			return;
		}

		SendJson::success(
			[
				'token' => LocalizeData::get_consent_log_token(),
			]
		);
	}

	/**
	 * Mark the latest consent log as viewed for the current admin user.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 1.2.0
	 * @return void
	 */
	public function mark_logs_viewed( $request ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required REST callback signature.
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			SendJson::error(
				[
					'message' => __( 'Unable to determine the current user.', 'surecookie' ),
				],
				403
			);
			return;
		}

		DBConsentLog::mark_logs_viewed_for_user( $user_id );

		SendJson::success(
			[
				'message'        => __( 'Consent logs marked as viewed.', 'surecookie' ),
				'last_viewed_id' => DBConsentLog::get_last_viewed_log_id_for_user( $user_id ),
			]
		);
	}

	/**
	 * Validate an optional Y-m-d date parameter.
	 *
	 * Checked with a strict round-trip rather than strtotime(), which accepts
	 * impossible dates like 2026-02-30 and silently rolls them into March.
	 *
	 * @param mixed $value Raw parameter value.
	 * @since 1.4.0
	 * @return bool
	 */
	public function validate_date_param( $value ): bool {
		if ( ! is_string( $value ) || $value === '' ) {
			return true;
		}

		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );

		return $date instanceof \DateTimeImmutable && $date->format( 'Y-m-d' ) === $value;
	}

	/**
	 * Get admin settings
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function get_consent_logs( $request ): void {
		$limit     = max( 1, (int) ( $request->get_param( 'limit' ) ?? 10 ) );
		$page      = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$search    = $request->get_param( 'search' ) ?? '';
		$action    = $request->get_param( 'log_action' ) ?? '';
		$country   = $request->get_param( 'country' ) ?? '';
		$date_from = (string) ( $request->get_param( 'date_from' ) ?? '' );
		$date_to   = (string) ( $request->get_param( 'date_to' ) ?? '' );

		// An inverted range would silently return nothing, which reads as data loss.
		if ( $date_from !== '' && $date_to !== '' && $date_from > $date_to ) {
			SendJson::error(
				[
					'message' => __( 'The start date cannot be later than the end date.', 'surecookie' ),
					'code'    => 'invalid_date_range',
				],
				400
			);
			return;
		}

		// IP addresses are anonymized before storage (last octet zeroed for IPv4).
		// If the search term is a valid IP, anonymize it so it matches the stored value.
		if ( ! empty( $search ) && filter_var( $search, FILTER_VALIDATE_IP ) ) {
			$search = self::anonymize_ip( $search );
		}

		$offset = ( $page - 1 ) * $limit;

		$logs   = DBConsentLog::get_filtered( $search, $action, $country, $limit, $offset, $date_from, $date_to );
		$counts = DBConsentLog::count_all_actions( $search, $country, $action, $date_from, $date_to );

		SendJson::success(
			[
				'message' => __( 'Consent logs retrieved successfully.', 'surecookie' ),
				'data'    => [
					'logs'  => array_map(
						static function ( $log ) {
							$log['country']      = $log['country'] ?? '';
							$log['region']       = $log['region'] ?? '';
							$log['is_forwarded'] = (int) ( $log['is_forwarded'] ?? 0 );
							$log['origin_site']  = Sanitize::text( $log['origin_site'] ?? '' );
							$forwarded_to        = json_decode( (string) ( $log['forwarded_to'] ?? '' ), true );
							$log['forwarded_to'] = is_array( $forwarded_to ) ? array_map( 'esc_url_raw', array_values( array_filter( $forwarded_to, 'is_string' ) ) ) : [];
							unset( $log['session_id'], $log['origin_blog_id'], $log['forwarded_blog_ids'] );
							return $log;
						},
						$logs
					),
					'count' => [
						'total'          => $counts['total'],
						'total_accepted' => $counts['accepted'],
						'total_declined' => $counts['declined'],
						'total_partial'  => $counts['partially_accepted'],
					],
				],
			]
		);
	}

	/**
	 * Update admin settings
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function update_consent_log( $request ): void {
		$consent_logging_enabled = Settings::get( 'consent_logging_enabled' );
		if ( ! $consent_logging_enabled ) {
			// Logging is intentionally disabled: recording the log is a
			// successful no-op, not an error. Returning an error envelope here
			// made the client treat a normal "logging off" response as a
			// failure (the message already says "successfully"). Consent itself
			// is persisted client-side regardless of this call.
			SendJson::success(
				[
					'message' => __( 'Consent processed successfully (logging disabled)', 'surecookie' ),
				]
			);
			return;
		}

		// Security: Validate request origin to prevent cross-site consent submission.
		$origin_check = $this->validate_origin( $request );
		if ( is_wp_error( $origin_check ) ) {
			SendJson::error(
				[
					'message' => $origin_check->get_error_message(),
					// Preserve the real error code: the frontend's stale-token
					// retry discriminates on `surecookie_invalid_token`.
					'code'    => $origin_check->get_error_code(),
				],
				403
			);
			return;
		}

		$raw_ip = self::get_client_ip();
		if ( $this->is_rate_limited( $raw_ip ) ) {
			SendJson::error(
				[
					'message' => __( 'Too many requests. Please try again later.', 'surecookie' ),
					'code'    => 'too_many_requests',
				],
				429
			);
			return;
		}

		// Get preferences and action type.
		$preferences = $request->get_param( 'preferences' );
		$preferences = is_string( $preferences ) ? wp_unslash( $preferences ) : '';
		$action_type = $request->get_param( 'action_type' );
		$action_type = ! empty( $action_type ) ? sanitize_text_field( wp_unslash( $action_type ) ) : 'partially_accepted';

		$session_id = $request->get_param( 'session_id' );
		$session_id = ! empty( $session_id ) ? sanitize_text_field( wp_unslash( $session_id ) ) : '';

		if ( Validate::empty_string( $preferences ) ) {
			SendJson::error(
				[
					'message' => __( 'Preferences should not be empty.', 'surecookie' ),
				]
			);
			return;
		}

		if ( strlen( $preferences ) > self::MAX_PREFERENCES_LENGTH ) {
			SendJson::error(
				[
					'message' => __( 'Preferences data is too large.', 'surecookie' ),
				]
			);
			return;
		}

		$preferences = json_decode( $preferences, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $preferences ) ) {
			SendJson::error(
				[
					'message' => __( 'Invalid preferences format.', 'surecookie' ),
				]
			);
			return;
		}

		$preferences = wp_json_encode( $preferences );

		if ( ! is_string( $preferences ) || $preferences === '' ) {
			SendJson::error(
				[
					'message' => __( 'Invalid preferences format.', 'surecookie' ),
				]
			);
			return;
		}

		if ( empty( $session_id ) ) {
			SendJson::error(
				[
					'message' => __( 'Session ID is required.', 'surecookie' ),
				]
			);
			return;
		}

		// Get current user ID (if logged in).
		$user_id = get_current_user_id();

		// Validate action type against ENUM values.
		$allowed_actions = [ 'accepted', 'declined', 'partially_accepted' ];
		if ( ! in_array( $action_type, $allowed_actions, true ) ) {
			// Default to partially_accepted for invalid values.
			$action_type = 'partially_accepted';
		}

		// Under opt-out (CCPA), 'accepted' records the visitor accepting the
		// defaults; the Accept All gate below protects the opt-in button only,
		// and applying it here silently dropped every CCPA acceptance log.
		$consent_model = (string) apply_filters( 'surecookie_active_consent_model', (string) Settings::get( 'consent_model' ) );

		// Reject an 'accepted' log when Accept All is disabled for this site or region.
		// The filter lets Pro apply per-region stricter-wins (global AND region); the
		// default value is the global setting alone.
		if ( $action_type === 'accepted' && $consent_model !== 'opt-out' ) {
			$accept_all_allowed = (bool) Settings::get( 'accept_all_enabled' );
			/**
			 * Filter whether the Accept All action is permitted for the current request.
			 *
			 * Pro (Geographic Targeting) narrows this per region. Free plugin defaults
			 * to the global toggle. Never broaden the value - only narrow.
			 *
			 * @param bool             $accept_all_allowed Effective Accept All state.
			 * @param \WP_REST_Request $request            The inbound REST request.
			 */
			$accept_all_allowed = (bool) apply_filters(
				'surecookie_accept_all_effective_enabled',
				$accept_all_allowed,
				$request
			);

			if ( ! $accept_all_allowed ) {
				SendJson::error(
					[
						'message' => __( 'Accept All is not enabled for this site.', 'surecookie' ),
						'code'    => 'surecookie_accept_all_disabled',
					],
					422
				);
				return;
			}
		}

		// Single owner of whether an IP is recorded at all, and of the geolocation
		// call that resolves the country and region; see
		// `surecookie_skip_consent_logs_ips`. Region is derived from the same
		// address, so resolving it separately would leak a lookup past the gate.
		$origin     = self::consent_log_origin( $raw_ip );
		$ip_address = $origin['ip'];
		$country    = $origin['country'];
		$region     = $origin['region'];

		// Record which geo preset governed this consent, resolved server-side by
		// Pro's geo module (empty on the free / no-geo path). Kept out of the
		// client POST so the proof-of-consent record can't be spoofed.
		$geo_preset_id = (string) apply_filters( 'surecookie_active_geo_preset_id', '' );

		$stored = false;
		$result = DBConsentLog::upsert( $ip_address, $user_id, $session_id, $preferences, $action_type, $country, null, $geo_preset_id, false, null, $region, $stored );

		if ( $result ) {
			// Gather consent log counts for analytics. A same-second duplicate
			// resolves to the existing row without writing, so counting it would
			// drift the total away from the table it is meant to describe.
			if ( $stored ) {
				$existing_count = Settings::get( 'total_logs' );
				$new_count      = $existing_count + 1;
				Settings::update( 'total_logs', $new_count );
			}
			SendJson::success(
				[
					'message'    => __( 'Consent saved successfully.', 'surecookie' ),
					'session_id' => $session_id,
					'action'     => $action_type,
				]
			);
			return;
		}

		SendJson::error(
			[
				'message' => __( 'Failed to save consent.', 'surecookie' ),
			]
		);
	}

	/**
	 * Get PDF prerequisites - returns all logs for the session of the given log ID.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function get_pdf_prerequisites( $request ): void {
		$log_id = absint( $request->get_param( 'log_id' ) );

		if ( ! $log_id ) {
			SendJson::error(
				[
					'message' => __( 'Invalid log ID', 'surecookie' ),
				]
			);
			return;
		}

		// Fetch the requested log to get its session_id.
		$log = DBConsentLog::get_by_log_id( $log_id );

		if ( ! $log ) {
			SendJson::error(
				[
					'message' => __( 'Log not found.', 'surecookie' ),
				]
			);
			return;
		}

		$session_id = Sanitize::text( $log['session_id'] ?? '' );

		// Fetch all logs for this session.
		$session_logs = ! empty( $session_id ) ? DBConsentLog::get_by_session_id( $session_id ) : [ $log ];

		$sanitized_logs = array_map(
			static function ( $entry ) {
				$preferences  = json_decode( $entry['preferences'] ?? '', true );
				$forwarded_to = json_decode( (string) ( $entry['forwarded_to'] ?? '' ), true );

				return [
					'id'            => Sanitize::integer( $entry['id'] ?? '' ),
					'ip_address'    => Sanitize::text( $entry['ip_address'] ?? '' ),
					'timestamp'     => Sanitize::text( $entry['timestamp'] ?? '' ),
					'country'       => Sanitize::text( $entry['country'] ?? '' ),
					'region'        => Sanitize::text( $entry['region'] ?? '' ),
					'action'        => Sanitize::text( $entry['action'] ?? '' ),
					'preferences'   => is_array( $preferences ) ? $preferences : [],
					'session_id'    => Sanitize::text( $entry['session_id'] ?? '' ),
					'geo_preset_id' => Sanitize::text( $entry['geo_preset_id'] ?? '' ),
					// Consent-forwarding fields - needed by the JS PDF generator.
					'is_forwarded'  => (int) ( $entry['is_forwarded'] ?? 0 ),
					'origin_site'   => Sanitize::text( $entry['origin_site'] ?? '' ),
					'forwarded_to'  => is_array( $forwarded_to ) ? array_map( 'esc_url_raw', array_values( array_filter( $forwarded_to, 'is_string' ) ) ) : [],
				];
			},
			$session_logs
		);

		SendJson::success(
			[
				'message'    => __( 'Log details retrieved successfully.', 'surecookie' ),
				'session_id' => $session_id,
				'logs'       => $sanitized_logs,
			]
		);
	}

	/**
	 * Get unique countries
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function get_unique_countries( $request ): void {
		global $wpdb;
		$table_name = $wpdb->prefix . SURECOOKIE_CONSENT_LOG_DB;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Admin-only filter list; %i is a valid WP 6.2+ identifier placeholder.
		$countries = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT country FROM %i WHERE country IS NOT NULL AND country != %s', $table_name, '' ) );

		$countries = is_array( $countries )
			? array_values( array_unique( array_map( 'sanitize_text_field', $countries ) ) )
			: [];

		SendJson::success(
			[
				'message'   => __( 'Log details retrieved successfully.', 'surecookie' ),
				'countries' => $countries,
			]
		);
	}

	/**
	 * Delete a log or multiple logs
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function delete_consent_log( $request ): void {
		$log_id = $request->get_param( 'log_id' );

		// Handle both single ID and array of IDs.
		if ( is_array( $log_id ) ) {
			// Bulk delete.
			if ( empty( $log_id ) ) {
				SendJson::error(
					[
						'message' => __( 'Invalid log IDs', 'surecookie' ),
					]
				);
				return;
			}

			$result = DBConsentLog::delete_by_log_ids( $log_id );

			if ( $result === false ) {
				SendJson::error(
					[
						'message' => __( 'Failed to delete logs.', 'surecookie' ),
					]
				);
				return;
			}

			SendJson::success(
				[
					'message' => sprintf(
						/* translators: %d: number of logs deleted */
						__( '%d log(s) deleted successfully.', 'surecookie' ),
						$result
					),
					'deleted' => $result,
				]
			);
			return;
		}

		// Single delete.
		$log_id = absint( $log_id );
		if ( ! $log_id ) {
			SendJson::error(
				[
					'message' => __( 'Invalid log ID', 'surecookie' ),
				]
			);
			return;
		}

		$result = DBConsentLog::delete_by_log_id( $log_id );

		if ( $result ) {
			SendJson::success(
				[
					'message' => __( 'Log deleted successfully.', 'surecookie' ),
					'deleted' => $result,
				]
			);
			return;
		}

		SendJson::error(
			[
				'message' => __( 'Failed to delete log.', 'surecookie' ),
			]
		);
	}

	/**
	 * Delete all logs
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function delete_all_consent_logs( $request ): void {
		global $wpdb;
		$table = $wpdb->prefix . SURECOOKIE_CONSENT_LOG_DB;

		// Pre-fetch origin rows that were forwarded to partner subsites so the
		// cascade hook can erase forwarded copies *after* the local wipe succeeds.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One-shot admin delete; %i is a valid WP 6.2+ identifier placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, session_id, forwarded_to, forwarded_blog_ids, is_forwarded FROM %i WHERE is_forwarded = 0 AND forwarded_to IS NOT NULL AND forwarded_to != %s AND forwarded_to != %s', $table, '', '[]' ), ARRAY_A );

		$ids  = [];
		$logs = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$id          = (int) $row['id'];
				$ids[]       = $id;
				$logs[ $id ] = $row;
			}
		}

		$result = DBConsentLog::get_instance()->use_delete_all();

		if ( $result && ! empty( $ids ) ) {
			// Fire AFTER the local wipe succeeds so partners never erase ahead of origin.
			do_action( 'surecookie_consent_log_deleted_bulk', $ids, $logs );
		}

		// 0 rows (already-empty table) is a success, not a failure.
		if ( $result !== false ) {
			SendJson::success(
				[
					'message' => __( 'All logs deleted successfully.', 'surecookie' ),
					'deleted' => (int) $result,
				]
			);
			return;
		}

		SendJson::error(
			[
				'message' => __( 'Failed to delete logs.', 'surecookie' ),
			]
		);
	}

	/**
	 * Enforce same-origin when Referer or Origin headers are present.
	 *
	 * With neither header present this passes - the token gates in the
	 * permission callbacks above are the primary defense; the host check
	 * defends against a leaked token being replayed cross-origin.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @since 1.2.1
	 * @return bool|WP_Error True when same-origin (or headerless), error otherwise.
	 */
	private function check_same_origin_headers( $request ) {
		$invalid_origin_error = static function () {
			return new WP_Error(
				'surecookie_invalid_origin',
				__( 'Request origin is not allowed.', 'surecookie' ),
				[ 'status' => 403 ]
			);
		};

		$referer = $request->get_header( 'referer' );
		$origin  = $request->get_header( 'origin' );

		$request_url = '';
		if ( ! empty( $referer ) && is_string( $referer ) ) {
			$request_url = $referer;
		} elseif ( ! empty( $origin ) && is_string( $origin ) ) {
			$request_url = $origin;
		}

		// No headers - token already validated above is sufficient.
		if ( $request_url === '' ) {
			return true;
		}

		// The public Site Address, never the WordPress Address: `rest_url()`
		// builds every endpoint from `home_url()`, so that is the origin the
		// browser actually sends. Reading `siteurl` here refused the site's own
		// frontend wherever the two differ, even by a `www` (#1348).
		$site_url  = home_url();
		$site_host = wp_parse_url( $site_url, PHP_URL_HOST );
		$site_port = wp_parse_url( $site_url, PHP_URL_PORT );
		$site_ssl  = wp_parse_url( $site_url, PHP_URL_SCHEME );

		$request_host = wp_parse_url( $request_url, PHP_URL_HOST );
		$request_port = wp_parse_url( $request_url, PHP_URL_PORT );
		$request_ssl  = wp_parse_url( $request_url, PHP_URL_SCHEME );

		// Reject malformed URLs or cross-origin hosts.
		if ( empty( $site_host ) || empty( $request_host ) ) {
			return $invalid_origin_error();
		}

		if ( strtolower( $site_host ) !== strtolower( $request_host ) ) {
			return $invalid_origin_error();
		}

		// If request has explicit scheme, enforce same scheme.
		if ( ! empty( $site_ssl ) && ! empty( $request_ssl ) && strtolower( $site_ssl ) !== strtolower( $request_ssl ) ) {
			return $invalid_origin_error();
		}

		// If either side has explicit port, enforce same port.
		if ( ( ! empty( $site_port ) || ! empty( $request_port ) ) && (int) $site_port !== (int) $request_port ) {
			return $invalid_origin_error();
		}

		// Same origin.
		return true;
	}

	/**
	 * Count rejected-token requests in a daily (UTC-day-keyed) transient so
	 * silent consent-log loss is measurable. Kept for two days so
	 * day-over-day reads survive the midnight rollover. Increments stop at
	 * TOKEN_REJECTION_COUNTER_CAP: this runs in an unauthenticated
	 * permission callback before any rate limiter, so the cap is what
	 * bounds wp_options writes under a forged-token flood.
	 *
	 * @since 1.2.1
	 * @return void
	 */
	private static function record_token_rejection(): void {
		$key   = self::token_rejection_key();
		$count = (int) get_transient( $key );

		if ( $count >= self::TOKEN_REJECTION_COUNTER_CAP ) {
			return;
		}

		set_transient( $key, $count + 1, 2 * DAY_IN_SECONDS );
	}

	/**
	 * Today's rejected-token count, for Site Health to report.
	 *
	 * @since 1.7.0
	 * @return int
	 */
	public static function get_token_rejection_count(): int {
		return (int) get_transient( self::token_rejection_key() );
	}

	/**
	 * Transient key for the current UTC day's rejection counter.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	private static function token_rejection_key(): string {
		return 'surecookie_token_rejections_' . gmdate( 'Ymd' );
	}

	/**
	 * Check and increment per-IP consent log submission counters.
	 *
	 * An external object cache gives a real atomic INCR, so a parallel burst cannot
	 * lost-update the counter. The transient fallback keeps the 64-shard packing -
	 * every visitor hits this endpoint, so one key per IP would grow wp_options
	 * without bound - and stays non-atomic, overshooting a burst by roughly its width.
	 *
	 * @param string $raw_ip           Client IP address.
	 * @param string $transient_prefix Rate-limit pool (transient key prefix).
	 * @return bool True when the request should be blocked.
	 * @since 0.0.1-beta.3
	 */
	private function is_rate_limited( string $raw_ip, string $transient_prefix = self::RATE_LIMIT_TRANSIENT_PREFIX ): bool {
		$ip_hash      = wp_hash( $raw_ip );
		$window_index = (int) floor( time() / self::RATE_LIMIT_WINDOW_SECONDS );
		// Keep buckets briefly past the window edge to avoid boundary races between
		// near-simultaneous requests that straddle expiration.
		$ttl = self::RATE_LIMIT_WINDOW_SECONDS + MINUTE_IN_SECONDS;

		if ( wp_using_ext_object_cache() ) {
			$key = $transient_prefix . $window_index . '_' . $ip_hash;

			// add() is atomic set-if-absent, so two requests racing to seed a window
			// cannot both write 1 and lose a count the way incr-then-set would.
			// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- $ttl is 360s, above the 300s floor; the sniff can't resolve the constants.
			if ( wp_cache_add( $key, 1, self::RATE_LIMIT_CACHE_GROUP, $ttl ) ) {
				return false; // First request in this window.
			}

			return (int) wp_cache_incr( $key, 1, self::RATE_LIMIT_CACHE_GROUP ) > self::RATE_LIMIT_MAX_ATTEMPTS;
		}

		// crc32() can return signed ints; cast via unsigned string for stable,
		// non-negative shard assignment across 32-bit/64-bit PHP runtimes.
		$shard       = (int) ( sprintf( '%u', crc32( $ip_hash ) ) % self::RATE_LIMIT_SHARDS );
		$transient   = $transient_prefix . $window_index . '_' . $shard;
		$window_data = get_transient( $transient );
		$window_data = is_array( $window_data ) ? $window_data : [];

		$attempts = isset( $window_data[ $ip_hash ] ) ? (int) $window_data[ $ip_hash ] : 0;
		if ( $attempts >= self::RATE_LIMIT_MAX_ATTEMPTS ) {
			return true;
		}

		$window_data[ $ip_hash ] = $attempts + 1;
		set_transient( $transient, $window_data, $ttl );

		return false;
	}
}
