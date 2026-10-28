<?php
/**
 * MCP Status class
 *
 * REST endpoint reporting whether SureRank managed to register its MCP server.
 *
 * @package SureRank\Inc\API
 * @since 1.10.2
 */

namespace SureRank\Inc\API;

use SureRank\Inc\Abilities\Abilities_Registrar;
use SureRank\Inc\Functions\Send_Json;
use SureRank\Inc\Traits\Get_Instance;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Mcp_Status
 *
 * An MCP adapter only announces itself during a REST request, so an admin page
 * load can never see whether the SureRank server was created. This route runs
 * inside a REST request, where that registration has just been attempted, and
 * reports what became of it.
 *
 * @since 1.10.2
 */
class Mcp_Status extends Api_Base {
	use Get_Instance;

	/**
	 * Route - MCP server status.
	 */
	protected const STATUS = '/mcp-status';

	/**
	 * Register API routes.
	 *
	 * @since 1.10.2
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->get_api_namespace(),
			self::STATUS,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_status' ],
					'permission_callback' => [ $this, 'validate_permission' ],
					'role_capability'     => 'global_setting',
				],
			]
		);
	}

	/**
	 * Report the MCP server status for this request.
	 *
	 * The adapter escapes its own error messages for HTML output, so they are
	 * decoded here the same way the other read endpoints do it. The screen shows
	 * the message as text and would otherwise print the entities.
	 *
	 * @since 1.10.2
	 * @return void
	 */
	public function get_status() {
		$status = Abilities_Registrar::get_server_status();

		Send_Json::success( Utils::decode_html_entities_recursive( $status ) ?? $status );
	}
}
