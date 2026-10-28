<?php
/**
 * AI Builder Compatibility for 'SureDonation'.
 *
 * @package AI Builder
 * @since 1.2.83
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Ai_Builder_Compatibility_SureDonation' ) ) {

	/**
	 * SureDonation Compatibility.
	 *
	 * @since 1.2.83
	 */
	class Ai_Builder_Compatibility_SureDonation {
		/**
		 * Instance
		 *
		 * @access private
		 * @var object Class object.
		 * @since 1.2.83
		 */
		private static $instance;

		/**
		 * Constructor
		 *
		 * @since 1.2.83
		 */
		public function __construct() {
			add_action( 'astra_sites_after_plugin_activation', array( $this, 'disable_suredonation_redirection' ) );
		}

		/**
		 * Initiator
		 *
		 * @since 1.2.83
		 * @return object initialized object of class.
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Disables SureDonation redirection during plugin activation.
		 *
		 * @param string $plugin_init The path to the plugin file that was just activated.
		 *
		 * @since 1.2.83
		 * @return void
		 */
		public function disable_suredonation_redirection( $plugin_init ) {
			if ( 'suredonation/suredonation.php' === $plugin_init ) {
				delete_option( '__suredonation_do_redirect' );
			}
		}
	}

	/**
	 * Kicking this off by calling 'instance()' method
	 */
	Ai_Builder_Compatibility_SureDonation::instance();
}
