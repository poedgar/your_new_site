<?php
/**
 * Phpmail Handler.php
 *
 * Handles sending emails using PHP Mail.
 *
 * @package SureMails\Inc\Emails\Providers\Phpmail
 */

namespace SureMails\Inc\Emails\Providers\Phpmail;

use Exception;
use SureMails\Inc\ConnectionManager;
use SureMails\Inc\Emails\Handler\ConnectionHandler;
use SureMails\Inc\Emails\ProviderHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PhpmailHandler
 *
 * Implements the ConnectionHandler to handle Phpmail Mail email sending and authentication.
 */
class PhpmailHandler implements ConnectionHandler {

	/**
	 * PHP mail connection data.
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
	 * Authenticate the PHP Mail connection.
	 *
	 * Since PHP Mail does not provide a direct authentication endpoint, this function
	 * simply saves the connection data and returns a success message.
	 *
	 * @return array{success: bool, message: string, error_code: int}
	 */
	public function authenticate() {

		$from_email = sanitize_email( (string) ( $this->connection_data['from_email'] ?? '' ) );

		if ( empty( $this->connection_data['from_email'] ) ) {
			return [
				'success'    => false,
				'message'    => __( 'From Email is missing in the connection data.', 'suremails' ),
				'error_code' => 400,
			];
		}

		return [
			'success'    => true,
			'message'    => __( 'PHP Mail connection failed.', 'suremails' ),
			'error_code' => 500,
		];
	}

	/**
	 * Send an email using PHP Mail.
	 *
	 * @param array<string, string|array<int, string>>                                                                                                                                                                                                                                                                                                                                                                                                                                                                             $atts The email attributes.
	 * @param int                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  $log_id The log ID.
	 * @param array<string, string|int|bool>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       $connection The connection details.
	 * @param array{to: array<int, array{name: string, email: string}>, headers: array{from: array{name: string, email: string}, cc: array<int, array{name: string, email: string}>, bcc: array<int, array{name: string, email: string}>, reply_to: array<int, array{name: string, email: string}>, content_type: string, charset: string, boundary: string, x_mailer: string, extra_headers: array<string, string>}, message: string, attachments: array<int, string>, subject: string, uploaded_attachments: array<int, string>} $processed_data The processed email data.
	 *
	 * @return array{success: bool, message: string, send: bool}
	 */
	public function send( array $atts, $log_id, array $connection, $processed_data ) {
		$phpmailer = ConnectionManager::instance()->get_phpmailer();

		$from_email = sanitize_email( (string) ( $connection['from_email'] ?? '' ) );
		$from_name  = sanitize_text_field( (string) ( $connection['from_name'] ) );
		$phpmailer->setFrom( $from_email, $from_name );
		$phpmailer->isMail();

		$content_type = $processed_data['headers']['content_type'];
		/**
		 * The email message body.
		 *
		 * @var string $message
		 */
		$message = $atts['message'] ?? '';
		if ( ! empty( $content_type ) && ProviderHelper::is_html( $content_type ) ) {
			$phpmailer->msgHTML( $message );
			$phpmailer->AltBody = wp_strip_all_tags( $message );
		}

		try {
			if ( $phpmailer->Mailer !== 'mail' ) {
				$phpmailer->Mailer = 'mail';
			}

			$send = $phpmailer->send();
			if ( ! $send ) {
				return [
					'success' => false,
					'message' => __( 'Email sending failed via PHP Mail.', 'suremails' ),
					'send'    => false,
				];
			}
			return [
				'success' => true,
				'message' => __( 'Email sent successfully via PHP Mail.', 'suremails' ),
				'send'    => true,

			];

		} catch ( Exception $e ) {
			return [
				'success' => false,
				// translators: %s: The error message.
				'message' => sprintf( __( 'Email sending failed via PHP Mail: %s', 'suremails' ), $e->getMessage() ),
				'send'    => false,
			];
		}
	}

	/**
	 * Get the PHP Mail connection options.
	 *
	 * @return array{title: string, description: string, fields: array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>, icon: string, display_name: string, provider_type: string, field_sequence: array<int, string>, provider_sequence: int}
	 */
	public static function get_options() {
		return [
			'title'             => __( 'PHP Mail Connection', 'suremails' ),
			'description'       => __( 'Enter the details below to connect with your PHP Mail account.', 'suremails' ),
			'fields'            => self::get_specific_fields(),
			'icon'              => 'PhpMailIcon',
			'display_name'      => __( 'PHP Mail', 'suremails' ),
			'provider_type'     => 'free',
			'field_sequence'    => [ 'connection_title', 'from_email', 'force_from_email', 'from_name', 'force_from_name', 'priority' ],
			'provider_sequence' => 36,
		];
	}

	/**
	 * Get the PHP Mail connection specific fields.
	 *
	 * @return array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>
	 */
	public static function get_specific_fields() {
		return [];
	}
}
