<?php
/**
 * SaveTestConnection class
 *
 * Handles the REST API endpoint for testing and saving email connection settings.
 *
 * @package SureMails\Inc\API
 */

namespace SureMails\Inc\API;

use SureMails\Inc\Analytics\Analytics;
use SureMails\Inc\Emails\Handler\ConnectionHandlerFactory;
use SureMails\Inc\Providers;
use SureMails\Inc\Settings;
use SureMails\Inc\Traits\Instance;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class SaveTestConnection
 *
 * Handles the `/test-and-save-email-connection` REST API endpoint.
 */
class SaveTestConnection extends Api_Base {
	use Instance;

	/**
	 * Indicates whether the current flow is for saving a connection.
	 * When simulation mode is enabled, this flag ensures that the Connection  Handler Factory returns the appropriate handler for performing the save operation.
	 *
	 * @since 1.5.0
	 * @var bool True if saving a connection, false otherwise.
	 */
	public $saving_connection = false;

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '/test-and-save-email-connection';

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
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'handle_test_save_email_connection' ],
					'permission_callback' => [ $this, 'validate_permission' ],
				],
			]
		);
	}

	/**
	 * Handle testing and saving the email connection settings.
	 *
	 * This method will:
	 *  - Retrieve the provider-specific fields via Providers::get_provider_options().
	 *  - Validate that all required fields are present.
	 *  - Prepare the connection data.
	 *  - Check for priority uniqueness.
	 *  - Authenticate the connection using the appropriate handler.
	 *  - Save the connection settings.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request object.
	 * @return WP_REST_Response The REST API response.
	 */
	public function handle_test_save_email_connection( $request ) {
		try {
			$params                  = $request->get_json_params();
			$settings                = $params['settings'] ?? [];
			$provider                = strtoupper( sanitize_text_field( $params['provider'] ?? '' ) );
			$this->saving_connection = true;

			$options = Providers::instance()->get_provider_options( $provider );

			if ( null === $options ) {
				return new WP_REST_Response(
					[
						'success' => false,
						'message' => __( 'Unsupported connection type.', 'suremails' ),
					],
					400
				);
			}

			$fields = $options['fields'] ?? [];

			// SureContact's account identity is platform-owned. On UPDATE the
			// React form deliberately omits these fields (only the editable
			// allowlist is sent), so resolve the stored row up-front and
			// merge the locked values into $settings — this both satisfies
			// schema validation for required fields like from_email AND lets
			// the post-prepare restore below treat the stored values as
			// authoritative regardless of what was submitted.
			$surecontact_locked_fields = [
				'api_key',
				'workspace_uuid',
				'connection_uuid',
				'email_verified',
				'is_paid',
				'from_email',
				'force_from_email',
			];
			$existing_surecontact      = null;
			if ( $provider === 'SURECONTACT' && ! empty( $settings['id'] ) ) {
				$existing_surecontact = $this->get_all_connections()[ $settings['id'] ] ?? null;
				if ( is_array( $existing_surecontact ) ) {
					foreach ( $surecontact_locked_fields as $field ) {
						if ( isset( $existing_surecontact[ $field ] ) ) {
							$settings[ $field ] = $existing_surecontact[ $field ];
						}
					}
				}
			}

			$validation = $this->validate_schema_fields( $fields, $settings );
			if ( ! $validation['success'] ) {
				return new WP_REST_Response(
					[
						'success' => false,
						'message' => $validation['message'] ?? __( 'Validation failed.', 'suremails' ),
					],
					400
				);
			}

			// Prepare connection data (only the fields defined in the providier options).
			$connection_data = $this->prepare_connection_data( $fields, $settings );

			$connection_data['type'] = strtoupper( $provider );

			// Re-pin the locked fields from the stored row even after
			// prepare_connection_data has run. A request can't swap the
			// bearer (api_key/workspace) or aim sends at a different mailbox
			// by changing from_email — the OAuth flow is the only path that
			// mutates them.
			if ( is_array( $existing_surecontact ) ) {
				foreach ( $surecontact_locked_fields as $field ) {
					if ( isset( $existing_surecontact[ $field ] ) ) {
						$connection_data[ $field ] = $existing_surecontact[ $field ];
					}
				}
			}

			// SureContact connection policy:
			// - Free accounts: one connection per site (the original cap).
			// - Paid accounts: additional senders allowed. Each extra row is a
			// new from_email that shares the account's single api_key, added
			// in-place (no OAuth) — see the clone branch below.
			// Block a new insert (empty id, no OAuth token) only when one already
			// exists AND the account is not paid.
			$is_surecontact_add_sender = false;
			if ( $connection_data['type'] === 'SURECONTACT' && empty( $connection_data['id'] ) && $this->has_existing_surecontact() ) {
				if ( ! $this->existing_surecontact_is_paid() ) {
					return new WP_REST_Response(
						[
							'success' => false,
							'message' => __( 'SureContact is already connected. Upgrade to a paid plan to send from additional addresses.', 'suremails' ),
						],
						400
					);
				}

				// Paid: a no-OAuth insert is an "add sender" clone. Reuse the
				// existing account's credentials and only vary the from_email.
				if ( empty( $settings['oauth_token'] ) ) {
					$primary = $this->primary_surecontact_connection();
					foreach ( [ 'api_key', 'workspace_uuid', 'connection_uuid', 'email_verified', 'is_paid' ] as $shared_field ) {
						if ( isset( $primary[ $shared_field ] ) ) {
							$connection_data[ $shared_field ] = $primary[ $shared_field ];
						}
					}
					$is_surecontact_add_sender = true;
				}
			}

			// SureContact's OAuth-callback save and the "add sender" clone run
			// from screens that don't know the next free sequence (the onboarding
			// wizard, the drawer's auto-exchange path, the add-sender form). Pick
			// a non-colliding priority server-side instead of bouncing the user
			// with a duplicate-sequence error.
			if (
				$connection_data['type'] === 'SURECONTACT'
				&& empty( $connection_data['id'] )
				&& ( ! empty( $settings['oauth_token'] ) || $is_surecontact_add_sender )
			) {
				$connection_data['priority'] = $this->next_available_priority();
			}

			// Check for priority uniqueness.
			if ( isset( $connection_data['priority'] ) && ! $this->is_priority_unique( intval( $connection_data['priority'] ), (string) ( $connection_data['id'] ?? '' ) ) ) {
				return new WP_REST_Response(
					[
						'success' => false,
						'message' => sprintf(
							/* translators: %s: Connection priority */
							__( 'Connection Sequence %1$s is already assigned to another connection. Please choose a different sequence.', 'suremails' ),
							$connection_data['priority']
						),
					],
					400
				);
			}

			// SureContact UPDATEs that don't change OAuth credentials are
			// cosmetic — from_name, force_from_name, priority, title. The
			// api_key was just auto-restored from the stored row above; it's
			// already known-good. Re-calling authenticate() would re-hit
			// SureContact's API on every save with no signal change, and
			// fails the save when the SaaS is briefly unreachable. Require
			// the id to resolve to an existing row so a forged id can't slip
			// past authenticate() and write a junk SureContact connection.
			// Skip the SureContact API round-trip when there's no new credential
			// to validate: a cosmetic update of an existing row, or an
			// "add sender" clone that reuses the already-validated api_key.
			$skip_authentication = (
				$connection_data['type'] === 'SURECONTACT'
				&& empty( $settings['oauth_token'] )
				&& (
					( ! empty( $connection_data['id'] ) && is_array( $existing_surecontact ) )
					|| $is_surecontact_add_sender
				)
			);

			if ( ! $skip_authentication ) {
				$auth_result = $this->authenticate_connection( $connection_data );

				if ( $auth_result['success'] !== true ) {
					$status_code = $auth_result['error_code'] ?? 401;
					return new WP_REST_Response(
						[
							'success' => false,
							'message' => $auth_result['message'] ?? __( 'Failed to authenticate.', 'suremails' ), // @phpstan-ignore nullCoalesce.offset
						],
						$status_code
					);
				}
			} else {
				$auth_result = [ 'success' => true ];
			}

			// If the connection is authenticated, store the connection data.
			$add_extra_fields    = $this->add_extra_fields( $connection_data, $auth_result );
			$new_connection_data = $this->store_connection( $add_extra_fields );

			return new WP_REST_Response(
				[
					'success'    => true,
					'message'    => __( 'Connection authenticated and settings saved.', 'suremails' ),
					'connection' => $new_connection_data,
				],
				200
			);
		} catch ( \Exception $e ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'An error occurred: ', 'suremails' ) . $e->getMessage(),
				],
				500
			);
		} finally {
			// Reset the saving_connection flag.
			$this->saving_connection = false;
		}
	}

	/**
	 * Authenticate the email connection to ensure it is valid.
	 *
	 * @param array<string, string|int|bool> $connection_data The connection data.
	 * @return array{success: bool, message: string, error_code?: int} The result of the authentication process.
	 */
	public function authenticate_connection( array $connection_data ) {
		$handler = ConnectionHandlerFactory::create( $connection_data );

		if ( empty( $handler ) ) {
			return [
				'success'    => false,
				'message'    => __( 'Invalid connection type.', 'suremails' ),
				'error_code' => 400,
			];
		}

		$auth_result = $handler->authenticate();

		// Include an error code based on the specific error, if provided.
		if ( isset( $auth_result['success'] ) && ! $auth_result['success'] ) { // @phpstan-ignore isset.offset
			$auth_result['error_code'] = $auth_result['error_code'] ?? 401;
		}

		return $auth_result;
	}

	/**
	 * Get all connection details.
	 *
	 * @return array<string, array<string, string|int|bool>> The array of all connections.
	 */
	public function get_all_connections() {
		// Fetch the connections from the WordPress options.
		$connections = Settings::instance()->get_settings();
		// Ensure the returned value is an array, even if the option does not exist.
		return is_array( $connections['connections'] ) ? $connections['connections'] : [];
	}

	/**
	 * Store the email connection data in the database.
	 *
	 * @param array<string, string|int|bool> $connection_data The connection data.
	 * @return array<string, string|int|bool> The stored connection data.
	 */
	public function store_connection( array $connection_data ) {
		$options = Settings::instance()->get_settings();

		// Check if it's a new connection or an update.
		if ( isset( $connection_data['id'] ) && ! empty( $connection_data['id'] ) ) {
			// If it's an update, keep the existing `created_at` timestamp if it exists.
			if ( ! empty( $options['connections'][ $connection_data['id'] ]['created_at'] ) ) {
				$connection_data['created_at'] = $options['connections'][ $connection_data['id'] ]['created_at'];
			}
			if ( $options['default_connection']['id'] === $connection_data['id'] ) {
				$options['default_connection'] = [
					'type'             => $connection_data['type'],
					'email'            => $connection_data['from_email'],
					'id'               => $connection_data['id'],
					'connection_title' => $connection_data['connection_title'],
				];
			}
			$options['connections'][ $connection_data['id'] ] = $connection_data;
		} else {
			// For new connections, generate a unique ID and add a `created_at` timestamp.
			$connection_data['id'] = $this->generate_unique_id( $options['connections'] );
			// Store the timestamp in MySQL datetime format.
			$connection_data['created_at']                    = current_time( 'mysql' );
			$options['connections'][ $connection_data['id'] ] = $connection_data;

			$events = Analytics::events();
			if ( null !== $events ) {
				$events->track(
					'first_connection_added',
					SUREMAILS_VERSION,
					[ 'provider_type' => $connection_data['type'] ?? '' ]
				);
			}
		}

		// Set default connection if necessary.
		if ( count( $options['connections'] ) === 1 ) {
			$options['default_connection'] = [
				'type'             => $connection_data['type'],
				'email'            => $connection_data['from_email'] ?? '',
				'id'               => $connection_data['id'],
				'connection_title' => $connection_data['connection_title'] ?? '',
			];
		}

		// Update the options in the WordPress database.
		update_option( SUREMAILS_CONNECTIONS, Settings::instance()->encrypt_all( $options ) );

		return $connection_data;
	}

	/**
	 * Whether at least one SureContact connection already exists.
	 *
	 * @return bool
	 */
	public function has_existing_surecontact() {
		foreach ( $this->get_all_connections() as $existing_connection ) {
			if ( ( $existing_connection['type'] ?? '' ) === 'SURECONTACT' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether any existing SureContact connection belongs to a paid account.
	 * Paid status is account-level and mirrored onto every sibling row by the
	 * status sync, so the first paid row is enough to allow extra senders.
	 *
	 * @return bool
	 */
	public function existing_surecontact_is_paid() {
		foreach ( $this->get_all_connections() as $existing_connection ) {
			if ( ( $existing_connection['type'] ?? '' ) === 'SURECONTACT' && ! empty( $existing_connection['is_paid'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The primary SureContact row (lowest priority number) — the source of the
	 * shared api_key/workspace/connection identifiers cloned onto additional
	 * sender rows.
	 *
	 * @return array<string, string|int|bool>
	 */
	public function primary_surecontact_connection() {
		$primary = [];
		$lowest  = PHP_INT_MAX;
		foreach ( $this->get_all_connections() as $existing_connection ) {
			if ( ( $existing_connection['type'] ?? '' ) !== 'SURECONTACT' ) {
				continue;
			}
			$priority = intval( $existing_connection['priority'] ?? 0 );
			if ( $priority < $lowest ) {
				$lowest  = $priority;
				$primary = $existing_connection;
			}
		}
		return $primary;
	}

	/**
	 * Add extra fields to the connection data.
	 *
	 * @param array<string, string|int|bool> $connection_data The connection data.
	 * @param array<string, string|int|bool> $new_fields The new fields to add.
	 * @return array<string, string|int|bool> The updated connection data.
	 */
	protected function add_extra_fields( $connection_data, $new_fields ) {

		$providers = [
			'GMAIL',
			'ZOHO',
			'SURECONTACT',
		];

		if ( ! in_array( $connection_data['type'], $providers ) ) {
			return $connection_data;
		}

		if ( isset( $new_fields['refresh_token'] ) ) {
			$connection_data['refresh_token'] = $new_fields['refresh_token'];
		}
		if ( isset( $new_fields['access_token'] ) ) {
			$connection_data['access_token'] = $new_fields['access_token'];
		}
		if ( isset( $new_fields['expires_in'] ) ) {
			$connection_data['expires_in'] = $new_fields['expires_in'];
		}
		if ( isset( $new_fields['expire_stamp'] ) ) {
			$connection_data['expire_stamp'] = $new_fields['expire_stamp'];
		}

		if ( isset( $new_fields['account_id'] ) ) {
			$connection_data['account_id'] = $new_fields['account_id']; // zoho.
		}

		if ( ! empty( $new_fields['from_email'] ) ) {
			$connection_data['from_email'] = $new_fields['from_email']; // zoho, surecontact.
		}
		if ( ! empty( $new_fields['from_name'] ) ) {
			$connection_data['from_name'] = $new_fields['from_name']; // surecontact.
		}

		// SureContact: persist the api_key + workspace identifiers returned by the OAuth exchange.
		if ( isset( $new_fields['api_key'] ) ) {
			$connection_data['api_key'] = $new_fields['api_key'];
		}
		if ( isset( $new_fields['workspace_uuid'] ) ) {
			$connection_data['workspace_uuid'] = $new_fields['workspace_uuid'];
		}
		if ( isset( $new_fields['connection_uuid'] ) ) {
			$connection_data['connection_uuid'] = $new_fields['connection_uuid'];
		}
		if ( isset( $new_fields['email_verified'] ) ) {
			$connection_data['email_verified'] = (bool) $new_fields['email_verified'];
		}

		// Persist the account plan tier captured during the OAuth exchange so a
		// paid account can add a second sender immediately, without waiting for a
		// later status sync to mirror the flag onto the row.
		if ( isset( $new_fields['is_paid'] ) ) {
			$connection_data['is_paid'] = (bool) $new_fields['is_paid'];
		}

		unset( $connection_data['auth_code'] );
		unset( $connection_data['oauth_token'] );
		unset( $connection_data['oauth_state'] );

		return $connection_data;
	}

	/**
	 * Validate the provided settings against the provider's field schema.
	 *
	 * @param array<string, array<string, mixed>> $schema   The field definitions from the provider's options.
	 * @param array<string, string|int|bool>      $settings The submitted settings.
	 * @return array{success: bool, message?: string}
	 */
	private function validate_schema_fields( array $schema, array $settings ) {
		foreach ( $schema as $field => $rules ) {
			if ( ! empty( $rules['required'] ) ) {
				$value = trim( (string) ( $settings[ $field ] ?? '' ) );
				if ( '' === $value ) {
					return [
						'success' => false,
						'message' => __( 'Missing required field.', 'suremails' ),
					];
				}
			}
		}

		return [ 'success' => true ];
	}

	/**
	 * Prepare the connection data array based on the provider schema and submitted settings.
	 *
	 * Only fields defined in the schema are stored.
	 *
	 * @param array<string, array<string, mixed>> $schema   The provider's field definitions.
	 * @param array<string, string|int|bool>      $settings The settings array from the request.
	 * @return array<string, string|int|bool> The prepared connection data.
	 */
	private function prepare_connection_data( array $schema, array $settings ) {
		$data = [];
		foreach ( $schema as $field => $rules ) {
			if ( isset( $settings[ $field ] ) ) {
				$value = $settings[ $field ];
				switch ( $rules['datatype'] ) {
					case 'email':
						$data[ $field ] = sanitize_email( (string) $value );
						break;
					case 'int':
						$data[ $field ] = intval( $value );
						break;
					case 'boolean':
						$data[ $field ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
						break;
					case 'string':
					default:
						$data[ $field ] = sanitize_text_field( (string) $value );
						break;
				}
			} else {
				$data[ $field ] = '';
			}
		}

		if ( isset( $settings['force_from_name'] ) && $settings['force_from_name'] ) {
			$data['force_from_name'] = ! empty( $data['from_name'] );
		}

		if ( isset( $settings['force_from_email'] ) && $settings['force_from_email'] ) {
			$data['force_from_email'] = ! empty( $data['from_email'] );
		}

		// ID is set for updatesss.
		$data['id'] = $settings['id'] ?? '';

		return $data;
	}

	/**
	 * Check if the desired priority is unique among existing connections.
	 *
	 * @param int    $desired_priority The desired priority.
	 * @param string $current_id       The current connection ID (if updating).
	 * @return bool True if unique, false otherwise.
	 */
	private function is_priority_unique( int $desired_priority, string $current_id ) {
		$all_connections = $this->get_all_connections();

		foreach ( $all_connections as $existing_connection ) {
			if ( ! empty( $existing_connection['id'] ) && $existing_connection['id'] === $current_id ) {
				// Skip the current connection if updating.
				continue;
			}
			if ( intval( $existing_connection['priority'] ) === $desired_priority ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Pick the next free priority — `max + 1` over existing connections.
	 *
	 * @return int
	 */
	private function next_available_priority() {
		$max = 0;
		foreach ( $this->get_all_connections() as $existing_connection ) {
			$priority = intval( $existing_connection['priority'] ?? 0 );
			if ( $priority > $max ) {
				$max = $priority;
			}
		}

		return $max + 1;
	}

	/**
	 * Generates a unique ID for a connection.
	 *
	 * @param array<string, array<string, string|int|bool>> $existing_connections The list of existing connections.
	 * @return string The generated unique ID.
	 */
	private function generate_unique_id( array $existing_connections ) {
		do {
			$id = bin2hex( random_bytes( 16 ) ); // Generate a 32-character unique ID.
		} while ( $this->id_exists( $id, $existing_connections ) );

		return $id;
	}

	/**
	 * Checks if a connection ID already exists.
	 *
	 * @param string                                        $id The ID to check.
	 * @param array<string, array<string, string|int|bool>> $existing_connections The existing connections.
	 * @return bool True if exists, false otherwise.
	 */
	private function id_exists( string $id, array $existing_connections ) {
		foreach ( $existing_connections as $connection ) {
			if ( isset( $connection['id'] ) && $connection['id'] === $id ) {
				return true;
			}
		}
		return false;
	}
}

// Initialize the SaveTestConnection singleton.
SaveTestConnection::instance();
