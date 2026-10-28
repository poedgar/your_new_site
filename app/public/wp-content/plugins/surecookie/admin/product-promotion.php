<?php
/**
 * Product Promotion class.
 *
 * @package SureCookie\Admin
 */

namespace SureCookie\Admin;

use SureCookie\Inc\Traits\GetInstance;

/**
 * Handles promotional products list and status helpers.
 */
class Product_Promotion {
	use GetInstance;

	/**
	 * Retrieve promotional plugins data with live status.
	 *
	 * @since 0.0.1
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_promotional_plugins(): array {
		$promotions = [];

		foreach ( $this->get_base_promotions() as $plugin_path => $plugin_data ) {
			$plugin_slug = $this->get_plugin_slug( $plugin_path );

			$promotions[ $plugin_path ] = array_merge(
				$plugin_data,
				[
					'slug'          => $plugin_slug,
					'status'        => $this->get_plugin_status( $plugin_path ),
					'managementUrl' => $this->get_plugin_management_url( $plugin_path ),
				]
			);
		}

		return $promotions;
	}

	/**
	 * Get plugin status.
	 *
	 * @param string $plugin_path Plugin path.
	 * @since 0.0.1
	 *
	 * @return string "not-installed"|"active"|"inactive"
	 */
	public function get_plugin_status( string $plugin_path ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_plugins = get_plugins();

		if ( ! isset( $installed_plugins[ $plugin_path ] ) ) {
			return 'not-installed';
		}

		if ( is_plugin_active( $plugin_path ) ) {
			return 'active';
		}

		return 'inactive';
	}

	/**
	 * Filtered promotional plugins list (filter out active plugins).
	 *
	 * @since 0.0.1
	 * @return array<string, array<string, mixed>>
	 */
	public function get_promotional_plugins_filtered(): array {
		$all_promotions = $this->get_promotional_plugins();
		$filtered       = [];

		foreach ( $all_promotions as $plugin_path => $plugin_data ) {
			$plugin_status = $this->get_plugin_status( $plugin_path );

			if ( $plugin_status !== 'active' ) {
				$filtered[ $plugin_path ] = $plugin_data;
			}
		}

		return $filtered;
	}

	/**
	 * Base promotional plugins configuration.
	 *
	 * Using a method to allow translation functions that cannot be used in constant expressions.
	 *
	 * @since 0.0.1
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_base_promotions(): array {
		return [
			'surerank/surerank.php'             => [
				'name'        => 'SureRank SEO',
				'description' => __( 'Grow traffic of your website with SureRank - a lightweight SEO toolkit plugin for WordPress users who want better rankings without the complexity.', 'surecookie' ),
				'priority'    => 1,
				'bannerImage' => 'https://ps.w.org/surerank/assets/banner-1544x500.jpg',
			],
			'sureforms/sureforms.php'           => [
				'name'        => 'SureForms',
				'description' => __( 'A simple yet powerful way to create modern forms for your website.', 'surecookie' ),
				'priority'    => 2,
				'bannerImage' => 'https://ps.w.org/sureforms/assets/banner-1544x500.png',
			],
			'suremails/suremails.php'           => [
				'name'        => 'SureMail',
				'description' => __( 'WordPress emails often go missing or land in spam because web hosts aren\'t built for reliable delivery. SureMail fixes this by connecting to trusted SMTP services, so your emails reach inboxes, no more lost messages or frustrated customers.', 'surecookie' ),
				'priority'    => 3,
				'bannerImage' => 'https://ps.w.org/suremails/assets/banner-1544x500.jpg',
			],
			'spectra-blocks/spectra-blocks.php' => [
				'name'        => 'Spectra Blocks',
				'description' => __( 'Spectra Blocks is a complete rebuild of Spectra on WordPress\'s modern core-block APIs - an AI website builder built directly into the Block Editor.', 'surecookie' ),
				'priority'    => 4,
				'bannerImage' => 'https://ps.w.org/spectra-blocks/assets/banner-1544x500.jpg',
			],
		];
	}

	/**
	 * Get the plugin slug for a plugin path.
	 *
	 * @param string $plugin_path Plugin path.
	 * @since 0.0.1
	 * @return string
	 */
	private function get_plugin_slug( string $plugin_path ): string {
		return strpos( $plugin_path, '/' ) !== false
			? dirname( $plugin_path )
			: str_replace( '.php', '', $plugin_path );
	}

	/**
	 * Get the plugins screen URL for a plugin.
	 *
	 * @param string $plugin_path Plugin path.
	 * @since 0.0.1
	 * @return string
	 */
	private function get_plugin_management_url( string $plugin_path ): string {
		return (string) add_query_arg(
			[
				'plugin_status' => 'all',
				's'             => $this->get_plugin_slug( $plugin_path ),
			],
			self_admin_url( 'plugins.php' )
		);
	}
}
