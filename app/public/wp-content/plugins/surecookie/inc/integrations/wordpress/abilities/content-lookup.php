<?php
/**
 * Content Lookup Ability
 *
 * Read-only resolver turning human names into the WordPress IDs that several
 * SureCookie settings store.
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress/Abilities
 * @since      1.4.0
 */

namespace SureCookie\Inc\Integrations\Wordpress\Abilities;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Integrations\Wordpress\Base;
use SureCookie\Inc\Services\ContentService;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ContentLookup
 *
 * @since 1.4.0
 */
class ContentLookup extends Base {
	/**
	 * Maximum IDs resolvable in one get_posts call.
	 *
	 * @since 1.4.0
	 */
	private const MAX_IDS = 50;

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input The validated input data.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		try {
			$action  = $input['action'] ?? 'search_posts';
			$service = new ContentService();

			switch ( $action ) {
				case 'search_posts':
					$per_page = absint( $input['per_page'] ?? 20 );

					// Per-type cap for parity with the picker, plus a total
					// ceiling so a CPT-heavy site cannot return per_page * N rows.
					$result = $service->search_posts(
						sanitize_text_field( $input['search'] ?? '' ),
						$per_page,
						$this->resolve_context( $input['context'] ?? 'policy' ),
						$per_page
					);

					return [
						'success' => true,
						'message' => __( 'Content retrieved.', 'surecookie' ),
						'data'    => $result,
					];

				case 'get_posts':
					$ids = is_array( $input['ids'] ?? null ) ? $input['ids'] : [];

					if ( empty( $ids ) ) {
						return [
							'success' => false,
							'message' => __( 'No post IDs provided.', 'surecookie' ),
							'data'    => [],
						];
					}

					return [
						'success' => true,
						'message' => __( 'Content retrieved.', 'surecookie' ),
						'data'    => [
							'posts' => $service->get_posts(
								array_slice( $ids, 0, self::MAX_IDS ),
								$this->resolve_context( $input['context'] ?? 'any' )
							),
						],
					];

				case 'list_nav_menus':
					return [
						'success' => true,
						'message' => __( 'Navigation menus retrieved.', 'surecookie' ),
						'data'    => [ 'menus' => Get::nav_menus() ],
					];

				default:
					return [
						'success' => false,
						'message' => __( 'Invalid content lookup action.', 'surecookie' ),
						'data'    => [],
					];
			}
		} catch ( \Throwable $e ) {
			return [
				'success' => false,
				'message' => __( 'An unexpected error occurred while looking up content.', 'surecookie' ),
				'data'    => [],
			];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_name(): string {
		return 'surecookie/content-lookup';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label(): string {
		return __( 'Content Lookup', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description(): string {
		return __( 'Look up WordPress content and navigation menus to obtain the numeric IDs other SureCookie abilities and settings need. Never writes anything. Actions: "search_posts" finds published content by title and returns id, title and post type. "get_posts" resolves up to 50 known IDs at once and reports each one as found or missing, so a saved selection can be shown to the user as names instead of bare numbers. "list_nav_menus" returns the site\'s classic navigation menus, whose id is exactly what the reconsent_menu_id setting expects; an empty list means the theme has no classic menus (block themes return none), not that the feature is broken. The "context" argument mirrors which post types SureCookie\'s admin pickers offer: "policy" for the cookie policy page, "scanner" for the cookie scanner, "any" for every public post type. Pass the context matching the setting you are about to write — nothing downstream validates post type, so an ID of the wrong type is stored silently and simply does not behave as intended. Typical flow: search_posts to turn a page name into an ID, then pass it to surecookie/site-scanner action "start", or to surecookie/manage-settings for cookie_policy_page_id, auto_scan_pages or reconsent_menu_id.', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_annotations(): array {
		return [
			'priority'        => 1.0,
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
			'instructions'    => 'Safe to call at any time — reads only the local database and never contacts an external service. Call "search_posts" to turn a page name into an ID before writing cookie_policy_page_id, scan_pages, auto_scan_pages or reconsent_menu_id, and pass the context matching that setting: nothing downstream validates the post type, so a wrong-context ID is stored silently and the feature quietly points at the wrong content. Use "get_posts" to read a saved ID list back to the user as names; a row with found=false is a stale ID worth reporting, while found=true with in_context=false means the ID works but is a post type that setting\'s picker would not have offered. An empty "list_nav_menus" result means the theme has no classic menus, not an error.',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'action'   => [
					'type'        => 'string',
					'enum'        => [ 'search_posts', 'get_posts', 'list_nav_menus' ],
					'description' => __( 'The lookup action to perform.', 'surecookie' ),
				],
				'search'   => [
					'type'        => 'string',
					'description' => __( 'For "search_posts", a title fragment. Empty lists content alphabetically.', 'surecookie' ),
				],
				'per_page' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'description' => __( 'For "search_posts", the maximum rows per post type, and also the ceiling on the combined result. Default 20.', 'surecookie' ),
				],
				'ids'      => [
					'type'        => 'array',
					'maxItems'    => 50,
					'items'       => [ 'type' => 'integer' ],
					'description' => __( 'For "get_posts", the post IDs to resolve.', 'surecookie' ),
				],
				'context'  => [
					'type'        => 'string',
					'enum'        => [ 'policy', 'scanner', 'any' ],
					'description' => __( 'Which picker\'s post types to use: "policy" for the cookie policy page, "scanner" for the cookie scanner, "any" for every public post type. Defaults to "policy" for search_posts and "any" for get_posts.', 'surecookie' ),
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
					'description' => __( 'Whether the lookup succeeded.', 'surecookie' ),
				],
				'message' => [
					'type'        => 'string',
					'description' => __( 'Result message.', 'surecookie' ),
				],
				'data'    => [
					'type'        => 'object',
					'description' => __( 'Lookup results: "posts" for search_posts and get_posts, "menus" for list_nav_menus. search_posts also returns "post_types", the resolved allowlist actually queried. In get_posts rows, "found" false means the ID is missing or unpublished and the other fields are empty strings; "in_context" reports whether the post type is one the requested picker offers.', 'surecookie' ),
				],
			],
		];
	}

	/**
	 * Constrain the context to a known picker.
	 *
	 * @param mixed $context Requested context.
	 * @return string
	 * @since 1.4.0
	 */
	private function resolve_context( $context ): string {
		$context = is_string( $context ) ? $context : '';

		return in_array( $context, [ 'policy', 'scanner', 'any' ], true ) ? $context : 'policy';
	}
}
