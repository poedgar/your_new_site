<?php
/**
 * AI Builder Compatibility for 'SureCookie'.
 *
 * @package AI Builder
 * @since 1.2.85
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Ai_Builder_Compatibility_SureCookie' ) ) {

	/**
	 * SureCookie Compatibility.
	 *
	 * @since 1.2.85
	 */
	class Ai_Builder_Compatibility_SureCookie {
		/**
		 * Instance
		 *
		 * @access private
		 * @var object Class object.
		 * @since 1.2.85
		 */
		private static $instance;

		/**
		 * Constructor
		 *
		 * @since 1.2.85
		 */
		public function __construct() {
			add_action( 'astra_sites_after_plugin_activation', array( $this, 'disable_surecookie_redirection' ) );
		}

		/**
		 * Initiator
		 *
		 * @since 1.2.85
		 * @return object initialized object of class.
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Disables SureCookie redirection during plugin activation.
		 *
		 * @param string $plugin_init The path to the plugin file that was just activated.
		 *
		 * @since 1.2.85
		 * @return void
		 */
		public function disable_surecookie_redirection( $plugin_init ) {
			if ( 'surecookie/surecookie.php' === $plugin_init ) {
				delete_option( 'surecookie_do_activation_redirect' );
			}
		}
	}

	/**
	 * Kicking this off by calling 'instance()' method
	 */
	Ai_Builder_Compatibility_SureCookie::instance();
}
