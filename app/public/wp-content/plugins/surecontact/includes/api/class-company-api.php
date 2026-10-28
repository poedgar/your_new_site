<?php
/**
 * Company API Client
 *
 * Handles company-specific operations using the SaaS Client.
 * Provides semantic methods for company CRUD, contact links, notes, and custom fields.
 * Delegates HTTP communication to SaaS_Client.
 *
 * @since 1.5.1
 *
 * @package SureContact
 */

namespace SureContact\API;

use SureContact\SaaS_Client;
use SureContact\Traits\API_Retry;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Company_API
 *
 * Wraps the SureContact /api/v1/companies/* endpoint family with semantic methods
 * for create, update, delete, contact attach/detach, primary contact, bulk link,
 * custom field schema, and company notes.
 *
 * @since 1.5.1
 */
class Company_API {

	use API_Retry;

	/**
	 * SaaS Client instance
	 *
	 * @since 1.5.1
	 *
	 * @var SaaS_Client
	 */
	private $saas_client;

	/**
	 * Constructor
	 *
	 * @since 1.5.1
	 *
	 * @param SaaS_Client|null $saas_client Optional. SaaS client instance.
	 */
	public function __construct( ?SaaS_Client $saas_client = null ) {
		$this->saas_client = $saas_client ? $saas_client : new SaaS_Client();
	}

	/**
	 * Create a new company in SureContact.
	 *
	 * @since 1.5.1
	 *
	 * @param array $data    Company data (must include 'name'). May include source,
	 *                       source_id, address, custom_fields, metadata, etc.
	 * @param array $options Optional. Retry/source options.
	 * @return array|WP_Error Response with company data (including 'uuid') or WP_Error.
	 */
	public function create_company( $data, $options = array() ) {
		if ( empty( $data['name'] ) ) {
			return new WP_Error(
				'missing_company_name',
				__( 'Company name is required to create a company.', 'surecontact' )
			);
		}

		return $this->execute_with_retry(
			function () use ( $data ) {
				return $this->saas_client->post( 'companies', $data );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'companies',
				'payload'      => $data,
				'operation'    => 'create_company',
			),
			$options
		);
	}

	/**
	 * Update an existing company in SureContact.
	 *
	 * @since 1.5.1
	 *
	 * @param string $company_uuid Company UUID.
	 * @param array  $data         Company fields to update.
	 * @param array  $options      Optional. Retry/source options.
	 * @return array|WP_Error Response with updated company data or WP_Error.
	 */
	public function update_company( $company_uuid, $data, $options = array() ) {
		if ( empty( $company_uuid ) ) {
			return new WP_Error(
				'missing_company_uuid',
				__( 'Company UUID is required to update a company.', 'surecontact' )
			);
		}

		$endpoint = 'companies/' . $company_uuid;

		return $this->execute_with_retry(
			function () use ( $endpoint, $data ) {
				return $this->saas_client->put( $endpoint, $data );
			},
			array(
				'request_type' => 'PUT',
				'endpoint'     => $endpoint,
				'payload'      => $data,
				'operation'    => 'update_company',
			),
			$options
		);
	}

	/**
	 * Delete a company in SureContact (soft delete).
	 *
	 * @since 1.5.1
	 *
	 * @param string $company_uuid Company UUID.
	 * @param array  $options      Optional. Retry/source options.
	 * @return array|WP_Error Response array or WP_Error.
	 */
	public function delete_company( $company_uuid, $options = array() ) {
		if ( empty( $company_uuid ) ) {
			return new WP_Error(
				'missing_company_uuid',
				__( 'Company UUID is required to delete a company.', 'surecontact' )
			);
		}

		$endpoint = 'companies/' . $company_uuid;

		return $this->execute_with_retry(
			function () use ( $endpoint ) {
				return $this->saas_client->delete( $endpoint );
			},
			array(
				'request_type' => 'DELETE',
				'endpoint'     => $endpoint,
				'payload'      => array(),
				'operation'    => 'delete_company',
			),
			$options
		);
	}

	/**
	 * Attach a contact to a company.
	 *
	 * @since 1.5.1
	 *
	 * @param string $company_uuid Company UUID.
	 * @param string $contact_uuid Contact UUID.
	 * @param bool   $is_primary   Whether this contact should be the primary contact.
	 * @param array  $options      Optional. Retry/source options.
	 * @return array|WP_Error Response array or WP_Error.
	 */
	public function attach_contact( $company_uuid, $contact_uuid, $is_primary = false, $options = array() ) {
		if ( empty( $company_uuid ) || empty( $contact_uuid ) ) {
			return new WP_Error(
				'missing_uuid',
				__( 'Company and contact UUIDs are required.', 'surecontact' )
			);
		}

		$endpoint = 'companies/' . $company_uuid . '/contacts';
		$payload  = array(
			'contact_uuid' => $contact_uuid,
			'is_primary'   => (bool) $is_primary,
			// Tag the pivot row's audit trail. Without this the backend defaults
			// to `linked_via=manual`, which would mislabel plugin-created links.
			'linked_via'   => 'api',
		);

		return $this->execute_with_retry(
			function () use ( $endpoint, $payload ) {
				return $this->saas_client->post( $endpoint, $payload );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => $endpoint,
				'payload'      => $payload,
				'operation'    => 'attach_contact_to_company',
			),
			$options
		);
	}

	/**
	 * Maximum contact_uuids the backend BulkAttachContactsRequest accepts in one call.
	 *
	 * @since 1.5.1
	 *
	 * @var int
	 */
	const BULK_ATTACH_MAX = 100;

	/**
	 * Bulk-attach multiple contacts to a single company.
	 *
	 * Hits the `POST /companies/{uuid}/contacts/bulk-attach` endpoint, which
	 * processes up to 100 contact UUIDs in a single round-trip. The backend
	 * loops `attachContact()` under the hood and fires per-contact automation
	 * triggers, so behavior is identical to N sequential `attach_contact()`
	 * calls minus the network overhead.
	 *
	 * `is_primary` is intentionally NOT sent here — the backend ignores it
	 * whenever the request contains more than one contact UUID. Primary
	 * contacts should be attached via the single-contact `attach_contact()`
	 * method, where the flag is honored.
	 *
	 * @since 1.5.1
	 *
	 * @param string $company_uuid  Company UUID.
	 * @param array  $contact_uuids Contact UUIDs (max BULK_ATTACH_MAX).
	 * @param array  $options       Optional. Retry/source options.
	 * @return array|WP_Error Response array (with `attached`, `skipped`, `errors`) or WP_Error.
	 */
	public function bulk_attach_contacts( $company_uuid, array $contact_uuids, $options = array() ) {
		if ( empty( $company_uuid ) || empty( $contact_uuids ) ) {
			return new WP_Error(
				'missing_args',
				__( 'Company UUID and contact UUIDs are required.', 'surecontact' )
			);
		}

		if ( count( $contact_uuids ) > self::BULK_ATTACH_MAX ) {
			return new WP_Error(
				'too_many_uuids',
				/* translators: %d: maximum count */
				sprintf( __( 'Bulk attach accepts at most %d contact UUIDs per call.', 'surecontact' ), self::BULK_ATTACH_MAX )
			);
		}

		$endpoint = 'companies/' . $company_uuid . '/contacts/bulk-attach';
		$payload  = array(
			'contact_uuids' => array_values( $contact_uuids ),
			'linked_via'    => 'api',
		);

		return $this->execute_with_retry(
			function () use ( $endpoint, $payload ) {
				return $this->saas_client->post( $endpoint, $payload );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => $endpoint,
				'payload'      => $payload,
				'operation'    => 'bulk_attach_contacts_to_company',
			),
			$options
		);
	}

	/**
	 * Bulk-attach multiple companies to a single contact.
	 *
	 * Mirror of `bulk_attach_contacts()` for the reverse-link direction,
	 * targeting `POST /contacts/{uuid}/companies/bulk-attach`. Used when a
	 * single subscriber lands on the SaaS and needs to be linked to several
	 * already-mapped companies in one shot.
	 *
	 * @since 1.5.1
	 *
	 * @param string $contact_uuid  Contact UUID.
	 * @param array  $company_uuids Company UUIDs (max BULK_ATTACH_MAX).
	 * @param array  $options       Optional. Retry/source options.
	 * @return array|WP_Error Response array (with `attached`, `skipped`, `errors`) or WP_Error.
	 */
	public function bulk_attach_companies( $contact_uuid, array $company_uuids, $options = array() ) {
		if ( empty( $contact_uuid ) || empty( $company_uuids ) ) {
			return new WP_Error(
				'missing_args',
				__( 'Contact UUID and company UUIDs are required.', 'surecontact' )
			);
		}

		if ( count( $company_uuids ) > self::BULK_ATTACH_MAX ) {
			return new WP_Error(
				'too_many_uuids',
				/* translators: %d: maximum count */
				sprintf( __( 'Bulk attach accepts at most %d company UUIDs per call.', 'surecontact' ), self::BULK_ATTACH_MAX )
			);
		}

		$endpoint = 'contacts/' . $contact_uuid . '/companies/bulk-attach';
		$payload  = array(
			'company_uuids' => array_values( $company_uuids ),
			'linked_via'    => 'api',
		);

		return $this->execute_with_retry(
			function () use ( $endpoint, $payload ) {
				return $this->saas_client->post( $endpoint, $payload );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => $endpoint,
				'payload'      => $payload,
				'operation'    => 'bulk_attach_companies_to_contact',
			),
			$options
		);
	}

	/**
	 * Detach a contact from a company.
	 *
	 * @since 1.5.1
	 *
	 * @param string $company_uuid Company UUID.
	 * @param string $contact_uuid Contact UUID.
	 * @param array  $options      Optional. Retry/source options.
	 * @return array|WP_Error Response array or WP_Error.
	 */
	public function detach_contact( $company_uuid, $contact_uuid, $options = array() ) {
		if ( empty( $company_uuid ) || empty( $contact_uuid ) ) {
			return new WP_Error(
				'missing_uuid',
				__( 'Company and contact UUIDs are required.', 'surecontact' )
			);
		}

		$endpoint = 'companies/' . $company_uuid . '/contacts/' . $contact_uuid;

		return $this->execute_with_retry(
			function () use ( $endpoint ) {
				return $this->saas_client->delete( $endpoint );
			},
			array(
				'request_type' => 'DELETE',
				'endpoint'     => $endpoint,
				'payload'      => array(),
				'operation'    => 'detach_contact_from_company',
			),
			$options
		);
	}

	/**
	 * Check whether a company already exists in the workspace for the given domain.
	 *
	 * Uses the dedicated `check-domain` endpoint which performs an exact,
	 * case-insensitive domain lookup — the same identity key the SaaS uses for
	 * its own auto-domain-matching feature. Safer than fuzzy name search for
	 * resolving "does this FluentCRM company already exist on the SaaS?".
	 *
	 * @since 1.5.1
	 *
	 * @param string $domain Domain to check (e.g. "acme.com").
	 * @return array|WP_Error Response of shape `{ matched: bool, company: object|null }` or WP_Error.
	 */
	public function check_domain( $domain ) {
		$domain = trim( (string) $domain );
		if ( $domain === '' ) {
			return new WP_Error(
				'missing_domain',
				__( 'Domain is required.', 'surecontact' )
			);
		}

		$endpoint = 'companies/check-domain?' . http_build_query( array( 'domain' => $domain ) );

		return $this->saas_client->get( $endpoint );
	}

	/**
	 * Look up a company by exact (case-insensitive) name.
	 *
	 * Company names are unique per workspace, so this endpoint returns at most
	 * one match. Used to resolve a UUID for a company that already exists on
	 * the SaaS but isn't mapped locally (e.g. created outside the plugin, or
	 * domain probe missed because the FluentCRM company has no website).
	 *
	 * @since 1.5.1
	 *
	 * @param string $name Company name to look up.
	 * @return array|WP_Error Standard paginated list response (data array of 0 or 1) or WP_Error.
	 */
	public function find_by_exact_name( $name ) {
		$name = trim( (string) $name );
		if ( $name === '' ) {
			return new WP_Error(
				'missing_company_name',
				__( 'Company name is required.', 'surecontact' )
			);
		}

		$endpoint = 'companies?' . http_build_query(
			array(
				'exact_company_name' => $name,
				'per_page'           => 1,
			)
		);

		return $this->saas_client->get( $endpoint );
	}

	/**
	 * Get all company custom field definitions for the workspace.
	 *
	 * @since 1.5.1
	 *
	 * @return array|WP_Error Response array or WP_Error.
	 */
	public function get_custom_fields() {
		return $this->saas_client->get( 'company-custom-fields' );
	}

	/**
	 * Create a new company custom field.
	 *
	 * @since 1.5.1
	 *
	 * @param array $data    Field data (name, label, field_type, options, etc.).
	 * @param array $options Optional. Retry/source options.
	 * @return array|WP_Error Response with created field or WP_Error.
	 */
	public function create_custom_field( $data, $options = array() ) {
		if ( empty( $data['name'] ) ) {
			return new WP_Error(
				'missing_field_name',
				__( 'Custom field name is required.', 'surecontact' )
			);
		}

		return $this->execute_with_retry(
			function () use ( $data ) {
				return $this->saas_client->post( 'company-custom-fields', $data );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'company-custom-fields',
				'payload'      => $data,
				'operation'    => 'create_company_custom_field',
			),
			$options
		);
	}

	/**
	 * Add a note to a company.
	 *
	 * @since 1.5.1
	 *
	 * @param string $company_uuid Company UUID.
	 * @param array  $data         Note data (title, description, type, etc.).
	 * @param array  $options      Optional. Retry/source options.
	 * @return array|WP_Error Response with created note (including 'uuid') or WP_Error.
	 */
	public function add_note( $company_uuid, $data, $options = array() ) {
		if ( empty( $company_uuid ) ) {
			return new WP_Error(
				'missing_company_uuid',
				__( 'Company UUID is required.', 'surecontact' )
			);
		}

		$endpoint = 'companies/' . $company_uuid . '/notes';

		return $this->execute_with_retry(
			function () use ( $endpoint, $data ) {
				return $this->saas_client->post( $endpoint, $data );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => $endpoint,
				'payload'      => $data,
				'operation'    => 'add_company_note',
			),
			$options
		);
	}

	/**
	 * Update a company note.
	 *
	 * @since 1.5.1
	 *
	 * @param string $note_uuid Note UUID.
	 * @param array  $data      Updated note data.
	 * @param array  $options   Optional. Retry/source options.
	 * @return array|WP_Error Response array or WP_Error.
	 */
	public function update_note( $note_uuid, $data, $options = array() ) {
		if ( empty( $note_uuid ) ) {
			return new WP_Error(
				'missing_note_uuid',
				__( 'Note UUID is required.', 'surecontact' )
			);
		}

		$endpoint = 'company-notes/' . $note_uuid;

		return $this->execute_with_retry(
			function () use ( $endpoint, $data ) {
				return $this->saas_client->put( $endpoint, $data );
			},
			array(
				'request_type' => 'PUT',
				'endpoint'     => $endpoint,
				'payload'      => $data,
				'operation'    => 'update_company_note',
			),
			$options
		);
	}

	/**
	 * Delete a company note.
	 *
	 * @since 1.5.1
	 *
	 * @param string $note_uuid Note UUID.
	 * @param array  $options   Optional. Retry/source options.
	 * @return array|WP_Error Response array or WP_Error.
	 */
	public function delete_note( $note_uuid, $options = array() ) {
		if ( empty( $note_uuid ) ) {
			return new WP_Error(
				'missing_note_uuid',
				__( 'Note UUID is required.', 'surecontact' )
			);
		}

		$endpoint = 'company-notes/' . $note_uuid;

		return $this->execute_with_retry(
			function () use ( $endpoint ) {
				return $this->saas_client->delete( $endpoint );
			},
			array(
				'request_type' => 'DELETE',
				'endpoint'     => $endpoint,
				'payload'      => array(),
				'operation'    => 'delete_company_note',
			),
			$options
		);
	}
}
