<?php
/**
 * Divi Builder Integration
 *
 * @package SureRank\Inc\ThirdPartyIntegrations
 */

namespace SureRank\Inc\ThirdPartyIntegrations;

use SureRank\Inc\Admin\Dashboard;
use SureRank\Inc\Admin\Seo_Popup as Admin_Seo_Popup;
use SureRank\Inc\Frontend\Image_Seo;
use SureRank\Inc\Functions\Get;
use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Traits\Loop_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Divi Builder Integration Class
 *
 * Handles both Divi 4 (et_pb_* shortcodes) and Divi 5 (WordPress blocks).
 *
 * DIVI 5 — native block rendering:
 *   Divi 5 registers all modules as WordPress block types, and explicitly
 *   enables this registration for REST API requests (see builder-5/server/
 *   bootstrap.php). WordPress core's do_blocks() invokes Divi's own
 *   server-side render callbacks for every module type automatically.
 *
 * DIVI 4 — et_builder_render_layout():
 *   Divi's own render function applies its full filter chain (do_blocks at
 *   priority 9, do_shortcode at priority 11), producing fully rendered HTML
 *   for every Divi 4 module type.
 */
class Divi {

	use Get_Instance;
	use Loop_Context;

	/**
	 * Per-request cache of processed builder content.
	 *
	 * SureRank resolves multiple meta variables per request (description,
	 * og/twitter description, schema, …) and each resolution re-runs the
	 * content filters, re-rendering the same builder content. Every extra
	 * do_blocks() pass also feeds Divi 5's DynamicAssetsDetection, which
	 * string-concatenates every rendered block's attributes and can exhaust
	 * PHP memory on large layouts. Render once per request and reuse.
	 *
	 * @since 1.10.0
	 * @var array<string, string>
	 */
	private $processed_content_cache = [];

	/**
	 * Constructor
	 */
	public function __construct() {

		if ( ! $this->is_active() ) {
			return;
		}

		add_filter( 'surerank_post_analyzer_content', [ $this, 'process_divi_content' ], 10, 2 );
		add_filter( 'surerank_meta_variable_post_content', [ $this, 'process_divi_content' ], 10, 2 );

		// Divi Frontend Visual Builder (?et_fb=1) — enqueue popup on the frontend.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_for_visual_builder' ], 101 );

		// Enqueue surerank_globals on FVB frontend (same guard as enqueue_for_visual_builder).
		// Mirrors the Bricks pattern: hooked separately so jquery handle is reliably enqueued.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_globals_for_visual_builder' ], 999 );

		// Add is_divi flag to surerank_globals (mirrors Bricks add_localization_vars).
		add_filter( 'surerank_globals_localization_vars', [ $this, 'add_localization_vars' ] );

		// Admin bar button + click handler for any Divi admin editing context
		// (block editor, classic editor, BFB). Fires on admin_enqueue_scripts so the
		// inline script is appended after seo-popup is already in the queue.
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_add_divi_admin_click_handler' ], 99999 );

		// admin_bar_menu fires on both admin and frontend, so this handles both
		// the FVB top window (frontend) and all admin editor contexts in one hook.
		add_action( 'admin_bar_menu', [ $this, 'maybe_add_divi_admin_bar_menu' ], 100 );

		// Override editor type to 'divi' when Divi Backend Builder (BFB) is active
		// so the JS skips the classic .wrap > h1 button injection.
		add_filter( 'surerank_detect_editor_type', [ $this, 'filter_bfb_editor_type' ], 10, 2 );
	}

	/**
	 * Check if Divi Builder is active.
	 *
	 * ET_BUILDER_VERSION is defined inside et_setup_builder() which is always
	 * called by et-pagebuilder.php via the init hook, regardless of request
	 * context (admin, frontend, REST API, AJAX).
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public function is_active(): bool {
		return defined( 'ET_BUILDER_VERSION' ) || class_exists( 'ET_Builder_Element' );
	}

	/**
	 * Route content to the correct renderer based on Divi version.
	 *
	 * Detection strategy:
	 *   - Divi 5 pages store content as <!-- wp:divi/... --> block comments.
	 *     We detect this from the content itself because _et_pb_use_builder is
	 *     NOT reliably set for Divi 5 pages (it is only force-set to 'on' during
	 *     the Divi layout-block preview request, not on regular page saves).
	 *   - Divi 4 pages are detected via the _et_pb_use_builder = 'on' meta key,
	 *     which Divi 4 always writes when the builder is active for a post.
	 *
	 * @since 1.7.0
	 * @param string   $content Raw post_content.
	 * @param \WP_Post $post    Post being analyzed.
	 * @return string Rendered HTML for XPath analysis.
	 */
	public function process_divi_content( string $content, $post ): string {
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		$cache_key = $post->ID . ':' . md5( $content );

		if ( ! isset( $this->processed_content_cache[ $cache_key ] ) ) {
			$this->processed_content_cache[ $cache_key ] = $this->render_builder_content( $content, $post );
		}

		return $this->processed_content_cache[ $cache_key ];
	}

	/**
	 * Enqueue SEO popup for Divi Frontend Visual Builder (frontend ?et_fb=1).
	 *
	 * Fires on wp_enqueue_scripts at priority 101 (after Divi's own scripts).
	 * Calls Enqueue trait methods directly to avoid the is_admin_bar_showing()
	 * guard inside frontend_enqueue_scripts(), which can return false in
	 * certain Divi 5 rendering contexts even when the admin bar is visible.
	 *
	 * Skips the Divi 5 app-window iframe (?et_fb=1&app_window=1) — scripts
	 * should only load once, in the top-level builder window.
	 *
	 * @since 1.6.0
	 * @return void
	 */
	public function enqueue_for_visual_builder(): void {
		if ( ! function_exists( 'et_core_is_fb_enabled' ) || ! et_core_is_fb_enabled() ) {
			return;
		}

		// Divi 5 loads the page in two iframes: the outer builder shell (?et_fb=1)
		// and an inner content preview (?et_fb=1&app_window=1). Only enqueue in
		// the outer window to avoid double-loading assets.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['app_window'] ) ) {
			return;
		}

		if ( ! current_user_can( 'surerank_content_setting' ) ) {
			return;
		}

		$post_id = get_the_ID();

		// Auto-draft pages (new unsaved posts) are excluded from the public WP
		// query, so get_the_ID() returns 0 in FVB. Divi always passes the post
		// being edited as ?page_id= (pages) or ?p= (other post types).
		if ( ! $post_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw_id = absint( $_GET['page_id'] ?? $_GET['p'] ?? 0 );
			if ( $raw_id && current_user_can( 'edit_post', $raw_id ) ) {
				$post_id = $raw_id;
			}
		}

		if ( ! $post_id ) {
			return;
		}

		$seo_popup = Admin_Seo_Popup::get_instance();

		wp_enqueue_media();
		$seo_popup->enqueue_vendor_and_common_assets();

		$seo_popup->build_assets_operations(
			'seo-popup',
			[
				'hook'        => 'seo-popup',
				'object_name' => 'seo_popup',
				'data'        => [
					'admin_assets_url'   => SURERANK_URL . 'inc/admin/assets',
					'site_icon_url'      => get_site_icon_url( 16 ),
					'editor_type'        => 'divi',
					'post_type'          => get_post_type( $post_id ) ? get_post_type( $post_id ) : '',
					'is_taxonomy'        => false,
					'description_length' => Get::description_length(),
					'title_length'       => Get::title_length(),
					'keyword_checks'     => $seo_popup->keyword_checks(),
					'page_checks'        => $seo_popup->page_checks(),
					'image_seo'          => Image_Seo::get_instance()->status(),
					'is_frontend'        => true,
					'post_id'            => $post_id,
					'link'               => get_the_permalink( $post_id ),
				],
			]
		);

		$seo_popup->build_assets_operations(
			'front-end-meta-box',
			[
				'hook'        => 'front-end-meta-box',
				'object_name' => 'front_end_meta_box',
				'data'        => [],
			]
		);

		// Admin bar node is registered by maybe_add_divi_admin_bar_menu() which is
		// hooked on admin_bar_menu in the constructor and covers both FVB (frontend)
		// and all admin editing contexts.

		// Enqueue the Divi page bar integration script (button + status indicator + tooltip).
		$this->enqueue_divi_bar_script();
	}

	/**
	 * Enqueue surerank_globals for Divi Frontend Visual Builder.
	 *
	 * Fires on wp_enqueue_scripts at priority 999 (after Divi's own scripts).
	 * Hooked separately from enqueue_for_visual_builder() so that the jquery
	 * handle is reliably in the queue before wp_localize_script() is called —
	 * mirrors the pattern used by the Bricks integration.
	 *
	 * @since 1.6.0
	 * @return void
	 */
	public function enqueue_globals_for_visual_builder(): void {
		if ( ! function_exists( 'et_core_is_fb_enabled' ) || ! et_core_is_fb_enabled() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['app_window'] ) ) {
			return;
		}

		Dashboard::get_instance()->site_seo_check_enqueue_scripts();
	}

	/**
	 * Add Divi-specific variables to surerank_globals localization.
	 *
	 * Mirrors Bricks::add_localization_vars() so that JS code can detect
	 * the active builder via window.surerank_globals.is_divi.
	 *
	 * @since 1.6.0
	 * @param array<string, mixed> $vars Existing localization variables.
	 * @return array<string, mixed>
	 */
	public function add_localization_vars( array $vars ): array {
		return array_merge( $vars, [ 'is_divi' => true ] );
	}

	/**
	 * Override editor type to 'divi' when Divi Backend Builder is active.
	 *
	 * Hooked into surerank_detect_editor_type, which is applied at the end of
	 * Admin\Seo_Popup::detect_editor_type() so that the JS skips the classic
	 * .wrap > h1 button injection and uses the admin bar button instead.
	 *
	 * @since 1.6.0
	 * @param string          $editor_type Current editor type.
	 * @param \WP_Screen|null $screen      Current screen object.
	 * @return string
	 */
	public function filter_bfb_editor_type( string $editor_type, $screen ): string {
		if ( ! function_exists( 'et_builder_bfb_enabled' ) || ! et_builder_bfb_enabled() ) {
			return $editor_type;
		}

		// BFB is a site-wide toggle, but it only replaces the editor for posts
		// built with Divi — a plain block editor post must keep its real type.
		$post = get_post();
		if ( $post instanceof \WP_Post && ! $this->post_uses_divi_builder( $post ) ) {
			return $editor_type;
		}

		return 'divi';
	}

	/**
	 * Attach an inline admin-bar click handler for any Divi admin editing context.
	 *
	 * Fires late on admin_enqueue_scripts (priority 99999) so surerank-seo-popup
	 * is guaranteed to be enqueued before we append to it. Works for block editor,
	 * classic editor, and BFB — wherever seo-popup scripts are already loaded.
	 *
	 * @since 1.6.0
	 * @return void
	 */
	public function maybe_add_divi_admin_click_handler(): void {
		if ( ! wp_script_is( 'surerank-seo-popup', 'enqueued' ) ) {
			return;
		}

		wp_add_inline_script(
			'surerank-seo-popup',
			"document.addEventListener('click',function(e){if(e.target.closest('#wp-admin-bar-surerank-meta-box')){e.preventDefault();if(window.wp&&window.wp.data){window.wp.data.dispatch('surerank').updateModalState(true);} } });"
		);
	}

	/**
	 * Render admin bar node for any Divi editing context (admin or FVB frontend).
	 *
	 * Delegates to Admin\Seo_Popup::add_admin_bar_menu(), which self-guards:
	 * it only adds the node when surerank-seo-popup is already enqueued.
	 * This makes it safe to hook unconditionally on admin_bar_menu.
	 *
	 * @since 1.6.0
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 * @return void
	 */
	public function maybe_add_divi_admin_bar_menu( \WP_Admin_Bar $wp_admin_bar ): void {
		Admin_Seo_Popup::get_instance()->add_admin_bar_menu( $wp_admin_bar );
	}

	/**
	 * Render builder content through the version-appropriate Divi renderer.
	 *
	 * Extracted from process_divi_content() so the routing runs at most once
	 * per post content per request (see $processed_content_cache).
	 *
	 * @since 1.10.0
	 * @param string   $content Raw post_content.
	 * @param \WP_Post $post    Post being analyzed.
	 * @return string Rendered HTML, or the raw content for non-Divi posts.
	 */
	private function render_builder_content( string $content, \WP_Post $post ): string {
		// Divi 5 content always contains the wp:divi/ block namespace.
		if ( false !== strpos( $content, '<!-- wp:divi/' ) ) {
			return $this->process_divi5( $content );
		}

		// Divi 4 pages set this meta key when the visual builder is active.
		if ( 'on' !== get_post_meta( $post->ID, '_et_pb_use_builder', true ) ) {
			return $content;
		}

		return $this->process_divi4( $content );
	}

	/**
	 * Whether a post is built with the Divi builder.
	 *
	 * Mirrors process_divi_content(): Divi 5 stores wp:divi/ block comments in
	 * the content (the meta is not reliably set), Divi 4 writes the
	 * _et_pb_use_builder meta.
	 *
	 * @since 1.9.3
	 * @param \WP_Post $post Post to check.
	 * @return bool
	 */
	private function post_uses_divi_builder( $post ): bool {
		if ( false !== strpos( (string) $post->post_content, '<!-- wp:divi/' ) ) {
			return true;
		}

		return 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true );
	}

	/**
	 * Render Divi 5 content using WordPress core's do_blocks().
	 *
	 * Divi 5 registers ALL its modules as WordPress block types with proper
	 * render_callback functions, and this registration happens for REST API
	 * requests (see includes/builder-5/server/bootstrap.php). Calling
	 * do_blocks() invokes Divi's own server-side render callbacks for every
	 * module type with no custom per-module code required on our side.
	 *
	 * TabModule::_is_subsequent_loop_iteration() uses a private static array
	 * keyed on get_the_ID(). Calling do_blocks() here (during wp_head, before
	 * the page template renders) would consume the first-tab slot for the real
	 * post ID, causing the_content() render to omit et_pb_active_content from
	 * the first tab and hide it. We temporarily set $GLOBALS['post']->ID to -1
	 * (a sentinel that is impossible for any real WP post) so this render uses
	 * a separate counter slot from the real page render.
	 *
	 * The pass must leave no trace behind, so it also snapshots and restores
	 * Divi's global static render state and WordPress' loop globals.
	 *
	 * @param string $content Raw post_content with divi/* block comments.
	 * @return string Fully rendered HTML from Divi's own render callbacks.
	 */
	private function process_divi5( string $content ): string {
		if ( ! function_exists( 'do_blocks' ) ) {
			return $content;
		}

		// TabModule::_is_subsequent_loop_iteration() uses a private static array
		// keyed on get_the_ID(). Calling do_blocks() here (during wp_head, before
		// the page template renders) would consume the first-tab slot for the real
		// post ID, causing the_content() render to omit et_pb_active_content from
		// the first tab and hide it. Temporarily set $post->ID to -1 (a sentinel
		// impossible for any real WP post) so this render uses a separate counter
		// slot from the real page render.
		// Divi 5 keeps global static render state that this extra pass mutates:
		// ordinal class counters (et_pb_text_30, et_pb_column_31, … in
		// ET_Builder_Module_Order, keyed by layout type and module slug — not by
		// post ID, so the sentinel below does not protect them) and the style
		// collector (FrontEnd\Module\Style::$_styles, which Divi prints into the
		// page later). Snapshot both so the real template render starts from the
		// exact state it would have had without this pass; otherwise modules get
		// shifted ordinal classes and/or inline CSS collected during this hidden
		// render leaks into the visible page (#2880). Taken before the $post->ID
		// sentinel is applied so a snapshot failure can never leave the global
		// post mutated. WordPress' own loop globals are guarded separately below.
		$render_state = $this->get_divi5_render_state();

		// Divi 5 loop modules (Loop Builder, the feature that surfaced #2880) call
		// the_post()/setup_postdata() while rendering, mutating the whole loop
		// context, not only $post: $id, $authordata, $pages, $page, $more,
		// $numpages, $currentday, $currentmonth. Their own wp_reset_postdata()
		// restores the main query's post, not the state this hidden pass started
		// from, so snapshot the full context and put it back afterwards.
		$loop_context = $this->get_loop_context();

		global $post;

		// Hold the post object in its own variable. `global $post` is an alias of
		// the global slot: WP_Block::render() restores that slot around a block's
		// own callback, but anything mutating it outside that guard (a render_block
		// filter, a shortcode inside a module's output) leaves the alias pointing at
		// a different post. Writing the sentinel back through the alias would then
		// strand the real post at ID -1 and stamp -1 onto that other post.
		$sentinel_post = $post instanceof \WP_Post ? $post : null;
		$original_id   = null !== $sentinel_post ? $sentinel_post->ID : 0;

		if ( null !== $sentinel_post ) {
			$sentinel_post->ID = -1;
		}

		$html = '';
		try {
			$html = do_blocks( $content );
		} finally {
			if ( null !== $sentinel_post ) {
				$sentinel_post->ID = $original_id;
			}
			$this->restore_loop_context( $loop_context );
			$this->restore_divi5_render_state( $render_state );
		}

		return $html ? $html : $content;
	}

	/**
	 * Map of Divi 5 global static render state mutated by a do_blocks() pass.
	 *
	 * ET_Builder_Module_Order holds the ordinal class counters; the FrontEnd
	 * Module Style class accumulates the inline CSS Divi prints later in the
	 * request. Neither exposes a public way to snapshot its full state, so the
	 * properties are read via reflection.
	 *
	 * @since 1.10.0
	 * @return array<string, array<int, string>> Class name => static property names.
	 */
	private function divi5_render_state_map(): array {
		return [
			'ET_Builder_Module_Order'              => [ '_indices' ],
			'ET\\Builder\\FrontEnd\\Module\\Style' => [
				'_styles',
				'_unique_counter',
				'_preset_selector_processed',
				'_detected_module_types_for_inner_content',
				'_is_theme_builder_context_for_inner_content',
				'_ancestor_ids_cache',
				'_style_key_cache',
				// Not present in Divi 5.0-beta-9.4 / 5.9.0 but included
				// defensively — properties missing on the installed build are
				// skipped, so this covers any future Divi version that adds it.
				'_style_key_cache_context',
				'_group_style',
			],
		];
	}

	/**
	 * Resolve a static property of a Divi class for snapshot/restore access.
	 *
	 * Returns null for non-static properties. setAccessible() is only needed
	 * (and only called) below PHP 8.1 — it is a no-op since 8.1 and deprecated
	 * in 8.5.
	 *
	 * @since 1.10.0
	 * @param string $class_name    Fully qualified class name.
	 * @param string $property_name Static property name.
	 * @return \ReflectionProperty|null The accessible property, or null when not static.
	 * @throws \ReflectionException When the class or property does not exist.
	 */
	private function divi5_static_property( string $class_name, string $property_name ): ?\ReflectionProperty {
		$property = new \ReflectionProperty( $class_name, $property_name );

		if ( ! $property->isStatic() ) {
			return null;
		}

		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}

		return $property;
	}

	/**
	 * Read a snapshot of Divi 5's global render state.
	 *
	 * Failure is non-fatal by design: a class or property missing, renamed, or
	 * reshaped on the installed Divi version is skipped (\Throwable, not just
	 * ReflectionException — uninitialized typed properties throw Error), and
	 * without a snapshot the render simply behaves as before this workaround
	 * existed.
	 *
	 * @since 1.10.0
	 * @return array<string, array<string, mixed>>|null Snapshot, or null when unavailable.
	 */
	private function get_divi5_render_state(): ?array {
		$snapshot = [];

		foreach ( $this->divi5_render_state_map() as $class_name => $properties ) {
			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			foreach ( $properties as $property_name ) {
				try {
					$property = $this->divi5_static_property( $class_name, $property_name );

					if ( null === $property ) {
						continue;
					}

					$snapshot[ $class_name ][ $property_name ] = $property->getValue();
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}

		return [] !== $snapshot ? $snapshot : null;
	}

	/**
	 * Restore Divi 5's global render state captured by get_divi5_render_state().
	 *
	 * Called after the hidden do_blocks() pass in process_divi5() so that the
	 * extra render leaves Divi's ordinal class numbering and collected styles
	 * untouched for the real page render. Each property restores independently
	 * and never throws (\Throwable guard), so a single mismatch cannot break
	 * frontend rendering.
	 *
	 * @since 1.10.0
	 * @param array<string, array<string, mixed>>|null $state Snapshot, or null when none was taken.
	 * @return void
	 */
	private function restore_divi5_render_state( ?array $state ): void {
		if ( null === $state ) {
			return;
		}

		foreach ( $state as $class_name => $properties ) {
			foreach ( $properties as $property_name => $value ) {
				try {
					$property = $this->divi5_static_property( $class_name, $property_name );

					if ( null === $property ) {
						continue;
					}

					$property->setValue( null, $value );
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}
	}

	/**
	 * Render Divi 4 content using Divi's own render function.
	 *
	 * The et_builder_render_layout() applies Divi's full render filter chain
	 * (do_blocks at priority 9, do_shortcode at priority 11), producing
	 * fully rendered HTML for every Divi 4 module type.
	 *
	 * @param string $content Raw Divi 4 post_content with et_pb_* shortcodes.
	 * @return string Rendered HTML.
	 */
	private function process_divi4( string $content ): string {
		if ( ! function_exists( 'et_builder_render_layout' ) ) {
			return $content;
		}

		return et_builder_render_layout( $content );
	}

	/**
	 * Enqueue the bundled Divi page bar integration script.
	 *
	 * Registers and enqueues the webpack-built divi module which injects a
	 * SureRank button (with status indicator and tooltip) into the Divi VB
	 * page bar. Uses the auto-generated asset file for dependencies and version.
	 *
	 * @since 1.6.5
	 * @return void
	 */
	private function enqueue_divi_bar_script(): void {
		$asset_path = SURERANK_DIR . 'build/divi/index.asset.php';

		if ( ! file_exists( $asset_path ) ) {
			return;
		}

		$asset_info = include $asset_path;

		// Ensure surerank-seo-popup is a dependency so the store is available.
		$asset_info['dependencies'][] = 'surerank-seo-popup';
		$asset_info['dependencies']   = array_unique( $asset_info['dependencies'] );

		wp_register_script(
			'surerank-divi',
			SURERANK_URL . 'build/divi/index.js',
			$asset_info['dependencies'],
			$asset_info['version'],
			false
		);
		wp_enqueue_style(
			'surerank-divi',
			SURERANK_URL . 'build/divi/style.css',
			[],
			$asset_info['version']
		);
		wp_style_add_data( 'surerank-divi', 'rtl', 'replace' );
		wp_enqueue_script( 'surerank-divi' );
	}
}
