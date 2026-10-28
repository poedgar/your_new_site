<?php
/**
 * Live Versions - tells the dashboard when a newer SureCookie release is out.
 *
 * @package SureCookie\Admin
 * @since 1.6.0
 */

namespace SureCookie\Admin;

use SureCookie\Inc\Functions\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the running versions against the live ones set in the SureCookie SaaS admin.
 *
 * @since 1.6.0
 */
class Live_Versions {
	/**
	 * Transient caching the SaaS answer for a day.
	 *
	 * @since 1.6.0
	 */
	public const CACHE_KEY = 'surecookie_live_versions';

	/**
	 * Dashboard notice data: every product running behind its live version.
	 *
	 * Empty for users who cannot update plugins, who could not act on the notice anyway.
	 *
	 * @since 1.6.0
	 * @return array{url: string, products: array<int, array{key: string, name: string, current: string, latest: string}>}
	 */
	public static function get_update_notice(): array {
		$notice = [
			'url'      => network_admin_url( 'update-core.php' ),
			'products' => [],
		];

		if ( ! current_user_can( 'update_plugins' ) ) {
			return $notice;
		}

		$running = [ 'free' => SURECOOKIE_VERSION ];
		if ( defined( 'SURECOOKIE_PRO_VERSION' ) ) {
			$running['pro'] = SURECOOKIE_PRO_VERSION;
		}

		$names = [
			'free' => 'SureCookie',
			'pro'  => 'SureCookie Pro',
		];

		foreach ( self::get_live() as $key => $latest ) {
			if ( isset( $running[ $key ] ) && version_compare( $running[ $key ], $latest, '<' ) ) {
				$notice['products'][] = [
					'key'     => $key,
					'name'    => $names[ $key ],
					'current' => $running[ $key ],
					'latest'  => $latest,
				];
			}
		}

		return $notice;
	}

	/**
	 * Testing aid: `?clear_cache_version=1` on any URL makes the next dashboard load re-fetch.
	 *
	 * Called on every request from the loader, before the admin page localizes its data.
	 *
	 * @since 1.6.0
	 * @return void
	 */
	public static function maybe_clear_cache(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only drops a cache, for a user who can update plugins.
		if ( isset( $_GET['clear_cache_version'] ) && current_user_can( 'update_plugins' ) ) {
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Live versions from the SaaS, cached for a day.
	 *
	 * This runs inline on the admin page render, so a failed fetch is cached too: an
	 * unreachable SaaS then costs one short timeout a day instead of one per page load.
	 *
	 * @since 1.6.0
	 * @return array<string, string> `free`/`pro` => version, only for well-formed values.
	 */
	private static function get_live(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get( Helper::get_agent_app_url() . 'api/plugin/live-versions', [ 'timeout' => 3 ] );
		$body     = json_decode( wp_remote_retrieve_body( $response ), true );
		$live     = [];

		if ( wp_remote_retrieve_response_code( $response ) === 200 && is_array( $body ) ) {
			foreach ( [ 'free', 'pro' ] as $key ) {
				if ( isset( $body[ $key ] ) && is_string( $body[ $key ] ) && preg_match( '/^\d+(\.\d+){1,3}\z/', $body[ $key ] ) ) {
					$live[ $key ] = $body[ $key ];
				}
			}
		}

		set_transient( self::CACHE_KEY, $live, DAY_IN_SECONDS );

		return $live;
	}
}
