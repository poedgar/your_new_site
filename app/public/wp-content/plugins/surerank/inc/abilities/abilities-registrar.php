<?php
/**
 * Abilities registrar.
 *
 * @package SureRank\Inc\Abilities
 */

namespace SureRank\Inc\Abilities;

use SureRank\Inc\Abilities\Analysis\Get_Post_Seo_Checks;
use SureRank\Inc\Abilities\Analysis\Get_Term_Seo_Checks;
use SureRank\Inc\Abilities\Content\Get_Post_Seo;
use SureRank\Inc\Abilities\Content\Get_Term_Seo;
use SureRank\Inc\Abilities\Content\List_Content_Items;
use SureRank\Inc\Abilities\Content\List_Content_Types;
use SureRank\Inc\Abilities\Content\Update_Post_Seo;
use SureRank\Inc\Abilities\Content\Update_Term_Seo;
use SureRank\Inc\Abilities\Settings\Get_Global_Settings;
use SureRank\Inc\Abilities\Settings\Get_Robots_Txt;
use SureRank\Inc\Abilities\Settings\Get_Sitemap_Settings;
use SureRank\Inc\Abilities\Settings\Update_Global_Settings;
use SureRank\Inc\Abilities\Settings\Update_Robots_Txt;
use SureRank\Inc\Abilities\Settings\Update_Sitemap_Settings;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Traits\Get_Instance;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers SureRank abilities and the optional MCP server.
 */
class Abilities_Registrar {
	use Get_Instance;

	/**
	 * Outcome of this request's MCP server registration attempt.
	 *
	 * Request scoped on purpose. An adapter only announces itself during a REST
	 * request, so a stored value would be read back on admin page loads that never
	 * attempted a registration, describing some earlier request instead of the
	 * current state. The status route reads this in the request that produced it.
	 *
	 * @var WP_Error|bool|null Null until an adapter has announced itself.
	 * @since 1.10.2
	 */
	private static $registration_result = null;

	/**
	 * Constructor.
	 *
	 * @since 1.7.5
	 */
	public function __construct() {
		// Single admin toggle (enable_mcp) gates both the abilities and the MCP server.
		add_filter( 'surerank_abilities_api_enabled', [ $this, 'is_mcp_enabled' ] );
		add_filter( 'surerank_mcp_server_enabled', [ $this, 'is_mcp_enabled' ] );

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );

		/*
		 * Subscribed without asking first whether an adapter is present. This runs on
		 * plugins_loaded, while the adapter announces itself much later, on init or
		 * rest_api_init, so a check here decides on a state that is not final yet and
		 * a miss is permanent for the request. The callback re-checks instead, and the
		 * abilities hooks above only fire when the Abilities API is there at all.
		 */
		add_action( 'mcp_adapter_init', [ $this, 'register_mcp_server' ] );
	}

	/**
	 * Whether the MCP integration is enabled via the admin setting.
	 *
	 * Used as the callback for the `surerank_abilities_api_enabled` and
	 * `surerank_mcp_server_enabled` filters so a single toggle controls both the
	 * abilities registration and the MCP server endpoint.
	 *
	 * @since 1.9.0
	 * @return bool
	 */
	public function is_mcp_enabled() {
		return (bool) Settings::get( 'enable_mcp' );
	}

	/**
	 * Whether the MCP Adapter plugin is available.
	 *
	 * Supports both the current `WordPress/mcp-adapter` plugin (`McpAdapter`) and
	 * the deprecated `Automattic/wordpress-mcp` plugin (`Plugin`).
	 *
	 * @since 1.9.0
	 * @return bool
	 */
	public static function is_adapter_available() {
		return class_exists( 'WP\\MCP\\Core\\McpAdapter' ) || class_exists( 'WP\\MCP\\Plugin' );
	}

	/**
	 * Check whether the MCP adapter should be activated.
	 *
	 * @since 1.7.5
	 * @return bool
	 */
	public static function mcp_adapter_enabled() {
		return function_exists( 'wp_register_ability' ) &&
			self::is_adapter_available() &&
			(bool) apply_filters( 'surerank_mcp_server_enabled', true );
	}

	/**
	 * Register the SureRank MCP server.
	 *
	 * @since 1.7.5
	 * @param object $adapter MCP adapter instance.
	 * @return void
	 */
	public function register_mcp_server( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		if ( ! self::mcp_adapter_enabled() ) {
			return;
		}

		$abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : [];
		$tools     = [];

		foreach ( $abilities as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}

			if ( 0 === strpos( $ability->get_name(), 'surerank/' ) ) {
				$tools[] = $ability->get_name();
			}
		}

		$transport_class = class_exists( '\\WP\\MCP\\Transport\\HttpTransport' )
			? '\\WP\\MCP\\Transport\\HttpTransport'
			: '\\WP\\MCP\\Transport\\Http\\RestTransport';

		$created = $adapter->create_server(
			'surerank',
			'surerank/v1',
			'mcp',
			__( 'SureRank MCP Server', 'surerank' ),
			__( 'SureRank MCP Server for SEO settings, metadata, and analysis workflows.', 'surerank' ),
			SURERANK_VERSION,
			[ $transport_class ],
			'\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler',
			'\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler',
			$tools,
			[],
			[]
		);

		if ( is_wp_error( $created ) ) {
			self::$registration_result = $created;
			return;
		}

		/* Ask the adapter whether the server is really there, rather than trusting a return shape. */
		if ( method_exists( $adapter, 'get_server' ) && null === $adapter->get_server( 'surerank' ) ) {
			self::$registration_result = new WP_Error(
				'surerank_mcp_server_missing',
				__( 'The adapter reported no server named "surerank" after creating one.', 'surerank' )
			);
			return;
		}

		self::$registration_result = true;
	}

	/**
	 * MCP server status for the current request.
	 *
	 * Only meaningful inside a REST request, which is the one place an adapter
	 * announces itself and a registration is attempted at all.
	 *
	 * `registration_expected` is the same condition the registration itself runs
	 * on, so every reason to skip it (the admin toggle, a missing Abilities API,
	 * a third party filtering the server off) reads as "nothing to report" rather
	 * than as a failure.
	 *
	 * @since 1.10.2
	 * @return array<string, bool|string>
	 */
	public static function get_server_status() {
		$result = self::$registration_result;

		return [
			'registration_expected' => self::mcp_adapter_enabled(),
			'server_registered'     => true === $result,
			'message'               => $result instanceof WP_Error ? $result->get_error_message() : '',
		];
	}

	/**
	 * Register the SureRank category.
	 *
	 * @since 1.7.5
	 * @return void
	 */
	public function register_category() {
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'surerank' ) ) {
			return;
		}

		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				'surerank',
				[
					'label'       => __( 'SureRank', 'surerank' ),
					'description' => __( 'SEO settings, metadata, and analysis abilities powered by SureRank.', 'surerank' ),
				]
			);
		}
	}

	/**
	 * Register SureRank abilities.
	 *
	 * @since 1.7.5
	 * @return void
	 */
	public function register_abilities() {
		if ( ! Ability_Base::abilities_enabled() ) {
			return;
		}

		$abilities = [
			new List_Content_Types(),
			new List_Content_Items(),
			new Get_Global_Settings(),
			new Update_Global_Settings(),
			new Get_Post_Seo(),
			new Update_Post_Seo(),
			new Get_Term_Seo(),
			new Update_Term_Seo(),
			new Get_Post_Seo_Checks(),
			new Get_Term_Seo_Checks(),
			new Get_Robots_Txt(),
			new Update_Robots_Txt(),
			new Get_Sitemap_Settings(),
			new Update_Sitemap_Settings(),
		];

		$abilities = apply_filters( 'surerank_register_abilities', $abilities );

		foreach ( $abilities as $ability ) {
			if ( ! $ability instanceof Ability_Base ) {
				continue;
			}

			if ( ! $ability->meets_capability_policy() || ! $ability->is_enabled() ) {
				continue;
			}

			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability->get_id() ) ) {
				continue;
			}

			$ability->register();
		}
	}
}
