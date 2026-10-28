<?php
/**
 * Post-type archive sitemap provider.
 *
 * @package surerank
 * @since 1.9.3
 */

namespace SureRank\Inc\Sitemap\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\BatchProcess\Sync_Archives;
use SureRank\Inc\Functions\Cache;
use SureRank\Inc\Traits\Get_Instance;

/**
 * Archive_Provider
 *
 * Archives are a single tiny chunk by design (one URL per public
 * has_archive post type), so the whole family is one page.
 *
 * @since 1.9.3
 */
class Archive_Provider implements Provider {

	use Get_Instance;

	/**
	 * Whether this provider serves the given request.
	 *
	 * @param string $type   Requested sitemap type.
	 * @param string $prefix Requested prefix ('' for archives).
	 * @since 1.9.3
	 * @return bool
	 */
	public function handles( string $type, string $prefix ): bool {
		return Sync_Archives::TYPE === $type && '' === $prefix;
	}

	/**
	 * One index entry, only when at least one post-type archive qualifies.
	 *
	 * Advertising the archive sub-sitemap unconditionally would point the index
	 * at a page that builds no chunk (e.g. a default install with no has_archive
	 * CPT) and serves a 503. Gate on the same entry set the chunk builder uses.
	 *
	 * @since 1.9.3
	 * @return array<int, array<string, string>>
	 */
	public function get_index_entries(): array {
		if ( [] === Sync_Archives::get_instance()->get_archive_entries() ) {
			return [];
		}

		return [
			[
				'link'    => home_url( Sync_Archives::TYPE . '-sitemap-1.xml' ),
				'updated' => current_time( 'c' ),
			],
		];
	}

	/**
	 * Build the single archive chunk inline when missing.
	 *
	 * @param string $type   Sitemap type.
	 * @param string $prefix Prefix (unused for archives).
	 * @param int    $page   Page number (archives are single-page).
	 * @since 1.9.3
	 * @return void
	 */
	public function ensure_page_built( string $type, string $prefix, int $page ): void {
		if ( ! $this->handles( $type, $prefix ) ) {
			return;
		}

		if ( apply_filters( 'surerank_exclude_archives_from_sitemap', false ) ) {
			return;
		}

		if ( Cache::file_exists( 'sitemap/' . Sync_Archives::TYPE . '-chunk-1.json' ) ) {
			return;
		}

		Sync_Archives::get_instance()->import();
	}
}
