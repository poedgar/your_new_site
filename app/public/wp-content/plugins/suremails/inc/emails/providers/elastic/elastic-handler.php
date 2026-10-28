<?php
/**
 * ElasticHandler.php
 *
 * Handles sending emails using Elastic Email.
 *
 * @package SureMails\Inc\Emails\Providers\ElasticEmail
 */

namespace SureMails\Inc\Emails\Providers\Elastic;

use Exception;
use SureMails\Inc\Emails\Handler\ConnectionHandler;
use SureMails\Inc\Emails\ProviderHelper;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ElasticHandler
 *
 * Implements the ConnectionHandler to handle Elastic Email sending and authentication.
 */
class ElasticHandler implements ConnectionHandler {

	/**
	 * Connection data.
	 *
	 * @var array<string, string|int|bool>
	 */
	protected $connection_data;

	/**
	 * URL to make an API request to.
	 *
	 * @since 1.2.0
	 *
	 * @var string
	 */
	protected $url = 'https://api.elasticemail.com/v4';

	/**
	 * Payload
	 *
	 * @var array<string, string|array<string, string|array<int|string, string|array<string, bool|string>>>>
	 * @since 1.2.0
	 */
	protected $payload = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, string|int|bool> $connection_data Connection data.
	 */
	public function __construct( array $connection_data ) {
		$this->connection_data = $connection_data;
	}

	/**
	 * Get the payload for the Elastic Email API request.
	 *
	 * @since 1.2.0
	 * @return array<string, string|array<string, string|array<int|string, string|array<string, bool|string>>>> Payload.
	 */
	public function get_payload() {
		return $this->payload;
	}

	/**
	 * Get the headers for the Elastic Email API request.
	 *
	 * @param string $api_key API key.
	 * @return array<string, string> Headers.
	 * @since 1.2.0
	 */
	public function get_headers( $api_key ) {
		return [
			'Accept'                => 'application/json',
			'Content-Type'          => 'application/json',
			'X-ElasticEmail-ApiKey' => sanitize_text_field( $api_key ),
		];
	}

	/**
	 * Get API URL.
	 *
	 * @param string $type API type.
	 * @return string API URL.
	 *
	 * @since 1.2.0
	 */
	public function get_url( $type ) {
		$endpoints = [
			'transactional' => '/emails/transactional',
			'marketing'     => '/emails',
		];

		$type = empty( $type ) ? 'transactional' : $type;

		return $this->url . $endpoints[ $type ];
	}

	/**
	 * Authenticate the Elastic Email connection.
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
			'message'    => __( 'Elastic Email connection authenticated successfully.', 'suremails' ),
			'error_code' => 200,
		];
	}

	/**
	 * Send email using Elastic Email.
	 *
	 * @param array<string, string|array<int, string>>                                                                                                                                                                                                                                                                                                                                                                                                                                                                             $atts Email attributes.
	 * @param int                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  $log_id Log ID.
	 * @param array<string, string|int|bool>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       $connection Connection data.
	 * @param array{to: array<int, array{name: string, email: string}>, headers: array{from: array{name: string, email: string}, cc: array<int, array{name: string, email: string}>, bcc: array<int, array{name: string, email: string}>, reply_to: array<int, array{name: string, email: string}>, content_type: string, charset: string, boundary: string, x_mailer: string, extra_headers: array<string, string>}, message: string, attachments: array<int, string>, subject: string, uploaded_attachments: array<int, string>} $processed_data Processed email data.
	 *
	 * @return array{success: bool, message: string, send: bool}
	 */
	public function send( array $atts, $log_id, array $connection, $processed_data ) {

		try {
			/**
			 * The email message body.
			 *
			 * @var string $message
			 */
			$message = $atts['message'] ?? '';
			$this->set_content( $message, $processed_data['headers']['content_type'] );

			if ( ! empty( $processed_data['attachments'] ) ) {
				$this->set_attachments( $processed_data['attachments'] );
			}

			/**
			 * The raw email subject.
			 *
			 * @var string $raw_subject
			 */
			$raw_subject = $atts['subject'] ?? '';
			$this->set_subject( sanitize_text_field( $raw_subject ) );

			$this->set_reply_to( $processed_data['headers']['reply_to'] );

			$this->set_from( (string) ( $connection['from_email'] ?? '' ), (string) ( $connection['from_name'] ?? '' ) );

			$api_key     = (string) ( $connection['api_key'] ?? '' );
			$all_headers = $this->get_headers( $api_key );

			foreach ( $all_headers as $key => $value ) {
				$this->set_body_header( $key, $value );
			}

			$type = (string) ( $connection['mail_type'] ?? 'transactional' );

			$this->set_recipients(
				[
					'to'  => $processed_data['to'],
					'cc'  => $processed_data['headers']['cc'],
					'bcc' => $processed_data['headers']['bcc'],
				],
				$type
			);

			$response = $this->send_api( $this->get_url( $type ), $this->get_headers( $api_key ) );

			if ( is_wp_error( $response ) ) {
				return [
					'success' => false,
					'message' => sprintf(
						/* translators: %s: Error message from Elastic Email */
						__( 'Email sending failed via Elastic Email. Error: %s', 'suremails' ),
						$response->get_error_message()
					),
					'send'    => false,
				];
			}

			return [
				'success' => true,
				'message' => __( 'Email sent successfully via Elastic Email.', 'suremails' ),
				'send'    => true,
			];
		} catch ( Exception $e ) {
			return [
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Error message from Elastic Email */
					__( 'Email sending failed via Elastic Email. Error: %s', 'suremails' ),
					$e->getMessage()
				),
				'send'    => false,
			];
		}
	}

	/**
	 * Set the content of the email.
	 *
	 * @param string $content Email content.
	 * @param string $type Content type.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function set_content( $content, $type ) {
		if ( empty( $content ) ) {
			return;
		}

		$data = [
			[
				'ContentType' => $type === 'text/plain' ? 'PlainText' : 'HTML',
				'Content'     => $content,
			],
		];

		$this->set_payload(
			[
				'Content' => [
					'Body' => $data,
				],
			]
		);
	}

	/**
	 * Set the attachments.
	 *
	 * @param array<int, string> $attachments Attachments.
	 * @since 1.2.0
	 * @return void
	 */
	public function set_attachments( $attachments ) {
		if ( empty( $attachments ) ) {
			return;
		}

		$data = [];

		foreach ( $attachments as $attachment ) {

			$attachment_values = ProviderHelper::get_attachment( $attachment );

			if ( ! $attachment_values ) {
				continue;
			}

			$data[] = [
				'Name'          => $attachment_values['name'] ?? '',
				'BinaryContent' => $attachment_values['blob'] ?? '',
				'ContentType'   => $attachment_values['type'] ?? '',
			];
		}

		if ( ! empty( $data ) ) {
			$this->set_payload(
				[
					'Content' => [
						'Attachments' => $data,
					],
				]
			);
		}
	}

	/**
	 * Set the subject of the email.
	 *
	 * @param string $subject Email subject.
	 * @since 1.2.0
	 * @return void
	 */
	public function set_subject( $subject ) {
		// Set the subject.
		$this->set_payload(
			[
				'Content' => [
					'Subject' => $subject,
				],
			]
		);
	}

	/**
	 * This mailer supports email-related custom headers inside a body of the message.
	 *
	 * @since 1.2.0
	 *
	 * @param string $name  Header name.
	 * @param string $value Header value.
	 * @return void
	 */
	public function set_body_header( $name, $value ) {

		$name = sanitize_text_field( $name );

		if ( empty( $name ) ) {
			return;
		}

		$this->set_payload(
			[
				'Content' => [
					'Headers' => [
						$name => $value,
					],
				],
			]
		);
	}

	/**
	 * Set the From information for an email.
	 *
	 * @since 1.2.0
	 *
	 * @param string $email The sender email address.
	 * @param string $name  The sender name.
	 * @return void
	 */
	public function set_from( $email, $name ) {

		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return;
		}

		$this->set_payload(
			[
				'Content' => [
					'From' => ProviderHelper::address_format(
						[
							'email' => $email,
							'name'  => $name,
						]
					),
				],
			]
		);
	}

	/**
	 * Set email recipients: to, cc, bcc.
	 *
	 * @since 1.2.0
	 *
	 * @param array{to: array<int, array{name: string, email: string}>, cc: array<int, array{name: string, email: string}>, bcc: array<int, array{name: string, email: string}>} $recipients Email recipients.
	 * @param string                                                                                                                                                             $email_type Email type.
	 * @return void
	 */
	public function set_recipients( $recipients, $email_type ) {

		if ( empty( $recipients['to'] ) ) {
			return;
		}

		$email_type = empty( $email_type ) ? 'transactional' : $email_type;

		if ( 'transactional' !== $email_type ) {
			$data = [
				[
					'Email' => $recipients['to'][0]['email'],
				],
			];
			$this->set_payload(
				[
					'Recipients' => $data,
				]
			);
			return;
		}

		$recipient_mappings = [
			'to'  => 'To',
			'cc'  => 'CC',
			'bcc' => 'BCC',
		];

		$allowed_types = array_keys( $recipient_mappings );
		$data          = [];

		foreach ( $recipients as $type => $emails ) {
			if (
				! in_array( $type, $allowed_types, true ) ||
				empty( $emails ) ||
				! is_array( $emails )
			) {
				continue;
			}

			$field = $recipient_mappings[ $type ];

			foreach ( $emails as $email ) {
				if ( ! isset( $email['email'] ) || ! filter_var( $email['email'], FILTER_VALIDATE_EMAIL ) ) { // @phpstan-ignore isset.offset
					continue;
				}

				$data[ $field ][] = ProviderHelper::address_format( $email );
			}
		}

		if ( ! empty( $data ) ) {
			$this->set_payload(
				[
					'Recipients' => $data,
				]
			);
		}
	}

	/**
	 * Set the Reply To information for an email.
	 *
	 * @since 1.2.0
	 *
	 * @param array<int, array{name: string, email: string}> $emails Reply To email addresses.
	 * @return void
	 */
	public function set_reply_to( $emails ) {

		if ( empty( $emails ) ) {
			return;
		}

		$data = [];

		foreach ( $emails as $email ) {
			// Expect $email to be an array with 'name' and 'email' keys.
			if ( empty( $email['email'] ) || ! filter_var( $email['email'], FILTER_VALIDATE_EMAIL ) ) {
				continue;
			}

			$data[] = ProviderHelper::address_format( $email );
		}

		if ( ! empty( $data ) ) {
			$this->set_payload(
				[
					'Content' => [
						'ReplyTo' => $data[0],
					],
				]
			);
		}
	}

	/**
	 * Get Elastic Email specific options.
	 *
	 * @return array{title: string, description: string, fields: array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>, icon: string, display_name: string, provider_type: string, field_sequence: array<int, string>, provider_sequence: int}
	 */
	public static function get_options() {
		return [
			'title'             => __( 'Elastic Email Connection', 'suremails' ),
			'description'       => __( 'Enter the details below to connect with your Elastic Email account.', 'suremails' ),
			'fields'            => self::get_specific_fields(),
			'icon'              => 'ElasticEmailIcon',
			'display_name'      => __( 'Elastic Email', 'suremails' ),
			'provider_type'     => 'free',
			'field_sequence'    => [ 'connection_title', 'api_key', 'mail_type', 'from_email', 'force_from_email', 'from_name', 'force_from_name', 'priority' ],
			'provider_sequence' => 25,
		];
	}

	/**
	 * Get the specific schema fields for Elastic Email.
	 *
	 * @return array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>
	 */
	public static function get_specific_fields() {
		return [
			'api_key'   => [
				'required'    => true,
				'datatype'    => 'string',
				'label'       => __( 'API Key', 'suremails' ),
				'input_type'  => 'password',
				'placeholder' => __( 'Enter your Elastic Email API key', 'suremails' ),
				'encrypt'     => true,
			],
			'mail_type' => [
				'default'     => [
					'label' => __( 'Transactional Email', 'suremails' ),
					'value' => 'transactional',
				],
				'required'    => false,
				'datatype'    => 'string',
				'help_text'   => sprintf(       // translators: %s: www.mailgun.com/about/regions/ URL.
					__( 'Select the type of email you will be sending using this connection. Choose Transactional Email for emails like password resets, order confirmations, etc. Select Marketing Email for sending bulk emails like newsletters, broadcasts, etc. %1$sLearn more%2$s', 'suremails' ),
					'<a href="' . esc_url( 'https://suremails.com/docs/elastic-email?utm_campaign=suremails&utm_medium=suremails-dashboard' ) . '" target="_blank">',
					'</a>'
				),
				'label'       => __( 'Email Type', 'suremails' ),
				'input_type'  => 'select',
				'placeholder' => __( 'Select Email Type', 'suremails' ),
				'options'     => [
					[
						'label' => __( 'Transactional Email', 'suremails' ),
						'value' => 'transactional',
					],
					[
						'label' => __( 'Marketing Email', 'suremails' ),
						'value' => 'marketing',
					],
				],
			],
		];
	}

	/**
	 * Set the request params, that goes to the body of the HTTP request.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $param Key=>value of what should be sent to a 3rd party API.
	 *
	 * @internal param array $params
	 * @return void
	 */
	protected function set_payload( $param ) {

		$this->payload = array_merge_recursive( $this->payload, $param );
	}

	/**
	 * Retrieve data from Elastic Email API using GET requests.
	 *
	 * @param string                $url     The full API URL.
	 * @param array<string, string> $headers The request headers.
	 * @return array<string, string|int>|WP_Error The decoded response data or a WP_Error on failure.
	 */
	private function send_api( $url, array $headers ) {
		$body = wp_json_encode( $this->get_payload() );
		if ( false === $body ) {
			return new WP_Error( 'api_error', __( 'Email sending failed via Elastic Email. Failed to encode email payload to JSON.', 'suremails' ) );
		}
		$response = wp_safe_remote_post(
			$url,
			[
				'headers' => $headers,
				'body'    => ! $body ? '{}' : $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( in_array( $response_code, [ 401, 403 ], true ) ) {
			return new WP_Error( 'unauthorized', __( 'Email sending failed via Elastic Email. Unauthorized: API key invalid or insufficient permissions.', 'suremails' ), $response_code );
		}

		$body         = wp_remote_retrieve_body( $response );
		$decoded_body = json_decode( $body, true );

		if ( null === $decoded_body ) {
			return new WP_Error( 'json_decode_error', __( 'Failed to decode JSON response from Elastic Email.', 'suremails' ), $response_code );
		}

		if ( $response_code >= 400 ) {
			return new WP_Error(
				'api_error',
				sprintf(
					/* translators: 1: Error log */
					__( 'Email sending failed via Elastic Email. Error: %1$s', 'suremails' ),
					$body
				),
				$response_code
			);
		}

		return $decoded_body;
	}
}
