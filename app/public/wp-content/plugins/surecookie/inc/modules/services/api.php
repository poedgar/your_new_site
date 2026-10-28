<?php
/**
 * Known Services API.
 *
 * Powers the Known Services library: a full catalog listing (every service,
 * including cookieless ones, with tier + counts) plus the installed + detected
 * state, and the atomic add/remove endpoints. Blocking is independent of this
 * registry, so these endpoints only manage the declared/policy state.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.3.0
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\API\Base;
use SureCookie\Inc\Functions\Cookie_Identity;
use SureCookie\Inc\Services\KnownServicesService;
use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Api - the Known Services REST controller (GET/POST/DELETE /known-services).
 *
 * @since 1.3.0
 */
class Api extends Base {
	use GetInstance;

	/**
	 * Route path.
	 *
	 * @since 1.3.0
	 */
	protected const ROUTE = '/known-services';

	/**
	 * Recognised-services route. Deliberately not nested under ROUTE: the slug
	 * route's `[a-z0-9-]+` segment would also match it.
	 *
	 * @since 1.5.1
	 */
	protected const RECOGNISED_ROUTE = '/recognised-services';

	/**
	 * Register API routes.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			self::ROUTE,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_known_services' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::RECOGNISED_ROUTE,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_recognised_services' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::ROUTE . '/(?P<slug>[a-z0-9-]+)',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE, // POST: install.
					'callback'            => [ $this, 'install_service' ],
					'permission_callback' => [ $this, 'validate_permission' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE, // DELETE: uninstall.
					'callback'            => [ $this, 'uninstall_service' ],
					'permission_callback' => [ $this, 'validate_permission' ],
				],
			]
		);
	}

	/**
	 * Full catalog + installed + detected state for the library.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @since 1.3.0
	 * @return WP_REST_Response { services:[], installed:[], detected:[] }.
	 */
	public function get_known_services( $request ): WP_REST_Response {
		$catalog   = Services_Source::get_instance()->get_catalog();
		$installed = Installed_Services::get_instance()->get_installed();
		$services  = [];

		foreach ( $catalog as $slug => $service ) {
			if ( ! is_string( $slug ) || ! is_array( $service ) ) {
				continue;
			}

			// Patterns are matchers, not cookies: install() will not add them, so the
			// library card must not count or list them either, or it promises seven
			// cookies for Google Analytics and adds five.
			$cookies = array_values(
				array_filter(
					(array) ( $service['cookies'] ?? [] ),
					static fn( $cookie ): bool => is_array( $cookie )
						&& ! Cookie_Identity::is_pattern( (string) ( $cookie['name'] ?? '' ) )
				)
			);
			$summary = Services_Source::pattern_summary( $service );

			$services[] = array_merge(
				[
					'slug'        => $slug,
					'label'       => (string) ( $service['label'] ?? $slug ),
					'description' => (string) ( $service['description'] ?? '' ),
					'category'    => (string) ( $service['category'] ?? 'uncategorized' ),
					'pro'         => ! empty( $service['pro'] ),
					'cookieCount' => count( $cookies ),
					'cookies'     => $cookies,
					'resources'   => Services_Source::pattern_lists( $service ),
				],
				$summary
			);
		}

		$recognised = Recognised_Services::get_instance();

		return new WP_REST_Response(
			[
				'services'   => $services,
				'installed'  => array_values( $installed ),
				'detected'   => $recognised->slugs(),
				'recognised' => $recognised->report(),
			],
			200
		);
	}

	/**
	 * Services this site is known to load, with their declared state.
	 *
	 * Kept apart from the catalog listing because the scanner screen needs only
	 * this handful of rows, not all 170-odd services and their cookies.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @since 1.5.1
	 * @return WP_REST_Response { services:[ {slug,label,category,declared,suppressed} ] }.
	 */
	public function get_recognised_services( $request ): WP_REST_Response {
		unset( $request );

		return new WP_REST_Response(
			[ 'services' => Recognised_Services::get_instance()->report() ],
			200
		);
	}

	/**
	 * Install a known service: declare its cookies + record the registry entry.
	 * Pro services require an active Pro license (enforced here, not just in UI).
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request (slug URL param).
	 * @since 1.3.0
	 * @return WP_REST_Response
	 */
	public function install_service( $request ): WP_REST_Response {
		$slug = (string) $request->get_param( 'slug' );

		// Shared with the known-services ability so the Pro gate has one
		// definition and cannot be bypassed through the other path.
		$blocked = ( new KnownServicesService() )->check_installable( $slug );

		if ( $blocked !== null ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'code'    => $blocked['code'],
					'message' => $blocked['message'],
				],
				$blocked['code'] === 'unknown_service' ? 404 : 403
			);
		}

		$result = Installed_Services::get_instance()->install( $slug );

		return new WP_REST_Response( $result, empty( $result['success'] ) ? 400 : 200 );
	}

	/**
	 * Uninstall a known service: remove its declared cookies + registry entry.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request (slug URL param).
	 * @since 1.3.0
	 * @return WP_REST_Response
	 */
	public function uninstall_service( $request ): WP_REST_Response {
		$slug   = (string) $request->get_param( 'slug' );
		$result = Installed_Services::get_instance()->uninstall( $slug );

		return new WP_REST_Response( $result, 200 );
	}
}
