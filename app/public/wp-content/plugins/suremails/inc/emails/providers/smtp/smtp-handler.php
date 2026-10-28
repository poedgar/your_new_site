<?php
/**
 * SmtpHandler.php
 *
 * Handles SMTP-specific email sending functionalities for the SureMails plugin.
 *
 * @package SureMails\Inc\Emails\Providers\SMTP
 */

namespace SureMails\Inc\Emails\Providers\SMTP;

use PHPMailer\PHPMailer\Exception;
use SureMails\Inc\ConnectionManager;
use SureMails\Inc\Emails\Handler\ConnectionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class SmtpHandler
 *
 * Handles SMTP-specific email sending functionalities.
 */
class SmtpHandler implements ConnectionHandler {
	/**
	 * SMTP connection data.
	 *
	 * @var array<string, string|int|bool>
	 */
	protected $connection_data;

	/**
	 * Constructor.
	 *
	 * Initializes SMTP connection data.
	 *
	 * @param array<string, string|int|bool> $connection_data The SMTP connection settings.
	 */
	public function __construct( array $connection_data ) {
		$this->connection_data = $connection_data;
	}

	/**
	 * Authenticate the SMTP connection.
	 *
	 * @return array{success: bool, message: string, error_code: int}
	 */
	public function authenticate() {
		return [
			'success'    => true,
			'message'    => '',
			'error_code' => 200,
		];
	}

	/**
	 * Send an email via SMTP, including attachments if provided.
	 *
	 * @param array<string, string|array<int, string>>                                                                                                                                                                                                                                                                                                                                                                                                                                                                             $atts The email attributes.
	 * @param int                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  $log_id The ID of the email log entry.
	 * @param array<string, string|int|bool>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       $connection The connection details.
	 * @param array{to: array<int, array{name: string, email: string}>, headers: array{from: array{name: string, email: string}, cc: array<int, array{name: string, email: string}>, bcc: array<int, array{name: string, email: string}>, reply_to: array<int, array{name: string, email: string}>, content_type: string, charset: string, boundary: string, x_mailer: string, extra_headers: array<string, string>}, message: string, attachments: array<int, string>, subject: string, uploaded_attachments: array<int, string>} $processed_data The processed email data from ProcessEmailData.
	 * @return array{success: bool, message: string, send: bool, retries?: int} The result of the email send operation.
	 */
	public function send( array $atts, $log_id, array $connection, array $processed_data ) {
		$result = [
			'success' => false,
			'message' => '',
			'send'    => false,
		];

		try {
			$phpmailer = ConnectionManager::instance()->get_phpmailer();
			// Server settings.
			$phpmailer->isSMTP(); // Set mailer to use SMTP.
			$phpmailer->Host        = sanitize_text_field( (string) ( $connection['host'] ?? '' ) ); // Specify main SMTP server.
			$phpmailer->Username    = sanitize_text_field( (string) ( $connection['username'] ?? '' ) ); // SMTP username.
			$phpmailer->Password    = sanitize_text_field( (string) ( $connection['password'] ?? '' ) ); // SMTP password.
			$phpmailer->SMTPAuth    = ! ( empty( $phpmailer->Username ) && empty( $phpmailer->Password ) ); // Enable SMTP auth only if credentials exist.
			$phpmailer->SMTPAutoTLS = (bool) $connection['auto_tls'];
			$encryption             = strtolower( sanitize_text_field( (string) ( $connection['encryption'] ) ) );
			if ( $encryption !== 'none' ) {
				$phpmailer->SMTPSecure = $encryption;
			}
			$phpmailer->Port    = intval( $connection['port'] ); // TCP port to connect to.
			$phpmailer->Timeout = 5; // Set a timeout of 4 seconds.

			$from_email = (string) ( $connection['from_email'] ?? '' );
			$from_name  = ! empty( $connection['from_name'] ) ? (string) $connection['from_name'] : __( 'WordPress', 'suremails' );

			$phpmailer->setFrom( $from_email, $from_name );

			// Set Return-Path if provided.
			if ( isset( $connection['return_path'] ) && $connection['return_path'] ) {
				$phpmailer->Sender = $phpmailer->From;
			}

			// Send the email.
			$send = $phpmailer->send();

			if ( $send ) {
				$result['success'] = true;
				$result['message'] = __( 'Email sent successfully via SMTP.', 'suremails' );
				$result['send']    = true;
			} else {
				$result['message'] = sprintf(
					// translators: %s: The error message from PHPMailer.
					__( 'Email sending failed via SMTP: %s', 'suremails' ),
					$phpmailer->ErrorInfo
				);
				$result['retries'] = 1; // Increment retries if applicable.
			}
		} catch ( Exception $e ) {
			$result['success'] = false;
			$result['message'] = sprintf(
				// translators: %s: The error message.
				__( 'Email sending failed via SMTP: %s', 'suremails' ),
				$e->getMessage()
			);
			$result['retries'] = 1; // Increment retries if applicable.
		}

		return $result;
	}
	/**
	 * Return the option configuration for SMTP.
	 *
	 * @return array{title: string, description: string, fields: array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>, display_name: string, icon: string, provider_type: string, field_sequence: array<int, string>, provider_sequence: int}
	 */
	public static function get_options() {
		return [
			'title'             => __( 'SMTP Connection', 'suremails' ),
			'description'       => __( 'Enter the details below to connect with your SMTP account.', 'suremails' ),
			'fields'            => self::get_specific_fields(),
			'display_name'      => __( 'Other SMTP Provider', 'suremails' ),
			'icon'              => 'SmtpIcon',
			'provider_type'     => 'free',
			'field_sequence'    => [ 'connection_title', 'host', 'port', 'encryption', 'username', 'password', 'auto_tls', 'from_email', 'force_from_email', 'return_path', 'from_name', 'force_from_name', 'priority' ],
			'provider_sequence' => 150,
		];
	}

	/**
	 * Get the specific schema fields for SMTP.
	 *
	 * @return array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>
	 */
	public static function get_specific_fields() {
		return [
			'host'        => [
				'required'    => true,
				'datatype'    => 'string',
				'help_text'   => '',
				'label'       => __( 'Host', 'suremails' ),
				'input_type'  => 'text',
				'placeholder' => __( 'Enter the SMTP host', 'suremails' ),
			],
			'port'        => [
				'required'    => true,
				'datatype'    => 'int',
				'help_text'   => '',
				'label'       => __( 'Port', 'suremails' ),
				'input_type'  => 'text',
				'placeholder' => __( 'Enter port', 'suremails' ),
			],
			'username'    => [
				'required'    => false,
				'datatype'    => 'string',
				'help_text'   => '',
				'label'       => __( 'Username', 'suremails' ),
				'input_type'  => 'text',
				'placeholder' => __( 'Enter SMTP username', 'suremails' ),
			],
			'password'    => [
				'required'    => false,
				'datatype'    => 'string',
				'help_text'   => '',
				'label'       => __( 'Password', 'suremails' ),
				'input_type'  => 'password',
				'placeholder' => __( 'Enter SMTP password', 'suremails' ),
				'encrypt'     => true,
			],
			'return_path' => [
				'default'     => true,
				'required'    => false,
				'datatype'    => 'boolean',
				'help_text'   => __( 'The Return Path is where bounce messages (failed delivery notices) are sent. If it’s off, you might not get these messages. Turn it on to receive bounce notifications at the "From Email" address if delivery fails.', 'suremails' ),
				'label'       => __( 'Return Path', 'suremails' ),
				'input_type'  => 'checkbox',
				'placeholder' => '',
				'depends_on'  => [ 'from_email' ],
			],
			'encryption'  => [
				'default'    => 'TLS',
				'required'   => true,
				'datatype'   => 'string',
				'help_text'  => __( 'Choose SSL for port 465, or TLS for port 25 or 587', 'suremails' ),
				'label'      => __( 'Encryption', 'suremails' ),
				'input_type' => 'select',
				'options'    => [
					'NONE' => __( 'None', 'suremails' ),
					'SSL'  => __( 'SSL', 'suremails' ),
					'TLS'  => __( 'TLS', 'suremails' ),
				],
			],
			'auto_tls'    => [
				'default'     => true,
				'required'    => false,
				'datatype'    => 'boolean',
				'help_text'   => __( 'Enable TLS automatically if the server supports it.', 'suremails' ),
				'label'       => __( 'Auto TLS', 'suremails' ),
				'input_type'  => 'checkbox',
				'placeholder' => '',
			],
		];
	}
}
