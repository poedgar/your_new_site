<?php
/**
 * Domain-control verification handler for the SaaS scanner registration flow.
 *
 * @package SureCookie\Inc\Modules\SiteScanner
 * @since 0.0.1-beta.3
 */

namespace SureCookie\Inc\Modules\SiteScanner;

use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issue #473 - HTTP-token domain verification challenge.
 *
 * During /api/register, the SureCookie SaaS issues a verification_token and
 * then GETs `https://{site}/wp-json/surecookie/v1/site-verify/{token}` to
 * confirm the requester actually controls the domain. This handler reads the
 * pending token from the transient set by SaasClient::register_site() and
 * echoes it back when the path token matches.
 *
 * The endpoint is intentionally public (no nonce / no auth) - the SaaS calls
 * it from a different origin without WordPress credentials. The shared secret
 * is the verification_token itself, which is single-use, time-limited, and
 * only valid while the matching install_nonce is also in flight on the SaaS.
 *
 * @since 0.0.1-beta.3
 */
class VerificationHandler {
	use GetInstance;

	/**
	 * Constructor - hook the route registration onto rest_api_init.
	 *
	 * @since 0.0.1-beta.3
	 */
	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the site-verify route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'surecookie/v1',
			'/site-verify/(?P<token>[a-f0-9]{64})',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'serve_verification_token' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'token' => [
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && (bool) preg_match( '/^[a-f0-9]{64}$/', $value );
						},
					],
				],
			]
		);
	}

	/**
	 * Echo the pending verification token if it matches the URL path token.
	 *
	 * Returns a JSON envelope (rather than plain text) so the standard WP
	 * REST serializer doesn't add quote marks the SaaS comparison wouldn't
	 * expect. Constant-time comparison via hash_equals() prevents an attacker
	 * from probing valid tokens through timing side channels.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response Token JSON on success, 404 otherwise.
	 */
	public function serve_verification_token( WP_REST_Request $request ): WP_REST_Response {
		$path_token = (string) $request->get_param( 'token' );
		$pending    = get_transient( SaasClient::PENDING_VERIFICATION_TRANSIENT );

		// All failure paths return an identical 404 body so an external observer can't distinguish "no registration in flight" from "wrong
		// token" - minimizes the state information leaked by this public endpoint (issue #473 review feedback).
		$ok = is_string( $pending ) && $pending !== '' && hash_equals( $pending, $path_token );

		if ( ! $ok ) {
			return new WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}

		$response = new WP_REST_Response( [ 'token' => $pending ], 200 );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}
}
