<?php
/**
 * Content Service
 *
 * Shared post lookup for the admin page pickers and the content-lookup ability.
 * The REST controller and the ability both call this, so the query and the
 * same-host permalink guard live in one place.
 *
 * @package SureCookie\Inc\Services
 * @since   1.4.0
 */

namespace SureCookie\Inc\Services;

use SureCookie\Inc\Functions\Get;
use WP_Post;
use WP_Post_Type;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ContentService
 *
 * @since 1.4.0
 */
class ContentService {
	/**
	 * Hard ceiling on rows returned by a single search.
	 *
	 * @since 1.4.0
	 */
	public const MAX_SEARCH_RESULTS = 50;

	/**
	 * Post types selectable for a given picker context.
	 *
	 * 'policy' and 'scanner' mirror the admin pickers. 'any' is reachable only
	 * through the ability, never through the REST route, and covers the settings
	 * that accept an arbitrary published post.
	 *
	 * @param string $context Picker context.
	 * @return array<int, string>
	 * @since 1.4.0
	 */
	public function lookup_post_types( string $context ): array {
		if ( $context !== 'any' ) {
			return Get::searchable_post_types( $context );
		}

		// Same set as Pro's banner-visibility picker, built from slugs here
		// because that method returns WP_Post_Type objects keyed by slug.
		return array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) );
	}

	/**
	 * Search published content, grouped by post type.
	 *
	 * One capped query per type keeps every group represented; a broad term
	 * cannot let one dominant type crowd the others out.
	 *
	 * @param string $search    Search term. Empty lists alphabetically.
	 * @param int    $per_page  Row cap applied PER post type.
	 * @param string $context   Picker context.
	 * @param int    $total_cap Optional ceiling on the combined result. 0 = none.
	 * @return array{post_types: array<int, string>, posts: array<int, array<string, mixed>>}
	 * @since 1.4.0
	 */
	public function search_posts( string $search, int $per_page, string $context, int $total_cap = 0 ): array {
		$allowed = $this->lookup_post_types( $context );

		// WP_Query treats `'post_type' => []` as 'post', which would silently
		// include the default type even if a filter removed it.
		if ( empty( $allowed ) ) {
			return [
				'post_types' => [],
				'posts'      => [],
			];
		}

		$per_type = max( 1, min( self::MAX_SEARCH_RESULTS, $per_page ) );
		$posts    = [];

		foreach ( $allowed as $post_type ) {
			foreach ( $this->query_post_type( $post_type, $search, $per_type ) as $row ) {
				$posts[] = $row;
			}
		}

		// per_page stays a PER-TYPE cap, as the picker route has always treated
		// it, so every type keeps its own slots. $total_cap bounds the payload
		// for callers that need one; the route passes 0 for no total cap.
		return [
			'post_types' => $allowed,
			'posts'      => $total_cap > 0 ? array_slice( $posts, 0, $total_cap ) : $posts,
		];
	}

	/**
	 * Resolve one published post.
	 *
	 * Deliberately does not gate on post type: no SureCookie write path
	 * validates it, so the caller decides what an out-of-context type means.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null Null when missing or not published.
	 * @since 1.4.0
	 */
	public function get_post( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) || $post->post_status !== 'publish' ) {
			return null;
		}

		$type_object = get_post_type_object( $post->post_type );

		return [
			'id'         => $post->ID,
			'title'      => wp_strip_all_tags( $post->post_title ),
			'status'     => $post->post_status,
			'link'       => $this->same_host_permalink( $post->ID ),
			'type'       => $post->post_type,
			'type_label' => $type_object instanceof WP_Post_Type ? $type_object->labels->name : ucfirst( $post->post_type ),
		];
	}

	/**
	 * Resolve several post IDs, reporting each one's outcome.
	 *
	 * `in_context` says whether the post type is one the given picker offers.
	 * It is reported rather than enforced, because nothing downstream enforces
	 * it either: a wrong-context ID is stored silently.
	 *
	 * @param array<int, mixed> $ids     Post IDs.
	 * @param string            $context Picker context to report against.
	 * @return array<int, array<string, mixed>>
	 * @since 1.4.0
	 */
	public function get_posts( array $ids, string $context ): array {
		$allowed = $this->lookup_post_types( $context );
		$rows    = [];

		foreach ( $ids as $id ) {
			$post_id = absint( $id );
			$post    = $post_id > 0 ? $this->get_post( $post_id ) : null;

			if ( $post === null ) {
				$rows[] = [
					'id'         => $post_id,
					'found'      => false,
					'reason'     => $post_id > 0 && get_post( $post_id ) !== null ? 'not_published' : 'not_found',
					'in_context' => false,
					'title'      => '',
					'status'     => '',
					'link'       => '',
					'type'       => '',
					'type_label' => '',
				];
				continue;
			}

			$rows[] = array_merge(
				$post,
				[
					'found'      => true,
					'reason'     => '',
					'in_context' => in_array( $post['type'], $allowed, true ),
				]
			);
		}

		return $rows;
	}

	/**
	 * Run one capped query for a single post type.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $search    Search term.
	 * @param int    $per_type  Row cap for this type.
	 * @return array<int, array<string, mixed>>
	 * @since 1.4.0
	 */
	private function query_post_type( string $post_type, string $search, int $per_type ): array {
		$type_object = get_post_type_object( $post_type );
		$type_label  = $type_object instanceof WP_Post_Type ? $type_object->labels->name : ucfirst( $post_type );

		$args = [
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => $per_type,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		];

		if ( ! empty( $search ) ) {
			$args['s']       = $search;
			$args['orderby'] = 'relevance';
			unset( $args['order'] );
		}

		$rows = [];

		foreach ( ( new WP_Query( $args ) )->posts as $post ) {
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}

			$rows[] = [
				'id'         => $post->ID,
				// Raw stored title, not get_the_title(): that runs the `the_title`
				// filter chain (SEO/translation plugins), which we do not want in
				// an admin JSON picker where the raw title is the source of truth.
				'title'      => wp_strip_all_tags( $post->post_title ),
				'type'       => $post->post_type,
				'type_label' => $type_label,
			];
		}

		return $rows;
	}

	/**
	 * Permalink for a post, but only when it resolves to the site's own host.
	 *
	 * A rogue `post_link` filter or custom rewrite could otherwise steer admins
	 * toward an off-site URL which then ships to every visitor as the "Cookie
	 * Policy" link. Mirrors Get::cookie_policy_page_details().
	 *
	 * @param int $post_id Post ID.
	 * @return string Empty string when the host does not match.
	 * @since 1.4.0
	 */
	private function same_host_permalink( int $post_id ): string {
		$permalink = get_permalink( $post_id );

		if ( ! is_string( $permalink ) || $permalink === '' ) {
			return '';
		}

		$parsed_permalink = wp_parse_url( $permalink );
		$parsed_home      = wp_parse_url( home_url() );

		if (
			! empty( $parsed_permalink['host'] )
			&& ! empty( $parsed_home['host'] )
			&& strtolower( $parsed_permalink['host'] ) === strtolower( $parsed_home['host'] )
		) {
			return esc_url_raw( $permalink );
		}

		return '';
	}
}
