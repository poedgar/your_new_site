<?php
/**
 * Author Email Notice class
 *
 * REST endpoint for dismissing the notice shown on the Schema screen after the
 * author email cleanup has run.
 *
 * @package SureRank\Inc\API
 * @since 1.10.1
 */

namespace SureRank\Inc\API;

use SureRank\Inc\Functions\Send_Json;
use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Upgrades\Author_Email_Cleanup;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Author_Email_Notice
 *
 * The cleanup sets a flag on sites where it actually cleared a saved author
 * email. That flag is what shows the notice, so dismissing the notice just
 * removes it. Dismissal is site-wide, matching the change it describes.
 *
 * @since 1.10.1
 */
class Author_Email_Notice extends Api_Base {
	use Get_Instance;

	/**
	 * Route - dismiss the notice.
	 */
	protected const DISMISS = '/author-email-notice/dismiss';

	/**
	 * Register API routes.
	 *
	 * @since 1.10.1
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->get_api_namespace(),
			self::DISMISS,
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'dismiss' ],
					'permission_callback' => [ $this, 'validate_permission' ],
					'role_capability'     => 'global_setting',
				],
			]
		);
	}

	/**
	 * Dismiss the notice for this site.
	 *
	 * @since 1.10.1
	 * @return void
	 */
	public function dismiss() {
		delete_option( Author_Email_Cleanup::NOTICE_OPTION );

		Send_Json::success(
			[
				'message' => __( 'Notice dismissed.', 'surerank' ),
			]
		);
	}
}
