<?php
/**
 * Elementor - Video Widget Handler
 *
 * Elementor's native Video widget does not put its embed in the page HTML. For
 * YouTube it renders an empty `<div class="elementor-video">` plus a
 * `data-settings` JSON blob, and its frontend JS builds the `<iframe>` in the
 * browser after load. The tag-level blocking passes only ever see the finished
 * server HTML, so there is no embed there to gate and the video reaches Google
 * before the visitor answers the banner.
 *
 * This pass gates the widget instead of the embed: it renames `data-widget_type`
 * so Elementor never dispatches `frontend/element_ready/video.default` (the
 * handler that builds the iframe never runs at all), and swaps the empty
 * container for the standard consent placeholder. On accept, consentManager.js
 * puts both back and calls Elementor's own `runReadyTrigger()`, so the video
 * appears in place with no reload.
 *
 * Widget variants that already render a real `<iframe>` server-side (Vimeo) or
 * only build one on click (lightbox) are left alone: the first is handled by the
 * core iframe pass, and the second never contacts the provider on load.
 *
 * @package SureCookie\Inc\Integrations\Elementor
 * @since   1.4.0
 */

namespace SureCookie\Inc\Integrations\Elementor;

use SureCookie\Inc\Modules\ScriptBlocking\Matched_Resources;
use SureCookie\Inc\Modules\ScriptBlocking\Resource_Categories;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Traits\PlaceholderContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Video_Widget class.
 *
 * @since 1.4.0
 */
class Video_Widget {
	use GetInstance;
	use PlaceholderContent;

	/**
	 * Elementor `video_type` values mapped to the catalog service key that owns
	 * their blocking rule and category. `vimeo` and `hosted` are absent on
	 * purpose - see the file docblock.
	 */
	private const PROVIDER_SERVICES = [
		'youtube'     => 'youtube',
		'dailymotion' => 'dailymotion',
		'videopress'  => 'videopress',
	];

	/**
	 * Matches a video widget wrapper immediately followed by the empty container
	 * Elementor fills in from JavaScript. Capture 1 is the wrapper tag, capture 2
	 * is whatever sits between it and the container (an `.elementor-widget-container`
	 * div, or nothing when Elementor's optimized markup is on).
	 *
	 * The length bound on capture 2 keeps a widget that is NOT the client-side
	 * shape from matching the next widget's container further down the page.
	 */
	private const WIDGET_PATTERN = '#(<div\b[^>]*\bdata-widget_type="video\.default"[^>]*>)(.{0,400}?)<div class="elementor-video"></div>#s';

	/**
	 * Constructor.
	 *
	 * @since 1.4.0
	 */
	private function __construct() {
		add_filter( 'surecookie_blocked_buffer', [ $this, 'gate_video_widgets' ] );
	}

	/**
	 * Replace every client-side-built video widget with a consent placeholder.
	 *
	 * @since 1.4.0
	 * @param string $buffer Page HTML.
	 * @return string
	 */
	public function gate_video_widgets( $buffer = '' ): string {
		$buffer = (string) $buffer;

		// Cheap bail-out: most pages carry no video widget at all, and the regex
		// below runs over the whole document.
		if ( strpos( $buffer, 'data-widget_type="video.default"' ) === false ) {
			return $buffer;
		}

		$result = preg_replace_callback(
			self::WIDGET_PATTERN,
			function ( $matches ) {
				return $this->gate_widget( $matches[0], $matches[1], $matches[2] );
			},
			$buffer
		);

		return is_string( $result ) ? $result : $buffer;
	}

	/**
	 * Gate one matched widget, or return it untouched when it carries no provider
	 * we recognise.
	 *
	 * @since 1.4.0
	 * @param string $full_match  The whole matched fragment.
	 * @param string $wrapper_tag The widget's opening tag.
	 * @param string $between     Markup between the wrapper and the video container.
	 * @return string
	 */
	private function gate_widget( string $full_match, string $wrapper_tag, string $between ): string {
		$settings = $this->parse_settings( $wrapper_tag );
		$provider = isset( $settings['video_type'] ) && is_string( $settings['video_type'] )
			? strtolower( $settings['video_type'] )
			: '';

		if ( ! isset( self::PROVIDER_SERVICES[ $provider ] ) ) {
			return $full_match;
		}

		$service = self::PROVIDER_SERVICES[ $provider ];
		$catalog = $this->resolve_catalog_service( $service );
		if ( $catalog === null ) {
			return $full_match;
		}

		// The embed URL the widget would have built, used only to resolve an admin
		// category override and the video's own thumbnail - it is never emitted.
		$url      = $this->embed_url( $provider, $settings );
		$category = Resource_Categories::resolve( $url, $catalog['category'], 'iframe' );

		/**
		 * Filter: skip consent gating for a specific Elementor video widget.
		 *
		 * @since 1.4.0
		 * @param bool                 $skip     True to leave the widget untouched.
		 * @param string               $service  Matched catalog service key.
		 * @param string               $category Resolved category.
		 * @param array<string, mixed> $settings The widget's decoded `data-settings`.
		 */
		if ( apply_filters( 'surecookie_skip_elementor_video', false, $service, $category, $settings ) ) {
			return $full_match;
		}

		// The placeholder carries no provider URL, so no scan can discover this
		// embed. Record it or it has no row on any admin screen.
		Matched_Resources::get_instance()->record( 'iframe', $url, $service, $category );

		// An essential-category embed is never gated, matching the tag passes.
		if ( $this->is_skippable_category( $category ) ) {
			return $full_match;
		}

		// Renaming the attribute is what actually stops the load: Elementor keys
		// `frontend/element_ready/{widget_type}` off it, so its video handler is
		// never dispatched and no iframe is ever built.
		$gated_tag = (string) preg_replace( // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- Static replacement string, no /e modifier; preg_replace_callback would add nothing.
			'#\bdata-widget_type=#',
			'data-surecookie-widget-type=',
			$wrapper_tag,
			1
		);

		return $gated_tag . $between . $this->build_placeholder( $service, $category, $catalog['label'], $url, $settings );
	}

	/**
	 * Decode the widget's `data-settings` JSON.
	 *
	 * @since 1.4.0
	 * @param string $wrapper_tag The widget's opening tag.
	 * @return array<string, mixed>
	 */
	private function parse_settings( string $wrapper_tag ): array {
		if ( preg_match( '#\bdata-settings="([^"]*)"#', $wrapper_tag, $match ) !== 1 ) {
			return [];
		}

		$decoded = json_decode( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ), true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Rebuild the embed URL Elementor would have loaded, so category overrides and
	 * thumbnails resolve off the same host the visitor would otherwise have hit.
	 *
	 * @since 1.4.0
	 * @param string               $provider Elementor `video_type`.
	 * @param array<string, mixed> $settings Decoded widget settings.
	 * @return string Embed URL, or '' when the widget carries no usable URL.
	 */
	private function embed_url( string $provider, array $settings ): string {
		$source = (string) ( $settings[ $provider . '_url' ] ?? '' );
		if ( $source === '' ) {
			return '';
		}

		if ( $provider !== 'youtube' ) {
			return $source;
		}

		if ( preg_match( '#[?&]v=([A-Za-z0-9_-]+)#', $source, $match ) !== 1 ) {
			return $source;
		}

		// yt_privacy is Elementor's "Privacy Mode", which serves the player from
		// youtube-nocookie.com. Both hosts carry their own catalog pattern.
		$host = ! empty( $settings['yt_privacy'] ) ? 'www.youtube-nocookie.com' : 'www.youtube.com';

		return 'https://' . $host . '/embed/' . $match[1];
	}

	/**
	 * Build the placeholder that replaces the empty video container.
	 *
	 * @since 1.4.0
	 * @param string               $service  Matched catalog service key.
	 * @param string               $category Resolved category.
	 * @param string               $label    Vendor label.
	 * @param string               $url      Embed URL (thumbnail resolution only).
	 * @param array<string, mixed> $settings Decoded widget settings.
	 * @return string
	 */
	private function build_placeholder( string $service, string $category, string $label, string $url, array $settings ): string {
		$image = $this->resolve_placeholder_image( $service, $category, $url );

		// Banner root classes so the placeholder inherits the banner's reset and is
		// insulated from the theme, exactly like every other blocked embed.
		$class = 'surecookie-styles surecookie-public-banner-wrapper surecookie-placeholder surecookie-placeholder-elementor surecookie-placeholder-' . $service;
		if ( $image !== '' ) {
			$class .= ' surecookie-placeholder-has-image';
		}

		$out  = '<div class="' . esc_attr( $class ) . '"';
		$out .= ' data-surecookie-name="' . esc_attr( $service ) . '"';
		$out .= ' data-surecookie-category="' . esc_attr( $category ) . '"';
		// Match the widget's own aspect ratio so accepting doesn't shift the page.
		// There is no embed here for matchPlaceholderSizes() to measure - the
		// container it would have sized is exactly what this replaces.
		$out .= ' style="' . esc_attr( 'width:100%;aspect-ratio:' . $this->aspect_ratio( $settings ) . ';' ) . '">';
		$out .= $this->render_placeholder_overlay( $category, $label, $image );
		$out .= '</div>';

		return $out;
	}

	/**
	 * The widget's aspect ratio as a CSS value. Elementor stores it with the colon
	 * dropped ("169" is 16:9) and omits the setting entirely while it is on the
	 * default.
	 *
	 * @since 1.4.0
	 * @param array<string, mixed> $settings Decoded widget settings.
	 * @return string
	 */
	private function aspect_ratio( array $settings ): string {
		$ratios = [
			'169' => '16/9',
			'219' => '21/9',
			'43'  => '4/3',
			'32'  => '3/2',
			'11'  => '1/1',
			'916' => '9/16',
		];

		$ratio = (string) ( $settings['aspect_ratio'] ?? '' );

		return $ratios[ $ratio ] ?? '16/9';
	}
}
