<?php
/**
 * Sendgrid.php
 *
 * Handles sending emails using SendGrid.
 *
 * @package SureMails\Inc\Emails\Providers\SendGrid
 */

namespace SureMails\Inc\Emails\Providers\SENDGRID;

use SureMails\Inc\Emails\Handler\ConnectionHandler;
use SureMails\Inc\Emails\ProviderHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Sendgrid
 *
 * Implements the ConnectionHandler to handle SendGrid email sending and authentication.
 */
class SendgridHandler implements ConnectionHandler {

	/**
	 * SendGrid connection data.
	 *
	 * @var array<string, string|int|bool>
	 */
	protected $connection_data;

	/**
	 * SendGrid API endpoint for sending emails.
	 *
	 * @var string
	 */
	private $api_url = 'https://api.sendgrid.com/v3/mail/send';

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
	 * Get headers for the SendGrid connection.
	 *
	 * @return array<string, string> The headers for the SendGrid connection.
	 * @param string $api_key The API key for the SendGrid connection.
	 * @since 1.0.1
	 */
	public function get_headers( $api_key ) {
		return [
			'Authorization' => 'Bearer ' . sanitize_text_field( $api_key ),
			'Content-Type'  => 'application/json',
		];
	}

	/**
	 * Authenticate the SendGrid connection by verifying the API key.
	 *
	 * @return array{success: bool, message: string, error_code: int}
	 */
	public function authenticate() {
		if ( empty( $this->connection_data['api_key'] ) || empty( $this->connection_data['from_email'] ) ) {
			return [
				'success'    => false,
				'message'    => __( 'API key or From Email is missing in the connection data.', 'suremails' ),
				'error_code' => 400,
			];
		}

		return [
			'success'    => true,
			'message'    => __( 'SendGrid connection saved successfully.', 'suremails' ),
			'error_code' => 200,
		];
	}

	/**
	 * Send an email via SendGrid, including attachments if provided.
	 *
	 * @param array<string, string|array<int, string>>                                                                                                                                                                                                                                                                                                                                                                                                                                                                             $atts The email attributes.
	 * @param int                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  $log_id The log ID for the email.
	 * @param array<string, string|int|bool>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       $connection The connection details.
	 * @param array{to: array<int, array{name: string, email: string}>, headers: array{from: array{name: string, email: string}, cc: array<int, array{name: string, email: string}>, bcc: array<int, array{name: string, email: string}>, reply_to: array<int, array{name: string, email: string}>, content_type: string, charset: string, boundary: string, x_mailer: string, extra_headers: array<string, string>}, message: string, attachments: array<int, string>, subject: string, uploaded_attachments: array<int, string>} $processed_data The processed email data.
	 * @return array{success: bool, message: string, send: bool, error_code?: int|string} The result of the email send operation.
	 * @throws \Exception If the email payload cannot be encoded to JSON.
	 */
	public function send( array $atts, $log_id, array $connection, $processed_data ) {
		$result = [
			'success' => false,
			'message' => '',
			'send'    => false,
		];

		/**
		 * The raw email subject.
		 *
		 * @var string $raw_subject
		 */
		$raw_subject   = $atts['subject'] ?? '';
		$email_payload = [
			'personalizations' => [],
			'from'             => [
				'email' => sanitize_email( (string) ( $connection['from_email'] ?? '' ) ),
				'name'  => ! empty( $connection['from_name'] ) ? sanitize_text_field( (string) ( $connection['from_name'] ) ) : __( 'WordPress', 'suremails' ),
			],
			'subject'          => sanitize_text_field( $raw_subject ),
			'content'          => [],
		];

		// Prepare recipients.
		$email_payload['personalizations'][] = [
			'to' => $processed_data['to'],
		];

		// Add CC and BCC if provided.
		if ( ! empty( $processed_data['headers']['cc'] ) ) {
			$email_payload['personalizations'][0]['cc'] = $processed_data['headers']['cc'];
		}
		if ( ! empty( $processed_data['headers']['bcc'] ) ) {
			$email_payload['personalizations'][0]['bcc'] = $processed_data['headers']['bcc'];
		}

		// Add content based on content type.
		/**
		 * The email message body.
		 *
		 * @var string $message
		 */
		$message                    = $atts['message'] ?? '';
		$is_html                    = strtolower( $processed_data['headers']['content_type'] ) === 'text/html';
		$email_payload['content'][] = [
			'type'  => $is_html ? 'text/html' : 'text/plain',
			'value' => $is_html ? $message : wp_strip_all_tags( $message ),
		];

		// Handle reply-to information.
		$reply_to = $processed_data['headers']['reply_to'];
		if ( ! empty( $reply_to ) ) {
			if ( count( $reply_to ) > 1 ) {
				$email_payload['reply_to_list'] = array_map(
					static function ( $email ) {
						return [
							'email' => sanitize_email( $email['email'] ),
							'name'  => isset( $email['name'] ) ? sanitize_text_field( $email['name'] ) : '', // @phpstan-ignore isset.offset
						];
					},
					$reply_to
				);

			} else {

				$single_reply_to           = reset( $reply_to );
				$email_payload['reply_to'] = [
					'email' => sanitize_email( $single_reply_to['email'] ),
					'name'  => isset( $single_reply_to['name'] ) ? sanitize_text_field( $single_reply_to['name'] ) : '', // @phpstan-ignore isset.offset
				];
			}
		}

		if ( ! empty( $processed_data['attachments'] ) ) {
			$data = [];
			foreach ( $processed_data['attachments'] as $attachment ) {

				$attachment_values = ProviderHelper::get_attachment( $attachment );

				if ( ! $attachment_values ) {
					continue;
				}
				$data[] = [
					'type'        => $attachment_values['type'],
					'filename'    => $attachment_values['name'],
					'disposition' => 'attachment',
					'content_id'  => $attachment_values['id'],
					'content'     => $attachment_values['blob'],
				];
			}

			if ( ! empty( $data ) ) {
				$email_payload['attachments'] = $data;
			}
		}

		// Send email via SendGrid API.
		try {
			$json_payload = wp_json_encode( $email_payload );
			if ( $json_payload === false ) {
				throw new \Exception( __( 'Failed to encode email payload to JSON.', 'suremails' ) );
			}
			$response = wp_safe_remote_post(
				$this->api_url,
				[
					'headers' => $this->get_headers( (string) ( $connection['api_key'] ?? '' ) ),
					'body'    => $json_payload,
				]
			);

			if ( is_wp_error( $response ) ) {
				$result['message'] = sprintf(
					/* translators: %s: Error message from SendGrid */
					__( 'Email sending failed via SendGrid. Error: %s', 'suremails' ),
					$response->get_error_message()
				);
				$result['error_code'] = $response->get_error_code();
				return $result;
			}

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( $response_code === 202 ) { // Accepted.
				$result['success'] = true;
				$result['message'] = __( 'Email sent successfully via SendGrid.', 'suremails' );
				$result['send']    = true;
			} else {
				$response_body     = wp_remote_retrieve_body( $response );
				$decoded_body      = json_decode( $response_body, true );
				$error_message     = $decoded_body['errors'][0]['message'] ?? __( 'Unknown error.', 'suremails' );
				$result['message'] = sprintf(
					/* translators: %s: Error message from SendGrid */
					__( 'Email sending failed via SendGrid. Error: %s', 'suremails' ),
					$error_message
				);
				$result['error_code'] = $response_code;
			}
		} catch ( \Exception $e ) {
			$result['message'] = sprintf(
				/* translators: %s: Exception message */
				__( 'Email sending failed via SendGrid. Error: %s', 'suremails' ),
				$e->getMessage()
			);
			$result['error_code'] = 500;
		}

		return $result;
	}

	/**
	 * Return the option configuration for SendGrid.
	 *
	 * @return array{title: string, description: string, fields: array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>, display_name: string, icon: string, provider_type: string, field_sequence: array<int, string>, provider_sequence: int}
	 */
	public static function get_options() {
		return [
			'title'             => __( 'SendGrid Connection', 'suremails' ),
			'description'       => __( 'Enter the details below to connect with your SendGrid account.', 'suremails' ),
			'fields'            => self::get_specific_fields(),
			'display_name'      => __( 'SendGrid', 'suremails' ),
			'icon'              => 'SendGridIcon',
			'provider_type'     => 'free',
			'field_sequence'    => [ 'connection_title', 'api_key', 'from_email', 'force_from_email', 'from_name', 'force_from_name', 'priority' ],
			'provider_sequence' => 40,
		];
	}

	/**
	 * Get the specific schema fields for SendGrid.
	 *
	 * @return array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>
	 */
	public static function get_specific_fields() {
		return [
			'api_key' => [
				'required'    => true,
				'datatype'    => 'string',
				'help_text'   => '',
				'label'       => __( 'API Key', 'suremails' ),
				'input_type'  => 'password',
				'placeholder' => __( 'Enter your SendGrid API Key', 'suremails' ),
				'encrypt'     => true,
			],
		];
	}
}
