<?php
/**
 * Author Meta Data
 *
 * This file will handle functionality to print meta_data in frontend for author archive requests.
 *
 * @package surerank
 * @since 1.9.0
 */

namespace SureRank\Inc\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Functions\Validate;
use SureRank\Inc\Functions\Variables;
use SureRank\Inc\Meta_Variables\User;
use SureRank\Inc\Traits\Get_Instance;

/**
 * Author SEO
 * This class will handle functionality to print meta_data in frontend for author archive requests.
 *
 * @since 1.9.0
 */
class Author {

	use Get_Instance;

	/**
	 * Meta Data
	 *
	 * @var array<string, mixed>|null $meta_data User meta data.
	 * @since 1.9.0
	 */
	private $meta_data = null;

	/**
	 * Constructor
	 *
	 * @since 1.9.0
	 */
	public function __construct() {
		add_filter( 'surerank_set_meta', [ $this, 'get_meta_data' ], 1 );
		add_filter( 'surerank_enable_archive_auto_title', [ $this, 'enable_archive_auto_title' ] );
	}

	/**
	 * Enable document title replacement on author archives only.
	 *
	 * The Title class gates archive title replacement behind this filter
	 * (default false). Author archives opt in so the per-user page_title
	 * reaches the document <title>; other archives keep current behavior.
	 *
	 * @param bool $enabled Whether archive auto title is enabled.
	 * @since 1.9.0
	 * @return bool
	 */
	public function enable_archive_auto_title( $enabled ) {
		if ( ! apply_filters( 'surerank_enable_user_seo_settings', true ) ) {
			return $enabled;
		}
		return is_author() ? true : $enabled;
	}

	/**
	 * Add meta data
	 *
	 * @param array<string, mixed> $meta_data Meta Data.
	 * @since 1.9.0
	 * @return array<string, mixed>
	 */
	public function get_meta_data( $meta_data ) {
		if ( ! is_author() || ! apply_filters( 'surerank_enable_user_seo_settings', true ) ) {
			return $meta_data;
		}

		$user_id = (int) get_queried_object_id();
		if ( ! $user_id ) {
			return $meta_data;
		}

		if ( null !== $this->meta_data ) {
			return $meta_data;
		}

		User::get_instance()->set_user( $user_id );
		/**
		 * Pass 0 as the post ID: Variables::replace() seeds Post::set_post()
		 * with whatever ID it receives, and a post sharing the user's numeric
		 * ID would otherwise leak into post-scoped variables. The User
		 * instance set above resolves all author variables.
		 */
		$this->meta_data = Variables::replace( Validate::array( Settings::prep_user_meta( $user_id ) ), 0 );

		return $this->meta_data;
	}
}
