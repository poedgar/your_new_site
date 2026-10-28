<?php
/**
 * Cookie Category Service
 *
 * Shared business logic for cookie category CRUD operations.
 * Used by both the REST API (inc/api/cookie-categories.php) and the WordPress
 * Abilities integration (inc/integrations/wordpress/abilities/cookie-categories.php).
 *
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Services
 * @since      0.0.0-alpha.1
 */

namespace SureCookie\Inc\Services;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Functions\Validate;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class CookieCategoryService
 *
 * Handles cookie category CRUD operations.
 * Returns associative arrays (not JSON responses) so callers
 * can adapt the output to their transport layer.
 *
 * @since 0.0.0-alpha.1
 */
class CookieCategoryService {
	/**
	 * Bucket that keeps cookies whose category is deleted, when no other
	 * destination is supplied.
	 *
	 * @since 1.3.0
	 */
	public const DEFAULT_TARGET_CATEGORY = 'uncategorized';

	/**
	 * Get all cookie categories, with the per-category usage tally alongside them.
	 *
	 * `usage` and `in_use` are siblings rather than fields on each record, because
	 * the category shape `{ id, name, description, required }` is assumed verbatim
	 * by the consent-API map, the services resolver and the policy shortcode.
	 * Categories are NEVER filtered out of this response: the admin must be able to
	 * see and edit a category that visitors currently cannot see.
	 *
	 * `disclosure` rides along for the same reason: the banner preview has to
	 * show what a visitor is told, and `window.surecookiePublicSettings` never
	 * resolves in wp-admin.
	 *
	 * @return array{success: bool, message: string, categories: array<string, mixed>, count: int, usage: array<string, array{cookies: int, scripts: int, services: int}>, in_use: array<int, string>, disclosure: array<string, mixed>}
	 * @since 0.0.0-alpha.1
	 */
	public function get_categories(): array {
		$categories = Settings::get( 'cookie_categories' );

		return [
			'success'    => true,
			'message'    => __( 'Cookie categories retrieved successfully.', 'surecookie' ),
			'categories' => $categories,
			'count'      => is_array( $categories ) ? count( $categories ) : 0,
			'usage'      => Get::category_usage_map(),
			'in_use'     => Get::categories_in_use(),
			'disclosure' => Get::category_disclosure(),
		];
	}

	/**
	 * Create a new cookie category.
	 *
	 * @param array<string, mixed> $data Category data with keys: name (required), description, required.
	 * @return array{success: bool, message: string, category?: array<string, mixed>}
	 * @since 0.0.0-alpha.1
	 */
	public function create_category( array $data ): array {
		$name        = sanitize_text_field( $data['name'] ?? '' );
		$description = sanitize_textarea_field( $data['description'] ?? '' );
		$required    = (bool) ( $data['required'] ?? false );

		if ( Validate::empty_string( $name ) ) {
			return [
				'success' => false,
				'message' => __( 'Category name is required.', 'surecookie' ),
			];
		}

		$categories = Settings::get( 'cookie_categories' );
		$categories = is_array( $categories ) ? $categories : [];

		if ( $this->name_taken( $categories, $name ) ) {
			return [
				'success' => false,
				'message' => __( 'A category with this name already exists.', 'surecookie' ),
			];
		}

		$category_id = Get::unique_id( 'category_' );

		$new_category = [
			'id'          => $category_id,
			'name'        => $name,
			'description' => $description,
			'required'    => $required,
		];

		// Add new category to the associative array using ID as key.
		$categories[ $category_id ] = $new_category;
		Settings::update( 'cookie_categories', $categories );

		return [
			'success'  => true,
			'message'  => __( 'Category created successfully.', 'surecookie' ),
			'category' => $new_category,
		];
	}

	/**
	 * Update an existing cookie category.
	 *
	 * Protects default category "required" status from being changed.
	 *
	 * @param string               $category_id The category ID to update.
	 * @param array<string, mixed> $data        Fields to update (name, description, required).
	 * @return array{success: bool, message: string, category?: array<string, mixed>}
	 * @since 0.0.0-alpha.1
	 */
	public function update_category( string $category_id, array $data ): array {
		$category_id = sanitize_text_field( $category_id );

		if ( empty( $category_id ) ) {
			return [
				'success' => false,
				'message' => __( 'Category ID is required.', 'surecookie' ),
			];
		}

		$categories = Settings::get( 'cookie_categories' );

		// Check if category exists using associative array key. Shape too: a scalar row
		// would survive the lookup and then fatal on the field writes below.
		if ( ! isset( $categories[ $category_id ] ) || ! is_array( $categories[ $category_id ] ) ) {
			return [
				'success' => false,
				'message' => __( 'Category not found.', 'surecookie' ),
			];
		}

		$category = $categories[ $category_id ];

		// Default categories have a fixed required status (only "essential" is mandatory).
		$default_category_ids = Get::default_cookie_categories_keys();
		$is_default_category  = in_array( $category_id, $default_category_ids, true );

		// Update fields if provided.
		if ( isset( $data['name'] ) && is_string( $data['name'] ) ) {
			$name = sanitize_text_field( $data['name'] );

			// Guard a real rename only, so an install already holding duplicates
			// can still edit either one.
			if ( $name !== (string) ( $category['name'] ?? '' ) && $this->name_taken( $categories, $name, $category_id ) ) {
				return [
					'success' => false,
					'message' => __( 'A category with this name already exists.', 'surecookie' ),
				];
			}

			$category['name'] = $name;
		}

		if ( isset( $data['description'] ) && is_string( $data['description'] ) ) {
			$category['description'] = sanitize_textarea_field( $data['description'] );
		}

		// Update required status if provided. Default categories keep their fixed status
		// (only "essential" is mandatory; the rest are opt-in per GDPR); custom categories are free.
		if ( isset( $data['required'] ) ) {
			$category['required'] = $is_default_category
				? ( $category_id === 'essential' )
				: (bool) $data['required'];
		}

		$categories[ $category_id ] = $category;
		Settings::update( 'cookie_categories', $categories );

		return [
			'success'  => true,
			'message'  => __( 'Category updated successfully.', 'surecookie' ),
			'category' => $category,
		];
	}

	/**
	 * Delete a cookie category.
	 *
	 * Default categories cannot be deleted. Every cookie filed under the
	 * category - user-defined custom cookies and scanner-detected ones alike -
	 * is either transferred to $target_category or deleted, based on the
	 * $keep_cookies flag. Scanned cookies are grouped by category ID in their
	 * own option, so their bucket has to be emptied here too; left behind, the
	 * rows survive under an ID no registered category renders, which hides them
	 * from Cookie Manager and the banner disclosure while they still occupy the
	 * option.
	 *
	 * @param string $category_id     The category ID to delete.
	 * @param bool   $keep_cookies    Whether to transfer cookies to $target_category (true) or delete them (false).
	 * @param string $target_category Destination category for transferred cookies. Defaults to "uncategorized".
	 * @return array{success: bool, message: string, cookies_moved?: int, cookies_deleted?: int, target_category?: string}
	 * @since 0.0.0-alpha.1
	 * @since 1.3.0 Re-homes scanned cookies too, and honours the $target_category destination.
	 */
	public function delete_category( string $category_id, bool $keep_cookies = false, string $target_category = self::DEFAULT_TARGET_CATEGORY ): array {
		$category_id = sanitize_text_field( $category_id );

		if ( empty( $category_id ) ) {
			return [
				'success' => false,
				'message' => __( 'Category ID is required.', 'surecookie' ),
			];
		}

		// Check if it's a default category - cannot be deleted.
		$default_category_ids = Get::default_cookie_categories_keys();
		if ( in_array( $category_id, $default_category_ids, true ) ) {
			return [
				'success' => false,
				'message' => __( 'Default categories cannot be deleted.', 'surecookie' ),
			];
		}

		$categories = Settings::get( 'cookie_categories' );

		// Check if category exists using associative array key.
		if ( ! isset( $categories[ $category_id ] ) ) {
			return [
				'success' => false,
				'message' => __( 'Category not found.', 'surecookie' ),
			];
		}

		$target_category = sanitize_text_field( $target_category );
		if ( $target_category === '' ) {
			$target_category = self::DEFAULT_TARGET_CATEGORY;
		}

		$custom_cookies = (array) Settings::get( 'custom_cookies' );

		$category_cookies = array_filter(
			$custom_cookies,
			static function ( $cookie ) use ( $category_id ) {
				return isset( $cookie['category'] ) && $cookie['category'] === $category_id;
			}
		);

		$scanned_cookies = Get::option( SURECOOKIE_SCANNED_COOKIES_OPTION, [], 'array' );
		$scanned_cookies = is_array( $scanned_cookies ) ? $scanned_cookies : [];
		$scanned_bucket  = isset( $scanned_cookies[ $category_id ] ) && is_array( $scanned_cookies[ $category_id ] )
			? $scanned_cookies[ $category_id ]
			: [];

		$cookie_count = count( $category_cookies ) + count( $scanned_bucket );

		// Validate the destination only when cookies are actually going to land
		// in it - an unusable target must not block deleting an empty category.
		if ( $keep_cookies && $cookie_count > 0 ) {
			if ( $target_category === $category_id ) {
				return [
					'success' => false,
					'message' => __( 'Cookies cannot be moved to the category being deleted.', 'surecookie' ),
				];
			}

			if ( ! isset( $categories[ $target_category ] ) ) {
				return [
					'success' => false,
					'message' => __( 'The selected category no longer exists. Please reload and try again.', 'surecookie' ),
				];
			}
		}

		// Custom cookies: re-home to the target category, or drop them.
		foreach ( array_keys( $category_cookies ) as $cookie_id ) {
			if ( ! isset( $custom_cookies[ $cookie_id ] ) ) {
				continue;
			}

			if ( $keep_cookies ) {
				$custom_cookies[ $cookie_id ]['category'] = $target_category;
			} else {
				unset( $custom_cookies[ $cookie_id ] );
			}
		}

		Settings::update( 'custom_cookies', $custom_cookies );

		// Scanned cookies: append the whole bucket to the target group, then
		// drop the source key so nothing is stranded under the deleted ID.
		if ( array_key_exists( $category_id, $scanned_cookies ) ) {
			if ( $keep_cookies && ! empty( $scanned_bucket ) ) {
				$target_bucket = isset( $scanned_cookies[ $target_category ] ) && is_array( $scanned_cookies[ $target_category ] )
					? $scanned_cookies[ $target_category ]
					: [];

				$scanned_cookies[ $target_category ] = array_merge( $target_bucket, $scanned_bucket );

				// Picking the destination is an explicit categorisation, so pin it.
				// A scan re-buckets every cookie it reports into the category the
				// scanner assigns, which would otherwise undo this choice the next
				// time the site is scanned. Recorded before the category itself is
				// dropped below, and unaffected by that cleanup because these
				// assignments point at the target, not the deleted ID.
				CookieCategoryMemory::remember( $scanned_bucket, $target_category );
			}

			unset( $scanned_cookies[ $category_id ] );
			Update::option( SURECOOKIE_SCANNED_COOKIES_OPTION, $scanned_cookies );
		}

		// Delete the category from associative array & save.
		unset( $categories[ $category_id ] );
		Settings::update( 'cookie_categories', $categories );

		// Stop remembering scanned-cookie assignments to a category that no
		// longer exists, so they cannot be honoured if the ID is ever reused.
		CookieCategoryMemory::forget_category( $category_id );

		return [
			'success'         => true,
			'message'         => __( 'Category deleted successfully.', 'surecookie' ),
			'cookies_moved'   => $keep_cookies ? $cookie_count : 0,
			'cookies_deleted' => $keep_cookies ? 0 : $cookie_count,
			'target_category' => $keep_cookies && $cookie_count > 0 ? $target_category : '',
		];
	}

	/**
	 * Whether another category already carries this name.
	 *
	 * The id is a fresh UUID so it can never collide; the name is what the admin
	 * sees, and duplicates split their cookies between identical-looking rows.
	 *
	 * @param array<string, mixed> $categories Existing categories keyed by id.
	 * @param string               $name       Sanitized candidate name.
	 * @param string               $ignore_id  Category being renamed, so it keeps its own name.
	 * @since 1.5.0
	 * @return bool
	 */
	private function name_taken( array $categories, string $name, string $ignore_id = '' ): bool {
		$needle = $this->fold( $name );

		foreach ( $categories as $id => $category ) {
			if ( (string) $id === $ignore_id || ! is_array( $category ) ) {
				continue;
			}

			if ( $this->fold( (string) ( $category['name'] ?? '' ) ) === $needle ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalise a name for comparison. Trimmed here, not left to the sanitizer.
	 *
	 * @param string $value Name to fold.
	 * @since 1.5.0
	 * @return string
	 */
	private function fold( string $value ): string {
		$value = trim( $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}
}
