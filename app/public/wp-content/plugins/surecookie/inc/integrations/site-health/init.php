<?php
/**
 * Site Health reporting for visitor IP visibility.
 *
 * Trusting forwarded headers is opt-in, correctly so, and the cost of that
 * default is silence: a site behind a proxy gets the proxy's address for every
 * visitor with nothing anywhere saying so. Reports it; changes nothing.
 *
 * @package SureCookie\Inc\Integrations\SiteHealth
 * @since   1.5.1
 */

namespace SureCookie\Inc\Integrations\SiteHealth;

use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Traits\IpManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init class.
 *
 * @since 1.5.1
 */
class Init {
	use GetInstance;
	use IpManager;

	/**
	 * Site Health test id.
	 *
	 * @since 1.5.1
	 */
	private const TEST_ID = 'surecookie_visitor_ip';

	/**
	 * Constructor.
	 *
	 * @since 1.5.1
	 */
	public function __construct() {
		add_filter( 'site_status_tests', [ $this, 'register_test' ] );
		add_filter( 'debug_information', [ $this, 'add_debug_information' ] );
	}

	/**
	 * Register the direct test.
	 *
	 * `skip_cron` is required, not optional: the weekly scheduled check runs
	 * direct tests inside the WP-Cron loopback request, whose peer is the server
	 * itself, so without it every install stores `local` and reports a proxy it
	 * does not have.
	 *
	 * Untyped, and both the outer value and the group are checked: this is an
	 * open contract, so another plugin's value must not become our fatal.
	 *
	 * @param mixed $tests Registered Site Health tests.
	 * @since 1.5.1
	 * @return mixed
	 */
	public function register_test( $tests = null ) {
		if ( ! is_array( $tests ) || ( isset( $tests['direct'] ) && ! is_array( $tests['direct'] ) ) ) {
			return $tests;
		}

		$tests['direct'][ self::TEST_ID ] = [
			'label'     => __( 'Visitor identification', 'surecookie' ),
			'test'      => [ $this, 'run_test' ],
			'skip_cron' => true,
		];

		return $tests;
	}

	/**
	 * Report which address the plugin resolves as the visitor.
	 *
	 * @since 1.5.1
	 * @return array<string, mixed>
	 */
	public function run_test(): array {
		$source = self::visitor_ip_source();

		$result = [
			'label'       => __( 'SureCookie can identify your visitors', 'surecookie' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'Privacy', 'surecookie' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html__( 'Visitor addresses reach SureCookie directly, so geographic rules, consent-log countries and per-visitor rate limits all describe the real visitor.', 'surecookie' ) . '</p>',
			'actions'     => '',
			'test'        => self::TEST_ID,
		];

		if ( $source === 'visitor' ) {
			return $result;
		}

		$result['status'] = 'recommended';
		$result['label']  = __( 'SureCookie sees your proxy instead of your visitors', 'surecookie' );

		$result['description'] = '<p>' . ( $source === 'cdn'
			? esc_html__( 'Requests reach this site through a CDN, so every address SureCookie resolves belongs to the CDN edge rather than to your visitor.', 'surecookie' )
			: esc_html__( 'Requests reach this site through a reverse proxy, so every address SureCookie resolves belongs to that proxy rather than to your visitor.', 'surecookie' )
		) . '</p><p>'
			. esc_html__( 'Three things are affected: geographic rules resolve the wrong country, so a visitor can be shown the wrong consent model; consent logs record that wrong country; and the per-visitor rate limits collapse into one shared bucket.', 'surecookie' )
			. '</p>';

		$result['actions'] = $source === 'cdn' ? $this->cdn_actions() : $this->local_actions();

		return $result;
	}

	/**
	 * Add the resolved source to the Site Health info tab.
	 *
	 * Carries an untranslated `debug` value beside the label, so an exported
	 * report is readable whatever locale produced it.
	 *
	 * @param mixed $info Debug information sections.
	 * @since 1.5.1
	 * @return mixed
	 */
	public function add_debug_information( $info = null ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$source = self::visitor_ip_source();
		$labels = [
			'visitor' => __( 'Visitor address (direct)', 'surecookie' ),
			'cdn'     => __( 'CDN edge address - forwarded headers not trusted', 'surecookie' ),
			'local'   => __( 'Reverse proxy address - forwarded headers not trusted', 'surecookie' ),
		];

		$fields = [
			'visitor_ip_source' => [
				'label' => __( 'Visitor IP source', 'surecookie' ),
				'value' => $labels[ $source ] ?? $source,
				'debug' => $source,
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
	 * Remediation for a CDN peer.
	 *
	 * @since 1.5.1
	 * @return string
	 */
	private function cdn_actions(): string {
		return '<p>' . esc_html__( 'Forwarded headers are already trusted from a recognised proxy, and only X-Forwarded-For is read. Your CDN is either not sending that header or is sending its own instead, so name the header it overwrites on every request. Name only that one: a header a proxy merely passes through would let a visitor choose their own country and rate-limit bucket.', 'surecookie' ) . '</p>'
			. '<pre><code>' . esc_html(
				"add_filter( 'surecookie_trusted_client_ip_headers', function () {\n"
				. "    return array( 'CF-Connecting-IP' );\n"
				. '} );'
			) . '</code></pre>'
			. '<p>' . esc_html__( 'That header is correct for Cloudflare, whose edge ranges ship recognised. On any other CDN, substitute the header your provider documents as overwritten and add its published ranges through the surecookie_trusted_proxy_ips filter, or its forwarded header stays untrusted and nothing changes.', 'surecookie' ) . '</p>';
	}

	/**
	 * Remediation for a reverse proxy.
	 *
	 * @since 1.5.1
	 * @return string
	 */
	private function local_actions(): string {
		return '<p>' . esc_html__( 'Your reverse proxy is not passing the visitor address on at all. Prefer fixing it in the server, where every plugin benefits: with nginx, ngx_http_realip_module rewrites the peer address itself, so SureCookie needs no configuration.', 'surecookie' ) . '</p>'
			. '<pre><code>' . esc_html( "set_real_ip_from 10.0.0.0/8;\nreal_ip_header X-Forwarded-For;" ) . '</code></pre>'
			. '<p>' . esc_html__( 'Passing the header to PHP works too, and needs nothing on the SureCookie side because a recognised proxy is already trusted:', 'surecookie' ) . '</p>'
			. '<pre><code>' . esc_html( 'fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;' ) . '</code></pre>'
			. '<p>' . esc_html__( 'Use $proxy_add_x_forwarded_for, which appends. A proxy that relays the visitor\'s own X-Forwarded-For untouched leaves nothing to tell a forged value from a real one, which would let a visitor pick their own country and per-visitor rate-limit bucket.', 'surecookie' ) . '</p>';
	}
}
