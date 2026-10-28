<?php
/**
 * Abandoned Cart Manager
 *
 * Manages abandoned cart detection, tagging, and recovery with 2 processing modes:
 * 1. Recurring cron — every 5 minutes, processes one batch of abandoned carts.
 * 2. Async batch chaining — if more carts remain after a batch, chains another action.
 *
 * Follows the same pattern as Queue_Manager (class-queue-manager.php).
 *
 * @since 1.5.0
 *
 * @package SureContact
 */

namespace SureContact;

use SureContact\Database\Abandoned_Carts_Operations;
use SureContact\Database\Integrations_DB;
use SureContact\Integrations\EDD_Abandoned_Cart;
use SureContact\Integrations\Woocommerce_Abandoned_Cart;
use SureContact\Traits\Abandoned_Cart_Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Abandoned_Cart_Manager
 *
 * Handles cart tracking, abandonment detection, and recovery.
 *
 * @since 1.5.0
 */
class Abandoned_Cart_Manager {

	use Abandoned_Cart_Helpers;

	/**
	 * Action Scheduler hook for recurring detection (every 5 min).
	 *
	 * @since 1.5.0
	 *
	 * @var string
	 */
	const DETECTOR_HOOK = 'surecontact_detect_abandoned_carts';

	/**
	 * Action Scheduler hook for async batch chaining.
	 *
	 * @since 1.5.0
	 *
	 * @var string
	 */
	const BATCH_HOOK = 'surecontact_process_abandoned_cart_batch';

	/**
	 * Action Scheduler hook for daily cleanup.
	 *
	 * @since 1.5.0
	 *
	 * @var string
	 */
	const CLEANUP_HOOK = 'surecontact_cleanup_abandoned_carts';

	/**
	 * Detection interval in seconds (5 minutes).
	 *
	 * @since 1.5.0
	 *
	 * @var int
	 */
	const DETECTION_INTERVAL = 300;

	/**
	 * Entries to process per batch.
	 *
	 * @since 1.5.0
	 *
	 * @var int
	 */
	const BATCH_SIZE = 10;

	/**
	 * Maximum async batch chains per cron cycle.
	 *
	 * Caps how many consecutive batches a single cron tick can chain
	 * to avoid flooding Action Scheduler. Remaining carts are picked up
	 * by the next recurring cron run (every DETECTION_INTERVAL seconds).
	 *
	 * @since 1.5.0
	 *
	 * @var int
	 */
	const MAX_CHAIN_DEPTH = 50;

	/**
	 * Transient lock duration in seconds.
	 *
	 * Set to 3x DETECTION_INTERVAL so a long-running batch (slow SaaS API,
	 * large queue) cannot have its lock self-expire while still working —
	 * which would let the next cron tick acquire the lock and double-process
	 * the same active rows before either run flips them to abandoned.
	 *
	 * @since 1.5.0
	 *
	 * @var int
	 */
	const LOCK_DURATION = 900;

	/**
	 * Transient key for cached "any abandoned cart enabled" check.
	 *
	 * Avoids hitting Integrations_DB on every request via action_scheduler_init.
	 * Busted by the `surecontact_integration_settings_saved` hook.
	 *
	 * @since 1.5.0
	 *
	 * @var string
	 */
	const ENABLED_CHECK_CACHE_KEY = 'surecontact_ac_enabled_check';

	/**
	 * Constructor
	 *
	 * @since 1.5.0
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	private function init_hooks() {
		add_action( self::DETECTOR_HOOK, array( $this, 'process_batch' ) );
		add_action( self::BATCH_HOOK, array( $this, 'process_chained_batch' ), 10, 1 );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup_old_carts' ) );

		// AS dispatches `action_scheduler_init` at `init` priority 1; the manager
		// constructs at `init` priority 2, so the action has already fired by the
		// time we get here. Call directly when that's the case, otherwise hook it
		// for the (rare) path where AS initializes later.
		if ( did_action( 'action_scheduler_init' ) ) {
			$this->maybe_schedule();
		} else {
			add_action( 'action_scheduler_init', array( $this, 'maybe_schedule' ) );
		}

		// Bust the enabled-check cache when integration settings are saved.
		add_action( 'surecontact_integration_settings_saved', array( __CLASS__, 'bust_enabled_check_cache' ) );
	}

	// =========================================================================
	// Cart Tracking
	// =========================================================================

	/**
	 * Track or update an abandoned cart.
	 *
	 * Finds an existing active/abandoned row by email or user_id. If found, updates it
	 * (resetting abandoned rows back to active). If not found, inserts a new row.
	 * Uses a transient throttle to prevent DB churn on rapid cart changes.
	 *
	 * Note on duplicate rows: the find-then-update/insert is non-atomic, so two
	 * concurrent requests for the same email could both miss and both insert.
	 * The 30s throttle below + LIMIT 1 in get_active_by_email() bound the impact
	 * to one extra row that is picked up by the next detection scan.
	 *
	 * @since 1.5.0
	 *
	 * @param string $email       Customer email address.
	 * @param int    $user_id     WordPress user ID (0 for guests).
	 * @param array  $cart_data   Cart contents as associative array.
	 * @param float  $cart_total  Cart total amount.
	 * @param string $integration Integration slug.
	 * @return int|false Cart row ID on success, false on throttle/failure.
	 */
	public function track_cart( $email, $user_id, $cart_data, $cart_total, $integration = 'woocommerce' ) {
		if ( empty( $email ) || ! is_email( $email ) ) {
			return false;
		}

		// Throttle: skip if tracked within the last 30 seconds.
		$throttle_key = 'surecontact_ac_throttle_' . md5( $email . $integration );
		if ( get_transient( $throttle_key ) ) {
			return false;
		}

		set_transient( $throttle_key, true, 30 );

		$cart_json = wp_json_encode( $cart_data );
		if ( false === $cart_json ) {
			Logger::error( 'Abandoned Cart', 'Failed to encode cart data', array( 'email_hash' => md5( $email ) ) );
			return false;
		}

		// Try to find existing active/abandoned row.
		$existing = Abandoned_Carts_Operations::get_active_by_email( $email, $integration );

		if ( ! $existing && $user_id > 0 ) {
			$existing = Abandoned_Carts_Operations::get_active_by_user_id( $user_id, $integration );
		}

		if ( $existing ) {
			// Don't clear contact_uuid: process_single_cart re-resolves it via
			// find_contact_id_by_email on every re-abandonment, so clearing
			// here only breaks recovery when capture_checkout_email runs just
			// before order completion (GIT-324).
			$update_data = array(
				'email'      => $email,
				'cart_data'  => $cart_json,
				'cart_total' => $cart_total,
				'status'     => Abandoned_Carts_Operations::STATUS_ACTIVE,
			);

			if ( $user_id > 0 ) {
				$update_data['user_id'] = $user_id;
			}

			Abandoned_Carts_Operations::update( $existing->id, $update_data ); // @phpstan-ignore property.notFound

			return (int) $existing->id; // @phpstan-ignore property.notFound
		}

		// Insert new row.
		return Abandoned_Carts_Operations::insert(
			array(
				'integration' => $integration,
				'email'       => $email,
				'user_id'     => $user_id,
				'cart_data'   => $cart_json,
				'cart_total'  => $cart_total,
			)
		);
	}

	/**
	 * Mark cart(s) as recovered by email.
	 *
	 * Updates both active and abandoned rows. Returns contact UUIDs of rows
	 * that were already tagged (need tag removal).
	 *
	 * @since 1.5.0
	 *
	 * @param string $email       Customer email address.
	 * @param string $integration Integration slug.
	 * @return array Array of contact UUIDs that need tag removal.
	 */
	public function mark_recovered( $email, $integration = 'woocommerce' ) {
		if ( empty( $email ) ) {
			return array();
		}

		$tagged_rows = Abandoned_Carts_Operations::mark_recovered_by_email( $email, $integration );

		$contact_uuids = array();
		foreach ( $tagged_rows as $row ) {
			if ( ! empty( $row->contact_uuid ) ) {
				$contact_uuids[] = $row->contact_uuid;
			}
		}

		return array_unique( $contact_uuids );
	}

	// =========================================================================
	// Detection (Action Scheduler batch processing)
	// =========================================================================

	/**
	 * Process one batch of abandoned carts and chain if more remain.
	 *
	 * Entry point for recurring cron (DETECTOR_HOOK). Resets chain depth to 0.
	 * Same pattern as Queue_Manager::process_batch().
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function process_batch() {
		$this->run_batch( 0 );
	}

	/**
	 * Process a chained batch (called by BATCH_HOOK).
	 *
	 * Depth is passed as the AS hook argument (not stored in a transient) so
	 * the cap survives queue lag — a transient TTL shorter than the queue's
	 * actual run delay would otherwise expire and reset the depth to 0.
	 *
	 * @since 1.5.0
	 *
	 * @param int $depth Current chain iteration count (passed by AS).
	 * @return void
	 */
	public function process_chained_batch( $depth = 0 ) {
		$this->run_batch( (int) $depth );
	}

	/**
	 * Run a single batch of abandoned carts and chain if more remain.
	 *
	 * @since 1.5.0
	 *
	 * @param int $chain_depth Current chain iteration count.
	 * @return void
	 */
	private function run_batch( $chain_depth ) {
		// Concurrency lock: prevent overlapping runs. Self-expires after LOCK_DURATION.
		$lock_key = 'surecontact_ac_detector_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, true, self::LOCK_DURATION );

		$all_settings = $this->get_all_abandoned_cart_settings();

		if ( empty( $all_settings ) ) {
			delete_transient( $lock_key );
			return;
		}

		try {
			$this->process_batch_carts( $all_settings );
		} finally {
			delete_transient( $lock_key );
		}

		// Chain another batch if more carts remain, respecting the depth cap.
		if ( $chain_depth >= self::MAX_CHAIN_DEPTH ) {
			return;
		}

		$remaining = $this->count_due_carts( $all_settings );
		if ( $remaining > 0 && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::BATCH_HOOK, array( $chain_depth + 1 ), 'surecontact' );
		}
	}

	/**
	 * Fetch and process due carts for the current batch.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, array> $all_settings Settings keyed by integration slug.
	 * @return void
	 */
	private function process_batch_carts( $all_settings ) {
		$carts = $this->fetch_due_carts( $all_settings, self::BATCH_SIZE );

		if ( empty( $carts ) ) {
			return;
		}

		$contact_service = new Contact_Service();

		foreach ( $carts as $cart ) {
			$cart_integration = $cart->integration ?? 'woocommerce';
			$cart_settings    = $all_settings[ $cart_integration ] ?? null;

			if ( ! $cart_settings ) {
				continue;
			}

			$list_uuids = $this->extract_uuids( $cart_settings['abandoned_cart_add_lists'] ?? array() );
			$tag_uuids  = $this->extract_uuids( $cart_settings['abandoned_cart_add_tags'] ?? array() );

			if ( empty( $list_uuids ) && empty( $tag_uuids ) ) {
				continue;
			}

			$this->process_single_cart( $cart, $contact_service, $list_uuids, $tag_uuids );
		}
	}

	/**
	 * Fetch due carts across enabled integrations using each integration's own threshold.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, array> $all_settings Settings keyed by integration slug.
	 * @param int                  $batch_size   Total cap on returned rows.
	 * @return array Cart row objects.
	 */
	private function fetch_due_carts( $all_settings, $batch_size ) {
		$carts     = array();
		$remaining = $batch_size;

		foreach ( $all_settings as $slug => $settings ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$threshold = self::normalize_threshold( $settings['abandoned_cart_threshold'] ?? 60 );
			$rows      = Abandoned_Carts_Operations::get_abandoned_past_threshold( $threshold, $remaining, $slug );

			if ( ! empty( $rows ) ) {
				$carts      = array_merge( $carts, $rows );
				$remaining -= count( $rows );
			}
		}

		return $carts;
	}

	/**
	 * Count due carts across enabled integrations using each integration's own threshold.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, array> $all_settings Settings keyed by integration slug.
	 * @return int Total remaining count.
	 */
	private function count_due_carts( $all_settings ) {
		$total = 0;

		foreach ( $all_settings as $slug => $settings ) {
			$threshold = self::normalize_threshold( $settings['abandoned_cart_threshold'] ?? 60 );
			$total    += Abandoned_Carts_Operations::get_abandoned_count( $threshold, $slug );
		}

		return $total;
	}

	/**
	 * Normalize threshold value to allowed range.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $value Raw threshold value.
	 * @return int Threshold in minutes (5-10080).
	 */
	private static function normalize_threshold( $value ) {
		return max( 5, min( 10080, absint( $value ) ) );
	}

	/**
	 * Process a single abandoned cart: create/find contact and apply tags.
	 *
	 * @since 1.5.0
	 *
	 * @param object          $cart            Cart row from database.
	 * @param Contact_Service $contact_service Contact service instance.
	 * @param array           $list_uuids      List UUIDs to apply.
	 * @param array           $tag_uuids       Tag UUIDs to apply.
	 * @return void
	 */
	private function process_single_cart( $cart, $contact_service, $list_uuids, $tag_uuids ) {
		$cart_id     = $cart->id; // @phpstan-ignore property.notFound
		$cart_email  = $cart->email; // @phpstan-ignore property.notFound
		$cart_userid = $cart->user_id; // @phpstan-ignore property.notFound
		$cart_source = $cart->integration; // @phpstan-ignore property.notFound

		$source_options = array( 'source' => $cart_source );

		// Look up existing contact first to avoid overwriting their data.
		$contact_uuid = $contact_service->find_contact_id_by_email( $cart_email );

		if ( $contact_uuid ) {
			// Contact exists — only attach lists/tags, don't update contact data.
			if ( ! empty( $list_uuids ) ) {
				$contact_service->attach_lists_to_contact( $contact_uuid, $list_uuids, $source_options );
			}
			if ( ! empty( $tag_uuids ) ) {
				$contact_service->attach_tags_to_contact( $contact_uuid, $tag_uuids, $source_options );
			}
		} else {
			// Contact doesn't exist — create with minimal data + lists/tags.
			$contact_data = array(
				'primary_fields' => array(
					'email' => $cart_email,
				),
			);

			if ( ! empty( $list_uuids ) ) {
				$contact_data['list_uuids'] = $list_uuids;
			}

			if ( ! empty( $tag_uuids ) ) {
				$contact_data['tag_uuids'] = $tag_uuids;
			}

			$user_id = absint( $cart_userid );
			$result  = $contact_service->create_contact( $contact_data, $user_id, $source_options );

			if ( is_wp_error( $result ) ) {
				// Bump updated_at so this cart isn't re-picked next scan.
				// The API_Retry trait already queues retryable failures for
				// background processing — this just prevents the detection
				// job from hammering the same cart every 5 minutes.
				Abandoned_Carts_Operations::touch( $cart_id );
				Logger::error(
					'Abandoned Cart',
					'Failed to create contact for abandoned cart',
					array(
						'cart_id' => $cart_id,
						'error'   => $result->get_error_message(),
					)
				);
				return;
			}

			$contact_uuid = $result['contact_uuid'] ?? null;
		}

		Abandoned_Carts_Operations::update(
			$cart_id,
			array(
				'status'       => Abandoned_Carts_Operations::STATUS_ABANDONED,
				'contact_uuid' => $contact_uuid,
				'abandoned_at' => current_time( 'mysql', true ),
			)
		);
	}

	// =========================================================================
	// Scheduling
	// =========================================================================

	/**
	 * Schedule detection if abandoned cart is enabled for any integration.
	 *
	 * Caches the enabled-check via transient to avoid hitting Integrations_DB
	 * on every request (action_scheduler_init fires on basically every page load).
	 * The cache is busted via `surecontact_integration_settings_saved`.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function maybe_schedule() {
		$cached = get_transient( self::ENABLED_CHECK_CACHE_KEY );

		if ( '1' === $cached ) {
			self::ensure_scheduled();
			return;
		}

		if ( '0' === $cached ) {
			self::unschedule();
			return;
		}

		// Cache miss — read from DB and remember the answer for an hour.
		$all_settings = $this->get_all_abandoned_cart_settings();
		$enabled      = ! empty( $all_settings );

		set_transient( self::ENABLED_CHECK_CACHE_KEY, $enabled ? '1' : '0', HOUR_IN_SECONDS );

		if ( $enabled ) {
			self::ensure_scheduled();
		} else {
			self::unschedule();
		}
	}

	/**
	 * Bust the enabled-check transient cache.
	 *
	 * Hooked to `surecontact_integration_settings_saved`.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public static function bust_enabled_check_cache() {
		delete_transient( self::ENABLED_CHECK_CACHE_KEY );
	}

	/**
	 * Ensure the recurring detection job and cleanup are scheduled.
	 *
	 * @since 1.5.0
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function ensure_scheduled() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}

		try {
			if ( ! as_next_scheduled_action( self::DETECTOR_HOOK ) ) {
				as_schedule_recurring_action( time(), self::DETECTION_INTERVAL, self::DETECTOR_HOOK, array(), 'surecontact' );
			}

			if ( ! as_next_scheduled_action( self::CLEANUP_HOOK ) ) {
				as_schedule_recurring_action( self::next_cleanup_timestamp(), DAY_IN_SECONDS, self::CLEANUP_HOOK, array(), 'surecontact' );
			}

			return true;
		} catch ( \Exception $e ) {
			Logger::error( 'Abandoned Cart', 'Exception while scheduling: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Compute the next 4:00am UTC anchor for the daily cleanup job.
	 *
	 * @since 1.5.0
	 *
	 * @return int Unix timestamp for tomorrow 04:00:00 UTC.
	 */
	private static function next_cleanup_timestamp() {
		$tomorrow = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
		return (int) strtotime( $tomorrow . ' 04:00:00 UTC' );
	}

	/**
	 * Unschedule all abandoned cart actions.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public static function unschedule() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::DETECTOR_HOOK, array(), 'surecontact' );
		as_unschedule_all_actions( self::BATCH_HOOK, array(), 'surecontact' );
		as_unschedule_all_actions( self::CLEANUP_HOOK, array(), 'surecontact' );
	}

	// =========================================================================
	// Cleanup
	// =========================================================================

	/**
	 * Clean up old recovered/abandoned carts.
	 *
	 * Uses the maximum retention across enabled integrations so that a cart
	 * persists at least as long as any integration requests.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function cleanup_old_carts() {
		$all_settings   = $this->get_all_abandoned_cart_settings();
		$retention_days = 0;

		foreach ( $all_settings as $settings ) {
			$days = max( 7, absint( $settings['abandoned_cart_retention_days'] ?? 30 ) );
			if ( $days > $retention_days ) {
				$retention_days = $days;
			}
		}

		if ( 0 === $retention_days ) {
			return;
		}

		Abandoned_Carts_Operations::delete_old_carts( $retention_days );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Get abandoned cart settings for all enabled integrations.
	 *
	 * Returns an associative array keyed by integration slug. Only includes
	 * integrations where abandoned cart is explicitly enabled.
	 *
	 * @since 1.5.0
	 *
	 * @return array<string, array> Integration slug => settings array. Empty if none enabled.
	 */
	private function get_all_abandoned_cart_settings() {
		$integrations_db = Integrations_DB::get_instance();
		$slugs           = array(
			Woocommerce_Abandoned_Cart::INTEGRATION_SLUG,
			EDD_Abandoned_Cart::INTEGRATION_SLUG,
		);
		$all_settings    = array();

		foreach ( $slugs as $slug ) {
			$result   = $integrations_db->get( $slug, null );
			$settings = ( $result && ! empty( $result['config'] ) ) ? $result['config'] : array();

			if ( ! empty( $settings['abandoned_cart_enabled'] ) ) {
				$all_settings[ $slug ] = $settings;
			}
		}

		return $all_settings;
	}
}
