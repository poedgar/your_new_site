<?php
/**
 * Cookie Categories Ability
 *
 * Multi-action ability for cookie category CRUD operations.
 * Delegates business logic to the shared CookieCategoryService.
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress/Abilities
 * @since      0.0.1-alpha.1
 */

namespace SureCookie\Inc\Integrations\Wordpress\Abilities;

use SureCookie\Inc\Integrations\Wordpress\Base;
use SureCookie\Inc\Services\CookieCategoryService;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class CookieCategories
 *
 * Provides cookie category listing, creation, updating, and deletion.
 *
 * @since 0.0.1-alpha.1
 */
class CookieCategories extends Base {
	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input The validated input data.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		try {
			$action  = $input['action'] ?? '';
			$service = new CookieCategoryService();

			switch ( $action ) {
				case 'list':
					return $service->get_categories();

				case 'create':
					return $service->create_category( $input );

				case 'update':
					$category_id = sanitize_text_field( $input['category_id'] ?? '' );
					return $service->update_category( $category_id, $input );

				case 'delete':
					$category_id     = sanitize_text_field( $input['category_id'] ?? '' );
					$keep_cookies    = (bool) ( $input['keep_cookies'] ?? false );
					$target_category = sanitize_text_field( (string) ( $input['target_category'] ?? '' ) );
					return $service->delete_category( $category_id, $keep_cookies, $target_category );

				default:
					return [
						'success' => false,
						'message' => __( 'Invalid action specified.', 'surecookie' ),
					];
			}
		} catch ( \Throwable $e ) {
			return [
				'success' => false,
				'message' => __( 'An unexpected error occurred while managing cookie categories.', 'surecookie' ),
			];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_name(): string {
		return 'surecookie/cookie-categories';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label(): string {
		return __( 'Cookie Categories', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description(): string {
		return __( 'Manage cookie categories used to organize cookies by purpose (e.g., Necessary, Analytics, Marketing). Actions: "list" returns all categories with their ID, name, description, required status, and cookie count. "create" adds a new category (requires name). "update" modifies a category by category_id (name, description, required status). "delete" permanently removes a category by category_id — use "keep_cookies" to control whether associated cookies are moved to another category (true) or deleted (false), and "target_category" to choose which category receives them (defaults to uncategorized). Default/built-in categories (e.g., Necessary) cannot be deleted and their "required" status cannot be changed. Deleting a category that contains cookies without setting keep_cookies to true will also delete those cookie definitions.', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_annotations(): array {
		return [
			'priority'        => 3.0,
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
			'instructions'    => 'DESTRUCTIVE — the "delete" action permanently removes a cookie category and cannot be undone. When deleting, always set "keep_cookies" to true to preserve cookie definitions by moving them to another category, unless the user explicitly wants to delete the cookies as well. Pass "target_category" when the user names a destination; it defaults to uncategorized. Show the user the category name and its cookie count before confirming deletion. Default categories (e.g., Necessary) are protected and cannot be deleted. Always call "list" first to show existing categories before creating or deleting.',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'action'          => [
					'type'        => 'string',
					'enum'        => [ 'list', 'create', 'update', 'delete' ],
					'description' => __( 'The category action to perform.', 'surecookie' ),
				],
				'category_id'     => [
					'type'        => 'string',
					'description' => __( 'Category ID for update/delete operations.', 'surecookie' ),
				],
				'name'            => [
					'type'        => 'string',
					'description' => __( 'Category name (required for create).', 'surecookie' ),
				],
				'description'     => [
					'type'        => 'string',
					'description' => __( 'Category description.', 'surecookie' ),
				],
				'required'        => [
					'type'        => 'boolean',
					'description' => __( 'Whether the category is required (users cannot opt out).', 'surecookie' ),
				],
				'keep_cookies'    => [
					'type'        => 'boolean',
					'description' => __( 'For delete action: true to move cookies to another category, false to delete them.', 'surecookie' ),
				],
				'target_category' => [
					'type'        => 'string',
					'description' => __( 'For delete action with keep_cookies: ID of the category that receives the cookies. Defaults to "uncategorized".', 'surecookie' ),
				],
			],
			'required'   => [ 'action' ],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'success' => [
					'type'        => 'boolean',
					'description' => __( 'Whether the operation succeeded.', 'surecookie' ),
				],
				'message' => [
					'type'        => 'string',
					'description' => __( 'Result message.', 'surecookie' ),
				],
				'data'    => [
					'type'        => 'object',
					'description' => __( 'Result data varying by action.', 'surecookie' ),
				],
			],
		];
	}
}
