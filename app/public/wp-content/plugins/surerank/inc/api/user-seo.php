<?php
/**
 * User_Seo class
 *
 * Handles user (author archive) related REST API endpoints for the SureRank plugin.
 *
 * @package SureRank\Inc\API
 * @since 1.9.0
 */

namespace SureRank\Inc\API;

use SureRank\Inc\Functions\Cache_Purge;
use SureRank\Inc\Functions\Defaults;
use SureRank\Inc\Functions\Get;
use SureRank\Inc\Functions\Send_Json;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Functions\Update;
use SureRank\Inc\Schema\Meta_Resolver;
use SureRank\Inc\Schema\Validator;
use SureRank\Inc\Traits\Get_Instance;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class User_Seo
 *
 * Handles user related REST API endpoints.
 *
 * @since 1.9.0
 */
class User_Seo extends Api_Base {
	use Get_Instance;

	/**
	 * Route Get User Seo Data
	 */
	protected const USER_SEO_DATA = '/user/seo-settings';

	/**
	 * Legacy user SEO data route, kept as a working alias. See #2878.
	 *
	 * @since 1.10.2
	 */
	protected const USER_SEO_DATA_LEGACY = '/user/settings';

	/**
	 * Constructor
	 *
	 * @since 1.9.0
	 */
	public function __construct() {
	}

	/**
	 * Register API routes.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public function register_routes() {
		$namespace = $this->get_api_namespace();
		$this->register_all_user_routes( $namespace );
	}

	/**
	 * Get user seo data
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 1.9.0
	 * @return void
	 */
	public function get_user_seo_data( $request ) {

		$user_id = (int) $request->get_param( 'user_id' );

		if ( ! self::can_manage_user_seo( $user_id ) ) {
			Send_Json::error( [ 'message' => __( 'You are not allowed to manage SEO settings for this user.', 'surerank' ) ] );
		}

		$data = self::get_user_data_by_id( $user_id );

		Send_Json::success( $data );
	}

	/**
	 * Get user data by id
	 *
	 * @param int $user_id User ID.
	 * @since 1.9.0
	 * @return array<string, mixed>
	 */
	public static function get_user_data_by_id( $user_id ) {
		$all_options = Settings::format_array( Defaults::get_instance()->get_post_defaults( false ) );
		// overridden_schemas is transport-only provenance, deliberately absent
		// from the defaults map (defaults drive persistence) — allow it through
		// the response filter explicitly.
		$data                   = array_intersect_key( Settings::prep_user_meta( $user_id ), array_merge( $all_options, [ 'overridden_schemas' => true ] ) );
		$decode_data            = Utils::decode_html_entities_recursive( $data ) ?? $data;
		$global_values          = Settings::get();
		$extended_meta          = apply_filters( 'surerank_prep_user_meta_extended_values', [], $global_values, $user_id );
		$global_with_emt        = array_merge( $global_values, $extended_meta );
		$decode_global_defaults = Utils::decode_html_entities_recursive( $global_with_emt ) ?? $global_with_emt;
		return [
			'data'           => $decode_data,
			'global_default' => $decode_global_defaults,
		];
	}

	/**
	 * Update user seo data
	 *
	 * REST endpoint handler. Extracts params from the request, delegates to
	 * the transport-free save_user_seo_meta() helper, and emits the result
	 * as JSON. The helper is shared with the AJAX fallback registered in
	 * inc/ajax/save-endpoints.php so both paths produce identical side
	 * effects for identical input.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 1.9.0
	 * @return void
	 */
	public function update_user_seo_data( $request ) {

		$user_id = (int) $request->get_param( 'user_id' );
		$data    = (array) $request->get_param( 'metaData' );

		if ( ! self::can_manage_user_seo( $user_id ) ) {
			Send_Json::error( [ 'message' => __( 'You are not allowed to manage SEO settings for this user.', 'surerank' ) ] );
		}

		$result = self::save_user_seo_meta( $user_id, $data );

		if ( $result['success'] ) {
			\SureRank\Inc\Functions\Rest_Observation::mark_reachable();
			Send_Json::success( [ 'message' => $result['message'] ] );
		}

		Send_Json::error( [ 'message' => $result['message'] ] );
	}

	/**
	 * Object-level capability guard.
	 *
	 * The route-level permission chain (validate_permission + role_capability)
	 * gates access to the endpoint itself; this additionally ensures the
	 * current user is allowed to edit the specific target user, so a role
	 * granted content_setting access cannot rewrite another user's SEO.
	 *
	 * Also enforces the surerank_excluded_roles_from_seo_checks policy so
	 * excluded-role users cannot be managed via any route (not just hidden
	 * from the users-list column).
	 *
	 * @param int $user_id Target user ID.
	 * @since 1.9.0
	 * @return bool
	 */
	public static function can_manage_user_seo( $user_id ) {
		// Respect the feature kill-switch: when disabled, no route (REST or
		// AJAX) may read or write per-user SEO, not just the admin UI.
		if ( ! apply_filters( 'surerank_enable_user_seo_settings', true ) ) {
			return false;
		}

		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}

		$excluded_roles = apply_filters( 'surerank_excluded_roles_from_seo_checks', [] );
		if ( ! empty( array_intersect( $user->roles, (array) $excluded_roles ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Save user SEO meta — transport-free core logic.
	 *
	 * Called by the REST endpoint handler above and by the AJAX fallback
	 * handler in inc/ajax/save-endpoints.php. Returns a result array rather
	 * than emitting a response so both callers can transport it in their
	 * native format.
	 *
	 * On success: writes user meta, runs the surerank_run_user_seo_checks
	 * filter, and updates the global + per-user last-optimised timestamps.
	 *
	 * @param int                  $user_id User ID to save meta against.
	 * @param array<string, mixed> $data    Meta payload (already sanitised).
	 * @return array{success: bool, message: string}
	 * @since 1.9.0
	 */
	public static function save_user_seo_meta( int $user_id, array $data ): array {
		if ( isset( $data['schemas'] ) ) {
			$stored     = Get::user_meta( $user_id, 'surerank_settings_schemas', true );
			$validation = Validator::validate_schemas_payload( $data['schemas'], $stored['schemas'] ?? [] );
			if ( ! $validation['valid'] ) {
				return [
					'success' => false,
					'message' => '' !== $validation['message'] ? $validation['message'] : __( 'Invalid schema payload.', 'surerank' ),
				];
			}

			/**
			 * The client edits the full effective schema set (inherited globals
			 * plus user-level entries). Persist only the diff against global —
			 * overrides, user-added schemas, and exclusions — so global schema
			 * changes keep reaching this author archive. Always overwrite
			 * excluded_schemas; a client-sent value must not leak through.
			 */
			$global_schemas = Settings::get( 'schemas' );

			// Globals snapshot the client session was seeded with — transport-only,
			// consumed by the diff and never persisted. Key presence matters:
			// an empty map means "session saw zero globals", absence means the
			// caller sent no baseline at all.
			$baseline = isset( $data['schemas_baseline'] ) && is_array( $data['schemas_baseline'] ) ? $data['schemas_baseline'] : null;
			unset( $data['schemas_baseline'] );

			$diff                     = Meta_Resolver::diff(
				is_array( $data['schemas'] ) ? $data['schemas'] : [],
				is_array( $global_schemas ) ? $global_schemas : [],
				Meta_Resolver::CONTEXT_USER,
				'',
				$user_id,
				$baseline
			);
			$data['schemas']          = $diff['schemas'];
			$data['excluded_schemas'] = $diff[ Meta_Resolver::EXCLUDED_KEY ];
		}

		self::update_user_meta_common( $user_id, $data );

		$check_result = self::get_instance()->run_checks( $user_id );
		if ( is_wp_error( $check_result ) ) {
			return [
				'success' => false,
				'message' => __( 'Error while running SEO Checks.', 'surerank' ),
			];
		}

		$current_time = time();
		Update::option( 'surerank_last_optimized_on', $current_time ); // Site-wide last optimisation.
		Update::user_meta( $user_id, 'surerank_user_optimized_at', $current_time ); // Per-user optimisation timestamp.

		// Refresh this author archive's cached output across page-cache plugins.
		Cache_Purge::purge_url( get_author_posts_url( $user_id ) );

		return [
			'success' => true,
			'message' => __( 'Data updated', 'surerank' ),
		];
	}

	/**
	 * Common method to process and update user meta data
	 *
	 * @param int                  $user_id User ID to update.
	 * @param array<string, mixed> $data Data to update.
	 * @since 1.9.0
	 * @return void
	 */
	public static function update_user_meta_common( int $user_id, array $data ) {
		/**
		 * The excluded_schemas key is only valid alongside a sibling `schemas` value
		 * produced by Meta_Resolver::diff(); prep-derived data carries the key
		 * alone and must not stamp the new-format marker onto legacy rows.
		 */
		if ( isset( $data['excluded_schemas'] ) && ! isset( $data['schemas'] ) ) {
			unset( $data['excluded_schemas'] );
		}

		// Transport-only keys; must never reach stored meta.
		unset( $data['schemas_baseline'], $data['overridden_schemas'] );

		$all_options = Defaults::get_instance()->get_post_defaults( false );
		/** Getting user meta if exists, otherwise getting all options(defaults) */
		$user_meta = Get::all_user_meta( $user_id );
		if ( ! empty( $user_meta ) ) {
			$data = array_merge( $user_meta, $data );
		}

		$processed_options = Utils::process_option_values( $all_options, $data, $user_id, '', false );

		foreach ( $processed_options as $option_name => $new_option_value ) {
			Update::user_meta( $user_id, 'surerank_settings_' . $option_name, $new_option_value );
		}
	}

	/**
	 * Run checks
	 *
	 * @param int $user_id User ID.
	 * @since 1.9.0
	 * @return WP_Error|int|array<string, mixed>
	 */
	public function run_checks( $user_id ) {
		if ( ! $user_id ) {
			return new WP_Error( 'no_user_id', __( 'No user ID provided.', 'surerank' ) );
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'no_user', __( 'No user found.', 'surerank' ) );
		}

		return apply_filters( 'surerank_run_user_seo_checks', $user_id, $user );
	}

	/**
	 * Register all user routes
	 *
	 * @param string $namespace The API namespace.
	 * @since 1.9.0
	 * @return void
	 */
	private function register_all_user_routes( $namespace ) {
		$this->register_get_user_seo_data_route( $namespace );
		$this->register_update_user_seo_data_route( $namespace );
	}

	/**
	 * Register get user SEO data route
	 *
	 * @param string $namespace The API namespace.
	 * @since 1.9.0
	 * @return void
	 */
	private function register_get_user_seo_data_route( $namespace ) {
		$this->register_route_with_aliases(
			$namespace,
			self::USER_SEO_DATA,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_user_seo_data' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_user_seo_data_args(),
				'role_capability'     => 'content_setting',
			],
			[ self::USER_SEO_DATA_LEGACY ]
		);
	}

	/**
	 * Register update user SEO data route
	 *
	 * @param string $namespace The API namespace.
	 * @since 1.9.0
	 * @return void
	 */
	private function register_update_user_seo_data_route( $namespace ) {
		$this->register_route_with_aliases(
			$namespace,
			self::USER_SEO_DATA,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'update_user_seo_data' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_update_user_seo_data_args(),
				'role_capability'     => 'content_setting',
			],
			[ self::USER_SEO_DATA_LEGACY ]
		);
	}

	/**
	 * Get user SEO data arguments
	 *
	 * @since 1.9.0
	 * @return array<string, array<string, mixed>>
	 */
	private function get_user_seo_data_args() {
		return [
			'user_id' => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
		];
	}

	/**
	 * Get update user SEO data arguments
	 *
	 * @since 1.9.0
	 * @return array<string, array<string, mixed>>
	 */
	private function get_update_user_seo_data_args() {
		return [
			'user_id'  => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
			'metaData' => [
				'type'              => 'object',
				'required'          => true,
				'sanitize_callback' => [ $this, 'sanitize_array_data' ],
			],
		];
	}
}
