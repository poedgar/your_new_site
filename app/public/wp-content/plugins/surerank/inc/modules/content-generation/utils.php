<?php
/**
 * Content Generation Utils
 *
 * Utils module class for handling content generation functionality.
 *
 * @package SureRank\Inc\Modules\Content_Generation
 * @since 1.4.2
 */

namespace SureRank\Inc\Modules\Content_Generation;

use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Functions\API_Utils;
use SureRank\Inc\Modules\Fix_Seo_Checks\Page as FixSeoPage;
use SureRank\Inc\ThirdPartyIntegrations\Multilingual\Post_Language_Resolver;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Utils class
 *
 * Main module class for content generation functionality.
 * Extends API_Utils for shared API functionality.
 */
class Utils extends API_Utils {

	use Get_Instance;

	/**
	 * Get API types.
	 *
	 * @return array<int,string> Array of API types.
	 * @since 1.4.2
	 */
	public function get_api_types() {
		return apply_filters(
			'surerank_content_generation_types',
			[
				'page_title',
				'home_page_title',
				'page_description',
				'home_page_description',
				'home_page_social_title',
				'home_page_social_description',
				'social_title',
				'social_description',
				'combined_meta',
				'site_tag_line',
				'page_url_slug',
			]
		);
	}

	/**
	 * Prepare inputs for content generation.
	 *
	 * @param int|null $id Post or term ID (optional).
	 * @param bool     $is_taxonomy Whether the ID is for a taxonomy term.
	 * @return array<string,string> Array of inputs for content generation.
	 * @since 1.4.2
	 */
	public function prepare_content_inputs( $id = null, $is_taxonomy = false ) {
		$title   = '';
		$content = '';
		$type    = '';

		if ( ! empty( $id ) ) {
			if ( $is_taxonomy ) {
				$term = get_term( $id );
				if ( $term && ! is_wp_error( $term ) ) {
					$title   = $term->name;
					$content = $term->description;

					$taxonomy_obj = get_taxonomy( $term->taxonomy );
					if ( $taxonomy_obj ) {
						$readable_name = $taxonomy_obj->labels->singular_name ?? $taxonomy_obj->label;
						$type          = sprintf( 'Taxonomy - %s - %s', $readable_name, $term->taxonomy );
					}
				}
			} else {
				$post = get_post( $id );
				if ( $post ) {
					$title   = get_the_title( $id );
					$content = $post->post_content;

					$post_type_obj = get_post_type_object( $post->post_type );
					if ( $post_type_obj ) {
						$readable_name = $post_type_obj->labels->singular_name ?? $post_type_obj->label;
						$type          = sprintf( 'Post Type - %s - %s', $readable_name, $post->post_type );
					}
				}
			}
		}
		// Limit to 500 words (wp_trim_words handles tag stripping and whitespace).
		if ( ! empty( $content ) ) {
			$content = wp_trim_words( $content, 500, '' );
		}

		$language = $is_taxonomy
			? Post_Language_Resolver::for_term_id( (int) $id )
			: Post_Language_Resolver::for_id( (int) $id );

		return apply_filters(
			'surerank_content_generation_inputs',
			[
				'site_name'     => get_bloginfo( 'name' ),
				'site_tagline'  => get_bloginfo( 'description' ),
				'page_title'    => $title,
				'page_content'  => $content,
				'focus_keyword' => $this->get_focus_keyword( $id, $is_taxonomy ),
				'type'          => $type,
				'language'      => $language,
			]
		);
	}

	/**
	 * Get focus keyword for the post or term.
	 *
	 * @param int|null $post_id Post ID or Term ID.
	 * @param bool     $is_taxonomy Whether it's a taxonomy term.
	 * @return string Focus keyword.
	 * @since 1.4.2
	 */
	private function get_focus_keyword( $post_id = null, $is_taxonomy = false ) {
		if ( empty( $post_id ) ) {
			return '';
		}

		if ( $is_taxonomy ) {
			$term_meta = get_term_meta( $post_id, 'surerank_settings_general', true );
			return $term_meta['focus_keyword'] ?? '';
		}

		$post_meta = get_post_meta( $post_id, 'surerank_settings_general', true );
		return $post_meta['focus_keyword'] ?? '';
	}

	/**
	 * Generate content for a single ID
	 *
	 * @param int    $id Post or term ID.
	 * @param string $content_type Type of content to generate.
	 * @param bool   $is_taxonomy Whether the ID is for a taxonomy term.
	 * @return string|WP_Error|null Generated content or error on failure.
	 * @since 1.10.0
	 */
	public function generate_content_for_id( $id, $content_type, $is_taxonomy ) {
		$inputs             = $this->prepare_content_inputs( $id, $is_taxonomy );
		$content_controller = Controller::get_instance();

		$content = $content_controller->generate_content( $inputs, $content_type );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		/**
		 * Ensure proper typing for content variable.
		 *
		 * @var string|array<mixed> $content
		 */
		return is_array( $content ) && isset( $content[0] ) ? $content[0] : $content;
	}

	/**
	 * Apply generated content using the fix SEO page functionality
	 *
	 * @param int    $id Post or term ID.
	 * @param string $content_type Type of content.
	 * @param string $content Generated content.
	 * @param bool   $is_taxonomy Whether the ID is for a taxonomy term.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 * @since 1.10.0
	 */
	public function apply_generated_content( $id, $content_type, $content, $is_taxonomy ) {
		$input_key = $this->get_input_key_for_content_type( $content_type );

		if ( empty( $input_key ) ) {
			/* translators: %s: content type */
			return new WP_Error( 'invalid_content_type', sprintf( __( 'Unknown content type: %s', 'surerank' ), $content_type ) );
		}

		$result = FixSeoPage::get_instance()->use_me( $input_key, $content, $id, $is_taxonomy );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result['status'] ) {
			return new WP_Error( 'apply_failed', $result['message'] );
		}

		return true;
	}

	/**
	 * Validate that an ID exists in the database
	 *
	 * @param int  $id Post or term ID.
	 * @param bool $is_taxonomy Whether the ID is for a taxonomy term.
	 * @return bool
	 * @since 1.10.0
	 */
	public function validate_id_exists( $id, $is_taxonomy ) {
		if ( $is_taxonomy ) {
			$term = get_term( $id );
			return $term && ! is_wp_error( $term );
		}

		return get_post( $id ) !== null;
	}

	/**
	 * Parse comma-separated IDs into an array
	 *
	 * @param string $ids_string Comma-separated IDs.
	 * @return array<int> Array of integer IDs.
	 * @since 1.10.0
	 */
	public function parse_ids( $ids_string ) {
		$ids = explode( ',', $ids_string );
		$ids = array_map( 'trim', $ids );
		$ids = array_map( 'absint', $ids );
		$ids = array_filter( $ids );
		return array_unique( $ids );
	}

	/**
	 * Check if action is a valid content generation action
	 *
	 * @param string $action The action to check.
	 * @return bool True if valid content generation action.
	 * @since 1.10.0
	 */
	public static function is_valid_content_generation_action( $action ) {
		return in_array( $action, [ 'surerank_generate_page_title', 'surerank_generate_page_description' ], true );
	}

	/**
	 * Get input key for content type
	 *
	 * @param string $content_type Content type.
	 * @return string Input key or empty string if invalid.
	 * @since 1.10.0
	 */
	private function get_input_key_for_content_type( $content_type ) {
		switch ( $content_type ) {
			case 'page_title':
				return 'search_engine_title';
			case 'page_description':
				return 'search_engine_description';
			default:
				return '';
		}
	}
}
