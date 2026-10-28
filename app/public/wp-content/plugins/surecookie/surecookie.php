<?php
/**
 * Plugin Name: SureCookie
 * Plugin URI: https://surecookie.com
 * Description: Real cookie consent for WordPress. Browser-based scanning, smart categorization, strict script blocking, and consent logs stored in your database.
 * Author: SureCookie
 * Author URI: https://surecookie.com/
 * Version: 1.7.0
 * License: GPL-2.0-or-later
 * Text Domain: surecookie
 * Domain Path: /languages
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Tested up to: 7.1
 *
 * @package surecookie
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Define the plugin constants.
 */
define( 'SURECOOKIE_FILE', __FILE__ );
define( 'SURECOOKIE_BASE', plugin_basename( SURECOOKIE_FILE ) );
define( 'SURECOOKIE_DIR', plugin_dir_path( SURECOOKIE_FILE ) );
define( 'SURECOOKIE_URL', plugins_url( '/', SURECOOKIE_FILE ) );
define( 'SURECOOKIE_VERSION', '1.7.0' );

/**
 * Define the necessary core constants.
 */
define( 'SURECOOKIE_PREFIX', 'surecookie' );
define( 'SURECOOKIE_CONSENT_LOG_DB', 'surecookie_consent_log' );
define( 'SURECOOKIE_SETTINGS_OPTION', 'surecookie_settings' );
define( 'SURECOOKIE_ONBOARDING_OPTION', 'surecookie_onboarding_user_details' );
define( 'SURECOOKIE_ONBOARDING_COMPLETED_OPTION', 'surecookie_onboarding_completed' );
define( 'SURECOOKIE_SCANNED_DETAILS_OPTION', 'surecookie_scanned_details' );
define( 'SURECOOKIE_SCANNED_COOKIES_OPTION', 'surecookie_scanned_cookies' );
define( 'SURECOOKIE_SCANNED_LOGS_OPTION', 'surecookie_scanned_logs' );
define( 'SURECOOKIE_SCANNED_RESOURCES_OPTION', 'surecookie_scanned_resources' );
define( 'SURECOOKIE_COOKIE_CATEGORY_MEMORY_OPTION', 'surecookie_cookie_category_memory' );
define( 'SURECOOKIE_INSTALLED_SERVICES_OPTION', 'surecookie_installed_services' );
define( 'SURECOOKIE_SITE_CREDENTIALS_OPTION', 'surecookie_site_credentials' );
define( 'SURECOOKIE_BILLING_PORTAL', 'https://my.surecookie.com/' );
define( 'SURECOOKIE_WEBSITE', 'https://surecookie.com/' );

/**
 * SureCookie's own plugin capability.
 */
if ( ! defined( 'SURECOOKIE_CAPABILITY' ) ) {
	define( 'SURECOOKIE_CAPABILITY', 'manage_options' );
}

/**
 * Pro nudges option key.
 */
define( 'SURECOOKIE_NUDGES', 'surecookie_nudges' );

/**
 * DB schema version. Increment whenever get_columns_definition() changes.
 * The loader compares this against the stored option and re-runs dbDelta
 * automatically so column additions take effect without a plugin reactivation.
 */
define( 'SURECOOKIE_DB_VERSION', '1.2.4' );

/**
 * Load the plugin.
 */
require_once 'loader.php';
