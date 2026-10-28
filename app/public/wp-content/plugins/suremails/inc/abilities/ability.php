<?php
/**
 * Abilities API Runtime
 *
 * Contains execute callbacks and helpers for all SureMails abilities.
 *
 * @package SureMails\Inc\Abilities
 * @since 1.9.4
 */

namespace SureMails\Inc\Abilities;

use Exception;
use SureMails\Inc\API\SaveTestConnection;
use SureMails\Inc\ConnectionManager;
use SureMails\Inc\Controller\Logger;
use SureMails\Inc\DB\EmailLog;
use SureMails\Inc\Emails\ProviderHelper;
use SureMails\Inc\Providers;
use SureMails\Inc\Settings;
use SureMails\Inc\Traits\SendEmail;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ability
 *
 * Runtime class for SureMails Abilities API.
 */
class Ability {

	use SendEmail;

	/**
	 * Sensitive field keys to strip from connection data.
	 */
	private const SENSITIVE_FIELDS = [
		'api_key',
		'access_key',
		'secret_key',
		'password',
		'client_secret',
		'access_token',
		'refresh_token',
		'auth_code',
		'expires_in',
		'expire_stamp',
		'account_id',
		'server_token',
	];

	/**
	 * Parsed input data.
	 *
	 * @var array<string, string|int|float|bool|array<string, string|int|float|bool>>|false
	 */
	protected $input = false;

	/**
	 * Register ability categories.
	 *
	 * @return void
	 */
	public function register_categories() {
		wp_register_ability_category(
			'suremails',
			[
				'label'       => __( 'SureMail', 'suremails' ),
				'description' => __( 'Abilities for the SureMail email delivery plugin.', 'suremails' ),
			]
		);
	}

	/**
	 * Register all abilities.
	 *
	 * @return void
	 */
	public function register() {
		$abilities = ConfigAbility::get_abilities();

		foreach ( $abilities as $ability_name => $ability ) {
			wp_register_ability(
				$ability_name,
				[
					'label'               => $ability['label'],
					'description'         => $ability['description'],
					'category'            => $ability['category'],
					'input_schema'        => $ability['input_schema'],
					'output_schema'       => $ability['output_schema'],
					'execute_callback'    => $ability['execute_callback'],
					'permission_callback' => $ability['permission_callback'],
					'meta'                => $ability['meta'],
				]
			);
		}
	}

	// ============================================
	// Connections
	// ============================================

	/**
	 * List all connections.
	 *
	 * @param mixed $input Input data.
	 * @return array{connections: array<int, array<string, string|int|bool>>, total: int}|array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Result.
	 */
	public function list_connections( $input ) {
		try {
			$this->init( $input, SUREMAILS_ABILITY_API_NAMESPACE . 'list-connections' );

			$options     = Settings::instance()->get_settings();
			$connections = isset( $options['connections'] ) && is_array( $options['connections'] ) ? $options['connections'] : [];
			$type_filter = $this->input_get( 'type', '' );

			$result = [];
			foreach ( $connections as $connection ) {
				if ( ! empty( $type_filter ) && strtoupper( $type_filter ) !== strtoupper( $connection['type'] ?? '' ) ) {
					continue;
				}
				$result[] = $this->strip_sensitive_fields( $connection );
			}

			return [
				'connections' => $result,
				'total'       => count( $result ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get a single connection by ID.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, string|int|bool>|array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Result.
	 * @throws \Exception If connection ID is invalid.
	 */
	public function get_connection( $input ) {
		try {
			$this->init( $input, SUREMAILS_ABILITY_API_NAMESPACE . 'get-connection' );
			$id = $this->input_get( 'id' );

			if ( empty( $id ) ) {
				throw new Exception( esc_html__( 'Invalid connection ID.', 'suremails' ) );
			}

			$options     = Settings::instance()->get_settings();
			$connections = isset( $options['connections'] ) && is_array( $options['connections'] ) ? $options['connections'] : [];

			if ( ! isset( $connections[ $id ] ) ) {
				throw new Exception( esc_html__( 'Connection not found.', 'suremails' ) );
			}

			return $this->strip_sensitive_fields( $connections[ $id ] );
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get the default connection.
	 *
	 * @param mixed $input Input data.
	 * @return array{type: string, email: string, id: string, connection_title: string}|array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Result.
	 */
	public function get_default_connection( $input ) {
		try {
			$this->init( $input, SUREMAILS_ABILITY_API_NAMESPACE . 'get-default-connection' );

			$options = Settings::instance()->get_settings();
			if ( isset( $options['default_connection'] ) && is_array( $options['default_connection'] ) ) {
				$connection = $options['default_connection'];

				return [
					'type'             => (string) ( $connection['type'] ?? '' ),
					'email'            => (string) ( $connection['email'] ?? '' ),
					'id'               => (string) ( $connection['id'] ?? '' ),
					'connection_title' => (string) ( $connection['connection_title'] ?? '' ),
				];
			}

			return [
				'type'             => '',
				'email'            => '',
				'id'               => '',
				'connection_title' => '',
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Delete a connection by ID.
	 *
	 * @param mixed $input Input data.
	 * @return array{id: string, message: string, default_connection: array{type: string, email: string, id: string, connection_title: string}}|array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Result.
	 * @throws \Exception If connection ID is invalid.
	 */
	public function delete_connection( $input ) {
		try {
			$this->init( $input, SUREMAILS_ABILITY_API_NAMESPACE . 'delete-connection' );
			$id = $this->input_get( 'id' );

			if ( empty( $id ) ) {
				throw new Exception( esc_html__( 'Invalid connection ID.', 'suremails' ) );
			}

			$options = Settings::instance()->get_raw_settings();

			if ( ! isset( $options['connections'] ) || ! is_array( $options['connections'] ) ) {
				throw new Exception( esc_html__( 'No connections found.', 'suremails' ) );
			}

			if ( ! isset( $options['connections'][ $id ] ) ) {
				throw new Exception( esc_html__( 'Connection not found.', 'suremails' ) );
			}

			$deleted_connection = $options['connections'][ $id ];
			unset( $options['connections'][ $id ] );

			// Handle default connection reassignment.
			if (
				isset( $options['default_connection']['id'] ) &&
				$options['default_connection']['id'] === $id
			) {
				$options['default_connection'] = $this->get_highest_priority_connection( $options['connections'] );
			}

			update_option( SUREMAILS_CONNECTIONS, $options );

			return [
				'id'                 => $id,
				'message'            => esc_html__( 'Connection deleted successfully.', 'suremails' ),
				'default_connection' => $options['default_connection'],
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Add a new email connection.
	 *
	 * @param mixed $input Input data.
	 * @return array{success: bool, message: string, connection: array<string, string|int|bool>}|array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Result.
	 * @throws \Exception If type is unsupported, OAuth, or validation fails.
	 */
	public function add_connection( $input ) {
		try {
			$this->init( $input, SUREMAILS_ABILITY_API_NAMESPACE . 'add-connection' );

			$type              = strtoupper( sanitize_text_field( $this->input_get( 'type' ) ) );
			$provider_settings = $this->input_get( 'provider_settings' );

			if ( empty( $type ) ) {
				throw new Exception( esc_html__( 'Provider type is required.', 'suremails' ) );
			}

			// Reject OAuth providers.
			$oauth_providers = [ 'GMAIL', 'OUTLOOK', 'ZOHO' ];
			if ( in_array( $type, $oauth_providers, true ) ) {
				throw new Exception(
					sprintf(
						/* translators: %s: provider type */
						esc_html__( '%s requires browser-based OAuth authentication and cannot be added via this ability.', 'suremails' ),
						esc_html( $type )
					)
				);
			}

			// Get provider schema.
			$provider_options = Providers::instance()->get_provider_options( $type );
			if ( null === $provider_options ) {
				throw new Exception(
					sprintf(
						/* translators: %s: provider type */
						esc_html__( 'Unsupported provider type: %s.', 'suremails' ),
						esc_html( $type )
					)
				);
			}

			if ( ! is_array( $provider_settings ) ) {
				throw new Exception( esc_html__( 'provider_settings must be an object.', 'suremails' ) );
			}

			$fields = $provider_options['fields'] ?? [];

			// Auto-assign priority if not provided.
			if ( empty( $provider_settings['priority'] ) ) {
				$options     = Settings::instance()->get_settings();
				$connections = isset( $options['connections'] ) && is_array( $options['connections'] ) ? $options['connections'] : [];
				$max         = 0;
				foreach ( $connections as $conn ) {
					$p = intval( $conn['priority'] ?? 0 );
					if ( $p > $max ) {
						$max = $p;
					}
				}
				$provider_settings['priority'] = $max + 1;
			}

			// Validate required fields.
			foreach ( $fields as $field_name => $rules ) {
				if ( ! empty( $rules['required'] ) ) {
					$value = trim( (string) ( $provider_settings[ $field_name ] ?? '' ) );
					if ( '' === $value ) {
						throw new Exception(
							sprintf(
								/* translators: %s: field name */
								esc_html__( 'Missing required field: %s.', 'suremails' ),
								esc_html( $rules['label'] ?? $field_name )
							)
						);
					}
				}
			}

			// Sanitize fields by datatype.
			$data = [];
			foreach ( $fields as $field_name => $rules ) {
				if ( isset( $provider_settings[ $field_name ] ) ) {
					$value    = $provider_settings[ $field_name ];
					$datatype = $rules['datatype'] ?? 'string';
					switch ( $datatype ) {
						case 'email':
							$data[ $field_name ] = sanitize_email( $value );
							break;
						case 'int':
							$data[ $field_name ] = intval( $value );
							break;
						case 'boolean':
							$data[ $field_name ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
							break;
						case 'string':
						default:
							$data[ $field_name ] = sanitize_text_field( $value );
							break;
					}
				} else {
					$data[ $field_name ] = '';
				}
			}

			// Handle force_from_name / force_from_email boolean logic.
			if ( ! empty( $provider_settings['force_from_name'] ) ) {
				$data['force_from_name'] = ! empty( $data['from_name'] );
			}
			if ( ! empty( $provider_settings['force_from_email'] ) ) {
				$data['force_from_email'] = ! empty( $data['from_email'] );
			}

			$data['type'] = $type;
			$data['id']   = ''; // New connection.

			$save_test = SaveTestConnection::instance();

			// Set saving_connection flag to bypass simulation mode.
			$save_test->saving_connection = true;

			try {
				// Authenticate.
				$auth_result = $save_test->authenticate_connection( $data );
				if ( empty( $auth_result['success'] ) ) {
					throw new Exception( $auth_result['message'] );
				}

				// Store.
				$stored = $save_test->store_connection( $data );
			} finally {
				$save_test->saving_connection = false;
			}

			return [
				'success'    => true,
				'message'    => esc_html__( 'Connection created and authenticated successfully.', 'suremails' ),
				'connection' => $this->strip_sensitive_fields( $stored ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Update a connection's non-sensitive fields.
	 *
	 * @param mixed $input Input data.
	 * @return array{success: bool, message: string}|array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Result.
	 * @throws \Exception If connection ID is invalid or not found.
	 */
	public function update_connection( $input ) {
		try {
			$this->init( $input, SUREMAILS_ABILITY_API_NAMESPACE . 'update-connection' );
			$id = $this->input_get( 'id' );

			if ( empty( $id ) ) {
				throw new Exception( esc_html__( 'Invalid connection ID.', 'suremails' ) );
			}

			$options = Settings::instance()->get_raw_settings();

			if ( ! isset( $options['connections'] ) || ! is_array( $options['connections'] ) ) {
				throw new Exception( esc_html__( 'No connections found.', 'suremails' ) );
			}

			if ( ! isset( $options['connections'][ $id ] ) ) {
				throw new Exception( esc_html__( 'Connection not found.', 'suremails' ) );
			}

			$connection = $options['connections'][ $id ];
			$updated    = false;

			$from_email = $this->input_get( 'from_email', '' );
			if ( ! empty( $from_email ) ) {
				$connection['from_email'] = sanitize_email( $from_email );
				$updated                  = true;
			}

			$from_name = $this->input_get( 'from_name', '' );
			if ( ! empty( $from_name ) ) {
				$connection['from_name'] = sanitize_text_field( $from_name );
				$updated                 = true;
			}

			$connection_title = $this->input_get( 'connection_title', '' );
			if ( ! empty( $connection_title ) ) {
				$connection['connection_title'] = sanitize_text_field( $connection_title );
				$updated                        = true;
			}

			$force_from_email = $this->input_get( 'force_from_email', '' );
			if ( '' !== $force_from_email ) {
				$connection['force_from_email'] = (bool) $force_from_email;
				$updated                        = true;
			}

			$force_from_name = $this->input_get( 'force_from_name', '' );
			if ( '' !== $force_from_name ) {
				$connection['force_from_name'] = (bool) $force_from_name;
				$updated                       = true;
			}

			if ( ! $updated ) {
				return [
					'success' => true,
					'message' => esc_html__( 'No changes made to connection.', 'suremails' ),
				];
			}

			$options['connections'][ $id ] = $connection;

			// Update default connection info if this is the default.
			if (
				isset( $options['default_connection']['id'] ) &&
				$options['default_connection']['id'] === $id
			) {
				$options['default_connection']['email']            = $connection['from_email'] ?? '';
				$options['default_connection']['connection_title'] = $connection['connection_title'] ?? '';
			}

			update_option( SUREMAILS_CONNECTIONS, $options );

			return [
				'success'    => true,
				'message'    => esc_html__( 'Connection updated successfully.', 'suremails' ),
				'connection' => $this->strip_sensitive_fields( $connection ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Send a test email through a specific connection.
	 *
	 * @param mixed $input Input data.
	 * @return array{success: bool, message: string}|array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Result.
	 * @throws \Exception If required fields are missing or send fails.
	 */
	public function send_test_email( $input ) {
		try {
			$this->init( $input, SUREMAILS_ABILITY_API_NAMESPACE . 'send-test-email' );

			$connection_id = $this->input_get( 'connection_id' );
			$to_email      = $this->input_get( 'to_email' );

			if ( empty( $connection_id ) || empty( $to_email ) ) {
				throw new Exception( esc_html__( 'Connection ID and recipient email are required.', 'suremails' ) );
			}

			$options    = Settings::instance()->get_settings( 'connections' );
			$connection = $options[ $connection_id ] ?? null;

			if ( empty( $connection ) ) {
				throw new Exception( esc_html__( 'Connection not found.', 'suremails' ) );
			}

			$connection_manager = ConnectionManager::instance();
			$connection_manager->set_connection( $connection );
			$connection_manager->set_is_testing( true );

			$from_email = $connection['from_email'] ?? '';
			$headers    = [
				'From: ' . $from_email,
				'Content-Type: text/html; charset=UTF-8',
			];

			/* translators: %s: Site name */
			$subject = sprintf( __( 'SureMail: Test Email - %s', 'suremails' ), get_bloginfo( 'name' ) );
			$body    = '<p>' . esc_html__( 'This is a test email sent via the SureMail Abilities API to verify your connection is working correctly.', 'suremails' ) . '</p>';

			$sent = self::send( sanitize_email( $to_email ), $subject, $body, $headers, [] );

			return [
				'success' => (bool) $sent,
				'message' => $sent
					? esc_html__( 'Test email sent successfully.', 'suremails' )
					: esc_html__( 'Failed to send test email.', 'suremails' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	// ============================================
	// Email Logs
	// ============================================

	/**
	 * List email logs with filtering and pagination.
	 *
	 * @param mixed $input Input data.
	 * @return array{logs: array<int, array<string, mixed>>, total: int, total_pages: int}|array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Result.
	 */
	public function list_email_logs( $input ) {
		try {
			$this->init( $input, SUREMAILS_ABILITY_API_NAMESPACE . 'list-email-logs' );

			$page       = $this->clamp_page( $this->input_get( 'page' ) );
			$per_page   = $this->clamp_per_page( $this->input_get( 'per_page' ) );
			$status     = $this->input_get( 'status', '' );
			$start_date = $this->input_get( 'start_date', '' );
			$end_date   = $this->input_get( 'end_date', '' );
			$search     = $this->input_get( 'search', '' );

			$email_log = EmailLog::instance();

			// Build where clause.
			$where = [];

			if ( ! empty( $status ) && in_array( $status, $email_log->get_statuses(), true ) ) {
				$where['status'] = $status;
			}

			if ( ! empty( $start_date ) ) {
				$start_datetime = new \DateTime( $start_date );
				$start_datetime->setTime( 0, 0, 0 );
				$where['updated_at >='] = $start_datetime->format( 'Y-m-d H:i:s' );

				if ( ! empty( $end_date ) ) {
					$end_datetime = new \DateTime( $end_date );
				} else {
					$end_datetime = clone $start_datetime;
				}
				$end_datetime->setTime( 23, 59, 59 );
				$where['updated_at <='] = $end_datetime->format( 'Y-m-d H:i:s' );
			}

			if ( ! empty( $search ) ) {
				$where['subject LIKE']     = '%' . $search . '%';
				$where['OR email_to LIKE'] = '%' . $search . '%';
			}

			$offset = ( $page - 1 ) * $per_page;

			$logs = $email_log->get(
				[
					'where'  => $where,
					'order'  => [ 'updated_at' => 'DESC' ],
					'limit'  => $per_page,
					'offset' => $offset,
				]
			);

			// Get total count.
			$count_results = $email_log->get(
				[
					'select' => 'COUNT(*) as total_count',
					'where'  => $where,
				]
			);

			$total = 0;
			if ( is_array( $count_results ) && ! empty( $count_results ) ) {
				$total = (int) ( $count_results[0]['total_count'] ?? 0 );
			}

			return [
				'logs'        => is_array( $logs ) ? $logs : [],
				'total'       => $total,
				'total_pages' => (int) ceil( $total / $per_page ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Get a single email log by ID.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, int|string|array<int|string, int|string>>|array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Result.
	 * @throws \Exception If log ID is invalid.
	 */
	public function get_email_log( $input ) {
		try {
			$this->init( $input, SUREMAILS_ABILITY_API_NAMESPACE . 'get-email-log' );
			$id = $this->input_get( 'id' );

			if ( $id === 0 ) {
				throw new Exception( esc_html__( 'Invalid log ID.', 'suremails' ) );
			}

			$logs = EmailLog::instance()->get(
				[
					'where' => [ 'id' => $id ],
					'limit' => 1,
				]
			);

			if ( empty( $logs ) ) {
				throw new Exception( esc_html__( 'Email log not found.', 'suremails' ) );
			}

			return $logs[0];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Delete email logs by IDs.
	 *
	 * @param mixed $input Input data.
	 * @return array{deleted_count: int, message: string}|array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Result.
	 * @throws \Exception If log IDs are missing or invalid.
	 */
	public function delete_email_logs( $input ) {
		try {
			$this->init( $input, SUREMAILS_ABILITY_API_NAMESPACE . 'delete-email-logs' );
			$log_ids = $this->input_get( 'log_ids' );

			if ( empty( $log_ids ) || ! is_array( $log_ids ) ) {
				throw new Exception( esc_html__( 'Log IDs array is required.', 'suremails' ) );
			}

			$log_ids   = array_map( 'absint', $log_ids );
			$email_log = EmailLog::instance();

			// Fetch logs to delete for attachment cleanup.
			$logs_to_delete = $email_log->get(
				[
					'select' => 'attachments',
					'where'  => [ 'id IN' => $log_ids ],
				]
			);

			if ( is_array( $logs_to_delete ) ) {
				$extracted            = ProviderHelper::extract_log_data( $logs_to_delete );
				$all_attachments_list = $extracted['attachments'];

				$where              = ProviderHelper::build_attachment_like_conditions( $all_attachments_list );
				$where['id NOT IN'] = $log_ids;

				$logs_to_retain   = $email_log->get(
					[
						'select' => 'id, attachments',
						'where'  => $where,
					]
				);
				$attachments_kept = [];
				if ( ! empty( $logs_to_retain ) ) {
					foreach ( $logs_to_retain as $log ) {
						if ( ! empty( $log['attachments'] ) ) {
							$attachments_kept = array_merge( $attachments_kept, (array) $log['attachments'] );
						}
					}
				}
				$attachments_kept = array_unique( $attachments_kept );

				ProviderHelper::delete_unused_attachments( $all_attachments_list, $attachments_kept );
			}

			$deleted_count = $email_log->delete( [ 'ids' => $log_ids ] );

			return [
				'deleted_count' => is_int( $deleted_count ) ? $deleted_count : 0,
				'message'       => sprintf(
					/* translators: %d: Number of logs deleted */
					esc_html__( '%d log(s) deleted successfully.', 'suremails' ),
					is_int( $deleted_count ) ? $deleted_count : 0
				),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Resend emails from log entries.
	 *
	 * @param mixed $input Input data.
	 * @return array{results: array<int, array{log_id: int, success: bool, message: string}>}|array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Result.
	 * @throws \Exception If log IDs are missing or invalid.
	 */
	public function resend_email( $input ) {
		try {
			$this->init( $input, SUREMAILS_ABILITY_API_NAMESPACE . 'resend-email' );
			$log_ids = $this->input_get( 'log_ids' );

			if ( empty( $log_ids ) || ! is_array( $log_ids ) ) {
				throw new Exception( esc_html__( 'Log IDs array is required.', 'suremails' ) );
			}

			$log_ids            = array_map( 'absint', $log_ids );
			$email_log          = EmailLog::instance();
			$logger             = Logger::instance();
			$connection_manager = ConnectionManager::instance();
			$results            = [];

			foreach ( $log_ids as $log_id ) {
				$logs = $email_log->get(
					[
						'where' => [ 'id' => $log_id ],
						'limit' => 1,
					]
				);

				if ( empty( $logs ) ) {
					$results[] = [
						'log_id'  => $log_id,
						'success' => false,
						'message' => esc_html__( 'Log not found.', 'suremails' ),
					];
					continue;
				}

				$log = $logs[0];
				/**
				 * The log email recipient.
				 *
				 * @var string $log_email_to
				 */
				$log_email_to = $log['email_to'];
				/**
				 * The log subject.
				 *
				 * @var string $log_subject
				 */
				$log_subject = $log['subject'];
				/**
				 * The log body.
				 *
				 * @var string $log_body
				 */
				$log_body = $log['body'];
				/**
				 * The log headers.
				 *
				 * @var string|array<int, string> $log_headers
				 */
				$log_headers = $log['headers'];
				/**
				 * The log attachments.
				 *
				 * @var array<int, string> $log_attachments
				 */
				$log_attachments = $log['attachments'];

				$logger->set_id( $log_id );
				$connection_manager->set_is_resend( true );

				/**
				 * The send-to address.
				 *
				 * @var string $send_to
				 */
				$send_to    = maybe_unserialize( $log_email_to );
				$email_sent = self::send( $send_to, $log_subject, $log_body, $log_headers, $log_attachments );

				$results[] = [
					'log_id'  => $log_id,
					'success' => (bool) $email_sent,
					'message' => $email_sent
						? esc_html__( 'Email resent successfully.', 'suremails' )
						: esc_html__( 'Failed to resend email.', 'suremails' ),
				];
			}

			$connection_manager->set_is_resend( false );

			return [ 'results' => $results ];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	// ============================================
	// Analytics
	// ============================================

	/**
	 * Get email statistics for a date range.
	 *
	 * @param mixed $input Input data.
	 * @return array{total_sent: int, total_failed: int, chart_data: array<int, array<string, int|string|array<int|string, int|string>>>}|array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Result.
	 * @throws \Exception If start date is missing.
	 */
	public function get_email_stats( $input ) {
		try {
			$this->init( $input, SUREMAILS_ABILITY_API_NAMESPACE . 'get-email-stats' );

			$start_date = $this->input_get( 'start_date' );
			$end_date   = $this->input_get( 'end_date', '' );

			if ( empty( $start_date ) ) {
				throw new Exception( esc_html__( 'Start date is required.', 'suremails' ) );
			}

			$start_datetime = new \DateTime( $start_date );

			if ( ! empty( $end_date ) ) {
				$end_datetime = new \DateTime( $end_date );
			} else {
				$end_datetime = clone $start_datetime;
			}
			$end_datetime->setTime( 23, 59, 59 );

			$date_from = $start_datetime->format( 'Y-m-d H:i:s' );
			$date_to   = $end_datetime->format( 'Y-m-d H:i:s' );

			$email_log = EmailLog::instance();

			$chart_data = $email_log->get(
				[
					'select'   => "DATE(created_at) as created_at, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed",
					'where'    => [
						'created_at >=' => $date_from,
						'created_at <=' => $date_to,
					],
					'group_by' => 'DATE(created_at)',
					'order'    => [ 'created_at' => 'ASC' ],
				]
			);

			$total_counts = $email_log->get(
				[
					'select' => "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed",
					'where'  => [
						'created_at >=' => $date_from,
						'created_at <=' => $date_to,
					],
				]
			);

			return [
				'total_sent'   => (int) ( $total_counts[0]['total_sent'] ?? 0 ),
				'total_failed' => (int) ( $total_counts[0]['total_failed'] ?? 0 ),
				'chart_data'   => is_array( $chart_data ) ? $chart_data : [],
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	// ============================================
	// Settings & Configuration
	// ============================================

	/**
	 * Get all plugin settings.
	 *
	 * @param mixed $input Input data.
	 * @return array<string, string|int>|array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Result.
	 */
	public function get_settings( $input ) {
		try {
			$this->init( $input, SUREMAILS_ABILITY_API_NAMESPACE . 'get-settings' );

			$options = Settings::instance()->get_settings();
			$misc    = Settings::instance()->get_misc_settings();

			// Strip sensitive data from connections.
			$connections = [];
			if ( isset( $options['connections'] ) && is_array( $options['connections'] ) ) {
				foreach ( $options['connections'] as $conn ) {
					$connections[] = $this->strip_sensitive_fields( $conn );
				}
			}

			return [
				'default_connection'      => $options['default_connection'] ?? [
					'type'             => '',
					'email'            => '',
					'id'               => '',
					'connection_title' => '',
				],
				'log_emails'              => $options['log_emails'] ?? 'yes',
				'email_simulation'        => $options['email_simulation'] ?? 'no',
				'delete_email_logs_after' => $options['delete_email_logs_after'] ?? 'none',
				'email_summary_active'    => $misc['email_summary_active'] ?? 'yes',
				'email_summary_day'       => $misc['email_summary_day'] ?? 'monday',
				'show_in_sidebar'         => $misc['show_in_sidebar'] ?? 'no',
				'total_connections'       => count( $connections ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * Update plugin settings.
	 *
	 * @param mixed $input Input data.
	 * @return array{success: bool, message: string}|array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Result.
	 */
	public function update_settings( $input ) {
		try {
			$this->init( $input, SUREMAILS_ABILITY_API_NAMESPACE . 'update-settings' );

			$options    = Settings::instance()->get_raw_settings();
			$is_updated = false;

			// Update log_emails.
			$log_emails = $this->input_get( 'log_emails', '' );
			if ( ! empty( $log_emails ) ) {
				$options['log_emails'] = sanitize_text_field( $log_emails );
				$is_updated            = true;
			}

			// Update email_simulation.
			$email_simulation = $this->input_get( 'email_simulation', '' );
			if ( ! empty( $email_simulation ) ) {
				$options['email_simulation'] = sanitize_text_field( $email_simulation );
				$is_updated                  = true;
			}

			// Update delete_email_logs_after.
			$retention = $this->input_get( 'delete_email_logs_after', '' );
			if ( ! empty( $retention ) ) {
				$options['delete_email_logs_after'] = sanitize_text_field( $retention );
				$is_updated                         = true;
			}

			// Update misc settings.
			$summary_active = $this->input_get( 'email_summary_active', '' );
			if ( ! empty( $summary_active ) ) {
				Settings::instance()->update_misc_settings( 'email_summary_active', sanitize_text_field( $summary_active ) );
				$is_updated = true;
			}

			$summary_day = $this->input_get( 'email_summary_day', '' );
			if ( ! empty( $summary_day ) ) {
				Settings::instance()->update_misc_settings( 'email_summary_day', sanitize_text_field( $summary_day ) );
				$is_updated = true;
			}

			if ( $is_updated ) {
				update_option( SUREMAILS_CONNECTIONS, $options );
			}

			return [
				'success' => true,
				'message' => $is_updated
					? esc_html__( 'Settings updated successfully.', 'suremails' )
					: esc_html__( 'No changes made to settings.', 'suremails' ),
			];
		} catch ( Exception $e ) {
			return $this->error( $e );
		}
	}

	// ============================================
	// Helper Methods
	// ============================================

	/**
	 * Initialize input parsing.
	 *
	 * @param mixed  $input        Input data.
	 * @param string $ability_name Ability identifier.
	 * @return void
	 */
	public function init( $input, $ability_name ) {
		$this->input_parse( $input, $ability_name );
	}

	/**
	 * Check user capabilities.
	 *
	 * @param string|array<int, string> $caps Single capability or array of capabilities (AND logic).
	 * @return bool True if user has required capabilities.
	 */
	public function permission_callback( $caps ) {
		if ( empty( $caps ) ) {
			return false;
		}

		$user = wp_get_current_user();
		if ( 0 === $user->ID ) {
			return false;
		}

		if ( is_string( $caps ) ) {
			return $user->has_cap( $caps );
		}

		foreach ( $caps as $cap ) {
			if ( ! $user->has_cap( $cap ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Parse and validate input against schema.
	 *
	 * @param mixed  $input        Raw input.
	 * @param string $ability_name Ability identifier.
	 * @return array<string, string|int|float|bool|array<string, string|int|float|bool>> Parsed input.
	 *
	 * @throws Exception If required field is missing or value is invalid.
	 */
	public function input_parse( $input, $ability_name ) {
		$this->input = [];

		if ( is_a( $input, 'WP_REST_Request' ) ) {
			$input = $input->get_json_params();
			if ( ! is_array( $input ) ) {
				$input = [];
			}
		}

		if ( ! is_array( $input ) ) {
			$input = [];
		}

		$input_schema = ConfigAbility::get_ability_input_schema( $ability_name );
		if ( ! is_array( $input_schema ) || empty( $input_schema ) ) {
			return [];
		}

		if ( ! isset( $input_schema['properties'] ) || ! is_array( $input_schema['properties'] ) ) {
			return [];
		}

		$required_fields = isset( $input_schema['required'] ) && is_array( $input_schema['required'] )
			? $input_schema['required']
			: [];

		foreach ( $input_schema['properties'] as $name => $prop ) {
			$type      = isset( $prop['type'] ) ? strtolower( $prop['type'] ) : 'string';
			$raw_value = array_key_exists( $name, $input ) ? $input[ $name ] : null;

			$is_required = in_array( $name, $required_fields, true );
			if ( $is_required && ( $raw_value === null || $raw_value === '' ) ) {
				throw new Exception(
					sprintf(
						/* translators: %s: field name */
						esc_html__( 'Required field %s is missing.', 'suremails' ),
						esc_html( $name )
					)
				);
			}

			if ( $raw_value === null && isset( $prop['default'] ) ) {
				$raw_value = $prop['default'];
			}

			if ( $raw_value === null ) {
				switch ( $type ) {
					case 'integer':
						$raw_value = 0;
						break;
					case 'number':
						$raw_value = 0.0;
						break;
					case 'boolean':
						$raw_value = false;
						break;
					case 'array':
						$raw_value = [];
						break;
					default:
						$raw_value = '';
						break;
				}
			}

			$value = $raw_value;

			switch ( $type ) {
				case 'integer':
					$value = intval( $value );
					break;
				case 'number':
					$value = floatval( $value );
					break;
				case 'boolean':
					$value = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
					break;
				case 'string':
					$value = is_string( $value ) ? sanitize_text_field( $value ) : sanitize_text_field( strval( $value ) );
					break;
				case 'array':
					if ( ! is_array( $value ) ) {
						$value = [];
					}
					$value = $this->sanitize_recursive( $value );
					break;
			}

			if ( isset( $prop['enum'] ) && is_array( $prop['enum'] ) ) { // @phpstan-ignore booleanAnd.rightAlwaysFalse
				// Skip enum validation for empty/default values on non-required fields.
				$skip_enum = ! $is_required && $value === '';
				if ( ! $skip_enum && ! in_array( $value, $prop['enum'], true ) ) {
					throw new Exception(
						sprintf(
							/* translators: %s: field name */
							esc_html__( 'Invalid value for %s.', 'suremails' ),
							esc_html( $name )
						)
					);
				}
			}

			$this->input[ $name ] = $value;
		}

		return $this->input;
	}

	/**
	 * Get a parsed input value.
	 *
	 * @param string $name    Property name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 *
	 * @throws Exception If inputs not parsed or property not found.
	 */
	public function input_get( $name, $default = '{__NO_DEFAULT__}' ) {
		if ( $this->input === false ) {
			throw new Exception( esc_html__( 'Inputs not parsed.', 'suremails' ) );
		}

		if ( ! array_key_exists( $name, $this->input ) ) {
			if ( '{__NO_DEFAULT__}' !== $default ) {
				return $default;
			}
			throw new Exception(
				sprintf(
					/* translators: %s: property name */
					esc_html__( 'Property %s not found.', 'suremails' ),
					esc_html( $name )
				)
			);
		}

		return $this->input[ $name ];
	}

	/**
	 * Format error response.
	 *
	 * @param Exception $e The exception.
	 * @return array{error: array{code: string, message: string, debug?: array{file: string, line: int}}} Error response.
	 */
	public function error( $e ) {
		$error = [
			'error' => [
				'code'    => 'suremails_error',
				'message' => esc_html( $e->getMessage() ),
			],
		];

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$error['error']['debug'] = [
				'file' => $e->getFile(),
				'line' => $e->getLine(),
			];
		}

		return $error;
	}

	/**
	 * Recursively sanitize array values.
	 *
	 * @param array<string, string|int|float|bool|array<string, string|int|float|bool>> $data Data to sanitize.
	 * @return array<string, string|int|float|bool|array<string, string|int|float|bool>> Sanitized data.
	 */
	protected function sanitize_recursive( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$sanitized = [];
		foreach ( $data as $key => $value ) {
			$key = sanitize_text_field( strval( $key ) );
			if ( is_array( $value ) ) {
				/**
				 * The sanitized value.
				 *
				 * @var array<string, string|int|float|bool> $sanitized_value
				 */
				$sanitized_value   = $this->sanitize_recursive( $value );
				$sanitized[ $key ] = $sanitized_value;
			} elseif ( is_string( $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( $value );
			} elseif ( is_int( $value ) ) {
				$sanitized[ $key ] = intval( $value );
			} elseif ( is_float( $value ) ) {
				$sanitized[ $key ] = floatval( $value );
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $key ] = (bool) $value;
			} else {
				$sanitized[ $key ] = $value;
			}
		}
		return $sanitized;
	}

	/**
	 * Clamp per_page to safe bounds.
	 *
	 * @param int $per_page Raw per_page value.
	 * @param int $max      Maximum allowed.
	 * @return int Clamped value (minimum 1).
	 */
	protected function clamp_per_page( $per_page, $max = 100 ) {
		return max( 1, min( intval( $per_page ), $max ) );
	}

	/**
	 * Clamp page to minimum 1.
	 *
	 * @param int $page Raw page value.
	 * @return int Clamped value (minimum 1).
	 */
	protected function clamp_page( $page ) {
		return max( 1, intval( $page ) );
	}

	/**
	 * Strip sensitive fields from a connection array.
	 *
	 * @param array<string, string|int|bool> $connection Connection data.
	 * @return array<string, string|int|bool> Sanitized connection.
	 */
	private function strip_sensitive_fields( $connection ) {
		if ( ! is_array( $connection ) ) {
			return [];
		}

		foreach ( self::SENSITIVE_FIELDS as $field ) {
			unset( $connection[ $field ] );
		}

		return $connection;
	}

	/**
	 * Get the connection with the highest priority (lowest number).
	 *
	 * @param array<string, array<string, string|int|bool>> $connections Array of connections.
	 * @return array{type: string, email: string, id: string, connection_title: string} Default connection structure.
	 */
	private function get_highest_priority_connection( $connections ) {
		$empty = [
			'type'             => '',
			'email'            => '',
			'id'               => '',
			'connection_title' => '',
		];

		if ( empty( $connections ) ) {
			return $empty;
		}

		uasort(
			$connections,
			static function ( $a, $b ) {
				return intval( $a['priority'] ?? 0 ) - intval( $b['priority'] ?? 0 );
			}
		);

		$first = reset( $connections );
		if ( ! $first ) {
			return $empty;
		}

		return [
			'type'             => (string) ( $first['type'] ?? '' ),
			'email'            => (string) ( $first['from_email'] ?? '' ),
			'id'               => (string) ( $first['id'] ?? '' ),
			'connection_title' => (string) ( $first['connection_title'] ?? '' ),
		];
	}
}
