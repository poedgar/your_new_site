<?php
/**
 * Placeholder Content Trait.
 *
 * Shared resolution of the blocked-content placeholder's custom pieces - image
 * (video thumbnail / integration filter / global setting), admin-editable
 * description ({service} token), and button label. Consumed by both the core
 * Blocker (iframe/embed/object) and the Presto Player block handler so the two
 * placeholder builders render identical, settings-aware markup and cannot drift.
 *
 * @package SureCookie
 * @since   1.4.0
 */

namespace SureCookie\Inc\Traits;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Integrations\Multilingual\Translation_Filter;
use SureCookie\Inc\Modules\ScriptBlocking\Blocker;
use SureCookie\Inc\Modules\ScriptBlocking\Blocking_Surface;

defined( 'ABSPATH' ) || exit;

/**
 * Trait PlaceholderContent.
 *
 * @since 1.4.0
 */
trait PlaceholderContent {
	/**
	 * Admin-editable placeholder description, with `{service}` replaced by the
	 * vendor label. Returns rich text (sanitized on save); render with
	 * wp_kses_post() so basic formatting survives.
	 *
	 * @since 1.4.0
	 * @param string $label Vendor label.
	 * @return string
	 */
	public static function placeholder_description( string $label ): string {
		return str_replace( '{service}', $label, self::placeholder_description_template() );
	}

	/**
	 * The placeholder description with its `{service}` token still in place.
	 *
	 * Split out so the client-side builder for runtime-blocked embeds renders the
	 * same copy as the server-side one, from the same setting, rather than
	 * carrying its own default.
	 *
	 * @since 1.4.0
	 * @return string Rich text containing a `{service}` token.
	 */
	public static function placeholder_description_template(): string {
		$template = (string) Settings::get( 'placeholder_description' );

		// Translate the raw stored template BEFORE any manipulation: WPML /
		// Polylang string translation is keyed off the exact value registered by
		// String_Registration (the raw setting). The `{service}` token is expanded
		// afterwards so translators keep it in their translations.
		if ( $template !== '' ) {
			$template = Translation_Filter::translate_string( $template, 'surecookie_placeholder_description', true );
		}

		$template = self::strip_empty_paragraphs( $template );

		if ( $template !== '' ) {
			return $template;
		}

		// Says what blocking prevents, not what the service stores: many blocked
		// services (Bunny, asset CDNs) set no cookies at all.
		/* translators: %s: Service name (e.g., YouTube, Google Maps) */
		return sprintf( __( 'This content is blocked because it would connect to %s.', 'surecookie' ), '{service}' );
	}

	/**
	 * Admin-editable "Accept & Load" button label.
	 *
	 * @since 1.4.0
	 * @return string
	 */
	public static function placeholder_button_text(): string {
		$text = (string) Settings::get( 'placeholder_button_text' );
		if ( $text === '' ) {
			return __( 'Accept & Load', 'surecookie' );
		}

		// WPML/Polylang translation of the admin-entered label (registered raw by
		// String_Registration; a no-op sanitize when neither plugin is active).
		return Translation_Filter::translate_string( $text, 'surecookie_placeholder_button_text' );
	}
	/**
	 * The catalog's own category and label for a service, or null when the
	 * catalog has never heard of it.
	 *
	 * Integrations gate content the tag passes never see, so without this they
	 * have to hardcode a category - and then the catalog and the integration can
	 * disagree about the same service, with no admin setting able to reconcile
	 * them. Read the catalog instead and keep any hardcoded value as a fallback.
	 *
	 * @since 1.5.0
	 * @param string $service Catalog service key.
	 * @return array{category: string, label: string}|null
	 */
	protected function resolve_catalog_service( string $service ): ?array {
		if ( $service === '' ) {
			return null;
		}

		// Reached from the output-buffer display handler: a callback opening its own
		// buffer is an uncatchable fatal that discards the response (#1081).
		$catalog = apply_filters( 'surecookie_known_scripts', [] );
		if ( ! is_array( $catalog ) ) {
			return null;
		}

		foreach ( $catalog as $category => $services ) {
			if ( ! is_array( $services ) || ! isset( $services[ $service ] ) ) {
				continue;
			}

			$entry = is_array( $services[ $service ] ) ? $services[ $service ] : [];

			return [
				'category' => (string) $category,
				'label'    => (string) ( $entry['label'] ?? $service ),
			];
		}

		return null;
	}

	/**
	 * Whether a category is never gated, matching the tag passes.
	 *
	 * @since 1.5.0
	 * @param string $category Resolved category.
	 * @return bool
	 */
	protected function is_skippable_category( string $category ): bool {
		// Same display-handler constraint as surecookie_known_scripts above
		// (#1081): the shared reader is pure string work and opens no buffer.
		return Blocking_Surface::is_skippable_category( $category );
	}

	/**
	 * Resolve the placeholder image for a blocked resource.
	 *
	 * Priority: the embed's own thumbnail (video thumbnails, opt-in) > a
	 * filtered per-service image (e.g. a Presto poster) > the admin's global
	 * placeholder image. Empty string falls back to the text-only placeholder.
	 *
	 * @since 1.4.0
	 * @param string $name     Matched service key.
	 * @param string $category Mapped category.
	 * @param string $url      Original resource URL ('' when not applicable).
	 * @return string Image URL, or '' for text-only.
	 */
	protected function resolve_placeholder_image( string $name, string $category, string $url ): string {
		$image = '';

		// 1. The embed's own thumbnail (opt-in, since fetching it contacts the
		// provider before consent).
		if ( Settings::get( 'placeholder_video_thumbnails' ) ) {
			$image = $this->get_embed_thumbnail( $url );
		}

		/**
		 * Filter: the placeholder image for a blocked resource. Lets integrations
		 * supply a per-service image (e.g. Presto Player's poster). Receives the
		 * embed's own thumbnail (if any) so it can choose to keep or replace it.
		 *
		 * @since 1.4.0
		 * @param string $image    Resolved image URL so far (may be empty).
		 * @param string $name     Matched service key.
		 * @param string $category Mapped category.
		 * @param string $url      Original resource URL.
		 */
		$image = (string) apply_filters( 'surecookie_placeholder_image', $image, $name, $category, $url );

		// 3. The admin's global placeholder image, only when nothing else set one.
		if ( $image === '' ) {
			$image = (string) Settings::get( 'placeholder_image' );
		}

		return $image;
	}

	/**
	 * Derive a thumbnail URL from a known video embed URL. YouTube only for now;
	 * returns '' for everything else (no provider request is made server-side).
	 *
	 * @since 1.4.0
	 * @param string $url Embed URL.
	 * @return string Thumbnail URL or ''.
	 */
	protected function get_embed_thumbnail( string $url ): string {
		$video_id = '';

		// The host must be YouTube's own, not merely contain it: the leading
		// (?:^|//|\.) stops an unrelated third-party URL that happens to carry
		// "youtu.be/" in its path from triggering a pre-consent request to Google.
		// youtube.com/embed/ID, youtube-nocookie.com/embed/ID, youtu.be/ID.
		if ( preg_match( '#(?:^|//|\.)(?:youtube(?:-nocookie)?\.com/embed/|youtu\.be/)([A-Za-z0-9_-]{6,})#i', $url, $m ) === 1 ) {
			$video_id = $m[1];
		} elseif ( preg_match( '#(?:^|//|\.)youtube\.com/watch\?(?:.*&)?v=([A-Za-z0-9_-]{6,})#i', $url, $m ) === 1 ) {
			$video_id = $m[1];
		}

		// Reserved embed paths that sit where an ID normally would. They are not
		// videos, so img.youtube.com has nothing to serve and the placeholder
		// would render a broken image.
		if ( in_array( strtolower( $video_id ), [ 'videoseries', 'live_stream' ], true ) ) {
			return '';
		}

		if ( $video_id === '' ) {
			return '';
		}

		// hqdefault is always present (maxresdefault 404s for many videos).
		return 'https://img.youtube.com/vi/' . $video_id . '/hqdefault.jpg';
	}

	/**
	 * Drop empty paragraphs the rich-text editor leaves behind (`<p></p>`,
	 * `<p><br></p>`, `<p>&nbsp;</p>`), which would otherwise render as blank
	 * lines of dead space between the description and the Accept button.
	 *
	 * @since 1.4.0
	 * @param string $html Rich-text description markup.
	 * @return string
	 */
	protected static function strip_empty_paragraphs( string $html ): string {
		$stripped = preg_replace( '#<p[^>]*>(?:\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- Static replacement string, no /e modifier; preg_replace_callback is needless here.

		return trim( is_string( $stripped ) ? $stripped : $html );
	}

	/**
	 * Render the placeholder's visible overlay: optional preview image, the
	 * consent message, and the "Accept & Load" button.
	 *
	 * The wrapper and the hidden original markup differ per builder (core wraps a
	 * tag, Presto stashes a `<template>`, Elementor swaps a container), but this
	 * overlay is identical in all three, so it lives here rather than being
	 * copied a third time.
	 *
	 * @since 1.4.0
	 * @param string $category    Mapped category.
	 * @param string $label       Vendor label.
	 * @param string $image       Placeholder image URL, '' for text-only.
	 * @param string $description Pre-resolved copy; defaults to the admin-editable description.
	 * @return string
	 */
	protected function render_placeholder_overlay( string $category, string $label, string $image, string $description = '' ): string {
		// Colors resolved server-side to literal hex (the technique the banner
		// uses, minus the CSS-var mismatch): background from the palette, text
		// auto-contrasted to it. Over an image the overlay sits on a dark scrim,
		// so force white text.
		$colors     = Get::banner_display_colors();
		$content_bg = $image !== '' ? 'rgb(0 0 0 / 0.55)' : $colors['background'];
		$text_color = $image !== '' ? '#ffffff' : $colors['text'];

		$out = '';

		// Decorative; the dialog below conveys the message.
		if ( $image !== '' ) {
			$out .= '<img class="surecookie-placeholder-image" src="' . esc_url( $image ) . '" alt="" loading="lazy" aria-hidden="true" />';
		}

		/*
		 * Drop the button and keep the copy neutral whenever consent cannot
		 * release this content, so we never imply a dead control would load it:
		 * quarantined trackers (Pro Guard) are never released by consent, and a
		 * category hidden by `hide_unused_categories` has no toggle to grant it.
		 */
		$is_ungrantable = $category === Blocker::QUARANTINE_CATEGORY || Get::is_category_hidden( $category );

		$out .= '<div class="surecookie-placeholder-content" style="' . esc_attr( 'background-color:' . $content_bg . ';' ) . '">';
		// A <div>, NOT <p>: the admin description is rich text and usually arrives
		// wrapped in <p> tags. Nesting <p> inside <p> is invalid HTML - the browser
		// splits it, orphaning the styled wrapper as an empty <p> and dropping the
		// visible text into an unstyled, theme-margined one.
		$out .= '<div class="surecookie-placeholder-text" style="' . esc_attr( 'color:' . $text_color . ';' ) . '">';
		if ( $is_ungrantable ) {
			$out .= esc_html__( 'This content is currently blocked.', 'surecookie' );
		} else {
			// wp_kses_post keeps safe inline markup while stripping anything unsafe.
			$out .= wp_kses_post( $description !== '' ? $description : self::placeholder_description( $label ) );
		}
		$out .= '</div>';

		if ( ! $is_ungrantable ) {
			// aria-label carries the service name so multiple blocked embeds on one
			// page don't all expose the identical accessible name "Accept & Load".
			$button_aria = sprintf(
				/* translators: %s: Service name (e.g., YouTube, Google Maps) */
				__( 'Accept and load %s content', 'surecookie' ),
				$label
			);
			// White was hardcoded here, which fell under the WCAG minimum on every
			// palette whose primary is light (issue #1063).
			$out .= '<button type="button" class="surecookie-placeholder-button" data-surecookie-category="' . esc_attr( $category ) . '" aria-label="' . esc_attr( $button_aria ) . '" style="' . esc_attr( 'background-color:' . $colors['primary'] . ';color:' . $colors['primary_text'] . ';' ) . '">';
			$out .= esc_html( self::placeholder_button_text() );
			$out .= '</button>';
		}

		$out .= '</div>';

		return $out;
	}
}
