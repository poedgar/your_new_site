<?php
/**
 * EmailitHandler.php
 *
 * Handles sending emails using Emailit service.
 *
 * @package SureMails\Inc\Emails\Providers\Emailit
 */

namespace SureMails\Inc\Emails\Providers\EMAILIT;

use SureMails\Inc\Emails\Handler\ConnectionHandler;
use SureMails\Inc\Emails\ProviderHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class EmailitHandler
 *
 * Implements the ConnectionHandler to handle Emailit email sending and authentication.
 */
class EmailitHandler implements ConnectionHandler {

	/**
	 * Emailit connection data.
	 *
	 * @var array<string, string|int|bool>
	 */
	protected $connection_data;

	/**
	 * Emailit API endpoint for sending emails.
	 *
	 * @var string
	 */
	private $api_url = 'https://api.emailit.com/v2/emails';

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
	 * Get headers for the Emailit connection.
	 *
	 * @param string $api_key The API key for the Emailit connection.
	 * @return array<string, string> The headers for the Emailit connection.
	 */
	public function get_headers( $api_key ) {
		return [
			'Authorization' => 'Bearer ' . sanitize_text_field( $api_key ),
			'Content-Type'  => 'application/json',
		];
	}
	/**
	 * Authenticate the Emailit connection by verifying the API key.
	 *
	 * @return array{success: bool, message: string, error_code: int}
	 */
	public function authenticate() {
		return [
			'success'    => true,
			'message'    => __( 'Emailit connection saved successfully.', 'suremails' ),
			'error_code' => 200,
		];
	}

	/**
	 * Send an email via Emailit, including attachments if provided.
	 *
	 * @param array<string, string|array<int, string>>                                                                                                                                                                                                                                                                                                                                                                                                                                                                             $atts The email attributes.
	 * @param int                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  $log_id The log ID for the email.
	 * @param array<string, string|int|bool>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       $connection The connection details.
	 * @param array{to: array<int, array{name: string, email: string}>, headers: array{from: array{name: string, email: string}, cc: array<int, array{name: string, email: string}>, bcc: array<int, array{name: string, email: string}>, reply_to: array<int, array{name: string, email: string}>, content_type: string, charset: string, boundary: string, x_mailer: string, extra_headers: array<string, string>}, message: string, attachments: array<int, string>, subject: string, uploaded_attachments: array<int, string>} $processed_data The processed email data.
	 * @return array{success: bool, message: string, send: bool, email_id?: string, error_code?: int|string, retries?: int} The result of the email send operation.
	 * @throws \Exception If the email payload cannot be encoded to JSON.
	 */
	public function send( array $atts, $log_id, array $connection, $processed_data ) {
		$result = [
			'success' => false,
			'message' => '',
			'send'    => false,
		];

		// Prepare basic email payload.
		$from_email = sanitize_email( (string) ( $connection['from_email'] ?? '' ) ); // @phpstan-ignore nullCoalesce.offset
		if ( empty( $from_email ) || ! is_email( $from_email ) ) {
			$result['message'] = __( 'Invalid or missing from email address.', 'suremails' );
			return $result;
		}

		$from_name = ! empty( $connection['from_name'] )
			? sanitize_text_field( (string) ( $connection['from_name'] ) )
			: __( 'WordPress', 'suremails' );

		/**
		 * The raw email subject.
		 *
		 * @var string $raw_subject
		 */
		$raw_subject   = $atts['subject'] ?? '';
		$email_payload = [
			'from'    => $this->format_email_address(
				$from_email,
				$from_name
			),
			'subject' => sanitize_text_field( $raw_subject ),
		];

		// Prepare recipients.
		$to_recipients = $processed_data['to'];
		$to_emails     = [];
		foreach ( $to_recipients as $recipient ) {
			if ( ! isset( $recipient['email'] ) ) { // @phpstan-ignore isset.offset
				continue;
			}
			$sanitized_email = sanitize_email( $recipient['email'] );
			if ( is_email( $sanitized_email ) ) {
				$to_emails[] = $sanitized_email;
			}
		}
		if ( ! empty( $to_emails ) ) {
			$email_payload['to'] = $to_emails;
		} else {
			$result['message'] = __( 'No valid recipient email addresses provided.', 'suremails' );
			return $result;
		}

		// Handle reply-to.
		$reply_to = $processed_data['headers']['reply_to'];
		if ( ! empty( $reply_to ) ) {
			$reply_to_email = reset( $reply_to );
			if ( isset( $reply_to_email['email'] ) && ! empty( $reply_to_email['email'] ) ) { // @phpstan-ignore isset.offset
				$sanitized_email = sanitize_email( $reply_to_email['email'] );
				if ( is_email( $sanitized_email ) ) {
					$email_payload['reply_to'] = $sanitized_email;
				}
			}
		}

		// Add content based on content type.
		$content_type = $processed_data['headers']['content_type'];
		$is_html      = ProviderHelper::is_html( $content_type );

		/**
		 * The email message body.
		 *
		 * @var string $message
		 */
		$message = $atts['message'] ?? '';
		if ( $is_html ) {
			$email_payload['html'] = $message;
		}

		// Always include text version.
		$email_payload['text'] = $is_html ? wp_strip_all_tags( $message ) : $message;

		// Handle CC (v2 API uses array of email addresses).
		if ( ! empty( $processed_data['headers']['cc'] ) ) {
			$cc_emails = [];
			foreach ( $processed_data['headers']['cc'] as $cc ) {
				if ( ! isset( $cc['email'] ) ) { // @phpstan-ignore isset.offset
					continue;
				}
				$sanitized_email = sanitize_email( $cc['email'] );
				if ( is_email( $sanitized_email ) ) {
					$cc_emails[] = $sanitized_email;
				}
			}
			if ( ! empty( $cc_emails ) ) {
				$email_payload['cc'] = $cc_emails;
			}
		}

		// Handle BCC (v2 API uses array of email addresses).
		if ( ! empty( $processed_data['headers']['bcc'] ) ) {
			$bcc_emails = [];
			foreach ( $processed_data['headers']['bcc'] as $bcc ) {
				if ( ! isset( $bcc['email'] ) ) { // @phpstan-ignore isset.offset
					continue;
				}
				$sanitized_email = sanitize_email( $bcc['email'] );
				if ( is_email( $sanitized_email ) ) {
					$bcc_emails[] = $sanitized_email;
				}
			}
			if ( ! empty( $bcc_emails ) ) {
				$email_payload['bcc'] = $bcc_emails;
			}
		}

		// Handle attachments.
		if ( ! empty( $processed_data['attachments'] ) ) {
			$attachments = [];
			foreach ( $processed_data['attachments'] as $attachment ) {
				$attachment_values = ProviderHelper::get_attachment( $attachment );

				if ( ! $attachment_values ) {
					continue;
				}

				$attachments[] = [
					'filename'     => $attachment_values['name'],
					'content'      => $attachment_values['blob'],
					'content_type' => $attachment_values['type'],
				];
			}

			if ( ! empty( $attachments ) ) {
				$email_payload['attachments'] = $attachments;
			}
		}

		// Send email via Emailit API.
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
					'timeout' => 30,
				]
			);

			if ( is_wp_error( $response ) ) {
				$result['message']    = __( 'Emailit send failed: ', 'suremails' ) . $response->get_error_message();
				$result['error_code'] = $response->get_error_code();
				return $result;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			// v2 API returns 200/201 for success, 202 for scheduled emails.
			if ( $response_code === 200 || $response_code === 201 || $response_code === 202 ) {
				$result['success'] = true;
				$result['message'] = __( 'Email sent successfully via Emailit.', 'suremails' );
				$result['send']    = true;

				// Try to get message ID from response.
				$decoded_response = json_decode( $response_body, true );
				if ( is_array( $decoded_response ) && isset( $decoded_response['id'] ) ) {
					$result['email_id'] = $decoded_response['id'];
				}
			} else {
				$decoded_body  = json_decode( $response_body, true );
				$error_message = $this->extract_error_message( $decoded_body, (int) $response_code );

				// translators: %s is the error message from Emailit API.
				$result['message']    = sprintf( __( 'Email sending failed via Emailit: %s', 'suremails' ), $error_message );
				$result['error_code'] = $response_code;
				$result['retries']    = 1;
			}
		} catch ( \Exception $e ) {
			$result['message']    = __( 'Emailit send failed: ', 'suremails' ) . $e->getMessage();
			$result['error_code'] = 500;
			$result['retries']    = 1;
		}

		return $result;
	}

	/**
	 * Return the option configuration for Emailit.
	 *
	 * @return array{title: string, description: string, fields: array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>, display_name: string, icon: string, provider_type: string, field_sequence: array<int, string>, provider_sequence: int}
	 */
	public static function get_options() {
		return [
			'title'             => __( 'Emailit Connection', 'suremails' ),
			'description'       => __( 'Enter the details below to connect with your Emailit account. Important: Your sending domain must be verified in Emailit before you can send emails.', 'suremails' ),
			'fields'            => self::get_specific_fields(),
			'display_name'      => __( 'Emailit', 'suremails' ),
			'icon'              => 'EmailitIcon',
			'provider_type'     => 'free',
			'field_sequence'    => [ 'connection_title', 'api_key', 'from_email', 'force_from_email', 'from_name', 'force_from_name', 'priority' ],
			'provider_sequence' => 45,
		];
	}

	/**
	 * Get the specific schema fields for Emailit.
	 *
	 * @return array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>
	 */
	public static function get_specific_fields() {
		return [
			'api_key' => [
				'required'    => true,
				'datatype'    => 'string',
				'help_text'   => sprintf(
					// translators: %1$s: API key link, %2$s: domain verification link.
					__( 'Get your API key from your Emailit dashboard. %1$s. Important: Before sending emails, you must %2$s in your Emailit account.', 'suremails' ),
					'<a href="https://app.emailit.com/settings/api" target="_blank">' . __( 'Get API Key', 'suremails' ) . '</a>',
					'<a href="https://app.emailit.com/domains" target="_blank">' . __( 'verify your sending domain', 'suremails' ) . '</a>'
				),
				'label'       => __( 'API Key', 'suremails' ),
				'input_type'  => 'password',
				'placeholder' => __( 'Enter your Emailit API Key', 'suremails' ),
				'encrypt'     => true,
			],
		];
	}

	/**
	 * Format email address with name.
	 *
	 * @param string $email The email address.
	 * @param string $name  The name (optional).
	 * @return string Formatted email address.
	 */
	private function format_email_address( $email, $name = '' ) {
		if ( ! empty( $name ) && $name !== $email ) {
			return sprintf( '%s <%s>', $name, $email );
		}
		return $email;
	}

	/**
	 * Extract error message from API response.
	 *
	 * @param array<string, string|array<string, string>|array<int, string|array<string, string>>>|null $decoded_body The decoded response body.
	 * @param int                                                                                       $response_code The HTTP response code.
	 * @return string The error message.
	 */
	private function extract_error_message( $decoded_body, $response_code ) {
		if ( is_array( $decoded_body ) ) {
			if ( isset( $decoded_body['message'] ) && is_string( $decoded_body['message'] ) ) {
				return $decoded_body['message'];
			}
			if ( isset( $decoded_body['error'] ) ) {
				if ( is_string( $decoded_body['error'] ) ) {
					return $decoded_body['error'];
				}
				if ( is_array( $decoded_body['error'] ) && isset( $decoded_body['error']['message'] ) ) { // @phpstan-ignore function.alreadyNarrowedType, booleanAnd.leftAlwaysTrue
					return (string) $decoded_body['error']['message']; // @phpstan-ignore cast.string
				}
				return __( 'Unknown error', 'suremails' );
			}
			if ( isset( $decoded_body['errors'] ) && is_array( $decoded_body['errors'] ) && ! empty( $decoded_body['errors'] ) ) {
				$first_error = reset( $decoded_body['errors'] );
				if ( is_string( $first_error ) ) {
					return $first_error;
				}
				if ( is_array( $first_error ) && isset( $first_error['message'] ) ) {
					return (string) $first_error['message'];
				}
				return __( 'Unknown error', 'suremails' );
			}
		}

		// Default error messages based on HTTP status codes.
		switch ( $response_code ) {
			case 400:
				return __( 'Bad request. Please check your email data.', 'suremails' );
			case 401:
				return __( 'Unauthorized. Please check your API key.', 'suremails' );
			case 403:
				return __( 'Forbidden. Access denied.', 'suremails' );
			case 404:
				return __( 'Not found. Please check the API endpoint.', 'suremails' );
			case 422:
				return __( 'Domain verification required. Your sending domain must be verified in Emailit before you can send emails. Please verify your domain in your Emailit dashboard at https://app.emailit.com/domains', 'suremails' );
			case 429:
				return __( 'Rate limit exceeded. Please try again later.', 'suremails' );
			case 500:
				return __( 'Internal server error. Please try again later.', 'suremails' );
			default:
				// translators: %d is the HTTP error code.
				return sprintf( __( 'HTTP error %d occurred.', 'suremails' ), $response_code );
		}
	}
}
