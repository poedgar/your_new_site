<?php
/**
 * First-party loopback resource detector.
 *
 * The remote SaaS scanner hits the site from external IPs, which some hosts
 * (e.g. SiteGround) block at the firewall. This detector fetches the site's own
 * pages from the server itself (a first-party / loopback request the host's
 * anti-bot does not challenge) and matches the scripts/iframes it finds against
 * the known-services dataset. When a SaaS scan is blocked, this still tells the
 * user which known third-party services are present so they can declare them.
 *
 * It intentionally does NOT execute JavaScript, so it complements (not replaces)
 * the SaaS crawl: it reliably finds statically-embedded services on any host.
 *
 * @package SureCookie\Inc\Modules\SiteScanner
 * @since   1.3.0
 */

namespace SureCookie\Inc\Modules\SiteScanner;

use SureCookie\Inc\Modules\Services\Services_Source;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * LoopbackScanner
 *
 * @since 1.3.0
 */
class LoopbackScanner {
	use GetInstance;

	/**
	 * Maximum pages fetched in a single loopback pass.
	 *
	 * @since 1.3.0
	 */
	private const MAX_PAGES = 10;

	/**
	 * Extract external script/iframe source URLs from an HTML document. Pure -
	 * no HTTP, no WordPress. Protocol-relative URLs are normalized to https.
	 *
	 * @param string $html - HTML document.
	 * @since 1.3.0
	 * @return array<int, string> Unique resource URLs.
	 */
	public static function extract_resources( string $html ): array {
		$srcs = [];

		if ( preg_match_all( '/<(?:script|iframe)\b[^>]*?\ssrc=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			$srcs = $matches[1];
		}

		$srcs = array_map(
			static function ( string $src ): string {
				$src = trim( $src );
				return strpos( $src, '//' ) === 0 ? 'https:' . $src : $src;
			},
			$srcs
		);

		return array_values( array_unique( array_filter( $srcs ) ) );
	}

	/**
	 * Match resource URLs against the known-services map. Pure. A service
	 * matches when any of its script patterns appears in any resource URL.
	 *
	 * @param array<int, string>                  $srcs     Resource URLs.
	 * @param array<string, array<string, mixed>> $services Known-services catalog
	 *        (slug => [label, patterns => [scripts, iframes], ...]).
	 * @since 1.3.0
	 * @return array<string, string> Detected services as id => label.
	 */
	public static function match_services( array $srcs, array $services ): array {
		$detected = [];

		foreach ( $services as $id => $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}

			// The dataset stores patterns split by kind under `patterns`. The
			// loopback detector matches both statically-embedded <script> and
			// <iframe> sources, so pool the script + iframe patterns together.
			$patterns = [];
			if ( ! empty( $meta['patterns'] ) && is_array( $meta['patterns'] ) ) {
				foreach ( [ 'scripts', 'iframes' ] as $kind ) {
					if ( ! empty( $meta['patterns'][ $kind ] ) && is_array( $meta['patterns'][ $kind ] ) ) {
						$patterns = array_merge( $patterns, $meta['patterns'][ $kind ] );
					}
				}
			}

			foreach ( $patterns as $pattern ) {
				$pattern = (string) $pattern;
				if ( $pattern === '' ) {
					continue;
				}

				foreach ( $srcs as $src ) {
					if ( stripos( $src, $pattern ) !== false ) {
						$detected[ (string) $id ] = ! empty( $meta['label'] )
							? (string) $meta['label']
							: (string) $id;
						continue 3; // Service matched; move to the next service.
					}
				}
			}
		}

		return $detected;
	}

	/**
	 * Fetch the given pages first-party (server loopback) and return the known
	 * services detected across them. Never throws; a failed page is skipped.
	 *
	 * @param array<int, string> $urls - Page URLs to fetch.
	 * @since 1.3.0
	 * @return array<string, string> Detected services as id => label.
	 */
	public function detect_services( array $urls ): array {
		$services = Services_Source::get_instance()->get_catalog();
		$detected = [];
		$fetched  = 0;

		foreach ( $urls as $url ) {
			if ( $fetched >= self::MAX_PAGES ) {
				break;
			}
			$fetched++;

			$response = wp_remote_get(
				$url,
				[
					'timeout'     => 15,
					'sslverify'   => false,
					'redirection' => 3,
					'user-agent'  => 'SureCookie-Loopback',
				]
			);

			if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
				continue;
			}

			$html     = (string) wp_remote_retrieve_body( $response );
			$detected = array_merge( $detected, self::match_services( self::extract_resources( $html ), $services ) );
		}

		return $detected;
	}
}
