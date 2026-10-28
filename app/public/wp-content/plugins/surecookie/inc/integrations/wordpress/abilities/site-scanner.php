<?php
/**
 * Site Scanner Ability
 *
 * Multi-action ability for managing the SureCookie cookie scanner.
 * Supports checking scan status, initiating scans with optional page URLs,
 * retrieving progress logs, and cancelling active scans.
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress/Abilities
 * @since      0.0.1-alpha.1
 */

namespace SureCookie\Inc\Integrations\Wordpress\Abilities;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Integrations\Wordpress\Base;
use SureCookie\Inc\Modules\SiteScanner\Cron;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;
use SureCookie\Inc\Modules\SiteScanner\Utils;
use SureCookie\Inc\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class SiteScanner
 *
 * Provides scan management capabilities.
 *
 * @since 0.0.1-alpha.1
 */
class SiteScanner extends Base {
	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input The validated input data.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		try {
			$action = $input['action'] ?? 'status';

			switch ( $action ) {
				case 'status':
					return $this->handle_status();

				case 'start':
					$pages = $input['pages'] ?? [];
					return $this->handle_start( $pages );

				case 'logs':
					return $this->handle_logs();

				case 'cancel':
					return $this->handle_cancel();

				case 'quota':
					return $this->handle_quota( ! empty( $input['refresh'] ) );

				default:
					return [
						'success' => false,
						'message' => __( 'Invalid scanner action.', 'surecookie' ),
						'data'    => [],
					];
			}
		} catch ( \Throwable $e ) {
			return [
				'success' => false,
				'message' => __( 'An unexpected error occurred while managing the site scanner.', 'surecookie' ),
				'data'    => [],
			];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_name(): string {
		return 'surecookie/site-scanner';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label(): string {
		return __( 'Site Scanner', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description(): string {
		return __( 'Manage the SureCookie site cookie scanner. Actions: "status" returns the current scan state (idle, in_progress, completed) with progress data — safe to call at any time. "start" initiates a new cookie scan that contacts an external scanning service to detect cookies on site pages; optionally pass post IDs or permalinks to scan, otherwise the saved page selection is used. Only one scan can run at a time. On success it reports group_id, pages_scanned and pages_dropped — a non-zero pages_dropped means the submission exceeded the plan budget and pages were trimmed, so tell the user. "logs" retrieves detailed scan progress log entries — safe to call at any time. "cancel" stops an active scan; any progress from the current scan is lost. "quota" reports the remaining scan allowance and plan; check it before "start" so a refusal can be explained (a failed start returns code, limit, remaining and used). Scans typically take a few minutes depending on the number of pages.', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_annotations(): array {
		return [
			'priority'        => 2.0,
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => true,
			'instructions'    => 'The "status" and "logs" actions are safe to call at any time. The "start" action initiates a cookie scan that contacts an external scanning service — always confirm with the user before starting a scan. If a scan is already in progress, the start action will fail gracefully. The "cancel" action stops an active scan and any in-progress scan data may be lost — confirm with the user before cancelling.',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'action'  => [
					'type'        => 'string',
					'enum'        => [ 'status', 'start', 'logs', 'cancel', 'quota' ],
					'description' => __( 'The scanner action: "status" to check progress, "start" to initiate a scan, "logs" to retrieve progress logs, "cancel" to stop an active scan, "quota" to read the remaining scan allowance and plan.', 'surecookie' ),
				],
				'refresh' => [
					'type'        => 'boolean',
					'description' => __( 'For "quota", bypass the cached value and ask the scanning service. Leave unset unless the cached figure looks stale, since a refresh makes an external request.', 'surecookie' ),
				],
				'pages'   => [
					'type'        => 'array',
					'items'       => [ 'type' => [ 'integer', 'string' ] ],
					'description' => __( 'Optional. For "start" action, the pages to scan as post IDs or permalinks of published content. Replaces the saved page selection. Entries that do not match published content are ignored; if none match, the scan is refused and the saved selection is kept. If empty, the saved selection is used.', 'surecookie' ),
				],
			],
			'required'   => [ 'action' ],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'success' => [
					'type'        => 'boolean',
					'description' => __( 'Whether the operation succeeded.', 'surecookie' ),
				],
				'message' => [
					'type'        => 'string',
					'description' => __( 'Result message.', 'surecookie' ),
				],
				'data'    => [
					'type'        => 'object',
					'description' => __( 'Scanner result data.', 'surecookie' ),
				],
			],
		];
	}

	/**
	 * Handle the "status" action.
	 *
	 * @return array{success: bool, message: string, data: array<string, mixed>}
	 * @since 0.0.1-alpha.1
	 */
	private function handle_status(): array {
		$cron = Cron::get_instance();

		return [
			'success' => true,
			'message' => __( 'Scan status retrieved.', 'surecookie' ),
			'data'    => $cron->get_scan_status(),
		];
	}

	/**
	 * Handle the "quota" action.
	 *
	 * Mirrors the admin endpoint: served from cache unless a refresh is asked
	 * for, because a cold read contacts the SaaS. Without this an agent calling
	 * "start" blind cannot tell a quota refusal from a transient failure.
	 *
	 * @param bool $refresh Bypass the cached value and ask the SaaS.
	 * @return array{success: bool, message: string, data: array<string, mixed>}
	 * @since 1.4.0
	 */
	private function handle_quota( bool $refresh ): array {
		$saas_client = SaasClient::get_instance();

		if ( ! $refresh ) {
			$cached = $saas_client->get_cached_quota();

			if ( ! empty( $cached ) ) {
				$plan = $saas_client->get_cached_plan();
				// `_plan` is an internal sentinel on the cached payload.
				unset( $cached['_plan'] );

				return [
					'success' => true,
					'message' => __( 'Scan quota retrieved.', 'surecookie' ),
					'data'    => [
						'quota' => $cached,
						'plan'  => $plan !== '' ? $plan : Utils::get_plan(),
						'fresh' => false,
					],
				];
			}
		}

		$result = $saas_client->get_quota();
		$ok     = ! empty( $result['success'] );

		return [
			// Mirrors handle_start: a SaaS refusal is a failure, not an empty
			// quota, or an agent reads "not registered" as "zero scans left".
			'success' => $ok,
			'message' => $ok
				? __( 'Scan quota retrieved.', 'surecookie' )
				: ( is_string( $result['message'] ?? null ) && $result['message'] !== ''
					? $result['message']
					: __( 'Could not retrieve the scan quota.', 'surecookie' ) ),
			'data'    => [
				'quota'      => $result['quota'] ?? [],
				'plan'       => $result['plan'] ?? Utils::get_plan(),
				'fresh'      => $ok,
				'error_code' => $result['error_code'] ?? null,
				'error'      => empty( $result['success'] ) ? ( $result['message'] ?? null ) : null,
			],
		];
	}

	/**
	 * Resolve supplied pages into the rows `scan_pages` stores.
	 *
	 * Cron::get_pages_urls_to_scan() reads each row's `value` as a post ID, so
	 * bare URL strings resolve to nothing and the scan bails with `no_pages`.
	 * Accepts post IDs or permalinks and keeps only published content.
	 *
	 * @param array<int, mixed> $pages Post IDs or permalinks.
	 * @return array<int, array{label: string, value: int}>
	 * @since 1.4.0
	 */
	private function resolve_scan_pages( array $pages ): array {
		$resolved = [];

		foreach ( $pages as $page ) {
			$post_id = is_numeric( $page ) ? absint( $page ) : url_to_postid( (string) $page );
			$post    = $post_id > 0 ? get_post( $post_id ) : null;

			if ( ! $post || $post->post_status !== 'publish' ) {
				continue;
			}

			// Keyed by ID so a duplicate ID and permalink collapse to one row.
			$resolved[ $post_id ] = [
				'label' => (string) get_the_title( $post_id ),
				'value' => $post_id,
			];
		}

		return array_values( $resolved );
	}

	/**
	 * Handle the "start" action.
	 *
	 * @param array<int, mixed> $pages Optional post IDs or permalinks to scan.
	 * @return array{success: bool, message: string, data: array<string, mixed>}
	 * @since 0.0.1-alpha.1
	 */
	private function handle_start( array $pages = [] ): array {
		$saas_client = SaasClient::get_instance();

		if ( $saas_client->is_scan_in_progress() ) {
			return [
				'success' => false,
				'message' => __( 'A scan is already in progress.', 'surecookie' ),
				'data'    => [],
			];
		}

		$pages    = array_filter( $pages );
		$resolved = $this->resolve_scan_pages( $pages );

		// Refuse rather than overwrite: a failed resolve used to store unusable
		// rows, so the admin's saved selection was lost and every later scan
		// started from wp-admin failed too until it was re-picked by hand.
		if ( ! empty( $pages ) && empty( $resolved ) ) {
			return [
				'success' => false,
				'message' => __( 'None of the supplied pages matched published content, so the scan was not started and the saved page selection was left unchanged.', 'surecookie' ),
				'data'    => [],
			];
		}

		if ( ! empty( $resolved ) ) {
			Settings::update( 'scan_pages', $resolved );
		}

		Logger::get_instance()->cleanup_logs();

		$cron   = Cron::get_instance();
		$result = $cron->start_site_scanning();

		// Surface the real outcome: on local/dev sites, when the plan limit is
		// hit, or on a SaaS/network failure, start_scan() blocks the scan and
		// returns success=false — don't report a scan that never started.
		if ( empty( $result['success'] ) ) {
			$data = [];
			foreach ( [ 'code', 'limit', 'remaining', 'used' ] as $key ) {
				if ( isset( $result[ $key ] ) ) {
					$data[ $key ] = $result[ $key ];
				}
			}

			return [
				'success' => false,
				'message' => $result['message'] ?? __( 'Failed to start cookie scan.', 'surecookie' ),
				'data'    => $data,
			];
		}

		// Pass the submission outcome through. pages_dropped is the only signal
		// that the page list was silently trimmed to the plan's per-scan budget.
		$data = [];
		foreach ( [ 'group_id', 'pages_scanned', 'pages_dropped' ] as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$data[ $key ] = $result[ $key ];
			}
		}

		return [
			'success' => true,
			'message' => __( 'Cookie scan has been initiated.', 'surecookie' ),
			'data'    => $data,
		];
	}

	/**
	 * Handle the "logs" action.
	 *
	 * @return array{success: bool, message: string, data: mixed}
	 * @since 0.0.1-alpha.1
	 */
	private function handle_logs(): array {
		return [
			'success' => true,
			'message' => __( 'Scan logs retrieved.', 'surecookie' ),
			'data'    => Logger::get_instance()->get_log(),
		];
	}

	/**
	 * Handle the "cancel" action.
	 *
	 * @return array{success: bool, message: string, data: array<string, mixed>}
	 * @since 0.0.1-alpha.1
	 */
	private function handle_cancel(): array {
		$cron      = Cron::get_instance();
		$cancelled = $cron->cancel_scan();

		return [
			'success' => $cancelled,
			'message' => $cancelled
				? __( 'Scan cancelled successfully.', 'surecookie' )
				: __( 'No active scan to cancel.', 'surecookie' ),
			'data'    => [],
		];
	}
}
