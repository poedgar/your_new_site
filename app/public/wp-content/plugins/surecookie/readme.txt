=== SureCookie - GDPR Cookie Consent Banner, Cookie Scanner & Script Blocking ===
Contributors: brainstormforce
Tags: cookie consent, cookie banner, consent logs, ccpa, cookie policy
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://www.paypal.me/BrainstormForce

Cookie consent banner with real browser scanning, script blocking, Google Consent Mode, GDPR/CCPA consent logs, and a cookie policy page.

== Description ==

SureCookie is a WordPress cookie consent plugin that helps you scan cookies, display a customizable cookie banner, block non-essential scripts before consent, and store consent logs inside your WordPress database.

It is built for site owners, E-commerce stores, agencies, bloggers, and WordPress professionals who want more than a basic cookie notice. SureCookie helps you understand what cookies and third-party services are running on your site, lets visitors manage their choices, and gives you a practical consent workflow without visitor-based pricing.

👉 <a href="https://app.zipwp.com/blueprint/surecookie-n9i" target="_blank" rel="noopener">Try the live demo of SureCookie.</a>

[youtube https://www.youtube.com/watch?v=IwU3Qa1VQYI]

= Not Just a Cookie Banner =

A cookie notice can tell visitors that your site uses cookies, but that alone does not manage consent. If analytics scripts, marketing pixels, video embeds, maps, or tag manager scripts run before visitors choose, the banner is only cosmetic. SureCookie connects the banner to the rest of the workflow: scan the site, review detected cookies, block non-essential scripts before consent, store consent logs locally, and keep your cookie policy easier to maintain.

= How SureCookie Works =

SureCookie follows a simple WordPress cookie consent workflow:

1. Scan selected pages with the real browser cookie scanner.
2. Review detected cookies, scripts, resources, and third-party domains.
3. Organize cookies into consent categories such as Essential, Functional, Analytics, and Marketing.
4. Enable script blocking so non-essential scripts wait for consent.
5. Show the cookie consent banner and preference modal to visitors.
6. Store consent logs locally in WordPress for review and export.
7. Generate or connect a cookie policy page that reflects your cookie setup.
8. Generate a privacy policy draft whose cookie and consent sections stay in step with your settings.

This makes SureCookie more than a WordPress cookie banner. Many cookie plugins focus only on the visitor-facing notice, while SureCookie focuses on what happens before and after it: cookie detection, script blocking, consent records, and policy support.

= Free Features Included =

The WordPress.org version of SureCookie includes the core consent workflow:

* Cookie consent banner: Show a clean banner or notice for visitors.
* Privacy policy generator: Build a privacy policy draft from your cookie and consent settings, with the factual sections kept current by shortcodes.
* Business details: Enter your legal entity, address and privacy contact once, then reuse them anywhere with shortcodes such as [surecookie_company_name].
* Preference modal: Let visitors review and manage cookie categories.
* Accept, Accept All, Decline, and Preferences buttons: Control each button's text, order and visibility.
* Real browser cookie scanner: Scan selected pages using a browser-based scanning service.
* Cookie categories: Use Essential, Functional, Analytics, Marketing, and Uncategorized categories. See [managing cookie categories](https://surecookie.com/docs/manage-cookie-categories/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=cookie_categories_docs).
* Custom cookies: Add and manage cookies manually when needed.
* Script blocking: Block non-essential scripts before consent is given.
* Resource blocking: Manage scripts, iframes, embeds, and objects found during scans.
* Consent logs: Store visitor choices inside your WordPress database.
* Consent log filters: Search and filter logs by action, country, IP, and session ID.
* Consent PDF export: Export individual consent records for documentation.
* Consent retention settings: Control how long consent logs are kept.
* Monthly automatic scanning: Schedule recurring scans on the free plan.
* Scan history and change detection: See what changed between scans, including newly detected cookies and domains.
* Rule-based category suggestions: Get suggested categories for newly detected cookies.
* Cookie policy page: Generate a page with dynamic cookie tables.
* Re-consent controls: Add a Cookie Preferences link through a shortcode or menu item.
* Re-request consent: Ask all visitors to review choices again when needed.
* Google Consent Mode support: Send consent states to supported Google services.
* WP Consent API support: Share consent state with compatible WordPress plugins.
* Multilingual support: Work with WPML and Polylang for banner and admin text.
* RTL support: Display frontend cookie policy content correctly for RTL languages.
* MCP and WordPress Abilities support: Enable AI assistant access to SureCookie management actions when supported.
* No visitor-based limits: SureCookie does not charge by traffic or monthly visitors.

= Free vs Pro Clarity =

Everything listed above is in the free plugin. Advanced workflows are reserved for SureCookie Pro: weekly automatic scans, email scan digests, auto-apply behavior, compliance guard workflows, [geographic targeting](https://surecookie.com/docs/how-to-set-up-geographic-targeting/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=geo_targeting_docs) (which applies CCPA-style opt-out rules per region), and consent forwarding. Compare on the [features](https://surecookie.com/features/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=features_page) page or see [plans and pricing](https://surecookie.com/pricing/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=pricing_page).


= Real Browser Cookie Scanner =

SureCookie uses a browser-based scanning service at [https://library.surecookie.com/](https://library.surecookie.com/) to inspect selected pages. See how the [cookie scanner](https://surecookie.com/docs/cookie-scanner/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=cookie_scanner_docs) works.

Instead of only reading static HTML, the scanner loads your pages in a real browser environment. This helps detect cookies and resources that appear after page load, through tag managers, or through third-party scripts.

The scanner can help identify:

* Analytics, marketing, advertising, functional, and preference cookies
* WooCommerce and WordPress session cookies
* Third-party domains and resources
* Tag manager loaded and dynamically injected scripts


= Monthly Automatic Scanning =

When enabled, SureCookie runs scheduled monthly scans through WP-Cron. The scan uses the same scanner engine, respects the configured scan scope, and records the latest scan history. Full setup is in the [automatic scheduled scanning](https://surecookie.com/docs/automatic-scheduled-scanning/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=auto_scanning_docs) guide.

The scan history can show:

* Newly detected cookies
* Removed cookies
* Recategorized cookies
* Newly detected third-party domains
* Whether the scan was manual or automatic
* The last scan date and cookie count

= Script Blocking Before Consent =

SureCookie can block non-essential scripts before the visitor gives consent.

When blocking is enabled, SureCookie processes the frontend HTML and converts matching resources so they do not execute until the relevant cookie category is allowed. It can handle scripts as well as embedded content such as iframes, embeds, and objects.

This matters because a banner alone does not stop tracking. SureCookie is designed to connect consent choices with technical enforcement.

The script blocker also includes safeguards so it skips admin pages, REST requests, AJAX requests, feeds, JSON responses, XML responses, and scanner bypass requests. The [resource and script blocking](https://surecookie.com/docs/resource-blocking/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=resource_blocking_docs) guide covers how scripts, iframes and embeds are matched.

= Customizable Banner and Preferences =

SureCookie gives you control over the visitor-facing consent experience.

You can customize:

* Banner message and description
* Rich text banner content
* Accept, Accept All, Decline, and Preferences button labels
* Button order and visibility
* Banner position and width
* Banner logo
* Banner animation
* Background overlay
* Preference modal heading and description
* Cookie category labels and descriptions
* Custom CSS

Visitors can accept all cookies, decline non-essential cookies, or open the preferences modal and choose specific categories. See [customizing banner content](https://surecookie.com/docs/customizing-banner-content/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=banner_content_docs) and [banner layout](https://surecookie.com/docs/customizing-banner-layout/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=banner_layout_docs) for every option, and [custom CSS for banner styling](https://surecookie.com/docs/custom-css-for-banner-styling/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=banner_css_docs) if you need finer control.

= Consent Logs Stored Locally =

SureCookie stores consent records inside your WordPress database.

This is different from many SaaS consent management platforms where consent records live on an external platform. With SureCookie, your consent logs stay on your WordPress site and can be reviewed from the admin area.

Consent logs can include:

* User session ID
* Consent action, such as accepted, declined, or partially accepted
* Cookie category preferences
* Masked IP address
* Timestamp
* Country

Admins can view, search, filter, delete, and export logs from WordPress. SureCookie also includes retention settings so you can control how long logs are kept. See [understanding consent logs](https://surecookie.com/docs/understanding-surecookie-logs/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=consent_logs_docs) and [exporting consent logs to PDF or CSV](https://surecookie.com/docs/exporting-consent-logs-pdf-csv/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=consent_logs_export_docs).

= Cookie Policy Page and Generator =

SureCookie includes a cookie policy generator that can create a Cookie Policy page for your website.

The generated page uses native WordPress blocks and includes a dynamic shortcode that displays cookie tables grouped by category and provider. The cookie policy content can include:

* Cookie categories
* Cookie names
* Purpose or description
* Duration
* Domain
* Last updated timestamp
* A table of contents when multiple categories are available

You can edit the generated policy page in the WordPress editor and keep the dynamic cookie table connected to your scanned and manually added cookies. Walkthrough: [how to generate a cookie policy page](https://surecookie.com/docs/how-to-generate-cookie-policy-page/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=cookie_policy_docs).

= Re-Consent and Re-Request Consent =

Visitors should be able to change their choices later.

SureCookie includes a re-consent shortcode:

`[surecookie_reconsent_button]`

You can use it to add a Cookie Preferences button on your site. SureCookie can also add a virtual Cookie Preferences item to a selected WordPress navigation menu.

Admins can also re-request consent from all visitors. This is useful after updating your cookie policy, adding new tracking tools, changing categories, or making a major consent workflow change. See the [re-consent](https://surecookie.com/docs/re-consent/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=reconsent_docs) guide.

= Google Consent Mode =

When enabled, SureCookie sends consent states for supported Google services and updates them when visitors change their preferences. Setup steps: [setting up Google Consent Mode v2](https://surecookie.com/docs/setting-up-google-consent-mode-v2/?utm_source=wporg&utm_medium=readme&utm_campaign=core_plugin&utm_content=consent_mode_docs). It maps SureCookie categories to Google consent signals, detects Google services from enqueued scripts or the page HTML, and hides block toggles that Google Consent Mode already manages.

= WP Consent API =

SureCookie reads its consent cookie and syncs the visitor's consent state so compatible WordPress plugins can check consent using the standardized WP Consent API flow.

Default category mapping includes:

* Essential to functional
* Functional to preferences
* Analytics to statistics
* Marketing to marketing

Developers can customize mappings through filters.

= Multilingual and RTL Support =

SureCookie includes multilingual compatibility for WPML and Polylang.

It can register and translate banner text, button labels, preference modal text, category labels, and related frontend strings. It also includes RTL support for cookie policy layouts.

= MCP and WordPress Abilities =

When enabled, AI assistants and compatible tools can use structured SureCookie abilities to manage settings, cookie categories, consent logs, cookie management, and site scanner actions. Scanner start actions still require care because they contact an external scanning service.

= Who Should Use SureCookie? =

SureCookie is a good fit for:

* WordPress site owners and agencies who need a cookie consent banner across client sites
* E-commerce stores and publishers using analytics, ads, pixels, embeds, or marketing tools
* Businesses running tag managers, heatmaps, video embeds, maps, forms, or CRM and conversion tracking
* Developers who want a WordPress-native consent workflow with hooks and APIs, and consent logs kept inside WordPress

= Important Legal Note =

SureCookie provides technical tools for cookie scanning, script blocking, consent collection, consent logging, and cookie policy management.

No WordPress plugin can guarantee legal compliance on its own. Privacy and cookie requirements depend on your website, visitors, region, policies, data processing practices, and legal obligations. For legal advice, consult a qualified legal professional.

== External Services ==

SureCookie uses external services for cookie scanning, site verification, and geolocation.

= Cookie Scanning =

SureCookie connects to [https://library.surecookie.com/](https://library.surecookie.com/) to provide real browser-based cookie scanning and smart categorization.

When you run a scan, SureCookie sends the selected page URLs to the scanning service. A browser-based scanner visits those pages and detects cookies, scripts, resources, and third-party domains.

= Scanner Registration and Authentication =

Before the first scan, SureCookie performs a one-time registration handshake with `library.surecookie.com/api/register`.

The request sends:

* Site URL
* WordPress admin email
* SureCookie version
* A temporary installation nonce (one-time, random)

The scanning service verifies the site through a temporary REST endpoint, then returns site-specific credentials and a verification token. These credentials are stored locally in a non-autoloaded WordPress option and are used for authenticated scan requests.

= Consent IP Logs and Region Detection =

For consent logging and country detection, visitor IP addresses may be processed through MaxMind-backed region detection through SureCookie's service. This helps SureCookie record the country in the consent log.

IP addresses are masked before being stored in your WordPress database.

MaxMind attribution: [https://www.maxmind.com](https://www.maxmind.com)

= New Version Check =

At most once a day, when someone who can update plugins opens a SureCookie admin screen, SureCookie asks `library.surecookie.com/api/plugin/live-versions` for the current SureCookie and SureCookie Pro version numbers, so the dashboard can tell you when yours is older. The request sends no site data beyond what WordPress includes in every outgoing request, such as your site address in its user agent.

= Service URLs =

* SureCookie scanning and geolocation service: [https://library.surecookie.com/](https://library.surecookie.com/)
* SureCookie privacy policy: [https://surecookie.com/privacy-policy/](https://surecookie.com/privacy-policy/)
* MaxMind: [https://www.maxmind.com](https://www.maxmind.com)

== Data Stored Locally ==

SureCookie stores plugin settings and consent data in your WordPress database.

This can include:

* Banner and preference modal settings
* Cookie categories
* Custom cookies
* Scanned cookies
* Scanned resources
* Scan history
* Consent logs
* Cookie policy page ID
* Automatic scan settings
* Scanner credentials

Consent logs and scan results can be viewed, exported, or deleted from the WordPress admin.

== Privacy and Data Processing ==

SureCookie processes data through its API service at `library.surecookie.com` for cookie scanning, scanner authentication, and region-aware consent logging. Here is what happens and why.

= What We Send to SureCookie Services =

During the one-time scanner registration and site verification flow, SureCookie sends the site URL, the WordPress administrator email, the SureCookie version, and a temporary installation nonce. The scanning service uses these to verify your site and issue credentials, then returns a verification token that SureCookie exposes at a temporary REST endpoint for domain verification.

After registration, scan requests use site-specific credentials stored locally in WordPress and do not resend the admin email.

When a scan runs, SureCookie sends selected page URLs to the scanning service so a real browser can detect cookies, scripts, resources, and third-party domains.

= What Is Processed and Why =

* Cookie scanning: Selected page URLs are scanned in a real browser environment to detect cookies, scripts, resources, and third-party domains.
* Cookie categorization: Detected cookie details, such as names, domains, and durations, are used to help categorize cookies.
* Region detection: Visitor IP addresses may be processed through MaxMind-backed region detection to determine country-level location for consent logs. IP addresses are masked before being stored in your WordPress database.
* Consent records: Consent choices, timestamps, category preferences, and session details are logged locally in your WordPress database.

= Where Data Lives =

* Consent logs, scan results, settings, and scanner credentials are stored in your WordPress database.
* Data sent to `library.surecookie.com` for scanning and geolocation may be temporarily processed for those features.
* SureCookie does not sell this data or use it for advertising.

= Data Retention and Control =

* Consent logs and scan results can be viewed, exported, or deleted from your WordPress admin.
* Consent log retention periods are configurable in plugin settings.
* Scanner credentials are stored locally in a non-autoloaded WordPress option.

= Security =

* API communication uses HTTPS.
* Scan requests use authenticated site credentials after the registration flow.
* Site credentials are used to sign later scan requests.

Because visitor data and selected page URLs can be processed through SureCookie services, you should mention this in your site's privacy policy where appropriate.

Full details: [https://surecookie.com/privacy-policy/](https://surecookie.com/privacy-policy/)

For questions about data processing, visit [https://surecookie.com/support/](https://surecookie.com/support/).

== Useful Links ==

* [SureCookie Website](https://surecookie.com/)
* [Documentation](https://surecookie.com/docs/)
* [Support Forum](https://wordpress.org/support/plugin/surecookie/)
* [Privacy Policy](https://surecookie.com/privacy-policy/)
* [Live Demo](https://app.zipwp.com/blueprint/surecookie-n9i)

== About Brainstorm Force ==

SureCookie is built by Brainstorm Force, the team behind Astra and other widely used WordPress products.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/surecookie` directory, or install the plugin through the WordPress Plugins screen.
2. Activate SureCookie from the Plugins screen.
3. Go to the SureCookie dashboard in WordPress.
4. Configure your banner content, cookie categories, and consent settings.
5. Select pages and run a cookie scan.
6. Review detected cookies and resources.
7. Enable script blocking if you want non-essential scripts blocked before consent.
8. Generate or connect your Cookie Policy page.
9. Test the banner and preference modal on the frontend.

== Frequently Asked Questions ==

= Is SureCookie a consent management platform? =

SureCookie provides consent management features inside WordPress, including a cookie banner, preference modal, cookie scanner, script blocking, consent logs, and cookie policy support. It is not a SaaS platform that charges by visitor count.

= Can SureCookie be used as a cookie blocker? =

Yes. When script blocking is enabled, SureCookie can act as a cookie blocker for non-essential scripts and embedded resources until the visitor gives consent for the related category.

= Does SureCookie help with cookie compliance? =

SureCookie helps with the technical parts of cookie compliance, such as scanning cookies, showing a consent banner, blocking non-essential scripts, storing consent logs, and maintaining a cookie policy page. It does not replace legal advice or guarantee compliance by itself.

= Is SureCookie just a cookie banner plugin? =

No. SureCookie includes a cookie banner, but it also includes cookie scanning, script blocking, consent logs, cookie policy page support, re-consent controls, Google Consent Mode, WP Consent API, and monthly automatic scanning in the free plugin.

= What features are included in the free plugin? =

The free plugin includes the banner, preference modal, real browser cookie scanner, custom cookies, script blocking, consent logs, consent log export, cookie policy page generation, re-consent button, re-request consent, monthly automatic scanning, scan history, Google Consent Mode, WP Consent API, multilingual support, and no visitor-based limits.

= How does the cookie scanner work? =

SureCookie sends selected page URLs to `library.surecookie.com`, where a browser-based scanner loads the pages and detects cookies, third-party domains, scripts, and resources. This helps detect cookies that may not appear in static HTML.

= Does SureCookie support automatic scanning? =

Yes. The free plugin includes monthly automatic scanning through WP-Cron. Weekly scanning, email digests, auto-apply, and compliance guard behavior are Pro extensions.

= Can SureCookie show what changed between scans? =

Yes. SureCookie records the latest scan history and can show newly detected cookies, removed cookies, recategorized cookies, and new third-party domains.

= Can SureCookie block scripts before consent? =

Yes. SureCookie can block non-essential scripts and embedded resources before consent is given. It can process scripts, iframes, embeds, and objects when blocking is enabled.

= Does SureCookie support Google Consent Mode? =

Yes. SureCookie includes Google Consent Mode support and can update Google consent signals based on visitor choices.

= Does SureCookie support WP Consent API? =

Yes. SureCookie can sync consent state with WP Consent API so compatible plugins can read consent through the standardized API.

= Can visitors change their consent choices later? =

Yes. Visitors can reopen the preferences modal through the settings button, a shortcode, or a configured menu item.

= What shortcode opens Cookie Preferences? =

You can use this shortcode:

`[surecookie_reconsent_button]`

It renders a Cookie Preferences button that opens the preferences modal.

= Can I re-request consent from all visitors? =

Yes. SureCookie includes a Re-request Consent option. When used, existing consent is treated as stale and visitors are asked to make a new choice.

= Are consent logs stored locally? =

Yes. Consent logs are stored in your WordPress database. Logs can include the action, preferences, timestamp, masked IP address, country, and session ID.

= Can I export consent logs? =

Yes. SureCookie supports consent log PDF export for individual records.

= Does SureCookie generate a cookie policy page? =

Yes. SureCookie can generate a WordPress page with editable policy content and a dynamic cookie table shortcode.

= Does SureCookie work with WooCommerce? =

Yes. SureCookie is designed for WordPress sites, including E-commerce stores. It can recognize common WooCommerce session cookies as essential during classification.

= Does SureCookie work with multilingual websites? =

Yes. SureCookie includes WPML and Polylang compatibility for banner text, preference modal text, category labels, and other frontend strings.

= Does SureCookie support RTL languages? =

Yes. SureCookie includes RTL styling support, including for the cookie policy page.

= Does SureCookie include AI assistant support? =

SureCookie includes MCP and WordPress Abilities integration for supported setups. When enabled, compatible AI assistants can interact with structured SureCookie management actions.

= Will SureCookie slow down my website? =

SureCookie is designed to stay lightweight on the frontend. Script blocking runs only where needed and skips admin, AJAX, REST, feeds, JSON, and XML responses.

= Are there visitor limits? =

No. SureCookie does not charge by visitors, traffic, or pageviews.

= Does SureCookie guarantee GDPR or CCPA compliance? =

No. SureCookie provides tools for cookie consent workflows, but no plugin can guarantee legal compliance by itself. Your compliance depends on your site, region, policies, and data practices. Please consult a legal professional for legal advice.

= What data is sent to external services? =

For cookie scanning, selected page URLs and site verification details are sent to `library.surecookie.com`. For country detection in consent logs, visitor IP addresses may be processed through the SureCookie service using MaxMind-backed data.

== Screenshots ==

1. SureCookie dashboard overview.
2. Cookie scan and detected cookies.
3. Cookie banner customization.
4. Preference modal customization.
5. Resource blocking settings.
6. Consent logs.
7. Cookie policy page.

== Upgrade Notice ==
= 1.4.0 =
Adds Data Requests, Import / Export and a wider AI assistant surface. Fixes several cases where blocking silently did nothing: very large pages, post types templated by another plugin, and CDN-served sites. Recommended for every site.

= 1.3.1 =
Cookies with the same name are no longer duplicated when scanned again. Scanned cookies now update existing entries instead of being added again.

= 1.3.0 =
Adds the Known Services library, browser-based scanning for sites where the scanner is blocked, the new Scripts and Embeds page, bulk cookie category changes, remembered manual categories, and duplicate cookie fixes.

= 1.2.0 =
Adds Re-request Consent, monthly automatic scanning foundation, scan history change detection, background overlay controls, consent log improvements, and better Google script handling.

= 1.1.0 =
Adds AI Assistants integration, HTML support in banner content fields, improved settings organization, multilingual improvements, and cookie policy timestamp support.

= 1.0.0 =
Initial public release of SureCookie.

== Changelog ==
= 1.7.0 - 20-October-2026 =
* New: The preferences window now includes links to your Cookie Policy, Privacy Policy, and Legal Notice. You can reorder or hide them under Banner > Content > Preference Modal.
* New: Added a Legal Notice (Imprint) page setting for countries that require one. Select the page alongside your other legal links in the preference modal settings.
* New: The preferences window now shows which services receive data and when it is transferred outside the EU/EEA, based on your scan results and services catalog.
* New: Added a support shortcut to the admin top bar, labels for its icons on hover, and an Enable Automatic Scanning button on the dashboard when scheduled scans are disabled.
* Improvement: The setup wizard now stays on the scan step to show results as they arrive. You can continue at any time while the scan runs in the background.
* Improvement: Scan results now refresh automatically when a scan finishes, even when the scanning log is closed.
* Improvement: The setup wizard now explains when a scan is blocked by your host's firewall or finds nothing, and offers a browser-based scan instead.
* Improvement: The setup wizard now provides a Cookie Preferences link for all themes, including block themes, which receive a shortcode to add it.
* Improvement: The setup wizard now selects your homepage by default when scanning, since it often contains the most tracking.
* Improvement: Licence activation errors now include clearer guidance instead of raw error messages.
* Improvement: Added Ukrainian translations for the banner, preferences window, and admin screens.
* Improvement: Business Details can now be saved without selecting a privacy law. Leave Applicable Privacy Regimes empty if none applies to your site.
* Improvement: Updated the Analytics category description to better explain what its cookies do.
* Improvement: Hardened the security of the plugin.
* Fix: The setup wizard now lets you connect your account when SureCookie Pro is active but has no licence key.
* Fix: If your browser blocks the account connection pop-up, you are now returned to your previous setup wizard step.
* Fix: The dashboard tour button now correctly describes its action instead of suggesting it opens the banner designer.
* Fix: The setup wizard now explains when scanning is paused because WP-Cron is disabled and what to ask your hosting provider.
* Fix: Consent records are now saved on servers that block URLs ending in `-log`. Site Health also warns when the original address is blocked.
* Fix: Deleting consent logs now works on servers that block URLs ending in `-log`.
* Fix: Shortcode and block features that depend on WordPress React, such as Powerful Docs search, now load correctly on affected sites.
* Fix: First-party session cookies, such as `PHPSESSID`, are now attributed to your own site in the cookie policy. Existing entries are corrected automatically.
* Fix: Geographic Rules now work correctly with page caching. Visitors receive the rules for their own region instead of another region's cached settings. Clear your cache once after updating.
* Fix: Consent Log selections now stay in sync with the displayed rows when changing pages or filters, preventing accidental deletion of hidden records.
* Fix: Changes made while settings are saving are no longer lost. The Save button remains available until all changes are stored.
* Fix: The Country list in Compliance > Business Details now includes all countries and territories, including the Cayman Islands, Bermuda, and Puerto Rico.
* Fix: Consent sharing on multisite networks now tracks sites by their identity rather than their address, so shared records remain removable after a subsite's address changes. Existing records are repaired automatically where possible.
* Fix: LearnDash lessons using Vimeo Video Progression now unlock the Mark Complete button after visitors accept cookies and finish watching. Added the `surecookie_scripts_restored` browser event for plugins that need to initialise scripts after they are restored.
* Fix: Accept All and Reject All buttons in the preferences window now retain their appearance on hover, even with themes and builders that apply their own button styles.
* Fix: Removed an extra scrollbar and unexpected page scrolling caused by a hidden screen-reader element.
* Fix: SureCookie and LiteSpeed Cache cookies are now correctly attributed to your own domain in the cookie policy. Existing entries are corrected automatically without rescanning.
* Fix: Consent records are now saved on sites where the WordPress Address and Site Address use different hosts, such as versions with and without `www`. Requests from other sites remain blocked.
* Fix: The Cookie Preferences menu item no longer triggers PHP deprecation notices on sites where themes or menu plugins inspect menu item details.
* Fix: The banner preview in the dashboard now uses the same button text color as the live banner, such as dark text on light brand colors like Astra's orange.
* Fix: The Cookie Preferences menu item and shortcode no longer show a link with no text when the saved button label is empty. They now use the default label.
* Fix: Blocked content placeholders now name the website they would connect to when a scan could not identify the provider, instead of reading "would connect to ." with the name missing.

= 1.6.1 - 7-October-2026 =
* Improvement: SureCart affiliates are now credited even if visitors browse a few pages before accepting cookies. The affiliate code is preserved on internal links until the tracker runs, without storing anything on the visitor’s device. Visitors who decline are still not credited.
* Fix: Some scanned cookies showed "null" as the provider. This is now either left blank or filled correctly from the services list, and existing entries are fixed automatically after update.
* Fix: On sites using optimization plugins like WP Rocket or Autoptimize, trackers served from your own site could run before consent. SureCookie now ensures these stay blocked until consent, while other optimizations continue as normal.
* Fix: When using delayed JavaScript execution, blocking one tracker could stop other scripts from running. Scripts now continue to load as expected, while blocked trackers still wait for consent.
* Fix: With W3 Total Cache Minify enabled, blocked scripts could be combined and run before consent. These are now excluded from combining so they load only after consent. If you use page caching, clear caches once after updating.
* Fix: SureCart affiliate tracking could run before consent and set cookies even after visitors declined. It now waits for Marketing consent and is correctly reflected in the cookie policy.
* Fix: Global Privacy Control is now fully respected by script blocking. Visitors sending this signal receive only essential cookies and scripts, regardless of previous choices.
* Fix: Declining or withdrawing consent now removes both scanned and manually added cookies for that category. Essential and allowed cookies are preserved.
* Fix: Pages visited during cookie scans could be cached with trackers unblocked. These pages are no longer cached, ensuring visitors always see the correct consent-controlled version. Clear your cache after updating.
* Fix: Duplicate cookies could appear in the cookie policy when added manually and detected by scan. Cookies are now merged into a single entry using your custom wording.
* Fix: Some scripts from known service domains could load before consent if not explicitly listed. Blocking now applies consistently across the entire domain, while still allowing exceptions where needed.
* Fix: With certain optimization settings, the cookie banner could lose styling or appear behind content. Styling is now preserved and displayed correctly.
* Fix: Translation issues in Dutch and other languages have been corrected, including table labels and scan timers.
* Fix: On sites using WP Rocket with Minify JavaScript on, trackers such as Plausible, Fathom, Simple Analytics and Cloudflare Web Analytics were copied to your own site by WP Rocket and ran when a visitor first interacted, before they had chosen. They now stay at their original address and wait for consent.

= 1.6.0 - 30-September-2026 =
* New: Banner > Content now lets you manage all four buttons in one place. You can reorder them, show or hide each one, and set custom labels. Empty labels fall back to translated defaults, so most sites do not need to change anything. Preferences and refusal options always remain visible.
* New: PixelYourSite now respects visitor consent. Its pixels, cookies, and tracking events are held until the visitor accepts the relevant category, then start on the same page. Google Consent Mode and Global Privacy Control are also respected.
* New: The dashboard now shows when a new version of SureCookie or SureCookie Pro is available, with a quick link to the updates screen. Visible only to users who can update plugins.
* New: Added a `surecookie_capability` filter to control who can manage SureCookie settings. Useful for agencies that want to restrict access. Default behavior remains unchanged.
* Improvement: The default banner message is now shorter and fits better on mobile screens, helping PageSpeed measure your site content instead of the banner. Custom messages are not affected.
* Improvement: Reduced unused configuration data loaded on each page, lowering page size while keeping cookie policy and preferences unchanged.
* Fix: Improved compatibility with W3 Total Cache so the banner and its scripts are not broken by file combining.
* Fix: Screen readers now announce banner buttons using their visible labels, improving accessibility across all languages.
* Fix: The preferences window now only shows Accept All where it is enabled in the banner, ensuring consent logs remain accurate.
* Fix: Button order and labels are now validated to prevent missing or duplicate buttons, and labels are limited to a safe length.
* Fix: Visitors sending Global Privacy Control could be treated as consenting on the server, allowing some plugins to run when they should not. The server now matches the browser and Google Consent Mode, so everything except essential cookies is denied regardless of consent model or any earlier choice.
* Fix: Re-requesting consent now correctly resets previous choices on both browser and server, preventing trackers from running before a new choice is made.
* Fix: Google reCAPTCHA and Google Fonts are now blocked until consent when using Google Consent Mode. You can still allow them manually if needed.
* Fix: HubSpot tracking is now correctly blocked for EU-hosted portals.
* Fix: Cookie policy now lists only services your site actually loads, avoiding incorrect entries.
* Fix: Google Consent Mode defaults are now consistent for all visitors, preventing cached pages from applying incorrect consent states.
* Fix: Placeholder content styling is now consistent across themes, while still allowing basic formatting.
* Fix: Microsoft Clarity now receives consent updates correctly, so analytics tracking and session recordings work as expected.
* Fix: EU and EEA geographic rules now apply correctly across all supported regions.
* Fix: Scripts that depend on each other now load in the correct order after consent, preventing broken functionality.

= 1.5.1 - 21-September-2026 =
- New: A surecookie_translate_string filter for translation plugins other than WPML and Polylang. It receives every string SureCookie renders on the server, along with the string's name and whether it holds formatting.
- Improvement: The services catalog now records which services set no cookies at all, starting with Fathom Analytics, Plausible, Simple Analytics, Umami and Cloudflare Web Analytics. With SureCookie Pro this stops Compliance Guard holding them for review. On its own it changes nothing, and those services are still blocked until consent under their category.
- Improvement: The Cookie Scanner now names the services it recognised, not just the number of cookies it saw. Services that only set cookies after consent, or that load inside a blocked embed, leave little for a scan to find, so a low cookie count used to read as a broken scan even though their cookies were already on your cookie policy. The notice also names any service you removed from the Known Services library, the one case a fresh scan cannot fix, and links you to it.
- Improvement: Site Health now warns when SureCookie is seeing your CDN or reverse proxy instead of your visitors. Left unnoticed, geographic rules apply the wrong country, consent records store it, and per-visitor rate limits collapse into one shared bucket. The check names which case you have and what to change, and stays quiet when visitors are identified correctly.
- Fix: On themes and page builders that size a video or map with CSS rather than width and height attributes, such as the Bricks theme, a blocked embed showed a small consent box floating in a large empty area instead of filling the space the embed would have taken. The prompt now covers the whole embed, as it already did elsewhere. Only the appearance before consent changes: blocking, accepting and loading the embed afterwards all worked already.
- Fix: On WPML and Polylang sites, text in the preferences window and on blocked-content placeholders lost its links, bold and paragraphs, while the same text kept them on single-language sites. Both now keep their formatting through translation.
- Fix: A service you stopped using kept publishing its cookies to your cookie policy forever, with no way to clear them, because those rows are not cookies a scan found and so had no Delete button. Each now offers "Remove service", which clears every cookie that service declares. If the service is still used somewhere, it is detected again and its cookies return, so your policy cannot end up saying too little. Cookies a scan actually observed are never touched.
- Fix: Scripts and Embeds could report that a resource always loads while every visitor was being refused it, because SureCookie Pro's Compliance Guard was holding that address for review. A held row now reads "Held for review" and says it will not load until you release it. An "Always allowed" rule naming a held address now releases it, which previously worked only if a scan had found the resource. Worth knowing before you update: if you already have such a rule, the resource it names starts loading as soon as you update. That is the rule finally being honoured, but it is a visible change, so check your Scripts and Embeds list afterwards if you are unsure what you allowed.
- Fix: Services loaded through an embed, such as YouTube and Vimeo, were never marked as detected in the Known Services library, because their entries are recognised by an address that includes a path while results were matched on the bare domain. They are now detected correctly.
- Fix: A site with an active SureCookie Pro licence could be limited to free scan allowances for up to a week. The licence was sent to our servers without checking whether it arrived, so a lost or rejected message was recorded as a success. SureCookie now waits for confirmation and retries on its own.
- Fix: Ad blockers with cookie-notice filtering, which most visitors run, broke two things. The cookie policy tables lost all styling, because the stylesheet's file name matched a filter rule; and the preferences window and floating privacy button disappeared along with the banner they sat inside, leaving visitors no way to change their choices while script blocking kept running. The stylesheet has been renamed, and preferences and the floating button now sit outside the banner. If you copied our styles into your theme or filtered the stylesheet out to work around this, that keeps working.
- Fix: On a phone, the Confirm My Choices button in the preferences window was sliced in half in most languages, because all three buttons were held on one line and translated labels are wider than the English ones. They now wrap onto a second line whenever they do not fit.
- Fix: Sites that keep WordPress in its own folder, or run a setup like Bedrock, could not connect to the cookie scanner: SureCookie sent our servers where WordPress's files live rather than where the site is served, so the ownership check looked in the wrong place. Both addresses are now sent. Nothing changes where the two are the same, which is most sites.
- Fix: On Google Analytics 4 sites, the cookie policy left the Purpose column empty for your property's own analytics cookie, directly below a near-identical row that had one. It now reads the same as the rest. Anything you typed yourself, or that a scan found, still takes precedence.
- Fix: The floating cookie banner could collapse to a sliver on your site while the settings screen showed a normal width, if the width field had ever been left empty and saved as zero. The width is now kept within the range the field offers, an already-saved zero no longer reaches visitors, and the field settles on its stored value when you click away.

= 1.5.0 - 09-September-2026 =
- New: A new "Compliance" section gathers your legal pages and business details in one place. Cookie Policy moves here from Tracking Manager (old links still work), Tracking Manager's "Cookies" group is now a single "All Cookies" item, and Consent Logs and Data Requests sit below Settings.
- New: A privacy policy generator under Compliance > Legal Pages builds a draft from your cookie and consent settings, including disclosures only SureCookie can make about what a consent record holds. Nothing is published for you, and the screen tracks what is still left to review. The generated page stays current because each section is a shortcode that follows your settings, so you can also reorder sections or drop them into a policy you wrote yourself. ( https://surecookie.com/docs/generating-a-privacy-policy-page/ )
- New: "Business Details" holds your legal entity name, address, country, privacy contact and the privacy laws your site is subject to. Your privacy policy, cookie policy and Business Details shortcodes all read from it, and a settings export now carries it to another site. ( https://surecookie.com/docs/setting-up-your-business-details/ )
- New: Shortcodes such as [surecookie_company_name] and [surecookie_contact_email] put your business details anywhere shortcodes work, including page builders. The full list with copy buttons is on the Business Details screen. Anything you have not filled in shows as a marker to administrators only, never to visitors.
- New: SureCookie contributes suggested text to WordPress's own Privacy Policy Guide under Tools > Privacy, and can be set as your site's privacy policy page. Setting it swaps the /privacy-policy/ address over, and SureCookie asks first because that changes a public URL.
- New: "Compliance Check" is the first screen under Compliance. It introduces the upcoming checker, which will load your live site and report what fires before a visitor consents, and lets you connect your site so the check can run.
- New: You can now correct a scanned cookie's purpose, duration and provider, not just its category. Open it from SureCookie > All Cookies and edit. Your wording survives later scans; clear a field to go back to what the scanner reported.
- New: A scanned cookie can now be deleted. Previously a cookie from a plugin or service you had removed stayed on your cookie policy with no way to clear it. If it is still being set, it reappears on the next scan.
- New: Consent records can be kept with no IP address at all, for sites that would rather hold no personal identifier. Add the surecookie_skip_consent_logs_ips filter and records keep the date, categories and session, but no IP or country is stored, and no address is sent to work out a country. Off by default. If you also use Pro's Geographic Rules for selected regions, a country is still resolved on page views so the right rules apply, and your privacy policy says so. ( https://surecookie.com/docs/recording-consent-without-storing-visitor-ip-addresses/ )
- New: Geographic rules can now target individual US states, so a rule can apply to California for CCPA rather than to the whole country. Consent records and the PDF certificate show the state as well. Geographic rules need SureCookie Pro.
- Fix: Geographic rules had no effect on sites behind a CDN or load balancer. SureCookie read the address of the CDN rather than the visitor, so every visitor resolved as "location unknown" and the fallback rule was applied to the whole site: the banner appeared in countries you had excluded, and script blocking ran for those visitors too. The visitor's real address is now used whenever the request genuinely arrives from a recognised proxy, and a request that reaches your server directly still cannot claim an address of its own.
- Fix: The first automatic scan treated everything it found as new, so with Compliance Guard on (the default) it blocked every third-party resource at once, including support widgets and payment scripts, in a state no visitor's consent could release. A first scan now records what your site already loads and blocks nothing.
- Fix: Turning the cookie banner off also turned off "Block Scripts Until Consent", and turning the banner back on did not restore it, so the banner returned while nothing was being blocked. Toggling the banner now leaves that setting alone. If you have ever switched your banner off and on, please check SureCookie > Tracking Manager and re-enable "Block Scripts Until Consent" if it is off.
- Fix: An "Always allowed" rule could allow far more than you chose, or nothing at all. It applied to every service on the same web address, so allowing Google reCAPTCHA also allowed Google Ads and Google Maps before consent; a broader scanner entry could override a specific address; and an address typed the way it really looks was ignored by the browser-side guard. Rules now apply only to the service you picked, recognise the common ways of writing an address, and bind tightly to the folder and port you name. Name the service's address rather than one exact file, so the rule also covers scripts a page builder or form plugin adds after the page has loaded. Rules you saved earlier keep working as they did until you next save that row.
- Fix: Blocked resources could still load before consent. A blocked host is now held back whether the page loads it as a script, an iframe, an embed or an object, which closes gaps for Google Maps drawn with the JavaScript API, the YouTube and Vimeo players and Tag Manager's no-JavaScript fallback. A blocked Presto Player video is also no longer present as markup until you accept, so another plugin can no longer unwrap it and let it reach YouTube.
- Fix: Google Fonts loaded before consent on every site, even with the service listed as blocked. Web fonts and stylesheets arrive on a different kind of tag than scripts and embeds, and that tag was never held back, so the visitor's IP address still reached Google. Stylesheets and fonts are now held until consent, along with the preload and prefetch hints that fetch them early.
- Fix: The WP Consent API bridge never ran, on any site, for the whole life of the feature. Other plugins that read consent through that standard were told nothing until a visitor clicked the banner, and the opt-out side had never run at all. It now runs on every request. A follow-on fix stops the four "Cannot modify header information" warnings this produced in your log on each WP-Cron run.
- Fix: Front-end page builders such as Beaver Builder, Bricks, Divi, Elementor and WPBakery are editable again. The banner no longer takes over the Escape and Tab keys for the whole page, and script blocking pauses inside the builder's own editing screen. It still applies everywhere else, including for logged-in editors browsing the site normally, so what you see matches what visitors get.
- Fix: Your cookie policy could list internal name patterns such as `_ga_<container-id>` as though they were cookies your site sets. They are no longer listed, and existing ones clear on your next scan.
- Fix: The Purpose and Duration columns on your cookie policy were wrong. Purpose showed a dash for every scanned cookie, session cookies showed a dash instead of "Session", the duration counted down over time so an older cookie could read 0, and a duration that came from our service catalog could be lost on the next scan. All four now read correctly.
- Fix: The cookie banner could vanish entirely on sites using an HTML optimizer that strips empty elements, taking the blocked-content placeholders and the whole consent mechanism with it, silently. The banner now creates its own container when the one SureCookie prints is missing.
- Fix: Banner button text is now always readable. It was fixed to white, which fell below the WCAG minimum contrast on the four dark palettes and on light custom colors. Text color is now chosen from the button's own background across the banner, the preference modal and blocked-content placeholders.
- Fix: Sites using a regional language variant (Swiss or Austrian German, Belgian French, Mexican Spanish and similar) now get the bundled translation of their base language instead of English. A real regional translation still takes priority.
- Fix: The Cookie Scanner said "Resets in 24 hours" in English, and said it even when your daily scan limit had already reset, which read as a fresh lockout. It now shows a real countdown in your language, and nothing at all once the limit has reset.
- Fix: One unusable value no longer breaks a whole screen or a whole save. An incomplete cookie, category or blocking rule could stop the Cookie Manager or Cookie Scanner loading, break the cookie policy for every visitor, hide your saved Google Consent Mode regional rules, or make a rule match nothing while the tracker it named kept loading. A settings save or import with one bad value now completes, tells you which settings were skipped, and the Scripts and Embeds list shows the rules the blocker really enforces.
- Fix: Scan requests and messages are now accurate. Starting a scan with an invalid page list quietly scanned your previous selection and used part of your allowance, and most failures blamed a slow page whatever had really happened. Invalid requests are refused, messages match the real cause, and they say when retrying will not help.

= 1.4.0 - 24-August-2026 =
- New: A "Data Requests" screen where visitor GDPR and CCPA requests arrive and are tracked against their response deadline. Answering them is a SureCookie Pro feature; the free screen explains the workflow. ( https://surecookie.com/docs/handling-data-requests/ )
- New: Import / Export for settings, cookie categories and custom cookies, so you can copy a configuration between sites or keep a backup. ( https://surecookie.com/docs/importing-and-exporting-surecookie-settings/ )
- New: AI assistants now reach most of the plugin: a diagnostic summary of your setup, blocked scripts and embeds, the Known Services library, and the pages and menus your settings point to. ( https://surecookie.com/docs/surecookie-mcp-abilities/ )
- New: Settings > Advanced > Blocked Content UI, for customizing the placeholder shown in place of blocked content. ( https://surecookie.com/docs/customizing-the-blocked-content-ui/ )
- New: "Hide Unused Categories" under Manage Categories hides categories that have nothing on your site from the visitor preferences modal. Off by default.
- New: Date filter on consent logs.
- New: "Astra Global Palette" for banner colors, matching the Astra theme palette.
- New: Ask an AI assistant to recategorize cookies in bulk, or to report your remaining scan allowance before it starts a scan.
- New: Verify your domain with a DNS TXT record, for sites where a firewall or security plugin blocks our scanner. Allowlisting the scanner still works too. ( https://surecookie.com/docs/verifying-your-domain-with-a-dns-record/ )
- Improvement: Every settings, Tracking Manager and Learn screen now links to its own documentation from the page header.
- Improvement: Settings offered to an AI assistant now describe their allowed values and flag the ones that change what visitors see before they consent.
- Improvement: Trackers that a plugin or theme injects with JavaScript after page load are now held behind consent, showing the usual "Accept & Load" placeholder.
- Improvement: Script and embed matching is no longer case-sensitive.
- Improvement: The "Managed by Google Consent Mode" list now notes that Consent Mode does not cover the non-Google tags you deploy through Tag Manager.
- Improvement: Presto Player videos and embeds now follow the category set on the Scripts and Embeds page.
- Improvement: Resources blocked by the built-in service list now appear on the Scripts and Embeds page, so you can change their category.
- Improvement: The blocked-content message now says the content would connect to the service, instead of saying it requires cookies.
- Compatibility: YouTube videos added with Elementor's Video widget are now held behind consent, and play in place once accepted.
- Compatibility: Fixed the banner not appearing when a caching plugin combines JavaScript, most often LiteSpeed Cache.
- Compatibility: Improved cached CSS and JS handling with WP Rocket, W3 Total Cache, LiteSpeed Cache and Flying Press.
- Compatibility: Fixed the admin dashboard shrinking when MemberPress Courses is active.
- Fix: The Google Consent Mode screen now describes the rule actually in force, and warns you when a category is granted before the visitor answers the banner.
- Fix: Blocking did nothing on sites using a performance plugin that inlines scripts as data: URIs, such as Perfmatters. Those scripts are now blocked like any other.
- Fix: Sites using Google Consent Mode found almost no cookies when scanning. A scan now sees your site the way a visitor who has accepted would.
- Fix: An AI assistant could not read or change any setting.
- Fix: Banner and preferences modal descriptions now normalize non-breaking spaces and wrap long unbreakable content without overflowing.
- Fix: Asking an AI assistant to scan a specific list of pages scanned the site's default pages instead.
- Fix: Changing a setting through an AI assistant no longer leaves caching plugins serving the old banner.
- Fix: Consent log filters offered to an AI assistant were missing several actions and columns.
- Fix: Blocking could silently stop on a page carrying one very large inline script, such as a page builder's template data. Those pages are now rewritten correctly, and the limit is written to the scan log if it is ever reached.
- Fix: On sites serving files through a CDN, blocking could hold back WordPress core and theme files instead of a tracker. Core files now always load, browser import maps and speculation rules are never held back, and asset-delivery CDNs are treated as Essential: Bunny, Kinsta, NitroPack, Optimole, StackPath, ImageKit, EWWW Easy IO, RocketCDN, CDN77, Statically, Jetpack Site Accelerator and BootstrapCDN.
- Fix: Blocking and the consent banner did nothing on post types whose templates come from another plugin. Both now work whichever plugin renders the template.
- Fix: A category set on a script or embed was ignored when the match was made on the script's contents rather than its address.
- Fix: "Do not block" was hidden whenever SureCookie Pro was active. There is now one option, on every site, that behaves the same with or without Pro.
- Fix: "Accept & Load" did nothing on blocked videos in pages cached before this release. Those placeholders are now repaired in the browser.
- Fix: The built-in service list stopped at 200 services, silently dropping later blocking patterns. The limit is raised and truncation is recorded in the scan log.
- Fix: The Known Services confirmation, the Scripts and Embeds notice and the Detected and Managed Resources table each described blocking incorrectly.
- Fix: When domain verification failed, SureCookie guessed at the reason and often guessed wrong. It now reports the reason the scanning service actually gave.

= 1.3.1 - 03-August-2026 =
- Fix: Prevented duplicate cookies by ensuring scanned cookies with the same name are update existing entries instead of being added again.

= 1.3.0 - 03-August-2026 =
- New: Introducing "Known Services", a library of popular third-party services (Google Analytics, Meta Pixel, YouTube, Stripe, Hotjar and more) that you can add in one click. Adding a service declares its cookies in your cookie list and cookie policy, and blocks its scripts and embeds until consent, without waiting for a scan to find them. The free plan includes 5 services; SureCookie Pro unlocks the full automated library of 150+ services. ( https://surecookie.com/docs/using-known-services/ )
- New: Introducing Assisted Scanner mechanism i.e. "Scan from Your Browser", a fallback scan for sites where the hosting firewall blocks SureCookie Scanner agent. ( https://surecookie.com/docs/scanning-your-site-from-your-browser/ )
- New: "Resource Blocking" has been rebuilt as the "Scripts and Embeds" page, a single list of every script and embed found on your site plus any you add yourself.
- New: You can now choose the consent state Google Consent Mode starts from before a visitor answers the banner. A "Worldwide" rule sets the baseline for Functional, Analytics and Marketing / Ads storage, and the regional rules below it override that baseline for the countries you list, so a region such as the EU can stay denied while other regions start from your own setting. Everything stays denied until you change it, so existing sites are unaffected.
- New: Under Scripts and Embeds, you can now add your own scripts and embeds to block, even if they are not detected by a scan. This is useful for scripts that only run on certain pages or under certain conditions, such as tag managers, marketing pixels, or custom embeds.
- New: You can now change the category of several cookies at once. Select them on the All Cookies page and move them together, with a reminder of what a category change means for visitor consent.
- New: Deleting a cookie category now asks where its cookies should go, with a "Move Cookies To" picker instead of always sending them to Uncategorized.
- Improvement: The built-in service catalog has grown to 150+ services with reviewed categories, so services such as Stripe, Cloudflare Turnstile, hCaptcha and Google Sign-In are treated as Essential or Functional instead of falling into Marketing. The catalog also refreshes on its daily schedule as intended.
- Improvement: The admin has been reorganized. "Cookie Manager" is now "Tracking Manager" and holds Scanning, Known Services, Cookies (All Cookies and Cookie Policy), Scripts and Embeds, Geographic Rules and Consent Sharing in one place.
- Improvement: Reduced the plugin's footprint on every page load. The scan log, active scan state, connection details and notice state are no longer loaded on requests that do not need them, and the scan log is capped at 500 lines so sites running automatic scans no longer grow it without limit.
- Improvement: Hardened the security of the plugin.
- Improvement: The review request now waits until your site has logged 30 visitor consents, instead of appearing after your first cookie scan, so it only shows up once SureCookie has demonstrably done its job. It also steps aside when another SureCookie notice is already on screen, and once you rate the plugin or choose "I already did" it stays away for every administrator on the site rather than just the one who answered.
- Compatibility: If you use SureCookie Pro, update it to 1.1.0 or later alongside this release. Geographic Rules and Consent Sharing now live under Tracking Manager, and older Pro versions still register those pages at their previous location.
- Fix: Consent log PDF exports breaks in right-to-left languages such as Arabic and Hebrew.
- Fix: A cookie category you assign by hand is now remembered. Previously the next scan re-sorted that cookie using the scanner's own classification and your change was lost, including when a cookie stopped being detected and came back later.
- Fix: Security cookies and scripts found by a scan, such as captcha, bot protection and CSRF tokens, were filed as Marketing or left Uncategorized. They are now treated as Essential, so a visitor who declines marketing no longer breaks logins, forms and captchas on your site. Run a scan to re-sort anything already detected.
- Fix: Google Consent Mode sent no consent signals at all on sites that load Google Tag Manager from their own domain or through server-side tagging. SureCookie looked for Google's own script URLs to decide whether to act, and those setups have none, so neither the consent defaults nor the visitor's later choice ever reached Google. With Google Consent Mode turned on, the consent defaults are now always set at the top of every page before your tags run, no matter how those tags are loaded, and the visitor's choice is sent to Google as soon as they answer the banner.
- Fix: The Provider and Duration columns showed "-" for every scanned cookie. Both now fill in, including on cookies already stored, so no rescan is needed.
- Fix: Blocked embeds added to the page later, lazy loaded or loaded over AJAX, showed an empty box with no "Accept & Load" button.
- Fix: The Google Consent Mode conflict warning for Site Kit never appeared in the WordPress admin.
- Fix: Consent submissions returned an error when consent logging was turned off, even though the visitor's choice was saved correctly.
- Fix: Screen readers announced every blocked embed on a page with the same "Accept & Load" label; each button now names the service it will load.
- Fix: Removed the "Replace data on next scan" toggle. It had no effect, as every scan already replaces the detected resource list.
- Fix: Deactivating or uninstalling the plugin now clears the scheduled tasks and cached service data added in this release.
- Tweak: Scan results now tell you what actually happened. Separate notices cover a scan blocked by your host, a scan that reached the site but found nothing, a scan taking longer than usual, and a scan that finished only part of its pages. A retry adds to what was already found instead of replacing it, and the completion notice now points at both places results land, the Cookies page and the Scripts and Embeds page.
- Tweak: The Consent Logs table header now wraps on narrow screens instead of overflowing.
- Tweak: Scans could sit at "Queued..." forever on hosts where WordPress cron does not run, even though the scan had already finished. Opening the Scanning screen now refreshes the status directly.
- Tweak: When a hosting firewall blocked our scanner, the Scanning screen simply reported 0 cookies with no explanation. It now says the host blocked the scan, links to the allowlisting guide, and lists anything it could still detect. An invalid, expired or self-signed SSL certificate is now reported as a certificate problem rather than a firewall problem.

= 1.2.4 - 21-July-2026 =
- Improvement: The banner button order setting now displays a notice, with a shortcut to your Geographic Targeting rules, explaining that visitors matched by a region-specific rule see that region's button order instead of the global one.
- Improvement: The admin "What's New" panel no longer loads its font from Google's servers, removing an external request while keeping its appearance unchanged.
- Compatibility: SureCookie now blocks Presto Player video and audio embeds (YouTube, Vimeo, Bunny.net, self-hosted, and audio) until the visitor consents, showing an "Accept & Load" placeholder and restoring the player in place once the matching cookie category is accepted.
- Fix: Licensed users no longer see a misleading "5 pages per scan" limit before their site connects with SureCookie; the cookie scanner now prompts them to run their first scan to unlock their plan's full scan limits.
- Fix: SureCookie Pro users can now activate their license during onboarding, so their premium plan and higher scan limits are detected correctly instead of the site being set up on the free tier.
- Fix: Re-consent entry points (the shortcode button and menu item) now open the cookie preferences even when the consent banner is turned off site-wide, instead of appearing as unresponsive controls.
- Fix: The SureCookie review request notice in the WordPress admin now displays its logo icon at the correct, compact size instead of appearing oversized.
- Developer note: Custom post types can now be included in the site scanner and Cookie Policy pickers (and the automatic "All Published Content" scan scope) via the `surecookie_searchable_post_types` filter, with picker results grouped by content type. ( https://surecookie.com/docs/including-custom-post-types-in-scanning-and-the-cookie-policy-picker/ )

= 1.2.3 - 14-July-2026 =
- Improvement: Added notification dot for unread consent logs in admin menu.
- Improvement: The consent banner stylesheet now loads without blocking page render (served asynchronously), taking it off the critical rendering path and improving FCP / LCP / Lighthouse scores.
- Improvement: The bundled Figtree fonts are now served as woff2 (about 60% smaller) and use font-display: swap, so banner text paints immediately in a fallback font and the fonts no longer sit on the critical path.
- Fix: Accessibility issue with focus outlines for buttons and links in the banner interface.
- Fix: Script blocking now works even if the remote list fails, using a built-in baseline of common third-party scripts.
- Compatibility: SureCookie now activates cleanly on SQLite-based WordPress installs by skipping MySQL-only steps, fixing prior index and column errors.

= 1.2.2 - 10-July-2026 =
- Fix: Enabling Resource Blocking could break the page layout on WordPress 7.0 sites using classic themes with block-based content - block and global styles were pushed into the page body instead of the header, inverting the CSS cascade and collapsing multi-column sections. Resource Blocking no longer interferes with WordPress 7.0's on-demand block-style loading.
- Fix: Blocked video embeds (Vimeo, YouTube, and other WordPress responsive embeds) no longer leave a large empty gap around the "content is blocked" placeholder. The placeholder now overlays the embed's reserved aspect-ratio space instead of adding its own height on top of it.

For older releases, see the full SureCookie changelog [here](https://surecookie.com/changelog/).
