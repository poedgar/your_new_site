<?php
/**
 * Content Generation Background Process
 *
 * @package SureRank\Inc\Modules\Content_Generation
 * @since 1.10.0
 */

namespace SureRank\Inc\Modules\Content_Generation;

use SureRank\Inc\Lib\Background_Process\Wp_Background_Process;
use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Traits\Logger;
use Throwable;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Content Generation Background Process
 *
 * @since 1.10.0
 */
class BatchProcess extends Wp_Background_Process {

	use Get_Instance;
	use Logger;

	/**
	 * Content Generation Process
	 *
	 * @var string
	 */
	protected $action = 'content_generation_process';

	/**
	 * Cron interval in minutes
	 *
	 * @var int
	 */
	protected $cron_interval = 1;


	/**
	 * Handle critical error and cleanup
	 *
	 * @param Throwable $e The throwable exception.
	 * @param string    $context Error context for logging.
	 * @return void
	 * @since 1.10.0
	 */
	private function handle_critical_error( Throwable $e, $context = 'processing' ) {
		self::log( sprintf( 'Critical error in %s: %s', $context, $e->getMessage() ), 'error' );
		
		$status_manager = Batch_Status_Manager::get_instance();
		$status_manager->mark_all_pending_as_failed( sprintf( '%s error: %s', ucfirst( $context ), $e->getMessage() ), 'batch_critical_error' );
		
		$this->clear_scheduled_cron();
		$this->cancel_process();
	}

	/**
	 * Queue content generation for multiple IDs with chunking
	 *
	 * @param array<int> $ids Array of post/term IDs.
	 * @param string     $content_type Type of content (page_title, page_description).
	 * @param bool       $is_taxonomy Whether the IDs are for taxonomy terms.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 * @since 1.10.0
	 */
	public function queue_content_generation( $ids, $content_type, $is_taxonomy = false ) {
		$status_manager = Batch_Status_Manager::get_instance();

		// Check if there's already a batch process running or scheduled.
		$existing_batch = $status_manager->get_batch_data();
		if ( $existing_batch && ! $status_manager->is_batch_complete() ) {
			return new WP_Error(
				'batch_already_running',
				__( 'A batch process is currently running or scheduled. Please wait for it to complete before initiating a new process', 'surerank' )
			);
		}

		// Check if there are already queued items.
		if ( ! $this->is_queue_empty() ) {
			return new WP_Error(
				'queue_not_empty',
				__( 'Content generation queue is not empty. Please wait for current processing to complete or clear the queue.', 'surerank' )
			);
		}

		$status_manager->create_batch( $ids, $content_type, $is_taxonomy );

		$chunks = array_chunk( $ids, 5 );

		/* translators: %1$d: number of chunks, %2$s: content type */
		self::log( sprintf( 'Queuing %d chunks for content type: %s', count( $chunks ), $content_type ), 'info' );

		// Cron runs with no logged-in user, but applying the generated content goes through
		// the per-object capability guard in Fix_Seo_Checks\Page::use_me(). Remember who
		// started the batch so the guard can be evaluated against them at run time.
		$user_id = get_current_user_id();

		foreach ( $chunks as $chunk ) {
			$this->push_to_queue(
				[
					'ids'          => $chunk,
					'content_type' => $content_type,
					'is_taxonomy'  => $is_taxonomy,
					'user_id'      => $user_id,
				]
			);
		}
		// Explicitly save the queue to database.
		$this->save();
		
		/* translators: %d: number of chunks added to queue */
		self::log( sprintf( 'Added %d chunks to content generation queue', count( $chunks ) ), 'info' );

		return true;
	}

	/**
	 * Task
	 *
	 * Process each chunk of IDs
	 *
	 * @since 1.10.0
	 *
	 * @param mixed $item Queue item.
	 * @return mixed
	 */
	protected function task( $item ) {
		try {
			if ( empty( $item ) ) {
				return false;
			}

			// Convert object to array for compatibility.
			if ( is_object( $item ) ) {
				$item = (array) $item;
			}
			if ( $this->time_exceeded() || $this->memory_exceeded() ) {
				return $item;
			}

			$ids          = $item['ids'] ?? [];
			$content_type = $item['content_type'] ?? '';
			$is_taxonomy  = $item['is_taxonomy'] ?? false;

			if ( empty( $ids ) || empty( $content_type ) ) {
				self::log( 'Invalid batch item: missing IDs or content type', 'error' );
				return false;
			}

			$restore_user_id = $this->impersonate_batch_user( $item );

			try {
				$this->process_ids( $ids, $content_type, $is_taxonomy );
			} finally {
				// Always hand the request back its original identity, including when
				// processing dies, so nothing downstream keeps running as this user.
				wp_set_current_user( $restore_user_id );
			}

			return false;

		} catch ( Throwable $e ) {
			$this->handle_critical_error( $e, 'task processing' );
			return false;
		}
	}

	/**
	 * Generate and apply content for one chunk of IDs.
	 *
	 * @param array<int> $ids IDs to process.
	 * @param string     $content_type Type of content to generate.
	 * @param bool       $is_taxonomy Whether the IDs are taxonomy terms.
	 * @return void
	 * @since 1.10.0
	 */
	private function process_ids( array $ids, $content_type, $is_taxonomy ) {
		$utils          = Utils::get_instance();
		$status_manager = Batch_Status_Manager::get_instance();

		$status_manager->update_batch_status( Batch_Status_Manager::STATUS_RUNNING );

		/* translators: %1$d: number of IDs, %2$s: content type */
		self::log( sprintf( 'Processing %d IDs for %s', count( $ids ), $content_type ), 'info' );

		foreach ( $ids as $id ) {
			try {
				// Validate the ID exists before processing.
				if ( ! $utils->validate_id_exists( $id, $is_taxonomy ) ) {
					/* translators: %1$s: term or post, %2$d: ID number */
					self::log( sprintf( 'Skipping non-existent %s %d', $is_taxonomy ? 'term' : 'post', $id ), 'warning' );

					$status_manager->mark_id_failed( $id, __( 'ID does not exist.', 'surerank' ), 'id_not_found' );
					continue;
				}

				$generated_content = $utils->generate_content_for_id( $id, $content_type, $is_taxonomy );
				if ( is_wp_error( $generated_content ) ) {
					/* translators: %1$s: term or post, %2$d: ID number, %3$s: error message */
					self::log( sprintf( 'Error generating content for %s %d: %s', $is_taxonomy ? 'term' : 'post', $id, $generated_content->get_error_message() ), 'error' );
						
					$status_manager->mark_id_failed( $id, $generated_content->get_error_message(), (string) $generated_content->get_error_code() );
					continue;
				}
				if ( ! empty( $generated_content ) ) {
					$result = $utils->apply_generated_content( $id, $content_type, $generated_content, $is_taxonomy );
					if ( is_wp_error( $result ) ) {
						/* translators: %1$s: term or post, %2$d: ID number, %3$s: error message */
						self::log( sprintf( 'Failed to apply content for %s %d: %s', $is_taxonomy ? 'term' : 'post', $id, $result->get_error_message() ), 'error' );

						$status_manager->mark_id_failed( $id, $result->get_error_message(), (string) $result->get_error_code() );
					} else {
						/* translators: %1$s: term or post, %2$d: ID number, %3$s: content type */
						self::log( sprintf( 'Successfully processed %s %d for %s', $is_taxonomy ? 'term' : 'post', $id, $content_type ), 'info' );

						$status_manager->mark_id_processed( $id );
					}
				} else {
					/* translators: %1$s: term or post, %2$d: ID number, %3$s: content type */
					self::log( sprintf( 'Failed to generate content for %s %d (%s)', $is_taxonomy ? 'term' : 'post', $id, $content_type ), 'warning' );

					$status_manager->mark_id_failed( $id, __( 'Failed to generate content.', 'surerank' ), 'generation_failed' );
				}
			} catch ( \Exception $e ) {
				/* translators: %1$s: term or post, %2$d: ID number, %3$s: error message */
				self::log( sprintf( 'Error processing %s %d: %s', $is_taxonomy ? 'term' : 'post', $id, $e->getMessage() ), 'error' );

				$status_manager->mark_id_failed( $id, $e->getMessage(), 'generation_exception' );
			}
		}
	}

	/**
	 * Switch to the user who queued the batch for the duration of this chunk.
	 *
	 * @param array<string,mixed> $item Queue item.
	 * @return int User ID to restore once the chunk is done.
	 * @since 1.10.0
	 */
	private function impersonate_batch_user( array $item ) {
		$current_user_id = get_current_user_id();
		$batch_user_id   = isset( $item['user_id'] ) ? (int) $item['user_id'] : 0;

		if ( $batch_user_id > 0 && $batch_user_id !== $current_user_id ) {
			wp_set_current_user( $batch_user_id );
		}

		return $current_user_id;
	}

	/**
	 * Get time limit for processing
	 * Override to use content generation specific filter
	 *
	 * @return int
	 */
	protected function get_time_limit() {
		$time_limit         = 30;
		$max_execution_time = ini_get( 'max_execution_time' );

		if ( $max_execution_time > 0 && $max_execution_time < $time_limit ) {
			$time_limit = $max_execution_time;
		}

		return apply_filters( 'surerank_content_generation_time_limit', $time_limit );
	}

	/**
	 * Override dispatch to force cron-based processing only
	 *
	 * @return array<string, mixed>|\WP_Error|false
	 * @since 1.10.0
	 */
	public function dispatch() {
		try {
			// Only schedule the cron event, don't process immediately.
			if ( ! $this->is_queue_empty() ) {
				$this->schedule_event_delayed( 10 );
				self::log( 'Scheduling content generation cron event for background processing in 10 seconds', 'info' );
			} else {
				self::log( 'Queue is empty, no cron event needed', 'info' );
			}

			return false;
		} catch ( Throwable $e ) {
			$this->handle_critical_error( $e, 'dispatch' );
			return false;
		}
	}

	/**
	 * Schedule cron event with delay
	 *
	 * @param int $delay_seconds Delay in seconds before running the cron.
	 * @return void
	 * @since 1.10.0
	 */
	protected function schedule_event_delayed( $delay_seconds = 10 ) {
		if ( wp_next_scheduled( $this->cron_hook_identifier ) ) {
			return;
		}

		wp_schedule_single_event( time() + $delay_seconds, $this->cron_hook_identifier );
	}

	/**
	 * Complete
	 *
	 * @since 1.10.0
	 * @return void
	 */
	protected function complete(): void {
		try {
			parent::complete();

			$status_manager = Batch_Status_Manager::get_instance();
			$status_manager->update_batch_status( Batch_Status_Manager::STATUS_EXECUTED );

			do_action( 'surerank_content_generation_batch_complete' );
			self::log( 'Content generation batch process completed', 'info' );
		} catch ( Throwable $e ) {
			$this->handle_critical_error( $e, 'completion' );
		}
	}

	/**
	 * Check if process is currently running
	 *
	 * @return bool
	 * @since 1.10.0
	 */
	public function is_processing() {
		if ( method_exists( parent::class, 'is_processing' ) ) {
			return parent::is_processing();
		}
		return false;
	}

	/**
	 * Override handle_cron_healthcheck to avoid dispatch loop
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function handle_cron_healthcheck() {
		try {
			if ( $this->is_processing() ) {
				self::log( 'Content generation already processing, skipping', 'info' );
				exit;
			}

			if ( $this->is_queue_empty() ) {
				self::log( 'Content generation queue is empty, clearing scheduled event', 'info' );
				$this->clear_scheduled_event();
				exit;
			}

			self::log( 'Content generation cron healthcheck triggered, starting processing', 'info' );
			
			// Process the queue directly without calling dispatch().
			$this->handle();
		} catch ( Throwable $e ) {
			$this->handle_critical_error( $e, 'cron healthcheck' );
		}
	}

	/**
	 * Force process the queue (for debugging)
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function force_process_queue() {
		try {
			self::log( 'Force processing content generation queue', 'info' );
			
			if ( $this->is_queue_empty() ) {
				self::log( 'Queue is empty, nothing to process', 'info' );
				return;
			}
			
			// Process the queue manually.
			$this->handle_cron_healthcheck();
		} catch ( Throwable $e ) {
			$this->handle_critical_error( $e, 'force processing' );
		}
	}

	/**
	 * Clear scheduled cron event
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function clear_scheduled_cron() {
		$this->clear_scheduled_event();
		self::log( 'Cleared scheduled cron event', 'info' );
	}

	/**
	 * Cancel the background process
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function cancel_process() {
		
		if ( method_exists( parent::class, 'cancel_process' ) ) {
			parent::cancel_process();
		} elseif ( method_exists( $this, 'cancel' ) ) {
			$this->cancel();
		}
		self::log( 'Cancelled background process', 'info' );
	}

	/**
	 * Delete all queue items
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function delete_all() {

		if ( method_exists( parent::class, 'delete_all' ) ) {
			parent::delete_all();
		}
		self::log( 'Cleared all queue items', 'info' );
	}
}
