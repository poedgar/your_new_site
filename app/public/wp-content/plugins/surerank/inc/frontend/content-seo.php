<?php
/**
 * Unified Content SEO Enhancement Module
 *
 * Handles both image and link SEO enhancements in a single pass for optimal performance.
 *
 * @package surerank
 * @since 1.5.0
 */

namespace SureRank\Inc\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SureRank\Inc\Traits\Get_Instance;

/**
 * Unified content SEO enhancement handler
 *
 * @since 1.5.0
 */
class Content_Seo {

	use Get_Instance;

	/**
	 * Image SEO processor
	 *
	 * @var Image_Seo
	 */
	private $image_processor;

	/**
	 * Link SEO processor
	 *
	 * @var Link_Seo
	 */
	private $link_processor;

	/**
	 * WordPress hooks configuration
	 *
	 * @var array<string, array<string, int>>
	 */
	private $hooks;

	/**
	 * Initialize content enhancement
	 *
	 * @since 1.5.0
	 */
	public function __construct() {
		$this->load_processors();
		$this->initialize_hooks_config();
		$this->register_hooks();
	}

	/**
	 * Process images only
	 *
	 * @param string   $content Content to enhance.
	 * @param int|null $post_id Post ID context.
	 * @return string Enhanced content
	 * @since 1.5.0
	 */
	public function enhance_images_only( $content, $post_id = null ) {
		if ( empty( $content ) || strpos( $content, '<img' ) === false ) {
			return $content;
		}

		if ( $this->should_skip_content( $content ) ) {
			return $content;
		}

		[ $protected, $blocks ] = $this->protect_blocks( $content );
		$image_tags             = $this->extract_tags( $protected, 'img' );

		if ( empty( $image_tags ) ) {
			return $content;
		}

		return $this->restore_blocks( $this->process_content( $protected, $image_tags, [], $post_id ), $blocks );
	}

	/**
	 * Process links only
	 *
	 * @param string   $content Content to enhance.
	 * @param int|null $post_id Post ID context.
	 * @return string Enhanced content
	 * @since 1.5.0
	 */
	public function enhance_links_only( $content, $post_id = null ) {
		if ( empty( $content ) || strpos( $content, '<a' ) === false ) {
			return $content;
		}

		if ( $this->should_skip_content( $content ) ) {
			return $content;
		}

		[ $protected, $blocks ] = $this->protect_blocks( $content );
		$link_tags              = $this->extract_tags( $protected, 'a' );

		if ( empty( $link_tags ) ) {
			return $content;
		}

		return $this->restore_blocks( $this->process_content( $protected, [], $link_tags, $post_id ), $blocks );
	}

	/**
	 * Unified content enhancement (both images and links)
	 *
	 * @param string   $content Content to enhance.
	 * @param int|null $post_id Post ID context.
	 * @return string Enhanced content
	 * @since 1.5.0
	 */
	public function enhance_content( $content, $post_id = null ) {
		if ( empty( $content ) ) {
			return $content;
		}

		$has_images = strpos( $content, '<img' ) !== false;
		$has_links  = strpos( $content, '<a' ) !== false;

		if ( ! $has_images && ! $has_links ) {
			return $content;
		}

		if ( $this->should_skip_content( $content ) ) {
			return $content;
		}

		[ $protected, $blocks ] = $this->protect_blocks( $content );
		$image_tags             = $has_images ? $this->extract_tags( $protected, 'img' ) : [];
		$link_tags              = $has_links ? $this->extract_tags( $protected, 'a' ) : [];

		if ( empty( $image_tags ) && empty( $link_tags ) ) {
			return $content;
		}

		return $this->restore_blocks( $this->process_content( $protected, $image_tags, $link_tags, $post_id ), $blocks );
	}

	/**
	 * Load sub-processors
	 *
	 * @since 1.5.0
	 */
	private function load_processors(): void {
		$this->image_processor = new Image_Seo();
		$this->link_processor  = new Link_Seo();
	}

	/**
	 * Initialize hooks configuration
	 *
	 * @since 1.5.0
	 */
	private function initialize_hooks_config(): void {
		$this->hooks = [
			'global' => [
				'the_content' => 11,
			],
			'image'  => [
				'post_thumbnail_html' => 11,
				'woocommerce_single_product_image_thumbnail_html' => 11,
			],
			'link'   => [
				'widget_text'  => 11,
				'comment_text' => 11,
			],
		];
	}

	/**
	 * Register WordPress hooks based on enabled features
	 *
	 * @since 1.5.0
	 */
	private function register_hooks(): void {
		$has_images = $this->image_processor->is_enabled();
		$has_links  = $this->link_processor->is_processing_enabled();

		if ( ! $has_images && ! $has_links ) {
			return;
		}

		if ( $has_images && ! $has_links ) {
			$this->register_image_only_hooks();
		} elseif ( $has_links && ! $has_images ) {
			$this->register_link_only_hooks();
		} else {
			$this->register_unified_hooks();
		}
	}

	/**
	 * Register hooks for image-only processing
	 *
	 * @since 1.5.0
	 */
	private function register_image_only_hooks(): void {
		$filters = array_merge(
			$this->hooks['global'],
			$this->hooks['image']
		);

		foreach ( $filters as $hook => $priority ) {
			add_filter( $hook, [ $this, 'enhance_images_only' ], $priority, 2 );
		}
	}

	/**
	 * Register hooks for link-only processing
	 *
	 * @since 1.5.0
	 */
	private function register_link_only_hooks(): void {
		$filters = array_merge(
			$this->hooks['global'],
			$this->hooks['link']
		);

		foreach ( $filters as $hook => $priority ) {
			add_filter( $hook, [ $this, 'enhance_links_only' ], $priority, 2 );
		}
	}

	/**
	 * Register hooks for unified processing
	 *
	 * @since 1.5.0
	 */
	private function register_unified_hooks(): void {
		$filters = array_merge(
			$this->hooks['global'],
			$this->hooks['image'],
			$this->hooks['link']
		);

		foreach ( $filters as $hook => $priority ) {
			add_filter( $hook, [ $this, 'enhance_content' ], $priority, 2 );
		}
	}

	/**
	 * Decide whether the_content rewriting must be skipped for this content.
	 *
	 * Some page builders inject fully-rendered, JS-hydrated markup into
	 * the_content. Regex-rewriting their <img>/<a> tags corrupts that markup and
	 * the affected block renders blank (Divi 5 React hydration, Breakdance Woo
	 * cart). We also skip WooCommerce cart/checkout pages outright, since those
	 * carry hydrated block markup regardless of the builder in use.
	 *
	 * @param string $content Rendered content.
	 * @return bool
	 * @since 1.9.3
	 */
	private function should_skip_content( $content ): bool {
		// Cheapest first: the cart/checkout bail needs no content scan, so it
		// short-circuits before the md5-backed builder detectors below.
		if ( function_exists( 'is_cart' ) && function_exists( 'is_checkout' )
			&& ( is_cart() || is_checkout() ) ) {
			return true;
		}

		if ( $this->is_divi5_rendered_content( $content ) ) {
			return true;
		}

		return $this->is_breakdance_rendered_content( $content );
	}

	/**
	 * Detect Breakdance rendered output. Breakdance hooks the_content at
	 * PHP_INT_MIN and returns its full page HTML wrapped in <div class="breakdance">
	 * (Render\renderer.php), stripping core content filters (wpautop,
	 * wp_filter_content_tags) because they corrupt that output. SureRank's filter
	 * is not stripped, so rewriting the emitted <img>/<a> tags breaks hydrated
	 * blocks (e.g. the WooCommerce cart block goes blank). We bail entirely.
	 *
	 * Detection markers, all absent in normal post content:
	 *   - bde-section CSS class. Every Breakdance layout's root element is a
	 *     Section that emits the bde-section class (renderer.php element classes),
	 *     so this matches the common theme-enabled case where no wrapper is added.
	 *   - class='breakdance' / class="breakdance" wrapper, only emitted by
	 *     maybeWrapInMainTag() in theme-disabled / zero-theme mode.
	 *   - data-breakdance-foreign-document (header/footer documents).
	 *
	 * Result is memoized per content to avoid repeated strpos scans.
	 *
	 * @param string $content Rendered content.
	 * @return bool
	 * @since 1.9.3
	 */
	private function is_breakdance_rendered_content( $content ): bool {
		static $cache = [];

		$key = md5( $content );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$is_breakdance = strpos( $content, 'bde-section' ) !== false
			|| strpos( $content, "class='breakdance'" ) !== false
			|| strpos( $content, 'class="breakdance"' ) !== false
			|| strpos( $content, 'data-breakdance-foreign-document' ) !== false;

		$cache[ $key ] = (bool) apply_filters( 'surerank_is_breakdance_content', $is_breakdance, $content );
		return $cache[ $key ];
	}

	/**
	 * Detect Divi 5 rendered output. Modifying that HTML breaks React hydration
	 * (the first tab panel goes invisible during the post-mismatch re-render), so
	 * we bail entirely.
	 *
	 * Detection uses three layers:
	 *
	 * 1. CSS class markers in the rendered HTML. Divi 5 SectionModule always emits
	 *    exactly one of: et_block_section (block layout), et_flex_section (flex —
	 *    the default, see SectionModule.php:175 `?? 'flex'`), or et_grid_section
	 *    (grid). et_flex_module covers flex-layout row modules. All are absent in
	 *    Divi 4 shortcode output.
	 *
	 * 2. Raw post_content fallback — Divi 5 stores <!-- wp:divi/ --> block comments.
	 *    Catches layouts where the rendered HTML lacks section wrappers (rare), but
	 *    fails for Theme Builder templates where the layout lives in a separate Divi
	 *    Layout post and $post->post_content is empty.
	 *
	 * `data-et-multi-view` and `et-builder-5` only appear in theme-level HTML
	 * (header/footer), not in content passed to the_content filter.
	 * Result is memoized per content to avoid repeated strpos scans.
	 *
	 * @param string $content Rendered content.
	 * @return bool
	 * @since 1.7.4
	 */
	private function is_divi5_rendered_content( $content ): bool {
		static $cache = [];

		$key = md5( $content );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		// Every Divi 5 section emits exactly one of these three layout classes.
		// et_flex_section is the default (SectionModule.php layout attr defaults
		// to 'flex'), so this check now catches all standard Divi 5 layouts.
		$is_divi5 = strpos( $content, 'et_block_section' ) !== false
			|| strpos( $content, 'et_flex_section' ) !== false
			|| strpos( $content, 'et_grid_section' ) !== false
			|| strpos( $content, 'et_flex_module' ) !== false;

		// Fallback for edge cases (e.g. sectionless layouts): check the raw post
		// content for Divi 5 block comments. This does NOT cover Theme Builder
		// templates — those live in a separate Divi Layout post, leaving
		// $post->post_content empty — but the CSS check above handles them.
		if ( ! $is_divi5 ) {
			global $post;
			$is_divi5 = $post instanceof \WP_Post
				&& strpos( $post->post_content, '<!-- wp:divi/' ) !== false;
		}

		$cache[ $key ] = (bool) apply_filters( 'surerank_is_divi5_content', $is_divi5, $content );
		return $cache[ $key ];
	}

	/**
	 * Stash <script>/<style> blocks behind salted placeholders so subsequent
	 * str_replace passes can't corrupt image/link strings embedded in JSON
	 * hydration data. The salt prevents user content from colliding with our
	 * marker (which would mis-restore the block).
	 *
	 * @param string $content Raw content.
	 * @return array{0: string, 1: array<string, string>} Protected content + placeholder→block map.
	 * @since 1.7.4
	 */
	private function protect_blocks( $content ): array {
		$blocks    = [];
		$token     = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );
		$protected = preg_replace_callback(
			'/<(script|style)[^>]*?>.*?<\/\1>/si',
			static function ( $matches ) use ( &$blocks, $token ) {
				$placeholder            = sprintf( '<!--SURERANK_BLOCK_%s_%d-->', $token, count( $blocks ) );
				$blocks[ $placeholder ] = $matches[0];
				return $placeholder;
			},
			$content
		);

		return [ $protected !== null ? $protected : $content, $blocks ];
	}

	/**
	 * Restore the blocks stashed by protect_blocks().
	 *
	 * @param string                $content Protected content.
	 * @param array<string, string> $blocks  Placeholder → block map.
	 * @return string
	 * @since 1.7.4
	 */
	private function restore_blocks( $content, $blocks ): string {
		foreach ( $blocks as $placeholder => $block ) {
			$content = str_replace( $placeholder, $block, $content );
		}

		return $content;
	}

	/**
	 * Extract tags using unified regex
	 *
	 * @param string $content Clean content.
	 * @param string $tag_type Either 'img' or 'a'.
	 * @return array<string> Matching tags
	 * @since 1.5.0
	 */
	private function extract_tags( $content, $tag_type ): array {
		if ( $tag_type === 'img' ) {
			return $this->image_processor->extract_processable_images( $content );
		}
		if ( $tag_type === 'a' ) {
			return $this->link_processor->extract_processable_links( $content );
		}

		return [];
	}

	/**
	 * Process content with extracted tags
	 *
	 * @param string        $content Original content.
	 * @param array<string> $image_tags Image tags to process.
	 * @param array<string> $link_tags Link tags to process.
	 * @param int|null      $post_id Post context.
	 * @return string Enhanced content
	 * @since 1.5.0
	 */
	private function process_content( $content, $image_tags, $link_tags, $post_id ): string {
		$processed_content = $content;

		if ( ! empty( $image_tags ) ) {
			$processed_content = $this->image_processor->process_images( $processed_content, $image_tags, $post_id );
		}

		if ( ! empty( $link_tags ) ) {
			$processed_content = $this->link_processor->process_links( $processed_content, $link_tags, $post_id );
		}

		return $processed_content;
	}
}
