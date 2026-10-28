<?php
/**
 * Post Popup
 *
 * @since 1.0.0
 * @package surerank
 */

namespace SureRank\Inc\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\API\Migrations;
use SureRank\Inc\API\Post;
use SureRank\Inc\Frontend\Crawl_Optimization;
use SureRank\Inc\Frontend\Image_Seo;
use SureRank\Inc\Functions\Get;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Functions\Update;
use SureRank\Inc\GoogleSearchConsole\Auth as GoogleSearchConsoleAuth;
use SureRank\Inc\GoogleSearchConsole\Controller as GoogleSearchConsoleController;
use SureRank\Inc\GoogleSearchConsole\Url_Inspection;
use SureRank\Inc\Traits\Enqueue;
use SureRank\Inc\Traits\Get_Instance;

/**
 * Post Popup
 *
 * @method void wp_enqueue_scripts()
 * @since 1.0.0
 */
class Seo_Popup {

	use Enqueue;
	use Get_Instance;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function __construct() {
		if ( ! apply_filters( 'surerank_content_setting_access', current_user_can( 'manage_options' ) ) ) {
			return;
		}

		$this->enqueue_scripts_admin();
		add_action( 'current_screen', [ $this, 'register_term_edit_trigger' ] );
		add_action( 'show_user_profile', [ $this, 'add_user_meta_box_trigger' ] );
		add_action( 'edit_user_profile', [ $this, 'add_user_meta_box_trigger' ] );
		add_action( 'add_meta_boxes', [ $this, 'register_classic_sidebar_meta_box' ], 20, 2 );
		// Pin the box to the second position (just below Publish). Runs last so all boxes are registered.
		add_action( 'add_meta_boxes', [ $this, 'pin_classic_sidebar_meta_box' ], 9999, 1 );
		add_action( 'created_category', [ $this, 'update_category_seo_values' ] );
		add_action( 'edited_category', [ $this, 'update_category_seo_values' ] );
		/* SEO menu on the admin bar (wp-admin + front end); gated in the callback. */
		add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_menu' ], 100 );
		// For enqueue scripts on the frontend.
		add_action( 'wp_enqueue_scripts', [ $this, 'frontend_enqueue_scripts' ] );

		/* Live admin-bar status update when checks are ignored/restored/fixed. */
		add_action( 'rest_api_init', [ $this, 'register_status_route' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_bar_live' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_admin_bar_live' ] );
	}

	/**
	 * Add tags
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function add_meta_box_trigger() {
		echo '<span id="seo-popup" class="surerank-root"></span>';
	}

	/**
	 * Add SEO popup trigger on user profile edit screens.
	 *
	 * Fires inside the profile form via show_user_profile/edit_user_profile;
	 * the popup JS relocates the trigger next to the page heading.
	 *
	 * @param \WP_User $user The user being edited.
	 * @since 1.9.0
	 * @return void
	 */
	public function add_user_meta_box_trigger( $user ) {
		if ( ! $user instanceof \WP_User || ! Seo_Bar::display_metabox( '', 'wp_users' ) ) {
			return;
		}

		// profile.php is reachable by every role, so gate the trigger on the
		// same capability chain the REST routes use plus edit_user on the
		// target, otherwise subscribers see a button whose API calls 403.
		if ( ! apply_filters( 'surerank_content_setting_access', current_user_can( 'manage_options' ) )
			|| ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		echo '<span id="seo-popup" class="surerank-root"></span>';
	}

	/**
	 * Register the term edit form trigger for the current taxonomy screen.
	 *
	 * @param \WP_Screen $screen Current screen object.
	 * @return void
	 */
	public function register_term_edit_trigger( $screen ): void {
		if ( ! $screen instanceof \WP_Screen || 'term' !== $screen->base || empty( $screen->taxonomy ) ) {
			return;
		}

		if ( ! Seo_Bar::display_metabox( $screen->taxonomy, 'wp_terms' ) ) {
			return;
		}

		add_action( "{$screen->taxonomy}_term_edit_form_top", [ $this, 'add_meta_box_trigger' ] );
	}

	/**
	 * Register the Classic Editor sidebar meta box for opening the SEO popup.
	 *
	 * @param string   $post_type Current post type.
	 * @param \WP_Post $post      Current post object.
	 * @return void
	 */
	public function register_classic_sidebar_meta_box( string $post_type, $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$screen = $this->get_current_screen_safe();
		if ( $screen && ! empty( $screen->is_block_editor ) ) {
			return;
		}

		if ( ! Seo_Bar::display_metabox( $post_type, 'wp_posts' ) ) {
			return;
		}

		$priority = apply_filters( 'surerank_seo_sidebar_box_priority', 'core', $post_type, $post );
		if ( ! in_array( $priority, [ 'high', 'core', 'default', 'low' ], true ) ) {
			$priority = 'core';
		}

		add_meta_box(
			'surerank_classic_seo_box',
			esc_html__( 'SureRank', 'surerank' ),
			[ $this, 'render_classic_sidebar_meta_box' ],
			$post_type,
			'side',
			$priority
		);
	}

	/**
	 * Pin the SureRank Classic Editor meta box to the second position in the
	 * sidebar, directly below the Publish ("submitdiv") box.
	 *
	 * Runs after every meta box is registered. Only reorders when the box is in
	 * the default 'core' priority bucket, so a custom
	 * surerank_seo_sidebar_box_priority value is left untouched. A user's own
	 * drag-and-drop order still wins, since that is restored at render time.
	 *
	 * @param string $post_type Current post type / screen id.
	 * @since 1.9.2
	 * @return void
	 */
	public function pin_classic_sidebar_meta_box( string $post_type ): void {
		global $wp_meta_boxes;

		if ( empty( $wp_meta_boxes[ $post_type ]['side']['core'] ) ) {
			return;
		}

		$core = $wp_meta_boxes[ $post_type ]['side']['core'];

		if ( ! isset( $core['surerank_classic_seo_box'], $core['submitdiv'] ) ) {
			return;
		}

		$surerank = $core['surerank_classic_seo_box'];
		unset( $core['surerank_classic_seo_box'] );

		$reordered = [];
		foreach ( $core as $id => $box ) {
			$reordered[ $id ] = $box;
			if ( 'submitdiv' === $id ) {
				$reordered['surerank_classic_seo_box'] = $surerank;
			}
		}

		$wp_meta_boxes[ $post_type ]['side']['core'] = $reordered; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reordering registered meta boxes requires updating this global.
	}

	/**
	 * Render the Classic Editor sidebar meta box content.
	 *
	 * @param \WP_Post $post Current post object.
	 * @return void
	 */
	public function render_classic_sidebar_meta_box( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$box_title = apply_filters( 'surerank_seo_sidebar_box_title', __( 'Manage your SEO', 'surerank' ), $post );
		$cta_label = apply_filters( 'surerank_seo_sidebar_cta_label', __( 'Click here', 'surerank' ), $post );
		?>
		<div class="surerank-classic-sidebar-box">
			<p class="surerank-classic-sidebar-box-title"><?php echo esc_html( $box_title ); ?></p>
			<div
				id="surerank-classic-seo-popup-trigger"
				class="surerank-root"
				data-surerank-variant="sidebar"
				data-surerank-cta-label="<?php echo esc_attr( $cta_label ); ?>"
			></div>
		</div>
		<?php
	}

	/**
	 * Enqueue SEO metabox front-end scripts
	 *
	 * @since 1.6.2
	 * @return void
	 */
	public function frontend_enqueue_scripts() {
		// Restrict to singular posts and taxonomy term archives — the only page
		// types where SureRank manages SEO metadata.
		if ( ! is_singular() && ! is_tax() && ! is_tag() && ! is_category() ) {
			return;
		}

		// Skip shared-URL contexts that fire wp_enqueue_scripts but should not render UI.
		if ( wp_doing_ajax()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| is_feed()
			|| is_embed()
			|| is_customize_preview()
			|| is_preview() ) {
			return;
		}

		// Skip third-party visual-builder previews that render on a public URL.
		if ( null !== filter_input( INPUT_GET, 'elementor-preview', FILTER_VALIDATE_INT )
			|| ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() )
			|| ( function_exists( 'et_fb_is_enabled' ) && et_fb_is_enabled() ) ) {
			return;
		}

		// Same gate as the editor metabox and seo-bar: manage_options by
		// default, Pro Role Manager grants surerank_content_setting via
		// the surerank_content_setting_access filter.
		$can_edit = apply_filters( 'surerank_content_setting_access', current_user_can( 'manage_options' ) );
		$post_id  = is_singular() ? (int) get_queried_object_id() : 0;

		/**
		 * Filters whether the current user can open the frontend SEO metabox.
		 *
		 * Pro can hook here to apply license or custom-role restrictions on top
		 * of the default capability check. On singular pages $post_id is the post
		 * ID; on taxonomy archives it is 0.
		 *
		 * @since 1.9.0
		 * @param bool $can_edit Whether the user passes the default capability check.
		 * @param int  $post_id  Post ID for singular views; 0 for taxonomy archives.
		 */
		if ( ! is_user_logged_in() || ! apply_filters( 'surerank_frontend_metabox_access', $can_edit, $post_id ) ) {
			return;
		}

		if ( ! is_admin_bar_showing() ) {
			return;
		}

		do_action( 'surerank_seo_popup_frontend_enqueue_scripts' );

		wp_enqueue_media();
		Dashboard::get_instance()->site_seo_check_enqueue_scripts();

		$context_data = $this->get_frontend_context_data();

		if ( ! $context_data ) {
			return;
		}

		// The seo-popup script bundle must never load on the frontend: its asset
		// dependencies include wp-editor, which registers the core/editor data
		// store in the browser. WooCommerce's Cart/Checkout blocks treat that
		// store's presence as "running inside the editor" and render their
		// preview products instead of the real cart. The frontend popup is
		// mounted by the front-end-meta-box entry, which only needs the
		// seo-popup localized data and stylesheet (cloned into its shadow root).
		$this->enqueue_vendor_and_common_assets();
		$this->enqueue_seo_popup_style();

		$this->build_assets_operations(
			'front-end-meta-box',
			[
				'hook'        => 'front-end-meta-box',
				'object_name' => 'front_end_meta_box',
				'data'        => [],
			]
		);

		$this->localize_script(
			'front-end-meta-box',
			'seo_popup',
			$this->get_localization_data( 'elementor', $context_data )
		);
	}

	/**
	 * Enqueue admin scripts
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function admin_enqueue_scripts() {
		wp_enqueue_media();

		$screen      = $this->get_current_screen_safe();
		$editor_type = self::detect_editor_type( $screen );

		if ( ! self::should_enqueue_scripts( $editor_type, $screen ) ) {
			return;
		}

		$context_data = $this->get_context_data( $editor_type, $screen );
		$this->enqueue_assets( $editor_type, $context_data );
	}

	/**
	 * Add admin bar menu
	 *
	 * @since 1.6.2
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 *
	 * @return void
	 */
	public function add_admin_bar_menu( $wp_admin_bar ) {
		// On the frontend the popup is mounted by the front-end-meta-box entry;
		// the seo-popup bundle itself is editor/admin-only (see frontend_enqueue_scripts).
		$popup_available = wp_script_is( $this->enqueue_prefix . '-seo-popup', 'enqueued' )
			|| wp_script_is( $this->enqueue_prefix . '-front-end-meta-box', 'enqueued' );
		Seo_Toolbar::get_instance()->add_nodes(
			$wp_admin_bar,
			$this->get_admin_bar_object_id(),
			$popup_available
		);
	}

	/**
	 * Register the REST route that returns the admin bar's current issue counts.
	 *
	 * @since 1.9.2
	 * @return void
	 */
	public function register_status_route() {
		register_rest_route(
			'surerank/v1',
			'/seo-bar-status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_seo_bar_status' ],
				'permission_callback' => static function () {
					return apply_filters( 'surerank_content_setting_access', current_user_can( 'manage_options' ) );
				},
				'args'                => [
					'post_id' => [
						'sanitize_callback' => 'absint',
						'default'           => 0,
					],
				],
			]
		);
	}

	/**
	 * REST callback: site + page issue counts for the admin bar.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @since 1.9.2
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_seo_bar_status( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		// Object-level guard: this route is only gated by the content-setting role,
		// so verify edit access to the requested post before disclosing its checks.
		// post_id 0 is the site-level bar (no post context); it exposes only
		// aggregate site counts, already gated by the permission_callback, so the
		// per-post guard applies only when an actual post is requested.
		if ( $post_id > 0 && ! Post::can_manage_post_seo( $post_id ) ) {
			return new \WP_Error(
				'surerank_forbidden',
				__( 'You are not allowed to view SEO checks for this post.', 'surerank' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return rest_ensure_response( Seo_Toolbar::get_instance()->get_status( $post_id ) );
	}

	/**
	 * Enqueue the tiny script that live-updates the admin-bar dots when checks change.
	 *
	 * @since 1.9.2
	 * @return void
	 */
	public function enqueue_admin_bar_live() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$handle = $this->enqueue_prefix . '-admin-bar-live';
		wp_enqueue_script(
			$handle,
			SURERANK_URL . 'inc/admin/assets/admin-bar-live.js',
			[ 'wp-api-fetch' ],
			SURERANK_VERSION,
			true
		);
		wp_localize_script(
			$handle,
			'surerank_admin_bar_live',
			[ 'post_id' => $this->get_admin_bar_object_id() ]
		);
	}

	/**
	 * Update seo values
	 *
	 * @param int $term_id Post ID.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function update_category_seo_values( $term_id ) {
		// Validate post ID.
		if ( empty( $term_id ) || ! is_int( $term_id ) ) {
			return;
		}

		// Update post seo values.
		$result = Update::term_meta( $term_id, [], [] );

		if ( is_wp_error( $result ) ) {
			return;
		}

		do_action( 'surerank_after_update_category_seo_values', $term_id );
	}

	/**
	 * Get keyword checks configuration
	 *
	 * @since 1.0.0
	 * @return array<string>
	 */
	public function keyword_checks() {
		return [
			'keyword_in_title',
			'keyword_in_description',
			'keyword_in_url',
			'keyword_in_content',
		];
	}

	/**
	 * Get page checks configuration
	 *
	 * @since 1.0.0
	 * @return array<string>
	 */
	public function page_checks() {
		return [
			'h2_subheadings',
			'image_alt_text',
			'media_present',
			'links_present',
			'url_length',
			'search_engine_title',
			'search_engine_description',
			'canonical_url',
			'all_links',
			'open_graph_tags',
			'broken_links',
		];
	}

	/**
	 * Detect the current editor type.
	 *
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return string Editor type.
	 */
	public static function detect_editor_type( $screen ): string {
		if ( class_exists( \Elementor\Plugin::class ) && \Elementor\Plugin::instance()->editor->is_edit_mode() ) {
			return 'elementor';
		}

		if ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() ) {
			return 'bricks';
		}

		// Listing pages (post/taxonomy/user list tables) use a dedicated context.
		if ( $screen && in_array( $screen->base, [ 'edit', 'edit-tags', 'users' ], true ) ) {
			return 'listing';
		}

		// User profile edit screens use a dedicated context.
		if ( $screen && in_array( $screen->base, [ 'profile', 'user-edit' ], true ) ) {
			return 'user';
		}

		// Allow integrations (e.g. Divi BFB) to override before the block-editor check.
		$filtered = apply_filters( 'surerank_detect_editor_type', 'classic', $screen );
		if ( 'classic' !== $filtered ) {
			return $filtered;
		}

		if ( $screen && $screen->is_block_editor ) {
			return 'block';
		}

		return 'classic';
	}

	/**
	 * Check if scripts should be enqueued.
	 *
	 * @param string          $editor_type Editor type.
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return bool True if scripts should be enqueued.
	 */
	public static function should_enqueue_scripts( string $editor_type, $screen ): bool {
		if ( $editor_type === 'bricks' ) {
			return true;
		}

		if ( ! $screen || empty( $screen->base ) || ! in_array( $screen->base, [ 'post', 'term', 'edit', 'edit-tags', 'profile', 'user-edit', 'users' ], true ) ) {
			return false;
		}

		if ( in_array( $screen->base, [ 'profile', 'user-edit', 'users' ], true ) ) {
			if ( ! Seo_Bar::display_metabox( '', 'wp_users' ) ) {
				return false;
			}

			// Don't ship the popup bundle to roles that can't use it (e.g.
			// subscribers on their own profile.php). Per-user edit_user is
			// still enforced per row in the users-list column and per request
			// by the REST object guard.
			return (bool) apply_filters( 'surerank_content_setting_access', current_user_can( 'manage_options' ) );
		}

		if ( 'post' === $screen->base && ! empty( $screen->post_type ) ) {
			if ( ! Seo_Bar::display_metabox( $screen->post_type, 'wp_posts' ) ) {
				return false;
			}
		}

		if ( 'term' === $screen->base && ! empty( $screen->taxonomy ) ) {
			if ( ! Seo_Bar::display_metabox( $screen->taxonomy, 'wp_terms' ) ) {
				return false;
			}
		}

		if ( 'edit' === $screen->base && ! empty( $screen->post_type ) ) {
			if ( ! Seo_Bar::display_metabox( $screen->post_type, 'wp_posts' ) ) {
				return false;
			}
		}

		if ( 'edit-tags' === $screen->base && ! empty( $screen->taxonomy ) ) {
			if ( ! Seo_Bar::display_metabox( $screen->taxonomy, 'wp_terms' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolve the current object id for the admin bar in both contexts.
	 *
	 * @since 1.9.2
	 * @return int Post id on a singular front-end view or a post edit screen, else 0.
	 */
	private function get_admin_bar_object_id() {
		if ( ! is_admin() ) {
			return is_singular() ? (int) get_queried_object_id() : 0;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || 'post' !== $screen->base ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen lookup, no state change.
		return isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
	}

	/**
	 * Get current screen safely.
	 *
	 * @return \WP_Screen|null
	 */
	private function get_current_screen_safe() {
		return function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	}

	/**
	 * Get context data for the current page.
	 *
	 * @param string          $editor_type Editor type.
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return array{post_data: array<string, mixed>, term_data: array<string, mixed>, user_data: array<string, mixed>, post_type: string, is_taxonomy: bool, is_user: bool, is_frontend?: bool} Context data.
	 */
	private function get_context_data( string $editor_type, $screen ): array {
		$post_data = $this->get_post_data( $editor_type, $screen );
		$term_data = $this->get_term_data( $screen );
		$user_data = $this->get_user_data( $screen );

		return [
			'post_data'   => $post_data,
			'term_data'   => $term_data,
			'user_data'   => $user_data,
			'post_type'   => $this->get_post_type( $editor_type, $screen ),
			'is_taxonomy' => $this->is_taxonomy( $editor_type, $screen ),
			'is_user'     => $this->is_user( $screen ),
		];
	}

	/**
	 * Get post data if on post edit screen.
	 *
	 * @param string          $editor_type Editor type.
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return array<string, mixed> Post data.
	 */
	private function get_post_data( string $editor_type, $screen ): array {
		if ( ( $screen && 'post' === $screen->base ) || $editor_type === 'bricks' ) {
			$post_id = get_the_ID();
			if ( ! $post_id ) {
				return [];
			}
			return [
				'post_id'     => $post_id,
				'editor_type' => $editor_type,
				'link'        => get_the_permalink( $post_id ),
			];
		}

		return [];
	}

	/**
	 * Get term data if on term edit screen.
	 *
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return array<string, mixed> Term data.
	 */
	private function get_term_data( $screen ): array {
		if ( ! $screen || 'term' !== $screen->base ) {
			return [];
		}

		global $tag_ID;

		$final_link = get_term_link( (int) $tag_ID );
		if ( is_wp_error( $final_link ) ) {
			return [];
		}

		$final_link = $this->process_category_link( $final_link, $tag_ID, $screen );

		return [
			'term_id' => $tag_ID,
			'link'    => $final_link,
		];
	}

	/**
	 * Get user data if on a user profile edit screen.
	 *
	 * @param \WP_Screen|null $screen Current screen object.
	 * @since 1.9.0
	 * @return array<string, mixed> User data.
	 */
	private function get_user_data( $screen ): array {
		if ( ! $screen || ! in_array( $screen->base, [ 'profile', 'user-edit' ], true ) ) {
			return [];
		}

		if ( 'user-edit' === $screen->base ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only context detection; capability enforced below and on save.
			$user_id = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
		} else {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			return [];
		}

		return [
			'user_id' => $user_id,
			'link'    => get_author_posts_url( $user_id ),
		];
	}

	/**
	 * Check if current context is a single-user edit screen (profile or user-edit).
	 *
	 * Intentionally excludes the users.php listing: no user_id exists there at
	 * load time — the seo-bar badge click handler sets is_user/user_id on the
	 * JS side when a specific user is selected.
	 *
	 * @param \WP_Screen|null $screen Current screen object.
	 * @since 1.9.0
	 * @return bool True if user context.
	 */
	private function is_user( $screen ): bool {
		return $screen && in_array( $screen->base, [ 'profile', 'user-edit' ], true );
	}

	/**
	 * Process category link if needed.
	 *
	 * @param string          $link Term link.
	 * @param int             $tag_ID Term ID.
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return string Processed link.
	 */
	private function process_category_link( string $link, int $tag_ID, $screen ): string {
		if ( $screen && 'category' === $screen->taxonomy && apply_filters( 'surerank_remove_category_base', false ) ) {
			$term = get_term( $tag_ID );
			if ( $term && ! is_wp_error( $term ) ) {
				return Crawl_Optimization::get_instance()->remove_category_base_from_links( $link, $term, $screen->taxonomy );
			}
		}

		return $link;
	}

	/**
	 * Get post type for current context.
	 *
	 * @param string          $editor_type Editor type.
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return string Post type.
	 */
	private function get_post_type( string $editor_type, $screen ): string {
		if ( $editor_type === 'bricks' ) {
			$post_id   = get_the_ID();
			$post_type = $post_id ? get_post_type( $post_id ) : false;
			return $post_type !== false ? $post_type : '';
		}

		if ( ! $screen ) {
			return '';
		}

		return ! empty( $screen->taxonomy ) ? $screen->taxonomy : $screen->post_type;
	}

	/**
	 * Check if current context is taxonomy.
	 *
	 * @param string          $editor_type Editor type.
	 * @param \WP_Screen|null $screen Current screen object.
	 * @return bool True if taxonomy context.
	 */
	private function is_taxonomy( string $editor_type, $screen ): bool {
		if ( $editor_type === 'bricks' ) {
			return false;
		}

		return $screen && ! empty( $screen->taxonomy );
	}

	/**
	 * Enqueue only the seo-popup stylesheet.
	 *
	 * Used on the frontend, where the popup markup (rendered by the
	 * front-end-meta-box entry inside a shadow root) still relies on the
	 * seo-popup styles, but the seo-popup script must not load because its
	 * wp-editor dependency breaks WooCommerce block hydration.
	 *
	 * @since 1.9.3
	 * @return void
	 */
	private function enqueue_seo_popup_style(): void {
		$asset_path = $this->build_path . 'seo-popup/index.asset.php';
		$version    = SURERANK_VERSION;

		if ( file_exists( $asset_path ) ) {
			$asset   = include $asset_path;
			$version = is_array( $asset ) && ! empty( $asset['version'] ) ? $asset['version'] : SURERANK_VERSION;
		}

		$this->style_operations( 'seo-popup', $this->build_url . 'seo-popup/style.css', [], $version );
		wp_style_add_data( $this->enqueue_prefix . '-seo-popup', 'rtl', 'replace' );
	}

	/**
	 * Enqueue assets for SEO popup.
	 *
	 * @param string                                                                                                                                                                              $editor_type Editor type.
	 * @param array{post_data: array<string, mixed>, term_data: array<string, mixed>, user_data?: array<string, mixed>, post_type: string, is_taxonomy: bool, is_user?: bool, is_frontend?: bool} $context_data Context data.
	 * @return void
	 */
	private function enqueue_assets( string $editor_type, array $context_data ): void {
		$this->enqueue_vendor_and_common_assets();

		$this->build_assets_operations(
			'seo-popup',
			[
				'hook'        => 'seo-popup',
				'object_name' => 'seo_popup',
				'data'        => $this->get_localization_data( $editor_type, $context_data ),
			]
		);
	}

	/**
	 * Build the localization payload shared by the seo-popup and
	 * front-end-meta-box entries (exposed to JS as surerank_seo_popup).
	 *
	 * @param string                                                                                                                                                                              $editor_type Editor type.
	 * @param array{post_data: array<string, mixed>, term_data: array<string, mixed>, user_data?: array<string, mixed>, post_type: string, is_taxonomy: bool, is_user?: bool, is_frontend?: bool} $context_data Context data.
	 * @since 1.9.3
	 * @return array<string, mixed>
	 */
	private function get_localization_data( string $editor_type, array $context_data ): array {
		return array_merge(
			[
				'admin_assets_url'         => SURERANK_URL . 'inc/admin/assets',
				'site_icon_url'            => get_site_icon_url( 16 ),
				'editor_type'              => $editor_type,
				'post_type'                => $context_data['post_type'],
				'is_taxonomy'              => $context_data['is_taxonomy'],
				'is_user'                  => $context_data['is_user'] ?? false,
				'description_length'       => Get::description_length(),
				'title_length'             => Get::title_length(),
				'keyword_checks'           => $this->keyword_checks(),
				'page_checks'              => $this->page_checks(),
				'image_seo'                => Image_Seo::get_instance()->status(),
				'generate_alt_with_ai'     => (bool) Settings::get( 'generate_alt_with_ai' ),
				'is_frontend'              => $context_data['is_frontend'] ?? false,
				'broken_link_ignored_urls' => Get::option( 'surerank_broken_link_ignored_urls', [] ),
				'active_cache_plugins'     => Migrations::is_cache_plugin_active(),
			],
			$context_data['post_data'],
			$context_data['term_data'],
			$context_data['user_data'] ?? [],
			$this->get_indexing_status_localization( $context_data )
		);
	}

	/**
	 * Get frontend context data.
	 *
	 * @return array{post_data: array<string, mixed>, term_data: array<string, mixed>, post_type: string, is_taxonomy: bool, is_frontend: bool}|false Context data or false if invalid.
	 */
	private function get_frontend_context_data() {
		$post_data   = [];
		$term_data   = [];
		$post_type   = '';
		$is_taxonomy = false;

		if ( is_singular() ) {
			$post_id = get_the_ID();
			if ( ! $post_id ) {
				return false;
			}
			$post_type = get_post_type( $post_id );
			if ( ! $post_type ) {
				return false;
			}
			$post_data = [
				'post_id'     => $post_id,
				'editor_type' => apply_filters( 'surerank_frontend_editor_type', 'classic' ),
				'link'        => get_the_permalink( $post_id ),
			];
		} elseif ( is_tax() || is_tag() || is_category() ) {
			$object = get_queried_object();
			if ( ! $object instanceof \WP_Term ) {
				return false;
			}
			$term_link = get_term_link( $object );
			if ( is_wp_error( $term_link ) ) {
				return false;
			}
			$term_data   = [
				'term_id' => $object->term_id,
				'link'    => $term_link,
			];
			$post_type   = $object->taxonomy;
			$is_taxonomy = true;
		} else {
			return false;
		}

		return [
			'post_data'   => $post_data,
			'term_data'   => $term_data,
			'post_type'   => $post_type,
			'is_taxonomy' => $is_taxonomy,
			'is_frontend' => true,
		];
	}

	/**
	 * Build the indexing-status slice of the localized SEO popup data.
	 *
	 * The pill mounts inside the popup header and needs four things at
	 * boot: whether GSC is connected, whether a site property is selected,
	 * whether that selected property matches the current WordPress site,
	 * and the last cached inspection result for the current post or term.
	 * Returning the cached value lets the pill paint on first frame
	 * without a REST round-trip. The cache is suppressed when the GSC
	 * property doesn't match this site so a previous property's result
	 * can't bleed through.
	 *
	 * @param array<string, mixed> $context_data Result of get_context_data().
	 * @return array<string, mixed>
	 * @since 1.7.5
	 */
	private function get_indexing_status_localization( array $context_data ): array {
		$is_connected   = (bool) GoogleSearchConsoleController::get_instance()->get_auth_status();
		$selected_site  = (string) GoogleSearchConsoleAuth::get_instance()->get_credentials( 'site_url' );
		$has_site       = '' !== $selected_site;
		$is_matching    = $is_connected && $has_site && Url_Inspection::selected_site_matches_current();
		$indexing_cache = null;

		if ( $is_matching ) {
			$post_id = isset( $context_data['post_data']['post_id'] )
				? absint( $context_data['post_data']['post_id'] )
				: 0;
			$term_id = isset( $context_data['term_data']['term_id'] )
				? absint( $context_data['term_data']['term_id'] )
				: 0;

			if ( $term_id ) {
				$cached = get_term_meta( $term_id, Url_Inspection::META_KEY, true );
			} elseif ( $post_id ) {
				$cached = get_post_meta( $post_id, Url_Inspection::META_KEY, true );
			} else {
				$cached = null;
			}

			$indexing_cache = is_array( $cached ) && ! empty( $cached ) ? $cached : null;
		}

		return [
			'is_gsc_connected'      => $is_connected,
			'has_gsc_site_selected' => $has_site,
			'is_gsc_site_matching'  => $is_matching,
			'indexing_status'       => $indexing_cache,
			'indexing_fresh_ttl'    => Url_Inspection::FRESH_TTL,
		];
	}

}
