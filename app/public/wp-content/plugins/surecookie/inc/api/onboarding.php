<?php
/**
 * Onboarding API module.
 *
 * @package    SureCookie
 * @subpackage SureCookie\Inc\Api
 * @since      0.0.1
 */

namespace SureCookie\Inc\Api;

use SureCookie\Inc\API\Base;
use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Functions\Validate;
use SureCookie\Inc\Traits\GetInstance;

defined( 'ABSPATH' ) || exit;

/**
 * Onboarding API module.
 *
 * @since 0.0.1
 */
class Onboarding extends Base {
	use GetInstance;

	/**
	 * Route to save onboarding data.
	 */
	protected const SAVE_ONBOARDING = '/onboarding/save';

	/**
	 * Route to mark onboarding as complete.
	 */
	protected const COMPLETE_ONBOARDING = '/onboarding/complete';

	/**
	 * Webhook URL for sending onboarding data.
	 */
	private const BSF_METRICS_URL = 'https://metrics.brainstormforce.com/wp-json/bsf-metrics-server/v1/subscribe';

	/**
	 * Screens allowed to report that they recorded completion.
	 *
	 * @since 1.5.0
	 */
	private const COMPLETION_SOURCES = [ 'finish', 'user_details_next', 'user_details_skip', 'unknown' ];

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			self::SAVE_ONBOARDING,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_onboarding_data' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					// Optional: WordPress never populates first_name at install,
					// and the webhook only needs the email address.
					'first_name' => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'last_name'  => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'email'      => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => 'is_email',
					],
					'lead'       => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
						'validate_callback' => 'rest_is_boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::COMPLETE_ONBOARDING,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'mark_onboarding_complete' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					// Optional on purpose: a cached bundle posts no body, and a
					// required arg would reject those completions outright.
					'source' => [
						'required'          => false,
						'type'              => 'string',
						'default'           => 'unknown',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( $value ): bool => in_array( $value, self::COMPLETION_SOURCES, true ),
					],
				],
			]
		);
	}

	/**
	 * Validation callback.
	 *
	 * @since 0.0.1
	 * @param mixed $value The value to validate.
	 * @return bool
	 */
	public function validate_params( $value ): bool {
		return Validate::string( $value ) && Validate::not_empty( $value );
	}

	/**
	 * Save onboarding data.
	 *
	 * @since 0.0.1
	 * @param \WP_REST_Request<array<string, mixed>> $request $request The REST request.
	 * @return void
	 */
	public function save_onboarding_data( $request ): void {
		$first_name = $request->get_param( 'first_name' );
		$last_name  = $request->get_param( 'last_name' );
		$email      = $request->get_param( 'email' );
		$lead       = $request->get_param( 'lead' ) ?? false;

		// Send data to external webhook.
		$this->send_to_webhook(
			[
				'firstName' => $first_name,
				'lastName'  => $last_name,
				'email'     => $email,
				'lead'      => $lead,
			]
		);

		SendJson::success(
			[
				'message' => __( 'Onboarding data saved successfully.', 'surecookie' ),
			]
		);
	}

	/**
	 * Mark onboarding as complete.
	 *
	 * @since 0.0.1
	 * @param \WP_REST_Request<array<string, mixed>> $request $request The REST request.
	 * @return void
	 */
	public function mark_onboarding_complete( $request ): void {
		$is_completed = (bool) Get::option( SURECOOKIE_ONBOARDING_COMPLETED_OPTION, false );

		if ( $is_completed ) {
			SendJson::success(
				[
					'message' => __( 'Onboarding is already marked as complete.', 'surecookie' ),
				]
			);
			return;
		}

		Update::option( SURECOOKIE_ONBOARDING_COMPLETED_OPTION, true );

		// First write only, guaranteed by the early return above, so the value
		// attributes the completion to the screen that actually recorded it.
		Update::option( 'surecookie_onboarding_completed_source', (string) $request->get_param( 'source' ) );

		SendJson::success(
			[
				'message' => __( 'Onboarding marked as complete.', 'surecookie' ),
			]
		);
	}

	/**
	 * Send onboarding data to external webhook.
	 *
	 * @since 1.0.0-alpha.2
	 * @param array<string, mixed> $data The data to send.
	 * @return void
	 */
	private function send_to_webhook( $data ): void {
		if ( empty( $data['email'] ) ) {
			return;
		}

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $domain ) ) {
			$domain = '';
		}

		$payload = [
			'email'      => $data['email'],
			'first_name' => $data['firstName'] ?? '',
			'last_name'  => $data['lastName'] ?? '',
			'domain'     => $domain,
			'source'     => 'surecookie',
		];
		$body    = wp_json_encode( $payload );

		if ( $body === false ) {
			return;
		}

		$response = wp_remote_post(
			self::BSF_METRICS_URL,
			[
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => $body,
				'timeout' => 15,
			]
		);

		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
			update_option( 'surecookie_onboarding_lead', true );
		}
	}
}
