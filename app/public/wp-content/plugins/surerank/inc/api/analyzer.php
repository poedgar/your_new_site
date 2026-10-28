<?php
/**
 * Analyzer API class.
 *
 * Handles SEO-related REST API endpoints for the SureRank plugin.
 *
 * @package SureRank\Inc\API
 */

namespace SureRank\Inc\API;

use DOMXPath;
use SureRank\Inc\Analyzer\Scraper;
use SureRank\Inc\Analyzer\SeoAnalyzer;
use SureRank\Inc\Analyzer\Utils;
use SureRank\Inc\Functions\Get;
use SureRank\Inc\Functions\Requests;
use SureRank\Inc\Functions\Settings;
use SureRank\Inc\Functions\Update;
use SureRank\Inc\GoogleSearchConsole\Controller;
use SureRank\Inc\Importers\ImporterUtils;
use SureRank\Inc\Modules\Nudges\Utils as Nudge_Utils;
use SureRank\Inc\Sitemap\Generation_Mode;
use SureRank\Inc\Traits\Get_Instance;
use SureRank\Inc\Traits\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Analyzer
 *
 * Handles SEO analysis REST API endpoints.
 */
class Analyzer extends Api_Base {

	use Get_Instance;
	use Logger;
	/**
	 * Route for general SEO checks.
	 *
	 * @var string
	 */
	private $general_checks = '/checks/general';

	/**
	 * Route for settings checks.
	 *
	 * @var string
	 */
	private $settings_checks = '/checks/settings-checks';

	/**
	 * Legacy settings checks route, kept as a working alias. See #2878.
	 *
	 * @since 1.10.2
	 * @var string
	 */
	private $settings_checks_legacy = '/checks/settings';

	/**
	 * Route for other SEO checks.
	 *
	 * @var string
	 */
	private $other_checks = '/checks/other';

	/**
	 * Route for broken links check.
	 *
	 * @var string
	 */
	private $broken_links_check = '/checks/broken-link';

	/**
	 * Page Seo Status
	 *
	 * @var string
	 */
	private $page_seo_checks = '/checks/page';

	/**
	 * Taxonomy Seo Status
	 *
	 * @var string
	 */
	private $taxonomy_seo_checks = '/checks/taxonomy';

	/**
	 * User Seo Status
	 *
	 * @var string
	 * @since 1.9.0
	 */
	private $user_seo_checks = '/checks/user';

	/**
	 * Route for sitemap check.
	 *
	 * @var string
	 */
	private $ignore_checks = '/checks/ignore-site-check';

	/**
	 * Route for post-specific ignore checks.
	 *
	 * @var string
	 */
	private $ignore_post_checks = '/checks/ignore-page-check';

	/**
	 * Route for broken link ignore/restore.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	private $broken_link_ignore = '/checks/broken-link-ignore';

	/**
	 * Register API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$namespace = $this->get_api_namespace();
		$this->register_all_analyzer_routes( $namespace );
	}

	/**
	 * Get page SEO checks.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_page_seo_checks( $request ) {
		$post_ids = $request->get_param( 'post_ids' );

		if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
			return $this->create_error_response( __( 'Invalid Post ID.', 'surerank' ) );
		}

		$data = [];
		foreach ( $post_ids as $p_id ) {
			// Object-level guard (parity with get_user_seo_checks and the SEO save endpoints): the
			// route-level permission gates the endpoint; skip posts the caller cannot edit so SEO
			// check data (title, meta, content analysis) for other objects is not disclosed.
			if ( ! Post::can_manage_post_seo( (int) $p_id ) ) {
				continue;
			}

			$checks = $this->get_post_checks_data( $p_id );
			if ( is_wp_error( $checks ) ) {
				continue;
			}
			$checks = $this->consolidate_keyword_checks( $checks );
			if ( isset( $checks['broken_links'] ) && ! isset( $checks['broken_links']['type'] ) ) {
				$checks['broken_links']['type'] = 'page';
			}
			$data[ $p_id ] = [
				'checks' => $checks,
			];
		}

		return rest_ensure_response(
			[
				'status'  => 'success',
				'message' => __( 'SEO checks retrieved.', 'surerank' ),
				'data'    => $data,
			]
		);
	}

	/**
	 * Get taxonomy seo checks.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_taxonomy_seo_checks( $request ) {
		$term_ids = $request->get_param( 'term_ids' );

		if ( empty( $term_ids ) || ! is_array( $term_ids ) ) {
			return $this->create_error_response( __( 'Invalid Term ID.', 'surerank' ) );
		}

		$data = [];
		foreach ( $term_ids as $p_id ) {
			// Object-level guard (parity with get_user_seo_checks and the SEO save endpoints): the
			// route-level permission gates the endpoint; skip terms the caller cannot edit so SEO
			// check data for other objects is not disclosed.
			if ( ! Term::can_manage_term_seo( (int) $p_id ) ) {
				continue;
			}

			$checks = $this->get_term_checks_data( $p_id );
			if ( is_wp_error( $checks ) ) {
				continue;
			}
			$checks = $this->consolidate_keyword_checks( $checks );
			if ( isset( $checks['broken_links'] ) && ! isset( $checks['broken_links']['type'] ) ) {
				$checks['broken_links']['type'] = 'page';
			}
			$data[ $p_id ] = [
				'checks' => $checks,
			];
		}

		return rest_ensure_response(
			[
				'status'  => 'success',
				'message' => __( 'SEO checks retrieved.', 'surerank' ),
				'data'    => $data,
			]
		);
	}

	/**
	 * Get user seo checks.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 1.9.0
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_user_seo_checks( $request ) {
		$user_ids = $request->get_param( 'user_ids' );

		if ( empty( $user_ids ) || ! is_array( $user_ids ) ) {
			return $this->create_error_response( __( 'Invalid User ID.', 'surerank' ) );
		}

		$data = [];
		foreach ( $user_ids as $u_id ) {
			// Object-level guard (parity with /user/seo-settings): the route-level
			// permission gates the endpoint, this prevents reading another
			// user's data (e.g. focus keyword) without the edit_user capability.
			if ( ! User_Seo::can_manage_user_seo( (int) $u_id ) ) {
				continue;
			}

			$checks = $this->get_user_checks_data( $u_id );
			if ( is_wp_error( $checks ) ) {
				continue;
			}
			$checks        = $this->consolidate_keyword_checks( $checks );
			$data[ $u_id ] = [
				'checks' => $checks,
			];
		}

		return rest_ensure_response(
			[
				'status'  => 'success',
				'message' => __( 'SEO checks retrieved.', 'surerank' ),
				'data'    => $data,
			]
		);
	}

	/**
	 * Get general SEO checks for a URL or homepage.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_general_checks( $request ) {
		$url   = $request->get_param( 'url' );
		$force = $request->get_param( 'force' );

		if ( $this->cache_exists( 'general' ) && ! $force ) {
			return rest_ensure_response(
				$this->get_cached_response( 'general' )
			);
		}

		return rest_ensure_response(
			$this->run_general_checks( $url )
		);
	}

	/**
	 * Ignore site-wide checks.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ignore_checks( $request ) {
		$id            = $request->get_param( 'id' );
		$ignore_checks = $this->get_ignore_checks();

		if ( ! in_array( $id, $ignore_checks, true ) ) {
			$ignore_checks[] = $id;
		}

		$seo_checks = Get::option( 'surerank_site_seo_checks', [] );
		foreach ( $seo_checks as $key => $check ) {
			if ( isset( $check[ $id ] ) ) {
				$check[ $id ]['ignore'] = true;
				$seo_checks[ $key ]     = $check;
			}
		}

		Update::option( 'surerank_site_seo_checks', $seo_checks );
		Update::option( 'surerank_ignored_site_checks_list', array_values( $ignore_checks ) );

		return rest_ensure_response(
			[
				'status'  => 'success',
				'message' => __( 'Checks ignored.', 'surerank' ),
			]
		);
	}

	/**
	 * Delete ignore checks.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_ignore_checks( $request ) {
		$id            = $request->get_param( 'id' );
		$ignore_checks = $this->get_ignore_checks();

		if ( in_array( $id, $ignore_checks, true ) ) {
			$ignore_checks = array_diff( $ignore_checks, [ $id ] );
		}

		$seo_checks = Get::option( 'surerank_site_seo_checks', [] );
		foreach ( $seo_checks as $key => $check ) {
			if ( isset( $check[ $id ] ) ) {
				if ( isset( $check[ $id ]['ignore'] ) ) {
					unset( $check[ $id ]['ignore'] );
					$seo_checks[ $key ] = $check;
				}
			}
		}

		Update::option( 'surerank_site_seo_checks', $seo_checks );
		Update::option( 'surerank_ignored_site_checks_list', array_values( $ignore_checks ) );

		return rest_ensure_response(
			[
				'success' => true,
				'checks'  => $ignore_checks,
				'status'  => 'success',
				'message' => __( 'Checks unignored.', 'surerank' ),
			]
		);
	}

	/**
	 * Get ignored checks list.
	 *
	 * @param array<string, mixed> $post_checks List of post checks.
	 * @param int                  $post_id Post or term ID.
	 * @param string               $check_type Type of check, either 'post' or 'taxonomy'.
	 * @return array<string, mixed>
	 */
	public function get_updated_ignored_check_list( $post_checks, $post_id, $check_type = 'post' ) {
		$ignored_checks = $this->get_ignored_post_taxo_check( $post_id, $check_type );

		if ( ! empty( $ignored_checks ) && is_array( $ignored_checks ) ) {
			foreach ( $post_checks as $key => $check ) {
				if ( in_array( $key, $ignored_checks, true ) ) {
					$post_checks[ $key ]['ignore'] = true;
				}
			}
		}

		return $post_checks;
	}

	/**
	 * Get ignored checks.
	 *
	 * @param int    $post_id Post or term ID.
	 * @param string $check_type Type of check, either 'post' or 'taxonomy'.
	 * @return array<string, mixed>
	 */
	public function get_ignored_post_taxo_check( $post_id, $check_type = 'post' ) {
		$ignored_checks = null;
		if ( $check_type === 'taxonomy' ) {
			$ignored_checks = $this->get_ignore_taxonomy_checks( $post_id );
		} elseif ( $check_type === 'user' ) {
			$ignored_checks = $this->get_ignore_user_checks( $post_id );
		} else {
			$ignored_checks = $this->get_ignore_post_checks( $post_id );
		}
		if ( empty( $ignored_checks ) || ! is_array( $ignored_checks ) ) {
			$ignored_checks = [];
		}
		return $ignored_checks;
	}

	/**
	 * Update ignored post or taxonomy checks.
	 *
	 * @param int           $post_id Post or term ID.
	 * @param string        $check_type Type of check, either 'post' or 'taxonomy'.
	 * @param array<string> $checks List of checks to ignore.
	 * @return void
	 */
	public function update_ignored_post_taxo_check( $post_id, $check_type = 'post', $checks = [] ) {
		if ( $check_type === 'taxonomy' ) {
			Update::term_meta( $post_id, 'surerank_ignored_post_checks', array_values( $checks ) );
		} elseif ( $check_type === 'user' ) {
			Update::user_meta( $post_id, 'surerank_ignored_post_checks', array_values( $checks ) );
		} else {
			Update::post_meta( $post_id, 'surerank_ignored_post_checks', array_values( $checks ) );
		}
	}

	/**
	 * Ignore post-specific checks.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ignore_post_taxo_check( $request ) {
		$id         = $request->get_param( 'id' );
		$post_id    = $request->get_param( 'post_id' );
		$check_type = $request->get_param( 'check_type' );

		$guard = $this->guard_object_check_access( $post_id, $check_type );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$ignore_checks = $this->get_ignored_post_taxo_check( $post_id, $check_type );

		if ( ! in_array( $id, $ignore_checks, true ) ) {
			$ignore_checks[] = $id;
			$this->update_ignored_post_taxo_check( $post_id, $check_type, $ignore_checks );
		}

		return rest_ensure_response(
			[
				'success' => true,
				'status'  => 'success',
				'message' => __( 'Check ignored for post.', 'surerank' ),
				'checks'  => $ignore_checks,
			]
		);
	}

	/**
	 * Delete post-specific ignore checks.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_ignore_post_taxo_check( $request ) {
		$id         = $request->get_param( 'id' );
		$post_id    = $request->get_param( 'post_id' );
		$check_type = $request->get_param( 'check_type' );

		$guard = $this->guard_object_check_access( $post_id, $check_type );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$ignore_checks = $this->get_ignored_post_taxo_check( $post_id, $check_type );

		if ( in_array( $id, $ignore_checks, true ) ) {
			$ignore_checks = array_values( array_diff( $ignore_checks, [ $id ] ) );
			$this->update_ignored_post_taxo_check( $post_id, $check_type, $ignore_checks );
		}

		return rest_ensure_response(
			[
				'success' => true,
				'status'  => 'success',
				'message' => __( 'Check unignored for post.', 'surerank' ),
				'checks'  => $ignore_checks,
			]
		);
	}

	/**
	 * Get ignored checks.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_ignore_post_taxo_check( $request ) {

		$post_id    = (int) $request->get_param( 'post_id' );
		$check_type = $request->get_param( 'check_type' );

		$guard = $this->guard_object_check_access( $post_id, $check_type );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$ignore_checks = $this->get_ignored_post_taxo_check( $post_id, $check_type );

		return rest_ensure_response(
			[
				'success' => true,
				'status'  => 'success',
				'message' => __( 'Ignored checks retrieved.', 'surerank' ),
				'checks'  => $ignore_checks,
			]
		);
	}

	/**
	 * Get settings checks.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_settings_checks( $request ) {
		$force = $request->get_param( 'force' );

		if ( $this->cache_exists( 'settings' ) && ! $force ) {
			return rest_ensure_response(
				$this->get_cached_response( 'settings' )
			);
		}

		return rest_ensure_response(
			$this->run_settings_checks()
		);
	}

	/**
	 * Get other SEO checks for a URL or homepage.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_other_checks( $request ) {
		$force = $request->get_param( 'force' );

		if ( $this->cache_exists( 'other' ) && ! $force ) {
			return rest_ensure_response(
				$this->get_cached_response( 'other' )
			);
		}

		return rest_ensure_response(
			$this->run_other_checks()
		);
	}

	/**
	 * Get authentication status.
	 *
	 * @return array<string, mixed>
	 */
	public function get_auth_status() {
		$auth_status       = Controller::get_instance()->get_auth_status() && Settings::get( 'enable_google_console' );
		$working_label     = __( 'Google Search Console is currently connected to your site.', 'surerank' );
		$not_working_label = __( 'Google Search Console is not currently connected to your site.', 'surerank' );

		$helptext = [
			__( 'Search Console helps you understand how your site appears in Google search results. It shows which pages are indexed, how your site is performing, and whether Google is reporting any issues.', 'surerank' ),
			__( 'Without it connected, you miss important visibility into how Google sees your site.', 'surerank' ),

			sprintf(
				'<h6>🛠️ %s </h6>',
				__( 'Where to Update It', 'surerank' )
			),
			__( 'You can connect Google Search Console directly from the SureRank Dashboard.', 'surerank' ),
			[
				'list' => [
					sprintf(
						// translators: %s is the Search Console URL.
						__( 'Go to SureRank ⇾ <a href="%s">Search Console</a>', 'surerank' ),
						$this->get_search_console_url()
					),
					__( 'Sign in with your Google account', 'surerank' ),
					__( 'Select your site and complete the connection', 'surerank' ),
				],
			],

			sprintf(
				'<h6>✏️ %s </h6>',
				__( 'Update Here', 'surerank' )
			),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/02/google-search-console-is-not-connected.webp' ),
				esc_attr( 'Search Console Connection' )
			),

			__( 'Once connected, SureRank will start using Search Console data to show search performance and indexing insights.', 'surerank' ),
		];

		if ( ! Nudge_Utils::get_instance()->is_pro_active() ) {
			$helptext[] = sprintf(
				'<h6>💬 %s </h6>',
				__( 'Need More?', 'surerank' )
			);
			$helptext[] = __( 'SureRank Pro unlocks advanced insights and recommendations powered by Google Search Console data.', 'surerank' );
		}

		$heading = $auth_status ? __( 'Google Search Console is connected.', 'surerank' ) : __( 'Google Search Console is not connected.', 'surerank' );

		return [
			'exists'       => true,
			'not_locked'   => true,
			'button_label' => __( 'Connect Now', 'surerank' ),
			'button_url'   => $this->get_search_console_url(),
			'status'       => $auth_status ? 'success' : 'suggestion',
			'description'  => $helptext,
			'message'      => $auth_status ? $working_label : $not_working_label,
			'heading'      => $heading,
		];
	}

	/**
	 * Get list of installed SEO plugins with detection info.
	 *
	 * @return array{active_plugins: array<int, string>, detected_plugins: array<int, array<string, string>>}
	 * @since 1.4.0
	 */
	public function get_installed_seo_plugins_data(): array {
		$seo_plugins = ImporterUtils::get_seo_plugins_list();

		$active_plugins   = apply_filters( 'active_plugins', get_option( 'active_plugins', [] ) );
		$detected_plugins = [];

		foreach ( $seo_plugins as $file => $data ) {
			if ( in_array( $file, $active_plugins, true ) ) {
				$detected_plugins[] = [
					'name'     => $data['name'],
					'slug'     => $file,
					'pro_slug' => $data['pro_slug'],
				];
			}
		}

		return [
			'active_plugins'   => $active_plugins,
			'detected_plugins' => $detected_plugins,
		];
	}

	/**
	 * Analyze installed SEO plugins.
	 *
	 * @return array<string, mixed>
	 */
	public function get_installed_seo_plugins(): array {

		$plugin_data      = $this->get_installed_seo_plugins_data();
		$detected_plugins = array_map(
			static function( $plugin ) {
				return [ 'name' => $plugin['name'] ];
			},
			$plugin_data['detected_plugins']
		);

		$active_count = count( $detected_plugins );
		$heading      = $active_count > 0 ? __( 'Other SEO Plugin Detected', 'surerank' ) : __( 'No other SEO plugin detected', 'surerank' );
		$title        = __( 'No other SEO plugin detected on your site.', 'surerank' );

		if ( $active_count > 0 ) {
			if ( $active_count > 1 ) {
				$title = __( 'More than one SEO plugin is currently active on your site.', 'surerank' );
			} else {
				/* translators: %s is the list of active plugins */
				$title = sprintf( __( 'Another SEO plugin, %s, is currently active on your site.', 'surerank' ), implode( ', ', array_column( $detected_plugins, 'name' ) ) );
			}
		}

		$description = [
			__( 'SEO plugins manage things like page titles, descriptions, schema, and indexing settings. These signals help search engines understand how your site appears in search results.', 'surerank' ),
			__( 'When multiple SEO plugins are active, they can create duplicate or conflicting signals. This makes it harder for search engines to understand which information to trust.', 'surerank' ),
			__( 'Using a single SEO plugin helps keep everything consistent and easier to manage.', 'surerank' ),

			sprintf(
				'<h6>🛠️ %s </h6>',
				__( 'Where to Update It', 'surerank' )
			),
			__( 'You can manage this from your WordPress plugins list.', 'surerank' ),
			[
				'list' => [
					sprintf(
						// translators: %s is the Plugins menu URL.
						__( 'Go to <a href="%s">Plugins ⇾ Installed Plugins</a>', 'surerank' ),
						admin_url( 'plugins.php' )
					),
					__( 'Identify any other active SEO plugins', 'surerank' ),
					__( 'Deactivate the ones you are not using', 'surerank' ),
				],
			],

			sprintf(
				'<h6>✏️ %s </h6>',
				__( 'Deactivate Here', 'surerank' )
			),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/02/other-seo-plugin-detected.webp' ),
				esc_attr( 'Other SEO Plugin Detected' )
			),
		];

		if ( ! Nudge_Utils::get_instance()->is_pro_active() ) {
			$description[] = sprintf(
				'<h6>💬 %s </h6>',
				__( 'Need Help?', 'surerank' )
			);
			$description[] = __( 'SureRank Pro users get access to our support team, available 24×7, to help review plugin conflicts and guide you through cleanup.', 'surerank' );
		}

		return [
			'exists'      => true,
			'status'      => $active_count > 0 ? 'error' : 'success',
			'description' => $description,
			'message'     => $title,
			'heading'     => $heading,
		];
	}

	/**
	 * Analyze site tagline.
	 *
	 * @return array<string, mixed>
	 */
	public function get_site_tag_line(): array {
		$tagline = get_bloginfo( 'description' );
		$is_set  = ! empty( $tagline );

		$heading = __( 'Site Tagline', 'surerank' );
		$title   = $is_set ? __( 'Your site currently has a tagline set.', 'surerank' ) : __( 'Your site does not currently have a tagline set.', 'surerank' );

		$description = [
			__( 'A site tagline is a short line that describes what your website is about.', 'surerank' ),
			__( 'It often appears alongside your site title and helps visitors quickly understand your purpose.', 'surerank' ),
			__( 'A clear tagline sets context, supports your brand, and makes your site feel more intentional.', 'surerank' ),

			sprintf(
				'<h6>✅ %s </h6>',
				__( 'Best Practice', 'surerank' )
			),
			[
				'list' => [
					__( 'A good tagline is simple and easy to understand.', 'surerank' ),
					__( 'It should describe what you do or who your site is for.', 'surerank' ),
					__( 'Aim for a single clear sentence that feels natural and human.', 'surerank' ),
				],
			],
			__( 'Avoid buzzwords or vague phrases that do not say much about your site.', 'surerank' ),

			sprintf(
				'<h6>🛠️ %s </h6>',
				__( 'Where to Update It', 'surerank' )
			),
			__( 'You can update your site tagline from the WordPress settings.', 'surerank' ),
			[
				'list' => [
					__( 'Go to Settings ⇾ General', 'surerank' ),
					__( 'Update the Tagline field', 'surerank' ),
					__( 'Save your changes', 'surerank' ),
				],
			],

			sprintf(
				'<h6>✏️ %s </h6>',
				__( 'Update Here', 'surerank' )
			),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/02/site-tagline.webp' ),
				esc_attr( 'Site Tagline Settings' )
			),
		];

		if ( ! Nudge_Utils::get_instance()->is_pro_active() ) {
			$description[] = sprintf(
				'<h6>💬 %s </h6>',
				__( 'Need Help?', 'surerank' )
			);
			$description[] = __( 'SureRank Pro helps fix SEO issues across your website using AI, without manual effort.', 'surerank' );
		}

		return [
			'exists'      => true,
			'status'      => $is_set ? 'success' : 'warning',
			'description' => $description,
			'message'     => $title,
			'heading'     => $heading,
		];
	}

	/**
	 * Analyze robots.txt.
	 *
	 * @return array<string, mixed>
	 */
	public function robots_txt() {
		$response = Scraper::get_instance()->call_request( home_url( '/robots.txt' ) );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		// Not available: missing, inaccessible, or a non-robots HTML/404 body.
		if ( is_wp_error( $response ) || 200 !== $code ) {
			return $this->robots_txt_unreachable_result();
		}

		$body         = (string) wp_remote_retrieve_body( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$content_type = is_string( $content_type ) ? $content_type : '';

		if ( $this->is_non_robots_content_type( $content_type ) || $this->looks_like_markup_body( $body ) ) {
			return $this->robots_txt_unreachable_result();
		}

		// Analyze the served content for SEO impact (not just syntax).
		$parsed   = $this->parse_robots_txt( $body );
		$findings = $this->evaluate_robots_findings( $parsed );

		if ( empty( $findings ) ) {
			return $this->robots_txt_success_result();
		}

		return $this->build_robots_txt_result( $findings );
	}

	/**
	 * Analyze site indexed.
	 *
	 * @return array<string, mixed>
	 */
	public function index_status() {
		$index_status = get_option( 'blog_public' );
		$no_index     = $this->settings['no_index'] ?? [];

		$working_heading = __( 'Search engine visibility is enabled.', 'surerank' );
		$working_label   = __( 'Search engine visibility is currently enabled in your WordPress settings.', 'surerank' );

		$not_working_heading = __( 'Search engine visibility is disabled.', 'surerank' );
		$not_working_label   = __( 'Search engine visibility is currently disabled in your WordPress settings.', 'surerank' );

		$helptext = [
			__( 'WordPress includes a setting that tells search engines whether they are allowed to index your site. Indexing means your pages can appear in search results.', 'surerank' ),
			__( 'This setting is commonly used while a site is being built or kept private. When enabled, it asks search engines not to index new pages.', 'surerank' ),
			__( 'If this setting remains enabled by mistake, your site may not appear in search results even if everything else is set up correctly. It can quietly limit visibility without showing obvious errors.', 'surerank' ),

			sprintf(
				'<h6>🛠️ %s </h6>',
				__( 'Where to Update It', 'surerank' )
			),
			__( 'You can change this setting from your WordPress dashboard.', 'surerank' ),

			[
				'list' => [
					sprintf(
						/* translators: %s is the URL of the WordPress Reading settings page */
						__( 'Go to <a href="%s">Settings ⇾ Reading</a>', 'surerank' ),
						$this->get_wordpress_settings_url( 'reading' )
					),
					__( 'Find the option labeled “Search engine visibility”', 'surerank' ),
					__( 'Make sure the checkbox is not selected', 'surerank' ),
					__( 'Save your changes', 'surerank' ),
				],
			],

			sprintf(
				/* translators: %s is the URL of the WordPress Reading settings page */
				'<h6>✏️ %s </h6>',
				__( 'Update Here', 'surerank' )
			),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/02/search-engine-visibility-is-disabled.webp' ),
				esc_attr( 'Search engine visibility setting' )
			),
		];

		if ( ! Nudge_Utils::get_instance()->is_pro_active() ) {
			$helptext[] = sprintf(
				'<h6>💬 %s </h6>',
				__( 'Need Help?', 'surerank' )
			);
			$helptext[] = __( 'SureRank Pro users get access to our support team, available 24×7, to help detect visibility and indexing issues before they affect your site.', 'surerank' );
		}

		$sensitive_post_types = [ 'post', 'page', 'product', 'product_variation', 'product_category', 'product_tag' ];
		$noindex_types        = array_intersect( $no_index, $sensitive_post_types );

		if ( ! empty( $noindex_types ) ) {
			return [
				'exists'      => false,
				'status'      => 'error',
				'description' => $helptext,
				'message'     => $not_working_label,
				'heading'     => $not_working_heading,
			];
		}

		if ( ! $index_status ) {
			return [
				'exists'      => false,
				'status'      => 'error',
				'description' => $helptext,
				'message'     => $not_working_label,
				'heading'     => $not_working_heading,
			];
		}

		return [
			'exists'      => true,
			'status'      => 'success',
			'description' => $helptext,
			'message'     => $working_label,
			'heading'     => $working_heading,
		];
	}

	/**
	 * Analyze sitemaps.
	 *
	 * @return array<string, mixed>
	 */
	public function sitemaps(): array {
		$working_heading = __( 'XML sitemap is accessible.', 'surerank' );
		$working_label   = __( 'The XML sitemap for this site is accessible to search engines.', 'surerank' );

		$not_working_heading = __( 'XML sitemap is missing or inaccessible.', 'surerank' );
		$not_working_label   = __( 'The XML sitemap for this site is missing or cannot be accessed.', 'surerank' );

		$sitemap_steps = [
			__( 'Go to SureRank ⇾ General ⇾ Sitemaps', 'surerank' ),
			__( 'Enable the XML Sitemap toggle', 'surerank' ),
		];
		// The Regenerate button only exists in cron mode; in the default auto
		// mode the sitemap builds on the fly, so enabling the toggle is enough.
		if ( ! Generation_Mode::allows_inline_build() ) {
			$sitemap_steps[] = __( 'Click on Regenerate Button', 'surerank' );
		}
		$sitemap_steps[] = __( 'Save your changes', 'surerank' );

		$helptext = [
			__( 'An XML sitemap helps search engines discover and understand the pages on your site.', 'surerank' ),
			__( 'When the sitemap is missing or cannot be accessed, search engines may take longer to find new or updated pages.', 'surerank' ),
			__( 'Having a sitemap makes it easier for search engines to crawl your site efficiently.', 'surerank' ),
			__( 'It also helps ensure important pages are not missed during indexing.', 'surerank' ),

			sprintf(
				'<h6>🛠️ %s </h6>',
				__( 'Where to Update It', 'surerank' )
			),
			__( 'You can enable and manage your XML sitemap directly from SureRank.', 'surerank' ),
			[
				'list' => $sitemap_steps,
			],

			sprintf(
				'<h6>✏️ %s </h6>',
				__( 'Update Here', 'surerank' )
			),

			sprintf(
				"<img class='w-full h-full' src='%s' alt='%s' />",
				esc_attr( 'https://surerank.com/wp-content/uploads/2026/04/xml-sitemap-is-missing-or-inaccessible-visual.webp' ),
				esc_attr( 'XML Sitemap Settings' )
			),
		];

		if ( ! Nudge_Utils::get_instance()->is_pro_active() ) {
			$helptext[] = sprintf(
				'<h6>💬 %s </h6>',
				__( 'Need More?', 'surerank' )
			);
			$helptext[] = __( 'Upgrade to SureRank Pro to unlock advanced sitemap types like Video, News, HTML, and Author sitemaps for better search visibility.', 'surerank' );
		}

		$sitemap_url = home_url( '/sitemap_index.xml' );
		$sitemap     = Scraper::get_instance()->fetch( $sitemap_url );

		if ( is_wp_error( $sitemap ) || empty( $sitemap ) ) {
			return [
				'exists'      => false,
				'status'      => 'warning',
				'description' => $helptext,
				'message'     => $not_working_label,
				'heading'     => $not_working_heading,
			];
		}

		if ( ! $this->is_valid_xml( $sitemap ) ) {
			return [
				'exists'      => false,
				'status'      => 'warning',
				'description' => $helptext,
				'message'     => $not_working_label,
				'heading'     => $not_working_heading,
			];
		}

		return [
			'exists'      => true,
			'status'      => 'success',
			'description' => $helptext,
			'message'     => $working_label,
			'heading'     => $working_heading,
		];
	}

	/**
	 * Get surerank settings url.
	 *
	 * @param string $page Page slug.
	 * @param string $parent Parent slug.
	 * @return string
	 */
	public function get_surerank_settings_url( string $page = '', string $parent = '' ) {

		if ( ! empty( $parent ) ) {

			return admin_url( 'admin.php?page=surerank' . ( $page ? "#/{$parent}/{$page}" : '' ) );

		}
		return admin_url( 'admin.php?page=surerank' . ( $page ? "#/{$page}" : '' ) );
	}

	/**
	 * Get broken links check.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_broken_links_status( $request ) {
		$url     = $request->get_param( 'url' ) ?? '';
		$post_id = $request->get_param( 'post_id' ) ?? 0;
		$urls    = $request->get_param( 'urls' ) ?? [];

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->create_broken_link_error_response( __( 'Post not found', 'surerank' ) );
		}

		// Object-level guard: the status paths below persist per-post broken-link
		// state, so require edit access to the target post.
		if ( ! Post::can_manage_post_seo( (int) $post_id ) ) {
			return $this->create_broken_link_error_response( __( 'You are not allowed to manage SEO checks for this post.', 'surerank' ) );
		}

		// Normalize incoming URLs to absolute, then escape. Pre-1.9.3 scans
		// stored relative hrefs (e.g. "/page-slug", "contact") in post meta, and
		// clients replay them here; checking them raw always fails ("A valid URL
		// was not provided"), which re-persists the stale broken-link records on
		// every Analyze run. The route's sanitize callbacks deliberately preserve
		// the raw href shape (esc_url_raw() there would turn "contact" into
		// "http://contact" and destroy its relative semantics), so resolution
		// against the post base URL happens first and esc_url_raw() runs on the
		// resulting absolute URL. The base comes from Utils::get_post_base_url()
		// so a static front page resolves path-relative hrefs against the same
		// original page slug URL PostAnalyzer scans with.
		$base = Utils::get_post_base_url( (int) $post_id );
		if ( ! is_array( $urls ) ) {
			return $this->create_broken_link_error_response( __( 'Invalid links list.', 'surerank' ) );
		}

		$url  = self::normalize_broken_link_request_url( (string) $url, $base );
		$urls = array_values(
			array_filter(
				array_map(
					static function ( $u ) use ( $base ) {
						return self::normalize_broken_link_request_url( (string) $u, $base );
					},
					$urls
				)
			)
		);

		if ( $url === '' || ! in_array( $url, $urls, true ) ) {
			return $this->create_broken_link_error_response( __( 'Invalid link URL.', 'surerank' ) );
		}

		if ( $this->is_broken_link_ignored( $url ) ) {
			$this->remove_broken_links( $url, $post_id, $urls );
			return rest_ensure_response(
				[
					'success' => true,
					'ignored' => true,
					'message' => __( 'Link is ignored.', 'surerank' ),
				]
			);
		}

		// Confirm same-site links against the database before falling back to an
		// HTTP request. Hosts that cannot request themselves report their own
		// URLs as transport failures, which leaves every internal link stuck as
		// unverifiable; resolving locally is both accurate and instant there.
		if ( $this->internal_link_exists_locally( $url ) ) {
			$this->remove_broken_links( $url, $post_id, $urls );
			return rest_ensure_response(
				[
					'success' => true,
					'message' => __( 'Link is not broken', 'surerank' ),
				]
			);
		}

		$response = $this->fetch_url_status( $url, (string) $request->get_param( 'user_agent' ) );

		if ( is_wp_error( $response ) ) {
			return $this->handle_broken_link_error( $url, $post_id, $urls, $response );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code === 404 || $status_code === 410 ) {
			return $this->handle_broken_link_status_error( $url, $post_id, $urls, $status_code, $response );
		}

		if ( $status_code < 200 || $status_code >= 400 ) {
			return $this->create_broken_link_unverified_response(
				__( 'Could not verify the link.', 'surerank' ),
				$status_code,
				wp_remote_retrieve_response_message( $response )
			);
		}
		$this->remove_broken_links( $url, $post_id, $urls );
		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Link is not broken', 'surerank' ),
			]
		);
	}

	/**
	 * Remove broken links.
	 *
	 * @param string        $url URL.
	 * @param int           $post_id Post ID.
	 * @param array<string> $urls URLs.
	 * @return void
	 */
	public function remove_broken_links( $url, $post_id, $urls ) {
		$seo_checks = Get::post_meta( $post_id, SURERANK_SEO_CHECKS, true );
		// Legacy/empty meta can be a string (e.g. unanalysed posts return '' or
		// older versions stored a non-array). Normalise before the offset write
		// below, otherwise PHP 8 throws "Cannot access offset of type string on string".
		if ( ! is_array( $seo_checks ) ) {
			$seo_checks = [];
		}

		$broken_links = $seo_checks['broken_links'] ?? [];

		$base                  = Utils::get_post_base_url( (int) $post_id );
		$existing_broken_links = $this->reconcile_broken_links( $broken_links, $urls, $base );

		foreach ( $existing_broken_links as $key => $existing_link ) {
			if ( $this->stored_broken_link_matches_url( $existing_link, $url, $base ) ) {
				unset( $existing_broken_links[ $key ] );
			}
		}

		$seo_checks['broken_links'] = $this->build_broken_links_check( array_values( $existing_broken_links ) );
		Update::post_meta( $post_id, SURERANK_SEO_CHECKS, $seo_checks );
	}

	/**
	 * Ignore a broken link URL site-wide.
	 *
	 * Ignored URLs are skipped by the broken link checker on every post.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|\WP_Error
	 * @since 1.9.0
	 */
	public function ignore_broken_link( $request ) {
		$url     = (string) $request->get_param( 'url' );
		$post_id = (int) $request->get_param( 'post_id' );
		$urls    = (array) $request->get_param( 'urls' );

		// Object-level guard: remove_broken_links() below mutates the per-post SEO
		// checks cache, so require edit access to the target post before any write.
		if ( $post_id > 0 && ! Post::can_manage_post_seo( $post_id ) ) {
			return $this->create_broken_link_error_response( __( 'You are not allowed to manage SEO checks for this post.', 'surerank' ) );
		}

		$ignored_urls = $this->get_broken_link_ignored_urls();

		if ( ! $this->is_broken_link_ignored( $url ) ) {
			$ignored_urls[] = esc_url_raw( $url );
			Update::option( 'surerank_broken_link_ignored_urls', array_values( array_unique( $ignored_urls ) ) );
		}

		if ( $post_id > 0 ) {
			$this->remove_broken_links( $url, $post_id, $urls );
		}

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Link ignored.', 'surerank' ),
				'urls'    => $this->get_broken_link_ignored_urls(),
			]
		);
	}

	/**
	 * Restore (un-ignore) a broken link URL.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response|\WP_Error
	 * @since 1.9.0
	 */
	public function restore_broken_link( $request ) {
		$url    = (string) $request->get_param( 'url' );
		$needle = $this->normalize_broken_link_url( $url );

		$ignored_urls = array_values(
			array_filter(
				$this->get_broken_link_ignored_urls(),
				fn( $ignored_url ) => $this->normalize_broken_link_url( $ignored_url ) !== $needle
			)
		);

		Update::option( 'surerank_broken_link_ignored_urls', $ignored_urls );

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Link restored.', 'surerank' ),
				'urls'    => $ignored_urls,
			]
		);
	}

	/**
	 * Run checks.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function run_checks( $post_id ) {
		return Post::get_instance()->run_checks( $post_id );
	}

	/**
	 * Run taxonomy checks.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function run_taxonomy_checks( $term_id ) {
		return Term::get_instance()->run_checks( $term_id );
	}

	/**
	 * Run user checks.
	 *
	 * @param int $user_id User ID.
	 * @since 1.9.0
	 * @return array<string, mixed>|int|WP_Error
	 */
	public function run_user_checks( $user_id ) {
		return User_Seo::get_instance()->run_checks( $user_id );
	}

	/**
	 * Run general checks.
	 *
	 * @param string $url URL to run checks on.
	 * @return array<string, mixed>|WP_Error
	 */
	public function run_general_checks( string $url ) {
		$analyzer = SeoAnalyzer::get_instance( $url );
		$xpath    = $analyzer->get_xpath();

		if ( ! $xpath instanceof DOMXPath ) {
			return $this->create_analysis_error( $xpath );
		}

		$response = $this->execute_general_checks( $analyzer, $xpath );
		$this->update_site_seo_checks( $response, 'general' );

		return $response;
	}

	/**
	 * Run settings checks.
	 *
	 * @return array<string, mixed>
	 */
	public function run_settings_checks() {
		$ignore_checks = $this->get_ignore_checks();
		$response      = [
			'sitemaps'     => fn() => $this->sitemaps(),
			'index_status' => fn() => $this->index_status(),
			'robots_txt'   => fn() => $this->robots_txt(),
		];

		foreach ( $response as $key => $callback ) {
			$response[ $key ] = array_merge( (array) $callback(), [ 'ignore' => in_array( $key, $ignore_checks, true ) ] );
		}

		$this->update_site_seo_checks( $response, 'settings' );

		return $response;
	}

	/**
	 * Run other checks.
	 *
	 * @return array<string, mixed>
	 */
	public function run_other_checks() {
		$response = [
			'other_seo_plugins' => fn() => $this->get_installed_seo_plugins(),
			'site_tag_line'     => fn() => $this->get_site_tag_line(),
			'auth_status'       => fn() => $this->get_auth_status(),
		];

		foreach ( $response as $key => $callback ) {
			$response[ $key ] = array_merge( (array) $callback(), [ 'ignore' => in_array( $key, $this->get_ignore_checks(), true ) ] );
		}

		$this->update_site_seo_checks( $response, 'other' );

		return $response;
	}

	/**
	 * Sanitize ids.
	 *
	 * @param array<int|string>                     $params IDs.
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @param string                                $key Key.
	 * @return array<int>
	 */
	public static function sanitize_ids( $params, $request, $key ) {
		return array_map( 'intval', $params );
	}

	/**
	 * Sanitize an array of URLs.
	 *
	 * @param mixed                                 $params URLs as sent by the client; scalars are coerced to an array.
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @param string                                $key Key.
	 * @return array<int, string>
	 * @since 1.9.2
	 */
	public static function sanitize_urls( $params, $request, $key ) {
		// A scalar reaches this callback when a client sends a non-array value:
		// the schema's own array coercion is skipped once a custom
		// sanitize_callback is set, and array_map() on a string is fatal.
		if ( ! is_array( $params ) ) {
			$params = $params === null || $params === '' ? [] : [ $params ];
		}

		return array_map( 'esc_url_raw', $params );
	}

	/**
	 * Sanitize a single link href while preserving its relative shape.
	 *
	 * The broken-link status route defers esc_url_raw() to the handler:
	 * escaping first would prepend a scheme to path-relative hrefs
	 * ("contact" becomes "http://contact"), so the handler could no longer
	 * resolve them against the post base URL. The handler escapes the
	 * resolved absolute URL instead.
	 *
	 * @param mixed                                 $param Raw href value.
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @param string                                $key Key.
	 * @return string
	 * @since 1.10.2
	 */
	public static function sanitize_link_href( $param, $request, $key ) {
		return is_scalar( $param ) ? trim( wp_check_invalid_utf8( (string) $param ) ) : '';
	}

	/**
	 * Sanitize an array of link hrefs while preserving relative shapes.
	 *
	 * Companion to sanitize_link_href() for array params; scalars are
	 * coerced to a one-item array the same way core's rest_is_array() /
	 * rest_sanitize_array() treat scalar values for `type: array` params.
	 *
	 * @param mixed                                 $params Hrefs as sent by the client.
	 * @param WP_REST_Request<array<string, mixed>> $request Request object.
	 * @param string                                $key Key.
	 * @return array<int, string>
	 * @since 1.10.2
	 */
	public static function sanitize_link_hrefs( $params, $request, $key ) {
		if ( ! is_array( $params ) ) {
			return [];
		}

		$params = array_filter( $params, 'is_scalar' );

		return array_values(
			array_map(
				static function ( $param ) {
					return trim( wp_check_invalid_utf8( (string) $param ) );
				},
				$params
			)
		);
	}

	/**
	 * Keep stored broken links that still occur in the current page link set.
	 *
	 * @param array<string, mixed> $broken_links Broken links check data.
	 * @param array<string>        $urls Normalized current page URLs.
	 * @param string               $base Post URL used for legacy relative entries.
	 * @return array<int|string, mixed>
	 * @since 1.10.2
	 */
	private function reconcile_broken_links( $broken_links, array $urls, string $base ) {
		$reconciled = [];

		foreach ( Utils::get_broken_links_list( $broken_links ) as $key => $stored_link ) {
			$stored_url = is_array( $stored_link ) ? ( $stored_link['url'] ?? '' ) : $stored_link;
			if ( ! is_string( $stored_url ) ) {
				continue;
			}

			$normalized_url = self::normalize_broken_link_request_url( $stored_url, $base );
			if ( ! in_array( $normalized_url, $urls, true ) ) {
				continue;
			}

			$reconciled[ $key ] = is_array( $stored_link ) ? $stored_link : [
				'url'     => $stored_link,
				'status'  => 'error',
				'details' => __( 'The link is broken.', 'surerank' ),
				'type'    => 'page',
			];
		}

		return $reconciled;
	}

	/**
	 * Build the canonical stored broken-links check shape.
	 *
	 * @param array<int|string, mixed> $broken_links Broken-link records.
	 * @return array<string, mixed>
	 * @since 1.10.2
	 */
	private function build_broken_links_check( array $broken_links ) {
		if ( empty( $broken_links ) ) {
			return [
				'status'  => 'success',
				'type'    => 'page',
				'message' => __( 'No broken links found on the page.', 'surerank' ),
			];
		}

		return [
			'status'      => 'error',
			'type'        => 'page',
			'description' => [
				__( 'These broken links were found on the page:', 'surerank' ),
				[ 'list' => $broken_links ],
			],
			'message'     => __( 'One or more broken links found on the page.', 'surerank' ),
		];
	}

	/**
	 * Resolve and validate a client-supplied href for a broken-link check.
	 *
	 * @param string $href Raw href value.
	 * @param string $base Post URL used to resolve relative hrefs.
	 * @return string Absolute HTTP(S) URL, or an empty string when unusable.
	 * @since 1.10.2
	 */
	private static function normalize_broken_link_request_url( $href, $base ) {
		$href = trim( $href );
		if ( $href === '' || 0 === strpos( $href, '#' ) ) {
			return '';
		}

		$url   = esc_url_raw( Utils::normalize_link_url( $href, $base ) );
		$parts = wp_parse_url( $url );

		if (
			! is_array( $parts ) ||
			empty( $parts['host'] ) ||
			empty( $parts['scheme'] ) ||
			! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true )
		) {
			return '';
		}

		return $url;
	}

	/**
	 * Check whether a stored legacy or canonical entry represents a URL.
	 *
	 * @param mixed  $stored_link Stored list item.
	 * @param string $url Canonical URL being checked.
	 * @param string $base Post URL used to resolve legacy relative entries.
	 * @return bool
	 * @since 1.10.2
	 */
	private function stored_broken_link_matches_url( $stored_link, $url, $base ) {
		$stored_url = is_array( $stored_link ) ? ( $stored_link['url'] ?? '' ) : $stored_link;

		if ( ! is_string( $stored_url ) || $stored_url === '' ) {
			return false;
		}

		return self::normalize_broken_link_request_url( $stored_url, $base ) === $url;
	}

	/**
	 * Whether a link points at this site.
	 *
	 * Host and port must match exactly. A www/non-www or port mismatch falls
	 * through to the HTTP check rather than being resolved locally, because
	 * only the request itself can prove such a variant is served.
	 *
	 * @param string $url Absolute URL to test.
	 * @return bool
	 * @since 1.10.2
	 */
	private function is_same_site_url( $url ) {
		$home = home_url();
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$base = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );

		if ( $host === '' || $base === '' || $host !== $base ) {
			return false;
		}

		return wp_parse_url( $url, PHP_URL_PORT ) === wp_parse_url( $home, PHP_URL_PORT );
	}

	/**
	 * Whether a link points at a file that exists in the uploads directory.
	 *
	 * Scoped to the uploads directory because that is where content-linked
	 * media lives, and every path under it is served directly. Paths are
	 * confined with realpath() so an encoded traversal cannot escape it.
	 *
	 * @param string $url Absolute URL to test.
	 * @return bool
	 * @since 1.10.2
	 */
	private function uploaded_file_exists( $url ) {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );

		if ( $path === '' || $base === '' || strpos( $path, $base . '/' ) !== 0 ) {
			return false;
		}

		$relative = rawurldecode( substr( $path, strlen( $base ) + 1 ) );

		if ( $relative === '' || strpos( $relative, '..' ) !== false ) {
			return false;
		}

		$root = realpath( $uploads['basedir'] );
		$file = realpath( $uploads['basedir'] . '/' . $relative );

		if ( $root === false || $file === false ) {
			return false;
		}

		return strpos( $file, $root . DIRECTORY_SEPARATOR ) === 0 && is_file( $file );
	}

	/**
	 * Whether a same-site link can be confirmed present without an HTTP request.
	 *
	 * Resolving locally removes the dependency on the site being able to request
	 * itself. Hosts that block loopback requests answer their own URLs with a
	 * transport error, which leaves every internal link permanently unverifiable.
	 *
	 * This can only ever confirm that a link is fine. A miss means "not resolved
	 * here", never "broken", so the caller falls through to the HTTP check and
	 * behaviour is unchanged for everything this does not understand: term and
	 * date archives, feeds, sitemaps, paged URLs and custom rewrites.
	 *
	 * @param string $url Absolute URL to test.
	 * @return bool
	 * @since 1.10.2
	 */
	private function internal_link_exists_locally( $url ) {
		$resolved = false;

		if ( $this->is_same_site_url( $url ) ) {
			$post_id = url_to_postid( $url ); // @phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid

			// url_to_postid() maps any "/?<query>" URL to the static front page,
			// so a front-page match on a URL carrying a query string is not proof
			// the target exists. Those go to the HTTP check instead.
			$front_id    = (int) get_option( 'page_on_front' );
			$is_front    = $front_id > 0 && $post_id === $front_id;
			$has_query   = (string) wp_parse_url( $url, PHP_URL_QUERY ) !== '';
			$trustworthy = $post_id > 0 && ! ( $is_front && $has_query );

			// A draft resolves through url_to_postid() but 404s for visitors, so
			// the status has to be checked as well as the ID.
			$resolved = ( $trustworthy && is_post_publicly_viewable( $post_id ) ) || $this->uploaded_file_exists( $url );
		}

		/**
		 * Filters whether a same-site link was confirmed present without an HTTP request.
		 *
		 * @param bool   $resolved Whether the link was confirmed locally.
		 * @param string $url      Absolute URL that was tested.
		 * @since 1.10.2
		 */
		return (bool) apply_filters( 'surerank_broken_link_resolved_locally', $resolved, $url );
	}

	/**
	 * Parse robots.txt content into user-agent groups and directives (RFC 9309).
	 *
	 * @param string $content Robots.txt content.
	 * @return array<string, mixed>
	 * @since 1.9.0
	 */
	private function parse_robots_txt( string $content ) {
		$content = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $content );

		// Crawlers read at most 500 KiB (Google's documented limit); ignore the rest.
		if ( strlen( $content ) > 512000 ) {
			$content = substr( $content, 0, 512000 );
		}

		$groups             = [];
		$unrecognized       = [];
		$orphan_rules       = [];
		$unknown_directives = [];
		$current            = [];
		$last_was_agent     = false;

		$lines = preg_split( '/\r\n|\r|\n/', $content );
		$lines = is_array( $lines ) ? $lines : [];

		foreach ( $lines as $raw ) {
			$hash = strpos( $raw, '#' );
			if ( false !== $hash ) {
				$raw = substr( $raw, 0, $hash );
			}
			$line = trim( $raw );
			if ( '' === $line ) {
				continue;
			}

			if ( ! preg_match( '/^([A-Za-z][A-Za-z0-9 -]*?)\s*:\s*(.*)$/', $line, $matches ) ) {
				$unrecognized[] = $line;
				continue;
			}

			$directive = $this->canonical_robots_directive( strtolower( $matches[1] ) );
			$value     = trim( $matches[2] );

			if ( 'user-agent' === $directive ) {
				if ( ! $last_was_agent && ! empty( $current ) ) {
					$current = [];
				}
				// Keep only the product token: "Googlebot/2.1" governs "googlebot".
				$agent = strtolower( trim( (string) strtok( $value, '/' ) ) );
				if ( '' !== $agent ) {
					$current[] = $agent;
				}
				$last_was_agent = true;
				continue;
			}

			// Sitemap lines are independent of groups; recognized here so they
			// are not mistaken for unknown directives.
			if ( 'sitemap' === $directive ) {
				continue;
			}

			if ( 'allow' === $directive || 'disallow' === $directive ) {
				$last_was_agent = false;
				$rule           = [
					'type'  => $directive,
					'value' => $value,
				];
				if ( empty( $current ) ) {
					$orphan_rules[] = $rule;
				} else {
					foreach ( $current as $agent ) {
						if ( ! isset( $groups[ $agent ] ) ) {
							$groups[ $agent ] = [ 'rules' => [] ];
						}
						$groups[ $agent ]['rules'][] = $rule;
					}
				}
				continue;
			}

			$last_was_agent       = false;
			$unknown_directives[] = $directive;
		}

		return [
			'groups'             => $groups,
			'unrecognized'       => $unrecognized,
			'orphan_rules'       => $orphan_rules,
			'unknown_directives' => $unknown_directives,
		];
	}

	/**
	 * Normalize directive spellings that major crawlers accept and apply.
	 *
	 * Google's open-source robots.txt parser tolerates these exact misspellings
	 * and applies the rules, so the analyzer must treat them as real directives
	 * to judge SEO impact the way Google does. Source list (kAllowFrequentTypos):
	 * https://github.com/google/robotstxt/blob/master/robots.cc
	 *
	 * @param string $directive Lowercased directive name.
	 * @return string
	 * @since 1.9.0
	 */
	private function canonical_robots_directive( string $directive ) {
		$aliases = [
			'dissallow'  => 'disallow',
			'dissalow'   => 'disallow',
			'disalow'    => 'disallow',
			'diasllow'   => 'disallow',
			'disallaw'   => 'disallow',
			'useragent'  => 'user-agent',
			'user agent' => 'user-agent',
			'site-map'   => 'sitemap',
		];
		return $aliases[ $directive ] ?? $directive;
	}

	/**
	 * Get the rule group that governs a given crawler.
	 *
	 * Falls back to the wildcard group, matching how Google selects the most
	 * specific matching user-agent group.
	 *
	 * @param array<string, mixed> $parsed Parsed robots.txt.
	 * @param string               $agent  User-agent (lowercased).
	 * @return array<string, mixed>|null
	 * @since 1.9.0
	 */
	private function get_effective_group_for_agent( array $parsed, string $agent ) {
		$groups = $parsed['groups'] ?? [];
		$agent  = strtolower( $agent );

		if ( isset( $groups[ $agent ] ) ) {
			return $groups[ $agent ];
		}
		if ( isset( $groups['*'] ) ) {
			return $groups['*'];
		}
		return null;
	}

	/**
	 * Object-level guard for the shared ignore-check routes.
	 *
	 * These routes accept check_type=post|taxonomy|user, so the route-level
	 * validate_permission alone would let any role that passes the gate
	 * read/write another object's ignored checks. Enforce the same per-object
	 * guard used by the dedicated post/term/user SEO routes, matching the ID
	 * type to the check_type (post ID, term ID, or user ID).
	 *
	 * @param int|string $post_id    Target ID (post, term, or user ID per $check_type).
	 * @param string     $check_type Check type ('post', 'taxonomy', 'user').
	 * @since 1.9.0
	 * @return WP_Error|null WP_Error when denied, null when allowed.
	 */
	private function guard_object_check_access( $post_id, $check_type ) {
		// Map each check type to its object-level capability check and denial
		// message; unknown types fall back to the post guard (fail-safe).
		$guards = [
			'user'     => [ [ User_Seo::class, 'can_manage_user_seo' ], __( 'You are not allowed to manage SEO checks for this user.', 'surerank' ) ],
			'taxonomy' => [ [ Term::class, 'can_manage_term_seo' ], __( 'You are not allowed to manage SEO checks for this term.', 'surerank' ) ],
			'post'     => [ [ Post::class, 'can_manage_post_seo' ], __( 'You are not allowed to manage SEO checks for this post.', 'surerank' ) ],
		];

		[ $capability_check, $message ] = $guards[ $check_type ] ?? $guards['post'];

		if ( ! call_user_func( $capability_check, (int) $post_id ) ) {
			return new WP_Error(
				'surerank_cannot_manage_object',
				$message,
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return null;
	}

	/**
	 * Match a robots.txt path pattern against a path (RFC 9309 wildcards).
	 *
	 * @param string $pattern Pattern (may contain * and a trailing $).
	 * @param string $path    Path to test.
	 * @return int|null Specificity (pattern length) when it matches, else null.
	 * @since 1.9.0
	 */
	private function robots_pattern_match_length( string $pattern, string $path ) {
		if ( '' === $pattern ) {
			return null;
		}

		// Specificity counts the full pattern including $ (matches Google's parser).
		$specificity = strlen( $pattern );

		$anchored = false;
		if ( '$' === substr( $pattern, -1 ) ) {
			$anchored = true;
			$pattern  = substr( $pattern, 0, -1 );
		}

		$regex = preg_quote( $pattern, '#' );
		$regex = str_replace( '\*', '.*', $regex );
		$regex = '#^' . $regex . ( $anchored ? '$' : '' ) . '#';

		if ( preg_match( $regex, $path ) ) {
			return $specificity;
		}
		return null;
	}

	/**
	 * Whether a group's rules allow crawling a path (longest-match, allow wins on tie).
	 *
	 * @param array<int, array<string, string>> $rules Allow/Disallow rules.
	 * @param string                            $path  Path to test.
	 * @return bool
	 * @since 1.9.0
	 */
	private function robots_path_allows( array $rules, string $path ) {
		$longest_allow    = -1;
		$longest_disallow = -1;

		foreach ( $rules as $rule ) {
			$value = (string) ( $rule['value'] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			$length = $this->robots_pattern_match_length( $value, $path );
			if ( null === $length ) {
				continue;
			}
			if ( 'allow' === $rule['type'] ) {
				$longest_allow = max( $longest_allow, $length );
			} elseif ( 'disallow' === $rule['type'] ) {
				$longest_disallow = max( $longest_disallow, $length );
			}
		}

		if ( $longest_disallow < 0 ) {
			return true;
		}
		return $longest_allow >= $longest_disallow;
	}

	/**
	 * Real render-asset URL paths from this WordPress install, used to detect
	 * whether the served robots.txt blocks resources search engines need.
	 *
	 * Paths are derived from the actual site (theme stylesheet, core jQuery),
	 * so they adapt to custom locations and subdirectory installs instead of
	 * relying on hardcoded paths. A versioned variant (?ver=) is added for each
	 * because WordPress appends a version query string to enqueued CSS/JS,
	 * which a rule like "Disallow: /*?" blocks.
	 *
	 * @return array<int, string>
	 * @since 1.9.0
	 */
	private function get_robots_asset_probe_paths() {
		$urls = [
			get_stylesheet_uri(),                         // Active theme stylesheet.
			includes_url( 'js/jquery/jquery.min.js' ),    // WordPress core JavaScript.
		];

		$paths = [];
		foreach ( $urls as $url ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( '' === $path ) {
				continue;
			}
			$paths[] = $path;
			$paths[] = $path . '?ver=1';
		}

		return $paths;
	}

	/**
	 * Run all SEO-impact evaluators and collect findings.
	 *
	 * @param array<string, mixed> $parsed Parsed robots.txt.
	 * @return array<int, array<string, mixed>>
	 * @since 1.9.0
	 */
	private function evaluate_robots_findings( array $parsed ) {
		$findings = [];
		$checks   = [
			$this->check_site_blocked( $parsed ),
			$this->check_blocking_render_assets( $parsed ),
			$this->check_ignored_lines( $parsed ),
		];

		foreach ( $checks as $finding ) {
			if ( null !== $finding ) {
				$findings[] = $finding;
			}
		}
		return $findings;
	}

	/**
	 * R1: site-wide crawl block (the most damaging robots.txt mistake).
	 *
	 * @param array<string, mixed> $parsed Parsed robots.txt.
	 * @return array<string, mixed>|null
	 * @since 1.9.0
	 */
	private function check_site_blocked( array $parsed ) {
		$group = $this->get_effective_group_for_agent( $parsed, 'googlebot' );
		if ( null === $group || $this->robots_path_allows( $group['rules'], '/' ) ) {
			return null;
		}

		return [
			'id'       => 'site_blocked',
			'severity' => 'error',
			'heading'  => __( 'Robots.txt is not valid for SEO.', 'surerank' ),
			'summary'  => __( 'Your robots.txt is blocking search engines from crawling your entire site.', 'surerank' ),
		];
	}

	/**
	 * R2: blocking CSS/JS resources search engines need to render pages.
	 *
	 * @param array<string, mixed> $parsed Parsed robots.txt.
	 * @return array<string, mixed>|null
	 * @since 1.9.0
	 */
	private function check_blocking_render_assets( array $parsed ) {
		$group = $this->get_effective_group_for_agent( $parsed, 'googlebot' );
		if ( null === $group ) {
			return null;
		}

		$blocked = [];
		foreach ( $this->get_robots_asset_probe_paths() as $probe ) {
			if ( ! $this->robots_path_allows( $group['rules'], $probe ) ) {
				$blocked[] = $probe;
			}
		}

		if ( empty( $blocked ) ) {
			return null;
		}

		return [
			'id'       => 'blocking_assets',
			'severity' => 'warning',
			'heading'  => __( 'Robots.txt is not valid for SEO.', 'surerank' ),
			'summary'  => __( 'Your robots.txt blocks resources (CSS/JS) that search engines need to render your pages.', 'surerank' ),
		];
	}

	/**
	 * R3: lines search engines silently ignore, so the written rules never apply.
	 *
	 * Covers lines that fail to parse, Allow/Disallow rules with no User-agent
	 * above them, and probable misspellings of real directives. Legitimate
	 * extension directives (Crawl-delay, Clean-param, etc.) are not flagged.
	 *
	 * @param array<string, mixed> $parsed Parsed robots.txt.
	 * @return array<string, mixed>|null
	 * @since 1.9.0
	 */
	private function check_ignored_lines( array $parsed ) {
		$ignored = count( $parsed['unrecognized'] ?? [] ) + count( $parsed['orphan_rules'] ?? [] );

		foreach ( $parsed['unknown_directives'] ?? [] as $directive ) {
			if ( $this->is_probable_directive_typo( (string) $directive ) ) {
				$ignored++;
			}
		}

		if ( 0 === $ignored ) {
			return null;
		}

		return [
			'id'       => 'ignored_lines',
			'severity' => 'warning',
			'heading'  => __( 'Robots.txt is not valid for SEO.', 'surerank' ),
			'summary'  => __( 'Your robots.txt contains rules search engines cannot understand, so those rules are ignored.', 'surerank' ),
		];
	}

	/**
	 * Whether an unknown directive is likely a misspelling of a real one.
	 *
	 * Uses edit distance against the canonical directive names, so typos like
	 * "Disalloew" or "Allooww" are caught while unrelated extension directives
	 * pass through. Directives containing spaces are never valid.
	 *
	 * @param string $directive Lowercased directive name.
	 * @return bool
	 * @since 1.9.0
	 */
	private function is_probable_directive_typo( string $directive ) {
		if ( false !== strpos( $directive, ' ' ) ) {
			return true;
		}
		foreach ( [ 'allow', 'disallow', 'user-agent', 'sitemap' ] as $known ) {
			if ( levenshtein( $directive, $known ) <= 2 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rank a severity for ordering (higher is worse).
	 *
	 * @param string $severity Severity name.
	 * @return int
	 * @since 1.9.0
	 */
	private function robots_severity_rank( string $severity ) {
		$ranks = [
			'success'    => 0,
			'suggestion' => 1,
			'warning'    => 2,
			'error'      => 3,
		];
		return $ranks[ $severity ] ?? 0;
	}

	/**
	 * Compose a single check result from one or more findings.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @return array<string, mixed>
	 * @since 1.9.0
	 */
	private function build_robots_txt_result( array $findings ) {
		if ( empty( $findings ) ) {
			return $this->robots_txt_success_result();
		}

		usort(
			$findings,
			fn( $a, $b ) => $this->robots_severity_rank( $b['severity'] ) <=> $this->robots_severity_rank( $a['severity'] )
		);

		$top = $findings[0];

		return [
			'exists'      => true,
			'status'      => $top['severity'],
			'description' => [ $top['summary'] ],
			'message'     => $top['summary'],
			'heading'     => $top['heading'],
		];
	}

	/**
	 * Result for a missing or inaccessible robots.txt.
	 *
	 * @return array<string, mixed>
	 * @since 1.9.0
	 */
	private function robots_txt_unreachable_result() {
		return [
			'exists'      => false,
			'status'      => 'warning',
			'description' => [ __( 'Your site does not currently have an accessible robots.txt file.', 'surerank' ) ],
			'message'     => __( 'Your site does not currently have an accessible robots.txt file.', 'surerank' ),
			'heading'     => __( 'Robots.txt is missing or inaccessible.', 'surerank' ),
		];
	}

	/**
	 * Result for a robots.txt that is valid for SEO.
	 *
	 * @return array<string, mixed>
	 * @since 1.9.0
	 */
	private function robots_txt_success_result() {
		return [
			'exists'      => true,
			'status'      => 'success',
			'description' => [ __( 'Your robots.txt is valid and does not contain rules that harm your SEO.', 'surerank' ) ],
			'message'     => __( 'Your robots.txt is valid for SEO.', 'surerank' ),
			'heading'     => __( 'Robots.txt is valid.', 'surerank' ),
		];
	}

	/**
	 * Detect when a 200 response declares a non-robots content type (HTML, JSON, XML).
	 *
	 * Soft 404s and error pages commonly answer 200 with one of these types,
	 * which would otherwise parse as an all-allowing robots.txt.
	 *
	 * @param string $content_type Content-Type header value.
	 * @return bool
	 * @since 1.9.0
	 */
	private function is_non_robots_content_type( string $content_type ) {
		$content_type = strtolower( $content_type );
		foreach ( [ 'text/html', 'application/json', 'xml' ] as $type ) {
			if ( false !== strpos( $content_type, $type ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detect when a 200 response body is actually an HTML or XML page, not a robots.txt.
	 *
	 * @param string $body Response body.
	 * @return bool
	 * @since 1.9.0
	 */
	private function looks_like_markup_body( string $body ) {
		$trimmed = ltrim( $body );
		if ( '' === $trimmed ) {
			return false;
		}
		$lower = strtolower( substr( $trimmed, 0, 14 ) );
		return strpos( $lower, '<!doctype' ) === 0 || strpos( $lower, '<html' ) === 0 || strpos( $lower, '<?xml' ) === 0;
	}

	/**
	 * Consolidate keyword checks if all are suggestions (no focus keyword set).
	 *
	 * @param array<string, mixed> $checks List of checks.
	 * @return array<string, mixed>
	 */
	private function consolidate_keyword_checks( $checks ) {
		$keyword_check_keys = [
			'keyword_in_title',
			'keyword_in_description',
			'keyword_in_url',
			'keyword_in_content',
		];

		$all_exist = true;
		foreach ( $keyword_check_keys as $key ) {
			if ( ! isset( $checks[ $key ] ) ) {
				$all_exist = false;
				break;
			}
		}

		if ( ! $all_exist ) {
			return $checks;
		}

		$all_suggestions = true;
		foreach ( $keyword_check_keys as $key ) {
			if ( ! isset( $checks[ $key ]['status'] ) || $checks[ $key ]['status'] !== 'suggestion' ) {
				$all_suggestions = false;
				break;
			}
		}

		if ( ! $all_suggestions ) {
			return $checks;
		}

		foreach ( $keyword_check_keys as $key ) {
			unset( $checks[ $key ] );
		}

		// Add consolidated check.
		$checks['keyword_checks'] = [
			'status'  => 'suggestion',
			'message' => __( 'No focus keyword set. Add one to analyze title, description, URL, and content.', 'surerank' ),
			'type'    => 'keyword',
		];

		return $checks;
	}

	/**
	 * Get term checks data (cached or fresh).
	 *
	 * @param int $term_id Term ID.
	 * @return array<string, mixed>|WP_Error
	 */
	private function get_term_checks_data( $term_id ) {
		if ( $this->is_taxonomy_cache_valid( $term_id ) ) {
			return $this->get_cached_taxonomy_checks( $term_id );
		}

		$term_checks = $this->run_taxonomy_checks( $term_id );
		if ( ! is_wp_error( $term_checks ) ) {
			$term_checks = $this->get_updated_ignored_check_list( $term_checks, $term_id, 'taxonomy' );
		}

		return $term_checks;
	}

	/**
	 * Get user checks data (cached or fresh).
	 *
	 * @param int $user_id User ID.
	 * @since 1.9.0
	 * @return array<string, mixed>|WP_Error
	 */
	private function get_user_checks_data( $user_id ) {
		if ( ! get_user_by( 'id', $user_id ) ) {
			return new WP_Error( 'invalid_user', __( 'Invalid User ID.', 'surerank' ) );
		}

		if ( $this->is_user_cache_valid( $user_id ) ) {
			return $this->get_cached_user_checks( $user_id );
		}

		$user_checks = $this->run_user_checks( $user_id );
		if ( ! is_array( $user_checks ) || isset( $user_checks['status'] ) && 'error' === $user_checks['status'] ) {
			return new WP_Error( 'user_checks_failed', __( 'Failed to run user SEO checks.', 'surerank' ) );
		}

		return $this->get_updated_ignored_check_list( $user_checks, $user_id, 'user' );
	}

	/**
	 * Get post checks data (cached or fresh).
	 *
	 * @param int $post_id       Post ID.
	 * @return array<string, mixed>|WP_Error
	 */
	private function get_post_checks_data( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_post', __( 'Invalid Post ID.', 'surerank' ) );
		}

		if ( $this->is_post_cache_valid( $post, $post_id ) ) {
			return $this->get_cached_post_checks( $post_id );
		}

		$post_checks = $this->run_checks( $post_id );
		if ( ! is_wp_error( $post_checks ) ) {
			$post_checks = $this->get_updated_ignored_check_list( $post_checks, $post_id, 'post' );
		}

		return $post_checks;
	}

	/**
	 * Register all analyzer routes
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 */
	private function register_all_analyzer_routes( $namespace ) {
		$this->register_general_checks_route( $namespace );
		$this->register_settings_checks_route( $namespace );
		$this->register_other_checks_route( $namespace );
		$this->register_broken_links_route( $namespace );
		$this->register_page_seo_checks_route( $namespace );
		$this->register_taxonomy_seo_checks_route( $namespace );
		$this->register_user_seo_checks_route( $namespace );
		$this->register_ignore_checks_routes( $namespace );
		$this->register_ignore_post_checks_routes( $namespace );
		$this->register_broken_link_ignore_routes( $namespace );
	}

	/**
	 * Get the site-wide list of ignored broken link URLs.
	 *
	 * @return array<int, string>
	 * @since 1.9.0
	 */
	private function get_broken_link_ignored_urls() {
		$ignored_urls = Get::option( 'surerank_broken_link_ignored_urls', [] );
		return is_array( $ignored_urls ) ? $ignored_urls : [];
	}

	/**
	 * Normalize a URL for ignored-list comparison.
	 *
	 * Trailing slashes are stripped so cosmetic permalink variants match;
	 * scheme is preserved because http/https are different resources.
	 *
	 * @param string $url URL to normalize.
	 * @return string
	 * @since 1.9.0
	 */
	private function normalize_broken_link_url( $url ) {
		return untrailingslashit( esc_url_raw( trim( $url ) ) );
	}

	/**
	 * Whether a URL is in the site-wide ignored broken links list.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 * @since 1.9.0
	 */
	private function is_broken_link_ignored( $url ) {
		$needle = $this->normalize_broken_link_url( $url );

		foreach ( $this->get_broken_link_ignored_urls() as $ignored_url ) {
			if ( $this->normalize_broken_link_url( $ignored_url ) === $needle ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register broken link ignore/restore routes
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 * @since 1.9.0
	 */
	private function register_broken_link_ignore_routes( $namespace ) {
		register_rest_route(
			$namespace,
			$this->broken_link_ignore,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'ignore_broken_link' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_broken_link_ignore_args(),
				'role_capability'     => 'content_setting',
			]
		);

		register_rest_route(
			$namespace,
			$this->broken_link_ignore,
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'restore_broken_link' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_broken_link_ignore_args(),
				'role_capability'     => 'content_setting',
			]
		);
	}

	/**
	 * Register general checks route
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 */
	private function register_general_checks_route( $namespace ) {
		register_rest_route(
			$namespace,
			$this->general_checks,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_general_checks' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_general_checks_args(),
				'role_capability'     => 'global_setting',
			]
		);
	}

	/**
	 * Register settings checks route
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 */
	private function register_settings_checks_route( $namespace ) {
		$this->register_route_with_aliases(
			$namespace,
			$this->settings_checks,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_settings_checks' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_force_args(),
				'role_capability'     => 'global_setting',
			],
			[ $this->settings_checks_legacy ]
		);
	}

	/**
	 * Register other checks route
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 */
	private function register_other_checks_route( $namespace ) {
		register_rest_route(
			$namespace,
			$this->other_checks,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_other_checks' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_force_args(),
				'role_capability'     => 'global_setting',
			]
		);
	}

	/**
	 * Register broken links route
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 */
	private function register_broken_links_route( $namespace ) {
		register_rest_route(
			$namespace,
			$this->broken_links_check,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'get_broken_links_status' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_broken_links_args(),
				'role_capability'     => 'content_setting',
			]
		);
	}

	/**
	 * Register page SEO checks route
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 */
	private function register_page_seo_checks_route( $namespace ) {
		register_rest_route(
			$namespace,
			$this->page_seo_checks,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_page_seo_checks' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_post_id_args(),
				'role_capability'     => 'content_setting',
			]
		);
	}

	/**
	 * Register taxonomy SEO checks route
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 */
	private function register_taxonomy_seo_checks_route( $namespace ) {
		register_rest_route(
			$namespace,
			$this->taxonomy_seo_checks,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_taxonomy_seo_checks' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_term_id_args(),
				'role_capability'     => 'content_setting',
			]
		);
	}

	/**
	 * Register user SEO checks route
	 *
	 * @param string $namespace The API namespace.
	 * @since 1.9.0
	 * @return void
	 */
	private function register_user_seo_checks_route( $namespace ) {
		register_rest_route(
			$namespace,
			$this->user_seo_checks,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_user_seo_checks' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_user_id_args(),
				'role_capability'     => 'content_setting',
			]
		);
	}

	/**
	 * Register ignore checks routes
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 */
	private function register_ignore_checks_routes( $namespace ) {
		$this->register_create_ignore_check_route( $namespace );
		$this->register_delete_ignore_check_route( $namespace );
	}

	/**
	 * Register ignore post checks routes
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 */
	private function register_ignore_post_checks_routes( $namespace ) {
		$this->register_create_ignore_post_check_route( $namespace );
		$this->register_delete_ignore_post_check_route( $namespace );
		$this->register_get_ignore_post_check_route( $namespace );
	}

	/**
	 * Register create ignore check route
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 */
	private function register_create_ignore_check_route( $namespace ) {
		register_rest_route(
			$namespace,
			$this->ignore_checks,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'ignore_checks' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_id_args(),
				'role_capability'     => 'global_setting',
			]
		);
	}

	/**
	 * Register delete ignore check route
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 */
	private function register_delete_ignore_check_route( $namespace ) {
		register_rest_route(
			$namespace,
			$this->ignore_checks,
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_ignore_checks' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_sanitized_id_args(),
				'role_capability'     => 'global_setting',
			]
		);
	}

	/**
	 * Register create ignore post check route
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 */
	private function register_create_ignore_post_check_route( $namespace ) {
		register_rest_route(
			$namespace,
			$this->ignore_post_checks,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'ignore_post_taxo_check' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_ignore_post_check_args(),
				'role_capability'     => 'content_setting',
			]
		);
	}

	/**
	 * Register delete ignore post check route
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 */
	private function register_delete_ignore_post_check_route( $namespace ) {
		register_rest_route(
			$namespace,
			$this->ignore_post_checks,
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_ignore_post_taxo_check' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_ignore_post_check_args(),
				'role_capability'     => 'content_setting',
			]
		);
	}

	/**
	 * Register get ignore post check route
	 *
	 * @param string $namespace The API namespace.
	 * @return void
	 */
	private function register_get_ignore_post_check_route( $namespace ) {
		register_rest_route(
			$namespace,
			$this->ignore_post_checks,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_ignore_post_taxo_check' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => $this->get_post_id_with_check_type_args(),
				'role_capability'     => 'content_setting',
			]
		);
	}

	/**
	 * Create analysis error response.
	 *
	 * @param mixed $xpath XPath error data.
	 * @return WP_Error
	 */
	private function create_analysis_error( $xpath ): WP_Error {
		return new WP_Error(
			'analysis_failed',
			is_array( $xpath ) && isset( $xpath['message'] ) ? $xpath['message'] : 'Analysis failed',
			[
				'status'  => 500,
				'details' => is_array( $xpath ) && isset( $xpath['details'] ) ? $xpath['details'] : [],
			]
		);
	}

	/**
	 * Execute general checks.
	 *
	 * @param SeoAnalyzer $analyzer Analyzer instance.
	 * @param DOMXPath    $xpath    XPath instance.
	 * @return array<string, mixed>
	 */
	private function execute_general_checks( SeoAnalyzer $analyzer, DOMXPath $xpath ): array {
		$checks   = $this->get_general_check_callbacks( $analyzer, $xpath );
		$response = [];

		foreach ( $checks as $key => $callback ) {
			$response[ $key ] = $this->execute_single_check( $key, $callback );
		}

		return $response;
	}

	/**
	 * Get general check callbacks.
	 *
	 * @param SeoAnalyzer $analyzer Analyzer instance.
	 * @param DOMXPath    $xpath    XPath instance.
	 * @return array<string, callable>
	 */
	private function get_general_check_callbacks( SeoAnalyzer $analyzer, DOMXPath $xpath ): array {
		return [
			'title'             => static fn() => $analyzer->analyze_title( $xpath ),
			'meta_description'  => static fn() => $analyzer->analyze_meta_description( $xpath ),
			'headings_h1'       => static fn() => $analyzer->analyze_heading_h1( $xpath ),
			'headings_h2'       => static fn() => $analyzer->analyze_heading_h2( $xpath ),
			'images'            => static fn() => $analyzer->analyze_images( $xpath ),
			'links'             => static fn() => $analyzer->analyze_links( $xpath ),
			'canonical'         => static fn() => $analyzer->analyze_canonical( $xpath ),
			'indexing'          => static fn() => $analyzer->analyze_indexing( $xpath ),
			'reachability'      => static fn() => $analyzer->analyze_reachability(),
			'secure_connection' => static fn() => $analyzer->analyze_secure_connection(),
			'www_canonical'     => static fn() => $analyzer->analyze_www_canonicalization(),
			'open_graph_tags'   => static fn() => $analyzer->open_graph_tags( $xpath ),
			'schema_meta_data'  => static fn() => $analyzer->schema_meta_data( $xpath ),
		];
	}

	/**
	 * Execute a single check.
	 *
	 * @param string   $key      Check key.
	 * @param callable $callback Check callback.
	 * @return array<string, mixed>
	 */
	private function execute_single_check( string $key, callable $callback ): array {
		$result           = (array) $callback();
		$result['ignore'] = $this->is_check_ignored( $key );
		return $result;
	}

	/**
	 * Check if a check should be ignored.
	 *
	 * @param string $key Check key.
	 * @return bool
	 */
	private function is_check_ignored( string $key ): bool {
		return in_array( $key, $this->get_ignore_checks(), true );
	}

	/**
	 * Check if the sitemap is valid XML.
	 *
	 * @param string $sitemap Sitemap content.
	 * @return bool
	 */
	private function is_valid_xml( string $sitemap ): bool {
		/**
		 * Here we are checking if the sitemap is valid XML.
		 * First we supressing the errors.
		 * Then we load the sitemap as simplexml.
		 * Then we clear the errors.
		 * Then we restore the errors suppression.
		 */

		libxml_use_internal_errors( true );
		$xml        = simplexml_load_string( $sitemap );
		$xml_errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		return $xml !== false && empty( $xml_errors );
	}

	/**
	 * Get WordPress settings page url.
	 *
	 * @param string $page Page slug.
	 * @return string
	 */
	private function get_wordpress_settings_url( string $page = 'general' ): string {
		return admin_url( 'options-' . $page . '.php' );
	}

	/**
	 * Get SureRank dashboard url.
	 *
	 * @return string
	 */
	private function get_search_console_url() {
		// Check if Google Search Console feature is enabled.
		if ( ! Settings::get( 'enable_google_console' ) ) {
			return admin_url( 'admin.php?page=surerank#/tools/manage-features' );
		}

		return admin_url( 'admin.php?page=surerank#/search-console' );
	}

	/**
	 * Get ignore checks.
	 *
	 * @return array<string>
	 */
	private function get_ignore_checks() {
		return Get::option( 'surerank_ignored_site_checks_list', [] );
	}

	/**
	 * Save broken links.
	 *
	 * @param string        $url URL.
	 * @param int           $post_id Post ID.
	 * @param array<string> $urls URLs.
	 * @param int|null      $status_code HTTP status code.
	 * @param int|string    $error_message Error message.
	 * @return bool
	 */
	private function save_broken_links( string $url, int $post_id, array $urls, $status_code = null, $error_message = null ) {
		$seo_checks = Get::post_meta( $post_id, SURERANK_SEO_CHECKS, true );
		if ( ! is_array( $seo_checks ) ) {
			$seo_checks = [];
		}
		$broken_links = $seo_checks['broken_links'] ?? [];

		$base                  = Utils::get_post_base_url( $post_id );
		$existing_broken_links = $this->reconcile_broken_links( $broken_links, $urls, $base );

		$broken_link_details = [
			'url'     => $url,
			'status'  => $status_code,
			'details' => $error_message ? $error_message : __( 'The link is broken.', 'surerank' ),
		];

		$url_found = false;
		foreach ( $existing_broken_links as $key => $existing_link ) {
			if ( $this->stored_broken_link_matches_url( $existing_link, $url, $base ) ) {
				$existing_broken_links[ $key ] = $broken_link_details;
				$url_found                     = true;
				break;
			}
		}

		if ( ! $url_found ) {
			$existing_broken_links[] = $broken_link_details;
		}

		$final_array                 = [];
		$final_array['broken_links'] = $this->build_broken_links_check( array_values( $existing_broken_links ) );

		return Update::post_seo_checks( $post_id, $final_array );
	}

	/**
	 * Get post-specific ignore checks.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string>
	 */
	private function get_ignore_post_checks( $post_id ) {
		return Get::post_meta( $post_id, 'surerank_ignored_post_checks', true );
	}

	/**
	 * Get taxonomy-specific ignore checks.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string>
	 */
	private function get_ignore_taxonomy_checks( $term_id ) {
		return Get::term_meta( $term_id, 'surerank_ignored_post_checks', true );
	}

	/**
	 * Get user-specific ignore checks.
	 *
	 * @param int $user_id User ID.
	 * @since 1.9.0
	 * @return array<string>
	 */
	private function get_ignore_user_checks( $user_id ) {
		return Get::user_meta( $user_id, 'surerank_ignored_post_checks', true );
	}

	/**
	 * Update the site SEO checks.
	 *
	 * @param array<string, mixed> $response Response data.
	 * @param string               $type Type of checks.
	 * @return void
	 */
	private function update_site_seo_checks( array &$response, string $type ) {
		$existing_seo_checks = Get::option( 'surerank_site_seo_checks', [] );
		$seo_checks          = ! is_array( $existing_seo_checks ) ? [] : $existing_seo_checks;
		$seo_checks[ $type ] = $response;
		Update::option( 'surerank_site_seo_checks', $seo_checks );
	}

	/**
	 * Check if the cache exists.
	 *
	 * @param string $type Type of checks.
	 * @return bool
	 */
	private function cache_exists( string $type ) {
		$seo_checks = Get::option( 'surerank_site_seo_checks', [] );
		return isset( $seo_checks[ $type ] ) && ! empty( $seo_checks[ $type ] );
	}

	/**
	 * Get cached response.
	 *
	 * @param string $type Type of checks.
	 * @return array<string, mixed>
	 */
	private function get_cached_response( string $type ) {
		$seo_checks = Get::option( 'surerank_site_seo_checks', [] );
		return $seo_checks[ $type ] ?? [];
	}

	/**
	 * Get general checks route arguments
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_general_checks_args() {
		return [
			'url' => [
				'type'              => 'string',
				'validate_callback' => static function ( $param, $request, $key ) {
					return filter_var( $param, FILTER_VALIDATE_URL );
				},
				'sanitize_callback' => 'esc_url_raw',
				'required'          => true,
			],
		];
	}

	/**
	 * Get force arguments
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_force_args() {
		return [
			'force' => [
				'type'     => 'boolean',
				'required' => false,
			],
		];
	}

	/**
	 * Get broken links route arguments
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_broken_links_args() {
		return [
			'url'        => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => [ self::class, 'sanitize_link_href' ],
			],
			'user_agent' => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'post_id'    => [
				'type'              => 'integer',
				'required'          => true,
				'validate_callback' => static function ( $param, $request, $key ) {
					return $param > 0;
				},
				'sanitize_callback' => 'absint',
			],
			'urls'       => [
				'type'              => 'array',
				'required'          => true,
				'validate_callback' => static function ( $param, $request, $key ) {
					return is_array( $param ) && $param !== [];
				},
				'sanitize_callback' => [ self::class, 'sanitize_link_hrefs' ],
				'items'             => [
					'type' => 'string',
				],
			],
		];
	}

	/**
	 * Get broken link ignore/restore arguments
	 *
	 * @return array<string, mixed>
	 * @since 1.9.0
	 */
	private function get_broken_link_ignore_args() {
		return [
			'url'     => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'esc_url_raw',
			],
			'post_id' => [
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
			],
			'urls'    => [
				'type'              => 'array',
				'required'          => false,
				'default'           => [],
				'sanitize_callback' => [ self::class, 'sanitize_urls' ],
				'items'             => [
					'type' => 'string',
				],
			],
		];
	}

	/**
	 * Get post ID arguments
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_post_id_args() {
		return [
			'post_ids' => [
				'type'              => 'array',
				'required'          => true,
				'sanitize_callback' => [ self::class, 'sanitize_ids' ],
				'items'             => [
					'type' => 'integer',
				],
			],
		];
	}

	/**
	 * Get term ID arguments
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_term_id_args() {
		return [
			'term_ids' => [
				'type'              => 'array',
				'required'          => true,
				'sanitize_callback' => [ self::class, 'sanitize_ids' ],
				'items'             => [
					'type' => 'integer',
				],
			],
		];
	}

	/**
	 * Get user ID arguments
	 *
	 * @since 1.9.0
	 * @return array<string, array<string, mixed>>
	 */
	private function get_user_id_args() {
		return [
			'user_ids' => [
				'type'              => 'array',
				'required'          => true,
				'sanitize_callback' => [ self::class, 'sanitize_ids' ],
				'items'             => [
					'type' => 'integer',
				],
			],
		];
	}

	/**
	 * Get ID arguments
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_id_args() {
		return [
			'id' => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Get sanitized ID arguments
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_sanitized_id_args() {
		return [
			'id' => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Get ignore post check arguments
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_ignore_post_check_args() {
		return [
			'id'         => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'post_id'    => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
			'check_type' => [
				'type'        => 'string',
				'default'     => 'post',
				'enum'        => [
					'post',
					'taxonomy',
					'user',
				],
				'description' => __( 'Type of check to delete. Can be "post", "taxonomy" or "user".', 'surerank' ),
			],
		];
	}

	/**
	 * Get post ID with check type arguments
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_post_id_with_check_type_args() {
		return [
			'post_id'    => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
			'check_type' => [
				'type'        => 'string',
				'default'     => 'post',
				'enum'        => [
					'post',
					'taxonomy',
					'user',
				],
				'description' => __( 'Type of check to delete. Can be "post", "taxonomy" or "user".', 'surerank' ),
			],
		];
	}

	/**
	 * Create error response
	 *
	 * @param string $message Error message.
	 * @return WP_REST_Response
	 */
	private function create_error_response( $message ) {
		return rest_ensure_response(
			[
				'status'  => 'error',
				'message' => $message,
			]
		);
	}

	/**
	 * Check if post cache is valid
	 *
	 * @param \WP_Post $post Post object.
	 * @param int      $post_id Post ID.
	 * @return bool
	 */
	private function is_post_cache_valid( $post, $post_id ) {
		if ( $this->post_checks_contain_legacy_relative_links( $post_id ) ) {
			return false;
		}

		$post_modified_time  = $post->post_modified_gmt ? strtotime( $post->post_modified_gmt ) : 0;
		$checks_last_updated = Get::post_meta( $post_id, SURERANK_SEO_CHECKS_LAST_UPDATED, true );
		$settings_updated    = Get::option( SURERANK_SEO_LAST_UPDATED );

		$checks_last_updated = ! empty( $checks_last_updated ) ? (int) $checks_last_updated : 0;
		$settings_updated    = ! empty( $settings_updated ) ? (int) $settings_updated : 0;

		return $checks_last_updated !== 0 &&
			$post_modified_time <= $checks_last_updated &&
			( $settings_updated === 0 || $checks_last_updated >= $settings_updated );
	}

	/**
	 * Detect pre-normalization relative broken-link records in cached checks.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 * @since 1.10.2
	 */
	private function post_checks_contain_legacy_relative_links( int $post_id ) {
		$checks = Get::post_meta( $post_id, SURERANK_SEO_CHECKS, true );
		$list   = Utils::get_broken_links_list( is_array( $checks ) ? ( $checks['broken_links'] ?? [] ) : [] );

		foreach ( $list as $item ) {
			$url = is_array( $item ) ? ( $item['url'] ?? '' ) : $item;
			if ( is_string( $url ) && $url !== '' && ! preg_match( '#^https?://#i', trim( $url ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get cached post checks
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function get_cached_post_checks( $post_id ) {
		$post_checks = Get::post_meta( $post_id, 'surerank_seo_checks', true );
		if ( ! empty( $post_checks ) ) {
			return $this->get_updated_ignored_check_list( $post_checks, $post_id, 'post' );
		}
		return new WP_Error( 'no_cached_checks', __( 'No cached checks found.', 'surerank' ) );
	}

	/**
	 * Check if taxonomy cache is valid
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	private function is_taxonomy_cache_valid( $term_id ) {
		$term_modified_time  = Get::term_meta( $term_id, SURERANK_TAXONOMY_UPDATED_AT, true );
		$checks_last_updated = Get::term_meta( $term_id, SURERANK_SEO_CHECKS_LAST_UPDATED, true );
		$settings_updated    = Get::option( SURERANK_SEO_LAST_UPDATED );

		$term_modified_time  = ! empty( $term_modified_time ) ? (int) $term_modified_time : 0;
		$checks_last_updated = ! empty( $checks_last_updated ) ? (int) $checks_last_updated : 0;
		$settings_updated    = ! empty( $settings_updated ) ? (int) $settings_updated : 0;

		return $checks_last_updated !== 0 &&
			$term_modified_time <= $checks_last_updated &&
			( $settings_updated === 0 || $checks_last_updated >= $settings_updated );
	}

	/**
	 * Get cached taxonomy checks
	 *
	 * @param int $term_id Term ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function get_cached_taxonomy_checks( $term_id ) {
		$term_checks = Get::term_meta( $term_id, 'surerank_seo_checks', true );
		if ( ! empty( $term_checks ) ) {
			return $this->get_updated_ignored_check_list( $term_checks, $term_id, 'taxonomy' );
		}
		return new WP_Error( 'no_cached_checks', __( 'No cached checks found.', 'surerank' ) );
	}

	/**
	 * Check if user cache is valid
	 *
	 * @param int $user_id User ID.
	 * @since 1.9.0
	 * @return bool
	 */
	private function is_user_cache_valid( $user_id ) {
		$user_modified_time  = Get::user_meta( $user_id, SURERANK_USER_UPDATED_AT, true );
		$checks_last_updated = Get::user_meta( $user_id, SURERANK_SEO_CHECKS_LAST_UPDATED, true );
		$settings_updated    = Get::option( SURERANK_SEO_LAST_UPDATED );

		$user_modified_time  = ! empty( $user_modified_time ) ? (int) $user_modified_time : 0;
		$checks_last_updated = ! empty( $checks_last_updated ) ? (int) $checks_last_updated : 0;
		$settings_updated    = ! empty( $settings_updated ) ? (int) $settings_updated : 0;

		return $checks_last_updated !== 0 &&
			$user_modified_time <= $checks_last_updated &&
			( $settings_updated === 0 || $checks_last_updated >= $settings_updated );
	}

	/**
	 * Get cached user checks
	 *
	 * @param int $user_id User ID.
	 * @since 1.9.0
	 * @return array<string,mixed>|WP_Error
	 */
	private function get_cached_user_checks( $user_id ) {
		$user_checks = Get::user_meta( $user_id, 'surerank_seo_checks', true );
		if ( ! empty( $user_checks ) ) {
			return $this->get_updated_ignored_check_list( $user_checks, $user_id, 'user' );
		}
		return new WP_Error( 'no_cached_checks', __( 'No cached checks found.', 'surerank' ) );
	}

	/**
	 * Fetch URL status
	 *
	 * The client supplies the editor's browser user agent so the check is made
	 * with the same identity a visitor would use. Hosts and WAFs commonly answer
	 * the default WordPress user agent with a 403 or a challenge page, which
	 * would otherwise read as an unverifiable link.
	 *
	 * @param string $url URL to check.
	 * @param string $user_agent User agent for the outbound request. Empty keeps the WordPress default.
	 * @return array<string, mixed>|WP_Error
	 */
	private function fetch_url_status( $url, string $user_agent = '' ) {
		$args = [
			'limit_response_size' => 1,
			'timeout'             => 30, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
		];

		if ( $user_agent !== '' ) {
			$args['user-agent'] = $user_agent;
		}

		return Requests::get(
			$url,
			apply_filters(
				'surerank_broken_link_request_args',
				$args
			)
		);
	}

	/**
	 * Create broken link error response
	 *
	 * @param string $message Error message.
	 * @return WP_REST_Response
	 */
	private function create_broken_link_error_response( $message ) {
		return rest_ensure_response(
			[
				'success'  => false,
				'verified' => false,
				'message'  => $message,
			]
		);
	}

	/**
	 * Handle broken link error
	 *
	 * @param string        $url URL.
	 * @param int           $post_id Post ID.
	 * @param array<string> $urls URLs array.
	 * @param WP_Error      $response Error response.
	 * @return WP_REST_Response
	 */
	private function handle_broken_link_error( $url, $post_id, $urls, $response ) {
		self::log( 'Could not verify link: ' . $url . ' with Error: ' . $response->get_error_message() );
		return $this->create_broken_link_unverified_response(
			__( 'Could not verify the link.', 'surerank' ),
			$response->get_error_code(),
			$response->get_error_message()
		);
	}

	/**
	 * Return a result for a link that could not be checked conclusively.
	 *
	 * @param string     $message User-facing message.
	 * @param int|string $status Request or HTTP status.
	 * @param string     $details Technical details.
	 * @return WP_REST_Response
	 * @since 1.10.2
	 */
	private function create_broken_link_unverified_response( $message, $status, $details ) {
		return rest_ensure_response(
			[
				'success'  => false,
				'verified' => false,
				'message'  => $message,
				'status'   => $status,
				'details'  => $details,
			]
		);
	}

	/**
	 * Handle broken link status error
	 *
	 * @param string               $url URL.
	 * @param int                  $post_id Post ID.
	 * @param array<string>        $urls URLs array.
	 * @param int                  $status_code HTTP status code.
	 * @param array<string, mixed> $response HTTP response.
	 * @return WP_REST_Response
	 */
	private function handle_broken_link_status_error( $url, $post_id, $urls, $status_code, $response ) {
		$this->save_broken_links( $url, $post_id, $urls, $status_code, wp_remote_retrieve_response_message( $response ) );
		self::log( 'Link is broken: ' . $url . ' with status code: ' . $status_code );
		return rest_ensure_response(
			[
				'success'  => false,
				'verified' => true,
				'message'  => __( 'Link is broken', 'surerank' ),
				'details'  => wp_remote_retrieve_response_message( $response ),
				'status'   => $status_code,
			]
		);
	}
}
