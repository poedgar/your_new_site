<?php
/**
 * WPML Translation Provider
 *
 * Handles translation data retrieval for WPML plugin.
 *
 * @package surerank
 * @since 1.6.3
 */

namespace SureRank\Inc\ThirdPartyIntegrations\Multilingual\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SureRank\Inc\ThirdPartyIntegrations\Multilingual\Locale_Formatter;
use SureRank\Inc\ThirdPartyIntegrations\Multilingual\Provider;

/**
 * WPML Provider Class
 *
 * @since 1.6.3
 */
class Wpml implements Provider {

	/**
	 * Get translation URLs for a single post
	 *
	 * @since 1.6.3
	 * @param int    $post_id Post ID.
	 * @param string $post_type Post type.
	 * @return array<string, array{url: string, locale: string}>
	 */
	public function get_translations( int $post_id, string $post_type ): array {
		// Non-translatable post types bypass wpml_object_id's missing-translation
		// logic and would report every language as translated, so skip them.
		if ( ! apply_filters( 'wpml_is_translated_post_type', null, $post_type ) ) {
			return [];
		}

		$languages    = $this->get_active_languages();
		$translations = [];

		// WPML does not translate the page_on_front option inside a language
		// switch, so front-page translations must resolve to the language home
		// URL directly (same special case WPML core uses in get_ls_languages()).
		$front_page_id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;

		foreach ( $languages as $lang_code => $language_data ) {
			// $return_original_if_missing must be false: with true, WPML returns the
			// original post when no translation exists, so missing languages would be
			// annotated with the original URL.
			$translated_id = apply_filters( 'wpml_object_id', $post_id, $post_type, false, $lang_code );

			if ( ! $translated_id ) {
				continue;
			}

			$url = $front_page_id && $post_id === $front_page_id
				? $this->resolve_in_language( $lang_code, static fn() => home_url( '/' ) )
				: $this->resolve_in_language( $lang_code, static fn() => get_permalink( $translated_id ) );

			if ( ! $url ) {
				continue;
			}

			$translations[ $lang_code ] = [
				// The language switch resolves directory prefix and translated
				// slug; wpml_permalink (non-absolute) additionally maps the host
				// on domain-per-language setups and is a no-op otherwise.
				'url'    => apply_filters( 'wpml_permalink', $url, $lang_code ),
				'locale' => Locale_Formatter::to_bcp47( $language_data['default_locale'] ?? $lang_code ),
			];
		}

		return $translations;
	}

	/**
	 * Batch fetch translations for multiple posts
	 *
	 * @since 1.6.3
	 * @param array<int> $post_ids Array of post IDs.
	 * @param string     $post_type Post type.
	 * @return array<int, array<string, array{url: string, locale: string}>>
	 */
	public function get_translations_batch( array $post_ids, string $post_type ): array {
		$results = [];

		foreach ( $post_ids as $post_id ) {
			$results[ $post_id ] = $this->get_translations( $post_id, $post_type );
		}

		return $results;
	}

	/**
	 * Get default site language
	 *
	 * @since 1.6.3
	 * @return string
	 */
	public function get_default_language(): string {
		return apply_filters( 'wpml_default_language', '' );
	}

	/**
	 * Check if translation is available for post
	 *
	 * @since 1.6.3
	 * @param int    $post_id Post ID.
	 * @param string $language Language code.
	 * @return bool
	 */
	public function is_translation_available( int $post_id, string $language ): bool {
		// $return_original_if_missing = true is intentional here: the
		// $translated_id !== $post_id check below detects the fallback.
		$translated_id = apply_filters( 'wpml_object_id', $post_id, get_post_type( $post_id ), true, $language );
		return ! empty( $translated_id ) && $translated_id !== $post_id;
	}

	/**
	 * Get translated post ID
	 *
	 * @since 1.6.3
	 * @param int    $post_id Post ID.
	 * @param string $language Language code.
	 * @return int|null
	 */
	public function get_translated_post_id( int $post_id, string $language ): ?int {
		// $return_original_if_missing = true is intentional here: callers
		// (Translation_Manager) compare the result against the original ID.
		$translated_id = apply_filters( 'wpml_object_id', $post_id, get_post_type( $post_id ), true, $language );
		return $translated_id ? (int) $translated_id : null;
	}

	/**
	 * Get the language of a post
	 *
	 * @since 1.6.3
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function get_post_language( int $post_id ): string {
		$language_info = apply_filters( 'wpml_post_language_details', null, $post_id );

		if ( ! $language_info || ! isset( $language_info['language_code'] ) ) {
			return '';
		}

		return (string) $language_info['language_code'];
	}

	/**
	 * Get translation URLs for a single term
	 *
	 * @since 1.6.4
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string, array{url: string, locale: string}>
	 */
	public function get_term_translations( int $term_id, string $taxonomy ): array {
		// Non-translatable taxonomies bypass wpml_object_id's missing-translation
		// logic and would report every language as translated, so skip them.
		if ( ! apply_filters( 'wpml_is_translated_taxonomy', null, $taxonomy ) ) {
			return [];
		}

		$languages    = $this->get_active_languages();
		$translations = [];

		foreach ( $languages as $lang_code => $language_data ) {
			// $return_original_if_missing must be false: with true, WPML returns the
			// original term when no translation exists, so missing languages would be
			// annotated with the original URL. It also guarantees the language switch
			// below only happens for a confirmed translation, since WPML remaps term
			// IDs by the current language.
			$translated_id = apply_filters( 'wpml_object_id', $term_id, $taxonomy, false, $lang_code );

			if ( ! $translated_id ) {
				continue;
			}

			$url = $this->resolve_in_language( $lang_code, static fn() => get_term_link( (int) $translated_id, $taxonomy ) );

			if ( is_wp_error( $url ) || ! $url ) {
				continue;
			}

			$translations[ $lang_code ] = [
				// The language switch resolves directory prefix and translated
				// slug; wpml_permalink (non-absolute) additionally maps the host
				// on domain-per-language setups and is a no-op otherwise.
				'url'    => apply_filters( 'wpml_permalink', $url, $lang_code ),
				'locale' => Locale_Formatter::to_bcp47( $language_data['default_locale'] ?? $lang_code ),
			];
		}

		return $translations;
	}

	/**
	 * Get the language of a term
	 *
	 * @since 1.6.4
	 * @param int $term_id Term ID.
	 * @return string
	 */
	public function get_term_language( int $term_id ): string {
		$term = get_term( $term_id );

		if ( ! $term instanceof \WP_Term ) {
			return '';
		}

		$element_type  = apply_filters( 'wpml_element_type', $term->taxonomy );
		$language_info = apply_filters(
			'wpml_element_language_details',
			null,
			[
				'element_id'   => $term_id,
				'element_type' => $element_type,
			]
		);

		if ( ! $language_info || ! isset( $language_info->language_code ) ) {
			return '';
		}

		return (string) $language_info->language_code;
	}

	/**
	 * Get active languages
	 *
	 * @since 1.6.3
	 * @return array<string, array<string, mixed>>
	 */
	private function get_active_languages(): array {
		return apply_filters( 'wpml_active_languages', [], [ 'skip_missing' => true ] );
	}

	/**
	 * Run a callback with WPML switched to the given language.
	 *
	 * Permalink and term-link functions resolve URLs in the current request
	 * language, not the language of the object. The wpml_permalink filter
	 * cannot repair the site root, so the translated front page would keep
	 * the default-language URL without this switch.
	 *
	 * @since 1.10.1
	 * @param string   $lang_code Target language code.
	 * @param callable $callback Callback that resolves the URL.
	 * @return mixed Whatever the callback returns.
	 */
	private function resolve_in_language( string $lang_code, callable $callback ) {
		$current_language = apply_filters( 'wpml_current_language', null );

		if ( $current_language === $lang_code ) {
			return $callback();
		}

		do_action( 'wpml_switch_language', $lang_code );

		try {
			return $callback();
		} finally {
			// Restore even if a permalink filter throws, so the rest of the
			// request does not run in the wrong language.
			do_action( 'wpml_switch_language', $current_language );
		}
	}

}
