<?php
/**
 * User class
 *
 * @package surerank
 * @since 1.9.0
 */

namespace SureRank\Inc\Meta_Variables;

use SureRank\Inc\Frontend\Description;
use SureRank\Inc\Traits\Get_Instance;
use WP_User;

/**
 * This class deals with variables related to users (author archives).
 *
 * @since 1.9.0
 */
class User extends Variables {

	/**
	 * Note: the Custom_Field trait is intentionally NOT used here. For users it
	 * would expose raw usermeta (session tokens, capabilities, admin prefs) as
	 * %custom_field.*% variables — its `_`/`surerank_` prefix filter only suits
	 * post/term meta.
	 */
	use Get_Instance;

	/**
	 * Stores variables array.
	 *
	 * @var array<string, mixed>
	 * @since 1.9.0
	 */
	public $variables = [];

	/**
	 * Category of variables.
	 *
	 * @var string
	 * @since 1.9.0
	 */
	public $category = 'user';

	/**
	 * Stores current user.
	 *
	 * @var WP_User|null
	 * @since 1.9.0
	 */
	public $user;

	/**
	 * Constructor
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public function __construct() {
		$this->variables = [
			'ID'                 => [
				'label'       => __( 'ID', 'surerank' ),
				'description' => __( 'The unique identifier for a user.', 'surerank' ),
			],
			'author_name'        => [
				'label'       => __( 'Author Name', 'surerank' ),
				'description' => __( 'The display name of the author.', 'surerank' ),
			],
			'author_description' => [
				'label'       => __( 'Author Bio', 'surerank' ),
				'description' => __( 'The biographical info of the author.', 'surerank' ),
			],
			'author_nicename'    => [
				'label'       => __( 'Author Nicename', 'surerank' ),
				'description' => __( 'The URL-friendly name of the author.', 'surerank' ),
			],
			'permalink'          => [
				'label'       => __( 'Permalink', 'surerank' ),
				'description' => __( 'The author archive URL.', 'surerank' ),
			],
		];
	}

	/**
	 * Get current user id
	 *
	 * @since 1.9.0
	 * @return int|false
	 */
	public function get_ID() {
		if ( ! empty( $this->user ) ) {
			return $this->user->ID;
		}
		return false;
	}

	/**
	 * Get display name of current user.
	 *
	 * Returns false (not '') when no user is set so this class never shadows
	 * the Post class' author_name variable during frontend replacement.
	 *
	 * @since 1.9.0
	 * @return string|false
	 */
	public function get_author_name() {
		if ( ! empty( $this->user ) ) {
			return $this->user->display_name;
		}
		return false;
	}

	/**
	 * Get current user biographical info.
	 *
	 * @since 1.9.0
	 * @return string|false
	 */
	public function get_author_description() {
		if ( ! empty( $this->user ) ) {
			return Description::get_instance()->sanitize_description( (string) get_the_author_meta( 'description', $this->user->ID ) );
		}
		return false;
	}

	/**
	 * Get nicename of current user.
	 *
	 * @since 1.9.0
	 * @return string|false
	 */
	public function get_author_nicename() {
		if ( ! empty( $this->user ) ) {
			return $this->user->user_nicename;
		}
		return false;
	}

	/**
	 * Returns author archive permalink of current user.
	 *
	 * @since 1.9.0
	 * @return string|false
	 */
	public function get_permalink() {
		if ( ! empty( $this->user ) ) {
			return get_author_posts_url( $this->user->ID );
		}
		return false;
	}

	/**
	 * This function sets $user variable, required for meta_variables based on user.
	 *
	 * @param int $user_id User id to set $user variable to retrieve relevant variables.
	 * @since 1.9.0
	 * @return void
	 */
	public function set_user( $user_id = 0 ) {
		if ( ! empty( $user_id ) ) {
			$user       = get_user_by( 'id', $user_id );
			$this->user = $user instanceof WP_User ? $user : null;
		}
	}
}
