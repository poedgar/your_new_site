<?php
/**
 * Records what an assisted scan did, for the analytics state events.
 *
 * Follows the established split: this module writes option flags as things happen, and
 * Analytics::detect_state_events() reads them on the next admin load and emits the events,
 * so nothing here touches the analytics library and a walk finishing in a REST request or
 * cron tick costs one option write.
 *
 * Worth measuring because how often an assisted scan is needed is our only measure of how
 * often the cloud scanner is blocked, the number that should decide whether we fix the root
 * cause (egress IPs, host allowlisting, provider conversations) or keep this workaround.
 *
 * @package SureCookie\Inc\Modules\AssistedScan
 * @since   1.3.0
 */

namespace SureCookie\Inc\Modules\AssistedScan;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Telemetry
 *
 * @since 1.3.0
 */
class Telemetry {
	/**
	 * Set once the site has ever started an assisted walk.
	 *
	 * @since 1.3.0
	 */
	public const STARTED_FLAG = 'surecookie_assisted_scan_started_flag';

	/**
	 * Set once the site has ever completed an assisted walk.
	 *
	 * @since 1.3.0
	 */
	public const COMPLETED_FLAG = 'surecookie_assisted_scan_completed_flag';

	/**
	 * Set once a walk has ever been finished by the abandoned-session rescue rather
	 * than by the browser reporting in.
	 *
	 * @since 1.3.0
	 */
	public const ABANDONED_FLAG = 'surecookie_assisted_scan_abandoned_flag';

	/**
	 * Shape of the most recent completed walk, for the event's properties.
	 *
	 * @since 1.3.0
	 */
	public const STATS_OPTION = 'surecookie_assisted_scan_stats';

	/**
	 * Every option this class writes, so uninstall can clean up in one place.
	 *
	 * @since 1.3.0
	 * @return array<int, string>
	 */
	public static function options(): array {
		return [
			self::STARTED_FLAG,
			self::COMPLETED_FLAG,
			self::ABANDONED_FLAG,
			self::STATS_OPTION,
		];
	}

	/**
	 * Record that a walk was started.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public static function record_started(): void {
		if ( ! get_option( self::STARTED_FLAG, false ) ) {
			update_option( self::STARTED_FLAG, true, false );
		}
	}

	/**
	 * Record how a walk finished.
	 *
	 * Stats are overwritten each time, not accumulated: the event is one-shot, so it only
	 * needs a representative shape, not a history.
	 *
	 * @param array<string, mixed> $summary   Finalize summary.
	 * @param int                  $pages     Pages the walk actually attempted.
	 * @param bool                 $abandoned Whether the rescue finished it rather than the browser.
	 * @since 1.3.0
	 * @return void
	 */
	public static function record_completed( array $summary, int $pages, bool $abandoned = false ): void {
		if ( ! get_option( self::COMPLETED_FLAG, false ) ) {
			update_option( self::COMPLETED_FLAG, true, false );
		}

		if ( $abandoned && ! get_option( self::ABANDONED_FLAG, false ) ) {
			update_option( self::ABANDONED_FLAG, true, false );
		}

		update_option(
			self::STATS_OPTION,
			[
				'pages'             => $pages,
				'cookies'           => (int) ( $summary['cookies_count'] ?? 0 ),
				'services'          => (int) ( $summary['services_count'] ?? 0 ),
				'adblock_suspected' => ! empty( $summary['adblock_suspected'] ),
				// Whether the site has SaaS credentials: the segment that matters, since an
				// assisted scan on an unregistered site means it could never register, a
				// harder failure than a registered site whose scan was merely blocked.
				'registered'        => self::is_registered(),
				'abandoned'         => $abandoned,
			],
			false
		);
	}

	/**
	 * Whether this site holds SaaS credentials.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	public static function is_registered(): bool {
		$credentials = get_option( SURECOOKIE_SITE_CREDENTIALS_OPTION, [] );

		return is_array( $credentials ) && ! empty( $credentials['api_key'] );
	}
}
