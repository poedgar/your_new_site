<?php
/**
 * Link SEO Processor
 *
 * Handles link-specific SEO enhancement logic.
 *
 * @package surerank
 * @since 1.5.0
 */

namespace SureRank\Inc\Frontend;

use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Traits\Tag_Attribute_Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link SEO processor
 *
 * @since 1.5.0
 */
class Link_Seo {

	use Get_Instance;
	use Tag_Attribute_Helpers;

	/**
	 * Check if link enhancement is enabled
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function is_enabled(): bool {
		return apply_filters( 'surerank_auto_add_nofollow_external_links', false );
	}

	/**
	 * Check if external links should open in new tab.
	 *
	 * @return bool
	 * @since 1.7.5
	 */
	public function is_new_tab_enabled(): bool {
		return apply_filters( 'surerank_open_external_links_in_new_tab', false );
	}

	/**
	 * Whether any link enhancement (nofollow or new-tab) is enabled.
	 *
	 * Used by the hook-registration gate. Distinct from is_enabled(), which
	 * remains nofollow-specific so per-rel decisions don't get falsely flipped
	 * when only the new-tab filter is on.
	 *
	 * @return bool
	 * @since 1.7.5
	 */
	public function is_processing_enabled(): bool {
		return $this->is_enabled() || $this->is_new_tab_enabled();
	}

	/**
	 * Extract links that need processing
	 *
	 * @param string $content Clean content.
	 * @return array<string> Link tags that need enhancement
	 * @since 1.5.0
	 */
	public function extract_processable_links( $content ): array {
		return $this->extract_external_links( $content );
	}

	/**
	 * Process link tags in content
	 *
	 * @param string        $content Original content.
	 * @param array<string> $link_tags Link tags to process.
	 * @param int|null      $post_id Post context.
	 * @return string Enhanced content
	 * @since 1.5.0
	 */
	public function process_links( $content, $link_tags, $post_id ): string {
		$context = $this->build_processing_context( $post_id );
		return $this->enhance_link_tags( $content, $link_tags, $context );
	}

	/**
	 * Extract external links that need enhancement
	 *
	 * @param string $content Content to search.
	 * @return array<string> External link tags
	 * @since 1.5.0
	 */
	private function extract_external_links( $content ): array {
		if ( ! $this->is_enabled() && ! $this->is_new_tab_enabled() ) {
			return [];
		}

		/**
		 * Extract all anchor tags with href attributes from content
		 *
		 * Regex pattern breakdown:
		 * <a                           : Matches literal "<a"
		 * [^>]*                        : Match any characters except ">" (stay within opening tag)
		 * href=                        : Match literal "href="
		 * ["\']                        : Match opening quote (single or double)
		 * ([^"\']*)                    : Capture group 1 - Match and capture href value (any chars except quotes)
		 * ["\']                        : Match closing quote (single or double)
		 * [^>]*                        : Match remaining tag attributes until closing ">"
		 * >                            : Match closing bracket of opening tag
		 * i                            : Case-insensitive flag
		 *
		 * Examples of what this WILL match:
		 * - <a href="https://example.com">Link</a>
		 * - <A HREF='http://site.org' class="external">Link</A>
		 * - <a class="btn" href="mailto:test@example.com" id="contact">Email</a>
		 * - <a target="_blank" href="https://google.com" rel="noopener">Google</a>
		 *
		 * Captured groups:
		 * [0] => Full anchor tag: <a href="https://example.com" class="link">
		 * [1] => href value: https://example.com
		 *
		 * @param string $content The HTML content to search for anchor tags
		 * @param array $matches Output array containing matched anchor tags and href values
		 * @return int Number of matches found
		 *
		 * @see https://www.php.net/manual/en/reference.pcre.pattern.syntax.php
		 * @since 1.5.0
		 */
		preg_match_all( '/<a[^>]*href=["\']([^"\']*)["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER );

		$external_links   = [];
		$site_domain      = $this->get_site_domain();
		$excluded_domains = $this->get_excluded_domains();

		foreach ( $matches as $match ) {
			$full_tag = $match[0];
			$url      = $match[1];

			if ( ! $this->is_external_link( $url, $site_domain ) || $this->is_excluded_domain( $url, $excluded_domains ) ) {
				continue;
			}

			$attributes = $this->parse_link_attributes( $full_tag );
			if ( $this->needs_any_enhancement( $attributes ) ) {
				$external_links[] = $full_tag;
			}
		}

		return array_unique( $external_links );
	}

	/**
	 * Check if URL is external link
	 *
	 * @param string $url URL to check.
	 * @param string $site_domain Current site domain.
	 * @return bool True if external
	 * @since 1.5.0
	 */
	private function is_external_link( $url, $site_domain ): bool {
		if ( empty( $url ) || $url[0] === '#' ) {
			return false;
		}

		if ( $url[0] === '/' ) {
			return false;
		}

		$url_domain = wp_parse_url( $url, PHP_URL_HOST );
		return $url_domain && $url_domain !== $site_domain;
	}

	/**
	 * Check if domain is excluded
	 *
	 * @param string        $url URL to check.
	 * @param array<string> $excluded_domains Excluded domains.
	 * @return bool True if excluded
	 * @since 1.5.0
	 */
	private function is_excluded_domain( $url, $excluded_domains ): bool {
		if ( empty( $excluded_domains ) ) {
			return false;
		}

		$url_domain = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $url_domain ) {
			return false;
		}

		foreach ( $excluded_domains as $excluded_domain ) {
			if ( $url_domain === trim( $excluded_domain ) || strpos( $url_domain, '.' . trim( $excluded_domain ) ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get current site domain
	 *
	 * @return string Site domain
	 * @since 1.5.0
	 */
	private function get_site_domain(): string {
		$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
		return $site_domain ? $site_domain : '';
	}

	/**
	 * Get excluded domains list
	 *
	 * @return array<string> Excluded domains
	 * @since 1.5.0
	 */
	private function get_excluded_domains(): array {
		$excluded_domains = apply_filters( 'surerank_nofollow_excluded_domains', [] );

		if ( is_string( $excluded_domains ) ) {
			$excluded_domains = array_map( 'trim', explode( ',', $excluded_domains ) );
		}

		return is_array( $excluded_domains ) ? $excluded_domains : [];
	}

	/**
	 * Build processing context object
	 *
	 * @param int|null $post_id Post ID.
	 * @return object{post_id: int|null, site_domain: string} Context data
	 * @since 1.5.0
	 */
	private function build_processing_context( $post_id ): object {
		return (object) [
			'post_id'     => $post_id,
			'site_domain' => $this->get_site_domain(),
		];
	}

	/**
	 * Enhance individual link tags
	 *
	 * @param string                                         $content Original content.
	 * @param array<string>                                  $links Link tag array.
	 * @param object{post_id: int|null, site_domain: string} $context Processing context.
	 * @return string Enhanced content
	 * @since 1.5.0
	 */
	private function enhance_link_tags( $content, $links, $context ): string {
		foreach ( $links as $original_tag ) {
			$enhanced_tag = $this->enhance_single_link( $original_tag, $context );

			if ( $enhanced_tag !== $original_tag ) {
				$content = str_replace( $original_tag, $enhanced_tag, $content );
			}
		}

		return $content;
	}

	/**
	 * Enhance single link tag
	 *
	 * @param string                                         $tag Original link tag.
	 * @param object{post_id: int|null, site_domain: string} $context Processing context.
	 * @return string Enhanced tag
	 * @since 1.5.0
	 */
	private function enhance_single_link( $tag, $context ): string {
		$attributes = $this->parse_link_attributes( $tag );

		if ( empty( $attributes ) ) {
			return $tag;
		}

		$enhancement_needed = $this->calculate_needed_enhancements( $attributes );
		$enhancement_needed = apply_filters( 'surerank_link_seo_enhancements', $enhancement_needed, $attributes, $context );

		if ( ! $enhancement_needed ) {
			return $tag;
		}

		return $this->apply_enhancements( $tag, $attributes );
	}

	/**
	 * Parse attributes from link tag
	 *
	 * @param string $tag Link tag.
	 * @return array<string, string> Parsed attributes
	 * @since 1.5.0
	 */
	private function parse_link_attributes( $tag ): array {
		$attributes = [];

		/**
		 * Parse all attributes from an HTML anchor tag
		 *
		 * Regex pattern breakdown:
		 * ([a-zA-Z_:][a-zA-Z0-9\-_.:]*)   : Capture group 1 - Attribute name
		 *   [a-zA-Z_:]                     : First char: letter, underscore, or colon
		 *   [a-zA-Z0-9\-_.]*               : Remaining chars: alphanumeric, hyphen, dot, underscore, colon
		 * =                                : Literal equals sign
		 * ["\']                            : Opening quote (single or double)
		 * ([^"\']*)                        : Capture group 2 - Attribute value (any chars except quotes)
		 * ["\']                            : Closing quote (single or double)
		 *
		 * Examples of what this WILL match:
		 * - href="https://example.com"
		 * - class='btn external'
		 * - target="_blank"
		 * - rel="nofollow noopener"
		 * - data-toggle="modal"
		 * - xml:lang="en"                  (namespaced attributes)
		 *
		 * Examples of what this will NOT match:
		 * - href=https://example.com       (no quotes)
		 * - disabled                       (boolean attribute without value)
		 * - 123invalid="value"             (attribute name starts with number)
		 *
		 * Captured groups per match:
		 * [0] => Full match: href="https://example.com"
		 * [1] => Attribute name: href
		 * [2] => Attribute value: https://example.com
		 *
		 * @param string $tag The anchor tag to parse
		 * @param array $matches Output array containing all matched attributes
		 * @return int Number of matches found
		 *
		 * @see https://www.php.net/manual/en/reference.pcre.pattern.syntax.php
		 * @since 1.5.0
		 */
		if ( preg_match_all( '/([a-zA-Z_:][a-zA-Z0-9\-_.:]*)=["\']([^"\']*)["\']/', $tag, $matches, PREG_SET_ORDER ) ) {
			/**
			 * Process matches to build attribute array:
			 * [0] => href="https://example.com"  // Full match
			 * [1] => href                         // Attribute name
			 * [2] => https://example.com          // Attribute value
			 */
			foreach ( $matches as $match ) {
				$attributes[ $match[1] ] = $match[2];
			}
		}

		return $attributes;
	}

	/**
	 * Calculate which enhancements are needed
	 *
	 * @param array<string, string> $attributes Current attributes.
	 * @return bool Whether enhancement is needed
	 * @since 1.5.0
	 */
	private function calculate_needed_enhancements( $attributes ): bool {
		if ( ! isset( $attributes['href'] ) ) {
			return false;
		}

		$site_domain      = $this->get_site_domain();
		$excluded_domains = $this->get_excluded_domains();

		if ( ! $this->is_external_link( $attributes['href'], $site_domain ) || $this->is_excluded_domain( $attributes['href'], $excluded_domains ) ) {
			return false;
		}

		return $this->needs_any_enhancement( $attributes );
	}

	/**
	 * Check if any enhancement is needed for this link.
	 *
	 * @param array<string, string> $attributes Link attributes.
	 * @return bool True if any enhancement is needed
	 * @since 1.7.5
	 */
	private function needs_any_enhancement( $attributes ): bool {
		$current_rel_values = $this->get_current_rel_values( $attributes );
		$current_target     = strtolower( trim( $attributes['target'] ?? '' ) );

		if ( $this->is_enabled() && ! in_array( 'nofollow', $current_rel_values, true ) ) {
			return true;
		}

		return $this->is_new_tab_enabled() && '_blank' !== $current_target;
	}

	/**
	 * Get current rel attribute values as array
	 *
	 * @param array<string, string> $attributes Link attributes.
	 * @return array<string> Current rel values
	 * @since 1.5.0
	 */
	private function get_current_rel_values( $attributes ): array {
		if ( ! isset( $attributes['rel'] ) ) {
			return [];
		}

		return array_map( 'trim', explode( ' ', $attributes['rel'] ) );
	}

	/**
	 * Apply rel enhancements to the original tag in place.
	 *
	 * Mirrors Image_Seo::apply_enhancements — writes only the attributes that
	 * changed, leaving boolean attributes (download, inert, hidden) and
	 * data-* attrs the regex parser does not capture exactly as authored.
	 *
	 * @param string                $tag        Original anchor tag.
	 * @param array<string, string> $attributes Parsed attributes.
	 * @return string Enhanced link tag
	 * @since 1.5.0
	 */
	private function apply_enhancements( $tag, $attributes ): string {
		$before = $attributes;

		if ( $this->is_new_tab_enabled() ) {
			$attributes['target'] = '_blank';
		}

		$rel_values = isset( $attributes['rel'] )
			? array_map( 'trim', explode( ' ', $attributes['rel'] ) )
			: [];

		$rel_values = $this->apply_rel_enhancements( $rel_values );
		$rel_value  = implode( ' ', array_filter( $rel_values ) );

		if ( $rel_value !== '' ) {
			$attributes['rel'] = $rel_value;
		}

		/**
		 * Filter the post-enhancement attribute set. Mutations are diffed
		 * against the pre-filter snapshot and written back to the original
		 * tag in place — booleans/data-* attrs are not stripped.
		 *
		 * @var array<string, string> $attributes
		 */
		$attributes = apply_filters( 'surerank_link_seo_enhanced_attributes', $attributes );

		foreach ( $attributes as $name => $value ) {
			if ( ( $before[ $name ] ?? null ) === $value ) {
				continue;
			}
			$tag = array_key_exists( $name, $before )
				? $this->replace_attribute_value( $tag, (string) $name, (string) $value )
				: $this->inject_attribute( $tag, (string) $name, (string) $value );
		}

		return $tag;
	}

	/**
	 * Apply all enabled rel attribute enhancements
	 *
	 * @param array<string> $rel_values Current rel values.
	 * @return array<string> Enhanced rel values
	 * @since 1.5.0
	 */
	private function apply_rel_enhancements( $rel_values ): array {
		if ( $this->is_enabled() && ! in_array( 'nofollow', $rel_values, true ) ) {
			$rel_values[] = 'nofollow';
		}

		if ( $this->is_new_tab_enabled() ) {
			foreach ( [ 'noopener', 'noreferrer' ] as $rel_token ) {
				if ( ! in_array( $rel_token, $rel_values, true ) ) {
					$rel_values[] = $rel_token;
				}
			}
		}

		/**
		 * Filter to allow custom rel attribute enhancements
		 *
		 * @param array<string> $rel_values Current rel values
		 */
		return apply_filters( 'surerank_link_seo_rel_enhancements', $rel_values );
	}

}
