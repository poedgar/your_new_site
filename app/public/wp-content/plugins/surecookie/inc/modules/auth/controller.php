<?php
/**
 * Auth Controller
 *
 * Main controller class for handling authentication functionality.
 *
 * @package SureCookie\Inc\Modules\Auth
 * @since 0.0.1-beta.3
 */

namespace SureCookie\Inc\Modules\Auth;

use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;
use SureCookie\Inc\Traits\GetInstance;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Controller class
 *
 * Main controller class for authentication functionality.
 */
class Controller {
	use GetInstance;

	/**
	 * Module settings key.
	 *
	 * @since 0.0.1-beta.3
	 */
	public const SETTINGS_KEY = 'surecookie_auth';

	/**
	 * TTL for the per-flow encryption key transient (seconds).
	 *
	 * @since 0.0.1-beta.3
	 */
	public const FLOW_KEY_TTL = 300;

	/**
	 * Auth flow started from the admin app.
	 *
	 * @since 1.7.0
	 */
	public const ORIGIN_ADMIN = 'admin';

	/**
	 * Auth flow started from the setup wizard.
	 *
	 * @since 1.7.0
	 */
	public const ORIGIN_ONBOARDING = 'onboarding';

	/**
	 * Every screen an auth flow may return to.
	 *
	 * A closed enum on purpose: the value picked here is used to build a redirect.
	 *
	 * @since 1.7.0
	 * @var string[]
	 */
	public const ORIGINS = [ self::ORIGIN_ADMIN, self::ORIGIN_ONBOARDING ];

	/**
	 * Where to send the browser when an auth flow ends.
	 *
	 * The wizard keeps its hash route so a returning user lands on the scan step
	 * rather than back at the start of setup.
	 *
	 * @since 1.7.0
	 * @param string $origin        One of self::ORIGINS; anything else falls back to the admin app.
	 * @param string $status        Optional `auth` query value to append.
	 * @param bool   $with_fragment Whether to append the wizard's hash route. Off for the
	 *                              outbound `redirect-back`, which the billing portal owns
	 *                              and may append its own query to.
	 * @return string Admin URL to redirect to.
	 */
	public function get_return_url( string $origin, string $status = '', bool $with_fragment = true ): string {
		$is_onboarding = $origin === self::ORIGIN_ONBOARDING;
		$page          = $is_onboarding ? 'surecookie-onboarding' : 'surecookie';
		$url           = admin_url( 'admin.php?page=' . $page );

		if ( $status !== '' ) {
			$url = add_query_arg( 'auth', $status, $url );
		}

		// Appended after the query string, which is where a fragment has to sit.
		return $is_onboarding && $with_fragment ? $url . '#/scan' : $url;
	}

	/**
	 * Encryption key.
	 *
	 * @since 0.0.1-beta.3
	 * @var string
	 */
	public $key;

	/**
	 * Build the auth payload the frontend POSTs to the billing portal (issue #466).
	 *
	 * Returns the action URL + hidden field values for a form-based POST so the
	 * AES key and flow identifier never appear in any URL, browser history,
	 * Referer header, or access log.
	 *
	 * Each call mints a fresh 256-bit key and a fresh flow_id (UUID); the key
	 * is stored server-side under a transient keyed by flow_id (NOT user_id -
	 * eliminates multi-tab key reuse).
	 *
	 * @since 0.0.1-beta.3
	 *
	 * @param string $origin Screen that started the flow, one of self::ORIGINS.
	 * @return array{action_url: string, method: string, fields: array<string, string>}|WP_Error
	 */
	public function get_auth_payload( string $origin = self::ORIGIN_ADMIN ) {
		try {
			$this->key = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'csprng_unavailable', __( 'Could not generate secure auth key.', 'surecookie' ) );
		}

		$flow_id = wp_generate_uuid4();

		set_transient( 'scap_auth_key_' . $flow_id, $this->key, self::FLOW_KEY_TTL );

		if ( ! in_array( $origin, self::ORIGINS, true ) ) {
			$origin = self::ORIGIN_ADMIN;
		}

		// Recorded against the flow so the inbound callback can send a
		// popup-blocked user back to the screen they started on.
		set_transient( Api::ORIGIN_TRANSIENT_PREFIX . $flow_id, $origin, self::FLOW_KEY_TTL );

		$token_data = [
			'redirect-back' => $this->get_return_url( $origin, '', false ),
			'key'           => $this->key,
			'site-url'      => site_url(),
			'flow_id'       => $flow_id,
		];

		$encoded_token_data = wp_json_encode( $token_data );

		if ( empty( $encoded_token_data ) ) {
			return new WP_Error( 'failed_to_encode_token_data', __( 'Failed to encode the token data.', 'surecookie' ) );
		}

		return [
			'action_url' => SURECOOKIE_BILLING_PORTAL . 'auth/',
			'method'     => 'POST',
			'fields'     => [
				'token'   => base64_encode( $encoded_token_data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'flow_id' => $flow_id,
			],
		];
	}

	/**
	 * Get Auth status.
	 *
	 * Checks if user is authenticated via stored credentials or via Pro license.
	 *
	 * @since 0.0.1-beta.3
	 * @return bool
	 */
	public function get_auth_status() {
		$auth_status      = get_option( self::SETTINGS_KEY, false );
		$is_authenticated = ! empty( $auth_status );

		/**
		 * Filter to allow Pro plugin to override authentication status.
		 *
		 * @since 0.0.1-beta.3
		 * @param bool $is_authenticated Whether user is authenticated.
		 */
		return apply_filters( 'surecookie_is_authenticated', $is_authenticated );
	}

	/**
	 * Get authenticated user email.
	 *
	 * Retained for UI display ("Logged in as X"). The email is NOT
	 * transmitted to the SaaS scanner on any request after issue #469.
	 *
	 * @since 0.0.1-beta.3
	 * @return string|null
	 */
	public function get_auth_email() {
		$auth_data = get_option( self::SETTINGS_KEY, [] );
		return $auth_data['user_email'] ?? null;
	}

	/**
	 * Get the opaque billing-portal account reference (UUID v4) - issue #469.
	 *
	 * The only identifier sent to the SaaS alongside HMAC site auth.
	 * Returns null for corrupted/non-v4 stored values.
	 *
	 * @since 0.0.1-beta.3
	 */
	public function get_account_ref(): ?string {
		$auth_data = get_option( self::SETTINGS_KEY, [] );
		$ref       = $auth_data['account_ref'] ?? null;

		if ( ! is_string( $ref ) || $ref === '' ) {
			return null;
		}

		// RFC 4122 v4: 8-4-4-4-12 hex, version nibble = 4, variant in [89ab].
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $ref ) ) {
			return null;
		}

		return $ref;
	}

	/**
	 * Save Auth.
	 *
	 * Decrypts the access key using the per-flow transient key and random IV.
	 * Expected input format: base64( iv + ciphertext )
	 *
	 * Issue #466: keyed by flow_id (UUID), not user_id - eliminates multi-tab
	 * key reuse. The WP nonce was removed from the payload (cross-origin nonce
	 * is meaningless; replay protection survives via one-time transient + TTL).
	 *
	 * @since 0.0.1-beta.3
	 * @param string $data    Base64-encoded encrypted data.
	 * @param string $flow_id UUID from the inbound POST identifying which key transient to use.
	 * @param string $method  Encryption method. Default is AES-256-CBC.
	 * @return bool|WP_Error
	 */
	public function save_auth( $data, $flow_id, $method = 'AES-256-CBC' ) {

		if ( ! is_string( $flow_id ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $flow_id ) ) {
			return new WP_Error( 'invalid_flow_id', __( 'Invalid flow identifier.', 'surecookie' ) );
		}

		// Convert URL-safe base64 (RFC 4648 §5) back to standard base64.
		$data = strtr( (string) $data, '-_', '+/' );

		$decoded_data = base64_decode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( empty( $decoded_data ) ) {
			return new WP_Error( 'failed_to_decode', __( 'Failed to decode the access key.', 'surecookie' ) );
		}

		$iv_length = openssl_cipher_iv_length( $method );
		if ( $iv_length === false || strlen( $decoded_data ) <= $iv_length ) {
			return new WP_Error( 'invalid_data_format', __( 'Invalid data format.', 'surecookie' ) );
		}

		$iv        = substr( $decoded_data, 0, $iv_length );
		$encrypted = substr( $decoded_data, $iv_length );

		$decrypted = $this->attempt_transient_decrypt( $encrypted, $iv, $method, $flow_id );

		// One-time use: consume the transient regardless of decrypt outcome.
		// Even a failed attempt (wrong ciphertext / wrong key) invalidates the
		// transient so a captured payload can't be replayed against a fresh attempt.
		delete_transient( 'scap_auth_key_' . $flow_id );

		if ( empty( $decrypted ) ) {
			return new WP_Error( 'failed_to_decrypt', __( 'Failed to decrypt the access key.', 'surecookie' ) );
		}

		$decrypted_data_array = json_decode( $decrypted, true );

		if ( ! is_array( $decrypted_data_array ) || empty( $decrypted_data_array ) ) {
			return new WP_Error( 'failed_to_json_decode', __( 'Failed to json decode the decrypted data.', 'surecookie' ) );
		}

		if ( empty( $decrypted_data_array['user_email'] ) ) {
			return new WP_Error( 'no_user_email', __( 'No user email found in the decrypted data.', 'surecookie' ) );
		}

		if ( isset( $decrypted_data_array['is_subscribed'] ) ) {
			$is_subscribed = is_string( $decrypted_data_array['is_subscribed'] )
				? $decrypted_data_array['is_subscribed'] === 'true'
				: (bool) $decrypted_data_array['is_subscribed'];

			update_option( 'surecookie_usage_optin', $is_subscribed ? 'yes' : 'no' );
			unset( $decrypted_data_array['is_subscribed'] );
		}

		// Strip any stray nonce field the billing portal might still echo for
		// pre-#466 clients - not validated, just not persisted.
		unset( $decrypted_data_array['nonce'] );

		// Non-autoloaded - the auth payload is only read by admin/REST paths, never
		// on the front-end banner render, so it stays out of the alloptions cache.
		Update::option( self::SETTINGS_KEY, $decrypted_data_array );

		// Push account_ref to SaaS so it can populate Site::tier without seeing the
		// user's email (issue #469). Result is ignored: the callback must redirect
		// either way, and the ref is CRM-only, so a miss costs no entitlement.
		$account_ref = $decrypted_data_array['account_ref'] ?? null;
		if ( is_string( $account_ref ) && $account_ref !== '' ) {
			SaasClient::get_instance()->link_billing_account( $account_ref );
		}

		return true;
	}

	/**
	 * Clear Auth data.
	 *
	 * @since 0.0.1-beta.3
	 * @return bool
	 */
	public function clear_auth() {
		return delete_option( self::SETTINGS_KEY );
	}

	/**
	 * Attempt decryption using the key stored in the per-flow transient.
	 *
	 * The transient holds the hex-encoded key (transport-safe in JSON).
	 * We hex2bin() it here so OpenSSL receives the full 32 raw bytes -
	 * passing the 64-char hex string directly would silently truncate
	 * inside openssl and reduce the AES-256 key to 128 effective bits.
	 *
	 * @since 0.0.1-beta.3
	 * @param string $encrypted Raw encrypted data (without IV).
	 * @param string $iv        The initialization vector.
	 * @param string $method    Encryption method.
	 * @param string $flow_id   UUID identifying which transient to read.
	 * @return string|false Decrypted string or false on failure.
	 */
	private function attempt_transient_decrypt( string $encrypted, string $iv, string $method, string $flow_id ) {
		$transient_key = get_transient( 'scap_auth_key_' . $flow_id );

		if ( ! is_string( $transient_key ) || strlen( $transient_key ) !== 64 || ! ctype_xdigit( $transient_key ) ) {
			return false;
		}

		$key_bytes = hex2bin( $transient_key );
		if ( ! is_string( $key_bytes ) || strlen( $key_bytes ) !== 32 ) {
			return false;
		}

		return openssl_decrypt( $encrypted, $method, $key_bytes, OPENSSL_RAW_DATA, $iv );
	}
}
