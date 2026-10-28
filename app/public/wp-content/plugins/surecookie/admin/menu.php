<?php
/**
 * Menu handler for SureCookie plugin.
 *
 * @package    SureCookie
 * @subpackage SureCookie\Admin
 * @since      0.0.1
 */

namespace SureCookie\Admin;

use SureCookie\Inc\Database\ConsentLog;
use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\LocalizeData;

defined( 'ABSPATH' ) || exit;

/**
 * Menu handler for SureCookie plugin.
 *
 * This class handles the registration and rendering of the admin menu.
 *
 * @since 0.0.1
 */
class Menu {
	use GetInstance;

	/**
	 * Admin page hooks that render the SureCookie app.
	 *
	 * @since 1.4.0
	 */
	private const PLUGIN_SCREEN_HOOKS = [
		'toplevel_page_surecookie',
		'settings_page_surecookie',
		'surecookie_page_surecookie-banner',
		'surecookie_page_surecookie-settings',
		'surecookie_page_surecookie-advanced',
	];

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		// Late, so it wins over plugins that enqueue on the default priority.
		add_action( 'admin_enqueue_scripts', [ $this, 'dequeue_conflicting_styles' ], 999 );
		add_filter( 'plugin_action_links_' . SURECOOKIE_BASE, [ $this, 'plugin_action_links' ] );

		if ( ! Helper::is_pro_active() && Settings::get( 'top_level_menu_enabled' ) ) {
			add_action( 'admin_footer', [ $this, 'print_upgrade_submenu_assets' ] );
		}
	}

	/**
	 * Register the admin menu.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_admin_menu(): void {
		$show_top_level = Settings::get( 'top_level_menu_enabled' );

		if ( $show_top_level ) {
			// Resolve the unread-logs count once so the top-level attention dot
			// and the Logs submenu badge stay in sync from a single lookup.
			$unread_logs = $this->get_unread_logs_count();

			add_menu_page(
				'SureCookie',
				$this->get_top_level_menu_title( $unread_logs ),
				Helper::capability(),
				SURECOOKIE_PREFIX,
				[ $this, 'render_admin_page' ],
				'none',
				58
			);

			// Wanted Settings submenu to be second item no need to follow
			// (getNavLinks() in src/admin/components/AdminToolbar/constants.js) order.
			$submenus = [
				[
					'id'         => SURECOOKIE_PREFIX,
					'page_title' => __( 'Dashboard', 'surecookie' ),
				],
				[
					'id'         => SURECOOKIE_PREFIX . '#/cookie-manager/scan',
					'page_title' => __( 'Tracking Manager', 'surecookie' ),
				],
				[
					'id'         => SURECOOKIE_PREFIX . '#/compliance/check',
					'page_title' => __( 'Compliance', 'surecookie' ),
					'menu_title' => $this->with_new_badge( __( 'Compliance', 'surecookie' ) ),
				],
				[
					'id'         => SURECOOKIE_PREFIX . '#/analytics',
					'page_title' => __( 'Consent Logs', 'surecookie' ),
					'menu_title' => $this->get_logs_submenu_title( $unread_logs ),
				],
				[
					'id'         => SURECOOKIE_PREFIX . '#/data-requests',
					'page_title' => __( 'Data Requests', 'surecookie' ),
					'menu_title' => $this->get_data_requests_submenu_title( $this->get_open_data_requests_count() ),
				],
				[
					'id'         => SURECOOKIE_PREFIX . '#/banner/content',
					'page_title' => __( 'Settings', 'surecookie' ),
				],
				[
					'id'         => SURECOOKIE_PREFIX . '#/learn',
					'page_title' => __( 'Learn', 'surecookie' ),
				],
			];

			foreach ( $submenus as $submenu ) {
				add_submenu_page(
					SURECOOKIE_PREFIX,
					$submenu['page_title'],
					$submenu['menu_title'] ?? $submenu['page_title'],
					Helper::capability(),
					$submenu['id'],
					[ $this, 'render_admin_page' ]
				);
			}

			if ( ! Helper::is_pro_active() ) {
				$this->register_upgrade_submenu();
			}

			return;
		}

		add_options_page(
			'SureCookie',
			'SureCookie',
			Helper::capability(),
			SURECOOKIE_PREFIX,
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( Helper::capability() ) ) {
			wp_die( esc_html__( 'You don\'t have access to this page.', 'surecookie' ) );
		}

		echo '<div class="wrap">';
		echo '<div id="surecookie-admin-root"></div>';
		echo '</div>';
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @since 0.0.1
	 * @param string $hook The current admin page.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook = '' ): void {
		$this->enqueue_admin_menu_icon_style();

		if ( ! in_array( $hook, self::PLUGIN_SCREEN_HOOKS, true ) ) {
			return;
		}

		$asset_file = SURECOOKIE_DIR . 'build/admin.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$assets             = require $asset_file;
		$extra_dependencies = [ 'updates' ];

		// Enqueue WordPress media uploader.
		wp_enqueue_media();

		// Enqueue CodeMirror for CSS editor.
		wp_enqueue_code_editor( [ 'type' => 'text/css' ] );

		wp_enqueue_script(
			'surecookie-admin',
			SURECOOKIE_URL . 'build/admin.js',
			array_merge( $assets['dependencies'] ?? [], $extra_dependencies ),
			$assets['version'] ?? SURECOOKIE_VERSION,
			true
		);
		wp_set_script_translations( 'surecookie-admin', 'surecookie', SURECOOKIE_DIR . 'languages' );

		wp_enqueue_style(
			'surecookie-admin',
			SURECOOKIE_URL . 'build/' . Get::admin_style_css(),
			[],
			SURECOOKIE_VERSION
		);

		wp_enqueue_style(
			'surecookie-jodit-library',
			SURECOOKIE_URL . 'assets/css/' . Get::jodit_style_css(),
			[],
			SURECOOKIE_VERSION . '-jodit-4.12.18'
		);

		wp_enqueue_style(
			'surecookie-admin-styles',
			SURECOOKIE_URL . 'assets/css/' . Get::shared_style_css(),
			[ 'surecookie-admin' ],
			SURECOOKIE_VERSION
		);

		// Add palette CSS variables from stored option.
		$palette_css = Sanitize::stylesheet( Get::palette_root_css() );
		if ( ! empty( $palette_css ) ) {
			wp_add_inline_style( 'surecookie-admin', wp_strip_all_tags( $palette_css ) );
		}

		do_action( 'surecookie_admin_enqueue_scripts_after', $hook );

		// Localize admin data using utility.
		LocalizeData::localize_admin_script( 'surecookie-admin' );
	}

	/**
	 * Drop other plugins' unscoped stylesheets from SureCookie's own screens.
	 *
	 * MemberPress Courses enqueues simplegrid.css on EVERY admin page; its
	 * generic selectors (`.grid`, `[class*='col-']`) restyle Tailwind's grid
	 * and col-span utilities and mangle this app's layout (ticket 1507247).
	 * `mpcs-admin-shared` lists simplegrid as a dependency, so it has to go
	 * too or WordPress prints simplegrid right back.
	 *
	 * @since 1.4.0
	 * @param string $hook The current admin page.
	 * @return void
	 */
	public function dequeue_conflicting_styles( $hook = '' ): void {
		if ( ! in_array( $hook, self::PLUGIN_SCREEN_HOOKS, true ) ) {
			return;
		}

		foreach ( [ 'mpcs-admin-shared', 'mpcs-simplegrid', 'mepr-simplegrid' ] as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	/**
	 * Print styles and a click handler that open the Upgrade submenu in a new tab.
	 *
	 * Runs on every admin page because the submenu appears in the sidebar site-wide.
	 * The CSS/JS selectors are scoped to `#toplevel_page_surecookie` so there is no
	 * side effect on other admin screens.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function print_upgrade_submenu_assets(): void {
		?>
		<style>
			#adminmenu #toplevel_page_surecookie .wp-submenu a[href*="surecookie.com/pricing"] {
				background-color: #377A00 !important;
				color: #ffffff !important;
				font-weight: 500;
				font-size: 13px;
				line-height: 20px;
				padding-right: 12px !important;
				padding-left: 12px !important;
			}
			#adminmenu #toplevel_page_surecookie .wp-submenu a[href*="surecookie.com/pricing"]:hover,
			#adminmenu #toplevel_page_surecookie .wp-submenu a[href*="surecookie.com/pricing"]:focus {
				background-color: #2F6A00 !important;
				color: #ffffff !important;
			}
		</style>
		<script type="text/javascript">
			document.addEventListener( 'DOMContentLoaded', function () {
				var link = document.querySelector( '#adminmenu #toplevel_page_surecookie .wp-submenu a[href*="surecookie.com/pricing"]' );
				if ( ! link ) {
					return;
				}
				link.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					window.open( link.href, '_blank', 'noopener,noreferrer' );
				} );
			} );
		</script>
		<?php
	}

	/**
	 * Add action links to the plugin list page.
	 *
	 * @since 0.0.1
	 * @param array<string, string> $links Existing action links.
	 * @return array<string, string> Modified action links.
	 */
	public function plugin_action_links( $links = [] ): array {
		$admin_url = admin_url( 'admin.php' );

		$new_links = [
			'settings' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( 'page', SURECOOKIE_PREFIX, $admin_url ) . '#/banner/content' ),
				esc_html__( 'Settings', 'surecookie' )
			),
		];

		return array_merge( $new_links, $links );
	}

	/**
	 * Register the "Upgrade" submenu under SureCookie.
	 *
	 * Uses a stable internal slug so that WordPress-generated hook names
	 * (`load-{$slug}`, `admin_page_{$slug}`, etc.) stay clean. The external
	 * pricing URL is then swapped into the `$submenu` global so WordPress
	 * renders the item as an external link. The JS click handler printed in
	 * `print_upgrade_submenu_assets()` intercepts the click and opens it in a
	 * new tab, so the page is never actually navigated to - `'__return_null'`
	 * is therefore a safe no-op callback.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_upgrade_submenu(): void {
		$upgrade_slug = SURECOOKIE_PREFIX . '_upgrade';

		add_submenu_page(
			SURECOOKIE_PREFIX,
			__( 'Upgrade', 'surecookie' ),
			__( 'Upgrade', 'surecookie' ),
			Helper::capability(),
			$upgrade_slug,
			'__return_null'
		);

		$upgrade_url = Helper::get_marketing_link(
			'pricing/',
			'pricing_submenu_link_upgrade'
		);

		// Swap the internal slug with the external pricing URL. Writing to the
		// $submenu global is the canonical WP pattern for linking a submenu item
		// to an external URL without polluting hook names with query strings.
		global $submenu;

		if ( ! isset( $submenu[ SURECOOKIE_PREFIX ] ) || ! is_array( $submenu[ SURECOOKIE_PREFIX ] ) ) {
			return;
		}

		foreach ( $submenu[ SURECOOKIE_PREFIX ] as $key => $item ) {
			if ( isset( $item[2] ) && $upgrade_slug === $item[2] ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional: swap internal slug for external upgrade URL.
				$submenu[ SURECOOKIE_PREFIX ][ $key ][2] = $upgrade_url;
				break;
			}
		}
	}

	/**
	 * Enqueue the inline style used for the top-level admin menu icon.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	private function enqueue_admin_menu_icon_style(): void {
		if ( ! is_admin() || ! current_user_can( Helper::capability() ) ) {
			return;
		}

		if ( ! Settings::get( 'top_level_menu_enabled' ) ) {
			return;
		}

		wp_register_style( 'surecookie-admin-menu-icon', false, [], SURECOOKIE_VERSION );
		wp_enqueue_style( 'surecookie-admin-menu-icon' );
		wp_add_inline_style( 'surecookie-admin-menu-icon', $this->get_admin_menu_icon_css() );
		wp_add_inline_style( 'surecookie-admin-menu-icon', $this->get_admin_menu_separator_css() );
		wp_add_inline_style( 'surecookie-admin-menu-icon', $this->get_admin_menu_dot_css() );
	}

	/**
	 * Count the current user's unread consent logs.
	 *
	 * Centralizes the capability check and count lookup so the top-level menu
	 * attention dot and the Logs submenu badge are driven by a single query.
	 *
	 * @since 1.2.3
	 * @return int
	 */
	private function get_unread_logs_count(): int {
		if ( ! current_user_can( Helper::capability() ) ) {
			return 0;
		}

		return ConsentLog::count_unread_logs_for_user( get_current_user_id() );
	}

	/**
	 * Build the top-level menu title, appending an attention dot for unread logs.
	 *
	 * The dot mirrors the pattern used across BSF plugins (e.g. SureForms) to
	 * flag unseen activity on the parent menu item. It is removed client-side
	 * once the Logs page is viewed (see AdminApp.jsx) and stops rendering on the
	 * next load once the logs are marked as viewed server-side.
	 *
	 * @since 1.2.3
	 * @param int $unread_count Number of unread consent logs.
	 * @return string
	 */
	private function get_top_level_menu_title( int $unread_count ): string {
		$label = 'SureCookie';

		if ( $unread_count <= 0 ) {
			return $label;
		}

		return $label . ' <span class="surecookie-update-dot" data-surecookie-logs-dot="true" aria-hidden="true"></span>';
	}

	/**
	 * Count the data requests still awaiting action.
	 *
	 * Free ships no data-request storage, so the number comes from Pro through
	 * the filter; without Pro it stays 0 and the submenu shows the "New" badge.
	 *
	 * @since 1.4.0
	 * @return int
	 */
	private function get_open_data_requests_count(): int {
		if ( ! current_user_can( Helper::capability() ) ) {
			return 0;
		}

		return max( 0, (int) apply_filters( 'surecookie_open_data_requests_count', 0 ) );
	}

	/**
	 * Wrap a submenu label in the shared "NEW" pill.
	 *
	 * Extracted so a feature with nothing to count does not have to grow its own
	 * count branch just to reuse the pill markup and the `.surecookie-new-badge`
	 * rule already printed by get_admin_menu_dot_css().
	 *
	 * @since 1.5.0
	 * @param string $label Submenu label.
	 * @return string
	 */
	private function with_new_badge( string $label ): string {
		return sprintf(
			'%1$s <span class="surecookie-new-badge">%2$s</span>',
			$label,
			esc_html__( 'NEW', 'surecookie' )
		);
	}

	/**
	 * Build the Data Requests submenu title.
	 *
	 * Mirrors the Consent Logs badge: an attention count when requests are
	 * waiting, and a "New" pill in its place while there are none, so the
	 * feature is still discoverable on a site that has never received one.
	 *
	 * @since 1.4.0
	 * @param int $open_count Number of data requests awaiting action.
	 * @return string
	 */
	private function get_data_requests_submenu_title( int $open_count ): string {
		$label = __( 'Data Requests', 'surecookie' );

		if ( $open_count <= 0 ) {
			return $this->with_new_badge( $label );
		}

		return sprintf(
			'%1$s <span class="update-plugins count-%2$d" data-surecookie-data-requests-badge="true"><span class="plugin-count">%3$s</span></span>',
			$label,
			$open_count,
			esc_html( number_format_i18n( $open_count ) )
		);
	}

	/**
	 * Build the Logs submenu title with an unread-count badge when needed.
	 *
	 * @since 1.2.0
	 * @since 1.2.3 Accepts the pre-computed unread count instead of querying it.
	 * @param int $unread_count Number of unread consent logs.
	 * @return string
	 */
	private function get_logs_submenu_title( int $unread_count ): string {
		$label = __( 'Consent Logs', 'surecookie' );

		if ( $unread_count <= 0 ) {
			return $label;
		}

		return sprintf(
			'%1$s <span class="update-plugins count-%2$d" data-surecookie-logs-badge="true"><span class="plugin-count">%3$s</span></span>',
			$label,
			$unread_count,
			esc_html( number_format_i18n( $unread_count ) )
		);
	}

	/**
	 * Build the submenu group-separator stylesheet.
	 *
	 * A rule is drawn after every item and suppressed for the ones listed here, so
	 * this list is a second hand-kept expression of the submenu order: the bands it
	 * leaves are configuration, then the two record screens, then Learn.
	 *
	 * @since 1.1.0
	 * @return string
	 */
	private function get_admin_menu_separator_css(): string {
		return '#toplevel_page_surecookie li {
				clear: both;
			}
			#toplevel_page_surecookie li:not(:last-child) a[href^="admin.php?page=surecookie"]:after {
				border-bottom: 1px solid hsla(0,0%,100%,.2);
				display: block;
				float: left;
				margin: 13px -15px 8px;
				content: "";
				width: calc(100% + 26px);
			}
			#toplevel_page_surecookie li:not(:last-child) a[href="admin.php?page=surecookie"]:after,
			#toplevel_page_surecookie li:not(:last-child) a[href^="admin.php?page=surecookie#/analytics"]:after,
			#toplevel_page_surecookie li:not(:last-child) a[href^="admin.php?page=surecookie#/cookie-manager/scan"]:after,
			#toplevel_page_surecookie li:not(:last-child) a[href^="admin.php?page=surecookie#/learn"]:after,
			#toplevel_page_surecookie li:not(:last-child) a[href^="admin.php?page=surecookie#/banner/content"]:after {
				content: none;
			}';
	}

	/**
	 * Build the unread-logs attention dot stylesheet for the top-level menu.
	 *
	 * @since 1.2.3
	 * @return string
	 */
	private function get_admin_menu_dot_css(): string {
		return '#toplevel_page_surecookie .wp-menu-name .surecookie-update-dot {
				display: inline-block;
				width: 8px;
				height: 8px;
				margin-left: 6px;
				border-radius: 50%;
				background: #d63638;
				vertical-align: middle;
			}
			#toplevel_page_surecookie .wp-submenu .surecookie-new-badge {
				background-color: #377a00;
				font-size: 9px;
				color: #fff;
				padding: 2px 4px;
				margin-left: 4px;
				border-radius: 4px;
				font-weight: 600;
			}';
	}

	/**
	 * Build the admin menu icon stylesheet.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	private function get_admin_menu_icon_css(): string {
		$icon_svg        = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256" fill="none"><path d="M97.0156 9.74499C120.404 0.0570803 146.141 -2.47884 170.971 2.45983C195.8 7.39873 218.609 19.59 236.51 37.4911L200.307 73.6942C189.566 62.9535 175.88 55.64 160.982 52.6766C146.085 49.7134 130.643 51.233 116.609 57.0458C102.576 62.8586 90.5814 72.7032 82.1426 85.3329C73.7038 97.9625 69.1992 112.811 69.1992 128.001C69.1992 140.594 72.2983 152.952 78.166 164.007C78.2596 164.006 78.3534 164.001 78.4473 164.001C89.4146 164.001 98.5033 172.026 100.17 182.524C105.185 179.335 111.141 177.497 117.525 177.518C133.572 177.572 146.835 189.36 149.221 204.729C153.155 204.564 157.087 204.1 160.982 203.325C175.88 200.362 189.566 193.046 200.307 182.306L236.51 218.511C218.609 236.412 195.8 248.603 170.971 253.542C146.141 258.48 120.404 255.945 97.0156 246.257C73.6273 236.569 53.6369 220.163 39.5723 199.114C25.5075 178.065 18 153.317 18 128.001C18 102.685 25.5074 77.9371 39.5723 56.8876C53.6369 35.8385 73.6272 19.433 97.0156 9.74499Z" fill="#A0A5AA"/><path d="M164 140.001C170.627 140.001 176 145.373 176 152.001C176 158.628 170.627 164.001 164 164.001C157.373 164.001 152 158.628 152 152.001C152 145.373 157.373 140.001 164 140.001Z" fill="#A0A5AA"/><path d="M108 108.001C114.627 108.001 120 113.373 120 120.001C120 126.628 114.627 132.001 108 132.001C101.373 132.001 96.0004 126.628 96 120.001C96 113.373 101.373 108.001 108 108.001Z" fill="#A0A5AA"/><path d="M152 76.0008C158.627 76.0008 164 81.3734 164 88.0008C164 94.6279 158.627 100.001 152 100.001C145.373 100.001 140 94.6279 140 88.0008C140 81.3734 145.373 76.0008 152 76.0008Z" fill="#A0A5AA"/></svg>' );
		$active_icon_svg = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256" fill="none"><path d="M97.0156 9.74499C120.404 0.0570803 146.141 -2.47884 170.971 2.45983C195.8 7.39873 218.609 19.59 236.51 37.4911L200.307 73.6942C189.566 62.9535 175.88 55.64 160.982 52.6766C146.085 49.7134 130.643 51.233 116.609 57.0458C102.576 62.8586 90.5814 72.7032 82.1426 85.3329C73.7038 97.9625 69.1992 112.811 69.1992 128.001C69.1992 140.594 72.2983 152.952 78.166 164.007C78.2596 164.006 78.3534 164.001 78.4473 164.001C89.4146 164.001 98.5033 172.026 100.17 182.524C105.185 179.335 111.141 177.497 117.525 177.518C133.572 177.572 146.835 189.36 149.221 204.729C153.155 204.564 157.087 204.1 160.982 203.325C175.88 200.362 189.566 193.046 200.307 182.306L236.51 218.511C218.609 236.412 195.8 248.603 170.971 253.542C146.141 258.48 120.404 255.945 97.0156 246.257C73.6273 236.569 53.6369 220.163 39.5723 199.114C25.5075 178.065 18 153.317 18 128.001C18 102.685 25.5074 77.9371 39.5723 56.8876C53.6369 35.8385 73.6272 19.433 97.0156 9.74499Z" fill="#FFFFFF"/><path d="M164 140.001C170.627 140.001 176 145.373 176 152.001C176 158.628 170.627 164.001 164 164.001C157.373 164.001 152 158.628 152 152.001C152 145.373 157.373 140.001 164 140.001Z" fill="#FFFFFF"/><path d="M108 108.001C114.627 108.001 120 113.373 120 120.001C120 126.628 114.627 132.001 108 132.001C101.373 132.001 96.0004 126.628 96 120.001C96 113.373 101.373 108.001 108 108.001Z" fill="#FFFFFF"/><path d="M152 76.0008C158.627 76.0008 164 81.3734 164 88.0008C164 94.6279 158.627 100.001 152 100.001C145.373 100.001 140 94.6279 140 88.0008C140 81.3734 145.373 76.0008 152 76.0008Z" fill="#FFFFFF"/></svg>' );

		return implode(
			"\n",
			[
				'#toplevel_page_surecookie .wp-menu-image:before {',
				"\tcontent: '';",
				"\tmask-image: url(" . wp_json_encode( $icon_svg ) . ');',
				"\tmask-size: contain;",
				"\tmask-repeat: no-repeat;",
				"\tmask-position: center;",
				"\tbackground-color: currentColor;",
				'}',
				'#toplevel_page_surecookie .wp-menu-image {',
				"\talign-content: center;",
				'}',
				'#toplevel_page_surecookie.current .wp-menu-image:before,',
				'#toplevel_page_surecookie.wp-has-current-submenu .wp-menu-image:before {',
				"\tmask-image: url(" . wp_json_encode( $active_icon_svg ) . ');',
				'}',
			]
		);
	}
}
