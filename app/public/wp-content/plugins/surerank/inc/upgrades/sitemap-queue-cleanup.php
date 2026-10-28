<?php
/**
 * Sitemap Queue Cleanup
 *
 * Clears a background sitemap queue left running by sites that updated to
 * on-the-fly sitemap generation while a batch was still queued.
 *
 * @package SureRank\Inc\Upgrades
 * @since 1.10.2
 */

namespace SureRank\Inc\Upgrades;

use SureRank\Inc\BatchProcess\Process;
use SureRank\Inc\Sitemap\Generation_Mode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sitemap Queue Cleanup
 *
 * On-the-fly generation became the default in 1.9.3 and never queues sitemap
 * work, but a batch already queued at that moment is not stranded quietly: the
 * library's own healthcheck cron keeps redispatching it. On hosts that block
 * rapid rewrites of one option the batch never shrinks, so that loop never
 * ends and each pass surfaces as an excess-updates notice.
 *
 * @since 1.10.2
 */
class Sitemap_Queue_Cleanup {

	/**
	 * Cleanup version applied to this site.
	 *
	 * Bump this to re-run the cleanup everywhere.
	 *
	 * @since 1.10.2
	 */
	public const VERSION = 1;

	/**
	 * Option recording the cleanup version already applied.
	 *
	 * @since 1.10.2
	 */
	private const VERSION_OPTION = 'surerank_sitemap_queue_cleanup_version';

	/**
	 * Delete the stranded queue rows.
	 *
	 * Hooked on wp_loaded, so the happy path costs one autoloaded option read.
	 * The work itself is a LIKE scan of wp_options, which is why it is gated to
	 * run once rather than on every request.
	 *
	 * @since 1.10.2
	 * @return void
	 */
	public static function maybe_run(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		// Background mode still owns its queue. Return without recording the
		// version so a later switch back to on-the-fly is still cleaned up.
		if ( Generation_Mode::cron_prebuild_enabled() ) {
			return;
		}

		Process::get_instance()->clear_queue();

		update_option( self::VERSION_OPTION, self::VERSION );
	}
}
