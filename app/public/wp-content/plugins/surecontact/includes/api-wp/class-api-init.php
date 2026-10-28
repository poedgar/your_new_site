<?php
/**
 * Initialize API
 *
 * Registers all REST API routes for SureContact plugin
 *
 * @since 0.0.1
 *
 * @package SureContact\API_WP
 */

namespace SureContact\API_WP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Api_Init class
 *
 * @since 0.0.1
 */
class Api_Init {

	/**
	 * Instance
	 *
	 * @since 0.0.1
	 *
	 * @var Api_Init
	 */
	private static $instance = null;

	/**
	 * Get instance
	 *
	 * @since 0.0.1
	 *
	 * @return Api_Init
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	private function __construct() {
		// Register routes immediately - this is called during rest_api_init.
		// so we don't need to add another hook.
		$this->register_routes();
	}

	/**
	 * Register API routes
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function register_routes() {
		$controllers = array(
			'\SureContact\API_WP\Field_Mapping',
			'\SureContact\API_WP\Bulk_Sync_Api',
			'\SureContact\API_WP\Integration_Rules_API',
			'\SureContact\API_WP\Settings_API',
			'\SureContact\API_WP\Lists_Tags_API',
			'\SureContact\API_WP\Queue_Rest_Controller',
		);

		foreach ( $controllers as $controller_class ) {
			if ( class_exists( $controller_class ) ) {
				$controller = $controller_class::instance();
				$controller->register_routes();
			}
		}
	}
}
