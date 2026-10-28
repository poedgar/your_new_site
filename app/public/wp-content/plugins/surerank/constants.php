<?php
/**
 * Setting constants for the plugin.
 * This file is used to define the constants used within the plugin.
 *
 * @package surerank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Setting constants for global options.
 */
define( 'SURERANK_SEO_LAST_UPDATED', '_surerank_seo_last_updated' );

/**
 * Global post meta settings.
 */
define( 'SURERANK_SETTINGS', 'surerank_settings' );

/**
 * Global post meta settings.
 */
define( 'SURERANK_SEO_CHECKS', 'surerank_seo_checks' );

/**
 * Global post meta settings.
 */
define( 'SURERANK_SEO_CHECKS_LAST_UPDATED', 'surerank_seo_checks_last_updated' );

/**
 * Global post meta settings.
 */
define( 'SURERANK_TAXONOMY_UPDATED_AT', 'surerank_taxonomy_updated_at' );

/**
 * User meta key holding the last profile-update timestamp, used to
 * invalidate cached user SEO checks.
 *
 * @since 1.8.2
 */
define( 'SURERANK_USER_UPDATED_AT', 'surerank_user_updated_at' );

/**
 * Robots.txt content option.
 */
define( 'SURERANK_ROBOTS_TXT_CONTENT', 'surerank_robots_txt_content' );

/**
 * Pro nudges option key.
 */
define( 'SURERANK_NUDGES', 'surerank_nudges' );

/**
 * Option key: when truthy, uninstall.php wipes all SureRank data on plugin deletion.
 * Stored standalone (not nested in SURERANK_SETTINGS) so uninstall.php can read it cheaply.
 */
define( 'SURERANK_DELETE_ON_UNINSTALL', 'surerank_delete_on_uninstall' );
