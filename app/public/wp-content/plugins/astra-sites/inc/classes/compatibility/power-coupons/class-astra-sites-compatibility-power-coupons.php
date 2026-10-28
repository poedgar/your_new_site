<?php
/**
 * Astra Sites Compatibility for 'Power Coupons'.
 *
 * @package Astra Sites
 * @since 4.6.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Astra_Sites_Compatibility_Power_Coupons' ) ) {

	/**
	 * Power Coupons Compatibility.
	 *
	 * @since 4.6.0
	 */
	class Astra_Sites_Compatibility_Power_Coupons {

		/**
		 * Instance
		 *
		 * @access private
		 * @var self Class object.
		 *
		 * @since 4.6.0
		 */
		private static $instance = null;

		/**
		 * Constructor.
		 *
		 * @since 4.6.0
		 */
		public function __construct() {
			add_action( 'astra_sites_after_plugin_activation', array( $this, 'disable_power_coupons_redirection' ) );
		}

		/**
		 * Initiator.
		 *
		 * @since 4.6.0
		 * @return self initialized object of class.
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Disables Power Coupons onboarding redirection during plugin activation.
		 *
		 * @param string $plugin_init The path to the plugin file that was just activated.
		 *
		 * @since 4.6.0
		 * @return void
		 */
		public function disable_power_coupons_redirection( $plugin_init ) {
			if ( 'power-coupons/power-coupons.php' === $plugin_init ) {
				delete_transient( 'power_coupons_redirect_to_onboarding' );
			}
		}
	}

	/**
	 * Kicking this off by calling 'get_instance()' method.
	 */
	Astra_Sites_Compatibility_Power_Coupons::get_instance();
}
