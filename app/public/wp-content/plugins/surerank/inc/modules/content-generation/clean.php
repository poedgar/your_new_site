<?php
/**
 * Content Generation Queue Cleaner
 *
 * Handles clearing of content generation queue and batch processes.
 *
 * @package SureRank\Inc\Modules\Content_Generation
 * @since 1.10.0
 */

namespace SureRank\Inc\Modules\Content_Generation;

use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Traits\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Clean
 *
 * Handles queue clearing functionality for content generation.
 */
class Clean {

	use Get_Instance;
	use Logger;

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'handle_queue_clear_request' ] );
	}

	/**
	 * Handle queue clear request via URL parameter
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function handle_queue_clear_request() {
		if ( ! is_admin() || ! isset( $_GET['surerank_content_generation'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( 'empty' !== sanitize_text_field( wp_unslash( $_GET['surerank_content_generation'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'surerank' ) );
		}

		check_admin_referer( 'surerank_content_generation' );

		$this->clear_content_generation_queue();


		$redirect_url = remove_query_arg( 'surerank_content_generation' );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Clear content generation queue and batch status
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function clear_content_generation_queue() {
		$batch_process  = BatchProcess::get_instance();
		$status_manager = Batch_Status_Manager::get_instance();

		self::log( 'Starting content generation queue cleanup', 'info' );

		$batch_process->cancel_process();
		self::log( 'Cancelled background process', 'info' );
		
		$batch_process->delete_all();
		self::log( 'Cleared all queue items', 'info' );

		$batch_process->clear_scheduled_cron();
		self::log( 'Cleared scheduled cron events', 'info' );

		$status_manager->clear_batch_data();
		self::log( 'Cleared batch status data', 'info' );

		
		self::log( 'Content generation queue cleanup completed', 'info' );
	}

}
