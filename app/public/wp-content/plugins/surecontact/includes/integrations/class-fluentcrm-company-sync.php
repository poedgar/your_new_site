<?php
/**
 * FluentCRM Company Sync
 *
 * Bulk-synchronizes FluentCRM companies (from the Pro Company module) to SureContact.
 * Uses the same Action Scheduler-backed job model as the contact sync, but talks
 * to the per-company `/api/v1/companies` endpoints instead of the contact bulk-sync
 * SaaS endpoint (since there is no equivalent server-side batch endpoint for companies).
 *
 * @since 1.5.1
 *
 * @package SureContact
 */

namespace SureContact\Integrations;

use SureContact\Logger;
use SureContact\Bulk_Sync_Service;
use SureContact\Company_Service;
use SureContact\Contact_Service;
use SureContact\SaaS_Client;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FluentCRM_Company_Sync
 *
 * @since 1.5.1
 */
class FluentCRM_Company_Sync {

	/**
	 * Sync type identifier consumed by Bulk_Sync_Service routing.
	 *
	 * @since 1.5.1
	 *
	 * @var string
	 */
	const SYNC_TYPE = 'fluentcrm_companies';

	/**
	 * Parent FluentCRM integration.
	 *
	 * @since 1.5.1
	 *
	 * @var FluentCRM_Integration
	 */
	private $integration;

	/**
	 * Company service.
	 *
	 * @since 1.5.1
	 *
	 * @var Company_Service
	 */
	private $company_service;

	/**
	 * Contact service — used by the email-fallback when a subscriber lacks the
	 * `surecontact_contact_uuid` meta but a SaaS contact already exists for them
	 * (e.g. synced via the WordPress integration).
	 *
	 * @since 1.5.1
	 *
	 * @var Contact_Service
	 */
	private $contact_service;

	/**
	 * Batch size for company iteration.
	 *
	 * @since 1.5.1
	 *
	 * @var int
	 */
	private $batch_size;


	/**
	 * Constructor.
	 *
	 * @since 1.5.1
	 *
	 * @param FluentCRM_Integration $integration     Parent integration.
	 * @param Company_Service       $company_service Company service used to link subscribers.
	 * @param Contact_Service       $contact_service Contact service used for the email-fallback lookup.
	 */
	public function __construct( FluentCRM_Integration $integration, Company_Service $company_service, Contact_Service $contact_service ) {
		$this->integration     = $integration;
		$this->company_service = $company_service;
		$this->contact_service = $contact_service;
		// Each bulk-attach call processes up to 100 contacts in one HTTP
		// round-trip, so the work budget here counts bulk calls (plus a
		// per-company sync_company call), NOT individual subscriber links.
		// We measured ~10s server-side per bulk-of-100 (the backend loops
		// attachContact() with per-row pivot writes + automation triggers),
		// so 15 bulk calls × ~10s ≈ 150s — half of AS's 300s time_limit,
		// leaving comfortable headroom for the persist + re-enqueue step.
		$this->batch_size = 15;

		Bulk_Sync_Service::register_sync_handler( self::SYNC_TYPE, $this );
	}

	/**
	 * Sync type metadata for the bulk-sync UI.
	 *
	 * @since 1.5.1
	 *
	 * @return array Array of sync-type definitions.
	 */
	public function get_sync_types() {
		return array(
			array(
				'type'        => self::SYNC_TYPE,
				'title'       => __( 'Companies', 'surecontact' ),
				'description' => __( 'Synchronize all FluentCRM companies (and their contact links) to SureContact.', 'surecontact' ),
			),
		);
	}

	/**
	 * Entry point invoked by Bulk_Sync_Service.
	 *
	 * @since 1.5.1
	 *
	 * @param string $sync_type Sync type identifier.
	 * @return array Job response or error.
	 */
	public function handle_sync( $sync_type ) {
		if ( ! class_exists( '\\FluentCrm\\App\\Models\\Company' ) ) {
			return array(
				'success' => false,
				'message' => __( 'FluentCRM Company module is not available.', 'surecontact' ),
			);
		}

		$total = (int) \FluentCrm\App\Models\Company::count();

		if ( $total === 0 ) {
			return array(
				'success' => true,
				'message' => __( 'No companies to sync.', 'surecontact' ),
			);
		}

		$total_links = $this->count_total_contact_links();

		return $this->start_bulk_sync( $total, $total_links, $sync_type );
	}

	/**
	 * Count subscriber-pivot rows across all FluentCRM companies.
	 *
	 * Used as the denominator for the "Linked Contacts" progress display and to
	 * compute an upper-bound on AS-action count (one work unit = one HTTP call,
	 * either `sync_company` or `link_contact`).
	 *
	 * @since 1.5.1
	 *
	 * @return int
	 */
	private function count_total_contact_links() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time aggregate read at job start; no caching needed.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriber_pivot WHERE object_type = %s",
				'FluentCrm\\App\\Models\\Company'
			)
		);

		return (int) $count;
	}

	/**
	 * Initialize the bulk-sync job and enqueue the first batch.
	 *
	 * @since 1.5.1
	 *
	 * @param int    $total       Total companies to sync.
	 * @param int    $total_links Total subscriber-company pivot rows to attach.
	 * @param string $sync_type   Sync type identifier.
	 * @return array Job response.
	 */
	private function start_bulk_sync( $total, $total_links, $sync_type ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			Logger::error( 'FluentCRM Company Sync', 'Action Scheduler is not available' );
			return array(
				'success' => false,
				'message' => __( 'Action Scheduler is not available.', 'surecontact' ),
			);
		}

		$job_id = uniqid( 'sync_job_', true );

		// Each AS action processes up to `batch_size` work units. A work unit
		// is either a `sync_company` call (1 per company) or a bulk-attach
		// call (1 per chunk of up to BULK_ATTACH_MAX contacts). The upper
		// bound on AS actions is therefore (companies + chunks) / batch_size.
		$total_link_chunks = (int) ceil( $total_links / \SureContact\API\Company_API::BULK_ATTACH_MAX );
		$total_work_units  = $total + $total_link_chunks;
		$total_batches     = max( 1, (int) ceil( $total_work_units / $this->batch_size ) );

		$job_data = array(
			'job_id'                      => $job_id,
			'batch_uuid'                  => '',
			'total_users'                 => $total,
			'total_contact_links'         => $total_links,
			'total_batches'               => $total_batches,
			'processed_batches'           => 0,
			'current_offset'              => 0,
			'current_company_offset'      => 0,
			'current_subscriber_offset'   => 0,
			'current_company_initialized' => false,
			'batch_size'                  => $this->batch_size,
			'query_args'                  => array(),
			'status'                      => 'processing',
			'created_at'                  => current_time( 'mysql' ),
			'type'                        => $this->integration->get_slug(),
			'sync_type'                   => $sync_type,
			'success_count'               => 0,
			'failure_count'               => 0,
			'linked_contacts'             => 0,
		);

		// add_option with autoload=no keeps the job row off the wp_options
		// autoload set. Subsequent `safe_update_job` calls use `update_option`,
		// which preserves the existing autoload value.
		add_option( "surecontact_job_{$job_id}", $job_data, '', false );

		// Defer every batch to Action Scheduler. Unlike the contact bulk-sync —
		// which offloads work to a single SaaS endpoint — company sync makes one
		// HTTP call per linked contact, so a company with thousands of contacts
		// can easily exceed PHP's max_execution_time when run inline on the
		// triggering request. Letting AS handle it from the start keeps the UI
		// responsive and the worker time-box-friendly.
		as_enqueue_async_action( Bulk_Sync_Service::BATCH_HOOK, array( 'job_id' => $job_id ), 'surecontact' );

		$response = Bulk_Sync_Service::format_job_response( $job_id );
		if ( ! $response ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to retrieve job status.', 'surecontact' ),
			);
		}

		return $response;
	}

	/**
	 * Process one chain step for the company-sync job.
	 *
	 * Each invocation performs up to `batch_size` work units — where one work
	 * unit is either a single `sync_company` call OR a single `link_contact`
	 * call — and then re-enqueues itself via Action Scheduler. This mirrors the
	 * contact-sync chain pattern and guarantees per-action wall-clock stays
	 * well under AS's 300s `time_limit`, even when one company carries
	 * hundreds of thousands of subscribers.
	 *
	 * Resume state is tracked via `current_company_offset` (which company we're
	 * on) and `current_subscriber_offset` (where we left off inside that
	 * company's subscribers).
	 *
	 * @since 1.5.1
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public function process_batch( $job_id ) {
		$cancelled_jobs = get_option( 'surecontact_sync_job_cancelled', array() );
		if ( is_array( $cancelled_jobs ) && in_array( $job_id, $cancelled_jobs, true ) ) {
			Bulk_Sync_Service::safe_update_job( $job_id, array( 'status' => 'cancelled' ) );
			return;
		}

		$job_data = get_option( "surecontact_job_{$job_id}" );
		if ( ! $job_data ) {
			Logger::error( 'FluentCRM Company Sync', "Job {$job_id} not found" );
			return;
		}

		if ( in_array( $job_data['status'], array( 'completed', 'cancelled' ), true ) ) {
			return;
		}

		if ( ! class_exists( '\\FluentCrm\\App\\Models\\Company' ) ) {
			Bulk_Sync_Service::safe_update_job( $job_id, array( 'status' => 'failed' ) );
			return;
		}

		$batch_size          = (int) $job_data['batch_size'];
		$total               = (int) $job_data['total_users'];
		$company_offset      = (int) ( $job_data['current_company_offset'] ?? $job_data['current_offset'] ?? 0 );
		$sub_offset          = (int) ( $job_data['current_subscriber_offset'] ?? 0 );
		$success_count       = (int) ( $job_data['success_count'] ?? 0 );
		$failure_count       = (int) ( $job_data['failure_count'] ?? 0 );
		$linked_contacts     = (int) ( $job_data['linked_contacts'] ?? 0 );
		$company_initialized = (bool) ( $job_data['current_company_initialized'] ?? false );

		$work_budget  = $batch_size;
		$rate_limited = false;

		while ( $work_budget > 0 && $company_offset < $total ) {
			$company = \FluentCrm\App\Models\Company::query()
				->orderBy( 'id', 'asc' )
				->skip( $company_offset )
				->take( 1 )
				->get()
				->first();

			if ( ! $company ) {
				// Company was deleted after the job started counting. Mark the
				// remainder as done so the chain can finish.
				$company_offset = $total;
				break;
			}

			// First-time work for THIS company in THIS job: sync_company +
			// notes + count. The `$company_initialized` flag survives across
			// batches in the job state, so a rate-limit retry that resumes
			// mid-company skips this branch (no double sync_company call,
			// no double success_count increment).
			if ( ! $company_initialized ) {
				$before_uuid = $this->integration->get_company_uuid_by_id( (int) $company->id );
				$uuid        = $this->integration->sync_company( $company );
				--$work_budget;

				if ( ! $uuid ) {
					if ( SaaS_Client::was_rate_limited_this_request() ) {
						// Stop processing this batch; leave company_offset where
						// it is so the same company is retried on the rescheduled
						// batch (after the 60s backoff).
						$rate_limited = true;
						break;
					}
					++$failure_count;
					++$company_offset;
					continue;
				}

				++$success_count;
				$company_initialized = true;

				$was_recreated = ( $before_uuid !== null && $before_uuid !== $uuid );
				$notes_created = $this->integration->sync_company_notes( $company, $uuid, $was_recreated );
				$work_budget  -= $notes_created;

				if ( SaaS_Client::was_rate_limited_this_request() ) {
					// Notes path tripped the SaaS throttle — stop here, leave
					// the offset AND the initialized flag so the next batch
					// resumes mid-company without re-running sync_company.
					$rate_limited = true;
					break;
				}
			} else {
				// Resuming a company that was already initialized in an
				// earlier batch (rate-limit retry). Reuse the cached mapping;
				// re-sync only if the mapping vanished between AS actions.
				$uuid = $this->integration->get_company_uuid_by_id( (int) $company->id );

				if ( ! $uuid ) {
					$uuid = $this->integration->sync_company( $company );
					--$work_budget;

					if ( ! $uuid ) {
						if ( SaaS_Client::was_rate_limited_this_request() ) {
							$rate_limited = true;
							break;
						}
						++$failure_count;
						++$company_offset;
						$sub_offset          = 0;
						$company_initialized = false;
						continue;
					}
				}

				// Notes are idempotent (already-mapped notes are skipped) — re-run
				// so a notes loop that broke on rate-limit in the previous batch
				// can finish here.
				$notes_created = $this->integration->sync_company_notes( $company, $uuid, false );
				$work_budget  -= $notes_created;

				if ( SaaS_Client::was_rate_limited_this_request() ) {
					$rate_limited = true;
					break;
				}
			}

			if ( $work_budget <= 0 ) {
				// Budget exhausted on the sync_company call alone — pause here
				// and let the next AS action pick up linking from sub_offset=0.
				break;
			}

			$chunk = $this->link_subscriber_chunk( $company, $uuid, $sub_offset );

			$linked_contacts += $chunk['linked'];
			$work_budget     -= $chunk['work_consumed'];

			if ( SaaS_Client::was_rate_limited_this_request() ) {
				// Link path tripped the throttle (bulk_link_contacts or
				// link_contact). Persist offsets so the next batch resumes
				// from the same sub_offset after the 60s backoff.
				$rate_limited = true;
				break;
			}

			if ( $chunk['company_done'] ) {
				++$company_offset;
				$sub_offset          = 0;
				$company_initialized = false;
			} else {
				$sub_offset += $chunk['processed'];
			}

			// Persist mid-action progress so the UI's poll reflects live
			// activity and a downstream timeout can't leave the job stuck at
			// the values it had when this AS action began.
			Bulk_Sync_Service::safe_update_job(
				$job_id,
				array(
					'current_company_offset'      => $company_offset,
					'current_subscriber_offset'   => $sub_offset,
					'current_company_initialized' => $company_initialized,
					'current_offset'              => $company_offset,
					'success_count'               => $success_count,
					'failure_count'               => $failure_count,
					'linked_contacts'             => $linked_contacts,
				)
			);
		}

		// A rate-limited batch is never "complete" — by definition there are
		// still companies left to process once the quota clears.
		$is_complete       = ( $company_offset >= $total ) && ! $rate_limited;
		$processed_batches = (int) ( $job_data['processed_batches'] ?? 0 ) + 1;

		Bulk_Sync_Service::safe_update_job(
			$job_id,
			array(
				'current_company_offset'      => $company_offset,
				'current_subscriber_offset'   => $sub_offset,
				'current_company_initialized' => $company_initialized,
				// Keep the legacy `current_offset` in sync for any external
				// consumer that reads it.
				'current_offset'              => $company_offset,
				'processed_batches'           => $processed_batches,
				'success_count'               => $success_count,
				'failure_count'               => $failure_count,
				'linked_contacts'             => $linked_contacts,
				'status'                      => $is_complete ? 'completed' : 'processing',
			)
		);

		if ( ! $is_complete ) {
			if ( $rate_limited ) {
				as_schedule_single_action(
					time() + 60,
					Bulk_Sync_Service::BATCH_HOOK,
					array( 'job_id' => $job_id ),
					'surecontact'
				);
				Logger::info(
					'FluentCRM Company Sync',
					sprintf( 'Job %s: next batch scheduled with 60s delay due to SaaS rate limit / plan-quota.', $job_id )
				);
			} else {
				as_enqueue_async_action( Bulk_Sync_Service::BATCH_HOOK, array( 'job_id' => $job_id ), 'surecontact' );
			}
			return;
		}

		Logger::info(
			'FluentCRM Company Sync',
			sprintf(
				'Job %s completed. Synced %d, failed %d, linked %d contacts.',
				$job_id,
				$success_count,
				$failure_count,
				$linked_contacts
			)
		);
	}

	/**
	 * Link a FluentCRM company's subscribers to the corresponding SureContact company.
	 *
	 * Resolves each subscriber's SureContact contact UUID via three paths, in order:
	 *  1. The `surecontact_contact_uuid` meta (set during FluentCRM real-time contact sync).
	 *  2. The email-fallback (`Contact_Service::find_contact_id_by_email`) — covers
	 *     contacts synced via other integrations (e.g. WordPress) and contacts
	 *     synced via the FluentCRM bulk path, which doesn't set the meta key.
	 *  3. Skip — the contact-side bulk-sync completion sweep will retry this pair
	 *     once the contact lands on SaaS.
	 *
	 * Iterates the pivot in pages of `$subscriber_chunk_size` so a single company
	 * with tens of thousands of contacts can be linked without exhausting memory.
	 *
	 * Public so the contact-sync completion sweep in `FluentCRM_Integration` can
	 * re-run linking after a contact bulk-sync finishes.
	 *
	 * @since 1.5.1
	 *
	 * @param \FluentCrm\App\Models\Company $company      Company model.
	 * @param string                        $company_uuid SureContact company UUID.
	 * @return int Number of subscribers successfully linked.
	 */
	public function link_subscribers_to_company( $company, $company_uuid ) {
		$linked_total = 0;
		$offset       = 0;

		do {
			$chunk         = $this->link_subscriber_chunk( $company, $company_uuid, $offset );
			$linked_total += $chunk['linked'];
			$offset       += $chunk['processed'];
		} while ( ! $chunk['company_done'] );

		return $linked_total;
	}

	/**
	 * Link one bulk-sized chunk of a company's subscribers.
	 *
	 * Loads up to BULK_ATTACH_MAX (100) subscribers starting at `$start_offset`,
	 * resolves each one's SureContact UUID locally (meta + email-fallback, no
	 * HTTP), and then collapses the linking into:
	 *   - ONE `bulk_link_contacts` call for the non-primary contacts (1 HTTP),
	 *   - One single `attach_contact` for the primary contact, if present.
	 *
	 * Falls back to the per-subscriber `link_single_subscriber` loop when the
	 * bulk endpoint rejects the batch with HTTP 422 (typically stale meta
	 * after a SaaS workspace reset). The per-subscriber path already runs the
	 * email-lookup recovery so we don't duplicate that logic here.
	 *
	 * Returns `work_consumed` so `process_batch` can budget AS time correctly:
	 * one bulk call counts as one work unit (not 100), letting an AS action
	 * fit ~30 chunks comfortably inside AS's 300s `time_limit`.
	 *
	 * @since 1.5.1
	 *
	 * @param \FluentCrm\App\Models\Company $company      Company model.
	 * @param string                        $company_uuid SureContact company UUID.
	 * @param int                           $start_offset Subscriber-relation offset to resume from.
	 * @return array{linked:int,processed:int,company_done:bool,work_consumed:int}
	 */
	private function link_subscriber_chunk( $company, $company_uuid, $start_offset ) {
		$page_size = \SureContact\API\Company_API::BULK_ATTACH_MAX;

		if ( ! method_exists( $company, 'subscribers' ) ) {
			return array(
				'linked'        => 0,
				'processed'     => 0,
				'company_done'  => true,
				'work_consumed' => 0,
			);
		}

		$primary_subscriber_id = isset( $company->owner_id ) ? (int) $company->owner_id : 0;

		$subscribers = $company->subscribers()
			->orderBy( 'fc_subscribers.id', 'asc' )
			->skip( $start_offset )
			->take( $page_size )
			->get();

		if ( $subscribers->isEmpty() ) {
			return array(
				'linked'        => 0,
				'processed'     => 0,
				'company_done'  => true,
				'work_consumed' => 0,
			);
		}

		$processed    = $subscribers->count();
		$company_done = ( $processed < $page_size );

		// Phase 1: separate the primary subscriber (handled by the existing
		// single-attach path, which preserves stale-meta recovery) from the
		// rest, and pre-resolve the rest's UUIDs locally — no HTTP yet for
		// the bulk-friendly group.
		$non_primary_uuids = array();
		$primary           = null;

		foreach ( $subscribers as $subscriber ) {
			/**
			 * Narrow the iterator type — BelongsToMany::get() returns an untyped Collection.
			 *
			 * @var \FluentCrm\App\Models\Subscriber $subscriber
			 */
			// Only the company's `owner_id` identifies the one true primary. The
			// inverse FluentCRM field (`subscribers.company_id`) records each
			// subscriber's own primary company — matching it here would mark
			// every subscriber in the company as "primary", which is both
			// semantically wrong and would force a single-attach per chunk
			// (doubling work-budget consumption for large companies).
			$is_primary = ( $primary_subscriber_id > 0 && (int) $subscriber->id === $primary_subscriber_id );

			if ( $is_primary && null === $primary ) {
				$primary = $subscriber;
				continue;
			}

			$uuid = $this->resolve_contact_uuid( $subscriber );
			if ( $uuid ) {
				$non_primary_uuids[] = $uuid;
			}
		}

		$linked        = 0;
		$work_consumed = 0;

		// Phase 2: bulk-attach the non-primary contacts in one call.
		if ( ! empty( $non_primary_uuids ) ) {
			$result = $this->company_service->bulk_link_contacts(
				$company_uuid,
				$non_primary_uuids,
				array( 'source' => $this->integration->get_slug() )
			);
			++$work_consumed;

			if ( is_wp_error( $result ) ) {
				if ( $this->is_bulk_validation_error( $result ) ) {
					// Workspace-reset / stale-meta case: bail to the per-subscriber
					// path so existing email-lookup recovery runs for each row.
					$fallback       = $this->link_chunk_per_subscriber( $subscribers, $company, $company_uuid, $primary_subscriber_id );
					$linked        += $fallback['linked'];
					$work_consumed += $fallback['work_consumed'];
					unset( $subscribers );

					return array(
						'linked'        => $linked,
						'processed'     => $processed,
						'company_done'  => $company_done,
						'work_consumed' => $work_consumed,
					);
				}
			}

			if ( ! is_wp_error( $result ) ) {
				$linked += (int) $result['attached'] + (int) $result['skipped'];
			}
		}

		// Phase 3: single-attach the primary contact so `is_primary=true` is
		// honored (the backend ignores the flag whenever the bulk request
		// carries more than one UUID).
		if ( null !== $primary ) {
			$primary_ok = $this->link_single_subscriber( $primary, $company, $company_uuid, $primary_subscriber_id );
			++$work_consumed;
			if ( $primary_ok ) {
				++$linked;
			}
		}

		unset( $subscribers );

		return array(
			'linked'        => $linked,
			'processed'     => $processed,
			'company_done'  => $company_done,
			'work_consumed' => $work_consumed,
		);
	}

	/**
	 * Resolve a subscriber's SureContact UUID via meta + email-fallback.
	 *
	 * Pure resolution — performs at most one SaaS lookup (the email fallback)
	 * but does NOT mutate meta or call any attach endpoint. The actual
	 * stale-meta recovery (mutate-on-422) still lives in
	 * `link_single_subscriber`, which runs in the per-subscriber fallback
	 * path.
	 *
	 * @since 1.5.1
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber Subscriber.
	 * @return string|null UUID or null when neither path resolves one.
	 */
	private function resolve_contact_uuid( $subscriber ) {
		$contact_uuid = $subscriber->getMeta( 'surecontact_contact_uuid', 'surecontact' );
		if ( $contact_uuid ) {
			return $contact_uuid;
		}

		if ( empty( $subscriber->email ) ) {
			return null;
		}

		$uuid = $this->contact_service->find_contact_id_by_email( $subscriber->email );
		if ( $uuid ) {
			return $uuid;
		}

		// Neither meta nor SaaS-side lookup yielded a UUID — the contact was
		// either never synced or was deleted on the SaaS. Recreate it inline
		// so the upstream bulk_link_contacts call has a valid UUID to send.
		// Without this, subscribers in this state are silently dropped from
		// the attach payload and the bulk sync completes with zero links.
		$recreated_uuid = $this->recreate_contact_on_saas( $subscriber );
		if ( $recreated_uuid ) {
			$subscriber->updateMeta( 'surecontact_contact_uuid', $recreated_uuid, 'surecontact' );
			return $recreated_uuid;
		}

		return null;
	}

	/**
	 * Per-subscriber fallback used when the bulk endpoint rejects a chunk.
	 *
	 * Iterates the same collection of subscribers and delegates each to
	 * `link_single_subscriber`, which carries the existing stale-meta
	 * recovery (refresh via email lookup + retry + meta cleanup).
	 *
	 * @since 1.5.1
	 *
	 * @param iterable                      $subscribers           Subscriber collection.
	 * @param \FluentCrm\App\Models\Company $company               Parent company.
	 * @param string                        $company_uuid          SureContact company UUID.
	 * @param int                           $primary_subscriber_id Subscriber ID that should be primary, or 0.
	 * @return array{linked:int,work_consumed:int}
	 */
	private function link_chunk_per_subscriber( $subscribers, $company, $company_uuid, $primary_subscriber_id ) {
		$linked        = 0;
		$work_consumed = 0;

		foreach ( $subscribers as $subscriber ) {
			/**
			 * Narrow the iterator type — BelongsToMany::get() returns an untyped Collection.
			 *
			 * @var \FluentCrm\App\Models\Subscriber $subscriber
			 */
			if ( $this->link_single_subscriber( $subscriber, $company, $company_uuid, $primary_subscriber_id ) ) {
				++$linked;
			}
			++$work_consumed;
		}

		return array(
			'linked'        => $linked,
			'work_consumed' => $work_consumed,
		);
	}

	/**
	 * Detect a validation-failure 422 from the bulk-attach endpoint.
	 *
	 * Laravel returns 422 with `errors.contact_uuids.{index}` keys when one or
	 * more UUIDs in the batch fail the `exists:contacts,uuid` rule. When this
	 * happens we drop to the per-subscriber path so the existing stale-meta
	 * recovery can refresh those specific rows. A wider 422 (e.g.
	 * `errors.linked_via`) shouldn't reach this code because we control the
	 * payload — but we treat any 422 as "bulk failed, fall back" to stay safe.
	 *
	 * @since 1.5.1
	 *
	 * @param \WP_Error $error API error.
	 * @return bool
	 */
	private function is_bulk_validation_error( $error ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			return false;
		}
		return 422 === (int) ( $data['code'] ?? 0 );
	}

	/**
	 * Resolve a single subscriber's contact UUID and link it to the company.
	 *
	 * Extracted from `link_subscribers_to_company` so the chunked outer loop
	 * stays readable. Returns true when an attach call succeeds (including
	 * after stale-meta recovery), false otherwise.
	 *
	 * @since 1.5.1
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber           Subscriber model.
	 * @param \FluentCrm\App\Models\Company    $company              Parent FluentCRM company.
	 * @param string                           $company_uuid         SureContact company UUID.
	 * @param int                              $primary_subscriber_id Subscriber ID that should be marked primary, or 0.
	 * @return bool
	 */
	private function link_single_subscriber( $subscriber, $company, $company_uuid, $primary_subscriber_id ) {
		$contact_uuid = $subscriber->getMeta( 'surecontact_contact_uuid', 'surecontact' );
		$used_meta    = (bool) $contact_uuid;

		if ( ! $contact_uuid && ! empty( $subscriber->email ) ) {
			// Fall back to a SaaS lookup by email. Handles two cases:
			// (a) contacts synced via another integration (no FluentCRM meta), and
			// (b) contacts synced via the FluentCRM bulk path (which doesn't write
			// the meta key — only the real-time hook does).
			$contact_uuid = $this->contact_service->find_contact_id_by_email( $subscriber->email );
		}

		if ( ! $contact_uuid ) {
			// Neither meta nor email lookup yielded a UUID — the contact was
			// either never synced or was deleted on the SaaS. Recreate it
			// inline so the company-link can complete on this pass instead
			// of failing silently.
			$contact_uuid = $this->recreate_contact_on_saas( $subscriber );

			if ( ! $contact_uuid ) {
				return false;
			}

			$subscriber->updateMeta( 'surecontact_contact_uuid', $contact_uuid, 'surecontact' );
		}

		// See `link_subscriber_chunk()` for the rationale on dropping the
		// `subscriber->company_id` clause — only `owner_id` defines the true primary.
		$is_primary = ( $primary_subscriber_id > 0 && (int) $subscriber->id === $primary_subscriber_id );

		// `$company` is only used now for context; silence unused-variable warnings
		// without changing the signature (called by the existing primary path).
		unset( $company );

		$result = $this->company_service->link_contact(
			$company_uuid,
			$contact_uuid,
			$is_primary,
			array( 'source' => $this->integration->get_slug() )
		);

		if ( ! is_wp_error( $result ) ) {
			return true;
		}

		// Stale-meta recovery: SaaS workspaces can be reset/cleaned, leaving
		// FluentCRM holding a contact UUID that no longer exists on the SaaS.
		// When the attach call is rejected with "invalid contact uuid", refresh
		// the UUID via email lookup, persist the new value, and retry once.
		if ( ! $used_meta || ! $this->is_stale_uuid_error( $result ) ) {
			return false;
		}

		$fresh_uuid = ! empty( $subscriber->email )
			? $this->contact_service->find_contact_id_by_email( $subscriber->email )
			: false;

		if ( $fresh_uuid && $fresh_uuid !== $contact_uuid ) {
			$subscriber->updateMeta( 'surecontact_contact_uuid', $fresh_uuid, 'surecontact' );
			$retry = $this->company_service->link_contact(
				$company_uuid,
				$fresh_uuid,
				$is_primary,
				array( 'source' => $this->integration->get_slug() )
			);
			return ! is_wp_error( $retry );
		}

		if ( ! $fresh_uuid ) {
			// Contact truly doesn't exist on SaaS. Recreate it inline via the
			// upsert endpoint so the company-link can complete on this same
			// pass — otherwise the link stays broken until a FluentCRM edit
			// or contact bulk-sync run repopulates the SaaS-side contact.
			$recreated_uuid = $this->recreate_contact_on_saas( $subscriber );

			if ( $recreated_uuid ) {
				$subscriber->updateMeta( 'surecontact_contact_uuid', $recreated_uuid, 'surecontact' );
				$retry = $this->company_service->link_contact(
					$company_uuid,
					$recreated_uuid,
					$is_primary,
					array( 'source' => $this->integration->get_slug() )
				);
				return ! is_wp_error( $retry );
			}

			// Recreate also failed (missing email, API down, validation
			// reject) — clear dead meta so the next pass at least starts
			// from the email path fresh.
			$subscriber->updateMeta( 'surecontact_contact_uuid', '', 'surecontact' );
		}

		return false;
	}

	/**
	 * Push a subscriber back to the SaaS via the upsert endpoint and return
	 * its new UUID.
	 *
	 * Used by the company-link recovery path when both the stored meta and
	 * the email lookup miss — meaning the contact was deleted on the SaaS
	 * and nothing has recreated it since.
	 *
	 * @since 1.5.1
	 *
	 * @param \FluentCrm\App\Models\Subscriber $subscriber Subscriber model.
	 * @return string|null New contact UUID, or null if the recreate failed.
	 */
	private function recreate_contact_on_saas( $subscriber ) {
		if ( empty( $subscriber->email ) ) {
			return null;
		}

		$subscriber_data = $this->integration->prepare_subscriber_data( $subscriber );
		$mapped_data     = $this->integration->normalize_data( $subscriber_data );

		if ( empty( $mapped_data['primary_fields']['email'] ) ) {
			return null;
		}

		$user_id = $subscriber->user_id ?? 0;
		$result  = $this->integration->send_to_crm( $mapped_data, $user_id );

		if ( ! is_wp_error( $result ) && ! empty( $result['contact_id'] ) ) {
			return (string) $result['contact_id'];
		}

		// Stale-mapping recovery for the contact-create payload itself: the
		// upsert can 422 if the embedded list/tag UUIDs no longer exist on
		// the SaaS (lists/tags were deleted alongside contacts, but the
		// local mapping still carries the old UUIDs). Strip the dead refs
		// and retry — the contact gets created bare so the company-link can
		// complete on this pass. List/tag attachments will be re-established
		// by the next lists/tags sync.
		if ( ! $this->is_stale_list_or_tag_error( $result ) ) {
			return null;
		}

		unset( $mapped_data['list_uuids'], $mapped_data['tag_uuids'] );
		$retry = $this->integration->send_to_crm( $mapped_data, $user_id );

		if ( is_wp_error( $retry ) || empty( $retry['contact_id'] ) ) {
			return null;
		}

		return (string) $retry['contact_id'];
	}

	/**
	 * Detect a 422 from wordpress/sync-contact caused by stale list_uuids or
	 * tag_uuids in the payload. Laravel returns:
	 *   { "errors": { "list_uuids.N": [...], "tag_uuids.M": [...] } }
	 * — keys are indexed, so we match by prefix.
	 *
	 * @since 1.5.1
	 *
	 * @param mixed $result Result from send_to_crm.
	 * @return bool
	 */
	private function is_stale_list_or_tag_error( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}

		$data = $result->get_error_data();
		if ( ! is_array( $data ) || (int) ( $data['code'] ?? 0 ) !== 422 ) {
			return false;
		}

		$body = isset( $data['body'] ) && is_string( $data['body'] ) ? $data['body'] : '';
		if ( $body === '' ) {
			return false;
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['errors'] ) || ! is_array( $decoded['errors'] ) ) {
			return false;
		}

		foreach ( array_keys( $decoded['errors'] ) as $key ) {
			if ( strpos( (string) $key, 'list_uuids' ) === 0 || strpos( (string) $key, 'tag_uuids' ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect a "stale UUID" rejection from the SaaS attach endpoint.
	 *
	 * The backend's `AttachContactRequest` validates four fields (contact_uuid,
	 * role, is_primary, linked_via) and Laravel returns 422 with a structured
	 * payload `{ "errors": { "<field>": ["..."] } }`. Looking at the specific
	 * `errors.contact_uuid` key tells us the failure is about the UUID — not
	 * one of the other fields — and is the deterministic signal we want before
	 * touching local meta.
	 *
	 * @since 1.5.1
	 *
	 * @param \WP_Error $error API error.
	 * @return bool
	 */
	private function is_stale_uuid_error( $error ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || (int) ( $data['code'] ?? 0 ) !== 422 ) {
			return false;
		}

		$body = isset( $data['body'] ) && is_string( $data['body'] ) ? $data['body'] : '';
		if ( $body === '' ) {
			return false;
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['errors']['contact_uuid'] ) ) {
			return false;
		}

		return true;
	}
}
