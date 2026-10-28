<?php
/**
 * OutlookHandler.php
 *
 * Handles sending emails using Microsoft Outlook/Office 365.
 *
 * @package SureMails\Inc\Emails\Providers\Outlook
 */

namespace SureMails\Inc\Emails\Providers\OUTLOOK;

use SureMails\Inc\Emails\Handler\ConnectionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OutlookHandler
 *
 * Implements the ConnectionHandler to handle Outlook email sending and authentication.
 */
class OutlookHandler implements ConnectionHandler {

	/**
	 * Outlook connection data.
	 *
	 * @var array<string, string|int|bool>
	 */
	protected $connection_data;

	/**
	 * Constructor.
	 *
	 * Initializes connection data.
	 *
	 * @param array<string, string|int|bool> $connection_data The connection details.
	 */
	public function __construct( array $connection_data ) {
		$this->connection_data = $connection_data;
	}

	/**
	 * Authenticate the Outlook connection.
	 *
	 * Since Outlook does not provide a direct authentication endpoint, this function
	 * simply saves the connection data and returns a success message.
	 *
	 * @return array{success: bool, message: string, error_code: int}
	 */
	public function authenticate() {
		return [
			'success'    => true,
			'message'    => __( 'Outlook connection saved successfully.', 'suremails' ),
			'error_code' => 200,
		];
	}

	/**
	 * Send email using Outlook.
	 *
	 * @param array<string, string|array<int, string>>                                                                                                                                                                                                                                                                                                                                                                                                                                                                             $atts The email attributes.
	 * @param int                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  $log_id The log ID.
	 * @param array<string, string|int|bool>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       $connection The connection details.
	 * @param array{to: array<int, array{name: string, email: string}>, headers: array{from: array{name: string, email: string}, cc: array<int, array{name: string, email: string}>, bcc: array<int, array{name: string, email: string}>, reply_to: array<int, array{name: string, email: string}>, content_type: string, charset: string, boundary: string, x_mailer: string, extra_headers: array<string, string>}, message: string, attachments: array<int, string>, subject: string, uploaded_attachments: array<int, string>} $processed_data The processed email data.
	 *
	 * @return array{success: bool, message: string, send: bool}
	 */
	public function send( array $atts, $log_id, array $connection, $processed_data ) {
		return [
			'success' => false,
			'message' => __( 'Outlook sending not yet implemented.', 'suremails' ),
			'send'    => false,
		];
	}

	/**
	 * Get the Outlook connection options.
	 *
	 * @return array{title: string, description: string, fields: array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>, icon: string, display_name: string, provider_type: string, field_sequence: array<int, string>}
	 */
	public static function get_options() {
		return [
			'title'          => __( 'Outlook Connection', 'suremails' ),
			'description'    => __( 'Enter the details below to connect with your Microsoft Outlook/Office 365 account.', 'suremails' ),
			'fields'         => self::get_specific_fields(),
			'icon'           => 'OutlookIcon',
			'display_name'   => __( 'Microsoft Outlook/Office 365', 'suremails' ),
			'provider_type'  => 'soon',
			'field_sequence' => [ 'connection_title', 'client_id', 'client_secret', 'redirect_uri', 'from_email', 'force_from_email', 'from_name', 'force_from_name', 'priority' ],
		];
	}

	/**
	 * Get the specific fields for the Outlook connection.
	 *
	 * @return array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>
	 */
	public static function get_specific_fields() {
		return [
			'client_id'     => [
				'required'    => true,
				'datatype'    => 'string',
				'label'       => __( 'Client ID', 'suremails' ),
				'input_type'  => 'text',
				'placeholder' => __( 'Enter your Outlook Client ID', 'suremails' ),
				'encrypt'     => true,
			],
			'client_secret' => [
				'required'    => true,
				'datatype'    => 'string',
				'label'       => __( 'Client Secret', 'suremails' ),
				'input_type'  => 'password',
				'placeholder' => __( 'Enter your Outlook Client Secret', 'suremails' ),
				'encrypt'     => true,
			],
			'redirect_uri'  => [
				'required'    => true,
				'datatype'    => 'string',
				'label'       => __( 'Redirect URI', 'suremails' ),
				'input_type'  => 'text',
				'placeholder' => __( 'Enter your Outlook Redirect URI', 'suremails' ),
			],
		];
	}
}
