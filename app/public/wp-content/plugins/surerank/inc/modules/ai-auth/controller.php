<?php
/**
 * Index_Now Module
 *
 * Main module class for handling instant indexing functionality.
 *
 * @package SureRank\Inc\Modules\Ai_Auth
 * @since 1.4.2
 */

namespace SureRank\Inc\Modules\Ai_Auth;

use SureRank\Inc\Functions\Get;
use SureRank\Inc\Functions\Update;
use SureRank\Inc\Traits\Get_Instance;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Controller class
 *
 * Main module class for instant indexing functionality.
 */
class Controller {

	use Get_Instance;

	/**
	 * Module settings key.
	 *
	 * @since 1.4.2
	 */
	public const SETTINGS_KEY = 'surerank_auth';

	/**
	 * Encryption key.
	 *
	 * @since 1.4.2
	 * @var string
	 */
	public $key;

	/**
	 * Get Auth URL.
	 *
	 * @since 1.4.2
	 * @return string|WP_Error
	 */
	public function get_auth_url() {
		// Generate a random key of 16 characters.
		$this->key = wp_generate_password( 16, false );

		// Prepare the token data.
		$token_data = [
			'redirect-back' => admin_url( 'admin.php?page=surerank' ),
			'key'           => $this->key,
			'site-url'      => site_url(),
			'nonce'         => wp_create_nonce( 'surerank_ai_auth_nonce' ),
		];

		$encoded_token_data = wp_json_encode( $token_data );

		if ( empty( $encoded_token_data ) ) {
			return new WP_Error( 'failed_to_encode_token_data', __( 'Failed to encode the token data.', 'surerank' ) );
		}

		return SURERANK_BILLING_PORTAL . 'auth/?token=' . base64_encode( $encoded_token_data );
	}

	/**
	 * Get Auth status.
	 *
	 * @since 1.4.2
	 * @return bool
	 */
	public function get_auth_status() {
		return '' !== $this->get_auth_source();
	}

	/**
	 * Whether a SureRank AI account is connected locally (freemium auth).
	 *
	 * This is intentionally NOT license-aware: it reflects only the local
	 * account so the disconnect flow can confirm the account was removed.
	 *
	 * @since 1.10.1
	 * @return bool
	 */
	public function has_connected_account() {
		return ! empty( get_option( self::SETTINGS_KEY, false ) );
	}

	/**
	 * Resolve how the user is authenticated with SureRank AI.
	 *
	 * @since 1.10.1
	 * @return string 'license' (active Pro license), 'account' (connected
	 *                account), or '' when not connected.
	 */
	public function get_auth_source() {
		// Check the Pro license authoritatively via its own class rather than a
		// spoofable public filter, so arbitrary code cannot flip the state.
		// The license wins over a connected account: disconnecting the account
		// would not disconnect AI for a licensed user, so the UI offers
		// "Manage License" instead of a misleading "Disconnect".
		$licensing = '\SureRankPro\Inc\Licensing\Licensing';
		if ( is_callable( [ $licensing, 'is_license_active' ] ) && $licensing::is_license_active() ) {
			return 'license';
		}

		if ( $this->has_connected_account() ) {
			return 'account';
		}

		return '';
	}

	/**
	 * Save Auth.
	 *
	 * @since 1.4.2
	 * @param string $data Data to save.
	 * @param string $key Key to use for encryption.
	 * @param string $method Encryption method. Default is AES-256-CBC.
	 * @return bool|WP_Error
	 */
	public function save_auth( $data, $key, $method = 'AES-256-CBC' ) {

		// Decode the data and split IV and encrypted data.
		$decoded_data = base64_decode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		// if the data is not base64 encoded then return false.
		if ( empty( $decoded_data ) ) {
			return new WP_Error( 'failed_to_decode', __( 'Failed to decode the access key.', 'surerank' ) );
		}

		// split the key and encrypted data.
		[$key, $encrypted] = explode( '::', $decoded_data, 2 );

		// Decrypt the data using the key.
		$decrypted = openssl_decrypt( $encrypted, $method, $key, 0, $key );

		// if the decryption returns false then send error.
		if ( empty( $decrypted ) ) {
			return new WP_Error( 'failed_to_decrypt', __( 'Failed to decrypt the access key.', 'surerank' ) );
		}

		// json decode the decrypted data.
		$decrypted_data_array = json_decode( $decrypted, true );

		if ( ! is_array( $decrypted_data_array ) || empty( $decrypted_data_array ) ) {
			return new WP_Error( 'failed_to_json_decode', __( 'Failed to json decode the decrypted data.', 'surerank' ) );
		}

		// verify the nonce that comes in $encrypted_email_array.
		if ( ! empty( $decrypted_data_array['nonce'] ) && ! wp_verify_nonce( $decrypted_data_array['nonce'], 'surerank_ai_auth_nonce' ) ) {
			return new WP_Error( 'nonce_verification_failed', __( 'Nonce verification failed.', 'surerank' ) );
		}

		// check if the user email is present in the decrypted data.
		if ( empty( $decrypted_data_array['user_email'] ) ) {
			return new WP_Error( 'no_user_email', __( 'No user email found in the decrypted data.', 'surerank' ) );
		}

		// Extract is_subscribed value if present.
		$is_subscribed = false;
		if ( isset( $decrypted_data_array['is_subscribed'] ) ) {
			// Convert string 'true'/'false' to boolean if needed.
			if ( is_string( $decrypted_data_array['is_subscribed'] ) ) {
				$is_subscribed = 'true' === $decrypted_data_array['is_subscribed'];
			} else {
				$is_subscribed = (bool) $decrypted_data_array['is_subscribed'];
			}

			// Record the usage-tracking consent captured at signup.
			$this->maybe_set_usage_optin( $is_subscribed );

			// Remove is_subscribed from the decrypted data.
			unset( $decrypted_data_array['is_subscribed'] );
		}

		// remove the nonce from the decrypted data before saving it to the options.
		unset( $decrypted_data_array['nonce'] );

		// save the user email to the options.
		update_option( self::SETTINGS_KEY, $decrypted_data_array );

		return true;
	}

	/**
	 * Delete stored auth data (disconnect SureRank AI).
	 *
	 * @since 1.10.1
	 * @return bool True on success, false otherwise.
	 */
	public function delete_auth() {
		return delete_option( self::SETTINGS_KEY );
	}

	/**
	 * Get stored account details.
	 *
	 * Only a minimal, explicitly whitelisted subset is returned. The stored
	 * option holds the full decrypted auth payload, so exposing it wholesale
	 * (via localization/REST) could leak fields the frontend never needs.
	 *
	 * @since 1.10.1
	 * @return array<string, mixed> Whitelisted account data (user_email, plan) or empty array.
	 */
	public function get_account() {
		$account = get_option( self::SETTINGS_KEY, [] );

		if ( ! is_array( $account ) ) {
			return [];
		}

		return array_intersect_key( $account, array_flip( [ 'user_email', 'plan' ] ) );
	}

	/**
	 * Persist the usage-tracking consent captured at AI/account signup.
	 *
	 * The `is_subscribed` flag from the server reflects a real consent choice the
	 * user made at signup, so it is only honored to FILL an empty state — it must
	 * never silently override an explicit choice the user already made through
	 * onboarding, the settings toggle, or the BSF admin notice. An existing
	 * 'yes'/'no' is therefore left untouched; a legacy '' (written by older
	 * builds) and an absent option both count as "no choice yet" and are writable.
	 *
	 * Opt-out is always stored as 'no', never '' (keeps every writer consistent —
	 * see issue #2314). Site-local option semantics are intentional here; aligning
	 * all consent writers with the library's network-level `get_site_option`
	 * reader on multisite is tracked separately (issue #2316, M1).
	 *
	 * @param bool $is_subscribed Whether the user opted into usage sharing at signup.
	 * @since 1.10.1
	 * @return void
	 */
	protected function maybe_set_usage_optin( $is_subscribed ) {
		$existing = Get::option( 'surerank_usage_optin', false );

		// Respect an explicit choice the user has already made.
		if ( 'yes' === $existing || 'no' === $existing ) {
			return;
		}

		Update::option( 'surerank_usage_optin', $is_subscribed ? 'yes' : 'no' );
	}
}
