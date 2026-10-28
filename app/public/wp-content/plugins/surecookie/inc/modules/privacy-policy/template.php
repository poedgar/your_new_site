<?php
/**
 * Default content for the generated privacy policy.
 *
 * Every sentence here is either generic to any website or a placeholder the
 * author replaces. Anything whose truth depends on a setting lives in a
 * shortcode instead, because the generator runs once and never rewrites the
 * page: prose holding a setting value would be stale the first time that
 * setting changed, silently and with no repair path.
 *
 * @package SureCookie\Inc\Modules\PrivacyPolicy
 * @since 1.5.0
 */

namespace SureCookie\Inc\Modules\PrivacyPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Template
 *
 * @since 1.5.0
 */
final class Template {
	/**
	 * The whole page, in order.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function sections(): array {
		return array_merge(
			self::preamble(),
			self::identity(),
			self::collection(),
			self::cookies(),
			self::consent_records(),
			self::recipients(),
			self::retention(),
			self::transfers(),
			self::jurisdictions(),
			self::rights(),
			self::closing()
		);
	}

	/**
	 * Last-updated stamp and scope.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function preamble(): array {
		return [
			[
				'type' => 'shortcode',
				'tag'  => 'surecookie_privacy_last_updated',
			],
			[
				'type' => 'paragraph',
				'text' => __( 'This policy explains what personal information this website collects about you, why we collect it, who else receives it, and what choices and rights you have. It applies to this website and to the services we run on it.', 'surecookie' ),
			],
		];
	}

	/**
	 * Controller identity.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function identity(): array {
		return [
			[
				'type'  => 'heading',
				'level' => 2,
				'text'  => __( 'Who we are', 'surecookie' ),
			],
			[
				'type' => 'paragraph',
				'text' => __( 'This website is run by the organisation named below. That organisation is responsible for the personal information described in this policy.', 'surecookie' ),
			],
			[
				'type' => 'shortcode',
				'tag'  => 'surecookie_privacy_identity',
			],
		];
	}

	/**
	 * What the site collects directly.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function collection(): array {
		return [
			[
				'type'  => 'heading',
				'level' => 2,
				'text'  => __( 'What personal information we collect', 'surecookie' ),
			],
			[
				'type' => 'paragraph',
				'text' => __( 'When you visit this website, our web server automatically records technical details that your browser sends with every request, such as your IP address, which pages you asked for, when you asked for them, and which page or search sent you here. This happens on every website, and we use it to serve pages and to keep the site secure.', 'surecookie' ),
			],
			[
				'type' => 'paragraph',
				'text' => __( 'Cookies and similar technologies may collect more than this, depending on the choices you make. That is described in the next two sections.', 'surecookie' ),
			],
			[
				'type' => 'placeholder',
				'text' => __( 'Replace this block: Describe anything else this site collects directly from people. For example names, email addresses and messages from contact forms; account details if visitors can register; delivery and billing details if you sell something; files people upload. For each one, say what you collect it for and how long you keep it.', 'surecookie' ),
			],
		];
	}

	/**
	 * Cookies, categories and the withdrawal control.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function cookies(): array {
		return [
			[
				'type'  => 'heading',
				'level' => 2,
				'text'  => __( 'Cookies and similar technologies', 'surecookie' ),
			],
			[
				'type' => 'paragraph',
				'text' => __( 'Cookies are small files a website asks your browser to store. Similar technologies, such as pixels, tags and browser storage, do the same job in a different way. Some are needed for the site to work at all. Others are used for measurement or advertising, and those are the ones you get a choice about.', 'surecookie' ),
			],
			[
				'type' => 'shortcode',
				'tag'  => 'surecookie_privacy_cookie_summary',
			],
			[
				'type' => 'paragraph',
				'text' => __( 'You can review or change your choice at any time using the cookie preferences control on this site.', 'surecookie' ),
			],
		];
	}

	/**
	 * Consent records, retention and the geolocation lookup.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function consent_records(): array {
		return [
			[
				'type'  => 'heading',
				'level' => 2,
				'text'  => __( 'Your cookie consent choices', 'surecookie' ),
			],
			[
				'type' => 'shortcode',
				'tag'  => 'surecookie_privacy_consent_records',
			],
		];
	}

	/**
	 * Who receives information about a visit.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function recipients(): array {
		return [
			[
				'type'  => 'heading',
				'level' => 2,
				'text'  => __( 'Who receives information about your visit', 'surecookie' ),
			],
			[
				'type' => 'paragraph',
				'text' => __( 'Some of the services this site uses receive information about you directly, because your browser has to contact them to load the content or the script they provide. When that happens they can see your IP address and basic details about your browser, whether or not you interact with anything.', 'surecookie' ),
			],
			[
				'type' => 'shortcode',
				'tag'  => 'surecookie_privacy_third_parties',
			],
			[
				'type'  => 'heading',
				'level' => 3,
				'text'  => __( 'What this site sends from its own server', 'surecookie' ),
			],
			[
				'type' => 'shortcode',
				'tag'  => 'surecookie_privacy_server_egress',
			],
			[
				'type' => 'placeholder',
				'text' => __( 'Replace this block: Add any other organizations that receive personal information from this site but would not show up in a scan of the pages. Common examples are your web host, your payment processor, your email delivery service, your CRM or helpdesk, and delivery companies.', 'surecookie' ),
			],
			[
				'type' => 'shortcode',
				'tag'  => 'surecookie_privacy_regional',
			],
		];
	}

	/**
	 * Retention.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function retention(): array {
		return [
			[
				'type'  => 'heading',
				'level' => 2,
				'text'  => __( 'How long we keep your information', 'surecookie' ),
			],
			[
				'type' => 'paragraph',
				'text' => __( 'We keep personal information only for as long as we need it for the purpose we collected it for, or for as long as the law requires us to. How long this site keeps records of your cookie choices is set out under "Your cookie consent choices" above.', 'surecookie' ),
			],
			[
				'type' => 'placeholder',
				'text' => __( 'Replace this block: Add how long you keep the other information listed above, such as form submissions, accounts, orders, invoices and support messages.', 'surecookie' ),
			],
		];
	}

	/**
	 * Where data is processed.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function transfers(): array {
		return [
			[
				'type' => 'shortcode',
				'tag'  => 'surecookie_privacy_data_transfers',
			],
		];
	}

	/**
	 * One block per known regime, selected or not.
	 *
	 * Separate blocks rather than one tag with a list, so a user can delete or
	 * reorder a single regime's section without touching the others.
	 *
	 * Every regime gets a block even when it is switched off, because the page is
	 * generated once and never rewritten: emitting only the selected ones would
	 * mean a regime turned on afterwards could never appear without hand-editing
	 * the page. The shortcode renders nothing for a regime that is not selected,
	 * so switching one on later behaves like every other setting.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function jurisdictions(): array {
		$sections = [];

		foreach ( Validator::JURISDICTIONS as $code ) {
			$sections[] = [
				'type' => 'shortcode',
				'tag'  => 'surecookie_privacy_jurisdiction',
				'atts' => [ 'code' => $code ],
			];
		}

		return $sections;
	}

	/**
	 * Data subject rights, legal basis and security.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function rights(): array {
		return [
			[
				'type'  => 'heading',
				'level' => 2,
				'text'  => __( 'Your rights over your information', 'surecookie' ),
			],
			[
				'type' => 'shortcode',
				'tag'  => 'surecookie_privacy_rights',
			],
			[
				'type'  => 'heading',
				'level' => 3,
				'text'  => __( 'Why we are allowed to use your information', 'surecookie' ),
			],
			[
				'type' => 'paragraph',
				'text' => __( 'For anything on this site that needs your permission, including non-essential cookies, our basis is the permission you give through the cookie banner, and you can take it back at any time without affecting anything we did before you did.', 'surecookie' ),
			],
			[
				'type' => 'placeholder',
				'text' => __( 'Replace this block: Add the reason you are allowed to use the other information listed above. The usual reasons are that someone asked you to do something (a contract), that you have to by law, or that you have a legitimate interest you have weighed against their privacy.', 'surecookie' ),
			],
			[
				'type'  => 'heading',
				'level' => 2,
				'text'  => __( 'Keeping your information secure', 'surecookie' ),
			],
			[
				'type' => 'paragraph',
				'text' => __( 'We take reasonable technical and organisational steps to protect personal information from being lost, misused, or accessed by people who should not see it. No website and no way of sending information over the internet is completely secure, so we cannot promise absolute security.', 'surecookie' ),
			],
		];
	}

	/**
	 * Third-party links, changes and contact.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function closing(): array {
		return [
			[
				'type'  => 'heading',
				'level' => 2,
				'text'  => __( 'Links to other websites', 'surecookie' ),
			],
			[
				'type' => 'paragraph',
				'text' => __( 'This site may link to websites we do not run. Those websites have their own privacy policies and their own practices, and we are not responsible for their content or what they do with your information.', 'surecookie' ),
			],
			[
				'type'  => 'heading',
				'level' => 2,
				'text'  => __( 'Changes to this policy', 'surecookie' ),
			],
			[
				'type' => 'paragraph',
				'text' => __( 'We may update this policy when this site changes, when what we do with information changes, or when the law changes. When we do, we will publish the new version on this page and update the date at the top. If a change means we need your permission for something new, we will ask you for it.', 'surecookie' ),
			],
			[
				'type'  => 'heading',
				'level' => 2,
				'text'  => __( 'How to contact us', 'surecookie' ),
			],
			[
				'type' => 'paragraph',
				'text' => __( 'If you have a question about this policy, or you want to exercise any of the rights described above, you can reach us here:', 'surecookie' ),
			],
			[
				'type' => 'shortcode',
				'tag'  => 'surecookie_privacy_identity',
			],
			[
				'type' => 'shortcode',
				'tag'  => 'surecookie_privacy_contact',
			],
		];
	}
}
