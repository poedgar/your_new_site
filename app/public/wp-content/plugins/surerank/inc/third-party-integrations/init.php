<?php
/**
 * Third-party plugins initialization
 *
 * This file handles initialization of all third-party plugin integrations.
 *
 * @package surerank
 * @since 1.5.0
 */

namespace SureRank\Inc\ThirdPartyIntegrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\Functions\Settings;
use SureRank\Inc\ThirdPartyIntegrations\Multilingual\Init as Multilingual;
use SureRank\Inc\Traits\Get_Instance;

/**
 * Third-party plugins initialization class
 *
 * Manages loading of all integrations with third-party plugins.
 *
 * @since 1.5.0
 */
class Init {

	use Get_Instance;

	/**
	 * Constructor
	 *
	 * @since 1.5.0
	 */
	public function __construct() {
		// Register integration setting keys with the Role Manager allowlist in all
		// contexts (incl. REST), so the keys are not stripped on Pro Role Manager sites.
		add_filter( 'surerank_role_manager_option_mappings', [ $this, 'register_role_manager_mappings' ] );

		if ( is_admin() ) {
			$this->load_admin_integrations();
		}
		$this->load_frontend_integrations();
	}

	/**
	 * Register integration setting keys with the Role Manager option mappings.
	 *
	 * SureRank Pro's Role Manager strips any settings key not in its allowlist
	 * from the settings API (read and save). Registering the FluentCart key here
	 * keeps the free plugin self-contained — it is a no-op when Pro is not active.
	 *
	 * @since 1.9.2
	 * @param mixed $mappings Role Manager option mappings keyed by capability.
	 * @return mixed
	 */
	public function register_role_manager_mappings( $mappings ) {
		if ( ! is_array( $mappings ) || ! isset( $mappings['surerank_global_setting'] ) || ! is_array( $mappings['surerank_global_setting'] ) ) {
			return $mappings;
		}

		if ( ! in_array( 'enable_fluentcart_integration', $mappings['surerank_global_setting'], true ) ) {
			$mappings['surerank_global_setting'][] = 'enable_fluentcart_integration';
		}

		return $mappings;
	}

	/**
	 * Load admin-specific integrations
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function load_admin_integrations(): void {
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			Elementor::get_instance();
		}

		Avada_Fusion_Builder::get_instance();
	}

	/**
	 * Load frontend-specific integrations
	 *
	 * Only loads integration classes when the corresponding plugin is active,
	 * avoiding unnecessary class autoloading and constructor overhead.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function load_frontend_integrations(): void {
		$enable_woocommerce_integration = $this->is_integration_enabled( 'enable_woocommerce_integration' );
		$enable_angie_integration       = $this->is_integration_enabled( 'enable_angie_integration' );
		$enable_fluentcart_integration  = $this->is_integration_enabled( 'enable_fluentcart_integration' );

		if ( defined( 'BRICKS_VERSION' ) ) {
			Bricks::get_instance();
		}

		if ( class_exists( 'WooCommerce' ) && $enable_woocommerce_integration ) {
			Woocommerce::get_instance();
		}

		if ( defined( 'FLUENTCART_VERSION' ) && $enable_fluentcart_integration ) {
			Fluentcart::get_instance();
		}

		if ( defined( 'ANGIE_VERSION' ) && $enable_angie_integration ) {
			Angie::get_instance();
		}

		if ( defined( 'CARTFLOWS_VER' ) ) {
			CartFlows::get_instance();
		}

		if ( defined( 'ET_BUILDER_VERSION' ) ) {
			Divi::get_instance();
		}

		if ( defined( '__BREAKDANCE_VERSION' ) ) {
			Breakdance::get_instance();
		}

		if ( class_exists( 'td_global_blocks' ) ) {
			Tagdiv::get_instance();
		}

		if ( defined( 'ETCH_PLUGIN_DIR' ) ) {
			Etch::get_instance();
		}

		Multilingual::get_instance();
	}

	/**
	 * Check whether a third-party integration is enabled.
	 *
	 * Settings are normalized to booleans before being used with plugin
	 * availability checks so malformed stored values do not accidentally load
	 * an integration.
	 *
	 * @param string $setting_key Integration setting key.
	 * @return bool
	 * @since 1.7.5
	 */
	private function is_integration_enabled( string $setting_key ): bool {
		$value = Settings::get( $setting_key );

		return true === $value || 1 === $value || '1' === $value || 'true' === $value;
	}
}
