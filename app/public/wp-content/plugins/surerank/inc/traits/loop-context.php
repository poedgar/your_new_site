<?php
/**
 * Loop Context.
 *
 * @package surerank
 * @since 1.10.0
 */

namespace SureRank\Inc\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Loop_Context
 *
 * Snapshot and restore the WordPress loop globals around a render that
 * SureRank performs outside the main template flow (analyzer block rendering,
 * page-builder content rendering during wp_head, …).
 *
 * Any block or module rendered by such a pass can call the_post() /
 * setup_postdata() — Query Loop blocks and page-builder loop modules do — which
 * mutates far more than $post. Their wp_reset_postdata() restores the *main
 * query's* post, not the state SureRank found, so without this guard the hidden
 * render leaks loop state into everything that runs afterwards in the request.
 *
 * @since 1.10.0
 */
trait Loop_Context {

	/**
	 * Capture the WordPress loop globals.
	 *
	 * @since 1.10.0
	 * @return array<string, mixed> Snapshot to hand back to restore_loop_context().
	 */
	protected function get_loop_context(): array {
		global $post, $id, $authordata, $currentday, $currentmonth, $page, $pages, $multipage, $more, $numpages;

		return [
			'post'         => $post,
			'id'           => $id,
			'authordata'   => $authordata,
			'currentday'   => $currentday,
			'currentmonth' => $currentmonth,
			'page'         => $page,
			'pages'        => $pages,
			'multipage'    => $multipage,
			'more'         => $more,
			'numpages'     => $numpages,
		];
	}

	/**
	 * Restore the loop globals captured by get_loop_context().
	 *
	 * Call from a finally block so a throwing render cannot leave the request
	 * with someone else's loop state.
	 *
	 * @since 1.10.0
	 * @param array<string, mixed> $context Snapshot from get_loop_context().
	 * @return void
	 */
	protected function restore_loop_context( array $context ): void {
		global $post, $id, $authordata, $currentday, $currentmonth, $page, $pages, $multipage, $more, $numpages;

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the caller's own loop context after an out-of-band render.
		$post         = $context['post'];
		$id           = $context['id'];
		$authordata   = $context['authordata'];
		$currentday   = $context['currentday'];
		$currentmonth = $context['currentmonth'];
		$page         = $context['page'];
		$pages        = $context['pages'];
		$multipage    = $context['multipage'];
		$more         = $context['more'];
		$numpages     = $context['numpages'];
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}

}
