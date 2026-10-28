import PageContentWrapper from '@AdminComponents/page-content-wrapper';
import { __ } from '@wordpress/i18n';
import withSuspense from '@AdminComponents/hoc/with-suspense';
import GeneratePageContent from '@Functions/page-content-generator';

export const PAGE_CONTENT = [
	{
		container: {
			id: 'integrations-container',
			direction: 'column',
			gap: 6,
		},
		content: [
			{
				container: null,
				content: [
					{
						type: 'switch',
						id: 'enable_woocommerce_integration',
						storeKey: 'enable_woocommerce_integration',
						dataType: 'boolean',
						label: __( 'WooCommerce', 'surerank' ),
						description: __(
							'Enable SureRank support for WooCommerce store content, including product SEO fields, product schema, and metadata used across product and shop pages. Turn this off only if you do not want SureRank to load WooCommerce-specific SEO compatibility.',
							'surerank'
						),
					},
				],
			},
			{
				container: null,
				content: [
					{
						type: 'switch',
						id: 'enable_fluentcart_integration',
						storeKey: 'enable_fluentcart_integration',
						dataType: 'boolean',
						label: __( 'FluentCart', 'surerank' ),
						description: __(
							'Enable SureRank support for FluentCart store content, including product schema and Open Graph metadata used across product pages. Turn this off only if you do not want SureRank to load FluentCart-specific SEO compatibility.',
							'surerank'
						),
					},
				],
			},
			{
				container: null,
				content: [
					{
						type: 'switch',
						id: 'enable_angie_integration',
						storeKey: 'enable_angie_integration',
						dataType: 'boolean',
						label: __( 'Angie', 'surerank' ),
						description: __(
							'Enable SureRank compatibility with Angie so Angie can access supported SEO actions and data where both plugins integrate. Turn this off if you want to prevent Angie from using SureRank-specific integration points.',
							'surerank'
						),
					},
				],
			},
		],
	},
];

const Integrations = () => {
	return (
		<PageContentWrapper
			title={ __( 'Integrations', 'surerank' ) }
			description={ __(
				'Manage compatibility with supported third-party tools.',
				'surerank'
			) }
		>
			<GeneratePageContent json={ PAGE_CONTENT } />
		</PageContentWrapper>
	);
};

export default withSuspense( Integrations );
