<?php
/**
 * AI Builder Compatibility for 'SureMembers Core'.
 *
 * @package AI Builder
 * @since 1.2.82
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Ai_Builder_Compatibility_SureMembers' ) ) {

	/**
	 * SureMembers Compatibility.
	 *
	 * @since 1.2.82
	 */
	class Ai_Builder_Compatibility_SureMembers {
		/**
		 * Instance
		 *
		 * @access private
		 * @var object Class object.
		 * @since 1.2.82
		 */
		private static $instance;

		/**
		 * Constructor
		 *
		 * @since 1.2.82
		 */
		public function __construct() {
			add_action( 'astra_sites_after_plugin_activation', array( $this, 'disable_suremembers_redirection' ) );
		}

		/**
		 * Initiator
		 *
		 * @since 1.2.82
		 * @return object initialized object of class.
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Disables SureMembers redirection during plugin activation.
		 *
		 * @param string $plugin_init The path to the plugin file that was just activated.
		 *
		 * @since 1.2.82
		 * @return void
		 */
		public function disable_suremembers_redirection( $plugin_init ) {
			if ( 'suremembers-core/suremembers-core.php' === $plugin_init ) {
				delete_option( '__suremembers_do_redirect' );
			}
		}
	}

	/**
	 * Kicking this off by calling 'instance()' method
	 */
	Ai_Builder_Compatibility_SureMembers::instance();
}
