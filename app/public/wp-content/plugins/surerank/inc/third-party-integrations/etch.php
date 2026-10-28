<?php
/**
 * Third Party Plugins class - Etch
 *
 * Handles Etch page builder compatibility.
 *
 * @package SureRank\Inc\ThirdPartyIntegrations
 */

namespace SureRank\Inc\ThirdPartyIntegrations;

use SureRank\Inc\Admin\Dashboard;
use SureRank\Inc\Admin\Seo_Popup;
use SureRank\Inc\Frontend\Image_Seo;
use SureRank\Inc\Functions\Get;
use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Traits\Loop_Context;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Etch
 *
 * Etch is a native Gutenberg block builder, but keeps the semantics in block
 * attributes rather than the saved HTML:
 *
 *   <!-- wp:etch/element {"tag":"h1"} -->
 *   <!-- wp:etch/text {"content":"Heading"} /-->
 *   <!-- /wp:etch/element -->
 *
 * There is no literal <h1> in post_content, so raw reads see no headings,
 * images or links. do_blocks() renders it correctly.
 *
 * @since 1.10.2
 */
class Etch {

	use Get_Instance;
	use Loop_Context;

	/**
	 * Marker for Etch block markup. Etch stores no "built with Etch" meta.
	 *
	 * @since 1.10.2
	 */
	private const CONTENT_MARKER = '<!-- wp:etch/';

	/**
	 * Per-request render cache, keyed by post and content hash.
	 *
	 * @since 1.10.2
	 * @var array<string, string>
	 */
	private $rendered_content_cache = [];

	/**
	 * Constructor
	 */
	public function __construct() {
		if ( ! defined( 'ETCH_PLUGIN_DIR' ) ) {
			return;
		}

		/** Content filters run in every context, so no is_admin() guard. */
		add_filter( 'surerank_post_analyzer_content', [ $this, 'process_etch_content' ], 10, 2 );
		add_filter( 'surerank_meta_variable_post_content', [ $this, 'process_etch_content' ], 10, 2 );

		/**
		 * The builder renders on a front-end URL whose template calls wp_head(),
		 * so assets enqueue normally. Not etch/canvas/enqueue_assets: that hook
		 * harvests the queue into the canvas iframe, never the builder chrome.
		 */
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_builder_assets' ], 20 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_globals' ], 999 );
	}

	/**
	 * Enqueue the SEO popup and the Etch builder bridge script.
	 *
	 * @since 1.10.2
	 * @return void
	 */
	public function enqueue_builder_assets(): void {
		if ( ! $this->is_builder_context() ) {
			return;
		}

		$post_id = $this->get_edited_post_id();

		if ( 0 === $post_id ) {
			return;
		}

		$seo_popup = Seo_Popup::get_instance();

		wp_enqueue_media();
		$seo_popup->enqueue_vendor_and_common_assets();

		/**
		 * Fires before SureRank's popup bundle is queued on the Etch builder.
		 *
		 * Ahead of the free bundle on purpose: the free bundle latches whether
		 * Pro is active when it evaluates, so queueing Pro second shows Pro
		 * features as locked. Divi and Breakdance order themselves the same way.
		 *
		 * @since 1.10.2
		 * @param int $post_id Post being edited in the builder.
		 */
		do_action( 'surerank_etch_editor_enqueue', $post_id );

		$seo_popup->build_assets_operations(
			'seo-popup',
			[
				'hook'        => 'seo-popup',
				'object_name' => 'seo_popup',
				'data'        => [
					'admin_assets_url'   => SURERANK_URL . 'inc/admin/assets',
					'site_icon_url'      => get_site_icon_url( 16 ),
					'editor_type'        => 'etch',
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

		/** On a front end context seo-popup does not self-mount; this entry owns the shadow root. */
		$seo_popup->build_assets_operations(
			'front-end-meta-box',
			[
				'hook'        => 'front-end-meta-box',
				'object_name' => 'front_end_meta_box',
				'data'        => [],
			]
		);

		$this->register_builder_script();
	}

	/**
	 * Enqueue surerank_globals on the builder page.
	 *
	 * Priority 999 so jQuery, which the globals localize onto, is queued first.
	 *
	 * @since 1.10.2
	 * @return void
	 */
	public function enqueue_globals(): void {
		/** Same gates as the assets, so nothing ships on an unauthorized request. */
		if ( ! $this->is_builder_context() || 0 === $this->get_edited_post_id() ) {
			return;
		}

		add_filter( 'surerank_globals_localization_vars', [ $this, 'add_localization_vars' ] );

		Dashboard::get_instance()->site_seo_check_enqueue_scripts();
	}

	/**
	 * Flag the Etch builder for the SEO popup's JavaScript.
	 *
	 * @since 1.10.2
	 * @param array<string, mixed> $vars Existing localization variables.
	 * @return array<string, mixed> Variables with the Etch flag added.
	 */
	public function add_localization_vars( $vars ) {
		return array_merge( $vars, [ 'is_etch' => true ] );
	}

	/**
	 * Render Etch block markup into HTML for SureRank to read.
	 *
	 * Serves both filters. keyword_in_content reads this output directly, and
	 * %content% would otherwise resolve to an empty string. Rendering rather
	 * than reading attributes is what resolves Etch's dynamic expressions.
	 *
	 * @since 1.10.2
	 * @param string $content Raw post content.
	 * @param mixed  $post    Post being processed.
	 * @return string Rendered HTML, or the original content when not an Etch post.
	 */
	public function process_etch_content( string $content, $post ): string {
		if ( ! $post instanceof WP_Post || ! $this->is_etch_content( $content ) ) {
			return $content;
		}

		$cache_key = $post->ID . ':' . md5( $content );

		if ( ! isset( $this->rendered_content_cache[ $cache_key ] ) ) {
			$this->rendered_content_cache[ $cache_key ] = $this->render_etch_content( $content, $post );
		}

		return $this->rendered_content_cache[ $cache_key ];
	}

	/**
	 * Skip Etch SVG blocks during SureRank's render pass.
	 *
	 * Etch's svg loader can fire wp_remote_get() on a cold transient, and Etch
	 * busts that cache on every save. An svg carries no SEO-relevant markup.
	 *
	 * @since 1.10.2
	 * @param string|null          $pre_render   Short-circuited render, or null to continue.
	 * @param array<string, mixed> $parsed_block Parsed block being rendered.
	 * @return string|null Empty string to skip the block, otherwise the value unchanged.
	 */
	public function skip_svg_block( $pre_render, $parsed_block ) {
		if ( is_array( $parsed_block ) && isset( $parsed_block['blockName'] ) && 'etch/svg' === $parsed_block['blockName'] ) {
			return '';
		}

		return $pre_render;
	}

	/**
	 * Whether the current request is the Etch builder screen.
	 *
	 * Mirrors Etch's own mount condition.
	 *
	 * @since 1.10.2
	 * @return bool True on the builder screen.
	 */
	private function is_builder_context(): bool {
		if ( is_admin() || ! is_front_page() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of Etch's own builder URL flag; no state is changed.
		$mode = isset( $_GET['etch'] ) ? sanitize_text_field( wp_unslash( $_GET['etch'] ) ) : '';

		if ( 'magic' !== $mode ) {
			return false;
		}

		/** Filtered, not the raw capability, so Pro's Role Manager is honoured. */
		return (bool) apply_filters( 'surerank_content_setting_access', current_user_can( 'manage_options' ) );
	}

	/**
	 * Resolve the post the builder is editing.
	 *
	 * Etch never validates post_id server side, so verify it here.
	 *
	 * @since 1.10.2
	 * @return int Post ID, or 0 when it is missing or not editable.
	 */
	private function get_edited_post_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of Etch's own builder URL argument; capability is verified below.
		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;

		if ( 0 === $post_id || ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * Register and enqueue the Etch builder bridge script.
	 *
	 * @since 1.10.2
	 * @return void
	 */
	private function register_builder_script(): void {
		$asset_file = SURERANK_DIR . 'build/etch/index.asset.php';

		$asset = file_exists( $asset_file )
			? include $asset_file
			: [
				'dependencies' => [],
				'version'      => SURERANK_VERSION,
			];

		$dependencies = is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : [];

		/** The bridge reads SureRank's store, which seo-popup registers. */
		if ( ! in_array( 'surerank-seo-popup', $dependencies, true ) ) {
			$dependencies[] = 'surerank-seo-popup';
		}

		wp_register_script(
			'surerank-etch',
			SURERANK_URL . 'build/etch/index.js',
			$dependencies,
			$asset['version'] ?? SURERANK_VERSION,
			true
		);

		wp_enqueue_script( 'surerank-etch' );
	}

	/**
	 * Whether a content string carries Etch block markup.
	 *
	 * @since 1.10.2
	 * @param string $content Post content.
	 * @return bool True when the content was built with Etch.
	 */
	private function is_etch_content( string $content ): bool {
		return strpos( $content, self::CONTENT_MARKER ) !== false;
	}

	/**
	 * Run do_blocks() over Etch content under the post's own context.
	 *
	 * Swaps the global $post, and snapshots the whole loop context because Etch
	 * loop blocks call the_post() and restore the main query rather than this.
	 *
	 * @since 1.10.2
	 * @param string  $content     Raw post content.
	 * @param WP_Post $post_object Post being processed.
	 * @return string Rendered HTML, or the original content if rendering produced nothing.
	 */
	private function render_etch_content( string $content, WP_Post $post_object ): string {
		if ( ! function_exists( 'do_blocks' ) ) {
			return $content;
		}

		$loop_context = $this->get_loop_context();

		global $post;

		$post = $post_object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily set post context for block rendering; restored in finally.

		add_filter( 'pre_render_block', [ $this, 'skip_svg_block' ], 10, 2 );

		$html = '';

		try {
			$html = do_blocks( $content );
		} finally {
			remove_filter( 'pre_render_block', [ $this, 'skip_svg_block' ], 10 );
			$this->restore_loop_context( $loop_context );
		}

		return '' !== trim( $html ) ? $html : $content;
	}

}
