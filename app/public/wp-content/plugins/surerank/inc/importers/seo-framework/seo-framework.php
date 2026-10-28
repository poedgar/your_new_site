<?php
/**
 * The SEO Framework Importer Class
 *
 * Handles importing data from The SEO Framework plugin.
 *
 * @package SureRank\Inc\Importers
 * @since   1.10.0
 */

namespace SureRank\Inc\Importers\SeoFramework;

use Exception;
use SureRank\Inc\API\Onboarding;
use SureRank\Inc\Functions\Get;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Importers\BaseImporter;
use SureRank\Inc\Importers\ImporterUtils;
use SureRank\Inc\Traits\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements The SEO Framework → SureRank migration.
 *
 * @since 1.10.0
 */
class SeoFramework extends BaseImporter {

	use Logger;

	/**
	 * Get plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return Constants::PLUGIN_NAME;
	}

	/**
	 * Get plugin file.
	 *
	 * @return string
	 */
	public function get_plugin_file(): string {
		return Constants::PLUGIN_FILE;
	}

	/**
	 * Check if The SEO Framework plugin is active.
	 *
	 * @return bool
	 */
	public function is_plugin_active(): bool {
		return defined( 'THE_SEO_FRAMEWORK_VERSION' );
	}

	/**
	 * Detect The SEO Framework data for post.
	 *
	 * Overridden because TSF post meta keys do not share a single prefix; the
	 * generic prefix scan would miss posts holding only social/Twitter meta.
	 *
	 * @param int $post_id Post ID.
	 * @return array{success: bool, message: string}
	 */
	public function detect_post( int $post_id ): array {
		$meta = Constants::get_post_meta_data( $post_id );

		if ( ! empty( $meta ) ) {
			return ImporterUtils::build_response(
				sprintf(
					// translators: %d: post ID.
					__( 'The SEO Framework data detected for post %d.', 'surerank' ),
					$post_id
				),
				true
			);
		}

		ImporterUtils::update_surerank_migrated( $post_id );

		return ImporterUtils::build_response(
			sprintf(
				// translators: %d: post ID.
				__( 'No The SEO Framework data found for post %d.', 'surerank' ),
				$post_id
			),
			false,
			[],
			true
		);
	}

	/**
	 * Detect The SEO Framework data for term.
	 *
	 * @param int $term_id Term ID.
	 * @return array{success: bool, message: string}
	 */
	public function detect_term( int $term_id ): array {
		$meta = Constants::get_term_meta_data( $term_id );

		if ( ! empty( $meta ) ) {
			return ImporterUtils::build_response(
				sprintf(
					// translators: %d: term ID.
					__( 'The SEO Framework data detected for term %d.', 'surerank' ),
					$term_id
				),
				true
			);
		}

		ImporterUtils::update_surerank_migrated( $term_id, false );

		return ImporterUtils::build_response(
			sprintf(
				// translators: %d: term ID.
				__( 'No The SEO Framework data found for term %d.', 'surerank' ),
				$term_id
			),
			false,
			[],
			true
		);
	}

	/**
	 * Import meta-robots settings for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{success: bool, message: string}
	 */
	public function import_post_meta_robots( int $post_id ): array {
		return $this->import_robots( $post_id, false );
	}

	/**
	 * Import meta-robots settings for a term.
	 *
	 * @param int $term_id Term ID.
	 * @return array{success: bool, message: string}
	 */
	public function import_term_meta_robots( int $term_id ): array {
		return $this->import_robots( $term_id, true );
	}

	/**
	 * Import general SEO settings for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{success: bool, message: string}
	 */
	public function import_post_general_settings( int $post_id ): array {
		return $this->import_general_settings( $post_id, false );
	}

	/**
	 * Import general SEO settings for a term.
	 *
	 * @param int $term_id Term ID.
	 * @return array{success: bool, message: string}
	 */
	public function import_term_general_settings( int $term_id ): array {
		return $this->import_general_settings( $term_id, true );
	}

	/**
	 * Import social metadata for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{success: bool, message: string}
	 */
	public function import_post_social( int $post_id ): array {
		return $this->import_social( $post_id, false );
	}

	/**
	 * Import social metadata for a term.
	 *
	 * @param int $term_id Term ID.
	 * @return array{success: bool, message: string}
	 */
	public function import_term_social( int $term_id ): array {
		return $this->import_social( $term_id, true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function import_global_settings(): array {
		try {
			$this->source_settings   = Constants::get_global_settings();
			$this->surerank_settings = Settings::get();

			if ( empty( $this->source_settings ) ) {
				return ImporterUtils::build_response(
					__( 'No The SEO Framework global settings found to import.', 'surerank' ),
					false
				);
			}

			$this->update_title_settings();
			$this->update_homepage_settings();
			$this->update_robots_settings();
			$this->update_social_settings();
			$this->update_webmaster_settings();
			$this->update_feature_settings();
			$this->update_site_details();

			// Allow pro plugin to migrate CPT, archive, and taxonomy title/desc (Extended Meta Templates).
			$this->surerank_settings = apply_filters( 'surerank_seoframework_global_settings', $this->surerank_settings, $this->source_settings );

			ImporterUtils::update_global_settings( $this->surerank_settings );

			return ImporterUtils::build_response(
				__( 'The SEO Framework global settings imported successfully.', 'surerank' ),
				true
			);
		} catch ( Exception $e ) {
			self::log(
				sprintf(
					/* translators: %s: error message. */
					__( 'Error importing The SEO Framework global settings: %s', 'surerank' ),
					$e->getMessage()
				)
			);
			return ImporterUtils::build_response( $e->getMessage(), false );
		}
	}

	/**
	 * Return a paginated list of post IDs that have The SEO Framework data.
	 *
	 * Overrides BaseImporter::get_count_and_posts() because the base SQL matches
	 * a single `meta_key LIKE 'prefix%'` while TSF post meta keys share no common
	 * prefix; the queries below match the full key list instead.
	 *
	 * @param array<string> $post_types Post types to check.
	 * @param int           $batch_size Number of posts to fetch in one batch.
	 * @param int           $offset     Offset for pagination.
	 * @return array{total_items: int, post_ids: array<string|int>}
	 */
	public function get_count_and_posts( $post_types, $batch_size, $offset ) {
		return [
			'total_items' => $this->get_source_posts_count( $post_types ),
			'post_ids'    => $this->get_source_post_ids( $post_types, $batch_size, $offset ),
		];
	}

	/**
	 * Return a paginated list of term IDs that have The SEO Framework data.
	 *
	 * Overrides BaseImporter::get_count_and_terms() because term data lives under
	 * the exact meta key `autodescription-term-settings`, not under the post meta
	 * prefix returned by get_meta_key_prefix().
	 *
	 * @param array<string> $taxonomies Term types to check.
	 * @param array<mixed>  $taxonomies_objects Taxonomy objects to check.
	 * @param int           $batch_size Number of terms to fetch in one batch.
	 * @param int           $offset     Offset for pagination.
	 * @return array<mixed>
	 */
	public function get_count_and_terms( $taxonomies, $taxonomies_objects, $batch_size, $offset ) {
		$excluded_keys = $this->get_excluded_meta_keys();

		return [
			'total_items' => ImporterUtils::get_total_terms_count( $taxonomies, $excluded_keys, Constants::TERM_META_KEY ),
			'term_ids'    => ImporterUtils::get_term_ids( $taxonomies, $excluded_keys, Constants::TERM_META_KEY, $batch_size, $offset ),
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_not_allowed_types(): array {
		return Constants::NOT_ALLOWED_TYPES;
	}

	/**
	 * Get the source meta data for a post or term.
	 *
	 * @param int    $id          The ID of the post or term.
	 * @param bool   $is_taxonomy Whether it is a taxonomy.
	 * @param string $type        The type of post or term.
	 * @return array<string, mixed>
	 */
	protected function get_source_meta_data( int $id, bool $is_taxonomy, string $type = '' ): array {
		return $is_taxonomy ? Constants::get_term_meta_data( $id ) : Constants::get_post_meta_data( $id );
	}

	/**
	 * Get the meta key prefix for the importer.
	 *
	 * @return string
	 */
	protected function get_meta_key_prefix(): string {
		return Constants::META_KEY_PREFIX;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_excluded_meta_keys(): array {
		return Constants::EXCLUDED_META_KEYS;
	}

	/**
	 * Import robots settings for a post or term.
	 *
	 * TSF robots values are qubits: 1 = force noindex/nofollow/noarchive,
	 * -1 = force index/follow/archive, 0/absent = inherit defaults.
	 *
	 * @param int  $id          Post or Term ID.
	 * @param bool $is_taxonomy Whether the ID is for a taxonomy term.
	 * @return array{success: bool, message: string}
	 */
	private function import_robots( int $id, bool $is_taxonomy ): array {
		try {
			$source_meta = $this->source_meta;
			$mapping     = $is_taxonomy ? Constants::TERM_ROBOTS_MAPPING : Constants::ROBOTS_MAPPING;
			$imported    = false;

			foreach ( $mapping as $source_key => $surerank_key ) {
				if ( ! isset( $source_meta[ $source_key ] ) ) {
					continue;
				}

				$value = Constants::qubit_to_robots( $source_meta[ $source_key ] );
				if ( $value === null ) {
					continue;
				}

				$this->default_surerank_meta[ $surerank_key ] = $value;
				$imported                                     = true;
			}

			if ( $imported ) {
				return ImporterUtils::build_response(
					sprintf(
						// translators: %s: type, %d: ID.
						__( 'Meta-robots imported for %1$s %2$d.', 'surerank' ),
						$is_taxonomy ? 'term' : 'post',
						$id
					),
					true
				);
			}

			return ImporterUtils::build_response(
				sprintf(
					// translators: %s: type, %d: ID.
					__( 'No meta-robots settings to import for %1$s %2$d.', 'surerank' ),
					$is_taxonomy ? 'term' : 'post',
					$id
				),
				false
			);
		} catch ( Exception $e ) {
			self::log(
				sprintf(
					/* translators: 1: type, 2: ID, 3: error message. */
					__( 'Error importing meta-robots for %1$s %2$d: %3$s', 'surerank' ),
					$is_taxonomy ? 'term' : 'post',
					$id,
					$e->getMessage()
				)
			);
			return ImporterUtils::build_response( $e->getMessage(), false );
		}
	}

	/**
	 * Import general settings for a post or term.
	 *
	 * @param int  $id          Post or Term ID.
	 * @param bool $is_taxonomy Whether the ID is for a taxonomy term.
	 * @return array{success: bool, message: string}
	 */
	private function import_general_settings( int $id, bool $is_taxonomy ): array {
		$source_meta = $this->source_meta;
		$mapping     = $is_taxonomy ? Constants::TERM_META_MAPPING : Constants::META_MAPPING;
		$title_key   = $is_taxonomy ? 'doctitle' : '_genesis_title';
		$imported    = false;

		foreach ( $mapping as $source_key => $surerank_key ) {
			if ( ! isset( $source_meta[ $source_key ] ) || empty( $source_meta[ $source_key ] ) ) {
				continue;
			}

			$value = (string) $source_meta[ $source_key ];

			// TSF stores literal titles and appends site-name branding at render
			// time; rebuild the equivalent SureRank title template.
			if ( $source_key === $title_key ) {
				$value = $this->build_title_template( $value, $source_meta, $is_taxonomy );
			}

			$this->default_surerank_meta[ $surerank_key ] = $value;
			$imported                                     = true;
		}

		// TSF can suppress site-name branding on its generated title, with no custom
		// title stored. build_title_template() never runs then, so migrate the
		// override here or the global template would re-append %site_name%.
		$no_blogname_key = $is_taxonomy ? 'title_no_blog_name' : '_tsf_title_no_blogname';
		if ( empty( $source_meta[ $title_key ] ) && ! empty( $source_meta[ $no_blogname_key ] ) ) {
			$this->default_surerank_meta['page_title'] = $is_taxonomy ? '%term_title%' : '%title%';
			$imported                                  = true;
		}

		if ( $imported ) {
			return ImporterUtils::build_response(
				sprintf(
					// translators: %s: type, %d: ID.
					__( 'General settings imported for %1$s %2$d.', 'surerank' ),
					$is_taxonomy ? 'term' : 'post',
					$id
				),
				true
			);
		}

		return ImporterUtils::build_response(
			sprintf(
				// translators: %s: type, %d: ID.
				__( 'No general settings to import for %1$s %2$d.', 'surerank' ),
				$is_taxonomy ? 'term' : 'post',
				$id
			),
			false
		);
	}

	/**
	 * Import social metadata for a post or term.
	 *
	 * @param int  $id          Post or Term ID.
	 * @param bool $is_taxonomy Whether the ID is for a taxonomy term.
	 * @return array{success: bool, message: string}
	 */
	private function import_social( int $id, bool $is_taxonomy ): array {
		// Settings::prep_post_meta()/prep_term_meta() fill empty social fields with
		// runtime fallbacks (page title/description); persisting those would freeze
		// them. Clear the fill-ins so empty fields keep falling back at runtime —
		// but never clear a value the user explicitly saved in SureRank, or a rerun
		// on a partially migrated site would erase it.
		$social_fields_to_clear = [
			'facebook_title',
			'facebook_description',
			'twitter_title',
			'twitter_description',
			'fallback_image',
		];

		$existing_meta = $is_taxonomy ? Get::all_term_meta( $id ) : Get::all_post_meta( $id );

		foreach ( $social_fields_to_clear as $field ) {
			if ( empty( $existing_meta[ $field ] ) ) {
				$this->default_surerank_meta[ $field ] = '';
			}
		}

		$source_meta = $this->source_meta;
		$mapping     = $is_taxonomy ? Constants::TERM_SOCIAL_META_MAPPING : Constants::SOCIAL_META_MAPPING;
		$image_url   = '';
		$imported    = false;
		$has_twitter = false;

		foreach ( $mapping as $source_key => $surerank_key ) {
			if ( ! isset( $source_meta[ $source_key ] ) || empty( $source_meta[ $source_key ] ) ) {
				continue;
			}

			$value = $source_meta[ $source_key ];

			if ( $surerank_key === 'facebook_image_url' ) {
				$image_url = (string) $value;
			}

			// TSF zeroes the image ID when the image URL is empty; keep that invariant.
			if ( $surerank_key === 'facebook_image_id' ) {
				if ( empty( $image_url ) ) {
					continue;
				}
				$value = (int) $value;
			}

			if ( $surerank_key === 'twitter_card_type' && ! in_array( $value, [ 'summary', 'summary_large_image' ], true ) ) {
				continue;
			}

			// Card type alone is configuration, not content — TSF still mirrors
			// Open Graph values in that state, so it must not break inheritance.
			if ( in_array( $surerank_key, [ 'twitter_title', 'twitter_description' ], true ) ) {
				$has_twitter = true;
			}

			$this->default_surerank_meta[ $surerank_key ] = $value;
			$imported                                     = true;
		}

		// TSF has no separate Twitter image; Twitter mirrors the Open Graph values
		// unless post/term-specific Twitter content exists. Only break inheritance
		// when TSF actually provides it — otherwise leave the prepared value alone.
		if ( $has_twitter ) {
			$this->default_surerank_meta['twitter_same_as_facebook'] = false;

			// TSF falls back per field (twitter → og → generated) while SureRank's
			// flag is all-or-nothing: once inheritance breaks, empty Twitter fields
			// render from page_title/page_description/featured image instead of the
			// OG values. Materialize the OG value into each missing Twitter field so
			// breaking inheritance doesn't change what renders.
			$og_fallbacks = [
				'twitter_title'       => 'facebook_title',
				'twitter_description' => 'facebook_description',
				'twitter_image_url'   => 'facebook_image_url',
				'twitter_image_id'    => 'facebook_image_id',
			];

			foreach ( $og_fallbacks as $twitter_key => $facebook_key ) {
				if ( empty( $this->default_surerank_meta[ $twitter_key ] ) && ! empty( $this->default_surerank_meta[ $facebook_key ] ) ) {
					$this->default_surerank_meta[ $twitter_key ] = $this->default_surerank_meta[ $facebook_key ];
				}
			}
		}

		if ( $imported ) {
			return ImporterUtils::build_response(
				sprintf(
					// translators: %s: type, %d: ID.
					__( 'Social metadata imported for %1$s %2$d.', 'surerank' ),
					$is_taxonomy ? 'term' : 'post',
					$id
				),
				true
			);
		}

		return ImporterUtils::build_response(
			sprintf(
				// translators: %s: type, %d: ID.
				__( 'No social metadata to import for %1$s %2$d.', 'surerank' ),
				$is_taxonomy ? 'term' : 'post',
				$id
			),
			false
		);
	}

	/**
	 * Rebuild the SureRank title template for a TSF literal title.
	 *
	 * TSF appends/prepends the site name and separator at render time, governed
	 * by the global `title_rem_additions`/`title_location` options and the
	 * per-post `_tsf_title_no_blogname` / per-term `title_no_blog_name` flag.
	 *
	 * @param string               $title       The literal TSF title.
	 * @param array<string, mixed> $source_meta The TSF meta for the post or term.
	 * @param bool                 $is_taxonomy Whether the meta belongs to a term.
	 * @return string
	 */
	private function build_title_template( string $title, array $source_meta, bool $is_taxonomy ): string {
		$tsf_settings = Constants::get_global_settings();

		$no_blogname_key = $is_taxonomy ? 'title_no_blog_name' : '_tsf_title_no_blogname';
		$no_blogname     = ! empty( $source_meta[ $no_blogname_key ] );

		if ( $no_blogname || ! empty( $tsf_settings['title_rem_additions'] ) ) {
			return $title;
		}

		$separator = Constants::get_separator( is_string( $tsf_settings['title_separator'] ?? null ) ? $tsf_settings['title_separator'] : '' );

		if ( ( $tsf_settings['title_location'] ?? 'right' ) === 'left' ) {
			return "%site_name% {$separator} {$title}";
		}

		return "{$title} {$separator} %site_name%";
	}

	/**
	 * Get total count of posts holding The SEO Framework meta.
	 *
	 * Mirrors ImporterUtils::get_total_posts_count() with `meta_key IN (...)`
	 * instead of a prefix LIKE, since TSF keys share no common prefix.
	 *
	 * @param array<string> $post_types Post types to check.
	 * @return int
	 */
	private function get_source_posts_count( array $post_types ): int {
		global $wpdb;

		$query_data = $this->prepare_post_query_params( $post_types );
		$sql        = $this->build_source_posts_sql( 'COUNT(DISTINCT p.ID)', $query_data['placeholders'], false );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var($wpdb->prepare($sql, $query_data['params'])); // phpcs:ignore
	}

	/**
	 * Get post IDs holding The SEO Framework meta.
	 *
	 * @param array<string> $post_types Post types to check.
	 * @param int           $batch_size Number of posts to retrieve.
	 * @param int           $offset     Offset for pagination.
	 * @return array<int>
	 */
	private function get_source_post_ids( array $post_types, int $batch_size, int $offset ): array {
		global $wpdb;

		$query_data = $this->prepare_post_query_params( $post_types, $batch_size, $offset );
		$sql        = $this->build_source_posts_sql( 'DISTINCT p.ID', $query_data['placeholders'], true );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_col($wpdb->prepare($sql, $query_data['params'])); // phpcs:ignore
	}

	/**
	 * Build the shared SQL for the post count/batch queries.
	 *
	 * @param string        $select       SELECT clause.
	 * @param array<string> $placeholders IN-clause placeholders from prepare_post_query_params().
	 * @param bool          $paginated    Whether to append ORDER BY / LIMIT / OFFSET.
	 * @return string
	 */
	private function build_source_posts_sql( string $select, array $placeholders, bool $paginated ): string {
		global $wpdb;

		$sql = "SELECT {$select}
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    AND pm.meta_key = %s
                INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
                    AND pm2.meta_key IN ({$placeholders[0]})
                WHERE p.post_type IN ({$placeholders[1]})
                 AND p.post_status != 'auto-draft'
                 AND pm.meta_id IS NULL";

		if ( $paginated ) {
			$sql .= '
                 ORDER BY p.ID
                 LIMIT %d OFFSET %d';
		}

		return $sql;
	}

	/**
	 * Build placeholders and parameters for the post batch queries.
	 *
	 * @param array<string> $post_types Post types to check.
	 * @param int|null      $batch_size Number of posts to retrieve.
	 * @param int|null      $offset     Offset for pagination.
	 * @return array{placeholders: array<string>, params: array<mixed>}
	 */
	private function prepare_post_query_params( array $post_types, ?int $batch_size = null, ?int $offset = null ): array {
		$meta_keys = Constants::SOURCE_POST_META_KEYS;

		$params = array_merge(
			[ 'surerank_migration' ],
			$meta_keys,
			$post_types
		);

		$placeholders = [
			implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) ),
			implode( ',', array_fill( 0, count( $post_types ), '%s' ) ),
		];

		if ( $batch_size !== null && $offset !== null ) {
			$params[] = $batch_size;
			$params[] = $offset;
		}

		return [
			'placeholders' => $placeholders,
			'params'       => $params,
		];
	}

	/**
	 * Update title and description settings from The SEO Framework.
	 *
	 * Skipped (no SureRank equivalent): site_title, title_rem_prefixes,
	 * title_strip_tags, auto_description_html_method.
	 *
	 * @return void
	 */
	private function update_title_settings(): void {
		$separator_key = is_string( $this->source_settings['title_separator'] ?? null ) ? $this->source_settings['title_separator'] : '';
		$separator     = Constants::get_separator( $separator_key );

		$this->surerank_settings['separator'] = $separator;

		// TSF has no title templates: branding is rebuilt from title_rem_additions + title_location.
		if ( ! empty( $this->source_settings['title_rem_additions'] ) ) {
			$this->surerank_settings['page_title'] = '%title%';
		} elseif ( ( $this->source_settings['title_location'] ?? 'right' ) === 'left' ) {
			$this->surerank_settings['page_title'] = "%site_name% {$separator} %title%";
		} else {
			$this->surerank_settings['page_title'] = "%title% {$separator} %site_name%";
		}

		if ( isset( $this->source_settings['auto_description'] ) ) {
			$this->surerank_settings['auto_generate_description'] = ! empty( $this->source_settings['auto_description'] );
		}
	}

	/**
	 * Update homepage settings from The SEO Framework.
	 *
	 * Skipped (no SureRank equivalent): homepage_canonical, homepage_redirect,
	 * homepage_twitter_card_type.
	 *
	 * @return void
	 */
	private function update_homepage_settings(): void {
		$separator = is_string( $this->surerank_settings['separator'] ?? null ) ? $this->surerank_settings['separator'] : '-';

		if ( ! empty( $this->source_settings['homepage_title'] ) ) {
			$title = (string) $this->source_settings['homepage_title'];

			// TSF appends the tagline (or a custom one) to the home title when homepage_tagline is on.
			if ( ! empty( $this->source_settings['homepage_tagline'] ) ) {
				$title = ( $this->source_settings['home_title_location'] ?? 'right' ) === 'left'
					? "%tagline% {$separator} {$title}"
					: "{$title} {$separator} %tagline%";
			}

			$this->surerank_settings['home_page_title'] = $title;
		}

		if ( ! empty( $this->source_settings['homepage_description'] ) ) {
			$this->surerank_settings['home_page_description'] = (string) $this->source_settings['homepage_description'];
		}

		$homepage_social_mapping = [
			'homepage_og_title'            => 'home_page_facebook_title',
			'homepage_og_description'      => 'home_page_facebook_description',
			'homepage_twitter_title'       => 'home_page_twitter_title',
			'homepage_twitter_description' => 'home_page_twitter_description',
			'homepage_social_image_url'    => 'home_page_facebook_image_url',
		];

		foreach ( $homepage_social_mapping as $tsf_key => $surerank_key ) {
			if ( ! empty( $this->source_settings[ $tsf_key ] ) ) {
				$this->surerank_settings[ $surerank_key ] = (string) $this->source_settings[ $tsf_key ];
			}
		}

		if ( ! isset( $this->surerank_settings['home_page_robots']['general'] ) || ! is_array( $this->surerank_settings['home_page_robots']['general'] ) ) {
			$this->surerank_settings['home_page_robots']['general'] = [];
		}

		foreach ( [ 'noindex', 'nofollow', 'noarchive' ] as $robot ) {
			// Absent option = unknown, not disabled; leave SureRank's value untouched.
			if ( ! isset( $this->source_settings[ "homepage_{$robot}" ] ) ) {
				continue;
			}

			$this->toggle_token(
				$this->surerank_settings['home_page_robots']['general'],
				$robot,
				! empty( $this->source_settings[ "homepage_{$robot}" ] )
			);
		}
	}

	/**
	 * Update robots settings from The SEO Framework.
	 *
	 * Skipped (no SureRank equivalent): site_noindex/nofollow/noarchive,
	 * advanced_query_protection, set_copyright_directives, max_snippet_length,
	 * max_image_preview, max_video_preview, robotstxt_block_ai/seo,
	 * disabled_post_types/disabled_taxonomies.
	 *
	 * @return void
	 */
	private function update_robots_settings(): void {
		$robots_mapping = [
			'noindex'   => 'no_index',
			'nofollow'  => 'no_follow',
			'noarchive' => 'no_archive',
		];

		foreach ( $robots_mapping as $tsf_robot => $surerank_key ) {
			// Per post type: TSF stores maps like [ 'attachment' => 1 ]. An absent or
			// malformed option means "unknown", not "all disabled" — skip the loop so
			// SureRank's own defaults (e.g. attachment in no_index) are not stripped.
			$post_type_map = $this->source_settings[ "{$tsf_robot}_post_types" ] ?? null;

			if ( is_array( $post_type_map ) ) {
				foreach ( $this->post_types as $post_type ) {
					$this->toggle_robot_setting( $surerank_key, $post_type, ! empty( $post_type_map[ $post_type ] ) );
				}
			}

			// Per taxonomy.
			$taxonomy_map = $this->source_settings[ "{$tsf_robot}_taxonomies" ] ?? null;

			if ( is_array( $taxonomy_map ) ) {
				foreach ( $this->taxonomies as $taxonomy ) {
					$this->toggle_robot_setting( $surerank_key, $taxonomy->name, ! empty( $taxonomy_map[ $taxonomy->name ] ) );
				}
			}

			// Author, date, and search archives use standalone TSF options.
			foreach ( [ 'author', 'date', 'search' ] as $archive ) {
				if ( isset( $this->source_settings[ "{$archive}_{$tsf_robot}" ] ) ) {
					$this->toggle_robot_setting( $surerank_key, $archive, ! empty( $this->source_settings[ "{$archive}_{$tsf_robot}" ] ) );
				}
			}
		}

		if ( isset( $this->source_settings['paged_noindex'] ) ) {
			$this->surerank_settings['noindex_paginated_pages'] = ! empty( $this->source_settings['paged_noindex'] );
		}

		// Inverted: TSF flags "noindex paginated home", SureRank flags "index paginated home".
		if ( isset( $this->source_settings['home_paged_noindex'] ) ) {
			$this->surerank_settings['index_home_page_paginated_pages'] = empty( $this->source_settings['home_paged_noindex'] );
		}
	}

	/**
	 * Add or remove a slug/token from a SureRank robots array.
	 *
	 * @param string $setting_key SureRank robots key (no_index, no_follow, no_archive).
	 * @param string $token       Post type, taxonomy, or special token (author, date, search).
	 * @param bool   $enabled     Whether the robots directive is enabled for the token.
	 * @return void
	 */
	private function toggle_robot_setting( string $setting_key, string $token, bool $enabled ): void {
		if ( ! isset( $this->surerank_settings[ $setting_key ] ) || ! is_array( $this->surerank_settings[ $setting_key ] ) ) {
			$this->surerank_settings[ $setting_key ] = [];
		}

		$this->toggle_token( $this->surerank_settings[ $setting_key ], $token, $enabled );
	}

	/**
	 * Add or remove a token from a list, keeping it de-duplicated and re-indexed.
	 *
	 * @param array<string> $list    Token list, modified in place.
	 * @param string        $token   Token to add or remove.
	 * @param bool          $enabled Whether the token should be present.
	 * @return void
	 */
	private function toggle_token( array &$list, string $token, bool $enabled ): void {
		$is_present = in_array( $token, $list, true );

		if ( $enabled && ! $is_present ) {
			$list[] = $token;
		} elseif ( ! $enabled && $is_present ) {
			$list = array_values( array_diff( $list, [ $token ] ) );
		}
	}

	/**
	 * Update social settings from The SEO Framework.
	 *
	 * Skipped (no SureRank equivalent): theme_color, multi_og_image,
	 * social_title_rem_additions, knowledge_tumblr, knowledge_soundcloud.
	 *
	 * @return void
	 */
	private function update_social_settings(): void {
		foreach ( Constants::SOCIAL_TOGGLES_MAPPING as $tsf_key => $surerank_key ) {
			if ( isset( $this->source_settings[ $tsf_key ] ) ) {
				$this->surerank_settings[ $surerank_key ] = ! empty( $this->source_settings[ $tsf_key ] );
			}
		}

		foreach ( Constants::SOCIAL_STRINGS_MAPPING as $tsf_key => $surerank_key ) {
			if ( ! empty( $this->source_settings[ $tsf_key ] ) ) {
				$value = (string) $this->source_settings[ $tsf_key ];

				// TSF stores Twitter handles as '@handle'. SureRank's frontend prepends
				// its own '@' to the last URL segment and update_global_settings() reuses
				// twitter_profile_username as the schema sameAs entry, so store the
				// profile URL form: it renders as '@handle' and stays a valid URL.
				if ( in_array( $surerank_key, [ 'twitter_profile_username', 'twitter_profile_fallback' ], true ) ) {
					$value = $this->normalize_twitter_handle( $value );
				}

				$this->surerank_settings[ $surerank_key ] = $value;
			}
		}

		// ImporterUtils::update_global_settings() rebuilds social_profiles['facebook']
		// from facebook_page_url, so the knowledge-graph URL must land there too when
		// TSF has no facebook_publisher set.
		if ( empty( $this->surerank_settings['facebook_page_url'] ) && ! empty( $this->source_settings['knowledge_facebook'] ) ) {
			$this->surerank_settings['facebook_page_url'] = (string) $this->source_settings['knowledge_facebook'];
		}

		$twitter_card = $this->source_settings['twitter_card'] ?? '';
		if ( in_array( $twitter_card, [ 'summary', 'summary_large_image' ], true ) ) {
			$this->surerank_settings['twitter_card_type'] = $twitter_card;
		}

		if ( ! isset( $this->surerank_settings['social_profiles'] ) || ! is_array( $this->surerank_settings['social_profiles'] ) ) {
			$this->surerank_settings['social_profiles'] = [];
		}

		foreach ( Constants::KNOWLEDGE_PROFILES_MAPPING as $tsf_key => $platform ) {
			if ( ! empty( $this->source_settings[ $tsf_key ] ) ) {
				$this->surerank_settings['social_profiles'][ $platform ] = (string) $this->source_settings[ $tsf_key ];
			}
		}
	}

	/**
	 * Convert a TSF Twitter handle to its profile URL form.
	 *
	 * @param string $value TSF-stored handle ('@handle'), bare handle, or URL.
	 * @return string
	 */
	private function normalize_twitter_handle( string $value ): string {
		if ( 0 === strpos( $value, 'http' ) ) {
			return $value;
		}

		return 'https://x.com/' . ltrim( $value, '@' );
	}

	/**
	 * Update webmaster tools verification codes from The SEO Framework.
	 *
	 * @return void
	 */
	private function update_webmaster_settings(): void {
		foreach ( Constants::WEBMASTER_MAPPING as $tsf_key => $surerank_key ) {
			if ( ! empty( $this->source_settings[ $tsf_key ] ) ) {
				$this->surerank_settings[ $surerank_key ] = (string) $this->source_settings[ $tsf_key ];
			}
		}
	}

	/**
	 * Update feature toggles from The SEO Framework.
	 *
	 * Skipped (no SureRank equivalent): sitemap_query_limit, sitemap styling/logo
	 * options, cache_sitemap, sitemap_cron_prerender, ld_json_* and breadcrumb
	 * schema settings (SureRank schemas are structurally different and are never
	 * migrated), feed settings, prev_next_* relationship tags, shortlink_tag.
	 *
	 * @return void
	 */
	private function update_feature_settings(): void {
		if ( isset( $this->source_settings['sitemaps_output'] ) ) {
			$this->surerank_settings['enable_xml_sitemap'] = ! empty( $this->source_settings['sitemaps_output'] );
		}
	}

	/**
	 * Update site identity details from The SEO Framework knowledge graph settings.
	 *
	 * @return void
	 */
	private function update_site_details(): void {
		$knowledge_type = $this->source_settings['knowledge_type'] ?? '';
		$knowledge_name = $this->source_settings['knowledge_name'] ?? '';
		$knowledge_logo = $this->source_settings['knowledge_logo_url'] ?? '';

		if ( empty( $knowledge_name ) && empty( $knowledge_logo ) ) {
			return;
		}

		$site_data = [
			'organization_type' => 'person' === $knowledge_type ? 'Person' : 'Organization',
			'website_type'      => 'person' === $knowledge_type ? 'person' : 'organization',
		];

		if ( ! empty( $knowledge_name ) ) {
			$site_data['website_name'] = (string) $knowledge_name;
		}

		if ( ! empty( $knowledge_logo ) ) {
			$site_data['website_logo'] = (string) $knowledge_logo;
		}

		Onboarding::update_common_onboarding_data( $site_data );
	}
}
