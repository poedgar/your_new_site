import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import {
	ENABLE_GOOGLE_CONSOLE,
	ENABLE_SCHEMAS,
	ENABLE_MIGRATION,
} from '@Global/constants';

/**
 * Step IDs are validated server-side. Keep this in sync with
 * Learn::get_allowed_steps() in inc/api/learn.php.
 *
 * cta.type:
 *   'route'        navigates internally via TanStack router
 *   'edit-screen'  hard navigates to wp-admin (uses surerank_globals.wp_dashboard_url + target)
 */
const allChapters = () => [
	{
		id: 'getting_started',
		title: __( 'Get Started', 'surerank' ),
		description: __(
			'Set up the essentials so SureRank is fully configured from day one.',
			'surerank'
		),
		steps: [
			ENABLE_GOOGLE_CONSOLE && {
				id: 'connect_gsc',
				title: __( 'Connect Google Search Console', 'surerank' ),
				description: __(
					'Link your GSC account so SureRank can pull real clicks, impressions, and ranking data into your dashboard.',
					'surerank'
				),
				learnMoreUrl:
					'https://surerank.com/docs/search-console-surerank/',
				cta: {
					label: __( 'Connect GSC', 'surerank' ),
					type: 'route',
					target: '/search-console',
				},
				autoDetect: 'gsc',
			},
			{
				id: 'title_templates',
				title: __(
					'Set your global title & description templates',
					'surerank'
				),
				description: __(
					'Create title and description templates using dynamic tags. SureRank applies them automatically across all your posts, pages, and archives.',
					'surerank'
				),
				learnMoreUrl: 'https://surerank.com/docs/general-settings/',
				cta: {
					label: __( 'Configure Templates', 'surerank' ),
					type: 'route',
					target: '/general',
				},
			},
			( () => {
				const isStaticPage =
					window?.surerank_globals?.home_page_static === 'page';
				const frontPageId = Number(
					window?.surerank_globals?.page_on_front || 0
				);
				const useEditScreen = isStaticPage && frontPageId > 0;
				return {
					id: 'homepage_seo',
					title: __( 'Configure your homepage SEO', 'surerank' ),
					description: __(
						"Set a custom title and meta description for your homepage. It's your most important page and needs its own SEO settings.",
						'surerank'
					),
					learnMoreUrl: 'https://surerank.com/docs/general-settings/',
					cta: useEditScreen
						? {
								label: __( 'Set Homepage SEO', 'surerank' ),
								type: 'edit-screen',
								target: `post.php?post=${ frontPageId }&action=edit&surerank_open=true`,
						  }
						: {
								label: __( 'Set Homepage SEO', 'surerank' ),
								type: 'route',
								target: '/general/homepage',
						  },
				};
			} )(),
			ENABLE_MIGRATION &&
				Object.keys(
					window?.surerank_admin_common?.plugins_for_migration || {}
				).length > 0 && {
					id: 'migrate',
					title: __( 'Migrate from your old SEO plugin', 'surerank' ),
					description: __(
						'Coming from Yoast, Rank Math, or AIOSEO? Import all your existing titles, descriptions, and settings into SureRank in one click.',
						'surerank'
					),
					learnMoreUrl:
						'https://surerank.com/docs-category/migration-to-surerank/',
					cta: {
						label: __( 'Start Migration', 'surerank' ),
						type: 'route',
						target: '/tools/migrate',
					},
					autoDetect: 'migration',
				},
		].filter( Boolean ),
	},
	{
		id: 'find_you',
		title: __( 'Help Google Find You', 'surerank' ),
		description: __(
			'Make sure search engines can discover, crawl, and index all your content.',
			'surerank'
		),
		steps: [
			{
				id: 'xml_sitemap',
				title: __(
					'Configure and enable your XML sitemap',
					'surerank'
				),
				description: __(
					'Choose which post types and pages to include, then submit your sitemap to Google Search Console so every page gets discovered.',
					'surerank'
				),
				learnMoreUrl: 'https://surerank.com/docs/sitemaps/',
				cta: {
					label: __( 'Configure Sitemap', 'surerank' ),
					type: 'route',
					target: '/general/sitemaps',
				},
				autoDetect: 'sitemap',
				autoDetectedCta: {
					label: __( 'Open Sitemap', 'surerank' ),
					type: 'external',
					target: window?.surerank_admin_common?.sitemap_url || '',
				},
			},
			{
				id: 'robots_instructions',
				title: __(
					'Set robots instructions for your site',
					'surerank'
				),
				description: __(
					"Control which pages search engines can index. Set noindex on pages like thank-you pages, login pages, and tag archives that don't belong in search results.",
					'surerank'
				),
				learnMoreUrl:
					'https://surerank.com/docs/surerank-robots-txt-file/',
				cta: {
					label: __( 'Configure Robots', 'surerank' ),
					type: 'route',
					target: '/advanced/robot_instructions',
				},
			},
			{
				id: 'canonicals',
				title: __(
					'Set canonical URLs to avoid duplicate content',
					'surerank'
				),
				description: __(
					'Tell search engines which version of a page is the official one. This prevents duplicate content penalties when the same content is reachable at multiple URLs.',
					'surerank'
				),
				learnMoreUrl: 'https://surerank.com/docs/editing-meta-box/',
				cta: {
					label: __( 'Configure Canonicals', 'surerank' ),
					type: 'route',
					target: '/advanced/robots-txt-editor',
				},
			},
			{
				id: 'site_seo_issues',
				title: __( 'Fix your site SEO issues', 'surerank' ),
				description: __(
					"Run SureRank's site-wide health check to see everything rated Poor or Satisfactory, then fix the issues that are holding your site back.",
					'surerank'
				),
				learnMoreUrl:
					'https://surerank.com/docs-category/seo-analysis/',
				cta: {
					label: __( 'View SEO Analysis', 'surerank' ),
					type: 'route',
					target: '/site-seo-analysis',
				},
				autoDetect: 'site_seo_run',
			},
		],
	},
	{
		id: 'optimize',
		title: __( 'Optimize Your Pages', 'surerank' ),
		description: __(
			'Use the SureRank metabox to make every post and page work harder in search results.',
			'surerank'
		),
		steps: [
			{
				id: 'page_meta',
				title: __(
					'Set title & meta description for a page',
					'surerank'
				),
				description: __(
					"Open the SureRank metabox right from the posts or pages listing, or from inside the editor, to write a custom SEO title and meta description and preview exactly how it'll look in Google before publishing.",
					'surerank'
				),
				learnMoreUrl: 'https://surerank.com/docs/editing-meta-box/',
				cta: {
					label: __( 'View Pages', 'surerank' ),
					type: 'edit-screen',
					target: 'edit.php?post_type=page',
				},
			},
			{
				id: 'page_seo_check',
				title: __( 'Run your page-level SEO check', 'surerank' ),
				description: __(
					'See a real-time checklist of on-page SEO factors like keyword usage, heading structure, internal links, and image alt text, and fix what’s flagged.',
					'surerank'
				),
				learnMoreUrl:
					'https://surerank.com/docs/surerank-page-seo-checks/',
				cta: {
					label: __( 'Open Page Analysis', 'surerank' ),
					type: 'edit-screen',
					target: 'edit.php?post_type=post',
				},
			},
			ENABLE_SCHEMAS && {
				id: 'schema',
				title: __( 'Add schema markup to your content', 'surerank' ),
				description: __(
					'Pick the right schema type for each page (Article, FAQ, HowTo, Product) to help Google understand your content and unlock rich results in search.',
					'surerank'
				),
				learnMoreUrl: 'https://surerank.com/docs/editing-meta-box/',
				cta: {
					label: __( 'Add Schema', 'surerank' ),
					type: 'route',
					target: '/advanced/schema',
				},
				autoDetect: 'schemas',
			},
			{
				id: 'image_alt',
				title: __( 'Optimize your images with alt text', 'surerank' ),
				description: __(
					'SureRank automatically generates alt text for your images, improving how search engines understand your content and making your site more accessible.',
					'surerank'
				),
				learnMoreUrl: 'https://surerank.com/docs/image-seo-surerank/',
				cta: {
					label: __( 'Configure Image SEO', 'surerank' ),
					type: 'route',
					target: '/advanced/image-seo',
				},
			},
		].filter( Boolean ),
	},
	{
		id: 'social',
		title: __( 'Social Appearance', 'surerank' ),
		description: __(
			'Control exactly how your content looks when shared on Facebook, LinkedIn, and X.',
			'surerank'
		),
		steps: [
			{
				id: 'og_fallback',
				title: __( 'Set your fallback Open Graph image', 'surerank' ),
				description: __(
					'Choose the default image that appears whenever any page on your site is shared on social. Used when a page has no featured image of its own.',
					'surerank'
				),
				learnMoreUrl: 'https://surerank.com/docs/general-settings/',
				cta: {
					label: __( 'Configure OG Image', 'surerank' ),
					type: 'route',
					target: '/general/social',
				},
				autoDetect: 'fallback_image',
			},
			{
				id: 'facebook_og',
				title: __( 'Add your Facebook profile links', 'surerank' ),
				description: __(
					"Enter your site's and author's Facebook page URLs. SureRank adds them to your structured data so search engines link your brand to its verified social profile.",
					'surerank'
				),
				learnMoreUrl: 'https://surerank.com/docs/general-settings/',
				cta: {
					label: __( 'Configure Facebook', 'surerank' ),
					type: 'route',
					target: '/general/social/facebook',
				},
			},
			{
				id: 'x_cards',
				title: __( 'Set up X (Twitter) cards', 'surerank' ),
				description: __(
					'Add your X username and choose your card type so your content displays as a rich image preview, not just a plain link, when shared on X.',
					'surerank'
				),
				learnMoreUrl: 'https://surerank.com/docs/general-settings/',
				cta: {
					label: __( 'Configure X Cards', 'surerank' ),
					type: 'route',
					target: '/general/social/x',
				},
				autoDetect: 'x_cards',
			},
			{
				id: 'override_per_page',
				title: __(
					'Override social settings for a specific page',
					'surerank'
				),
				description: __(
					'Open the SureRank metabox right from the posts or pages listing, or from inside the editor, then use the Social tab to set a different image, title, or description for that page when it’s shared.',
					'surerank'
				),
				learnMoreUrl: 'https://surerank.com/docs/editing-meta-box/',
				cta: {
					label: __( 'View Pages', 'surerank' ),
					type: 'edit-screen',
					target: 'edit.php?post_type=page',
				},
			},
		],
	},
];

/**
 * Pro feature chapter.
 *
 * These steps are presentational (`pro: true`): they are never marked complete,
 * never count toward progress, and never POST to the REST endpoint, so they are
 * intentionally NOT mirrored in Learn::get_allowed_steps() in inc/api/learn.php.
 *
 * Steps are locked by default: no docs link, and the card renders a default
 * Upgrade to Pro CTA that routes to the pricing page. The Pro plugin hooks the
 * `surerank-pro.learn-chapter` filter to unlock them and attach a `proCta`
 * that opens the relevant feature page when active + licensed.
 */
const proChapter = () => ( {
	id: 'pro',
	isPro: true,
	title: __( 'Do More with SureRank Premium', 'surerank' ),
	description: __(
		'Advanced SEO tools that take your site further with SureRank Pro.',
		'surerank'
	),
	steps: [
		{
			id: 'pro_redirection',
			pro: true,
			locked: true,
			title: __( 'Redirect old URLs without losing SEO', 'surerank' ),
			description: __(
				'Set up 301/302 redirects, auto-redirect deleted posts, and import your existing rules from Yoast or Rank Math.',
				'surerank'
			),
			learnMoreUrl:
				'https://surerank.com/docs/managing-url-redirection-in-surerank/',
		},
		{
			id: 'pro_broken_links',
			pro: true,
			locked: true,
			title: __( 'Catch and fix broken links automatically', 'surerank' ),
			description: __(
				'Scan every internal and external link on your site and get alerted the moment one breaks.',
				'surerank'
			),
			learnMoreUrl:
				'https://surerank.com/docs/page-level-seo-broken-links-detected/',
		},
		{
			id: 'pro_link_suggestions',
			pro: true,
			locked: true,
			title: __( 'Get AI-powered internal link suggestions', 'surerank' ),
			description: __(
				'SureRank reads your content and suggests the most relevant internal links as you write.',
				'surerank'
			),
			learnMoreUrl:
				'https://surerank.com/docs/surerank-pro-link-suggestions/',
		},
		// Schema Pro steps share the FREE `enable_schemas` flag: when schema is
		// disabled the whole engine is off, so hide these for everyone (free and
		// licensed) — same visibility rule as the free schema card above. All
		// enrichment (Learn More, action CTA, auto-detect) is added Pro-side.
		ENABLE_SCHEMAS && {
			id: 'pro_advanced_schema',
			pro: true,
			locked: true,
			title: __( 'Unlock 15+ advanced schema types', 'surerank' ),
			description: __(
				'Add Recipe, Event, FAQ, HowTo, Course, Video, and more rich-result schema to the right pages.',
				'surerank'
			),
			learnMoreUrl: 'https://surerank.com/docs-category/schema/',
		},
		ENABLE_SCHEMAS && {
			id: 'pro_custom_schema',
			pro: true,
			locked: true,
			title: __( 'Build custom schema for any content', 'surerank' ),
			description: __(
				'Map your own fields to any schema type for full control over how Google reads your pages.',
				'surerank'
			),
			learnMoreUrl:
				'https://surerank.com/docs/custom-json-ld-schema-in-surerank/',
		},
		{
			id: 'pro_meta_generation',
			pro: true,
			locked: true,
			title: __(
				'Generate meta titles and descriptions with AI',
				'surerank'
			),
			description: __(
				'Bulk-create optimized titles and descriptions across all your posts and pages in one pass.',
				'surerank'
			),
			learnMoreUrl: 'https://surerank.com/docs/editing-meta-box/',
		},
		{
			id: 'pro_bulk_alt_text',
			pro: true,
			locked: true,
			title: __( 'Auto-generate image alt text in bulk', 'surerank' ),
			description: __(
				'Let AI write descriptive alt text across your entire media library to boost image SEO and accessibility.',
				'surerank'
			),
			learnMoreUrl: 'https://surerank.com/docs/image-seo-surerank/',
		},
		{
			id: 'pro_instant_indexing',
			pro: true,
			locked: true,
			title: __( 'Get indexed instantly', 'surerank' ),
			description: __(
				'Push new and updated URLs to Google and Bing the moment you publish via the Indexing and IndexNow APIs.',
				'surerank'
			),
			learnMoreUrl:
				'https://surerank.com/docs/instant-indexing-in-surerank-pro/',
		},
		{
			id: 'pro_advanced_sitemaps',
			pro: true,
			locked: true,
			title: __( 'Add Video and News sitemaps', 'surerank' ),
			description: __(
				'Help Google surface your videos and news content with dedicated, auto-generated sitemaps.',
				'surerank'
			),
			learnMoreUrl: 'https://surerank.com/docs/sitemaps/',
		},
	].filter( Boolean ),
} );

export const getLearnChapters = () => {
	const chapters = allChapters().filter( ( ch ) => ch.steps.length > 0 );
	// Always show the Pro chapter. The free plugin renders it as a locked list
	// (no Learn More, no auto-detect) whose cards carry an Upgrade to Pro CTA
	// pointing at the pricing page. The Pro plugin hooks
	// `surerank-pro.learn-chapter` to unlock the cards and attach Learn More +
	// feature-page action CTAs when the site is active + licensed.
	chapters.push( applyFilters( 'surerank-pro.learn-chapter', proChapter() ) );
	return chapters;
};
