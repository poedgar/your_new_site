<?php
/**
 * Presto Player - Block Handler
 *
 * Wraps every server-rendered Presto Player block (YouTube, Vimeo, Bunny.net,
 * self-hosted video, audio) in a SureCookie placeholder. The original Presto
 * markup is stashed inside an inert `<template>` element so its `<presto-player>`
 * custom element is never upgraded (no third-party iframe loads, no cookies set)
 * until accept.
 *
 * Gating is intentionally consent-agnostic on the server (cache-safe): the block
 * is always wrapped regardless of the request's consent cookie, so a full-page
 * cache can't serve an un-gated player warmed by a consented visitor.
 * consentManager.js restores the block for visitors who have already consented,
 * cloning the template's content back into the DOM. Presto's runtime - already
 * loaded because its render_callback ran - then auto-upgrades the new
 * `<presto-player>` element. Same UX as raw YouTube/Vimeo embeds: in-place
 * restore, no reload. This mirrors the core script blocker's model.
 *
 * @package SureCookie\Inc\Integrations\PrestoPlayer
 * @since 1.2.4
 */

namespace SureCookie\Inc\Integrations\PrestoPlayer;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\ScriptBlocking\Matched_Resources;
use SureCookie\Inc\Modules\ScriptBlocking\Resource_Categories;
use SureCookie\Inc\Modules\ScriptBlocking\Utils;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Traits\PlaceholderContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Block_Handler class.
 *
 * @since 1.2.4
 */
class Block_Handler {
	use GetInstance;
	use PlaceholderContent;

	/**
	 * Container blocks that render through `render_block` but build the player
	 * indirectly (Media Hub / reusable video, playlist, popup media) - so the
	 * inner provider block never reaches this filter. Handled by resolving the
	 * referenced video's provider from its post.
	 */
	private const CONTAINER_BLOCKS = [
		'presto-player/reusable-display',
		'presto-player/media-hub',
		'presto-player/playlist',
		'presto-player/popup-trigger',
	];

	/**
	 * Map of Presto Player block names → service / category / label.
	 */
	private const PROVIDER_MAP = [
		'presto-player/youtube'     => [
			'service'  => 'youtube',
			'category' => 'marketing',
			'label'    => 'YouTube',
		],
		'presto-player/vimeo'       => [
			'service'  => 'vimeo',
			'category' => 'marketing',
			'label'    => 'Vimeo',
		],
		'presto-player/bunny'       => [
			'service'  => 'bunny',
			'category' => 'marketing',
			'label'    => 'Bunny.net',
		],
		'presto-player/self-hosted' => [
			'service'  => 'self-hosted',
			'category' => 'functional',
			'label'    => 'Video',
		],
		'presto-player/audio'       => [
			'service'  => 'audio',
			'category' => 'functional',
			'label'    => 'Audio',
		],
	];

	/**
	 * Presto provider key => catalog service slug, where the two differ.
	 *
	 * Presto calls its Bunny Stream provider `bunny`; the catalog has no service
	 * by that name, only `bunny-stream` (bunny-cdn and bunny-fonts are different
	 * products). Anything absent here uses the provider key as the slug.
	 */
	private const CATALOG_SLUGS = [
		'bunny' => 'bunny-stream',
	];

	/**
	 * Context key marking a block as rendering inside a Presto popup. Popups
	 * render their player through the WP Interactivity API in a `wp_footer`
	 * template, which the placeholder/restore mechanism can't safely wrap - so
	 * we leave popup videos to Presto (they stay inert there until opened).
	 */
	private const POPUP_CONTEXT = 'surecookie/inPrestoPopup';

	/**
	 * Constructor.
	 *
	 * @since 1.2.4
	 */
	private function __construct() {
		add_filter( 'render_block', [ $this, 'wrap_block' ], 10, 3 );
		add_filter( 'render_block_context', [ $this, 'flag_popup_descendant' ], 10, 3 );
	}

	/**
	 * Mark a block as rendering inside a Presto popup.
	 *
	 * Carried in the block's own context rather than on the handler, so it
	 * belongs to one block instead of to whichever block filtered last. Core
	 * merges a changed context into `available_context`, which every descendant
	 * inherits, so re-reading the parent's flag walks the whole subtree and not
	 * just the direct children the old boolean covered.
	 *
	 * @since 1.2.4
	 * @since 1.5.0 Context key instead of shared instance state.
	 * @param array<string, mixed> $context      Block context.
	 * @param array<string, mixed> $parsed_block The block about to render.
	 * @param \WP_Block|null       $parent_block The parent block, if any.
	 * @return array<string, mixed>
	 */
	public function flag_popup_descendant( $context = [], $parsed_block = [], $parent_block = null ): array {
		unset( $parsed_block );
		$context = is_array( $context ) ? $context : [];

		if ( ! $parent_block instanceof \WP_Block ) {
			return $context;
		}

		// Only the footer player (popup-media's subtree) is skipped; the popup
		// trigger is gated normally, since blocking it stops the popup opening.
		$inherited = is_array( $parent_block->context ) && ! empty( $parent_block->context[ self::POPUP_CONTEXT ] );
		if ( $inherited || $parent_block->name === 'presto-player/popup-media' ) {
			$context[ self::POPUP_CONTEXT ] = true;
		}

		return $context;
	}

	/**
	 * Wrap rendered Presto blocks in a consent placeholder.
	 *
	 * Pass-through for non-Presto blocks, popup descendants, admin/AJAX/REST
	 * renders, and when blocking is globally off (geo-rules, etc.). Wrapping is
	 * consent-agnostic on the server (cache-safe); consentManager.js restores the
	 * block client-side for visitors who have already consented.
	 *
	 * @since 1.2.4
	 * @param string               $block_content The rendered block markup.
	 * @param array<string, mixed> $block         The block array (`blockName`, `attrs`, ...).
	 * @param mixed                $instance      The rendering `WP_Block`, when core passes one.
	 * @return string
	 */
	public function wrap_block( $block_content = '', $block = [], $instance = null ): string {
		// Cast once - render_block can pass non-string $block_content for some
		// dynamic blocks; the method return type requires string.
		$block_content = (string) $block_content;
		$block_name    = is_array( $block ) ? (string) ( $block['blockName'] ?? '' ) : '';

		$is_container = in_array( $block_name, self::CONTAINER_BLOCKS, true );
		if ( $block_name === '' || ( ! isset( self::PROVIDER_MAP[ $block_name ] ) && ! $is_container ) ) {
			return $block_content;
		}

		// Inside a popup: leave it to Presto (its Interactivity footer template
		// keeps the player inert until the popup is opened anyway).
		if ( $instance instanceof \WP_Block && is_array( $instance->context )
			&& ! empty( $instance->context[ self::POPUP_CONTEXT ] ) ) {
			return $block_content;
		}

		// Skip the editor preview (REST), admin pages, AJAX - admins need to
		// see the actual player while configuring blocks.
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $block_content;
		}

		if ( ! Utils::is_blocking_enabled() || ! Utils::should_process_based_on_geo() ) {
			return $block_content;
		}

		// Let the scanner see the real player. Unlike a blocked <script>/<iframe>,
		// the placeholder below keeps only the service name and category - the
		// provider URL is gone - so a gated Presto block is undetectable by any
		// scan, and its host (e.g. a Bunny Stream delivery zone) never reaches the
		// cookie catalog. The Blocker short-circuits on the same token, but that
		// only covers the output buffer, and `render_block` runs before it.
		if ( Utils::is_scan_bypass_request() ) {
			return $block_content;
		}

		$attributes = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];

		$provider = $is_container
			? $this->resolve_container_provider( $block_name, $attributes, $block )
			: $this->resolve_provider( $block_name, $attributes );
		if ( $provider === null ) {
			return $block_content;
		}

		// PROVIDER_MAP is only a fallback. The catalog owns the category, so an
		// admin recategorising a service is honoured here too - otherwise the same
		// service can be functional in the catalog and marketing here, and no
		// setting on any screen can reconcile the two.
		$provider['category'] = $this->resolve_provider_category( $provider, $block_name, $attributes );

		// The placeholder keeps only the service name and category, so the host is
		// gone by the time anything else looks. Record it now or this video is
		// invisible on every admin screen.
		Matched_Resources::get_instance()->record(
			'iframe',
			$this->resolve_embed_src( $block_name, $attributes ),
			self::CATALOG_SLUGS[ $provider['service'] ] ?? (string) $provider['service'],
			(string) $provider['category']
		);

		/**
		 * Filter: Allow bypassing Presto-block consent gating for a specific block.
		 *
		 * @since 1.2.4
		 * @param bool                                                     $skip       True to skip blocking (default false).
		 * @param string                                                   $block_name Presto block name.
		 * @param array<string, mixed>                                     $attributes Block attributes.
		 * @param array{service: string, category: string, label: string}  $provider   Resolved provider info.
		 */
		if ( apply_filters( 'surecookie_skip_presto_block', false, $block_name, $attributes, $provider ) ) {
			return $block_content;
		}

		// An essential-category embed is never gated, matching the tag passes and
		// the Elementor handler.
		if ( $this->is_skippable_category( (string) $provider['category'] ) ) {
			return $block_content;
		}

		// Always wrap server-side (cache-safe): the rendered HTML must not vary on
		// the visitor's consent cookie, or a full-page cache warmed by a consented
		// visitor would serve un-gated players to everyone. consentManager.js
		// restores the block in place for visitors who have already consented - the
		// same model the core script blocker uses for iframes/scripts.
		return $this->build_placeholder( $provider, $attributes, $block_content, $block_name );
	}

	/**
	 * Build the placeholder wrapper. Visible overlay + the original Presto
	 * markup, escaped into an attribute for client-side restoration.
	 *
	 * @since 1.2.4
	 * @param array{service: string, category: string, label: string} $provider      Resolved provider info.
	 * @param array<string, mixed>                                    $attributes    Block attributes.
	 * @param string                                                  $block_content Original rendered block.
	 * @param string                                                  $block_name    Presto block name (for poster resolution).
	 * @return string
	 */
	private function build_placeholder( array $provider, array $attributes, string $block_content, string $block_name ): string {
		$service  = $provider['service'];
		$category = $provider['category'];

		$is_audio      = $service === 'audio';
		$wrapper_style = $this->aspect_ratio_style( $is_audio, $attributes );

		// Resolve the placeholder image so Presto matches the custom placeholder:
		// the block's own Presto poster wins, then the shared filter / global
		// placeholder_image fallback. Audio blocks skip the image - the short bar
		// would hard-crop a poster.
		$poster = $is_audio ? '' : $this->resolve_poster( $block_name, $attributes );
		// Pass the block's embed src so a YouTube-sourced block WITHOUT a
		// poster can still derive that video's own thumbnail (opt-in via
		// placeholder_video_thumbnails, same as core-blocked iframes).
		$src   = $is_audio ? '' : $this->resolve_embed_src( $block_name, $attributes );
		$image = $is_audio
			? ''
			: ( $poster !== '' ? $poster : $this->resolve_placeholder_image( $service, $category, $src ) );

		// Banner root classes (surecookie-styles + surecookie-public-banner-wrapper)
		// so the placeholder inherits the banner's reset and is insulated from the
		// active theme, exactly like the consent banner.
		$wrapper_class = 'surecookie-styles surecookie-public-banner-wrapper surecookie-placeholder surecookie-placeholder-presto surecookie-placeholder-' . $service;
		if ( $image !== '' ) {
			$wrapper_class .= ' surecookie-placeholder-has-image';
		}

		$out  = '<div class="' . esc_attr( $wrapper_class ) . '"';
		$out .= ' data-surecookie-name="' . esc_attr( $service ) . '"';
		$out .= ' data-surecookie-category="' . esc_attr( $category ) . '"';
		if ( $wrapper_style !== '' ) {
			$out .= ' style="' . esc_attr( $wrapper_style ) . '"';
		}
		$out .= '>';

		// Presto keeps its own media-aware copy; everything else about the overlay
		// is the shared one, so the two builders can't drift apart.
		$out .= $this->render_placeholder_overlay( $category, $provider['label'], $image, $this->placeholder_text( $provider['label'] ) );

		// After the overlay so the payload cannot answer a `:first-child` rule.
		$out .= $this->restore_payload( $block_content );

		$out .= '</div>';

		return $out;
	}

	/**
	 * The gated block's markup, parked where only this plugin can have put it.
	 *
	 * Provenance is the whole point. `class` and `data-*` survive `wp_kses_post()`
	 * on a div, so while the payload rode in `data-surecookie-restore` any author
	 * without `unfiltered_html` could fabricate a carrier and have the frontend
	 * re-create it as running script (#1345). KSES strips `<script>` from authored
	 * content outright, so this element cannot be forged through post content.
	 *
	 * JSON, not markup: `JSON_HEX_TAG` escapes `<` and `>`, so a payload containing
	 * `</script>` cannot close the element early. And unlike the `<template>` this
	 * module used before, a script's content is text rather than child elements, so
	 * a pass that re-serialises the region cannot lift the real `<presto-player>`
	 * out and leave it live behind the overlay.
	 *
	 * @since 1.7.0
	 * @param string $block_content Original rendered block.
	 * @return string
	 */
	private function restore_payload( string $block_content ): string {
		// JSON_INVALID_UTF8_SUBSTITUTE is not optional, for the same reason
		// ENT_SUBSTITUTE was not: on a non-UTF-8 blog_charset one bad byte makes
		// the whole encode fail, and a placeholder with no payload unwraps to
		// nothing, losing the player.
		$json = wp_json_encode(
			$block_content,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);

		if ( ! is_string( $json ) ) {
			return '';
		}

		return '<script type="application/json" class="surecookie-presto-restore">' . $json . '</script>';
	}

	/**
	 * Effective consent category for a Presto block.
	 *
	 * Catalog first, the hardcoded provider map as a fallback for services the
	 * catalog does not carry (self-hosted video, audio, popups), then any admin
	 * override keyed on the host the embed would have loaded from.
	 *
	 * @param array{service: string, category: string, label: string} $provider   Resolved provider.
	 * @param string                                                  $block_name Presto block name.
	 * @param array<string, mixed>                                    $attributes Block attributes.
	 * @since 1.5.0
	 * @return string
	 */
	private function resolve_provider_category( array $provider, string $block_name, array $attributes ): string {
		$service  = (string) $provider['service'];
		$slug     = self::CATALOG_SLUGS[ $service ] ?? $service;
		$catalog  = $this->resolve_catalog_service( $slug );
		$category = $catalog['category'] ?? (string) $provider['category'];

		$src = $this->resolve_embed_src( $block_name, $attributes );

		return $src === '' ? $category : Resource_Categories::resolve( $src, $category, 'iframe' );
	}

	/**
	 * Resolve the Presto Player poster image for a block. Direct provider blocks
	 * carry it as `attrs['poster']`; reusable / media-hub containers reference a
	 * video post whose poster is read via Presto's own model. Returns '' when no
	 * poster is available (falls through to the global placeholder image).
	 *
	 * @since 1.4.0
	 * @param string               $block_name Presto block name.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string Poster URL or ''.
	 */
	private function resolve_poster( string $block_name, array $attributes ): string {
		if ( isset( $attributes['poster'] ) && is_string( $attributes['poster'] ) && $attributes['poster'] !== '' ) {
			return esc_url_raw( $attributes['poster'] );
		}

		$containers = [ 'presto-player/reusable-display', 'presto-player/media-hub' ];
		if (
			in_array( $block_name, $containers, true )
			&& isset( $attributes['id'] )
			&& class_exists( '\PrestoPlayer\Models\ReusableVideo' )
		) {
			$poster = ( new \PrestoPlayer\Models\ReusableVideo( absint( $attributes['id'] ) ) )->getPosterFromBlock();
			return is_string( $poster ) ? esc_url_raw( $poster ) : '';
		}

		return '';
	}

	/**
	 * Resolve the embed source URL for a block, mirroring resolve_poster:
	 * direct provider blocks carry it as `attrs['src']`; reusable / media-hub
	 * containers reference a video post whose inner block carries it. Used to
	 * derive the video's own thumbnail when no poster is set.
	 *
	 * @since 1.4.0
	 * @param string               $block_name Presto block name.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string Embed URL or ''.
	 */
	private function resolve_embed_src( string $block_name, array $attributes ): string {
		if ( isset( $attributes['src'] ) && is_string( $attributes['src'] ) && $attributes['src'] !== '' ) {
			return $attributes['src'];
		}

		$containers = [ 'presto-player/reusable-display', 'presto-player/media-hub' ];
		if (
			in_array( $block_name, $containers, true )
			&& isset( $attributes['id'] )
			&& class_exists( '\PrestoPlayer\Models\ReusableVideo' )
		) {
			$block = ( new \PrestoPlayer\Models\ReusableVideo( absint( $attributes['id'] ) ) )->getBlock();
			$src   = is_array( $block ) ? ( $block['attrs']['src'] ?? '' ) : '';
			return is_string( $src ) ? $src : '';
		}

		return '';
	}

	/**
	 * Placeholder overlay text. A custom admin placeholder_description wins (with
	 * `{service}` expansion via the shared trait); otherwise Presto's media-aware
	 * default wording is used so generic "Video"/"Audio" labels read naturally.
	 *
	 * @since 1.4.0
	 * @param string $label Provider label.
	 * @return string
	 */
	private function placeholder_text( string $label ): string {
		$custom   = (string) Settings::get( 'placeholder_description' );
		$defaults = Settings::get_settings_defaults();
		$default  = is_array( $defaults ) ? (string) ( $defaults['placeholder_description'] ?? '' ) : '';

		// Honor the admin's description only when they've actually customized it
		// (the setting ships with a non-empty default, so compare against it);
		// otherwise keep Presto's media-aware wording, which reads better for the
		// generic "Video"/"Audio" labels than "requires Video cookies".
		if ( $custom !== '' && $custom !== $default ) {
			return self::placeholder_description( $label );
		}

		return $this->placeholder_message( $label );
	}

	/**
	 * Inline `aspect-ratio` style derived from block attributes, so the
	 * placeholder covers the same visual footprint Presto would have.
	 *
	 * @since 1.2.4
	 * @param bool                 $is_audio   True for audio blocks (no aspect ratio).
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string CSS declaration (with trailing semicolon) or empty string.
	 */
	private function aspect_ratio_style( bool $is_audio, array $attributes ): string {
		if ( $is_audio ) {
			return '';
		}

		$ratio = isset( $attributes['aspect_ratio'] ) && is_string( $attributes['aspect_ratio'] )
			? trim( $attributes['aspect_ratio'] )
			: '';

		// Presto stores ratios like "16:9", "4:3", "auto", "". Default to 16:9 for video.
		if ( $ratio === '' || $ratio === 'auto' || ! preg_match( '/^\d+\s*:\s*\d+$/', $ratio ) ) {
			$ratio = '16:9';
		}

		return 'aspect-ratio:' . str_replace( ':', '/', $ratio ) . ';';
	}

	/**
	 * Build the translated placeholder message for a provider label.
	 *
	 * Generic media labels ("Video", "Audio") get a self-contained sentence so
	 * they never read as "requires Video cookies". Brand-name labels (YouTube,
	 * Vimeo, Bunny.net) are proper nouns dropped into the branded message.
	 *
	 * @since 1.2.4
	 * @param string $label Raw label from PROVIDER_MAP / container resolution.
	 * @return string
	 */
	private function placeholder_message( string $label ): string {
		if ( $label === 'Audio' ) {
			return __( 'This audio is blocked until you accept cookies to load it.', 'surecookie' );
		}

		if ( $label === 'Video' ) {
			return __( 'This video is blocked until you accept cookies to load it.', 'surecookie' );
		}

		return sprintf(
			/* translators: %s: Service name (e.g., YouTube, Vimeo, Bunny.net). */
			__( 'This content is blocked because it requires %s cookies.', 'surecookie' ),
			$label
		);
	}

	/**
	 * Resolve provider info from the block name, after filter overrides.
	 *
	 * @since 1.2.4
	 * @param string               $block_name Presto block name.
	 * @param array<string, mixed> $attributes Block attributes (for filter context).
	 * @return array{service: string, category: string, label: string}|null
	 */
	private function resolve_provider( string $block_name, array $attributes ): ?array {
		/**
		 * Filter: Override the default Presto block → service/category map.
		 *
		 * @since 1.2.4
		 * @param array<string, array{service: string, category: string, label: string}> $map        Provider map.
		 * @param string                                                                  $block_name Presto block name.
		 * @param array<string, mixed>                                                    $attributes Block attributes.
		 */
		$map = apply_filters( 'surecookie_presto_provider_map', self::PROVIDER_MAP, $block_name, $attributes );

		if ( ! is_array( $map ) || ! isset( $map[ $block_name ] ) || ! is_array( $map[ $block_name ] ) ) {
			return null;
		}

		$entry = $map[ $block_name ];
		if ( empty( $entry['service'] ) || empty( $entry['category'] ) || empty( $entry['label'] ) ) {
			return null;
		}

		return [
			'service'  => (string) $entry['service'],
			'category' => (string) $entry['category'],
			'label'    => (string) $entry['label'],
		];
	}

	/**
	 * Resolve provider info for a container block (reusable / media-hub / playlist).
	 *
	 * @since 1.2.4
	 * @param string               $block_name Container block name.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $block      Full block array (for playlist inner items).
	 * @return array{service: string, category: string, label: string} Always fail-closed (never null).
	 */
	private function resolve_container_provider( string $block_name, array $attributes, array $block ): array {
		if ( $block_name === 'presto-player/playlist' ) {
			return $this->resolve_playlist_provider( $block );
		}

		// Popup trigger: gating the trigger button/thumbnail blocks the whole
		// popup (it can't open). The video lives in a sibling block, so the
		// provider isn't knowable here - gate under marketing (popups are
		// typically third-party video) with a generic label.
		if ( $block_name === 'presto-player/popup-trigger' ) {
			return [
				'service'  => 'presto-popup',
				'category' => 'marketing',
				'label'    => 'Video',
			];
		}

		// reusable-display / media-hub: gate by the referenced video's provider.
		// Fail closed when the provider can't be resolved (deleted video, unmapped
		// provider, or a renamed Presto model class - init.php intentionally gates
		// only on PRESTO_PLAYER_PLUGIN_FILE, not on \ReusableVideo existing). Mirror
		// the playlist / popup-trigger defaults so a container is never left ungated.
		$inner_name = $this->reusable_inner_block_name( isset( $attributes['id'] ) ? absint( $attributes['id'] ) : 0 );
		$provider   = $inner_name === '' ? null : $this->resolve_provider( $inner_name, $attributes );

		return $provider ?? [
			'service'  => 'presto-reusable',
			'category' => 'marketing',
			'label'    => 'Video',
		];
	}

	/**
	 * Look up the inner provider block name of a reusable video post, using
	 * Presto's own model so URL-based provider detection is respected.
	 *
	 * @since 1.2.4
	 * @param int $video_id Reusable video post ID.
	 * @return string Inner block name (e.g. `presto-player/youtube`), or '' when unresolved.
	 */
	private function reusable_inner_block_name( int $video_id ): string {
		if ( $video_id <= 0 || ! class_exists( '\PrestoPlayer\Models\ReusableVideo' ) ) {
			return '';
		}

		$inner = ( new \PrestoPlayer\Models\ReusableVideo( $video_id ) )->getBlock();
		return is_array( $inner ) ? (string) ( $inner['blockName'] ?? '' ) : '';
	}

	/**
	 * Resolve provider info for a playlist. Playlists render their items as JSON
	 * for a JS component (no per-item render_block), so the whole playlist is
	 * gated. Category is the strictest across items (marketing wins). Any item
	 * that resolves to marketing - or any item that cannot be resolved at all -
	 * forces the whole playlist to marketing, so it is never left ungated or
	 * silently downgraded to functional.
	 *
	 * @since 1.2.4
	 * @param array<string, mixed> $block Full playlist block array.
	 * @return array{service: string, category: string, label: string}
	 */
	private function resolve_playlist_provider( array $block ): array {
		$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];
		$category     = 'functional';
		// Raw label - placeholder_message() keys on it, then translates the full
		// sentence, so keep it untranslated here.
		$label = 'Video';
		$found = false;

		foreach ( $inner_blocks as $item ) {
			$attrs = is_array( $item['attrs'] ?? null ) ? $item['attrs'] : [];
			$entry = $this->resolve_provider(
				$this->reusable_inner_block_name( isset( $attrs['id'] ) ? absint( $attrs['id'] ) : 0 ),
				[]
			);
			// An unresolvable item may itself be a marketing provider (orphaned or
			// deleted reference, renamed Presto model). Fail closed: gate the whole
			// playlist as marketing rather than risk downgrading it to functional.
			if ( $entry === null ) {
				return [
					'service'  => 'playlist',
					'category' => 'marketing',
					'label'    => $label,
				];
			}
			if ( ! $found ) {
				$label = $entry['label'];
				$found = true;
			}
			if ( $entry['category'] === 'marketing' ) {
				return [
					'service'  => 'playlist',
					'category' => 'marketing',
					'label'    => $entry['label'],
				];
			}
		}

		return [
			'service'  => 'playlist',
			'category' => $found ? $category : 'marketing',
			'label'    => $label,
		];
	}
}
