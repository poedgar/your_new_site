<?php
/**
 * Post list state for SureCookie-managed policy pages.
 *
 * Marks the Cookie Policy and Privacy Policy pages in the Pages list so an
 * admin can tell at a glance which page the plugin is actually pointing at,
 * the way Elementor flags the posts it owns.
 *
 * @package    SureCookie
 * @subpackage SureCookie\Admin
 * @since      1.5.0
 */

namespace SureCookie\Admin;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

defined( 'ABSPATH' ) || exit;

/**
 * Post_States
 *
 * @since 1.5.0
 */
class Post_States {
	use GetInstance;

	/**
	 * Settings holding a page ID this plugin manages.
	 *
	 * @since 1.5.0
	 */
	private const PAGE_ID_SETTINGS = [
		'cookie_policy_page_id',
		'privacy_policy_page_id',
		'legal_notice_page_id',
	];

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 */
	private function __construct() {
		add_filter( 'display_post_states', [ $this, 'add_post_state' ], 10, 2 );
	}

	/**
	 * Flag a page this plugin manages.
	 *
	 * Keyed on the page-ID settings rather than a marker written at creation,
	 * so it is equally true of a page we generated and of one the user wrote
	 * and linked through the picker. "Managed" is an association, not a claim
	 * of authorship, which is what makes that honest either way.
	 *
	 * WordPress adds its own "Privacy Policy Page" state when the page is also
	 * core's designated policy page. Both showing is informative; neither is
	 * suppressed.
	 *
	 * @since 1.5.0
	 * @param array<string, string> $post_states Existing post states.
	 * @param \WP_Post|null         $post        Post being listed.
	 * @return array<string, string>
	 */
	public function add_post_state( $post_states = [], $post = null ) {
		if ( ! is_array( $post_states ) || ! $post instanceof \WP_Post ) {
			return is_array( $post_states ) ? $post_states : [];
		}

		foreach ( self::PAGE_ID_SETTINGS as $setting ) {
			$page_id = absint( Settings::get( $setting ) );

			// An unset setting is 0, which must not match anything.
			if ( $page_id > 0 && $page_id === $post->ID ) {
				$post_states['surecookie'] = esc_html__( 'Managed by SureCookie', 'surecookie' );
				break;
			}
		}

		return $post_states;
	}
}
