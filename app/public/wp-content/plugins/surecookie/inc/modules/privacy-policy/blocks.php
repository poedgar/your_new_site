<?php
/**
 * Gutenberg block serializer for generated policy content.
 *
 * The cookie-policy generator can only emit headings, paragraphs and a
 * shortcode, and escapes every value, which cannot express the privacy policy's
 * lists, tables and run-in labels. This builds the same block comments the
 * editor's own save() produces, so the generated page opens without an
 * "unexpected or invalid content" warning.
 *
 * @package SureCookie\Inc\Modules\PrivacyPolicy
 * @since 1.5.0
 */

namespace SureCookie\Inc\Modules\PrivacyPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks
 *
 * @since 1.5.0
 */
final class Blocks {
	/**
	 * Serialize an ordered list of sections into post content.
	 *
	 * @since 1.5.0
	 * @param array<int, array<string, mixed>> $sections Ordered sections.
	 * @return string Block-editor-ready post content.
	 */
	public static function build( array $sections ): string {
		$content = '';

		foreach ( $sections as $section ) {
			$content .= self::render_section( is_array( $section ) ? $section : [] );
		}

		return $content;
	}

	/**
	 * Build an escaped mailto link.
	 *
	 * The rich lane accepts no caller-built anchors, so every link goes through
	 * here. That is what makes "the rich lane never sees an unescaped href" a
	 * property of the code rather than a rule someone has to remember.
	 *
	 * @since 1.5.0
	 * @param string $email Email address.
	 * @param string $label Link text, defaults to the address.
	 * @return string
	 */
	public static function mailto( string $email, string $label = '' ): string {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return '';
		}

		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( 'mailto:' . $email, [ 'mailto' ] ),
			esc_html( $label !== '' ? $label : $email )
		);
	}

	/**
	 * Render one section.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed> $section Section descriptor.
	 * @return string
	 */
	private static function render_section( array $section ): string {
		$type = isset( $section['type'] ) && is_string( $section['type'] ) ? $section['type'] : '';
		$rich = ! empty( $section['rich'] );

		switch ( $type ) {
			case 'heading':
				return self::heading(
					isset( $section['level'] ) ? (int) $section['level'] : 2,
					isset( $section['text'] ) ? (string) $section['text'] : ''
				);

			case 'paragraph':
				return self::paragraph( isset( $section['text'] ) ? (string) $section['text'] : '', $rich );

			case 'list':
				return self::list_block(
					isset( $section['items'] ) && is_array( $section['items'] ) ? $section['items'] : [],
					! empty( $section['ordered'] ),
					$rich
				);

			case 'table':
				return self::table_block(
					isset( $section['head'] ) && is_array( $section['head'] ) ? $section['head'] : [],
					isset( $section['rows'] ) && is_array( $section['rows'] ) ? $section['rows'] : [],
					isset( $section['caption'] ) ? (string) $section['caption'] : ''
				);

			case 'shortcode':
				return self::shortcode(
					isset( $section['tag'] ) ? (string) $section['tag'] : '',
					isset( $section['atts'] ) && is_array( $section['atts'] ) ? $section['atts'] : []
				);

			case 'placeholder':
				return self::placeholder_group( isset( $section['text'] ) ? (string) $section['text'] : '' );

			default:
				return '';
		}
	}

	/**
	 * Heading block.
	 *
	 * Clamped to H2/H3: WordPress renders the page title as the H1, so a body
	 * H1 would give the page two. Level 2 omits the attribute JSON because it
	 * is core/heading's default, and the wp-block-heading class is required or
	 * the heading loses core's block spacing.
	 *
	 * @since 1.5.0
	 * @param int    $level Heading level.
	 * @param string $text  Heading text.
	 * @return string
	 */
	private static function heading( int $level, string $text ): string {
		$level = $level === 3 ? 3 : 2;
		$attrs = $level === 2 ? '' : ' {"level":3}';

		return sprintf(
			"<!-- wp:heading%1\$s -->\n<h%2\$d class=\"wp-block-heading\">%3\$s</h%2\$d>\n<!-- /wp:heading -->\n\n",
			$attrs,
			$level,
			esc_html( $text )
		);
	}

	/**
	 * Paragraph block.
	 *
	 * @since 1.5.0
	 * @param string $text Paragraph text.
	 * @param bool   $rich Whether inline formatting is allowed.
	 * @return string
	 */
	private static function paragraph( string $text, bool $rich = false ): string {
		return sprintf(
			"<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->\n\n",
			$rich ? self::inline( $text ) : esc_html( $text )
		);
	}

	/**
	 * List block.
	 *
	 * The core/list block declares core/list-item as its only allowed child, so
	 * each item carries its own block comment. Anything else reads as invalid
	 * content.
	 *
	 * @since 1.5.0
	 * @param array<int, string> $items   List items.
	 * @param bool               $ordered Whether to emit an ordered list.
	 * @param bool               $rich    Whether inline formatting is allowed.
	 * @return string
	 */
	private static function list_block( array $items, bool $ordered = false, bool $rich = false ): string {
		if ( empty( $items ) ) {
			return '';
		}

		$tag   = $ordered ? 'ol' : 'ul';
		$attrs = $ordered ? ' {"ordered":true}' : '';
		$inner = '';

		foreach ( $items as $item ) {
			if ( ! is_string( $item ) || trim( $item ) === '' ) {
				continue;
			}

			$inner .= sprintf(
				"<!-- wp:list-item -->\n<li>%s</li>\n<!-- /wp:list-item -->\n",
				$rich ? self::inline( $item ) : esc_html( $item )
			);
		}

		if ( $inner === '' ) {
			return '';
		}

		return sprintf(
			"<!-- wp:list%1\$s -->\n<%2\$s class=\"wp-block-list\">%3\$s</%2\$s>\n<!-- /wp:list -->\n\n",
			$attrs,
			$tag,
			$inner
		);
	}

	/**
	 * Table block.
	 *
	 * Core defaults hasFixedLayout to true, so the attribute stays out of the
	 * comment but has-fixed-layout must be on the table or the editor reports
	 * invalid content.
	 *
	 * @since 1.5.0
	 * @param array<int, string>             $head    Header cells.
	 * @param array<int, array<int, string>> $rows    Body rows.
	 * @param string                         $caption Optional caption.
	 * @return string
	 */
	private static function table_block( array $head, array $rows, string $caption = '' ): string {
		if ( empty( $rows ) ) {
			return '';
		}

		$markup = '';

		if ( ! empty( $head ) ) {
			$cells = '';
			foreach ( $head as $cell ) {
				$cells .= '<th scope="col">' . esc_html( is_string( $cell ) ? $cell : '' ) . '</th>';
			}
			$markup .= '<thead><tr>' . $cells . '</tr></thead>';
		}

		$body = '';

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$cells = '';
			$index = 0;

			foreach ( $row as $cell ) {
				// data-label mirrors the cookie-policy tables so the same
				// responsive stacking rules apply to both policy pages.
				$label  = isset( $head[ $index ] ) && is_string( $head[ $index ] ) ? $head[ $index ] : '';
				$cells .= sprintf(
					'<td data-label="%1$s">%2$s</td>',
					esc_attr( $label ),
					esc_html( is_string( $cell ) ? $cell : '' )
				);
				++$index;
			}

			$body .= '<tr>' . $cells . '</tr>';
		}

		$markup .= '<tbody>' . $body . '</tbody>';

		$figcaption = $caption !== ''
			? '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>'
			: '';

		return sprintf(
			"<!-- wp:table -->\n<figure class=\"wp-block-table\"><table class=\"has-fixed-layout\">%1\$s</table>%2\$s</figure>\n<!-- /wp:table -->\n\n",
			$markup,
			$figcaption
		);
	}

	/**
	 * Shortcode block.
	 *
	 * @since 1.5.0
	 * @param string               $tag  Shortcode tag.
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return string
	 */
	private static function shortcode( string $tag, array $atts = [] ): string {
		$tag = sanitize_key( $tag );

		if ( $tag === '' ) {
			return '';
		}

		$rendered = '[' . $tag;

		foreach ( $atts as $key => $value ) {
			$rendered .= sprintf( ' %s="%s"', sanitize_key( (string) $key ), esc_attr( (string) $value ) );
		}

		$rendered .= ']';

		return sprintf( "<!-- wp:shortcode -->\n%s\n<!-- /wp:shortcode -->\n\n", $rendered );
	}

	/**
	 * A visibly unfinished block the author is expected to replace.
	 *
	 * Rendered as a quote so it stands out in the editor and on the page, since
	 * the whole point is that it must not survive to a published policy.
	 *
	 * @since 1.5.0
	 * @param string $text Guidance for the author.
	 * @return string
	 */
	private static function placeholder_group( string $text ): string {
		if ( trim( $text ) === '' ) {
			return '';
		}

		return sprintf(
			"<!-- wp:quote {\"className\":\"surecookie-policy-placeholder\"} -->\n<blockquote class=\"wp-block-quote surecookie-policy-placeholder\"><!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph --></blockquote>\n<!-- /wp:quote -->\n\n",
			esc_html( $text )
		);
	}

	/**
	 * Sanitize inline markup for the rich lane.
	 *
	 * Uses wp_kses with an explicit allowlist rather than wp_kses_post, so the
	 * generator can emit a bold run-in label or a mailto link without opening
	 * the door to everything a post can normally contain. mailto needs no extra
	 * registration: wp_kses falls back to wp_allowed_protocols().
	 *
	 * @since 1.5.0
	 * @param string $text Text with limited inline markup.
	 * @return string
	 */
	private static function inline( string $text ): string {
		return wp_kses( $text, self::allowed_inline_html() );
	}

	/**
	 * Inline tags the rich lane permits.
	 *
	 * @since 1.5.0
	 * @return array<string, array<string, bool>>
	 */
	private static function allowed_inline_html(): array {
		return [
			'strong' => [],
			'em'     => [],
			'code'   => [],
			'br'     => [],
			'a'      => [
				'href'  => true,
				'rel'   => true,
				'title' => true,
			],
		];
	}
}
