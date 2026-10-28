import PageContentWrapper from '@AdminComponents/page-content-wrapper';
import { __ } from '@wordpress/i18n';
import withSuspense from '@AdminComponents/hoc/with-suspense';
import GeneratePageContent from '@Functions/page-content-generator';
import { createLazyRoute } from '@tanstack/react-router';
import { getSurerankUtmUrl } from '@/global/utils/utm';
import { Text } from '@bsf/force-ui';

export const PAGE_CONTENT = [
	{
		container: {
			id: 'miscellaneous-container',
			direction: 'column',
			gap: 6,
		},
		content: [
			{
				container: null,
				content: [
					{
						type: 'switch',
						id: 'surerank_usage_optin',
						storeKey: 'surerank_usage_optin',
						dataType: 'boolean',
						label: __( 'Contribute to SureRank', 'surerank' ),
						description: (
							<span>
								<span>
									{ __(
										'Help shape the future of SureRank. Share how you use the plugin so we can build features that matter, fix issues faster, and make smarter decisions.',
										'surerank'
									) }
								</span>{ ' ' }
								<a
									href={ getSurerankUtmUrl(
										'https://surerank.com/share-usage-data/',
										'advanced_tools',
										'learn_more_usage_data'
									) }
									target="_blank"
									rel="noopener noreferrer"
									className="no-underline hover:no-underline ring-0"
								>
									<Text
										as="span"
										color="link"
										className="inline underline-offset-2 hover:underline"
									>
										{ __( 'Learn More', 'surerank' ) }
									</Text>
								</a>
							</span>
						),
					},
					{
						type: 'switch',
						id: 'enable_headless_rest_api',
						storeKey: 'enable_headless_rest_api',
						dataType: 'boolean',
						label: __( 'Headless REST API', 'surerank' ),
						description: __(
							'Expose SureRank SEO metadata and schema for a public URL through the surerank/v1/get_head REST endpoint, for use with headless front-ends.',
							'surerank'
						),
					},
					{
						type: 'switch',
						id: 'surerank_delete_on_uninstall',
						storeKey: 'surerank_delete_on_uninstall',
						dataType: 'boolean',
						label: __(
							'Delete plugin data on uninstall',
							'surerank'
						),
						description: __(
							'When enabled, deleting SureRank from the Plugins screen will permanently remove all of its settings, schema, sitemap data, post and term meta, custom tables, and scheduled tasks. Disabled by default so your data is preserved across reinstalls.',
							'surerank'
						),
					},
				],
			},
		],
	},
];

const Miscellaneous = () => {
	return (
		<PageContentWrapper
			title={ __( 'Miscellaneous', 'surerank' ) }
			description={ __(
				'Additional settings and preferences for SureRank.',
				'surerank'
			) }
		>
			<GeneratePageContent json={ PAGE_CONTENT } />
		</PageContentWrapper>
	);
};

export const LazyRoute = createLazyRoute( '/tools/miscellaneous' )( {
	component: withSuspense( Miscellaneous ),
} );

export default withSuspense( Miscellaneous );
