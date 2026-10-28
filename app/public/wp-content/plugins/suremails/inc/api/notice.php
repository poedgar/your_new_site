<?php
/**
 * Disable admin notice API.
 *
 * @package SureMails\Inc\API
 * @since 1.7.0
 */

namespace SureMails\Inc\API;

use SureMails\Inc\Traits\Instance;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Notice
 *
 * Handles the notice dismissal REST API endpoints.
 */
class Notice extends Api_Base {
	use Instance;

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '/disable-notice';

	/**
	 * Register API routes.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function register_routes() {
		// Configuration notice dismissal (15 days).
		register_rest_route(
			$this->get_api_namespace(),
			$this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'handle_configuration_notice' ],
					'permission_callback' => [ $this, 'validate_permission' ],
				],
			]
		);

		// Menu location notice dismissal (permanent).
		register_rest_route(
			$this->get_api_namespace(),
			'/disable-menu-location-notice',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'handle_menu_location_notice' ],
					'permission_callback' => [ $this, 'validate_permission' ],
				],
			]
		);

		// SureContact cross-sell promo dismissal (15 days).
		register_rest_route(
			$this->get_api_namespace(),
			'/disable-surecontact-promo',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'handle_surecontact_promo' ],
					'permission_callback' => [ $this, 'validate_permission' ],
				],
			]
		);

		// SureContact SMTP launch promo dismissal (15 days).
		register_rest_route(
			$this->get_api_namespace(),
			'/disable-surecontact-smtp-promo',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'handle_surecontact_smtp_promo' ],
					'permission_callback' => [ $this, 'validate_permission' ],
				],
			]
		);
	}

	/**
	 * Disable configuration notice for 15 days.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The request object.
	 * @return WP_REST_Response
	 */
	public function handle_configuration_notice( $request ) {
		// Calculate "now + 15 days".
		$expiry_time = time() + ( 1296000 );
		update_option( 'suremails_notice_dismissal_time', $expiry_time );

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Notice disabled for 15 days.', 'suremails' ),
			]
		);
	}

	/**
	 * Disable menu location notice permanently (one-time dismissal).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The request object.
	 * @return WP_REST_Response
	 */
	public function handle_menu_location_notice( $request ) {
		// Set a permanent flag - notice will never show again once dismissed.
		update_option( 'suremails_menu_notice_dismissed', true );

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Menu location notice dismissed.', 'suremails' ),
			]
		);
	}

	/**
	 * Dismiss the SureContact cross-sell promo for 15 days.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The request object.
	 * @return WP_REST_Response
	 */
	public function handle_surecontact_promo( $request ) {
		// Calculate "now + 15 days".
		$expiry_time = time() + ( 1296000 );
		update_option( 'suremails_surecontact_promo_dismissal_time', $expiry_time );

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'SureContact promo dismissed for 15 days.', 'suremails' ),
			]
		);
	}

	/**
	 * Dismiss the SureContact SMTP launch promo for 15 days.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The request object.
	 * @return WP_REST_Response
	 */
	public function handle_surecontact_smtp_promo( $request ) {
		// Calculate "now + 15 days".
		$expiry_time = time() + ( 1296000 );
		update_option( 'suremails_surecontact_smtp_promo_dismissal_time', $expiry_time );

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'SureContact SMTP promo dismissed for 15 days.', 'suremails' ),
			]
		);
	}
}

// Initialize the Notice singleton.
Notice::instance();
