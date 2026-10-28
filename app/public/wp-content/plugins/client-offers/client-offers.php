<?php
/**
 * Plugin Name: Client Offers
 * Description: Send personalised email offers to potential clients in batches, with contact import and unsubscribe handling.
 * Version:     1.0.0
 * Author:      Svyatoslav Kachmar
 * Text Domain: client-offers
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'CO_VERSION', '1.0.0' );
define( 'CO_DIR', plugin_dir_path( __FILE__ ) );
define( 'CO_URL', plugin_dir_url( __FILE__ ) );

require CO_DIR . 'includes/class-co-db.php';
require CO_DIR . 'includes/class-co-mailer.php';
require CO_DIR . 'includes/class-co-admin.php';

register_activation_hook( __FILE__, array( 'CO_DB', 'install' ) );
add_action( 'plugins_loaded', array( 'CO_DB', 'maybe_upgrade' ) );
add_action( 'init', array( 'CO_Mailer', 'handle_unsubscribe' ) );

if ( is_admin() ) {
	new CO_Admin();
}
