<?php
/**
 * Contact API Client
 *
 * Handles contact-specific operations using the SaaS Client.
 * This class provides a semantic API for contact operations
 * and delegates HTTP communication to SaaS_Client.
 *
 * @since 0.0.1
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
 * Class Contact_API
 *
 * Handles all contact-related API operations with the external SaaS API.
 * Provides semantic methods for contact CRUD operations and delegates
 * HTTP communication to SaaS_Client.
 *
 * @since 0.0.1
 */
class Contact_API {

	use API_Retry;

	/**
	 * SaaS Client instance
	 *
	 * @since 0.0.1
	 *
	 * @var SaaS_Client
	 */
	private $saas_client;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 *
	 * @param SaaS_Client|null $saas_client Optional. SaaS client instance.
	 */
	public function __construct( ?SaaS_Client $saas_client = null ) {
		$this->saas_client = $saas_client ? $saas_client : new SaaS_Client();
	}

	/**
	 * Create a new contact in the CRM
	 *
	 * @since 0.0.1
	 *
	 * @param array $data Contact data (must include 'email' at minimum).
	 *                    Optionally includes 'list_uuids' and 'tag_uuids' arrays.
	 * @param array $options    Optional. Additional options including 'source'.
	 * @return array|WP_Error Response array with 'contact_uuid' or WP_Error on failure.
	 */
	public function create_contact( $data, $options = array() ) {
		// Check if data is in new structured format (primary_fields, custom_fields, metadata).
		if ( isset( $data['primary_fields'] ) ) {
			// Validate email in primary_fields.
			if ( empty( $data['primary_fields']['email'] ) ) {
				return new WP_Error(
					'missing_email',
					__( 'Email address is required to create a contact.', 'surecontact' )
				);
			}
		} elseif ( empty( $data['email'] ) ) {
			// Legacy format validation.
			return new WP_Error(
				'missing_email',
				__( 'Email address is required to create a contact.', 'surecontact' )
			);
		}

		// Validate list_uuids if provided.
		if ( isset( $data['list_uuids'] ) && ! is_array( $data['list_uuids'] ) ) {
			return new WP_Error(
				'invalid_list_uuids',
				__( 'List UUIDs must be an array.', 'surecontact' )
			);
		}

		// Validate tag_uuids if provided.
		if ( isset( $data['tag_uuids'] ) && ! is_array( $data['tag_uuids'] ) ) {
			return new WP_Error(
				'invalid_tag_uuids',
				__( 'Tag UUIDs must be an array.', 'surecontact' )
			);
		}

		// Execute with automatic retry logic.
		return $this->execute_with_retry(
			function () use ( $data ) {
				return $this->saas_client->post( 'wordpress/sync-contact', $data );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'wordpress/sync-contact',
				'payload'      => $data,
				'operation'    => 'create_contact',
			),
			$options
		);
	}

	/**
	 * Find a contact by email address
	 *
	 * @since 0.0.1
	 *
	 * @param string $email Email address.
	 * @return array|WP_Error Contact data with 'contact_uuid' or WP_Error on failure.
	 */
	public function find_contact_by_email( $email ) {
		if ( empty( $email ) || ! is_email( $email ) ) {
			return new WP_Error(
				'invalid_email',
				__( 'Valid email address is required.', 'surecontact' )
			);
		}

		// Build the endpoint with query parameter.
		$endpoint = 'contacts?' . http_build_query( array( 'search' => $email ) );

		$response = $this->saas_client->get( $endpoint );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Handle array response with data key (typical for paginated responses).
		$contacts = $response;
		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			$contacts = $response['data'];
		}

		// If response is array of contacts, find exact email match.
		if ( is_array( $contacts ) ) {
			foreach ( $contacts as $contact ) {
				if ( isset( $contact['email'] ) && strtolower( $contact['email'] ) === strtolower( $email ) ) {
					return $contact;
				}
				// Also check primary_fields for email.
				if ( isset( $contact['primary_fields']['email'] ) &&
					strtolower( $contact['primary_fields']['email'] ) === strtolower( $email ) ) {
					return $contact;
				}
			}
		}

		// If no exact match found, return WP_Error.
		return new WP_Error(
			'contact_not_found',
			__( 'No contact found with this email address.', 'surecontact' )
		);
	}

	/**
	 * Batch create or update contacts
	 *
	 * @since 0.0.1
	 *
	 * @param array $payload Payload containing workspace_uuid, contacts array, and optional batch_uuid.
	 *                       - First call (without batch_uuid): Creates new batch and returns batch_uuid.
	 *                       - Subsequent calls (with batch_uuid): Appends contacts to existing batch.
	 * @param array $options Optional. Additional options including 'source'.
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function batch_sync_contacts( $payload, $options = array() ) {
		if ( empty( $payload['contacts'] ) || ! is_array( $payload['contacts'] ) ) {
			return new WP_Error(
				'invalid_contacts',
				__( 'Contacts must be a non-empty array.', 'surecontact' )
			);
		}

		// Use the async bulk sync endpoint.
		// Returns: { batch_uuid, status_url, total_contacts, chunks, chunk_size }.
		$merged_options = array_merge( array( 'skip_queue' => true ), $options );

		$response = $this->execute_with_retry(
			function () use ( $payload ) {
				return $this->saas_client->post( 'wordpress/sync-contacts-bulk', $payload );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'wordpress/sync-contacts-bulk',
				'payload'      => $payload,
				'operation'    => 'batch_sync_contacts',
			),
			$merged_options
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Store batch info for status polling.
		if ( isset( $response['batch_uuid'] ) ) {
			$this->store_batch_info( $response );
		}

		return $response;
	}

	/**
	 * Get batch sync status
	 *
	 * @since 0.0.1
	 *
	 * @param string $batch_uuid Batch UUID from initial sync request.
	 * @return array|WP_Error Batch status or WP_Error on failure.
	 */
	public function get_batch_status( $batch_uuid ) {
		if ( empty( $batch_uuid ) ) {
			return new WP_Error(
				'missing_batch_uuid',
				__( 'Batch UUID is required.', 'surecontact' )
			);
		}

		return $this->saas_client->get( 'wordpress/sync-batch/' . $batch_uuid );
	}

	/**
	 * Store batch info for later retrieval
	 *
	 * @since 0.0.1
	 *
	 * @param array $batch_info Batch information from API.
	 * @return void
	 */
	private function store_batch_info( $batch_info ) {
		$batches = get_option( 'surecontact_sync_batches', array() );

		$batches[ $batch_info['batch_uuid'] ] = array(
			'batch_uuid'     => $batch_info['batch_uuid'],
			'total_contacts' => $batch_info['total_contacts'] ?? 0,
			'chunks'         => $batch_info['chunks'] ?? 1,
			'chunk_size'     => $batch_info['chunk_size'] ?? 25,
			'status_url'     => $batch_info['status_url'] ?? '',
			'created_at'     => current_time( 'mysql' ),
			'status'         => 'pending',
		);

		// Keep only last 50 batches.
		if ( count( $batches ) > 50 ) {
			$batches = array_slice( $batches, -50, null, true );
		}

		update_option( 'surecontact_sync_batches', $batches );
	}

	/**
	 * Attach lists to a contact
	 *
	 * @since 0.0.3
	 *
	 * @param string $contact_uuid Contact UUID.
	 * @param array  $list_uuids   Array of list UUIDs to attach.
	 * @param array  $options      Optional. Additional options including 'source'.
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function attach_lists_to_contact( $contact_uuid, $list_uuids, $options = array() ) {
		if ( empty( $contact_uuid ) ) {
			return new WP_Error(
				'missing_contact_uuid',
				__( 'Contact UUID is required.', 'surecontact' )
			);
		}

		if ( empty( $list_uuids ) || ! is_array( $list_uuids ) ) {
			return new WP_Error(
				'invalid_list_uuids',
				__( 'List UUIDs must be a non-empty array.', 'surecontact' )
			);
		}

		$payload = array(
			'list_uuids' => array_values( $list_uuids ),
		);

		// Execute with automatic retry logic.
		return $this->execute_with_retry(
			function () use ( $contact_uuid, $payload ) {
				return $this->saas_client->post( 'contacts/' . $contact_uuid . '/lists/attach', $payload );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'contacts/' . $contact_uuid . '/lists/attach',
				'payload'      => $payload,
				'operation'    => 'attach_lists_to_contact',
			),
			$options
		);
	}

	/**
	 * Attach tags to a contact
	 *
	 * @since 0.0.3
	 *
	 * @param string $contact_uuid Contact UUID.
	 * @param array  $tag_uuids    Array of tag UUIDs to attach.
	 * @param array  $options      Optional. Additional options including 'source'.
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function attach_tags_to_contact( $contact_uuid, $tag_uuids, $options = array() ) {
		if ( empty( $contact_uuid ) ) {
			return new WP_Error(
				'missing_contact_uuid',
				__( 'Contact UUID is required.', 'surecontact' )
			);
		}

		if ( empty( $tag_uuids ) || ! is_array( $tag_uuids ) ) {
			return new WP_Error(
				'invalid_tag_uuids',
				__( 'Tag UUIDs must be a non-empty array.', 'surecontact' )
			);
		}

		$payload = array(
			'tag_uuids' => array_values( $tag_uuids ),
		);

		// Execute with automatic retry logic.
		return $this->execute_with_retry(
			function () use ( $contact_uuid, $payload ) {
				return $this->saas_client->post( 'contacts/' . $contact_uuid . '/tags/attach', $payload );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'contacts/' . $contact_uuid . '/tags/attach',
				'payload'      => $payload,
				'operation'    => 'attach_tags_to_contact',
			),
			$options
		);
	}

	/**
	 * Detach lists from a contact
	 *
	 * @since 0.0.3
	 *
	 * @param string $contact_uuid Contact UUID.
	 * @param array  $list_uuids   Array of list UUIDs to detach.
	 * @param array  $options      Optional. Additional options including 'source'.
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function detach_lists_from_contact( $contact_uuid, $list_uuids, $options = array() ) {
		if ( empty( $contact_uuid ) ) {
			return new WP_Error(
				'missing_contact_uuid',
				__( 'Contact UUID is required.', 'surecontact' )
			);
		}

		if ( empty( $list_uuids ) || ! is_array( $list_uuids ) ) {
			return new WP_Error(
				'invalid_list_uuids',
				__( 'List UUIDs must be a non-empty array.', 'surecontact' )
			);
		}

		$payload = array(
			'list_uuids' => array_values( $list_uuids ),
		);

		// Execute with automatic retry logic.
		return $this->execute_with_retry(
			function () use ( $contact_uuid, $payload ) {
				return $this->saas_client->post( 'contacts/' . $contact_uuid . '/lists/detach', $payload );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'contacts/' . $contact_uuid . '/lists/detach',
				'payload'      => $payload,
				'operation'    => 'detach_lists_from_contact',
			),
			$options
		);
	}

	/**
	 * Detach tags from a contact
	 *
	 * @since 0.0.3
	 *
	 * @param string $contact_uuid Contact UUID.
	 * @param array  $tag_uuids    Array of tag UUIDs to detach.
	 * @param array  $options      Optional. Additional options including 'source'.
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function detach_tags_from_contact( $contact_uuid, $tag_uuids, $options = array() ) {
		if ( empty( $contact_uuid ) ) {
			return new WP_Error(
				'missing_contact_uuid',
				__( 'Contact UUID is required.', 'surecontact' )
			);
		}

		if ( empty( $tag_uuids ) || ! is_array( $tag_uuids ) ) {
			return new WP_Error(
				'invalid_tag_uuids',
				__( 'Tag UUIDs must be a non-empty array.', 'surecontact' )
			);
		}

		$payload = array(
			'tag_uuids' => array_values( $tag_uuids ),
		);

		// Execute with automatic retry logic.
		return $this->execute_with_retry(
			function () use ( $contact_uuid, $payload ) {
				return $this->saas_client->post( 'contacts/' . $contact_uuid . '/tags/detach', $payload );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'contacts/' . $contact_uuid . '/tags/detach',
				'payload'      => $payload,
				'operation'    => 'detach_tags_from_contact',
			),
			$options
		);
	}

	/**
	 * Update a contact's email address
	 *
	 * Updates the email address of an existing contact by looking up the contact
	 * using the old email and updating it to the new email. This prevents duplicate
	 * contacts from being created when a user changes their email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $old_email The current email address of the contact.
	 * @param string $new_email The new email address to update to.
	 * @param array  $options   Optional. Additional options including 'source'.
	 * @return array|WP_Error Response array with updated contact data or WP_Error on failure.
	 */
	public function update_email( $old_email, $new_email, $options = array() ) {
		if ( empty( $old_email ) || ! is_email( $old_email ) ) {
			return new WP_Error(
				'invalid_old_email',
				__( 'Valid old email address is required.', 'surecontact' )
			);
		}

		if ( empty( $new_email ) || ! is_email( $new_email ) ) {
			return new WP_Error(
				'invalid_new_email',
				__( 'Valid new email address is required.', 'surecontact' )
			);
		}

		if ( strtolower( $old_email ) === strtolower( $new_email ) ) {
			return new WP_Error(
				'same_email',
				__( 'Old and new email addresses are the same.', 'surecontact' )
			);
		}

		$payload = array(
			'old_email' => $old_email,
			'new_email' => $new_email,
		);

		// Execute with automatic retry logic.
		return $this->execute_with_retry(
			function () use ( $payload ) {
				return $this->saas_client->post( 'wordpress/update-email', $payload );
			},
			array(
				'request_type' => 'POST',
				'endpoint'     => 'wordpress/update-email',
				'payload'      => $payload,
				'operation'    => 'update_email',
			),
			$options
		);
	}
}
