<?php
/**
 * Site Health Ability
 *
 * Read-only diagnostic roll-up: connection, scanning, blocking and integration
 * state in one call.
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress/Abilities
 * @since      1.4.0
 */

namespace SureCookie\Inc\Integrations\Wordpress\Abilities;

use SureCookie\Inc\API\Plugin as PluginApi;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Integrations\Wordpress\Base;
use SureCookie\Inc\Integrations\WpConsentApi\Init as WpConsentApi;
use SureCookie\Inc\Modules\AutomaticScanning\Scheduler;
use SureCookie\Inc\Modules\GoogleConsentMode\Consent_Handler;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;
use SureCookie\Inc\Modules\SiteScanner\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class SiteHealth
 *
 * @since 1.4.0
 */
class SiteHealth extends Base {
	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input The validated input data.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		try {
			$action = $input['action'] ?? 'overview';

			switch ( $action ) {
				case 'overview':
					return [
						'success' => true,
						'message' => __( 'Site health retrieved.', 'surecookie' ),
						'data'    => $this->build_overview(),
					];

				case 'conflicts':
					$plugins = PluginApi::get_instance()->detect_conflicting_plugins();

					return [
						'success' => true,
						'message' => __( 'Conflict scan complete.', 'surecookie' ),
						'data'    => [
							'plugins' => $plugins,
							'count'   => count( $plugins ),
						],
					];

				default:
					return [
						'success' => false,
						'message' => __( 'Invalid site health action.', 'surecookie' ),
						'data'    => [],
					];
			}
		} catch ( \Throwable $e ) {
			return [
				'success' => false,
				'message' => __( 'An unexpected error occurred while reading site health.', 'surecookie' ),
				'data'    => [],
			];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_name(): string {
		return 'surecookie/site-health';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label(): string {
		return __( 'Site Health', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description(): string {
		return __( 'Read-only diagnostics answering "why is SureCookie not doing what I expect?" in one call. Action "overview" reports whether the site is connected to the scanning service, the cached scan quota and plan, whether scheduled scanning will actually fire (WP-Cron can be disabled entirely, in which case it never will), which consent features are switched on, and which integrations are active. Action "conflicts" lists other active plugins that look like competing cookie-consent plugins, which is the usual cause of two banners or of consent being overwritten. This is a diagnostic summary, not a settings reader: surecookie/manage-settings action "get" is authoritative for reading settings and the only way to change them, and surecookie/site-scanner action "quota" is the way to force a fresh quota fetch. Nothing here contacts an external service or changes state, so it is always safe to call first when troubleshooting.', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_annotations(): array {
		return [
			'priority'        => 1.0,
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
			'instructions'    => 'Safe to call at any time and a good first step when the user reports that the banner, blocking or scanning is misbehaving. Every value is read from local state: the quota is the CACHED figure and may be stale, so use surecookie/site-scanner action "quota" with refresh when the number matters. If cron_status is "unavailable", scheduled scans will never run no matter how auto_scan_enabled is set, and that is worth saying plainly. If conflicts returns any plugin, treat it as the leading explanation for duplicate banners or overwritten consent, but confirm with the user before suggesting they deactivate anything; this ability cannot deactivate plugins and detection matches on plugin name and description, so an unrelated plugin can be flagged.',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'action' => [
					'type'        => 'string',
					'enum'        => [ 'overview', 'conflicts' ],
					'description' => __( 'Which diagnostic to run.', 'surecookie' ),
				],
			],
			'required'   => [ 'action' ],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'success' => [
					'type'        => 'boolean',
					'description' => __( 'Whether the diagnostic ran.', 'surecookie' ),
				],
				'message' => [
					'type'        => 'string',
					'description' => __( 'Result message.', 'surecookie' ),
				],
				'data'    => [
					'type'        => 'object',
					'description' => __( 'For "overview": connection, scanning, features, integrations and plan blocks. For "conflicts": the matching plugins and their count.', 'surecookie' ),
				],
			],
		];
	}

	/**
	 * Cron availability, without the loopback probe.
	 *
	 * Mirrors Helper::are_crons_available() except for its final branch, which
	 * makes a blocking request to wp-cron.php and writes an option; this ability
	 * promises neither. Every cheap branch is kept, so a site with a real server
	 * cron still gets a definitive answer rather than "unknown".
	 *
	 * @return string wp_cron, server_cron, unavailable, or unknown.
	 * @since 1.4.0
	 */
	private function cron_status(): string {
		if ( wp_doing_cron() ) {
			return 'wp_cron';
		}

		// Commonly set by a host that replaced WP-Cron with a real server cron.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return 'server_cron';
		}

		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			return 'wp_cron';
		}

		$cached = get_option( 'surecookie_cron_test_ok' );

		if ( $cached === 'yes' ) {
			return 'wp_cron';
		}

		// 'unknown' only when the probe has genuinely never run; this ability
		// will not trigger it.
		return $cached === 'no' ? 'unavailable' : 'unknown';
	}

	/**
	 * Pre-consent Consent Mode state, as granted/denied booleans per category.
	 *
	 * @return array<string, bool>
	 * @since 1.5.0
	 */
	private function gcm_default_consent(): array {
		$stored = Settings::get( 'gcm_default_consent' );
		$stored = is_array( $stored ) ? $stored : [];

		$state = [];
		foreach ( [ 'functional', 'analytics', 'marketing' ] as $category ) {
			$state[ $category ] = ! empty( $stored[ $category ] );
		}

		return $state;
	}

	/**
	 * Configured per-region Consent Mode overrides.
	 *
	 * @return array<int, mixed>
	 * @since 1.5.0
	 */
	private function gcm_region_defaults(): array {
		$stored = Settings::get( 'gcm_region_defaults' );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Assemble the diagnostic overview.
	 *
	 * Every call here is a plain read. Cron::get_scan_status() and
	 * SaasClient::is_scan_in_progress() are deliberately avoided: the first can
	 * complete a scan and persist cookies, the second can clean up the active
	 * scan option, and neither belongs behind a readOnly ability.
	 *
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	private function build_overview(): array {
		$saas   = SaasClient::get_instance();
		$quota  = $saas->get_cached_quota();
		$plan   = $saas->get_cached_plan();
		$active = $saas->get_active_scan();

		// `_plan` is an internal sentinel on the cached payload, reported as a
		// sibling field rather than leaked inside the quota block.
		unset( $quota['_plan'] );

		return [
			'connection'   => [
				// Prefix only. get_api_key() returns the plaintext key and must
				// never reach an MCP response.
				'registered' => $saas->get_key_prefix() !== '',
				'key_prefix' => $saas->get_key_prefix(),
				'local_site' => SaasClient::is_local_site(),
			],
			'scanning'     => [
				'quota'               => $quota,
				'quota_is_cached'     => true,
				'plan'                => $plan !== '' ? $plan : Utils::get_plan(),
				'max_pages_per_scan'  => Utils::get_max_scan_pages(),
				'scan_in_progress'    => ! empty( $active ),
				'auto_scan_enabled'   => (bool) Settings::get( 'auto_scan_enabled' ),
				'auto_scan_frequency' => Scheduler::effective_frequency(),
				'auto_scan_next_run'  => Scheduler::next_run(),
				'allowed_frequencies' => Scheduler::allowed_frequencies(),
				// 'unavailable' means a scheduled scan never fires whatever the
				// settings say; 'unknown' means the probe has not run and this
				// ability will not trigger it.
				'cron_status'         => $this->cron_status(),
			],
			'features'     => [
				'banner_enabled'          => (bool) Settings::get( 'banner_enabled' ),
				'blocking_enabled'        => (bool) Settings::get( 'blocking_enabled' ),
				'consent_logging_enabled' => (bool) Settings::get( 'consent_logging_enabled' ),
				'consent_model'           => (string) Settings::get( 'consent_model' ),
				'gcm_enabled'             => (bool) Settings::get( 'gcm_enabled' ),
				// gcm_enabled alone reads as a compliance positive. What actually
				// matters is the state Google is told before the visitor answers.
				'gcm_default_consent'     => $this->gcm_default_consent(),
				'gcm_region_rules'        => count( $this->gcm_region_defaults() ),
				'mcp_enabled'             => (bool) Settings::get( 'enable_mcp' ),
			],
			'integrations' => [
				'wp_consent_api'        => WpConsentApi::is_wp_consent_api_active(),
				'site_kit_consent_mode' => Consent_Handler::has_consent_mode_conflict(),
			],
			'plan_details' => apply_filters(
				/**
				 * Filters the site-health plan block.
				 *
				 * Free can see that Pro is installed but not whether its licence
				 * is active, so Pro fills that in.
				 *
				 * @param array<string, mixed> $plan Plan/licence details.
				 * @since 1.4.0
				 */
				'surecookie_site_health_plan',
				[
					'pro_installed' => Helper::is_pro_installed(),
					'pro_active'    => Helper::is_pro_active(),
				]
			),
		];
	}
}
