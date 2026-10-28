<?php
/**
 * Business Details exposed as shortcodes.
 *
 * Lets a site owner reuse their controller identity in their own pages, so a
 * hand-written policy stays in step with the plugin's settings instead of
 * hard-coding an address that later changes.
 *
 * Shortcodes rather than a brace syntax of our own: they resolve anywhere
 * WordPress already runs shortcodes, which includes the page builders and
 * widget surfaces a bespoke token filter would each need integrating with, and
 * they cost nothing on a page that does not use one. A token pass had to scan
 * every post body on every render to find out.
 *
 * @package SureCookie\Inc\Modules\BusinessDetails
 * @since 1.5.0
 */

namespace SureCookie\Inc\Modules\BusinessDetails;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Modules\PrivacyPolicy\Countries;
use SureCookie\Inc\Modules\PrivacyPolicy\Validator;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Traits\Shortcode as Shortcode_Trait;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode
 *
 * @since 1.5.0
 */
class Shortcode {
	use GetInstance;
	use Shortcode_Trait;

	/**
	 * Memoized registry for this request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $registry = null;

	/**
	 * Constructor.
	 *
	 * The loader runs this on init:999, so the registry filter fires then.
	 * Anything adding a shortcode through it has to hook by then;
	 * `surecookie_before_load_components` is the seam Pro already uses.
	 *
	 * @since 1.5.0
	 */
	private function __construct() {
		// Every tag answers from one method, which reads the tag it was given.
		$this->register_shortcodes(
			array_fill_keys( array_keys( self::registry() ), 'detail' )
		);
	}

	/**
	 * Every registered shortcode, keyed by tag.
	 *
	 * @since 1.5.0
	 * @return array<string, array<string, mixed>> Tag => label, example, resolve callable.
	 */
	public static function registry(): array {
		if ( self::$registry !== null ) {
			return self::$registry;
		}

		$shortcodes = [
			'surecookie_company_name'       => [
				'label'   => __( 'Legal entity name', 'surecookie' ),
				'example' => 'Example Ltd',
				'resolve' => static fn(): string => self::detail( 'legal_entity_name' ),
			],
			'surecookie_contact_email'      => [
				'label'   => __( 'Privacy contact email', 'surecookie' ),
				'example' => 'privacy@example.com',
				'resolve' => static fn(): string => self::detail( 'privacy_contact_email' ),
			],
			'surecookie_address'            => [
				'label'   => __( 'Postal address', 'surecookie' ),
				'example' => "1 Example Street\nLondon",
				'resolve' => static fn(): string => self::address(),
			],
			'surecookie_postal_code'        => [
				'label'   => __( 'Postal code', 'surecookie' ),
				'example' => 'SW1A 1AA',
				'resolve' => static fn(): string => self::detail( 'postal_code' ),
			],
			'surecookie_country'            => [
				'label'   => __( 'Country', 'surecookie' ),
				'example' => 'United Kingdom',
				'resolve' => static fn(): string => Countries::name( self::detail( 'country' ) ),
			],
			'surecookie_grievance_officer'  => [
				'label'   => __( 'Grievance officer name', 'surecookie' ),
				'example' => 'Priya Sharma',
				'resolve' => static fn(): string => self::detail( 'grievance_officer_name' ),
			],
			'surecookie_grievance_email'    => [
				'label'   => __( 'Grievance officer email', 'surecookie' ),
				'example' => 'grievance@example.com',
				'resolve' => static fn(): string => self::detail( 'grievance_officer_email' ),
			],
			'surecookie_cookie_policy_url'  => [
				'label'   => __( 'Cookie Policy page URL', 'surecookie' ),
				'example' => 'https://example.com/cookie-policy/',
				'resolve' => static fn(): string => (string) ( Get::cookie_policy_page_details()['url'] ?? '' ),
			],
			'surecookie_privacy_policy_url' => [
				'label'   => __( 'Privacy Policy page URL', 'surecookie' ),
				'example' => 'https://example.com/privacy-policy/',
				'resolve' => static fn(): string => (string) ( Get::privacy_policy_page_details()['url'] ?? '' ),
			],
		];

		/**
		 * Filters the Business Details shortcodes.
		 *
		 * The discoverability panel in the admin renders from this same list, so
		 * a shortcode added here is documented for users automatically.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, array<string, mixed>> $shortcodes Tag => label, example, resolve callable.
		 */
		$shortcodes = apply_filters( 'surecookie_business_shortcodes', $shortcodes );

		self::$registry = is_array( $shortcodes ) ? $shortcodes : [];

		return self::$registry;
	}

	/**
	 * Render whichever detail the matched tag names.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed>|string $atts    Shortcode attributes. None are accepted.
	 * @param string|null                 $content Enclosed content, never used.
	 * @param string                      $tag     The tag that matched.
	 * @return string
	 */
	public function render_detail( $atts = [], $content = null, $tag = '' ): string {
		unset( $atts, $content );

		$tag      = (string) $tag;
		$callback = self::registry()[ $tag ]['resolve'] ?? null;

		if ( ! is_callable( $callback ) ) {
			return '';
		}

		return self::render( (string) $callback(), $tag );
	}

	/**
	 * The registry shaped for the admin discoverability panel.
	 *
	 * Resolved values travel with it so the panel can show what each shortcode
	 * currently produces, or flag it as not set.
	 *
	 * @since 1.5.0
	 * @return array<int, array{shortcode: string, label: string, value: string, example: string}>
	 */
	public static function for_admin(): array {
		$rows = [];

		foreach ( self::registry() as $tag => $shortcode ) {
			$callback = $shortcode['resolve'] ?? null;
			$value    = is_callable( $callback ) ? (string) $callback() : '';

			$rows[] = [
				'shortcode' => '[' . $tag . ']',
				'label'     => (string) ( $shortcode['label'] ?? $tag ),
				'value'     => trim( $value ),
				'example'   => (string) ( $shortcode['example'] ?? '' ),
			];
		}

		return $rows;
	}

	/**
	 * Render one resolved value for output in post content.
	 *
	 * An unset value renders as nothing for visitors: a stray label on a live
	 * legal page is worse than a gap. Users who can act on it get a marker
	 * instead, so the gap is discoverable while editing.
	 *
	 * @since 1.5.0
	 * @param string $value Resolved value.
	 * @param string $tag   Shortcode tag, for the editor marker.
	 * @return string
	 */
	private static function render( string $value, string $tag ): string {
		if ( trim( $value ) !== '' ) {
			// Newlines only ever come from the address, whose lines are each
			// escaped before being joined, so nl2br is safe on the escaped form.
			return nl2br( esc_html( $value ), false );
		}

		if ( ! is_user_logged_in() || ! current_user_can( Helper::capability() ) ) {
			return '';
		}

		return sprintf(
			'<span class="surecookie-shortcode-empty" title="%1$s">%2$s</span>',
			esc_attr__( 'Visible to you only. Fill this in under Compliance, Business Details.', 'surecookie' ),
			esc_html( self::registry()[ $tag ]['label'] ?? $tag )
		);
	}

	/**
	 * One identity field as a trimmed string.
	 *
	 * @since 1.5.0
	 * @param string $field Field key.
	 * @return string
	 */
	private static function detail( string $field ): string {
		$value = Validator::details()[ $field ] ?? '';
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * The postal address as newline-separated lines, postal code appended.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private static function address(): string {
		$details = Validator::details();
		$lines   = is_array( $details['postal_address_lines'] ?? null ) ? $details['postal_address_lines'] : [];
		$lines   = array_values( array_filter( array_map( 'trim', array_filter( $lines, 'is_string' ) ) ) );

		$tail = trim( trim( (string) ( $details['postal_code'] ?? '' ) ) . ' ' . Countries::name( (string) ( $details['country'] ?? '' ) ) );

		if ( $tail !== '' ) {
			$lines[] = $tail;
		}

		return implode( "\n", $lines );
	}
}
