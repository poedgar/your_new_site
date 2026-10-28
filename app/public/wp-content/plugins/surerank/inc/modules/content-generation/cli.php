<?php
/**
 * SureRank Pro CLI
 *
 * Handles CLI commands for content generation and other features.
 *
 * @package SureRank\Inc\Modules\Content_Generation
 * @since 1.10.0
 */

namespace SureRank\Inc\Modules\Content_Generation;

use SureRank\Inc\Traits\Get_Instance;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WP-Cli commands to manage SureRank Pro CLI features.
 *
 * @since 1.10.0
 */
class Cli {


	use Get_Instance;

	/**
	 * Generate titles and descriptions for specified IDs.
	 *
	 * ## OPTIONS
	 *
	 * --ids=<ids>
	 * : Comma-separated list of post/term IDs to process.
	 *
	 * --type=<type>
	 * : Content type to generate. Options: page_title, page_description
	 *
	 * [--is_taxonomy]
	 * : Whether the IDs are for taxonomy terms instead of posts.
	 *
	 * [--force]
	 * : Process immediately instead of using background processing.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate page titles for posts with IDs 1,2,5,8
	 *     $ wp surerank generate_content --type=page_title --ids=1,2,5,8
	 *
	 *     # Generate page descriptions for taxonomy terms with IDs 1,2,5,10,12
	 *     $ wp surerank generate_content --type=page_description --ids=1,2,5,10,12 --is_taxonomy
	 *
	 *     # Process immediately without background processing
	 *     $ wp surerank generate_content --type=page_title --ids=1,2,5,8 --force
	 *
	 * @since 1.10.0
	 * @param array<string,string> $args Positional arguments.
	 * @param array<string,string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( $args = [], $assoc_args = [] ) {
		if ( class_exists( 'WP_CLI' ) ) {
			$this->handle_generate_content( $args, $assoc_args );
		}
	}

	/**
	 * Generate titles and descriptions for specified IDs.
	 *
	 * @param array<string,string> $args Positional arguments.
	 * @param array<string,string> $assoc_args Associative arguments.
	 * @return void
	 * @since 1.10.0
	 */
	public function generate_content( $args = [], $assoc_args = [] ) {
		if ( class_exists( 'WP_CLI' ) ) {
			$this->handle_generate_content( $args, $assoc_args );
		}
	}

	/**
	 * Handle content generation CLI command
	 *
	 * @param array<string>        $args Positional arguments.
	 * @param array<string,string> $assoc_args CLI arguments.
	 * @return void
	 * @since 1.10.0
	 */
	public function handle_generate_content( $args, $assoc_args ) {
		$ids_string  = $assoc_args['ids'] ?? '';
		$type        = $assoc_args['type'] ?? '';
		$is_taxonomy = isset( $assoc_args['is_taxonomy'] );
		$force       = isset( $assoc_args['force'] );

		if ( empty( $ids_string ) ) {
			WP_CLI::error( __( 'Please specify --ids parameter with comma-separated IDs.', 'surerank' ) );
		}

		if ( empty( $type ) || ! in_array( $type, [ 'page_title', 'page_description' ], true ) ) {
			WP_CLI::error( __( 'Please specify --type parameter with either "page_title" or "page_description".', 'surerank' ) );
		}

		$utils = Utils::get_instance();

		if ( $force ) {
			$this->process_content_immediately( $ids_string, $type, $is_taxonomy, $utils );
		} else {
			$batch_processor = BatchProcess::get_instance();
			$ids             = $utils->parse_ids( $ids_string );
			
			$result = $batch_processor->queue_content_generation( $ids, $type, $is_taxonomy );
			
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			
			/* translators: %1$d: number of items, %2$s: content type */
			WP_CLI::line( sprintf( __( 'Queued %1$d items for %2$s generation.', 'surerank' ), count( $ids ), $type ) );

			// Start the batch processing.
			$batch_processor->dispatch();
			
			WP_CLI::line( __( 'Content generation batch process started.', 'surerank' ) );
			WP_CLI::line( __( 'Processing will begin in 15 seconds.', 'surerank' ) );
			WP_CLI::line( __( 'Use "wp surerank content_status" to track progress.', 'surerank' ) );
		}
	}

	/**
	 * Process content generation immediately without background processing
	 *
	 * @param string $ids_string IDs string.
	 * @param string $type Content type to generate.
	 * @param bool   $is_taxonomy Whether processing taxonomy terms.
	 * @param Utils  $utils Content generation utils.
	 * @return void
	 * @since 1.10.0
	 */
	private function process_content_immediately( $ids_string, $type, $is_taxonomy, $utils ) {
		$ids = $utils->parse_ids( $ids_string );
		/* translators: %1$d: number of items, %2$s: content type */
		WP_CLI::line( sprintf( __( 'Processing %1$d items for %2$s generation...', 'surerank' ), count( $ids ), $type ) );

		$this->process_ids_for_content_type( $ids, $type, $is_taxonomy, $utils );

		WP_CLI::line( __( 'Content generation completed.', 'surerank' ) );
	}

	/**
	 * Process IDs for a specific content type
	 *
	 * @param array<int> $ids Array of IDs to process.
	 * @param string     $content_type Content type to generate.
	 * @param bool       $is_taxonomy Whether processing taxonomy terms.
	 * @param Utils      $utils Content generation utils.
	 * @return void
	 * @since 1.10.0
	 */
	private function process_ids_for_content_type( $ids, $content_type, $is_taxonomy, $utils ) {
		$chunks       = array_chunk( $ids, 5 );
		$total_chunks = count( $chunks );
		$entity_type  = $is_taxonomy ? 'term' : 'post';

		foreach ( $chunks as $chunk_index => $chunk ) {
			/* translators: %1$d: current chunk number, %2$d: total chunks, %3$d: items in chunk */
			WP_CLI::line( sprintf( __( 'Processing chunk %1$d of %2$d (%3$d items)...', 'surerank' ), $chunk_index + 1, $total_chunks, count( $chunk ) ) );

			foreach ( $chunk as $id ) {
				try {

					if ( ! $utils->validate_id_exists( $id, $is_taxonomy ) ) {
						/* translators: %1$s: term or post, %2$d: ID number */
						WP_CLI::line( sprintf( __( '⚠ Skipping non-existent %1$s %2$d', 'surerank' ), $entity_type, $id ) );
						continue;
					}

					$generated_content = $utils->generate_content_for_id( $id, $content_type, $is_taxonomy );
					if ( is_wp_error( $generated_content ) ) {
						/* translators: %1$s: term or post, %2$d: ID number, %3$s: error message */
						WP_CLI::line( sprintf( __( '✗ Error generating content for %1$s %2$d: %3$s', 'surerank' ), $entity_type, $id, $generated_content->get_error_message() ) );
						continue;
					}
					if ( ! empty( $generated_content ) ) {
						$result = $utils->apply_generated_content( $id, $content_type, $generated_content, $is_taxonomy );
						if ( is_wp_error( $result ) ) {
							/* translators: %1$s: term or post, %2$d: ID number, %3$s: error message */
							WP_CLI::line( sprintf( __( '✗ Failed to apply content for %1$s %2$d: %3$s', 'surerank' ), $entity_type, $id, $result->get_error_message() ) );
						} else {
							/* translators: %1$s: term or post, %2$d: ID number, %3$s: content type, %4$s: generated content preview */
							WP_CLI::line( sprintf( __( '✓ Successfully processed %1$s %2$d for %3$s: "%4$s"', 'surerank' ), $entity_type, $id, $content_type, substr( $generated_content, 0, 50 ) . '...' ) );
						}
					} else {
						/* translators: %1$s: term or post, %2$d: ID number, %3$s: content type */
						WP_CLI::line( sprintf( __( '✗ Failed to generate content for %1$s %2$d (%3$s)', 'surerank' ), $entity_type, $id, $content_type ) );
					}
				} catch ( \Exception $e ) {
					/* translators: %1$s: term or post, %2$d: ID number, %3$s: error message */
					WP_CLI::line( sprintf( __( '✗ Error processing %1$s %2$d: %3$s', 'surerank' ), $entity_type, $id, $e->getMessage() ) );
				}
			}
		}
	}

	/**
	 * Check the status of the current batch process.
	 *
	 * ## EXAMPLES
	 *
	 *     wp surerank content_status
	 *
	 * @param array<string> $args Command arguments.
	 * @param array<string> $assoc_args Associated arguments.
	 * @since 1.10.0
	 * @return void
	 */
	public function status( $args, $assoc_args ): void {
		$status_manager = Batch_Status_Manager::get_instance();
		$batch_status   = $status_manager->get_batch_status();
		
		if ( ! $batch_status['found'] ) {
			WP_CLI::error( __( 'No batch process found.', 'surerank' ) );
		}

		// Display batch information.
		WP_CLI::line( WP_CLI::colorize( '%GBatch Process Status:%n' ) );
		WP_CLI::line( sprintf( 'Status: %s', $this->colorize_status( $batch_status['status'] ) ) );
		WP_CLI::line( sprintf( 'Type: %s', $batch_status['type'] ) );
		WP_CLI::line( sprintf( 'Is Taxonomy: %s', $batch_status['is_taxonomy'] ? 'Yes' : 'No' ) );
		WP_CLI::line( sprintf( 'Progress: %s%% (%d/%d)', $batch_status['progress_percentage'], $batch_status['processed_count'] + $batch_status['failed_count'], $batch_status['total_count'] ) );
		
		if ( $batch_status['processed_count'] > 0 ) {
			WP_CLI::line( WP_CLI::colorize( sprintf( '%%GProcessed: %d IDs%%n', $batch_status['processed_count'] ) ) );
		}
		
		if ( $batch_status['failed_count'] > 0 ) {
			WP_CLI::line( WP_CLI::colorize( sprintf( '%%RFailed: %d IDs%%n', $batch_status['failed_count'] ) ) );
			if ( ! empty( $batch_status['failed_ids'] ) ) {
				WP_CLI::line( 'Failed IDs:' );
				foreach ( $batch_status['failed_ids'] as $failed_id => $error_message ) {
					WP_CLI::line( sprintf( '  - ID %d: %s', $failed_id, $error_message ) );
				}
			}
		}
		
		if ( ! empty( $batch_status['pending_ids'] ) ) {
			WP_CLI::line( sprintf( 'Pending: %d IDs', count( $batch_status['pending_ids'] ) ) );
		}
		
		if ( $batch_status['started_at'] ) {
			WP_CLI::line( sprintf( 'Started: %s', gmdate( 'Y-m-d H:i:s', $batch_status['started_at'] ) ) );
		}
		
		if ( $batch_status['completed_at'] ) {
			WP_CLI::line( sprintf( 'Completed: %s', gmdate( 'Y-m-d H:i:s', $batch_status['completed_at'] ) ) );
		}
	}

	/**
	 * Clear current batch process data.
	 *
	 * ## EXAMPLES
	 *
	 *     wp surerank content_clear
	 *
	 * @param array<string> $args Command arguments.
	 * @param array<string> $assoc_args Associated arguments.
	 * @since 1.10.0
	 */
	public function clear( $args, $assoc_args ): void {
		// Clear the queue too, not just the status option. Leaving queued chunks behind
		// keeps queue_content_generation() blocked on its "queue is not empty" guard.
		Clean::get_instance()->clear_content_generation_queue();

		WP_CLI::success( __( 'Batch data and queue cleared successfully.', 'surerank' ) );
	}

	/**
	 * Force process the content generation queue.
	 *
	 * ## EXAMPLES
	 *
	 *     wp surerank content_process
	 *
	 * @param array<string> $args Command arguments.
	 * @param array<string> $assoc_args Associated arguments.
	 * @since 1.10.0
	 */
	public function process_queue( $args, $assoc_args ): void {
		$batch_processor = BatchProcess::get_instance();
		
		WP_CLI::line( __( 'Forcing content generation queue processing...', 'surerank' ) );
		$batch_processor->force_process_queue();
		WP_CLI::success( __( 'Queue processing completed.', 'surerank' ) );
	}


	/**
	 * Colorize status text for CLI output.
	 *
	 * @param string $status Status string.
	 * @return string Colorized status.
	 */
	private function colorize_status( $status ) {
		switch ( $status ) {
			case Batch_Status_Manager::STATUS_SCHEDULED:
				return WP_CLI::colorize( '%Y' . $status . '%n' );
			case Batch_Status_Manager::STATUS_RUNNING:
				return WP_CLI::colorize( '%B' . $status . '%n' );
			case Batch_Status_Manager::STATUS_EXECUTED:
				return WP_CLI::colorize( '%G' . $status . '%n' );
			case Batch_Status_Manager::STATUS_FAILED:
				return WP_CLI::colorize( '%R' . $status . '%n' );
			default:
				return $status;
		}
	}
}

/**
 * Add Command
 */
if ( defined( 'WP_CLI' ) && class_exists( 'WP_CLI' ) ) {
	$cli_instance = Cli::get_instance();
	
	WP_CLI::add_command( 'surerank generate_content', $cli_instance );
	
	WP_CLI::add_command( 'surerank content_status', [ $cli_instance, 'status' ] );
	WP_CLI::add_command( 'surerank content_clear', [ $cli_instance, 'clear' ] );
	WP_CLI::add_command( 'surerank content_process', [ $cli_instance, 'process_queue' ] );
}
