<?php
/**
 * Presto Player Integration
 *
 * Handles Presto Player video watch progress and completion tracking
 * with tag application based on video milestones.
 *
 * Features:
 * - Apply tags when video starts playing
 * - Track video progress at specific percentages (10%, 25%, 50%, 75%, etc.)
 * - Apply tags at custom timecodes or percentages
 * - Track video completion (100% watched)
 * - Per-video configuration with rule engine support
 *
 * @since 0.0.3
 *
 * @package SureContact
 */

namespace SureContact\Integrations;

use SureContact\Logger;
use SureContact\Traits\Integration_DB_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Presto_Player_Integration
 *
 * Integrates Presto Player with SureContact for video engagement tracking
 *
 * @since 0.0.3
 */
class Presto_Player_Integration extends Base_Integration {

	// Use the database helper trait for rule engine support.
	use Integration_DB_Helper;

	/**
	 * Track processed progress events per user per video to prevent duplicates
	 *
	 * @since 0.0.3
	 *
	 * @var array
	 */
	private $processed_events = array();

	/**
	 * Constructor
	 *
	 * @since 0.0.3
	 */
	public function __construct() {
		$this->slug        = 'presto-player';
		$this->name        = 'Presto Player';
		$this->description = __( 'Track video engagement and apply tags based on watch progress and completion', 'surecontact' );
		$this->docs_url    = '';
		$this->dependency  = 'PrestoPlayer\Models\Video'; // Presto Player's Video model class.

		parent::__construct();
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 0.0.3
	 */
	protected function init() {
		add_action( 'presto_player_progress', array( $this, 'handle_video_progress' ), 10, 2 );
		add_action( 'presto_player/pro/forms/save', array( $this, 'handle_email_submission' ), 10, 4 );
	}

	/**
	 * Get all available item types for this integration
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of item type definitions
	 */
	public function get_item_types() {
		return array(
			array(
				'key'   => 'video',
				'label' => __( 'Video', 'surecontact' ),
			),
		);
	}

	/**
	 * Get available events for a specific item type
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_type Item type (e.g., 'video').
	 * @return array Array of event definitions
	 */
	public function get_events_by_item_type( $item_type ) {
		switch ( $item_type ) {
			case 'video':
				return array(
					array(
						'key'   => 'email_submit',
						'label' => __( 'Email Submission', 'surecontact' ),
					),
					array(
						'key'   => 'start',
						'label' => __( 'Video Start', 'surecontact' ),
					),
					array(
						'key'   => 'progress',
						'label' => __( 'Progress Tracking', 'surecontact' ),
					),
					array(
						'key'   => 'complete',
						'label' => __( 'Video Complete', 'surecontact' ),
					),
				);

			default:
				return array();
		}
	}

	/**
	 * Get item-specific configuration fields
	 *
	 * Returns configuration schema for per-video settings based on the selected event.
	 *
	 * @since 0.0.3
	 *
	 * @param string      $item_id Item ID (video ID).
	 * @param string|null $event   Event name (email_submit, start, progress, complete).
	 * @return array Configuration fields schema
	 */
	public function get_item_config_fields( $item_id, $event = null ) {
		// Return fields based on the event type.
		switch ( $event ) {
			case 'progress':
				// Dynamic progress tracking - allows custom percentages with repeater-style configuration.
				$percentage_field = array(
					'percentage' => array(
						'label'       => __( 'Percentage', 'surecontact' ),
						'type'        => 'number',
						'placeholder' => '25',
						'min'         => 1,
						'max'         => 100,
						'required'    => true,
					),
				);

				return array(
					'progress_thresholds' => array(
						'label'       => __( 'Progress Thresholds', 'surecontact' ),
						'description' => __( 'Add one or more progress percentages to track. For each percentage, you can assign lists and tags to add or remove when the video reaches that point.', 'surecontact' ),
						'type'        => 'repeater',
						'default'     => array(),
						'fields'      => array_merge( $percentage_field, self::get_standard_list_tag_fields() ),
					),
				);

			case 'email_submit':
			case 'start':
			case 'complete':
			default:
				// Standard list and tag assignment with add/remove options.
				return self::get_standard_list_tag_fields();
		}
	}

	/**
	 * Get item fields for field mapping
	 *
	 * Videos don't have custom fields to map, so return empty array.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id Item ID (video ID).
	 * @return array Empty array
	 */
	public function get_item_fields( $item_id ) {
		return array();
	}

	/**
	 * Handle email submission event
	 *
	 * This is triggered when a user submits their email to access a gated video.
	 * Hook: presto_player/pro/forms/save
	 *
	 * @since 0.0.3
	 *
	 * @param array    $data       Validated user submission data (email, name, etc.).
	 * @param object   $preset     Preset configuration used for email collection.
	 * @param \WP_Post $email_post Post object storing email submission.
	 * @param bool     $created    Whether submission is new (true) or updated (false).
	 * @return void
	 */
	public function handle_email_submission( $data, $preset = null, $email_post = null, $created = false ) {
		// Extract email from data (various possible formats).
		$email      = '';
		$first_name = '';
		$last_name  = '';
		$video_id   = null;

		// Try different data structures based on how Presto Player passes data.
		if ( is_array( $data ) ) {
			$email      = $data['email'] ?? $data['user_email'] ?? '';
			$first_name = $data['first_name'] ?? $data['name'] ?? '';
			$last_name  = $data['last_name'] ?? '';
		}

		// Try to get video_id from preset object.
		if ( $preset && is_object( $preset ) ) {
			if ( isset( $preset->video_id ) ) {
				$video_id = $preset->video_id;
			} elseif ( isset( $preset->id ) ) {
				$video_id = $preset->id;
			}
		}

		// Try to get video_id from email_post.
		if ( ! $video_id && $email_post instanceof \WP_Post ) {
			// Check post meta for associated video ID.
			$meta_video_id = get_post_meta( $email_post->ID, 'video_id', true );
			if ( $meta_video_id ) {
				$video_id = $meta_video_id;
			}
		}

		// Check if email_submit event is enabled before processing.
		$db         = $this->get_db();
		$is_enabled = false;

		// Check video-specific settings first.
		if ( $video_id ) {
			$result = $db->get( $this->slug, (string) $video_id, 'video', 'email_submit' );
			if ( $result && ! empty( $result['status'] ) ) {
				$is_enabled = true;
			}
		}

		// If not enabled for specific video, check "All Videos" settings.
		if ( ! $is_enabled ) {
			$result = $db->get( $this->slug, 'all', 'video', 'email_submit' );
			if ( $result && ! empty( $result['status'] ) ) {
				$is_enabled = true;
			}
		}

		// If email_submit event is not enabled, skip processing.
		if ( ! $is_enabled ) {
			return;
		}

		// Validate email.
		if ( empty( $email ) || ! is_email( $email ) ) {
			Logger::error( $this->name, 'Invalid or missing email in submission data' );
			return;
		}

		// Get current user ID if logged in.
		$user_id = get_current_user_id();

		// If not logged in, try to find user by email.
		if ( ! $user_id ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$user_id = $user->ID;
			}
		}

		// Try to get existing contact by user ID or email.
		$contact_id = null;
		if ( $user_id ) {
			$contact_id = $this->contact_service->get_contact_id_by_user( $user_id );
		}

		// If no contact found by user, search by email.
		if ( ! $contact_id ) {
			$contact_id = $this->contact_service->find_contact_id_by_email( $email );
		}

		// If contact doesn't exist, create it.
		if ( ! $contact_id ) {
			$contact_id = $this->create_contact_from_email( $email, $first_name, $last_name, $user_id );

			if ( ! $contact_id ) {
				Logger::error( $this->name, "Failed to create contact for email: {$email}" );
				return;
			}
		}

		// Apply lists and tags for email submission.
		$this->apply_email_submission_lists_and_tags( $contact_id, $video_id );
	}

	/**
	 * Handle video progress event
	 *
	 * This is the main handler that fires when Presto Player reports video progress.
	 *
	 * @since 0.0.3
	 *
	 * @param int $video_id Video ID created when adding a video in Presto Player.
	 * @param int $percent  Progress percentage (integer, multiples of 10: 0, 10, 20, ..., 100).
	 * @return void
	 */
	public function handle_video_progress( $video_id, $percent ) {
		// Get current user ID.
		$user_id = get_current_user_id();

		// Skip if user is not logged in (we need a user to track).
		if ( ! $user_id ) {
			return;
		}

		// Prevent duplicate processing for the same user, video, and percentage.
		$event_key = "{$user_id}_{$video_id}_{$percent}";
		if ( isset( $this->processed_events[ $event_key ] ) ) {
			return;
		}
		$this->processed_events[ $event_key ] = true;

		// Get or create contact for this user.
		$contact_id = $this->get_or_create_contact( $user_id );
		if ( ! $contact_id ) {
			Logger::error( $this->name, "Failed to get or create contact for user {$user_id}" );
			return;
		}

		// Handle video start (0%).
		if ( 0 === $percent ) {
			$this->handle_video_start( $video_id, $contact_id );
			return;
		}

		// Handle video completion.
		if ( 100 === $percent ) {
			$this->handle_video_completion( $video_id, $contact_id );
			return;
		}

		// Handle percentage-based progress tracking (for all other percentages).
		$this->handle_percentage_progress( $video_id, $percent, $contact_id );
	}

	/**
	 * Handle video start event
	 *
	 * @since 0.0.3
	 *
	 * @param int    $video_id   Video ID.
	 * @param string $contact_id Contact UUID.
	 * @return void
	 */
	private function handle_video_start( $video_id, $contact_id ) {
		$lists_to_add    = array();
		$tags_to_add     = array();
		$lists_to_remove = array();
		$tags_to_remove  = array();

		// Get database instance directly to pass item_type correctly.
		$db = $this->get_db();

		// Priority: Specific video settings override "All Videos" settings.
		// First check video-specific settings.
		$result = $db->get( $this->slug, (string) $video_id, 'video', 'start' );

		$video_settings = ( $result && ! empty( $result['config'] ) && is_array( $result['config'] ) && ! empty( $result['status'] ) ) ? $result['config'] : array();

		// Determine if we should use video-specific settings or fall back to "All Videos".
		$has_video_settings = ! empty( $video_settings ) && (
			! empty( $video_settings['add_lists'] ) ||
			! empty( $video_settings['add_tags'] ) ||
			! empty( $video_settings['remove_lists'] ) ||
			! empty( $video_settings['remove_tags'] )
		);

		if ( $has_video_settings ) {
			// Use video-specific settings.
			if ( ! empty( $video_settings['add_lists'] ) ) {
				$lists_to_add = array_merge( $lists_to_add, $this->extract_uuids( $video_settings['add_lists'] ) );
			}
			if ( ! empty( $video_settings['add_tags'] ) ) {
				$tags_to_add = array_merge( $tags_to_add, $this->extract_uuids( $video_settings['add_tags'] ) );
			}
			if ( ! empty( $video_settings['remove_lists'] ) ) {
				$lists_to_remove = array_merge( $lists_to_remove, $this->extract_uuids( $video_settings['remove_lists'] ) );
			}
			if ( ! empty( $video_settings['remove_tags'] ) ) {
				$tags_to_remove = array_merge( $tags_to_remove, $this->extract_uuids( $video_settings['remove_tags'] ) );
			}
		} else {
			// Fall back to "All Videos" settings if no video-specific settings.
			$result              = $db->get( $this->slug, 'all', 'video', 'start' );
			$all_videos_settings = ( $result && ! empty( $result['config'] ) && is_array( $result['config'] ) && ! empty( $result['status'] ) ) ? $result['config'] : array();
			if ( ! empty( $all_videos_settings['add_lists'] ) ) {
				$lists_to_add = array_merge( $lists_to_add, $this->extract_uuids( $all_videos_settings['add_lists'] ) );
			}
			if ( ! empty( $all_videos_settings['add_tags'] ) ) {
				$tags_to_add = array_merge( $tags_to_add, $this->extract_uuids( $all_videos_settings['add_tags'] ) );
			}
			if ( ! empty( $all_videos_settings['remove_lists'] ) ) {
				$lists_to_remove = array_merge( $lists_to_remove, $this->extract_uuids( $all_videos_settings['remove_lists'] ) );
			}
			if ( ! empty( $all_videos_settings['remove_tags'] ) ) {
				$tags_to_remove = array_merge( $tags_to_remove, $this->extract_uuids( $all_videos_settings['remove_tags'] ) );
			}
		}

		// Add lists if any.
		if ( ! empty( $lists_to_add ) ) {
			$lists_to_add = array_values( array_unique( $lists_to_add ) );
			$this->apply_lists_to_contact( $contact_id, $lists_to_add, "video {$video_id} start" );
		}

		// Add tags if any.
		if ( ! empty( $tags_to_add ) ) {
			$tags_to_add = array_values( array_unique( $tags_to_add ) );
			$this->apply_tags_to_contact( $contact_id, $tags_to_add, "video {$video_id} start" );
		}

		// Remove lists if any.
		if ( ! empty( $lists_to_remove ) ) {
			$lists_to_remove = array_values( array_unique( $lists_to_remove ) );
			$this->remove_lists_from_contact( $contact_id, $lists_to_remove, "video {$video_id} start" );
		}

		// Remove tags if any.
		if ( ! empty( $tags_to_remove ) ) {
			$tags_to_remove = array_values( array_unique( $tags_to_remove ) );
			$this->remove_tags_from_contact( $contact_id, $tags_to_remove, "video {$video_id} start" );
		}
	}

	/**
	 * Handle video completion event
	 *
	 * @since 0.0.3
	 *
	 * @param int    $video_id   Video ID.
	 * @param string $contact_id Contact UUID.
	 * @return void
	 */
	private function handle_video_completion( $video_id, $contact_id ) {
		$lists_to_add    = array();
		$tags_to_add     = array();
		$lists_to_remove = array();
		$tags_to_remove  = array();

		// Get database instance directly to pass item_type correctly.
		$db = $this->get_db();

		// Priority: Specific video settings override "All Videos" settings.
		// First check video-specific settings.
		$result         = $db->get( $this->slug, (string) $video_id, 'video', 'complete' );
		$video_settings = ( $result && ! empty( $result['config'] ) && is_array( $result['config'] ) && ! empty( $result['status'] ) ) ? $result['config'] : array();

		// Determine if we should use video-specific settings or fall back to "All Videos".
		$has_video_settings = ! empty( $video_settings ) && (
			! empty( $video_settings['add_lists'] ) ||
			! empty( $video_settings['add_tags'] ) ||
			! empty( $video_settings['remove_lists'] ) ||
			! empty( $video_settings['remove_tags'] )
		);

		if ( $has_video_settings ) {
			// Use video-specific settings.
			if ( ! empty( $video_settings['add_lists'] ) ) {
				$lists_to_add = array_merge( $lists_to_add, $this->extract_uuids( $video_settings['add_lists'] ) );
			}
			if ( ! empty( $video_settings['add_tags'] ) ) {
				$tags_to_add = array_merge( $tags_to_add, $this->extract_uuids( $video_settings['add_tags'] ) );
			}
			if ( ! empty( $video_settings['remove_lists'] ) ) {
				$lists_to_remove = array_merge( $lists_to_remove, $this->extract_uuids( $video_settings['remove_lists'] ) );
			}
			if ( ! empty( $video_settings['remove_tags'] ) ) {
				$tags_to_remove = array_merge( $tags_to_remove, $this->extract_uuids( $video_settings['remove_tags'] ) );
			}
		} else {
			// Fall back to "All Videos" settings if no video-specific settings.
			$result              = $db->get( $this->slug, 'all', 'video', 'complete' );
			$all_videos_settings = ( $result && ! empty( $result['config'] ) && is_array( $result['config'] ) && ! empty( $result['status'] ) ) ? $result['config'] : array();
			if ( ! empty( $all_videos_settings['add_lists'] ) ) {
				$lists_to_add = array_merge( $lists_to_add, $this->extract_uuids( $all_videos_settings['add_lists'] ) );
			}
			if ( ! empty( $all_videos_settings['add_tags'] ) ) {
				$tags_to_add = array_merge( $tags_to_add, $this->extract_uuids( $all_videos_settings['add_tags'] ) );
			}
			if ( ! empty( $all_videos_settings['remove_lists'] ) ) {
				$lists_to_remove = array_merge( $lists_to_remove, $this->extract_uuids( $all_videos_settings['remove_lists'] ) );
			}
			if ( ! empty( $all_videos_settings['remove_tags'] ) ) {
				$tags_to_remove = array_merge( $tags_to_remove, $this->extract_uuids( $all_videos_settings['remove_tags'] ) );
			}
		}

		// Add lists if any.
		if ( ! empty( $lists_to_add ) ) {
			$lists_to_add = array_values( array_unique( $lists_to_add ) );
			$this->apply_lists_to_contact( $contact_id, $lists_to_add, "video {$video_id} completion" );
		}

		// Add tags if any.
		if ( ! empty( $tags_to_add ) ) {
			$tags_to_add = array_values( array_unique( $tags_to_add ) );
			$this->apply_tags_to_contact( $contact_id, $tags_to_add, "video {$video_id} completion" );
		}

		// Remove lists if any.
		if ( ! empty( $lists_to_remove ) ) {
			$lists_to_remove = array_values( array_unique( $lists_to_remove ) );
			$this->remove_lists_from_contact( $contact_id, $lists_to_remove, "video {$video_id} completion" );
		}

		// Remove tags if any.
		if ( ! empty( $tags_to_remove ) ) {
			$tags_to_remove = array_values( array_unique( $tags_to_remove ) );
			$this->remove_tags_from_contact( $contact_id, $tags_to_remove, "video {$video_id} completion" );
		}
	}

	/**
	 * Handle percentage-based progress tracking
	 *
	 * Processes thresholds within current range (e.g., 30% event checks thresholds 21-30).
	 *
	 * @since 0.0.3
	 *
	 * @param int    $video_id   Video ID.
	 * @param int    $percent    Current percentage (multiples of 10).
	 * @param string $contact_id Contact UUID.
	 * @return void
	 */
	private function handle_percentage_progress( $video_id, $percent, $contact_id ) {
		$db = $this->get_db();

		$result         = $db->get( $this->slug, (string) $video_id, 'video', 'progress' );
		$video_settings = ( $result && ! empty( $result['config'] ) && is_array( $result['config'] ) && ! empty( $result['status'] ) ) ? $result['config'] : array();

		$thresholds = array();
		if ( ! empty( $video_settings['progress_thresholds'] ) ) {
			$thresholds = $this->parse_progress_thresholds( $video_settings['progress_thresholds'] );
		} else {
			$result              = $db->get( $this->slug, 'all', 'video', 'progress' );
			$all_videos_settings = ( $result && ! empty( $result['config'] ) && is_array( $result['config'] ) && ! empty( $result['status'] ) ) ? $result['config'] : array();
			if ( ! empty( $all_videos_settings['progress_thresholds'] ) ) {
				$thresholds = $this->parse_progress_thresholds( $all_videos_settings['progress_thresholds'] );
			}
		}

		if ( empty( $thresholds ) ) {
			return;
		}

		// Calculate range: 30% checks thresholds 21-30.
		$range_min = $percent - 9;
		$range_max = $percent;

		$lists_to_add         = array();
		$tags_to_add          = array();
		$lists_to_remove      = array();
		$tags_to_remove       = array();
		$triggered_thresholds = array();

		foreach ( $thresholds as $threshold_percent => $threshold_config ) {
			if ( $threshold_percent < $range_min || $threshold_percent > $range_max ) {
				continue;
			}

			$triggered_thresholds[] = $threshold_percent;

			if ( ! empty( $threshold_config['add_lists'] ) ) {
				$lists_to_add = array_merge( $lists_to_add, $this->extract_uuids( $threshold_config['add_lists'] ) );
			}
			if ( ! empty( $threshold_config['add_tags'] ) ) {
				$tags_to_add = array_merge( $tags_to_add, $this->extract_uuids( $threshold_config['add_tags'] ) );
			}
			if ( ! empty( $threshold_config['remove_lists'] ) ) {
				$lists_to_remove = array_merge( $lists_to_remove, $this->extract_uuids( $threshold_config['remove_lists'] ) );
			}
			if ( ! empty( $threshold_config['remove_tags'] ) ) {
				$tags_to_remove = array_merge( $tags_to_remove, $this->extract_uuids( $threshold_config['remove_tags'] ) );
			}
		}

		if ( empty( $triggered_thresholds ) ) {
			return;
		}

		$context = "video {$video_id} - thresholds " . implode( ', ', $triggered_thresholds ) . '%';

		// Add lists if any.
		$lists_to_add = array_values( array_unique( $lists_to_add ) );
		if ( ! empty( $lists_to_add ) ) {
			$this->apply_lists_to_contact( $contact_id, $lists_to_add, $context );
		}

		// Add tags if any.
		$tags_to_add = array_values( array_unique( $tags_to_add ) );
		if ( ! empty( $tags_to_add ) ) {
			$this->apply_tags_to_contact( $contact_id, $tags_to_add, $context );
		}

		// Remove lists if any.
		$lists_to_remove = array_values( array_unique( $lists_to_remove ) );
		if ( ! empty( $lists_to_remove ) ) {
			$this->remove_lists_from_contact( $contact_id, $lists_to_remove, $context );
		}

		// Remove tags if any.
		$tags_to_remove = array_values( array_unique( $tags_to_remove ) );
		if ( ! empty( $tags_to_remove ) ) {
			$this->remove_tags_from_contact( $contact_id, $tags_to_remove, $context );
		}
	}

	/**
	 * Create contact from email submission
	 *
	 * @since 0.0.3
	 *
	 * @param string $email      Email address.
	 * @param string $first_name First name (optional).
	 * @param string $last_name  Last name (optional).
	 * @param int    $user_id    User ID (0 if not logged in).
	 * @return string|null Contact UUID or null on failure
	 */
	private function create_contact_from_email( $email, $first_name = '', $last_name = '', $user_id = 0 ) {
		// Build contact data.
		$primary_fields = array(
			'email' => $email,
		);

		if ( ! empty( $first_name ) ) {
			$primary_fields['first_name'] = $first_name;
		}

		if ( ! empty( $last_name ) ) {
			$primary_fields['last_name'] = $last_name;
		}

		$mapped_data = $this->build_crm_data( $primary_fields );

		// Create contact.
		$result = $this->contact_service->create_contact( $mapped_data, $user_id );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		// Return contact ID.
		return $result['contact_id'] ?? $result['uuid'] ?? null;
	}

	/**
	 * Apply email submission lists and tags to contact
	 *
	 * @since 0.0.3
	 *
	 * @param string   $contact_id Contact UUID.
	 * @param int|null $video_id   Video ID (optional).
	 * @return void
	 */
	private function apply_email_submission_lists_and_tags( $contact_id, $video_id = null ) {
		$lists_to_add    = array();
		$tags_to_add     = array();
		$lists_to_remove = array();
		$tags_to_remove  = array();

		// Get database instance directly to pass item_type correctly.
		$db = $this->get_db();

		// Priority: Specific video settings override "All Videos" settings.
		// First check video-specific settings if video_id is provided.
		if ( $video_id ) {
			$result         = $db->get( $this->slug, (string) $video_id, 'video', 'email_submit' );
			$video_settings = ( $result && ! empty( $result['config'] ) && is_array( $result['config'] ) && ! empty( $result['status'] ) ) ? $result['config'] : array();

			// Determine if we should use video-specific settings or fall back to "All Videos".
			$has_video_settings = ! empty( $video_settings ) && (
				! empty( $video_settings['add_lists'] ) ||
				! empty( $video_settings['add_tags'] ) ||
				! empty( $video_settings['remove_lists'] ) ||
				! empty( $video_settings['remove_tags'] )
			);

			if ( $has_video_settings ) {
				// Use video-specific settings.
				if ( ! empty( $video_settings['add_lists'] ) ) {
					$lists_to_add = array_merge( $lists_to_add, $this->extract_uuids( $video_settings['add_lists'] ) );
				}
				if ( ! empty( $video_settings['add_tags'] ) ) {
					$tags_to_add = array_merge( $tags_to_add, $this->extract_uuids( $video_settings['add_tags'] ) );
				}
				if ( ! empty( $video_settings['remove_lists'] ) ) {
					$lists_to_remove = array_merge( $lists_to_remove, $this->extract_uuids( $video_settings['remove_lists'] ) );
				}
				if ( ! empty( $video_settings['remove_tags'] ) ) {
					$tags_to_remove = array_merge( $tags_to_remove, $this->extract_uuids( $video_settings['remove_tags'] ) );
				}
			} else {
				// Fall back to "All Videos" settings.
				$result              = $db->get( $this->slug, 'all', 'video', 'email_submit' );
				$all_videos_settings = ( $result && ! empty( $result['config'] ) && is_array( $result['config'] ) && ! empty( $result['status'] ) ) ? $result['config'] : array();
				if ( ! empty( $all_videos_settings['add_lists'] ) ) {
					$lists_to_add = array_merge( $lists_to_add, $this->extract_uuids( $all_videos_settings['add_lists'] ) );
				}
				if ( ! empty( $all_videos_settings['add_tags'] ) ) {
					$tags_to_add = array_merge( $tags_to_add, $this->extract_uuids( $all_videos_settings['add_tags'] ) );
				}
				if ( ! empty( $all_videos_settings['remove_lists'] ) ) {
					$lists_to_remove = array_merge( $lists_to_remove, $this->extract_uuids( $all_videos_settings['remove_lists'] ) );
				}
				if ( ! empty( $all_videos_settings['remove_tags'] ) ) {
					$tags_to_remove = array_merge( $tags_to_remove, $this->extract_uuids( $all_videos_settings['remove_tags'] ) );
				}
			}
		} else {
			// No video_id provided, use "All Videos" settings.
			$result              = $db->get( $this->slug, 'all', 'video', 'email_submit' );
			$all_videos_settings = ( $result && ! empty( $result['config'] ) && is_array( $result['config'] ) && ! empty( $result['status'] ) ) ? $result['config'] : array();
			if ( ! empty( $all_videos_settings['add_lists'] ) ) {
				$lists_to_add = array_merge( $lists_to_add, $this->extract_uuids( $all_videos_settings['add_lists'] ) );
			}
			if ( ! empty( $all_videos_settings['add_tags'] ) ) {
				$tags_to_add = array_merge( $tags_to_add, $this->extract_uuids( $all_videos_settings['add_tags'] ) );
			}
			if ( ! empty( $all_videos_settings['remove_lists'] ) ) {
				$lists_to_remove = array_merge( $lists_to_remove, $this->extract_uuids( $all_videos_settings['remove_lists'] ) );
			}
			if ( ! empty( $all_videos_settings['remove_tags'] ) ) {
				$tags_to_remove = array_merge( $tags_to_remove, $this->extract_uuids( $all_videos_settings['remove_tags'] ) );
			}
		}

		$context = $video_id ? "video {$video_id} email submission" : 'email submission';

		// Add lists if any.
		if ( ! empty( $lists_to_add ) ) {
			$lists_to_add = array_values( array_unique( $lists_to_add ) );
			$this->apply_lists_to_contact( $contact_id, $lists_to_add, $context );
		}

		// Add tags if any.
		if ( ! empty( $tags_to_add ) ) {
			$tags_to_add = array_values( array_unique( $tags_to_add ) );
			$this->apply_tags_to_contact( $contact_id, $tags_to_add, $context );
		}

		// Remove lists if any.
		if ( ! empty( $lists_to_remove ) ) {
			$lists_to_remove = array_values( array_unique( $lists_to_remove ) );
			$this->remove_lists_from_contact( $contact_id, $lists_to_remove, $context );
		}

		// Remove tags if any.
		if ( ! empty( $tags_to_remove ) ) {
			$tags_to_remove = array_values( array_unique( $tags_to_remove ) );
			$this->remove_tags_from_contact( $contact_id, $tags_to_remove, $context );
		}
	}

	/**
	 * Get or create contact for user
	 *
	 * @since 0.0.3
	 *
	 * @param int $user_id User ID.
	 * @return string|null Contact UUID or null on failure
	 */
	private function get_or_create_contact( $user_id ) {
		if ( ! $user_id ) {
			return null;
		}

		// Try to get existing contact.
		$contact_id = $this->contact_service->get_contact_id_by_user( $user_id );

		if ( $contact_id ) {
			return $contact_id;
		}

		// Contact doesn't exist, create it from user data.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		// Build contact data.
		$primary_fields = array(
			'email'      => $user->user_email,
			'first_name' => $user->first_name,
			'last_name'  => $user->last_name,
		);

		$metadata = array(
			'source'  => $this->slug,
			'user_id' => $user_id,
		);

		$mapped_data = $this->build_crm_data( $primary_fields, array(), $metadata );

		// Create contact.
		$result = $this->contact_service->create_contact( $mapped_data, $user_id );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		// Return contact ID.
		return $result['contact_id'] ?? $result['uuid'] ?? null;
	}

	/**
	 * Apply lists to contact
	 *
	 * @since 0.0.3
	 *
	 * @param string $contact_id Contact UUID.
	 * @param array  $list_uuids Array of list UUIDs.
	 * @param string $context    Context for logging.
	 * @return bool True on success, false on error
	 */
	private function apply_lists_to_contact( $contact_id, $list_uuids, $context = '' ) {
		if ( empty( $list_uuids ) || empty( $contact_id ) ) {
			return false;
		}

		$result = $this->contact_service->attach_lists_to_contact( $contact_id, $list_uuids );

		return ! is_wp_error( $result );
	}

	/**
	 * Apply tags to contact
	 *
	 * @since 0.0.3
	 *
	 * @param string $contact_id Contact UUID.
	 * @param array  $tag_uuids  Array of tag UUIDs.
	 * @param string $context    Context for logging.
	 * @return bool True on success, false on error
	 */
	private function apply_tags_to_contact( $contact_id, $tag_uuids, $context = '' ) {
		if ( empty( $tag_uuids ) || empty( $contact_id ) ) {
			return false;
		}

		$result = $this->contact_service->attach_tags_to_contact( $contact_id, $tag_uuids );

		return ! is_wp_error( $result );
	}

	/**
	 * Remove lists from contact
	 *
	 * @since 1.0.0
	 *
	 * @param string $contact_id Contact UUID.
	 * @param array  $list_uuids Array of list UUIDs.
	 * @param string $context    Context for logging.
	 * @return bool True on success, false on error
	 */
	private function remove_lists_from_contact( $contact_id, $list_uuids, $context = '' ) {
		if ( empty( $list_uuids ) || empty( $contact_id ) ) {
			return false;
		}

		$result = $this->contact_service->detach_lists_from_contact( $contact_id, $list_uuids );

		return ! is_wp_error( $result );
	}

	/**
	 * Remove tags from contact
	 *
	 * @since 1.0.0
	 *
	 * @param string $contact_id Contact UUID.
	 * @param array  $tag_uuids  Array of tag UUIDs.
	 * @param string $context    Context for logging.
	 * @return bool True on success, false on error
	 */
	private function remove_tags_from_contact( $contact_id, $tag_uuids, $context = '' ) {
		if ( empty( $tag_uuids ) || empty( $contact_id ) ) {
			return false;
		}

		$result = $this->contact_service->detach_tags_from_contact( $contact_id, $tag_uuids );

		return ! is_wp_error( $result );
	}

	/**
	 * Parse progress thresholds configuration
	 *
	 * Converts repeater array format like:
	 * [
	 *   {"percentage": 25, "add_lists": [uuid1, uuid2], "add_tags": [uuid3], "remove_lists": [], "remove_tags": []},
	 *   {"percentage": 50, "add_lists": [uuid4], "add_tags": [], "remove_lists": [uuid5], "remove_tags": [uuid6]},
	 * ]
	 * into an associative array with percentage as key:
	 * {
	 *   25: {"add_lists": [uuid1, uuid2], "add_tags": [uuid3], "remove_lists": [], "remove_tags": []},
	 *   50: {"add_lists": [uuid4], "add_tags": [], "remove_lists": [uuid5], "remove_tags": [uuid6]}
	 * }
	 *
	 * @since 0.0.3
	 *
	 * @param array $thresholds Progress thresholds array.
	 * @return array Associative array of percentage => {add_lists, add_tags, remove_lists, remove_tags}
	 */
	private function parse_progress_thresholds( $thresholds ) {
		if ( empty( $thresholds ) || ! is_array( $thresholds ) ) {
			return array();
		}

		$parsed = array();
		foreach ( $thresholds as $threshold ) {
			if ( ! isset( $threshold['percentage'] ) ) {
				continue;
			}

			$percentage = (int) $threshold['percentage'];

			$parsed[ $percentage ] = array(
				'add_lists'    => isset( $threshold['add_lists'] ) && is_array( $threshold['add_lists'] ) ? $threshold['add_lists'] : array(),
				'add_tags'     => isset( $threshold['add_tags'] ) && is_array( $threshold['add_tags'] ) ? $threshold['add_tags'] : array(),
				'remove_lists' => isset( $threshold['remove_lists'] ) && is_array( $threshold['remove_lists'] ) ? $threshold['remove_lists'] : array(),
				'remove_tags'  => isset( $threshold['remove_tags'] ) && is_array( $threshold['remove_tags'] ) ? $threshold['remove_tags'] : array(),
			);
		}

		return $parsed;
	}

	/**
	 * Get videos list for admin UI
	 *
	 * Returns a list of all Presto Player videos from the video model.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of video items with 'id', 'title', and 'type' keys
	 */
	public function get_videos() {
		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Videos', 'surecontact' ),
				'type'  => 'video',
			),
		);

		// Check if Presto Player Video model exists.
		if ( ! class_exists( 'PrestoPlayer\Models\Video' ) ) {
			Logger::error( $this->name, 'Presto Player Video model not found' );
			return $items;
		}

		// Get all videos using Presto Player's Video model.
		$video_model = new \PrestoPlayer\Models\Video();
		$videos      = $video_model->all();

		if ( empty( $videos ) ) {
			return $items;
		}

		foreach ( $videos as $video ) {
			$items[] = array(
				'id'    => $video->id,
				'title' => $this->extract_video_title( $video ),
				'type'  => 'video',
			);
		}

		return $items;
	}

	/**
	 * Get item title by type and ID
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id   Item ID (video ID from presto_player_videos table).
	 * @param string $item_type Item type ('video').
	 * @return string|null Item title or null if not found
	 */
	public function get_item_title( $item_id, $item_type ) {
		if ( 'all' === $item_id ) {
			if ( 'video' === $item_type ) {
				return __( 'All Videos', 'surecontact' );
			}
			return null;
		}

		if ( 'video' !== $item_type ) {
			return null;
		}

		// Check if Presto Player Video model exists.
		if ( ! class_exists( 'PrestoPlayer\Models\Video' ) ) {
			return null;
		}

		// Get video from Presto Player's Video model.
		try {
			$video = new \PrestoPlayer\Models\Video( (int) $item_id );
			return $this->extract_video_title( $video );
		} catch ( \Exception $e ) {
			Logger::error( $this->name, "Failed to get video title for ID {$item_id}: " . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Extract video title from Video object
	 *
	 * Helper method to get title from Presto Player Video object.
	 * Handles fallback to 'Untitled Video' if title is empty.
	 *
	 * @since 0.0.3
	 *
	 * @param object $video Presto Player Video object.
	 * @return string Video title or 'Untitled Video'
	 */
	private function extract_video_title( $video ) {
		$title = method_exists( $video, 'getTitle' ) ? $video->getTitle() : ( property_exists( $video, 'title' ) ? $video->title : '' );

		if ( ! $title || '' === trim( $title ) ) {
			return __( 'Untitled Video', 'surecontact' );
		}

		return $title;
	}
}
