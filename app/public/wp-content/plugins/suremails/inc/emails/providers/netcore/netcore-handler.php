<?php
/**
 * NetcoreHandler.php
 *
 * Handles sending emails using Netcore.
 *
 * @package SureMails\Inc\Emails\Providers\Netcore
 */

namespace SureMails\Inc\Emails\Providers\Netcore;

use SureMails\Inc\Emails\Handler\ConnectionHandler;
use SureMails\Inc\Emails\ProviderHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NetcoreHandler
 *
 * Implements the ConnectionHandler to handle Netcore email sending and authentication.
 */
class NetcoreHandler implements ConnectionHandler {

	/**
	 * Netcore connection data.
	 *
	 * @var array<string, string|int|bool>
	 */
	protected $connection_data;

	/**
	 * Expected HTTP code for a successful email send.
	 *
	 * @var int
	 */
	protected $email_sent_code = 202;

	/**
	 * API endpoints for different regions.
	 *
	 * @var array<string, string>
	 */
	protected $api_urls = [
		'US' => 'https://emailapi.netcorecloud.net/v5.1/mail/send',
		'EU' => 'https://apieu.netcorecloud.net/v5.1/mail/send',
	];

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
	 * Authenticate the Netcore connection.
	 *
	 * Since Netcore does not provide a direct authentication endpoint, this function
	 * simply saves the connection data and returns a success message.
	 *
	 * @return array{success: bool, message: string, error_code: int}
	 */
	public function authenticate() {
		return [
			'success'    => true,
			'message'    => __( 'Netcore connection saved successfully.', 'suremails' ),
			'error_code' => 200,
		];
	}

	/**
	 * Send email using Netcore.
	 *
	 * @param array<string, string|array<int, string>>                                                                                                                                                                                                                                                                                                                                                                                                                                                                             $atts The email attributes.
	 * @param int                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  $log_id The log ID.
	 * @param array<string, string|int|bool>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       $connection The connection details.
	 * @param array{to: array<int, array{name: string, email: string}>, headers: array{from: array{name: string, email: string}, cc: array<int, array{name: string, email: string}>, bcc: array<int, array{name: string, email: string}>, reply_to: array<int, array{name: string, email: string}>, content_type: string, charset: string, boundary: string, x_mailer: string, extra_headers: array<string, string>}, message: string, attachments: array<int, string>, subject: string, uploaded_attachments: array<int, string>} $processed_data The processed email data.
	 *
	 * @return array{success: bool, message: string, send: bool, email_id?: string, error_code?: int|string}
	 */
	public function send( array $atts, $log_id, array $connection, $processed_data ) {
		$result = [
			'success' => false,
			'message' => '',
			'send'    => false,
		];

		// Determine API endpoint based on region setting.
		$region  = isset( $connection['region'] ) ? strtoupper( (string) $connection['region'] ) : 'US';
		$api_url = $this->api_urls[ $region ] ?? $this->api_urls['US'];

		$from_name  = (string) ( $connection['from_name'] ?? '' );
		$from_email = sanitize_email( (string) ( $connection['from_email'] ?? '' ) );

		$personalizations = [
			'to'  => $this->format_recipients_array( $processed_data['to'] ),
			'cc'  => $this->format_recipients_array( $processed_data['headers']['cc'] ),
			'bcc' => $this->format_recipients_array( $processed_data['headers']['bcc'] ),
		];

		/**
		 * The email message body.
		 *
		 * @var string $message
		 */
		$message = $atts['message'] ?? '';
		/**
		 * The raw email subject.
		 *
		 * @var string $raw_subject
		 */
		$raw_subject = $atts['subject'] ?? '';
		$content     = [
			[
				'type'  => 'html',
				'value' => $message,
			],
		];

		$content_type = $processed_data['headers']['content_type'];
		if ( ! empty( $content_type ) && ProviderHelper::is_html( $content_type ) ) {
			$content[] = [
				'type'  => 'amp',
				'value' => wp_strip_all_tags( $message ),
			];
		}

		$body = [
			'from'             => [
				'name'  => $from_name,
				'email' => $from_email,
			],
			'personalizations' => [ $personalizations ],
			'subject'          => sanitize_text_field( $raw_subject ),
			'content'          => $content,
			'headers'          => [],
		];

		// Add reply_to if provided.
		if ( ! empty( $processed_data['headers']['reply_to'] ) ) {
			$reply_to = $this->get_reply_to( $processed_data['headers']['reply_to'] );
			if ( $reply_to ) {
				$body['reply_to'] = $reply_to;
			}
		}

		// Add attachments if any.
		if ( ! empty( $processed_data['attachments'] ) ) { // @phpstan-ignore booleanAnd.rightAlwaysTrue
			$body['attachments'] = $this->get_attachments( $processed_data['attachments'] );
		}

		// Prepare request parameters.
		$body_json = wp_json_encode( $body );
		if ( false === $body_json ) {
			$result['message'] = __( 'Email sending failed via Netcore. Failed to encode email body to JSON.', 'suremails' );
			return $result;
		}

		$params = [
			'body'    => $body_json,
			'headers' => [
				'content-type' => 'application/json',
				'api_key'      => $connection['api_key'] ?? '',
			],
			'timeout' => 15,
		];

		// Send the request.
		$response = wp_safe_remote_post( $api_url, $params );

		if ( is_wp_error( $response ) ) {
			$result['message']    = __( 'Email sending failed via Netcore. ', 'suremails' ) . $response->get_error_message();
			$result['error_code'] = $response->get_error_code();
			return $result;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		if ( $response_code === $this->email_sent_code ) {
			$result['success']  = true;
			$result['send']     = true;
			$result['email_id'] = $response_data['data']['message_id'] ?? '';
			$result['message']  = __( 'Email sent successfully via Netcore.', 'suremails' );
		} else {
			$error_message        = $response_data['error'];
			$result['message']    = __( 'Email sending failed via Netcore. ', 'suremails' ) . ( is_array( $error_message ) ? $error_message[0]['description'] ?? '' : $error_message );
			$result['error_code'] = $response_code;
		}

		return $result;
	}

	/**
	 * Get the options for the Netcore connection.
	 *
	 * @return array{title: string, description: string, fields: array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>, icon: string, display_name: string, provider_type: string, field_sequence: array<int, string>, provider_sequence: int}
	 */
	public static function get_options() {
		return [
			'title'             => __( 'Netcore Connection', 'suremails' ),
			'description'       => __( 'Enter the details below to connect with your Netcore account.', 'suremails' ),
			'fields'            => self::get_specific_fields(),
			'icon'              => 'NetcoreIcon',
			'display_name'      => __( 'Netcore', 'suremails' ),
			'provider_type'     => 'free',
			'field_sequence'    => [ 'connection_title', 'api_key', 'region', 'from_email', 'force_from_email', 'from_name', 'force_from_name', 'priority' ],
			'provider_sequence' => 36,
		];
	}

	/**
	 * Get the specific fields for the Netcore connection.
	 *
	 * @return array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>
	 */
	public static function get_specific_fields() {
		return [
			'api_key' => [
				'required'    => true,
				'datatype'    => 'string',
				'label'       => __( 'API Key', 'suremails' ),
				'input_type'  => 'password',
				'placeholder' => __( 'Enter your Netcore API key', 'suremails' ),
				'encrypt'     => true,
			],
			'region'  => [
				'required'    => true,
				'datatype'    => 'string',
				'label'       => __( 'Region', 'suremails' ),
				'input_type'  => 'select',
				'options'     => [
					[
						'label' => 'US',
						'value' => 'US',
					],
					[
						'label' => 'EU',
						'value' => 'EU',
					],
				],
				'default'     => 'US',
				'placeholder' => __( 'Select your Netcore region', 'suremails' ),
				'help_text'   => __( 'Select the endpoint you want to use for sending messages. If you are subject to EU laws, you may need to use the EU region.', 'suremails' ),
			],
		];
	}

	/**
	 * Format recipients as an array of arrays with name and email.
	 *
	 * @param array<int, array{name: string, email: string}> $recipients The recipients.
	 * @return array<int, array{name: string, email: string}> The formatted recipients.
	 */
	protected function format_recipients_array( array $recipients ) {
		$result = [];
		foreach ( $recipients as $recipient ) {
			if ( is_array( $recipient ) && ! empty( $recipient['email'] ) ) {
				$result[] = [
					'name'  => $recipient['name'] ?? '', // @phpstan-ignore nullCoalesce.offset
					'email' => sanitize_email( $recipient['email'] ),
				];
			}
		}
		return $result;
	}

	/**
	 * Retrieve the reply-to email address.
	 *
	 * @param mixed $reply_to The reply-to data.
	 * @return string|false The reply-to email address.
	 */
	protected function get_reply_to( $reply_to ) {
		if ( is_array( $reply_to ) ) {
			$first = reset( $reply_to );
			if ( is_array( $first ) && isset( $first['email'] ) ) {
				return sanitize_email( $first['email'] );
			}
		}
		return false;
	}

	/**
	 * Process attachments to be Netcore-compatible.
	 *
	 * @param array<int, string> $attachments The attachments.
	 * @return array<int, array{name: string|false, content: string|false}> The processed attachments.
	 */
	private function get_attachments( array $attachments ) {
		$result = [];
		foreach ( $attachments as $attachment ) {

			$attachment_values = ProviderHelper::get_attachment( $attachment );

			if ( ! $attachment_values ) {
				continue;
			}

			$result[] = [
				'name'    => $attachment_values['name'] ?? '',
				'content' => $attachment_values['blob'] ?? '',
			];
		}
		return $result;
	}
}
