<?php
/**
 * Settings Metadata
 *
 * Annotates the settings dataset with the information an AI agent needs to set
 * a value correctly: a human description, the legal values, and a warning where
 * a wrong write is legally or operationally costly.
 *
 * Delivered as a filter on the settings dataset rather than inline in
 * Options::get_all_configurations(), so Pro can annotate its own keys the same
 * way through the filter it already uses.
 *
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Utils
 * @since      1.4.0
 */

namespace SureCookie\Inc\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Settings_Metadata
 *
 * @since 1.4.0
 */
class Settings_Metadata {
	/**
	 * Merge metadata into the settings dataset.
	 *
	 * Only annotates keys that already exist, so it never invents a setting.
	 *
	 * @param mixed $settings Settings dataset.
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public static function merge( $settings ): array {
		$settings = is_array( $settings ) ? $settings : [];

		foreach ( self::get_metadata() as $key => $meta ) {
			if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
				$settings[ $key ] = array_merge( $settings[ $key ], $meta );
			}
		}

		return $settings;
	}

	/**
	 * Per-key metadata.
	 *
	 * `enum` is declared only where the value set is closed in code. Sets that a
	 * filter can extend at runtime (color_palette via surecookie_color_palette_codes,
	 * auto_scan_frequency via surecookie_auto_scan_frequencies) list their values in
	 * the description instead, because a frozen enum would reject a legitimate
	 * third-party value before execute() ever runs.
	 *
	 * `internal` withholds a key from the writable schema. `hazard` is appended to
	 * the description and is reserved for writes that are legally significant,
	 * destructive, self-locking, or that fail silently.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.4.0
	 */
	private static function get_metadata(): array {
		return [
			// Banner appearance.
			'notice_type'                  => [
				'enum'        => [ 'box', 'banner' ],
				'description' => __( 'Banner shape: "box" is a floating card, "banner" is a full-width bar.', 'surecookie' ),
				'hazard'      => __( 'Coupled with notice_position, so set both together: "box" takes bottom-left, bottom-center, bottom-right or middle, "banner" takes only top or bottom. A mismatched pair is not rejected, it silently renders somewhere else.', 'surecookie' ),
			],
			'notice_position'              => [
				'enum'        => [ 'bottom-left', 'bottom-center', 'bottom-right', 'middle', 'top', 'bottom' ],
				'description' => __( 'Where the banner sits on screen. The legal subset depends on notice_type.', 'surecookie' ),
				'hazard'      => __( 'Not interchangeable across notice_type: a box-only value on a "banner" renders as a bottom bar, and top/bottom on a "box" falls back to bottom-right, both silently.', 'surecookie' ),
			],
			'banner_position'              => [
				'internal'    => true,
				'description' => __( 'Unused. Superseded by notice_position.', 'surecookie' ),
			],
			'banner_animation'             => [
				'enum'        => [ 'fade', 'slide' ],
				'description' => __( 'Banner entrance animation.', 'surecookie' ),
			],
			'color_palette'                => [
				'description' => __( 'ID of the banner colour preset. Built-ins are blue-purple, purple-pink, blue-cyan, green-lime, red-orange, dark-blue, dark-green, dark-pink and dark-orange, plus "astra" while the Astra theme is active. A theme or plugin can register more.', 'surecookie' ),
				'hazard'      => __( 'Not validated on write: an unknown ID reports success and silently renders the default palette.', 'surecookie' ),
			],
			'banner_overlay_enabled'       => [
				'description' => __( 'Dim the page behind the banner and block interaction until the visitor chooses.', 'surecookie' ),
				'hazard'      => __( 'Forces a consent choice before the site can be used, which conflicts with the freely-given consent requirement in some regions. Confirm with the user before enabling.', 'surecookie' ),
			],
			'banner_enabled'               => [
				'description' => __( 'Master switch for the consent banner.', 'surecookie' ),
				'hazard'      => __( 'Also disables script and iframe blocking, whatever blocking_enabled says, so turning it off lets trackers fire before consent.', 'surecookie' ),
			],
			'banner_logo'                  => [
				'description' => __( 'Absolute URL of an image shown at the top of the banner. Empty string removes it.', 'surecookie' ),
			],
			'message_heading'              => [
				'description' => __( 'Plain-text headline above the banner message. HTML is stripped. Empty renders no heading.', 'surecookie' ),
			],
			'message_description'          => [
				'description' => __( 'Banner body copy. Accepts post-level HTML such as links and emphasis.', 'surecookie' ),
			],

			// Consent model and compliance.
			'consent_model'                => [
				'enum'        => [ 'opt-in', 'opt-out' ],
				'description' => __( 'Site-wide consent model: "opt-in" (GDPR style, nothing non-essential runs before consent) or "opt-out" (CCPA style, allowed until the visitor declines).', 'surecookie' ),
				'hazard'      => __( 'Legally load-bearing. "opt-out" lets non-essential trackers fire before any consent, which is unlawful for EU and UK visitors, and it removes the Preferences and Accept All buttons. Confirm the site\'s jurisdiction with the user before changing it.', 'surecookie' ),
			],
			'compliance_law'               => [
				'description' => __( 'An {id, name} object naming the regulation the site declares it follows. Only "name" is read, and only for usage telemetry, so this does not change banner or blocking behaviour.', 'surecookie' ),
			],
			'consent_log_retention'        => [
				'enum'        => [ '30_days', '60_days', '90_days', '365_days', 'never' ],
				'description' => __( 'How long consent log rows are kept before the daily cleanup cron deletes them. Note the token format is "<days>_days", not a bare number.', 'surecookie' ),
				'hazard'      => __( 'Destructive. Shortening this makes the next cron run permanently delete every consent row older than the new window, erasing the proof-of-consent evidence GDPR Article 7(1) requires. Read the current value and confirm before lowering it.', 'surecookie' ),
			],
			'blocking_enabled'             => [
				'description' => __( 'Master switch for pre-consent script and iframe blocking.', 'surecookie' ),
				'hazard'      => __( 'Turning this off lets analytics and marketing tags fire before consent is collected. It is the single most compliance-significant setting after consent_model.', 'surecookie' ),
			],
			'accept_all_enabled'           => [
				'description' => __( 'Whether the banner offers an Accept All button.', 'surecookie' ),
				'hazard'      => __( 'Not cosmetic: with it off the consent-log endpoint rejects every accept-all record with HTTP 422, so existing integrations that post one will start failing.', 'surecookie' ),
			],
			'delete_data_on_uninstall'     => [
				'description' => __( 'Whether uninstalling the plugin also deletes its data.', 'surecookie' ),
				'hazard'      => __( 'Arms an irreversible action: on uninstall it drops the consent-log table and deletes every SureCookie option. Never enable it without explicit user confirmation.', 'surecookie' ),
			],
			'consent_renewed_at'           => [
				'description' => __( 'UNIX timestamp in seconds of the last "re-request consent" action. Any consent recorded before this instant is treated as stale.', 'surecookie' ),
				'hazard'      => __( 'Writing the current time invalidates every visitor\'s existing consent and shows the banner again site-wide. Only set it when the user explicitly asks to re-collect consent.', 'surecookie' ),
			],
			'enable_mcp'                   => [
				'description' => __( 'Whether SureCookie registers its WordPress Abilities and MCP server.', 'surecookie' ),
				'hazard'      => __( 'Self-locking: writing false through this ability deregisters every SureCookie ability on the next request, so no AI client can turn it back on. It can only be re-enabled from wp-admin.', 'surecookie' ),
			],

			// Google Consent Mode.
			'gcm_enabled'                  => [
				'description' => __( 'Whether SureCookie emits Google Consent Mode v2 signals (a gtag consent default in the head, and a consent update once the visitor chooses).', 'surecookie' ),
				'hazard'      => __( 'Turning this on also exempts Google services, Tag Manager included, from script blocking, because Consent Mode is expected to restrain them instead. Non-Google tags deployed inside Tag Manager are not covered by that and will load before consent unless each one carries its own consent check.', 'surecookie' ),
			],
			'gcm_wait_for_update'          => [
				'description' => __( 'Milliseconds Google services wait for a consent update before acting on the default. Clamped to 0-2000.', 'surecookie' ),
			],
			'gcm_default_consent'          => [
				'description' => __( 'A {functional, analytics, marketing} map giving the consent state Google services assume for a visitor who has not answered the banner yet. Marketing drives four signals: ad_storage, ad_user_data, ad_personalization and personalization_storage.', 'surecookie' ),
				'hazard'      => __( 'Legally load-bearing, and ships all-denied for a reason. Granting a category here tells Google the visitor consented before they were asked, so tags fire on the first page view and a later decline cannot undo it. The banner still looks like it is working, which is what makes this the easiest setting to get wrong.', 'surecookie' ),
			],
			'gcm_region_defaults'          => [
				'description' => __( 'Per-region overrides of gcm_default_consent, each an {region[], functional, analytics, marketing} entry. Google applies the most specific match, so US-CA beats US. Ships with one EU rule denying everything.', 'surecookie' ),
				'hazard'      => __( 'Deleting the shipped EU rule drops EU and EEA visitors onto the worldwide baseline in gcm_default_consent, which is unlawful there if that baseline grants anything. Removing a rule is silent, with no warning in the UI.', 'surecookie' ),
			],

			// Buttons.
			'button_order'                 => [
				'description' => __( 'Order of the banner buttons in reading order (mirrored on right-to-left pages), as a comma-separated list of the tokens accept_all, accept (essential only), preferences and decline. A token left out is hidden. Keep accept_all in the order and hide it with accept_all_enabled, which Geographic Targeting rules and the consent log also read.', 'surecookie' ),
				'hazard'      => __( 'Preferences and at least one of accept or decline are always kept: a value without them has them appended, and unknown or repeated tokens are dropped. A value naming no known token is refused and the stored order kept.', 'surecookie' ),
			],
			'accept_btn_text'              => [
				'description' => __( 'Label for the essential-only button. Empty falls back to the translated "Only Essential".', 'surecookie' ),
				'hazard'      => __( 'This is not the Accept All button, which is accept_all_btn_text. Labelling it "Accept" misrepresents what the visitor is consenting to.', 'surecookie' ),
			],
			'accept_all_btn_text'          => [
				'description' => __( 'Label for the Accept All button. Empty falls back to the translated "Accept All".', 'surecookie' ),
			],
			'decline_btn_text'             => [
				'description' => __( 'Label for the Decline button. Empty falls back to the translated "Decline".', 'surecookie' ),
			],
			'preferences_btn_text'         => [
				'description' => __( 'Label for the Preferences button, which opens the category choices. Empty falls back to the translated "Preferences".', 'surecookie' ),
			],

			// Colours and styling.
			'custom_colors'                => [
				'description' => __( 'Pro banner colour override, shaped {mode, colors}. Send mode "custom" together with the colors map.', 'surecookie' ),
				'hazard'      => __( 'Writing colors while mode stays "preset" is a silent no-op: color_palette continues to win and the write still reports success.', 'surecookie' ),
			],
			'custom_css'                   => [
				'description' => __( 'Raw CSS inlined on every frontend page to restyle the banner and preferences modal. Requires the unfiltered_html capability.', 'surecookie' ),
			],

			// Registries owned by a dedicated ability.
			'cookie_categories'            => [
				'description' => __( 'The consent-category registry the whole consent model derives from. Readable here, but edit it with the surecookie/cookie-categories ability.', 'surecookie' ),
			],
			'custom_cookies'               => [
				'description' => __( 'The manually declared cookie registry shown in the preferences modal. Readable here, but edit it with the surecookie/cookie-management ability.', 'surecookie' ),
			],

			// Scanning.
			'scan_pages'                   => [
				'description' => __( 'Saved manual scan selection, as {label, value} rows whose value is a published post ID. Prefer the surecookie/site-scanner ability with action "start", which resolves and validates the rows for you.', 'surecookie' ),
			],
			'auto_scan_enabled'            => [
				'description' => __( 'Master switch for unattended scheduled scanning. Enabling it schedules the WP-Cron event.', 'surecookie' ),
			],
			'auto_scan_frequency'          => [
				'description' => __( 'Cadence of the automatic scan. "monthly" always works; "weekly" requires SureCookie Pro. A theme or plugin can register more.', 'surecookie' ),
				'hazard'      => __( 'The clamp is silent: an unsupported value, including "weekly" without Pro, is reported as saved but stores "monthly".', 'surecookie' ),
			],
			'auto_scan_scope'              => [
				'enum'        => [ 'same_as_manual', 'all_published', 'selected' ],
				'description' => __( 'What the automatic scan crawls: "same_as_manual" reuses scan_pages, "all_published" walks published content up to the plan limit, "selected" uses auto_scan_pages.', 'surecookie' ),
			],
			'auto_scan_pages'              => [
				'description' => __( 'Content the automatic scan crawls when auto_scan_scope is "selected". Accepts picker rows or bare post IDs, and is ignored under any other scope.', 'surecookie' ),
			],

			// Resource blocking.
			'excluded_scan_resources'      => [
				'description' => __( 'Scan-detected resources marked "do not block", keyed per kind as "script::example.com" or "iframe::example.com".', 'surecookie' ),
			],
			'resource_category_overrides'  => [
				'description' => __( 'Category reassignment for scan-detected scripts and iframes, mapping the same scoped key format as excluded_scan_resources to a cookie category ID.', 'surecookie' ),
			],
			'custom_blocked_scripts'       => [
				'description' => __( 'Admin-authored blocking rules. Each row needs a non-empty "value" URL or domain pattern and a "category", optionally narrowed by type, location, path and keywords.', 'surecookie' ),
			],

			// Blocked-content placeholder.
			'placeholder_image'            => [
				'description' => __( 'Absolute URL of the fallback image on the placeholder that replaces blocked embeds. Empty renders a text-only placeholder.', 'surecookie' ),
			],
			'placeholder_description'      => [
				'description' => __( 'Body copy of the blocked-content placeholder. Keep the literal token {service}, which is replaced with the vendor name at render time.', 'surecookie' ),
			],
			'placeholder_button_text'      => [
				'description' => __( 'Label of the consent button on the blocked-content placeholder. Empty renders the built-in "Accept & Load".', 'surecookie' ),
			],
			'placeholder_video_thumbnails' => [
				'description' => __( 'Show a blocked video\'s own thumbnail on the placeholder. Currently resolves YouTube URLs only.', 'surecookie' ),
			],

			// Pages and menus.
			'cookie_policy_page_id'        => [
				'description' => __( 'Post ID of the cookie policy page linked from the preferences modal. 0 means none is linked.', 'surecookie' ),
				'hazard'      => __( 'Sanitized with absint, so a non-numeric or negative value becomes 0 and silently unlinks the policy page.', 'surecookie' ),
			],
			'privacy_policy_page_id'       => [
				'description' => __( 'Post ID of the SureCookie-managed privacy policy page. 0 means none is linked.', 'surecookie' ),
				'hazard'      => __( 'This is not WordPress\'s own wp_page_for_privacy_policy option. Writing it does not change the page WordPress links from the footer or the login screen, nor the one the consent modal links, which reads core\'s option first and falls back to this only when core has no published policy; use the "Use as WordPress Privacy Policy page" action to make them agree.', 'surecookie' ),
			],
			'policy_links_order'           => [
				'description' => __( 'Which legal links the preferences modal shows and in what order, as a comma-separated list of the tokens cookie_policy, privacy_policy and legal_notice. A token left out is hidden. An empty string hides all three.', 'surecookie' ),
				'hazard'      => __( 'Hiding a link does not unpublish its page, but with the banner overlay on the consent UI is the only place a visitor can reach it.', 'surecookie' ),
			],
			'legal_notice_page_id'         => [
				'description' => __( 'Post ID of the legal notice / imprint (Impressum) page linked from the preferences modal. 0 means none is linked.', 'surecookie' ),
				'hazard'      => __( 'Sanitized with absint, so a non-numeric or negative value becomes 0 and silently unlinks a page several jurisdictions require to stay reachable.', 'surecookie' ),
			],
			'privacy_jurisdictions'        => [
				'description' => __( 'Privacy regimes whose sections appear in the generated privacy policy. Empty means none.', 'surecookie' ),
				'enum'        => [ 'gdpr', 'uk_gdpr', 'cpra', 'dpdp', 'coppa' ],
				'hazard'      => __( 'A legal declaration, not a display toggle. Enabling a regime the site is not subject to publishes commitments it may not be able to meet.', 'surecookie' ),
			],
			'organization_details'         => [
				'description' => __( 'Controller identity shown as "Business Details": legal entity name, postal address lines, postal code, country, privacy contact email, grievance officer, transfer safeguard text and the CCPA sale/share declaration.', 'surecookie' ),
				'hazard'      => __( 'Stored as one nested value, so a partial write replaces every sibling field. Read, merge, then write. Clearing the entity name, address, country or contact email blocks publishing the generated policy and escalates the in-product notice on an already published one.', 'surecookie' ),
			],
			'reconsent_menu_id'            => [
				'description' => __( 'Term ID of the nav menu that receives the virtual "Cookie Preferences" item, as a numeric string. Empty string injects nothing.', 'surecookie' ),
			],
			'top_level_menu_enabled'       => [
				'description' => __( 'Whether SureCookie gets its own top-level admin menu rather than living under Settings.', 'surecookie' ),
			],

			// Internal state.
			'preview_enabled'              => [
				'internal'    => true,
				'description' => __( 'Admin-only preview panel state. No effect on the public site.', 'surecookie' ),
			],
			'show_preview'                 => [
				'internal'    => true,
				'description' => __( 'Unused.', 'surecookie' ),
			],
			'total_logs'                   => [
				'internal'    => true,
				'description' => __( 'Internal counter mirroring the consent-log table.', 'surecookie' ),
			],
		];
	}
}
