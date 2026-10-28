<?php
/**
 * Batch Status Manager
 *
 * Manages the status tracking for content generation batch processes.
 *
 * @package SureRank\Inc\Modules\Content_Generation
 * @since 1.10.0
 */

namespace SureRank\Inc\Modules\Content_Generation;

use SureRank\Inc\Functions\API_Utils;
use SureRank\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Batch_Status_Manager
 *
 * Manages batch process status tracking for content generation.
 */
class Batch_Status_Manager {

	use Get_Instance;

	/**
	 * Option name for storing batch process status.
	 */
	const OPTION_NAME = 'surerank_content_generation_batch_process';

	/**
	 * Batch statuses.
	 */
	const STATUS_SCHEDULED = 'scheduled';
	const STATUS_RUNNING   = 'running';
	const STATUS_EXECUTED  = 'executed';
	const STATUS_FAILED    = 'failed';

	/**
	 * Create a new batch process tracking record.
	 *
	 * @param array<int> $ids Array of IDs to process.
	 * @param string     $content_type Content type (page_title or page_description).
	 * @param bool       $is_taxonomy Whether processing taxonomy terms.
	 * @return bool True on success.
	 */
	public function create_batch( array $ids, $content_type, $is_taxonomy = false ) {
		$batch_data = [
			'ids'             => array_values( array_unique( array_map( 'intval', $ids ) ) ),
			'is_taxonomy'     => (bool) $is_taxonomy,
			'type'            => sanitize_text_field( $content_type ),
			'status'          => self::STATUS_SCHEDULED,
			'processed_ids'   => [],
			'failed_ids'      => [],
			'pending_ids'     => array_values( array_unique( array_map( 'intval', $ids ) ) ),
			'started_at'      => time(),
			'completed_at'    => null,
			'total_count'     => count( $ids ),
			'processed_count' => 0,
			'failed_count'    => 0,
		];

		return $this->save_batch_data( $batch_data );
	}

	/**
	 * Update batch status.
	 *
	 * @param string $status New status.
	 * @return bool True on success, false on failure.
	 */
	public function update_batch_status( $status ) {
		$batch_data = $this->get_batch_data();
		
		if ( ! $batch_data ) {
			return false;
		}

		$batch_data['status'] = $status;
		
		if ( self::STATUS_EXECUTED === $status || self::STATUS_FAILED === $status ) {
			$batch_data['completed_at'] = time();
		}

		return $this->save_batch_data( $batch_data );
	}

	/**
	 * Mark an ID as processed successfully.
	 *
	 * @param int $id ID that was processed.
	 * @return bool True on success, false on failure.
	 */
	public function mark_id_processed( $id ) {
		$batch_data = $this->get_batch_data();
		
		if ( ! $batch_data ) {
			return false;
		}

		$id = intval( $id );
		
		// Add to processed_ids if not already there.
		if ( ! in_array( $id, $batch_data['processed_ids'], true ) ) {
			$batch_data['processed_ids'][] = $id;
		}
		
		// Remove from pending_ids.
		$batch_data['pending_ids'] = array_values( array_diff( $batch_data['pending_ids'], [ $id ] ) );
		
		// Remove from failed_ids if it was there.
		if ( isset( $batch_data['failed_ids'][ $id ] ) ) {
			unset( $batch_data['failed_ids'][ $id ] );
		}
		
		// Update counts.
		$batch_data['processed_count'] = count( $batch_data['processed_ids'] );
		$batch_data['failed_count']    = count( $batch_data['failed_ids'] );

		return $this->save_batch_data( $batch_data );
	}

	/**
	 * Mark an ID as failed.
	 *
	 * @param int    $id ID that failed.
	 * @param string $error_message Error message.
	 * @param string $error_code Error code behind the failure.
	 * @return bool True on success, false on failure.
	 */
	public function mark_id_failed( $id, $error_message = '', $error_code = '' ) {
		$batch_data = $this->get_batch_data();

		if ( ! $batch_data ) {
			return false;
		}

		$id = intval( $id );

		// Add to failed_ids. The code travels with the message so consumers can branch
		// on it instead of matching translated wording.
		$batch_data['failed_ids'][ $id ] = [
			'message' => sanitize_text_field( $error_message ),
			'code'    => sanitize_key( $error_code ),
		];
		
		// Remove from pending_ids.
		$batch_data['pending_ids'] = array_values( array_diff( $batch_data['pending_ids'], [ $id ] ) );
		
		// Remove from processed_ids if it was there.
		$batch_data['processed_ids'] = array_values( array_diff( $batch_data['processed_ids'], [ $id ] ) );
		
		// Update counts.
		$batch_data['processed_count'] = count( $batch_data['processed_ids'] );
		$batch_data['failed_count']    = count( $batch_data['failed_ids'] );

		return $this->save_batch_data( $batch_data );
	}

	/**
	 * Get current batch data.
	 *
	 * @return array<string, mixed>|false Batch data or false if not found.
	 */
	public function get_batch_data() {
		return get_option( self::OPTION_NAME, false );
	}

	/**
	 * Get current batch status for polling.
	 *
	 * @return array<string, mixed> Status information.
	 */
	public function get_batch_status() {
		$batch_data = $this->get_batch_data();
		
		if ( ! $batch_data ) {
			return [
				'found'   => false,
				'message' => __( 'No batch process found.', 'surerank' ),
			];
		}

		$progress_percentage = 0;
		if ( $batch_data['total_count'] > 0 ) {
			$completed           = $batch_data['processed_count'] + $batch_data['failed_count'];
			$progress_percentage = round( ( $completed / $batch_data['total_count'] ) * 100, 2 );
		}

		return [
			'found'               => true,
			'status'              => $batch_data['status'],
			'type'                => $batch_data['type'],
			'is_taxonomy'         => $batch_data['is_taxonomy'],
			'total_count'         => $batch_data['total_count'],
			'processed_count'     => $batch_data['processed_count'],
			'failed_count'        => $batch_data['failed_count'],
			'pending_count'       => count( $batch_data['pending_ids'] ),
			'progress_percentage' => $progress_percentage,
			'started_at'          => $batch_data['started_at'],
			'completed_at'        => $batch_data['completed_at'],
			'processed_ids'       => $batch_data['processed_ids'],
			'failed_ids'          => $this->prepare_failed_ids( $batch_data['failed_ids'] ?? [] ),
			'pending_ids'         => $batch_data['pending_ids'],
		];
	}

	/**
	 * Normalise failures for the response and flag the ones an upgrade resolves.
	 *
	 * Batches queued before failures carried a code store a plain message string,
	 * so accept both shapes.
	 *
	 * @param array<int, mixed> $failed_ids Stored failures keyed by ID.
	 * @return array<int, array<string, mixed>> Failures keyed by ID.
	 * @since 1.10.0
	 */
	private function prepare_failed_ids( array $failed_ids ) {
		$prepared = [];

		foreach ( $failed_ids as $id => $failure ) {
			$message = is_array( $failure ) ? ( $failure['message'] ?? '' ) : (string) $failure;
			$code    = is_array( $failure ) ? ( $failure['code'] ?? '' ) : '';

			$prepared[ $id ] = [
				'message'          => $message,
				'code'             => $code,
				'upgrade_required' => API_Utils::is_upgrade_required_code( $code ),
			];
		}

		return $prepared;
	}

	/**
	 * Clear/delete current batch data.
	 *
	 * @return bool True on success.
	 */
	public function clear_batch_data() {
		return delete_option( self::OPTION_NAME );
	}

	/**
	 * Check if current batch is complete.
	 *
	 * @return bool True if complete.
	 */
	public function is_batch_complete() {
		$batch_data = $this->get_batch_data();
		
		if ( ! $batch_data ) {
			return true; // If no data, consider it complete.
		}
		
		return empty( $batch_data['pending_ids'] ) || 
			in_array( $batch_data['status'], [ self::STATUS_EXECUTED, self::STATUS_FAILED ], true );
	}

	/**
	 * Mark all pending IDs as failed with a given error message
	 *
	 * @param string $error_message Error message to record.
	 * @param string $error_code Error code behind the failure.
	 * @return bool True on success, false on failure.
	 */
	public function mark_all_pending_as_failed( $error_message = '', $error_code = '' ) {
		if ( empty( $error_message ) ) {
			$error_message = __( 'Critical error occurred', 'surerank' );
		}
		$batch_data = $this->get_batch_data();
		
		if ( ! $batch_data || empty( $batch_data['pending_ids'] ) ) {
			return false;
		}
		

		foreach ( $batch_data['pending_ids'] as $id ) {
			$batch_data['failed_ids'][ $id ] = [
				'message' => sanitize_text_field( $error_message ),
				'code'    => sanitize_key( $error_code ),
			];
		}
		

		$batch_data['pending_ids'] = [];
		

		$batch_data['failed_count'] = count( $batch_data['failed_ids'] ?? [] );
		

		$batch_data['status']       = self::STATUS_FAILED;
		$batch_data['completed_at'] = time();
		
		return $this->save_batch_data( $batch_data );
	}

	/**
	 * Save batch data to database.
	 *
	 * @param array<string, mixed> $batch_data Batch data to save.
	 * @return bool True on success.
	 */
	private function save_batch_data( array $batch_data ) {
		return update_option( self::OPTION_NAME, $batch_data, false );
	}
}
