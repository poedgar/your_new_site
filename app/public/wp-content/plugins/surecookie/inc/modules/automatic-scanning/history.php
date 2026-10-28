<?php
/**
 * Automatic Scanning history recorder + query facade.
 *
 * Maintains a SINGLE scan-history record inside the existing
 * SURECOOKIE_SCANNED_DETAILS_OPTION (no custom table). Each successful scan
 * overwrites the record, which carries:
 *   - the latest reported snapshot (the baseline the next scan diffs against), and
 *   - the computed change set for display ("what changed in this scan").
 *
 * @package SureCookie\Inc\Modules\AutomaticScanning
 * @since 1.2.0
 */

namespace SureCookie\Inc\Modules\AutomaticScanning;

use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Services\CookieCategoryMemory;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * History
 *
 * @since 1.2.0
 */
class History {
	use GetInstance;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	private function __construct() {
		// Priority 10: runs after Sync (which writes the basic scan-history
		// fields and preserves our keys via array_merge).
		add_action( 'surecookie_scan_completed', [ $this, 'record' ], 10, 2 );
	}

	/**
	 * Record a scan: diff against the previous snapshot and overwrite the record.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $cookies_by_category This scan's reported cookies, grouped by category.
	 * @param array<string, mixed>                            $context             Scan context (cookies_count, scanned_at, domains, ...).
	 * @since 1.2.0
	 * @return void
	 */
	public function record( $cookies_by_category = [], $context = [] ): void {
		$cookies_by_category = is_array( $cookies_by_category ) ? $cookies_by_category : [];
		$context             = is_array( $context ) ? $context : [];

		$option = get_option( SURECOOKIE_SCANNED_DETAILS_OPTION, [] );
		if ( ! is_array( $option ) ) {
			$option = [];
		}

		// A MISSING snapshot is not an empty one: diffing against it made every
		// cookie and domain "new", so a first scan had Pro's guard force-block the
		// site. A first scan baselines instead.
		$is_baseline       = ! isset( $option['reported_snapshot'] ) || ! is_array( $option['reported_snapshot'] );
		$previous_snapshot = $is_baseline ? [] : $option['reported_snapshot'];

		$domains  = isset( $context['domains'] ) && is_array( $context['domains'] ) ? $context['domains'] : [];
		$snapshot = DiffEngine::build_snapshot( $cookies_by_category, $domains );
		$diff     = $is_baseline ? DiffEngine::empty_diff() : DiffEngine::diff( $previous_snapshot, $snapshot );

		// The snapshots hold the scanner's own classification, so a cookie whose
		// category the admin has pinned can still show up as recategorized even
		// though the store kept their choice. Reporting that would be untrue, so
		// drop those entries.
		$diff['recategorized'] = self::without_pinned_cookies( $diff['recategorized'] );

		// Annotate newly-detected cookies with a rule-based suggested category
		// and confidence (advisory in Free; consumed by Pro auto-apply).
		$diff['added'] = array_map(
			static function ( array $cookie ) {
				$classification               = Classifier::classify( $cookie );
				$cookie['suggested_category'] = $classification['category'];
				$cookie['confidence']         = $classification['confidence'];
				return $cookie;
			},
			$diff['added']
		);

		// The runner flags scheduled scans via a transient; its absence = manual.
		$is_auto = (bool) get_transient( Runner::ACTIVE_TRANSIENT );
		if ( $is_auto ) {
			delete_transient( Runner::ACTIVE_TRANSIENT );
		}

		// Single record: overwrite the change-detection keys, keeping the basic
		// fields Sync wrote (date, cookies_count, total_scans, success).
		$option = array_merge(
			$option,
			[
				'reported_snapshot' => $snapshot,
				'changes'           => $diff,
				'is_baseline'       => $is_baseline,
				'trigger_type'      => $is_auto ? 'auto' : 'manual',
				'changed_at'        => (string) ( $context['scanned_at'] ?? current_time( 'mysql' ) ),
			]
		);

		Update::option( SURECOOKIE_SCANNED_DETAILS_OPTION, $option );

		/**
		 * Fires after the single scan-history record is updated with its diff.
		 *
		 * Pro features (email digest, auto-apply, compliance guard) hook this to
		 * act on the change set without re-computing it.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, mixed> $diff    Change set (added/removed/recategorized/domains_added).
		 * @param array<string, mixed> $context Scan context.
		 * @param bool                 $is_auto Whether the scan was scheduled (vs manual).
		 */
		do_action( 'surecookie_auto_scan_recorded', $diff, $context, $is_auto );
	}

	/**
	 * Get the single latest scan-history record, formatted for the UI.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Empty array when no scan has run yet.
	 */
	public static function get_latest(): array {
		$option = get_option( SURECOOKIE_SCANNED_DETAILS_OPTION, [] );

		if ( ! is_array( $option ) || empty( $option ) ) {
			return [];
		}

		$changes = isset( $option['changes'] ) && is_array( $option['changes'] ) ? $option['changes'] : [];

		return [
			'date'                => (string) ( $option['date'] ?? '' ),
			'changed_at'          => (string) ( $option['changed_at'] ?? ( $option['date'] ?? '' ) ),
			'cookies_count'       => (int) ( $option['cookies_count'] ?? 0 ),
			'total_scans'         => (int) ( $option['total_scans'] ?? 0 ),
			'trigger_type'        => (string) ( $option['trigger_type'] ?? 'manual' ),
			'success'             => ! empty( $option['success'] ),
			'added_count'         => isset( $changes['added'] ) && is_array( $changes['added'] ) ? count( $changes['added'] ) : 0,
			'removed_count'       => isset( $changes['removed'] ) && is_array( $changes['removed'] ) ? count( $changes['removed'] ) : 0,
			'recategorized_count' => isset( $changes['recategorized'] ) && is_array( $changes['recategorized'] ) ? count( $changes['recategorized'] ) : 0,
			'new_domains'         => isset( $changes['domains_added'] ) && is_array( $changes['domains_added'] ) ? $changes['domains_added'] : [],
			// A baseline reports no changes, so the UI must not read 0 as "found nothing".
			'is_baseline'         => ! empty( $option['is_baseline'] ),
			'changes'             => $changes,
		];
	}

	/**
	 * Drop cookies whose category the admin has pinned.
	 *
	 * A pinned cookie is re-bucketed back to the admin's choice on every scan, so
	 * the scanner changing its mind about it is not a change to the site - and Pro
	 * auto-apply must not be handed a suggestion that would undo the choice.
	 *
	 * @param array<int, array<string, string>> $changes Recategorized entries from the diff.
	 * @since 1.3.0
	 * @return array<int, array<string, string>> The entries that are still real changes.
	 */
	private static function without_pinned_cookies( array $changes ): array {
		if ( empty( $changes ) ) {
			return $changes;
		}

		if ( empty( CookieCategoryMemory::all() ) ) {
			return $changes;
		}

		return array_values(
			array_filter(
				$changes,
				static fn( array $change ): bool => CookieCategoryMemory::remembered_category( $change ) === ''
			)
		);
	}
}
