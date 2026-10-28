<?php
/**
 * Dashboard class
 *
 * Handles dashboard related REST API endpoints for the SureRank plugin.
 *
 * @package SureRank\Inc\GoogleSearchConsole
 */

namespace SureRank\Inc\GoogleSearchConsole;

use SureRank\Inc\API\Api_Base;
use SureRank\Inc\Functions\Send_Json;
use SureRank\Inc\Traits\Get_Instance;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Dashboard
 *
 * Handles dashboard related REST API endpoints with improved error handling and structure.
 */
class Dashboard extends Api_Base {

	use Get_Instance;

	/**
	 * Constructor
	 */
	public function __construct() {
	}

	/**
	 * Register Routes
	 *
	 * @return void
	 */
	public function register_routes() {
		$namespace = $this->get_api_namespace();

		$routes = [
			'revoke-auth'            => [
				'methods'  => WP_REST_Server::DELETABLE,
				'callback' => [ $this, 'revoke' ],
			],
			'auth-url'               => [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [ $this, 'get_auth_url' ],
			],
			'matched-site'           => [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [ $this, 'get_matched_site' ],
			],
			'sites'                  => [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [ $this, 'get_sites' ],
			],
			'site'                   => [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [ $this, 'get_site' ],
			],
			'clicks-and-impressions' => [
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => [ $this, 'get_clicks_and_impressions' ],
				'args'     => [
					'startDate' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'endDate'   => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
			'site-traffic'           => [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [ $this, 'get_site_traffic' ],
				'args'     => [
					'startDate' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'endDate'   => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
			'content-performance'    => [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [ $this, 'get_content_performance' ],
				'args'     => [
					'startDate' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'endDate'   => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
			'keyword-rankings'       => [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [ $this, 'get_keyword_rankings' ],
				'args'     => [
					'url'       => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'startDate' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'endDate'   => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
			'add-site'               => [
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => [ $this, 'auto_create_property' ],
			],
			'verify-site'            => [
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => [ $this, 'verify_existing_property' ],
			],
			'url-inspection'         => [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [ $this, 'get_url_inspection' ],
				'args'     => [
					'post_id' => [
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					],
					'term_id' => [
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					],
					'refresh' => [
						'type'     => 'boolean',
						'required' => false,
					],
				],
			],
		];

		foreach ( $routes as $endpoint => $args ) {
			register_rest_route(
				$namespace,
				'google-search-console/' . $endpoint,
				[
					'methods'             => $args['methods'],
					'callback'            => $args['callback'],
					'permission_callback' => [ $this, 'validate_permission' ],
					'role_capability'     => 'global_setting',
					'args'                => $args['args'] ?? [],
				]
			);
		}

		register_rest_route(
			$namespace,
			'google-search-console/site',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_site' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'role_capability'     => 'global_setting',
				'args'                => [
					'url' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Get Site saved in credentials
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function get_site() {
		Send_Json::success( [ 'site' => Auth::get_instance()->get_credentials( 'site_url' ) ] );
	}

	/**
	 * Update Site saved in credentials
	 *
	 * The stored value is used verbatim as the `siteUrl` parameter of every
	 * Search Console API call, so it must be the exact property string from
	 * the user's property list — Google rejects anything else (including a
	 * URL-prefix property without its trailing slash) with 403.
	 *
	 * @return void
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 1.0.0
	 */
	public function update_site( $request ) {
		$url = (string) $request->get_param( 'url' );

		$sites_response = Controller::get_instance()->get_sites();

		if ( isset( $sites_response['error'] ) && $sites_response['error'] ) {
			// Property list unavailable (transient API failure) — fall back to
			// storing a property-formatted value rather than hard-failing.
			$property = Utils::ensure_property_format( $url );
		} else {
			$property = Controller::get_instance()->resolve_property_for_site( $url, $sites_response['siteEntry'] ?? [] );
			if ( null === $property ) {
				Send_Json::error(
					[
						'message' => __( 'This URL does not match any property on the connected Google account. Please select a property from the list.', 'surerank' ),
						'code'    => 'property_not_found',
					]
				);
				return;
			}
		}

		$all_credentials             = Auth::get_instance()->get_credentials();
		$all_credentials['site_url'] = $property;
		Auth::get_instance()->save_credentials( $all_credentials );
		Send_Json::success();
	}

	/**
	 * Revoke
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function revoke() {
		Auth::get_instance()->delete_credentials();
		Send_Json::success();
	}

	/**
	 * Get authentication URL
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function get_auth_url() {
		$query_args = apply_filters(
			'surerank_auth_api_url_query_args',
			[
				'nonce'  => wp_create_nonce( 'surerank_auth_nonce' ),
				'action' => 'surerank_auth',
			]
		);

		$redirect_uri = add_query_arg( $query_args, admin_url( 'admin.php?page=surerank' ) );
		$auth_url     = Utils::get_saas_auth_api_url() . 'search-console/connect/?redirect_uri=' . urlencode( $redirect_uri );
		Send_Json::success( [ 'url' => $auth_url ] );
	}

	/**
	 * Get Sites
	 *
	 * Returns all sites with verification status
	 *
	 * @return void
	 * @since 1.0.0
	 * @since 1.4.0
	 */
	public function get_sites() {
		// Get all sites once.
		$all_sites = Controller::get_instance()->get_sites();

		if ( isset( $all_sites['siteEntry'] ) && is_array( $all_sites['siteEntry'] ) ) {
			foreach ( $all_sites['siteEntry'] as &$site ) {
				$site['isVerified'] = isset( $site['permissionLevel'] ) &&
					$site['permissionLevel'] !== 'siteUnverifiedUser';
			}
		}

		Send_Json::success( $all_sites );
	}

	/**
	 * Get Matched
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function get_matched_site() {
		Send_Json::success( [ 'matched' => Controller::get_instance()->get_matched_site() ] );
	}

	/**
	 * Get Site Traffic
	 *
	 * @return void
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 1.0.0
	 */
	public function get_site_traffic( $request ) {
		Send_Json::success( Controller::get_instance()->get_site_traffic( $request ) );
	}

	/**
	 * Get Clicks and Impressions
	 *
	 * @return void
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 1.0.0
	 */
	public function get_clicks_and_impressions( $request ) {
		Send_Json::success( Controller::get_instance()->get_clicks_and_impressions( $request ) );
	}

	/**
	 * Get Content Performance
	 *
	 * @return void
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 1.0.0
	 */
	public function get_content_performance( $request ) {
		Send_Json::success( Controller::get_instance()->get_content_performance( $request ) );
	}

	/**
	 * Get Keyword Rankings
	 *
	 * @return void
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 1.6.3
	 */
	public function get_keyword_rankings( $request ) {
		Send_Json::success( Controller::get_instance()->get_keyword_rankings( $request ) );
	}

	/**
	 * Auto Create Property
	 *
	 * Automatically creates and verifies GSC property if one doesn't exist
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function auto_create_property() {
		$result = Controller::get_instance()->auto_create_and_verify_property();

		if ( isset( $result['success'] ) && $result['success'] ) {
			Send_Json::success( $result );
		} else {
			Send_Json::error( $result );
		}
	}

	/**
	 * Verify Existing Property
	 *
	 * Verifies an existing GSC property that's already added but not verified
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function verify_existing_property() {
		$result = Controller::get_instance()->verify_existing_property();

		if ( isset( $result['success'] ) && $result['success'] ) {
			Send_Json::success( $result );
		} else {
			Send_Json::error( $result );
		}
	}

	/**
	 * Get URL Inspection result for a post or term.
	 *
	 * Thin pass-through: validates request, checks per-object cap,
	 * delegates cache/API logic to Url_Inspection.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return void
	 * @since 1.7.5
	 */
	public function get_url_inspection( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$term_id = absint( $request->get_param( 'term_id' ) );
		$refresh = (bool) $request->get_param( 'refresh' );

		if ( ! $post_id && ! $term_id ) {
			Send_Json::error(
				[
					'message' => __( 'A post_id or term_id is required.', 'surerank' ),
					'code'    => 'missing_object',
				]
			);
			return;
		}

		if ( ! Url_Inspection::selected_site_matches_current() ) {
			Send_Json::error(
				[
					'message' => __( 'The connected Search Console property does not match this site.', 'surerank' ),
					'code'    => 'site_mismatch',
				]
			);
			return;
		}

		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			Send_Json::error(
				[
					'message' => __( 'You do not have permission to view indexing status for this post.', 'surerank' ),
					'code'    => 'forbidden_object',
				]
			);
			return;
		}

		if ( $term_id && ! current_user_can( 'edit_term', $term_id ) ) {
			Send_Json::error(
				[
					'message' => __( 'You do not have permission to view indexing status for this term.', 'surerank' ),
					'code'    => 'forbidden_object',
				]
			);
			return;
		}

		$result = $term_id
			? Url_Inspection::get_instance()->get_for_term( $term_id, $refresh )
			: Url_Inspection::get_instance()->get_for_post( $post_id, $refresh );

		if ( $result['ok'] ) {
			Send_Json::success( $result['payload'] );
			return;
		}

		Send_Json::error( $result['payload'] );
	}

}
