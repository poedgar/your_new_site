<?php
/**
 * Cache and optimization plugin compatibility.
 *
 * Consent assets are exempt from the usual "optimise everything" treatment for
 * two reasons a cache plugin cannot infer:
 *
 *  1. The banner is rendered client-side into an EMPTY `#surecookie-public-root`,
 *     so Unused/Critical CSS passes scan the HTML, find no `.surecookie-*`
 *     markup, and strip the banner's styles as dead.
 *  2. Delaying the banner script until user interaction defeats the point of a
 *     consent banner, which must run before the trackers it gates.
 *
 * REST reads are also marked uncacheable: an edge that caches them serves the
 * admin its pre-save settings, which reads as a save silently reverting. So is
 * a scan's render, which is unblocked and grants Consent Mode.
 *
 * @package SureCookie\Inc\Integrations\Cache
 * @since 1.4.0
 */

namespace SureCookie\Inc\Integrations\Cache;

use SureCookie\Inc\Modules\ScriptBlocking\Entry_Match;
use SureCookie\Inc\Modules\ScriptBlocking\Utils as Blocking_Utils;
use SureCookie\Inc\Modules\Services\Pattern_Kinds;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Init class.
 *
 * @since 1.4.0
 */
class Init {
	use GetInstance;

	/**
	 * Asset handle that must reach the browser untouched. The public script and
	 * stylesheet share it.
	 *
	 * @since 1.4.0
	 */
	private const HANDLES = [ 'surecookie-public' ];

	/**
	 * Path fragments identifying those assets, for plugins whose exclusion
	 * lists match on URL rather than handle.
	 *
	 * @since 1.4.0
	 */
	private const PATHS = [ 'surecookie/build/public', 'surecookie/assets/css/' ];

	/**
	 * CSS selectors covering everything the banner renders, for optimisers whose
	 * allowlists match selectors rather than files. Every rule we ship is
	 * namespaced, so the two prefixes below cover all of them.
	 *
	 * @since 1.4.0
	 */
	private const SELECTORS = [ '#surecookie-public-root', '#surecookie-preferences-root', '.surecookie-*' ];

	/**
	 * Opt-out attributes for our own assets.
	 *
	 * @since 1.4.0
	 */
	private const ATTRIBUTES = 'data-no-optimize="1" data-no-defer="1" data-no-delay="1" data-no-minify="1" data-cfasync="false"';

	/**
	 * Opt-out attributes for the core scripts we depend on. They stay minifiable,
	 * but not combinable: see dependency_paths() for why.
	 *
	 * @since 1.4.0
	 */
	private const DEPENDENCY_ATTRIBUTES = 'data-no-optimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false"';

	/**
	 * The same opt-outs as ATTRIBUTES, keyed for `wp_inline_script_attributes`.
	 *
	 * @since 1.5.0
	 */
	private const INLINE_ATTRIBUTES = [
		'data-no-optimize' => '1',
		'data-no-defer'    => '1',
		'data-no-delay'    => '1',
		'data-no-minify'   => '1',
		'data-cfasync'     => 'false',
	];

	/**
	 * Exclusion filters taking an array of URL path fragments.
	 *
	 * @since 1.4.0
	 */
	private const FILTERS_PATHS = [
		'litespeed_optimize_js_excludes',
		'litespeed_optimize_css_excludes',
		'litespeed_optm_js_defer_exc',
		'litespeed_optm_gm_js_exc',
		'rocket_exclude_js',
		'rocket_exclude_css',
		'rocket_exclude_defer_js',
		'rocket_delay_js_exclusions',
		'rocket_minify_excluded_external_js',
		'rocket_excluded_inline_js_content',
		'perfmatters_delay_js_exclusions',
		'perfmatters_defer_js_exclusions',
		'flying_press_exclude_from_delay_js',
		'flying_press_exclude_from_defer_js',
		'wphb_delay_js_exclusions',
		// With aggregation on, Autoptimize still minifies an excluded file under a new name.
		'autoptimize_filter_js_consider_minified',
		'autoptimize_filter_css_consider_minified',
	];

	/**
	 * Exclusion filters taking an array of registered asset handles.
	 *
	 * SiteGround compares with `in_array( $handle, ... )`, so passing paths here
	 * silently matches nothing.
	 *
	 * @since 1.4.0
	 */
	private const FILTERS_HANDLES = [
		'sgo_javascript_combine_exclude',
		'sgo_js_minify_exclude',
		'sgo_css_combine_exclude',
		'sgo_css_minify_exclude',
	];

	/**
	 * Exclusion filters taking an array of CSS selectors.
	 *
	 * @since 1.4.0
	 */
	private const FILTERS_SELECTORS = [
		'litespeed_ucss_whitelist',
		'litespeed_ccss_whitelist',
	];

	/**
	 * Exclusion filters taking a comma-separated string of path fragments.
	 *
	 * @since 1.4.0
	 */
	private const FILTERS_CSV = [
		'autoptimize_filter_js_exclude',
		'autoptimize_filter_css_exclude',
	];

	/**
	 * Exclusion filters asked once per resource, wanting a boolean back.
	 *
	 * W3 Total Cache reads none of the opt-out attributes above, so its own
	 * filter is the only lever it offers.
	 *
	 * @since 1.6.0
	 */
	private const FILTERS_PER_FILE = [
		'w3tc_minify_js_do_tag_minification',
		'w3tc_minify_css_do_tag_minification',
	];

	/**
	 * Headers that keep a response out of the browser, page cache and CDN.
	 *
	 * @since 1.6.1
	 */
	private const NO_STORE_HEADERS = [
		'Cache-Control'     => 'no-store, no-cache, must-revalidate, max-age=0',
		'Pragma'            => 'no-cache',
		// Cloudflare and other edges honour this even under a cache-everything rule.
		'CDN-Cache-Control' => 'no-store',
	];

	/**
	 * Page caches that clear via an action.
	 *
	 * @since 1.4.0
	 */
	private const PURGE_ACTIONS = [
		'litespeed_purge_all',
		'cache_enabler_clear_complete_cache',
		'sg_cachepress_purge_cache',
		'breeze_clear_all_cache',
		'wphb_clear_page_cache',
		'swift_performance_clear_all_cache',
		'wpo_cache_flush',
		'nitropack_sdk_purge_all',
		'flying_press_purge_everything',
	];

	/**
	 * Page caches that clear via a function.
	 *
	 * Typed loosely on purpose: these belong to plugins that may not be
	 * installed, so they must stay unresolved names rather than known symbols.
	 *
	 * @since 1.4.0
	 */
	private const PURGE_FUNCTIONS = [
		'rocket_clean_domain',
		'w3tc_flush_all',
		'wp_cache_clear_cache',
		'wpfc_clear_all_cache',
	];

	/**
	 * Handles to protect. Defaults kept as the property default so the class is
	 * usable without running the constructor.
	 *
	 * @var array<int, string>
	 * @since 1.4.0
	 */
	private $handles = self::HANDLES;

	/**
	 * Path fragments to protect.
	 *
	 * @var array<int, string>
	 * @since 1.4.0
	 */
	private $paths = self::PATHS;

	/**
	 * Resolved dependency handles, cached for the request. Null until resolved.
	 *
	 * @var array<int, string>|null
	 * @since 1.4.0
	 */
	private $dependencies = null;

	/**
	 * Resolved dependency URL paths, cached for the request.
	 *
	 * @var array<int, string>|null
	 * @since 1.4.0
	 */
	private $dependency_paths = null;

	/**
	 * Resolved first-party blocking patterns, cached for the request.
	 *
	 * @var array<int, string>|null
	 * @since 1.6.1
	 */
	private $tracker_paths = null;

	/**
	 * Resolved third-party blocking patterns, cached for the request.
	 *
	 * @var array<int, string>|null
	 * @since 1.6.1
	 */
	private $external_tracker_fragments = null;

	/**
	 * Constructor.
	 *
	 * @since 1.4.0
	 */
	private function __construct() {
		/**
		 * Filters the asset handles kept out of cache-plugin optimisation.
		 *
		 * @since 1.4.0
		 * @param array<int, string> $handles Registered script/style handles.
		 */
		$this->handles = (array) apply_filters( 'surecookie_cache_asset_handles', self::HANDLES );

		/**
		 * Filters the asset path fragments kept out of cache-plugin optimisation.
		 *
		 * @since 1.4.0
		 * @param array<int, string> $paths URL fragments identifying those assets.
		 */
		$this->paths = (array) apply_filters( 'surecookie_cache_asset_paths', self::PATHS );

		add_filter( 'script_loader_tag', [ $this, 'mark_script' ], 20, 2 );
		add_filter( 'wp_inline_script_attributes', [ $this, 'mark_inline_script' ], 20 );
		add_filter( 'style_loader_tag', [ $this, 'mark_style' ], 20, 2 );
		add_filter( 'rest_post_dispatch', [ $this, 'no_store_rest' ], 10, 3 );
		add_filter( 'wp_headers', [ $this, 'no_store_scan' ], PHP_INT_MAX );

		add_action( 'surecookie_admin_settings_after_processing', [ $this, 'purge' ] );

		$this->register_exclusions();
	}

	/**
	 * Keep the banner script out of defer/delay/combine passes.
	 *
	 * `data-cfasync` covers Cloudflare Rocket Loader, the `data-no-*` trio is
	 * honoured by LiteSpeed, WP Rocket, Perfmatters and SG Optimizer.
	 *
	 * @param mixed $tag    Script tag HTML.
	 * @param mixed $handle Script handle.
	 * @since 1.4.0
	 * @return mixed
	 */
	public function mark_script( $tag = null, $handle = null ) {
		if ( ! is_string( $tag ) ) {
			return $tag;
		}

		if ( in_array( $handle, $this->handles, true ) ) {
			return $this->add_attributes( $tag, '<script ' );
		}

		// A dependency that runs late runs after us, and the banner bundle calls
		// into it at parse time. Excluding ourselves from delay while leaving
		// wp-i18n delayed is what produces "Cannot read properties of undefined".
		if ( is_string( $handle ) && in_array( $handle, $this->dependency_handles(), true ) ) {
			// Marker is the combine opt-out, not one of the ordering ones: those
			// are commonly added by other compat layers, and matching on one would
			// skip the very attribute this branch exists to add.
			return $this->add_attributes( $tag, '<script ', self::DEPENDENCY_ATTRIBUTES );
		}

		return $tag;
	}

	/**
	 * Arm our inline scripts like the tags they belong to.
	 *
	 * `script_loader_tag` never sees these; core prints localized data through
	 * `wp_get_inline_script_tag()`. Arming the bundle alone is worse than arming
	 * neither: Rocket Loader then defers the settings the banner reads once at mount.
	 *
	 * @param mixed $attributes Inline script attributes.
	 * @since 1.5.0
	 * @return mixed
	 */
	public function mark_inline_script( $attributes = null ) {
		if ( ! is_array( $attributes ) ) {
			return $attributes;
		}

		$handle = $this->inline_owner( $attributes['id'] ?? '' );

		// '' means "not one of ours", so it must never be looked up: one falsy
		// entry anywhere in the graph would otherwise match every inline script on
		// the page, core's and every third party's, and silently opt them all out.
		if ( $handle === '' ) {
			return $attributes;
		}

		if ( in_array( $handle, $this->handles, true ) ) {
			return array_merge( $attributes, self::INLINE_ATTRIBUTES );
		}

		if ( in_array( $handle, $this->dependency_handles(), true ) ) {
			return array_merge( $attributes, array_diff_key( self::INLINE_ATTRIBUTES, [ 'data-no-minify' => '' ] ) );
		}

		return $attributes;
	}

	/**
	 * Keep the banner stylesheet out of combine/UCSS passes.
	 *
	 * @param mixed $tag    Style tag HTML.
	 * @param mixed $handle Style handle.
	 * @since 1.4.0
	 * @return mixed
	 */
	public function mark_style( $tag = null, $handle = null ) {
		if ( ! is_string( $tag ) || ! in_array( $handle, $this->handles, true ) ) {
			return $tag;
		}

		return $this->add_attributes( $tag, '<link ' );
	}

	/**
	 * Tell every layer not to store a SureCookie REST response.
	 *
	 * WordPress core sends `no-cache` for logged-in REST requests, which an
	 * edge may still store; `no-store` is the directive that reliably keeps it
	 * out. Scoped to this plugin's namespace so no other route is affected.
	 *
	 * @param mixed $result  Response, passed through untouched.
	 * @param mixed $server  REST server.
	 * @param mixed $request Current request.
	 * @since 1.4.0
	 * @return mixed
	 */
	public function no_store_rest( $result = null, $server = null, $request = null ) {
		if ( ! is_object( $result ) || ! method_exists( $result, 'header' ) ) {
			return $result;
		}

		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}

		if ( strpos( (string) $request->get_route(), '/surecookie/' ) !== 0 ) {
			return $result;
		}

		// Honoured by W3TC, WP Super Cache, LiteSpeed, Batcache, SG and Breeze:
		// the nearest thing to a cross-plugin "never cache this" standard.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		foreach ( self::NO_STORE_HEADERS as $name => $value ) {
			$result->header( $name, $value );
		}

		return $result;
	}

	/**
	 * Keep a scan's render out of every cache.
	 *
	 * The bypass rides a request header no page cache varies on, so a stored copy
	 * would serve visitors unparked trackers and granted Consent Mode (#1236).
	 *
	 * @param mixed $headers Response headers from `wp_headers`.
	 * @since 1.6.1
	 * @return mixed
	 */
	public function no_store_scan( $headers = null ) {
		if ( ! is_array( $headers ) || ! ( Blocking_Utils::is_scan_bypass_request() || Blocking_Utils::is_scan_probe() ) ) {
			return $headers;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		return array_merge( $headers, self::NO_STORE_HEADERS );
	}

	/**
	 * Purge page caches after settings are saved.
	 *
	 * Public settings are localised into the page HTML, so a cached page keeps
	 * serving the previous banner configuration until its TTL expires.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public function purge(): void {
		foreach ( self::PURGE_ACTIONS as $hook ) {
			do_action( $hook );
		}

		/**
		 * Filters the cache-clearing functions called after a settings save.
		 *
		 * @since 1.4.0
		 * @param array<int, string> $functions Function names to call when defined.
		 */
		$functions = apply_filters( 'surecookie_cache_purge_functions', self::PURGE_FUNCTIONS );

		foreach ( (array) $functions as $function ) {
			if ( is_string( $function ) && is_callable( $function ) ) {
				call_user_func( $function );
			}
		}

		// Autoptimize. Resolved through a variable so the optional class is not
		// treated as a known symbol at analysis time.
		$autoptimize = '\autoptimizeCache';
		if ( class_exists( $autoptimize ) && is_callable( [ $autoptimize, 'clearall' ] ) ) {
			call_user_func( [ $autoptimize, 'clearall' ] );
		}

		// Deliberately no wp_cache_flush(): that empties the whole site's object
		// cache, and update_option() has already invalidated our own entry.
		// Page caches are handled above.

		/**
		 * Fires after SureCookie has asked the known caches to purge, for hosts
		 * and plugins not covered above.
		 *
		 * @since 1.4.0
		 */
		do_action( 'surecookie_purge_caches' );
	}

	/**
	 * Handle an inline script id belongs to, or '' when it is not one of ours.
	 *
	 * @param mixed $id Inline script id, e.g. `surecookie-public-js-extra`.
	 * @return string
	 */
	private function inline_owner( $id ): string {
		$id = (string) $id;

		foreach ( [ '-js-extra', '-js-before', '-js-after' ] as $suffix ) {
			if ( str_ends_with( $id, $suffix ) ) {
				return substr( $id, 0, - strlen( $suffix ) );
			}
		}

		return '';
	}

	/**
	 * Every handle the protected bundles depend on, resolved transitively.
	 *
	 * Resolved lazily: nothing is registered yet when this class is constructed.
	 *
	 * @since 1.4.0
	 * @return array<int, string>
	 */
	private function dependency_handles(): array {
		if ( ! empty( $this->dependencies ) ) {
			return $this->dependencies;
		}

		// Deliberately not memoizing an empty result. These filters are applied
		// by each cache plugin on its own schedule, and one that asks before
		// `wp_enqueue_scripts` would otherwise pin an empty graph for the rest of
		// the request - leaving every dependency unprotected with no symptom.
		$this->dependencies = [];

		if ( ! function_exists( 'wp_scripts' ) ) {
			return $this->dependencies;
		}

		$registered = wp_scripts()->registered;
		$queue      = $this->handles;
		$seen       = array_fill_keys( $this->handles, true );

		while ( $queue ) {
			$handle = (string) array_shift( $queue );

			$deps = isset( $registered[ $handle ] ) ? (array) $registered[ $handle ]->deps : [];

			foreach ( $deps as $dep ) {
				$dep = (string) $dep;

				if ( isset( $seen[ $dep ] ) ) {
					continue;
				}

				$seen[ $dep ]         = true;
				$queue[]              = $dep;
				$this->dependencies[] = $dep;
			}
		}

		return $this->dependencies;
	}

	/**
	 * URL paths for every dependency, so they leave the combine passes our own
	 * bundle leaves.
	 *
	 * Combining a dependency only preserves order while the script needing it is
	 * combined too, and ours never is.
	 *
	 * @since 1.4.0
	 * @return array<int, string>
	 */
	private function dependency_paths(): array {
		if ( ! empty( $this->dependency_paths ) ) {
			return $this->dependency_paths;
		}

		if ( ! function_exists( 'wp_scripts' ) ) {
			return [];
		}

		$registered = wp_scripts()->registered;
		$paths      = [];

		foreach ( $this->dependency_handles() as $handle ) {
			$src = isset( $registered[ $handle ] ) ? $registered[ $handle ]->src : '';

			if ( ! is_string( $src ) || $src === '' ) {
				continue;
			}

			// Path only: exclusion lists are matched against the tag's src, and a
			// stored absolute URL would miss a site served on the other scheme or
			// on the www / non-www variant.
			$path = (string) wp_parse_url( $src, PHP_URL_PATH );

			if ( $path !== '' ) {
				$paths[] = $path;
			}
		}

		$this->dependency_paths = array_values( array_unique( $paths ) );

		return $this->dependency_paths;
	}

	/**
	 * First-party blocking patterns, as the fragments exclusion lists match on.
	 *
	 * An optimiser that rewrites the page before the blocker, or renames a local file
	 * as it prints, hands the blocker a tracker URL its pattern no longer matches.
	 * Reached from those buffers' display handlers, as the blocker's read is.
	 *
	 * @since 1.6.1
	 * @return array<int, string>
	 */
	private function tracker_paths(): array {
		if ( ! empty( $this->tracker_paths ) ) {
			return $this->tracker_paths;
		}

		$catalog  = apply_filters( 'surecookie_known_scripts', [] );
		$site     = Entry_Match::without_www( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$patterns = [];

		foreach ( is_array( $catalog ) ? $catalog : [] as $services ) {
			foreach ( is_array( $services ) ? $services : [] as $service ) {
				foreach ( array_keys( Pattern_Kinds::enforced() ) as $bucket ) {
					$patterns[] = is_array( $service ) ? (array) ( $service[ $bucket ] ?? [] ) : [];
				}
			}
		}

		$paths = [];

		foreach ( array_filter( array_merge( [], ...$patterns ), 'is_string' ) as $pattern ) {
			$parts = Entry_Match::parts( $pattern );

			// Another host stays out here (WP Rocket's copy of an external script is
			// external_tracker_fragments()), and a bare site host has no path: as a
			// fragment it would exclude every file on the site.
			if ( ( $parts['host'] !== '' && Entry_Match::without_www( $parts['host'] ) !== $site ) || trim( $parts['path'], '/' ) === '' ) {
				continue;
			}

			// WP Rocket joins these lists into one regex, where a single stray `(`
			// voids every exclusion, the banner's included.
			if ( preg_match( '#^[\w.~/-]+$#', $parts['path'] ) ) {
				$paths[] = $parts['path'];
			}
		}

		$this->tracker_paths = array_values( array_unique( $paths ) );

		return $this->tracker_paths;
	}

	/**
	 * Third-party script patterns, as the substrings WP Rocket's external-JS
	 * exclusion list matches on.
	 *
	 * WP Rocket copies an external script under its own cache path when it
	 * minifies it, so plausible.io/js/script.js reaches the blocker as
	 * /wp-content/cache/min/1/js/script.js, matches no pattern and runs before
	 * consent. Kept at its real URL, the host pattern still catches it.
	 *
	 * @since 1.6.1
	 * @return array<int, string>
	 */
	private function external_tracker_fragments(): array {
		if ( $this->external_tracker_fragments !== null ) {
			return $this->external_tracker_fragments;
		}

		$catalog   = apply_filters( 'surecookie_known_scripts', [] );
		$site      = Entry_Match::without_www( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$fragments = [];

		foreach ( is_array( $catalog ) ? $catalog : [] as $services ) {
			foreach ( is_array( $services ) ? $services : [] as $service ) {
				foreach ( is_array( $service ) ? (array) ( $service['scripts'] ?? [] ) : [] as $pattern ) {
					$pattern = is_string( $pattern ) ? strtolower( trim( $pattern ) ) : '';
					$host    = Entry_Match::without_www( Entry_Match::parts( $pattern )['host'] );

					if ( $pattern !== '' && $host !== $site && preg_match( '#^[\w.~/-]+$#', $pattern ) ) {
						$fragments[] = $pattern;
					}
				}
			}
		}

		$this->external_tracker_fragments = array_values( array_unique( $fragments ) );

		return $this->external_tracker_fragments;
	}

	/**
	 * Registered handles whose src a first-party blocking pattern matches, for
	 * the lists that exclude by handle.
	 *
	 * @since 1.6.1
	 * @return array<int, string>
	 */
	private function tracker_handles(): array {
		$paths = $this->tracker_paths();

		if ( $paths === [] || ! function_exists( 'wp_scripts' ) ) {
			return [];
		}

		$handles = [];

		foreach ( wp_scripts()->registered as $handle => $script ) {
			$src = is_string( $script->src ) ? $script->src : '';

			foreach ( $paths as $path ) {
				if ( $src !== '' && stripos( $src, $path ) !== false ) {
					$handles[] = (string) $handle;
					break;
				}
			}
		}

		return $handles;
	}

	/**
	 * Add the opt-out attributes to a tag, if not already present.
	 *
	 * @param string $tag        Tag HTML.
	 * @param string $needle     Opening tag to inject after.
	 * @param string $attributes Attributes to inject.
	 * @param string $marker     Attribute proving the tag was already marked.
	 * @since 1.4.0
	 * @return string
	 */
	private function add_attributes( string $tag, string $needle, string $attributes = self::ATTRIBUTES, string $marker = 'data-no-optimize' ): string {
		$opener = rtrim( $needle );

		// Match whole elements, not bare opening tags. One `script_loader_tag`
		// value carries the element and its localized payloads in either order, so
		// a payload already marked by mark_inline_script() must not veto the
		// element beside it. Stepping over each body is what stops a literal
		// `<script` inside a payload reading as another opening tag; `link` is void
		// and has no body to step over.
		$body = $opener === '<script' ? '(?:.*?<\\/script>)?' : '';
		return (string) preg_replace_callback(
			'/' . preg_quote( $opener, '/' ) . '(?=[\s>])[^>]*>' . $body . '/is',
			static function ( array $match ) use ( $opener, $attributes, $marker ): string {
				$end  = (int) strpos( $match[0], '>' );
				$open = substr( $match[0], 0, $end + 1 );

				if ( strpos( $open, $marker ) !== false ) {
					return $match[0];
				}

				return $opener . ' ' . $attributes . ' '
					. ltrim( substr( $open, strlen( $opener ) ) )
					. substr( $match[0], $end + 1 );
			},
			$tag
		);
	}

	/**
	 * Register this plugin's assets, and the first-party trackers it blocks, with
	 * every known exclusion list.
	 *
	 * WordPress has no core API for "leave this asset alone", so each plugin
	 * exposes its own filter. They differ in the shape they expect - paths,
	 * handles, selectors, CSV or a per-resource boolean - so each shape gets its
	 * own table. Passing the wrong shape fails silently, so add a filter only to
	 * a table whose shape has been checked against that plugin's source.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	private function register_exclusions(): void {
		// Resolved when the filter fires, not now: this runs from the constructor,
		// where no script is registered yet and the dependency graph is empty.
		$self    = $this;
		$paths   = static function () use ( $self ) {
			return array_merge( $self->paths, $self->dependency_paths(), $self->tracker_paths() );
		};
		$handles = static function () use ( $self ) {
			return array_merge( $self->handles, $self->dependency_handles(), $self->tracker_handles() );
		};

		$append = static function ( callable $values ) {
			return static function ( $list ) use ( $values ) {
				return array_merge( is_array( $list ) ? $list : [], $values() );
			};
		};

		foreach ( self::FILTERS_PATHS as $filter ) {
			add_filter( $filter, $append( $paths ) );
		}

		foreach ( self::FILTERS_HANDLES as $filter ) {
			add_filter( $filter, $append( $handles ) );
		}

		add_filter(
			'rocket_minify_excluded_external_js',
			$append(
				static function () use ( $self ) {
					return $self->external_tracker_fragments();
				}
			)
		);

		// WP Rocket's Remove Unused CSS drops what the HTML does not use, and the banner mounts
		// later, so it lost its z-index tokens (#1297). Keep our own tags whole, nothing else.
		add_filter(
			'rocket_rucss_external_exclusions',
			$append(
				static function () use ( $self ) {
					return $self->paths;
				}
			)
		);
		add_filter(
			'rocket_rucss_inline_atts_exclusions',
			$append(
				static function () use ( $self ) {
					return array_map(
						static function ( $handle ) {
							return $handle . '-inline-css';
						},
						$self->handles
					);
				}
			)
		);

		$selectors = static function () {
			return self::SELECTORS;
		};

		foreach ( self::FILTERS_SELECTORS as $filter ) {
			add_filter( $filter, $append( $selectors ) );
		}

		foreach ( self::FILTERS_CSV as $filter ) {
			add_filter(
				$filter,
				static function ( $list ) use ( $paths ) {
					$existing = is_string( $list ) && $list !== '' ? $list . ',' : '';
					return $existing . implode( ',', $paths() );
				}
			);
		}

		// W3TC hands us its own file identity: docroot-relative, or the full URL
		// when the resource is external. Neither carries the leading slash a
		// dependency path has, and an empty needle would match every file on the
		// site, so both are handled before the comparison.
		foreach ( self::FILTERS_PER_FILE as $filter ) {
			add_filter(
				$filter,
				static function ( $minify, $tag = '', $file = '' ) use ( $paths ) {
					if ( ! $minify || ! is_string( $file ) ) {
						return $minify;
					}

					// W3TC's src/href regexes read a parked tag's data-surecookie-src/-href as live and would bundle it pre-consent.
					if ( is_string( $tag ) && strpos( $tag, 'data-surecookie-category' ) !== false ) {
						return false;
					}

					foreach ( $paths() as $path ) {
						$needle = ltrim( (string) $path, '/' );

						if ( $needle !== '' && strpos( $file, $needle ) !== false ) {
							return false;
						}
					}

					return $minify;
				},
				10,
				3
			);
		}

		// Hummingbird asks once per resource and wants a boolean back. Trackers are
		// matched on the URL it passes, so no per-handle scan of every registered script.
		foreach ( [ 'wphb_minify_resource', 'wphb_combine_resource' ] as $filter ) {
			add_filter(
				$filter,
				static function ( $value, $handle = '', $type = '', $url = '' ) use ( $self ) {
					foreach ( is_string( $url ) && $url !== '' ? $self->tracker_paths() : [] as $path ) {
						if ( stripos( $url, $path ) !== false ) {
							return false;
						}
					}

					return $value;
				},
				10,
				4
			);
		}

		add_filter(
			'wphb_dont_combine_handles',
			static function ( $skip, $handle = '' ) use ( $self ) {
				return $skip || in_array( $handle, array_merge( $self->handles, $self->dependency_handles() ), true );
			},
			10,
			2
		);
	}
}
