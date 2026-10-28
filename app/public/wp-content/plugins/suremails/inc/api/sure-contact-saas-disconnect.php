<?php
/**
 * SureContactSaasDisconnect class
 *
 * Webhook called by the SureContact Laravel backend when a SureContact
 * account is disconnected on its side (`ConnectionController::revoke()`),
 * so the WP plugin can remove the matching connection row without manual
 * intervention. Authentication is server-to-server: a `Bearer {api_key}`
 * header plus `workspace_uuid` and `connection_id` body params, all three
 * of which must match the same stored SURECONTACT connection.
 *
 * @package SureMails\Inc\API
 */

namespace SureMails\Inc\API;

use SureMails\Inc\Settings;
use SureMails\Inc\Traits\Instance;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SureContactSaasDisconnect
 *
 * Handles `POST /suremails/v1/surecontact/saas-disconnect`.
 */
class SureContactSaasDisconnect extends Api_Base {
	use Instance;

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '/surecontact/saas-disconnect';

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
					'callback'            => [ $this, 'disconnect' ],
					'permission_callback' => [ $this, 'validate_saas_request' ],
					'args'                => [
						'workspace_uuid' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'connection_id'  => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);
	}

	/**
	 * Authenticate the SaaS-initiated disconnect. The bearer must `hash_equals`
	 * the stored `api_key` of a SURECONTACT row, and that same row's
	 * `workspace_uuid` and `connection_uuid` must match the body params. All
	 * three checks scope to a single row — a partial match on a different row
	 * cannot promote into a successful auth.
	 *
	 * On success the matched storage key is stashed on the request so the
	 * handler can delete by id without re-walking the connection list.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 * @return bool|WP_Error
	 */
	public function validate_saas_request( $request ) {
		$auth_header = $request->get_header( 'Authorization' );
		if ( $auth_header === null ) {
			return new WP_Error(
				'suremails_missing_auth',
				__( 'Authorization header is required.', 'suremails' ),
				[ 'status' => 401 ]
			);
		}

		// Anchored regex: a header like `NotBearer foo` must not match `Bearer foo`
		// as a substring and silently degrade into a "wrong token" 403. The
		// SaaS sends a clean header — reject trailing whitespace too.
		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', (string) $auth_header, $matches ) ) {
			return new WP_Error(
				'suremails_invalid_auth_format',
				__( 'Invalid authorization header. Expected: Bearer {token}', 'suremails' ),
				[ 'status' => 401 ]
			);
		}
		$provided_token = $matches[1];

		$workspace_uuid = (string) $request->get_param( 'workspace_uuid' );
		$connection_id  = (string) $request->get_param( 'connection_id' );

		$connections = Settings::instance()->get_settings()['connections'] ?? [];
		if ( ! is_array( $connections ) ) {
			$connections = [];
		}

		$matched_id = '';
		foreach ( $connections as $id => $connection ) {
			if ( ! is_array( $connection ) || ( $connection['type'] ?? '' ) !== 'SURECONTACT' ) {
				continue;
			}

			$stored_key        = (string) ( $connection['api_key'] ?? '' );
			$stored_workspace  = (string) ( $connection['workspace_uuid'] ?? '' );
			$stored_connection = (string) ( $connection['connection_uuid'] ?? '' );
			if ( $stored_key === '' || $stored_workspace === '' || $stored_connection === '' ) {
				continue;
			}

			if (
				hash_equals( $stored_key, $provided_token )
				&& hash_equals( $stored_workspace, $workspace_uuid )
				&& hash_equals( $stored_connection, $connection_id )
			) {
				$matched_id = (string) $id;
				break;
			}
		}

		if ( $matched_id === '' ) {
			// Single neutral message across all match-failure cases so the
			// caller can't infer which of the three identifiers was wrong.
			return new WP_Error(
				'suremails_invalid_request',
				__( 'Invalid SureContact disconnect request.', 'suremails' ),
				[ 'status' => 403 ]
			);
		}

		$request->set_param( '__matched_connection_id', $matched_id );
		return true;
	}

	/**
	 * Remove the SURECONTACT connection identified during auth. Default-
	 * connection bookkeeping mirrors `DeleteConnection` so both delete paths
	 * leave the same shape.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 * @return WP_REST_Response
	 */
	public function disconnect( $request ) {
		$matched_id = (string) $request->get_param( '__matched_connection_id' );
		$options    = Settings::instance()->get_raw_settings();

		if ( ! isset( $options['connections'][ $matched_id ] ) ) {
			// Concurrent local delete won the race. Idempotent success so
			// Laravel's revoke flow doesn't retry pointlessly.
			return new WP_REST_Response(
				[
					'success' => true,
					'message' => __( 'SureContact connection already removed.', 'suremails' ),
				],
				200
			);
		}

		$deleted_conn   = $options['connections'][ $matched_id ];
		$workspace_uuid = (string) ( $deleted_conn['workspace_uuid'] ?? '' );

		// All SureContact rows for this workspace are siblings of one account
		// sharing a single api_key. A SaaS-side disconnect revokes that key, so
		// every sibling sender is now dead — remove them all, not just the row
		// the webhook happened to match. Rebalance the default once afterward.
		$default_id      = isset( $options['default_connection']['id'] ) ? (string) $options['default_connection']['id'] : ''; // @phpstan-ignore isset.offset
		$removed_default = false;
		foreach ( $options['connections'] as $id => $connection ) {
			if (
				! is_array( $connection )
				|| ( $connection['type'] ?? '' ) !== 'SURECONTACT'
				|| ( $workspace_uuid !== '' && (string) ( $connection['workspace_uuid'] ?? '' ) !== $workspace_uuid )
			) {
				continue;
			}
			if ( (string) $id === $default_id ) {
				$removed_default = true;
			}
			unset( $options['connections'][ $id ] );
		}

		if ( $removed_default ) {
			$options['default_connection'] = $this->highest_priority_connection( $options['connections'] );
		}

		update_option( SUREMAILS_CONNECTIONS, $options );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'SureContact connection disconnected.', 'suremails' ),
			],
			200
		);
	}

	/**
	 * Pick the highest-priority connection (lowest priority number) to
	 * become the new default after the SureContact row is removed.
	 *
	 * @param array<string, array<string, string|int|bool>> $connections Remaining connections.
	 * @return array{type: string, email: string, id: string, connection_title: string}
	 */
	private function highest_priority_connection( $connections ) {
		if ( empty( $connections ) ) {
			return [
				'type'             => '',
				'email'            => '',
				'id'               => '',
				'connection_title' => '',
			];
		}

		uasort(
			$connections,
			static function ( $a, $b ) {
				return intval( $a['priority'] ) - intval( $b['priority'] );
			}
		);

		$first = reset( $connections );
		if ( ! $first ) {
			return [
				'type'             => '',
				'email'            => '',
				'id'               => '',
				'connection_title' => '',
			];
		}

		return [
			'type'             => (string) ( $first['type'] ?? '' ),
			'email'            => (string) ( $first['from_email'] ?? '' ),
			'id'               => (string) ( $first['id'] ?? '' ),
			'connection_title' => (string) ( $first['connection_title'] ?? '' ),
		];
	}
}

SureContactSaasDisconnect::instance();
