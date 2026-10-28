<?php
/**
 * Site Health reporting for consent-log delivery.
 *
 * A host rule protecting `error-log`/`debug-log` matches any URL path ending
 * in `-log`, so `/wp-json/surecookie/v1/consent-logs/create-log` is answered
 * before WordPress loads: no hook fires, no counter moves, and the owner sees
 * an empty Consent Logs screen with everything else working. The frontend now
 * retries such a write on the query-string route; this probe is what says so
 * out loud, and it answers with no visitor traffic at all (#1275).
 *
 * Reports only; it never changes how a write is routed.
 *
 * @package SureCookie\Inc\Integrations\SiteHealth
 * @since   1.7.0
 */

namespace SureCookie\Inc\Integrations\SiteHealth;

use SureCookie\Inc\API\ConsentLogs;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\LocalizeData;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Consent_Write.
 *
 * @since 1.7.0
 */
class Consent_Write {
	use GetInstance;

	/**
	 * Site Health test id.
	 *
	 * @since 1.7.0
	 */
	private const TEST_ID = 'surecookie_consent_write';

	/**
	 * Transient holding the last probe verdict.
	 *
	 * @since 1.7.0
	 */
	private const CACHE_KEY = 'surecookie_consent_write_probe';

	/**
	 * How long a verdict is reused. Each miss costs up to two loopback
	 * requests, and this test is also run by the weekly Site Health cron,
	 * which is what normally keeps it warm.
	 *
	 * @since 1.7.0
	 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @since 1.7.0
	 */
	public function __construct() {
		add_filter( 'site_status_tests', [ $this, 'register_test' ] );
		add_filter( 'debug_information', [ $this, 'add_debug_information' ] );
	}

	/**
	 * Register the direct test.
	 *
	 * Untyped, and both the outer value and the group are checked: this is an
	 * open contract, so another plugin's value must not become our fatal.
	 *
	 * @param mixed $tests Registered Site Health tests.
	 * @since 1.7.0
	 * @return mixed
	 */
	public function register_test( $tests = null ) {
		if ( ! is_array( $tests ) || ( isset( $tests['direct'] ) && ! is_array( $tests['direct'] ) ) ) {
			return $tests;
		}

		$tests['direct'][ self::TEST_ID ] = [
			'label' => __( 'Consent log delivery', 'surecookie' ),
			'test'  => [ $this, 'run_test' ],
		];

		return $tests;
	}

	/**
	 * Report whether a consent write reaches WordPress.
	 *
	 * @since 1.7.0
	 * @return array<string, mixed>
	 */
	public function run_test(): array {
		$result = [
			'label'       => __( 'SureCookie is recording consent', 'surecookie' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'Privacy', 'surecookie' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html__( 'Consent writes reach WordPress, so every visitor decision is being added to your consent log.', 'surecookie' ) . '</p>',
			'actions'     => '',
			'test'        => self::TEST_ID,
		];

		switch ( self::probe() ) {
			case 'ok':
			case 'disabled':
				return $result;

			case 'fallback':
				$result['status']      = 'recommended';
				$result['label']       = __( 'SureCookie is routing consent writes around your server', 'surecookie' );
				$result['description'] = '<p>' . esc_html__( 'Something on your server answers the consent log address before WordPress sees it, which is usually a firewall rule protecting files whose names end in -log. Consent is still being recorded, because SureCookie retries each write on a second address, but every write now costs two requests.', 'surecookie' ) . '</p>';
				$result['actions']     = self::host_action();
				return $result;

			case 'blocked':
				$result['status']      = 'critical';
				$result['label']       = __( 'SureCookie cannot record consent', 'surecookie' );
				$result['description'] = '<p>' . esc_html__( 'Neither consent log address reaches WordPress, so visitor decisions are not being saved and your Consent Logs screen will stay empty. The requests are answered before WordPress loads, which is why nothing is reported anywhere else.', 'surecookie' ) . '</p>';
				$result['actions']     = self::host_action();
				return $result;

			case 'restricted':
				$result['status']      = 'critical';
				$result['label']       = __( 'SureCookie cannot record consent', 'surecookie' );
				$result['description'] = '<p>' . esc_html__( 'WordPress refuses the consent log address for visitors who are not logged in, so visitor decisions are not being saved and your Consent Logs screen will stay empty. A plugin or code snippet that restricts the REST API to logged-in users usually causes this.', 'surecookie' ) . '</p>';
				$result['actions']     = '<p>' . esc_html__( 'Allow these REST API routes for visitors who are not logged in, in whatever restricts your REST API:', 'surecookie' ) . '</p><pre><code>/surecookie/v1/consent-logs/</code></pre>';
				return $result;
		}

		$result['status']      = 'recommended';
		$result['label']       = __( 'SureCookie could not check consent recording', 'surecookie' );
		$result['description'] = '<p>' . esc_html__( 'This site could not make a request to itself, so whether consent writes arrive is unknown. That usually means outgoing requests are blocked, and WordPress reports its own loopback failure separately.', 'surecookie' ) . '</p>';

		return $result;
	}

	/**
	 * Add the probe verdict and the rejected-token count to the info tab.
	 *
	 * The counter is otherwise write-only, which left the one measurable
	 * symptom of silently dropped consent logs unreadable in the field.
	 *
	 * @param mixed $info Debug information sections.
	 * @since 1.7.0
	 * @return mixed
	 */
	public function add_debug_information( $info = null ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$verdict = self::probe();
		$labels  = [
			'ok'         => __( 'Reaching WordPress', 'surecookie' ),
			'fallback'   => __( 'Blocked - retried on the query-string route', 'surecookie' ),
			'blocked'    => __( 'Blocked on both routes', 'surecookie' ),
			'restricted' => __( 'Refused by WordPress for logged-out visitors', 'surecookie' ),
			'disabled'   => __( 'Consent logging is turned off', 'surecookie' ),
			'unknown'    => __( 'Could not be checked - no loopback', 'surecookie' ),
		];

		$fields = [
			'consent_write_route'      => [
				'label' => __( 'Consent log delivery', 'surecookie' ),
				'value' => $labels[ $verdict ] ?? $verdict,
				'debug' => $verdict,
			],
			'consent_token_rejections' => [
				'label' => __( 'Consent writes rejected today (stale token)', 'surecookie' ),
				'value' => (string) ConsentLogs::get_token_rejection_count(),
			],
		];

		// Merge rather than assign: another module may already own this section.
		$info['surecookie']['label']  = 'SureCookie';
		$info['surecookie']['fields'] = array_merge(
			isset( $info['surecookie']['fields'] ) && is_array( $info['surecookie']['fields'] ) ? $info['surecookie']['fields'] : [],
			$fields
		);

		return $info;
	}

	/**
	 * Cached verdict: ok, fallback, blocked, restricted, disabled or unknown.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	private static function probe(): string {
		if ( ! Settings::get( 'consent_logging_enabled' ) ) {
			return 'disabled';
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		$endpoints = LocalizeData::consent_log_endpoints();
		$canonical = self::wordpress_code( $endpoints['create_log'] );
		// The token route is the control: same namespace, no `-log` suffix.
		$control = in_array( $canonical, [ null, 'rest_no_route' ], true ) ? null : self::wordpress_code( $endpoints['token'] );

		if ( $canonical === 'rest_no_route' ) {
			$verdict = 'ok';
		} elseif ( $control === 'rest_no_route' ) {
			$fallback = $endpoints['create_log_fallback'] ?? '';
			$verdict  = $fallback !== '' && self::wordpress_code( $fallback ) === 'rest_no_route' ? 'fallback' : 'blocked';
		} elseif ( (string) $control !== '' ) {
			// WordPress itself refused the control too, so it turns every logged-out visitor away.
			$verdict = 'restricted';
		} else {
			// Refused as well means the probe itself is being turned away - a
			// bot rule, basic auth, a blocked loopback - so nothing was measured
			// and reporting a block would be a guess.
			$verdict = 'unknown';
		}

		set_transient( self::CACHE_KEY, $verdict, self::CACHE_TTL );

		return $verdict;
	}

	/**
	 * The WordPress error code a URL answers with.
	 *
	 * GET on a write-only route, so WordPress replies `rest_no_route` and
	 * nothing is stored, no token is spent and no rejection is counted. Any
	 * other code is WordPress refusing the caller, as a REST-restricting plugin
	 * refuses logged-out visitors' writes with a JSON 401.
	 *
	 * Redirects are followed: a canonicalising http-to-https or www hop answers
	 * with an HTML body that would otherwise read as a block.
	 *
	 * @param string $url Endpoint to probe.
	 * @since 1.7.0
	 * @return string|null '' when something other than WordPress answered, null when nothing did.
	 */
	private static function wordpress_code( string $url ): ?string {
		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 5,
				'sslverify'   => false,
				'redirection' => 3,
				'user-agent'  => 'SureCookie-Loopback',
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = json_decode( (string) wp_remote_retrieve_body( $response ), true )['code'] ?? '';

		return is_string( $code ) ? $code : '';
	}

	/**
	 * Remediation shared by the blocked and fallback verdicts.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	private static function host_action(): string {
		return '<p>' . esc_html__( 'Ask your host to stop blocking this address. It is a WordPress REST route, not a log file, and the rule is matching it only because the address ends in -log:', 'surecookie' ) . '</p>'
			. '<pre><code>' . esc_html( LocalizeData::consent_log_endpoints()['create_log'] ) . '</code></pre>';
	}
}
