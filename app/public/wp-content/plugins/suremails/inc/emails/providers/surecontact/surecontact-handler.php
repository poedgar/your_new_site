<?php
/**
 * SurecontactHandler.php
 *
 * Handles sending emails through SureContact's hosted SMTP service.
 *
 * @package SureMails\Inc\Emails\Providers\Surecontact
 */

namespace SureMails\Inc\Emails\Providers\SURECONTACT;

use SureMails\Inc\Emails\Handler\ConnectionHandler;
use SureMails\Inc\Emails\ProviderHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SurecontactHandler
 *
 * Implements the ConnectionHandler interface for SureContact. The handler covers
 * three flows:
 *
 *   1. Existing-account OAuth-style: an `oauth_token` arrives via the auth-code
 *      callback. We exchange it for a long-lived `api_key` via the SureContact
 *      `/connections/exchange` endpoint, then store the key + workspace info on
 *      the connection.
 *   2. Existing-key validation: when a connection is already saved (`api_key`
 *      is non-empty), `authenticate()` validates it by calling `/suremails/status`.
 *   3. Send: the standard ConnectionHandler::send() shape — POSTs to
 *      `/suremails/send` with bearer auth.
 */
class SurecontactHandler implements ConnectionHandler {

	/**
	 * Base URL for the SureContact API.
	 */
	private const API_BASE = 'https://api.surecontact.com/api/v1';

	/**
	 * URL the user is redirected to in order to authorize a SureContact
	 * connection.
	 */
	private const CONNECT_URL = 'https://app.surecontact.com/connect';

	/**
	 * URL surfaced to users for upgrading their SureContact plan from inside
	 * the WordPress dashboard.
	 */
	private const BILLING_URL = 'https://app.surecontact.com/organization/plans';

	/**
	 * URL surfaced to users for managing their SureContact sending domains from
	 * inside the WordPress dashboard.
	 */
	private const SENDING_DOMAINS_URL = 'https://app.surecontact.com/organization/sending-domains';

	/**
	 * Acquisition channels SureContact accepts as `utm_source`. Anything else
	 * is reported as 'suremails'.
	 */
	private const ATTRIBUTION_SOURCES = [ 'suremails', 'sureforms' ];

	/**
	 * Transient prefix that carries `utm_source` from get_auth_url() to the
	 * exchange, keyed by the state minted for that flow.
	 */
	private const SOURCE_TRANSIENT_PREFIX = 'suremails_sc_source_';

	/**
	 * Connection data passed in from the factory.
	 *
	 * @var array<string, string|int|bool>
	 */
	private $connection_data;

	/**
	 * Constructor.
	 *
	 * @param array<string, string|int|bool> $connection_data Connection details.
	 */
	public function __construct( array $connection_data ) {
		$this->connection_data = $connection_data;
	}

	/**
	 * Get the SureContact API base URL.
	 *
	 * @return string
	 */
	public static function api_base() {
		return self::API_BASE;
	}

	/**
	 * Get the SureContact connect/authorize URL.
	 *
	 * @return string
	 */
	public static function connect_url() {
		return self::CONNECT_URL;
	}

	/**
	 * Get the SureContact billing/upgrade URL.
	 *
	 * @return string
	 */
	public static function billing_url() {
		return self::BILLING_URL;
	}

	/**
	 * Get the SureContact sending-domains management URL.
	 *
	 * @return string
	 */
	public static function sending_domains_url() {
		return self::SENDING_DOMAINS_URL;
	}

	/**
	 * Resolve the acquisition channel reported to SureContact, so accounts and
	 * connections started from another plugin (e.g. SureForms) are attributed
	 * to it.
	 *
	 * @param mixed $source Caller-supplied source.
	 * @return string An allowlisted source, 'suremails' by default.
	 */
	public static function resolve_source( $source ) {
		return is_string( $source ) && in_array( $source, self::ATTRIBUTION_SOURCES, true ) ? $source : 'suremails';
	}

	/**
	 * Authenticate the SureContact connection.
	 *
	 * @return array{success: bool, message: string, error_code?: int, api_key?: string, workspace_uuid?: string, connection_uuid?: string, email_verified?: bool, is_paid?: bool}
	 */
	public function authenticate() {
		$oauth_token = (string) ( $this->connection_data['oauth_token'] ?? '' );
		$oauth_state = (string) ( $this->connection_data['oauth_state'] ?? '' );

		if ( $oauth_token !== '' ) {
			return $this->exchange_oauth_token( $oauth_token, $oauth_state );
		}

		$api_key = (string) ( $this->connection_data['api_key'] ?? '' );
		if ( $api_key === '' ) {
			return [
				'success'    => false,
				'message'    => __( 'No SureContact API key found. Reconnect to SureContact to authorize this site.', 'suremails' ),
				'error_code' => 401,
			];
		}

		$response = $this->api_request( 'GET', '/suremails/status', [], $api_key );
		if ( ! $response['ok'] ) {
			return [
				'success'    => false,
				'message'    => $response['message'],
				'error_code' => $response['code'] !== 0 ? $response['code'] : 401,
			];
		}

		$verified = (bool) ( $response['data']['data']['account']['email_verified'] ?? false );

		return [
			'success'        => true,
			'message'        => __( 'Connected to SureContact.', 'suremails' ),
			'error_code'     => 200,
			'email_verified' => $verified,
		];
	}

	/**
	 * Send an email through SureContact.
	 *
	 * @param array<string, string|array<int, string>>                                                                                                                                                                                                                                                                                                                                                                                                                                                                             $atts            Email attributes.
	 * @param int|null                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             $log_id          Log row ID.
	 * @param array<string, string|int|bool>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       $connection      Connection data.
	 * @param array{to: array<int, array{name: string, email: string}>, headers: array{from: array{name: string, email: string}, cc: array<int, array{name: string, email: string}>, bcc: array<int, array{name: string, email: string}>, reply_to: array<int, array{name: string, email: string}>, content_type: string, charset: string, boundary: string, x_mailer: string, extra_headers: array<string, string>}, message: string, attachments: array<int, string>, subject: string, uploaded_attachments: array<int, string>} $processed_data  Processed data.
	 *
	 * @return array{success: bool, message: string, send?: bool, error_code?: int|string, email_id?: string}
	 */
	public function send( array $atts, $log_id, array $connection, array $processed_data ) {
		$result = [
			'success' => false,
			'message' => '',
			'send'    => false,
		];

		$api_key = (string) ( $connection['api_key'] ?? '' );
		if ( $api_key === '' ) {
			$result['message']    = __( 'SureContact API key is missing. Reconnect this site to SureContact.', 'suremails' );
			$result['error_code'] = 401;
			return $result;
		}

		$from_email   = sanitize_email( (string) ( $connection['from_email'] ?? '' ) );
		$from_name    = sanitize_text_field( (string) ( $connection['from_name'] ?? '' ) );
		$raw_subject  = $atts['subject'] ?? '';
		$subject_text = is_array( $raw_subject ) ? implode( ' ', $raw_subject ) : (string) $raw_subject;

		$payload = [
			'from_email' => $from_email,
			'subject'    => sanitize_text_field( $subject_text ),
		];

		if ( $from_name !== '' ) {
			$payload['from_name'] = $from_name;
		}

		$recipients = $this->process_recipients( $processed_data['to'] );
		if ( count( $recipients ) === 0 ) {
			$result['message']    = __( 'No recipients to send the email to.', 'suremails' );
			$result['error_code'] = 422;
			return $result;
		}

		$payload['to'] = $recipients[0];

		$cc = $this->process_recipients( $processed_data['headers']['cc'] );
		if ( count( $cc ) > 0 ) {
			$payload['cc'] = $cc;
		}

		$bcc = $this->process_recipients( $processed_data['headers']['bcc'] );
		if ( count( $bcc ) > 0 ) {
			$payload['bcc'] = $bcc;
		}

		$reply_to_list = $processed_data['headers']['reply_to'];
		if ( ! empty( $reply_to_list ) ) {
			$reply_to = reset( $reply_to_list );
			if ( is_array( $reply_to ) && ! empty( $reply_to['email'] ) ) {
				$payload['reply_to'] = sanitize_email( $reply_to['email'] );
				if ( ! empty( $reply_to['name'] ) ) {
					$payload['reply_to_name'] = sanitize_text_field( $reply_to['name'] );
				}
			}
		}

		$raw_message  = $atts['message'] ?? '';
		$html_body    = is_array( $raw_message ) ? implode( "\n", $raw_message ) : (string) $raw_message;
		$content_type = strtolower( $processed_data['headers']['content_type'] );

		if ( $content_type === 'text/html' ) {
			$payload['html'] = $html_body;
			$payload['text'] = wp_strip_all_tags( $html_body );
		} else {
			$payload['text'] = $html_body;
		}

		$extra_headers = $processed_data['headers']['extra_headers'];
		if ( is_array( $extra_headers ) && count( $extra_headers ) > 0 ) {
			$payload['headers'] = array_map( 'sanitize_text_field', $extra_headers );
		}

		if ( ! empty( $processed_data['attachments'] ) ) {
			$attachments = $this->prepare_attachments( $processed_data['attachments'] );
			if ( count( $attachments ) > 0 ) {
				$payload['attachments'] = $attachments;
			}
		}

		$payload['source_metadata'] = [
			'origin'   => 'wp_mail',
			'site_url' => home_url(),
		];

		if ( $log_id !== null ) {
			$payload['idempotency_key'] = sprintf( 'suremails:%s:%d', md5( home_url() ), $log_id );
		}

		$response = $this->api_request( 'POST', '/suremails/send', $payload, $api_key );

		if ( ! $response['ok'] ) {
			$result['message']    = $response['message'];
			$result['error_code'] = $response['code'] !== 0 ? $response['code'] : 0;
			return $result;
		}

		$result['success']  = true;
		$result['send']     = true;
		$result['message']  = __( 'Email sent successfully via SureContact.', 'suremails' );
		$result['email_id'] = (string) ( $response['data']['data']['uuid'] ?? '' );

		return $result;
	}

	/**
	 * Build the redirect URL for the SureContact OAuth-style connect flow.
	 *
	 * The plugin opens this URL in the same tab. After approval, the user is
	 * sent back to {oauth_url}?oauth_token=...&state=... — this matches the
	 * Gmail/Zoho callback contract reused by `auth-code-display.js`.
	 *
	 * The callback URL is server-built from `admin_url()` (no request-controlled
	 * input) so the `oauth_token` can never be delivered to a foreign host.
	 *
	 * An optional `utm_source` (see resolve_source()) is passed on in the
	 * redirect, and kept for the exchange keyed by this flow's state.
	 *
	 * @param array<string, mixed> $params Auth-url request params; only `utm_source` is read.
	 * @return array{auth_url?: string, state?: string, error?: string}
	 */
	public static function get_auth_url( $params ) {
		$source = self::resolve_source( $params['utm_source'] ?? '' );

		$state = self::mint_state();
		if ( $state === '' ) {
			return [
				'error' => __( 'You must be logged in to start authorization.', 'suremails' ),
			];
		}

		if ( 'suremails' !== $source ) {
			// Lives as long as the state itself (see validate_state()).
			set_transient( self::SOURCE_TRANSIENT_PREFIX . md5( $state ), $source, 10 * MINUTE_IN_SECONDS );
		}

		$callback_url = admin_url( 'admin.php?page=suremail' );

		$auth_url = self::connect_url() . '?' . http_build_query(
			[
				'oauth_url'        => $callback_url,
				'state'            => $state,
				'integration_name' => 'SureMails',
				'utm_source'       => $source,
			]
		);

		return [
			'auth_url' => $auth_url,
			'state'    => $state,
		];
	}

	/**
	 * Provider option configuration.
	 *
	 * @return array{title: string, description: string, fields: array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>, icon: string, display_name: string, provider_type: string, field_sequence: array<int, string>, provider_sequence: int}
	 */
	public static function get_options() {
		return [
			'title'             => __( 'SureContact SMTP', 'suremails' ),
			'description'       => __( 'Send emails through SureContact — no API keys, no DNS, no credit card.', 'suremails' ),
			'fields'            => self::get_specific_fields(),
			'icon'              => 'SureContactIcon',
			'display_name'      => __( 'SureContact SMTP', 'suremails' ),
			'provider_type'     => 'free',
			'field_sequence'    => [
				'connection_title',
				'from_email',
				'force_from_email',
				'from_name',
				'force_from_name',
				'priority',
			],
			'provider_sequence' => 0,
		];
	}

	/**
	 * Provider-specific fields exposed to the user-facing schema.
	 *
	 * The OAuth callback inputs and the long-lived `api_key` are listed here.
	 * `api_key` is not user-fillable (it's written by
	 * `SaveTestConnection::add_extra_fields` from the auth result) but it must
	 * be enrolled here so `Settings::encrypt_all` rotates it through the
	 * encrypt/decrypt pipeline alongside other providers' bearer secrets,
	 * keeping it out of `wp_options` in plain form.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_specific_fields() {
		return [
			'oauth_token' => [
				'datatype'   => 'string',
				'input_type' => 'password',
				'encrypt'    => true,
				'class_name' => 'hidden',
			],
			'oauth_state' => [
				'datatype'   => 'string',
				'input_type' => 'text',
				'class_name' => 'hidden',
			],
			'api_key'     => [
				'datatype'   => 'string',
				'input_type' => 'password',
				'encrypt'    => true,
				'class_name' => 'hidden',
			],
		];
	}

	/**
	 * Mint an HMAC-signed stateless OAuth state token, bound to the current WP
	 * user. Mirrors the SureContact plugin's class-auth-manager pattern — chosen
	 * over transients to be cache-immune. Replay protection is provided by the
	 * SaaS exchange endpoint enforcing single-use on `oauth_token`.
	 *
	 * Format: `surecontact_<base64url(user_id|timestamp|hmac_sha256)>`. The
	 * `surecontact_` prefix lets the React callback identify the provider.
	 *
	 * @return string Empty string when no admin is logged in.
	 */
	private static function mint_state() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}

		$timestamp = time();
		$payload   = $user_id . '|' . $timestamp;
		$signature = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of HMAC token, not obfuscation.
		$token = rtrim( strtr( base64_encode( $payload . '|' . $signature ), '+/', '-_' ), '=' );

		return 'surecontact_' . $token;
	}

	/**
	 * Verify an OAuth state token against the current admin. Rejects tokens that
	 * are malformed, were minted for a different user, are older than 10 minutes,
	 * or whose HMAC doesn't match.
	 *
	 * @param string $state State value from the OAuth callback.
	 * @return bool
	 */
	private function validate_state( $state ) {
		if ( ! is_string( $state ) || strpos( $state, 'surecontact_' ) !== 0 ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$token = substr( $state, strlen( 'surecontact_' ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding HMAC token, not obfuscation.
		$decoded = base64_decode( strtr( $token, '-_', '+/' ), true );
		if ( false === $decoded ) {
			return false;
		}

		$parts = explode( '|', $decoded );
		if ( 3 !== count( $parts ) ) {
			return false;
		}

		[ $tok_user, $tok_ts, $tok_sig ] = $parts;

		if ( (int) $tok_user !== $user_id ) {
			return false;
		}

		$elapsed = time() - (int) $tok_ts;
		if ( $elapsed < 0 || $elapsed > 600 ) { // 10-minute window.
			return false;
		}

		$expected = hash_hmac( 'sha256', $tok_user . '|' . $tok_ts, wp_salt( 'auth' ) );

		return hash_equals( $expected, $tok_sig );
	}

	/**
	 * Exchange an `oauth_token` (from the connect redirect) for a long-lived API key.
	 *
	 * @param string $oauth_token Token returned by SureContact.
	 * @param string $oauth_state The CSRF state value SureContact issued during initiate.
	 * @return array{success: bool, message: string, error_code?: int, api_key?: string, workspace_uuid?: string, connection_uuid?: string, email_verified?: bool, from_email?: string, from_name?: string, is_paid?: bool}
	 */
	private function exchange_oauth_token( $oauth_token, $oauth_state ) {
		// Verify the state was minted by this WP for the current admin within
		// the last 10 minutes. Stops attacker-supplied state/token pairs that
		// would otherwise bind this site to a foreign SureContact workspace.
		if ( ! $this->validate_state( $oauth_state ) ) {
			return [
				'success'    => false,
				'message'    => __( 'OAuth state is invalid or expired. Please reconnect.', 'suremails' ),
				'error_code' => 401,
			];
		}

		$source_key = self::SOURCE_TRANSIENT_PREFIX . md5( $oauth_state );
		$source     = self::resolve_source( get_transient( $source_key ) );
		delete_transient( $source_key );

		$body = [
			'oauth_token'    => $oauth_token,
			'site_url'       => esc_url_raw( rest_url( 'suremails/v1/' ) ),
			'state'          => $oauth_state,
			'site_name'      => get_bloginfo( 'name' ),
			'wp_version'     => get_bloginfo( 'version' ),
			'plugin_version' => defined( 'SUREMAILS_VERSION' ) ? SUREMAILS_VERSION : '',
			'utm_source'     => $source,
		];

		$response = $this->api_request( 'POST', '/connections/exchange', $body, '' );

		if ( ! $response['ok'] ) {
			return [
				'success'    => false,
				'message'    => $response['message'],
				'error_code' => $response['code'] !== 0 ? $response['code'] : 401,
			];
		}

		$data    = $response['data']['data'] ?? [];
		$api_key = (string) ( $data['api_key'] ?? '' );

		if ( $api_key === '' ) {
			return [
				'success'    => false,
				'message'    => __( 'SureContact returned an empty API key. Try connecting again.', 'suremails' ),
				'error_code' => 502,
			];
		}

		// `/connections/exchange` only carries the workspace identifiers. Pull
		// the SureContact account's own email, name, and verification flag from
		// `/suremails/status` so the saved connection reflects the connected
		// account — not whatever the WP form happened to submit (which is the
		// admin email by default in the OAuth path).
		$account = $this->fetch_account( $api_key );

		return [
			'success'         => true,
			'message'         => __( 'Connected to SureContact.', 'suremails' ),
			'error_code'      => 200,
			'api_key'         => $api_key,
			'workspace_uuid'  => (string) ( $data['workspace_uuid'] ?? '' ),
			'connection_uuid' => (string) ( $data['connection_id'] ?? ( $data['connection_uuid'] ?? '' ) ),
			'email_verified'  => array_key_exists( 'email_verified', $data )
				? (bool) $data['email_verified']
				: $account['email_verified'],
			'from_email'      => $account['email'],
			'from_name'       => $account['name'],
			'is_paid'         => $account['is_paid'],
		];
	}

	/**
	 * Fetch the connected SureContact account's identity (email, name,
	 * verification flag) and plan tier using a freshly-issued bearer. Returns
	 * empty strings and `false` when the lookup fails so the caller can fall
	 * back gracefully.
	 *
	 * @param string $api_key Bearer token issued by `/connections/exchange`.
	 * @return array{email: string, name: string, email_verified: bool, is_paid: bool}
	 */
	private function fetch_account( $api_key ) {
		$default = [
			'email'          => '',
			'name'           => '',
			'email_verified' => false,
			'is_paid'        => false,
		];

		$status = $this->api_request( 'GET', '/suremails/status', [], $api_key );
		if ( ! $status['ok'] ) {
			return $default;
		}

		$account = $status['data']['data']['account'] ?? [];
		if ( ! is_array( $account ) ) {
			return $default;
		}

		// Plan is account-level; capture is_paid now so the saved connection
		// reflects the tier immediately — without it, paid accounts can't add a
		// second sender until a later status sync persists the flag.
		$plan = $status['data']['data']['plan'] ?? [];

		return [
			'email'          => sanitize_email( (string) ( $account['email'] ?? '' ) ),
			'name'           => sanitize_text_field( (string) ( $account['name'] ?? '' ) ),
			'email_verified' => (bool) ( $account['email_verified'] ?? false ),
			'is_paid'        => is_array( $plan ) ? (bool) ( $plan['is_paid'] ?? false ) : false,
		];
	}

	/**
	 * Make a request against the SureContact API.
	 *
	 * @param string               $method  HTTP verb.
	 * @param string               $path    Path under the API base, leading slash.
	 * @param array<string, mixed> $body    Request body (encoded as JSON for non-GET).
	 * @param string               $api_key Bearer token; empty string for unauthenticated calls.
	 * @return array{ok: bool, code: int, message: string, data: array<string, mixed>}
	 */
	private function api_request( $method, $path, $body, $api_key ) {
		$url     = rtrim( self::api_base(), '/' ) . $path;
		$headers = [
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		];
		if ( $api_key !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$args = [
			'method'  => strtoupper( $method ),
			'headers' => $headers,
			'timeout' => 20,
		];

		if ( $method === 'GET' && ! empty( $body ) ) {
			$url = add_query_arg( $body, $url );
		} elseif ( ! empty( $body ) ) {
			$encoded = wp_json_encode( $body );
			if ( $encoded === false ) {
				return [
					'ok'      => false,
					'code'    => 0,
					'message' => __( 'Failed to encode SureContact request payload.', 'suremails' ),
					'data'    => [],
				];
			}
			$args['body'] = $encoded;
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return [
				'ok'      => false,
				'code'    => 0,
				'message' => $response->get_error_message(),
				'data'    => [],
			];
		}

		$code         = (int) wp_remote_retrieve_response_code( $response );
		$raw          = (string) wp_remote_retrieve_body( $response );
		$decoded      = json_decode( $raw, true );
		$decoded_body = is_array( $decoded ) ? $decoded : [];
		$ok           = $code >= 200 && $code < 300 && ( $decoded_body['success'] ?? true ) === true;

		return [
			'ok'      => $ok,
			'code'    => $code,
			'message' => $ok ? '' : $this->extract_error_message( $decoded_body, $code ),
			'data'    => $decoded_body,
		];
	}

	/**
	 * Pick a friendly error message out of a SureContact error envelope.
	 *
	 * @param array<string, mixed> $body API response.
	 * @param int                  $code HTTP status.
	 * @return string
	 */
	private function extract_error_message( array $body, $code ) {
		$message = (string) ( $body['message'] ?? '' );
		$reason  = (string) ( $body['reason'] ?? '' );

		if ( $reason === 'SEND_CAP_EXCEEDED' ) {
			return $message !== ''
				? $message
				: __( 'SureContact send cap exceeded. Verify your email or upgrade to keep sending.', 'suremails' );
		}

		if ( $reason === 'RECIPIENT_SUPPRESSED' ) {
			return $message !== ''
				? $message
				: __( 'Recipient is on the SureContact suppression list.', 'suremails' );
		}

		if ( $message !== '' ) {
			return $message;
		}

		if ( $code === 0 ) {
			return __( 'Unable to reach SureContact.', 'suremails' );
		}

		return sprintf(
			// translators: %d is the HTTP status code returned by SureContact.
			__( 'SureContact returned an unexpected response (HTTP %d).', 'suremails' ),
			$code
		);
	}

	/**
	 * Convert PHPMailer-style recipient arrays to the SureContact API shape.
	 *
	 * @param array<int, array{name?: string, email?: string}|string> $recipients Recipients to convert.
	 * @return array<int, array{email: string, name?: string}>
	 */
	private function process_recipients( array $recipients ) {
		$result = [];
		foreach ( $recipients as $recipient ) {
			$email = '';
			$name  = '';

			if ( is_array( $recipient ) ) {
				$email = isset( $recipient['email'] ) ? sanitize_email( $recipient['email'] ) : '';
				$name  = isset( $recipient['name'] ) ? sanitize_text_field( $recipient['name'] ) : '';
			} elseif ( is_string( $recipient ) ) {
				$email = sanitize_email( $recipient );
			}

			if ( $email === '' ) {
				continue;
			}

			$entry = [ 'email' => $email ];
			if ( $name !== '' ) {
				$entry['name'] = $name;
			}
			$result[] = $entry;
		}

		return $result;
	}

	/**
	 * Convert attachment paths into SureContact's base64 payload format.
	 *
	 * @param array<int, string> $attachments Paths.
	 * @return array<int, array{name: string, content: string, content_type: string}>
	 */
	private function prepare_attachments( array $attachments ) {
		$result = [];
		foreach ( $attachments as $attachment ) {
			$values = ProviderHelper::get_attachment( $attachment );
			if ( ! $values ) {
				continue;
			}

			$result[] = [
				'name'         => (string) ( $values['name'] ?? '' ),
				'content'      => (string) ( $values['blob'] ?? '' ),
				'content_type' => (string) ( $values['type'] ?? '' ),
			];
		}

		return $result;
	}
}
