<?php
/**
 * Sitemap provider registry.
 *
 * Central lookup used by every sitemap entry point (XML dispatcher,
 * headless REST) to resolve which provider serves a request and to
 * assemble the on-demand sitemap index from provider counts.
 *
 * @package surerank
 * @since 1.9.3
 */

namespace SureRank\Inc\Sitemap\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\Functions\Cache;
use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Traits\Logger;
use Throwable;

/**
 * Registry
 *
 * @since 1.9.3
 */
class Registry {

	use Get_Instance;
	use Logger;

	/**
	 * Registered providers.
	 *
	 * @var array<int, Provider>|null
	 */
	private $providers = null;

	/**
	 * All registered providers. Free providers plus anything added via
	 * the surerank_sitemap_providers filter (Pro video/news/author).
	 *
	 * @since 1.9.3
	 * @return array<int, Provider>
	 */
	public function get_providers(): array {
		if ( null === $this->providers ) {
			$providers = [
				Post_Type_Provider::get_instance(),
				Taxonomy_Provider::get_instance(),
				Archive_Provider::get_instance(),
			];

			$providers = apply_filters( 'surerank_sitemap_providers', $providers );

			$this->providers = array_values(
				array_filter(
					is_array( $providers ) ? $providers : [],
					static function ( $provider ): bool {
						return $provider instanceof Provider;
					}
				)
			);
		}

		return $this->providers;
	}

	/**
	 * Resolve the provider serving a request, if any.
	 *
	 * @param string $type   Requested sitemap type.
	 * @param string $prefix Requested prefix.
	 * @since 1.9.3
	 * @return Provider|null
	 */
	public function for_request( string $type, string $prefix ): ?Provider {
		foreach ( $this->get_providers() as $provider ) {
			if ( $provider->handles( $type, $prefix ) ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Build the sitemap index inline from provider counts and persist it.
	 *
	 * No content is loaded — every provider derives its entries from
	 * count queries. The chunk files the entries point to are built
	 * lazily by ensure_page_built() as each sub-sitemap is requested.
	 *
	 * @since 1.9.3
	 * @return bool True when the index file exists afterwards.
	 */
	public function build_index(): bool {
		$entries = [];
		foreach ( $this->get_providers() as $provider ) {
			try {
				$provider_entries = $provider->get_index_entries();
			} catch ( Throwable $e ) {
				// A faulty provider (often a third-party one added via the
				// surerank_sitemap_providers filter) must not blank the whole
				// index. Skip it and keep every other type.
				self::log( 'Sitemap provider failed in build_index(): ' . $e->getMessage(), 'error' );
				continue;
			}

			foreach ( $provider_entries as $entry ) {
				if ( isset( $entry['link'], $entry['updated'] ) ) {
					$entries[] = [
						'link'    => (string) $entry['link'],
						'updated' => (string) $entry['updated'],
					];
				}
			}
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				return strnatcmp( $a['link'], $b['link'] );
			}
		);

		$json_data = wp_json_encode( $entries );
		if ( false !== $json_data ) {
			Cache::store_file( 'sitemap/sitemap_index.json', $json_data );
		}

		return Cache::file_exists( 'sitemap/sitemap_index.json' );
	}
}
