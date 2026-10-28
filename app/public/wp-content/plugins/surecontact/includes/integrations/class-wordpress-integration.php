<?php
/**
 * WordPress Core Integration
 *
 * Handles WordPress user registration, profile updates, and core user events.
 * This integration now manages ALL WordPress-specific hooks and data preparation.
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact\Integrations;

use SureContact\Logger;
use SureContact\Synced_Metadata;
use SureContact\Traits\Integration_DB_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WordPress_Integration
 *
 * Integrates WordPress core user functionality with SureContact.
 * Handles all WordPress user lifecycle events.
 *
 * @since 0.0.1
 */
class WordPress_Integration extends Base_Integration {

	use Integration_DB_Helper;

	/**
	 * WordPress User Sync handler instance.
	 *
	 * @since 1.2.0
	 *
	 * @var WordPress_User_Sync
	 */
	private $user_sync;

	/**
	 * Whether profile_update will fire (inside wp_insert_user).
	 * Role hooks skip when true to avoid duplicate API calls.
	 *
	 * @since 1.2.0
	 *
	 * @var bool
	 */
	private $profile_update_pending = false;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		$this->slug        = 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- This is a slug identifier, not the brand name.
		$this->name        = 'WordPress';
		$this->description = __( 'Sync WordPress users, registration, and profile updates', 'surecontact' );
		$this->docs_url    = '';
		$this->dependency  = 'wp_insert_user'; // Core WP function.

		parent::__construct();

		// Initialize bulk sync handler.
		$this->user_sync = new WordPress_User_Sync( $this, $this->contact_service );

		// Register sync type.
		add_filter( 'surecontact_available_sync_types', array( $this, 'register_sync_type' ) );
	}

	/**
	 * Get WordPress sync types
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of sync type definitions.
	 */
	public function get_sync_types() {
		return $this->user_sync->get_sync_types();
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 0.0.1
	 */
	protected function init() {
		// User registration.
		add_action( 'user_register', array( $this, 'handle_user_register' ), 20 );

		// User data sync — handles contact sync and role-based list/tag management.
		// Late priority (999) ensures third-party plugins (Meta Box, ACF, etc.)
		// have saved their custom fields to usermeta before we read them.
		add_action( 'profile_update', array( $this, 'handle_profile_update' ), 999, 2 );

		// Flag when wp_insert_user() is running so role hooks skip (profile_update handles it).
		add_filter( 'wp_pre_insert_user_data', array( $this, 'flag_profile_update_pending' ) );

		// Role-based list/tag management for standalone role changes only.
		// Skipped when profile_update will fire (flag is set).
		add_action( 'set_user_role', array( $this, 'handle_role_change' ), 10, 2 );
		add_action( 'add_user_role', array( $this, 'handle_role_change' ), 10, 2 );
		add_action( 'remove_user_role', array( $this, 'handle_role_removed' ), 10, 2 );

		// Page/post visit tracking.
		add_action( 'template_redirect', array( $this, 'handle_page_visit' ) );
	}

	/**
	 * Handle user registration
	 *
	 * @since 0.0.1
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function handle_user_register( $user_id ) {
		// Check if auto-create contacts is enabled first to avoid unnecessary processing.
		$all_settings  = get_option( 'surecontact_general_settings', array() );
		$sync_settings = isset( $all_settings['sync_settings'] ) ? $all_settings['sync_settings'] : array();
		if ( empty( $sync_settings['auto_create_contacts'] ) ) {
			return;
		}

		// Skip if this is being triggered by WooCommerce customer creation
		// The WooCommerce integration will handle the sync with more complete data.
		if ( doing_action( 'woocommerce_created_customer' ) ) {
			return;
		}

		$user_data = $this->get_user_data( $user_id );

		// Map to CRM format.
		$mapped_data = $this->normalize_data( $user_data );

		$this->send_to_crm( $mapped_data, $user_id );
	}

	/**
	 * Handle profile updates
	 *
	 * @since 0.0.1
	 *
	 * @param int      $user_id      User ID.
	 * @param \WP_User $old_user_data Old user data.
	 * @return void
	 */
	public function handle_profile_update( $user_id, $old_user_data ) {
		// Check if auto-sync updates is enabled first to avoid unnecessary processing.
		$all_settings  = get_option( 'surecontact_general_settings', array() );
		$sync_settings = isset( $all_settings['sync_settings'] ) ? $all_settings['sync_settings'] : array();
		if ( empty( $sync_settings['auto_sync_updates'] ) ) {
			return;
		}

		// Skip if this is running during user registration (user_register handles that).
		if ( doing_action( 'user_register' ) ) {
			return;
		}

		// Skip if this is running during WooCommerce checkout
		// The WooCommerce integration will handle the sync with order-specific data.
		if ( doing_action( 'woocommerce_checkout_order_processed' ) || doing_action( 'woocommerce_created_customer' ) ) {
			return;
		}

		// Check if WooCommerce is active and this is a customer role.
		if ( class_exists( 'WooCommerce' ) ) {
			$user = get_userdata( $user_id );

			// Skip if this update is happening during any WooCommerce order-related action.
			if ( doing_action( 'woocommerce_checkout_create_order' ) ||
				doing_action( 'woocommerce_checkout_update_order_meta' ) ||
				doing_action( 'woocommerce_new_order' ) ||
				doing_action( 'woocommerce_thankyou' ) ) {
				return;
			}

			// Check if this profile update was triggered by WooCommerce updating billing/shipping
			// WooCommerce updates user meta when processing checkout, which triggers profile_update
			// We detect this by checking if we're in a WooCommerce checkout context.
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only checking for presence of WooCommerce checkout markers, not processing data. WooCommerce validates its own nonces.
			if ( isset( $_POST['woocommerce-process-checkout-nonce'] ) ||
				isset( $_POST['woocommerce_checkout_place_order'] ) ||
				( isset( $_POST['payment_method'] ) && function_exists( 'WC' ) && WC()->session ) ) {
				// phpcs:enable WordPress.Security.NonceVerification.Missing
				// This is a WooCommerce checkout - let WooCommerce integration handle it.
				Logger::info( 'WordPress Integration', "Skipping profile_update for user {$user_id} - WooCommerce checkout detected" );
				return;
			}
		}

		// Check if email has changed - if so, update the contact's email first.
		$new_user = get_userdata( $user_id );
		if ( $new_user && $old_user_data && ! empty( $old_user_data->user_email ) && ! empty( $new_user->user_email ) ) {
			$old_email = $old_user_data->user_email;
			$new_email = $new_user->user_email;

			if ( strtolower( $old_email ) !== strtolower( $new_email ) ) {
				$email_update_result = $this->contact_service->update_email( $old_email, $new_email, $user_id, array( 'source' => $this->slug ) );

				// If email update fails, continue with normal sync - it will create a new contact if needed.
			}
		}

		$user_data = $this->get_user_data( $user_id );

		// Map to CRM format.
		$mapped_data = $this->normalize_data( $user_data );

		if ( empty( $mapped_data ) ) {
			return;
		}

		// Sync contact with current role-based lists/tags included.
		$result = $this->send_to_crm( $mapped_data, $user_id );

		// After successful sync, detach lists/tags for any removed roles.
		if ( ! is_wp_error( $result ) && is_array( $result ) && ! empty( $result['contact_uuid'] ) ) {
			$this->handle_removed_roles_after_sync( $result['contact_uuid'], $user_id, $old_user_data );
		}

		$this->profile_update_pending = false;
	}

	/**
	 * Set flag when wp_insert_user() is running.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data User data.
	 * @return array Unmodified data.
	 */
	public function flag_profile_update_pending( $data ) {
		$this->profile_update_pending = true;
		return $data;
	}

	/**
	 * Handle standalone role addition (set_user_role / add_user_role hooks)
	 *
	 * Only runs for standalone role changes (outside wp_update_user).
	 * When profile_update will fire, it handles everything.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role slug that was added.
	 * @return void
	 */
	public function handle_role_change( $user_id, $role ) {
		if ( $this->profile_update_pending ) {
			return;
		}

		$all_settings  = get_option( 'surecontact_general_settings', array() );
		$sync_settings = isset( $all_settings['sync_settings'] ) ? $all_settings['sync_settings'] : array();
		if ( empty( $sync_settings['auto_sync_updates'] ) ) {
			return;
		}

		$sync_as_lists = ! empty( $sync_settings['sync_roles_as_lists'] );
		$sync_as_tags  = ! empty( $sync_settings['sync_roles_as_tags'] );
		if ( ! $sync_as_lists && ! $sync_as_tags ) {
			return;
		}

		$contact_uuid = $this->contact_service->get_contact_id_by_user( $user_id );
		if ( ! $contact_uuid ) {
			return;
		}

		$role_items = $this->prepare_role_items_for_sync( array( $role ) );
		if ( empty( $role_items ) ) {
			return;
		}

		$role_names = implode( ', ', array_column( $role_items, 'name' ) );
		$context    = "added role ({$role_names})";

		if ( $sync_as_lists ) {
			$list_uuids = $this->get_role_uuids( $role_items, 'list' );
			if ( ! empty( $list_uuids ) ) {
				$this->apply_or_remove_lists( $contact_uuid, $list_uuids, 'attach' );
			}
		}

		if ( $sync_as_tags ) {
			$tag_uuids = $this->get_role_uuids( $role_items, 'tag' );
			if ( ! empty( $tag_uuids ) ) {
				$this->apply_or_remove_tags( $contact_uuid, $tag_uuids, 'apply' );
			}
		}
	}

	/**
	 * Handle standalone role removal (remove_user_role hook)
	 *
	 * Only runs for standalone role changes (outside wp_update_user).
	 * When profile_update will fire, it handles everything.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role slug that was removed.
	 * @return void
	 */
	public function handle_role_removed( $user_id, $role ) {
		if ( $this->profile_update_pending ) {
			return;
		}

		$all_settings  = get_option( 'surecontact_general_settings', array() );
		$sync_settings = isset( $all_settings['sync_settings'] ) ? $all_settings['sync_settings'] : array();
		if ( empty( $sync_settings['auto_sync_updates'] ) ) {
			return;
		}

		$sync_as_lists = ! empty( $sync_settings['sync_roles_as_lists'] );
		$sync_as_tags  = ! empty( $sync_settings['sync_roles_as_tags'] );
		if ( ! $sync_as_lists && ! $sync_as_tags ) {
			return;
		}

		$contact_uuid = $this->contact_service->get_contact_id_by_user( $user_id );
		if ( ! $contact_uuid ) {
			return;
		}

		$this->detach_role_lists_tags( $contact_uuid, array( $role ), $sync_as_lists, $sync_as_tags );
	}

	/**
	 * Handle removed roles after contact sync
	 *
	 * Compares old vs new roles and detaches lists/tags for any removed roles.
	 * Called AFTER send_to_crm so the contact exists before list/tag operations.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $contact_uuid  Contact UUID from the sync response.
	 * @param int      $user_id       User ID.
	 * @param \WP_User $old_user_data Old user data before the update.
	 * @return void
	 */
	private function handle_removed_roles_after_sync( $contact_uuid, $user_id, $old_user_data ) {
		$all_settings  = get_option( 'surecontact_general_settings', array() );
		$sync_settings = isset( $all_settings['sync_settings'] ) ? $all_settings['sync_settings'] : array();

		$sync_as_lists = ! empty( $sync_settings['sync_roles_as_lists'] );
		$sync_as_tags  = ! empty( $sync_settings['sync_roles_as_tags'] );
		if ( ! $sync_as_lists && ! $sync_as_tags ) {
			return;
		}

		$new_user = get_userdata( $user_id );
		if ( ! $new_user ) {
			return;
		}

		$old_roles     = $old_user_data ? (array) $old_user_data->roles : array();
		$new_roles     = (array) $new_user->roles;
		$removed_roles = array_diff( $old_roles, $new_roles );

		if ( empty( $removed_roles ) ) {
			return;
		}

		$this->detach_role_lists_tags( $contact_uuid, array_values( $removed_roles ), $sync_as_lists, $sync_as_tags );
	}

	/**
	 * Get user data for syncing.
	 *
	 * Used by both real-time hooks and bulk sync handler.
	 *
	 * @since 0.0.1
	 *
	 * @param int  $user_id           User ID.
	 * @param bool $include_post_data Whether to merge $_POST data. Default true for registration/profile hooks, pass false for frontend contexts like page visits.
	 * @return array User data.
	 */
	public function get_user_data( $user_id, $include_post_data = true ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return array();
		}

		// Get all user meta.
		$user_meta = get_user_meta( $user_id );

		// Flatten meta array (get first value).
		$meta_data = array();
		foreach ( $user_meta as $key => $value ) {
			$meta_data[ $key ] = is_array( $value ) && count( $value ) === 1 ? $value[0] : $value;
		}

		// Combine user object data with meta.
		$user_data = array(
			'user_login'      => $user->user_login,
			'user_email'      => $user->user_email,
			'first_name'      => $user->first_name,
			'last_name'       => $user->last_name,
			'user_url'        => $user->user_url,
			'user_registered' => $user->user_registered,
			'role'            => ! empty( $user->roles ) ? implode( ', ', $user->roles ) : '',
			// User locale - falls back to site locale if user hasn't set one.
			'locale'          => get_user_locale( $user_id ),
		);

		// Add user capabilities.
		if ( ! empty( $user->roles ) ) {
			$user_data['user_role']    = implode( ', ', $user->roles );
			$user_data['capabilities'] = implode( ', ', $user->allcaps ? array_keys( array_filter( $user->allcaps ) ) : array() );
		}

		// Merge with meta data.
		$user_data = array_merge( $meta_data, $user_data );

		// Add POST data if available (for registration forms with custom fields).
		// Skipped in frontend contexts (e.g., page visits) where $_POST may contain unrelated form data.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- This function is called during WordPress core hooks (user_register, profile_update) which are only triggered after WordPress core has already validated nonces in registration/profile forms.
		if ( $include_post_data && ! empty( $_POST ) ) {
			// Fields that should be sanitized as email addresses.
			$email_fields = array( 'user_email', 'billing_email' );

			// Fields that should be sanitized as URLs.
			$url_fields = array( 'user_url' );

			// Fields that should be sanitized as textarea (allowing line breaks).
			$textarea_fields = array( 'description' );

			// Process only relevant POST fields, excluding WordPress internal fields and sensitive data.
			$excluded_fields = array(
				'_wpnonce',
				'_wp_http_referer',
				'action',
				'redirect_to',
				'pwd',
				'pass1',
				'pass2',
				'user_pass',
				'password',
				'password_confirmation',
			);

			foreach ( $_POST as $field => $value ) {
				// Skip excluded fields.
				if ( in_array( $field, $excluded_fields, true ) ) {
					continue;
				}

				// Skip if field already exists in user_data (prioritize database values).
				if ( isset( $user_data[ $field ] ) ) {
					continue;
				}

				// Skip non-scalar values (arrays, objects) to avoid processing complex data.
				if ( ! is_scalar( $value ) ) {
					continue;
				}

				// Apply appropriate sanitization based on field type.
				if ( in_array( $field, $email_fields, true ) ) {
					$user_data[ $field ] = sanitize_email( wp_unslash( (string) $value ) );
				} elseif ( in_array( $field, $url_fields, true ) ) {
					$user_data[ $field ] = esc_url_raw( wp_unslash( (string) $value ) );
				} elseif ( in_array( $field, $textarea_fields, true ) ) {
					$user_data[ $field ] = sanitize_textarea_field( wp_unslash( (string) $value ) );
				} else {
					$user_data[ $field ] = sanitize_text_field( wp_unslash( (string) $value ) );
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $user_data;
	}

	/**
	 * WordPress integration is always enabled (it's the core system)
	 *
	 * @since 0.0.1
	 *
	 * @return bool
	 */
	protected function is_enabled() {
		return true;
	}

	/**
	 * Prepare lists, tags, and custom fields for WordPress user sync
	 *
	 * Consolidates all WordPress list/tag sources into a single process:
	 * 1. Role-based lists/tags (from user roles when sync_roles_as_lists/tags enabled)
	 * 2. Global assigned lists/tags (from sync_settings)
	 * 3. Context-specific lists/tags (passed by caller)
	 *
	 * @since 1.2.0
	 *
	 * @param array $data     Contact data.
	 * @param int   $user_id  User ID.
	 * @param array $context  Context with optional list_uuids, tag_uuids, custom_fields.
	 * @return array Modified contact data with lists, tags, and custom fields.
	 */
	protected function prepare_lists_and_tags( $data, $user_id, $context ) {
		$all_settings  = get_option( 'surecontact_general_settings', array() );
		$sync_settings = isset( $all_settings['sync_settings'] ) ? $all_settings['sync_settings'] : array();

		$list_uuids = array();
		$tag_uuids  = array();

		// 1. Role-based lists/tags.
		$role_context = $this->get_role_based_lists_tags_context( $user_id );
		if ( ! empty( $role_context['list_uuids'] ) ) {
			$list_uuids = array_merge( $list_uuids, $role_context['list_uuids'] );
		}
		if ( ! empty( $role_context['tag_uuids'] ) ) {
			$tag_uuids = array_merge( $tag_uuids, $role_context['tag_uuids'] );
		}

		// 2. Global assigned lists/tags from sync settings (raw, extracted in single pass below).
		if ( ! empty( $sync_settings['assigned_lists'] ) ) {
			$list_uuids = array_merge( $list_uuids, $sync_settings['assigned_lists'] );
		}

		if ( ! empty( $sync_settings['assigned_tags'] ) ) {
			$tag_uuids = array_merge( $tag_uuids, $sync_settings['assigned_tags'] );
		}

		// 3. Context-specific lists/tags (from caller).
		if ( ! empty( $context['list_uuids'] ) ) {
			$list_uuids = array_merge( $list_uuids, $context['list_uuids'] );
		}
		if ( ! empty( $context['tag_uuids'] ) ) {
			$tag_uuids = array_merge( $tag_uuids, $context['tag_uuids'] );
		}

		// Deduplicate and assign.
		$list_uuids = $this->extract_uuids( $list_uuids );
		if ( ! empty( $list_uuids ) ) {
			$data['list_uuids'] = $list_uuids;
		}

		$tag_uuids = $this->extract_uuids( $tag_uuids );
		if ( ! empty( $tag_uuids ) ) {
			$data['tag_uuids'] = $tag_uuids;
		}

		// Add custom fields from context if present.
		if ( ! empty( $context['custom_fields'] ) && is_array( $context['custom_fields'] ) ) {
			if ( ! isset( $data['custom_fields'] ) ) {
				$data['custom_fields'] = array();
			}
			$data['custom_fields'] = array_merge( $data['custom_fields'], $context['custom_fields'] );
		}

		return $data;
	}

	/**
	 * Get role-based lists and tags context for a user
	 *
	 * Builds a context array with list_uuids and tag_uuids based on user roles
	 * when the corresponding sync settings are enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Context array with list_uuids and/or tag_uuids.
	 */
	public function get_role_based_lists_tags_context( $user_id ) {
		$context = array();

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->roles ) ) {
			return $context;
		}

		$all_settings  = get_option( 'surecontact_general_settings', array() );
		$sync_settings = isset( $all_settings['sync_settings'] ) ? $all_settings['sync_settings'] : array();

		$sync_roles_as_lists = ! empty( $sync_settings['sync_roles_as_lists'] );
		$sync_roles_as_tags  = ! empty( $sync_settings['sync_roles_as_tags'] );

		// Early return if neither setting is enabled.
		if ( ! $sync_roles_as_lists && ! $sync_roles_as_tags ) {
			return $context;
		}

		// Prepare role items for syncing.
		$role_items = $this->prepare_role_items_for_sync( $user->roles );

		if ( empty( $role_items ) ) {
			return $context;
		}

		if ( $sync_roles_as_lists ) {
			$list_uuids = $this->get_role_uuids( $role_items, 'list' );
			if ( ! empty( $list_uuids ) ) {
				$context['list_uuids'] = $list_uuids;
			}
		}

		if ( $sync_roles_as_tags ) {
			$tag_uuids = $this->get_role_uuids( $role_items, 'tag' );
			if ( ! empty( $tag_uuids ) ) {
				$context['tag_uuids'] = $tag_uuids;
			}
		}

		return $context;
	}

	/**
	 * Detach role-based lists/tags from a contact
	 *
	 * Looks up existing UUIDs for the given roles (without creating missing ones)
	 * and detaches them using the base class helpers.
	 *
	 * @since 1.2.0
	 *
	 * @param string $contact_uuid  Contact UUID.
	 * @param array  $roles         Array of role slugs to detach.
	 * @param bool   $sync_as_lists Whether to detach from lists.
	 * @param bool   $sync_as_tags  Whether to detach from tags.
	 * @return void
	 */
	private function detach_role_lists_tags( $contact_uuid, $roles, $sync_as_lists, $sync_as_tags ) {
		$role_items = $this->prepare_role_items_for_sync( $roles );
		if ( empty( $role_items ) ) {
			return;
		}

		$role_names = implode( ', ', array_column( $role_items, 'name' ) );
		$context    = "removed role ({$role_names})";

		if ( $sync_as_lists ) {
			$list_uuids = $this->get_role_uuids( $role_items, 'list' );
			if ( ! empty( $list_uuids ) ) {
				$this->apply_or_remove_lists( $contact_uuid, $list_uuids, 'detach' );
			}
		}

		if ( $sync_as_tags ) {
			$tag_uuids = $this->get_role_uuids( $role_items, 'tag' );
			if ( ! empty( $tag_uuids ) ) {
				$this->apply_or_remove_tags( $contact_uuid, $tag_uuids, 'remove' );
			}
		}
	}

	/**
	 * Prepare role items array for syncing
	 *
	 * @since 1.0.0
	 *
	 * @param array $roles Array of role slugs.
	 * @return array Array of items with 'slug' and 'name'.
	 */
	private function prepare_role_items_for_sync( $roles ) {
		global $wp_roles;

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		$role_names = $wp_roles->get_names();
		$items      = array();

		foreach ( $roles as $role ) {
			$display_name = isset( $role_names[ $role ] )
				? translate_user_role( $role_names[ $role ] )
				: ucfirst( str_replace( '_', ' ', $role ) );

			$items[] = array(
				'slug' => $role,
				'name' => $display_name,
			);
		}

		return $items;
	}

	/**
	 * Get UUIDs for roles, finding existing or creating new lists/tags
	 *
	 * Searches in synced metadata by exact role display name.
	 * If found, uses the existing UUID. If not found, creates a new one
	 * and updates the synced metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $role_items Array of role items with 'slug' and 'name'.
	 * @param string $type       Type: 'list' or 'tag'.
	 * @return array Array of UUIDs.
	 */
	private function get_role_uuids( $role_items, $type ) {
		// Get existing synced metadata (single source of truth).
		$get_method    = 'get_' . $type . 's';
		$synced_items  = Synced_Metadata::$get_method();
		$found_uuids   = array();
		$missing_items = array();

		// First pass: find existing, collect missing.
		foreach ( $role_items as $item ) {
			$found_uuid = $this->find_uuid_by_name( $synced_items, $item['name'] );

			if ( $found_uuid ) {
				$found_uuids[] = $found_uuid;
			} else {
				$missing_items[] = $item;
			}
		}

		// Batch create all missing items in ONE API call.
		if ( ! empty( $missing_items ) ) {
			$items_for_api = array_map(
				function ( $item ) {
					return array(
						'id'   => $item['slug'],
						'name' => $item['name'],
					);
				},
				$missing_items
			);

			$created = $this->contact_service->sync_metadata( $items_for_api, $type );

			if ( ! empty( $created ) ) {
				foreach ( $missing_items as $item ) {
					$mapping_key = 'fc_' . $item['slug'];
					if ( isset( $created[ $mapping_key ] ) ) {
						$found_uuids[]  = $created[ $mapping_key ];
						$synced_items[] = array(
							'uuid' => $created[ $mapping_key ],
							'name' => $item['name'],
						);
					}
				}

				// Update synced metadata with new items.
				$set_method = 'set_' . $type . 's';
				Synced_Metadata::$set_method( $synced_items );
			}
		}

		return $found_uuids;
	}

	/**
	 * Find UUID by name in synced items array
	 *
	 * @since 1.0.0
	 *
	 * @param array  $items Array of items with 'uuid' and 'name'.
	 * @param string $name  Name to search for (case-insensitive).
	 * @return string|null UUID if found, null otherwise.
	 */
	private function find_uuid_by_name( $items, $name ) {
		foreach ( $items as $item ) {
			if ( isset( $item['name'], $item['uuid'] ) && strcasecmp( $item['name'], $name ) === 0 ) {
				return $item['uuid'];
			}
		}
		return null;
	}

	/**
	 * Get all available item types for WordPress.
	 *
	 * @since 1.4.0
	 *
	 * @return array Array of item type definitions with 'key' and 'label' keys.
	 */
	public function get_item_types() {
		return array(
			array(
				'key'   => 'page',
				'label' => __( 'Page', 'surecontact' ),
			),
			array(
				'key'   => 'post',
				'label' => __( 'Post', 'surecontact' ),
			),
		);
	}

	/**
	 * Get available events for a specific item type.
	 *
	 * @since 1.4.0
	 *
	 * @param string $item_type Item type ('page' or 'post').
	 * @return array Array of event definitions with 'key' and 'label' keys.
	 */
	public function get_events_by_item_type( $item_type ) {
		if ( in_array( $item_type, array( 'page', 'post' ), true ) ) {
			return array(
				array(
					'key'   => 'visited',
					'label' => __( 'Visited', 'surecontact' ),
				),
			);
		}

		return array();
	}

	/**
	 * Get item-specific configuration fields for a WordPress page or post.
	 *
	 * @since 1.4.0
	 *
	 * @param string      $item_id Page or post ID.
	 * @param string|null $event   Event name.
	 * @return array Configuration fields schema.
	 */
	public function get_item_config_fields( $item_id, $event = null ) {
		return self::get_standard_list_tag_fields();
	}

	/**
	 * Get published WordPress pages for the rule engine.
	 *
	 * @since 1.4.0
	 *
	 * @return array Array of page items.
	 */
	public function get_pages() {
		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Pages', 'surecontact' ),
				'type'  => 'page',
			),
		);

		$posts = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $post ) {
			$items[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
				'type'  => 'page',
			);
		}

		return $items;
	}

	/**
	 * Get published WordPress posts for the rule engine.
	 *
	 * @since 1.4.0
	 *
	 * @return array Array of post items.
	 */
	public function get_posts() {
		$items = array(
			array(
				'id'    => 'all',
				'title' => __( 'All Posts', 'surecontact' ),
				'type'  => 'post',
			),
		);

		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $post ) {
			$items[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
				'type'  => 'post',
			);
		}

		return $items;
	}

	/**
	 * Handle page/post visit tracking for logged-in users.
	 *
	 * Fires on template_redirect to detect visits to singular pages/posts.
	 * Looks up matching rules and applies configured list/tag actions.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function handle_page_visit() {
		if ( ! is_singular( array( 'page', 'post' ) ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$post_id   = get_queried_object_id();
		$post_type = get_post_type( $post_id );

		if ( ! $post_id || ! in_array( $post_type, array( 'page', 'post' ), true ) ) {
			return;
		}

		$user_id = get_current_user_id();

		$actions = $this->get_page_visit_actions( $post_id, $post_type );

		if ( empty( $actions ) ) {
			return;
		}

		$contact_id = $this->get_or_create_contact( $user_id );

		if ( ! $contact_id ) {
			return;
		}

		// Apply add actions.
		if ( ! empty( $actions['add_lists'] ) ) {
			$list_uuids = $this->extract_uuids( $actions['add_lists'] );
			if ( ! empty( $list_uuids ) ) {
				$this->apply_or_remove_lists( $contact_id, $list_uuids, 'attach' );
			}
		}

		if ( ! empty( $actions['add_tags'] ) ) {
			$tag_uuids = $this->extract_uuids( $actions['add_tags'] );
			if ( ! empty( $tag_uuids ) ) {
				$this->apply_or_remove_tags( $contact_id, $tag_uuids, 'apply' );
			}
		}

		// Apply remove actions.
		if ( ! empty( $actions['remove_lists'] ) || ! empty( $actions['remove_tags'] ) ) {
			$this->apply_remove_actions_with_config( $contact_id, $actions['remove_lists'], $actions['remove_tags'] );
		}
	}

	/**
	 * Get or create a SureContact contact from a WordPress user.
	 *
	 * Looks up existing contact by user email. If not found, creates
	 * a new contact from the WordPress user data.
	 *
	 * @since 1.4.1
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string|null Contact UUID or null on failure.
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

		// Contact doesn't exist, create it from user data with field mapping.
		// Pass false for $include_post_data since this runs on frontend page visits, not form submissions.
		$user_data = $this->get_user_data( $user_id, false );

		if ( empty( $user_data ) ) {
			return null;
		}

		$mapped_data = $this->normalize_data( $user_data );

		if ( empty( $mapped_data ) ) {
			return null;
		}

		$result = $this->contact_service->create_contact( $mapped_data, $user_id );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		// Handle queued responses (request queued for retry, no contact ID available yet).
		if ( is_array( $result ) && ! empty( $result['queued'] ) ) {
			return null;
		}

		return $result['contact_id'] ?? $result['uuid'] ?? null;
	}

	/**
	 * Get configured actions for a page/post visit event.
	 *
	 * Checks for specific post rules first, then "all" rules.
	 * Both can match — results are merged with specific taking priority.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type ('page' or 'post').
	 * @return array Merged config with add_lists, add_tags, remove_lists, remove_tags keys, or empty array.
	 */
	private function get_page_visit_actions( $post_id, $post_type ) {
		$db      = $this->get_db();
		$actions = array();

		// Check specific post rule with 'visited' event.
		$specific_result = $db->get( $this->slug, (string) $post_id, $post_type, 'visited' );
		if ( ! $this->has_valid_config( $specific_result ) ) {
			$specific_result = $db->get( $this->slug, (string) $post_id, $post_type, null );
		}

		if ( $this->has_valid_config( $specific_result ) && isset( $specific_result['config'] ) ) {
			$actions = $this->merge_config_defaults( $specific_result['config'] );
		}

		// Check "all" rule.
		$all_result = $db->get( $this->slug, 'all', $post_type, 'visited' );
		if ( ! $this->has_valid_config( $all_result ) ) {
			$all_result = $db->get( $this->slug, 'all', $post_type, null );
		}

		if ( $this->has_valid_config( $all_result ) && isset( $all_result['config'] ) ) {
			$all_actions = $this->merge_config_defaults( $all_result['config'] );

			if ( empty( $actions ) ) {
				$actions = $all_actions;
			} else {
				// Merge: combine lists/tags from both rules.
				$actions['add_lists']    = array_merge( $actions['add_lists'], $all_actions['add_lists'] );
				$actions['add_tags']     = array_merge( $actions['add_tags'], $all_actions['add_tags'] );
				$actions['remove_lists'] = array_merge( $actions['remove_lists'], $all_actions['remove_lists'] );
				$actions['remove_tags']  = array_merge( $actions['remove_tags'], $all_actions['remove_tags'] );
			}
		}

		return $actions;
	}
}
