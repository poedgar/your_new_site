<?php
/**
 * Plugin class
 *
 * Handles installed products related REST API endpoints for the SureCookie plugin.
 *
 * @package SureCookie\Inc\API
 */

namespace SureCookie\Inc\API;

use SureCookie\Admin\Product_Promotion;
use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Plugin
 *
 * Handles this related REST API endpoints.
 */
class Plugin extends Base {
	use GetInstance;

	/**
	 * Get conflicting plugins.
	 */
	protected const CONFLICTING_PLUGINS = '/plugin/get-conflicts';

	/**
	 * Route activate plugin.
	 */
	protected const ACTIVATE_PLUGIN = '/plugin/activate';

	/**
	 * Route deactivate plugin.
	 */
	protected const DEACTIVATE_PLUGIN = '/plugin/deactivate';

	/**
	 * Route get promotional plugins data.
	 */
	protected const GET_PROMOTIONS = '/plugin/get-promotions';

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			self::CONFLICTING_PLUGINS,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_conflicting_plugins' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::DEACTIVATE_PLUGIN,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'deactivate_plugin' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'plugin_path' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::GET_PROMOTIONS,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_promotional_plugins_data' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::ACTIVATE_PLUGIN,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'get_plugin_activate' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'plugin_path' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * Get promotional plugins data.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 0.0.1
	 */
	public function get_promotional_plugins_data( $request ): void {
		$promoting_plugins = Product_Promotion::get_instance()->get_promotional_plugins();

		SendJson::success(
			[
				'message' => __( 'Promotional plugins data retrieved.', 'surecookie' ),
				'plugins' => $promoting_plugins,
			]
		);
	}

	/**
	 * Activate plugin helper.
	 *
	 * @param string $plugin_path Plugin path.
	 * @return \WP_Error|null
	 * @since 0.0.1
	 */
	public function activate_plugin( $plugin_path ) {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Disable redirection to plugin page after activation.
		add_filter( 'wp_redirect', '__return_false' );

		return activate_plugin( $plugin_path );
	}

	/**
	 * Activate plugin via REST.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 0.0.1
	 */
	public function get_plugin_activate( $request ): void {
		$plugin_path = $request->get_param( 'plugin_path' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			SendJson::error( [ 'message' => __( 'You don\'t have permission to activate plugins.', 'surecookie' ) ] );
		}

		if ( ! $plugin_path ) {
			SendJson::error( [ 'message' => __( 'Plugin not found.', 'surecookie' ) ] );
		}

		$activate_result = $this->activate_plugin( $plugin_path );
		if ( is_wp_error( $activate_result ) ) {
			SendJson::error(
				[
					'message' => $activate_result->get_error_message(),
				]
			);
		}

		SendJson::success(
			[
				'message'          => __( 'Plugin activated successfully.', 'surecookie' ),
				'activated_plugin' => $plugin_path,
			]
		);
	}

	/**
	 * Deactivate plugin.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 */
	public function deactivate_plugin( $request ): void {
		$plugin_path = $request->get_param( 'plugin_path' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			SendJson::error( [ 'message' => __( 'You don\'t have permission to deactivate plugins.', 'surecookie' ) ] );
		}

		if ( ! $plugin_path ) {
			SendJson::error( [ 'message' => __( 'Plugin not found.', 'surecookie' ) ] );
		}

		// Load plugin.php if not already loaded.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_path = (string) $plugin_path;

		// Check if plugin is already inactive.
		if ( ! is_plugin_active( $plugin_path ) ) {
			SendJson::success( [ 'message' => __( 'Plugin is already inactive.', 'surecookie' ) ] );
		}

		$is_network_active = $this->is_network_activated_plugin( $plugin_path );

		// Network-activated plugins can only be deactivated network-wide by a
		// Super Admin. A regular site admin is pointed to Network Admin instead
		// of silently failing.
		if ( $is_network_active && ! current_user_can( 'manage_network_plugins' ) ) {
			SendJson::error(
				[
					'message'           => __( 'This plugin is network-activated and must be deactivated by a Network Administrator from Network Admin > Plugins.', 'surecookie' ),
					'requiresNetwork'   => true,
					'networkPluginsUrl' => $this->get_plugin_management_url( $plugin_path, true ),
				]
			);
		}

		deactivate_plugins( [ $plugin_path ], false, $is_network_active );

		// Never report success while the plugin is still active.
		if ( is_plugin_active( $plugin_path ) ) {
			SendJson::error( [ 'message' => __( 'Plugin could not be deactivated.', 'surecookie' ) ] );
		}

		SendJson::success( [ 'message' => __( 'Plugin deactivated successfully.', 'surecookie' ) ] );
	}

	/**
	 * Get plugins that can be conflicting with the main plugin.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full data about the request.
	 * @since 0.0.1
	 */
	public function get_conflicting_plugins( $request ): void {
		SendJson::success(
			[
				'message' => __( 'Conflicting plugins retrieved.', 'surecookie' ),
				'plugins' => $this->detect_conflicting_plugins(),
			]
		);
	}

	/**
	 * Active plugins that look like competing cookie-consent plugins.
	 *
	 * Split out of the REST handler so the site-health ability can run the same
	 * detection: that handler terminates the request through SendJson.
	 *
	 * @since 1.4.0
	 * @return array<int, array<string, mixed>>
	 */
	public function detect_conflicting_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = Get::option( 'active_plugins', [], 'array' );
		// On multisite, network-activated plugins are active everywhere but live
		// in a separate network option, so treat them as active too.
		$network_active = $this->get_network_active_plugins();

		$conflicting_plugins = [];

		foreach ( $all_plugins as $plugin_path => $plugin_data ) {
			$is_network_active = in_array( $plugin_path, $network_active, true );

			if ( ! in_array( $plugin_path, $active_plugins, true ) && ! $is_network_active ) {
				continue;
			}

			if ( strpos( $plugin_path, 'surecookie' ) !== false ) {
				continue;
			}

			// Check if plugin matches conflicting keywords.
			$plugin_name        = strtolower( $plugin_data['Name'] );
			$plugin_description = strtolower( $plugin_data['Description'] );
			$text_domain        = isset( $plugin_data['TextDomain'] ) ? strtolower( $plugin_data['TextDomain'] ) : '';

			if ( ! $this->is_plugin_conflicting( $plugin_name, $plugin_description, $text_domain ) ) {
				continue;
			}

			// Extract plugin slug from path for icon URL.
			$slug = strpos( $plugin_path, '/' ) !== false
				? dirname( $plugin_path )
				: str_replace( '.php', '', $plugin_path );

			$conflicting_plugins[ $plugin_path ] = [
				'name'               => $plugin_data['Name'],
				'slug'               => $slug,
				'path'               => $plugin_path,
				'version'            => $plugin_data['Version'],
				'description'        => $plugin_data['Description'],
				'managementUrl'      => $this->get_plugin_management_url( $plugin_path, $is_network_active ),
				'icon'               => "https://ps.w.org/{$slug}/assets/icon-256x256.png",
				'isNetworkActivated' => $is_network_active,
				'canDeactivate'      => $this->can_deactivate( $is_network_active ),
			];
		}

		return array_values( $conflicting_plugins );
	}

	/**
	 * Get the plugins screen URL for a plugin. Network-activated plugins resolve
	 * to the Network Admin plugins screen (the only place they can be managed).
	 *
	 * @param string $plugin_path          Plugin path.
	 * @param bool   $is_network_activated Whether the plugin is network-activated.
	 * @since 0.0.1
	 * @return string
	 */
	private function get_plugin_management_url( string $plugin_path, bool $is_network_activated = false ): string {
		$plugin_slug = strpos( $plugin_path, '/' ) !== false
			? dirname( $plugin_path )
			: str_replace( '.php', '', $plugin_path );

		$base_url = $is_network_activated
			? network_admin_url( 'plugins.php' )
			: self_admin_url( 'plugins.php' );

		return (string) add_query_arg(
			[
				'plugin_status' => 'all',
				's'             => $plugin_slug,
			],
			$base_url
		);
	}

	/**
	 * Plugin paths that are network-activated on this multisite network.
	 * Empty on single-site installs.
	 *
	 * @since 1.2.0
	 * @return array<int, string>
	 */
	private function get_network_active_plugins(): array {
		if ( ! is_multisite() ) {
			return [];
		}

		return array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
	}

	/**
	 * Whether a plugin is network-activated.
	 *
	 * @since 1.2.0
	 * @param string $plugin_path Plugin file path.
	 * @return bool
	 */
	private function is_network_activated_plugin( string $plugin_path ): bool {
		return in_array( $plugin_path, $this->get_network_active_plugins(), true );
	}

	/**
	 * Whether the current user can deactivate the plugin from the SureCookie UI.
	 * Mirrors the deactivate endpoint's gate: every plugin requires
	 * activate_plugins, and network-activated plugins additionally require
	 * manage_network_plugins.
	 *
	 * @since 1.2.0
	 * @param bool $is_network_activated Whether the plugin is network-activated.
	 * @return bool
	 */
	private function can_deactivate( bool $is_network_activated ): bool {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return false;
		}

		return ! $is_network_activated || current_user_can( 'manage_network_plugins' );
	}

	/**
	 * Get brand-name keywords that always indicate a conflicting consent plugin.
	 *
	 * @return array<string> List of known consent tool brand keywords.
	 * @since 0.0.1
	 */
	private function get_brand_keywords(): array {
		return [
			'trustarc',
			'iubenda',
			'onetrust',
			'cookiebot',
			'cookieyes',
			'cookiehub',
			'webtoffee',
			'wpgdpr',
			'moove-gdpr',
			'borlabs',
			'real-cookie-banner',
			'complianz',
			'optanon',
		];
	}

	/**
	 * Get generic keywords that indicate a conflict only when combined with a cookie/consent signal.
	 *
	 * @return array<string> List of generic consent-related keywords.
	 * @since 0.0.0-alpha.2
	 */
	private function get_generic_keywords(): array {
		return [
			'cookie consent',
			'cookie-consent',
			'cookie banner',
			'cookie-banner',
			'cookie notice',
			'cookie-notice',
			'cookie popup',
			'cookie-popup',
			'cookie widget',
			'cookie-widget',
			'cookie preference',
			'cookie-preference',
			'cookie manager',
			'cookie-manager',
			'cookie control',
			'cookie-control',
			'cookie solution',
			'cookie-solution',
			'cookie tracking',
			'cookie-tracking',
			'cookie regulation',
			'cookie-regulation',
			'cookie law',
			'cookie-law',
			'eu cookie law',
			'consent management',
			'consent-management',
			'user consent',
			'user-consent',
			'gdpr cookie',
			'ccpa cookie',
			'eprivacy',
			'privacy compliance',
			'privacy-compliance',
		];
	}

	/**
	 * Check if a plugin matches conflicting keywords.
	 *
	 * Brand keywords match immediately. Generic keywords require the combined
	 * plugin name + description to contain a cookie/consent signal, preventing
	 * false positives from plugins that merely mention GDPR or privacy.
	 *
	 * @param string $plugin_name        Lowercased plugin name.
	 * @param string $plugin_description Lowercased plugin description.
	 * @param string $text_domain        Lowercased text domain.
	 * @return bool
	 * @since 0.0.0-alpha.2
	 */
	private function is_plugin_conflicting( string $plugin_name, string $plugin_description, string $text_domain ): bool {
		$searchable = $plugin_name . ' ' . $plugin_description . ' ' . $text_domain;

		// Brand keywords - always a conflict.
		foreach ( $this->get_brand_keywords() as $keyword ) {
			if ( strpos( $searchable, $keyword ) !== false ) {
				return true;
			}
		}

		// Generic keywords - match only if present.
		foreach ( $this->get_generic_keywords() as $keyword ) {
			if ( strpos( $searchable, $keyword ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
