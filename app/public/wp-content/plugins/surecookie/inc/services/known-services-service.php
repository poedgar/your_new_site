<?php
/**
 * Known Services Service
 *
 * Shared catalog read/write for the Known Services admin screen and the
 * known-services ability, so the Pro gate is defined once.
 *
 * @package SureCookie\Inc\Services
 * @since   1.4.0
 */

namespace SureCookie\Inc\Services;

use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Modules\Services\Cron;
use SureCookie\Inc\Modules\Services\Installed_Services;
use SureCookie\Inc\Modules\Services\Recognised_Services;
use SureCookie\Inc\Modules\Services\Services_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class KnownServicesService
 *
 * @since 1.4.0
 */
class KnownServicesService {
	/**
	 * The catalog, keyed by slug.
	 *
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public function get_catalog(): array {
		return Services_Source::get_instance()->get_catalog();
	}

	/**
	 * Catalog slugs this site is known to load.
	 *
	 * @return array<int, string>
	 * @since 1.4.0
	 */
	public function detected_slugs(): array {
		return Recognised_Services::get_instance()->slugs();
	}

	/**
	 * Whether a slug may be installed on this site.
	 *
	 * Single definition of the Pro gate: the REST route and the ability both
	 * call this, so an unlicensed site cannot install a Pro catalog service
	 * through either path.
	 *
	 * @param string $slug Catalog slug.
	 * @return array{code: string, message: string}|null Null when installable.
	 * @since 1.4.0
	 */
	public function check_installable( string $slug ): ?array {
		$catalog = $this->get_catalog();

		if ( ! isset( $catalog[ $slug ] ) || ! is_array( $catalog[ $slug ] ) ) {
			return [
				'code'    => 'unknown_service',
				'message' => __( 'Unknown service.', 'surecookie' ),
			];
		}

		if ( ! empty( $catalog[ $slug ]['pro'] ) && ! Helper::is_pro_active() ) {
			return [
				'code'    => 'pro_required',
				'message' => __( 'This is a Pro service. Upgrade to add it.', 'surecookie' ),
			];
		}

		return null;
	}

	/**
	 * The catalog with each entry's install and detection state.
	 *
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public function list_services(): array {
		$catalog   = $this->get_catalog();
		$installed = Installed_Services::get_instance()->get_installed();
		$detected  = $this->detected_slugs();
		$pro_ready = Helper::is_pro_active();
		$services  = [];

		foreach ( $catalog as $slug => $service ) {
			if ( ! is_string( $slug ) || ! is_array( $service ) ) {
				continue;
			}

			$cookies = array_values( (array) ( $service['cookies'] ?? [] ) );
			$summary = Services_Source::pattern_summary( $service );
			$is_pro  = ! empty( $service['pro'] );

			if ( isset( $installed[ $slug ] ) ) {
				$state = 'installed';
			} elseif ( in_array( $slug, $detected, true ) ) {
				$state = 'detected';
			} else {
				$state = 'available';
			}

			$services[] = array_merge(
				[
					'slug'        => $slug,
					'label'       => (string) ( $service['label'] ?? $slug ),
					'description' => (string) ( $service['description'] ?? '' ),
					'category'    => (string) ( $service['category'] ?? 'uncategorized' ),
					'pro'         => $is_pro,
					'locked'      => $is_pro && ! $pro_ready,
					'state'       => $state,
					'cookieCount' => count( $cookies ),
				],
				$summary
			);
		}

		return [
			'services'  => $services,
			'installed' => array_values( $installed ),
			'detected'  => $detected,
		];
	}

	/**
	 * Declare a catalog service's cookies on this site.
	 *
	 * @param string $slug Catalog slug.
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public function install( string $slug ): array {
		$blocked = $this->check_installable( $slug );

		if ( $blocked !== null ) {
			return [
				'success' => false,
				'message' => $blocked['message'],
				'code'    => $blocked['code'],
				'data'    => [ 'slug' => $slug ],
			];
		}

		$result = Installed_Services::get_instance()->install( $slug );

		if ( empty( $result['success'] ) ) {
			return [
				'success' => false,
				'message' => __( 'The service could not be installed.', 'surecookie' ),
				'code'    => (string) ( $result['code'] ?? 'install_failed' ),
				'data'    => [ 'slug' => $slug ],
			];
		}

		$added   = (array) ( $result['added'] ?? [] );
		$skipped = (array) ( $result['skipped'] ?? [] );

		return [
			'success' => true,
			'message' => empty( $added )
				// cookie_count only counts cookies THIS install minted, so a
				// service whose cookies already exist reports zero. Say that
				// plainly rather than letting it read as "declared nothing".
				? __( 'Service installed. Every cookie it declares was already present, so no new cookie definitions were created.', 'surecookie' )
				: __( 'Service installed and its cookies declared.', 'surecookie' ),
			'data'    => [
				'slug'                    => $slug,
				'cookies_added'           => array_values( $added ),
				'cookies_already_present' => array_values( $skipped ),
				'cookies_declared_total'  => count( $added ) + count( $skipped ),
			],
		];
	}

	/**
	 * Remove a service's declared cookies and its registry entry.
	 *
	 * @param string $slug Catalog slug.
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public function uninstall( string $slug ): array {
		$installed     = Installed_Services::get_instance()->get_installed();
		$was_installed = isset( $installed[ $slug ] );

		if ( ! $was_installed && ! isset( $this->get_catalog()[ $slug ] ) ) {
			return [
				'success' => false,
				'message' => __( 'Unknown service.', 'surecookie' ),
				'code'    => 'unknown_service',
				'data'    => [ 'slug' => $slug ],
			];
		}

		$result = Installed_Services::get_instance()->uninstall( $slug );

		return [
			'success' => ! empty( $result['success'] ),
			'message' => $was_installed
				? __( 'Service removed and its declared cookies deleted.', 'surecookie' )
				: __( 'That service was not installed, so nothing was removed.', 'surecookie' ),
			'data'    => [
				'slug'          => $slug,
				'was_installed' => $was_installed,
				'removed'       => (int) ( $result['removed'] ?? 0 ),
			],
		];
	}

	/**
	 * Re-fetch the catalog from the remote source.
	 *
	 * Reports the catalog size on either side rather than widening the existing
	 * void refresh methods, which are mirrored in both plugins' PHPStan stubs.
	 *
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public function refresh_catalog(): array {
		$before = count( $this->get_catalog() );

		// No clear_cache() here: refresh_from_remote() has just written the
		// fetched catalog to the file cache, and clearing it would throw that
		// away and fall back to the bundled dataset.
		Cron::get_instance()->refresh();

		$after = count( $this->get_catalog() );

		return [
			'success' => true,
			'message' => __( 'Catalog refreshed.', 'surecookie' ),
			'data'    => [
				'services_before' => $before,
				'services_after'  => $after,
				'changed'         => $before !== $after,
			],
		];
	}
}
