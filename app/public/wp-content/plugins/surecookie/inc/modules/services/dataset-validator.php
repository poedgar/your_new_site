<?php
/**
 * Dataset Validator.
 *
 * Coercive validators for the datasets the plugin consumes. The live remote
 * dataset is the unified services.json (validate_services); the blocking-scripts
 * / service-cookies validators are retained for validating the bundled legacy
 * floors. Every validator follows a drop-bad-keep-good policy: a single
 * malformed entry is dropped, never the whole payload, so a partially-corrupt
 * remote response can still contribute its valid rows over the bundled floor.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.2.5
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Traits\IpManager;
use SureCookie\Inc\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Dataset_Validator
 *
 * Static, coercive validation for remote-served datasets.
 *
 * @since 1.2.5
 */
class Dataset_Validator {
	// Reuse the shared local/dev host detection (is_local_url) instead of
	// duplicating it - see is_allowed_url().
	use IpManager;

	/**
	 * Maximum number of services accepted from a service-cookies payload.
	 *
	 * A cap belongs here, but it is a guard against a malformed payload, not a
	 * product limit: the catalog is expected to keep growing and was already at
	 * 159. Passing it truncated the payload silently, in whatever order the JSON
	 * happened to be in, so services would simply stop being blocked with no
	 * signal anywhere.
	 *
	 * @since 1.2.5
	 */
	private const MAX_SERVICES = 1000;

	/**
	 * Maximum number of cookies accepted per service.
	 *
	 * @since 1.2.5
	 */
	private const MAX_COOKIES_PER_SERVICE = 50;

	/**
	 * Maximum number of transfer countries accepted per service.
	 *
	 * @since 1.7.0
	 */
	private const MAX_TRANSFER_COUNTRIES = 10;

	/**
	 * Validate a unified services dataset (service slug => {label, category,
	 * gcm_compatible?, cookieless?, patterns keyed by Pattern_Kinds bucket,
	 * cookies:[...]}).
	 *
	 * Coercive (drop-bad-keep-good): the `_meta` key is dropped, non-slug keys are
	 * skipped, the service-level `category` is coerced to a valid consent key
	 * (falling back to `uncategorized`), patterns are reduced to non-empty strings,
	 * each cookie is normalised via validate_cookie() so its OWN category is
	 * preserved (never inherited from the service), and a service that ends up with
	 * neither patterns nor cookies is dropped. Counts are capped.
	 *
	 * @param array<string, mixed> $raw Raw decoded payload.
	 * @since 1.3.0
	 * @return array<string, array<string, mixed>> Validated unified catalog.
	 */
	public static function validate_services( array $raw ): array {
		unset( $raw['_meta'] );

		$valid_categories = Get::default_cookie_categories_keys();
		$out              = [];
		$service_count    = 0;

		foreach ( $raw as $slug => $service ) {
			if ( $service_count >= self::MAX_SERVICES ) {
				self::log_truncation( count( $raw ) );
				break;
			}

			if ( ! is_string( $slug ) || preg_match( '/^[a-z0-9-]+$/', $slug ) !== 1 ) {
				continue;
			}

			if ( ! is_array( $service ) ) {
				continue;
			}

			$patterns    = [];
			$has_pattern = false;

			foreach ( Pattern_Kinds::buckets() as $bucket ) {
				$patterns[ $bucket ] = self::clean_pattern_list( $service['patterns'][ $bucket ] ?? [] );
				$has_pattern         = $has_pattern || $patterns[ $bucket ] !== [];
			}

			$clean_cookies = [];
			$cookie_count  = 0;
			foreach ( (array) ( $service['cookies'] ?? [] ) as $cookie ) {
				if ( $cookie_count >= self::MAX_COOKIES_PER_SERVICE ) {
					break;
				}
				$clean = self::validate_cookie( $cookie, $valid_categories );
				if ( $clean === null ) {
					continue;
				}
				$clean_cookies[] = $clean;
				++$cookie_count;
			}

			// A service with neither patterns nor cookies contributes nothing.
			if ( ! $has_pattern && empty( $clean_cookies ) ) {
				continue;
			}

			$category = isset( $service['category'] ) ? (string) $service['category'] : 'uncategorized';
			if ( ! in_array( $category, $valid_categories, true ) ) {
				$category = 'uncategorized';
			}

			$entry = [
				'label'          => isset( $service['label'] ) ? Sanitize::text( $service['label'] ) : $slug,
				'description'    => isset( $service['description'] ) ? Sanitize::text( $service['description'] ) : '',
				'category'       => $category,
				// Known Services tiering flag (drives the plugin's Add gate only;
				// blocking ignores it). Default false so an unknown/legacy payload
				// treats a service as free rather than surprise-locking it.
				'pro'            => ! empty( $service['pro'] ),
				'gcm_compatible' => ! empty( $service['gcm_compatible'] ),
				'patterns'       => $patterns,
				'cookies'        => $clean_cookies,
			];

			// Presence-sensitive, unlike every other key here: an absent `cookieless`
			// means "not asserted", so the merge can keep the bundled claim.
			if ( array_key_exists( 'cookieless', $service ) ) {
				$entry['cookieless'] = ! empty( $service['cookieless'] );
			}

			// Presence-sensitive for the same reason, and for a sharper one: an
			// always-written key would make every remote row that has never heard
			// of it assert "no transfer", publishing a false compliance statement
			// on the banner. Codes only - the EEA test and the visitor-facing
			// wording stay ours, so a catalog edit cannot author either.
			if ( array_key_exists( 'transfer_countries', $service ) ) {
				$entry['transfer_countries'] = self::clean_country_list( $service['transfer_countries'] );
			}

			$out[ $slug ] = $entry;
			++$service_count;
		}

		return $out;
	}

	/**
	 * Whether a URL may be fetched: HTTPS is always allowed; HTTP only for a
	 * genuine local/dev host, so a compromised or misconfigured endpoint cannot
	 * downgrade a production fetch to plain HTTP.
	 *
	 * The local-host test is is_local_host() below - deliberately NOT
	 * IpManager::is_local_url(), which short-circuits to false whenever
	 * SURECOOKIE_ALLOW_LOCAL_SCAN (or the surecookie_bypass_local_url_check
	 * filter) is set. Those flags govern whether to SCAN a local site, not the
	 * dataset transport; reusing is_local_url() here meant enabling local
	 * scanning wrongly rejected an http:// local agent URL and silently pinned
	 * the plugin to the bundled floor.
	 *
	 * @param string $url URL to test.
	 * @since 1.2.5
	 * @return bool
	 */
	public static function is_allowed_url( string $url ): bool {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$scheme = is_string( $scheme ) ? strtolower( $scheme ) : '';

		if ( $scheme === 'https' ) {
			return true;
		}

		return $scheme === 'http' && self::is_local_host( $url );
	}

	/**
	 * Whether a host is a genuine local/dev host: a loopback or private IP,
	 * "localhost", or a *.localhost / *.local suffix. Unlike
	 * IpManager::is_local_url(), this does NOT consult SURECOOKIE_ALLOW_LOCAL_SCAN
	 * or the surecookie_bypass_local_url_check filter (see is_allowed_url()).
	 *
	 * @param string $url URL to test.
	 * @since 1.3.0
	 * @return bool
	 */
	private static function is_local_host( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) || ! is_string( $host ) ) {
			return false;
		}

		// Normalize: lowercase and strip IPv6 brackets so "[::1]" matches "::1".
		$host = strtolower( trim( $host, '[]' ) );

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return inet_pton( $host ) === inet_pton( '::1' )
				|| strpos( $host, '127.' ) === 0
				|| self::is_private_ip( $host );
		}

		if ( $host === 'localhost' ) {
			return true;
		}

		foreach ( [ '.localhost', '.local' ] as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate and normalise a single cookie row.
	 *
	 * @param mixed              $cookie           Raw cookie row.
	 * @param array<int, string> $valid_categories Allowed category ids.
	 * @since 1.2.5
	 * @return array<string, mixed>|null Normalised cookie, or null when dropped.
	 */
	private static function validate_cookie( $cookie, array $valid_categories ): ?array {
		if ( ! is_array( $cookie ) ) {
			return null;
		}

		$name = isset( $cookie['name'] ) && is_string( $cookie['name'] ) ? trim( $cookie['name'] ) : '';
		if ( $name === '' ) {
			return null;
		}

		$category = isset( $cookie['category'] ) ? (string) $cookie['category'] : 'uncategorized';
		if ( ! in_array( $category, $valid_categories, true ) ) {
			$category = 'uncategorized';
		}

		return [
			'name'          => $name,
			'domain'        => Sanitize::cookie_domain( $cookie['domain'] ?? '' ),
			'duration_days' => absint( $cookie['duration_days'] ?? 0 ),
			'category'      => $category,
			'provider'      => Sanitize::text( $cookie['provider'] ?? '' ),
			'purpose'       => sanitize_textarea_field( (string) ( $cookie['purpose'] ?? '' ) ),
			'description'   => sanitize_textarea_field( (string) ( $cookie['description'] ?? '' ) ),
		];
	}

	/**
	 * Say so when a payload is truncated, instead of dropping services silently.
	 *
	 * Silent truncation looks exactly like a service that was never in the
	 * catalog: it stops being blocked and nothing anywhere says why.
	 *
	 * @since 1.5.0
	 * @param int $received How many services the payload carried.
	 * @return void
	 */
	private static function log_truncation( int $received ): void {
		$message = sprintf(
			'SureCookie: services dataset truncated at %d of %d entries. Blocking patterns beyond the cap were dropped.',
			self::MAX_SERVICES,
			$received
		);

		// save_log() too: log() only writes in development mode, and dropping
		// blocking patterns is precisely what a production site must be told.
		$logger = Logger::get_instance();
		$logger->log( $message, 'warning' );
		$logger->save_log( $message );
	}

	/**
	 * Reduce a raw transfer-country list to unique ISO 3166-1 alpha-2 codes.
	 *
	 * @param mixed $list Raw country list.
	 * @since 1.7.0
	 * @return array<int, string>
	 */
	private static function clean_country_list( $list ): array {
		if ( ! is_array( $list ) ) {
			return [];
		}

		$out = [];
		foreach ( $list as $item ) {
			$code = is_string( $item ) ? strtoupper( trim( $item ) ) : '';
			// The table has no UK row (ISO reserves it for GB), so dropping it would hide a UK transfer.
			$code = $code === 'UK' ? 'GB' : $code;
			if ( preg_match( '/^[A-Z]{2}$/', $code ) === 1 ) {
				$out[ $code ] = true;
			}
		}

		// Shape check only. A code with no entry in the shipped country table is
		// dropped at render, so an unknown one can never print as a bare code.
		return array_slice( array_keys( $out ), 0, self::MAX_TRANSFER_COUNTRIES );
	}

	/**
	 * Reduce a raw pattern list to trimmed, non-empty strings.
	 *
	 * @param mixed $list Raw pattern list.
	 * @since 1.2.5
	 * @return array<int, string>
	 */
	private static function clean_pattern_list( $list ): array {
		if ( ! is_array( $list ) ) {
			return [];
		}

		$out = [];
		foreach ( $list as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}

			$item = trim( $item );
			if ( $item !== '' ) {
				$out[] = $item;
			}
		}

		return $out;
	}
}
