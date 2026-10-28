<?php
/**
 * Cookie Management Ability
 *
 * Multi-action ability for custom cookie CRUD and scanned cookie operations.
 * Delegates business logic to the shared CookieService.
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress/Abilities
 * @since      0.0.1-alpha.1
 */

namespace SureCookie\Inc\Integrations\Wordpress\Abilities;

use SureCookie\Inc\Integrations\Wordpress\Base;
use SureCookie\Inc\Services\CookieService;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class CookieManagement
 *
 * Provides cookie listing, custom cookie CRUD, and scanned cookie recategorization.
 *
 * @since 0.0.1-alpha.1
 */
class CookieManagement extends Base {
	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input The validated input data.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		try {
			$action  = $input['action'] ?? '';
			$service = new CookieService();

			switch ( $action ) {
				case 'list_scanned':
					return $service->get_scanned_cookies();

				case 'list_custom':
					return $service->get_custom_cookies();

				case 'create_custom':
					return $service->create_custom_cookie( $input );

				case 'update_custom':
					$cookie_id = sanitize_text_field( $input['cookie_id'] ?? '' );
					return $service->update_custom_cookie( $cookie_id, $input );

				case 'delete_custom':
					$cookie_id = sanitize_text_field( $input['cookie_id'] ?? '' );
					return $service->delete_custom_cookie( $cookie_id );

				case 'recategorize_scanned':
					$cookie_name      = sanitize_text_field( $input['cookie_name'] ?? '' );
					$current_category = sanitize_text_field( $input['current_category'] ?? '' );
					$domain           = sanitize_text_field( $input['domain'] ?? '' );

					// Mirrors the REST route: only what was actually supplied, or an
					// absent field would clear a correction nobody asked to clear.
					$changes = [];
					foreach ( [
						'new_category' => 'category',
						'purpose'      => 'purpose',
						'duration'     => 'duration',
						'provider'     => 'provider',
					] as $key => $field ) {
						if ( array_key_exists( $key, $input ) ) {
							$changes[ $field ] = (string) $input[ $key ];
						}
					}

					return $service->update_scanned_cookie( $cookie_name, $current_category, $changes, $domain );

				case 'delete_scanned':
					return $service->delete_scanned_cookie(
						sanitize_text_field( $input['cookie_name'] ?? '' ),
						sanitize_text_field( $input['category'] ?? '' ),
						sanitize_text_field( $input['domain'] ?? '' )
					);

				case 'bulk_recategorize':
					return $service->bulk_update_cookie_category(
						is_array( $input['items'] ?? null ) ? $input['items'] : [],
						sanitize_text_field( $input['new_category'] ?? '' )
					);

				default:
					return [
						'success' => false,
						'message' => __( 'Invalid action specified.', 'surecookie' ),
					];
			}
		} catch ( \Throwable $e ) {
			return [
				'success' => false,
				'message' => __( 'An unexpected error occurred while managing cookies.', 'surecookie' ),
			];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_name(): string {
		return 'surecookie/cookie-management';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label(): string {
		return __( 'Cookie Management', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description(): string {
		return __( 'Manage cookies detected and defined by SureCookie. Actions: "list_scanned" returns all cookies found by the site scanner with their name, category, provider, and description. "list_custom" returns manually defined cookies. "create_custom" adds a new custom cookie definition (requires name and category). "update_custom" modifies an existing custom cookie by cookie_id. "delete_custom" permanently removes a custom cookie definition by cookie_id — this cannot be undone. "recategorize_scanned" moves a scanned cookie from one category to another, and can also correct the purpose, duration or provider the scanner reported. "delete_scanned" removes a scanned cookie from the stored set, which is how a stale entry is cleared from the public cookie policy; a cookie that is still present on the site simply reappears on the next scan. "bulk_recategorize" moves up to 100 cookies, custom and scanned together, into one category in a single write — prefer it over repeated "recategorize_scanned" calls when triaging scan results, since each single move rewrites the whole cookie store. Use surecookie/cookie-categories with action "list" to discover valid category IDs before creating or recategorizing cookies.', 'surecookie' );
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
			'instructions'    => 'DESTRUCTIVE — "delete_custom" permanently removes a custom cookie definition and cannot be undone. "delete_scanned" removes a scanned cookie from the stored set; it is not permanent in the same way, since a cookie still present on the site returns on the next scan, but it does change what the public cookie policy discloses. Always ask the user to confirm before deleting and show them the cookie name and ID. For "create_custom", call "list_custom" first to avoid creating duplicate cookie definitions. For "recategorize_scanned", call "list_scanned" first to verify the cookie exists in the specified current category. The "list_scanned" and "list_custom" actions are safe to call at any time.',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'action'           => [
					'type'        => 'string',
					'enum'        => [
						'list_scanned',
						'list_custom',
						'create_custom',
						'update_custom',
						'delete_custom',
						'recategorize_scanned',
						'delete_scanned',
						'bulk_recategorize',
					],
					'description' => __( 'The cookie management action to perform.', 'surecookie' ),
				],
				'items'            => [
					'type'        => 'array',
					'maxItems'    => 100,
					'description' => __( 'For "bulk_recategorize", the cookies to move (up to 100). Use "list_scanned" and "list_custom" first to discover them.', 'surecookie' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'type'             => [
								'type'        => 'string',
								'enum'        => [ 'custom', 'scanned' ],
								'description' => __( 'Which store the cookie lives in.', 'surecookie' ),
							],
							'id'               => [
								'type'        => 'string',
								'description' => __( 'Cookie ID. Required when type is "custom".', 'surecookie' ),
							],
							'name'             => [
								'type'        => 'string',
								'description' => __( 'Cookie name. Required when type is "scanned".', 'surecookie' ),
							],
							'current_category' => [
								'type'        => 'string',
								'description' => __( 'Current category ID. Required when type is "scanned".', 'surecookie' ),
							],
							'domain'           => [
								'type'        => 'string',
								'description' => __( 'Optional. Disambiguates two scanned cookies sharing a name.', 'surecookie' ),
							],
						],
					],
				],
				'cookie_id'        => [
					'type'        => 'string',
					'description' => __( 'Cookie ID for update/delete operations.', 'surecookie' ),
				],
				'name'             => [
					'type'        => 'string',
					'description' => __( 'Cookie name (required for create).', 'surecookie' ),
				],
				'category'         => [
					'type'        => 'string',
					'description' => __( 'Cookie category ID (required for create).', 'surecookie' ),
				],
				'description'      => [
					'type'        => 'string',
					'description' => __( 'Cookie description.', 'surecookie' ),
				],
				'duration'         => [
					'type'        => 'string',
					'description' => __( 'Cookie duration in days.', 'surecookie' ),
				],
				'provider'         => [
					'type'        => 'string',
					'description' => __( 'Cookie provider.', 'surecookie' ),
				],
				'purpose'          => [
					'type'        => 'string',
					'description' => __( 'Cookie purpose.', 'surecookie' ),
				],
				'domain'           => [
					'type'        => 'string',
					'description' => __( 'Cookie domain. For recategorize_scanned, supply it when two scanned cookies share a name so the right one is moved.', 'surecookie' ),
				],
				'cookie_name'      => [
					'type'        => 'string',
					'description' => __( 'Scanned cookie name (for recategorize_scanned action).', 'surecookie' ),
				],
				'current_category' => [
					'type'        => 'string',
					'description' => __( 'Current category ID (for recategorize_scanned action).', 'surecookie' ),
				],
				'new_category'     => [
					'type'        => 'string',
					'description' => __( 'Target category ID (for recategorize_scanned action).', 'surecookie' ),
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
