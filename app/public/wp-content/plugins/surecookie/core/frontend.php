<?php
/**
 * App handler for SureCookie plugin.
 *
 * @package    SureCookie
 * @subpackage SureCookie/Core
 * @since      0.0.1
 */

namespace SureCookie\Core;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Functions\Validate;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\LocalizeData;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend handler for SureCookie plugin.
 *
 * This class handles the registration and rendering of the admin menu.
 *
 * @since 0.0.1
 */
class Frontend {
	use GetInstance;

	/**
	 * Whether the banner mount point has been printed for this request.
	 *
	 * @var bool
	 */
	private bool $root_printed = false;

	/**
	 * Shared core handles opted into `defer` so the banner's script chain can be deferred.
	 */
	private const SHARED_DEFER_HANDLES = [ 'react', 'react-dom', 'react-jsx-runtime', 'wp-element', 'wp-escape-html', 'wp-hooks' ];

	/**
	 * Each script's dependencies as of the head print, when WordPress caches who depends on what.
	 *
	 * @var array<string, array<int|string, string>>
	 */
	private array $head_deps = [];

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_public_assets' ] );

		// wp_body_open puts the banner root at the top of the body, which is
		// better for accessibility. function_exists() only proves WordPress
		// supports the hook, not that the running template calls it - a plugin
		// owning a post type's template may not - so register both and let the
		// footer pass no-op once the root is out.
		add_action( 'wp_body_open', [ $this, 'add_public_root' ], 1 );
		add_action( 'wp_footer', [ $this, 'add_public_root' ] );
	}

	/**
	 * Print the banner mount point, once per request.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function add_public_root(): void {
		// No mount point where nothing mounts, or a builder canvas keeps a stray
		// div for a bundle we deliberately did not enqueue.
		if ( $this->root_printed || ! $this->should_enqueue_public_assets() ) {
			return;
		}

		$this->root_printed = true;

		echo '<div id="surecookie-public-root"></div>';
	}

	/**
	 * Enqueue public assets.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function enqueue_public_assets(): void {
		if ( ! $this->should_enqueue_public_assets() ) {
			return;
		}

		// Check if script file exists before enqueuing.
		$base_directory = plugin_dir_path( SURECOOKIE_FILE ) . 'build/';
		$assets_file    = $base_directory . 'public.asset.php';
		if ( ! file_exists( $assets_file ) ) {
			return;
		}
		$assets_info = include $assets_file;

		// Must stay a plain render-blocking link. A media="print" + onload swap reads as
		// removable to cache plugins: WP Fastest Cache drops the tag entirely, leaving the
		// banner unstyled.
		wp_enqueue_style(
			'surecookie-public',
			SURECOOKIE_URL . 'assets/css/' . Get::shared_style_css(),
			[],
			SURECOOKIE_VERSION
		);

		// Add palette CSS variables from stored option.
		$palette_css = Sanitize::stylesheet( Get::palette_root_css() );
		if ( ! empty( $palette_css ) ) {
			wp_add_inline_style( 'surecookie-public', wp_strip_all_tags( $palette_css ) );
		}

		// Add custom CSS if available.
		$custom_css = Sanitize::stylesheet( (string) Settings::get( 'custom_css' ) );
		if ( Validate::not_empty( $custom_css ) ) {
			wp_add_inline_style( 'surecookie-public', "/* SureCookie Custom CSS - Public */\n" . wp_strip_all_tags( $custom_css ) );
		}

		/**
		 * Enqueue or register additional frontend scripts before the main public script.
		 *
		 * @param array<string, mixed> $assets_info Build asset metadata for surecookie-public.
		 */
		do_action( 'surecookie_public_enqueue_scripts', $assets_info );

		$public_dependencies = apply_filters( 'surecookie_public_script_dependencies', $assets_info['dependencies'] ?? [] );
		$public_dependencies = is_array( $public_dependencies ) ? $public_dependencies : ( $assets_info['dependencies'] ?? [] );

		// Enqueue the JavaScript file. Never use 'async' here: the pro/third-party
		// filter scripts are dependencies of this handle and must execute first, which 'defer' preserves (DOM order) and 'async' does not.
		wp_enqueue_script(
			'surecookie-public',
			SURECOOKIE_URL . 'build/public.js',
			$public_dependencies,
			$assets_info['version'] ?? SURECOOKIE_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		// A script chain only gets `defer` when every handle in it declares the intent, so opt the shared core dependencies in as well. WordPress
		// auto-downgrades any of them to blocking if another plugin enqueues a non-deferred dependent, but only for dependents registered before it
		// first looks them up; release_shared_handles_for_late_scripts() covers the rest.
		// wp-i18n is deliberately excluded: wp_set_script_translations() injects a parse-time inline script that requires the wp.i18n global. Since core
		// wp-i18n depends on wp-hooks, wp-hooks is auto-downgraded to blocking for now - the intent below starts working once wp-i18n leaves the chain.
		foreach ( self::SHARED_DEFER_HANDLES as $core_handle ) {
			if ( wp_script_is( $core_handle, 'registered' ) ) {
				wp_script_add_data( $core_handle, 'strategy', 'defer' );
			}
		}
		add_action( 'wp_head', [ $this, 'remember_head_scripts' ], 8 );
		add_action( 'wp_print_footer_scripts', [ $this, 'release_shared_handles_for_late_scripts' ], 9 );
		wp_set_script_translations( 'surecookie-public', 'surecookie', SURECOOKIE_DIR . 'languages' );

		// Localize frontend data using utility.
		LocalizeData::localize_frontend_script( 'surecookie-public' );
	}

	/**
	 * Note the registered scripts just before WordPress prints the head.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function remember_head_scripts(): void {
		$this->head_deps = array_map( static fn( $script ) => (array) $script->deps, wp_scripts()->registered );
	}

	/**
	 * Return shared handles to blocking when a script registered after the head needs them.
	 *
	 * WordPress never refreshes the dependents it cached for the head, so a script a shortcode or block registers during content
	 * (the Powerful Docs search) escapes its auto-downgrade and runs before the deferred React it reads from a global.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function release_shared_handles_for_late_scripts(): void {
		$scripts = wp_scripts();
		$deps    = [];

		foreach ( $scripts->registered as $handle => $script ) {
			$deps[ $handle ] = (array) $script->deps;
		}

		// Mirrors WordPress: a script is blocking without a delay strategy, and any inline script printed after it forces that too.
		$blocking = array_filter(
			self::handles_reachable( $deps, (array) $scripts->queue ),
			fn( $handle ) => ( $this->head_deps[ $handle ] ?? null ) !== $deps[ $handle ]
				&& ! in_array( $handle, (array) $scripts->done, true )
				&& ( $scripts->get_data( $handle, 'after' ) || ( ! empty( $scripts->registered[ $handle ]->src ) && ! $scripts->get_data( $handle, 'strategy' ) ) )
		);

		foreach ( array_intersect( self::SHARED_DEFER_HANDLES, self::handles_reachable( $deps, $blocking ) ) as $handle ) {
			wp_script_add_data( $handle, 'strategy', false );
		}
	}

	/**
	 * Every handle reachable from the roots through their dependencies, roots included.
	 *
	 * @since 1.7.0
	 * @param array<string, array<int|string, string>> $deps  Handle => direct dependencies, for every registered script.
	 * @param array<int, string>                       $roots Handles to start from.
	 * @return array<int, string>
	 */
	public static function handles_reachable( array $deps, array $roots ): array {
		$seen  = [];
		$stack = array_values( $roots );

		while ( $stack ) {
			$handle = array_pop( $stack );
			if ( isset( $seen[ $handle ] ) || ! isset( $deps[ $handle ] ) ) {
				continue;
			}
			$seen[ $handle ] = true;
			foreach ( $deps[ $handle ] as $dep ) {
				$stack[] = $dep;
			}
		}

		return array_keys( $seen );
	}

	/**
	 * Whether the public frontend assets should be enqueued for this request.
	 *
	 * Keeps the bundle loading when the banner is globally disabled but a
	 * re-consent entry point is still active on the page.
	 *
	 * @since 1.2.4
	 * @return bool
	 */
	public function should_enqueue_public_assets(): bool {
		if ( is_admin() ) {
			return false;
		}

		// A builder canvas is not a visitor view: blocking already stands aside
		// there, so mounting the banner over it only breaks the editor.
		if ( Helper::is_builder_edit_render() ) {
			return false;
		}

		if ( Settings::get( 'banner_enabled' ) ) {
			return true;
		}

		return $this->has_active_reconsent_surface();
	}

	/**
	 * Whether the current request exposes any re-consent entry point.
	 *
	 * @since 1.2.4
	 * @return bool
	 */
	private function has_active_reconsent_surface(): bool {
		if ( Validate::not_empty( Settings::get( 'reconsent_menu_id' ) ) ) {
			return true;
		}

		if ( Settings::get( 'floating_widget_enabled' ) === true ) {
			return true;
		}

		return $this->current_page_has_reconsent_shortcode();
	}

	/**
	 * Whether the current page content contains the re-consent shortcode.
	 *
	 * @since 1.2.4
	 * @return bool
	 */
	private function current_page_has_reconsent_shortcode(): bool {
		if ( ! function_exists( 'has_shortcode' ) ) {
			return false;
		}

		global $post, $wp_query;

		$posts = [];

		if ( isset( $post ) && is_object( $post ) ) {
			$posts[] = $post;
		}

		if ( isset( $wp_query->posts ) && is_array( $wp_query->posts ) ) {
			$posts = array_merge( $posts, $wp_query->posts );
		}

		foreach ( $posts as $maybe_post ) {
			if ( ! is_object( $maybe_post ) || empty( $maybe_post->post_content ) ) {
				continue;
			}

			if ( has_shortcode( (string) $maybe_post->post_content, 'surecookie_reconsent_button' ) ) {
				return true;
			}
		}

		return false;
	}
}
