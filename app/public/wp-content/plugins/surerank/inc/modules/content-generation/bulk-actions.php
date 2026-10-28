<?php
/**
 * Content Generation Bulk Actions
 *
 * Handles bulk content generation actions for posts and taxonomies.
 *
 * @package SureRank\Inc\Modules\Content_Generation
 * @since 1.10.0
 */

namespace SureRank\Inc\Modules\Content_Generation;

use SureRank\Inc\Admin\BulkActions as Base_BulkActions;
use SureRank\Inc\Admin\Helper;
use SureRank\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Bulk Actions class
 *
 * Handles content generation bulk actions integration.
 */
class BulkActions {

	use Get_Instance;

	/**
	 * Constructor
	 */
	public function __construct() {
		add_filter( 'surerank_bulk_actions', [ $this, 'add_content_generation_actions' ] );
		add_filter( 'surerank_allowed_bulk_actions', [ $this, 'add_allowed_actions' ] );
		add_action( 'surerank_bulk_action_performed', [ $this, 'handle_content_generation_action' ], 10, 2 );
		add_action( 'admin_notices', [ $this, 'show_batch_processing_notice' ] );
	}

	/**
	 * Add content generation bulk actions to the dropdown
	 *
	 * @param array<string,string> $actions Existing bulk actions.
	 * @return array<string,string> Modified bulk actions.
	 * @since 1.10.0
	 */
	public function add_content_generation_actions( $actions ) {
		$content_actions = [
			'surerank_generate_page_title'       => __( 'Generate Page Title', 'surerank' ),
			'surerank_generate_page_description' => __( 'Generate Page Description', 'surerank' ),
		];

		return array_merge( $actions, $content_actions );
	}

	/**
	 * Add content generation actions to allowed actions list
	 *
	 * @param array<string> $actions Existing allowed actions.
	 * @return array<string> Modified allowed actions.
	 * @since 1.10.0
	 */
	public function add_allowed_actions( $actions ) {
		$content_actions = [
			'surerank_generate_page_title',
			'surerank_generate_page_description',
		];

		return array_merge( $actions, $content_actions );
	}

	/**
	 * Handle content generation bulk actions
	 *
	 * @param string     $action The bulk action being performed.
	 * @param array<int> $ids Array of post/term IDs.
	 * @return void
	 * @since 1.10.0
	 */
	public function handle_content_generation_action( $action, $ids ) {
		if ( ! Utils::is_valid_content_generation_action( $action ) ) {
			return;
		}

		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return;
		}

		// Determine if we're working with taxonomies or posts.
		$is_taxonomy = Helper::is_taxonomy_screen();
		
		// Map the bulk action to content type.
		$content_type = $this->get_content_type_from_action( $action );
		
		if ( empty( $content_type ) ) {
			return;
		}

		// Convert IDs to integers and filter out invalid ones.
		$valid_ids = array_filter(
			array_map( 'intval', $ids ),
			function( $id ) {
				return $id > 0;
			} 
		);

		if ( empty( $valid_ids ) ) {
			return;
		}

		// Use batch processing for bulk actions.
		$batch_processor = BatchProcess::get_instance();
		$result          = $batch_processor->queue_content_generation( $valid_ids, $content_type, $is_taxonomy );
		
		if ( is_wp_error( $result ) ) {
			// Store error to be picked up by bulk action redirect filter.
			add_filter(
				'surerank_bulk_action_redirect_url',
				function( $redirect_url, $filter_action, $count ) use ( $action, $result, $is_taxonomy ) {
					if ( $filter_action === $action ) {
						$query_args = [
							'surerank_bulk_action' => $action,
							'surerank_error'       => rawurlencode( $result->get_error_message() ),
							'surerank_nonce'       => wp_create_nonce( 'surerank_bulk_action_nonce' ),
						];

						// Preserve taxonomy parameter for taxonomy screens.
						if ( $is_taxonomy && isset( $_REQUEST['taxonomy'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
							$query_args['taxonomy'] = sanitize_text_field( wp_unslash( $_REQUEST['taxonomy'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						}

						return add_query_arg(
							$query_args,
							remove_query_arg( [ 'surerank_updated' ], $redirect_url )
						);
					}
					return $redirect_url;
				},
				10,
				3 
			);
			return;
		}
		
		$batch_processor->dispatch();
	}


	/**
	 * Show custom notice for batch processing
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function show_batch_processing_notice() {
		if (
			! isset( $_GET['surerank_bulk_action'], $_GET['surerank_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['surerank_nonce'] ) ), 'surerank_bulk_action_nonce' )
		) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_GET['surerank_bulk_action'] ) );

		// Only handle content generation actions.
		if ( ! Utils::is_valid_content_generation_action( $action ) ) {
			return;
		}

		// Check for error message first.
		if ( isset( $_GET['surerank_error'] ) ) {
			$error_message = sanitize_text_field( wp_unslash( $_GET['surerank_error'] ) );
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( rawurldecode( $error_message ) )
			);
			
			// Clear the URL parameters after displaying the error notice.
			$this->clear_url_parameters();
			return;
		}

		// Check for success message.
		$updated_count = isset( $_GET['surerank_updated'] ) ? absint( $_GET['surerank_updated'] ) : 0;
		if ( ! $updated_count ) {
			return;
		}

		$action_names = [
			'surerank_generate_page_title'       => __( 'page title generation', 'surerank' ),
			'surerank_generate_page_description' => __( 'page description generation', 'surerank' ),
		];

		$action_name = $action_names[ $action ] ?? __( 'content generation', 'surerank' );

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			sprintf(
				/* translators: %1$d: number of items, %2$s: action type */
				esc_html(
					/* translators: %1$d: number of items, %2$s: action type */
					_n( 
						'%1$d item has been scheduled for %2$s. Processing will begin shortly in the background.',
						'%1$d items have been scheduled for %2$s. Processing will begin shortly in the background.',
						$updated_count, 
						'surerank' 
					) 
				),
				esc_html( (string) $updated_count ),
				esc_html( $action_name )
			)
		);

		// Clear the URL parameters after displaying the success notice.
		$this->clear_url_parameters();
	}

	/**
	 * Clear URL parameters using the main SureRank bulk actions method
	 *
	 * @return void
	 * @since 1.10.0
	 */
	private function clear_url_parameters() {

		Base_BulkActions::get_instance()->clear_bulk_action_url_parameters();
	}

	/**
	 * Get content type from bulk action
	 *
	 * @param string $action The bulk action.
	 * @return string Content type or empty string.
	 * @since 1.10.0
	 */
	private function get_content_type_from_action( $action ) {
		$action_map = [
			'surerank_generate_page_title'       => 'page_title',
			'surerank_generate_page_description' => 'page_description',
		];

		return $action_map[ $action ] ?? '';
	}
}
