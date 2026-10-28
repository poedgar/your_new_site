<?php
/**
 * Cookie Policy API class.
 *
 * Handles REST API endpoint for auto-generating cookie policy pages.
 *
 * @package SureCookie\Inc\Modules\CookiePolicy
 * @since 0.0.0-alpha.1
 */

namespace SureCookie\Inc\Modules\CookiePolicy;

use SureCookie\Inc\API\Base;
use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\PrivacyPolicy\Validator;
use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Api
 *
 * @package SureCookie\Inc\Modules\CookiePolicy
 * @since 0.0.0-alpha.1
 */
class Api extends Base {
	use GetInstance;

	/**
	 * Route for generating a cookie policy page.
	 */
	protected const GENERATE_PAGE = '/cookie-policy/generate-page';

	/**
	 * Register API routes.
	 *
	 * @since 0.0.0-alpha.1
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			self::GENERATE_PAGE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'generate_page' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);
	}

	/**
	 * Generate a cookie policy page.
	 *
	 * Creates a published WordPress page containing the cookie policy shortcode.
	 * If a valid published page already exists, returns its details instead.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return void
	 * @since 0.0.0-alpha.1
	 */
	public function generate_page( WP_REST_Request $request ): void {
		// Check if a valid cookie policy page already exists.
		$existing_id = absint( Settings::get( 'cookie_policy_page_id' ) );

		if ( $existing_id > 0 ) {
			$page = get_post( $existing_id );

			if ( $page instanceof \WP_Post && $page->post_status === 'publish' ) {
				SendJson::success(
					[
						'message'        => __( 'Cookie Policy page already exists.', 'surecookie' ),
						'page_id'        => $page->ID,
						'edit_url'       => get_edit_post_link( $page->ID, 'raw' ),
						'view_url'       => get_permalink( $page->ID ),
						'already_exists' => true,
					]
				);
				return; // wp_send_json exits, but return for clarity.
			}
		}

		// Create a new cookie policy page.
		$page_id = wp_insert_post(
			[
				'post_title'   => __( 'Cookie Policy', 'surecookie' ),
				'post_content' => self::get_default_page_content(),
				'post_status'  => 'publish',
				'post_type'    => 'page',
			],
			true
		);

		if ( is_wp_error( $page_id ) ) {
			SendJson::error(
				[
					'message' => $page_id->get_error_message(),
				]
			);
			return;
		}

		// Save the new page ID to plugin settings.
		Settings::update( 'cookie_policy_page_id', $page_id );

		SendJson::success(
			[
				'message'        => __( 'Cookie Policy page created successfully.', 'surecookie' ),
				'page_id'        => $page_id,
				'edit_url'       => get_edit_post_link( $page_id, 'raw' ),
				'view_url'       => get_permalink( $page_id ),
				'already_exists' => false,
			]
		);
	}

	/**
	 * Build the default page content with comprehensive cookie policy sections.
	 *
	 * The content is inserted as native WordPress blocks so that end-users
	 * can freely edit every section in the block editor. The shortcode block
	 * renders the dynamic cookie tables, table of contents, and last-updated
	 * date.
	 *
	 * @since 0.0.0-alpha.1
	 * @return string Block-editor-ready post content.
	 */
	private static function get_default_page_content(): string {
		$site_name = get_bloginfo( 'name' );
		$sections  = self::get_page_sections( $site_name );
		$content   = '';

		foreach ( $sections as $section ) {
			if ( $section['type'] === 'shortcode' ) {
				$content .= '<!-- wp:shortcode -->' . "\n";
				$content .= '[surecookie_cookie_policy_content]' . "\n";
				$content .= '<!-- /wp:shortcode -->' . "\n\n";
				continue;
			}

			if ( $section['type'] === 'heading' ) {
				$level    = $section['level'] ?? 2;
				$tag      = 'h' . $level;
				$content .= '<!-- wp:heading {"level":' . $level . '} -->' . "\n";
				$content .= '<' . $tag . '>' . esc_html( $section['text'] ?? '' ) . '</' . $tag . '>' . "\n";
				$content .= '<!-- /wp:heading -->' . "\n\n";
				continue;
			}

			if ( $section['type'] === 'paragraph' ) {
				$content .= '<!-- wp:paragraph -->' . "\n";
				$content .= '<p>' . esc_html( $section['text'] ?? '' ) . '</p>' . "\n";
				$content .= '<!-- /wp:paragraph -->' . "\n\n";
			}
		}

		return $content;
	}

	/**
	 * Get the structured sections for the cookie policy page.
	 *
	 * Each section is either a heading, paragraph, or shortcode placeholder.
	 * All user-facing strings are translatable.
	 *
	 * @param string $site_name The site name for dynamic text.
	 * @since 0.0.0-alpha.1
	 * @return array<int, array{type: string, text?: string, level?: int}> Ordered sections.
	 */
	private static function get_page_sections( string $site_name ): array {
		$sections = [];

		// --- Introduction ---
		$sections[] = [
			'type' => 'paragraph',
			'text' => sprintf(
				/* translators: %s: site name. */
				__( 'This Cookie Policy explains how %s uses cookies and similar tracking technologies when you visit our website. It describes what these technologies are, why we use them, and your rights to control our use of them.', 'surecookie' ),
				$site_name
			),
		];

		// --- What Are Cookies ---
		$sections[] = [
			'type'  => 'heading',
			'level' => 2,
			'text'  => __( 'What Are Cookies', 'surecookie' ),
		];
		$sections[] = [
			'type' => 'paragraph',
			'text' => __( 'Cookies are small text files that are stored on your computer or mobile device when you visit a website. They are widely used to make websites work more efficiently, provide a better browsing experience, and supply information to the owners of the site. Cookies can be "persistent" or "session" cookies. Persistent cookies remain on your device for a set period or until you delete them, while session cookies are deleted as soon as you close your web browser.', 'surecookie' ),
		];
		$sections[] = [
			'type' => 'paragraph',
			'text' => __( 'In addition to cookies, we may also use similar technologies such as pixel tags, web beacons, and local storage to collect and store information. These technologies work in a similar way to cookies and allow us to monitor and improve our website and your experience of it.', 'surecookie' ),
		];

		// --- Types of Cookies We Use ---
		$sections[] = [
			'type'  => 'heading',
			'level' => 2,
			'text'  => __( 'Types of Cookies We Use', 'surecookie' ),
		];
		$sections[] = [
			'type' => 'paragraph',
			'text' => __( 'We use different types of cookies for various reasons. The table below provides detailed information about the cookies we use, organized by category, including the name of each cookie, its purpose, duration, and the domain it belongs to. Some cookies are essential for the website to function properly, while others help us understand how visitors interact with our site, remember your preferences, or deliver relevant advertising.', 'surecookie' ),
		];

		// --- Dynamic shortcode ---
		$sections[] = [ 'type' => 'shortcode' ];

		// --- How to Manage Preferences ---
		$sections[] = [
			'type'  => 'heading',
			'level' => 2,
			'text'  => __( 'How to Manage Your Cookie Preferences', 'surecookie' ),
		];
		$sections[] = [
			'type' => 'paragraph',
			'text' => __( 'When you first visit our website, you will be shown a cookie consent banner that allows you to accept or decline non-essential cookies. You can change your preferences at any time by clicking the cookie preferences option available on our website. Essential cookies cannot be disabled as they are necessary for the basic functioning of the website.', 'surecookie' ),
		];
		$sections[] = [
			'type' => 'paragraph',
			'text' => __( 'Please note that restricting cookies may impact your experience of the website, as some features and services may not function properly without certain cookies enabled.', 'surecookie' ),
		];

		// --- Browser Control ---
		$sections[] = [
			'type'  => 'heading',
			'level' => 2,
			'text'  => __( 'How to Control Cookies in Your Browser', 'surecookie' ),
		];
		$sections[] = [
			'type' => 'paragraph',
			'text' => __( 'Most web browsers allow you to manage your cookie preferences through their settings. You can set your browser to refuse cookies, delete existing cookies, or alert you when a cookie is being set. The steps to manage cookies vary by browser. Below are general instructions for the most common browsers.', 'surecookie' ),
		];
		$sections[] = [
			'type' => 'paragraph',
			'text' => __( 'Google Chrome: Open Settings, go to Privacy and Security, then click on Cookies and other site data. From there, you can block third-party cookies, clear cookies when you close the browser, or block all cookies.', 'surecookie' ),
		];
		$sections[] = [
			'type' => 'paragraph',
			'text' => __( 'Mozilla Firefox: Open Settings, go to Privacy and Security, and under Cookies and Site Data you can manage how Firefox handles cookies including clearing data and managing exceptions.', 'surecookie' ),
		];
		$sections[] = [
			'type' => 'paragraph',
			'text' => __( 'Safari: Open Preferences, go to the Privacy tab, and configure your cookie blocking preferences. Safari also offers an option to prevent cross-site tracking.', 'surecookie' ),
		];
		$sections[] = [
			'type' => 'paragraph',
			'text' => __( 'Microsoft Edge: Open Settings, go to Privacy, Search, and Services. Under the Cookies section, you can choose to block third-party cookies or all cookies and manage cookie exceptions.', 'surecookie' ),
		];

		// --- Consequences of Disabling Cookies ---
		$sections[] = [
			'type'  => 'heading',
			'level' => 2,
			'text'  => __( 'Consequences of Disabling Cookies', 'surecookie' ),
		];
		$sections[] = [
			'type' => 'paragraph',
			'text' => __( 'If you choose to disable or decline cookies, some parts of this website may not function as intended. For example, you may not be able to log in, your preferences may not be saved between visits, and certain interactive features may be limited. Disabling essential cookies may make it impossible to use some or all of the services provided through this website.', 'surecookie' ),
		];
		$sections[] = [
			'type' => 'paragraph',
			'text' => __( 'Disabling analytics or marketing cookies will not affect the core functionality of the website, but it may limit our ability to improve the website based on user behavior or to provide you with personalized content and advertisements.', 'surecookie' ),
		];

		// --- Changes to This Cookie Policy ---
		$sections[] = [
			'type'  => 'heading',
			'level' => 2,
			'text'  => __( 'Changes to This Cookie Policy', 'surecookie' ),
		];
		$sections[] = [
			'type' => 'paragraph',
			'text' => __( 'We may update this Cookie Policy from time to time to reflect changes in technology, legislation, our business operations, or any other reason we determine is necessary or appropriate. Any changes will be posted on this page with an updated revision date. We encourage you to review this Cookie Policy periodically to stay informed about how we are using cookies.', 'surecookie' ),
		];

		// --- Contact Us ---
		// Emitted only when there is something real to print. Generation runs
		// once and never rewrites the page, so a contact block built from empty
		// Business Details would be a permanently blank section on a live page.
		if ( self::has_contact_details() ) {
			$sections[] = [
				'type'  => 'heading',
				'level' => 2,
				'text'  => __( 'Contact Us', 'surecookie' ),
			];
			$sections[] = [
				'type' => 'paragraph',
				'text' => __( 'If you have any questions or concerns about this Cookie Policy or our use of cookies, please contact us at:', 'surecookie' ),
			];
			// Shortcodes rather than literal placeholders, so the page follows
			// Business Details instead of freezing whatever was true on the day
			// it was generated.
			$sections[] = [
				'type' => 'paragraph',
				'text' => '[surecookie_company_name], [surecookie_address], [surecookie_contact_email]',
			];
		}

		return $sections;
	}

	/**
	 * Whether Business Details carry enough to print a contact block.
	 *
	 * @since 1.5.0
	 * @return bool
	 */
	private static function has_contact_details(): bool {
		$details = Validator::details();

		return trim( (string) ( $details['legal_entity_name'] ?? '' ) ) !== ''
			&& is_email( trim( (string) ( $details['privacy_contact_email'] ?? '' ) ) );
	}
}
