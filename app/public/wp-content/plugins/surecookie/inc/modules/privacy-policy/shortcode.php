<?php
/**
 * Privacy policy content shortcodes.
 *
 * One narrow tag per section rather than one tag with a `section` attribute, so
 * a user can delete or reorder a single section in the editor without losing the
 * others.
 *
 * For a privacy policy, "no data" is itself a disclosure, so nothing here ever
 * returns an empty string the way the cookie-policy shortcode does. Each render
 * lands on one of three outcomes: the disclosure, a positive statement of the
 * absence, or an unresolved placeholder that the publish gate exists to stop
 * reaching a live page.
 *
 * @package SureCookie\Inc\Modules\PrivacyPolicy
 * @since 1.5.0
 */

namespace SureCookie\Inc\Modules\PrivacyPolicy;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Integrations\Multilingual\Translation_Filter;
use SureCookie\Inc\Modules\ConsentLogs\Cron;
use SureCookie\Inc\Modules\Services\Services_Source;
use SureCookie\Inc\Services\ScriptBlockingService;
use SureCookie\Inc\Traits\GetInstance;
// Aliased: this class is also called Shortcode, and importing the trait
// under its own name would collide with the declaration below.
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
	 * Host the geolocation lookup talks to.
	 *
	 * Hard-coded rather than read from Helper::get_agent_app_url(), because
	 * SURECOOKIE_AGENT_APP_URL can be pointed at a staging host and a published
	 * privacy policy must never name an internal endpoint.
	 *
	 * @since 1.5.0
	 */
	public const GEO_LOOKUP_HOST = 'library.surecookie.com';

	/**
	 * Country the geolocation service is hosted in.
	 *
	 * Named in two places that both reach a site owner's legal document: the
	 * generated policy and the suggested text WordPress shows in its Policy
	 * Guide. Both read it from here, so an infrastructure move cannot leave one
	 * of them asserting the old jurisdiction.
	 *
	 * @since 1.5.0
	 */
	public const GEO_LOOKUP_REGION = 'Germany';

	/**
	 * Country the CDN in front of that service is operated from.
	 *
	 * Stated separately and deliberately. The lookup terminates in the EU, but
	 * requests reach it through a content delivery network whose operator is a
	 * United States company, and that operator is a processor in its own right.
	 * Naming only the hosting country would let an EU site conclude no transfer
	 * needs documenting anywhere, which is the opposite of the truth.
	 *
	 * @since 1.5.0
	 */
	public const GEO_LOOKUP_CDN_REGION = 'United States';

	/**
	 * Cookie set to remember a visitor's answer.
	 *
	 * @since 1.5.0
	 */
	private const CONSENT_COOKIE = 'surecookie_user_consent';

	/**
	 * Cookie holding the pseudonymous session identifier.
	 *
	 * @since 1.5.0
	 */
	private const SESSION_COOKIE = 'surecookie_session_id';

	/**
	 * Hard-coded lifetime of the session cookie, in days.
	 *
	 * Deliberately not derived from `consent_duration_days`: the client writes
	 * this cookie with a fixed 365-day expiry, so a site set to 30 days still
	 * leaves a year-long identifier and the policy must say so.
	 *
	 * @since 1.5.0
	 */
	private const SESSION_COOKIE_DAYS = 365;

	/**
	 * Whether the stylesheet has already been queued this request.
	 *
	 * @var bool
	 */
	private bool $styles_queued = false;

	/**
	 * Memoized reads, so eleven tags share one lookup of each source.
	 *
	 * @var array<string, mixed>
	 */
	private array $memo = [];

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 */
	private function __construct() {
		$tags = [
			'surecookie_privacy_last_updated'    => 'last_updated',
			'surecookie_privacy_identity'        => 'identity',
			'surecookie_privacy_contact'         => 'contact',
			'surecookie_privacy_cookie_summary'  => 'cookie_summary',
			'surecookie_privacy_consent_records' => 'consent_records',
			'surecookie_privacy_third_parties'   => 'third_parties',
			'surecookie_privacy_server_egress'   => 'server_egress',
			'surecookie_privacy_data_transfers'  => 'data_transfers',
			'surecookie_privacy_regional'        => 'regional',
			'surecookie_privacy_rights'          => 'rights',
			'surecookie_privacy_jurisdiction'    => 'jurisdiction',
		];

		$this->register_shortcodes( $tags );
	}

	/**
	 * Two dated lines: the document, and the inventory.
	 *
	 * They move on different clocks, so a single date would misstate one of
	 * them. The policy date is what a regulator reads; the list date is what
	 * tells a visitor how fresh the cookie inventory is.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	public function render_last_updated(): string {
		$format = (string) get_option( 'date_format' );
		$format = $format !== '' ? $format : 'F j, Y';
		$lines  = [];

		$post_id = get_the_ID();
		// Offset timestamp, not UTC: date_i18n() re-creates a numeric argument in
		// the site zone, so a true UTC stamp prints the wrong day either side of
		// midnight.
		$modified = $post_id ? get_post_modified_time( 'U', false, $post_id ) : false;

		if ( is_numeric( $modified ) && (int) $modified > 0 ) {
			$lines[] = sprintf(
				/* translators: %s: date the policy page was last edited. */
				__( 'Policy last updated: %s', 'surecookie' ),
				date_i18n( $format, (int) $modified )
			);
		}

		$scan = $this->scan_timestamp();

		if ( $scan > 0 ) {
			$lines[] = sprintf(
				/* translators: %s: date of the most recent cookie scan. */
				__( 'Cookie and service list last updated: %s', 'surecookie' ),
				date_i18n( $format, $scan )
			);
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return $this->wrap( '<p class="surecookie-privacy-policy-dates">' . implode( '<br />', array_map( 'esc_html', $lines ) ) . '</p>' );
	}

	/**
	 * Legal entity name and registered address.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	public function render_identity(): string {
		$details = Validator::details();
		$name    = trim( (string) ( $details['legal_entity_name'] ?? '' ) );
		$lines   = is_array( $details['postal_address_lines'] ?? null ) ? $details['postal_address_lines'] : [];
		$lines   = array_values( array_filter( array_map( 'trim', array_filter( $lines, 'is_string' ) ) ) );

		$tail = trim( trim( (string) ( $details['postal_code'] ?? '' ) ) . ' ' . Countries::name( (string) ( $details['country'] ?? '' ) ) );

		if ( $tail !== '' ) {
			$lines[] = $tail;
		}

		if ( $name === '' && empty( $lines ) ) {
			return $this->unresolved(
				__( 'The organisation responsible for this website has not been named yet.', 'surecookie' ),
				__( 'Add your legal entity name, address and country under Compliance, Business Details.', 'surecookie' )
			);
		}

		$out = '<p class="surecookie-privacy-policy-identity">';

		if ( $name !== '' ) {
			$out .= '<strong>' . esc_html( $name ) . '</strong>';
			$out .= empty( $lines ) ? '' : '<br />';
		}

		$out .= implode( '<br />', array_map( 'esc_html', $lines ) );
		$out .= '</p>';

		return $this->wrap( $out );
	}

	/**
	 * Privacy contact address, as a link rather than printable text.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	public function render_contact(): string {
		$email = trim( (string) ( Validator::details()['privacy_contact_email'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			return $this->unresolved(
				__( 'A contact address for privacy questions has not been set yet.', 'surecookie' ),
				__( 'Add a privacy contact email under Compliance, Business Details.', 'surecookie' )
			);
		}

		return $this->wrap(
			sprintf(
				'<p class="surecookie-privacy-policy-contact">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'Email:', 'surecookie' ),
				esc_url( 'mailto:' . $email, [ 'mailto' ] ),
				esc_html( $email )
			)
		);
	}

	/**
	 * Cookie categories, and either a link to the Cookie Policy or the table.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_cookie_summary( $atts = [] ): string {
		// WordPress passes an empty string, not an array, when a shortcode is
		// used with no attributes.
		$atts = shortcode_atts( [ 'mode' => 'auto' ], is_array( $atts ) ? $atts : [], 'surecookie_privacy_cookie_summary' );

		$categories = $this->categories();

		if ( empty( $categories ) ) {
			return $this->affirmative_negative(
				__( 'No cookies have been identified on this site, so there is nothing to list here yet.', 'surecookie' )
			);
		}

		$items = [];

		foreach ( $categories as $category ) {
			$name        = (string) ( $category['name'] ?? '' );
			$description = (string) ( $category['description'] ?? '' );

			$items[] = $description !== ''
				? sprintf( '<strong>%1$s</strong> %2$s', esc_html( $name ), esc_html( $description ) )
				: '<strong>' . esc_html( $name ) . '</strong>';
		}

		$out = '<ul class="surecookie-privacy-policy-categories"><li>' . implode( '</li><li>', $items ) . '</li></ul>';

		$out .= '<p>' . esc_html( $this->consent_model_sentence() ) . '</p>';

		$policy = Get::cookie_policy_page_details();
		$mode   = (string) ( $atts['mode'] ?? 'auto' );

		// Link rather than duplicate when there is a Cookie Policy page: the two
		// pages otherwise carry the same long table and the same stylesheet.
		if ( $mode !== 'table' && ! empty( $policy['url'] ) ) {
			$out .= sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( (string) $policy['url'] ),
				esc_html__( 'See the full list of cookies used on this site', 'surecookie' )
			);

			return $this->wrap( $out );
		}

		if ( $mode !== 'summary' ) {
			$out .= do_shortcode( '[surecookie_cookie_policy_content show_timestamp="false"]' );
		}

		return $this->wrap( $out );
	}

	/**
	 * Consent records, retention and the geolocation lookup.
	 *
	 * The one section that is true on every install, required of every install,
	 * and invisible to a site owner who has not read the plugin source.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	public function render_consent_records(): string {
		$duration = max( 1, absint( Settings::get( 'consent_duration_days' ) ) );

		$cookie_paragraph = sprintf(
			/* translators: 1: consent cookie name, 2: number of days, 3: session cookie name, 4: session cookie lifetime in days. */
			__( 'When you choose which cookies to allow, this site stores that choice in two cookies in your browser. %1$s holds the choice itself and lasts %2$d days. %3$s holds a randomly generated identifier and lasts %4$d days regardless of that setting; it is created the first time you load a page, before you have chosen anything, so that a choice you make later can be matched to the same browser.', 'surecookie' ),
			'<code>' . self::CONSENT_COOKIE . '</code>',
			$duration,
			'<code>' . self::SESSION_COOKIE . '</code>',
			self::SESSION_COOKIE_DAYS
		);

		if ( ! Settings::get( 'consent_logging_enabled' ) ) {
			// No server-side record means no geolocation call either: the client
			// never reaches the consent endpoint that triggers it.
			$out  = '<p>' . wp_kses( $cookie_paragraph, [ 'code' => [] ] ) . '</p>';
			$out .= '<p>' . esc_html__( 'Your choice is stored in your own browser and nowhere else. This site keeps no record of it on the server, so there is nothing for us to look up, export or delete, and clearing your browser cookies removes it.', 'surecookie' ) . '</p>';
			$out .= '<p>' . esc_html__( 'You can change your choice at any time using the cookie preferences control on this site.', 'surecookie' ) . '</p>';

			return $this->wrap( $out );
		}

		$out = '<p>' . wp_kses( $cookie_paragraph, [ 'code' => [] ] ) . '</p>';

		// A site that opted out of IP capture must not read a policy claiming it
		// stores a shortened address and sends the full one away.
		$records_ip = Helper::consent_log_ip_enabled();

		$out .= '<p>' . ( $records_ip
			? esc_html__( 'We also keep a record of the choice on this site, so that we can show, if we are ever asked, that you were given a choice and what you chose. Each record holds the date and time, which categories you allowed and which you refused, the random identifier from the cookie above, a shortened form of your IP address, and the country your visit appeared to come from. If you were signed in, it also holds your user account ID.', 'surecookie' )
			: esc_html__( 'We also keep a record of the choice on this site, so that we can show, if we are ever asked, that you were given a choice and what you chose. Each record holds the date and time, which categories you allowed and which you refused, and the random identifier from the cookie above. If you were signed in, it also holds your user account ID.', 'surecookie' )
		) . '</p>';

		if ( $records_ip ) {
			$out .= '<p>' . esc_html__( 'The IP address in the record is shortened before it is stored: for a normal IPv4 address the last part is replaced with a zero. We do not store your full IP address in these records, and there is no setting that would make us.', 'surecookie' ) . '</p>';

			$out .= '<p>' . sprintf(
				/* translators: 1: hostname of the geolocation service, 2: country it is hosted in, 3: country the CDN operator is based in. Country names are not translated, matching the English country list the plugin uses elsewhere. */
				esc_html__( 'To work out which country your visit came from, this site sends your full IP address to a geolocation service operated by Brainstorm Force, the maker of the consent tool used on this site, at %1$s. The address is sent as part of the web address of that request, only the country comes back, and only the shortened address is stored here. That service is hosted in %2$s. Requests reach it through a content delivery network run by a company based in the %3$s, so that step can involve a transfer outside the European Economic Area. The result is cached for 24 hours, so a repeat visit within that time does not send it again.', 'surecookie' ),
				'<code>' . esc_html( self::GEO_LOOKUP_HOST ) . '</code>',
				esc_html( self::GEO_LOOKUP_REGION ),
				esc_html( self::GEO_LOOKUP_CDN_REGION )
			) . '</p>';
		} else {
			$out .= '<p>' . esc_html__( 'This site does not put your IP address in the record at all, not even a shortened form of it, and does not work out which country you are visiting from. Nothing about your address is sent anywhere when you answer the banner.', 'surecookie' ) . '</p>';
		}

		$out .= '<p>' . esc_html( $this->retention_sentence() ) . '</p>';

		$out .= '<p>' . sprintf(
			/* translators: %d: number of days a stored choice lasts before the banner returns. */
			esc_html__( 'Separately from how long that record is kept, the choice saved in your browser lasts %d days. After that it expires and you will be asked again. If we ever need to ask everyone again sooner, for example because the categories on this site have changed, the banner will reappear and your previous choice stops applying.', 'surecookie' ),
			$duration
		) . '</p>';

		if ( Settings::get( 'consent_model' ) === 'opt-out' ) {
			$out .= '<p>' . esc_html__( 'This site is set up so that non-essential cookies may run before you have answered the banner, and stop once you refuse them. Your record shows the point at which you refused, not the moment you arrived.', 'surecookie' ) . '</p>';
		}

		$out .= '<p>' . esc_html__( 'These consent records are not covered by the personal-data erasure tool built into this site. Erasing your other data leaves the consent record in place, because it is the evidence that you were given a choice. If you want the record itself removed, ask us and we will remove it by hand.', 'surecookie' ) . '</p>';

		if ( Settings::get( 'gcm_enabled' ) ) {
			$out .= '<p>' . esc_html__( 'Your choice is also passed to Google\'s tags on this site as a consent signal, so that they can adjust what they do before any of them run.', 'surecookie' ) . '</p>';
		}

		$out .= $this->pro_consent_paragraphs();

		$out .= '<p>' . esc_html__( 'You can change or withdraw your choice at any time using the cookie preferences control on this site. Withdrawing does not undo anything that happened while your permission was in place.', 'surecookie' ) . '</p>';

		return $this->wrap( $out );
	}

	/**
	 * Third-party resources a browser loads from this site's pages.
	 *
	 * Purpose comes from the service catalog, which carries a one-line
	 * description per slug; a category is a classification, and Art. 13 asks
	 * for the purpose. "When it loads" and "what they receive" exist in no
	 * resource row, no catalog entry and no setting, so they are not generated
	 * rather than guessed. A row we cannot match to a service leaves the cell
	 * empty for the same reason.
	 *
	 * One row per service and category, with every host that service was seen
	 * on listed together.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	public function render_third_parties(): string {
		$resources = $this->resources();

		if ( $resources === null ) {
			return $this->unresolved(
				__( 'This site has not been scanned yet, so the third-party services it loads have not been identified.', 'surecookie' ),
				__( 'Run a scan under Tracking Manager, Scanning to fill this in.', 'surecookie' )
			);
		}

		if ( empty( $resources ) ) {
			return $this->affirmative_negative(
				__( 'The most recent scan of this site found no third-party scripts or embedded content.', 'surecookie' )
			);
		}

		$labels   = Get::blocking_service_labels();
		$purposes = $this->service_purposes();
		$grouped  = [];

		foreach ( $resources as $resource ) {
			$domain = isset( $resource['domain'] ) ? (string) $resource['domain'] : '';

			if ( $domain === '' ) {
				continue;
			}

			$slug     = isset( $resource['service_slug'] ) ? (string) $resource['service_slug'] : '';
			$vendor   = isset( $resource['vendor'] ) ? trim( (string) $resource['vendor'] ) : '';
			$name     = $vendor !== '' ? $vendor : ( $labels[ $slug ] ?? $domain );
			$category = isset( $resource['effective_category'] ) ? (string) $resource['effective_category'] : '';

			// One row per service, not per hostname: a vendor commonly serves
			// from several domains, and repeating its purpose once per host
			// buries the disclosure. Category is part of the key so a service
			// split across categories still reports both rather than one of
			// them silently winning.
			$key = ( $slug !== '' ? $slug : $domain ) . '|' . $category;

			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = [
					'name'     => $name,
					'purpose'  => $purposes[ $slug ] ?? '',
					'category' => $this->category_name( $category ),
					'domains'  => [],
				];
			}

			$grouped[ $key ]['domains'][ $domain ] = true;
		}

		$rows = [];

		foreach ( $grouped as $row ) {
			$rows[] = [
				$row['name'],
				$row['purpose'],
				$row['category'],
				implode( ', ', array_keys( $row['domains'] ) ),
			];
		}

		if ( empty( $rows ) ) {
			return $this->affirmative_negative(
				__( 'The most recent scan of this site found no third-party scripts or embedded content.', 'surecookie' )
			);
		}

		$head = [
			__( 'Service', 'surecookie' ),
			__( 'Purpose', 'surecookie' ),
			__( 'Category', 'surecookie' ),
			__( 'Domain', 'surecookie' ),
		];

		return $this->wrap( $this->table( $head, $rows ) );
	}

	/**
	 * What the server sends out, which browser settings cannot block.
	 *
	 * Exactly one of these flows carries visitor data. The other two are about
	 * the site itself and are labelled as such: conflating them would make the
	 * section less honest than leaving it out.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	public function render_server_egress(): string {
		$visitor = [];
		$site    = [];

		if ( Settings::get( 'consent_logging_enabled' ) && Helper::consent_log_ip_enabled() ) {
			$visitor[] = sprintf(
				/* translators: %s: hostname of the geolocation service. */
				__( 'Your IP address is sent to %s to work out which country you are visiting from, so the right consent rules can be applied and recorded. Only the country comes back.', 'surecookie' ),
				'<code>' . esc_html( self::GEO_LOOKUP_HOST ) . '</code>'
			);
		} elseif ( Helper::is_pro_active() && Settings::get( 'geo_display_mode' ) === 'selected_regions' ) {
			// Regional rules resolve a country on page views whatever the consent
			// record holds, so `surecookie_skip_consent_logs_ips` silences the
			// clause above without stopping the transmission. Saying nothing here
			// would print the absolute negative below and contradict the consent
			// section, which discloses this same lookup.
			$visitor[] = sprintf(
				/* translators: %s: hostname of the geolocation service. */
				__( 'Your IP address is sent to %s on page views to work out which country you are visiting from, so the right consent rules can be applied. Only the country comes back, and your address is not kept.', 'surecookie' ),
				'<code>' . esc_html( self::GEO_LOOKUP_HOST ) . '</code>'
			);
		}

		if ( Settings::get( 'auto_scan_enabled' ) ) {
			$site[] = __( 'The addresses of pages on this site are sent to our cookie scanning service on a schedule, so the cookie list above stays current. This is information about the site, not about you.', 'surecookie' );
		}

		if ( Get::option( 'surecookie_usage_optin' ) === 'yes' ) {
			$site[] = __( 'Technical information about this site and its server is shared with the maker of the consent tool, because the site owner opted in to usage tracking. This is information about the site, not about you.', 'surecookie' );
		}

		if ( empty( $visitor ) && empty( $site ) ) {
			return $this->affirmative_negative(
				__( 'Separately from what your browser loads, this site does not send any information about your visit to another service from its own server.', 'surecookie' )
			);
		}

		$out = '<p>' . esc_html__( 'Separately from the files your browser loads, this site sends a small amount of information to other services from its own server while handling your visit. Because this happens on our side, your browser settings cannot control it.', 'surecookie' ) . '</p>';

		$items = [];

		foreach ( array_merge( $visitor, $site ) as $item ) {
			$items[] = wp_kses( $item, [ 'code' => [] ] );
		}

		$out .= '<ul class="surecookie-privacy-policy-egress"><li>' . implode( '</li><li>', $items ) . '</li></ul>';

		return $this->wrap( $out );
	}

	/**
	 * Where data is processed, and the safeguards relied on.
	 *
	 * Suppressed entirely when no safeguard is stated: a transfers section with
	 * a blank safeguard is a claim with nothing behind it.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	public function render_data_transfers(): string {
		$details   = Validator::details();
		$safeguard = trim( (string) ( $details['transfer_safeguard_text'] ?? '' ) );

		if ( $safeguard === '' ) {
			return '';
		}

		$country = Countries::name( (string) ( $details['country'] ?? '' ) );

		$out = '<h2 class="wp-block-heading">' . esc_html__( 'Where your information is processed', 'surecookie' ) . '</h2>';

		$out .= '<p>' . ( $country !== ''
			? sprintf(
				/* translators: %s: country of establishment. */
				esc_html__( 'We are based in %s. Some of the services listed above process information in other countries.', 'surecookie' ),
				esc_html( $country )
			)
			: esc_html__( 'Some of the services listed above process information in other countries.', 'surecookie' )
		) . '</p>';

		$out .= '<p>' . sprintf(
			/* translators: %s: the safeguard relied on for international transfers. */
			esc_html__( 'Where personal information is transferred out of your region, we rely on the following safeguard: %s', 'surecookie' ),
			esc_html( $safeguard )
		) . '</p>';

		return $this->wrap( $out );
	}

	/**
	 * Region-specific consent rules.
	 *
	 * Free has no geo data at all, so it renders the honest default and Pro
	 * replaces the array through the filter.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	public function render_regional(): string {
		/**
		 * Filters the region-specific consent rules described in the policy.
		 *
		 * Free ships no geographic rules, so this is empty unless SureCookie Pro
		 * fills it. Each entry is a ready-to-print sentence.
		 *
		 * @since 1.5.0
		 *
		 * @param array<int, string> $rules Sentences describing regional rules.
		 */
		$rules = apply_filters( 'surecookie_privacy_policy_regional_rules', [] );
		$rules = is_array( $rules ) ? array_filter( $rules, 'is_string' ) : [];

		if ( empty( $rules ) ) {
			// "The same for everyone" is a negative assertion, and it is false
			// on a site running region-specific rules. Only make it when the
			// configuration actually supports it; otherwise say that regional
			// rules apply without enumerating rules we were not given.
			if ( Helper::is_pro_active() && Settings::get( 'geo_display_mode' ) === 'selected_regions' ) {
				return $this->wrap( '<p>' . esc_html__( 'This site applies different cookie rules depending on the country you are visiting from, so the choices offered to you may differ from those offered elsewhere.', 'surecookie' ) . '</p>' );
			}

			return $this->wrap( '<p>' . esc_html__( 'This site applies the same cookie and privacy practices to every visitor, wherever they are.', 'surecookie' ) . '</p>' );
		}

		$items = array_map( 'esc_html', $rules );

		return $this->wrap(
			'<p>' . esc_html__( 'This site applies different cookie rules depending on where you are visiting from:', 'surecookie' ) . '</p>'
			. '<ul class="surecookie-privacy-policy-regional"><li>' . implode( '</li><li>', $items ) . '</li></ul>'
		);
	}

	/**
	 * Data subject rights, and how to exercise them.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	public function render_rights(): string {
		$out = '<p>' . esc_html__( 'You can ask us what personal information we hold about you and ask for a copy of it. You can ask us to correct it if it is wrong, ask us to delete it, and object to us using it in a particular way. Where we rely on your permission, you can take that permission back at any time.', 'surecookie' ) . '</p>';

		$requests = Settings::get( 'data_request_settings' );
		$enabled  = is_array( $requests ) && ! empty( $requests['data_request_enabled'] );

		if ( $enabled ) {
			$sla = absint( $requests['data_request_sla_days'] ?? 0 );

			$out .= '<p>' . ( $sla > 0
				? sprintf(
					/* translators: %d: number of days to respond to a request. */
					esc_html__( 'You can make any of these requests using the data request form on this site, and we aim to respond within %d days.', 'surecookie' ),
					$sla
				)
				: esc_html__( 'You can make any of these requests using the data request form on this site.', 'surecookie' )
			) . '</p>';
		} else {
			$out .= '<p>' . esc_html__( 'To make any of these requests, use the contact details at the end of this policy. We will not charge you for a request and we will not treat you differently for making one.', 'surecookie' ) . '</p>';
		}

		$out .= '<p>' . esc_html__( 'Some requests are limited by law. We may have to keep particular records to meet legal, accounting or security obligations, and where that applies to your request we will tell you which records and why.', 'surecookie' ) . '</p>';

		$out .= '<p>' . esc_html( $this->supervisory_authority_sentence() ) . '</p>';

		return $this->wrap( $out );
	}

	/**
	 * One regime's rights section.
	 *
	 * @since 1.5.0
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_jurisdiction( $atts = [] ): string {
		$atts = shortcode_atts( [ 'code' => '' ], is_array( $atts ) ? $atts : [], 'surecookie_privacy_jurisdiction' );
		$code = sanitize_key( (string) ( $atts['code'] ?? '' ) );

		if ( ! in_array( $code, Validator::JURISDICTIONS, true ) ) {
			return '';
		}

		// Every regime gets a block planted at generation time so selecting one
		// later shows up without regenerating the page. That makes this the only
		// thing between a visitor and a paragraph about a law the site never
		// claimed: unselected is out of scope, not unfinished.
		if ( ! in_array( $code, Validator::selected_jurisdictions(), true ) ) {
			return '';
		}

		if ( ! Validator::jurisdiction_is_satisfied( $code ) ) {
			return $this->unresolved(
				__( 'A section for this privacy law is switched on but is not finished yet.', 'surecookie' ),
				__( 'Complete the details this law requires under Compliance, Business Details, or turn the law off.', 'surecookie' )
			);
		}

		$method = 'jurisdiction_' . $code;

		return method_exists( $this, $method ) ? $this->wrap( $this->{$method}() ) : '';
	}

	/**
	 * California (CCPA as amended by CPRA).
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function jurisdiction_cpra(): string {
		$sells = (string) ( Validator::details()['ccpa_sells_or_shares'] ?? '' );

		$out  = '<h2 class="wp-block-heading">' . esc_html__( 'Your California privacy rights', 'surecookie' ) . '</h2>';
		$out .= '<p>' . esc_html__( 'If you live in California, the California Consumer Privacy Act, as amended by the California Privacy Rights Act, gives you the right to know what personal information we collect about you and to get a copy of it, to have it corrected, to have it deleted, to limit how we use sensitive personal information, and not to be treated differently for asking.', 'surecookie' ) . '</p>';

		$out .= '<p>' . ( $sells === 'yes'
			? esc_html__( 'You also have the right to opt out of the sale or sharing of your personal information. This site does sell or share personal information as those terms are defined under California law, and you can opt out using the cookie preferences control on this site or by contacting us.', 'surecookie' )
			: esc_html__( 'You also have the right to opt out of the sale or sharing of your personal information. This site does not sell or share personal information as those terms are defined under California law.', 'surecookie' )
		) . '</p>';

		$out .= '<p>' . esc_html__( 'To make any of these requests, use the contact details at the end of this policy. We will confirm who you are before we act, and we will respond within the time the law allows. You can also appoint someone to make a request for you, as long as we can confirm they are acting for you.', 'surecookie' ) . '</p>';

		return $out;
	}

	/**
	 * India (DPDP Act, 2023).
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function jurisdiction_dpdp(): string {
		$details = Validator::details();
		$name    = trim( (string) ( $details['grievance_officer_name'] ?? '' ) );
		$email   = trim( (string) ( $details['grievance_officer_email'] ?? '' ) );

		$out  = '<h2 class="wp-block-heading">' . esc_html__( 'Your rights under India\'s Digital Personal Data Protection Act', 'surecookie' ) . '</h2>';
		$out .= '<p>' . esc_html__( 'If you are in India, the Digital Personal Data Protection Act, 2023 gives you the right to a summary of the personal data we hold about you and what we do with it, the right to have it corrected or erased, the right to have your grievance heard, the right to nominate someone to act for you if you die or become unable to act, and the right to withdraw your consent at any time.', 'surecookie' ) . '</p>';

		$out .= '<p>' . sprintf(
			/* translators: 1: grievance officer name, 2: grievance officer email link. */
			esc_html__( 'Grievance Officer: %1$s. You can reach them at %2$s.', 'surecookie' ),
			esc_html( $name ),
			sprintf( '<a href="%1$s">%2$s</a>', esc_url( 'mailto:' . $email, [ 'mailto' ] ), esc_html( $email ) )
		) . '</p>';

		return $out;
	}

	/**
	 * EU (GDPR).
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function jurisdiction_gdpr(): string {
		$out  = '<h2 class="wp-block-heading">' . esc_html__( 'Your rights under the GDPR', 'surecookie' ) . '</h2>';
		$out .= '<p>' . esc_html__( 'If you are in the European Economic Area, the General Data Protection Regulation gives you the right of access to your personal data, the right to have it corrected or erased, the right to restrict or object to how we use it, the right to receive it in a portable form, and the right to withdraw consent at any time without affecting anything done before you withdrew it.', 'surecookie' ) . '</p>';
		$out .= '<p>' . esc_html__( 'You also have the right to lodge a complaint with the data protection authority in the country where you live or work.', 'surecookie' ) . '</p>';

		return $out;
	}

	/**
	 * United Kingdom (UK GDPR).
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function jurisdiction_uk_gdpr(): string {
		$out  = '<h2 class="wp-block-heading">' . esc_html__( 'Your rights in the United Kingdom', 'surecookie' ) . '</h2>';
		$out .= '<p>' . esc_html__( 'If you are in the United Kingdom, the UK GDPR gives you the right of access to your personal data, the right to have it corrected or erased, the right to restrict or object to how we use it, the right to receive it in a portable form, and the right to withdraw consent at any time.', 'surecookie' ) . '</p>';
		$out .= '<p>' . esc_html__( 'You can also complain to the Information Commissioner\'s Office, which is the UK supervisory authority for data protection.', 'surecookie' ) . '</p>';

		return $out;
	}

	/**
	 * Children's privacy (COPPA).
	 *
	 * Emits the heading and an author block only: the source section is a
	 * negative assertion, and those are never generated.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function jurisdiction_coppa(): string {
		$out  = '<h2 class="wp-block-heading">' . esc_html__( 'Children\'s privacy', 'surecookie' ) . '</h2>';
		$out .= '<p>' . esc_html__( 'Whether and how this site collects information from children depends on what the site does, so the site owner describes it here.', 'surecookie' ) . '</p>';

		return $out;
	}

	/**
	 * Sentence describing when non-essential cookies run.
	 *
	 * Depends on three settings, so it can never be static prose: on a site
	 * with the banner off, or in opt-out mode, the usual sentence is false.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function consent_model_sentence(): string {
		if ( ! Settings::get( 'banner_enabled' ) ) {
			return __( 'This site does not currently ask visitors for a cookie choice.', 'surecookie' );
		}

		if ( Settings::get( 'consent_model' ) === 'opt-out' ) {
			return __( 'Non-essential cookies may be set before you answer the banner, and stop once you refuse them.', 'surecookie' );
		}

		if ( ! Settings::get( 'blocking_enabled' ) ) {
			return __( 'You are asked for a choice before non-essential cookies are used. Note that scripts are not blocked automatically, so some may run before you answer.', 'surecookie' );
		}

		return __( 'Non-essential cookies are only set after you allow them through the cookie banner.', 'surecookie' );
	}

	/**
	 * Sentence describing how long consent records are kept.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function retention_sentence(): string {
		$retention = (string) Settings::get( 'consent_log_retention' );
		$days      = Cron::get_retention_days( $retention );

		if ( $days === null ) {
			return __( 'These records are not deleted automatically. They stay on this site until someone removes them by hand. If you want yours removed, ask us using the contact details at the end of this policy.', 'surecookie' );
		}

		return sprintf(
			/* translators: %d: number of days consent records are kept. */
			__( 'These records are stored on this website and are deleted automatically once they are %d days old.', 'surecookie' ),
			$days
		);
	}

	/**
	 * Sentence naming the authority a complaint goes to.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function supervisory_authority_sentence(): string {
		$selected = Validator::selected_jurisdictions();

		if ( in_array( 'uk_gdpr', $selected, true ) ) {
			return __( 'If you are not happy with how we handle your request, you can complain to the Information Commissioner\'s Office in the United Kingdom, or to the data protection authority for your own country.', 'surecookie' );
		}

		return __( 'If you are not happy with how we handle your request, you can complain to the data protection authority for your country.', 'surecookie' );
	}

	/**
	 * Extra consent-record paragraphs that only apply with Pro active.
	 *
	 * Pro settings are read as plain settings rather than through a Pro class,
	 * so free never references code it does not ship.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function pro_consent_paragraphs(): string {
		if ( ! Helper::is_pro_active() ) {
			return '';
		}

		$out = '';

		if ( Settings::get( 'geo_display_mode' ) === 'selected_regions' ) {
			// "as well" only holds while the consent record itself resolves a country.
			if ( Helper::consent_log_ip_enabled() ) {
				/* translators: %s: hostname of the geolocation service. */
				$geo_page_views = esc_html__( 'Because this site applies different cookie rules in different countries, your full IP address is sent to %s on page views as well, not only when you make a choice, so that the right rules can be applied before the banner is shown.', 'surecookie' );
			} else {
				/* translators: %s: hostname of the geolocation service. */
				$geo_page_views = esc_html__( 'Because this site applies different cookie rules in different countries, your full IP address is sent to %s on page views, so that the right rules can be applied before the banner is shown. It is not kept, and it is not part of the record of your choice.', 'surecookie' );
			}

			$out .= '<p>' . sprintf( $geo_page_views, '<code>' . esc_html( self::GEO_LOOKUP_HOST ) . '</code>' ) . '</p>';
		}

		$partners = Settings::get( 'consent_forwarding_partner_sites' );
		$partners = is_array( $partners ) ? array_filter( array_map( 'trim', array_filter( $partners, 'is_string' ) ) ) : [];

		if ( Settings::get( 'consent_forwarding_enabled' ) && ! empty( $partners ) ) {
			$out .= '<p>' . sprintf(
				/* translators: %s: comma-separated list of partner sites. */
				esc_html__( 'Your choice is also passed to these related sites so that you do not have to answer the same question again: %s. The record sent contains the same fields listed above.', 'surecookie' ),
				esc_html( implode( ', ', $partners ) )
			) . '</p>';
		}

		return $out;
	}

	/**
	 * Cookie categories actually in use, name and description translated.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, string>>
	 */
	private function categories(): array {
		if ( isset( $this->memo['categories'] ) && is_array( $this->memo['categories'] ) ) {
			return $this->memo['categories'];
		}

		$configured = Settings::get( 'cookie_categories' );
		$in_use     = Get::categories_in_use();
		$rows       = [];

		foreach ( is_array( $configured ) ? $configured : [] as $key => $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}

			$id = ! empty( $category['id'] ) ? (string) $category['id'] : (string) $key;

			if ( ! in_array( $id, $in_use, true ) ) {
				continue;
			}

			$cat_key = sanitize_key( $id );

			$rows[] = [
				'id'          => $id,
				'name'        => Translation_Filter::translate_string( (string) ( $category['name'] ?? '' ), 'surecookie_cat_' . $cat_key . '_name' ),
				'description' => Translation_Filter::translate_string( (string) ( $category['description'] ?? '' ), 'surecookie_cat_' . $cat_key . '_description', true ),
			];
		}

		$this->memo['categories'] = $rows;

		return $rows;
	}

	/**
	 * Translated display name for a category id.
	 *
	 * @since 1.5.0
	 * @param string $id Category id.
	 * @return string
	 */
	private function category_name( string $id ): string {
		foreach ( $this->categories() as $category ) {
			if ( ( $category['id'] ?? '' ) === $id ) {
				return (string) $category['name'];
			}
		}

		return $id !== '' ? $id : __( 'Uncategorized', 'surecookie' );
	}

	/**
	 * Service slug to its one-line purpose, from the catalog.
	 *
	 * Read from the catalog rather than the blocking view, because the view
	 * keeps only what blocking needs and drops the description. Not run through
	 * Translation_Filter: these are shipped catalog strings, not copy the site
	 * owner authored, so they do not belong in the multilingual string registry.
	 *
	 * @since 1.5.0
	 * @return array<string, string>
	 */
	private function service_purposes(): array {
		if ( isset( $this->memo['service_purposes'] ) && is_array( $this->memo['service_purposes'] ) ) {
			return $this->memo['service_purposes'];
		}

		$purposes = [];

		foreach ( Services_Source::get_instance()->get_catalog() as $slug => $service ) {
			$description = is_array( $service ) ? trim( (string) ( $service['description'] ?? '' ) ) : '';

			if ( $description !== '' ) {
				$purposes[ (string) $slug ] = $description;
			}
		}

		$this->memo['service_purposes'] = $purposes;

		return $purposes;
	}

	/**
	 * Scanned third-party resources, or null when no scan has ever run.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, mixed>>|null
	 */
	private function resources(): ?array {
		if ( array_key_exists( 'resources', $this->memo ) ) {
			return is_array( $this->memo['resources'] ) ? $this->memo['resources'] : null;
		}

		$scanned = Get::option( SURECOOKIE_SCANNED_RESOURCES_OPTION, [], 'array' );

		if ( empty( $scanned ) ) {
			$this->memo['resources'] = null;
			return null;
		}

		$service                 = new ScriptBlockingService();
		$this->memo['resources'] = $service->list_resources( 'all', false, '' );

		return $this->memo['resources'];
	}

	/**
	 * Timestamp of the most recent scan.
	 *
	 * @since 1.5.0
	 * @return int
	 */
	private function scan_timestamp(): int {
		$details = Get::option( SURECOOKIE_SCANNED_DETAILS_OPTION, [], 'array' );
		$date    = is_array( $details ) && isset( $details['date'] ) ? (string) $details['date'] : '';
		$stamp   = $date !== '' ? strtotime( $date ) : false;

		return is_int( $stamp ) && $stamp > 0 ? $stamp : 0;
	}

	/**
	 * Build a responsive table matching the cookie-policy markup.
	 *
	 * @since 1.5.0
	 * @param array<int, string>             $head Header cells.
	 * @param array<int, array<int, string>> $rows Body rows.
	 * @return string
	 */
	private function table( array $head, array $rows ): string {
		$markup = '<table class="surecookie-privacy-policy-table"><thead><tr>';

		foreach ( $head as $cell ) {
			$markup .= '<th scope="col">' . esc_html( $cell ) . '</th>';
		}

		$markup .= '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$markup .= '<tr>';
			$index   = 0;

			foreach ( $row as $cell ) {
				$markup .= sprintf(
					'<td data-label="%1$s">%2$s</td>',
					esc_attr( $head[ $index ] ?? '' ),
					esc_html( (string) $cell )
				);
				++$index;
			}

			$markup .= '</tr>';
		}

		return $markup . '</tbody></table>';
	}

	/**
	 * A positive statement that something does not happen on this site.
	 *
	 * @since 1.5.0
	 * @param string $text Statement.
	 * @return string
	 */
	private function affirmative_negative( string $text ): string {
		return $this->wrap( '<p>' . esc_html( $text ) . '</p>' );
	}

	/**
	 * A section whose facts are genuinely not known yet.
	 *
	 * The visitor-facing sentence is neutral; the hint is only rendered for
	 * someone who can actually fix it, so the gap is discoverable while editing
	 * without leaking admin guidance onto a public page.
	 *
	 * @since 1.5.0
	 * @param string $text Visitor-facing sentence.
	 * @param string $hint Editor-only guidance.
	 * @return string
	 */
	private function unresolved( string $text, string $hint ): string {
		$out = '<p class="surecookie-privacy-policy-unresolved">' . esc_html( $text ) . '</p>';

		if ( is_user_logged_in() && current_user_can( Helper::capability() ) ) {
			$out .= '<p class="surecookie-privacy-policy-hint"><em>' . esc_html( $hint ) . '</em></p>';
		}

		return $this->wrap( $out );
	}

	/**
	 * Wrap rendered output and queue the stylesheet once per request.
	 *
	 * @since 1.5.0
	 * @param string $inner Rendered HTML.
	 * @return string
	 */
	private function wrap( string $inner ): string {
		if ( ! $this->styles_queued ) {
			wp_enqueue_style(
				'surecookie-privacy-policy',
				SURECOOKIE_URL . 'assets/css/' . Get::privacy_policy_style_css(),
				[],
				SURECOOKIE_VERSION
			);

			$this->styles_queued = true;
		}

		return '<div class="surecookie-privacy-policy">' . $inner . '</div>';
	}
}
