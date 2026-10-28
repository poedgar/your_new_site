<?php
/**
 * Cookies class
 *
 * Handles cookie-related REST API endpoints for the SureCookie plugin.
 * Delegates business logic to CookieService.
 *
 * @package SureCookie\Inc\API
 */

namespace SureCookie\Inc\API;

use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Services\CookieService;
use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Cookies
 *
 * Handles cookie-related REST API endpoints.
 */
class Cookies extends Base {
	use GetInstance;

	/**
	 * Route Get Logs
	 */
	protected const GET_SCANNED_COOKIES = '/cookies/scanned-results';

	/**
	 * Route Get Custom Cookies
	 */
	protected const GET_CUSTOM_COOKIES = '/cookies/custom-cookies';

	/**
	 * Route Create Custom Cookie
	 */
	protected const CREATE_CUSTOM_COOKIE = '/cookies/custom-cookie';

	/**
	 * Route Update Custom Cookie
	 */
	protected const UPDATE_CUSTOM_COOKIE = '/cookies/custom-cookie/(?P<id>[a-zA-Z0-9_-]+)';

	/**
	 * Route Delete Custom Cookie
	 */
	protected const DELETE_CUSTOM_COOKIE = '/cookies/custom-cookie/(?P<id>[a-zA-Z0-9_-]+)';

	/**
	 * Route Update Scanned Cookie Category
	 */
	protected const UPDATE_SCANNED_COOKIE_CATEGORY = '/cookies/scanned-cookie/category';

	/**
	 * Route Delete Scanned Cookie. Addressed by name, domain and category: a scanned
	 * row has no generated id.
	 */
	protected const DELETE_SCANNED_COOKIE = '/cookies/scanned-cookie';

	/**
	 * Route: assign multiple cookies (custom and/or scanned) to one category.
	 *
	 * @since 1.3.0
	 */
	protected const BULK_UPDATE_COOKIE_CATEGORY = '/cookies/bulk-category';

	/**
	 * Route Forget Declared Service. A declared cookie has no stored row of its
	 * own on every path, so it is cleared by service, not by name.
	 *
	 * @since 1.5.1
	 */
	protected const FORGET_DECLARED_SERVICE = '/cookies/declared-service';

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			self::GET_SCANNED_COOKIES,
			[
				'methods'             => WP_REST_Server::READABLE, // GET method.
				'callback'            => [ $this, 'get_scanned_cookies' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::GET_CUSTOM_COOKIES,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_custom_cookies' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::CREATE_CUSTOM_COOKIE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_custom_cookie' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'name'        => [
						'required' => true,
						'type'     => 'string',
					],
					'category'    => [
						'required' => true,
						'type'     => 'string',
					],
					'description' => [
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					],
					'duration'    => [
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					],
					'provider'    => [
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					],
					'purpose'     => [
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					],
					'domain'      => [
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					],
					'type'        => [
						'required' => false,
						'type'     => 'string',
						'default'  => 'custom',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::UPDATE_CUSTOM_COOKIE,
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_custom_cookie' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'id'          => [
						'required' => true,
						'type'     => 'string',
					],
					'name'        => [
						'required' => false,
						'type'     => 'string',
					],
					'category'    => [
						'required' => false,
						'type'     => 'string',
					],
					'description' => [
						'required' => false,
						'type'     => 'string',
					],
					'duration'    => [
						'required' => false,
						'type'     => 'string',
					],
					'provider'    => [
						'required' => false,
						'type'     => 'string',
					],
					'purpose'     => [
						'required' => false,
						'type'     => 'string',
					],
					'domain'      => [
						'required' => false,
						'type'     => 'string',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::DELETE_CUSTOM_COOKIE,
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_custom_cookie' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'id' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::UPDATE_SCANNED_COOKIE_CATEGORY,
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_scanned_cookie_category' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'cookie_name'      => [
						'required' => true,
						'type'     => 'string',
					],
					'current_category' => [
						'required' => true,
						'type'     => 'string',
					],
					// Optional since corrections arrived: a call may change a field
					// without moving the cookie between categories.
					'new_category'     => [
						'required' => false,
						'type'     => 'string',
					],
					// Optional: tells two same-named cookies apart when both sit
					// in the source category. Older clients that omit it keep the
					// previous name-only match.
					'domain'           => [
						'required' => false,
						'type'     => 'string',
					],
					// Corrections to what the scanner reported. Name and domain are
					// absent deliberately: they are the cookie's identity.
					'purpose'          => [
						'required' => false,
						'type'     => 'string',
					],
					'duration'         => [
						'required' => false,
						'type'     => 'string',
					],
					'provider'         => [
						'required' => false,
						'type'     => 'string',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::DELETE_SCANNED_COOKIE,
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_scanned_cookie' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'cookie_name' => [
						'required' => true,
						'type'     => 'string',
					],
					'category'    => [
						'required' => true,
						'type'     => 'string',
					],
					'domain'      => [
						'required' => false,
						'type'     => 'string',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::FORGET_DECLARED_SERVICE,
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'forget_declared_service' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'service_slug' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::BULK_UPDATE_COOKIE_CATEGORY,
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'bulk_update_cookie_category' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'items'        => [
						'required' => true,
						'type'     => 'array',
						// Cap mirrored by CookieService::MAX_BULK_ITEMS.
						'maxItems' => 100,
						'items'    => [ 'type' => 'object' ],
					],
					'new_category' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);
	}

	/**
	 * Get scanned cookies.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @return void
	 */
	public function get_scanned_cookies( $request ): void {
		$service = new CookieService();
		$result  = $service->get_scanned_cookies();

		$result['success'] ? SendJson::success( $result ) : SendJson::error( $result );
	}

	/**
	 * Get all custom cookies.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 0.0.1
	 * @return void
	 */
	public function get_custom_cookies( $request ): void {
		$service = new CookieService();
		$result  = $service->get_custom_cookies();

		SendJson::success( $result );
	}

	/**
	 * Create a new custom cookie.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 0.0.1
	 * @return void
	 */
	public function create_custom_cookie( $request ): void {
		try {
			$service = new CookieService();
			$result  = $service->create_custom_cookie( $request->get_params() );

			$result['success'] ? SendJson::success( $result ) : SendJson::error( $result );
		} catch ( \Exception $e ) {
			SendJson::error(
				[
					'message' => __( 'Failed to create custom cookie: ', 'surecookie' ) . $e->getMessage(),
				]
			);
		}
	}

	/**
	 * Update an existing custom cookie.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 0.0.1
	 * @return void
	 */
	public function update_custom_cookie( $request ): void {
		try {
			$cookie_id = sanitize_text_field( $request->get_param( 'id' ) );
			$service   = new CookieService();
			$result    = $service->update_custom_cookie( $cookie_id, $request->get_params() );

			$result['success'] ? SendJson::success( $result ) : SendJson::error( $result );
		} catch ( \Exception $e ) {
			SendJson::error(
				[ 'message' => __( 'Failed to update custom cookie: ', 'surecookie' ) . $e->getMessage() ]
			);
		}
	}

	/**
	 * Delete a custom cookie.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 0.0.1
	 * @return void
	 */
	public function delete_custom_cookie( $request ): void {
		try {
			$cookie_id = sanitize_text_field( $request->get_param( 'id' ) );
			$service   = new CookieService();
			$result    = $service->delete_custom_cookie( $cookie_id );

			$result['success'] ? SendJson::success( $result ) : SendJson::error( $result );
		} catch ( \Exception $e ) {
			SendJson::error(
				[ 'message' => __( 'Failed to delete custom cookie: ', 'surecookie' ) . $e->getMessage() ]
			);
		}
	}

	/**
	 * Remove a scanned cookie.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 1.5.0
	 * @return void
	 */
	public function delete_scanned_cookie( $request ): void {
		try {
			$cookie_name = sanitize_text_field( (string) $request->get_param( 'cookie_name' ) );
			$category    = sanitize_text_field( (string) $request->get_param( 'category' ) );
			$domain      = sanitize_text_field( (string) $request->get_param( 'domain' ) );

			$service = new CookieService();
			$result  = $service->delete_scanned_cookie( $cookie_name, $category, $domain );

			$result['success'] ? SendJson::success( $result ) : SendJson::error( $result );
		} catch ( \Exception $e ) {
			SendJson::error(
				[ 'message' => __( 'Failed to remove cookie: ', 'surecookie' ) . $e->getMessage() ]
			);
		}
	}

	/**
	 * Stop publishing the cookies a catalog service declares.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 1.5.1
	 * @return void
	 */
	public function forget_declared_service( $request ): void {
		try {
			$service = sanitize_text_field( (string) $request->get_param( 'service_slug' ) );

			$result = ( new CookieService() )->forget_declared_service( $service );

			$result['success'] ? SendJson::success( $result ) : SendJson::error( $result );
		} catch ( \Exception $e ) {
			SendJson::error(
				[ 'message' => __( 'Failed to remove service cookies: ', 'surecookie' ) . $e->getMessage() ]
			);
		}
	}

	/**
	 * Update category of a scanned cookie.
	 *
	 * Moves a cookie from one category to another in the scanned cookies option.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 0.0.1
	 * @return void
	 */
	public function update_scanned_cookie_category( $request ): void {
		try {
			$cookie_name      = sanitize_text_field( $request->get_param( 'cookie_name' ) );
			$current_category = sanitize_text_field( $request->get_param( 'current_category' ) );
			$domain           = sanitize_text_field( (string) $request->get_param( 'domain' ) );

			// Only the fields the client actually sent. Defaulting an absent one to ''
			// would clear a correction the caller never mentioned.
			$fields  = [
				'new_category' => 'category',
				'purpose'      => 'purpose',
				'duration'     => 'duration',
				'provider'     => 'provider',
			];
			$changes = [];

			foreach ( $fields as $param => $field ) {
				if ( $request->get_param( $param ) !== null ) {
					$changes[ $field ] = (string) $request->get_param( $param );
				}
			}

			$service = new CookieService();
			$result  = $service->update_scanned_cookie( $cookie_name, $current_category, $changes, $domain );

			$result['success'] ? SendJson::success( $result ) : SendJson::error( $result );
		} catch ( \Exception $e ) {
			SendJson::error(
				[ 'message' => __( 'Failed to update cookie category: ', 'surecookie' ) . $e->getMessage() ]
			);
		}
	}

	/**
	 * Assign multiple cookies (custom and/or scanned) to one category.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 1.3.0
	 * @return void
	 */
	public function bulk_update_cookie_category( $request ): void {
		try {
			$items        = $request->get_param( 'items' );
			$new_category = sanitize_text_field( $request->get_param( 'new_category' ) );

			$service = new CookieService();
			$result  = $service->bulk_update_cookie_category( is_array( $items ) ? $items : [], $new_category );

			$result['success'] ? SendJson::success( $result ) : SendJson::error( $result );
		} catch ( \Exception $e ) {
			SendJson::error(
				[ 'message' => __( 'Failed to update cookie categories: ', 'surecookie' ) . $e->getMessage() ]
			);
		}
	}
}
