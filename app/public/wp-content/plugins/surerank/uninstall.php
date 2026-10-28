<?php
/**
 * SureRank uninstaller.
 *
 * Runs when the plugin is deleted from the WordPress Plugins screen.
 * Only wipes plugin data if the user has opted in via the
 * "Delete plugin data on uninstall" toggle in Settings → Advanced → Tools →
 * Miscellaneous.
 *
 * Scope: we only touch our own data — DB rows and our cache directory in
 * `wp-content/uploads/surerank/`. WordPress handles removing the plugin's
 * own files in `wp-content/plugins/surerank/`.
 *
 * @package surerank
 * @since 1.8.2
 */

// Bail if not invoked by WordPress during plugin deletion.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Recursive delete for our cache directory at `uploads/surerank/`.
 *
 * Plain PHP rather than WP_Filesystem because some hosting setups
 * (Local by Flywheel with certain configs, hosts without direct FS
 * access) fall back to FTP and either prompt for credentials or fail
 * silently — which on at least one observed run left WordPress unable to
 * remove the plugin directory after uninstall.php returned.
 *
 * Uniquely named to avoid collisions with other plugins' uninstall.php
 * running in the same PHP request.
 *
 * NOTE: this is for OUR cache files only. WordPress removes the plugin
 * directory itself (`wp-content/plugins/surerank/`) after uninstall.php
 * returns — we never touch that.
 */
if ( ! function_exists( 'surerank_free_delete_cache_dir' ) ) {
	/**
	 * Recursively delete a directory and its contents.
	 *
	 * @param string $path Absolute path to the directory.
	 * @return void
	 */
	function surerank_free_delete_cache_dir( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$items = @scandir( $path );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$full = $path . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $full ) ) {
				surerank_free_delete_cache_dir( $full );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
				@unlink( $full );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged, WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
		@rmdir( $path );
	}
}

/**
 * Run the full data cleanup for the CURRENT blog.
 *
 * Wrapped in a function so it runs once on a single site, or once per blog
 * (inside switch_to_blog) on a network. The opt-in gate is checked per blog,
 * so a network uninstall only wipes the sites that opted in.
 */
if ( ! function_exists( 'surerank_free_cleanup_site' ) ) {
	/**
	 * Delete all SureRank data on the current blog if it opted in.
	 *
	 * @since 1.10.3
	 * @return void
	 */
	function surerank_free_cleanup_site(): void {
		global $wpdb;

		// Gate: only proceed if the user explicitly opted in.
		if ( get_option( 'surerank_delete_on_uninstall' ) !== 'yes' ) {
			return;
		}

		/**
		 * 1. Plugin options.
		 *
		 * Wildcard-deletes every option whose name starts with `surerank`,
		 * `_surerank`, or `surerank-`. Covers all named option keys the plugin
		 * writes plus any future ones. No third-party plugin uses the `surerank`
		 * namespace, so the wildcard is safe.
		 *
		 * EXCEPTION: `surerank_delete_on_uninstall` (the opt-in gate itself) is
		 * intentionally preserved so the user's choice survives an
		 * uninstall→reinstall cycle and the toggle doesn't silently reset to OFF.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s ) AND option_name != %s",
				$wpdb->esc_like( 'surerank_' ) . '%',
				$wpdb->esc_like( '_surerank' ) . '%',
				$wpdb->esc_like( 'surerank-' ) . '%',
				'surerank_delete_on_uninstall'
			)
		);

		// Belt-and-braces: explicit delete in case the LIKE escape behaves
		// unexpectedly on the host's MySQL configuration. The gate option is
		// deliberately NOT deleted here (see note above).
		delete_option( '_surerank_seo_last_updated' );

		/**
		 * 2. Post meta — wipe every SureRank-prefixed post_meta row.
		 *
		 * Covers per-post settings (`surerank_settings_*`), SEO check snapshots,
		 * URL inspection cache, optimisation timestamps, import markers
		 * (`_surerank_*`), migration markers, and any future SureRank-prefixed
		 * keys.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
				$wpdb->esc_like( 'surerank' ) . '%',
				$wpdb->esc_like( '_surerank' ) . '%'
			)
		);

		// Belt-and-braces for the legacy `_surerank_seo_last_updated` post meta
		// key — older installs may still have rows but no current code writes it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_surerank_seo_last_updated'
			)
		);

		/**
		 * 3. Term meta — same prefix convention as post meta.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
				$wpdb->esc_like( 'surerank' ) . '%',
				$wpdb->esc_like( '_surerank' ) . '%'
			)
		);

		/**
		 * 4. Transients.
		 *
		 * Wildcard-deletes every surerank-prefixed transient row from wp_options.
		 * Link Manager caches per-URL HTTP-status checks as
		 * `_transient_surerank_http_status_<md5(url)>` — potentially hundreds of
		 * rows we can't enumerate by name — so a LIKE delete is the only sane
		 * approach.
		 *
		 * The shared BSF site transient `bsf_usage_track` lives in sitemeta /
		 * options under `_site_transient_*` and is intentionally NOT touched —
		 * other BSF plugins on the site may still rely on it.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_surerank' ) . '%',
				$wpdb->esc_like( '_transient_timeout_surerank' ) . '%'
			)
		);

		/**
		 * 5. Scheduled cron events.
		 *
		 * Done BEFORE the filesystem cleanup so a slow/failing file delete can't
		 * leave orphan crons behind.
		 *
		 * MAINTENANCE: this list must name every cron hook the plugin schedules — it
		 * can't be wildcarded. When you add a wp_schedule_event()/wp_schedule_single_event()
		 * call, add its hook here too, or the event leaks after uninstall. See
		 * `dev-docs/data-cleanup-on-uninstall.md`.
		 */
		$cron_hooks = [
			'surerank_generate_sitemap_cron',
			'surerank_compat_weekly_probe',
			'surerank_email_reports_cron',
		];

		foreach ( $cron_hooks as $hook ) {
			// wp_clear_scheduled_hook removes all scheduled events for the hook,
			// regardless of arguments.
			wp_clear_scheduled_hook( $hook );
		}

		/**
		 * 6. Our cache files in `wp-content/uploads/surerank/`.
		 *
		 * SureRank writes sitemap chunk JSONs (free) and embeddings metadata
		 * files (Pro) under this directory. WordPress doesn't know about them,
		 * so we clean them up here. Done last so any earlier issue still leaves
		 * the DB cleanup completed.
		 *
		 * We do NOT touch `wp-content/plugins/surerank/` — that's WordPress's
		 * job, done after this script returns.
		 */
		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['error'] ) && ! empty( $upload_dir['basedir'] ) ) {
			$surerank_cache_dir = trailingslashit( $upload_dir['basedir'] ) . 'surerank';
			if ( is_dir( $surerank_cache_dir ) ) {
				surerank_free_delete_cache_dir( $surerank_cache_dir );
			}
		}
	}
}

if ( is_multisite() ) {
	$surerank_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);

	foreach ( $surerank_site_ids as $surerank_site_id ) {
		switch_to_blog( (int) $surerank_site_id );
		surerank_free_cleanup_site();
		restore_current_blog();
	}
} else {
	surerank_free_cleanup_site();
}
