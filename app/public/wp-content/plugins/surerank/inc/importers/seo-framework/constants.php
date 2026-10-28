<?php
/**
 * The SEO Framework Constants
 *
 * Defines constants and utility functions for The SEO Framework plugin importer.
 *
 * @package SureRank\Inc\Importers
 * @since   1.10.0
 */

namespace SureRank\Inc\Importers\SeoFramework;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Constants
 */
class Constants {
	/**
	 * Human-readable plugin name.
	 */
	public const PLUGIN_NAME = 'The SEO Framework';

	/**
	 * Plugin slug.
	 */
	public const PLUGIN_SLUG = 'seoframework';

	/**
	 * The SEO Framework plugin file path.
	 */
	public const PLUGIN_FILE = 'autodescription/autodescription.php';

	/**
	 * Prefix for The SEO Framework post meta keys.
	 *
	 * Note: TSF post meta keys do not share a single prefix — this prefix only
	 * covers the `_genesis_*` keys and exists to satisfy BaseImporter's abstract
	 * get_meta_key_prefix(). No prefix scan runs for this importer: detection and
	 * batching are overridden to match the full key list in SOURCE_POST_META_KEYS.
	 */
	public const META_KEY_PREFIX = '_genesis_';

	/**
	 * The SEO Framework option name for global settings.
	 */
	public const OPTION_NAME = 'autodescription-site-settings';

	/**
	 * The SEO Framework term meta key (one serialized array per term).
	 */
	public const TERM_META_KEY = 'autodescription-term-settings';

	/**
	 * Not allowed post and term types for import.
	 */
	public const NOT_ALLOWED_TYPES = [
		'elementor_library',
		'product_shipping_class',
	];

	/**
	 * All TSF post meta keys the importer consumes. Drives post detection and
	 * batch queries.
	 *
	 * Intentionally NOT migrated (no SureRank equivalent):
	 * - `redirect` (per-post 301 redirect; also unprefixed and ambiguous with other plugins)
	 * - `exclude_local_search`, `exclude_from_archive` (query-alteration flags)
	 * - `_primary_term_{taxonomy}` (primary term selection)
	 */
	public const SOURCE_POST_META_KEYS = [
		'_genesis_title',
		'_tsf_title_no_blogname',
		'_genesis_description',
		'_genesis_canonical_uri',
		'_genesis_noindex',
		'_genesis_nofollow',
		'_genesis_noarchive',
		'_open_graph_title',
		'_open_graph_description',
		'_twitter_title',
		'_twitter_description',
		'_tsf_twitter_card_type',
		'_social_image_url',
		'_social_image_id',
	];

	/**
	 * Mapping of TSF post meta fields to SureRank meta fields (general settings).
	 */
	public const META_MAPPING = [
		'_genesis_title'         => 'page_title',
		'_genesis_description'   => 'page_description',
		'_genesis_canonical_uri' => 'canonical_url',
	];

	/**
	 * Mapping of TSF term meta fields to SureRank meta fields (general settings).
	 */
	public const TERM_META_MAPPING = [
		'doctitle'    => 'page_title',
		'description' => 'page_description',
		'canonical'   => 'canonical_url',
	];

	/**
	 * Mapping of TSF post robots meta to SureRank robots meta.
	 * Values are qubits: 1 = force noindex, -1 = force index, 0/absent = inherit.
	 */
	public const ROBOTS_MAPPING = [
		'_genesis_noindex'   => 'post_no_index',
		'_genesis_nofollow'  => 'post_no_follow',
		'_genesis_noarchive' => 'post_no_archive',
	];

	/**
	 * Mapping of TSF term robots keys to SureRank robots meta (same qubit semantics).
	 */
	public const TERM_ROBOTS_MAPPING = [
		'noindex'   => 'post_no_index',
		'nofollow'  => 'post_no_follow',
		'noarchive' => 'post_no_archive',
	];

	/**
	 * Mapping of TSF post social meta to SureRank social meta.
	 */
	public const SOCIAL_META_MAPPING = [
		'_open_graph_title'       => 'facebook_title',
		'_open_graph_description' => 'facebook_description',
		'_twitter_title'          => 'twitter_title',
		'_twitter_description'    => 'twitter_description',
		'_tsf_twitter_card_type'  => 'twitter_card_type',
		'_social_image_url'       => 'facebook_image_url',
		'_social_image_id'        => 'facebook_image_id',
	];

	/**
	 * Mapping of TSF term social keys to SureRank social meta.
	 */
	public const TERM_SOCIAL_META_MAPPING = [
		'og_title'         => 'facebook_title',
		'og_description'   => 'facebook_description',
		'tw_title'         => 'twitter_title',
		'tw_description'   => 'twitter_description',
		'tw_card_type'     => 'twitter_card_type',
		'social_image_url' => 'facebook_image_url',
		'social_image_id'  => 'facebook_image_id',
	];

	/**
	 * Meta keys to exclude from detection.
	 */
	public const EXCLUDED_META_KEYS = [];

	/**
	 * Mapping of TSF global social toggles to SureRank settings (booleans).
	 */
	public const SOCIAL_TOGGLES_MAPPING = [
		'og_tags'                 => 'open_graph_tags',
		'facebook_tags'           => 'facebook_meta_tags',
		'twitter_tags'            => 'twitter_meta_tags',
		'oembed_scripts'          => 'oembeded_scripts',
		'oembed_use_og_title'     => 'oembeded_og_title',
		'oembed_use_social_image' => 'oembeded_social_images',
		'oembed_remove_author'    => 'oembeded_remove_author_name',
	];

	/**
	 * Mapping of TSF global social string settings to SureRank settings.
	 */
	public const SOCIAL_STRINGS_MAPPING = [
		'facebook_publisher'  => 'facebook_page_url',
		'facebook_author'     => 'facebook_author_fallback',
		'twitter_site'        => 'twitter_profile_username',
		'twitter_creator'     => 'twitter_profile_fallback',
		'social_image_fb_url' => 'fallback_image',
	];

	/**
	 * Mapping of TSF webmaster verification codes to SureRank settings.
	 */
	public const WEBMASTER_MAPPING = [
		'google_verification' => 'google_verify',
		'bing_verification'   => 'bing_verify',
		'yandex_verification' => 'yandex_verify',
		'baidu_verification'  => 'baidu_verify',
		'pint_verification'   => 'pinterest_verify',
	];

	/**
	 * Mapping of TSF knowledge-graph profile URLs to SureRank social profile keys.
	 *
	 * Not migrated (no matching SureRank social profile key): knowledge_tumblr,
	 * knowledge_soundcloud.
	 */
	public const KNOWLEDGE_PROFILES_MAPPING = [
		'knowledge_facebook'  => 'facebook',
		'knowledge_twitter'   => 'twitter',
		'knowledge_instagram' => 'instagram',
		'knowledge_youtube'   => 'youtube',
		'knowledge_linkedin'  => 'linkedin',
		'knowledge_pinterest' => 'pinterest',
	];

	/**
	 * TSF stores the title separator as a named key; SureRank stores the literal
	 * character. Source list: autodescription Meta\Title\Utils::get_separator_list().
	 */
	public const SEPARATOR_MAP = [
		'hyphen' => '-',
		'pipe'   => '|',
		'ndash'  => '–',
		'mdash'  => '—',
		'bull'   => '•',
		'middot' => '·',
		'lsaquo' => '‹',
		'rsaquo' => '›',
		'frasl'  => '⁄',
		'laquo'  => '«',
		'raquo'  => '»',
		'le'     => '≤',
		'ge'     => '≥',
		'lt'     => '<',
		'gt'     => '>',
	];

	/**
	 * Get global settings from The SEO Framework.
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> The global settings.
	 */
	public static function get_global_settings(): array {
		$settings = get_option( self::OPTION_NAME, [] );
		return is_array( $settings ) ? $settings : [];
	}

	/**
	 * Get The SEO Framework meta data for a post.
	 *
	 * TSF stores individual post meta rows and deletes rows holding empty/zero
	 * values, so only the keys actually present are returned.
	 *
	 * @since 1.10.0
	 * @param int $post_id The post ID.
	 * @return array<string, mixed> The SEO Framework post meta data.
	 */
	public static function get_post_meta_data( int $post_id ): array {
		$meta = get_post_meta( $post_id );

		if ( ! is_array( $meta ) || empty( $meta ) ) {
			return [];
		}

		$data = [];
		foreach ( self::SOURCE_POST_META_KEYS as $key ) {
			if ( isset( $meta[ $key ][0] ) && $meta[ $key ][0] !== '' ) {
				$data[ $key ] = $meta[ $key ][0];
			}
		}

		return $data;
	}

	/**
	 * Get The SEO Framework meta data for a term.
	 *
	 * TSF stores one serialized array per term.
	 *
	 * @since 1.10.0
	 * @param int $term_id The term ID.
	 * @return array<string, mixed> The SEO Framework term meta data.
	 */
	public static function get_term_meta_data( int $term_id ): array {
		$meta = get_term_meta( $term_id, self::TERM_META_KEY, true );
		return is_array( $meta ) ? $meta : [];
	}

	/**
	 * Resolve a TSF separator key to its literal character.
	 *
	 * @since 1.10.0
	 * @param string $key TSF separator key (e.g. 'hyphen', 'pipe').
	 * @return string The literal separator character.
	 */
	public static function get_separator( string $key ): string {
		return self::SEPARATOR_MAP[ $key ] ?? '-';
	}

	/**
	 * Convert a TSF robots qubit to a SureRank robots value.
	 *
	 * TSF qubit semantics: 1 = force noindex/nofollow/noarchive,
	 * -1 = force index/follow/archive, 0/absent = inherit defaults.
	 *
	 * @since 1.10.0
	 * @param mixed $value The qubit value.
	 * @return string|null 'yes', 'no', or null when the default should be kept.
	 */
	public static function qubit_to_robots( $value ): ?string {
		// Accept only exact qubit representations; casting first would turn
		// malformed data (true, 1.8, "1x") into a forced robots directive.
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return null;
		}

		if ( 1 === $value || '1' === $value ) {
			return 'yes';
		}

		if ( -1 === $value || '-1' === $value ) {
			return 'no';
		}

		return null;
	}
}
