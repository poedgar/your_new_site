<?php
/**
 * Assisted Scan REST API.
 *
 * All four routes are capability + nonce gated. The two the scan window calls add a
 * session-token gate, and `/page` also checks the expected page index, so a replayed
 * or out-of-order submission cannot corrupt the run.
 *
 * @package SureCookie\Inc\Modules\AssistedScan
 * @since   1.3.0
 */

namespace SureCookie\Inc\Modules\AssistedScan;

use SureCookie\Inc\API\Base;
use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\SiteScanner\Cron;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\Logger;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Api
 *
 * @since 1.3.0
 */
class Api extends Base {
	use GetInstance;

	/**
	 * Register API routes.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			'/assisted-scan/start',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'start' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'post_ids' => [
						'type'     => 'array',
						'required' => false,
						'items'    => [ 'type' => 'integer' ],
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			'/assisted-scan/page',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'record_page' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'token'      => [
						'type'     => 'string',
						'required' => true,
					],
					'page_index' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			'/assisted-scan/finish',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'finish' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'token' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			'/assisted-scan/cancel',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'cancel' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);
	}

	/**
	 * Open a walk and hand back the first page to visit.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @since 1.3.0
	 * @return void
	 */
	public function start( $request ): void {
		$session = Session::get_instance();

		// One walk at a time. Silently adopting or discarding an in-flight session
		// would either lose what it collected or let two tabs fight over the queue.
		if ( $session->is_active() ) {
			SendJson::error(
				[
					'code'    => 'scan_in_progress',
					'message' => __( 'An assisted scan is already running. Cancel it before starting another.', 'surecookie' ),
				]
			);
		}

		// A stale session left by an abandoned walk is finalized rather than thrown
		// away, so its findings still reach the cookie list.
		if ( ! empty( $session->get() ) ) {
			Ingest::get_instance()->finalize();
		}

		$post_ids = $request->get_param( 'post_ids' );
		$pages    = is_array( $post_ids ) && $post_ids !== []
			? $this->pages_from_post_ids( $post_ids )
			: $this->pages_from_settings();

		$pages = array_slice( $pages, 0, Utils::get_max_pages() );

		if ( $pages === [] ) {
			SendJson::error(
				[
					'code'    => 'no_pages',
					'message' => __( 'Select at least one published page to scan.', 'surecookie' ),
				]
			);
		}

		$state = $session->start( $pages );

		if ( empty( $state['token'] ) ) {
			SendJson::error(
				[
					'code'    => 'no_pages',
					'message' => __( 'Select at least one published page to scan.', 'surecookie' ),
				]
			);
		}

		Telemetry::record_started();

		Logger::get_instance()->cleanup_logs();
		Logger::get_instance()->save_log( __( 'Starting assisted scan (collected from your browser).', 'surecookie' ) );
		Logger::get_instance()->save_log(
			sprintf(
				/* translators: %d: number of pages. */
				__( 'Pages to visit: %d.', 'surecookie' ),
				count( $state['pages'] )
			)
		);
		Logger::get_instance()->save_log( '' );

		SendJson::success(
			[
				'token' => (string) $state['token'],
				'url'   => $session->scan_url( 0, $state ),
				'total' => count( $state['pages'] ),
			]
		);
	}

	/**
	 * Accept one collected page and hand back the next URL.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @since 1.3.0
	 * @return void
	 */
	public function record_page( $request ): void {
		$session = Session::get_instance();
		$token   = (string) $request->get_param( 'token' );

		if ( ! $session->verify_token( $token ) ) {
			SendJson::error(
				[
					'code'    => 'invalid_token',
					'message' => __( 'This scan session has expired. Start the scan again.', 'surecookie' ),
				]
			);
		}

		$findings = $request->get_param( 'findings' );
		$findings = is_array( $findings ) ? $findings : [];

		$invalid = Ingest::validate_findings( $findings );
		if ( $invalid !== '' ) {
			SendJson::error(
				[
					'code'    => $invalid,
					'message' => __( 'The scan sent more data than a single page should produce.', 'surecookie' ),
				]
			);
		}

		$index = (int) $request->get_param( 'page_index' );
		$error = sanitize_text_field( (string) $request->get_param( 'error' ) );

		if ( ! Ingest::get_instance()->record_page( $index, $findings, $error ) ) {
			SendJson::error(
				[
					'code'     => 'unexpected_page',
					'message'  => __( 'That page is not the one this scan was waiting for.', 'surecookie' ),
					'expected' => $session->current_index(),
				]
			);
		}

		$state = $session->get();

		SendJson::success(
			[
				'next_url' => $session->next_url( $state ),
				'done'     => $session->is_complete( $state ),
				'progress' => $session->progress( $state ),
			]
		);
	}

	/**
	 * Close the walk: classify, cross-check, and record it as one scan.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @since 1.3.0
	 * @return void
	 */
	public function finish( $request ): void {
		$session = Session::get_instance();

		if ( ! $session->verify_token( (string) $request->get_param( 'token' ) ) ) {
			SendJson::error(
				[
					'code'    => 'invalid_token',
					'message' => __( 'This scan session has expired. Start the scan again.', 'surecookie' ),
				]
			);
		}

		SendJson::success( Ingest::get_instance()->finalize() );
	}

	/**
	 * Abandon the walk.
	 *
	 * A token is accepted but not required, so the admin can clear a session from the
	 * admin screen after the scan window (and its token) is gone. Capability-gated either way.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @since 1.3.0
	 * @return void
	 */
	public function cancel( $request ): void {
		unset( $request );

		Session::get_instance()->clear();
		Logger::get_instance()->save_log( __( 'Assisted scan cancelled.', 'surecookie' ) );

		SendJson::success( [ 'message' => __( 'Assisted scan cancelled.', 'surecookie' ) ] );
	}

	/**
	 * Build the walk from the administrator's saved content selection.
	 *
	 * Uses the same URL list the cloud scanner walks, so both scanners agree on scope
	 * and the `surecookie_scanner_page_urls_to_scan` filter still applies to extensions.
	 *
	 * @since 1.3.0
	 * @return array<int, array<string, mixed>>
	 */
	private function pages_from_settings(): array {
		$labels = [];

		$scan_pages = Settings::get( 'scan_pages' );
		foreach ( is_array( $scan_pages ) ? $scan_pages : [] as $entry ) {
			$post_id = absint( $entry['value'] ?? 0 );

			if ( $post_id <= 0 ) {
				continue;
			}

			$permalink = get_permalink( $post_id );

			if ( is_string( $permalink ) && $permalink !== '' ) {
				$labels[ $permalink ] = [
					'post_id' => $post_id,
					'title'   => (string) get_the_title( $post_id ),
				];
			}
		}

		$pages = [];

		foreach ( Cron::get_instance()->get_pages_urls_to_scan() as $url ) {
			$meta    = $labels[ $url ] ?? [];
			$pages[] = [
				'url'     => $url,
				'post_id' => absint( $meta['post_id'] ?? 0 ),
				'title'   => (string) ( $meta['title'] ?? '' ),
			];
		}

		return $pages;
	}

	/**
	 * Build a walk from explicit post ids, for re-scanning a single page.
	 *
	 * @param array<int, mixed> $post_ids Requested post ids.
	 * @since 1.3.0
	 * @return array<int, array<string, mixed>>
	 */
	private function pages_from_post_ids( array $post_ids ): array {
		$pages = [];

		foreach ( $post_ids as $raw_id ) {
			$post_id = absint( $raw_id );

			if ( $post_id <= 0 ) {
				continue;
			}

			$post = get_post( $post_id );

			if ( ! $post || $post->post_status !== 'publish' ) {
				continue;
			}

			$permalink = get_permalink( $post_id );

			if ( ! is_string( $permalink ) || $permalink === '' ) {
				continue;
			}

			$pages[] = [
				'url'     => $permalink,
				'post_id' => $post_id,
				'title'   => (string) get_the_title( $post_id ),
			];
		}

		return $pages;
	}
}
