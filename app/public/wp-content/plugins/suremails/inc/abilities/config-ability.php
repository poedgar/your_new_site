<?php
/**
 * Abilities API Configuration
 *
 * Defines all ability configurations for the SureMails plugin.
 *
 * @package SureMails\Inc\Abilities
 * @since 1.9.4
 */

namespace SureMails\Inc\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConfigAbility
 *
 * Holds all ability definitions for SureMails.
 */
class ConfigAbility {

	/**
	 * Cached abilities array.
	 *
	 * @var array<string, array{label: string, description: string, category: string, permission_callback: callable, input_schema: array<string, string|array<int, string>|array<string, array<string, string>>>, output_schema: array<string, string|array<string, array<string, string|array<string, string>>>>, execute_callback: callable, meta: array<string, array<string, bool|float|string>>}>|false
	 */
	public static $abilities = false;

	/**
	 * Get all ability configurations.
	 *
	 * @return array<string, array{label: string, description: string, category: string, permission_callback: callable, input_schema: array<string, string|array<int, string>|array<string, array<string, string>>>, output_schema: array<string, string|array<string, array<string, string|array<string, string>>>>, execute_callback: callable, meta: array<string, array<string, bool|float|string>>}> Ability definitions.
	 */
	public static function get_abilities() {

		if ( self::$abilities !== false ) {
			return self::$abilities;
		}

		$ability = new Ability();

		$abilities = [

			// ============================================
			// Connections
			// ============================================

			SUREMAILS_ABILITY_API_NAMESPACE . 'list-connections' => [
				'label'               => __( 'List connections', 'suremails' ),
				'description'         => __( 'Lists every configured email-sending connection on this WordPress site. Returns each connection\'s ID, provider type (e.g. SMTP, SENDGRID, PHPMAIL), sender email, display name, title, priority/sequence number, force-from flags, and creation date. Sensitive credentials (API keys, passwords, tokens) are never included in the response. Optionally filter by provider type. Use this to discover available connections before sending test emails or managing connections.', 'suremails' ),
				'category'            => 'suremails',
				'permission_callback' => static function () use ( $ability ) {
					return $ability->permission_callback( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'type' => [
							'type'        => 'string',
							'description' => __( 'Filter by provider type (e.g., SMTP, SENDGRID, GMAIL).', 'suremails' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'connections' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'               => [
										'type'        => 'string',
										'description' => __( 'Connection ID.', 'suremails' ),
									],
									'type'             => [
										'type'        => 'string',
										'description' => __( 'Provider type.', 'suremails' ),
									],
									'from_email'       => [
										'type'        => 'string',
										'description' => __( 'Sender email.', 'suremails' ),
									],
									'connection_title' => [
										'type'        => 'string',
										'description' => __( 'Connection title.', 'suremails' ),
									],
									'priority'         => [
										'type'        => 'integer',
										'description' => __( 'Connection priority/sequence.', 'suremails' ),
									],
									'from_name'        => [
										'type'        => 'string',
										'description' => __( 'Sender name.', 'suremails' ),
									],
									'force_from_email' => [
										'type'        => 'boolean',
										'description' => __( 'Force from email.', 'suremails' ),
									],
									'force_from_name'  => [
										'type'        => 'boolean',
										'description' => __( 'Force from name.', 'suremails' ),
									],
									'created_at'       => [
										'type'        => 'string',
										'description' => __( 'Creation date.', 'suremails' ),
									],
								],
							],
						],
						'total'       => [
							'type'        => 'integer',
							'description' => __( 'Total connections count.', 'suremails' ),
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					return $ability->list_connections( $input );
				},
				'meta'                => [
					'annotations' => [
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
						'instructions'    => __( 'Safe to call at any time. This is a read-only operation that returns no sensitive data. Use this first when the user asks about their email setup, before performing any connection modifications.', 'suremails' ),
					],
					'mcp'         => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			],

			SUREMAILS_ABILITY_API_NAMESPACE . 'get-connection' => [
				'label'               => __( 'Get connection details', 'suremails' ),
				'description'         => __( 'Retrieves the full details of a single email connection by its unique ID. Returns the provider type, sender email, display name, title, priority, force-from flags, and creation date. Sensitive credentials (API keys, passwords, OAuth tokens) are stripped from the response and will never be exposed. Returns an error if the connection ID does not exist.', 'suremails' ),
				'category'            => 'suremails',
				'permission_callback' => static function () use ( $ability ) {
					return $ability->permission_callback( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id' => [
							'type'        => 'string',
							'description' => __( 'The connection ID. Obtain this from list-connections.', 'suremails' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'               => [
							'type'        => 'string',
							'description' => __( 'Connection ID.', 'suremails' ),
						],
						'type'             => [
							'type'        => 'string',
							'description' => __( 'Provider type.', 'suremails' ),
						],
						'from_email'       => [
							'type'        => 'string',
							'description' => __( 'Sender email.', 'suremails' ),
						],
						'connection_title' => [
							'type'        => 'string',
							'description' => __( 'Connection title.', 'suremails' ),
						],
						'priority'         => [
							'type'        => 'integer',
							'description' => __( 'Connection priority.', 'suremails' ),
						],
						'from_name'        => [
							'type'        => 'string',
							'description' => __( 'Sender name.', 'suremails' ),
						],
						'force_from_email' => [
							'type'        => 'boolean',
							'description' => __( 'Force from email.', 'suremails' ),
						],
						'force_from_name'  => [
							'type'        => 'boolean',
							'description' => __( 'Force from name.', 'suremails' ),
						],
						'created_at'       => [
							'type'        => 'string',
							'description' => __( 'Creation date.', 'suremails' ),
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					return $ability->get_connection( $input );
				},
				'meta'                => [
					'annotations' => [
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
						'instructions'    => __( 'Safe to call at any time. Read-only lookup. If the user asks about a specific connection, call list-connections first to find the ID, then use this for details.', 'suremails' ),
					],
					'mcp'         => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			],

			SUREMAILS_ABILITY_API_NAMESPACE . 'get-default-connection' => [
				'label'               => __( 'Get default connection', 'suremails' ),
				'description'         => __( 'Returns the currently active default email connection that WordPress uses to send all outgoing emails. The default connection is the one with the highest priority (lowest sequence number). If no connections are configured, all fields will be empty strings. Use this to quickly check which provider and email address the site is currently sending from.', 'suremails' ),
				'category'            => 'suremails',
				'permission_callback' => static function () use ( $ability ) {
					return $ability->permission_callback( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'type'             => [
							'type'        => 'string',
							'description' => __( 'Provider type.', 'suremails' ),
						],
						'email'            => [
							'type'        => 'string',
							'description' => __( 'Default sender email.', 'suremails' ),
						],
						'id'               => [
							'type'        => 'string',
							'description' => __( 'Connection ID.', 'suremails' ),
						],
						'connection_title' => [
							'type'        => 'string',
							'description' => __( 'Connection title.', 'suremails' ),
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					return $ability->get_default_connection( $input );
				},
				'meta'                => [
					'annotations' => [
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
						'instructions'    => __( 'Safe to call at any time. Read-only. Use this as a quick health-check to see which connection is actively sending emails. If the result has empty fields, no connections are configured and the user should add one.', 'suremails' ),
					],
					'mcp'         => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			],

			SUREMAILS_ABILITY_API_NAMESPACE . 'delete-connection' => [
				'label'               => __( 'Delete connection', 'suremails' ),
				'description'         => __( 'Permanently deletes an email connection by its ID. This cannot be undone — the connection and all its credentials are removed immediately. If the deleted connection was the site\'s default, the remaining connection with the highest priority (lowest sequence number) automatically becomes the new default. If this was the only connection, the site will have no email-sending capability until a new connection is added.', 'suremails' ),
				'category'            => 'suremails',
				'permission_callback' => static function () use ( $ability ) {
					return get_option( 'suremails_abilities_api_delete', false ) &&
						$ability->permission_callback( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id' => [
							'type'        => 'string',
							'description' => __( 'The connection ID to delete. Obtain this from list-connections.', 'suremails' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'                 => [
							'type'        => 'string',
							'description' => __( 'Deleted connection ID.', 'suremails' ),
						],
						'message'            => [
							'type'        => 'string',
							'description' => __( 'Result message.', 'suremails' ),
						],
						'default_connection' => [
							'type'       => 'object',
							'properties' => [
								'type'             => [ 'type' => 'string' ],
								'email'            => [ 'type' => 'string' ],
								'id'               => [ 'type' => 'string' ],
								'connection_title' => [ 'type' => 'string' ],
							],
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					return $ability->delete_connection( $input );
				},
				'meta'                => [
					'annotations' => [
						'priority'        => 3.0,
						'readOnlyHint'    => false,
						'destructiveHint' => true,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
						'instructions'    => __( 'DESTRUCTIVE — always ask the user to confirm before running this. Show them the connection title and email so they know exactly what will be deleted. Warn them that this cannot be undone and that deleting the only connection will stop all outgoing emails from the site.', 'suremails' ),
					],
					'mcp'         => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			],

			SUREMAILS_ABILITY_API_NAMESPACE . 'add-connection'    => [
				'label'               => __( 'Add new email connection', 'suremails' ),
				'description'         => __( 'Creates and authenticates a new email-sending connection on this WordPress site. The connection is validated against the external email provider during creation — invalid credentials will be rejected immediately. IMPORTANT: Gmail, Outlook, and Zoho Mail use OAuth and CANNOT be added through this ability — they require a browser-based authorization flow in the SureMails admin dashboard. If priority is omitted, it is auto-assigned as the next available sequence number. ALL providers require these common fields in provider_settings: connection_title (string, required), from_email (email, required), from_name (string, optional), force_from_email (boolean, optional), force_from_name (boolean, optional), priority (integer, optional). EXACT provider-specific fields (use these exact key names): PHPMAIL — no extra fields needed, only common fields above. SMTP — host (string, required), port (integer, required, e.g. 587), encryption (string, required, one of: none/ssl/tls), username (string, optional), password (string, optional), auto_tls (boolean, optional), return_path (boolean, optional). SENDGRID — api_key (string, required). MAILGUN — api_key (string, required), domain (string, required, e.g. mg.example.com), region (string, optional, US or EU). POSTMARK — server_token (string, required), message_stream (string, required, default: outbound). AWS (Amazon SES) — username (string, required, this is the AWS Access Key ID), password (string, required, this is the AWS Secret Access Key), region (string, required, e.g. us-east-1), return_path (boolean, optional). SPARKPOST — api_key (string, required), region (string, optional, US or EU). BREVO — api_key (string, required). MAILERSEND — api_key (string, required). MAILJET — api_key (string, required), secret_key (string, required). SMTP2GO — api_key (string, required). ELASTIC (Elastic Email) — api_key (string, required), mail_type (string, optional, transactional or marketing). EMAILIT — api_key (string, required). NETCORE — api_key (string, required), region (string, required).', 'suremails' ),
				'category'            => 'suremails',
				'permission_callback' => static function () use ( $ability ) {
					return get_option( 'suremails_abilities_api_edit', false ) &&
						$ability->permission_callback( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'type', 'provider_settings' ],
					'properties' => [
						'type'              => [
							'type'        => 'string',
							'description' => __( 'Provider type. Must be one of: SMTP, PHPMAIL, SENDGRID, MAILGUN, POSTMARK, AWS, SPARKPOST, BREVO, MAILERSEND, MAILJET, SMTP2GO, ELASTIC, EMAILIT, NETCORE. OAuth providers (GMAIL, OUTLOOK, ZOHO) are not supported here.', 'suremails' ),
						],
						'provider_settings' => [
							'type'        => 'object',
							'description' => __( 'Provider-specific settings object. Must include common fields: connection_title (required), from_email (required). Plus provider-specific fields using the EXACT key names listed in the ability description — e.g. AWS uses "username" for Access Key ID and "password" for Secret Access Key (NOT access_key/secret_key); POSTMARK uses "server_token" and "message_stream"; MAILJET uses "api_key" and "secret_key"; most single-key providers use "api_key". See the ability description for the complete field reference per provider.', 'suremails' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'    => [
							'type'        => 'boolean',
							'description' => __( 'Whether the connection was created and authenticated.', 'suremails' ),
						],
						'message'    => [
							'type'        => 'string',
							'description' => __( 'Result message.', 'suremails' ),
						],
						'connection' => [
							'type'       => 'object',
							'properties' => [
								'id'               => [ 'type' => 'string' ],
								'type'             => [ 'type' => 'string' ],
								'from_email'       => [ 'type' => 'string' ],
								'from_name'        => [ 'type' => 'string' ],
								'connection_title' => [ 'type' => 'string' ],
								'priority'         => [ 'type' => 'integer' ],
							],
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					return $ability->add_connection( $input );
				},
				'meta'                => [
					'annotations' => [
						'priority'        => 2.0,
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => false,
						'openWorldHint'   => true,
						'instructions'    => __( 'This contacts an external email provider to authenticate credentials, so it reaches the internet. Always confirm the provider type and credentials with the user before running. CRITICAL: Use the EXACT field key names from the description — e.g. AWS uses "username" and "password" (NOT access_key/secret_key), POSTMARK uses "server_token" (NOT api_key), MAILJET needs both "api_key" and "secret_key". If the user asks to add a Gmail, Outlook, or Zoho Mail connection, do NOT attempt this ability — explain that those providers require OAuth browser-based authentication and must be set up in the SureMails dashboard at wp-admin. Running this multiple times with the same settings will create duplicate connections. If this is the first connection being added, it will automatically become the site\'s default sending connection.', 'suremails' ),
					],
					'mcp'         => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			],

			SUREMAILS_ABILITY_API_NAMESPACE . 'update-connection' => [
				'label'               => __( 'Update connection metadata', 'suremails' ),
				'description'         => __( 'Updates the non-credential metadata of an existing email connection. Only these fields can be changed: from_email (sender address), from_name (sender display name), connection_title (label shown in dashboard), force_from_email (override other plugins\' From address), and force_from_name (override other plugins\' From name). Credentials like API keys, passwords, and OAuth tokens cannot be changed through this ability — the connection must be re-created for that. Only the fields you provide will be updated; omitted fields remain unchanged. If this connection is the site\'s default, the default connection info is updated automatically.', 'suremails' ),
				'category'            => 'suremails',
				'permission_callback' => static function () use ( $ability ) {
					return get_option( 'suremails_abilities_api_edit', false ) &&
						$ability->permission_callback( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id'               => [
							'type'        => 'string',
							'description' => __( 'The connection ID to update. Obtain this from list-connections.', 'suremails' ),
						],
						'from_email'       => [
							'type'        => 'string',
							'format'      => 'email',
							'description' => __( 'New sender email address.', 'suremails' ),
						],
						'from_name'        => [
							'type'        => 'string',
							'description' => __( 'New sender display name.', 'suremails' ),
						],
						'connection_title' => [
							'type'        => 'string',
							'description' => __( 'New connection title shown in the dashboard.', 'suremails' ),
						],
						'force_from_email' => [
							'type'        => 'boolean',
							'description' => __( 'When true, all outgoing emails will use this connection\'s from_email, overriding any from address set by other plugins or themes.', 'suremails' ),
						],
						'force_from_name'  => [
							'type'        => 'boolean',
							'description' => __( 'When true, all outgoing emails will use this connection\'s from_name, overriding any sender name set by other plugins or themes.', 'suremails' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'    => [
							'type'        => 'boolean',
							'description' => __( 'Whether the update succeeded.', 'suremails' ),
						],
						'message'    => [
							'type'        => 'string',
							'description' => __( 'Result message.', 'suremails' ),
						],
						'connection' => [
							'type'       => 'object',
							'properties' => [
								'id'               => [ 'type' => 'string' ],
								'type'             => [ 'type' => 'string' ],
								'from_email'       => [ 'type' => 'string' ],
								'from_name'        => [ 'type' => 'string' ],
								'connection_title' => [ 'type' => 'string' ],
								'force_from_email' => [ 'type' => 'boolean' ],
								'force_from_name'  => [ 'type' => 'boolean' ],
							],
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					return $ability->update_connection( $input );
				},
				'meta'                => [
					'annotations' => [
						'priority'        => 2.0,
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
						'instructions'    => __( 'Confirm the changes with the user before applying. This does not change credentials — if the user wants to update an API key or password, they must delete and re-create the connection. Changing the from_email on the default connection affects all outgoing site emails immediately. Safe to run multiple times with the same values (idempotent).', 'suremails' ),
					],
					'mcp'         => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			],

			SUREMAILS_ABILITY_API_NAMESPACE . 'send-test-email' => [
				'label'               => __( 'Send test email', 'suremails' ),
				'description'         => __( 'Sends a real test email through a specific connection to a given recipient address. This actually delivers an email to the recipient\'s inbox — it is not a dry run or simulation. The email is sent using the connection\'s configured provider and from address. Use this to verify a connection is working correctly after setup. The email will appear in the recipient\'s inbox with the subject "SureMail: Test Email" and the site name.', 'suremails' ),
				'category'            => 'suremails',
				'permission_callback' => static function () use ( $ability ) {
					return get_option( 'suremails_abilities_api_edit', false ) &&
						$ability->permission_callback( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'connection_id', 'to_email' ],
					'properties' => [
						'connection_id' => [
							'type'        => 'string',
							'description' => __( 'The connection ID to send through. Obtain this from list-connections.', 'suremails' ),
						],
						'to_email'      => [
							'type'        => 'string',
							'description' => __( 'Recipient email address that will receive the test email.', 'suremails' ),
							'format'      => 'email',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => [
							'type'        => 'boolean',
							'description' => __( 'Whether the test email was sent successfully.', 'suremails' ),
						],
						'message' => [
							'type'        => 'string',
							'description' => __( 'Result message.', 'suremails' ),
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					return $ability->send_test_email( $input );
				},
				'meta'                => [
					'annotations' => [
						'priority'        => 2.0,
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => false,
						'openWorldHint'   => true,
						'instructions'    => __( 'This sends a REAL email to a real inbox — always confirm the recipient address with the user before sending. Do not send test emails to addresses the user has not explicitly provided. Each call sends a new email, so avoid calling this repeatedly. If the send fails, the connection credentials may be invalid or the provider may be unreachable.', 'suremails' ),
					],
					'mcp'         => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			],

			// ============================================
			// Email Logs
			// ============================================

			SUREMAILS_ABILITY_API_NAMESPACE . 'list-email-logs' => [
				'label'               => __( 'List email logs', 'suremails' ),
				'description'         => __( 'Returns a paginated list of email log records stored by SureMails. Each log entry contains the sender, recipient(s), subject, delivery status (sent, failed, pending, or blocked), which connection was used, and the send date. Supports filtering by status, date range (start_date and end_date in YYYY-MM-DD format), and a search term that matches against the subject line and recipient email. Results are ordered newest-first and paginated with configurable page size (default 20, max 100). Use this to investigate delivery issues, audit email activity, or find specific log IDs for resend/delete operations.', 'suremails' ),
				'category'            => 'suremails',
				'permission_callback' => static function () use ( $ability ) {
					return $ability->permission_callback( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'status'     => [
							'type'        => 'string',
							'enum'        => [ 'sent', 'failed', 'pending', 'blocked' ],
							'description' => __( 'Filter by delivery status.', 'suremails' ),
						],
						'start_date' => [
							'type'        => 'string',
							'format'      => 'date',
							'description' => __( 'Include only logs on or after this date (YYYY-MM-DD).', 'suremails' ),
						],
						'end_date'   => [
							'type'        => 'string',
							'format'      => 'date',
							'description' => __( 'Include only logs on or before this date (YYYY-MM-DD). Defaults to start_date if omitted.', 'suremails' ),
						],
						'search'     => [
							'type'        => 'string',
							'description' => __( 'Free-text search across subject line and recipient email address.', 'suremails' ),
						],
						'page'       => [
							'type'        => 'integer',
							'description' => __( 'Page number, 1-based. Defaults to 1.', 'suremails' ),
							'default'     => 1,
						],
						'per_page'   => [
							'type'        => 'integer',
							'description' => __( 'Number of results per page. Minimum 1, maximum 100. Defaults to 20.', 'suremails' ),
							'default'     => 20,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'logs'        => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'         => [
										'type'        => 'integer',
										'description' => __( 'Log ID.', 'suremails' ),
									],
									'email_from' => [
										'type'        => 'string',
										'description' => __( 'Sender email.', 'suremails' ),
									],
									'email_to'   => [
										'type'        => 'string',
										'description' => __( 'Recipient email(s).', 'suremails' ),
									],
									'subject'    => [
										'type'        => 'string',
										'description' => __( 'Email subject.', 'suremails' ),
									],
									'status'     => [
										'type'        => 'string',
										'description' => __( 'Delivery status.', 'suremails' ),
									],
									'connection' => [
										'type'        => 'string',
										'description' => __( 'Connection ID used.', 'suremails' ),
									],
									'created_at' => [
										'type'        => 'string',
										'description' => __( 'Send date.', 'suremails' ),
									],
								],
							],
						],
						'total'       => [
							'type'        => 'integer',
							'description' => __( 'Total matching logs across all pages.', 'suremails' ),
						],
						'total_pages' => [
							'type'        => 'integer',
							'description' => __( 'Total number of pages.', 'suremails' ),
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					return $ability->list_email_logs( $input );
				},
				'meta'                => [
					'annotations' => [
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
						'instructions'    => __( 'Safe to call at any time. Read-only database query. Start with a small per_page (e.g. 5) to keep responses concise. Use status="failed" to quickly find delivery issues. Use this to obtain log IDs before calling resend-email or delete-email-logs.', 'suremails' ),
					],
					'mcp'         => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			],

			SUREMAILS_ABILITY_API_NAMESPACE . 'get-email-log' => [
				'label'               => __( 'Get email log details', 'suremails' ),
				'description'         => __( 'Retrieves the full details of a single email log entry by its ID. Returns everything stored about that email: sender, recipient(s), subject, full HTML body, headers, delivery status, the provider\'s response message, which connection was used, and timestamps. The body may contain full HTML email content. Use this to investigate why a specific email failed or to inspect the exact content that was sent.', 'suremails' ),
				'category'            => 'suremails',
				'permission_callback' => static function () use ( $ability ) {
					return $ability->permission_callback( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id' => [
							'type'        => 'integer',
							'description' => __( 'The email log ID. Obtain this from list-email-logs.', 'suremails' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'         => [
							'type'        => 'integer',
							'description' => __( 'Log ID.', 'suremails' ),
						],
						'email_from' => [
							'type'        => 'string',
							'description' => __( 'Sender email.', 'suremails' ),
						],
						'email_to'   => [
							'type'        => 'string',
							'description' => __( 'Recipient email(s).', 'suremails' ),
						],
						'subject'    => [
							'type'        => 'string',
							'description' => __( 'Email subject.', 'suremails' ),
						],
						'body'       => [
							'type'        => 'string',
							'description' => __( 'Full email body content (may be HTML).', 'suremails' ),
						],
						'headers'    => [
							'type'        => 'string',
							'description' => __( 'Email headers as stored by the logger.', 'suremails' ),
						],
						'status'     => [
							'type'        => 'string',
							'description' => __( 'Delivery status (sent, failed, pending, or blocked).', 'suremails' ),
						],
						'response'   => [
							'type'        => 'string',
							'description' => __( 'Response from the email provider (useful for diagnosing failures).', 'suremails' ),
						],
						'connection' => [
							'type'        => 'string',
							'description' => __( 'Connection ID that was used to send.', 'suremails' ),
						],
						'created_at' => [
							'type'        => 'string',
							'description' => __( 'When the email was originally sent.', 'suremails' ),
						],
						'updated_at' => [
							'type'        => 'string',
							'description' => __( 'When the log was last updated (e.g. after a resend).', 'suremails' ),
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					return $ability->get_email_log( $input );
				},
				'meta'                => [
					'annotations' => [
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
						'instructions'    => __( 'Safe to call at any time. Read-only. Be aware the response may be large if the email body contains full HTML. When debugging a failed email, check the "response" field for the provider error message.', 'suremails' ),
					],
					'mcp'         => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			],

			SUREMAILS_ABILITY_API_NAMESPACE . 'delete-email-logs' => [
				'label'               => __( 'Delete email logs', 'suremails' ),
				'description'         => __( 'Permanently deletes one or more email log entries by their IDs. This cannot be undone — the log records and any orphaned file attachments associated exclusively with these logs are removed from the database and disk. Logs that are still referenced by other entries are preserved. Returns the count of actually deleted records.', 'suremails' ),
				'category'            => 'suremails',
				'permission_callback' => static function () use ( $ability ) {
					return get_option( 'suremails_abilities_api_delete', false ) &&
						$ability->permission_callback( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'log_ids' ],
					'properties' => [
						'log_ids' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'integer' ],
							'description' => __( 'Array of log IDs to permanently delete. Obtain IDs from list-email-logs.', 'suremails' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'deleted_count' => [
							'type'        => 'integer',
							'description' => __( 'Number of log records actually deleted.', 'suremails' ),
						],
						'message'       => [
							'type'        => 'string',
							'description' => __( 'Result message.', 'suremails' ),
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					return $ability->delete_email_logs( $input );
				},
				'meta'                => [
					'annotations' => [
						'priority'        => 3.0,
						'readOnlyHint'    => false,
						'destructiveHint' => true,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
						'instructions'    => __( 'DESTRUCTIVE — always ask the user to confirm before running this. Show them the number of logs and their subjects so they understand what will be permanently removed. Once deleted, these email records cannot be recovered. If the user wants to delete all logs, use list-email-logs first to get the IDs — there is no "delete all" shortcut.', 'suremails' ),
					],
					'mcp'         => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			],

			SUREMAILS_ABILITY_API_NAMESPACE . 'resend-email' => [
				'label'               => __( 'Resend email from log', 'suremails' ),
				'description'         => __( 'Re-sends one or more previously logged emails to their original recipients using the original subject, body, headers, and attachments. This sends REAL emails — each recipient will receive the email again in their inbox. The original log entry is updated with the new send result. Use this to retry failed emails or re-deliver emails that were not received. Each log ID is processed independently, so partial success is possible when resending multiple emails.', 'suremails' ),
				'category'            => 'suremails',
				'permission_callback' => static function () use ( $ability ) {
					return get_option( 'suremails_abilities_api_edit', false ) &&
						$ability->permission_callback( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'log_ids' ],
					'properties' => [
						'log_ids' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'integer' ],
							'description' => __( 'Array of email log IDs to resend. Obtain IDs from list-email-logs.', 'suremails' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'results' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'log_id'  => [
										'type'        => 'integer',
										'description' => __( 'The log ID that was resent.', 'suremails' ),
									],
									'success' => [
										'type'        => 'boolean',
										'description' => __( 'Whether the resend was successful.', 'suremails' ),
									],
									'message' => [
										'type'        => 'string',
										'description' => __( 'Result or error message for this resend.', 'suremails' ),
									],
								],
							],
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					return $ability->resend_email( $input );
				},
				'meta'                => [
					'annotations' => [
						'priority'        => 2.0,
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => false,
						'openWorldHint'   => true,
						'instructions'    => __( 'This sends REAL emails to real recipients — always confirm with the user before resending. Show them the subject and recipient from the log so they know exactly what will be re-delivered. Each call sends new emails, so never call this repeatedly for the same log IDs without the user explicitly asking. If resending a failed email, let the user know the underlying issue (bad connection, etc.) may still exist.', 'suremails' ),
					],
					'mcp'         => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			],

			// ============================================
			// Analytics
			// ============================================

			SUREMAILS_ABILITY_API_NAMESPACE . 'get-email-stats' => [
				'label'               => __( 'Get email delivery statistics', 'suremails' ),
				'description'         => __( 'Returns aggregate email delivery statistics for a given date range. Provides the total count of successfully sent emails and failed emails, plus a daily breakdown (chart_data) with per-day sent/failed counts. The date range is inclusive. If end_date is omitted, statistics for the single start_date are returned. Use this to get a high-level overview of email health, spot delivery problems, or generate reports. Does not return individual email details — use list-email-logs for that.', 'suremails' ),
				'category'            => 'suremails',
				'permission_callback' => static function () use ( $ability ) {
					return $ability->permission_callback( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'start_date' ],
					'properties' => [
						'start_date' => [
							'type'        => 'string',
							'format'      => 'date',
							'description' => __( 'Start date for the range (YYYY-MM-DD). Required.', 'suremails' ),
						],
						'end_date'   => [
							'type'        => 'string',
							'format'      => 'date',
							'description' => __( 'End date for the range (YYYY-MM-DD). If omitted, defaults to the same day as start_date.', 'suremails' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'total_sent'   => [
							'type'        => 'integer',
							'description' => __( 'Total emails successfully sent in the range.', 'suremails' ),
						],
						'total_failed' => [
							'type'        => 'integer',
							'description' => __( 'Total emails that failed to send in the range.', 'suremails' ),
						],
						'chart_data'   => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'created_at'   => [
										'type'        => 'string',
										'description' => __( 'Date (YYYY-MM-DD).', 'suremails' ),
									],
									'total_sent'   => [
										'type'        => 'integer',
										'description' => __( 'Emails sent on this date.', 'suremails' ),
									],
									'total_failed' => [
										'type'        => 'integer',
										'description' => __( 'Emails failed on this date.', 'suremails' ),
									],
								],
							],
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					return $ability->get_email_stats( $input );
				},
				'meta'                => [
					'annotations' => [
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
						'instructions'    => __( 'Safe to call at any time. Read-only aggregate query. Use this when the user asks about email delivery health, success rates, or failure trends. For the last 7 days, set start_date to 7 days ago and end_date to today.', 'suremails' ),
					],
					'mcp'         => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			],

			// ============================================
			// Settings & Configuration
			// ============================================

			SUREMAILS_ABILITY_API_NAMESPACE . 'get-settings' => [
				'label'               => __( 'Get plugin settings', 'suremails' ),
				'description'         => __( 'Returns the current SureMails plugin configuration. Includes: the default sending connection (type, email, ID, title), whether email logging is enabled (log_emails), whether simulation mode is active (email_simulation — when "yes", emails are logged but NOT actually sent), the log retention period (delete_email_logs_after), weekly email summary preferences (email_summary_active, email_summary_day), admin sidebar visibility (show_in_sidebar), and the total number of configured connections. Use this to understand the current plugin state before making changes.', 'suremails' ),
				'category'            => 'suremails',
				'permission_callback' => static function () use ( $ability ) {
					return $ability->permission_callback( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'default_connection'      => [
							'type'       => 'object',
							'properties' => [
								'type'             => [ 'type' => 'string' ],
								'email'            => [ 'type' => 'string' ],
								'id'               => [ 'type' => 'string' ],
								'connection_title' => [ 'type' => 'string' ],
							],
						],
						'log_emails'              => [
							'type'        => 'string',
							'description' => __( 'Whether email logging is enabled ("yes" or "no").', 'suremails' ),
						],
						'email_simulation'        => [
							'type'        => 'string',
							'description' => __( 'Whether simulation mode is active ("yes" or "no"). When enabled, emails are logged but never actually sent.', 'suremails' ),
						],
						'delete_email_logs_after' => [
							'type'        => 'string',
							'description' => __( 'Automatic log retention period (e.g. "none", "7_days", "30_days").', 'suremails' ),
						],
						'email_summary_active'    => [
							'type'        => 'string',
							'description' => __( 'Whether the weekly email summary report is enabled ("yes" or "no").', 'suremails' ),
						],
						'email_summary_day'       => [
							'type'        => 'string',
							'description' => __( 'Day of the week when the summary report is sent.', 'suremails' ),
						],
						'show_in_sidebar'         => [
							'type'        => 'string',
							'description' => __( 'Whether SureMails appears in the WordPress admin sidebar ("yes" or "no").', 'suremails' ),
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					return $ability->get_settings( $input );
				},
				'meta'                => [
					'annotations' => [
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
						'instructions'    => __( 'Safe to call at any time. Read-only. Call this before update-settings so you can show the user their current values and only change what they ask for. Pay attention to email_simulation — if it is "yes", no emails are actually being delivered.', 'suremails' ),
					],
					'mcp'         => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			],

			SUREMAILS_ABILITY_API_NAMESPACE . 'update-settings' => [
				'label'               => __( 'Update plugin settings', 'suremails' ),
				'description'         => __( 'Changes one or more SureMails plugin settings. Only the settings you provide will be updated — omitted settings remain unchanged. Available settings: log_emails ("yes"/"no" — controls whether outgoing emails are recorded in the log), email_simulation ("yes"/"no" — when enabled, emails are logged but NOT delivered to recipients), delete_email_logs_after (auto-delete old logs: "none", "1_day", "7_days", "30_days", "365_days", "730_days"), email_summary_active ("yes"/"no" — weekly delivery report), email_summary_day (day of the week for the summary). Changes take effect immediately and affect all future emails sent from the site.', 'suremails' ),
				'category'            => 'suremails',
				'permission_callback' => static function () use ( $ability ) {
					return get_option( 'suremails_abilities_api_edit', false ) &&
						$ability->permission_callback( 'manage_options' );
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'log_emails'              => [
							'type'        => 'string',
							'enum'        => [ 'yes', 'no' ],
							'description' => __( 'Enable ("yes") or disable ("no") email logging. When disabled, no email records are saved.', 'suremails' ),
						],
						'email_simulation'        => [
							'type'        => 'string',
							'enum'        => [ 'yes', 'no' ],
							'description' => __( 'Enable ("yes") or disable ("no") email simulation mode. WARNING: When enabled, NO emails will actually be delivered — they are only logged.', 'suremails' ),
						],
						'delete_email_logs_after' => [
							'type'        => 'string',
							'enum'        => [ 'none', '1_day', '7_days', '30_days', '365_days', '730_days' ],
							'description' => __( 'Automatically delete email logs older than this period. "none" keeps logs indefinitely.', 'suremails' ),
						],
						'email_summary_active'    => [
							'type'        => 'string',
							'enum'        => [ 'yes', 'no' ],
							'description' => __( 'Enable ("yes") or disable ("no") the weekly email delivery summary report.', 'suremails' ),
						],
						'email_summary_day'       => [
							'type'        => 'string',
							'enum'        => [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ],
							'description' => __( 'Day of the week to send the weekly summary report.', 'suremails' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success' => [
							'type'        => 'boolean',
							'description' => __( 'Whether settings were updated.', 'suremails' ),
						],
						'message' => [
							'type'        => 'string',
							'description' => __( 'Result message.', 'suremails' ),
						],
					],
				],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					return $ability->update_settings( $input );
				},
				'meta'                => [
					'annotations' => [
						'priority'        => 2.0,
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
						'instructions'    => __( 'Confirm changes with the user before applying. Be especially careful with email_simulation — enabling it will STOP all real email delivery from the site (emails are logged but never sent). Disabling log_emails means delivery failures won\'t be recorded. Always call get-settings first to show the user their current values. Safe to run multiple times with the same values (idempotent).', 'suremails' ),
					],
					'mcp'         => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			],
		];

		$abilities = apply_filters( 'suremails_config_abilities', $abilities );
		if ( ! is_array( $abilities ) ) {
			$abilities = [];
		}

		self::$abilities = $abilities;

		return $abilities;
	}

	/**
	 * Get a single ability config by name.
	 *
	 * @param string $ability_name Ability identifier.
	 * @return array{label: string, description: string, category: string, permission_callback: callable, input_schema: array<string, string|array<int, string>|array<string, array<string, string>>>, output_schema: array<string, string|array<string, array<string, string|array<string, string>>>>, execute_callback: callable, meta: array<string, array<string, bool|float|string>>}|false Ability config or false if not found.
	 */
	public static function get_ability( $ability_name ) {
		if ( self::$abilities === false ) {
			self::$abilities = self::get_abilities();
		}
		return self::$abilities[ $ability_name ] ?? false;
	}

	/**
	 * Get ability input schema.
	 *
	 * @param string $ability_name Ability identifier.
	 * @return array<string, string|array<int, string>|array<string, array<string, string>>>|false Input schema or false.
	 */
	public static function get_ability_input_schema( $ability_name ) {
		$ability = self::get_ability( $ability_name );
		if ( $ability === false ) {
			return false;
		}
		return $ability['input_schema'];
	}
}
