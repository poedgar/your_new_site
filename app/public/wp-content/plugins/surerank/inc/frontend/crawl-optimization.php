<?php
/**
 * Crawl Optimization Class
 * This class is responsible for optimizing crawl settings by removing category bases in URLs
 * and managing rewrite rules for categories and product categories in WooCommerce.
 *
 * @since 1.0.0
 * @package surerank
 */

namespace SureRank\Inc\Frontend;

use SureRank\Inc\Crawl_Optimization\Utils;
use SureRank\Inc\Functions\Helper;
use SureRank\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Crawl_Optimization
 * This class will handle functionality to crawl optimization settings.
 *
 * @since 1.0.0
 */
class Crawl_Optimization {

	use Get_Instance;

	/**
	 * Constructor
	 * Initializes the crawl optimization based on settings and adds necessary actions.
	 *
	 * @since 1.0.0
	 */
	protected function __construct() {

		/**
		 * Filter to remove category base
		 * We need to flush settings while using this filter
		 *
		 * @since 1.0.0
		 */
		if ( apply_filters( 'surerank_remove_category_base', false ) ) {
			add_filter( 'query_vars', [ $this, 'add_custom_redirect_query_var' ] );
			add_filter( 'request', [ $this, 'handle_redirect_for_old_category' ] );
			add_filter( 'category_rewrite_rules', [ $this, 'add_rewrite_rules_for_categories' ] );
			add_filter( 'term_link', [ $this, 'remove_category_base_from_links' ], 10, 3 );
		} else {
			remove_filter( 'query_vars', [ $this, 'add_custom_redirect_query_var' ] );
			remove_filter( 'request', [ $this, 'handle_redirect_for_old_category' ] );
			remove_filter( 'category_rewrite_rules', [ $this, 'add_rewrite_rules_for_categories' ] );
			remove_filter( 'term_link', [ $this, 'remove_category_base_from_links' ], 10 );
		}

		/**
		 * Filter to remove product category base
		 * We need to flush settings while using this filter
		 *
		 * @since 1.0.0
		 */
		if ( Helper::wc_status() && apply_filters( 'surerank_remove_product_category_base', false ) ) {
			add_action( 'created_product_cat', 'flush_rewrite_rules' );
			add_action( 'delete_product_cat', 'flush_rewrite_rules' );
			add_action( 'edited_product_cat', 'flush_rewrite_rules' );
			add_filter( 'product_cat_rewrite_rules', [ $this, 'surerank_filter_product_category_rewrite_rules' ] );
			add_filter( 'term_link', [ $this, 'surerank_remove_product_category_base' ], 10, 3 );
			add_action( 'template_redirect', [ $this, 'surerank_product_category_redirect' ], 1 );
		} else {
			remove_action( 'created_product_cat', 'flush_rewrite_rules' );
			remove_action( 'delete_product_cat', 'flush_rewrite_rules' );
			remove_action( 'edited_product_cat', 'flush_rewrite_rules' );
			remove_filter( 'product_cat_rewrite_rules', [ $this, 'surerank_filter_product_category_rewrite_rules' ] );
			remove_filter( 'term_link', [ $this, 'surerank_remove_product_category_base' ], 10 );
			remove_action( 'template_redirect', [ $this, 'surerank_product_category_redirect' ], 1 );
		}

		/**
		 * Filter to strip the ?replytocom query argument from comment reply links.
		 * Each comment otherwise produces a unique ?replytocom=NN URL that serves
		 * identical page content, which search engines treat as duplicate content
		 * and which wastes crawl budget. Defaults to enabled; can be disabled with
		 * the filter below.
		 *
		 * @since 1.9.0
		 */
		if ( apply_filters( 'surerank_remove_replytocom', true ) ) {
			add_filter( 'comment_reply_link', [ $this, 'remove_replytocom_from_reply_link' ], 10, 1 );
			add_action( 'template_redirect', [ $this, 'redirect_replytocom_request' ], 1 );
		}

		/**
		 * Filter to add rel="nofollow noopener noreferrer" to the post comments
		 * link (the theme's "Leave a comment" / "N Comments" link output by
		 * comments_popup_link(), pointing at #respond). WordPress core adds rel
		 * to comment author and reply links, but not to this one, so crawlers may
		 * otherwise follow it. Defaults to enabled; disable with the filter below,
		 * or customise the rel value via `surerank_comment_form_link_rel`.
		 *
		 * @since 1.9.2
		 */
		if ( apply_filters( 'surerank_nofollow_comment_form_link', true ) ) {
			add_filter( 'comments_popup_link_attributes', [ $this, 'add_rel_to_comment_form_link' ], 10, 1 );
		}
	}

	/**
	 * Remove the ?replytocom Query Argument from Comment Reply Links
	 * Strips the replytocom query argument from the reply link href while keeping
	 * the fragment anchor (e.g. #respond) intact, so the JS-driven reply behavior
	 * still works and search engines no longer index duplicate ?replytocom URLs.
	 *
	 * @param string $link The HTML markup for the comment reply link.
	 * @return string Modified reply link markup.
	 * @since 1.9.0
	 */
	public function remove_replytocom_from_reply_link( $link ) {
		if ( strpos( $link, 'replytocom' ) === false ) {
			return $link;
		}

		$updated = preg_replace_callback(
			'/href=([\'"])(.*?)\1/',
			static function ( $matches ) {
				return 'href=' . $matches[1] . esc_url( remove_query_arg( 'replytocom', $matches[2] ) ) . $matches[1];
			},
			$link
		);

		return $updated !== null ? $updated : $link;
	}

	/**
	 * Redirect legacy ?replytocom request URLs to the canonical comment anchor.
	 *
	 * The link filter above keeps new reply links clean, but URLs already indexed
	 * or linked externally (e.g. example.com/post/?replytocom=10#respond) still
	 * resolve and stay duplicate content. This issues a 301 to the clean URL with
	 * a #comment-<id> fragment so crawlers consolidate the duplicates and humans
	 * still land on the referenced comment. No-op when the argument is absent, so
	 * sites without comments are unaffected.
	 *
	 * @since 1.9.2
	 * @return void
	 */
	public function redirect_replytocom_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only canonical redirect; no state is changed.
		if ( ! isset( $_GET['replytocom'] ) ) {
			return;
		}

		if ( is_feed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $request_uri ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only canonical redirect; no state is changed.
		$comment_id = absint( wp_unslash( $_GET['replytocom'] ) );

		$clean = remove_query_arg( 'replytocom', $request_uri );
		$clean = strtok( $clean, '#' );
		if ( ! is_string( $clean ) ) {
			return;
		}

		$location = $comment_id > 0 ? home_url( $clean ) . '#comment-' . $comment_id : home_url( $clean );

		wp_safe_redirect( $location, 301 );
		exit;
	}

	/**
	 * Add rel tokens to the post comments link without clobbering existing attributes.
	 *
	 * Unlike a blanket attribute replacement, this preserves any attributes the
	 * theme already set and merges the rel tokens (de-duplicated) into an existing
	 * rel attribute when one is present. The rel value is filterable via
	 * `surerank_comment_form_link_rel`.
	 *
	 * @param string $attributes Existing anchor attributes for the comments link.
	 * @return string Attributes with the rel tokens merged in.
	 * @since 1.9.2
	 */
	public function add_rel_to_comment_form_link( $attributes ) {
		$rel = apply_filters( 'surerank_comment_form_link_rel', 'nofollow noopener noreferrer' );
		if ( ! is_string( $rel ) || '' === trim( $rel ) ) {
			return $attributes;
		}

		$rel_tokens = array_filter( explode( ' ', $rel ) );

		if ( preg_match( '/(^|\s)rel\s*=\s*([\'"])(.*?)\2/i', $attributes, $matches ) ) {
			$existing    = array_filter( explode( ' ', $matches[3] ) );
			$merged      = array_unique( array_merge( $existing, $rel_tokens ) );
			$replacement = $matches[1] . 'rel=' . $matches[2] . esc_attr( implode( ' ', $merged ) ) . $matches[2];
			return str_replace( $matches[0], $replacement, $attributes );
		}

		return trim( $attributes . ' rel="' . esc_attr( implode( ' ', array_unique( $rel_tokens ) ) ) . '"' );
	}

	/**
	 * Remove Category Base from Links
	 * Removes the base from category links to create cleaner URLs.
	 *
	 * @param string $termlink Original term link.
	 * @param object $term Term object.
	 * @param string $taxonomy Taxonomy name.
	 * @return string Modified term link.
	 * @since 1.0.0
	 */
	public function remove_category_base_from_links( $termlink, $term, $taxonomy ) {
		if ( 'category' !== $taxonomy ) {
			return $termlink;
		}
		$category_base = get_option( 'category_base' ) ? get_option( 'category_base' ) : 'category';

		if ( strpos( $termlink, '/' . $category_base . '/' ) !== false ) {
			$termlink = str_replace( '/' . $category_base . '/', '/', $termlink );
		}

		return $termlink;
	}

	/**
	 * Add Custom Redirect Query Var
	 * Adds a custom query variable to handle category redirects.
	 *
	 * @param array<string, mixed> $query_vars Array of query variables.
	 * @return array<string, mixed>|array<int, string> Modified query variables.
	 * @since 1.0.0
	 */
	public function add_custom_redirect_query_var( $query_vars ) {
		$query_vars[] = 'custom_category_redirect';
		return $query_vars;
	}

	/**
	 * Handle Redirect for Old Category
	 * Redirects users from old category URLs to new URLs without category bases.
	 *
	 * @param array<string, mixed> $query_vars Array of query variables.
	 * @return array<string, mixed>|array<int, string> Modified query variables.
	 * @since 1.0.0
	 */
	public function handle_redirect_for_old_category( $query_vars ) {
		if ( isset( $query_vars['custom_category_redirect'] ) ) {
			$redirect_url = home_url( trailingslashit( $query_vars['custom_category_redirect'] ) );
			wp_safe_redirect( $redirect_url, 301 );
			exit;
		}
		return $query_vars;
	}

	/**
	 * Add Rewrite Rules for Categories
	 * Generates rewrite rules to handle clean category URLs.
	 *
	 * @param array<string, mixed> $rules Existing rewrite rules.
	 * @return array<string, mixed> Modified rewrite rules.
	 * @since 1.0.0
	 */
	public function add_rewrite_rules_for_categories( $rules ) {
		$base          = get_option( 'category_base' );
		$category_base = $base ? $base : 'category';

		$custom_rule = [ "^{$category_base}/(.+)/?$" => 'index.php?custom_category_redirect=$matches[1]' ];

		$clean_category_rules = $this->generate_clean_category_rules();

		return array_merge( $custom_rule, $clean_category_rules, $rules );
	}

	/**
	 * Filter Product Category Rewrite Rules
	 * Modifies rewrite rules for product categories in WooCommerce.
	 *
	 * @param array<string, mixed> $rules Existing product category rewrite rules.
	 * @return array<string, mixed> Modified rewrite rules.
	 */
	public function surerank_filter_product_category_rewrite_rules( $rules ) {
		$categories = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			]
		);

		if ( is_array( $categories ) && ! empty( $categories ) ) {
			// Build term ID map once to avoid N+1 queries in get_full_category_slug_path().
			$term_map = [];
			foreach ( $categories as $category ) {
				$term_map[ $category->term_id ] = $category;
			}

			$slugs = [];
			foreach ( $categories as $category ) {
				$slugs[] = Utils::get_full_category_slug_path( $category, $term_map );
			}

			$rules = [];
			foreach ( $slugs as $slug ) {
				$rules[ '(' . $slug . ')(/page/(\d+))?/?$' ]    = 'index.php?product_cat=$matches[1]&paged=$matches[3]';
				$rules[ $slug . '/(.+?)/page/?([0-9]{1,})/?$' ] = 'index.php?product_cat=$matches[1]&paged=$matches[2]';
				$rules[ $slug . '/(.+?)/?$' ]                   = 'index.php?product_cat=$matches[1]';
			}
		}

		return apply_filters( 'surerank_product_category_rewrite_rules', $rules );
	}

	/**
	 * Remove Product Category Base
	 * Removes the product category base from product category links.
	 *
	 * @param string $termlink Original term link.
	 * @param object $term Term object.
	 * @param string $taxonomy Taxonomy name.
	 * @return string Modified term link.
	 * @since 1.0.0
	 */
	public function surerank_remove_product_category_base( $termlink, $term, $taxonomy ) {
		if ( 'product_cat' === $taxonomy ) {
			$category_base = get_option( 'woocommerce_permalinks' )['category_base'] ? get_option( 'woocommerce_permalinks' )['category_base'] : 'product-category';
			$termlink      = str_replace( '/' . $category_base, '', $termlink );
		}
		return $termlink;
	}

	/**
	 * Product Category Redirect
	 * Redirects URLs containing the product category base to clean URLs.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function surerank_product_category_redirect() {
		if ( ! is_404() ) {
			return;
		}
		$category_base = get_option( 'woocommerce_permalinks' )['category_base'] ? get_option( 'woocommerce_permalinks' )['category_base'] : 'product-category';
		global $wp;
		$current_url = user_trailingslashit( home_url( add_query_arg( [], $wp->request ) ) );
		$regex       = sprintf( '/\/%s\//', str_replace( '/', '\/', $category_base ) );
		if ( preg_match( $regex, $current_url ) ) {
			$new_url = str_replace( '/' . $category_base, '', $current_url );
			wp_safe_redirect( $new_url, 301 );
			exit;
		}
	}

	/**
	 * Generate Clean Category Rules
	 * Generates rewrite rules for each category to allow cleaner URLs.
	 *
	 * @return array<string, mixed> Generated rewrite rules for categories.
	 * @since 1.0.0
	 */
	private function generate_clean_category_rules() {
		$rewrite_rules = [];
		$categories    = Utils::get_all_categories();
		$prefix        = Utils::get_blog_prefix();

		// Build term ID map once to avoid N+1 queries from get_category_parents().
		$term_map = [];
		foreach ( $categories as $category ) {
			$term_map[ $category->term_id ] = $category;
		}

		foreach ( $categories as $category ) {
			$path          = $this->get_category_path_from_map( $category, $term_map );
			$rewrite_rules = $this->append_category_rewrite( $rewrite_rules, $path, $prefix );
		}
		return $rewrite_rules;
	}

	/**
	 * Build category path using in-memory term map to avoid N+1 queries.
	 *
	 * @param \WP_Term             $category The category term object.
	 * @param array<int, \WP_Term> $term_map Term ID to term object map.
	 * @return string The full slug path for the category.
	 * @since 1.7.0
	 */
	private function get_category_path_from_map( $category, $term_map ) {
		$slugs   = [ $category->slug ];
		$current = $category;

		while ( $current->parent && isset( $term_map[ $current->parent ] ) ) {
			$current = $term_map[ $current->parent ];
			array_unshift( $slugs, $current->slug );
		}

		return implode( '/', $slugs );
	}

	/**
	 * Append Category Rewrite
	 * Adds a specific category's rewrite rule to the rules array.
	 *
	 * @param array<string, mixed> $rules Existing rewrite rules.
	 * @param string               $path Category path.
	 * @param string               $prefix Blog prefix if applicable.
	 * @return array<string, mixed> Modified rewrite rules.
	 * @since 1.0.0
	 */
	private function append_category_rewrite( $rules, $path, $prefix ) {
		$rules[ "^{$prefix}({$path})/page/([0-9]{1,})/?$" ] = 'index.php?category_name=$matches[1]&paged=$matches[2]';
		$rules[ "^{$prefix}({$path})/?$" ]                  = 'index.php?category_name=$matches[1]';
		return $rules;
	}
}
