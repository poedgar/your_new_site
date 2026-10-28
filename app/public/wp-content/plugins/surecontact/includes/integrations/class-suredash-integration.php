<?php
/**
 * SureDash Integration
 *
 * Handles SureDash community engagement tracking with rule-based lists and tags
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
 * Class SureDash_Integration
 *
 * Integrates SureDash with SureContact for community engagement tracking
 *
 * Features:
 * - Track post creation in community spaces
 * - Track comment/discussion activity
 * - Track content bookmarks (interest indicators)
 * - Track reactions/likes (engagement metrics)
 * - Per-space lists and tags configuration
 * - Support for engagement-based segmentation
 *
 * @since 0.0.3
 */
class SureDash_Integration extends Base_Integration {

	// Use the database helper trait for item-specific configurations.
	use Integration_DB_Helper;

	/**
	 * Constructor
	 *
	 * @since 0.0.3
	 */
	public function __construct() {
		$this->slug        = 'suredash';
		$this->name        = 'SureDash';
		$this->description = __( 'Track SureDash community engagement including posts, comments, bookmarks, and reactions', 'surecontact' );
		$this->docs_url    = 'https://suredash.com/';
		$this->dependency  = 'SureDashboard\\Portals_Loader'; // SureDash main class.

		parent::__construct();
	}

	/**
	 * Get additional plugin dependencies for SureDash.
	 *
	 * SureDash Pro is required for advanced community features.
	 *
	 * @since 0.0.3
	 *
	 * @return array Keyed array of plugin_key => array( plugin_file, plugin_name, plugin_dependencies ).
	 */
	public function get_additional_plugins() {
		return array(
			'suredash-pro' => array(
				'plugin_file'         => 'suredash-pro/suredash-pro.php',
				'plugin_name'         => __( 'SureDash Pro', 'surecontact' ),
				'plugin_dependencies' => array( 'suredash/suredash.php' ),
			),
		);
	}

	/**
	 * Get item type to plugin requirement mapping for SureDash.
	 *
	 * Only course rules require SureDash Pro; space is free.
	 *
	 * @since 0.0.3
	 *
	 * @return array Map of item_type_key => plugin_key.
	 */
	public function get_item_type_plugin_requirements() {
		return array(
			'course' => 'suredash-pro',
		);
	}

	/**
	 * Get integration-specific global settings fields
	 *
	 * SureDash does not use global settings.
	 * All configurations are done at the item level (Space/Course).
	 *
	 * @since 0.0.3
	 *
	 * @return array Settings fields configuration
	 */
	public function get_settings_fields() {
		return array();
	}

	/**
	 * Get all available item types for SureDash.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of item type definitions with 'key' and 'label' keys.
	 */
	public function get_item_types() {
		return array(
			array(
				'key'   => 'space',
				'label' => __( 'Community Space', 'surecontact' ),
			),
			array(
				'key'   => 'course',
				'label' => __( 'Course', 'surecontact' ),
			),
		);
	}

	/**
	 * Get available events for a specific item type.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_type Item type (e.g., 'space', 'course').
	 * @return array Array of event definitions with 'key' and 'label' keys.
	 */
	public function get_events_by_item_type( $item_type ) {
		switch ( $item_type ) {
			case 'space':
				return array(
					array(
						'key'   => 'post_created',
						'label' => __( 'Post Created', 'surecontact' ),
					),
					array(
						'key'   => 'comment_added',
						'label' => __( 'Comment Added', 'surecontact' ),
					),
					array(
						'key'   => 'bookmarked',
						'label' => __( 'Content Bookmarked', 'surecontact' ),
					),
					array(
						'key'   => 'reacted',
						'label' => __( 'Content Reacted/Liked', 'surecontact' ),
					),
				);

			case 'course':
				return array(
					array(
						'key'   => 'lesson_completed',
						'label' => __( 'Lesson Completed', 'surecontact' ),
					),
					array(
						'key'   => 'course_completed',
						'label' => __( 'Course Completed', 'surecontact' ),
					),
				);

			default:
				return array();
		}
	}

	/**
	 * Get item-specific configuration fields.
	 *
	 * Uses a common structure for all events with these standard field keys:
	 * - add_lists: Lists to add contacts to
	 * - add_tags: Tags to apply to contacts
	 * - remove_lists: Lists to remove contacts from
	 * - remove_tags: Tags to remove from contacts
	 *
	 * @since 0.0.3
	 *
	 * @param string      $item_id Space ID.
	 * @param string|null $event   Event name (not used - kept for compatibility).
	 * @return array Configuration fields schema.
	 */
	public function get_item_config_fields( $item_id, $event = null ) {
		// Return common configuration fields that work for all events.
		return self::get_standard_list_tag_fields();
	}

	/**
	 * Get item fields for field mapping.
	 *
	 * SureDash spaces don't have mappable fields.
	 * User data is synced from WordPress user accounts.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id Space ID.
	 * @return array Empty array (no mappable fields for spaces).
	 */
	public function get_item_fields( $item_id ) {
		// Spaces don't have mappable fields.
		// All configuration is handled through add_lists/add_tags/remove_lists/remove_tags.
		return array();
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 0.0.3
	 *
	 * @return void
	 */
	protected function init() {
		// Hook for post submission ($post_id, $filtered_data).
		add_action( 'suredash_after_post_submit', array( $this, 'handle_post_created' ), 10, 2 );

		// Hook for comment submission.
		add_action( 'suredash_after_comment_submit', array( $this, 'handle_comment_added' ), 10, 2 );

		// Hook for bookmark action.
		add_action( 'suredash_item_bookmark', array( $this, 'handle_bookmark' ), 10, 4 );

		// Hook for reaction/like.
		add_action( 'suredash_entity_like_reaction', array( $this, 'handle_reaction' ), 10, 4 );

		// Course completion hooks (SureDash Pro).
		add_action( 'suredash_lesson_completed', array( $this, 'handle_lesson_completed' ), 10, 3 );
		add_action( 'suredash_course_completed', array( $this, 'handle_course_completed' ), 10, 2 );
	}

	/**
	 * Handle post created
	 *
	 * Triggered when a user creates a post in a community space.
	 *
	 * @since 0.0.3
	 *
	 * @param int   $post_id Post ID.
	 * @param array $filtered_data Post data array.
	 * @return void
	 */
	public function handle_post_created( $post_id, $filtered_data ) {
		$post_id = absint( $post_id );
		$user_id = get_current_user_id();

		if ( ! $user_id || ! $post_id ) {
			return;
		}

		// For new posts, the taxonomy hasn't been assigned yet at the time this hook fires.
		// Get the space ID from the form data that SureDash submits.
		$space_id = 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by SureDash
		if ( ! empty( $_POST['formData'] ) ) {
			// SureDash sends form data as a JSON string in $_POST['formData'].
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by SureDash, data validated below
			$form_data = json_decode( wp_unslash( $_POST['formData'] ), true );

			if ( is_array( $form_data ) ) {
				// Check for space selection (portal ID).
				if ( ! empty( $form_data['custom_post_space_selection'] ) ) {
					$space_id = absint( $form_data['custom_post_space_selection'] );
				} elseif ( ! empty( $form_data['custom_post_tax_id'] ) ) {
					// Check for direct taxonomy term ID.
					// Need to find the portal with this feed_group_id.
					$term_id = absint( $form_data['custom_post_tax_id'] );
					$portals = get_posts(
						array(
							'post_type'      => 'portal',
							'posts_per_page' => 1,
							'post_status'    => 'any',
							// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to find portal by feed_group_id
							'meta_query'     => array(
								array(
									'key'     => 'feed_group_id',
									'value'   => $term_id,
									'compare' => '=',
								),
							),
						)
					);
					if ( ! empty( $portals ) ) {
						$space_id = absint( $portals[0]->ID );
					}
				}
			}
		}

		// Fallback: Try to get space from post (for existing posts or edge cases).
		if ( ! $space_id ) {
			$space_id = $this->get_space_id_from_post( $post_id );
		}

		if ( ! $space_id ) {
			return;
		}

		// Process engagement event.
		$this->process_engagement_event(
			$user_id,
			$space_id,
			'post_created',
			array(
				'post_id' => $post_id,
			)
		);
	}

	/**
	 * Handle comment added
	 *
	 * Triggered when a user adds a comment to a post.
	 *
	 * @since 0.0.3
	 *
	 * @param int $comment_id Comment ID.
	 * @param int $current_user_id User ID.
	 * @return void
	 */
	public function handle_comment_added( $comment_id, $current_user_id ) {
		$comment_id = absint( $comment_id );
		$user_id    = absint( $current_user_id );

		if ( ! $user_id || ! $comment_id ) {
			return;
		}

		// Get post ID from comment.
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		$post_id = absint( $comment->comment_post_ID );

		if ( ! $post_id ) {
			return;
		}

		// Get the space (portal) ID for this post.
		$space_id = $this->get_space_id_from_post( $post_id );

		if ( ! $space_id ) {
			return;
		}

		// Process engagement event.
		$this->process_engagement_event(
			$user_id,
			$space_id,
			'comment_added',
			array(
				'comment_id' => $comment_id,
				'post_id'    => $post_id,
			)
		);
	}

	/**
	 * Handle bookmark action
	 *
	 * Triggered when a user bookmarks content.
	 *
	 * @since 0.0.3
	 *
	 * @param int    $item_id Item ID (post ID).
	 * @param string $item_type Item type.
	 * @param string $status Bookmark status ('bookmarked' or 'unbookmarked').
	 * @param int    $user_id User ID.
	 * @return void
	 */
	public function handle_bookmark( $item_id, $item_type, $status, $user_id ) {
		$item_id = absint( $item_id );
		$user_id = absint( $user_id );

		if ( ! $user_id || ! $item_id ) {
			return;
		}

		// Only track bookmarks (not unbookmarks).
		if ( 'bookmarked' !== $status ) {
			return;
		}

		// Get the space (portal) ID for this item.
		$space_id = $this->get_space_id_from_post( $item_id );

		if ( ! $space_id ) {
			return;
		}

		// Process engagement event.
		$this->process_engagement_event(
			$user_id,
			$space_id,
			'bookmarked',
			array(
				'item_id'   => $item_id,
				'item_type' => $item_type,
			)
		);
	}

	/**
	 * Handle reaction/like
	 *
	 * Triggered when a user reacts to or likes content.
	 *
	 * @since 0.0.3
	 *
	 * @param int    $entity_id Entity ID (post or comment ID).
	 * @param string $entity_type Entity type ('post' or 'comment').
	 * @param string $like_status Like status ('liked' or 'unliked').
	 * @param int    $current_user_id User ID.
	 * @return void
	 */
	public function handle_reaction( $entity_id, $entity_type, $like_status, $current_user_id ) {
		$entity_id = absint( $entity_id );
		$user_id   = absint( $current_user_id );

		if ( ! $user_id || ! $entity_id ) {
			return;
		}

		// Only track likes (not unlikes).
		if ( 'liked' !== $like_status ) {
			return;
		}

		// Determine post ID based on entity type.
		if ( 'comment' === $entity_type ) {
			// If it's a comment, get the post ID from the comment.
			$comment = get_comment( $entity_id );
			if ( ! $comment instanceof \WP_Comment ) {
				return;
			}
			$post_id = absint( $comment->comment_post_ID );
		} else {
			// It's a post.
			$post_id = $entity_id;
		}

		// Get the space (portal) ID for this post.
		$space_id = $this->get_space_id_from_post( $post_id );

		if ( ! $space_id ) {
			return;
		}

		// Process engagement event.
		$this->process_engagement_event(
			$user_id,
			$space_id,
			'reacted',
			array(
				'entity_id'   => $entity_id,
				'entity_type' => $entity_type,
				'post_id'     => $post_id,
			)
		);
	}

	/**
	 * Handle lesson completed
	 *
	 * Triggered when a user completes a lesson in a course.
	 * Requires SureDash Pro.
	 *
	 * @since 0.0.3
	 *
	 * @param int $lesson_id Lesson ID.
	 * @param int $course_id Course ID.
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function handle_lesson_completed( $lesson_id, $course_id, $user_id ) {
		$lesson_id = absint( $lesson_id );
		$course_id = absint( $course_id );
		$user_id   = absint( $user_id );

		if ( ! $user_id || ! $course_id || ! $lesson_id ) {
			return;
		}

		// Process course event.
		$this->process_course_event(
			$user_id,
			$course_id,
			'lesson_completed',
			array(
				'lesson_id' => $lesson_id,
			)
		);
	}

	/**
	 * Handle course completed
	 *
	 * Triggered when a user completes all lessons in a course.
	 * Requires SureDash Pro.
	 *
	 * @since 0.0.3
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function handle_course_completed( $course_id, $user_id ) {
		$course_id = absint( $course_id );
		$user_id   = absint( $user_id );

		if ( ! $user_id || ! $course_id ) {
			return;
		}

		// Process course event.
		$this->process_course_event( $user_id, $course_id, 'course_completed', array() );
	}

	/**
	 * Process engagement event
	 *
	 * Core method that handles all engagement events by applying configured lists/tags.
	 *
	 * @since 0.0.3
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param int    $space_id Space (portal) ID.
	 * @param string $event Event type.
	 * @param array  $context Additional context data.
	 * @return void
	 */
	private function process_engagement_event( $user_id, $space_id, $event, $context = array() ) {
		// Get or create contact.
		$contact_id = $this->get_or_create_contact_from_user( $user_id, $space_id, $event );

		if ( ! $contact_id ) {
			return;
		}

		// Get the integration actions based on priority (specific > all > global).
		$actions = $this->get_integration_actions( $space_id, $event );

		// Apply "add" actions.
		if ( ! empty( $actions['add_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $actions['add_lists'] );
			$this->apply_or_remove_lists( $contact_id, $list_uuids, 'attach' );
		}

		if ( ! empty( $actions['add_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $actions['add_tags'] );
			$this->apply_or_remove_tags( $contact_id, $tag_uuids, 'apply' );
		}

		// Apply "remove" actions.
		if ( ! empty( $actions['remove_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $actions['remove_lists'] );
			$this->apply_or_remove_lists( $contact_id, $list_uuids, 'detach' );
		}

		if ( ! empty( $actions['remove_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $actions['remove_tags'] );
			$this->apply_or_remove_tags( $contact_id, $tag_uuids, 'remove' );
		}
	}

	/**
	 * Process course event
	 *
	 * Core method that handles course completion events by applying configured lists/tags.
	 * Requires SureDash Pro.
	 *
	 * @since 0.0.3
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param int    $course_id Course (portal) ID.
	 * @param string $event Event type (lesson_completed or course_completed).
	 * @param array  $context Additional context data.
	 * @return void
	 */
	private function process_course_event( $user_id, $course_id, $event, $context = array() ) {
		// Get or create contact.
		$contact_id = $this->get_or_create_contact_from_user( $user_id, $course_id, $event );

		if ( ! $contact_id ) {
			return;
		}

		// Get the integration actions based on priority (specific course > all courses > global).
		$actions = $this->get_course_actions( $course_id, $event );

		// Apply "add" actions.
		if ( ! empty( $actions['add_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $actions['add_lists'] );
			$this->apply_or_remove_lists( $contact_id, $list_uuids, 'attach' );
		}

		if ( ! empty( $actions['add_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $actions['add_tags'] );
			$this->apply_or_remove_tags( $contact_id, $tag_uuids, 'apply' );
		}

		// Apply "remove" actions.
		if ( ! empty( $actions['remove_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $actions['remove_lists'] );
			$this->apply_or_remove_lists( $contact_id, $list_uuids, 'detach' );
		}

		if ( ! empty( $actions['remove_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $actions['remove_tags'] );
			$this->apply_or_remove_tags( $contact_id, $tag_uuids, 'remove' );
		}
	}

	/**
	 * Get space ID from post
	 *
	 * Determines which community space a post belongs to.
	 *
	 * @since 0.0.3
	 *
	 * @param int $post_id Post ID.
	 * @return int|null Space ID or null if not found.
	 */
	private function get_space_id_from_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		// For community posts, the parent portal is the space.
		if ( 'community-post' === $post->post_type ) {
			// Community posts are associated with portals via the community-forum taxonomy.
			// The portal stores feed_group_id which points to the term ID.
			$terms = wp_get_post_terms( $post_id, 'community-forum' );

			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$term_id = $terms[0]->term_id;

				// Reverse lookup: Find portal where feed_group_id equals this term ID.
				$portals = get_posts(
					array(
						'post_type'      => 'portal',
						'posts_per_page' => 1,
						'post_status'    => 'any',
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to find portal by feed_group_id
						'meta_query'     => array(
							array(
								'key'     => 'feed_group_id',
								'value'   => $term_id,
								'compare' => '=',
							),
						),
					)
				);

				if ( ! empty( $portals ) ) {
					return absint( $portals[0]->ID );
				}
			}

			// Fallback: check post parent.
			if ( $post->post_parent ) {
				return absint( $post->post_parent );
			}
		}

		// For portal posts themselves.
		if ( 'portal' === $post->post_type ) {
			return absint( $post_id );
		}

		// For community content (lessons, etc.).
		if ( 'community-content' === $post->post_type && $post->post_parent ) {
			return absint( $post->post_parent );
		}

		return null;
	}

	/**
	 * Get or create contact from WordPress user
	 *
	 * @since 0.0.3
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param int    $space_id Space ID.
	 * @param string $event Event type.
	 * @return string|null Contact ID or null on failure.
	 */
	private function get_or_create_contact_from_user( $user_id, $space_id, $event ) {
		// Try to get existing contact.
		$contact_id = $this->contact_service->get_contact_id_by_user( $user_id );

		// Contact exists, return it.
		if ( $contact_id ) {
			return $contact_id;
		}

		// Get WordPress user data.
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			Logger::error( 'SureDash Integration', "User {$user_id} not found" );
			return null;
		}

		// Get first name with fallback to nickname.
		$first_name = get_user_meta( $user_id, 'first_name', true );
		if ( empty( $first_name ) ) {
			// Fallback to nickname (which is required in WordPress).
			$first_name = get_user_meta( $user_id, 'nickname', true );
		}
		if ( empty( $first_name ) ) {
			// Last resort: use display name.
			$first_name = $user->display_name;
		}

		// Get last name (optional).
		$last_name = get_user_meta( $user_id, 'last_name', true );

		// Prepare primary fields.
		$primary_fields = array(
			'email'      => $user->user_email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
		);

		// Build CRM data structure.
		$metadata = array(
			'source'          => 'suredash_' . $event,
			'user_id'         => $user_id,
			'space_id'        => $space_id,
			'user_login'      => $user->user_login,
			'user_registered' => $user->user_registered,
		);

		$mapped_data = $this->build_crm_data(
			$primary_fields,
			array(),
			$metadata
		);

		// Create contact.
		$result = $this->contact_service->create_contact( $mapped_data, $user_id );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		// Return contact ID.
		if ( isset( $result['contact_id'] ) ) {
			return $result['contact_id'];
		}

		return isset( $result['uuid'] ) ? $result['uuid'] : null;
	}

	/**
	 * Get integration actions based on priority: Specific Space > All Spaces.
	 *
	 * @since 0.0.3
	 *
	 * @param int    $space_id Space ID.
	 * @param string $event Event name.
	 * @return array Array of actions (add_lists, add_tags, remove_lists, remove_tags).
	 */
	private function get_integration_actions( $space_id, $event ) {
		$actions = array(
			'add_lists'    => array(),
			'add_tags'     => array(),
			'remove_lists' => array(),
			'remove_tags'  => array(),
		);

		// Priority 1: Specific Space Settings.
		if ( ! empty( $space_id ) ) {
			// Check for specific space config with event.
			$space_result = $this->integrations_db->get( $this->slug, (string) $space_id, 'space', $event );

			// Fallback to null event if not found.
			if ( ! $this->has_valid_config( $space_result ) ) {
				$space_result = $this->integrations_db->get( $this->slug, (string) $space_id, 'space', null );
			}

			if ( $this->has_valid_config( $space_result ) && isset( $space_result['config'] ) ) {
				return $this->merge_config_defaults( $space_result['config'] );
			}
		}

		// Priority 2: All Spaces.
		$all_spaces_result = $this->integrations_db->get( $this->slug, 'all', 'space', $event );

		// Fallback to null event if not found.
		if ( ! $this->has_valid_config( $all_spaces_result ) ) {
			$all_spaces_result = $this->integrations_db->get( $this->slug, 'all', 'space', null );
		}

		if ( $this->has_valid_config( $all_spaces_result ) && isset( $all_spaces_result['config'] ) ) {
			return $this->merge_config_defaults( $all_spaces_result['config'] );
		}

		return $actions;
	}

	/**
	 * Get course actions based on priority: Specific Course > All Courses.
	 *
	 * @since 0.0.3
	 *
	 * @param int    $course_id Course ID.
	 * @param string $event Event name.
	 * @return array Array of actions (add_lists, add_tags, remove_lists, remove_tags).
	 */
	private function get_course_actions( $course_id, $event ) {
		$actions = array(
			'add_lists'    => array(),
			'add_tags'     => array(),
			'remove_lists' => array(),
			'remove_tags'  => array(),
		);

		// Priority 1: Specific Course Settings.
		if ( ! empty( $course_id ) ) {
			// Check for specific course config with event.
			$course_result = $this->integrations_db->get( $this->slug, (string) $course_id, 'course', $event );

			// Fallback to null event if not found.
			if ( ! $this->has_valid_config( $course_result ) ) {
				$course_result = $this->integrations_db->get( $this->slug, (string) $course_id, 'course', null );
			}

			if ( $this->has_valid_config( $course_result ) && isset( $course_result['config'] ) ) {
				return $this->merge_config_defaults( $course_result['config'] );
			}
		}

		// Priority 2: All Courses.
		$all_courses_result = $this->integrations_db->get( $this->slug, 'all', 'course', $event );

		// Fallback to null event if not found.
		if ( ! $this->has_valid_config( $all_courses_result ) ) {
			$all_courses_result = $this->integrations_db->get( $this->slug, 'all', 'course', null );
		}

		if ( $this->has_valid_config( $all_courses_result ) && isset( $all_courses_result['config'] ) ) {
			return $this->merge_config_defaults( $all_courses_result['config'] );
		}

		return $actions;
	}

	/**
	 * Get spaces list.
	 *
	 * Returns a list of all SureDash community spaces for the admin UI.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of space items with 'id', 'title', and 'type' keys.
	 */
	public function get_spaces() {
		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Spaces', 'surecontact' ),
				'type'  => 'space',
			),
		);

		// Get all portal spaces (custom post type: portal).
		$args = array(
			'post_type'      => 'portal',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$spaces = get_posts( $args );

		if ( empty( $spaces ) ) {
			return $items;
		}

		foreach ( $spaces as $space ) {
			$items[] = array(
				'id'    => $space->ID,
				'title' => $space->post_title,
				'type'  => 'space',
			);
		}

		return $items;
	}

	/**
	 * Get courses list.
	 *
	 * Returns a list of all SureDash courses for the admin UI.
	 * Requires SureDash Pro to be active.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of course items with 'id', 'title', and 'type' keys.
	 */
	public function get_courses() {
		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Courses', 'surecontact' ),
				'type'  => 'course',
			),
		);

		// Get all portal posts that are courses (portals with course integration).
		$args = array(
			'post_type'      => 'portal',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to filter courses by integration type
			'meta_query'     => array(
				array(
					'key'     => 'integration',
					'value'   => 'course',
					'compare' => '=',
				),
			),
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$courses = get_posts( $args );

		if ( empty( $courses ) ) {
			return $items;
		}

		foreach ( $courses as $course ) {
			$items[] = array(
				'id'    => $course->ID,
				'title' => $course->post_title,
				'type'  => 'course',
			);
		}

		return $items;
	}

	/**
	 * Get item title by type and ID.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id Item ID.
	 * @param string $item_type Item type ('space' or 'course').
	 * @return string|null Item title or null if not found.
	 */
	public function get_item_title( $item_id, $item_type ) {
		if ( 'space' === $item_type ) {
			if ( 'all' === $item_id ) {
				return __( 'All Spaces', 'surecontact' );
			}

			$space = get_post( (int) $item_id );

			if ( ! $space instanceof \WP_Post || 'portal' !== $space->post_type ) {
				return null;
			}

			return $space->post_title;
		}

		if ( 'course' === $item_type ) {
			if ( 'all' === $item_id ) {
				return __( 'All Courses', 'surecontact' );
			}

			$course = get_post( (int) $item_id );

			if ( ! $course instanceof \WP_Post || 'portal' !== $course->post_type ) {
				return null;
			}

			return $course->post_title;
		}

		return null;
	}
}
