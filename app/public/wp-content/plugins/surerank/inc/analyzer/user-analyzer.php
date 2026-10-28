<?php
/**
 * User Analyzer class.
 *
 * Performs SEO checks for WordPress users (author archives) with consistent output for UI.
 *
 * @package SureRank\Inc\Analyzer
 * @since 1.9.0
 */

namespace SureRank\Inc\Analyzer;

use SureRank\Inc\API\Admin;
use SureRank\Inc\API\User_Seo;
use SureRank\Inc\Functions\Helper;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Functions\Update;
use SureRank\Inc\Traits\Get_Instance;
use WP_User;

/**
 * User Analyzer class.
 *
 * @since 1.9.0
 */
class UserAnalyzer {
	use Get_Instance;

	/**
	 * User page title.
	 *
	 * @var string|null
	 */
	private $user_title = '';

	/**
	 * User page description.
	 *
	 * @var string|null
	 */
	private $user_description = '';

	/**
	 * Canonical URL.
	 *
	 * @var string|null
	 */
	private $canonical_url = '';

	/**
	 * User ID.
	 *
	 * @var int|null
	 */
	private $user_id;

	/**
	 * User author archive permalink.
	 *
	 * @var string
	 */
	private $user_permalink = '';

	/**
	 * User content (biographical info).
	 *
	 * @var string
	 */
	private $user_content = '';

	/**
	 * Constructor.
	 *
	 * @since 1.9.0
	 */
	private function __construct() {
		if ( ! Settings::get( 'enable_page_level_seo' ) ) {
			return;
		}
		add_action( 'profile_update', [ $this, 'save_user' ], 10, 1 );
		add_filter( 'surerank_run_user_seo_checks', [ $this, 'run_checks' ], 10, 2 );
	}

	/**
	 * Handle profile update.
	 *
	 * Only bumps the freshness timestamp instead of running the full check
	 * pipeline: profile_update fires for EVERY user save (including bulk
	 * customer/membership updates), and the stale timestamp already forces a
	 * lazy recompute on the next /checks/user fetch.
	 *
	 * @param int $user_id User ID.
	 * @since 1.9.0
	 * @return void
	 */
	public function save_user( $user_id ) {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		Update::user_meta( $user_id, SURERANK_USER_UPDATED_AT, time() );
	}

	/**
	 * Run SEO checks for the user.
	 *
	 * @param int     $user_id User ID.
	 * @param WP_User $user    User object.
	 * @since 1.9.0
	 * @return array<string, mixed>
	 */
	public function run_checks( $user_id, $user ) {
		$this->user_id = $user_id;

		if ( ! $this->user_id || ! $user instanceof WP_User ) {
			return [
				'status'  => 'error',
				'message' => __( 'Invalid user ID or user object.', 'surerank' ),
			];
		}

		$meta_data = User_Seo::get_user_data_by_id( $user_id );
		$variables = Admin::get_instance()->get_variables( null, null, $user_id );
		$meta_data = Utils::get_meta_data( $meta_data );

		foreach ( $meta_data as $key => $value ) {
			$meta_data[ $key ] = Helper::replacement( $key, $value, $variables );
		}

		$this->user_title       = $meta_data['page_title'] ?? ''; // we are keeping meta_data['page_title'] as we are using this globally.
		$this->user_description = $meta_data['page_description'] ?? ''; // same for meta_data['page_description'] as above.
		$this->canonical_url    = $meta_data['canonical_url'] ?? '';
		$this->user_permalink   = get_author_posts_url( $user_id );
		$this->user_content     = (string) get_the_author_meta( 'description', $user_id );

		$result = $this->analyze( $meta_data );

		$success = Update::user_seo_checks( $user_id, $result );

		if ( ! $success ) {
			return [
				'status'  => 'error',
				'message' => __( 'Failed to update SEO checks', 'surerank' ),
			];
		}

		return $result;
	}

	/**
	 * Analyze the user (author archive).
	 *
	 * @param array<string, mixed> $meta_data Meta data.
	 * @since 1.9.0
	 * @return array<string, mixed>
	 */
	private function analyze( array $meta_data ) {
		// Get focus keyword for keyword checks.
		$focus_keyword = $meta_data['focus_keyword'] ?? '';

		return [
			'url_length'                => Utils::check_url_length( $this->user_permalink ),
			'search_engine_title'       => Utils::analyze_title( $this->user_title ),
			'search_engine_description' => Utils::analyze_description( $this->user_description ),
			'canonical_url'             => $this->canonical_url(),
			'open_graph_tags'           => Utils::open_graph_tags(),
			// Keyword checks.
			'keyword_in_title'          => Utils::analyze_keyword_in_title( $this->user_title, $focus_keyword ),
			'keyword_in_description'    => Utils::analyze_keyword_in_description( $this->user_description, $focus_keyword ),
			'keyword_in_url'            => Utils::analyze_keyword_in_url( $this->user_permalink, $focus_keyword ),
			'keyword_in_content'        => Utils::analyze_keyword_in_content( $this->user_content, $focus_keyword ),
		];
	}

	/**
	 * Get canonical URL.
	 *
	 * @since 1.9.0
	 * @return array<string, mixed>
	 */
	private function canonical_url() {
		if ( $this->canonical_url === null ) {
			return [
				'status'  => 'error',
				'message' => __( 'No canonical URL provided.', 'surerank' ),
			];
		}

		$permalink = get_author_posts_url( (int) $this->user_id );
		if ( ! $permalink ) {
			return [
				'status'  => 'error',
				'message' => __( 'No permalink provided.', 'surerank' ),
			];
		}

		return Utils::analyze_canonical_url( $this->canonical_url, $permalink );
	}
}
