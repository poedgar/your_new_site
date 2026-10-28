<?php
/**
 * SureContactProvision class
 *
 * Provisions a new SureContact account from inside WordPress: forwards the
 * user details to the SureContact API, persists the returned API key as a new
 * SureMails connection, and hands the saved connection back to the React UI.
 *
 * @package SureMails\Inc\API
 */

namespace SureMails\Inc\API;

use SureMails\Inc\Emails\Providers\SURECONTACT\SurecontactHandler;
use SureMails\Inc\Settings;
use SureMails\Inc\Traits\Instance;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SureContactProvision
 *
 * Handles the `POST /suremails/v1/surecontact/provision` endpoint.
 */
class SureContactProvision extends Api_Base {
	use Instance;

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '/surecontact/provision';

	/**
	 * Register API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->get_api_namespace(),
			$this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'provision' ],
					'permission_callback' => [ $this, 'validate_permission' ],
				],
			]
		);
	}

	/**
	 * Provision a SureContact account and persist the returned credentials as a
	 * SureMails connection.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 * @return WP_REST_Response
	 */
	public function provision( $request ) {
		$params = $request->get_json_params();
		$name   = sanitize_text_field( (string) ( $params['name'] ?? '' ) );
		$email  = sanitize_email( (string) ( $params['email'] ?? '' ) );
		// Lets SureForms (or another plugin) provisioning through SureMail be
		// credited as the channel; unknown values report as 'suremails'.
		$source = SurecontactHandler::resolve_source( $params['utm_source'] ?? '' );

		// The site identifier is this install's REST namespace root, built
		// server-side from rest_url() so it already honours the permalink
		// structure, the rest_url_prefix filter, and the WP-core address —
		// SureContact appends /verify and /surecontact/saas-disconnect to it
		// for its callbacks rather than guessing /wp-json/. Pinned here (not
		// taken from the request) so a tampered client can't bind the
		// SureContact account to a site the admin didn't intend.
		$website = esc_url_raw( rest_url( 'suremails/v1/' ) );

		// Only one SureContact connection is allowed per site. Reject before
		// hitting Laravel so we never orphan a remote account.
		if ( SaveTestConnection::instance()->has_existing_surecontact() ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'SureContact is already connected. Only one SureContact connection is allowed per site. Remove the existing connection to add a new one.', 'suremails' ),
				],
				400
			);
		}

		if ( $email === '' ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'A valid email address is required.', 'suremails' ),
				],
				400
			);
		}

		$payload = [
			'email'          => $email,
			'name'           => $name,
			'site_url'       => $website,
			'site_name'      => get_bloginfo( 'name' ),
			'wp_version'     => get_bloginfo( 'version' ),
			'plugin_version' => defined( 'SUREMAILS_VERSION' ) ? SUREMAILS_VERSION : '',
			'utm_source'     => $source,
		];

		$response = $this->call_provision( $payload );

		if ( ! $response['ok'] ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => $response['message'],
					'reason'  => $response['reason'],
				],
				$response['code'] !== 0 ? $response['code'] : 502
			);
		}

		$data    = $response['data']['data'] ?? [];
		$api_key = (string) ( $data['api_key'] ?? '' );

		if ( $api_key === '' ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'SureContact did not return an API key. Please try again.', 'suremails' ),
				],
				502
			);
		}

		$connection = $this->store_new_connection( $name, $email, $data );

		return new WP_REST_Response(
			[
				'success'    => true,
				'message'    => __( 'Connected to SureContact.', 'suremails' ),
				'connection' => $connection,
			],
			201
		);
	}

	/**
	 * Call the SureContact provision endpoint.
	 *
	 * @param array<string, string> $payload Body for the request.
	 * @return array{ok: bool, code: int, message: string, reason: string, data: array<string, mixed>}
	 */
	private function call_provision( $payload ) {
		$url = rtrim( SurecontactHandler::api_base(), '/' ) . '/suremails/provision';

		$encoded = wp_json_encode( $payload );
		if ( $encoded === false ) {
			return [
				'ok'      => false,
				'code'    => 0,
				'message' => __( 'Failed to encode SureContact request payload.', 'suremails' ),
				'reason'  => '',
				'data'    => [],
			];
		}

		$response = wp_remote_post(
			$url,
			[
				'method'  => 'POST',
				'headers' => [
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				],
				'body'    => $encoded,
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'ok'      => false,
				'code'    => 0,
				'message' => $response->get_error_message(),
				'reason'  => '',
				'data'    => [],
			];
		}

		$code         = (int) wp_remote_retrieve_response_code( $response );
		$raw          = (string) wp_remote_retrieve_body( $response );
		$decoded      = json_decode( $raw, true );
		$decoded_body = is_array( $decoded ) ? $decoded : [];
		$ok           = $code >= 200 && $code < 300 && ( $decoded_body['success'] ?? false ) === true;

		return [
			'ok'      => $ok,
			'code'    => $code,
			'message' => $ok ? '' : $this->friendly_error( $decoded_body, $code ),
			'reason'  => (string) ( $decoded_body['error_code'] ?? $decoded_body['reason'] ?? '' ),
			'data'    => $decoded_body,
		];
	}

	/**
	 * Map known SureContact error reasons into actionable plugin messages.
	 *
	 * @param array<string, mixed> $body API response body.
	 * @param int                  $code HTTP status.
	 * @return string
	 */
	private function friendly_error( array $body, $code ) {
		$reason  = (string) ( $body['error_code'] ?? $body['reason'] ?? '' );
		$message = (string) ( $body['message'] ?? '' );

		switch ( $reason ) {
			case 'email_already_registered':
				return __( 'An account already exists for this email address. Click on \'Already have a SureContact account? Connect it instead →\' to connect your existing SureContact account.', 'suremails' );
			case 'site_unreachable':
				return __( 'SureContact could not reach this site to verify ownership. Make sure the site is publicly accessible and try again.', 'suremails' );
			case 'site_not_verified':
				return __( 'Site ownership verification failed. Please retry — and ensure no security plugin is blocking SureContact requests to this site\'s REST API.', 'suremails' );
		}

		if ( $code === 429 ) {
			return __( 'Too many provisioning requests. Please wait a few minutes and try again.', 'suremails' );
		}

		// Laravel validation errors arrive as { message: "Validation failed", errors: { field: [..] } }.
		// Surface the first field-level error so the user knows what to fix.
		if ( $code === 422 && isset( $body['errors'] ) && is_array( $body['errors'] ) ) {
			foreach ( $body['errors'] as $field => $messages ) {
				if ( is_array( $messages ) && ! empty( $messages ) ) {
					return sprintf( '%s: %s', (string) $field, (string) $messages[0] );
				}
			}
		}

		if ( $message !== '' ) {
			return $message;
		}

		return sprintf(
			// translators: %d is the HTTP status code returned by SureContact.
			__( 'SureContact returned an unexpected response (HTTP %d).', 'suremails' ),
			$code
		);
	}

	/**
	 * Persist a brand new SureContact connection row using the SureContact
	 * provision response. Reuses the SaveTestConnection storage helpers so the
	 * row goes through the same encryption + default-connection bookkeeping as
	 * any other provider.
	 *
	 * @param string               $name  Display name supplied in the form.
	 * @param string               $email Email supplied in the form.
	 * @param array<string, mixed> $data  Inner `data` object from the SureContact response.
	 * @return array<string, mixed>
	 */
	private function store_new_connection( $name, $email, array $data ) {
		$connection_data = [
			'type'             => 'SURECONTACT',
			'connection_title' => $this->next_connection_title(),
			'from_email'       => $email,
			'from_name'        => $name !== '' ? $name : (string) get_bloginfo( 'name' ),
			'force_from_email' => true,
			'force_from_name'  => $name !== '',
			'priority'         => $this->next_priority(),
			'api_key'          => (string) ( $data['api_key'] ?? '' ),
			'workspace_uuid'   => (string) ( $data['workspace_uuid'] ?? '' ),
			'connection_uuid'  => (string) ( $data['connection_uuid'] ?? '' ),
			'email_verified'   => (bool) ( $data['email_verified'] ?? false ),
			'id'               => '',
		];

		return SaveTestConnection::instance()->store_connection( $connection_data );
	}

	/**
	 * Pick the next available priority — `max + 1` over existing connections.
	 *
	 * @return int
	 */
	private function next_priority() {
		$settings    = Settings::instance()->get_settings();
		$connections = is_array( $settings['connections'] ?? null ) ? $settings['connections'] : [];

		$max = 0;
		foreach ( $connections as $row ) {
			$priority = (int) ( $row['priority'] ?? 0 );
			if ( $priority > $max ) {
				$max = $priority;
			}
		}

		return $max + 1;
	}

	/**
	 * Generate a non-conflicting connection title for the new SureContact row.
	 *
	 * @return string
	 */
	private function next_connection_title() {
		$base        = __( 'SureContact SMTP', 'suremails' );
		$settings    = Settings::instance()->get_settings();
		$connections = is_array( $settings['connections'] ?? null ) ? $settings['connections'] : [];

		$count = 0;
		foreach ( $connections as $row ) {
			if ( ( $row['type'] ?? '' ) === 'SURECONTACT' ) {
				$count++;
			}
		}

		if ( $count === 0 ) {
			return $base;
		}

		return sprintf( '%s (%d)', $base, $count );
	}
}

SureContactProvision::instance();
