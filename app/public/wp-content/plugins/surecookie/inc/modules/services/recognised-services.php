<?php
/**
 * Catalog services this site is known to load, and whether each one is declared.
 *
 * Two stores answer the first half and they disagree: a scan writes
 * `SURECOOKIE_SCANNED_RESOURCES_OPTION`, while the blocker records its own
 * matched set from live page views, and only the second sees a service no scan
 * can observe - an embed replaced by a placeholder before the scanner reaches
 * it. Both reach the `surecookie_scanned_resources` filter, so
 * `Get::scanned_resources_for_display()` is the single authority and every
 * reader of "what did we recognise" comes through here.
 *
 * The second half is asked of the published cookie set rather than of the
 * installed registry, because the two can contradict each other: `uninstall()`
 * suppresses a slug but leaves rows an earlier scan already stored, and
 * reconciliation keeps them while the catalog still lists the service. Only a
 * service with no row anywhere is genuinely undeclared, which is the one case an
 * admin cannot fix by scanning again.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.5.1
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Recognised_Services
 *
 * @since 1.5.1
 */
class Recognised_Services {
	use GetInstance;

	/**
	 * Catalog slugs this site is known to load.
	 *
	 * @since 1.5.1
	 * @return array<int, string>
	 */
	public function slugs(): array {
		$catalog   = Services_Source::get_instance()->get_catalog();
		$resources = Get::scanned_resources_for_display();
		$matcher   = Service_Matcher::get_instance();
		$slugs     = array_fill_keys(
			$matcher->match_services( array_keys( $catalog ), $matcher->collect_resource_urls( [ $resources ] ) ),
			true
		);

		// A row the blocker merged in names its service but carries no URL, the
		// resource having been replaced by a placeholder, so pattern matching
		// alone cannot see it. These are the scanner's payload buckets, not the
		// catalog's Pattern_Kinds ones.
		foreach ( [ 'scripts', 'iframes' ] as $bucket ) {
			foreach ( (array) ( $resources[ $bucket ] ?? [] ) as $row ) {
				$slug = is_array( $row ) ? (string) ( $row['service_slug'] ?? '' ) : '';
				if ( isset( $catalog[ $slug ] ) ) {
					$slugs[ $slug ] = true;
				}
			}
		}

		return array_keys( $slugs );
	}

	/**
	 * Per-service report for the admin surfaces, ordered by label.
	 *
	 * @since 1.5.1
	 * @return array<int, array{slug: string, label: string, category: string, declared: bool, suppressed: bool}>
	 */
	public function report(): array {
		$catalog    = Services_Source::get_instance()->get_catalog();
		$installed  = Installed_Services::get_instance();
		$publishing = $this->publishing_slugs();
		$report     = [];

		foreach ( $this->slugs() as $slug ) {
			$service  = is_array( $catalog[ $slug ] ?? null ) ? $catalog[ $slug ] : [];
			$report[] = [
				'slug'       => $slug,
				'label'      => (string) ( $service['label'] ?? $slug ),
				'category'   => (string) ( $service['category'] ?? 'uncategorized' ),
				'declared'   => isset( $publishing[ $slug ] ),
				'suppressed' => $installed->is_suppressed( $slug ),
			];
		}

		usort( $report, static fn( array $a, array $b ): int => strcasecmp( $a['label'], $b['label'] ) );

		return $report;
	}

	/**
	 * Slugs contributing at least one cookie row to what visitors are shown.
	 *
	 * @since 1.5.1
	 * @return array<string, bool>
	 */
	private function publishing_slugs(): array {
		$slugs = [];

		foreach ( Get::scanned_cookies_for_display() as $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( ! is_array( $row ) || ( $row['source'] ?? '' ) !== 'declared' ) {
					continue;
				}

				// 'declared:{slug}:{name}', the contract reconciliation matches on.
				$parts = explode( ':', (string) ( $row['signature_id'] ?? '' ), 3 );
				if ( count( $parts ) === 3 && $parts[0] === 'declared' && $parts[1] !== '' ) {
					$slugs[ $parts[1] ] = true;
				}
			}
		}

		// Installing a service writes its cookies to `custom_cookies` instead,
		// tagged with the slug, so those rows are declared too.
		foreach ( (array) Settings::get( 'custom_cookies' ) as $row ) {
			$slug = is_array( $row ) ? (string) ( $row['service_slug'] ?? '' ) : '';
			if ( $slug !== '' ) {
				$slugs[ $slug ] = true;
			}
		}

		return $slugs;
	}
}
