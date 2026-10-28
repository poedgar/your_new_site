<?php
/**
 * SureMails ConnectionHandler
 *
 * This file contains the the common functionalities for all the email providers.
 *
 * @package SureMails\Inc\Emails
 */

namespace SureMails\Inc\Emails\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Interface for handling email connections.
 */
interface ConnectionHandler {
	/**
	 * Authenticate the connection.
	 *
	 * @return array{success: bool, message: string, error_code?: int} The result of the authentication attempt.
	 */
	public function authenticate();

	/**
	 * Send an email.
	 *
	 * @param array<string, string|array<int, string>>                                                                                                                                                                                                                                                                                                                                                                                                                                                                             $atts The email attributes, such as 'to', 'from', 'subject', 'message', 'headers', 'attachments', etc.
	 * @param int|null                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             $log_id used to find the log from database.
	 * @param array<string, string|int|bool>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       $connection The connection data/credentials used to connect and send data.
	 * @param array{to: array<int, array{name: string, email: string}>, headers: array{from: array{name: string, email: string}, cc: array<int, array{name: string, email: string}>, bcc: array<int, array{name: string, email: string}>, reply_to: array<int, array{name: string, email: string}>, content_type: string, charset: string, boundary: string, x_mailer: string, extra_headers: array<string, string>}, message: string, attachments: array<int, string>, subject: string, uploaded_attachments: array<int, string>} $processed_data The processed data.
	 * @return array{success: bool, message: string, send?: bool, error_code?: int, email_simulated?: bool} The result of the email send operation.
	 */
	public function send( array $atts, $log_id, array $connection, array $processed_data );

	/**
	 * Return the option configuration for this provider.
	 *
	 * The returned array should have the following structure:
	 * [
	 *     'title'         => (string) Provider title,
	 *     'description'   => (string) A brief description,
	 *     'fields'        => (array) A merged list of base fields plus provider-specific fields,
	 *     'logo'          => (string) URL/path to the provider logo,
	 *     'provider_type' => (string) e.g. 'free', 'soon', or 'paid'
	 * ]
	 *
	 * @return array{title: string, description: string, fields: array<string, array{required?: bool, datatype?: string, help_text?: string, label?: string, input_type?: string, placeholder?: string, encrypt?: bool, default?: bool|string|array{label: string, value: string}, depends_on?: array<int, string>, options?: array<string, string>|array<int, array{value: string, label: string}>, read_only?: bool, copy_button?: bool, class_name?: string, button_text?: string, alt_button_text?: string, on_click?: array{params: array<int|string, string>}, size?: string}>, display_name?: string, icon?: string, provider_type: string, field_sequence?: array<int, string>, provider_sequence?: int}
	 */
	public static function get_options();
}
