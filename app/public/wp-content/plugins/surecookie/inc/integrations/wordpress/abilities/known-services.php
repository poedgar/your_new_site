<?php
/**
 * Known Services Ability
 *
 * Declares a vendor's cookies and blocking patterns from the service catalog
 * in one step, instead of hand-crafting each cookie definition.
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress/Abilities
 * @since      1.4.0
 */

namespace SureCookie\Inc\Integrations\Wordpress\Abilities;

use SureCookie\Inc\Integrations\Wordpress\Base;
use SureCookie\Inc\Services\KnownServicesService;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class KnownServices
 *
 * @since 1.4.0
 */
class KnownServices extends Base {
	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input The validated input data.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		try {
			$action  = $input['action'] ?? 'list';
			$service = new KnownServicesService();
			$slug    = sanitize_key( $input['slug'] ?? '' );

			switch ( $action ) {
				case 'list':
					return [
						'success' => true,
						'message' => __( 'Service catalog retrieved.', 'surecookie' ),
						'data'    => $this->filter_list( $service->list_services(), $input ),
					];

				case 'install':
					if ( $slug === '' ) {
						return $this->missing_slug();
					}

					return $service->install( $slug );

				case 'uninstall':
					if ( $slug === '' ) {
						return $this->missing_slug();
					}

					return $service->uninstall( $slug );

				case 'refresh_catalog':
					return $service->refresh_catalog();

				default:
					return [
						'success' => false,
						'message' => __( 'Invalid known services action.', 'surecookie' ),
						'data'    => [],
					];
			}
		} catch ( \Throwable $e ) {
			return [
				'success' => false,
				'message' => __( 'An unexpected error occurred while managing known services.', 'surecookie' ),
				'data'    => [],
			];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_name(): string {
		return 'surecookie/known-services';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label(): string {
		return __( 'Known Services', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description(): string {
		return __( 'Declare a third-party vendor\'s cookies and blocking patterns from SureCookie\'s service catalog in one step, instead of hand-crafting each cookie definition with surecookie/cookie-management. Actions: "list" returns every catalog service with its state — "installed" (already declared here), "detected" (found by the last scan but not yet declared, the ones usually worth installing) or "available" — plus how many cookies, scripts and iframes it covers, and whether it is Pro-only. "install" declares one service\'s cookies by slug and records it. "uninstall" removes that service\'s declared cookies again. "refresh_catalog" re-fetches the catalog from SureCookie\'s servers. Start with "list" and prefer installing the services in "detected" state, since those are the vendors actually present on this site. Installing reports cookies_added and cookies_already_present separately: a service whose cookies all already exist installs successfully while adding nothing new, which is a success, not a failure.', 'surecookie' );
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
			'openWorldHint'   => true,
			'instructions'    => 'DESTRUCTIVE — "uninstall" deletes the cookie definitions that service declared, which removes them from the preferences modal and the cookie policy; confirm with the user first and name the service. "install" is safe to repeat: it reuses the existing entry and never duplicates a cookie. "refresh_catalog" makes an outbound request to SureCookie\'s servers and is not rate-limited, so call it at most once per task and only when the catalog looks stale — never in a loop or as a retry. Call "list" first: it is the only way to learn valid slugs, and its "detected" state tells you which vendors this site actually loads. On a site without a Pro licence, Pro-only services report locked=true and installing one is refused.',
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
					'enum'        => [ 'list', 'install', 'uninstall', 'refresh_catalog' ],
					'description' => __( 'The known services action to perform.', 'surecookie' ),
				],
				'slug'     => [
					'type'        => 'string',
					'description' => __( 'Catalog service slug, e.g. "google-analytics". Required for install and uninstall; take it from "list".', 'surecookie' ),
				],
				'state'    => [
					'type'        => 'string',
					'enum'        => [ 'installed', 'detected', 'available' ],
					'description' => __( 'For "list", return only services in this state. "detected" is the useful one after a scan.', 'surecookie' ),
				],
				'category' => [
					'type'        => 'string',
					'description' => __( 'For "list", filter by catalog category. The bundled catalog uses essential, functional, analytics and marketing; "uncategorized" is only a code fallback and will normally match nothing.', 'surecookie' ),
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
				'code'    => [
					'type'        => 'string',
					'description' => __( 'Failure reason: "unknown_service" or "pro_required".', 'surecookie' ),
				],
				'data'    => [
					'type'        => 'object',
					'description' => __( 'Action payload. For "install", cookies_added lists newly created definitions and cookies_already_present lists ones that existed already; both empty with success true means the service was already fully declared.', 'surecookie' ),
				],
			],
		];
	}

	/**
	 * Apply the optional list filters.
	 *
	 * @param array<string, mixed> $list  Catalog listing.
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	private function filter_list( array $list, array $input ): array {
		$state    = sanitize_key( $input['state'] ?? '' );
		$category = sanitize_key( $input['category'] ?? '' );

		if ( $state === '' && $category === '' ) {
			return $list;
		}

		$list['services'] = array_values(
			array_filter(
				$list['services'],
				static function ( array $service ) use ( $state, $category ): bool {
					if ( $state !== '' && $service['state'] !== $state ) {
						return false;
					}

					return $category === '' || $service['category'] === $category;
				}
			)
		);

		return $list;
	}

	/**
	 * Failure payload for a missing slug.
	 *
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	private function missing_slug(): array {
		return [
			'success' => false,
			'message' => __( 'A service slug is required. Use action "list" to see the available slugs.', 'surecookie' ),
			'data'    => [],
		];
	}
}
