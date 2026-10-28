<?php
/**
 * Review Notice handler.
 *
 * @package SureRank\Inc\Admin
 * @since 1.7.5
 */

namespace SureRank\Inc\Admin;

use SureRank\Inc\Analytics\Analytics;
use SureRank\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Review_Notice class.
 *
 * Mirrors the SureForms 5-star review notice flow for SureRank.
 */
class Review_Notice {
	use Get_Instance;

	/**
	 * Notice ID used by BSF admin notices.
	 */
	private const NOTICE_ID = 'surerank-rating-notice';

	/**
	 * Review URL.
	 */
	private const REVIEW_URL = 'https://wordpress.org/support/plugin/surerank/reviews/?filter=5#new-post';

	/**
	 * Optimized items threshold required before showing the notice.
	 */
	private const THRESHOLD = 3;

	/**
	 * Per-request memo for the eligibility result.
	 *
	 * @var bool|null
	 */
	private static $eligibility_cache = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_notices', [ $this, 'display_notice' ] );
		add_action( 'wp_ajax_surerank_notice_response', [ $this, 'handle_notice_response' ] );
	}

	/**
	 * Eligibility check shared by the notice and NPS suppression.
	 *
	 * Memoized per request because it's called from both `admin_notices`
	 * (notice render) and `admin_footer` (NPS coordination).
	 *
	 * @return bool
	 */
	public static function is_notice_eligible_for_current_user(): bool {
		if ( null !== self::$eligibility_cache ) {
			return self::$eligibility_cache;
		}

		self::$eligibility_cache = self::compute_eligibility();

		return self::$eligibility_cache;
	}

	/**
	 * Get the click-to-event mapping used by the AJAX handler.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function get_notice_response_events(): array {
		return [
			self::NOTICE_ID => [
				'rate_surerank' => 'rating_notice_cta',
				'maybe_later'   => 'rating_notice_snooze',
				'dismissed'     => 'rating_notice_dismiss',
			],
		];
	}

	/**
	 * Register the review notice.
	 *
	 * @return void
	 */
	public function display_notice(): void {
		if ( ! self::is_notice_eligible_for_current_user() ) {
			return;
		}

		if ( ! class_exists( 'BSF_Admin_Notices' ) ) {
			require_once SURERANK_DIR . 'inc/lib/astra-notices/class-bsf-admin-notices.php';
		}

		if ( ! class_exists( 'BSF_Admin_Notices' ) ) {
			return;
		}

		\BSF_Admin_Notices::add_notice(
			[
				'id'                         => self::NOTICE_ID,
				'type'                       => '',
				'message'                    => $this->build_notice_markup(),
				'repeat-notice-after'        => WEEK_IN_SECONDS,
				'display-with-other-notices' => true,
				'capability'                 => self::get_required_capability(),
			]
		);

		add_action( 'astra_notice_before_markup_' . self::NOTICE_ID, [ $this, 'print_notice_styles' ] );
		add_action( 'astra_notice_after_markup_' . self::NOTICE_ID, [ $this, 'enqueue_notice_response_script' ] );
	}

	/**
	 * Print scoped notice styles. Fires only when this notice renders.
	 *
	 * @return void
	 */
	public function print_notice_styles(): void {
		?>
		<style>
			#<?php echo esc_attr( self::NOTICE_ID ); ?> .notice-image {
				align-self: normal;
				margin-top: 4px;
			}

			#<?php echo esc_attr( self::NOTICE_ID ); ?> .notice-image img.custom-logo {
				max-width: 32px;
			}
		</style>
		<?php
	}

	/**
	 * Enqueue the click-tracking bridge only when the notice is rendered.
	 *
	 * @return void
	 */
	public function enqueue_notice_response_script(): void {
		if ( wp_script_is( 'surerank-notice-response', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'surerank-notice-response',
			SURERANK_URL . 'inc/admin/assets/js/notice-response.js',
			[],
			SURERANK_VERSION,
			true
		);

		wp_localize_script(
			'surerank-notice-response',
			'surerankNoticeResponse',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'surerank_notice_response' ),
			]
		);
	}

	/**
	 * Handle the notice response AJAX request.
	 *
	 * @return void
	 */
	public function handle_notice_response(): void {
		if ( ! check_ajax_referer( 'surerank_notice_response', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'surerank' ) ], 403 );
		}

		if ( ! current_user_can( self::get_required_capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized user.', 'surerank' ) ], 403 );
		}

		$notice_id = isset( $_POST['notice_id'] ) ? sanitize_text_field( wp_unslash( $_POST['notice_id'] ) ) : '';
		$button    = isset( $_POST['button'] ) ? sanitize_text_field( wp_unslash( $_POST['button'] ) ) : '';
		$valid     = self::get_notice_response_events();

		if ( ! isset( $valid[ $notice_id ][ $button ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'surerank' ) ], 400 );
		}

		$events = Analytics::events();
		if ( null !== $events ) {
			$events->track( $valid[ $notice_id ][ $button ], $button );
		}

		wp_send_json_success();
	}

	/**
	 * Reset memoized eligibility (test helper).
	 *
	 * @return void
	 */
	public static function reset_eligibility_cache(): void {
		self::$eligibility_cache = null;
	}

	/**
	 * Run the actual eligibility checks (cheapest to most expensive).
	 *
	 * @return bool
	 */
	private static function compute_eligibility(): bool {
		if ( ! current_user_can( self::get_required_capability() ) ) {
			return false;
		}

		if ( ! apply_filters( 'surerank_show_rating_notice', true ) ) {
			return false;
		}

		if ( false !== get_transient( self::NOTICE_ID ) ) {
			return false;
		}

		$meta_status = get_user_meta( get_current_user_id(), self::NOTICE_ID, true );
		if ( ! empty( $meta_status ) && 'delayed-notice' !== $meta_status ) {
			return false;
		}

		return self::get_optimized_items_count() >= self::THRESHOLD;
	}

	/**
	 * Build the notice markup.
	 *
	 * @return string
	 */
	private function build_notice_markup(): string {
		$logo_url = SURERANK_URL . 'inc/admin/assets/images/surerank.png';

		ob_start();
		?>
		<div class="notice-image">
			<img
				src="<?php echo esc_url( $logo_url ); ?>"
				class="custom-logo"
				alt="SureRank"
			/>
		</div>
		<div class="notice-content">
			<div class="notice-heading">
				<?php esc_html_e( 'Amazing! SureRank is helping optimize your site - let\'s keep growing together!', 'surerank' ); ?>
			</div>
			<?php esc_html_e( 'If SureRank has been helpful, would you mind taking a moment to leave a 5-star review on WordPress.org?', 'surerank' ); ?>
			<br />
			<div class="astra-review-notice-container">
				<a
					href="<?php echo esc_url( self::REVIEW_URL ); ?>"
					class="button-primary astra-notice-close"
					target="_blank"
					rel="noopener noreferrer"
				>
					<?php esc_html_e( 'Rate SureRank', 'surerank' ); ?>
				</a>
				<span class="dashicons dashicons-clock" aria-hidden="true"></span>
				<a
					href="#"
					data-repeat-notice-after="<?php echo esc_attr( (string) WEEK_IN_SECONDS ); ?>"
					class="astra-notice-close"
				>
					<?php esc_html_e( 'Maybe later', 'surerank' ); ?>
				</a>
				<span class="dashicons dashicons-smiley" aria-hidden="true"></span>
				<a href="#" class="astra-notice-close">
					<?php esc_html_e( 'I already did', 'surerank' ); ?>
				</a>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Count optimized posts and terms, bounded by the threshold.
	 *
	 * Uses LIMIT to short-circuit instead of full COUNT(DISTINCT) scans, and
	 * skips the term query once posts alone satisfy the threshold.
	 *
	 * @return int
	 */
	private static function get_optimized_items_count(): int {
		global $wpdb;

		$threshold  = self::THRESHOLD;
		$post_types = array_values( array_diff( get_post_types( [ 'public' => true ], 'names' ), [ 'attachment' ] ) );

		$post_count = 0;
		if ( ! empty( $post_types ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
			$params       = array_merge( [ 'surerank_post_optimized_at' ], $post_types, [ $threshold ] );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT pm.post_id FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					WHERE pm.meta_key = %s
					AND p.post_status = 'publish'
					AND p.post_type IN ({$placeholders})
					LIMIT %d",
					$params
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$post_count = count( $rows );
		}

		if ( $post_count >= $threshold ) {
			return $post_count;
		}

		$term_rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s LIMIT %d",
				'surerank_term_optimized_at',
				$threshold - $post_count
			)
		);

		return $post_count + count( $term_rows );
	}

	/**
	 * Get the capability required for the notice.
	 *
	 * @return string
	 */
	private static function get_required_capability(): string {
		$capability = apply_filters( 'surerank_admin_components_capability', 'manage_options' );

		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}
}
