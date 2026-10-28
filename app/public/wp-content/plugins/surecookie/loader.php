<?php
/**
 * Plugin Loader.
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie;

use SureCookie\Admin\Analytics;
use SureCookie\Admin\Live_Versions;
use SureCookie\Admin\Menu;
use SureCookie\Admin\Onboarding;
use SureCookie\Admin\Post_States;
use SureCookie\Admin\Rating_Notice;
use SureCookie\Admin\Sync;
use SureCookie\Core\Frontend as Frontend_App;
use SureCookie\Core\Maintenance;
use SureCookie\Inc\API\Init as API_Initializer;
use SureCookie\Inc\Database\Init as DB_Initializer;
use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Integrations\Init as Integrations_Initializer;
use SureCookie\Inc\Integrations\Wordpress\Init as Wordpress_Abilities_Initializer;
use SureCookie\Inc\Modules\GoogleConsentMode\Actions as Gcm_Actions;
use SureCookie\Inc\Modules\Init as Modules_Initiator;
use SureCookie\Inc\Modules\Mcp\Init as Mcp_Initializer;
use SureCookie\Inc\Utils\Settings_Metadata;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SureCookie_Loader
 *
 * Handles plugin initialization, autoloading, constants, and text domain loading.
 *
 * @since 0.0.1
 */
class SureCookie_Loader {
	/**
	 * Instance
	 *
	 * @access private
	 * @var SureCookie_Loader|null Class instance.
	 * @since 0.0.1
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		// Define plugin constants.
		$this->define_constants();

		// Register autoloader.
		spl_autoload_register( [ $this, 'autoload' ] );

		add_action( 'plugins_loaded', [ $this, 'register_settings_dataset' ], 1 );
		add_action( 'plugins_loaded', [ $this, 'load_routes' ] );

		// Initialize plugin hooks.
		add_action( 'init', [ $this, 'load_textdomain' ], 1 );
		add_action( 'init', [ $this, 'load_plugin' ], 999 );
		add_action( 'admin_init', [ $this, 'activation_redirect' ] );

		// Site initialization.
		add_action( 'wp_initialize_site', [ $this, 'initialize_new_site' ] );

		// Activation hooks.
		register_activation_hook( SURECOOKIE_FILE, [ $this, 'activation' ] );
		register_deactivation_hook( SURECOOKIE_FILE, [ $this, 'deactivation' ] );

		// Remove this after the translation error is fixed.
		add_filter( 'doing_it_wrong_trigger_error', [ $this, 'suppress_translation_error' ], 10, 4 );

		// Prevent Query Monitor from collecting the error.
		add_action( 'doing_it_wrong_run', [ $this, 'prevent_qm_collection' ], 5, 3 );
	}

	/**
	 * Get instance
	 *
	 * @since 0.0.1
	 * @return SureCookie_Loader Instance of the class.
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Prevent Query Monitor from collecting textdomain errors.
	 *
	 * @param string $function_name The function that was called.
	 * @param string $message The error message.
	 * @param string $version The version.
	 * @return void
	 * @since 1.0.0
	 */
	public function prevent_qm_collection( $function_name, $message, $version ): void {
		if ( $function_name === '_load_textdomain_just_in_time' && strpos( $message, 'surecookie' ) !== false ) {
			// Remove Query Monitor's action temporarily.
			if ( class_exists( '\QM_Collectors' ) ) {
				$collector = \QM_Collectors::get( 'doing_it_wrong' );
				if ( $collector ) {
					remove_action( 'doing_it_wrong_run', [ $collector, 'action_doing_it_wrong_run' ], 10 );

					// Re-add it after this specific error.
					add_action(
						'shutdown',
						static function () use ( $collector ): void {
							if ( ! has_action( 'doing_it_wrong_run', [ $collector, 'action_doing_it_wrong_run' ] ) ) {
								add_action( 'doing_it_wrong_run', [ $collector, 'action_doing_it_wrong_run' ], 10, 3 );
							}
						},
						-1
					);
				}
			}
		}
	}

	/**
	 * Suppress translation error.
	 *
	 * @param bool   $status       Status.
	 * @param string $function_name Function name.
	 * @param string $message      Message.
	 * @param string $version      Version.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function suppress_translation_error( $status, $function_name, $message, $version ) {
		if ( $function_name === '_load_textdomain_just_in_time' && strpos( $message, 'surecookie' ) !== false ) {
			return false;
		}
		return $status;
	}

	/**
	 * Define plugin constants.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function define_constants(): void {
		! defined( 'SURECOOKIE_DEVELOPMENT_MODE' ) && define( 'SURECOOKIE_DEVELOPMENT_MODE', false );

		$css_suffix = SURECOOKIE_DEVELOPMENT_MODE ? '.css' : '.min.css';
		$js_suffix  = SURECOOKIE_DEVELOPMENT_MODE ? '.js' : '.min.js';

		define( 'SURECOOKIE_CSS_SUFFIX', $css_suffix );
		define( 'SURECOOKIE_JS_SUFFIX', $js_suffix );
	}

	/**
	 * Autoload plugin classes.
	 *
	 * @param string $class Class name.
	 * @return void
	 */
	public function autoload( $class ): void {
		if ( strpos( $class, __NAMESPACE__ ) !== 0 ) {
			return;
		}

		$class_to_load = $class;

		$filename = preg_replace( // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative
			[ '/^' . __NAMESPACE__ . '\\\/', '/([a-z])([A-Z])/', '/_/', '/\\\/' ],
			[ '', '$1-$2', '-', DIRECTORY_SEPARATOR ],
			$class_to_load
		);

		if ( is_string( $filename ) ) {
			$filename = strtolower( $filename );

			$file = SURECOOKIE_DIR . $filename . '.php';

			// if the file readable, include it.
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	}

	/**
	 * Register settings-dataset contributions that must exist before init:20.
	 *
	 * The MCP adapter builds the abilities registry at init:20, which freezes
	 * manage-settings' input schema and primes the static cache in
	 * Settings::get_settings_defaults(). Modules boot at init:999, so a module
	 * that contributes setting keys registers that one filter here instead;
	 * registering it in the module would leave those keys unwritable over MCP
	 * and absent from the defaults for the rest of the request.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public function register_settings_dataset(): void {
		add_filter( 'surecookie_plugin_settings_dataset', [ Gcm_Actions::class, 'add_gcm_settings_to_dataset' ] );

		// Priority 20 so every contributor has added its keys first; the
		// annotator only touches keys that already exist.
		add_filter( 'surecookie_plugin_settings_dataset', [ Settings_Metadata::class, 'merge' ], 20 );
	}

	/**
	 * Load routes.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function load_routes(): void {
		do_action( 'surecookie_before_load_routes' );

		/* Maintenance & API Initializer tasks */
		Maintenance::get_instance();
		API_Initializer::get_instance();

		/*
		 * MCP + abilities must hook before init:20, where the MCP Adapter fires
		 * mcp_adapter_init under WP-CLI (modules/integrations auto-load too late
		 * at init:999). Singletons, so the later auto-discovery calls are no-ops.
		 */
		Mcp_Initializer::get_instance();
		if ( function_exists( 'wp_register_ability' ) ) {
			Wordpress_Abilities_Initializer::get_instance();
		}

		/* BSF Analytics */
		Analytics::get_instance();

		do_action( 'surecookie_after_load_routes' );
	}

	/**
	 * Load plugin classes and initialize.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function load_plugin(): void {
		do_action( 'surecookie_before_load_components' );

		/* Site scanning sync processor */
		Sync::get_instance();

		/* Include all modules */
		Modules_Initiator::get_instance();

		/* Initialize third-party integrations (Abilities API, etc.) */
		Integrations_Initializer::get_instance();

		Live_Versions::maybe_clear_cache();

		if ( is_admin() ) {
			/* Admin menu */
			Menu::get_instance();
			Post_States::get_instance();
			Onboarding::get_instance();
			Rating_Notice::get_instance();
		} else {
			/* Public app */
			Frontend_App::get_instance();
		}

		do_action( 'surecookie_after_load_components' );

		/**
		 * SureCookie Init.
		 *
		 * Fires when SureCookie is instantiated.
		 *
		 * @since 0.0.1
		 */
		do_action( 'surecookie_loaded' );
	}

	/**
	 * Load plugin textdomain.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function load_textdomain(): void {
		// Registered before the catalog loads so the very first lookup can
		// already fall back from a regional locale (de_CH) to the shipped
		// base-language file (de_DE) - for free and Pro alike (issue #1034).
		\SureCookie\Inc\Utils\Locale_Fallback::register();

		load_plugin_textdomain( 'surecookie', false, dirname( SURECOOKIE_BASE ) . '/languages/' );
	}

	/**
	 * Check if we should redirect.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function activation_redirect(): void {
		// Avoid redirection in case of WP_CLI calls.
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			return;
		}

		// Avoid redirection in case of ajax calls.
		if ( wp_doing_ajax() ) {
			return;
		}

		// Check if we should redirect.
		$do_redirect = get_option( 'surecookie_do_activation_redirect' );

		if ( $do_redirect ) {
			// Clear the redirect option so it doesn't happen again.
			delete_option( 'surecookie_do_activation_redirect' );

			// Make sure we're not in a multisite network.
			if ( ! is_multisite() ) {
				$is_onboarding_completed = (bool) Get::option( SURECOOKIE_ONBOARDING_COMPLETED_OPTION, false );

				// Always redirect to onboarding for fresh activations, dashboard if onboarding is completed.
				$redirect_url = $is_onboarding_completed ? admin_url( 'admin.php?page=surecookie' ) : admin_url( 'admin.php?page=surecookie-onboarding' );

				wp_safe_redirect( $redirect_url );
				exit;
			}
		}
	}

	/**
	 * Initialize database tables when a new site is created in multisite.
	 *
	 * @param \WP_Site $site The new site's object.
	 * @since 0.0.1
	 * @return void
	 */
	public function initialize_new_site( $site ): void {
		DB_Initializer::initialize_new_site( $site );
	}

	/**
	 * Activation trigger.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function activation(): void {
		update_option( 'surecookie_do_activation_redirect', true );
		if ( get_option( 'surecookie_usage_installed_time', false ) === false ) {
			add_option( 'surecookie_usage_installed_time', time(), '', false );
		}
		DB_Initializer::create_db_tables();
		Maintenance::store_db_version();

		// Warm the remote datasets shortly after activation (off-request) so the
		// blocking + declared-cookie catalogs are current without waiting for the
		// first daily cron tick. Bundled floors cover the interim.
		if ( ! wp_next_scheduled( 'surecookie_refresh_datasets' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'surecookie_refresh_datasets' );
		}
	}

	/**
	 * Deactivation trigger.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function deactivation(): void {
		wp_clear_scheduled_hook( 'surecookie_cleanup_consent_logs' );
		wp_clear_scheduled_hook( 'surecookie_poll_scan_status' );
		wp_clear_scheduled_hook( 'surecookie_auto_scan_run' );
		wp_clear_scheduled_hook( 'surecookie_auto_scan_retry' );
		wp_clear_scheduled_hook( 'surecookie_refresh_datasets' );
		wp_clear_scheduled_hook( 'surecookie_first_party_detect' );
	}
}

/**
 * Kick off the plugin by calling get_instance().
 */
SureCookie_Loader::get_instance();
