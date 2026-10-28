<?php
/**
 * Abstract Ability Base Class
 *
 * @since 1.3.1
 *
 * @package SureContact\Abilities
 */

namespace SureContact\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract_Ability class
 *
 * Base class for all SureContact abilities. Handles registration with the
 * WordPress Abilities API and provides shared helpers for connection checks
 * and internal REST dispatches.
 *
 * @since 1.3.1
 */
abstract class Abstract_Ability {

	/**
	 * Get the ability ID (e.g. "surecontact/get-setting").
	 *
	 * @since 1.3.1
	 *
	 * @return string
	 */
	abstract public function get_id(): string;

	/**
	 * Get the human-readable label.
	 *
	 * @since 1.3.1
	 *
	 * @return string
	 */
	abstract public function get_label(): string;

	/**
	 * Get the description shown to AI agents.
	 *
	 * @since 1.3.1
	 *
	 * @return string
	 */
	abstract public function get_description(): string;

	/**
	 * Execute the ability.
	 *
	 * @since 1.3.1
	 *
	 * @param array $args Input arguments from the caller.
	 * @return array|\WP_Error Result data or error.
	 */
	abstract public function execute( array $args = [] );

	/**
	 * Get the required WordPress capability.
	 *
	 * @since 1.3.1
	 *
	 * @return string
	 */
	public function get_capability(): string {
		return 'manage_options';
	}

	/**
	 * Get the JSON Schema for input arguments.
	 *
	 * @since 1.3.1
	 *
	 * @return array
	 */
	public function get_input_schema(): array {
		return [];
	}

	/**
	 * Get MCP annotations for this ability.
	 *
	 * @since 1.3.1
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return [];
	}

	/**
	 * Get optional meta (examples, resource, boost_screens, etc.).
	 *
	 * @since 1.3.1
	 *
	 * @return array
	 */
	public function get_meta(): array {
		$meta = [
			'mcp' => [
				'public' => true,
			],
		];

		$annotations = $this->get_annotations();
		if ( ! empty( $annotations ) ) {
			$meta['annotations'] = $annotations;
		}

		return $meta;
	}

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function register(): void {
		wp_register_ability(
			$this->get_id(),
			[
				'label'               => $this->get_label(),
				'description'         => $this->get_description(),
				'category'            => 'surecontact',
				'input_schema'        => $this->get_input_schema(),
				'execute_callback'    => [ $this, 'execute' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'meta'                => $this->get_meta(),
			]
		);
	}

	/**
	 * Check whether the current user has permission to run this ability.
	 *
	 * @since 1.3.1
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( $this->get_capability() );
	}

	/**
	 * Check whether SureContact is authenticated with the CRM.
	 *
	 * @since 1.3.1
	 *
	 * @return bool
	 */
	protected function is_connected(): bool {
		return class_exists( 'SureContact\Auth_Manager' )
			&& ( new \SureContact\Auth_Manager() )->is_authenticated();
	}

	/**
	 * Build a standard "not connected" error.
	 *
	 * @since 1.3.1
	 *
	 * @return array
	 */
	protected function connection_error(): array {
		return $this->error(
			__( 'SureContact is not connected.', 'surecontact' ),
			__( 'Connect SureContact via Settings > SureContact first.', 'surecontact' )
		);
	}

	/**
	 * Check whether the integration's required plugin is active.
	 *
	 * Returns an error array when the integration is registered but its plugin
	 * is inactive. Returns null when the integration is available (or when the
	 * slug is not in the registry), meaning the ability can proceed normally.
	 *
	 * @since 1.3.1
	 *
	 * @param string $slug Integration slug (e.g. "woocommerce").
	 * @return array|null Error array when unavailable, null when available.
	 */
	protected function integration_unavailable_error( string $slug ): ?array {
		if ( ! class_exists( 'SureContact\Integrations_Loader' ) ) {
			return null;
		}

		$loader = \SureContact::get_instance()->integrations_loader;
		if ( null === $loader ) {
			return null;
		}
		$config = $loader->get_integration_config( $slug );

		// Unknown slug — let the ability handle it downstream.
		if ( null === $config ) {
			return null;
		}

		// Available — no error.
		if ( ! empty( $config['available'] ) ) {
			return null;
		}

		$name = isset( $config['name'] ) ? $config['name'] : $slug;

		return $this->error(
			sprintf(
				/* translators: %s: integration/plugin name */
				__( 'The %s plugin is not active.', 'surecontact' ),
				$name
			),
			__( 'Install and activate the required plugin to use this integration.', 'surecontact' )
		);
	}

	/**
	 * Build a success response.
	 *
	 * @since 1.3.1
	 *
	 * @param string $message Human-readable message.
	 * @param array  $data    Result data.
	 * @return array
	 */
	protected function success( string $message, array $data = [] ): array {
		return [
			'success' => true,
			'message' => $message,
			'data'    => $data,
		];
	}

	/**
	 * Build an error response.
	 *
	 * @since 1.3.1
	 *
	 * @param string $message Human-readable error message.
	 * @param string $hint    Optional guidance for the caller.
	 * @return array
	 */
	protected function error( string $message, string $hint = '' ): array {
		$result = [
			'success' => false,
			'message' => $message,
		];

		if ( ! empty( $hint ) ) {
			$result['error'] = $hint;
		}

		return $result;
	}

	/**
	 * Build an error response from a WP_Error object.
	 *
	 * @since 1.3.1
	 *
	 * @param \WP_Error $wp_error Error object.
	 * @return array
	 */
	protected function error_from_wp_error( \WP_Error $wp_error ): array {
		return $this->error( $wp_error->get_error_message() );
	}

	/**
	 * Build an error response from a failed REST response.
	 *
	 * @since 1.3.1
	 *
	 * @param \WP_REST_Response $response REST response object that returned an error.
	 * @param string            $hint     Optional guidance for the caller.
	 * @return array
	 */
	protected function error_from_response( \WP_REST_Response $response, string $hint = '' ): array {
		$error = $response->as_error();
		return $this->error(
			$error instanceof \WP_Error ? $error->get_error_message() : __( 'An unknown error occurred.', 'surecontact' ),
			$hint
		);
	}

	/**
	 * Resolve the event for an event-based rule when not explicitly provided.
	 *
	 * Looks up configured-integrations to find the stored event for the given
	 * slug/item_id/item_type combination. Without this, event-based rules
	 * (e.g., SureCart "purchase") cannot be matched since the DB query uses
	 * `event IS NULL` when no event is provided.
	 *
	 * @since 1.3.1
	 *
	 * @param string $slug      Integration slug.
	 * @param string $item_id   Item ID.
	 * @param string $item_type Item type.
	 * @return string|null Resolved event or null.
	 */
	protected function resolve_event( string $slug, string $item_id, string $item_type ): ?string {
		$response = $this->rest_dispatch( 'GET', '/surecontact/v1/integration-rules/configured-integrations' );

		if ( $response->is_error() ) {
			return null;
		}

		$data = $response->get_data();

		foreach ( ( isset( $data['configured_integrations'] ) ? $data['configured_integrations'] : [] ) as $rule ) {
			if (
				( isset( $rule['slug'] ) ? $rule['slug'] : '' ) === $slug &&
				( isset( $rule['item_id'] ) ? $rule['item_id'] : '' ) === $item_id &&
				( isset( $rule['item_type'] ) ? $rule['item_type'] : '' ) === $item_type
			) {
				return isset( $rule['event'] ) ? $rule['event'] : null;
			}
		}

		return null;
	}

	/**
	 * Dispatch an internal REST request without HTTP overhead.
	 *
	 * @since 1.3.1
	 *
	 * @param string $method HTTP method ('GET', 'POST', etc.).
	 * @param string $route  REST route (e.g. '/surecontact/v1/crm/lists').
	 * @param array  $params Query/body parameters.
	 * @return \WP_REST_Response
	 */
	protected function rest_dispatch( string $method, string $route, array $params = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}
}
