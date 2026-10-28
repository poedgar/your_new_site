<?php
/**
 * Contact Service Class
 *
 * Handles all contact business logic operations with SureContact.
 * This class is integration-agnostic and provides reusable methods
 * for all integrations to use.
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact;

use SureContact\API\Contact_API;
use SureContact\API\Lists_Tags_API;
use SureContact\API\Fields_API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Contact_Service
 *
 * Business logic layer for contact operations.
 * Handles create, update, delete, tags, lists, and custom field operations.
 * This is the single entry point for all integrations.
 *
 * @since 0.0.1
 */
class Contact_Service {

	/**
	 * Contact API instance
	 *
	 * @since 0.0.1
	 *
	 * @var Contact_API
	 */
	private $contact_api;

	/**
	 * Lists and Tags API instance
	 *
	 * @since 0.0.3
	 *
	 * @var Lists_Tags_API
	 */
	private $lists_tags_api;

	/**
	 * Fields API instance
	 *
	 * @since 0.0.3
	 *
	 * @var Fields_API
	 */
	private $fields_api;

	/**
	 * Constructor
	 *
	 * @since 0.0.3
	 *
	 * @param Contact_API    $contact_api      Optional. Contact API instance.
	 * @param Lists_Tags_API $lists_tags_api   Optional. Lists and Tags API instance.
	 * @param Fields_API     $fields_api       Optional. Fields API instance.
	 */
	public function __construct( ?Contact_API $contact_api = null, ?Lists_Tags_API $lists_tags_api = null, ?Fields_API $fields_api = null ) {
		$this->contact_api    = $contact_api ? $contact_api : new Contact_API();
		$this->lists_tags_api = $lists_tags_api ? $lists_tags_api : new Lists_Tags_API();
		$this->fields_api     = $fields_api ? $fields_api : new Fields_API();
	}

	/**
	 * Create a new contact in CRM
	 *
	 * @since 0.0.1
	 *
	 * @param array $contact_data Normalized contact data.
	 *                            Can include 'list_uuids' and 'tag_uuids' arrays for optimization.
	 * @param int   $user_id      Optional. WordPress user ID to link contact.
	 * @param array $options      Optional. Additional options including 'source'.
	 * @return array|\WP_Error Response with contact_uuid or error
	 */
	public function create_contact( $contact_data, $user_id = 0, $options = array() ) {
		// Validate email - check both new and legacy format.
		$has_email = false;
		if ( isset( $contact_data['primary_fields']['email'] ) && ! empty( $contact_data['primary_fields']['email'] ) ) {
			$has_email = true;
		} elseif ( isset( $contact_data['email'] ) && ! empty( $contact_data['email'] ) ) {
			$has_email = true;
		}

		if ( ! $has_email ) {
			return new \WP_Error( 'missing_email', 'Email address is required to create a contact' );
		}

		// Add user_id to metadata if not already present.
		if ( $user_id > 0 && isset( $contact_data['metadata'] ) ) {
			$contact_data['metadata']['wp_user_id'] = $user_id;
		}

		// Create contact via API (with optional list_uuids and tag_uuids).
		$result = $this->contact_api->create_contact( $contact_data, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Handle queued responses (request failed immediately and was queued for retry).
		if ( is_array( $result ) && isset( $result['queued'] ) && $result['queued'] === true ) {
			return $result;
		}

		// Extract contact UUID from nested contact array.
		// The API returns UUID as the primary identifier for contacts.
		$contact_uuid = $result['data']['uuid'] ?? $result['contact']['uuid'] ?? $result['contact']['id'] ?? $result['contact_id'] ?? $result['uuid'] ?? $result['id'] ?? null;

		if ( ! $contact_uuid ) {
			Logger::error(
				'Contact Service',
				'No contact UUID returned from API',
				array( 'response' => $result )
			);
			return new \WP_Error( 'no_contact_uuid', 'No contact UUID returned from API' );
		}

		return array(
			'contact_id'   => $contact_uuid, // Backward compatibility.
			'contact_uuid' => $contact_uuid,
			'result'       => $result,
		);
	}

	/**
	 * Sync metadata (lists or tags) by external IDs
	 *
	 * Syncs lists/tags from external source and returns mappings (external_id => crm_uuid).
	 * Creates items if they don't exist.
	 *
	 * @since 0.0.3
	 *
	 * @param array  $items Array of items with 'external_id' and 'name'.
	 * @param string $type  Type: 'list' or 'tag' (singular).
	 * @return array Mappings array (external_id => crm_uuid)
	 */
	public function sync_metadata( $items, $type = 'list' ) {
		return $this->lists_tags_api->sync_metadata( $items, $type );
	}

	/**
	 * Create a new tag
	 *
	 * @since 0.0.3
	 *
	 * @param array $data    Tag data (must include 'name').
	 * @param array $options Optional. Additional options including 'source'.
	 * @return array|\WP_Error Response array or error on failure
	 */
	public function create_tag( $data, $options = array() ) {
		return $this->lists_tags_api->create_tag( $data, $options );
	}

	/**
	 * Search for tags by name
	 *
	 * @since 0.0.3
	 *
	 * @param string $name Tag name to search for.
	 * @return array|\WP_Error Array of matching tags or error on failure
	 */
	public function search_tags( $name ) {
		return $this->lists_tags_api->search_tags( $name );
	}

	/**
	 * Attach lists to contact
	 *
	 * @since 0.0.3
	 *
	 * @param string $contact_uuid Contact UUID.
	 * @param array  $list_uuids   Array of list UUIDs.
	 * @param array  $options      Optional. Additional options including 'source'.
	 * @return array|\WP_Error Response array or error on failure
	 */
	public function attach_lists_to_contact( $contact_uuid, $list_uuids, $options = array() ) {
		if ( empty( $contact_uuid ) ) {
			return new \WP_Error( 'missing_contact_uuid', 'Contact UUID is required' );
		}

		if ( empty( $list_uuids ) || ! is_array( $list_uuids ) ) {
			return new \WP_Error( 'invalid_list_uuids', 'List UUIDs must be a non-empty array' );
		}

		return $this->contact_api->attach_lists_to_contact( $contact_uuid, $list_uuids, $options );
	}

	/**
	 * Detach lists from contact
	 *
	 * @since 0.0.3
	 *
	 * @param string $contact_uuid Contact UUID.
	 * @param array  $list_uuids   Array of list UUIDs.
	 * @param array  $options      Optional. Additional options including 'source'.
	 * @return array|\WP_Error Response array or error on failure
	 */
	public function detach_lists_from_contact( $contact_uuid, $list_uuids, $options = array() ) {
		if ( empty( $contact_uuid ) ) {
			return new \WP_Error( 'missing_contact_uuid', 'Contact UUID is required' );
		}

		if ( empty( $list_uuids ) || ! is_array( $list_uuids ) ) {
			return new \WP_Error( 'invalid_list_uuids', 'List UUIDs must be a non-empty array' );
		}

		return $this->contact_api->detach_lists_from_contact( $contact_uuid, $list_uuids, $options );
	}

	/**
	 * Attach tags to contact
	 *
	 * @since 0.0.3
	 *
	 * @param string $contact_uuid Contact UUID.
	 * @param array  $tag_uuids    Array of tag UUIDs.
	 * @param array  $options      Optional. Additional options including 'source'.
	 * @return array|\WP_Error Response array or error on failure
	 */
	public function attach_tags_to_contact( $contact_uuid, $tag_uuids, $options = array() ) {
		if ( empty( $contact_uuid ) ) {
			return new \WP_Error( 'missing_contact_uuid', 'Contact UUID is required' );
		}

		if ( empty( $tag_uuids ) || ! is_array( $tag_uuids ) ) {
			return new \WP_Error( 'invalid_tag_uuids', 'Tag UUIDs must be a non-empty array' );
		}

		return $this->contact_api->attach_tags_to_contact( $contact_uuid, $tag_uuids, $options );
	}

	/**
	 * Detach tags from contact
	 *
	 * @since 0.0.3
	 *
	 * @param string $contact_uuid Contact UUID.
	 * @param array  $tag_uuids    Array of tag UUIDs.
	 * @param array  $options      Optional. Additional options including 'source'.
	 * @return array|\WP_Error Response array or error on failure
	 */
	public function detach_tags_from_contact( $contact_uuid, $tag_uuids, $options = array() ) {
		if ( empty( $contact_uuid ) ) {
			return new \WP_Error( 'missing_contact_uuid', 'Contact UUID is required' );
		}

		if ( empty( $tag_uuids ) || ! is_array( $tag_uuids ) ) {
			return new \WP_Error( 'invalid_tag_uuids', 'Tag UUIDs must be a non-empty array' );
		}

		return $this->contact_api->detach_tags_from_contact( $contact_uuid, $tag_uuids, $options );
	}

	/**
	 * Get available custom fields from CRM
	 *
	 * Returns a clean array of custom fields, properly parsed from the API response.
	 * Integrations should use this method instead of calling Fields_API directly.
	 *
	 * @since 0.0.3
	 *
	 * @return array|\WP_Error Array of custom fields or WP_Error on failure.
	 *                         Returns empty array if no fields found.
	 */
	public function get_custom_fields() {
		$response = $this->fields_api->get_custom_fields();

		// Return error as-is.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse and extract fields array from WordPress endpoint response.
		if ( isset( $response['data']['custom_fields'] ) && is_array( $response['data']['custom_fields'] ) ) {
			return $response['data']['custom_fields'];
		} else {
			return array();
		}
	}

	/**
	 * Sync custom field to CRM
	 *
	 * Creates a custom field in SureContact if it doesn't exist.
	 * The API will return the existing field if a field with the same name already exists.
	 *
	 * @since 0.0.3
	 *
	 * @param array $field_data {
	 *     Field configuration.
	 *
	 *     @type string $name         Field name (required) - must be unique within workspace.
	 *     @type string $label        Field label (required) - human-readable label.
	 *     @type string $type         Field type (required) - text, number, select, date, boolean, etc.
	 *     @type bool   $is_required  Whether field is required (optional, default false).
	 *     @type string $description  Field description (optional).
	 *     @type array  $options      Options for select/multi-select fields (optional).
	 *     @type mixed  $default_value Default value (optional).
	 * }
	 * @param array $options    Optional. Additional options including 'source'.
	 * @return array|\WP_Error Response array or error on failure
	 */
	public function sync_custom_field( $field_data, $options = array() ) {
		return $this->fields_api->sync_custom_field( $field_data, $options );
	}

	/**
	 * Get contact ID for a WordPress user
	 *
	 * @since 0.0.3
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string|false Contact UUID or false
	 */
	public function get_contact_id_by_user( $user_id ) {
		// Always perform dynamic lookup by email to ensure we get the correct contact
		// for the current workspace. This prevents issues when switching accounts.
		$user = get_userdata( $user_id );

		if ( ! $user || ! $user->user_email ) {
			return false;
		}

		// Look up contact by email in the current workspace.
		$contact_uuid = $this->find_contact_id_by_email( $user->user_email );

		if ( $contact_uuid ) {
			return $contact_uuid;
		}

		return false;
	}

	/**
	 * Find contact ID by email address
	 *
	 * @since 0.0.1
	 *
	 * @param string $email Email address.
	 * @return string|false Contact UUID or false
	 */
	public function find_contact_id_by_email( $email ) {
		$result = $this->contact_api->find_contact_by_email( $email );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		// Return UUID as the primary identifier.
		return $result['contact']['uuid'] ?? $result['contact']['id'] ?? $result['contact_id'] ?? $result['uuid'] ?? $result['id'] ?? false;
	}

	/**
	 * Batch sync multiple contacts
	 * Includes rate limiting and retry logic for all integrations
	 *
	 * @since 0.0.1
	 *
	 * @param array $contacts Array of contact data arrays.
	 * @param array $options  Optional. Additional options including 'source'.
	 * @return array|\WP_Error Response or error
	 */
	public function batch_sync_contacts( $contacts, $options = array() ) {
		// Make the API call.
		$result = $this->contact_api->batch_sync_contacts( $contacts, $options );

		return $result;
	}

	/**
	 * Update a contact's email address
	 *
	 * Updates the email address of an existing contact. This should be called
	 * when a WordPress user changes their email address to ensure the contact
	 * is updated rather than creating a duplicate.
	 *
	 * @since 1.0.0
	 *
	 * @param string $old_email The current email address of the contact.
	 * @param string $new_email The new email address to update to.
	 * @param int    $user_id   Optional. WordPress user ID for logging.
	 * @param array  $options   Optional. Additional options including 'source'.
	 * @return array|\WP_Error Response array with updated contact data or WP_Error on failure.
	 */
	public function update_email( $old_email, $new_email, $user_id = 0, $options = array() ) {
		$result = $this->contact_api->update_email( $old_email, $new_email, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}
}
