<?php
/**
 * ContentGuard class
 *
 * Handles the REST API endpoint for the Content Guard.
 *
 * @package SureMails\Inc\API
 */

namespace SureMails\Inc\API;

use SureMails\Inc\Analytics\Analytics;
use SureMails\Inc\Settings;
use SureMails\Inc\Traits\Instance;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ContentGuard
 */
class ContentGuard extends Api_Base {
	use Instance;

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '/content-guard';

	/**
	 * Register API routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->get_api_namespace(),
			$this->rest_base . '/activate',
			[
				[
					'methods'             => [ WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ],
					'callback'            => [ $this, 'activate' ],
					'permission_callback' => [ $this, 'validate_permission' ],
					'args'                => [
						'status' => [
							'required'          => false,
							'type'              => 'string',
							'enum'              => [ 'yes', 'no' ],
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			$this->rest_base . '/user-details',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'save_user_details' ],
					'permission_callback' => [ $this, 'validate_permission' ],
					'args'                => [
						'first_name'     => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'last_name'      => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'email'          => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
							'validate_callback' => [ $this, 'validate_email' ],
						],
						'skip'           => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'agree_to_terms' => [
							'required'          => false,
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
					],
				],
			]
		);
	}

	/**
	 * Sets the Reputation Shield activation status.
	 *
	 * If a `status` parameter ('yes'|'no') is provided, the option is set to that
	 * exact value. Otherwise the previous toggle behavior is preserved for
	 * backward compatibility.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request object.
	 * @since 1.0.0
	 * @return void
	 */
	public function activate( $request ) {

		$requested_status = $request->get_param( 'status' );

		if ( 'yes' === $requested_status || 'no' === $requested_status ) {
			$activated_status = $requested_status;
		} else {
			// Backward-compatible fallback: toggle the current value.
			$activated        = get_option( 'suremails_content_guard_activated', 'no' );
			$activated_status = 'yes' === $activated ? 'no' : 'yes';
		}

		update_option( 'suremails_content_guard_activated', $activated_status );

		if ( 'yes' === $activated_status ) {
			$events = Analytics::events();
			if ( null !== $events ) {
				$events->track( 'reputation_shield_activated', SUREMAILS_VERSION );
			}
		}

		wp_send_json_success( [ 'status' => $activated_status ] );
	}

	/**
	 * Handles the access key.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The request object.
	 * @since 1.0.0
	 * @return void
	 */
	public function save_user_details( $request ) {

		$body = $request->get_params();
		$this->process_usage_optin( $body );

		if ( empty( $body['email'] ) && 'no' === ( $body['skip'] ?? 'no' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Email address is required.', 'suremails' ),
				],
				400
			);
		}

		Settings::instance()->set_user_details( $body );

		if ( 'no' === $body['skip'] ) {
			$this->subscribe_user( $body ); // @phpstan-ignore argument.type
		}

		wp_send_json_success();
	}

	/**
	 * Validate email parameter from user details endpoint.
	 *
	 * @param mixed           $value Email value.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param Parameter name.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool
	 */
	public function validate_email( $value, WP_REST_Request $request, string $param ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$email = trim( (string) $value );
		if ( '' === $email ) {
			return true;
		}

		return false !== is_email( $email );
	}

	/**
	 * Subscribes the user to the email list.
	 *
	 * @param array{email: string, first_name: string, last_name: string, skip: string, lead?: bool} $details The user details.
	 * @since 1.0.0
	 * @return void
	 */
	public function subscribe_user( array $details ) {

		$subscription_status = Settings::instance()->get_user_details( 'lead', false );

		if ( $subscription_status ) {
			return;
		}

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $domain ) ) {
			$domain = '';
		}

		$payload = wp_json_encode(
			[
				'email'      => $details['email'],
				'first_name' => $details['first_name'],
				'last_name'  => $details['last_name'],
				'domain'     => $domain,
				'source'     => 'suremails',
			]
		);

		if ( false === $payload ) {
			return;
		}

		$args = [
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'body'    => $payload,
		];

		$response = wp_safe_remote_post(
			'https://metrics.brainstormforce.com/wp-json/bsf-metrics-server/v1/subscribe/',
			$args
		);

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$details['lead'] = true;
			Settings::instance()->set_user_details( $details );
		}
	}

	/**
	 * Persist onboarding usage opt-in state.
	 *
	 * @param array<string, mixed> $data Request data.
	 * @return void
	 */
	private function process_usage_optin( array $data ): void {
		if ( ! isset( $data['agree_to_terms'] ) ) {
			return;
		}

		$agree_to_terms = $data['agree_to_terms'];
		if ( ! is_bool( $agree_to_terms ) && ! is_int( $agree_to_terms ) && ! is_string( $agree_to_terms ) ) {
			return;
		}

		$has_opted_in = rest_sanitize_boolean( $agree_to_terms );
		update_option( SetSettings::SUREMAILS_ANALYTICS, $has_opted_in ? 'yes' : 'no' );

		if ( ! $has_opted_in ) {
			Settings::instance()->set_user_details( [ 'lead' => false ] );
		}
	}
}

// Initialize the ContentGuard singleton.
ContentGuard::instance();
