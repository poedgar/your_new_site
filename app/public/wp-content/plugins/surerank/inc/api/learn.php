<?php
/**
 * Learn class
 *
 * REST endpoints for the Learn (guided checklist) section.
 *
 * Step IDs here are the source of truth for validation. Keep this map in
 * sync with src/apps/admin-learn/learn-config.js whenever steps change.
 *
 * @package SureRank\Inc\API
 */

namespace SureRank\Inc\API;

use SureRank\Inc\Functions\Send_Json;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Traits\Get_Instance;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Learn
 *
 * Handles Learn section progress REST endpoints.
 *
 * @since 1.7.4
 */
class Learn extends Api_Base {
	use Get_Instance;

	/**
	 * Option key storing the site-wide progress.
	 */
	public const OPTION_KEY = 'surerank_learn_progress';

	/**
	 * Schema version for the persisted structure.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Route - Learn progress.
	 */
	protected const LEARN_PROGRESS = '/learn-progress';

	/**
	 * Register API routes.
	 *
	 * @since 1.7.4
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->get_api_namespace(),
			self::LEARN_PROGRESS,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_progress' ],
					'permission_callback' => [ $this, 'validate_permission' ],
					'role_capability'     => 'global_setting',
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'update_progress' ],
					'permission_callback' => [ $this, 'validate_permission' ],
					'role_capability'     => 'global_setting',
					'args'                => [
						'chapter_id' => [
							'type'              => 'string',
							'required'          => true,
							'description'       => __( 'Chapter identifier.', 'surerank' ),
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => static function ( $value ) {
								return is_string( $value ) && array_key_exists( $value, self::get_allowed_steps() );
							},
						],
						'step_id'    => [
							'type'              => 'string',
							'required'          => true,
							'description'       => __( 'Step identifier within the chapter.', 'surerank' ),
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => static function ( $value, $request ) {
								if ( ! is_string( $value ) ) {
									return false;
								}
								$chapter_id = $request->get_param( 'chapter_id' );
								$allowed    = self::get_allowed_steps();
								return isset( $allowed[ $chapter_id ] ) && in_array( $value, $allowed[ $chapter_id ], true );
							},
						],
						'completed'  => [
							'type'              => 'boolean',
							'required'          => true,
							'description'       => __( 'Whether the step is completed.', 'surerank' ),
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
					],
				],
			]
		);
	}

	/**
	 * Allowed chapter -> step IDs map.
	 *
	 * Mirror of LEARN_CHAPTERS in src/apps/admin-learn/learn-config.js. Both
	 * lists must match. PHP is the validator; JS is the renderer.
	 *
	 * The `pro` chapter steps are only marked complete client-side once Pro is
	 * active and licensed (free users see them locked and cannot toggle), but
	 * the IDs are allowed here so the unlocked checkboxes can persist progress.
	 *
	 * @since 1.7.4
	 * @return array<string, array<int, string>>
	 */
	public static function get_allowed_steps() {
		return [
			'getting_started' => [
				'connect_gsc',
				'title_templates',
				'homepage_seo',
				'migrate',
			],
			'find_you'        => [
				'xml_sitemap',
				'robots_instructions',
				'canonicals',
				'site_seo_issues',
			],
			'optimize'        => [
				'page_meta',
				'page_seo_check',
				'schema',
				'image_alt',
			],
			'social'          => [
				'og_fallback',
				'facebook_og',
				'x_cards',
				'override_per_page',
			],
			'pro'             => [
				'pro_redirection',
				'pro_broken_links',
				'pro_link_suggestions',
				'pro_advanced_schema',
				'pro_custom_schema',
				'pro_meta_generation',
				'pro_bulk_alt_text',
				'pro_instant_indexing',
				'pro_advanced_sitemaps',
			],
		];
	}

	/**
	 * Read the persisted progress structure with normalisation.
	 *
	 * @since 1.7.4
	 * @return array<string, mixed>
	 */
	public static function get_user_progress() {
		$stored = get_option( self::OPTION_KEY, [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return [
			'version'  => isset( $stored['version'] ) ? (int) $stored['version'] : self::SCHEMA_VERSION,
			'chapters' => isset( $stored['chapters'] ) && is_array( $stored['chapters'] ) ? $stored['chapters'] : [],
		];
	}

	/**
	 * Compute auto-detected step completions from existing settings.
	 *
	 * Steps where the underlying signal already says "done" are surfaced
	 * here so the UI can lock them as complete without persisting fake
	 * meta entries.
	 *
	 * @since 1.7.4
	 * @return array<string, array<string, bool>>
	 */
	public static function compute_auto_detected() {
		$auto_detected = [];

		// GSC connection.
		if ( class_exists( '\SureRank\Inc\GoogleSearchConsole\Controller' ) ) {
			$gsc_class = '\SureRank\Inc\GoogleSearchConsole\Controller';
			if ( method_exists( $gsc_class, 'get_instance' ) ) {
				$gsc_status = $gsc_class::get_instance()->get_auth_status();
				if ( $gsc_status ) {
					$auto_detected['getting_started']['connect_gsc'] = true;
				}
			}
		}

		// Migration.
		if ( class_exists( '\SureRank\Inc\API\Migrations' ) && Migrations::has_migration_ever_completed() ) {
			$auto_detected['getting_started']['migrate'] = true;
		}

		// Sitemap enabled.
		if ( Settings::get( 'enable_xml_sitemap' ) ) {
			$auto_detected['find_you']['xml_sitemap'] = true;
		}

		// Site SEO analysis ever run.
		$site_seo_checks = get_option( 'surerank_site_seo_checks', [] );
		if ( ! empty( $site_seo_checks ) ) {
			$auto_detected['find_you']['site_seo_issues'] = true;
		}

		// Schema enabled.
		if ( Settings::get( 'enable_schemas' ) ) {
			$auto_detected['optimize']['schema'] = true;
		}

		// OG fallback image set.
		$fallback_image = Settings::get( 'fallback_image' );
		if ( ! empty( $fallback_image ) ) {
			$auto_detected['social']['og_fallback'] = true;
		}

		// X username configured.
		$twitter_username = Settings::get( 'twitter_profile_username' );
		if ( ! empty( $twitter_username ) ) {
			$auto_detected['social']['x_cards'] = true;
		}

		/**
		 * Filter auto-detected Learn step completions.
		 *
		 * Lets the Pro plugin contribute completions for its own steps (keyed
		 * `$auto_detected['pro'][ $step_id ]`) from Pro-only settings, without
		 * the free plugin referencing Pro setting keys. Mirrors the
		 * `surerank-pro.learn-chapter` JS filter that extends the Pro chapter.
		 *
		 * @since 1.9.2
		 * @param array<string, array<string, bool>> $auto_detected Auto-detected map keyed by chapter then step.
		 */
		return apply_filters( 'surerank_learn_auto_detected', $auto_detected );
	}

	/**
	 * GET /learn-progress.
	 *
	 * @since 1.7.4
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return void
	 */
	public function get_progress( $request ) {
		unset( $request );
		Send_Json::success(
			[
				'progress'      => self::get_user_progress(),
				'auto_detected' => self::compute_auto_detected(),
			]
		);
	}

	/**
	 * POST /learn-progress.
	 *
	 * @since 1.7.4
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return void
	 */
	public function update_progress( $request ) {
		$chapter_id = (string) $request->get_param( 'chapter_id' );
		$step_id    = (string) $request->get_param( 'step_id' );
		$completed  = (bool) $request->get_param( 'completed' );

		$data = self::get_user_progress();

		if ( ! isset( $data['chapters'] ) || ! is_array( $data['chapters'] ) ) {
			$data['chapters'] = [];
		}

		if ( ! isset( $data['chapters'][ $chapter_id ] ) || ! is_array( $data['chapters'][ $chapter_id ] ) ) {
			$data['chapters'][ $chapter_id ] = [];
		}

		if ( $completed ) {
			$data['chapters'][ $chapter_id ][ $step_id ] = [
				'completed_at' => time(),
				'completed_by' => get_current_user_id(),
			];
		} else {
			unset( $data['chapters'][ $chapter_id ][ $step_id ] );
			if ( empty( $data['chapters'][ $chapter_id ] ) ) {
				unset( $data['chapters'][ $chapter_id ] );
			}
		}

		$data['version'] = self::SCHEMA_VERSION;

		update_option( self::OPTION_KEY, $data, false );

		// Signal the analytics layer to resend the Learn snapshot on its next flush.
		set_transient( 'surerank_learn_progress_changed', 1, DAY_IN_SECONDS );

		Send_Json::success(
			[
				'progress'      => self::get_user_progress(),
				'auto_detected' => self::compute_auto_detected(),
			]
		);
	}
}
