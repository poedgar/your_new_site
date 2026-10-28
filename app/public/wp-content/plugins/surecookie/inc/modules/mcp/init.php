<?php
/**
 * MCP Module Init
 *
 * Bootstraps the MCP (Model Context Protocol) module: the SureCookie
 * MCP server registration and the localized data consumed by the
 * Settings > MCP admin page.
 *
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Modules/Mcp
 * @since      1.1.0
 */

namespace SureCookie\Inc\Modules\Mcp;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init class
 *
 * Handles initialization and WordPress hooks for the MCP module.
 *
 * @since 1.1.0
 */
class Init {
	use GetInstance;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	private function __construct() {
		Server::get_instance();

		// Add localization variables for the Settings > MCP admin page.
		add_filter( 'surecookie_localized_admin_data', [ $this, 'add_admin_localization_vars' ] );
	}

	/**
	 * Add MCP localization variables for the admin React app.
	 *
	 * Added even when enable_mcp is off so the page can explain adapter/WP gaps.
	 *
	 * @param array<string, mixed> $variables Localization variables.
	 * @return array<string, mixed> Localization variables.
	 * @since 1.1.0
	 */
	public function add_admin_localization_vars( $variables = [] ) {
		return array_merge(
			$variables,
			[
				'mcp_rest_url'             => esc_url_raw( trailingslashit( rest_url() ) ),
				'mcp_username'             => wp_get_current_user()->user_login,
				'mcp_adapter_installed'    => Server::is_adapter_available(),
				'mcp_abilities_supported'  => function_exists( 'wp_register_ability' ),
				'mcp_app_password_url'     => esc_url_raw( admin_url( 'profile.php' ) . '#application-passwords-section' ),
				'mcp_adapter_download_url' => esc_url_raw(
					/** Filters the download URL shown when the MCP Adapter plugin is missing. */
					apply_filters( 'surecookie_mcp_adapter_download_url', 'https://github.com/WordPress/mcp-adapter/releases/latest' )
				),
			]
		);
	}
}
