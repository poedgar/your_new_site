<?php
/**
 * Posts API.
 *
 * REST endpoints for searching posts for the Cookie Policy and Site Scanner
 * page pickers and fetching a single post by ID. Both surfaces default to the
 * `page` post type; developers extend the searchable list per surface (via the
 * request `context` arg) using the `surecookie_searchable_post_types` filter -
 * see Get::searchable_post_types().
 *
 * @package SureCookie\Inc\API
 * @since 0.0.1-beta.2
 */

namespace SureCookie\Inc\API;

use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Services\ContentService;
use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Posts
 *
 * @since 0.0.1-beta.2
 */
class Posts extends Base {
	use GetInstance;

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1-beta.2
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			'/posts/search',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'search_posts' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'search'   => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'per_page' => [
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
						'description'       => 'Maximum number of results returned per post type.',
					],
					'context'  => [
						'type'              => 'string',
						'default'           => 'policy',
						'enum'              => [ 'policy', 'scanner' ],
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			'/posts/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_post_by_id' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'id'      => [
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'context' => [
						'type'              => 'string',
						'default'           => 'policy',
						'enum'              => [ 'policy', 'scanner' ],
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);
	}

	/**
	 * Search published posts for a page picker, grouped by post type.
	 *
	 * Both the Cookie Policy picker (context = 'policy') and the Site Scanner
	 * picker (context = 'scanner') default to the `page` post type; the allowed
	 * list is extensible per surface via the `surecookie_searchable_post_types`
	 * filter. One capped query runs per allowed post type so every type is
	 * represented in the results, and each row is tagged with its post type so
	 * the client can group the dropdown. Within a type, results are ordered
	 * alphabetically when no search term is given, or by relevance otherwise.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @since 0.0.1-beta.2
	 * @return void
	 */
	public function search_posts( WP_REST_Request $request ): void {
		// Values are already sanitized and validated by route arg definitions.
		// The query itself lives in ContentService so the content-lookup ability
		// runs exactly the same lookup; this route's JSON shape is unchanged.
		$result = ( new ContentService() )->search_posts(
			(string) $request->get_param( 'search' ),
			(int) $request->get_param( 'per_page' ),
			(string) $request->get_param( 'context' )
		);

		SendJson::success( [ 'data' => $result['posts'] ] );
	}

	/**
	 * Return basic data for a single published post regardless of post type.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @since 0.0.1-beta.2
	 * @return void
	 */
	public function get_post_by_id( WP_REST_Request $request ): void {
		$post_id = $request->get_param( 'id' ); // Already absint by sanitize_callback.
		$service = new ContentService();
		$post    = $service->get_post( (int) $post_id );

		// Return 404 for missing, non-published, OR disallowed post type so that draft/private/
		// structural IDs (attachments, nav items, blocks) are not confirmed to exist. Using the
		// same 404 for every disallowed case preserves the non-enumeration property. The service
		// deliberately does not apply the post-type gate, so this route keeps it.
		if (
			$post === null
			|| ! in_array( $post['type'], $service->lookup_post_types( (string) $request->get_param( 'context' ) ), true )
		) {
			SendJson::error( [ 'message' => __( 'Post not found.', 'surecookie' ) ], 404 );
			return;
		}

		SendJson::success(
			[
				'id'         => $post['id'],
				'title'      => $post['title'],
				'status'     => $post['status'],
				'link'       => $post['link'],
				'type'       => $post['type'],
				'type_label' => $post['type_label'],
			]
		);
	}
}
