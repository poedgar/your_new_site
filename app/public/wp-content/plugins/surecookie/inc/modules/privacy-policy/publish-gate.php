<?php
/**
 * Stops an incomplete privacy policy from going live.
 *
 * The block editor publishes through core's own /wp/v2/pages route, so a guard
 * on a SureCookie route would gate nothing. `rest_pre_insert_page` gives the
 * editor a real error message, and `wp_insert_post_data` catches the paths that
 * never touch REST at all: Classic Editor, Quick Edit and WP-CLI. Neither sees a
 * scheduled publish, which is what `transition_post_status` is for.
 *
 * @package SureCookie\Inc\Modules\PrivacyPolicy
 * @since 1.5.0
 */

namespace SureCookie\Inc\Modules\PrivacyPolicy;

use SureCookie\Inc\Functions\Settings;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Publish_Gate
 *
 * @since 1.5.0
 */
final class Publish_Gate {
	/**
	 * Shortcode whose presence marks the page as still ours to guard.
	 *
	 * @since 1.5.0
	 */
	private const OWNED_MARKER = 'surecookie_privacy_identity';

	/**
	 * Transient prefix for the reason shown on the editor screen.
	 *
	 * @since 1.5.0
	 */
	private const NOTICE_TRANSIENT = 'surecookie_privacy_gate_';

	/**
	 * Reject a publish through the REST API with a real message.
	 *
	 * @since 1.5.0
	 * @param mixed           $prepared Prepared post object.
	 * @param WP_REST_Request $request  Incoming request.
	 * @return mixed The prepared post, or WP_Error when the policy is incomplete.
	 */
	public static function guard_rest( $prepared, $request = null ) {
		if ( ! is_object( $prepared ) ) {
			return $prepared;
		}

		$post_id = isset( $prepared->ID ) ? absint( $prepared->ID ) : 0;
		$status  = isset( $prepared->post_status ) ? (string) $prepared->post_status : '';

		// A status-only PATCH carries no content, so fall back to what is stored
		// or a bare "publish" request would sail straight through.
		if ( isset( $prepared->post_content ) ) {
			$content = (string) $prepared->post_content;
		} else {
			$stored  = get_post_field( 'post_content', $post_id );
			$content = is_string( $stored ) ? $stored : '';
		}

		if ( ! self::gate_applies( $post_id, $status, $content ) ) {
			return $prepared;
		}

		unset( $request );

		return new WP_Error(
			'surecookie_privacy_policy_incomplete',
			self::message(),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Hold the post back as a draft on the non-REST save paths.
	 *
	 * Cannot return an error here, so the status is rewritten and the reason is
	 * stashed for an admin notice: a silent downgrade would just look like a bug.
	 *
	 * @since 1.5.0
	 * @param mixed $data    Sanitized post data.
	 * @param mixed $postarr Raw post array.
	 * @return mixed
	 */
	public static function guard_save( $data, $postarr = [] ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$post_id = is_array( $postarr ) ? absint( $postarr['ID'] ?? 0 ) : 0;
		$status  = (string) ( $data['post_status'] ?? '' );
		$content = wp_unslash( (string) ( $data['post_content'] ?? '' ) );

		if ( ! self::gate_applies( $post_id, $status, $content ) ) {
			return $data;
		}

		$data['post_status'] = 'draft';

		set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), self::message(), 60 );

		return $data;
	}

	/**
	 * Hold the page back when it becomes published outside the save filters.
	 *
	 * Scheduling never reaches them: core coerces a future-dated publish to
	 * 'future' before wp_insert_post_data runs, and the cron publish then flips
	 * the row with a bare query that fires neither filter. This runs on every
	 * transition on the site, so it exits on the first mismatch.
	 *
	 * @since 1.5.0
	 * @param mixed $new_status Status being moved to.
	 * @param mixed $old_status Status being moved from.
	 * @param mixed $post       Post object.
	 * @return void
	 */
	public static function guard_transition( $new_status, $old_status = '', $post = null ): void {
		if ( $new_status !== 'publish' || ! is_object( $post ) || ( $post->post_type ?? '' ) !== 'page' ) {
			return;
		}

		$post_id = absint( $post->ID ?? 0 );
		$content = (string) ( $post->post_content ?? '' );

		if ( ! self::gate_applies( $post_id, 'publish', $content, (string) $old_status ) ) {
			return;
		}

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'draft',
			]
		);

		// Cron publishes with no current user and nobody watching, so the notice
		// goes to the author and has to outlive the request.
		$user_id = get_current_user_id();

		set_transient(
			self::NOTICE_TRANSIENT . ( $user_id > 0 ? $user_id : absint( $post->post_author ?? 0 ) ),
			self::message(),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Explain a blocked publish on the editor screen.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function maybe_render_notice(): void {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$reason = get_transient( $key );

		if ( ! is_string( $reason ) || $reason === '' ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $reason )
		);
	}

	/**
	 * Whether this save should be held back.
	 *
	 * Conditions 3, 4 and 6 are release valves, not bookkeeping. Without them a
	 * user could be locked out of a page they own: the gate never touches a page
	 * that is already live, it stops applying the moment our block is removed,
	 * and a site with an unusual setup can switch it off outright.
	 *
	 * @since 1.5.0
	 * @param int         $post_id  Post being saved.
	 * @param string      $status   Incoming post status.
	 * @param string      $content  Incoming post content.
	 * @param string|null $previous Status before this change, when the caller knows it.
	 * @return bool
	 */
	private static function gate_applies( int $post_id, string $status, string $content, ?string $previous = null ): bool {
		$managed_id = absint( Settings::get( 'privacy_policy_page_id' ) );

		if ( $post_id <= 0 || $managed_id <= 0 || $post_id !== $managed_id ) {
			return false;
		}

		if ( $status !== 'publish' ) {
			return false;
		}

		// Never un-publish, and never block re-saving a live page. On a
		// transition the row is already written, so the caller passes what it was.
		if ( ( $previous ?? get_post_status( $post_id ) ) === 'publish' ) {
			return false;
		}

		// Once the author has rewritten the page without our block, it is theirs.
		if ( ! has_shortcode( $content, self::OWNED_MARKER ) ) {
			return false;
		}

		if ( Validator::is_complete() ) {
			return false;
		}

		/**
		 * Filters whether the privacy policy publish gate applies to a page.
		 *
		 * Returning false lets an incomplete policy be published, for a site
		 * whose situation the required-field rules do not fit.
		 *
		 * @since 1.5.0
		 *
		 * @param bool $applies Whether to hold the publish back.
		 * @param int  $post_id Post being saved.
		 */
		return (bool) apply_filters( 'surecookie_privacy_policy_publish_gate', true, $post_id );
	}

	/**
	 * Reason text naming what is still missing.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private static function message(): string {
		$labels = wp_list_pluck( Validator::missing_required_fields(), 'label' );

		return sprintf(
			/* translators: %s: comma-separated list of missing business detail fields. */
			__( 'This privacy policy cannot be published yet. Add the following under Compliance, Business Details: %s.', 'surecookie' ),
			implode( ', ', array_map( 'strval', $labels ) )
		);
	}
}
