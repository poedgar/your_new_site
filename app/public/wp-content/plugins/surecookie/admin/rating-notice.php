<?php
/**
 * Rating Notice handler for SureCookie plugin.
 *
 * Registers a WordPress admin notice asking for a wp.org review once the site
 * has recorded enough visitor consents to have seen the plugin work.
 *
 * @package    SureCookie
 * @subpackage SureCookie\Admin
 * @since      1.0.0
 */

namespace SureCookie\Admin;

use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

defined( 'ABSPATH' ) || exit;

/**
 * Rating Notice.
 *
 * Displays a review-request notice via the BSF_Admin_Notices library once the
 * consent-log milestone is reached. Mirrors the SureForms pattern for
 * voice/tone consistency across the Brainstorm Force suite.
 *
 * @since 1.0.0
 */
class Rating_Notice {
	use GetInstance;

	/**
	 * Unique notice ID used by BSF_Admin_Notices for dismissal tracking.
	 *
	 * @since 1.0.0
	 */
	private const NOTICE_ID = 'surecookie-rating-notice';

	/**
	 * WordPress.org review URL (opens the "leave a 5-star review" form).
	 *
	 * @since 1.0.0
	 */
	private const REVIEW_URL = 'https://wordpress.org/support/plugin/surecookie/reviews/?filter=5#new-post';

	/**
	 * Site flag set once the ask has been answered for good.
	 *
	 * @since 1.4.0
	 */
	private const RESOLVED_OPTION = 'surecookie_rating_notice_resolved';

	/**
	 * Consents the site must have logged before the ask appears.
	 *
	 * @since 1.4.0
	 */
	private const MIN_CONSENT_LOGS = 30;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_notices', [ $this, 'display_notice' ] );
		add_action( 'wp_ajax_surecookie_rating_notice_track', [ $this, 'handle_notice_response' ] );
	}

	/**
	 * Register the rating notice with BSF_Admin_Notices when the milestone is met.
	 *
	 * Short-circuits (in order) when:
	 *  - the current user lacks {@see Helper::capability()},
	 *  - the `surecookie_show_rating_notice` filter returns false,
	 *  - the ask was already answered on this site,
	 *  - the site hasn't logged {@see MIN_CONSENT_LOGS} consents yet.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function display_notice(): void {
		if ( ! current_user_can( Helper::capability() ) ) {
			return;
		}

		/**
		 * Allow third parties (agencies, managed hosts) to suppress the
		 * review-request notice entirely.
		 *
		 * @since 1.0.0
		 * @param bool $show Whether to register the notice. Default true.
		 */
		if ( ! apply_filters( 'surecookie_show_rating_notice', true ) ) {
			return;
		}

		// The library tracks dismissal per user, so a site-level flag stops a
		// review the site already gave from re-prompting every other admin.
		if ( ! empty( get_option( self::RESOLVED_OPTION, false ) ) ) {
			return;
		}

		if ( $this->consent_log_count() < $this->min_consent_logs() ) {
			return;
		}

		if ( ! class_exists( 'BSF_Admin_Notices' ) ) {
			require_once SURECOOKIE_DIR . 'inc/lib/astra-notices/class-bsf-admin-notices.php';
		}

		\BSF_Admin_Notices::add_notice(
			[
				'id'                         => self::NOTICE_ID,
				'type'                       => '',
				'message'                    => $this->build_message(),
				'repeat-notice-after'        => WEEK_IN_SECONDS,
				// Yield to any other BSF notice already on screen: a review ask
				// stacked under a compliance warning reads as noise.
				'display-with-other-notices' => false,
				'capability'                 => Helper::capability(),
				// Suppress WordPress core's native "×": it only snoozes (reading the wrapper's repeat interval) instead of dismissing permanently, and it
				// escapes click tracking. The three CTA links are the intended exits.
				'is_dismissible'             => false,
			]
		);

		// Adjust the styling of notice image element.
		add_action( 'astra_notice_before_markup_' . self::NOTICE_ID, [ $this, 'print_notice_styles' ] );

		// Load the click-tracking script only when the notice actually renders.
		add_action( 'astra_notice_after_markup_' . self::NOTICE_ID, [ $this, 'enqueue_tracking_script' ] );
	}

	/**
	 * Enqueue the click-tracking script and localize the AJAX endpoint + nonce.
	 *
	 * Hooked to `astra_notice_after_markup_{id}` so it loads only when the
	 * notice is actually rendered. Mirrors the SureForms review flow.
	 *
	 * @since 1.2.3
	 * @return void
	 */
	public function enqueue_tracking_script(): void {
		if ( wp_script_is( 'surecookie-rating-notice-track', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'surecookie-rating-notice-track',
			SURECOOKIE_URL . 'assets/js/rating-notice-track.js',
			[],
			SURECOOKIE_VERSION,
			true
		);

		wp_localize_script(
			'surecookie-rating-notice-track',
			'surecookieRatingNotice',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'surecookie_rating_notice' ),
			]
		);
	}

	/**
	 * Record which notice button the user clicked.
	 *
	 * Validates nonce + capability, maps the posted `button` to a canonical
	 * event name, and records it via the shared BSF analytics tracker. Unknown
	 * buttons are rejected so only the three expected events are ever stored.
	 *
	 * @since 1.2.3
	 * @return void
	 */
	public function handle_notice_response(): void {
		if ( ! check_ajax_referer( 'surecookie_rating_notice', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'surecookie' ) ], 403 );
		} elseif ( ! current_user_can( Helper::capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized user.', 'surecookie' ) ], 403 );
		} else {
			// Nonce is verified above via check_ajax_referer.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$button = isset( $_POST['button'] ) ? sanitize_text_field( wp_unslash( $_POST['button'] ) ) : '';

			$event_map = [
				'rate_surecookie' => 'rating_notice_cta',
				'maybe_later'     => 'rating_notice_snooze',
				'dismissed'       => 'rating_notice_dismiss',
			];

			if ( ! isset( $event_map[ $button ] ) ) {
				wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'surecookie' ) ], 400 );
			} else {
				$events = Analytics::events();
				if ( $events instanceof \BSF_Analytics_Events ) {
					$events->track( $event_map[ $button ], $button );
				}

				// Rating and "I already did" end the ask for the whole site;
				// "Maybe later" leaves the library's one-week snooze to it.
				if ( $button !== 'maybe_later' ) {
					update_option( self::RESOLVED_OPTION, time(), false );
				}

				wp_send_json_success();
			}
		}
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
	 * Consents logged on this site to date.
	 *
	 * Reads the running counter rather than the log table so the gate stays a
	 * single option read on every admin page load.
	 *
	 * @since 1.4.0
	 * @return int
	 */
	private function consent_log_count(): int {
		return (int) Settings::get( 'total_logs' );
	}

	/**
	 * Consent-log milestone that unlocks the ask.
	 *
	 * @since 1.4.0
	 * @return int
	 */
	private function min_consent_logs(): int {
		/**
		 * Filter the consent-log milestone that unlocks the review request.
		 *
		 * @since 1.4.0
		 * @param int $threshold Consents required. Default 30.
		 */
		$threshold = (int) apply_filters( 'surecookie_rating_notice_min_consent_logs', self::MIN_CONSENT_LOGS );

		// A zero or negative threshold would show the ask to every fresh install.
		return max( 1, $threshold );
	}

	/**
	 * Build the HTML markup for the notice body.
	 *
	 * Mirrors SureForms' `build_notice_markup` structure so styling inherits
	 * from the shared BSF_Admin_Notices stylesheet already enqueued by the library.
	 *
	 * The "Rate SureCookie" button carries both `button-primary` and
	 * `astra-notice-close` classes plus `target="_blank"`. The library's
	 * `_dismissNoticeNew` handler detects this combination and fires the
	 * dismiss AJAX + `window.open( …, '_blank' )` in a single action.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private function build_message(): string {
		$logo_url = SURECOOKIE_URL . 'assets/images/surecookie--brand-colored.svg';
		$snooze   = (string) WEEK_IN_SECONDS;
		$logged   = $this->consent_log_count();
		$heading  = sprintf(
			/* translators: %s: number of visitor consents recorded so far, e.g. "1,204". */
			_n(
				'Amazing! SureCookie has recorded %s visitor consent on your site - let\'s keep growing together!',
				'Amazing! SureCookie has recorded %s visitor consents on your site - let\'s keep growing together!',
				$logged,
				'surecookie'
			),
			number_format_i18n( $logged )
		);

		ob_start();
		?>
		<div class="notice-image">
			<img
				src="<?php echo esc_url( $logo_url ); ?>"
				class="custom-logo"
				alt="SureCookie"
			/>
		</div>
		<div class="notice-content">
			<div class="notice-heading">
				<?php echo esc_html( $heading ); ?>
			</div>
			<?php esc_html_e( 'If SureCookie has been helpful, would you mind taking a moment to leave a 5-star review on WordPress.org?', 'surecookie' ); ?>
			<br />
			<div class="astra-review-notice-container">
				<a
					href="<?php echo esc_url( self::REVIEW_URL ); ?>"
					class="button-primary astra-notice-close"
					target="_blank"
					rel="noopener noreferrer"
				>
					<?php esc_html_e( 'Rate SureCookie', 'surecookie' ); ?>
				</a>
				<span class="dashicons dashicons-clock" aria-hidden="true"></span>
				<a
					href="#"
					data-repeat-notice-after="<?php echo esc_attr( $snooze ); ?>"
					class="astra-notice-close"
				>
					<?php esc_html_e( 'Maybe later', 'surecookie' ); ?>
				</a>
				<span class="dashicons dashicons-smiley" aria-hidden="true"></span>
				<a href="#" class="astra-notice-close">
					<?php esc_html_e( 'I already did', 'surecookie' ); ?>
				</a>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
