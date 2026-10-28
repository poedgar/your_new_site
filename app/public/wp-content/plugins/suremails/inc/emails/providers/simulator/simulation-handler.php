<?php
/**
 * Phpmail Handler.php
 *
 * Handles sending emails using PHP Mail.
 *
 * @package SureMails\Inc\Emails\Providers\Simulation
 */

namespace SureMails\Inc\Emails\Providers\Simulator;

use SureMails\Inc\Emails\Handler\ConnectionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SimulationHandler
 *
 * Implements the ConnectionHandler to handle Phpmail Mail email sending and authentication.
 */
class SimulationHandler implements ConnectionHandler {

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
	 * @return array{success: bool, message: string}
	 */
	public function authenticate() {

		return [
			'success' => false,
			'message' => '',
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
	 * @return array{success: bool, message: string, send: bool, email_simulated: bool}
	 */
	public function send( array $atts, $log_id, array $connection, $processed_data ) {
			return [
				'success'         => true,
				'message'         => __( 'Email sending was simulated, but no email was actually sent.', 'suremails' ),
				'send'            => true,
				'email_simulated' => true,
			];
	}

	/**
	 * Get the PHP Mail connection options.
	 *
	 * @return array{}
	 */
	public static function get_options() {
		return [];
	}
}
