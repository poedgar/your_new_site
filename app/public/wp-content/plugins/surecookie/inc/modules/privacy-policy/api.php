<?php
/**
 * Privacy Policy REST API.
 *
 * @package SureCookie\Inc\Modules\PrivacyPolicy
 * @since 1.5.0
 */

namespace SureCookie\Inc\Modules\PrivacyPolicy;

use SureCookie\Inc\API\Base;
use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Api
 *
 * @since 1.5.0
 */
class Api extends Base {
	use GetInstance;

	/**
	 * Create the draft page.
	 */
	protected const GENERATE_PAGE = '/privacy-policy/generate-page';

	/**
	 * Read the whole screen state in one request.
	 */
	protected const STATE = '/privacy-policy/state';

	/**
	 * Publish the generated page, if it is complete.
	 */
	protected const PUBLISH = '/privacy-policy/publish';

	/**
	 * Point WordPress's own privacy policy setting at the generated page.
	 */
	protected const SET_WP_PAGE = '/privacy-policy/set-wp-page';

	/**
	 * Setting holding the managed page ID.
	 *
	 * @since 1.5.0
	 */
	private const PAGE_SETTING = 'privacy_policy_page_id';

	/**
	 * The slug a privacy policy should own.
	 *
	 * @since 1.5.0
	 */
	private const CANONICAL_SLUG = 'privacy-policy';

	/**
	 * Slug used while another page still holds the canonical one.
	 *
	 * @since 1.5.0
	 */
	private const INTERIM_SLUG = 'privacy-policy-new';

	/**
	 * Slug the displaced page is moved to.
	 *
	 * @since 1.5.0
	 */
	private const DISPLACED_SLUG = 'privacy-policy-old';

	/**
	 * Register API routes.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			self::GENERATE_PAGE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'generate_page' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::STATE,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_state' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::PUBLISH,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'publish_page' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::SET_WP_PAGE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'set_wp_privacy_page' ],
				'permission_callback' => [ $this, 'validate_privacy_options_permission' ],
				'args'                => [
					'confirm' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
	}

	/**
	 * Require WordPress's own privacy capability on top of the plugin's.
	 *
	 * Composes rather than replaces, so the audited nonce handling in Base is
	 * reused. `manage_privacy_options` maps to `manage_network` on multisite,
	 * where SureCookie's own capability does not, so writing core's option under
	 * the plugin capability alone would let a site admin set something core
	 * itself refuses them.
	 *
	 * @since 1.5.0
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function validate_privacy_options_permission( $request ) {
		$base = parent::validate_permission( $request );

		if ( is_wp_error( $base ) || $base !== true ) {
			return $base;
		}

		if ( ! current_user_can( 'manage_privacy_options' ) ) {
			return new WP_Error(
				'surecookie_forbidden',
				__( 'You are not allowed to change this site\'s privacy policy page.', 'surecookie' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Create the privacy policy page as a draft.
	 *
	 * @since 1.5.0
	 * @param WP_REST_Request $request REST request object.
	 * @return void
	 */
	public function generate_page( WP_REST_Request $request ): void {
		unset( $request );

		$existing = $this->existing_page();

		// Accepts any non-trashed status, unlike the cookie-policy generator,
		// because a draft is the normal state here: gating on `publish` would
		// mint a duplicate page on every click.
		if ( $existing instanceof WP_Post ) {
			SendJson::success(
				array_merge(
					[
						'message'        => __( 'Privacy Policy page already exists.', 'surecookie' ),
						'already_exists' => true,
					],
					$this->page_payload( $existing )
				)
			);
			return;
		}

		$page_id = wp_insert_post(
			[
				'post_title'   => __( 'Privacy Policy', 'surecookie' ),
				// Take the canonical slug when nothing holds it, so the common
				// case needs no rename later. The title never varies.
				'post_name'    => $this->occupant_of( self::CANONICAL_SLUG ) instanceof WP_Post
					? self::INTERIM_SLUG
					: self::CANONICAL_SLUG,
				'post_content' => Blocks::build( Template::sections() ),
				// Draft on purpose. WordPress core does the same for its own
				// privacy page: a live, indexed page carrying unfilled
				// placeholders is worse than no page at all.
				'post_status'  => 'draft',
				'post_type'    => 'page',
			],
			true
		);

		if ( is_wp_error( $page_id ) ) {
			SendJson::error( [ 'message' => $page_id->get_error_message() ] );
			return;
		}

		Settings::update( self::PAGE_SETTING, $page_id );

		$page = get_post( $page_id );

		SendJson::success(
			array_merge(
				[
					'message'        => __( 'Privacy Policy draft created. Review it, then publish when you are ready.', 'surecookie' ),
					'already_exists' => false,
				],
				$page instanceof WP_Post ? $this->page_payload( $page ) : []
			)
		);
	}

	/**
	 * Report the whole screen state in one request.
	 *
	 * ID-less on purpose: the generic /posts/{id} route rejects anything that is
	 * not published, so it reports a freshly generated draft as missing, and
	 * relaxing it would give away whether an arbitrary post exists. Computing
	 * the missing-field list here also means the gate and the UI count come from
	 * the same evaluation.
	 *
	 * @since 1.5.0
	 * @param WP_REST_Request $request REST request object.
	 * @return void
	 */
	public function get_state( WP_REST_Request $request ): void {
		unset( $request );

		$page = $this->existing_page();

		SendJson::success(
			[
				'page'                       => $page instanceof WP_Post ? $this->page_payload( $page ) : null,
				'placeholders'               => $page instanceof WP_Post ? $this->count_placeholders( $page ) : 0,
				'page_missing'               => absint( Settings::get( self::PAGE_SETTING ) ) > 0 && ! $page instanceof WP_Post,
				'wp_privacy_page'            => $this->wp_privacy_page_state(),
				'slug'                       => $page instanceof WP_Post ? $page->post_name : '',
				'slug_conflict'              => $this->slug_conflict( $page ),
				'missing'                    => Validator::missing_required_fields(),
				'jurisdictions'              => Validator::selected_jurisdictions(),
				'can_manage_privacy_options' => current_user_can( 'manage_privacy_options' ),
			]
		);
	}

	/**
	 * Publish the generated page once the identity fields are filled.
	 *
	 * @since 1.5.0
	 * @param WP_REST_Request $request REST request object.
	 * @return void
	 */
	public function publish_page( WP_REST_Request $request ): void {
		unset( $request );

		$page = $this->existing_page();

		if ( ! $page instanceof WP_Post ) {
			SendJson::error( [ 'message' => __( 'There is no privacy policy page to publish.', 'surecookie' ) ] );
			return;
		}

		$missing = Validator::missing_required_fields();

		if ( ! empty( $missing ) ) {
			SendJson::error(
				[
					'code'    => 'incomplete',
					'missing' => $missing,
					'message' => __( 'Add your business details before publishing this policy.', 'surecookie' ),
				]
			);
			return;
		}

		$updated = wp_update_post(
			[
				'ID'          => $page->ID,
				'post_status' => 'publish',
			],
			true
		);

		if ( is_wp_error( $updated ) ) {
			SendJson::error( [ 'message' => $updated->get_error_message() ] );
			return;
		}

		$page = get_post( $page->ID );

		SendJson::success(
			array_merge(
				[ 'message' => __( 'Privacy Policy published.', 'surecookie' ) ],
				$page instanceof WP_Post ? $this->page_payload( $page ) : []
			)
		);
	}

	/**
	 * Point core's wp_page_for_privacy_policy at the generated page.
	 *
	 * Deliberately a separate action rather than part of generation: it changes
	 * get_privacy_policy_url() site-wide, adds core's delete protection, and
	 * moves where SureCookie Pro auto-inserts its data request form.
	 *
	 * @since 1.5.0
	 * @param WP_REST_Request $request REST request object.
	 * @return void
	 */
	public function set_wp_privacy_page( WP_REST_Request $request ): void {
		$page = $this->existing_page();

		if ( ! $page instanceof WP_Post ) {
			SendJson::error( [ 'message' => __( 'Generate a privacy policy page first.', 'surecookie' ) ] );
			return;
		}

		$current = $this->wp_privacy_page_state();

		// Same source the screen reads, so the button's warning and the gate can
		// never disagree about what this click moves. Any live address this
		// retires is a reason to ask, ours or somebody else's: a published page
		// changing its public URL is not what a button about a WordPress setting
		// looks like it does.
		$conflict = $this->slug_conflict( $page );

		if ( ( $current['needs_confirmation'] || $conflict !== null ) && ! $request->get_param( 'confirm' ) ) {
			SendJson::error(
				[
					'code'       => 'requires_confirmation',
					'current'    => $current,
					'displacing' => $conflict !== null && empty( $conflict['is_ours'] ) ? $conflict : null,
					'conflict'   => $conflict,
					'message'    => $this->confirmation_message( $conflict ),
				]
			);
			return;
		}

		$slug = $this->claim_canonical_slug( $page );

		update_option( 'wp_page_for_privacy_policy', $page->ID );

		SendJson::success(
			[
				'message'         => $slug['swapped']
					? __( 'WordPress now uses this page as the site privacy policy, and it has taken the /privacy-policy/ address.', 'surecookie' )
					: __( 'WordPress now uses this page as the site privacy policy.', 'surecookie' ),
				'slug_swapped'    => $slug['swapped'],
				'displaced'       => $slug['displaced'],
				'page'            => $this->page_payload( $this->existing_page() ?? $page ),
				'wp_privacy_page' => $this->wp_privacy_page_state(),
			]
		);
	}

	/**
	 * Who, if anyone, stands between our page and the canonical slug.
	 *
	 * @since 1.5.0
	 * @param WP_Post|null $page Our managed page.
	 * @return array<string, mixed>|null
	 */
	private function slug_conflict( ?WP_Post $page ): ?array {
		if ( ! $page instanceof WP_Post || $page->post_name === self::CANONICAL_SLUG ) {
			return null;
		}

		$incumbent = $this->occupant_of( self::CANONICAL_SLUG, $page->ID );

		if ( $incumbent instanceof WP_Post ) {
			return [
				'id'       => $incumbent->ID,
				'title'    => get_the_title( $incumbent ),
				'slug'     => $incumbent->post_name,
				'moves_to' => self::DISPLACED_SLUG,
				'is_ours'  => false,
			];
		}

		// Nothing in the way, but our own page is published somewhere else: taking
		// the canonical slug retires the address it is published at today, and
		// every link to it starts 404ing. A draft has no public URL yet, so it is
		// not a conflict.
		if ( get_post_status( $page ) !== 'publish' ) {
			return null;
		}

		return [
			'id'       => $page->ID,
			'title'    => get_the_title( $page ),
			'slug'     => $page->post_name,
			'moves_to' => self::CANONICAL_SLUG,
			'is_ours'  => true,
		];
	}

	/**
	 * What to tell the admin before this click changes a public URL.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed>|null $conflict Result of slug_conflict().
	 * @return string
	 */
	private function confirmation_message( ?array $conflict ): string {
		if ( $conflict === null ) {
			return __( 'This site already has a published privacy policy page. Confirm to point WordPress at the SureCookie page instead.', 'surecookie' );
		}

		if ( ! empty( $conflict['is_ours'] ) ) {
			return sprintf(
				/* translators: 1: the address the page is published at now, 2: the address it moves to. */
				__( 'This page is published at /%1$s/. Confirm to move it to /%2$s/ - the old address will stop working.', 'surecookie' ),
				(string) $conflict['slug'],
				(string) $conflict['moves_to']
			);
		}

		return sprintf(
			/* translators: 1: title of the page being moved, 2: the slug it moves to, 3: the slug it gives up. */
			__( 'The page "%1$s" currently uses the /%3$s/ address. Confirm to move it to /%2$s/, turn it back into a draft, and give /%3$s/ to the SureCookie page.', 'surecookie' ),
			(string) $conflict['title'],
			self::DISPLACED_SLUG,
			self::CANONICAL_SLUG
		);
	}

	/**
	 * The page currently holding a slug, if any.
	 *
	 * Queried by post_name across every status but trash, because a draft or
	 * pending page holds the slug just as firmly as a published one as far as
	 * wp_unique_post_slug is concerned.
	 *
	 * @since 1.5.0
	 * @param string $slug    Slug to look up.
	 * @param int    $exclude Post ID to ignore.
	 * @return WP_Post|null
	 */
	private function occupant_of( string $slug, int $exclude = 0 ): ?WP_Post {
		$found = get_posts(
			[
				'name'                   => $slug,
				'post_type'              => 'page',
				'post_status'            => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'numberposts'            => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			]
		);

		$page = ! empty( $found ) && $found[0] instanceof WP_Post ? $found[0] : null;

		// Filtered here rather than with `exclude`, which resolves to
		// post__not_in and carries a performance warning for no benefit at
		// numberposts=1.
		if ( $page instanceof WP_Post && $exclude > 0 && $page->ID === $exclude ) {
			return null;
		}

		return $page;
	}

	/**
	 * Move a page to a slug, reporting whether it actually got it.
	 *
	 * WordPress runs the value through wp_unique_post_slug, which silently
	 * appends a suffix when the slug is taken. Comparing what came back is the
	 * only way to know the move succeeded.
	 *
	 * @since 1.5.0
	 * @param int         $page_id Page to move.
	 * @param string      $slug    Desired slug.
	 * @param string|null $status  Post status to set, or null to leave it alone.
	 * @return bool
	 */
	private function move_page( int $page_id, string $slug, ?string $status = null ): bool {
		$data = [
			'ID'        => $page_id,
			'post_name' => $slug,
		];

		if ( $status !== null ) {
			$data['post_status'] = $status;
		}

		$updated = wp_update_post( $data, true );

		if ( is_wp_error( $updated ) ) {
			return false;
		}

		if ( get_post_field( 'post_name', $page_id ) !== $slug ) {
			return false;
		}

		return $status === null || get_post_field( 'post_status', $page_id ) === $status;
	}

	/**
	 * Give the managed page the canonical slug, displacing whoever holds it.
	 *
	 * Order matters: WordPress will not hand out a slug another page still
	 * holds, so the incumbent moves first. If our page then fails to take the
	 * slug the incumbent is put back, because leaving neither page on
	 * /privacy-policy/ would be worse than changing nothing.
	 *
	 * @since 1.5.0
	 * @param WP_Post $page Our managed page.
	 * @return array{swapped: bool, displaced: array<string, mixed>|null}
	 */
	private function claim_canonical_slug( WP_Post $page ): array {
		$result = [
			'swapped'   => false,
			'displaced' => null,
		];

		if ( $page->post_name === self::CANONICAL_SLUG ) {
			return $result;
		}

		$incumbent  = $this->occupant_of( self::CANONICAL_SLUG, $page->ID );
		$was_slug   = $incumbent instanceof WP_Post ? $incumbent->post_name : '';
		$was_status = $incumbent instanceof WP_Post ? $incumbent->post_status : '';

		// The displaced page is taken off the site as well as out of the way. It
		// was the site's privacy policy a moment ago, so leaving it published at
		// /privacy-policy-old/ would leave two privacy policies reachable and
		// indexable. Reverting to a draft keeps the content and its history.
		if ( $incumbent instanceof WP_Post && ! $this->move_page( $incumbent->ID, self::DISPLACED_SLUG, 'draft' ) ) {
			return $result;
		}

		if ( ! $this->move_page( $page->ID, self::CANONICAL_SLUG ) ) {
			if ( $incumbent instanceof WP_Post && $was_slug !== '' ) {
				$this->move_page( $incumbent->ID, $was_slug, $was_status !== '' ? $was_status : null );
			}

			return $result;
		}

		$result['swapped'] = true;

		if ( $incumbent instanceof WP_Post ) {
			$result['displaced'] = [
				'id'     => $incumbent->ID,
				'title'  => get_the_title( $incumbent ),
				'slug'   => get_post_field( 'post_name', $incumbent->ID ),
				'status' => get_post_field( 'post_status', $incumbent->ID ),
				'url'    => get_permalink( $incumbent->ID ),
			];
		}

		return $result;
	}

	/**
	 * The managed page, when the stored ID still resolves to a live post.
	 *
	 * @since 1.5.0
	 * @return WP_Post|null
	 */
	private function existing_page(): ?WP_Post {
		$page_id = absint( Settings::get( self::PAGE_SETTING ) );

		if ( $page_id <= 0 ) {
			return null;
		}

		$page = get_post( $page_id );

		if ( ! $page instanceof WP_Post || $page->post_status === 'trash' ) {
			return null;
		}

		return $page;
	}

	/**
	 * How many author-owned blocks are still unedited.
	 *
	 * Counted from the marker class the generator puts on each one, so the
	 * screen can say what is left rather than asking the user to hunt.
	 *
	 * @since 1.5.0
	 * @param WP_Post $page Page to inspect.
	 * @return int
	 */
	private function count_placeholders( WP_Post $page ): int {
		// Match the block delimiter, not the class: the class also appears on the
		// rendered element, which would double every count.
		return substr_count(
			(string) $page->post_content,
			'<!-- wp:quote {"className":"surecookie-policy-placeholder"} -->'
		);
	}

	/**
	 * Page details shared by every response.
	 *
	 * @since 1.5.0
	 * @param WP_Post $page Page object.
	 * @return array<string, mixed>
	 */
	private function page_payload( WP_Post $page ): array {
		return [
			'page_id'  => $page->ID,
			'status'   => $page->post_status,
			'title'    => get_the_title( $page ),
			'edit_url' => get_edit_post_link( $page->ID, 'raw' ),
			'view_url' => get_permalink( $page->ID ),
		];
	}

	/**
	 * What WordPress currently treats as the site privacy policy.
	 *
	 * @since 1.5.0
	 * @return array<string, mixed>
	 */
	private function wp_privacy_page_state(): array {
		$state = [
			'id'                 => 0,
			'status'             => '',
			'title'              => '',
			'is_ours'            => false,
			'has_content'        => false,
			'needs_confirmation' => false,
		];

		$core_id = absint( get_option( 'wp_page_for_privacy_policy' ) );

		if ( $core_id <= 0 ) {
			return $state;
		}

		$page = get_post( $core_id );

		if ( ! $page instanceof WP_Post ) {
			return $state;
		}

		// "Untouched" means core's own generated draft that nobody reviewed or
		// edited: safe to repoint without asking.
		$reviewed = ! empty( get_post_meta( $core_id, '_wp_suggested_privacy_policy_content', true ) );
		$edited   = ! empty( get_post_meta( $core_id, '_edit_last', true ) );

		$state['id']          = $core_id;
		$state['status']      = $page->post_status;
		$state['title']       = get_the_title( $page );
		$state['is_ours']     = $core_id === absint( Settings::get( self::PAGE_SETTING ) );
		$state['has_content'] = trim( (string) $page->post_content ) !== '';

		$state['needs_confirmation'] = ! $state['is_ours']
			&& ( $page->post_status === 'publish' || $reviewed || $edited );

		return $state;
	}
}
