<?php
/**
 * MCP Server registration.
 *
 * Registers the SureCookie MCP server with the WordPress MCP Adapter,
 * exposing surecookie/* abilities at /wp-json/surecookie/v1/mcp.
 *
 * @link       https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Modules/Mcp
 * @since      1.1.0
 */

namespace SureCookie\Inc\Modules\Mcp;

use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Server
 *
 * Creates the plugin-scoped MCP server on `mcp_adapter_init`, whitelisting
 * only `surecookie/` abilities; the adapter's default server covers "Global".
 *
 * @since 1.1.0
 */
class Server {
	use GetInstance;

	/**
	 * Constructor. The enable check runs inside the callback at hook-fire time.
	 *
	 * @since 1.1.0
	 */
	private function __construct() {
		add_action( 'mcp_adapter_init', [ $this, 'register_mcp_server' ] );
	}

	/**
	 * Whether the MCP server is enabled via the admin setting.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	public static function is_enabled(): bool {
		/**
		 * Filters whether the SureCookie MCP server should be created.
		 *
		 * @param bool $enabled Defaults to the `enable_mcp` admin setting.
		 * @since 1.1.0
		 */
		return (bool) apply_filters( 'surecookie_mcp_server_enabled', (bool) Settings::get( 'enable_mcp' ) );
	}

	/**
	 * Whether the MCP Adapter plugin (current or legacy build) is available.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	public static function is_adapter_available(): bool {
		return class_exists( 'WP\\MCP\\Core\\McpAdapter' ) || class_exists( 'WP\\MCP\\Plugin' );
	}

	/**
	 * Register the SureCookie MCP server on `mcp_adapter_init`.
	 *
	 * @param object $adapter MCP adapter instance provided by the adapter plugin.
	 * @return void
	 * @since 1.1.0
	 */
	public function register_mcp_server( $adapter = null ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		// Created even with empty $tools (SureRank parity) so failures surface
		// as an empty tools list instead of a silent 404.
		$tools = $this->get_surecookie_ability_names();

		// Newer adapter builds ship HttpTransport; older ones only RestTransport.
		$transport_class = class_exists( '\\WP\\MCP\\Transport\\HttpTransport' )
			? '\\WP\\MCP\\Transport\\HttpTransport'
			: '\\WP\\MCP\\Transport\\Http\\RestTransport';

		try {
			$adapter->create_server(
				'surecookie',
				'surecookie/v1',
				'mcp',
				__( 'SureCookie MCP Server', 'surecookie' ),
				__( 'SureCookie MCP Server for cookie consent settings, consent logs, cookie management, and site scanning workflows.', 'surecookie' ),
				SURECOOKIE_VERSION,
				[ $transport_class ],
				'\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler',
				'\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler',
				$tools,
				[],
				[],
				// Transport access requires the same capability that gates
				// ability execution; older adapters ignore this 13th argument.
				static function (): bool {
					return current_user_can( Helper::capability() );
				}
			);
		} catch ( \Throwable $e ) {
			// A transport/adapter version mismatch must not fatal the REST API.
			Logger::get_instance()->log( 'SureCookie MCP server registration failed: ' . $e->getMessage(), 'warning' );
		}
	}

	/**
	 * Collect the names of all registered surecookie/* abilities.
	 *
	 * @return array<int, string>
	 * @since 1.1.0
	 */
	private function get_surecookie_ability_names(): array {
		$abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : [];
		$tools     = [];

		foreach ( $abilities as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}

			$name = $ability->get_name();

			if ( is_string( $name ) && strpos( $name, 'surecookie/' ) === 0 ) {
				$tools[] = $name;
			}
		}

		return $tools;
	}
}
