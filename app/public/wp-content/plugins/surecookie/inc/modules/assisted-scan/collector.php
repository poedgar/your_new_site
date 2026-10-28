<?php
/**
 * Front-end half of an assisted scan: turns one public page request into a
 * collectable scan page.
 *
 * Gated on BOTH `manage_options` AND a valid session token; without them the page
 * renders exactly as a visitor sees it, so there is nothing to probe for.
 *
 * The request modifications exist for fidelity, since an admin does not see what a
 * visitor sees:
 *  - script blocking off, so real third-party tags run and set real cookies (not
 *    our neutered `type="text/plain"` placeholders);
 *  - admin bar hidden, else its assets get reported as findings;
 *  - consent banner dropped, so a stored consent choice cannot suppress anything.
 *
 * @package SureCookie\Inc\Modules\AssistedScan
 * @since   1.3.0
 */

namespace SureCookie\Inc\Modules\AssistedScan;

use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Collector
 *
 * @since 1.3.0
 */
class Collector {
	use GetInstance;

	/**
	 * Script handle for the collector bundle.
	 *
	 * @since 1.3.0
	 */
	private const HANDLE = 'surecookie-assisted-scan';

	/**
	 * Whether this request is a verified scan page request.
	 *
	 * @since 1.3.0
	 * @var bool
	 */
	private $active = false;

	/**
	 * Index of the page being collected.
	 *
	 * @since 1.3.0
	 * @var int
	 */
	private $page_index = 0;

	/**
	 * Whether the one-time cookie reset has already happened.
	 *
	 * @since 1.3.0
	 * @var bool
	 */
	private $did_reset = false;

	/**
	 * Constructor.
	 *
	 * Runs on `init`, early enough to win every hook needed: `show_admin_bar` fires
	 * on `template_redirect`, headers later, and no output has been produced yet.
	 *
	 * @since 1.3.0
	 */
	private function __construct() {
		if ( ! $this->detect() ) {
			return;
		}

		$this->active = true;

		// See the file docblock: fidelity, not convenience.
		add_filter( 'surecookie_should_block_scripts', '__return_false' );
		// Unblocking the tags is only half of it: Google Consent Mode would still
		// tell them to store nothing, so they would run and set no cookies.
		add_filter( 'surecookie_is_scan_probe', '__return_true' );
		add_filter( 'show_admin_bar', '__return_false' );

		// A scan URL must never be indexed or cached.
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_action( 'send_headers', [ $this, 'send_headers' ] );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		// Priority 999: after every normal enqueue, so ours is the last word.
		add_action( 'wp_enqueue_scripts', [ $this, 'dequeue_banner' ], 999 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_collector' ], 1000 );

		// The interceptor has to beat every other script on the page.
		add_action( 'wp_head', [ $this, 'print_cookie_interceptor' ], -PHP_INT_MAX );
	}

	/**
	 * Resolve a query string into a scan context, or nothing.
	 *
	 * Free of superglobals so the gate is directly testable: this is the decision
	 * between a normal page view and one running with script blocking disabled.
	 *
	 * @param array<string, mixed> $query Unslashed request query arguments.
	 * @since 1.3.0
	 * @return array{page_index: int, did_reset: bool}|null Context, or null when this is not a scan request.
	 */
	public static function resolve( array $query ): ?array {
		$raw = isset( $query[ Session::TOKEN_ARG ] ) && is_string( $query[ Session::TOKEN_ARG ] )
			? sanitize_text_field( $query[ Session::TOKEN_ARG ] )
			: '';

		if ( preg_match( '/^[0-9a-f]{64}$/', $raw ) !== 1 ) {
			return null;
		}

		// Capability first: cheaper than the option read, and it is the check that
		// makes a leaked token useless to anyone else.
		if ( ! current_user_can( Helper::capability() ) ) {
			return null;
		}

		if ( ! Session::get_instance()->verify_token( $raw ) ) {
			return null;
		}

		return [
			'page_index' => isset( $query[ Session::PAGE_ARG ] ) ? absint( $query[ Session::PAGE_ARG ] ) : 0,
			'did_reset'  => ! empty( $query[ Session::RESET_ARG ] ),
		];
	}

	/**
	 * Whether this request is a verified scan page request.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->active;
	}

	/**
	 * Keep the scan URL out of caches and out of third-party Referer headers.
	 *
	 * The session token travels in the query string, so without a referrer policy
	 * every third-party tag would receive the full scan URL (token included) in its
	 * `Referer`. `same-origin` stops that while keeping same-site reporting intact.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function send_headers(): void {
		nocache_headers();

		foreach ( self::response_headers() as $name => $value ) {
			header( $name . ': ' . $value, true );
		}
	}

	/**
	 * Headers a scan page request must carry.
	 *
	 * @since 1.3.0
	 * @return array<string, string> Header name => value.
	 */
	public static function response_headers(): array {
		return [
			// Else third-party tags would get the full scan URL (token) in Referer.
			'Referrer-Policy' => 'same-origin',
			'X-Robots-Tag'    => 'noindex, nofollow',
		];
	}

	/**
	 * Drop the consent banner for this request.
	 *
	 * Dequeued rather than filtered off, so this works regardless of why the
	 * banner was enqueued (banner enabled, a re-consent surface, a shortcode).
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function dequeue_banner(): void {
		wp_dequeue_script( 'surecookie-public' );
		wp_dequeue_style( 'surecookie-public' );
	}

	/**
	 * Print the early `document.cookie` interceptor.
	 *
	 * The only way to learn a cookie's real lifetime: `document.cookie` exposes no
	 * attributes, so an already-set cookie cannot be dated, and a persistent tracker
	 * reported as a session cookie would be a false statement on the public cookie
	 * policy. Wrapping the setter captures the `Max-Age` / `Expires` the tag asked for.
	 *
	 * Inline in `<head>` rather than in the bundle because it must run before any
	 * other script - Analytics and Tag Manager write cookies during their own load.
	 *
	 * The captured string still holds the cookie value here; nothing transmits it -
	 * the bundle strips it before sending and the server drops the field on arrival.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function print_cookie_interceptor(): void {
		$script = <<<'JS'
( function () {
	var target = Object.getOwnPropertyDescriptor( Document.prototype, 'cookie' );
	if ( ! target || ! target.set || ! target.get ) {
		return;
	}
	var queue = { writes: [], dropped: 0, cap: 500 };
	window.__surecookieAssisted = queue;
	try {
		Object.defineProperty( document, 'cookie', {
			configurable: true,
			get: function () {
				return target.get.call( document );
			},
			set: function ( value ) {
				try {
					if ( queue.writes.length < queue.cap ) {
						queue.writes.push( {
							raw: String( value ),
							at: Date.now(),
							stack: ( new Error() ).stack || ''
						} );
					} else {
						queue.dropped++;
					}
				} catch ( e ) {}
				return target.set.call( document, value );
			}
		} );
	} catch ( e ) {}
}() );
JS;

		wp_print_inline_script_tag( $script, [ 'id' => 'surecookie-assisted-interceptor' ] );
	}

	/**
	 * Enqueue and configure the collector bundle.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function enqueue_collector(): void {
		$assets_file = plugin_dir_path( SURECOOKIE_FILE ) . 'build/assisted-scan.asset.php';

		if ( ! file_exists( $assets_file ) ) {
			return;
		}

		$assets_info = include $assets_file;

		wp_enqueue_script(
			self::HANDLE,
			SURECOOKIE_URL . 'build/assisted-scan.js',
			is_array( $assets_info['dependencies'] ?? null ) ? $assets_info['dependencies'] : [],
			is_string( $assets_info['version'] ?? null ) ? $assets_info['version'] : SURECOOKIE_VERSION,
			[ 'in_footer' => true ]
		);

		// wp_localize_script() casts every top-level scalar to a string, which turns
		// pageIndex 5 into "5" (so "5" + 1 rendered "page 51 of 10") and booleans into
		// "1"/"". Injecting real JSON keeps ints and booleans intact.
		wp_add_inline_script(
			self::HANDLE,
			'window.surecookieAssistedScan = ' . wp_json_encode( self::build_config( $this->page_index, $this->did_reset ) ) . ';',
			'before'
		);
	}

	/**
	 * Configuration handed to the collector bundle.
	 *
	 * Every user-facing string is passed in from here. The bundle deliberately
	 * declares no `wp-i18n` dependency, so it cannot translate at runtime.
	 *
	 * @param int  $page_index Index of the page being collected.
	 * @param bool $did_reset  Whether the one-time cookie reset already happened.
	 * @since 1.3.0
	 * @return array<string, mixed>
	 */
	public static function build_config( int $page_index, bool $did_reset ): array {
		$state = Session::get_instance()->get();

		return [
			'token'       => (string) ( $state['token'] ?? '' ),
			'pageIndex'   => $page_index,
			'totalPages'  => count( $state['pages'] ?? [] ),
			'isFirstPage' => $page_index === 0,
			'didReset'    => $did_reset,
			'resetArg'    => Session::RESET_ARG,
			// The page's own host, so a cookie with no Domain attribute is recorded
			// against the host it is really scoped to.
			'pageHost'    => Normalizer::request_host(),
			'preserve'    => Normalizer::ignored_prefixes(),
			'limits'      => [
				'cookies'   => Session::MAX_COOKIES_PER_PAGE,
				'resources' => Session::MAX_RESOURCES_PER_PAGE,
			],
			'timing'      => [
				'quietMs' => 1200,
				'minMs'   => 2000,
				'maxMs'   => 8000,
			],
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			// Full endpoint URLs, so this works on plain-permalink sites too.
			'api'         => [
				'page'   => esc_url_raw( rest_url( 'surecookie/v1/assisted-scan/page' ) ),
				'finish' => esc_url_raw( rest_url( 'surecookie/v1/assisted-scan/finish' ) ),
				'cancel' => esc_url_raw( rest_url( 'surecookie/v1/assisted-scan/cancel' ) ),
			],
			'i18n'        => [
				/* translators: 1: current page number, 2: total pages. */
				'progress'  => __( 'SureCookie is scanning page %1$d of %2$d', 'surecookie' ),
				'keepFront' => __( 'Keep this tab in front so the scan can continue.', 'surecookie' ),
				'paused'    => __( 'Paused. Bring this tab to the front to continue.', 'surecookie' ),
				'resetting' => __( 'Preparing a clean visit…', 'surecookie' ),
				/* translators: 1: number of cookies, 2: number of services. */
				'complete'  => __( 'Scan complete: %1$d cookies, %2$d services found', 'surecookie' ),
				'failed'    => __( 'The scan could not be completed. Return to SureCookie to try again.', 'surecookie' ),
				'cancel'    => __( 'Cancel', 'surecookie' ),
				'label'     => __( 'SureCookie assisted scan progress', 'surecookie' ),
			],
		];
	}

	/**
	 * Whether this request carries a usable scan token.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	private function detect(): bool {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only gate; the token is itself the credential and is verified in resolve().
		$context = self::resolve( wp_unslash( $_GET ) );

		if ( $context === null ) {
			return false;
		}

		$this->page_index = $context['page_index'];
		$this->did_reset  = $context['did_reset'];

		return true;
	}
}
