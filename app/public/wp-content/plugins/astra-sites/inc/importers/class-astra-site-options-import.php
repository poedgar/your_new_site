<?php
/**
 * Customizer Site options importer class.
 *
 * @since  1.0.0
 * @package Astra Addon
 */

use STImporter\Importer\Helpers\ST_Image_Importer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customizer Site options importer class.
 *
 * @since  1.0.0
 */
class Astra_Site_Options_Import {

	/**
	 * Instance of Astra_Site_Options_Importer
	 *
	 * @since  1.0.0
	 * @var (Object) Astra_Site_Options_Importer
	 */
	private static $instance = null;

	/**
	 * Instanciate Astra_Site_Options_Importer
	 *
	 * @since  1.0.0
	 * @return (Object) Astra_Site_Options_Importer
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'st_importer_site_options', array( $this, 'classic_templates_options' ), 10, 1 );
		add_action( 'st_importer_import_site_options', array( $this, 'import_classic_templates_options' ), 10, 1 );
	}

	/**
	 * WooCommerce page title options.
	 *
	 * Each of these carries a page title in the template payload, and is mapped onto
	 * its `_id` counterpart once the pages exist. Shared by the option whitelist and
	 * the importer so both stay in step when a page is added or removed.
	 *
	 * @since 4.7.5
	 * @return array<int, string> List of WooCommerce page title option names.
	 */
	private static function woocommerce_page_title_options() {
		return array(
			'woocommerce_shop_page_title',
			'woocommerce_cart_page_title',
			'woocommerce_checkout_page_title',
			'woocommerce_myaccount_page_title',
			'woocommerce_edit_address_page_title',
			'woocommerce_view_order_page_title',
			'woocommerce_change_password_page_title',
			'woocommerce_logout_page_title',
		);
	}

	/**
	 * Classic templates options.
	 *
	 * @since 4.3.0
	 * @param array<int, string> $default_options List of defined array.
	 * @return array<int, string> List of defined array.
	 */
	public function classic_templates_options( $default_options ) {

		$classic_templates_options = array(
			'custom_logo',
			'nav_menu_locations',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'site_title',

			// Plugin: Elementor.
			'elementor_container_width',
			'elementor_cpt_support',
			'elementor_css_print_method',
			'elementor_default_generic_fonts',
			'elementor_disable_color_schemes',
			'elementor_disable_typography_schemes',
			'elementor_editor_break_lines',
			'elementor_exclude_user_roles',
			'elementor_global_image_lightbox',
			'elementor_page_title_selector',
			'elementor_scheme_color',
			'elementor_scheme_color-picker',
			'elementor_scheme_typography',
			'elementor_space_between_widgets',
			'elementor_stretched_section_container',
			'elementor_load_fa4_shim',
			'elementor_active_kit',
			'elementor_experiment-container',

			// Plugin: Beaver Builder.
			'_fl_builder_enabled_icons',
			'_fl_builder_enabled_modules',
			'_fl_builder_post_types',
			'_fl_builder_color_presets',
			'_fl_builder_services',
			'_fl_builder_settings',
			'_fl_builder_user_access',
			'_fl_builder_enabled_templates',

			// Account & Privacy.
			'woocommerce_enable_guest_checkout',
			'woocommerce_enable_checkout_login_reminder',
			'woocommerce_enable_signup_and_login_from_checkout',
			'woocommerce_enable_myaccount_registration',
			'woocommerce_registration_generate_username',

			// Plugin: Easy Digital Downloads - EDD.
			'edd_settings',

			// Plugin: WPForms.
			'wpforms_settings',

			// Categories.
			'woocommerce_product_cat',

			// Plugin: LearnDash LMS.
			'learndash_settings_theme_ld30',
			'learndash_settings_courses_themes',

			// Astra Theme Global Color Palette and Typography Preset options.
			'astra-color-palettes',
			'astra-typography-presets',
		);

		// Plugin: WooCommerce pages.
		$classic_templates_options = array_merge( $classic_templates_options, self::woocommerce_page_title_options() );

		return array_merge( $default_options, $classic_templates_options );
	}

	/**
	 * Import Classic Templates Options.
	 *
	 * @since 4.3.0
	 *
	 * @param array<string, mixed> $options List of default options.
	 *
	 * @return void
	 */
	public function import_classic_templates_options( $options ) {

		if ( empty( $options ) || ! is_array( $options ) ) {
			Astra_Sites_Importer_Log::add( 'No site options found to import.' );
			return;
		}

		/**
		 * Only the WooCommerce specific mappings are handled here.
		 *
		 * Every other option in the payload — including the logo, menu locations, front
		 * page and the plain `update_option()` writes — has already been imported by
		 * ST_Importer::import_options() before this hook fires. Walking the full option
		 * set again doubled the work of a step that already runs close to the request
		 * timeout on slower hosts.
		 */
		try {
			foreach ( self::woocommerce_page_title_options() as $option_name ) {
				if ( ! empty( $options[ $option_name ] ) ) {
					$this->update_woocommerce_page_id_by_option_value( $option_name, $options[ $option_name ] );
				}
			}

			if ( ! empty( $options['woocommerce_product_cat'] ) ) {
				$this->set_woocommerce_product_cat( $options['woocommerce_product_cat'] );
			}

			Astra_Sites_Importer_Log::add( 'Classic templates WooCommerce options import completed successfully', 'success' );
		} catch ( Exception $e ) {
			// Failed silently: the remaining options are already imported, so a WooCommerce
			// mapping failure should not abort the import.
			Astra_Sites_Importer_Log::add( 'Site options import exception: ' . $e->getMessage(), 'warning' );
			astra_sites_error_log( 'Error while importing site options: ' . $e->getMessage() );
		}
	}

	/**
	 * Get post from post title and post type.
	 *
	 * @since 4.0.6
	 *
	 * @param  mixed  $post_title  post title.
	 * @param  string $post_type post type.
	 * @return mixed
	 */
	public function get_page_by_title( $post_title, $post_type ) {
		$page = array();
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'title'                  => $post_title,
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
			)
		);
		if ( $query->have_posts() ) {
			$page = $query->posts[0];
		}
		return $page;
	}

	/**
	 * Update post option
	 *
	 * @since 1.0.2
	 *
	 * @param  string $option_name  Option name.
	 * @param  mixed  $option_value Option value.
	 * @return void
	 */
	private function update_page_id_by_option_value( $option_name, $option_value ) {
		Astra_Sites_Importer_Log::add( 'Updating page ID for option: ' . $option_name . ' with value: ' . $option_value );

		if ( empty( $option_value ) ) {
			return;
		}

		$page = $this->get_page_by_title( $option_value, 'page' );

		if ( is_object( $page ) ) {
			Astra_Sites_Importer_Log::add( 'Page ID updated for ' . $option_name . ': ' . $page->ID );
			update_option( $option_name, $page->ID );
		} else {
			Astra_Sites_Importer_Log::add( 'Page not found for title: ' . $option_value );
		}
	}

	/**
	 * Update WooCommerce page ids.
	 *
	 * @since 1.1.6
	 *
	 * @param  string $option_name  Option name.
	 * @param  mixed  $option_value Option value.
	 * @return void
	 */
	private function update_woocommerce_page_id_by_option_value( $option_name, $option_value ) {
		Astra_Sites_Importer_Log::add( 'Updating WooCommerce page ID for option: ' . $option_name . ' with value: ' . $option_value );
		$option_name = str_replace( '_title', '_id', $option_name );
		$this->update_page_id_by_option_value( $option_name, $option_value );
	}

	/**
	 * Set WooCommerce category images.
	 *
	 * @since 1.1.4
	 *
	 * @param array $cats Array of categories.
	 */
	private function set_woocommerce_product_cat( $cats = array() ) {
		Astra_Sites_Importer_Log::add( 'Processing WooCommerce product categories - Total: ' . count( $cats ) );

		if ( isset( $cats ) ) {
			foreach ( $cats as $key => $cat ) {
				if ( ! empty( $cat['slug'] ) && ! empty( $cat['thumbnail_src'] ) ) {
					$downloaded_image = ST_Image_Importer::get_instance()->import(
						array(
							'url' => $cat['thumbnail_src'],
							'id'  => 0,
						)
					);

					if ( $downloaded_image['id'] ) {
						$term = get_term_by( 'slug', $cat['slug'], 'product_cat' );

						if ( is_object( $term ) ) {
							Astra_Sites_Importer_Log::add( 'WooCommerce category thumbnail set for: ' . $cat['slug'] );
							update_term_meta( $term->term_id, 'thumbnail_id', $downloaded_image['id'] );
						}
					}
				}
			}

			Astra_Sites_Importer_Log::add( 'WooCommerce product categories processed successfully', 'success' );
		}
	}

}

/**
 * Kicking this off by calling 'get_instance()' method
 */
Astra_Site_Options_Import::instance();
