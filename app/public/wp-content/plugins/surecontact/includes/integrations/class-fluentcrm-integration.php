<?php
/**
 * FluentCRM Integration
 *
 * Handles FluentCRM contact synchronization with bulk import support
 *
 * @since 0.0.1
 *
 * @package SureContact
 */

namespace SureContact\Integrations;

use SureContact\Logger;
use SureContact\Field_Formatter;
use SureContact\Company_Service;
use SureContact\Company_Field_Mapper;
use SureContact\SaaS_Client;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FluentCRM_Integration
 *
 * Integrates FluentCRM contacts with SureContact, supporting bulk import for large datasets
 *
 * @since 0.0.1
 */
class FluentCRM_Integration extends Base_Integration {

	/**
	 * FluentCRM Contact Sync handler.
	 *
	 * @since 1.2.0
	 *
	 * @var FluentCRM_Contact_Sync
	 */
	private $contact_sync;

	/**
	 * FluentCRM Company Sync handler.
	 *
	 * @since 1.5.1
	 *
	 * @var FluentCRM_Company_Sync|null
	 */
	private $company_sync;

	/**
	 * Company Service instance.
	 *
	 * @since 1.5.1
	 *
	 * @var Company_Service|null
	 */
	private $company_service;

	/**
	 * Whether `ensure_company_custom_field_mappings_fresh()` has already
	 * validated the cached crm_name mappings in the current request.
	 *
	 * @since 1.5.1
	 *
	 * @var bool
	 */
	private $company_custom_field_mappings_validated = false;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		$this->slug                  = 'fluentcrm';
		$this->name                  = 'FluentCRM';
		$this->description           = __( 'Sync FluentCRM contacts with bulk import support for large datasets', 'surecontact' );
		$this->docs_url              = '';
		$this->require_field_mapping = true;
		$this->dependency            = 'FluentCrm\App\Models\Subscriber';

		parent::__construct();

		// Initialize sync handler (registers with Bulk_Sync_Service).
		$this->contact_sync = new FluentCRM_Contact_Sync( $this, $this->contact_service );

		// Initialize company sync only when the FluentCRM Company module is enabled.
		if ( $this->is_company_module_enabled() ) {
			$this->company_service = new Company_Service();
			$this->company_sync    = new FluentCRM_Company_Sync( $this, $this->company_service, $this->contact_service );
		}

		// Register sync type.
		add_filter( 'surecontact_available_sync_types', array( $this, 'register_sync_type' ) );
	}

	/**
	 * Check whether the FluentCRM Company module is available.
	 *
	 * @since 1.5.1
	 *
	 * @return bool
	 */
	public function is_company_module_enabled() {
		if ( ! class_exists( '\\FluentCrm\\App\\Services\\Helper' ) || ! class_exists( '\\FluentCrm\\App\\Models\\Company' ) ) {
			return false;
		}

		if ( ! method_exists( '\\FluentCrm\\App\\Services\\Helper', 'isCompanyEnabled' ) ) {
			// Older FluentCRM Pro versions ship the model without the feature flag — assume enabled.
			return true;
		}

		return (bool) \FluentCrm\App\Services\Helper::isCompanyEnabled();
	}

	/**
	 * Get the Company_Service instance (null when the company module is disabled).
	 *
	 * @since 1.5.1
	 *
	 * @return Company_Service|null
	 */
	public function get_company_service() {
		return $this->company_service;
	}

	/**
	 * Initialize integration hooks
	 *
	 * @since 0.0.1
	 */
	protected function init() {

		// Real-time sync hooks for FluentCRM contact events.
		add_action( 'fluent_crm/contact_created', array( $this, 'handle_contact_created' ), 10, 1 );
		add_action( 'fluent_crm/contact_updated', array( $this, 'handle_contact_updated' ), 10, 2 );
		add_action( 'fluent_crm/contact_email_changed', array( $this, 'handle_contact_email_changed' ), 10, 2 );

		// Hooks for when lists/tags are added or removed from contacts.
		add_action( 'fluentcrm_contact_added_to_lists', array( $this, 'handle_lists_attached' ), 10, 2 );
		add_action( 'fluentcrm_contact_removed_from_lists', array( $this, 'handle_lists_detached' ), 10, 2 );
		add_action( 'fluentcrm_contact_added_to_tags', array( $this, 'handle_tags_attached' ), 10, 2 );
		add_action( 'fluentcrm_contact_removed_from_tags', array( $this, 'handle_tags_detached' ), 10, 2 );

		// Real-time hooks for FluentCRM company events (only when the module is active).
		if ( $this->is_company_module_enabled() ) {
			add_action( 'fluent_crm/company_created', array( $this, 'handle_company_created' ), 10, 2 );
			add_action( 'fluent_crm/company_updated', array( $this, 'handle_company_updated' ), 10, 2 );
			add_action( 'fluent_crm/before_company_delete', array( $this, 'handle_company_delete' ), 10, 1 );
			add_action( 'fluentcrm_contact_added_to_companies', array( $this, 'handle_contact_added_to_companies' ), 10, 2 );
			add_action( 'fluentcrm_contact_removed_from_companies', array( $this, 'handle_contact_removed_from_companies' ), 10, 2 );
			add_action( 'fluent_crm/company_note_added', array( $this, 'handle_company_note_added' ), 10, 3 );
			add_action( 'fluent_crm/company_note_updated', array( $this, 'handle_company_note_updated' ), 10, 3 );
			add_action( 'fluent_crm/company_note_deleted', array( $this, 'handle_company_note_deleted' ), 10, 2 );

			// After a contact bulk-sync job completes, run a linking sweep so any
			// pre-synced companies (Order B: Companies → Contacts) get their
			// `company_contact` pivot rows back-filled now that contacts exist on SaaS.
			add_action( 'surecontact_bulk_sync_completed', array( $this, 'handle_bulk_sync_completed' ), 10, 2 );
		}
	}

	/**
	 * Add FluentCRM field group
	 *
	 * @since 0.0.1
	 *
	 * @param array $groups Existing field groups.
	 * @return array Modified field groups
	 */
	public function add_meta_field_group( $groups ) {
		$groups['fluentcrm'] = array(
			'title' => __( 'FluentCRM', 'surecontact' ),
			'url'   => 'https://fluentcrm.com/docs/',
		);

		// No 'fluentcrm_company' group: company sync uses a fixed transform
		// (Company_Field_Mapper::map_to_crm_format()) and has no user-configurable
		// field mapping, so registering source fields here would only pollute the
		// contact field mapping UI without offering any meaningful choice.
		return $groups;
	}

	/**
	 * Add FluentCRM-specific fields
	 *
	 * @since 0.0.1
	 *
	 * @param array $fields Existing meta fields.
	 * @return array Modified meta fields
	 */
	public function add_meta_fields( $fields ) {
		// FluentCRM contact fields.
		$fluentcrm_fields = array(
			'fc_prefix'          => array(
				'label' => __( 'Name Prefix', 'surecontact' ),
				'type'  => 'text',
			),
			'fc_first_name'      => array(
				'label' => __( 'First Name', 'surecontact' ),
				'type'  => 'text',
			),
			'fc_last_name'       => array(
				'label' => __( 'Last Name', 'surecontact' ),
				'type'  => 'text',
			),
			'fc_email'           => array(
				'label' => __( 'Email', 'surecontact' ),
				'type'  => 'email',
			),
			'fc_phone'           => array(
				'label' => __( 'Phone', 'surecontact' ),
				'type'  => 'phone',
			),
			'fc_address_line_1'  => array(
				'label' => __( 'Address Line 1', 'surecontact' ),
				'type'  => 'text',
			),
			'fc_address_line_2'  => array(
				'label' => __( 'Address Line 2', 'surecontact' ),
				'type'  => 'text',
			),
			'fc_city'            => array(
				'label' => __( 'City', 'surecontact' ),
				'type'  => 'text',
			),
			'fc_state'           => array(
				'label' => __( 'State', 'surecontact' ),
				'type'  => 'text',
			),
			'fc_postal_code'     => array(
				'label' => __( 'Postal Code', 'surecontact' ),
				'type'  => 'text',
			),
			'fc_country'         => array(
				'label' => __( 'Country', 'surecontact' ),
				'type'  => 'text',
			),
			'fc_timezone'        => array(
				'label' => __( 'Timezone', 'surecontact' ),
				'type'  => 'text',
			),
			'fc_date_of_birth'   => array(
				'label' => __( 'Date of Birth', 'surecontact' ),
				'type'  => 'date',
			),
			'fc_status'          => array(
				'label' => __( 'Contact Status', 'surecontact' ),
				'type'  => 'text',
			),
			'fc_contact_type'    => array(
				'label' => __( 'Contact Type', 'surecontact' ),
				'type'  => 'text',
			),
			'fc_source'          => array(
				'label' => __( 'Source', 'surecontact' ),
				'type'  => 'text',
			),
			'fc_life_time_value' => array(
				'label' => __( 'Life Time Value', 'surecontact' ),
				'type'  => 'decimal',
			),
			'fc_total_points'    => array(
				'label' => __( 'Total Points', 'surecontact' ),
				'type'  => 'integer',
			),
			'fc_created_at'      => array(
				'label' => __( 'Contact Created Date', 'surecontact' ),
				'type'  => 'datetime',
			),
			'fc_last_activity'   => array(
				'label' => __( 'Last Activity Date', 'surecontact' ),
				'type'  => 'datetime',
			),
			'fc_updated_at'      => array(
				'label' => __( 'Contact Updated Date', 'surecontact' ),
				'type'  => 'datetime',
			),
		);

		// Add group to all fields.
		foreach ( $fluentcrm_fields as $key => &$config ) {
			$config['group'] = 'fluentcrm';
			$fields[ $key ]  = $config;
		}
		unset( $config );

		// No `fcc_*` source fields: company sync uses a fixed transform with no
		// user-configurable field mapping. See Company_Field_Mapper for the
		// hardcoded FluentCRM Company → SureContact Company mapping.

		return $fields;
	}

	/**
	 * Get integration-specific settings fields
	 *
	 * @since 0.0.1
	 *
	 * @return array Settings fields configuration
	 */
	public function get_settings_fields() {
		$settings = array(
			'enable_realtime_sync' => array(
				'label'       => __( 'Enable Real-time Sync', 'surecontact' ),
				'description' => __( 'Automatically sync FluentCRM contacts when they are created or updated', 'surecontact' ),
				'type'        => 'checkbox',
				'default'     => true,
			),
			'sync_status_filter'   => array(
				'label'       => __( 'Sync Only Subscribed Contacts', 'surecontact' ),
				'description' => __( 'Only sync contacts with "subscribed" status (recommended)', 'surecontact' ),
				'type'        => 'checkbox',
				'default'     => true,
			),
			'sync_lists'           => array(
				'label'       => __( 'Sync Lists', 'surecontact' ),
				'description' => __( 'Sync FluentCRM lists assigned to each contact (applies to both real-time and bulk sync)', 'surecontact' ),
				'type'        => 'checkbox',
				'default'     => true,
			),
			'sync_tags'            => array(
				'label'       => __( 'Sync Tags', 'surecontact' ),
				'description' => __( 'Sync FluentCRM tags assigned to each contact (applies to both real-time and bulk sync)', 'surecontact' ),
				'type'        => 'checkbox',
				'default'     => true,
			),
			'sync_custom_fields'   => array(
				'label'       => __( 'Sync Custom Fields', 'surecontact' ),
				'description' => __( 'Sync FluentCRM custom fields from each contact (applies to both real-time and bulk sync)', 'surecontact' ),
				'type'        => 'checkbox',
				'default'     => true,
			),
			'bulk_sync_lists'      => array(
				'label'       => __( 'Add to Lists', 'surecontact' ),
				'description' => __( 'Select lists to add to ALL FluentCRM contacts when synced (in addition to their individual lists)', 'surecontact' ),
				'type'        => 'list-select',
				'default'     => array(),
			),
			'bulk_sync_tags'       => array(
				'label'       => __( 'Add Tags', 'surecontact' ),
				'description' => __( 'Select tags to add to ALL FluentCRM contacts when synced (in addition to their individual tags)', 'surecontact' ),
				'type'        => 'tag-select',
				'default'     => array(),
			),
		);

		// Companies need no extra settings: they ride the existing real-time toggle
		// and are gated only by the FluentCRM Company module being enabled.
		return $settings;
	}

	/**
	 * Handle contact created event
	 *
	 * @since 0.0.1
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber FluentCRM Subscriber object.
	 * @return void
	 */
	public function handle_contact_created( $subscriber ) {
		if ( ! $this->get_setting( 'enable_realtime_sync', true ) ) {
			return;
		}

		// Check status filter.
		if ( $this->get_setting( 'sync_status_filter', true ) && $subscriber->status !== 'subscribed' ) {
			return;
		}

		$subscriber_data = $this->prepare_subscriber_data( $subscriber );
		$mapped_data     = $this->normalize_data( $subscriber_data );

		// Skip if email is missing or invalid (validation done in prepare_subscriber_data).
		if ( empty( $mapped_data['primary_fields']['email'] ) ) {
			Logger::warning(
				'FluentCRM Integration',
				sprintf(
					'Skipping subscriber ID %d on creation: Invalid or missing email address (%s)',
					$subscriber->id,
					$subscriber->email ?? 'empty'
				)
			);
			return;
		}

		$result = $this->send_to_crm( $mapped_data, $subscriber->user_id ?? 0 );

		if ( ! is_wp_error( $result ) && isset( $result['contact_id'] ) ) {
			$subscriber->updateMeta( 'surecontact_contact_uuid', $result['contact_id'], 'surecontact' );
		}
	}

	/**
	 * Handle contact updated event
	 *
	 * @since 0.0.1
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber   FluentCRM Subscriber object.
	 * @param array                            $dirty_fields Changed fields.
	 * @return void
	 */
	public function handle_contact_updated( $subscriber, $dirty_fields ) {
		if ( ! $this->get_setting( 'enable_realtime_sync', true ) ) {
			return;
		}

		// Skip if no meaningful changes.
		if ( empty( $dirty_fields ) ) {
			return;
		}

		// Check status filter.
		if ( $this->get_setting( 'sync_status_filter', true ) && $subscriber->status !== 'subscribed' ) {
			return;
		}

		$subscriber_data = $this->prepare_subscriber_data( $subscriber );
		$mapped_data     = $this->normalize_data( $subscriber_data );

		// Skip if email is missing or invalid (validation done in prepare_subscriber_data).
		if ( empty( $mapped_data['primary_fields']['email'] ) ) {
			Logger::warning(
				'FluentCRM Integration',
				sprintf(
					'Skipping subscriber ID %d on update: Invalid or missing email address (%s)',
					$subscriber->id,
					$subscriber->email ?? 'empty'
				)
			);
			return;
		}

		$result = $this->send_to_crm( $mapped_data, $subscriber->user_id ?? 0 );

		if ( is_wp_error( $result ) ) {
			return;
		}

		$contact_uuid = $result['contact_id'] ?? null;

		// wordpress/sync-contact upserts by email — if the previous contact was
		// deleted on the SaaS, this call returns a brand-new UUID. Writing it
		// back is what lets stale local meta self-heal whenever a FluentCRM
		// edit fires (e.g. between a SaaS deletion and the next bulk sync).
		if ( $contact_uuid ) {
			$subscriber->updateMeta( 'surecontact_contact_uuid', $contact_uuid, 'surecontact' );
		}
	}

	/**
	 * Handle contact email changed event
	 *
	 * This hook is fired by FluentCRM when a contact's email is changed,
	 * providing both the subscriber (with new email) and the old email address.
	 *
	 * @since 1.0.0
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber FluentCRM Subscriber object with new email.
	 * @param string                           $old_email  The previous email address.
	 * @return void
	 */
	public function handle_contact_email_changed( $subscriber, $old_email ) {
		if ( ! $this->get_setting( 'enable_realtime_sync', true ) ) {
			return;
		}

		$new_email = $subscriber->email ?? '';

		if ( empty( $old_email ) || empty( $new_email ) ) {
			return;
		}

		if ( strtolower( $old_email ) === strtolower( $new_email ) ) {
			return;
		}

		$this->contact_service->update_email( $old_email, $new_email, $subscriber->user_id ?? 0, array( 'source' => $this->slug ) );
	}

	/**
	 * Handle lists being attached to a contact
	 *
	 * @since 0.0.3
	 *
	 * @param array                            $list_ids   Array of FluentCRM list IDs.
	 * @param \FluentCrm\App\Models\Subscriber $subscriber FluentCRM Subscriber object.
	 * @return void
	 */
	public function handle_lists_attached( $list_ids, $subscriber ) {
		$this->handle_metadata_change( $list_ids, $subscriber, 'lists', 'attach' );
	}

	/**
	 * Handle lists being detached from a contact
	 *
	 * @since 0.0.3
	 *
	 * @param array                            $list_ids   Array of FluentCRM list IDs.
	 * @param \FluentCrm\App\Models\Subscriber $subscriber FluentCRM Subscriber object.
	 * @return void
	 */
	public function handle_lists_detached( $list_ids, $subscriber ) {
		$this->handle_metadata_change( $list_ids, $subscriber, 'lists', 'detach' );
	}

	/**
	 * Handle tags being attached to a contact
	 *
	 * @since 0.0.3
	 *
	 * @param array                            $tag_ids    Array of FluentCRM tag IDs.
	 * @param \FluentCrm\App\Models\Subscriber $subscriber FluentCRM Subscriber object.
	 * @return void
	 */
	public function handle_tags_attached( $tag_ids, $subscriber ) {
		$this->handle_metadata_change( $tag_ids, $subscriber, 'tags', 'attach' );
	}

	/**
	 * Handle tags being detached from a contact
	 *
	 * @since 0.0.3
	 *
	 * @param array                            $tag_ids    Array of FluentCRM tag IDs.
	 * @param \FluentCrm\App\Models\Subscriber $subscriber FluentCRM Subscriber object.
	 * @return void
	 */
	public function handle_tags_detached( $tag_ids, $subscriber ) {
		$this->handle_metadata_change( $tag_ids, $subscriber, 'tags', 'detach' );
	}

	/**
	 * Handle lists/tags changes (attach or detach)
	 *
	 * Simplified handler that validates, converts IDs, and calls the API.
	 * If contact doesn't exist in SureContact, creates it first.
	 *
	 * @since 0.0.3
	 *
	 * @param array                            $ids        Array of FluentCRM list/tag IDs.
	 * @param \FluentCrm\App\Models\Subscriber $subscriber FluentCRM Subscriber object.
	 * @param string                           $type       Type: 'lists' or 'tags'.
	 * @param string                           $action     Action: 'attach' or 'detach'.
	 * @return void
	 */
	private function handle_metadata_change( $ids, $subscriber, $type, $action ) {
		// Early validation checks.
		if ( ! $this->should_sync_metadata_change( $subscriber, $type ) || empty( $ids ) ) {
			return;
		}

		// Get contact UUID.
		$contact_uuid = $this->contact_service->find_contact_id_by_email( $subscriber->email );

		// If contact doesn't exist, create it first with the current lists/tags.
		if ( ! $contact_uuid ) {
			$contact_uuid = $this->create_contact_for_metadata_change( $subscriber );
			if ( ! $contact_uuid ) {
				return; // Failed to create contact.
			}
			// After creating, the contact already has all current lists/tags, so no need to attach.
			return;
		}

		// Convert FluentCRM IDs to SureContact UUIDs.
		$uuids = $this->get_metadata_uuids( $ids, $type );
		if ( empty( $uuids ) ) {
			return;
		}

		// Call the appropriate API method.
		$this->call_metadata_api( $contact_uuid, $uuids, $type, $action );
	}

	/**
	 * Create contact in SureContact when lists/tags change
	 *
	 * This is called when lists/tags are modified but the contact doesn't exist yet.
	 * It creates the contact with all current lists/tags.
	 *
	 * @since 0.0.3
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber FluentCRM Subscriber object.
	 * @return string|false Contact UUID or false on failure.
	 */
	private function create_contact_for_metadata_change( $subscriber ) {
		$subscriber_data = $this->prepare_subscriber_data( $subscriber );
		$mapped_data     = $this->normalize_data( $subscriber_data );

		// Create contact.
		$user_id = $subscriber->user_id ? (int) $subscriber->user_id : 0;
		$result  = $this->send_to_crm( $mapped_data, $user_id );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		$contact_uuid = $result['contact_id'] ?? false;

		if ( $contact_uuid ) {
			$this->attach_contact_to_mapped_companies( $subscriber, $contact_uuid );
		}

		return $contact_uuid;
	}

	/**
	 * Check if metadata change should be synced
	 *
	 * @since 0.0.3
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber FluentCRM Subscriber object.
	 * @param string                           $type       Type: 'lists' or 'tags'.
	 * @return bool
	 */
	private function should_sync_metadata_change( $subscriber, $type ) {
		// Check if real-time sync is enabled.
		if ( ! $this->get_setting( 'enable_realtime_sync', true ) ) {
			return false;
		}

		// Check if this metadata type is enabled.
		$setting_key = $type === 'lists' ? 'sync_lists' : 'sync_tags';
		if ( ! $this->is_setting_enabled( $setting_key, true ) ) {
			return false;
		}

		// Check status filter.
		if ( $this->get_setting( 'sync_status_filter', true ) && $subscriber->status !== 'subscribed' ) {
			return false;
		}

		// Quick check for empty email (full validation done in prepare_subscriber_data).
		if ( empty( $subscriber->email ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get SureContact UUIDs from FluentCRM IDs
	 *
	 * @since 0.0.3
	 *
	 * @param array  $fc_ids FluentCRM list/tag IDs.
	 * @param string $type   Type: 'lists' or 'tags'.
	 * @return array Array of SureContact UUIDs.
	 */
	private function get_metadata_uuids( $fc_ids, $type ) {
		if ( ! is_array( $fc_ids ) ) {
			$fc_ids = array( $fc_ids );
		}

		// Add 'fc_' prefix to IDs.
		$fc_ids_with_prefix = array_map(
			function ( $id ) {
				return 'fc_' . $id;
			},
			$fc_ids
		);

		// Try to get UUIDs from existing mappings.
		$uuids = $this->convert_ids_to_uuids( $fc_ids_with_prefix, $type );

		// If some IDs are not mapped, sync them first.
		if ( count( $uuids ) < count( $fc_ids ) ) {
			$items_data = $this->fetch_metadata_from_fluentcrm( $fc_ids, $type );
			if ( ! empty( $items_data ) ) {
				$uuids = $this->sync_and_get_uuids( $items_data, $type );
			}
		}

		return $uuids;
	}

	/**
	 * Fetch metadata from FluentCRM by IDs
	 *
	 * @since 0.0.3
	 *
	 * @param array  $ids  FluentCRM list/tag IDs.
	 * @param string $type Type: 'lists' or 'tags'.
	 * @return array Array of metadata items.
	 */
	private function fetch_metadata_from_fluentcrm( $ids, $type ) {
		if ( $type === 'lists' && class_exists( 'FluentCrm\App\Models\Lists' ) ) {
			return \FluentCrm\App\Models\Lists::whereIn( 'id', $ids )
				->get()
				->map(
					function ( $item ) {
						return array(
							'id'          => $item->id,
							'name'        => $item->title,
							'description' => $item->description ?? '',
						);
					}
				)
				->toArray();
		}

		if ( $type === 'tags' && class_exists( 'FluentCrm\App\Models\Tag' ) ) {
			return \FluentCrm\App\Models\Tag::whereIn( 'id', $ids )
				->get()
				->map(
					function ( $item ) {
						return array(
							'id'   => $item->id,
							'name' => $item->title,
						);
					}
				)
				->toArray();
		}

		return array();
	}

	/**
	 * Call the appropriate Lists/Tags API method
	 *
	 * @since 0.0.3
	 *
	 * @param string $contact_uuid Contact UUID.
	 * @param array  $uuids        Array of list/tag UUIDs.
	 * @param string $type         Type: 'lists' or 'tags'.
	 * @param string $action       Action: 'attach' or 'detach'.
	 * @return void
	 */
	private function call_metadata_api( $contact_uuid, $uuids, $type, $action ) {
		// Use Contact_Service for single source of truth.
		if ( $type === 'lists' ) {
			$result = ( $action === 'attach' )
				? $this->contact_service->attach_lists_to_contact( $contact_uuid, $uuids, array( 'source' => $this->slug ) )
				: $this->contact_service->detach_lists_from_contact( $contact_uuid, $uuids, array( 'source' => $this->slug ) );
		} else { // tags.
			$result = ( $action === 'attach' )
				? $this->contact_service->attach_tags_to_contact( $contact_uuid, $uuids, array( 'source' => $this->slug ) )
				: $this->contact_service->detach_tags_from_contact( $contact_uuid, $uuids, array( 'source' => $this->slug ) );
		}
	}

	/**
	 * UNIFIED: Prepare complete subscriber data with all metadata
	 * Single method for both real-time and bulk sync - eliminates duplication
	 *
	 * @since 0.0.3
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber FluentCRM Subscriber object.
	 * @return array Contact data with fc_* fields, lists, tags, custom fields
	 */
	public function prepare_subscriber_data( $subscriber ) {
		// Build contact data with fc_* prefixed fields.
		// Note: Field length validation (255 chars for names) handled by Field_Formatter during normalization.
		$data = array_filter(
			array(
				'fc_email'           => $subscriber->email ?? '',
				'fc_prefix'          => $subscriber->prefix,
				'fc_first_name'      => $subscriber->first_name,
				'fc_last_name'       => $subscriber->last_name,
				'fc_phone'           => $subscriber->phone,
				'fc_address_line_1'  => $subscriber->address_line_1,
				'fc_address_line_2'  => $subscriber->address_line_2,
				'fc_city'            => $subscriber->city,
				'fc_state'           => $subscriber->state,
				'fc_postal_code'     => $subscriber->postal_code,
				'fc_country'         => $subscriber->country,
				'fc_timezone'        => $subscriber->timezone,
				'fc_date_of_birth'   => $subscriber->date_of_birth,
				'fc_status'          => $subscriber->status,
				'fc_contact_type'    => $subscriber->contact_type,
				'fc_source'          => $subscriber->source,
				'fc_life_time_value' => $subscriber->life_time_value,
				'fc_total_points'    => $subscriber->total_points,
				'fc_created_at'      => $subscriber->created_at ?? null,
				'fc_last_activity'   => $subscriber->last_activity ?? null,
				'fc_updated_at'      => $subscriber->updated_at ?? null,
			)
		);

		// Add lists, tags, and custom fields.
		$sync_lists_enabled  = $this->is_setting_enabled( 'sync_lists', true );
		$sync_tags_enabled   = $this->is_setting_enabled( 'sync_tags', true );
		$sync_custom_enabled = $this->is_setting_enabled( 'sync_custom_fields', true );

		// Collect list UUIDs (subscriber's individual lists + global bulk_sync_lists).
		$list_uuids = array();

		if ( $sync_lists_enabled ) {
			if ( ! $subscriber->relationLoaded( 'lists' ) ) {
				$subscriber->load( 'lists' );
			}

			if ( ! empty( $subscriber->lists ) ) {
				$fc_list_ids   = array();
				$fc_lists_data = array();

				foreach ( $subscriber->lists as $list ) {
					$fc_list_ids[]   = 'fc_' . $list->id;
					$fc_lists_data[] = array(
						'id'          => $list->id,
						'name'        => $list->title,
						'description' => ! empty( $list->description ) ? $list->description : '',
					);
				}

				// Convert FluentCRM list IDs to SureContact UUIDs.
				$list_uuids = $this->convert_ids_to_uuids( $fc_list_ids, 'lists' );

				// If some lists are not mapped, sync them on-demand.
				if ( count( $list_uuids ) < count( $fc_list_ids ) ) {
					$list_uuids = $this->sync_and_get_uuids( $fc_lists_data, 'lists' );
				}
			}
		}

		// Add global bulk_sync_lists from settings.
		$bulk_lists = $this->get_setting( 'bulk_sync_lists', array() );
		if ( ! empty( $bulk_lists ) ) {
			$bulk_list_uuids = $this->extract_uuids( $bulk_lists );
			$list_uuids      = array_merge( $list_uuids, $bulk_list_uuids );
		}

		if ( ! empty( $list_uuids ) ) {
			$data['list_uuids'] = $this->extract_uuids( $list_uuids );
		}

		// Collect tag UUIDs (subscriber's individual tags + global bulk_sync_tags).
		$tag_uuids = array();

		if ( $sync_tags_enabled ) {
			if ( ! $subscriber->relationLoaded( 'tags' ) ) {
				$subscriber->load( 'tags' );
			}

			if ( ! empty( $subscriber->tags ) ) {
				$fc_tag_ids   = array();
				$fc_tags_data = array();

				foreach ( $subscriber->tags as $tag ) {
					$fc_tag_ids[]   = 'fc_' . $tag->id;
					$fc_tags_data[] = array(
						'id'   => $tag->id,
						'name' => $tag->title,
					);
				}

				// Convert FluentCRM tag IDs to SureContact UUIDs.
				$tag_uuids = $this->convert_ids_to_uuids( $fc_tag_ids, 'tags' );

				// If some tags are not mapped, sync them on-demand.
				if ( count( $tag_uuids ) < count( $fc_tag_ids ) ) {
					$tag_uuids = $this->sync_and_get_uuids( $fc_tags_data, 'tags' );
				}
			}
		}

		// Add global bulk_sync_tags from settings.
		$bulk_tags = $this->get_setting( 'bulk_sync_tags', array() );
		if ( ! empty( $bulk_tags ) ) {
			$bulk_tag_uuids = $this->extract_uuids( $bulk_tags );
			$tag_uuids      = array_merge( $tag_uuids, $bulk_tag_uuids );
		}

		if ( ! empty( $tag_uuids ) ) {
			$data['tag_uuids'] = $this->extract_uuids( $tag_uuids );
		}

		// Add custom fields.
		if ( $sync_custom_enabled ) {
			$custom_fields = $subscriber->custom_fields();
			if ( ! empty( $custom_fields ) ) {
				$mapped_custom_fields = $this->sync_and_map_custom_fields( $custom_fields );
				if ( ! empty( $mapped_custom_fields ) ) {
					$data['custom_fields'] = $mapped_custom_fields;
				}
			}
		}

		return $data;
	}

	/**
	 * Override normalize_data to preserve FluentCRM-specific fields
	 *
	 * The parent normalize_data only processes fields in global field mappings.
	 * FluentCRM adds list_uuids, tag_uuids, and custom_fields directly which
	 * would be lost during normalization. This override preserves them.
	 *
	 * @since 0.0.3
	 *
	 * @param array $raw_data Raw data from prepare_subscriber_data.
	 * @return array Normalized data with FluentCRM fields preserved
	 */
	public function normalize_data( $raw_data ) {
		// Extract FluentCRM-specific fields before normalization.
		$list_uuids    = $raw_data['list_uuids'] ?? null;
		$tag_uuids     = $raw_data['tag_uuids'] ?? null;
		$custom_fields = $raw_data['custom_fields'] ?? null;

		// Remove them from raw_data so parent doesn't process them.
		unset( $raw_data['list_uuids'], $raw_data['tag_uuids'], $raw_data['custom_fields'] );

		// Call parent to normalize fc_* fields.
		$normalized = parent::normalize_data( $raw_data );

		// Add back FluentCRM-specific fields.
		if ( ! empty( $list_uuids ) ) {
			$normalized['list_uuids'] = $list_uuids;
		}

		if ( ! empty( $tag_uuids ) ) {
			$normalized['tag_uuids'] = $tag_uuids;
		}

		if ( ! empty( $custom_fields ) ) {
			if ( ! isset( $normalized['custom_fields'] ) ) {
				$normalized['custom_fields'] = array();
			}
			$normalized['custom_fields'] = array_merge( $normalized['custom_fields'], $custom_fields );
		}

		return $normalized;
	}

	/**
	 * Sync lists/tags on-demand and return their UUIDs
	 *
	 * Syncs items with SureContact (creating or matching) and returns their UUIDs.
	 * Updates the stored mappings for future use.
	 *
	 * @since 0.0.3
	 *
	 * @param array  $items_data Array of items with 'id' and 'name' (and optionally 'description').
	 * @param string $type       Type of metadata: 'lists' or 'tags'.
	 * @return array Array of SureContact UUIDs
	 */
	public function sync_and_get_uuids( $items_data, $type ) {
		if ( empty( $items_data ) ) {
			return array();
		}

		// Get existing mappings to avoid unnecessary API calls.
		$existing_mappings = $this->get_metadata_mapping( $type );

		$all_mappings  = $existing_mappings; // Start with existing mappings.
		$items_to_sync = array();

		// Separate already-mapped items from unmapped ones.
		foreach ( $items_data as $item ) {
			$external_id = 'fc_' . $item['id'];

			if ( ! isset( $existing_mappings[ $external_id ] ) ) {
				// Not mapped - needs to be synced.
				$items_to_sync[] = $item;
			}
		}

		// Only sync unmapped items.
		if ( ! empty( $items_to_sync ) ) {
			// Determine the correct type for sync_metadata (singular: 'list' or 'tag').
			$sync_type = rtrim( $type, 's' );

			// Sync unmapped items and get mappings (external_id => crm_uuid).
			$new_mappings = $this->contact_service->sync_metadata( $items_to_sync, $sync_type );

			if ( ! empty( $new_mappings ) ) {
				// Update stored mappings.
				$this->update_metadata_mapping( $type, $new_mappings );

				// Merge new mappings with existing ones.
				$all_mappings = array_merge( $all_mappings, $new_mappings );
			}
		}

		// Extract UUIDs in the same order as items_data to maintain consistency.
		$ordered_uuids = array();
		foreach ( $items_data as $item ) {
			$external_id = 'fc_' . $item['id'];
			if ( isset( $all_mappings[ $external_id ] ) ) {
				$ordered_uuids[] = $all_mappings[ $external_id ];
			}
		}

		return $this->extract_uuids( $ordered_uuids );
	}

	/**
	 * Sync and map FluentCRM custom fields to SureContact
	 *
	 * Ensures FluentCRM custom fields exist in SureContact and returns mapped values.
	 *
	 * Flow:
	 * 1. Get FluentCRM field definitions for actual field types (once per call)
	 * 2. Check existing mapping - if mapped, use it and skip CRM API call
	 * 3. If not mapped, fetch CRM fields to check if field exists
	 * 4. If exists in CRM, create mapping from response data
	 * 5. If not in CRM, create new field then map it
	 *
	 * @since 0.0.3
	 *
	 * @param array $custom_fields FluentCRM custom fields array (key => value).
	 * @return array Mapped custom fields ready for SureContact (field_name => value)
	 */
	private function sync_and_map_custom_fields( $custom_fields ) {
		if ( empty( $custom_fields ) ) {
			return array();
		}

		$mapped_fields  = array();
		$field_mappings = $this->get_metadata_mapping( 'custom_fields' );

		// Get FluentCRM field definitions once for all custom fields in this call.
		$fc_field_definitions = $this->get_fluentcrm_field_definitions();

		// We'll fetch CRM fields only once when needed (lazy loading).
		$existing_fields_map = null;

		foreach ( $custom_fields as $fc_key => $value ) {
			// Skip empty values.
			if ( $value === null || $value === '' ) {
				continue;
			}

			// Get actual field type from FluentCRM definitions, fallback to detection.
			$field_type = $this->get_field_type_from_definitions( $fc_key, $fc_field_definitions, $value );

			// STEP 1: Check if field is already mapped in our local mapping.
			if ( isset( $field_mappings[ $fc_key ] ) ) {
				// Field is already mapped - use existing mapping, skip CRM API call.
				$crm_field_name = $field_mappings[ $fc_key ];
			} else {
				// STEP 2: Field not mapped yet - determine CRM field name.
				// Remove any existing fc_ prefix to avoid double prefixing (fc_fc_...).
				$clean_key = $fc_key;
				if ( strpos( $fc_key, 'fc_' ) === 0 ) {
					$clean_key = substr( $fc_key, 3 ); // Remove fc_ prefix.
				}
				$crm_field_name = 'fc_' . $clean_key; // Always add fc_ prefix to clean key.

				// Fetch existing CRM fields only once when first unmapped field is encountered.
				if ( $existing_fields_map === null ) {
					$existing_fields_map = array();
					$fields_array        = $this->contact_service->get_custom_fields();

					// Contact_Service returns parsed array or WP_Error.
					if ( ! is_wp_error( $fields_array ) && is_array( $fields_array ) ) {
						// Create a map for quick lookup: field_name => field_data.
						foreach ( $fields_array as $field ) {
							if ( is_array( $field ) && isset( $field['name'] ) ) {
								$existing_fields_map[ $field['name'] ] = $field;
							}
						}
					}
				}

				// STEP 3: Check if field exists in CRM.
				if ( isset( $existing_fields_map[ $crm_field_name ] ) ) {
					// Field exists in CRM - create mapping from response data.
					$field_mappings[ $fc_key ] = $crm_field_name;
					$this->update_metadata_mapping( 'custom_fields', array( $fc_key => $crm_field_name ) );
				} else {
					// STEP 4: Field doesn't exist in CRM - create it.
					// Get field config from definitions for complete field data.
					$fc_field_config = $fc_field_definitions[ $fc_key ] ?? array( 'type' => 'text' );
					$field_data      = $this->prepare_custom_field_for_sync( $crm_field_name, $fc_field_config );

					$result = $this->contact_service->sync_custom_field( $field_data, array( 'source' => $this->slug ) );

					if ( ! is_wp_error( $result ) ) {
						// Successfully created - store mapping.
						$field_mappings[ $fc_key ] = $crm_field_name;
						$this->update_metadata_mapping( 'custom_fields', array( $fc_key => $crm_field_name ) );

						// Add to existing fields map for future iterations in this loop.
						$existing_fields_map[ $crm_field_name ] = array( 'name' => $crm_field_name );
					} else {
						continue;
					}
				}
			}
			// Format value using Field_Formatter for proper validation/sanitization.
			$formatted_value = Field_Formatter::format_value_by_type( $value, $field_type, $crm_field_name );

			// Only include if value passes validation.
			if ( Field_Formatter::should_include_value( $formatted_value ) ) {
				$mapped_fields[ $crm_field_name ] = $formatted_value;
			}
		}

		return $mapped_fields;
	}

	/**
	 * Get FluentCRM custom field definitions
	 *
	 * Retrieves field definitions from FluentCRM including type, label, etc.
	 * Returns a map of field_slug => field_config for quick lookup.
	 *
	 * @since 0.0.3
	 *
	 * @return array Map of field_slug => field_config
	 */
	public function get_fluentcrm_field_definitions() {
		$definitions = array();

		if ( function_exists( 'fluentcrm_get_custom_contact_fields' ) ) {
			$fields = fluentcrm_get_custom_contact_fields();

			if ( is_array( $fields ) ) {
				foreach ( $fields as $field ) {
					if ( isset( $field['slug'] ) ) {
						$definitions[ $field['slug'] ] = $field;
					}
				}
			}
		}

		return $definitions;
	}

	/**
	 * Get field type from FluentCRM definitions
	 *
	 * Looks up the actual field type from FluentCRM, falling back to value-based detection.
	 *
	 * @since 0.0.3
	 *
	 * @param string $fc_key            FluentCRM field key.
	 * @param array  $fc_definitions    FluentCRM field definitions map.
	 * @param mixed  $value             Field value (for fallback detection).
	 * @return string SureContact-compatible field type
	 */
	private function get_field_type_from_definitions( $fc_key, $fc_definitions, $value ) {
		// Check if field exists in FluentCRM definitions.
		if ( isset( $fc_definitions[ $fc_key ] ) && ! empty( $fc_definitions[ $fc_key ]['type'] ) ) {
			$fc_type = $fc_definitions[ $fc_key ]['type'];
			return $this->map_field_type( $fc_type );
		}

		// Fallback: detect type from value.
		return $this->detect_field_type_from_value( $value );
	}

	/**
	 * Get field label from FluentCRM definitions
	 *
	 * @since 0.0.3
	 *
	 * @param string $crm_field_name CRM field name (with fc_ prefix).
	 * @param array  $fc_field_config FluentCRM field configuration with type, label, options, etc.
	 * @return array Field data ready for sync_custom_field API call
	 */
	public function prepare_custom_field_for_sync( $crm_field_name, $fc_field_config ) {
		$fc_type     = $fc_field_config['type'] ?? 'text';
		$mapped_type = $this->map_field_type( $fc_type );

		$field_data = array(
			'name'  => $crm_field_name,
			'label' => $fc_field_config['label'] ?? $this->format_field_label( $crm_field_name ),
			'type'  => $mapped_type,
		);

		// Field types that require options: select, multi_select.
		// FluentCRM types that have options: select-one, select-multi, radio, checkbox (sometimes).
		$types_requiring_options = array( 'select', 'multi_select' );

		if ( in_array( $mapped_type, $types_requiring_options, true ) && ! empty( $fc_field_config['options'] ) ) {
			$options = $this->normalize_field_options( $fc_field_config['options'] );
			if ( ! empty( $options ) ) {
				$field_data['options'] = $options;
			}
		}

		// Add description if available.
		if ( ! empty( $fc_field_config['description'] ) ) {
			$field_data['description'] = $fc_field_config['description'];
		}

		// Add default value if available.
		if ( isset( $fc_field_config['default_value'] ) && $fc_field_config['default_value'] !== '' ) {
			$field_data['default_value'] = $fc_field_config['default_value'];
		}

		return $field_data;
	}

	/**
	 * Normalize field options from FluentCRM format to SureContact format
	 *
	 * FluentCRM stores options as associative array (value => label).
	 * SureContact API expects a simple indexed array of option values.
	 *
	 * @since 0.0.3
	 *
	 * @param mixed $options Options from FluentCRM field config.
	 * @return array Normalized options array for SureContact API
	 */
	private function normalize_field_options( $options ) {
		if ( ! is_array( $options ) || empty( $options ) ) {
			return array();
		}

		// Check if it's an associative array (FluentCRM format: value => label).
		if ( array_keys( $options ) !== range( 0, count( $options ) - 1 ) ) {
			// Associative array - use keys as option values.
			return array_keys( $options );
		}

		// Already an indexed array.
		return $options;
	}

	/**
	 * Detect field type from value (fallback method)
	 *
	 * Used when FluentCRM field definitions are not available.
	 *
	 * @since 0.0.3
	 *
	 * @param mixed $value Field value.
	 * @return string Field type compatible with SureContact API
	 */
	private function detect_field_type_from_value( $value ) {
		if ( is_bool( $value ) ) {
			return 'checkbox';
		}

		if ( is_numeric( $value ) ) {
			return 'number';
		}

		if ( is_array( $value ) ) {
			return 'text'; // Store as JSON string.
		}

		// Check if it looks like a date.
		if ( is_string( $value ) && strtotime( $value ) !== false && preg_match( '/^\d{4}-\d{2}-\d{2}/', $value ) ) {
			return 'date';
		}

		return 'text';
	}

	/**
	 * Map FluentCRM field type to SureContact field type
	 *
	 * FluentCRM field types from CustomContactField::getFieldTypes():
	 * - text (type: text, value_type: string)
	 * - textarea (type: textarea, value_type: string)
	 * - number (type: number, value_type: numeric)
	 * - single-select (type: select-one, value_type: string)
	 * - multi-select (type: select-multi, value_type: array)
	 * - radio (type: radio, value_type: string)
	 * - checkbox (type: checkbox, value_type: array)
	 * - date (type: date, value_type: date)
	 * - date_time (type: date_time, value_type: datetime)
	 *
	 * @since 0.0.3
	 *
	 * @param string $fc_type FluentCRM field type.
	 * @return string SureContact field type
	 */
	private function map_field_type( $fc_type ) {
		$type_map = array(
			// Core FluentCRM field types.
			'text'         => 'text',
			'textarea'     => 'textarea',
			'number'       => 'number',
			'select-one'   => 'select',
			'select-multi' => 'multi_select',
			'radio'        => 'select',
			'checkbox'     => 'checkbox',
			'date'         => 'date',
			'date_time'    => 'datetime',
			// Additional mappings for compatibility.
			'select'       => 'select',
			'email'        => 'email',
			'url'          => 'url',
			'phone'        => 'phone',
		);

		return $type_map[ $fc_type ] ?? 'text';
	}

	/**
	 * Format field label from key
	 *
	 * @since 0.0.3
	 *
	 * @param string $key Field key.
	 * @return string Formatted label
	 */
	private function format_field_label( $key ) {
		// Convert snake_case or kebab-case to Title Case.
		$label = str_replace( array( '_', '-' ), ' ', $key );
		return ucwords( $label );
	}

	/**
	 * Override prepare_lists_and_tags to respect FluentCRM integration-specific settings
	 *
	 * This ensures that FluentCRM subscribers only get their individual lists/tags,
	 * NOT the global assigned_lists and assigned_tags from general settings.
	 * The bulk_sync_lists and bulk_sync_tags are applied separately during bulk sync.
	 *
	 * @since 0.0.1
	 *
	 * @param array $data     Contact data.
	 * @param int   $user_id  User ID.
	 * @param array $context  Context with optional list_uuids, tag_uuids, and custom_fields.
	 * @return array Modified contact data with lists, tags, and custom fields.
	 */
	protected function prepare_lists_and_tags( $data, $user_id, $context ) {
		$list_uuids = array();
		$tag_uuids  = array();

		$sync_lists_enabled = $this->is_setting_enabled( 'sync_lists', true );
		$sync_tags_enabled  = $this->is_setting_enabled( 'sync_tags', true );

		if ( $sync_lists_enabled ) {
			// Add lists from context (FluentCRM subscriber's individual lists)
			// DO NOT add global assigned_lists from general settings - FluentCRM handles its own lists.
			if ( ! empty( $context['list_uuids'] ) && is_array( $context['list_uuids'] ) ) {
				$list_uuids = array_merge( $list_uuids, $context['list_uuids'] );
			}
		}

		// Only add tags if sync_tags is enabled.
		if ( $sync_tags_enabled ) {
			// Add tags from context (FluentCRM subscriber's individual tags)
			// DO NOT add global assigned_tags from general settings - FluentCRM handles its own tags.
			if ( ! empty( $context['tag_uuids'] ) && is_array( $context['tag_uuids'] ) ) {
				$tag_uuids = array_merge( $tag_uuids, $context['tag_uuids'] );
			}
		}

		// Add integration-level lists and tags (applies to ALL contacts).
		$integration_lists = $this->get_setting( 'bulk_sync_lists', array() );
		$integration_tags  = $this->get_setting( 'bulk_sync_tags', array() );

		if ( ! empty( $integration_lists ) ) {
			$integration_list_uuids = $this->extract_uuids( $integration_lists );
			if ( ! empty( $integration_list_uuids ) ) {
				$list_uuids = array_merge( $list_uuids, $integration_list_uuids );
			}
		}

		if ( ! empty( $integration_tags ) ) {
			$integration_tag_uuids = $this->extract_uuids( $integration_tags );
			if ( ! empty( $integration_tag_uuids ) ) {
				$tag_uuids = array_merge( $tag_uuids, $integration_tag_uuids );
			}
		}

		// Apply lists and tags to data.
		if ( ! empty( $list_uuids ) ) {
			$data['list_uuids'] = $this->extract_uuids( $list_uuids );
		}

		if ( ! empty( $tag_uuids ) ) {
			$data['tag_uuids'] = $this->extract_uuids( $tag_uuids );
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
	 * Get FluentCRM sync types
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of sync type definitions.
	 */
	public function get_sync_types() {
		$sync_types = $this->contact_sync->get_sync_types();

		if ( $this->company_sync ) {
			$sync_types = array_merge( $sync_types, $this->company_sync->get_sync_types() );
		}

		return $sync_types;
	}

	/**
	 * Handle FluentCRM company created event.
	 *
	 * @since 1.5.1
	 *
	 * @param \FluentCrm\App\Models\Company $company Company model.
	 * @param array                         $data    Original payload that created the company.
	 * @return void
	 */
	public function handle_company_created( $company, $data = array() ) {
		unset( $data );

		if ( ! $this->should_sync_company_realtime() || ! $company ) {
			return;
		}

		$this->sync_company( $company );
	}

	/**
	 * Handle FluentCRM company updated event.
	 *
	 * @since 1.5.1
	 *
	 * @param \FluentCrm\App\Models\Company $company Company model.
	 * @param array                         $data    Original payload that updated the company.
	 * @return void
	 */
	public function handle_company_updated( $company, $data = array() ) {
		unset( $data );

		if ( ! $this->should_sync_company_realtime() || ! $company ) {
			return;
		}

		$this->sync_company( $company );
	}

	/**
	 * Handle FluentCRM company delete event.
	 *
	 * @since 1.5.1
	 *
	 * @param \FluentCrm\App\Models\Company $company Company being deleted.
	 * @return void
	 */
	public function handle_company_delete( $company ) {
		if ( ! $this->company_service || ! $company || empty( $company->id ) ) {
			return;
		}

		$company_uuid = $this->get_company_uuid_by_id( (int) $company->id );
		if ( ! $company_uuid ) {
			return;
		}

		$result = $this->company_service->delete_company( $company_uuid, array( 'source' => $this->slug ) );

		if ( ! is_wp_error( $result ) ) {
			$this->forget_company_mapping( (int) $company->id );
		}
	}

	/**
	 * Handle a contact being attached to one or more FluentCRM companies.
	 *
	 * @since 1.5.1
	 *
	 * @param array                            $company_ids Newly attached FluentCRM company IDs.
	 * @param \FluentCrm\App\Models\Subscriber $subscriber  Subscriber model.
	 * @return void
	 */
	public function handle_contact_added_to_companies( $company_ids, $subscriber ) {
		if ( ! $this->should_sync_company_realtime() || empty( $company_ids ) || ! $subscriber || ! $this->company_service ) {
			return;
		}

		$contact_uuid = $this->contact_service->find_contact_id_by_email( $subscriber->email ?? '' );
		if ( ! $contact_uuid ) {
			$contact_uuid = $this->create_contact_for_metadata_change( $subscriber );
		}
		if ( ! $contact_uuid ) {
			return;
		}

		$primary_id = isset( $subscriber->company_id ) ? (int) $subscriber->company_id : 0;

		foreach ( (array) $company_ids as $fc_company_id ) {
			$fc_company_id = (int) $fc_company_id;
			$company_uuid  = $this->ensure_company_uuid( $fc_company_id );
			if ( ! $company_uuid ) {
				continue;
			}

			$this->company_service->link_contact(
				$company_uuid,
				$contact_uuid,
				$primary_id === $fc_company_id,
				array( 'source' => $this->slug )
			);
		}
	}

	/**
	 * Handle a contact being detached from one or more FluentCRM companies.
	 *
	 * @since 1.5.1
	 *
	 * @param array                            $company_ids Removed FluentCRM company IDs.
	 * @param \FluentCrm\App\Models\Subscriber $subscriber  Subscriber model.
	 * @return void
	 */
	public function handle_contact_removed_from_companies( $company_ids, $subscriber ) {
		if ( ! $this->should_sync_company_realtime() || empty( $company_ids ) || ! $subscriber || ! $this->company_service ) {
			return;
		}

		$contact_uuid = $this->contact_service->find_contact_id_by_email( $subscriber->email ?? '' );
		if ( ! $contact_uuid ) {
			return;
		}

		foreach ( (array) $company_ids as $fc_company_id ) {
			$company_uuid = $this->get_company_uuid_by_id( (int) $fc_company_id );
			if ( ! $company_uuid ) {
				continue;
			}

			$this->company_service->unlink_contact( $company_uuid, $contact_uuid, array( 'source' => $this->slug ) );
		}
	}

	/**
	 * Handle a company note being added in FluentCRM.
	 *
	 * @since 1.5.1
	 *
	 * @param \FluentCrm\App\Models\CompanyNote $company_note CompanyNote model.
	 * @param \FluentCrm\App\Models\Company     $company      Company model.
	 * @param array                             $payload      Original payload.
	 * @return void
	 */
	public function handle_company_note_added( $company_note, $company, $payload = array() ) {
		unset( $payload );

		if ( ! $this->should_sync_company_notes() || ! $company_note || ! $company || ! $this->company_service ) {
			return;
		}

		$company_uuid = $this->ensure_company_uuid( (int) $company->id );
		if ( ! $company_uuid ) {
			return;
		}

		$note_uuid = $this->create_company_note_on_saas( $company_uuid, $company_note );

		if ( $note_uuid ) {
			$this->update_metadata_mapping( 'company_notes', array( 'fc_' . $company_note->id => $note_uuid ) );
		}
	}

	/**
	 * Handle a company note being updated in FluentCRM.
	 *
	 * @since 1.5.1
	 *
	 * @param \FluentCrm\App\Models\CompanyNote $company_note CompanyNote model.
	 * @param \FluentCrm\App\Models\Company     $company      Company model.
	 * @param array                             $payload      Original payload.
	 * @return void
	 */
	public function handle_company_note_updated( $company_note, $company, $payload = array() ) {
		unset( $payload );

		if ( ! $this->should_sync_company_notes() || ! $company_note || ! $this->company_service ) {
			return;
		}

		$note_mappings = $this->get_metadata_mapping( 'company_notes' );
		$mapping_key   = 'fc_' . $company_note->id;
		$note_uuid     = $note_mappings[ $mapping_key ] ?? null;

		if ( ! $note_uuid ) {
			// Note was never synced — treat as an add.
			$this->handle_company_note_added( $company_note, $company );
			return;
		}

		$this->company_service->update_note(
			$note_uuid,
			$this->build_note_payload( $company_note ),
			array( 'source' => $this->slug )
		);
	}

	/**
	 * Handle a company note being deleted in FluentCRM.
	 *
	 * @since 1.5.1
	 *
	 * @param int                                $note_id Deleted note ID.
	 * @param \FluentCrm\App\Models\Company|null $company Company model (unused; kept for hook signature).
	 * @return void
	 */
	public function handle_company_note_deleted( $note_id, $company = null ) {
		unset( $company );

		if ( ! $this->should_sync_company_notes() || ! $this->company_service ) {
			return;
		}

		$note_mappings = $this->get_metadata_mapping( 'company_notes' );
		$mapping_key   = 'fc_' . (int) $note_id;
		$note_uuid     = $note_mappings[ $mapping_key ] ?? null;

		if ( ! $note_uuid ) {
			return;
		}

		$result = $this->company_service->delete_note( $note_uuid, array( 'source' => $this->slug ) );

		if ( ! is_wp_error( $result ) ) {
			unset( $note_mappings[ $mapping_key ] );
			$this->replace_metadata_mapping( 'company_notes', $note_mappings );
		}
	}

	/**
	 * Sync a single FluentCRM company to SureContact (create or update).
	 *
	 * @since 1.5.1
	 *
	 * @param \FluentCrm\App\Models\Company $company Company model.
	 * @return string|false Company UUID on success, false on failure.
	 */
	public function sync_company( $company ) {
		if ( ! $this->company_service || ! $company || empty( $company->id ) ) {
			return false;
		}

		// Pin the service to a local var so PHPStan keeps the non-null narrowing
		// across the rest of the method (it loses track once we call into private
		// helpers like resolve_company_uuid_by_domain()).
		$service = $this->company_service;

		$payload = Company_Field_Mapper::map_to_crm_format( $company );

		if ( empty( $payload['name'] ) ) {
			// `name` is the only required field on the backend. The mapper
			// drops empty values, so guarantee it's present here.
			$payload['name'] = $company->name;
		}

		// Identity is established three ways, in order of trust:
		// 1. local mapping (`metadata_mappings['companies']`) → re-syncs
		// 2. exact domain match on the SaaS                   → first sync of a
		// company that already exists from another path (manual / auto-link)
		// 3. exact name match on the SaaS                     → fallback when
		// the FluentCRM company has no website or the domain probe missed
		// We don't set `source_id` (no need — the local mapping owns identity),
		// but `source` is set to 'api' on create so the audit trail labels
		// plugin-created companies correctly. We don't overwrite `source` on
		// update — that would clobber companies originally created via the UI,
		// auto-domain-match, or another integration.

		$custom_fields = $this->prepare_company_custom_field_values( $company );
		if ( ! empty( $custom_fields ) ) {
			$payload['custom_fields'] = $custom_fields;
		}

		// No metadata is stashed: FluentCRM's internal pointers (company_id,
		// hash, owner_id) and orphan fields (email, timezone — no SureContact column)
		// have no consumer on the SaaS side. Identity is owned plugin-side via
		// the local mapping option, and domain matching handles re-resolution.

		$company_uuid = $this->get_company_uuid_by_id( (int) $company->id );

		if ( $company_uuid ) {
			$result = $service->update_company( $company_uuid, $payload, array( 'source' => $this->slug ) );
			if ( ! is_wp_error( $result ) ) {
				return $company_uuid;
			}

			// Stale mapping: company was deleted on the SaaS. Clear the dead
			// pointer and fall through to the domain-probe + create branch
			// below so the company is re-created on the next sync attempt.
			if ( ! $this->is_company_not_found_error( $result ) ) {
				return false;
			}

			$this->forget_company_mapping( (int) $company->id );
			Logger::info(
				'FluentCRM Integration',
				sprintf(
					'Cleared stale company mapping for FluentCRM id %d (SaaS returned 404); re-creating.',
					(int) $company->id
				)
			);
			$company_uuid = null;
		}

		// No local mapping yet. If the company has a website we can extract a
		// domain from, ask the SaaS whether a company with that domain already
		// exists in the workspace (manual creation, auto-link, prior sync that
		// lost its local mapping, …) and adopt it instead of creating a duplicate.
		$existing_uuid = $this->resolve_company_uuid_by_domain( $company );

		// API failure during domain probe — don't fall through to create_company
		// or we'd produce a duplicate when the SaaS does have a matching company.
		if ( is_wp_error( $existing_uuid ) ) {
			Logger::warning(
				'FluentCRM Integration',
				sprintf(
					'Skipping company create for FluentCRM id %d: domain probe failed (%s).',
					(int) $company->id,
					$existing_uuid->get_error_message()
				)
			);
			return false;
		}

		// Domain probe missed (or didn't apply because there's no website).
		// Fall back to exact-name lookup: company names are unique per
		// workspace on the SaaS, so this is a deterministic adopt-or-create
		// gate that prevents duplicate creations for companies without a
		// matchable domain.
		if ( ! $existing_uuid ) {
			$name_match = $service->find_uuid_by_exact_name( $payload['name'] );
			if ( is_wp_error( $name_match ) ) {
				Logger::warning(
					'FluentCRM Integration',
					sprintf(
						'Skipping company create for FluentCRM id %d: exact-name probe failed (%s).',
						(int) $company->id,
						$name_match->get_error_message()
					)
				);
				return false;
			}
			if ( is_string( $name_match ) && $name_match !== '' ) {
				$existing_uuid = $name_match;
			}
		}

		if ( $existing_uuid ) {
			$this->remember_company_mapping( (int) $company->id, $existing_uuid );
			$update = $service->update_company( $existing_uuid, $payload, array( 'source' => $this->slug ) );
			if ( is_wp_error( $update ) ) {
				return false;
			}
			return $existing_uuid;
		}

		$payload['source'] = 'api';

		$new_uuid = $service->create_company( $payload, array( 'source' => $this->slug ) );
		if ( is_wp_error( $new_uuid ) ) {
			return false;
		}

		$this->remember_company_mapping( (int) $company->id, $new_uuid );

		return $new_uuid;
	}

	/**
	 * Sync a company's notes to SureContact and return the number of new note
	 * creates issued (one work unit per `add_note` call).
	 *
	 * Real-time note hooks (`fluent_crm/company_note_added` etc.) keep notes in
	 * sync as they happen, but they only run for in-session edits — anything
	 * created before bulk-sync ran, or while realtime sync was off, never
	 * reaches the SaaS. Calling this from the bulk-sync path closes that gap.
	 *
	 * When `$was_recreated` is true, the SaaS-side company is brand new (the
	 * previous one was deleted on the SaaS and we just re-created it). Every
	 * cached `company_notes['fc_<id>']` UUID for this company is dead, so we
	 * skip the per-note "already mapped" shortcut and re-add every note. The
	 * final `update_metadata_mapping` call is a merge — fresh UUIDs naturally
	 * overwrite the stale ones, no explicit prune needed.
	 *
	 * @since 1.5.1
	 *
	 * @param object $company       FluentCRM Company model.
	 * @param string $company_uuid  SureContact company UUID.
	 * @param bool   $was_recreated Whether `sync_company()` re-created the SaaS
	 *                              company (signals that note mappings are stale).
	 * @return int Number of `add_note` HTTP calls issued (for AS work-budget accounting).
	 */
	public function sync_company_notes( $company, $company_uuid, $was_recreated = false ) {
		if ( ! $this->company_service || ! $company || empty( $company_uuid ) || ! method_exists( $company, 'notes' ) ) {
			return 0;
		}

		$notes = $company->notes()->orderBy( 'id', 'asc' )->get();
		if ( $notes->isEmpty() ) {
			return 0;
		}

		$note_mappings = $was_recreated ? array() : $this->get_metadata_mapping( 'company_notes' );

		$created      = 0;
		$new_mappings = array();
		foreach ( $notes as $note ) {
			$key = 'fc_' . (int) $note->id;
			if ( isset( $note_mappings[ $key ] ) ) {
				// Already synced via realtime hook (or an earlier bulk run);
				// trust the mapping and skip. Update/delete events are handled
				// by their own hooks — bulk sync only fills the create gap.
				continue;
			}

			$note_uuid = $this->create_company_note_on_saas( $company_uuid, $note );
			++$created;

			if ( $note_uuid ) {
				$new_mappings[ $key ] = $note_uuid;
			} elseif ( SaaS_Client::was_rate_limited_this_request() ) {
				// Stop iterating remaining notes — the SaaS is throttling and
				// every subsequent add_note will burn the quota with no result.
				// process_batch() will reschedule and pick up the rest after the
				// 60s backoff.
				break;
			}
		}

		if ( ! empty( $new_mappings ) ) {
			$this->update_metadata_mapping( 'company_notes', $new_mappings );
		}

		return $created;
	}

	/**
	 * POST a single FluentCRM CompanyNote to the SaaS and return its UUID, or
	 * null on failure. Shared between the real-time `handle_company_note_added`
	 * hook and the bulk `sync_company_notes()` path so the payload shape, retry
	 * semantics, and source-tagging stay in lockstep.
	 *
	 * @since 1.5.1
	 *
	 * @param string                            $company_uuid SureContact company UUID.
	 * @param \FluentCrm\App\Models\CompanyNote $company_note CompanyNote model.
	 * @return string|null Note UUID on success, null on failure.
	 */
	private function create_company_note_on_saas( $company_uuid, $company_note ) {
		if ( ! $this->company_service ) {
			return null;
		}

		$note_uuid = $this->company_service->add_note(
			$company_uuid,
			$this->build_note_payload( $company_note ),
			array( 'source' => $this->slug )
		);

		return ( ! is_wp_error( $note_uuid ) && is_string( $note_uuid ) ) ? $note_uuid : null;
	}

	/**
	 * Resolve an existing SureContact company UUID by domain, if the FluentCRM
	 * company has a parseable website.
	 *
	 * @since 1.5.1
	 *
	 * @param object $company FluentCRM Company model.
	 * @return string|null|\WP_Error UUID on match, null on confirmed no-match
	 *                               (or no parseable domain), WP_Error on API
	 *                               failure so callers can skip create instead
	 *                               of producing duplicates on transient errors.
	 */
	private function resolve_company_uuid_by_domain( $company ) {
		if ( ! $this->company_service || empty( $company->website ) ) {
			return null;
		}

		$domain = $this->extract_domain( (string) $company->website );
		if ( $domain === '' ) {
			return null;
		}

		return $this->company_service->find_uuid_by_domain( $domain );
	}

	/**
	 * Parse a domain out of a website URL.
	 *
	 * Accepts bare domains ("acme.com"), URLs with or without scheme, optional
	 * `www.` prefix, and trailing path/query. Returns lowercased host or empty
	 * string on failure.
	 *
	 * @since 1.5.1
	 *
	 * @param string $url Raw website value from FluentCRM.
	 * @return string Domain (e.g. "acme.com") or empty string.
	 */
	private function extract_domain( $url ) {
		$url = trim( (string) $url );
		if ( $url === '' ) {
			return '';
		}

		// `parse_url` needs a scheme to populate `host` reliably; add one if missing.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || $host === '' ) {
			return '';
		}

		$host = strtolower( $host );

		// Strip a leading `www.` so the domain we send matches what the SaaS stores.
		if ( strpos( $host, 'www.' ) === 0 ) {
			$host = substr( $host, 4 );
		}

		return $host;
	}

	/**
	 * Detect a "company no longer exists on the SaaS" response.
	 *
	 * SaaS_Client wraps non-2xx responses as
	 *   WP_Error( 'saas_api_error', '<message>', [ 'code' => <http>, 'body' => '<raw>' ] )
	 * A PUT to companies/{uuid} returning 404 can only mean the company UUID
	 * we hold is no longer valid — the endpoint itself is stable.
	 *
	 * @since 1.5.1
	 *
	 * @param \WP_Error $error Error returned from Company_Service::update_company().
	 * @return bool
	 */
	private function is_company_not_found_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			return false;
		}

		return (int) ( $data['code'] ?? 0 ) === 404;
	}

	/**
	 * Pull custom field values off a FluentCRM company and map them through SureContact custom fields.
	 *
	 * Ensures each custom field exists on the SaaS side (creating + caching mappings),
	 * then returns a `field_name => value` array for the company payload.
	 *
	 * @since 1.5.1
	 *
	 * @param \FluentCrm\App\Models\Company $company Company model.
	 * @return array<string, mixed>
	 */
	private function prepare_company_custom_field_values( $company ) {
		if ( ! $this->company_service || ! method_exists( $company, 'getCustomValues' ) ) {
			return array();
		}

		// Pin the service locally so PHPStan keeps the non-null narrowing
		// across the call into `ensure_company_custom_field_mappings_fresh()`
		// below — same pattern used in `sync_company`.
		$service = $this->company_service;

		$values = $company->getCustomValues();
		if ( empty( $values ) || ! is_array( $values ) ) {
			return array();
		}

		// Drop any cached crm_name that no longer exists on the SaaS before we
		// read the mapping below. Without this, a SaaS-side wipe leaves stale
		// keys in `metadata_mappings['company_custom_fields']` — the bulk-sync
		// PUT then carries them in `custom_fields` and Laravel validation
		// silently drops the unknown keys (200 OK but `custom_values: []`).
		$this->ensure_company_custom_field_mappings_fresh();

		$definitions    = $this->get_fluentcrm_company_field_definitions();
		$field_mappings = $this->get_metadata_mapping( 'company_custom_fields' );
		$mapped         = array();
		$new_mappings   = array();

		foreach ( $values as $fc_key => $value ) {
			if ( $value === null || $value === '' ) {
				continue;
			}

			$config      = $definitions[ $fc_key ] ?? array( 'type' => 'text' );
			$mapped_type = $this->map_field_type( $config['type'] ?? 'text' );

			$crm_name = $field_mappings[ $fc_key ] ?? null;
			if ( ! $crm_name ) {
				$prepared    = $this->prepare_company_custom_field_for_sync( $fc_key, $config );
				$result_name = $service->sync_custom_field( $prepared, array( 'source' => $this->slug ) );
				if ( is_wp_error( $result_name ) ) {
					continue;
				}
				$crm_name                = $result_name;
				$new_mappings[ $fc_key ] = $crm_name;
			}

			// Mirror the contact-side pattern (see sync_and_map_custom_fields):
			// coerce by declared field type and drop values that fail validation,
			// so the bulk PUT doesn't carry malformed dates / numbers / etc. that
			// the SaaS validator would 422 on.
			$formatted = Field_Formatter::format_value_by_type( $value, $mapped_type, $crm_name );
			if ( Field_Formatter::should_include_value( $formatted ) ) {
				$mapped[ $crm_name ] = $formatted;
			}
		}

		if ( ! empty( $new_mappings ) ) {
			$this->update_metadata_mapping( 'company_custom_fields', $new_mappings );
		}

		return $mapped;
	}

	/**
	 * Drop entries in `company_custom_fields` whose crm_name is no longer
	 * registered on the SaaS — once per request.
	 *
	 * After a SaaS workspace reset the field definitions are gone, but the
	 * local mapping still holds the old crm_names. The update_company endpoint
	 * accepts the request and silently ignores unknown keys inside
	 * `custom_fields`, so the failure mode is invisible without this pruning.
	 * Once pruned, `prepare_company_custom_field_values()` falls through to
	 * `sync_custom_field()` and recreates each missing definition.
	 *
	 * Static-cached so a bulk-sync run that visits hundreds of companies pays
	 * for at most one `get_custom_fields` HTTP per AS action.
	 *
	 * @since 1.5.1
	 *
	 * @return void
	 */
	private function ensure_company_custom_field_mappings_fresh() {
		if ( $this->company_custom_field_mappings_validated || ! $this->company_service ) {
			return;
		}

		$saas_fields = $this->company_service->list_custom_fields();
		if ( is_wp_error( $saas_fields ) ) {
			// Network blip — leave the cache untouched and let the next AS
			// action retry. Better to ship the request with possibly-stale
			// keys than to wipe the local mapping based on a transient error.
			return;
		}

		$this->company_custom_field_mappings_validated = true;

		$this->prune_stale_metadata_mapping(
			'company_custom_fields',
			$this->collect_known_identifiers( $saas_fields, 'name' )
		);
	}

	/**
	 * Build a set (indexed by identifier) from an indexed list of associative
	 * arrays. Returns `[ identifier => true, ... ]` for fast `isset` lookups.
	 *
	 * @since 1.5.1
	 *
	 * @param array  $items     Indexed list of associative arrays.
	 * @param string $key_field Key whose value is the identifier (e.g. 'uuid', 'name').
	 * @return array<string,bool>
	 */
	private function collect_known_identifiers( array $items, $key_field ) {
		$known = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && ! empty( $item[ $key_field ] ) ) {
				$known[ (string) $item[ $key_field ] ] = true;
			}
		}
		return $known;
	}

	/**
	 * Drop entries in `metadata_mappings[$type]` whose value is not in
	 * `$known`. Shared between every stale-mapping recovery path so the
	 * persistence semantics stay aligned.
	 *
	 * @since 1.5.1
	 *
	 * @param string             $type  Mapping type ('lists', 'tags', 'company_custom_fields', ...).
	 * @param array<string,bool> $known Set of valid identifiers (value=>true).
	 * @return void
	 */
	private function prune_stale_metadata_mapping( $type, array $known ) {
		$mappings = $this->get_metadata_mapping( $type );
		if ( empty( $mappings ) ) {
			return;
		}

		// An empty `$known` set means the SaaS legitimately reports zero
		// entries of this type (workspace wipe / fresh tenant). The caller
		// already returned early on WP_Error, so we can trust that signal.
		// Wiping the local mapping here is safe — `sync_custom_field()` and
		// `sync_metadata()` are idempotent via search-or-create, so the next
		// per-item call rebuilds the mapping from current SaaS state.
		$pruned = array();
		foreach ( $mappings as $key => $value ) {
			if ( is_string( $value ) && isset( $known[ $value ] ) ) {
				$pruned[ $key ] = $value;
			}
		}

		if ( count( $pruned ) === count( $mappings ) ) {
			return;
		}

		$this->replace_metadata_mapping( $type, $pruned );
	}

	/**
	 * Get FluentCRM company custom field definitions, keyed by slug.
	 *
	 * @since 1.5.1
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fluentcrm_company_field_definitions() {
		$definitions = array();

		// Prefer the helper function (mirrors `fluentcrm_get_custom_contact_fields()`)
		// — it caches via a static variable and avoids instantiating the model
		// just to read an option. `getGlobalFields()` on CustomCompanyField is an
		// instance method (inherited from CustomContactField), not static, so the
		// helper is also the safer call shape.
		if ( function_exists( 'fluentcrm_get_custom_company_fields' ) ) {
			$fields = fluentcrm_get_custom_company_fields();
		} elseif ( class_exists( '\\FluentCrm\\App\\Models\\CustomCompanyField' ) ) {
			// Fallback for older FluentCRM Pro builds that haven't shipped the helper yet.
			$global = ( new \FluentCrm\App\Models\CustomCompanyField() )->getGlobalFields();
			$fields = $global['fields'] ?? array();
		} else {
			return $definitions;
		}

		if ( ! is_array( $fields ) ) {
			return $definitions;
		}

		foreach ( $fields as $field ) {
			if ( ! empty( $field['slug'] ) ) {
				$definitions[ $field['slug'] ] = $field;
			}
		}

		return $definitions;
	}

	/**
	 * Prepare a FluentCRM company custom field definition for SureContact sync.
	 *
	 * @since 1.5.1
	 *
	 * @param string $fc_key   FluentCRM field slug.
	 * @param array  $fc_field FluentCRM field config (type, label, options, ...).
	 * @return array Payload for Company_Service::sync_custom_field().
	 */
	private function prepare_company_custom_field_for_sync( $fc_key, $fc_field ) {
		$fc_type     = $fc_field['type'] ?? 'text';
		$mapped_type = $this->map_field_type( $fc_type );

		// Strip the literal `fc_` prefix if present. NB: `ltrim` would treat the
		// second arg as a character mask and chew off any leading f/c/_, turning
		// `fc_company_date` into `ompany_date`.
		$normalized_key = ( strpos( $fc_key, 'fc_' ) === 0 ) ? substr( $fc_key, 3 ) : $fc_key;

		$payload = array(
			'name'       => 'fcc_' . $normalized_key,
			'label'      => $fc_field['label'] ?? $this->format_field_label( $fc_key ),
			'field_type' => $mapped_type,
		);

		if ( in_array( $mapped_type, array( 'select', 'multi_select' ), true ) && ! empty( $fc_field['options'] ) ) {
			$options = $this->normalize_field_options( $fc_field['options'] );
			if ( ! empty( $options ) ) {
				$payload['options'] = $options;
			}
		}

		return $payload;
	}

	/**
	 * Build a SureContact company-note payload from a FluentCRM CompanyNote model.
	 *
	 * FluentCRM stores the note body in `description` as rich HTML (from its
	 * editor); the SaaS endpoint expects the same value under `content` and
	 * the column is plain text only — so we strip tags before sending.
	 * `wp_strip_all_tags` collapses whitespace as well, which matches what the
	 * SaaS would render anyway. Same payload is used for both the real-time
	 * hook and the bulk-sync path.
	 *
	 * @since 1.5.1
	 *
	 * @param \FluentCrm\App\Models\CompanyNote $company_note CompanyNote model.
	 * @return array
	 */
	private function build_note_payload( $company_note ) {
		$description = $company_note->description ?? '';
		$content     = $description !== '' ? wp_strip_all_tags( $description, true ) : '';

		return array_filter(
			array(
				'title'   => $company_note->title ?? '',
				'content' => $content,
				'type'    => $company_note->type ?? 'note',
			),
			static function ( $value ) {
				return $value !== '' && $value !== null;
			}
		);
	}

	/**
	 * Look up a stored SureContact company UUID by its FluentCRM company ID.
	 *
	 * @since 1.5.1
	 *
	 * @param int $fc_company_id FluentCRM company ID.
	 * @return string|null UUID if mapped, null otherwise.
	 */
	public function get_company_uuid_by_id( $fc_company_id ) {
		$mappings = $this->get_metadata_mapping( 'companies' );
		$key      = 'fc_' . (int) $fc_company_id;

		return isset( $mappings[ $key ] ) ? $mappings[ $key ] : null;
	}

	/**
	 * Look up — or create on-demand — the SureContact UUID for a FluentCRM company.
	 *
	 * @since 1.5.1
	 *
	 * @param int $fc_company_id FluentCRM company ID.
	 * @return string|null UUID or null on failure.
	 */
	public function ensure_company_uuid( $fc_company_id ) {
		$existing = $this->get_company_uuid_by_id( $fc_company_id );
		if ( $existing ) {
			return $existing;
		}

		if ( ! class_exists( '\\FluentCrm\\App\\Models\\Company' ) ) {
			return null;
		}

		$company = \FluentCrm\App\Models\Company::find( (int) $fc_company_id );
		if ( ! $company ) {
			return null;
		}

		$uuid = $this->sync_company( $company );
		return is_string( $uuid ) ? $uuid : null;
	}

	/**
	 * Persist a FluentCRM company-id → SureContact-uuid mapping.
	 *
	 * @since 1.5.1
	 *
	 * @param int    $fc_company_id FluentCRM company ID.
	 * @param string $uuid          SureContact company UUID.
	 * @return void
	 */
	public function remember_company_mapping( $fc_company_id, $uuid ) {
		$this->update_metadata_mapping( 'companies', array( 'fc_' . (int) $fc_company_id => $uuid ) );
	}

	/**
	 * Remove a FluentCRM company-id → SureContact-uuid mapping after deletion.
	 *
	 * @since 1.5.1
	 *
	 * @param int $fc_company_id FluentCRM company ID.
	 * @return void
	 */
	public function forget_company_mapping( $fc_company_id ) {
		$mappings = $this->get_metadata_mapping( 'companies' );
		$key      = 'fc_' . (int) $fc_company_id;

		if ( ! isset( $mappings[ $key ] ) ) {
			return;
		}

		unset( $mappings[ $key ] );
		$this->replace_metadata_mapping( 'companies', $mappings );
	}

	/**
	 * Replace (rather than merge) a single metadata-mapping type.
	 *
	 * Base_Integration::update_metadata_mapping() only supports merging in new
	 * keys; this helper is used when keys need to be removed (e.g. after a
	 * delete event).
	 *
	 * @since 1.5.1
	 *
	 * @param string $type     Mapping type ('companies', 'company_notes', etc.).
	 * @param array  $mappings New mapping array (replaces existing entirely).
	 * @return void
	 */
	protected function replace_metadata_mapping( $type, $mappings ) {
		$all_mappings          = $this->get_setting( 'metadata_mappings', array() );
		$all_mappings[ $type ] = $mappings;
		$this->update_setting( 'metadata_mappings', $all_mappings );
	}

	/**
	 * Attach a freshly-synced contact to any FluentCRM companies that already
	 * have a SureContact UUID mapped locally.
	 *
	 * Handles the reverse direction of the company-sync linking path: where
	 * `FluentCRM_Company_Sync::link_subscribers_to_company()` walks each company's
	 * subscribers, this walks a single subscriber's companies. Together they
	 * make bulk-sync ordering irrelevant — links get created as soon as both
	 * ends exist on SaaS.
	 *
	 * No-ops silently when:
	 *  - the Company module is off,
	 *  - the subscriber has no company associations,
	 *  - none of the associated companies have been synced yet (they'll be
	 *    picked up by `link_subscribers_to_company()` when that company syncs).
	 *
	 * @since 1.5.1
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber   Subscriber that just synced.
	 * @param string|null                      $contact_uuid Pre-resolved contact UUID
	 *                                                       (saves an email lookup if known).
	 * @return void
	 */
	public function attach_contact_to_mapped_companies( $subscriber, $contact_uuid = null ) {
		if ( ! $this->company_service || ! $this->is_company_module_enabled() ) {
			return;
		}

		if ( ! $subscriber || empty( $subscriber->id ) ) {
			return;
		}

		// Resolve the contact UUID. Prefer the caller-supplied one; fall back
		// to the meta we already store; finally hit the SaaS by email.
		if ( ! $contact_uuid ) {
			$contact_uuid = $subscriber->getMeta( 'surecontact_contact_uuid', 'surecontact' );
		}
		if ( ! $contact_uuid && ! empty( $subscriber->email ) ) {
			$contact_uuid = $this->contact_service->find_contact_id_by_email( $subscriber->email );
		}
		if ( ! $contact_uuid ) {
			return;
		}

		// Load the subscriber's company associations. The Pro Company module
		// adds a `companies()` belongsToMany relation; check defensively.
		if ( ! method_exists( $subscriber, 'companies' ) ) {
			return;
		}

		if ( ! $subscriber->relationLoaded( 'companies' ) ) {
			$subscriber->load( 'companies' );
		}

		$companies_relation = $subscriber->companies;
		if ( empty( $companies_relation ) ) {
			return;
		}
		// Eloquent Collection has isEmpty(); plain arrays don't — handle both
		// shapes so we don't blow up if FluentCRM ever changes the relation type.
		if ( is_object( $companies_relation ) && method_exists( $companies_relation, 'isEmpty' ) && $companies_relation->isEmpty() ) {
			return;
		}
		if ( is_array( $companies_relation ) && $companies_relation === array() ) {
			return;
		}

		$company_mappings = $this->get_metadata_mapping( 'companies' );
		if ( empty( $company_mappings ) ) {
			return;
		}

		$primary_company_id        = isset( $subscriber->company_id ) ? (int) $subscriber->company_id : 0;
		$non_primary_company_uuids = array();
		$primary_company_uuid      = null;

		foreach ( $subscriber->companies as $company ) {
			$mapping_key  = 'fc_' . (int) $company->id;
			$company_uuid = $company_mappings[ $mapping_key ] ?? null;
			if ( ! $company_uuid ) {
				// Company not synced yet; the company-side handler will pick this up.
				continue;
			}

			if ( $primary_company_id > 0 && (int) $company->id === $primary_company_id ) {
				$primary_company_uuid = $company_uuid;
			} else {
				$non_primary_company_uuids[] = $company_uuid;
			}
		}

		// Bulk-link the non-primary companies in a single HTTP call. The
		// backend ignores `is_primary` on bulk requests with >1 UUID anyway,
		// so the primary lives in its own single-attach below.
		if ( ! empty( $non_primary_company_uuids ) ) {
			$result = $this->company_service->bulk_link_companies(
				$contact_uuid,
				$non_primary_company_uuids,
				array( 'source' => $this->slug )
			);

			// On a 422 (typically stale company-uuid mapping) fall back to per-link
			// calls so the underlying single-attach path can recover row by row.
			if ( is_wp_error( $result ) && 422 === (int) ( $result->get_error_data()['code'] ?? 0 ) ) {
				foreach ( $non_primary_company_uuids as $non_primary_uuid ) {
					$this->company_service->link_contact(
						$non_primary_uuid,
						$contact_uuid,
						false,
						array( 'source' => $this->slug )
					);
				}
			}
		}

		if ( null !== $primary_company_uuid ) {
			$this->company_service->link_contact(
				$primary_company_uuid,
				$contact_uuid,
				true,
				array( 'source' => $this->slug )
			);
		}
	}

	/**
	 * Run a company-linking sweep after a FluentCRM contact bulk-sync job completes.
	 *
	 * Bulk contact sync returns only `batch_uuid` from the SaaS — individual contact
	 * UUIDs aren't available until the SaaS finishes processing. Once the job hits
	 * `completed`, we walk every mapped company and re-run `link_subscribers_to_company`,
	 * which uses the email-fallback added in R4 to resolve UUIDs for contacts that
	 * just landed on SaaS. Idempotent: re-linking an existing pair is a no-op server-side.
	 *
	 * Only triggers for FluentCRM contact sync jobs; ignores company-sync, WooCommerce,
	 * SureCart, etc.
	 *
	 * @since 1.5.1
	 *
	 * @param string $job_id   Job ID.
	 * @param array  $job_info Job info array.
	 * @return void
	 */
	public function handle_bulk_sync_completed( $job_id, $job_info ) {
		unset( $job_id );

		if ( ! is_array( $job_info ) ) {
			return;
		}

		$sync_type = $job_info['sync_type'] ?? '';
		if ( $sync_type !== 'fluentcrm_contacts' ) {
			return;
		}

		if ( ! $this->company_sync || ! class_exists( '\\FluentCrm\\App\\Models\\Company' ) ) {
			return;
		}

		$mappings = $this->get_metadata_mapping( 'companies' );
		if ( empty( $mappings ) ) {
			return;
		}

		foreach ( $mappings as $mapping_key => $company_uuid ) {
			$fc_company_id = (int) str_replace( 'fc_', '', (string) $mapping_key );
			if ( $fc_company_id <= 0 ) {
				continue;
			}

			$company = \FluentCrm\App\Models\Company::find( $fc_company_id );
			if ( ! $company ) {
				continue;
			}

			$this->company_sync->link_subscribers_to_company( $company, $company_uuid );
		}

		Logger::info(
			'FluentCRM Integration',
			'Post-bulk-sync company linking sweep completed.'
		);
	}

	/**
	 * Whether real-time company sync should run.
	 *
	 * Auto-derived from (a) the Company module being enabled in FluentCRM and
	 * (b) the existing `enable_realtime_sync` master toggle — there is no separate
	 * setting for companies, so users can't accidentally desync by leaving an
	 * extra toggle off.
	 *
	 * @since 1.5.1
	 *
	 * @return bool
	 */
	private function should_sync_company_realtime() {
		if ( ! $this->company_service ) {
			return false;
		}

		if ( ! $this->is_company_module_enabled() ) {
			return false;
		}

		return (bool) $this->is_setting_enabled( 'enable_realtime_sync', true );
	}

	/**
	 * Whether company note sync should run.
	 *
	 * Notes follow the same gate as the rest of company sync — kept as a separate
	 * helper for readability at the call sites.
	 *
	 * @since 1.5.1
	 *
	 * @return bool
	 */
	private function should_sync_company_notes() {
		return $this->should_sync_company_realtime();
	}
}
