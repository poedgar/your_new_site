<?php
/**
 * CookieCategories class
 *
 * Handles cookie category REST API endpoints for the SureCookie plugin.
 * Delegates business logic to CookieCategoryService.
 *
 * @package SureCookie\Inc\API
 */

namespace SureCookie\Inc\API;

use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Functions\Validate;
use SureCookie\Inc\Services\CookieCategoryService;
use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class CookieCategories
 *
 * Handles cookie category REST API endpoints.
 */
class CookieCategories extends Base {
	use GetInstance;

	/**
	 * Route Get Cookie Categories
	 */
	protected const GET_CATEGORIES = '/cookies/categories';

	/**
	 * Route Create Cookie Category
	 */
	protected const CREATE_CATEGORY = '/cookies/category';

	/**
	 * Route Update Cookie Category
	 */
	protected const UPDATE_CATEGORY = '/cookies/category/(?P<id>[a-zA-Z0-9_-]+)';

	/**
	 * Route Delete Cookie Category
	 */
	protected const DELETE_CATEGORY = '/cookies/category/(?P<id>[a-zA-Z0-9_-]+)';

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			self::GET_CATEGORIES,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_categories' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::CREATE_CATEGORY,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_category' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'name'        => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static function ( $value ) {
							return Validate::string( $value ) && Validate::not_empty( $value );
						},
					],
					'description' => [
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					],
					'required'    => [
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::UPDATE_CATEGORY,
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_category' ],
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
					'description' => [
						'required' => false,
						'type'     => 'string',
					],
					'required'    => [
						'required' => false,
						'type'     => 'boolean',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::DELETE_CATEGORY,
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_category' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'id'              => [
						'required' => true,
						'type'     => 'string',
					],
					'keep_cookies'    => [
						'required' => false,
						'type'     => 'boolean',
					],
					'target_category' => [
						'required' => false,
						'type'     => 'string',
						'default'  => CookieCategoryService::DEFAULT_TARGET_CATEGORY,
					],
				],
			]
		);
	}

	/**
	 * Get all cookie categories.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 0.0.1
	 * @return void
	 */
	public function get_categories( $request ): void {
		$service = new CookieCategoryService();
		$result  = $service->get_categories();

		SendJson::success( $result );
	}

	/**
	 * Create a new cookie category.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 0.0.1
	 * @return void
	 */
	public function create_category( $request ): void {
		try {
			$service = new CookieCategoryService();
			$result  = $service->create_category( $request->get_params() );

			$result['success'] ? SendJson::success( $result ) : SendJson::error( $result );
		} catch ( \Exception $e ) {
			SendJson::error(
				[
					'message' => __( 'Failed to create category: ', 'surecookie' ) . $e->getMessage(),
				]
			);
		}
	}

	/**
	 * Update an existing cookie category.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 0.0.1
	 * @return void
	 */
	public function update_category( $request ): void {
		try {
			$category_id = sanitize_text_field( $request->get_param( 'id' ) );
			$service     = new CookieCategoryService();
			$result      = $service->update_category( $category_id, $request->get_params() );

			$result['success'] ? SendJson::success( $result ) : SendJson::error( $result );
		} catch ( \Exception $e ) {
			SendJson::error(
				[
					'message' => __( 'Failed to update category: ', 'surecookie' ) . $e->getMessage(),
				]
			);
		}
	}

	/**
	 * Delete a cookie category.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 0.0.1
	 * @return void
	 */
	public function delete_category( $request ): void {
		try {
			$category_id     = sanitize_text_field( $request->get_param( 'id' ) ?? '' );
			$keep_cookies    = (bool) $request->get_param( 'keep_cookies' );
			$target_category = sanitize_text_field( (string) ( $request->get_param( 'target_category' ) ?? '' ) );

			$service = new CookieCategoryService();
			$result  = $service->delete_category( $category_id, $keep_cookies, $target_category );

			$result['success'] ? SendJson::success( $result ) : SendJson::error( $result );
		} catch ( \Exception $e ) {
			SendJson::error(
				[
					'message' => __( 'Failed to delete category: ', 'surecookie' ) . $e->getMessage(),
				]
			);
		}
	}
}
