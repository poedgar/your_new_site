<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Cleans up all plugin data if the user has opted in via
 * Settings > General Settings > "Delete Data on Uninstall".
 *
 * IMPORTANT: This file runs WITHOUT the plugin loaded.
 * No constants, classes, or autoloader are available.
 * All option keys are hardcoded and must be kept in sync
 * with SURECOOKIE_* constants in surecookie.php and runtime option keys.
 *
 * @link       https://surecookie.com
 * @since      0.0.1
 *
 * @package    SureCookie
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Check if the user has opted in to data deletion.
 *
 * @return bool
 */
function surecookie_should_delete_data() {
	$settings = get_option( 'surecookie_settings', [] );
	if ( ! is_array( $settings ) ) {
		return false;
	}
	return isset( $settings['delete_data_on_uninstall'] )
		&& $settings['delete_data_on_uninstall'] === true;
}

/*
 * Deletion is checked per-site (not globally) so each multisite sub-site's own
 * preference is respected. See surecookie_should_delete_data() usage below.
 */

/**
 * Hand the decision to a sibling plugin's uninstall running in the same request.
 *
 * WordPress includes every selected plugin's uninstall.php in one pass, and the
 * list table orders "SureCookie" before "SureCookie Pro", so by the time
 * Pro reads the preference this file has already deleted surecookie_settings and
 * Pro would read an absent option as "no" and keep its data - including the DSAR
 * tables, which hold requester names and email addresses. A short transient
 * carries the answer across without leaving a row behind if Pro is never deleted.
 *
 * @return void
 */
function surecookie_publish_uninstall_decision(): void {
	set_transient( 'surecookie_uninstall_delete_data', 1, 5 * MINUTE_IN_SECONDS );
}

/**
 * All known option keys created by SureCookie.
 *
 * Sync with: SURECOOKIE_* constants in surecookie.php + runtime option keys.
 *
 * @return array<string>
 */
function surecookie_get_option_keys() {
	return [
		// Defined as constants in surecookie.php.
		'surecookie_settings',
		'surecookie_onboarding_user_details',
		'surecookie_onboarding_completed',
		'surecookie_scanned_details',
		'surecookie_scanned_cookies',
		'surecookie_scanned_logs',
		'surecookie_scanned_resources',
		'surecookie_cookie_category_memory',
		'surecookie_nudges',
		// Runtime options (not constants).
		'surecookie_do_activation_redirect',
		'surecookie_cron_test_ok',
		'surecookie_active_scan',
		'surecookie_scan_poll_lock',
		'surecookie_saved_version',
		// BSF Analytics options.
		'surecookie_usage_optin',
		'surecookie_usage_installed_time',
		'surecookie_tracked_version',
		// Onboarding funnel signals: arrival stamp + the screen that recorded
		// completion (Admin\Onboarding, Inc\Api\Onboarding).
		'surecookie_onboarding_opened',
		'surecookie_onboarding_completed_source',
		'surecookie_first_scan_started_flag',
		'surecookie_first_scan_pages_count',
		'surecookie_first_scan_completed_flag',
		'surecookie_first_scan_pages_scanned',
		// Assisted Scan analytics flags (Modules\AssistedScan\Telemetry) + blocked-scanner
		// flag (SaasClient::BLOCKED_FLAG_OPTION). No autoloader - keep literals in sync.
		'surecookie_assisted_scan_started_flag',
		'surecookie_assisted_scan_completed_flag',
		'surecookie_assisted_scan_abandoned_flag',
		'surecookie_assisted_scan_stats',
		'surecookie_cloud_scan_blocked_flag',
		// Assisted Scan in-flight session state (Modules\AssistedScan\Session).
		'surecookie_assisted_scan',
		// Issue #473 - scanner SaaS credentials.
		'surecookie_site_credentials',
		'surecookie_last_known_quota',
		// Issue #742 - connected billing-portal account (Auth\Controller::SETTINGS_KEY).
		'surecookie_auth',
		'surecookie_onboarding_lead',
		// Legacy: no longer written since the review prompt moved to a consent-log
		// trigger. Kept so sites that upgraded from an earlier version still clean up.
		'surecookie_first_successful_scan',
		'surecookie_db_version',
		// Migration completion ledger (Core\Maintenance::LEDGER_OPTION). A single
		// row covering every migration, so adding a migration never adds a key here.
		'surecookie_migrations',
		// Pre-ledger per-migration flags (Maintenance::LEGACY_FLAGS). Deleted during ledger
		// adoption on the first upgraded run; listed here for a site that uninstalls before
		// ever upgrading. Safe to drop ~two releases after the ledger ships.
		'surecookie_consent_log_unique_key_migrated',
		'surecookie_services_backfill_v1',
		'surecookie_cookie_provider_backfill_v1',
		'surecookie_cookie_category_memory_backfill_v1',
		// Known Services installed registry (Modules\Services\Installed_Services).
		'surecookie_installed_services',
		// BSF Analytics event queue + schema version.
		'surecookie_analytics_events_version',
		'surecookie_usage_events_pending',
		'surecookie_usage_events_pushed',
		'surecookie_last_user_login',
		'surecookie_first_auto_scan_frequency',
		'surecookie_first_auto_scan_started_flag',
		// Pro activation redirect.
		'surecookie_pro_redirect_on_activation',
		// Rolling record of resources this site actually blocked.
		'surecookie_matched_resources',
	];
}

/**
 * Delete all plugin options for the current site.
 *
 * @return void
 */
function surecookie_delete_options(): void {
	foreach ( surecookie_get_option_keys() as $option ) {
		delete_option( $option );
	}

	// Per-user "last viewed consent log" pointers are stored as
	// surecookie_last_viewed_consent_log_<user_id>; the user-id suffix is unknowable in
	// advance, so delete by prefix (specific enough to never match Pro's keys).
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'surecookie_last_viewed_consent_log_' ) . '%'
		)
	);
}

/**
 * Delete all plugin transients for the current site.
 *
 * @return void
 */
function surecookie_delete_transients(): void {
	delete_transient( 'surecookie_services' );
	delete_transient( 'surecookie_services_v2' );
	// Legacy key from the pre-refactor known-scripts cache; kept so an uninstall
	// after upgrading from an older version still clears any lingering row.
	delete_transient( 'surecookie_known_scripts' );
	delete_transient( 'surecookie_geo_cache' );
	delete_transient( 'surecookie_geo_cache_v2' );
	// Geolocation circuit-breaker state (IpManager::geo_breaker_key()).
	delete_transient( 'surecookie_geo_breaker' );
	delete_transient( 'surecookie_state_events_checked' );
	// Live_Versions::CACHE_KEY, the dashboard's new-version check.
	delete_transient( 'surecookie_live_versions' );
	// Issue #473 - scanner registration / verification transients.
	delete_transient( 'surecookie_registering_site' );
	delete_transient( 'surecookie_pending_verification' );
	delete_transient( 'surecookie_pending_handshake' );
	// Mirror of SaasClient::SCAN_BYPASS_TRANSIENT_KEY (no autoloader here).
	delete_transient( 'surecookie_scan_bypass_token' );
	delete_transient( 'surecookie_scan_quota' );
	// Automatic-scanning runner state (Runner::ACTIVE_TRANSIENT / LOCK_TRANSIENT).
	delete_transient( 'surecookie_auto_scan_active' );
	delete_transient( 'surecookie_auto_scan_lock' );
	// Google Consent Mode service detection cache (ServiceDetector::TRANSIENT_KEY).
	delete_transient( 'surecookie_gcm_services_detected' );
	// Migration runner lock + schema-upgrade backoff (Core\Maintenance).
	delete_transient( 'surecookie_migrations_lock' );
	delete_transient( 'surecookie_db_upgrade_backoff' );
	// The schema backoff is network-scoped on multisite (Maintenance::maybe_upgrade_db).
	if ( is_multisite() ) {
		delete_site_transient( 'surecookie_db_upgrade_backoff' );
	}
}

/**
 * Unschedule all plugin cron events for the current site.
 *
 * @return void
 */
function surecookie_unschedule_crons(): void {
	wp_clear_scheduled_hook( 'surecookie_cleanup_consent_logs' );
	wp_clear_scheduled_hook( 'surecookie_poll_scan_status' );
	// Automatic-scanning events (Scheduler::RUN_HOOK / Runner::RETRY_HOOK).
	wp_clear_scheduled_hook( 'surecookie_auto_scan_run' );
	wp_clear_scheduled_hook( 'surecookie_auto_scan_retry' );
	// Services-dataset daily refresh (Services\Cron::REFRESH_HOOK).
	wp_clear_scheduled_hook( 'surecookie_refresh_datasets' );
	// First-party loopback service detection (SaaSClient::FIRST_PARTY_DETECT_HOOK).
	wp_clear_scheduled_hook( 'surecookie_first_party_detect' );
}

/**
 * Drop the consent log table for the current site.
 *
 * @return void
 */
function surecookie_drop_tables(): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}surecookie_consent_log" );
}

/**
 * Delete the file cache directory (wp-content/uploads/surecookie/).
 *
 * @return void
 */
function surecookie_delete_cache_files(): void {
	$upload_dir = wp_upload_dir();
	if ( empty( $upload_dir['basedir'] ) ) {
		return;
	}

	$cache_dir = trailingslashit( $upload_dir['basedir'] ) . 'surecookie';

	if ( ! is_dir( $cache_dir ) ) {
		return;
	}

	// Use WP_Filesystem_Direct explicitly - WP_Filesystem() can silently
	// fail on FTP/SSH servers where credentials are unavailable at uninstall.
	if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
	}
	if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
	}

	$filesystem = new WP_Filesystem_Direct( [] );
	$filesystem->delete( $cache_dir, true );
}

/**
 * Resolve the [surecookie_*] tags worth keeping as text, from the raw settings.
 *
 * Mirrors Business_Shortcodes::registry() plus the two privacy-policy tags that
 * name the controller. Like the option keys above, this has to be kept in sync
 * by hand, because this file runs with no autoloader and cannot call into the
 * plugin.
 *
 * @param array<string, mixed> $settings Raw surecookie_settings value.
 * @return array<string, string> Shortcode => resolved value.
 */
function surecookie_uninstall_shortcode_map( array $settings ): array {
	$details = isset( $settings['organization_details'] ) && is_array( $settings['organization_details'] )
		? $settings['organization_details']
		: [];

	$detail = static function ( string $key ) use ( $details ): string {
		return isset( $details[ $key ] ) && is_string( $details[ $key ] ) ? trim( $details[ $key ] ) : '';
	};

	$countries = [];
	$table     = __DIR__ . '/inc/data/countries.php';

	if ( file_exists( $table ) ) {
		$loaded    = require $table;
		$countries = is_array( $loaded ) ? $loaded : [];
	}

	$country = $countries[ strtoupper( $detail( 'country' ) ) ] ?? '';

	$lines = isset( $details['postal_address_lines'] ) && is_array( $details['postal_address_lines'] )
		? array_values( array_filter( array_map( 'trim', array_filter( $details['postal_address_lines'], 'is_string' ) ) ) )
		: [];

	$tail = trim( $detail( 'postal_code' ) . ' ' . $country );

	if ( $tail !== '' ) {
		$lines[] = $tail;
	}

	$page_url = static function ( int $page_id ): string {
		if ( $page_id <= 0 || get_post_status( $page_id ) !== 'publish' ) {
			return '';
		}

		$permalink = get_permalink( $page_id );

		return is_string( $permalink ) ? $permalink : '';
	};

	// Get::privacy_policy_page_details() order, so the frozen link is the one visitors saw.
	$privacy_url = $page_url( absint( get_option( 'wp_page_for_privacy_policy' ) ) );
	if ( $privacy_url === '' ) {
		$privacy_url = $page_url( absint( $settings['privacy_policy_page_id'] ?? 0 ) );
	}

	$map = [
		'[surecookie_company_name]'       => $detail( 'legal_entity_name' ),
		'[surecookie_contact_email]'      => $detail( 'privacy_contact_email' ),
		'[surecookie_address]'            => implode( ', ', $lines ),
		'[surecookie_postal_code]'        => $detail( 'postal_code' ),
		'[surecookie_country]'            => $country,
		'[surecookie_grievance_officer]'  => $detail( 'grievance_officer_name' ),
		'[surecookie_grievance_email]'    => $detail( 'grievance_officer_email' ),
		'[surecookie_cookie_policy_url]'  => $page_url( absint( $settings['cookie_policy_page_id'] ?? 0 ) ),
		'[surecookie_privacy_policy_url]' => $privacy_url,
	];

	/*
	 * The generated privacy policy carries none of the tags above and names the
	 * controller only through these two, so they resolve to text rather than
	 * being stripped. Plain text, because the generator's wp:shortcode wrapper
	 * stays; an unresolvable one is left out for the strip pass to remove.
	 */
	$identity = array_filter( array_merge( [ $detail( 'legal_entity_name' ) ], $lines ) );
	$contact  = array_filter(
		[
			$detail( 'privacy_contact_email' ),
			trim( $detail( 'grievance_officer_name' ) . ' ' . $detail( 'grievance_officer_email' ) ),
		]
	);

	if ( ! empty( $identity ) ) {
		$map['[surecookie_privacy_identity]'] = esc_html( implode( ', ', $identity ) );
	}

	if ( ! empty( $contact ) ) {
		$map['[surecookie_privacy_contact]'] = esc_html( implode( ', ', $contact ) );
	}

	return $map;
}

/**
 * Drop every [surecookie_*] tag nothing owns any more, block wrapper and all.
 *
 * Matched on the prefix rather than an enumerated list, so a tag added in a
 * later release is covered without anyone editing this file. Pro shares the
 * prefix and may outlive free, so registration is what decides: a tag another
 * plugin still registers keeps rendering and has to survive.
 *
 * @param string $content Post content.
 * @return string
 */
function surecookie_uninstall_strip_shortcodes( string $content ): string {
	$strip = static function ( $matches ) {
		return shortcode_exists( $matches['tag'] ) ? $matches[0] : '';
	};

	$content = (string) preg_replace_callback(
		'#<!--\s*wp:shortcode\s*-->\s*\[(?P<tag>surecookie_[^\]\s]*)[^\]]*\]\s*<!--\s*/wp:shortcode\s*-->\s*#',
		$strip,
		$content
	);

	return (string) preg_replace_callback( '/\[(?P<tag>surecookie_[^\]\s]*)[^\]]*\]/', $strip, $content );
}

/**
 * Bake shortcode values into the policy pages before the settings go away.
 *
 * Once this plugin is deleted a tag nothing registers renders as literal text
 * on whatever page used it. On a live legal page that is worse than a blank, so
 * the last thing we do is write the current values in and leave the document
 * standing. Everything a value cannot stand in for is removed instead, wrapper
 * block and all, unless another plugin still registers it.
 *
 * Called outside surecookie_should_delete_data(): the pages outlive the plugin
 * whichever way that preference is set, and it defaults to keeping data.
 *
 * Bounded work: at most three pages, one pass each.
 *
 * @return void
 */
function surecookie_preserve_policy_pages(): void {
	$settings = get_option( 'surecookie_settings', [] );

	if ( ! is_array( $settings ) ) {
		return;
	}

	$page_ids = [
		absint( $settings['cookie_policy_page_id'] ?? 0 ),
		absint( $settings['privacy_policy_page_id'] ?? 0 ),
		absint( $settings['legal_notice_page_id'] ?? 0 ),
		absint( get_option( 'wp_page_for_privacy_policy' ) ),
	];

	$page_ids = array_unique( array_filter( $page_ids ) );

	if ( empty( $page_ids ) ) {
		return;
	}

	$map = surecookie_uninstall_shortcode_map( $settings );

	foreach ( $page_ids as $page_id ) {
		$content = get_post_field( 'post_content', $page_id );

		if ( ! is_string( $content ) || strpos( $content, '[surecookie_' ) === false ) {
			continue;
		}

		$frozen = surecookie_uninstall_strip_shortcodes(
			str_replace( array_keys( $map ), array_values( $map ), $content )
		);

		if ( $frozen === $content ) {
			continue;
		}

		// get_post_field hands back unslashed content and wp_insert_post unslashes
		// again, so an unslashed value silently eats every backslash on the page.
		wp_update_post(
			[
				'ID'           => $page_id,
				'post_content' => wp_slash( $frozen ),
			]
		);
	}
}

/**
 * Run all cleanup for a single site.
 *
 * @return void
 */
function surecookie_uninstall_site(): void {
	surecookie_publish_uninstall_decision();
	surecookie_delete_options();
	surecookie_delete_transients();
	surecookie_unschedule_crons();
	surecookie_drop_tables();
	surecookie_delete_cache_files();
}

/*
 * Main execution - multisite-aware cleanup.
 */
if ( is_multisite() ) {
	global $wpdb;

	// Get all active (non-archived, non-spam, non-deleted) site IDs.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$blog_ids = $wpdb->get_col(
		"SELECT blog_id FROM {$wpdb->blogs} WHERE archived = '0' AND spam = '0' AND deleted = '0'"
	);

	if ( is_array( $blog_ids ) ) {
		// An empty list means nothing was cleaned, so the network row must stay.
		$all_sites_cleaned = ! empty( $blog_ids );

		foreach ( $blog_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			// Before the settings are removed, so the values still resolve.
			surecookie_preserve_policy_pages();
			if ( surecookie_should_delete_data() ) {
				surecookie_uninstall_site();
			} else {
				$all_sites_cleaned = false;
			}
			restore_current_blog();
		}

		/*
		 * surecookie_db_version is stored with update_network_option() on multisite (see
		 * Maintenance::store_db_version), so the per-blog delete_option() above never removes
		 * it. Only drop the network row once every site is cleaned - else a site that opted
		 * out loses its schema-version tracking.
		 */
		if ( $all_sites_cleaned ) {
			delete_network_option( null, 'surecookie_db_version' );
		}
	}
} else {
	surecookie_preserve_policy_pages();

	if ( surecookie_should_delete_data() ) {
		surecookie_uninstall_site();
	}
}
