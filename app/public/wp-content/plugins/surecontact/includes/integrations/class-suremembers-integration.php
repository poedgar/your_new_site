<?php
/**
 * SureMembers Integration
 *
 * Handles SureMembers access group membership changes with rule-based lists and tags
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
 * Class SureMembers_Integration
 *
 * Integrates SureMembers with SureContact for membership tracking
 *
 * Features:
 * - Sync contacts when access groups are granted or revoked
 * - Per-access-group lists and tags configuration
 * - Support for WooCommerce, SureCart, and manual access grants
 * - Track integration source (woocommerce, surecart, manual)
 * - Expiration date syncing to custom fields
 *
 * @since 0.0.3
 */
class SureMembers_Integration extends Base_Integration {

	// Use the database helper trait for item-specific configurations.
	use Integration_DB_Helper;

	/**
	 * Constructor
	 *
	 * @since 0.0.3
	 */
	public function __construct() {
		$this->slug        = 'suremembers';
		$this->name        = 'SureMembers';
		$this->description = __( 'Sync SureMembers access group memberships and track member lifecycle events', 'surecontact' );
		$this->docs_url    = '';
		$this->dependency  = 'SureMembers\Inc\Access';

		parent::__construct();
	}

	/**
	 * Add SureMembers field groups
	 *
	 * @since 0.0.3
	 *
	 * @param array $groups Existing field groups.
	 * @return array Modified field groups.
	 */
	public function add_meta_field_group( $groups ) {
		$groups['suremembers'] = array(
			'title' => __( 'SureMembers', 'surecontact' ),
			'url'   => '',
		);

		return $groups;
	}

	/**
	 * Add SureMembers-specific fields
	 *
	 * @since 0.0.3
	 *
	 * @param array $fields Existing meta fields.
	 * @return array Modified meta fields.
	 */
	public function add_meta_fields( $fields ) {
		// SureMembers membership fields.
		$suremembers_fields = array(
			'sm_membership_status'     => array(
				'label' => __( 'Membership Status', 'surecontact' ),
				'type'  => 'text',
				'group' => 'suremembers',
			),
			'sm_access_group'          => array(
				'label' => __( 'Membership Name', 'surecontact' ),
				'type'  => 'text',
				'group' => 'suremembers',
			),
			'sm_access_group_id'       => array(
				'label' => __( 'Access Group ID', 'surecontact' ),
				'type'  => 'number',
				'group' => 'suremembers',
			),
			'sm_active_groups_count'   => array(
				'label' => __( 'Active Groups Count', 'surecontact' ),
				'type'  => 'number',
				'group' => 'suremembers',
			),
			'sm_status'                => array(
				'label' => __( 'Access Status', 'surecontact' ),
				'type'  => 'text',
				'group' => 'suremembers',
			),
			'sm_granted_date'          => array(
				'label' => __( 'Access Granted Date', 'surecontact' ),
				'type'  => 'date',
				'group' => 'suremembers',
			),
			'sm_modified_date'         => array(
				'label' => __( 'Access Modified Date', 'surecontact' ),
				'type'  => 'date',
				'group' => 'suremembers',
			),
			'sm_expiration_date'       => array(
				'label' => __( 'Expiration Date', 'surecontact' ),
				'type'  => 'date',
				'group' => 'suremembers',
			),
			'sm_days_until_expiration' => array(
				'label' => __( 'Days Until Expiration', 'surecontact' ),
				'type'  => 'number',
				'group' => 'suremembers',
			),
			'sm_integration_source'    => array(
				'label' => __( 'Integration Source', 'surecontact' ),
				'type'  => 'text',
				'group' => 'suremembers',
			),
			'sm_wc_order_ids'          => array(
				'label' => __( 'WooCommerce Order IDs', 'surecontact' ),
				'type'  => 'text',
				'group' => 'suremembers',
			),
			'sm_days_as_member'        => array(
				'label' => __( 'Days as Member', 'surecontact' ),
				'type'  => 'number',
				'group' => 'suremembers',
			),
			'sm_last_login'            => array(
				'label' => __( 'Last Login', 'surecontact' ),
				'type'  => 'date',
				'group' => 'suremembers',
			),
		);

		// Merge with existing fields.
		foreach ( $suremembers_fields as $key => $config ) {
			$fields[ $key ] = $config;
		}

		return $fields;
	}

	/**
	 * Get integration-specific global settings fields
	 *
	 * SureMembers integration does not use global settings.
	 * All configuration is done per access group or for "All Access Groups".
	 *
	 * @since 0.0.3
	 *
	 * @return array Empty array - no global settings needed.
	 */
	public function get_settings_fields() {
		return array();
	}

	/**
	 * Get all available item types for SureMembers.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of item type definitions with 'key' and 'label' keys.
	 */
	public function get_item_types() {
		return array(
			array(
				'key'   => 'access_group',
				'label' => __( 'Membership', 'surecontact' ),
			),
		);
	}

	/**
	 * Get available events for a specific item type.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_type Item type (e.g., 'access_group').
	 * @return array Array of event definitions with 'key' and 'label' keys.
	 */
	public function get_events_by_item_type( $item_type ) {
		switch ( $item_type ) {
			case 'access_group':
				return array(
					array(
						'key'   => 'granted',
						'label' => __( 'Access Granted', 'surecontact' ),
					),
					array(
						'key'   => 'revoked',
						'label' => __( 'Access Revoked', 'surecontact' ),
					),
					array(
						'key'   => 'expired',
						'label' => __( 'Access Expired', 'surecontact' ),
					),
					array(
						'key'   => 'logged_in',
						'label' => __( 'Member Logged In', 'surecontact' ),
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
	 * @param string      $item_id Access group ID.
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
	 * SureMembers access groups don't have mappable fields.
	 * Membership data is tracked via lists/tags and custom fields.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id Access group ID.
	 * @return array Empty array (no mappable fields for access groups).
	 */
	public function get_item_fields( $item_id ) {
		// Access groups don't have mappable fields.
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
		// Check SureMembers version for compatibility.
		if ( $this->is_suremembers_v2() ) {
			// SureMembers 2.0+ hooks.
			add_action( 'suremembers_after_access_grant', array( $this, 'handle_access_granted_v2' ), 10, 2 );
			add_action( 'suremembers_after_access_revoke', array( $this, 'handle_access_revoked_v2' ), 10, 2 );
		} else {
			// Legacy SureMembers 1.x hooks.
			add_action( 'suremembers_user_access_group_granted', array( $this, 'handle_access_granted' ), 10, 3 );
			add_action( 'suremembers_user_access_group_revoked', array( $this, 'handle_access_revoked' ), 10, 3 );
		}

		// Hook for user login (track member engagement).
		add_action( 'wp_login', array( $this, 'handle_login' ), 10, 2 );

		// Hook for the cron job to check for expired memberships.
		add_action( 'surecontact_suremembers_check_expirations', array( $this, 'check_for_expired_memberships' ) );

		// Schedule the cron job once (avoid per-request DB query).
		add_action( 'admin_init', array( $this, 'maybe_schedule_expiration_check' ) );
	}

	/**
	 * Check if SureMembers version 2.0 or higher is installed.
	 *
	 * @since 0.0.3
	 *
	 * @return bool True if SureMembers 2.0+ is installed.
	 */
	private function is_suremembers_v2() {
		if ( defined( 'SUREMEMBERS_VER' ) ) {
			return version_compare( SUREMEMBERS_VER, '2.0.0', '>=' );
		}
		return false;
	}

	/**
	 * Handle access granted (SureMembers 2.0+)
	 *
	 * Triggered when a user is granted access to access groups.
	 * In v2, this receives an array of all access group IDs that were granted.
	 *
	 * @since 0.0.3
	 *
	 * @param int   $user_id          WordPress user ID.
	 * @param array $access_group_ids Array of access group IDs that were granted.
	 * @return void
	 */
	public function handle_access_granted_v2( $user_id, $access_group_ids ) {
		if ( empty( $access_group_ids ) || ! is_array( $access_group_ids ) ) {
			return;
		}

		// Process each access group that was granted.
		foreach ( $access_group_ids as $access_group_id ) {
			$this->handle_access_granted( $user_id, $access_group_id, $access_group_ids );
		}
	}

	/**
	 * Handle access revoked (SureMembers 2.0+)
	 *
	 * Triggered when a user loses access to access groups.
	 * In v2, this receives an array of all access group IDs that were revoked.
	 *
	 * @since 0.0.3
	 *
	 * @param int   $user_id          WordPress user ID.
	 * @param array $access_group_ids Array of access group IDs that were revoked.
	 * @return void
	 */
	public function handle_access_revoked_v2( $user_id, $access_group_ids ) {
		if ( empty( $access_group_ids ) || ! is_array( $access_group_ids ) ) {
			return;
		}

		// Get the user's remaining access groups after revocation.
		$remaining_access_groups = get_user_meta( $user_id, 'suremembers_user_access_group', true );
		if ( ! is_array( $remaining_access_groups ) ) {
			$remaining_access_groups = array();
		}

		// Process each access group that was revoked.
		foreach ( $access_group_ids as $access_group_id ) {
			$this->handle_access_revoked( $user_id, $access_group_id, $remaining_access_groups );
		}
	}

	/**
	 * Schedule the expiration check job if not already scheduled.
	 *
	 * Prefers Action Scheduler (consistent with the rest of the plugin) and
	 * migrates any legacy wp-cron schedule on the same hook to AS on first
	 * run after upgrade. Falls back to wp-cron when AS is unavailable so the
	 * job is never silently skipped.
	 *
	 * Runs on admin_init to avoid per-request DB queries on the frontend.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function maybe_schedule_expiration_check() {
		$hook = 'surecontact_suremembers_check_expirations';

		if ( function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
			// One-time migration: clear any legacy wp-cron schedule for this hook
			// so it doesn't fire alongside the new AS-driven schedule.
			if ( wp_next_scheduled( $hook ) ) {
				wp_clear_scheduled_hook( $hook );
			}

			if ( ! as_next_scheduled_action( $hook ) ) {
				as_schedule_recurring_action( time(), HOUR_IN_SECONDS, $hook, array(), 'surecontact' );
			}

			return;
		}

		// Fallback: wp-cron when Action Scheduler is unavailable.
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), 'hourly', $hook );
		}
	}

	/**
	 * Handle access granted
	 *
	 * Triggered when a user is granted access to an access group.
	 *
	 * @since 0.0.3
	 *
	 * @param int   $user_id           WordPress user ID.
	 * @param int   $access_group_id   Access group ID that was granted.
	 * @param array $access_group_ids  All access group IDs user now has.
	 * @return void
	 */
	public function handle_access_granted( $user_id, $access_group_id, $access_group_ids ) {
		// Clear expired-processed flag so future expirations can be detected again.
		delete_user_meta( $user_id, 'surecontact_sm_expired_processed_' . (int) $access_group_id );

		// Sync contact with membership fields (creates contact if new, updates if existing).
		$contact_id = $this->sync_membership_fields( $user_id, $access_group_id, 'granted' );

		if ( ! $contact_id ) {
			Logger::error( 'SureMembers Integration', "Failed to sync contact for user {$user_id}" );
			return;
		}

		// Get the integration actions based on priority (specific > all > global).
		$actions = $this->get_integration_actions( $access_group_id, 'granted' );
		$this->apply_configured_actions( $contact_id, $actions );
	}

	/**
	 * Handle access revoked
	 *
	 * Triggered when a user loses access to an access group.
	 *
	 * @since 0.0.3
	 *
	 * @param int   $user_id           WordPress user ID.
	 * @param int   $access_group_id   Access group ID that was revoked.
	 * @param array $access_group_ids  All access group IDs user still has.
	 * @return void
	 */
	public function handle_access_revoked( $user_id, $access_group_id, $access_group_ids ) {
		// Sync contact with updated membership fields.
		$contact_id = $this->sync_membership_fields( $user_id, $access_group_id, 'revoked' );

		if ( ! $contact_id ) {
			Logger::warning( 'SureMembers Integration', "Contact not found for user {$user_id}, skipping access revoked" );
			return;
		}

		// Get the integration actions for revoked event.
		$actions = $this->get_integration_actions( $access_group_id, 'revoked' );
		$this->apply_configured_actions( $contact_id, $actions );
	}

	/**
	 * Handle access expired
	 *
	 * Triggered when a user's access to an access group expires.
	 *
	 * @since 0.0.3
	 *
	 * @param int   $user_id           WordPress user ID.
	 * @param int   $access_group_id   Access group ID that expired.
	 * @param array $access_group_ids  All access group IDs user still has.
	 * @return void
	 */
	public function handle_access_expired( $user_id, $access_group_id, $access_group_ids ) {
		// Sync contact with updated membership fields.
		$contact_id = $this->sync_membership_fields( $user_id, $access_group_id, 'expired' );

		if ( ! $contact_id ) {
			Logger::warning( 'SureMembers Integration', "Contact not found for user {$user_id}, skipping access expired" );
			return;
		}

		// Get the integration actions for expired event.
		$actions = $this->get_integration_actions( $access_group_id, 'expired' );
		$this->apply_configured_actions( $contact_id, $actions );

		Logger::info( 'SureMembers Integration', "Processed expiration for user {$user_id}, access group {$access_group_id}" );
	}

	/**
	 * Handle user login
	 *
	 * Triggered when a user logs in. Processes login event for each active access group.
	 *
	 * @since 0.0.3
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       User object.
	 * @return void
	 */
	public function handle_login( $user_login, $user ) {
		// Check if SureMembers is active.
		if ( ! class_exists( 'SureMembers\Inc\Access' ) ) {
			return;
		}

		$user_id = $user->ID;

		// Get contact ID (don't create if doesn't exist - only track logins for existing contacts).
		$contact_id = $this->contact_service->get_contact_id_by_user( $user_id );

		if ( ! $contact_id ) {
			return;
		}

		// Get user's active access groups.
		$access_group_ids = get_user_meta( $user_id, 'suremembers_user_access_group', true );

		if ( empty( $access_group_ids ) || ! is_array( $access_group_ids ) ) {
			return;
		}

		// Process login for each active access group.
		foreach ( $access_group_ids as $access_group_id ) {
			// Get access group data to check status.
			$access_group_meta_key = 'suremembers_user_access_group_' . (int) $access_group_id;
			$access_group_data     = get_user_meta( $user_id, $access_group_meta_key, true );

			// Skip if no data or not active.
			if ( empty( $access_group_data ) || ! is_array( $access_group_data ) ) {
				continue;
			}

			if ( isset( $access_group_data['status'] ) && 'active' !== $access_group_data['status'] ) {
				continue;
			}

			// Get the integration actions for logged_in event.
			$actions = $this->get_integration_actions( $access_group_id, 'logged_in' );

			// Only process if there are configured actions.
			if ( empty( $actions['add_lists'] ) && empty( $actions['add_tags'] ) && empty( $actions['remove_lists'] ) && empty( $actions['remove_tags'] ) ) {
				continue;
			}

			$this->apply_configured_actions( $contact_id, $actions );

			Logger::info( 'SureMembers Integration', "Processed login for user {$user_id}, access group {$access_group_id}" );
		}

		// Update last login timestamp in CRM.
		$raw_data = array(
			'user_email'    => $user->user_email,
			'user_id'       => $user_id,
			'sm_last_login' => gmdate( 'Y-m-d H:i:s' ),
		);

		$mapped_data = $this->normalize_data( $raw_data );
		$this->contact_service->create_contact( $mapped_data, $user_id, array( 'source' => $this->slug ) );
	}

	/**
	 * Check for expired memberships
	 *
	 * This method is called by a scheduled cron job to check for expired access groups.
	 * It processes users whose access has expired and triggers the expired event.
	 *
	 * @since 0.0.3
	 *
	 * @return void
	 */
	public function check_for_expired_memberships() {
		// Check if SureMembers is active.
		if ( ! class_exists( 'SureMembers\Inc\Access' ) ) {
			Logger::warning( 'SureMembers Integration', 'SureMembers not found, skipping expiration check' );
			return;
		}

		global $wpdb;

		// Query users who have SureMembers access groups.
		$meta_key  = 'suremembers_user_access_group';
		$cache_key = 'suremembers_users_with_access_groups';
		$user_ids  = wp_cache_get( $cache_key, 'surecontact' );

		if ( false === $user_ids ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
					$meta_key
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			// Cache for 5 minutes since membership status can change.
			wp_cache_set( $cache_key, $user_ids ? $user_ids : array(), 'surecontact', 5 * MINUTE_IN_SECONDS );
		}

		if ( empty( $user_ids ) ) {
			Logger::info( 'SureMembers Integration', 'No users with access groups found during expiration check' );
			return;
		}

		$expired_count = 0;
		$current_time  = time();

		// Check each user's access groups for expiration.
		foreach ( $user_ids as $user_id ) {
			// Get all access groups for this user.
			$access_group_ids = get_user_meta( $user_id, $meta_key, true );

			if ( empty( $access_group_ids ) || ! is_array( $access_group_ids ) ) {
				continue;
			}

			// Check each access group for expiration.
			foreach ( $access_group_ids as $access_group_id ) {
				$access_group_meta_key = 'suremembers_user_access_group_' . (int) $access_group_id;
				$access_group_data     = get_user_meta( $user_id, $access_group_meta_key, true );

				// Skip if no data or already revoked.
				if ( empty( $access_group_data ) || ! is_array( $access_group_data ) ) {
					continue;
				}

				if ( isset( $access_group_data['status'] ) && 'revoked' === $access_group_data['status'] ) {
					continue;
				}

				// Check if there's an expiration date.
				if ( empty( $access_group_data['expiration'] ) ) {
					continue;
				}

				// Parse expiration timestamp.
				$expiration_timestamp = $this->parse_timestamp( $access_group_data['expiration'] );

				// Skip if expiration date is invalid or in the future.
				if ( false === $expiration_timestamp || $expiration_timestamp > $current_time ) {
					continue;
				}

				// Check if we've already processed this expiration.
				$processed_key = 'surecontact_sm_expired_processed_' . (int) $access_group_id;
				$is_processed  = get_user_meta( $user_id, $processed_key, true );

				if ( $is_processed ) {
					continue;
				}

				// Access has expired - process it.
				Logger::info(
					'SureMembers Integration',
					"Detected expired access for user {$user_id}, access group {$access_group_id}",
					array(
						'expiration_date' => gmdate( 'Y-m-d H:i:s', $expiration_timestamp ),
						'current_date'    => gmdate( 'Y-m-d H:i:s', $current_time ),
					)
				);

				// Trigger the expired event.
				$this->handle_access_expired( $user_id, $access_group_id, $access_group_ids );

				// Mark as processed to avoid duplicate processing.
				update_user_meta( $user_id, $processed_key, true );

				++$expired_count;
			}
		}

		if ( $expired_count > 0 ) {
			Logger::info( 'SureMembers Integration', "Processed {$expired_count} expired memberships" );
		} else {
			Logger::info( 'SureMembers Integration', 'No expired memberships found during check' );
		}
	}

	/**
	 * Sync membership custom fields to CRM
	 *
	 * Creates or updates a contact with membership data (status, expiration date, etc.).
	 * Uses normalize_data() to respect Contact Fields UI mapping, consistent with
	 * how WordPress, WooCommerce, and other integrations sync data.
	 *
	 * @since 0.0.3
	 *
	 * @param int    $user_id         WordPress user ID.
	 * @param int    $access_group_id Access group ID.
	 * @param string $event           Event type (granted/revoked/expired).
	 * @return string|null Contact UUID or null on failure.
	 */
	private function sync_membership_fields( $user_id, $access_group_id, $event ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return null;
		}

		// Get access group data from user meta.
		$access_group_meta_key = 'suremembers_user_access_group_' . (int) $access_group_id;
		$access_group_data     = get_user_meta( $user_id, $access_group_meta_key, true );

		// Get all active access groups.
		$all_access_groups = get_user_meta( $user_id, 'suremembers_user_access_group', true );

		// Build raw data with user info + sm_* keys for field mapping via normalize_data().
		// Includes primary fields so the SaaS can create or identify the contact,
		// and sm_* custom fields mapped via the Contact Fields UI config.
		$first_name = get_user_meta( $user_id, 'first_name', true );
		if ( empty( $first_name ) ) {
			$first_name = get_user_meta( $user_id, 'nickname', true );
		}
		if ( empty( $first_name ) ) {
			$first_name = $user->display_name;
		}

		$raw_data = array(
			'user_email'      => $user->user_email,
			'user_id'         => $user_id,
			'first_name'      => $first_name,
			'last_name'       => get_user_meta( $user_id, 'last_name', true ),
			'user_login'      => $user->user_login,
			'user_registered' => $user->user_registered,
		);

		// Membership status based on event.
		switch ( $event ) {
			case 'granted':
				$raw_data['sm_membership_status'] = 'active';
				break;
			case 'expired':
				$raw_data['sm_membership_status'] = 'expired';
				break;
			case 'revoked':
			default:
				$raw_data['sm_membership_status'] = 'revoked';
				break;
		}

		// Access group name and ID.
		$raw_data['sm_access_group_id'] = (int) $access_group_id;
		$access_group_name              = $this->get_access_group_title( $access_group_id );
		if ( $access_group_name ) {
			$raw_data['sm_access_group'] = $access_group_name;
		}

		// Active access groups count.
		$raw_data['sm_active_groups_count'] = is_array( $all_access_groups ) ? count( $all_access_groups ) : 0;

		// Access group specific data if available.
		if ( is_array( $access_group_data ) ) {
			// Status.
			if ( isset( $access_group_data['status'] ) ) {
				$raw_data['sm_status'] = sanitize_text_field( $access_group_data['status'] );
			}

			// Granted date.
			if ( isset( $access_group_data['created'] ) ) {
				$created_timestamp = $this->parse_timestamp( $access_group_data['created'] );
				if ( false !== $created_timestamp ) {
					$raw_data['sm_granted_date'] = gmdate( 'Y-m-d H:i:s', $created_timestamp );
				}
			}

			// Modified date.
			if ( isset( $access_group_data['modified'] ) ) {
				$modified_timestamp = $this->parse_timestamp( $access_group_data['modified'] );
				if ( false !== $modified_timestamp ) {
					$raw_data['sm_modified_date'] = gmdate( 'Y-m-d H:i:s', $modified_timestamp );
				}
			}

			// Expiration date.
			if ( ! empty( $access_group_data['expiration'] ) ) {
				$expiration_timestamp = $this->parse_timestamp( $access_group_data['expiration'] );
				if ( false !== $expiration_timestamp ) {
					$raw_data['sm_expiration_date'] = gmdate( 'Y-m-d H:i:s', $expiration_timestamp );

					// Calculate days until expiration.
					$days_until_expiration                = floor( ( $expiration_timestamp - time() ) / DAY_IN_SECONDS );
					$raw_data['sm_days_until_expiration'] = max( 0, $days_until_expiration );
				}
			}

			// Integration source (woocommerce, surecart, default/manual).
			if ( isset( $access_group_data['integration'] ) ) {
				$raw_data['sm_integration_source'] = sanitize_text_field( $access_group_data['integration'] );
			}

			// WooCommerce order IDs if available.
			if ( isset( $access_group_data['wc_order_ids'] ) && is_array( $access_group_data['wc_order_ids'] ) ) {
				$raw_data['sm_wc_order_ids'] = sanitize_text_field( implode( ', ', array_map( 'absint', $access_group_data['wc_order_ids'] ) ) );
			}
		}

		// Calculate days as member (if granted date exists).
		if ( isset( $raw_data['sm_granted_date'] ) ) {
			$granted_timestamp             = strtotime( $raw_data['sm_granted_date'] );
			$days_as_member                = floor( ( time() - $granted_timestamp ) / DAY_IN_SECONDS );
			$raw_data['sm_days_as_member'] = max( 0, $days_as_member );
		}

		// Map through Contact Fields UI config and send to CRM.
		$mapped_data = $this->normalize_data( $raw_data );
		$result      = $this->contact_service->create_contact( $mapped_data, $user_id, array( 'source' => $this->slug ) );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		return $result['contact_uuid'] ?? $result['contact_id'] ?? null;
	}

	/**
	 * Apply configured list and tag actions to a contact.
	 *
	 * @since 1.4.0
	 *
	 * @param string $contact_id Contact UUID.
	 * @param array  $actions    Array with add_lists, add_tags, remove_lists, remove_tags.
	 * @return void
	 */
	private function apply_configured_actions( $contact_id, $actions ) {
		if ( ! empty( $actions['add_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $actions['add_lists'] );
			$this->apply_or_remove_lists( $contact_id, $list_uuids, 'attach' );
		}

		if ( ! empty( $actions['add_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $actions['add_tags'] );
			$this->apply_or_remove_tags( $contact_id, $tag_uuids, 'apply' );
		}

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
	 * Parse a timestamp value that may be numeric or a date string.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $value Timestamp (int) or date string.
	 * @return int|false Unix timestamp or false on failure.
	 */
	private function parse_timestamp( $value ) {
		return is_numeric( $value ) ? (int) $value : strtotime( $value );
	}

	/**
	 * Get integration actions based on priority: Specific Access Group > All Access Groups.
	 *
	 * @since 0.0.3
	 *
	 * @param int    $access_group_id Access group ID.
	 * @param string $event           Event name ('granted', 'revoked').
	 * @return array Array of actions (add_lists, add_tags, remove_lists, remove_tags).
	 */
	private function get_integration_actions( $access_group_id, $event = 'granted' ) {
		$actions = array(
			'add_lists'    => array(),
			'add_tags'     => array(),
			'remove_lists' => array(),
			'remove_tags'  => array(),
		);

		// Priority 1: Specific Access Group Settings.
		if ( ! empty( $access_group_id ) ) {
			// Check for specific access group config with event.
			$access_group_result = $this->integrations_db->get( $this->slug, (string) $access_group_id, 'access_group', $event );

			// Fallback to null event if not found.
			if ( ! $this->has_valid_config( $access_group_result ) ) {
				$access_group_result = $this->integrations_db->get( $this->slug, (string) $access_group_id, 'access_group', null );
			}

			if ( $this->has_valid_config( $access_group_result ) && isset( $access_group_result['config'] ) ) {
				Logger::info( 'SureMembers Integration', "Applied settings from Access Group: {$access_group_id}" );
				return $this->merge_config_defaults( $access_group_result['config'] );
			}
		}

		// Priority 2: All Access Groups.
		$all_access_groups_result = $this->integrations_db->get( $this->slug, 'all', 'access_group', $event );

		// Fallback to null event if not found.
		if ( ! $this->has_valid_config( $all_access_groups_result ) ) {
			$all_access_groups_result = $this->integrations_db->get( $this->slug, 'all', 'access_group', null );
		}

		if ( $this->has_valid_config( $all_access_groups_result ) && isset( $all_access_groups_result['config'] ) ) {
			Logger::info( 'SureMembers Integration', 'Applied settings from "All Access Groups"' );
			return $this->merge_config_defaults( $all_access_groups_result['config'] );
		}

		// No configuration found - return empty actions.
		return $actions;
	}

	/**
	 * Get access groups list.
	 *
	 * Returns a list of all SureMembers access groups for the admin UI.
	 *
	 * @since 0.0.3
	 *
	 * @return array Array of access group items with 'id', 'title', and 'type' keys.
	 */
	public function get_access_groups() {
		// Check if SureMembers is active.
		if ( ! class_exists( 'SureMembers\Inc\Access' ) ) {
			return array();
		}

		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Memberships', 'surecontact' ),
				'type'  => 'access_group',
			),
		);

		// Get all memberships (custom post type: wsm_access_group).
		$args = array(
			'post_type'      => 'wsm_access_group',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		$access_groups = get_posts( $args );

		if ( empty( $access_groups ) ) {
			return $items;
		}

		foreach ( $access_groups as $access_group ) {
			$items[] = array(
				'id'    => $access_group->ID,
				'title' => $access_group->post_title,
				'type'  => 'access_group',
			);
		}

		return $items;
	}

	/**
	 * Get access group title by ID.
	 *
	 * @since 0.0.3
	 *
	 * @param int|string $access_group_id Access group ID.
	 * @return string|null Access group title or null if not found.
	 */
	private function get_access_group_title( $access_group_id ) {
		if ( 'all' === $access_group_id ) {
			return __( 'All Memberships', 'surecontact' );
		}

		$access_group = get_post( (int) $access_group_id );

		if ( ! $access_group instanceof \WP_Post || 'wsm_access_group' !== $access_group->post_type ) {
			return null;
		}

		return $access_group->post_title;
	}

	/**
	 * Get item title by type and ID.
	 *
	 * @since 0.0.3
	 *
	 * @param string $item_id   Item ID.
	 * @param string $item_type Item type ('access_group').
	 * @return string|null Item title or null if not found.
	 */
	public function get_item_title( $item_id, $item_type ) {
		if ( 'access_group' === $item_type ) {
			return $this->get_access_group_title( $item_id );
		}

		return null;
	}
}
