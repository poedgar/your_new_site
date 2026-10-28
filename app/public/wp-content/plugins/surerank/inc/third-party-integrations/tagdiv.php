<?php
/**
 * TagDiv Composer Integration
 *
 * Handles TagDiv Composer (Newspaper/Newsmag theme) related compatibility.
 *
 * @package SureRank\Inc\ThirdPartyIntegrations
 */

namespace SureRank\Inc\ThirdPartyIntegrations;

use SureRank\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * TagDiv Composer Integration Class
 *
 * TagDiv Composer stores pages as proprietary shortcodes inside post_content
 * ([tdc_zone][vc_row]…), with visible text base64-encoded in shortcode
 * attributes. The theme registers all block shortcodes unconditionally
 * (admin, AJAX and REST contexts included), so rendering them with
 * do_shortcode() at analysis time produces the real frontend HTML.
 */
class Tagdiv {

	use Get_Instance;

	/**
	 * Constructor
	 *
	 * @since 1.10.0
	 */
	public function __construct() {
		if ( ! $this->is_active() ) {
			return;
		}

		add_filter( 'surerank_post_analyzer_content', [ $this, 'process_tagdiv_content' ], 10, 2 );

		// Render TagDiv markup for %content% / %excerpt% meta variables so
		// generated descriptions contain real prose instead of shortcode soup.
		add_filter( 'surerank_meta_variable_post_content', [ $this, 'process_tagdiv_content' ], 10, 2 );

		// Flag TagDiv pages for the SEO popup JS so it uses server-side checks
		// instead of live-analyzing the raw shortcode content in the editor.
		add_filter( 'surerank_globals_localization_vars', [ $this, 'add_localization_vars' ] );
	}

	/**
	 * Add is_tagdiv flag to surerank_globals localization.
	 *
	 * Mirrors Bricks/Divi add_localization_vars() so JS can detect the active
	 * builder via window.surerank_globals.is_tagdiv. Only set when the post
	 * being edited contains TagDiv Composer markup, so regular posts keep the
	 * live editor checks.
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $vars Existing localization variables.
	 * @return array<string, mixed>
	 */
	public function add_localization_vars( array $vars ): array {
		$post = get_post();

		if ( $post instanceof \WP_Post && false !== strpos( (string) $post->post_content, '[tdc_zone' ) ) {
			$vars['is_tagdiv'] = true;
		}

		return $vars;
	}

	/**
	 * Check if a TagDiv theme (Newspaper/Newsmag) is active.
	 *
	 * The td_global_blocks class is the wp_booster class that registers and renders
	 * every TagDiv block shortcode; its presence guarantees do_shortcode()
	 * can resolve TagDiv markup in the current request.
	 *
	 * @since 1.10.0
	 * @return bool
	 */
	public function is_active(): bool {
		return class_exists( 'td_global_blocks' );
	}

	/**
	 * Render TagDiv Composer content for the SEO analyzer.
	 *
	 * Only posts whose content contains the [tdc_zone wrapper (written by
	 * TagDiv Composer on every save) are processed; everything else is
	 * returned untouched. Runs at analysis time and when %content% / %excerpt%
	 * meta variables resolve — the same contexts the theme renders anyway.
	 *
	 * @since 1.10.0
	 * @param string   $content Post content.
	 * @param \WP_Post $post    Post object.
	 * @return string Rendered HTML for TagDiv pages, or the original content.
	 */
	public function process_tagdiv_content( string $content, $post ): string {
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		if ( false === strpos( $content, '[tdc_zone' ) ) {
			return $content;
		}

		try {
			$rendered = do_shortcode( $content );
		} catch ( \Throwable $e ) {
			// Graceful fallback — never break analyzer execution.
			return $content;
		}

		return is_string( $rendered ) && '' !== $rendered ? $rendered : $content;
	}
}
