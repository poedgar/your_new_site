<?php
/**
 * Plugin Name: SureContact – Newsletters, Email Marketing, Automation, Revenue Tracking & CRM
 * Plugin URI: https://surecontact.com
 * Description: Send newsletters, set up email automations, manage contacts and track ecommerce revenue in a CRM for WordPress.
 * Version: 1.5.5
 * Author: SureContact
 * Author URI: https://profiles.wordpress.org/brainstormforce/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: surecontact
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 7.1
 * Requires PHP: 7.4
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SaaS API Base URL constant
 *
 * @since 0.0.1
 */
if ( ! defined( 'SURECONTACT_SAAS_API_BASE_URL' ) ) {
	define( 'SURECONTACT_SAAS_API_BASE_URL', 'https://api.surecontact.com' );
}

/**
 * SaaS SDK Base URL constant
 *
 * @since 0.0.1
 */
if ( ! defined( 'SURECONTACT_SAAS_BASE_URL' ) ) {
	define( 'SURECONTACT_SAAS_BASE_URL', 'https://app.surecontact.com' );
}

/**
 * Main plugin class
 *
 * Singleton pattern implementation for SureContact plugin.
 * Handles plugin initialization, dependency loading, and lifecycle hooks.
 *
 * @since 0.0.1
 */
final class SureContact {

	/**
	 * Plugin version
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	const VERSION = '1.5.5';

	/**
	 * Plugin singleton instance
	 *
	 * @since 0.0.1
	 *
	 * @var SureContact|null
	 */
	private static $instance = null;

	/**
	 * Plugin directory path
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	private $plugin_path;

	/**
	 * Plugin directory URL
	 *
	 * @since 0.0.1
	 *
	 * @var string
	 */
	private $plugin_url;

	/**
	 * Integrations loader instance
	 *
	 * @since 0.0.1
	 *
	 * @var SureContact\Integrations_Loader|null
	 */
	public $integrations_loader;

	/**
	 * Daily Sync Manager instance
	 *
	 * @since 0.0.1
	 *
	 * @var SureContact\Daily_Sync_Manager|null
	 */
	private $daily_sync_manager;


	/**
	 * Bulk Sync Service instance
	 *
	 * @since 0.0.1
	 *
	 * @var SureContact\Bulk_Sync_Service|null
	 */
	private $bulk_sync_service;

	/**
	 * Admin Menu instance
	 *
	 * @since 0.0.1
	 *
	 * @var SureContact\Admin\Admin_Menu|null
	 */
	private $admin_menu;


	/**
	 * Queue Manager instance
	 *
	 * @since 0.0.1
	 *
	 * @var SureContact\Queue_Manager|null
	 */
	private $queue_manager;

	/**
	 * Abandoned Cart Manager instance
	 *
	 * @since 1.5.0
	 *
	 * @var SureContact\Abandoned_Cart_Manager|null
	 */
	private $abandoned_cart_manager;

	/**
	 * Get singleton instance
	 *
	 * Implements lazy loading for better performance.
	 *
	 * @since 0.0.1
	 *
	 * @return SureContact
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation
	 *
	 * @since 0.0.1
	 */
	private function __construct() {
		$this->define_constants();
		$this->setup_hooks();
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Define plugin constants
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function define_constants() {
		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->plugin_url  = plugin_dir_url( __FILE__ );

		define( 'SURECONTACT_VERSION', self::VERSION );
		define( 'SURECONTACT_PLUGIN_PATH', $this->plugin_path );
		define( 'SURECONTACT_PLUGIN_URL', $this->plugin_url );
		define( 'SURECONTACT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
	}

	/**
	 * Setup activation and deactivation hooks
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function setup_hooks() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	/**
	 * Load required dependencies
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function load_dependencies() {
		$this->load_action_scheduler();

		// Load global helper functions.
		require_once SURECONTACT_PLUGIN_PATH . 'includes/functions.php';

		$autoloader_path = SURECONTACT_PLUGIN_PATH . 'includes/class-autoloader.php';
		if ( file_exists( $autoloader_path ) ) {
			require_once $autoloader_path;
		} else {
			// Autoloader not loaded yet, use direct error_log.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				'[' . gmdate( 'Y-m-d H:i:s' ) . '] SureContact [ERROR] Plugin: Critical error - autoloader not found at ' . $autoloader_path
			);
		}
	}

	/**
	 * Initialize plugin components via WordPress hooks
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Ensure database tables exist (safety check for edge cases).
		add_action( 'plugins_loaded', array( $this, 'maybe_create_tables' ), 1 );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init_queue_manager' ), 2 );
		add_action( 'init', array( $this, 'init_abandoned_cart_manager' ), 2 );
		add_action( 'init', array( $this, 'init_daily_sync_manager' ), 3 );
		add_action( 'init', array( $this, 'init_bulk_sync_service' ), 5 );
		// Load integrations early on init hook to ensure hooks are registered before plugin actions fire.
		add_action( 'init', array( $this, 'init_integrations' ), 5 );
		add_action( 'rest_api_init', array( $this, 'init_rest_api' ) );

		SureContact\Abilities\Abilities_Loader::instance()->init();

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'init_admin_menus' ) );
			add_filter( 'plugin_action_links_' . SURECONTACT_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );
			add_filter( 'plugin_row_meta', array( $this, 'add_meta_links' ), 10, 2 );
		}
	}

	/**
	 * Load plugin textdomain for translations
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'surecontact',
			false,
			dirname( SURECONTACT_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Initialize Queue Manager.
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function init_queue_manager() {
		if ( is_null( $this->queue_manager ) ) {
			$this->queue_manager = new SureContact\Queue_Manager();
		}
	}


	/**
	 * Initialize Daily Sync Manager
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function init_daily_sync_manager() {
		if ( is_null( $this->daily_sync_manager ) ) {
			$this->daily_sync_manager = new SureContact\Daily_Sync_Manager();
		}
	}


	/**
	 * Initialize Abandoned Cart Manager
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function init_abandoned_cart_manager() {
		if ( is_null( $this->abandoned_cart_manager ) ) {
			$this->abandoned_cart_manager = new SureContact\Abandoned_Cart_Manager();
		}
	}

	/**
	 * Initialize admin menus
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function init_admin_menus() {
		if ( is_null( $this->admin_menu ) ) {
			$this->admin_menu = new SureContact\Admin\Admin_Menu();
		}
	}

	/**
	 * Initialize REST API
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function init_rest_api() {
		$rest_controller = new SureContact\API_WP\Rest_Controller();
		$rest_controller->register_routes();

		SureContact\API_WP\Api_Init::instance();
	}

	/**
	 * Initialize Bulk Sync Service
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function init_bulk_sync_service() {
		if ( is_null( $this->bulk_sync_service ) ) {
			$this->bulk_sync_service = new SureContact\Bulk_Sync_Service();
		}
	}

	/**
	 * Initialize integrations loader
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function init_integrations() {
		$this->integrations_loader = new SureContact\Integrations_Loader();
	}

	/**
	 * Plugin activation handler
	 *
	 * Creates database tables and performs initial setup.
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function activate() {
		// Create database tables.
		require_once SURECONTACT_PLUGIN_PATH . 'includes/database/class-api-queue-table.php';
		require_once SURECONTACT_PLUGIN_PATH . 'includes/database/class-integrations-table.php';
		require_once SURECONTACT_PLUGIN_PATH . 'includes/database/class-abandoned-carts-table.php';

		SureContact\Database\API_Queue_Table::create_table();
		SureContact\Database\Integrations_Table::create_table();
		SureContact\Database\Abandoned_Carts_Table::create_table();

		flush_rewrite_rules();
	}

	/**
	 * Maybe create or update tables and run migrations.
	 *
	 * Runs on plugins_loaded to ensure tables exist and are up to date.
	 * Each table class handles its own version checking and update logic.
	 * Handles edge cases where activation hook didn't run (multisite, manual install, etc.).
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function maybe_create_tables() {
		require_once SURECONTACT_PLUGIN_PATH . 'includes/database/class-api-queue-table.php';
		require_once SURECONTACT_PLUGIN_PATH . 'includes/database/class-integrations-table.php';
		require_once SURECONTACT_PLUGIN_PATH . 'includes/database/class-abandoned-carts-table.php';
		require_once SURECONTACT_PLUGIN_PATH . 'includes/class-migration-manager.php';

		SureContact\Database\API_Queue_Table::maybe_create_or_update();
		SureContact\Database\Integrations_Table::maybe_create_or_update();
		SureContact\Database\Abandoned_Carts_Table::maybe_create_or_update();

		// Run data migrations after tables are ready.
		SureContact\Migration_Manager::maybe_run_migrations();
	}

	/**
	 * Plugin deactivation handler
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function deactivate() {
		flush_rewrite_rules();

		// Clear SureMembers expiration schedule from both wp-cron (legacy) and
		// Action Scheduler. The scheduler was migrated to AS in 1.4.2 but a
		// stale wp-cron entry may still exist on installs that upgraded.
		$timestamp = wp_next_scheduled( 'surecontact_suremembers_check_expirations' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'surecontact_suremembers_check_expirations' );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'surecontact_suremembers_check_expirations', array(), 'surecontact' );
		}

		// Clear daily sync cron schedule.
		$daily_sync_timestamp = wp_next_scheduled( SureContact\Daily_Sync_Manager::CRON_HOOK );
		if ( $daily_sync_timestamp ) {
			wp_unschedule_event( $daily_sync_timestamp, SureContact\Daily_Sync_Manager::CRON_HOOK );
		}

		// Unschedule abandoned cart detection.
		SureContact\Abandoned_Cart_Manager::unschedule();
	}

	/**
	 * Add action links to plugin list
	 *
	 * @since 0.0.1
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function add_action_links( $links ) {
		$workspace_uuid = get_option( 'surecontact_workspace_uuid', '' );
		$is_connected   = ! empty( $workspace_uuid );

		$link_text = $is_connected
			? __( 'Access Dashboard', 'surecontact' )
			: __( 'Get Started Now', 'surecontact' );

		$dashboard_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=surecontact-dashboard' ) ),
			esc_html( $link_text )
		);

		array_unshift( $links, $dashboard_link );

		return $links;
	}

	/**
	 * Add meta links to the plugin row (under description).
	 *
	 * @since 0.0.3
	 *
	 * @param array<int,string> $links Array of plugin meta links.
	 * @param string            $file Plugin file path.
	 * @return array<int,string> Modified plugin meta links.
	 */
	public function add_meta_links( array $links, string $file ): array {
		if ( SURECONTACT_PLUGIN_BASENAME === $file ) {
			$stars = '';
			for ( $indx = 0; $indx < 5; $indx++ ) {
				$stars .= '<span class="dashicons dashicons-star-filled" style="color: #ffb900; font-size: 16px; width: 16px; height: 16px; line-height: 1.2;" aria-hidden="true"></span>';
			}
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer" aria-label="%s" role="button">%s</a>',
				esc_url( 'https://wordpress.org/support/plugin/surecontact/reviews/#new-post' ),
				esc_attr__( 'Rate our plugin', 'surecontact' ),
				$stars
			);
		}
		return $links;
	}

	/**
	 * Get plugin directory path
	 *
	 * @since 0.0.1
	 *
	 * @return string Plugin directory path.
	 */
	public function get_plugin_path() {
		return $this->plugin_path;
	}

	/**
	 * Get plugin directory URL
	 *
	 * @since 0.0.1
	 *
	 * @return string Plugin directory URL.
	 */
	public function get_plugin_url() {
		return $this->plugin_url;
	}

	/**
	 * Get the Abandoned Cart Manager instance.
	 *
	 * @since 1.5.0
	 *
	 * @return SureContact\Abandoned_Cart_Manager|null Manager instance, or null if not yet initialized.
	 */
	public function get_abandoned_cart_manager() {
		return $this->abandoned_cart_manager;
	}

	/**
	 * Load Action Scheduler library
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function load_action_scheduler() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		$action_scheduler_path = SURECONTACT_PLUGIN_PATH . 'lib/action-scheduler/action-scheduler.php';

		if ( file_exists( $action_scheduler_path ) ) {
			require_once $action_scheduler_path;
		} else {
			// Autoloader not loaded yet, use direct error_log.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				'[' . gmdate( 'Y-m-d H:i:s' ) . '] SureContact [ERROR] Plugin: Action Scheduler library not found at ' . $action_scheduler_path
			);
		}
	}
}

// Initialize plugin.
$GLOBALS['surecontact'] = SureContact::get_instance();
