<?php
/**
 * Connection Handler Factory
 *
 * @since 0.0.1
 * @package suremails
 */

namespace SureMails\Inc\Emails\Handler;

use SureMails\Inc\API\SaveTestConnection;
use SureMails\Inc\Emails\Providers\Simulator\SimulationHandler;
use SureMails\Inc\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Factory class to create appropriate connection handler based on type.
 */
class ConnectionHandlerFactory {
	/**
	 * Create appropriate connection handler based on type.
	 *
	 * @param array<string, string|int|bool> $connection_data Connection data.
	 * @since 0.0.1
	 * @return ConnectionHandler|null
	 */
	public static function create( array $connection_data ) {

		// Check if simulation is enabled. If enabled, return simulation handler.
		if ( self::should_use_simulation() ) {
			$handler = new SimulationHandler( [] );
			if ( $handler instanceof ConnectionHandler ) {
				return $handler;
			}
		}
		$type          = (string) ( $connection_data['type'] ?? '' );
		$handler_class = 'SureMails\\Inc\\Emails\\Providers\\' . strtoupper( $type ) . '\\' . ucfirst( strtolower( $type ) ) . 'Handler';

		if ( class_exists( $handler_class ) ) {
			$handler = new $handler_class( $connection_data );

			// Ensure the handler implements ConnectionHandler.
			if ( $handler instanceof ConnectionHandler ) {
				return $handler;
			}
		}

		return null;
	}

	/**
	 * Determine if simulation mode should be used and it's not a invokation from Save Test Connection.
	 * The simulation mode is used to test email sending without actually sending emails.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	private static function should_use_simulation() {
		return Settings::instance()->get_email_simulation_status()
			&& ! SaveTestConnection::instance()->saving_connection;
	}
}
