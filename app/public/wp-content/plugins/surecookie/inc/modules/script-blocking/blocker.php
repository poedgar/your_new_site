<?php
/**
 * Script and Content Blocker.
 *
 * Modifies page output to block third-party scripts and iframes until consent.
 *
 * @package SureCookie\Inc\Modules\ScriptBlocking
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\Services\Pattern_Kinds;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Traits\PlaceholderContent;
use SureCookie\Inc\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Blocker
 *
 * Handles output buffering, script modification, and iframe placeholders.
 *
 * @since 0.0.1
 */
class Blocker {
	use GetInstance;
	use PlaceholderContent;

	/**
	 * Reserved blocking category for newly-detected trackers held by the Pro
	 * Compliance Guard. Resources in this category are blocked until an admin
	 * reviews them and are NEVER released by visitor consent. Mirrored in
	 * `src/utils/consentManager.js` (`isAllowed`) and the Pro Guard
	 * (`Guard::QUARANTINE_CATEGORY`).
	 */
	public const QUARANTINE_CATEGORY = 'quarantine';

	/**
	 * An attribute value in each of the three HTML spellings, as a branch-reset
	 * group so the value is always capture 1. `src` and `data` were read
	 * quoted-only, so a minifier's `src=https://player.vimeo.com/...` read as no
	 * URL at all and the tag shipped live.
	 *
	 * @since 1.5.0
	 */
	private const ATTR_VALUE = '(?|"([^"]*)"|\'([^\']*)\'|([^\s>]+))';

	/**
	 * The same three spellings, uncaptured, for strip-the-attribute rewrites.
	 *
	 * @since 1.5.0
	 */
	private const ATTR_VALUE_ANY = '(?:"[^"]*"|\'[^\']*\'|[^\s>]+)';

	/**
	 * Script `type` values the browser executes. Mirrors the allowlist in
	 * `src/utils/consentManager.js` so blocker output round-trips correctly.
	 */
	private const EXECUTABLE_SCRIPT_TYPES = [ 'text/javascript', 'module', 'application/javascript', 'application/ecmascript', 'text/ecmascript', 'importmap', 'speculationrules' ];

	/**
	 * Types that list other resources rather than carry tracker code, so a
	 * pattern hit inside one is always collateral. Still in
	 * EXECUTABLE_SCRIPT_TYPES, which consentManager.js validates against.
	 *
	 * @since 1.5.0
	 */
	private const MANIFEST_SCRIPT_TYPES = [ 'importmap', 'speculationrules' ];

	/**
	 * Marker comment printed at the very start of `wp_footer`, giving the
	 * buffer processor a precise footer boundary for region-constrained rules.
	 * Always stripped from the final output.
	 */
	private const FOOTER_MARKER = '<!--surecookie:footer-->';

	/**
	 * Largest `data:` script payload worth decoding for pattern matching, in bytes.
	 *
	 * @since 1.5.0
	 */
	private const MAX_DATA_URI_PAYLOAD = 262144;

	/**
	 * `<link rel>` values that reach the href host before consent, by fetching
	 * it (stylesheet and the preload family) or by opening a connection to it
	 * (preconnect, dns-prefetch) - which is the transfer the Google Fonts
	 * rulings are about, not the file.
	 *
	 * An allowlist, and it has to stay one. `canonical`, `profile` and
	 * `alternate` are on nearly every WordPress page and name a URL the browser
	 * never requests; `icon` and `manifest` do fetch, but a missing favicon
	 * reads as a broken site and no catalog service delivers one.
	 */
	private const FETCHING_LINK_RELS = [ 'stylesheet', 'preload', 'modulepreload', 'prefetch', 'prerender', 'preconnect', 'dns-prefetch' ];

	/**
	 * Memoized core asset bases as [ host, path ]. See core_bases().
	 *
	 * @var array<int, array{0: string, 1: string}>|null
	 */
	private static ?array $core_bases = null;

	/**
	 * Per-request cache of iframe-pattern lookup map (shared across
	 * block_iframes / block_embeds / block_objects).
	 *
	 * @var array<string, array{name: string, category: string, label: string, path?: string, location?: string}>|null
	 */
	private ?array $iframe_patterns_cache = null;

	/**
	 * Per-request cache of the link-pattern lookup map.
	 *
	 * @var array<string, array{name: string, category: string, label: string, location: string, path: string, src_only: bool}>|null
	 */
	private ?array $link_patterns_cache = null;

	/**
	 * Whether the processing buffer has been opened for this request.
	 *
	 * @var bool
	 */
	private bool $buffer_open = false;

	/**
	 * Memoized should_process() verdict.
	 *
	 * It fires `surecookie_scanner_request_detected`, and the buffer is now
	 * attempted on two hooks, so an unmemoized false verdict double-counted
	 * every scanner request.
	 *
	 * @var bool|null
	 */
	private ?bool $should_process = null;

	/**
	 * Memoized consent enforcement policy, independent of response type.
	 *
	 * @var bool|null
	 */
	private ?bool $should_enforce_consent = null;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Open a full-page output buffer for the resolved template so the finished
	 * HTML can be post-processed, then return the template unchanged so
	 * WordPress renders it normally.
	 *
	 * Untyped by design: this runs at PHP_INT_MAX, so every other callback on
	 * template_include has already returned. Core itself does not trust this
	 * value (see template-loader.php), and returning it untouched keeps a
	 * non-string from another plugin intact.
	 *
	 * @param mixed $template Expected string path to the resolved template.
	 * @since 0.0.1
	 * @since 1.2.2 Buffer the real template output instead of pre-rendering it,
	 *              so WordPress 7.0's on-demand block-style hoisting is preserved.
	 * @return mixed The template, untouched.
	 */
	public function intercept_template( $template = '' ) {
		if ( ! is_string( $template ) || $template === '' || ! file_exists( $template ) ) {
			return $template;
		}

		/*
		 * Open a full-page output buffer and let WordPress include the *real*
		 * template normally, then post-process the finished HTML in the buffer
		 * callback.
		 *
		 * We must NOT render the template ourselves here. Rendering the page
		 * inside this filter (and returning a blank template) runs ahead of
		 * WordPress 7.0's own template-enhancement output buffer, which is
		 * started later at the `wp_before_include_template` action and hoists
		 * on-demand block styles from the body back up into the <head>. Doing
		 * our own early render bypassed that hoisting, leaving classic-theme
		 * block/global styles stranded in the <body> in the wrong cascade order
		 * (e.g. the theme's `body .is-layout-grid{display:grid}` then overrode a
		 * block's responsive `display:flex`, collapsing multi-column layouts).
		 *
		 * By opening our buffer first and returning the real template, WordPress
		 * renders and enhances the page as usual - its buffer nests inside ours -
		 * and our callback processes the already-corrected HTML.
		 */
		$this->start_buffer();

		return $template;
	}

	/**
	 * `template_redirect` callback: open the buffer before any plugin that
	 * renders a page from this action can finish and exit.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function open_buffer(): void {
		$this->start_buffer();
	}

	/**
	 * Process the output buffer.
	 *
	 * @param string $buffer HTML content.
	 * @param int    $phase  Bitmask PHP passes to an output handler. The CLEAN bit is
	 *                       set when the buffer is being discarded rather than sent.
	 * @since 0.0.1
	 * @return string Modified HTML.
	 */
	public function process_buffer( string $buffer, int $phase = 0 ): string {
		// PHP sets the CLEAN bit when the buffer is being discarded rather than sent,
		// which is what a third party's `while ( ob_get_level() ) ob_end_clean()` does
		// to us. Our return value is thrown away in that case, so the page ships with
		// no blocking applied and nothing else records it. Core reads the same bit in
		// wp_finalize_template_enhancement_output_buffer(). See #1082.
		if ( ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) !== 0 ) {
			Logger::get_instance()->log(
				'SureCookie: the blocking buffer was discarded by another plugin before it could be sent, so this response shipped unblocked.',
				'error'
			);

			return $buffer;
		}

		// Skip empty buffers.
		if ( empty( $buffer ) ) {
			return $buffer;
		}

		// Check if this is HTML content.
		if ( ! $this->is_html( $buffer ) ) {
			return $buffer;
		}

		// Block scripts and embedded content (iframe/embed/object) if blocking is enabled.
		if ( Utils::is_blocking_enabled() ) {
			$buffer = $this->block_scripts( $buffer );
			$buffer = $this->block_iframes( $buffer );
			$buffer = $this->block_embeds( $buffer );
			$buffer = $this->block_objects( $buffer );
			$buffer = $this->block_links( $buffer );

			/**
			 * Filter the blocked page HTML, for integrations that must gate markup
			 * the tag-level passes above cannot see - a page builder that carries an
			 * embed as widget config and builds the iframe in the browser, say.
			 *
			 * Runs inside `should_process()`, so the scan bypass, geo rules and the
			 * admin/AJAX/REST guards already apply to every callback.
			 *
			 * Callbacks run inside an output-buffer display handler. PHP forbids opening
			 * a buffer there, so an `ob_start()` in a callback raises an uncatchable fatal
			 * that discards the entire response. Keep callbacks to pure string work.
			 *
			 * @since 1.4.0
			 * @param string $buffer Page HTML after the built-in blocking passes.
			 */
			$buffer = (string) apply_filters( 'surecookie_blocked_buffer', $buffer );

			// Last, so block_scripts() never sees it: the guard carries the whole
			// pattern catalog inline, which would self-match and neutralize it.
			// Injection position, not processing order, is what puts it first in
			// the finished document.
			$buffer = Dom_Guard::get_instance()->inject( $buffer );
		}

		// The footer-boundary marker is internal - never ship it.
		return str_replace( self::FOOTER_MARKER, '', $buffer );
	}

	/**
	 * Print the footer-boundary marker consumed by split_html_regions().
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function mark_footer_start(): void {
		if ( Utils::is_blocking_enabled() ) {
			echo self::FOOTER_MARKER; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static HTML comment constant.
		}
	}

	/**
	 * Core asset bases as [ host, path ] pairs, resolved once per request.
	 *
	 * Memoized: is_core_asset() runs per tag and nothing here changes mid-request.
	 * Shared with Dom_Guard so both layers agree on which host is ours.
	 *
	 * @since 1.5.0
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function core_bases(): array {
		if ( self::$core_bases !== null ) {
			return self::$core_bases;
		}

		$bases = [];
		foreach ( [ includes_url(), admin_url() ] as $base ) {
			$parts = wp_parse_url( $base );
			$parts = is_array( $parts ) ? $parts : [];
			$path  = (string) ( $parts['path'] ?? '' );

			if ( $path === '' ) {
				continue;
			}

			$bases[] = [ self::without_www( strtolower( (string) ( $parts['host'] ?? '' ) ) ), $path ];
		}

		self::$core_bases = $bases;

		return $bases;
	}

	/**
	 * Drop a leading `www.` so a host comparison is not defeated by the prefix.
	 *
	 * @param string $host Lowercased host.
	 * @since 1.5.0
	 * @return string
	 */
	public static function without_www( string $host ): string {
		return Entry_Match::without_www( $host );
	}

	/**
	 * Check if blocking should run.
	 *
	 * Public for the page payload, read after `open_buffer()` already settled it,
	 * so a browser hand-off holds a vendor exactly where this blocks.
	 *
	 * @since 0.0.1
	 * @return bool
	 */
	public function should_process(): bool {
		if ( $this->should_process === null ) {
			$this->should_process = $this->evaluate_should_process();
		}

		return $this->should_process;
	}

	/**
	 * Whether this visitor's consent should gate vendor activity on any response.
	 *
	 * Unlike should_process(), this is not disabled for AJAX/REST responses: server
	 * events still need the page's consent policy, but never HTML-specific rewrites.
	 *
	 * @return bool
	 */
	public function should_enforce_consent(): bool {
		if ( $this->should_enforce_consent === null ) {
			$is_server_response = wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
			$should_enforce     = ( ! is_admin() || $is_server_response )
				&& Utils::is_blocking_enabled()
				&& Utils::should_process_based_on_geo()
				&& ! Utils::is_scan_probe()
				&& ! $this->is_editor_bypass();

			if ( $should_enforce ) {
				$filtered       = apply_filters( 'surecookie_should_block_scripts', true );
				$should_enforce = is_bool( $filtered ) ? $filtered : true;
			}

			$this->should_enforce_consent = $should_enforce;
		}

		return $this->should_enforce_consent;
	}

	/**
	 * Open the processing buffer once per request.
	 *
	 * Opening it before WordPress 7.0's own template-enhancement buffer (started
	 * at `wp_before_include_template`) keeps ours on the outside, so block-style
	 * hoisting still runs and we post-process the corrected HTML.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	private function start_buffer(): void {
		if ( $this->buffer_open || ! $this->should_process() ) {
			return;
		}

		$this->buffer_open = true;

		ob_start( [ $this, 'process_buffer' ] );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	private function register_hooks(): void {
		// A plugin that owns a post type's templates can render the whole page
		// from template_redirect and exit, so template_include never fires and
		// the buffer never opens. Blocking is compliance-critical and must not be
		// cancellable that way, so open as early as a front-end request allows;
		// template_include stays as the fallback and no-ops if we already did.
		add_action( 'template_redirect', [ $this, 'open_buffer' ], -PHP_INT_MAX );
		add_filter( 'template_include', [ $this, 'intercept_template' ], PHP_INT_MAX );

		// Before every other wp_footer callback, so all footer scripts land
		// after the marker. Stripped again in process_buffer().
		add_action( 'wp_footer', [ $this, 'mark_footer_start' ], -PHP_INT_MAX );
	}

	/**
	 * Decide, once per request, whether blocking runs.
	 *
	 * @since 0.0.1
	 * @return bool
	 */
	private function evaluate_should_process(): bool {
		// Check if blocking feature is enabled.
		if ( ! Utils::is_blocking_enabled() ) {
			return false;
		}

		// Skip in admin area.
		if ( is_admin() ) {
			return false;
		}

		// Skip AJAX requests.
		if ( wp_doing_ajax() ) {
			return false;
		}

		// Skip REST API requests.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		// Skip feeds.
		if ( is_feed() ) {
			return false;
		}

		if ( $this->is_editor_bypass() ) {
			return false;
		}

		// Check content type header.
		$headers_list = headers_list();
		foreach ( $headers_list as $header ) {
			if ( stripos( $header, 'Content-Type:' ) === 0 ) {
				// Skip JSON responses.
				if ( stripos( $header, 'application/json' ) !== false ) {
					return false;
				}
				// Skip XML responses.
				if ( stripos( $header, 'application/xml' ) !== false || stripos( $header, 'text/xml' ) !== false ) {
					return false;
				}
			}
		}

		// Check geo-location rules - bypass blocking if user is not in a configured region -- This ensures script/content blocking respects the same geo-targeting rules as the consent banner.
		if ( ! Utils::should_process_based_on_geo() ) {
			return false;
		}

		// Allow the SaaS scanner to bypass blocking so it sees the full unblocked page.
		if ( $this->is_scan_bypass_request() ) {
			// Record that the scanner's request actually reached WordPress. If a
			// scan finishes with zero findings and zero reach, the host firewall
			// blocked the crawl at the edge (see SaasClient::classify_scan_outcome).
			do_action( 'surecookie_scanner_request_detected' );
			return false;
		}

		return $this->should_enforce_consent();
	}

	/**
	 * Whether blocking is skipped for a frontend builder's own render.
	 *
	 * Frontend builders edit where is_admin() is false, so blocking broke their
	 * tooling (issue #1033). Bailing turns off both the buffer rewrite and the
	 * DOM guard, so the capability alone is not enough: staff browsing normally
	 * are visitors, and a bypass would release even the quarantine category.
	 *
	 * @since 1.5.0
	 * @return bool
	 */
	private function is_editor_bypass(): bool {
		if ( ! Helper::is_builder_edit_render() ) {
			return false;
		}

		/**
		 * Filter whether logged-in users who can edit content bypass blocking.
		 * Return false to keep consent-blocking scripts for editing staff.
		 *
		 * @since 1.5.0
		 * @param bool $bypass Whether editors bypass blocking. Default true.
		 */
		if ( ! apply_filters( 'surecookie_bypass_blocking_for_editors', true ) ) {
			return false;
		}

		// An unblocked render must never reach visitors from a full-page cache.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		return true;
	}

	/**
	 * Check if buffer is HTML content.
	 *
	 * @param string $buffer Content to check.
	 * @since 0.0.1
	 * @return bool
	 */
	private function is_html( string $buffer ): bool {
		// ltrim() does not strip a UTF-8 BOM, and a single byte before the doctype
		// would otherwise turn blocking off for the entire page, silently.
		if ( str_starts_with( $buffer, "\xEF\xBB\xBF" ) ) {
			$buffer = substr( $buffer, 3 );
		}

		$trimmed = ltrim( $buffer );

		// Check if starts with HTML-like content.
		if ( empty( $trimmed ) ) {
			return false;
		}

		// Skip JSON (starts with { or [).
		if ( $trimmed[0] === '{' || $trimmed[0] === '[' ) {
			return false;
		}

		// Skip XML. The buffer now opens at template_redirect, which is where
		// core renders wp-sitemap.xsl, and that document carries a <head> the
		// guard would inject a raw <script> into - leaving it non-well-formed.
		if ( stripos( $trimmed, '<?xml' ) === 0 ) {
			return false;
		}

		// Should start with < for HTML/XML.
		if ( $trimmed[0] !== '<' ) {
			return false;
		}

		// Check for HTML doctype or html tag.
		if ( preg_match( '/^<!DOCTYPE\s+html|^<html/i', $trimmed ) ) {
			return true;
		}

		// Check for common HTML tags.
		if ( preg_match( '/^<(head|body|div|script|style|meta|link)/i', $trimmed ) ) {
			return true;
		}

		return true;
	}

	/**
	 * Find and block matching scripts in HTML.
	 *
	 * @param string $html HTML content.
	 * @since 0.0.1
	 * @return string Modified HTML.
	 */
	private function block_scripts( string $html ): string {
		$all_scripts = apply_filters( 'surecookie_known_scripts', [] );

		if ( empty( $all_scripts ) ) {
			return $html;
		}

		// Build patterns for matching.
		$patterns = $this->build_patterns( $all_scripts, 'scripts' );

		if ( empty( $patterns ) ) {
			return $html;
		}

		// Region-constrained rules (head|body|footer) need per-segment passes;
		// the common case (no constraints) keeps the single whole-page pass.
		$has_constraint = false;
		foreach ( $patterns as $info ) {
			if ( $info['location'] !== 'any' ) {
				$has_constraint = true;
				break;
			}
		}

		if ( ! $has_constraint ) {
			return $this->rewrite_script_tags( $html, $patterns );
		}

		$out = '';
		foreach ( $this->split_html_regions( $html ) as $segment ) {
			$region_patterns = array_filter(
				$patterns,
				static function ( $info ) use ( $segment ) {
					$location = $info['location'];
					return $location === 'any' || in_array( $location, $segment['accepts'], true );
				}
			);

			$out .= empty( $region_patterns )
				? $segment['html']
				: $this->rewrite_script_tags( $segment['html'], $region_patterns );
		}

		return $out;
	}

	/**
	 * Result of one rewrite pass, or the original HTML when PCRE gave up.
	 *
	 * PCRE returns null when it exceeds pcre.backtrack_limit, which on a large
	 * page is indistinguishable from "nothing matched" - the page then ships
	 * with that pass silently disabled. Say so loudly instead: save_log()
	 * reaches production, where the failure actually happens.
	 *
	 * @since 1.5.0
	 * @param string|null $result Callback result.
	 * @param string      $html   Original HTML.
	 * @param string      $kind   Pass name, for the log line.
	 * @return string
	 */
	private static function settled( ?string $result, string $html, string $kind ): string {
		if ( $result !== null ) {
			return $result;
		}

		Logger::get_instance()->save_log(
			sprintf(
				'SureCookie: %s blocking did not run on %s - the page (%d KB) exceeded PHP\'s pcre.backtrack_limit, so its tags were left untouched. Raise pcre.backtrack_limit or reduce the page size.',
				$kind,
				esc_url_raw( home_url( add_query_arg( [] ) ) ),
				(int) ( strlen( $html ) / 1024 )
			)
		);

		return $html;
	}

	/**
	 * Run the script-tag rewrite pass over one HTML chunk.
	 *
	 * @param string               $html     HTML content.
	 * @param array<string, mixed> $patterns Pattern lookup map.
	 * @since 1.3.0
	 * @return string Modified HTML.
	 */
	private function rewrite_script_tags( string $html, array $patterns ): string {
		$result = preg_replace_callback(
			'/<script\b([^>]*+)>((?:[^<]++|<(?!\/script>))*+)<\/script>/is',
			function ( $matches ) use ( $patterns ) {
				return $this->process_script_tag( $matches, $patterns );
			},
			$html
		);

		return self::settled( $result, $html, 'script' );
	}

	/**
	 * Split the page into head / body / footer segments for region-constrained
	 * matching. Head ends at `</head>`; footer starts at the marker printed by
	 * `mark_footer_start()`. When a boundary is missing, its region folds into
	 * the surrounding segment (which then accepts that region's rules too), so
	 * a constrained rule can never silently stop blocking.
	 *
	 * @param string $html HTML content.
	 * @since 1.3.0
	 * @return array<int, array{html: string, accepts: array<int, string>}>
	 */
	private function split_html_regions( string $html ): array {
		$head_end     = stripos( $html, '</head>' );
		$footer_start = strpos( $html, self::FOOTER_MARKER );

		$segments = [];

		if ( $head_end !== false ) {
			$segments[]  = [
				'html'    => substr( $html, 0, $head_end ),
				'accepts' => [ 'head' ],
			];
			$rest_offset = $head_end;
		} else {
			$rest_offset = 0;
		}

		$body_accepts = $head_end === false ? [ 'head', 'body' ] : [ 'body' ];

		if ( $footer_start !== false && $footer_start >= $rest_offset ) {
			$segments[] = [
				'html'    => substr( $html, $rest_offset, $footer_start - $rest_offset ),
				'accepts' => $body_accepts,
			];
			$segments[] = [
				'html'    => substr( $html, $footer_start ),
				'accepts' => [ 'footer' ],
			];
		} else {
			$segments[] = [
				'html'    => substr( $html, $rest_offset ),
				'accepts' => array_merge( $body_accepts, [ 'footer' ] ),
			];
		}

		return $segments;
	}

	/**
	 * Get the iframe-pattern lookup map, computed once per request.
	 *
	 * Shared by block_iframes / block_embeds / block_objects so the known-scripts
	 * payload is iterated only once per HTTP response. Cache is kept on the
	 * Blocker singleton, which is instantiated fresh per PHP request.
	 *
	 * @since 0.0.1-beta.2
	 * @return array<string, array{name: string, category: string, label: string, path?: string, location?: string}>
	 */
	private function get_iframe_patterns(): array {
		if ( $this->iframe_patterns_cache !== null ) {
			return $this->iframe_patterns_cache;
		}

		$all_scripts = apply_filters( 'surecookie_known_scripts', [] );

		if ( empty( $all_scripts ) ) {
			$this->iframe_patterns_cache = [];
			return $this->iframe_patterns_cache;
		}

		$this->iframe_patterns_cache = $this->build_patterns( $all_scripts, 'iframes' );

		return $this->iframe_patterns_cache;
	}

	/**
	 * Find and block matching iframes in HTML.
	 *
	 * @param string $html HTML content.
	 * @since 0.0.1
	 * @return string Modified HTML.
	 */
	private function block_iframes( string $html ): string {
		$patterns = $this->get_iframe_patterns();

		if ( empty( $patterns ) ) {
			return $html;
		}

		// The attribute run is lazy and the self-closed alternative comes first: a
		// possessive run swallows the `/` of `/>` and cannot give it back, so
		// `<iframe ... />` matched nothing while the paired form parked. HTML5 has no
		// self-closing iframe, so the browser builds the frame and fetches either way.
		$result = preg_replace_callback(
			'/<iframe\b([^>]*?)(?:\s*\/>|>((?:[^<]++|<(?!\/iframe>))*+)<\/iframe>)/is',
			function ( $matches ) use ( $patterns ) {
				return $this->process_iframe_tag( $matches, $patterns );
			},
			$html
		);

		return self::settled( $result, $html, 'iframe' );
	}

	/**
	 * Find and block matching <embed> tags in HTML.
	 *
	 * Reuses the `iframes[]` pattern array from blocking-scripts.json since
	 * <iframe>, <embed>, and <object> share URL semantics.
	 *
	 * @param string $html HTML content.
	 * @since 0.0.1-beta.2
	 * @return string Modified HTML.
	 */
	private function block_embeds( string $html ): string {
		$patterns = $this->get_iframe_patterns();

		if ( empty( $patterns ) ) {
			return $html;
		}

		// <embed> is always self-closing in practice.
		$result = preg_replace_callback(
			'/<embed\b([^>]*)\/?>/is',
			function ( $matches ) use ( $patterns ) {
				return $this->process_embed_tag( $matches, $patterns );
			},
			$html
		);

		return self::settled( $result, $html, 'embed' );
	}

	/**
	 * Find and block matching <object> tags in HTML.
	 *
	 * Handles both `<object data="…">…</object>`, legacy
	 * `<object><param name="movie|src|url" value="…"></object>` (Flash-style),
	 * AND self-closed XHTML-style `<object … />`.
	 *
	 * @param string $html HTML content.
	 * @since 0.0.1-beta.2
	 * @return string Modified HTML.
	 */
	private function block_objects( string $html ): string {
		$patterns = $this->get_iframe_patterns();

		if ( empty( $patterns ) ) {
			return $html;
		}

		// Covers both self-closed `<object … />` and full `<object …>…</object>` forms,
		// mirroring how block_iframes() handles its two shapes.
		$result = preg_replace_callback(
			'/<object\b([^>]*?)(?:\s*\/>|>((?:[^<]++|<(?!\/object>))*+)<\/object>)/is',
			function ( $matches ) use ( $patterns ) {
				return $this->process_object_tag( $matches, $patterns );
			},
			$html
		);

		return self::settled( $result, $html, 'object' );
	}

	/**
	 * Get the link-pattern lookup map, computed once per request.
	 *
	 * `styles` is the bucket a `<link>` OWNS - a stylesheet host is observed on
	 * exactly this tag - so it is collected first and un-pooled. Every other
	 * enforced bucket is pooled on top as cross-kind, because `rel=preload`,
	 * `prefetch` and `preconnect` are not deliveries: they warm a resource some
	 * other tag will request, and a hint that does not follow its target still
	 * fetches the blocked tracker and still leaks the visitor's IP.
	 *
	 * Going through `flatten_patterns()` rather than pooling separately is what
	 * keeps `tag_scoped` and `src_only` honoured here: an admin rule scoped to
	 * "Script" must not gate a `<link>`, and a pooled pattern is evidence about
	 * a host, never about inline code.
	 *
	 * @since 1.5.0
	 * @return array<string, array{name: string, category: string, label: string, location: string, path: string, src_only: bool}>
	 */
	private function get_link_patterns(): array {
		if ( $this->link_patterns_cache !== null ) {
			return $this->link_patterns_cache;
		}

		$all_scripts = apply_filters( 'surecookie_known_scripts', [] );

		if ( ! is_array( $all_scripts ) || empty( $all_scripts ) ) {
			$this->link_patterns_cache = [];
			return $this->link_patterns_cache;
		}

		$own      = 'styles';
		$patterns = $this->flatten_patterns( $all_scripts, $own, false );

		foreach ( array_keys( Pattern_Kinds::enforced() ) as $bucket ) {
			if ( $bucket !== $own ) {
				$patterns += $this->flatten_patterns( $all_scripts, $bucket, true );
			}
		}

		$this->link_patterns_cache = $patterns;

		return $this->link_patterns_cache;
	}

	/**
	 * Find and block matching `<link>` tags in HTML.
	 *
	 * @param string $html HTML content.
	 * @since 1.5.0
	 * @return string Modified HTML.
	 */
	private function block_links( string $html ): string {
		$patterns = $this->get_link_patterns();

		if ( empty( $patterns ) ) {
			return $html;
		}

		// `<link>` is a void element; the optional self-closing slash sits
		// inside the attribute run and is preserved with the rest of them.
		$result = preg_replace_callback(
			'/<link\b([^>]*)>/i',
			function ( $matches ) use ( $patterns ) {
				return $this->process_link_tag( $matches, $patterns );
			},
			$html
		);

		return self::settled( $result, $html, 'link' );
	}

	/**
	 * Process a single `<link>` tag.
	 *
	 * @param array<int, string>                                                                                    $matches  Regex matches.
	 * @param array<string, array{name: string, category: string, label: string, path?: string, location?: string}> $patterns Pattern mappings.
	 * @since 1.5.0
	 * @return string Parked link tag, or the original when it is not blocked.
	 */
	private function process_link_tag( array $matches, array $patterns ): string {
		$full_tag   = $matches[0];
		$attributes = $matches[1];

		// Skip if already parked.
		if ( strpos( $attributes, 'data-surecookie-href' ) !== false ) {
			return $full_tag;
		}

		if ( ! $this->link_reaches_host( $attributes ) ) {
			return $full_tag;
		}

		$href = $this->link_href( $attributes );

		if ( $href === '' || $this->is_core_asset( $href ) ) {
			return $full_tag;
		}

		$match_result = $this->match_pattern( $href, '', $patterns );

		if ( $match_result === null ) {
			return $full_tag;
		}

		// Scoped as a script: every scanner files a stylesheet under `scripts`,
		// so that is the kind the admin's exclusion and override are keyed by.
		$match_result = $this->gate( 'link', 'script', $href, $match_result );

		if ( $match_result === null ) {
			return $full_tag;
		}

		return $this->park_link_tag(
			$attributes,
			$href,
			(string) $match_result['name'],
			(string) $match_result['category']
		);
	}

	/**
	 * Whether a `<link>`'s rel makes the browser reach its href host.
	 *
	 * @param string $attributes Raw attributes string from the tag.
	 * @since 1.5.0
	 * @return bool
	 */
	private function link_reaches_host( string $attributes ): bool {
		$rel = $this->link_attribute( $attributes, 'rel' );

		if ( $rel === '' ) {
			return false;
		}

		// `rel` is a space-separated token list ("shortcut icon", "preload stylesheet").
		$rels = preg_split( '/\s+/', strtolower( $rel ) );

		return is_array( $rels ) && array_intersect( $rels, self::FETCHING_LINK_RELS ) !== [];
	}

	/**
	 * The `<link>`'s href, or '' when it has none.
	 *
	 * @param string $attributes Raw attributes string from the tag.
	 * @since 1.5.0
	 * @return string
	 */
	private function link_href( string $attributes ): string {
		return $this->link_attribute( $attributes, 'href' );
	}

	/**
	 * Read one attribute out of a tag's attribute run.
	 *
	 * Walks name="value" pairs rather than searching for the name, so a value
	 * that happens to contain another attribute's name cannot be mistaken for
	 * it: `href="...?rel=canonical"` used to be read as the tag's own `rel` and
	 * the link was then never parked. Quotes are matched to their own kind, so
	 * an apostrophe inside a double-quoted URL no longer truncates the value.
	 * A name is anchored by a lookbehind rather than by leading whitespace, so a
	 * minifier's `href="a.css"rel="stylesheet"` is still read as two attributes.
	 *
	 * @param string $attributes Raw attributes string from the tag.
	 * @param string $name       Attribute to read, lowercase.
	 * @since 1.5.0
	 * @return string Attribute value, trimmed, or '' when absent.
	 */
	private function link_attribute( string $attributes, string $name ): string {
		if ( preg_match_all( '/(?<![-\w:])([-\w:]+)\s*=\s*(?|"([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $attributes, $pairs, PREG_SET_ORDER ) === false ) {
			return '';
		}

		foreach ( $pairs as $pair ) {
			// Group 2 is absent for an empty value (`rel=""`), which is no value.
			if ( strtolower( $pair[1] ) === $name ) {
				return isset( $pair[2] ) ? trim( $pair[2] ) : '';
			}
		}

		return '';
	}

	/**
	 * Park a `<link>` by moving its href out of the browser's reach.
	 *
	 * No placeholder and no `display:none`: a link renders nothing, so removing
	 * the href is the whole of it. `rel` is deliberately left in place - a link
	 * with no href is inert either way, and keeping it means the restore only
	 * has to put one attribute back.
	 *
	 * @param string $attributes Original tag attributes string.
	 * @param string $href       Original href.
	 * @param string $name       Matched service key.
	 * @param string $category   Matched service category.
	 * @since 1.5.0
	 * @return string Parked tag.
	 */
	private function park_link_tag( string $attributes, string $href, string $name, string $category ): string {
		$new_attributes = preg_replace( '/\s*(?<![-\w:])href\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $attributes ) ?? $attributes; // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- No /e modifier used, safe replacement.

		// esc_url() drops data: URIs, and a stylesheet inlined into one still has
		// to survive here or consent could never restore it.
		$safe_href = stripos( $href, 'data:' ) === 0 ? esc_attr( $href ) : esc_url( $href );

		$tag  = '<link data-surecookie-category="' . esc_attr( $this->map_category( $category ) ) . '"';
		$tag .= ' data-surecookie-name="' . esc_attr( $name ) . '"';
		$tag .= ' data-surecookie-href="' . $safe_href . '"';

		$new_attributes = trim( (string) $new_attributes );
		if ( $new_attributes !== '' ) {
			$tag .= ' ' . $new_attributes;
		}

		return $tag . '>';
	}

	/**
	 * Resolve a matched resource to a blocking decision.
	 *
	 * Every pass ends the same way: apply the admin's category override, record
	 * the match so the resource gets an admin row, offer the per-kind skip
	 * filter, then let a skippable category through. Five copies of that
	 * sequence is how one pass ends up honouring an override the others ignore.
	 *
	 * `$scope` is the kind the admin's settings are keyed by, which is not
	 * always `$kind`: embed and object are scoped as iframes, and a link as a
	 * script. The scope filter fires alongside the kind filter for exactly that
	 * reason - `surecookie_skip_script` is where the exclusion, the GCM
	 * whitelist and Pro's whitelist are registered, and a link that ignored
	 * them would be parked with no admin control able to release it.
	 *
	 * @since 1.5.0
	 * @param string               $kind         Tag kind, naming the `surecookie_skip_{kind}` filter.
	 * @param string               $scope        Kind the admin settings are scoped by ('script'|'iframe').
	 * @param string               $src          Resource URL.
	 * @param array<string, mixed> $match_result Result from match_pattern().
	 * @return array<string, mixed>|null The match with its final category, or null to let the resource load.
	 */
	private function gate( string $kind, string $scope, string $src, array $match_result ): ?array {
		// Honor an admin category override for this resource.
		$keys                     = $this->resolution_keys( $src, $match_result );
		$match_result['category'] = Resource_Categories::resolve_first( $keys, (string) $match_result['category'], $scope );

		// The catalog is not readable from any admin screen, so note what we
		// matched or this resource stays invisible there. $keys[0] is the src for
		// a normal match and the pattern for one matched on inline content, which
		// has no src of its own to record; matched_in decides whether that entry
		// may also publish the service's catalog-declared cookies.
		Matched_Resources::get_instance()->record( $scope, (string) ( $keys[0] ?? '' ), (string) $match_result['name'], (string) $match_result['category'], (string) ( $match_result['matched_in'] ?? '' ) );

		$skip = false;

		/**
		 * Filter: allow one resource of this kind to load regardless of consent.
		 *
		 * Fires as `surecookie_skip_script`, `_iframe`, `_embed`, `_object` or
		 * `_link`. Callbacks must accept 5 arguments or the matched pattern is
		 * lost; see the module CLAUDE.md.
		 *
		 * @since 0.0.1-beta.2
		 * @param bool   $skip     Whether to skip blocking (default false).
		 * @param string $src      Resource URL.
		 * @param string $name     Matched service key.
		 * @param string $category Matched service category.
		 * @param string $pattern  Blocking pattern that matched.
		 */
		$skip = apply_filters(
			"surecookie_skip_{$kind}",
			$skip,
			$src,
			$match_result['name'],
			$match_result['category'],
			(string) ( $match_result['matched_pattern'] ?? '' )
		);

		// A kind whose settings are keyed by another one also consults that
		// one's filter, so a host-level allowance registered for scripts still
		// releases the link the admin excluded.
		if ( ! $skip && $kind !== $scope ) {
			/** This filter is documented above. */
			$skip = apply_filters(
				"surecookie_skip_{$scope}",
				$skip,
				$src,
				$match_result['name'],
				$match_result['category'],
				(string) ( $match_result['matched_pattern'] ?? '' )
			);
		}

		if ( $skip ) {
			return null;
		}

		return $this->can_category_skip( (string) $match_result['category'] ) ? null : $match_result;
	}

	/**
	 * Flatten the catalog into the pattern map one tag pass matches against.
	 *
	 * A pattern names a third-party host, not a tag: a vendor shipping both an
	 * embed and a JS API (Maps, YouTube, Vimeo) is the same connection either
	 * way, so both arrays feed both passes and a service declared under one is
	 * gated under the other. Own kind is collected first and wins a collision,
	 * so pooling only ever adds coverage - it never re-attributes a match.
	 *
	 * @param array<string, array<string, mixed>> $all_scripts Known scripts by category.
	 * @param string                              $own         Catalog key this pass owns ('scripts'|'iframes').
	 * @since 1.5.0
	 * @return array<string, array{name: string, category: string, label: string, location: string, path: string, src_only: bool}>
	 */
	private function build_patterns( array $all_scripts, string $own ): array {
		$cross = $own === 'scripts' ? 'iframes' : 'scripts';

		return $this->flatten_patterns( $all_scripts, $own, false )
			+ $this->flatten_patterns( $all_scripts, $cross, true );
	}

	/**
	 * Collect one bucket of the catalog into a pattern => service map.
	 *
	 * @param array<string, array<string, mixed>> $all_scripts Known scripts by category.
	 * @param string                              $bucket      Catalog key to read ('scripts'|'iframes').
	 * @param bool                                $cross_kind  Whether this is the pooled pass, which
	 *                                                         a `tag_scoped` producer opts out of.
	 * @since 1.5.0
	 * @return array<string, array{name: string, category: string, label: string, location: string, path: string, src_only: bool}>
	 */
	private function flatten_patterns( array $all_scripts, string $bucket, bool $cross_kind ): array {
		$patterns = [];

		foreach ( $all_scripts as $category => $services ) {
			if ( ! is_array( $services ) ) {
				continue;
			}

			foreach ( $services as $service_key => $service ) {
				if ( ! is_array( $service ) || empty( $service[ $bucket ] ) || ! is_array( $service[ $bucket ] ) ) {
					continue;
				}

				// `tag_scoped` means the producer meant its arrays literally - the
				// admin's script/iframe rule type is the only thing that sets it.
				if ( $cross_kind && ! empty( $service['tag_scoped'] ) ) {
					continue;
				}

				// A producer may declare `label` and leave it blank; the placeholder
				// prints it verbatim, so anything that renders as nothing is absent (#1429).
				$label = Sanitize::scalar( $service['label'] ?? '' );
				$label = Sanitize::is_blank( $label ) ? $service_key : $label;

				foreach ( $service[ $bucket ] as $pattern ) {
					$patterns[ $pattern ] = [
						'name'     => $service_key,
						'category' => $category,
						'label'    => $label,
						// Optional page-region constraint (head|body|footer).
						'location' => $service['location'] ?? 'any',
						// Optional narrowing constraint: the resource must
						// also contain this path/pattern.
						'path'     => (string) ( $service['path'] ?? '' ),
						// A pooled pattern is evidence about a host seen on
						// another tag, never about inline code, so the script
						// pass must not match it against script bodies.
						'src_only' => $cross_kind,
					];
				}
			}
		}

		return $patterns;
	}

	/**
	 * Process a single script tag.
	 *
	 * @param array<int, string>                                                                                    $matches  Regex matches.
	 * @param array<string, array{name: string, category: string, label: string, path?: string, location?: string}> $patterns Pattern mappings.
	 * @since 0.0.1
	 * @return string Modified script tag.
	 */
	private function process_script_tag( array $matches, array $patterns ): string {
		$full_tag   = $matches[0];
		$attributes = $matches[1];
		$content    = $matches[2] ?? '';

		// Skip if already blocked.
		if ( strpos( $attributes, 'data-surecookie-category' ) !== false ) {
			return $full_tag;
		}

		// Never block SureCookie's own inline scripts. WordPress emits our
		// localized data as id="{handle}-js-{extra,before,after}" (e.g.
		// surecookie-public-js-extra, which defines window.surecookiePublicSettings).
		// That payload embeds tracker-domain strings - cookie domains and the
		// blocking patterns themselves - for client-side use, so matching inline
		// CONTENT would self-match a blocking pattern and neutralize the consent
		// runtime's own bootstrap. Our handles are always "surecookie"-prefixed;
		// no third-party script carries that id.
		if ( preg_match( '/\bid\s*=\s*["\']surecookie[-_]/i', $attributes ) ) {
			return $full_tag;
		}

		$type = $this->extract_script_type( $attributes );
		if ( $type === 'text/plain' ) {
			return $full_tag;
		}
		if ( $type !== null && ! in_array( $type, self::EXECUTABLE_SCRIPT_TYPES, true ) ) {
			return $full_tag;
		}

		// A manifest is never the tracker, and gating an importmap is unrecoverable:
		// the browser ignores one restored after module resolution began, so consent
		// cannot repair the page view - only a reload can.
		if ( in_array( $type, self::MANIFEST_SCRIPT_TYPES, true ) ) {
			return $full_tag;
		}

		// Extract src attribute.
		$src = '';
		if ( preg_match( '/src\s*=\s*' . self::ATTR_VALUE . '/i', $attributes, $src_match ) ) {
			$src = $src_match[1];
		}

		// Try to match against known scripts.
		$match_result = $this->match_pattern( $src, $content, $patterns );

		// A performance plugin may have inlined this script as a data: URI, which
		// hides the tracker URL from the matcher. Decode and retry on the real body.
		if ( $match_result === null && $src !== '' ) {
			$decoded = $this->decode_data_uri_script( $src );
			if ( $decoded !== '' ) {
				$match_result = $this->match_pattern( '', $decoded, $patterns );
			}
		}

		if ( $match_result === null ) {
			return $full_tag;
		}

		// Only when the resource's OWN url matched: the src regex also lifts
		// `data-src` off a lazy tag, and exempting on that would hide an inline
		// tracker whose body is the real signal.
		if ( ( $match_result['matched_in'] ?? '' ) === 'src' && $this->is_core_asset( $src ) ) {
			return $full_tag;
		}

		$match_result = $this->gate( 'script', 'script', $src, $match_result );

		if ( $match_result === null ) {
			return $full_tag;
		}

		// Modify the script tag.
		return $this->modify_script_tag(
			$attributes,
			$content,
			$src,
			$match_result['name'],
			$match_result['category']
		);
	}

	/**
	 * Process a single iframe tag.
	 *
	 * @param array<int, string>                                                                                    $matches  Regex matches.
	 * @param array<string, array{name: string, category: string, label: string, path?: string, location?: string}> $patterns Pattern mappings.
	 * @since 0.0.1
	 * @return string Modified iframe tag with placeholder.
	 */
	private function process_iframe_tag( array $matches, array $patterns ): string {
		$full_tag   = $matches[0];
		$attributes = $matches[1];

		// Skip if already blocked.
		if ( strpos( $attributes, 'data-surecookie-src' ) !== false ) {
			return $full_tag;
		}

		// Extract src attribute.
		$src = '';
		if ( preg_match( '/src\s*=\s*' . self::ATTR_VALUE . '/i', $attributes, $src_match ) ) {
			$src = $src_match[1];
		}

		if ( empty( $src ) ) {
			return $full_tag;
		}

		// Try to match against known iframe patterns.
		$match_result = $this->match_pattern( $src, '', $patterns );

		if ( $match_result === null ) {
			return $full_tag;
		}

		$match_result = $this->gate( 'iframe', 'iframe', $src, $match_result );

		if ( $match_result === null ) {
			return $full_tag;
		}

		// Build blocked iframe with placeholder.
		return $this->build_embedded_placeholder(
			'iframe',
			$attributes,
			$src,
			$match_result['name'],
			$match_result['category'],
			$match_result['label']
		);
	}

	/**
	 * Process a single <embed> tag.
	 *
	 * @param array<int, string>                                                                                    $matches  Regex matches.
	 * @param array<string, array{name: string, category: string, label: string, path?: string, location?: string}> $patterns Pattern mappings.
	 * @since 0.0.1-beta.2
	 * @return string Modified embed tag with placeholder, or original tag if not blocked.
	 */
	private function process_embed_tag( array $matches, array $patterns ): string {
		$full_tag   = $matches[0];
		$attributes = $matches[1];

		// Skip if already blocked.
		if ( strpos( $attributes, 'data-surecookie-src' ) !== false ) {
			return $full_tag;
		}

		// Extract src attribute.
		$src = '';
		if ( preg_match( '/src\s*=\s*' . self::ATTR_VALUE . '/i', $attributes, $src_match ) ) {
			$src = $src_match[1];
		}

		if ( empty( $src ) ) {
			return $full_tag;
		}

		// Same-origin skip - don't wrap legitimate in-house PDFs/SVGs.
		if ( $this->is_same_origin_url( $src ) ) {
			return $full_tag;
		}

		$match_result = $this->match_pattern( $src, '', $patterns );

		if ( $match_result === null ) {
			return $full_tag;
		}

		$match_result = $this->gate( 'embed', 'iframe', $src, $match_result );

		if ( $match_result === null ) {
			return $full_tag;
		}

		return $this->build_embedded_placeholder(
			'embed',
			$attributes,
			$src,
			$match_result['name'],
			$match_result['category'],
			$match_result['label']
		);
	}

	/**
	 * Process a single <object> tag.
	 *
	 * @param array<int, string>                                                                                    $matches  Regex matches.
	 * @param array<string, array{name: string, category: string, label: string, path?: string, location?: string}> $patterns Pattern mappings.
	 * @since 0.0.1-beta.2
	 * @return string Modified object tag with placeholder, or original tag if not blocked.
	 */
	private function process_object_tag( array $matches, array $patterns ): string {
		$full_tag   = $matches[0];
		$attributes = $matches[1];
		$inner      = $matches[2] ?? '';

		// Skip if already blocked.
		if ( strpos( $attributes, 'data-surecookie-data' ) !== false ) {
			return $full_tag;
		}

		// Extract data attribute (primary URL source).
		$data = '';
		if ( preg_match( '/(?<![-\w])data\s*=\s*' . self::ATTR_VALUE . '/i', $attributes, $data_match ) ) {
			$data = $data_match[1];
		}

		// If no data= attribute, look for <param name="movie|src|url" value="..."> child.
		if ( empty( $data ) && ! empty( $inner ) ) {
			if ( preg_match( '/<param\b[^>]*\bname\s*=\s*["\'](?:movie|src|url)["\'][^>]*\bvalue\s*=\s*["\']([^"\']+)["\']/is', $inner, $param_match ) ) {
				$data = $param_match[1];
			} elseif ( preg_match( '/<param\b[^>]*\bvalue\s*=\s*["\']([^"\']+)["\'][^>]*\bname\s*=\s*["\'](?:movie|src|url)["\']/is', $inner, $param_match ) ) {
				$data = $param_match[1];
			}
		}

		if ( empty( $data ) ) {
			return $full_tag;
		}

		// Same-origin skip.
		if ( $this->is_same_origin_url( $data ) ) {
			return $full_tag;
		}

		$match_result = $this->match_pattern( $data, '', $patterns );

		if ( $match_result === null ) {
			return $full_tag;
		}

		$match_result = $this->gate( 'object', 'iframe', $data, $match_result );

		if ( $match_result === null ) {
			return $full_tag;
		}

		// Neutralize any <param> child that carries the URL so the browser
		// doesn't refetch Flash/legacy media while the object is hidden.
		$neutralized_inner = $this->neutralize_object_params( $inner );

		return $this->build_embedded_placeholder(
			'object',
			$attributes,
			$data,
			$match_result['name'],
			$match_result['category'],
			$match_result['label'],
			$neutralized_inner
		);
	}

	/**
	 * Rename `name` attribute on <param> elements carrying Flash-style URLs so
	 * the hidden <object> placeholder doesn't still fetch them. Restoration in
	 * consentManager.js renames the attribute back when consent is given.
	 *
	 * @param string $inner Inner HTML of the <object> tag.
	 * @since 0.0.1-beta.2
	 * @return string Neutralized inner HTML.
	 */
	private function neutralize_object_params( string $inner ): string {
		if ( trim( $inner ) === '' ) {
			return $inner;
		}

		$result = preg_replace_callback(
			'/<param\b([^>]*)(\/?>)/is',
			static function ( $matches ) {
				$attrs   = $matches[1];
				$closing = $matches[2];

				if ( preg_match( '/name\s*=\s*["\'](?:movie|src|url)["\']/i', $attrs ) !== 1 ) {
					return $matches[0];
				}

				// phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- No /e modifier used, safe replacement.
				$new_attrs = preg_replace(
					'/(^|\s)name(\s*=)/i',
					'$1data-surecookie-param-name$2',
					$attrs
				) ?? $attrs;

				return '<param' . $new_attrs . $closing;
			},
			$inner
		);

		return $result ?? $inner;
	}

	/**
	 * Whether a script URL is a WordPress core asset, which must always load.
	 *
	 * Host-gated against the URLs WordPress itself emits, so
	 * `https://evil.test/wp-includes/track.js` cannot borrow the exemption.
	 * Deliberately stops at core: the catalog targets self-hosted trackers under
	 * `/wp-content/` by filename (`matomo.js`), so exempting it would free them.
	 *
	 * @param string $src Script `src`; empty for an inline script.
	 * @since 1.5.0
	 * @return bool
	 */
	private function is_core_asset( string $src ): bool {
		$src = trim( $src );

		// Only a root-relative path or http(s) can name a core file. A `data:` URI
		// is out: its body parses as the path, so an inlined tracker would borrow this.
		if ( strpos( $src, '//' ) === 0 ) {
			$src = 'https:' . $src;
		} elseif ( $src === '' || ( strpos( $src, '/' ) !== 0 && preg_match( '#^https?://#i', $src ) !== 1 ) ) {
			return false;
		}

		$parts = wp_parse_url( $src );
		$parts = is_array( $parts ) ? $parts : [];
		$host  = self::without_www( strtolower( (string) ( $parts['host'] ?? '' ) ) );
		$path  = (string) ( $parts['path'] ?? '' );

		// A `..` segment means the path does not resolve where it reads. Decoded, or
		// `%2e%2e` walks past; per segment, so `foo..min.js` still counts.
		if ( $path === '' || in_array( '..', explode( '/', rawurldecode( $path ) ), true ) ) {
			return false;
		}

		// Anchored on the real core URLs, so a subdirectory or multisite install
		// follows, and a nested `/wp-content/uploads/wp-includes/` cannot pass.
		foreach ( self::core_bases() as [ $core_host, $core_path ] ) {
			if ( $host !== '' && $host !== $core_host ) {
				continue;
			}

			if ( stripos( $path, $core_path ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a URL has the same host as the current site.
	 *
	 * Used to avoid wrapping first-party PDFs/SVGs embedded via <embed>/<object>.
	 * Uses exact-host match (no eTLD+1 / Public Suffix List lookup).
	 *
	 * @param string $url Resource URL.
	 * @since 0.0.1-beta.2
	 * @return bool True if URL is same-origin (exact host), relative, or a data: URI.
	 */
	private function is_same_origin_url( string $url ): bool {
		if ( $url === '' ) {
			return false;
		}

		// Non-HTTP schemes and relative URLs - not third-party tracking.
		if ( preg_match( '/^(?:about|blob|data|file|javascript|mailto|tel):/i', $url ) === 1 ) {
			return true;
		}

		// Protocol-relative URL - normalize so wp_parse_url resolves host.
		if ( strpos( $url, '//' ) === 0 ) {
			$url = 'http:' . $url;
		}

		// Relative URLs (no scheme) are inherently same-origin.
		if ( preg_match( '/^https?:/i', $url ) !== 1 ) {
			return true;
		}

		$embed_host = wp_parse_url( $url, PHP_URL_HOST );
		$site_host  = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( empty( $embed_host ) || empty( $site_host ) ) {
			return false;
		}

		return strcasecmp( (string) $embed_host, (string) $site_host ) === 0;
	}

	/**
	 * Check if a category can be skipped.
	 *
	 * @param string $category Category name.
	 * @since 0.0.1
	 * @return bool True if category can be skipped, false otherwise.
	 */
	private function can_category_skip( string $category ): bool {
		return Blocking_Surface::is_skippable_category( $category );
	}

	/**
	 * Decode a script inlined into a `data:` URI back to its JavaScript body.
	 *
	 * Performance plugins rewrite inline scripts to `data:text/javascript;base64,...`
	 * so they can carry `defer`. That buries the tracker URL where substring matching
	 * cannot see it, so decode the payload and match against that instead.
	 *
	 * @param string $src Script src attribute.
	 * @since 1.5.0
	 * @return string Decoded JavaScript, or an empty string when $src is not a decodable script data URI.
	 */
	private function decode_data_uri_script( string $src ): string {
		if ( stripos( $src, 'data:' ) !== 0 ) {
			return '';
		}

		$comma = strpos( $src, ',' );
		if ( $comma === false ) {
			return '';
		}

		$meta    = strtolower( substr( $src, 5, $comma - 5 ) );
		$payload = substr( $src, $comma + 1 );

		// Only script payloads are worth decoding.
		if ( strpos( $meta, 'javascript' ) === false && strpos( $meta, 'ecmascript' ) === false ) {
			return '';
		}

		if ( strlen( $payload ) > self::MAX_DATA_URI_PAYLOAD ) {
			return '';
		}

		if ( strpos( $meta, 'base64' ) !== false ) {
			$decoded = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own page markup to match tracker patterns.
			return is_string( $decoded ) ? $decoded : '';
		}

		return rawurldecode( $payload );
	}

	/**
	 * Match a URL against known patterns.
	 *
	 * @param string                                                                                                $src      URL to match.
	 * @param string                                                                                                $content  Content to match.
	 * @param array<string, array{name: string, category: string, label: string, path?: string, location?: string}> $patterns Pattern mappings.
	 * @since 0.0.1
	 * @return array{name: string, category: string, label: string, path?: string, location?: string, matched_pattern?: string, matched_in?: string}|null Match result or null.
	 */
	private function match_pattern( string $src, string $content, array $patterns ): ?array {
		$best = null;

		foreach ( $patterns as $pattern => $info ) {
			// A pooled pattern was observed on a different tag, so it says
			// nothing about inline code: matching it against a script body
			// would park a tag that merely mentions the URL, and everything
			// else in it. Only the declaring kind reads $content.
			$body       = empty( $info['src_only'] ) ? $content : '';
			$matched_in = '';
			if ( ! empty( $src ) && stripos( $src, $pattern ) !== false ) {
				$matched_in = 'src';
			} elseif ( ! empty( $body ) && stripos( $body, $pattern ) !== false ) {
				$matched_in = 'content';
			}

			if ( $matched_in === '' ) {
				continue;
			}

			// Optional narrowing constraint: the resource must ALSO contain
			// the rule's path/pattern (e.g. host + `/fbevents.js`).
			$path = (string) ( $info['path'] ?? '' );
			if ( $path !== '' ) {
				$path_matched =
					( ! empty( $src ) && stripos( $src, $path ) !== false ) ||
					( ! empty( $body ) && stripos( $body, $path ) !== false );
				if ( ! $path_matched ) {
					continue;
				}
			}

			// The pattern is the host an admin setting is keyed on. An inline match
			// has no src to carry it, so hand it to the caller.
			$info['matched_pattern'] = (string) $pattern;
			$info['matched_in']      = $matched_in;

			// A pattern naming a path beats a bare host whatever the map order, so a
			// catalogued file keeps its category under a scan row or a hold (#1313).
			if ( null === $best || ( self::names_path( (string) $pattern ) && ! self::names_path( $best['matched_pattern'] ) ) ) {
				$best = $info;
			}
		}

		return $best;
	}

	/**
	 * Whether a blocking pattern names a path rather than only a host.
	 *
	 * Mirrored by `namesPath()` in src/dom-guard/index.js; the two layers must rank alike.
	 *
	 * @param string $pattern Blocking pattern.
	 * @since 1.6.1
	 * @return bool
	 */
	public static function names_path( string $pattern ): bool {
		return strpos( trim( $pattern, '/' ), '/' ) !== false;
	}

	/**
	 * Candidate keys an admin setting may be keyed on, most specific first.
	 *
	 * A resource matched on inline content has no src, and one a performance
	 * plugin inlined into a `data:` URI has a src no setting can appear in.
	 *
	 * @param string              $src          Resource URL, possibly empty.
	 * @param array<string,mixed> $match_result Result from match_pattern().
	 * @since 1.5.0
	 * @return array<int, string>
	 */
	private function resolution_keys( string $src, array $match_result ): array {
		// A content match says nothing about the src, and that src may be a data:
		// URI whose decoded body is what matched, so it is not a key here.
		if ( ( $match_result['matched_in'] ?? '' ) === 'content' ) {
			$src = '';
		}

		return array_values(
			array_filter( [ $src, (string) ( $match_result['matched_pattern'] ?? '' ) ] )
		);
	}

	/**
	 * Modify a script tag to block it.
	 *
	 * @param string $attributes Original attributes string.
	 * @param string $content    Inline script content.
	 * @param string $src        Original src attribute.
	 * @param string $name       Service name.
	 * @param string $category   Category name.
	 * @since 0.0.1
	 * @return string Modified script tag.
	 */
	private function modify_script_tag( string $attributes, string $content, string $src, string $name, string $category ): string {
		// Map API category names to SureCookie category names.
		$mapped_category = $this->map_category( $category );

		$type          = $this->extract_script_type( $attributes );
		$original_type = $type !== null && in_array( $type, self::EXECUTABLE_SCRIPT_TYPES, true ) ? $type : 'none';

		// Strip any type attribute (quoted or unquoted HTML5 form).
		$new_attributes = preg_replace( '/\s*type\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $attributes ) ?? $attributes; // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- No /e modifier used, safe replacement.

		// Remove src attribute (will be stored in data attribute).
		if ( ! empty( $src ) ) {
			$new_attributes = preg_replace( '/\s*src\s*=\s*' . self::ATTR_VALUE_ANY . '/i', '', $new_attributes ) ?? $new_attributes; // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- No /e modifier used, safe replacement.
		}

		// Build new script tag.
		$new_tag  = '<script type="text/plain"';
		$new_tag .= ' data-surecookie-category="' . esc_attr( $mapped_category ) . '"';
		$new_tag .= ' data-surecookie-name="' . esc_attr( $name ) . '"';
		$new_tag .= ' data-surecookie-original-type="' . esc_attr( $original_type ) . '"';

		if ( ! empty( $src ) ) {
			// esc_url() drops data: URIs, and a script inlined into one still has to
			// survive here or consent could never restore it.
			$safe_src = stripos( $src, 'data:' ) === 0 ? esc_attr( $src ) : esc_url( $src );
			$new_tag .= ' data-surecookie-src="' . $safe_src . '"';
		}

		// Add remaining attributes.
		$new_attributes = trim( (string) $new_attributes );
		if ( ! empty( $new_attributes ) ) {
			$new_tag .= ' ' . $new_attributes;
		}

		$new_tag .= '>' . $content . '</script>';

		return $new_tag;
	}

	/**
	 * Extract the normalized script `type` from a tag's attribute string.
	 *
	 * Handles quoted (`type="module"`, `type='module'`) and unquoted HTML5
	 * (`type=module`) forms, lowercases, trims whitespace, and strips MIME
	 * parameters (`text/javascript; charset=utf-8` → `text/javascript`).
	 *
	 * @param string $attributes Raw attributes string from the opening tag.
	 * @since 0.0.1-beta.2
	 * @return string|null Normalized type, or null when no (or empty) type attribute.
	 */
	private function extract_script_type( string $attributes ): ?string {
		if ( ! preg_match( '/type\s*=(?|\s*"([^"]*)"|\s*\'([^\']*)\'|([^\s>]+))/i', $attributes, $match ) ) {
			return null;
		}
		if ( $match[1] === '' ) {
			return null;
		}
		return strtolower( trim( explode( ';', $match[1], 2 )[0] ) );
	}

	/**
	 * Build an embedded-content placeholder for a blocked iframe/embed/object tag.
	 *
	 * Renders the same overlay UX ("This content is blocked… Accept & Load")
	 * for all three tag types; only the hidden inner element differs. The URL
	 * is stored in `data-surecookie-src` for iframe/embed (both use `src=`) and
	 * `data-surecookie-data` for object (uses `data=` attribute). consentManager.js
	 * restores the URL when the user consents.
	 *
	 * @param string $tag        One of 'iframe', 'embed', 'object'.
	 * @param string $attributes Original tag attributes string.
	 * @param string $url        Original resource URL (src for iframe/embed, data for object).
	 * @param string $name       Matched service key.
	 * @param string $category   Matched category.
	 * @param string $label      Human-readable vendor label.
	 * @param string $inner      For <object>, the neutralized inner HTML. Ignored otherwise.
	 * @since 0.0.1-beta.2
	 * @return string Placeholder HTML.
	 */
	private function build_embedded_placeholder(
		string $tag,
		string $attributes,
		string $url,
		string $name,
		string $category,
		string $label,
		string $inner = ''
	): string {
		$mapped_category = $this->map_category( $category );

		// Normalize tag.
		$tag = in_array( $tag, [ 'iframe', 'embed', 'object' ], true ) ? $tag : 'iframe';

		// Strip the URL attribute from the original attributes. <iframe>/<embed>
		// use src=; <object> uses data=.
		$url_attr       = $tag === 'object' ? 'data' : 'src';
		$new_attributes = preg_replace( '/\s*(?<![-\w])' . $url_attr . '\s*=\s*' . self::ATTR_VALUE_ANY . '/i', '', $attributes ) ?? $attributes; // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- No /e modifier used, safe replacement.

		// Extract any existing `style` attribute from the original tag and strip it
		// so we can merge its value with our `display:none;` declaration. Emitting
		// two `style` attributes is invalid HTML - the HTML parser keeps only the
		// first, which would silently discard our `display:none;` and leave the
		// blocked element visible.
		$existing_style = '';
		if ( preg_match( '/style\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', (string) $new_attributes, $style_match ) === 1 ) {
			$existing_style = trim( $style_match[1] !== '' ? $style_match[1] : ( $style_match[2] ?? '' ) );
			$new_attributes = preg_replace( '/\s*style\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', '', (string) $new_attributes ) ?? $new_attributes; // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- No /e modifier used, safe replacement.
		}

		// Append `display:none;` so it always wins the cascade for inline styles
		// (later declarations override earlier ones for the same property).
		$combined_style = $existing_style;
		if ( $combined_style !== '' && substr( $combined_style, -1 ) !== ';' ) {
			$combined_style .= ';';
		}
		$combined_style .= 'display:none;';

		// Attribute used to stash the URL on the hidden element for later restore.
		$data_url_attr = $tag === 'object' ? 'data-surecookie-data' : 'data-surecookie-src';

		// Embed dimensions from the tag, used only as a pre-paint fallback size.
		$original_width  = preg_match( '/(?:^|\s)width\s*=\s*["\']?(\d+)/i', $attributes, $w_match ) === 1 ? (int) $w_match[1] : 0;
		$original_height = preg_match( '/(?:^|\s)height\s*=\s*["\']?(\d+)/i', $attributes, $h_match ) === 1 ? (int) $h_match[1] : 0;

		// Resolve the optional placeholder image; the overlay renderer resolves the
		// admin-editable copy and button label itself.
		$image = $this->resolve_placeholder_image( $name, $mapped_category, $url );

		// Fallback only; consentManager.matchPlaceholderSizes() then sets the
		// exact height from the embed's real (often CSS-driven) rendered box.
		if ( $original_height > 0 ) {
			$wrapper_style = sprintf( 'width:100%%;height:%dpx;', $original_height );
		} else {
			$wrapper_style = 'width:100%;min-height:160px;';
		}

		// Outer placeholder wrapper. Carries the banner's root classes
		// (surecookie-styles + surecookie-public-banner-wrapper) so it inherits the
		// same box-sizing/font reset the banner uses and is insulated from the
		// active theme's styles, exactly like the consent banner.
		$wrapper_class = 'surecookie-styles surecookie-public-banner-wrapper surecookie-placeholder surecookie-placeholder-' . $name;
		if ( $image !== '' ) {
			$wrapper_class .= ' surecookie-placeholder-has-image';
		}
		$placeholder  = '<div class="' . esc_attr( $wrapper_class ) . '"';
		$placeholder .= ' data-surecookie-name="' . esc_attr( $name ) . '"';
		$placeholder .= ' data-surecookie-category="' . esc_attr( $mapped_category ) . '"';
		if ( $original_width > 0 ) {
			$placeholder .= ' data-surecookie-width="' . esc_attr( (string) $original_width ) . '"';
		}
		if ( $original_height > 0 ) {
			$placeholder .= ' data-surecookie-height="' . esc_attr( (string) $original_height ) . '"';
		}
		$placeholder .= ' style="' . esc_attr( $wrapper_style ) . '"';
		$placeholder .= '>';

		$placeholder .= $this->render_placeholder_overlay( $mapped_category, $label, $image );

		// Hidden element (restored on consent).
		$placeholder .= '<' . $tag;
		$placeholder .= ' ' . $data_url_attr . '="' . esc_url( $url ) . '"';
		$placeholder .= ' data-surecookie-name="' . esc_attr( $name ) . '"';
		$placeholder .= ' data-surecookie-category="' . esc_attr( $mapped_category ) . '"';

		$trimmed_attrs = trim( (string) $new_attributes );
		if ( $trimmed_attrs !== '' ) {
			$placeholder .= ' ' . $trimmed_attrs;
		}

		$placeholder .= ' style="' . esc_attr( $combined_style ) . '"';

		if ( $tag === 'embed' ) {
			// <embed> is void / self-closing.
			$placeholder .= ' />';
		} else {
			$placeholder .= '>' . $inner . '</' . $tag . '>';
		}

		$placeholder .= '</div>';

		return $placeholder;
	}

	/**
	 * Map API category names to SureCookie category names.
	 *
	 * Reached from the output-buffer display handler, where PHP forbids opening a
	 * buffer: an `ob_start()` in a callback is an uncatchable fatal that discards
	 * the whole response. Keep callbacks to pure string work.
	 *
	 * @param string $category Original category name.
	 * @since 0.0.1
	 * @return string Mapped category name.
	 */
	private function map_category( string $category ): string {
		$filtered = apply_filters( 'surecookie_map_category', $category, $category );
		return is_string( $filtered ) ? $filtered : $category;
	}

	/**
	 * Check if this request is from the SaaS scanner with a valid bypass token.
	 *
	 * @since 0.0.0-alpha.2
	 * @since 0.0.1-beta.2 Strict 64-char hex validation; replaces sanitize_text_field().
	 * @since 1.3.0 Delegates to Utils so integrations that gate outside the output buffer share it.
	 * @return bool
	 */
	private function is_scan_bypass_request(): bool {
		return Utils::is_scan_bypass_request();
	}
}
