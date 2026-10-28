<?php
/**
 * Helper
 *
 * @package surerank
 * @since 1.0.0
 */

namespace SureRank\Inc\GoogleSearchConsole;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Helper
 * This class will handle all helper functions.
 *
 * @since 1.0.0
 */
class Utils {

	/**
	 * Prefix marking a value encrypted with sodium_crypto_secretbox, so
	 * decrypt() can distinguish new encrypted values from legacy base64 ones.
	 *
	 * @since 1.9.2
	 */
	private const ENCRYPTION_PREFIX = 'srk:sb1:';

	/**
	 * Encrypt a string with libsodium authenticated encryption (secretbox).
	 *
	 * Falls back to legacy base64 only when libsodium is unavailable, which
	 * should not happen on supported WordPress/PHP versions.
	 *
	 * @param string $input The string to encrypt.
	 * @since 1.0.0
	 * @return string The encrypted, prefixed string (empty on invalid input).
	 */
	public static function encrypt( $input ) {
		if ( empty( $input ) || ! is_string( $input ) ) {
			return '';
		}

		// Idempotent: never double-encrypt an already-encrypted value.
		if ( 0 === strpos( $input, self::ENCRYPTION_PREFIX ) ) {
			return $input;
		}

		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return rtrim( base64_encode( $input ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $input, $nonce, self::encryption_key() );

		return self::ENCRYPTION_PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a string produced by encrypt().
	 *
	 * Values stored before encryption was added are plain base64 (no prefix);
	 * those are decoded the legacy way so existing connections keep working
	 * until the next save re-encrypts them.
	 *
	 * @param string $input The string to decrypt.
	 * @since 1.0.0
	 * @return string The decrypted string (empty on failure/invalid input).
	 */
	public static function decrypt( $input ) {
		if ( empty( $input ) || ! is_string( $input ) ) {
			return '';
		}

		// Prefixed values are encrypted and can only be read with libsodium; if
		// libsodium is unavailable, treat them as undecryptable rather than
		// falling through to the legacy path (which would return garbage).
		if ( 0 === strpos( $input, self::ENCRYPTION_PREFIX ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return '';
			}

			$decoded = base64_decode( substr( $input, strlen( self::ENCRYPTION_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( ! is_string( $decoded ) || strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
				return '';
			}

			$nonce     = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher    = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plaintext = sodium_crypto_secretbox_open( $cipher, $nonce, self::encryption_key() );

			return is_string( $plaintext ) ? $plaintext : '';
		}

		// Legacy base64-only value (pre-encryption); decode with correct padding.
		$padding = ( 4 - ( strlen( $input ) % 4 ) ) % 4;
		$base_64 = $input . str_repeat( '=', $padding );
		return (string) base64_decode( $base_64 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Coerce a URL into Search Console property format.
	 *
	 * Google identifies URL-prefix properties by their exact string, which
	 * always ends with a trailing slash (e.g. `https://www.example.com/`).
	 * A value like `window.location.origin` (`https://www.example.com`) is
	 * not a valid property identifier and every API call made with it fails
	 * with 403 PERMISSION_DENIED. Domain properties (`sc-domain:example.com`)
	 * are returned unchanged.
	 *
	 * @param string $url Property URL or `sc-domain:` value.
	 * @since 1.9.3
	 * @return string Property-formatted URL.
	 */
	public static function ensure_property_format( string $url ) {
		$url = trim( $url );
		if ( '' === $url || 0 === strpos( $url, 'sc-domain:' ) ) {
			return $url;
		}
		return rtrim( $url, '/' ) . '/';
	}

	/**
	 * Get SureRank SaaS Auth API URL
	 *
	 * @return string
	 */
	public static function get_saas_auth_api_url() {
		return defined( 'SURERANK_SAAS_AUTH_API_URL' ) ? SURERANK_SAAS_AUTH_API_URL : 'https://api.surerank.com/';
	}

	/**
	 * Derive the symmetric key for token encryption from the site's auth salt.
	 *
	 * @since 1.9.2
	 * @return string A 32-byte key for sodium_crypto_secretbox.
	 */
	private static function encryption_key() {
		return sodium_crypto_generichash( wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
