<?php
/**
 * Initialize the Privacy Policy module.
 *
 * @package SureCookie\Inc\Modules\PrivacyPolicy
 * @since 1.5.0
 */

namespace SureCookie\Inc\Modules\PrivacyPolicy;

use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Traits\GetInstance;

defined( 'ABSPATH' ) || exit;

/**
 * Init
 *
 * @since 1.5.0
 */
class Init {
	use GetInstance;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 */
	private function __construct() {
		Shortcode::get_instance();

		add_filter( 'surecookie_api_controllers', [ $this, 'register_api_controller' ], 20 );

		// Sits on the admin write path so the nested read-merge-write and the
		// per-field email validation happen in one place, before the generic
		// array sanitizer runs.
		add_filter( 'surecookie_update_admin_settings_data', [ Validator::class, 'filter_settings_patch' ] );

		add_filter( 'rest_pre_insert_page', [ Publish_Gate::class, 'guard_rest' ], 10, 2 );
		add_filter( 'wp_insert_post_data', [ Publish_Gate::class, 'guard_save' ], 10, 2 );

		// Neither filter sees a scheduled publish: core rewrites the status to
		// 'future' before them, and the cron publish fires only this.
		add_action( 'transition_post_status', [ Publish_Gate::class, 'guard_transition' ], 10, 3 );

		if ( is_admin() ) {
			// wp_add_privacy_policy_content() refuses to run before admin_init,
			// and modules boot well before it. Priority 20 lands after core's own
			// suggested content (1) and before its change check (100).
			add_action( 'admin_init', [ $this, 'register_suggested_privacy_content' ], 20 );
			add_action( 'admin_notices', [ Publish_Gate::class, 'maybe_render_notice' ] );
		}
	}

	/**
	 * Register API controller for this module.
	 *
	 * @param array<string> $controllers Existing controllers.
	 * @return array<string> Updated controllers.
	 * @since 1.5.0
	 */
	public function register_api_controller( $controllers = [] ) {
		$controllers   = is_array( $controllers ) ? $controllers : [];
		$controllers[] = '\SureCookie\Inc\Modules\PrivacyPolicy\Api';
		return $controllers;
	}

	/**
	 * Contribute suggested text to WordPress's Privacy Policy Guide.
	 *
	 * The zero-liability route: core renders this as suggested text on an
	 * admin-only screen with a copy button, so it reaches site owners who will
	 * never open the generator.
	 *
	 * Static on purpose. Core's text_change_check() compares this against what
	 * was last reviewed and nags every administrator when it differs, so any
	 * interpolated setting or count would fire that notice site-wide on every
	 * change. Treat edits to this string like a migration.
	 *
	 * The jurisdiction facts are the one exception: they come from the Shortcode
	 * constants rather than being written out again here, so this text and the
	 * generated policy cannot end up naming different countries. They are
	 * compile-time values, so the rendered string is still stable between
	 * releases. Moving the service does change it, and firing the nag then is
	 * correct: the owner's reviewed policy really has become wrong.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function register_suggested_privacy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$records_ip = Helper::consent_log_ip_enabled();

		$content = '<p>' . __( 'This site uses SureCookie to ask visitors for their cookie choice and to record it.', 'surecookie' ) . '</p>'
			. '<p>' . ( $records_ip
				? __( 'When a visitor answers the cookie banner, their choice is stored in their browser and, unless consent logging is switched off, a record is also kept on this site. That record holds the date and time, which cookie categories were allowed or refused, a randomly generated identifier from a cookie in the visitor\'s browser, a shortened form of their IP address, the country the visit resolved to, and their user ID if they were signed in.', 'surecookie' )
				: __( 'When a visitor answers the cookie banner, their choice is stored in their browser and, unless consent logging is switched off, a record is also kept on this site. That record holds the date and time, which cookie categories were allowed or refused, a randomly generated identifier from a cookie in the visitor\'s browser, and their user ID if they were signed in. This site is configured not to record the visitor\'s IP address or country, so no address is stored and none is sent out to resolve a country.', 'surecookie' )
			) . '</p>'
			. ( $records_ip ? '<p>' . sprintf(
				/* translators: 1: hostname of the geolocation service, 2: country it is hosted in, 3: country the CDN operator is based in. */
				__( 'To resolve that country, the visitor\'s full IP address is sent to a geolocation service operated by Brainstorm Force at %1$s. Only the country is returned, and only the shortened address is stored on this site. That service is hosted in %2$s. Requests reach it through a content delivery network operated by a company based in the %3$s, so that provider belongs on your sub-processor list with its own transfer mechanism.', 'surecookie' ),
				Shortcode::GEO_LOOKUP_HOST,
				Shortcode::GEO_LOOKUP_REGION,
				Shortcode::GEO_LOOKUP_CDN_REGION
			) . '</p>' : '' )
			. '<p>' . __( 'How long consent records are kept is a setting on this site. Consent records are deliberately excluded from the personal data eraser, because they are the evidence that consent was given.', 'surecookie' ) . '</p>'
			. '<p>' . __( 'SureCookie can also generate a privacy policy draft for this site, under Compliance in the SureCookie menu.', 'surecookie' ) . '</p>';

		wp_add_privacy_policy_content( 'SureCookie', wp_kses_post( $content ) );
	}
}
