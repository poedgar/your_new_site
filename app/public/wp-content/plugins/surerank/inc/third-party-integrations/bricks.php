<?php
/**
 * Third Party Plugins class - Bricks
 *
 * Handles Bricks Plugin related compatibility.
 *
 * @package SureRank\Inc\ThirdPartyIntegrations
 */

namespace SureRank\Inc\ThirdPartyIntegrations;

use SureRank\Inc\Admin\Dashboard;
use SureRank\Inc\Admin\Seo_Popup;
use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Traits\Loop_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Bricks
 *
 * Handles Bricks Plugin related compatibility.
 */
class Bricks {
	use Get_Instance;
	use Loop_Context;

	/**
	 * Constructor
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		if ( ! defined( 'BRICKS_VERSION' ) ) {
			return;
		}

		// Runs in admin/save context too — not limited to the visual builder.
		add_filter( 'surerank_post_analyzer_content', [ $this, 'process_bricks_content_for_analyzer' ], 10, 2 );
		add_filter( 'surerank_meta_variable_post_content', [ $this, 'process_bricks_content_for_meta' ], 10, 2 );

		if ( ! function_exists( 'bricks_is_builder_main' ) || ! bricks_is_builder_main() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', [ $this, 'register_script' ], 9999 );
		add_action( 'wp_enqueue_scripts', [ Dashboard::get_instance(), 'site_seo_check_enqueue_scripts' ], 999 );
		add_filter( 'surerank_globals_localization_vars', [ $this, 'add_localization_vars' ] );
	}

	/**
	 * Replace empty post_content with HTML extracted from Bricks element data.
	 *
	 * Bricks stores page content in the `_bricks_page_content_2` post meta as a flat
	 * array of element objects. WordPress's `post_content` is typically empty for
	 * Bricks-built posts, so the SEO analyzer must read the meta instead.
	 *
	 * Bricks saves its meta before calling wp_update_post(), so by the time
	 * wp_after_insert_post fires the data is already persisted and readable here.
	 *
	 * @param string   $content Post content (usually empty for Bricks posts).
	 * @param \WP_Post $post    Post object.
	 * @return string HTML extracted from Bricks elements, or the original $content.
	 * @since 1.7.0
	 */
	public function process_bricks_content_for_analyzer( string $content, \WP_Post $post ): string {
		// Skip posts that have been switched back to the WordPress block editor.
		$editor_mode = get_post_meta( $post->ID, BRICKS_DB_EDITOR_MODE, true );
		if ( 'WordPress' === $editor_mode ) {
			return $content;
		}

		$elements = get_post_meta( $post->ID, BRICKS_DB_PAGE_CONTENT, true );
		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return $content;
		}

		return $this->extract_bricks_html( $elements, $post );
	}

	/**
	 * Frontend meta-variable handler: cheap text-only extraction from Bricks elements.
	 *
	 * Avoids \Bricks\Frontend::render_data() because rendering query loops (e.g. sc_product)
	 * on every wp_head triggers expensive recursion. Only plain-text settings are read.
	 *
	 * @param string   $content Post content (usually empty for Bricks posts).
	 * @param \WP_Post $post    Post object.
	 * @return string Plain text harvested from Bricks element settings, or the original $content.
	 * @since 1.7.4
	 */
	public function process_bricks_content_for_meta( string $content, \WP_Post $post ): string {
		$editor_mode = get_post_meta( $post->ID, BRICKS_DB_EDITOR_MODE, true );
		if ( 'WordPress' === $editor_mode ) {
			return $content;
		}

		$elements = get_post_meta( $post->ID, BRICKS_DB_PAGE_CONTENT, true );
		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return $content;
		}

		return $this->extract_text_only( $elements );
	}

	/**
	 * Add localization variables for Bricks.
	 *
	 * @param array<string,mixed> $vars Localization variables.
	 * @return array<string,mixed> Updated localization variables.
	 * @since 1.1.0
	 */
	public function add_localization_vars( array $vars ) {
		return array_merge(
			$vars,
			[
				'is_bricks' => true,
			]
		);
	}

	/**
	 * Register Script
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function register_script() {
		Seo_Popup::get_instance()->admin_enqueue_scripts();

		$asset_path = SURERANK_DIR . 'build/bricks/index.asset.php';
		$asset_info = file_exists( $asset_path ) ? include $asset_path : [
			'dependencies' => [ 'jquery', 'wp-data' ],
			'version'      => SURERANK_VERSION,
		];

		wp_register_script( 'surerank-bricks', SURERANK_URL . 'build/bricks/index.js', $asset_info['dependencies'], $asset_info['version'], false );
		wp_enqueue_script( 'surerank-bricks' );
	}

	/**
	 * Render Bricks elements to HTML using Bricks' own rendering pipeline.
	 *
	 * Frontend::render_data() returns a string (it does not echo), so no output
	 * buffering is needed. Database::$page_data defaults to ['preview_or_post_id' => 0],
	 * so calling this in admin/save context is safe — no fatal errors occur.
	 *
	 * Bricks query loops call the_post() while rendering, and in a request with no
	 * current post (e.g. a translation webhook saving the post) their reset does
	 * not put the loop globals back. The analyzer runs on wp_after_insert_post, so
	 * a leaked global post would be seen by every later save hook. Set the global
	 * post to the analysed post for the render and restore the full loop context
	 * afterwards, the same guard the block render and Divi paths use.
	 *
	 * @param array<int, array<string, mixed>> $elements Flat Bricks elements array.
	 * @param \WP_Post                         $post     Post being analysed.
	 * @return string Rendered HTML string.
	 * @since 1.7.0
	 */
	private function extract_bricks_html( array $elements, \WP_Post $post ): string {
		if ( ! class_exists( '\Bricks\Frontend' ) || ! method_exists( '\Bricks\Frontend', 'render_data' ) ) {
			return '';
		}

		$loop_context = $this->get_loop_context();

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily set post context for the Bricks render; restored in finally.

		try {
			return \Bricks\Frontend::render_data( $elements, 'content' ) ?? '';
		} finally {
			$this->restore_loop_context( $loop_context );
		}
	}

	/**
	 * Walk Bricks elements and concatenate plain-text settings without rendering.
	 *
	 * @param array<int, array<string, mixed>> $elements Flat Bricks elements array.
	 * @return string Concatenated plain text from text/heading/description/label/content settings.
	 * @since 1.7.4
	 */
	private function extract_text_only( array $elements ): string {
		$text_keys = [ 'text', 'heading', 'description', 'label', 'content' ];
		$pieces    = [];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || empty( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
				continue;
			}

			foreach ( $text_keys as $key ) {
				if ( ! isset( $element['settings'][ $key ] ) || ! is_string( $element['settings'][ $key ] ) ) {
					continue;
				}

				$value = preg_replace( '/\{[a-z0-9_:\-\.]+\}/i', '', $element['settings'][ $key ] );
				if ( ! is_string( $value ) ) {
					continue;
				}

				$value = trim( wp_strip_all_tags( $value ) );
				if ( '' !== $value ) {
					$pieces[] = $value;
				}
			}
		}

		return trim( implode( ' ', $pieces ) );
	}
}
