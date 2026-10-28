<?php
/**
 * Trait.
 *
 * @package surerank
 * @since 1.9.0
 */

namespace SureRank\Inc\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Trait Reset_Meta_Data.
 *
 * Shared by the surerank_set_meta providers that cache their meta per
 * request. The headless REST render simulates multiple front-end renders
 * within a single request, so the cache must be clearable between renders
 * via the surerank_reset_frontend_meta action.
 *
 * @since 1.9.0
 */
trait Reset_Meta_Data {

	/**
	 * Reset the per-request meta cache.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public function reset_meta_data() {
		$this->meta_data = null;
	}

	/**
	 * Hook the reset into the shared frontend-meta reset action.
	 *
	 * Called from the using class's constructor, alongside its
	 * surerank_set_meta registration — anything that can cache can also be
	 * reset.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	protected function register_meta_reset() {
		add_action( 'surerank_reset_frontend_meta', [ $this, 'reset_meta_data' ] );
	}
}
