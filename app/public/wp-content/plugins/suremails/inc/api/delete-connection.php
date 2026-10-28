<?php
/**
 * DeleteConnection class
 *
 * Handles the REST API endpoint for deleting a connection.
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
	exit; // Exit if accessed directly.
}

/**
 * Class DeleteConnection
 *
 * Handles the `/delete-connection` REST API endpoint.
 */
class DeleteConnection extends Api_Base {
	use Instance;

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '/delete-connection';

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->get_api_namespace(),
			$this->rest_base,
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_connection' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'type'       => [
						'required' => true,
						'type'     => 'string',
					],
					'from_email' => [
						'required' => true,
						'type'     => 'string',
					],
					'id'         => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);
	}

	/**
	 * Delete a connection.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request object.
	 * @return WP_REST_Response The REST API response.
	 */
	public function delete_connection( $request ) {
		$connection_type = sanitize_text_field( $request->get_param( 'type' ) );
		$from_email      = sanitize_email( $request->get_param( 'from_email' ) );
		$connection_id   = sanitize_text_field( $request->get_param( 'id' ) );
		$options         = Settings::instance()->get_raw_settings();

		// Ensure 'connections' is an associative array.
		if ( ! isset( $options['connections'] ) || ! is_array( $options['connections'] ) ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'No connections found.', 'suremails' ),
				],
				404
			);
		}

		// Check if the connection exists.
		if ( ! isset( $options['connections'][ $connection_id ] ) ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Connection not found.', 'suremails' ),
				],
				404
			);
		}

		$connection = $options['connections'][ $connection_id ];

		// Verify the connection attributes.
		if ( $connection['type'] !== $connection_type || $connection['from_email'] !== $from_email ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Connection details do not match.', 'suremails' ),
				],
				400
			);
		}

		// Best-effort cleanup on the SureContact side. Failures don't block the
		// local delete. Multiple SureContact rows can be siblings sharing one
		// api_key (paid multi-sender) — revoking the key kills every sibling, so
		// only revoke when this is the LAST SureContact row for the workspace.
		if ( $connection_type === 'SURECONTACT' ) {
			$decrypted      = Settings::instance()->get_settings()['connections'][ $connection_id ] ?? [];
			$workspace_uuid = (string) ( $decrypted['workspace_uuid'] ?? '' );
			if ( $this->is_last_surecontact_sibling( $connection_id, $workspace_uuid ) ) {
				$this->revoke_surecontact_key( (string) ( $decrypted['api_key'] ?? '' ) );
			}
		}

		// Remove the connection.
		unset( $options['connections'][ $connection_id ] );

		// Handle default and fallback connections.
		$options = $this->handle_default_and_fallback_connections( $options, $connection );

		// Update the connections option.
		update_option( SUREMAILS_CONNECTIONS, $options );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Connection deleted successfully.', 'suremails' ),
			],
			200
		);
	}

	/**
	 * Handle default and fallback connections after deletion.
	 *
	 * @param array{connections: array<string, array<string, string|int|bool>>, default_connection: array{type: string, email: string, id: string, connection_title: string}, log_emails: string, delete_email_logs_after: string, email_simulation: string} $options     The connections options array.
	 * @param array<string, string|int|bool>                                                                                                                                                                                                                 $deleted_conn The deleted connection's data.
	 * @return array{connections: array<string, array<string, string|int|bool>>, default_connection: array{type: string, email: string, id: string, connection_title: string}, log_emails: string, delete_email_logs_after: string, email_simulation: string}
	 */
	private function handle_default_and_fallback_connections( $options, $deleted_conn ) {
		// Handle default connection.
		if (
			isset( $options['default_connection'] ) && // @phpstan-ignore isset.offset
			$options['default_connection']['id'] === $deleted_conn['id']
		) {

				// Set the connection with the highest priority as the new default.
				$options['default_connection'] = $this->get_highest_priority_connection( $options['connections'] );

		}

		return $options;
	}

	/**
	 * Get the connection with the highest priority.
	 *
	 * @param array<string, array<string, string|int|bool>> $connections The associative array of connections.
	 * @return array{type: string, email: string, id: string, connection_title: string} The connection with the highest priority or an empty array.
	 */
	private function get_highest_priority_connection( $connections ) {
		if ( empty( $connections ) ) {
			return [
				'type'             => '',
				'email'            => '',
				'id'               => '',
				'connection_title' => '',
			];
		}

		// Sort connections by priority ascending.
		uasort(
			$connections,
			static function ( $a, $b ) {
				return intval( $a['priority'] ) - intval( $b['priority'] );
			}
		);

		// Return the connection with the lowest priority number (highest priority).
		$first_connection = reset( $connections );

		if ( $first_connection ) {
			return [
				'type'             => (string) ( $first_connection['type'] ?? '' ),
				'email'            => (string) ( $first_connection['from_email'] ?? '' ),
				'id'               => (string) ( $first_connection['id'] ?? '' ),
				'connection_title' => (string) ( $first_connection['connection_title'] ?? '' ),
			];
		}

		return [
			'type'             => '',
			'email'            => '',
			'id'               => '',
			'connection_title' => '',
		];
	}

	/**
	 * Whether the row being deleted is the only remaining SureContact
	 * connection for its workspace. Siblings share one api_key, so the SaaS-
	 * side revoke must wait until the last sender is gone.
	 *
	 * @param string $connection_id  The row being deleted.
	 * @param string $workspace_uuid The shared workspace UUID (may be empty on legacy rows).
	 * @return bool
	 */
	private function is_last_surecontact_sibling( $connection_id, $workspace_uuid ) {
		$connections = Settings::instance()->get_settings()['connections'] ?? [];
		if ( ! is_array( $connections ) ) {
			return true;
		}

		foreach ( $connections as $id => $connection ) {
			if (
				(string) $id === (string) $connection_id
				|| ! is_array( $connection )
				|| ( $connection['type'] ?? '' ) !== 'SURECONTACT'
			) {
				continue;
			}
			// A legacy row without a workspace_uuid can't be proven a sibling,
			// so treat any other SureContact row as a sibling in that case.
			if ( $workspace_uuid === '' || (string) ( $connection['workspace_uuid'] ?? '' ) === $workspace_uuid ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Best-effort call to SureContact's /disconnect endpoint to revoke the
	 * connection's API key server-side. Runs synchronously with a short timeout
	 * so the request actually reaches Laravel — `blocking: false` was unreliable
	 * on some PHP-FPM setups. Response is intentionally discarded; failures
	 * never block the local delete.
	 *
	 * @param string $api_key The decrypted bearer token.
	 * @return void
	 */
	private function revoke_surecontact_key( $api_key ) {
		if ( $api_key === '' ) {
			return;
		}

		wp_remote_post(
			rtrim( SurecontactHandler::api_base(), '/' ) . '/suremails/disconnect',
			[
				'headers' => [
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				],
				'timeout' => 5,
			]
		);
	}
}

// Initialize the DeleteConnection singleton.
DeleteConnection::instance();
