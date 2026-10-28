<?php
/**
 * AI Builder Compatibility for 'UAG'
 *
 * @see  https://wordpress.org/plugins/ultimate-addons-for-gutenberg/
 *
 * @package AI Builder
 * @since 3.0.15
 */

/**
 * UAG compatibility for Starter Templates.
 */
class Ai_Builder_Compatibility_UAG {
	/**
	 * Instance
	 *
	 * @access private
	 * @var object Class object.
	 * @since 3.0.15
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'astra_sites_after_plugin_activation', array( $this, 'uag_activation' ), 10 );
	}

	/**
	 * Initiator
	 *
	 * @since 3.0.15
	 * @return object initialized object of class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Disable redirect after installing and activating UAG or Spectra Blocks.
	 *
	 * @since 3.0.15
	 * @param string $plugin_init The path to the plugin file that was just activated.
	 * @return void
	 */
	public function uag_activation( $plugin_init ) {
		if ( 'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php' === $plugin_init ) {
			update_option( '__uagb_do_redirect', false );
			update_option( 'spectra_onboarding', array( 'status' => 'completed' ) );
		}

		// Spectra Blocks 1.0.0 renamed the redirect flag and the onboarding-completed option.
		if ( 'spectra-blocks/spectra-blocks.php' === $plugin_init ) {
			update_option( '__spectra_blocks_do_redirect', false );
			update_option( 'spectra_blocks_onboarding', array( 'status' => 'completed' ) );
		}
	}
}

/**
 * Kicking this off by calling 'get_instance()' method
 */
Ai_Builder_Compatibility_UAG::get_instance();
