<?php
/**
 * Company Service Class
 *
 * Business logic layer for company operations. Integration-agnostic — wraps
 * Company_API calls with consistent error handling, response normalization,
 * and UUID extraction. Callers (integrations) are responsible for storing
 * FluentCRM-ID → SureContact-UUID mappings via their own settings.
 *
 * @since 1.5.1
 *
 * @package SureContact
 */

namespace SureContact;

use SureContact\API\Company_API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Company_Service
 *
 * @since 1.5.1
 */
class Company_Service {

	/**
	 * Company API instance.
	 *
	 * @since 1.5.1
	 *
	 * @var Company_API
	 */
	private $company_api;

	/**
	 * Constructor.
	 *
	 * @since 1.5.1
	 *
	 * @param Company_API|null $company_api Optional API instance for DI / testing.
	 */
	public function __construct( ?Company_API $company_api = null ) {
		$this->company_api = $company_api ? $company_api : new Company_API();
	}

	/**
	 * Create a company in SureContact.
	 *
	 * Returns the SureContact company UUID on success, or WP_Error on failure.
	 *
	 * @since 1.5.1
	 *
	 * @param array $data    Company payload (must include 'name').
	 * @param array $options Optional. Retry/source options forwarded to the API.
	 * @return string|\WP_Error Company UUID or error.
	 */
	public function create_company( $data, $options = array() ) {
		$response = $this->company_api->create_company( $data, $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$uuid = $this->extract_uuid( $response );
		if ( ! $uuid ) {
			return new \WP_Error(
				'company_uuid_missing',
				__( 'Company was created but no UUID was returned by the API.', 'surecontact' )
			);
		}

		return $uuid;
	}

	/**
	 * Update a company in SureContact.
	 *
	 * @since 1.5.1
	 *
	 * @param string $company_uuid Company UUID.
	 * @param array  $data         Fields to update.
	 * @param array  $options      Optional. Retry/source options.
	 * @return array|\WP_Error Raw API response or error.
	 */
	public function update_company( $company_uuid, $data, $options = array() ) {
		return $this->company_api->update_company( $company_uuid, $data, $options );
	}

	/**
	 * Delete a company in SureContact.
	 *
	 * @since 1.5.1
	 *
	 * @param string $company_uuid Company UUID.
	 * @param array  $options      Optional. Retry/source options.
	 * @return bool|\WP_Error True on success or WP_Error.
	 */
	public function delete_company( $company_uuid, $options = array() ) {
		$response = $this->company_api->delete_company( $company_uuid, $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Link a contact to a company.
	 *
	 * @since 1.5.1
	 *
	 * @param string $company_uuid Company UUID.
	 * @param string $contact_uuid Contact UUID.
	 * @param bool   $is_primary   Whether to mark as primary contact.
	 * @param array  $options      Optional. Retry/source options.
	 * @return bool|\WP_Error True on success or WP_Error.
	 */
	public function link_contact( $company_uuid, $contact_uuid, $is_primary = false, $options = array() ) {
		$response = $this->company_api->attach_contact( $company_uuid, $contact_uuid, $is_primary, $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Bulk-link a batch of contacts to a single company.
	 *
	 * Wraps `Company_API::bulk_attach_contacts()` and normalizes the SaaS
	 * envelope so callers always see a flat `{ attached, skipped, errors }`
	 * shape — Laravel returns these under `data` for some resources and at
	 * the root for others, so we unwrap once here.
	 *
	 * Callers MUST split out the primary contact and attach it separately
	 * via `link_contact()`, because the backend ignores `is_primary` when
	 * the bulk request carries more than one UUID.
	 *
	 * @since 1.5.1
	 *
	 * @param string $company_uuid  Company UUID.
	 * @param array  $contact_uuids Contact UUIDs (1..100).
	 * @param array  $options       Optional. Retry/source options.
	 * @return array|\WP_Error Normalized result or WP_Error.
	 */
	public function bulk_link_contacts( $company_uuid, array $contact_uuids, $options = array() ) {
		$response = $this->company_api->bulk_attach_contacts( $company_uuid, $contact_uuids, $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;

		return array(
			'attached' => isset( $data['attached'] ) ? (int) $data['attached'] : 0,
			'skipped'  => isset( $data['skipped'] ) ? (int) $data['skipped'] : 0,
			'errors'   => isset( $data['errors'] ) && is_array( $data['errors'] ) ? $data['errors'] : array(),
		);
	}

	/**
	 * Bulk-link a batch of companies to a single contact (reverse direction).
	 *
	 * Used by the FluentCRM real-time reverse-link helper when a subscriber
	 * lands on the SaaS and has FluentCRM relations to several already-mapped
	 * companies.
	 *
	 * @since 1.5.1
	 *
	 * @param string $contact_uuid  Contact UUID.
	 * @param array  $company_uuids Company UUIDs (1..100).
	 * @param array  $options       Optional. Retry/source options.
	 * @return array|\WP_Error Normalized result or WP_Error.
	 */
	public function bulk_link_companies( $contact_uuid, array $company_uuids, $options = array() ) {
		$response = $this->company_api->bulk_attach_companies( $contact_uuid, $company_uuids, $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;

		return array(
			'attached' => isset( $data['attached'] ) ? (int) $data['attached'] : 0,
			'skipped'  => isset( $data['skipped'] ) ? (int) $data['skipped'] : 0,
			'errors'   => isset( $data['errors'] ) && is_array( $data['errors'] ) ? $data['errors'] : array(),
		);
	}

	/**
	 * Unlink a contact from a company.
	 *
	 * @since 1.5.1
	 *
	 * @param string $company_uuid Company UUID.
	 * @param string $contact_uuid Contact UUID.
	 * @param array  $options      Optional. Retry/source options.
	 * @return bool|\WP_Error True on success or WP_Error.
	 */
	public function unlink_contact( $company_uuid, $contact_uuid, $options = array() ) {
		$response = $this->company_api->detach_contact( $company_uuid, $contact_uuid, $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Add a note to a company.
	 *
	 * Returns the note UUID on success.
	 *
	 * @since 1.5.1
	 *
	 * @param string $company_uuid Company UUID.
	 * @param array  $data         Note data (title, description, type).
	 * @param array  $options      Optional. Retry/source options.
	 * @return string|\WP_Error Note UUID or error.
	 */
	public function add_note( $company_uuid, $data, $options = array() ) {
		$response = $this->company_api->add_note( $company_uuid, $data, $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$uuid = $this->extract_uuid( $response );
		if ( ! $uuid ) {
			return new \WP_Error(
				'note_uuid_missing',
				__( 'Note was created but no UUID was returned.', 'surecontact' )
			);
		}

		return $uuid;
	}

	/**
	 * Update a company note.
	 *
	 * @since 1.5.1
	 *
	 * @param string $note_uuid Note UUID.
	 * @param array  $data      Updated note data.
	 * @param array  $options   Optional. Retry/source options.
	 * @return array|\WP_Error Response or error.
	 */
	public function update_note( $note_uuid, $data, $options = array() ) {
		return $this->company_api->update_note( $note_uuid, $data, $options );
	}

	/**
	 * Delete a company note.
	 *
	 * @since 1.5.1
	 *
	 * @param string $note_uuid Note UUID.
	 * @param array  $options   Optional. Retry/source options.
	 * @return bool|\WP_Error True on success or WP_Error.
	 */
	public function delete_note( $note_uuid, $options = array() ) {
		$response = $this->company_api->delete_note( $note_uuid, $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Look up an existing SureContact company by exact domain match.
	 *
	 * Returns the UUID of the matched company, or null when nothing matches /
	 * the API call fails. Used by the FluentCRM sync to resolve identity for
	 * companies that have a website set, without risking the false-positive
	 * merges that a fuzzy name search would produce.
	 *
	 * @since 1.5.1
	 *
	 * @param string $domain Domain to look up (e.g. "acme.com").
	 * @return string|null|\WP_Error UUID on match, null on confirmed no-match
	 *                               (or empty domain), WP_Error on API failure
	 *                               so the caller can avoid duplicate-creating
	 *                               on transient errors.
	 */
	public function find_uuid_by_domain( $domain ) {
		$domain = trim( (string) $domain );
		if ( $domain === '' ) {
			return null;
		}

		$response = $this->company_api->check_domain( $domain );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Endpoint returns either { matched, company } or { data: { matched, company } }
		// depending on the SaaS response envelope; handle both.
		$payload = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;

		if ( empty( $payload['matched'] ) || empty( $payload['company'] ) ) {
			return null;
		}

		$company = $payload['company'];
		if ( isset( $company['uuid'] ) && is_string( $company['uuid'] ) ) {
			return $company['uuid'];
		}

		return null;
	}

	/**
	 * Look up an existing SureContact company by exact (case-insensitive) name.
	 *
	 * Names are unique per workspace, so the SaaS returns at most one match.
	 * Used as the final identity-resolution step in the FluentCRM sync — after
	 * the local mapping and the domain probe both miss — to avoid creating a
	 * duplicate when the same company already exists on the SaaS.
	 *
	 * @since 1.5.1
	 *
	 * @param string $name Company name to look up.
	 * @return string|null|\WP_Error UUID on match, null on confirmed no-match
	 *                               (or empty name), WP_Error on API failure
	 *                               so the caller can avoid duplicate-creating
	 *                               on transient errors.
	 */
	public function find_uuid_by_exact_name( $name ) {
		$name = trim( (string) $name );
		if ( $name === '' ) {
			return null;
		}

		$response = $this->company_api->find_by_exact_name( $name );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$matches = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		if ( empty( $matches ) ) {
			return null;
		}

		$first = $matches[0];
		if ( isset( $first['uuid'] ) && is_string( $first['uuid'] ) ) {
			return $first['uuid'];
		}

		return null;
	}

	/**
	 * List the workspace's company custom fields as a normalized array.
	 *
	 * Wraps Company_API::get_custom_fields() with the same `unwrap_collection`
	 * shape `sync_custom_field()` uses internally — so callers can validate
	 * cached field-name mappings without paying for a per-field create-or-skip
	 * lookup.
	 *
	 * @since 1.5.1
	 *
	 * @return array|\WP_Error Indexed array of `{ name, label, ... }` entries, or WP_Error.
	 */
	public function list_custom_fields() {
		$response = $this->company_api->get_custom_fields();
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $this->unwrap_collection( $response );
	}

	/**
	 * Ensure a company custom field exists in SureContact, returning its name.
	 *
	 * Looks up existing workspace custom fields by name; creates the field
	 * if missing. Returns the field name (which is what the company payload
	 * uses as the key inside `custom_fields`).
	 *
	 * @since 1.5.1
	 *
	 * @param array $field_data Field definition (name, label, field_type, options).
	 * @param array $options    Optional. Retry/source options.
	 * @return string|\WP_Error Field name on success or WP_Error.
	 */
	public function sync_custom_field( $field_data, $options = array() ) {
		if ( empty( $field_data['name'] ) ) {
			return new \WP_Error(
				'missing_field_name',
				__( 'Custom field name is required.', 'surecontact' )
			);
		}

		// Check if the field already exists. Bail on transient API failure so a
		// blip doesn't cause a redundant create attempt (the SaaS returns 422
		// on duplicate names, but that's logged noise we'd rather not generate).
		$existing_fields = $this->list_custom_fields();
		if ( is_wp_error( $existing_fields ) ) {
			return $existing_fields;
		}
		foreach ( $existing_fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			if ( isset( $field['name'] ) && $field['name'] === $field_data['name'] ) {
				return $field['name'];
			}
		}

		$response = $this->company_api->create_custom_field( $field_data, $options );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$created = $this->unwrap_single( $response );
		if ( is_array( $created ) && ! empty( $created['name'] ) ) {
			return $created['name'];
		}

		// Fallback to the supplied name when API doesn't echo it back.
		return $field_data['name'];
	}

	/**
	 * Extract a UUID from a (possibly wrapped) API response.
	 *
	 * Handles common Laravel resource shapes: `{ data: { uuid: ... } }`,
	 * `{ uuid: ... }`, and flat dictionaries.
	 *
	 * @since 1.5.1
	 *
	 * @param mixed $response API response.
	 * @return string|null UUID or null.
	 */
	private function extract_uuid( $response ) {
		if ( ! is_array( $response ) ) {
			return null;
		}

		if ( isset( $response['uuid'] ) && is_string( $response['uuid'] ) ) {
			return $response['uuid'];
		}

		if ( isset( $response['data']['uuid'] ) && is_string( $response['data']['uuid'] ) ) {
			return $response['data']['uuid'];
		}

		if ( isset( $response['data'][0]['uuid'] ) && is_string( $response['data'][0]['uuid'] ) ) {
			return $response['data'][0]['uuid'];
		}

		return null;
	}

	/**
	 * Unwrap a Laravel-style collection response to a flat array of items.
	 *
	 * @since 1.5.1
	 *
	 * @param array $response API response.
	 * @return array Flat array of items.
	 */
	private function unwrap_collection( $response ) {
		if ( ! is_array( $response ) ) {
			return array();
		}

		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			return $response['data'];
		}

		return $response;
	}

	/**
	 * Unwrap a Laravel-style single resource response.
	 *
	 * @since 1.5.1
	 *
	 * @param array $response API response.
	 * @return array Flat item array.
	 */
	private function unwrap_single( $response ) {
		if ( ! is_array( $response ) ) {
			return array();
		}

		if ( isset( $response['data'] ) && is_array( $response['data'] ) && ! isset( $response['data'][0] ) ) {
			return $response['data'];
		}

		return $response;
	}
}
