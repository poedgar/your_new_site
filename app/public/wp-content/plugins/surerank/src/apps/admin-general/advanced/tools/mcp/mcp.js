import PageContentWrapper from '@AdminComponents/page-content-wrapper';
import { __, sprintf } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import withSuspense from '@AdminComponents/hoc/with-suspense';
import GeneratePageContent from '@Functions/page-content-generator';
import { createLazyRoute } from '@tanstack/react-router';
import { STORE_NAME } from '@AdminStore/constants';
import { Alert, Button } from '@bsf/force-ui';
import McpConnectionConfig from './mcp-connection-config';

export const PAGE_CONTENT = [
	{
		container: {
			id: 'mcp-container',
			direction: 'column',
			gap: 6,
		},
		content: [
			{
				container: null,
				content: [
					{
						type: 'switch',
						id: 'enable_mcp',
						storeKey: 'enable_mcp',
						dataType: 'boolean',
						label: __( 'Enable MCP', 'surerank' ),
						description: __(
							'Allow AI clients like Claude, ChatGPT, and Cursor to read and update your SEO settings, metadata, sitemap, and robots.txt through SureRank. When disabled, no abilities are registered and AI clients cannot connect.',
							'surerank'
						),
					},
				],
			},
		],
	},
];

// Warning shown when MCP is enabled but the MCP Adapter plugin is not active.
const AdapterMissingAlert = () => {
	const globals =
		( typeof window !== 'undefined' && window.surerank_globals ) || {};
	const downloadUrl =
		globals.mcp_adapter_download_url ||
		'https://github.com/WordPress/mcp-adapter/releases';

	return (
		<Alert
			variant="warning"
			design="stack"
			title={ __( 'MCP Adapter plugin not detected', 'surerank' ) }
			content={
				<>
					{ sprintf(
						// translators: %s: WP MCP Adapter plugin name.
						__(
							'To connect AI clients to your site, the %s plugin is required. Download the latest release from GitHub, upload it via Plugins > Add New > Upload Plugin, and activate.',
							'surerank'
						),
						'WP MCP Adapter'
					) }
					<div className="mt-2.5">
						<Button
							className="[&>span]:text-xs py-0.5"
							variant="primary"
							size="xs"
							onClick={ () =>
								window.open(
									downloadUrl,
									'_blank',
									'noopener,noreferrer'
								)
							}
						>
							{ __( 'Download from GitHub', 'surerank' ) }
						</Button>
					</div>
				</>
			}
		/>
	);
};

// Warning shown when an adapter is active but it refused SureRank's server.
const ServerMissingAlert = ( { message } ) => {
	const summary = __(
		'An MCP adapter is active, but SureRank could not register its own server, so the SureRank MCP endpoint will not respond.',
		'surerank'
	);

	return (
		<Alert
			variant="warning"
			design="stack"
			title={ __( 'SureRank MCP server is not registered', 'surerank' ) }
			content={
				message
					? sprintf(
							/* translators: 1: what went wrong, 2: the reason reported by the MCP adapter. */
							__( '%1$s The adapter reported: %2$s', 'surerank' ),
							summary,
							message
					  )
					: summary
			}
		/>
	);
};

const Mcp = () => {
	// Read the live toggle value (saved + unsaved) so the connect guide only
	// appears while MCP is enabled.
	const isEnabled = useSelect( ( select ) => {
		const { getMetaSettings, getUnsavedSettings } = select( STORE_NAME );
		const merged = { ...getMetaSettings(), ...getUnsavedSettings() };
		return !! merged.enable_mcp;
	}, [] );

	const globals =
		( typeof window !== 'undefined' && window.surerank_globals ) || {};
	const isAdapterActive = !! globals.mcp_adapter_installed;

	// Only a REST request reaches the adapter, so the registration outcome has to
	// be asked for rather than read off the page.
	const [ serverStatus, setServerStatus ] = useState( null );

	useEffect( () => {
		if ( ! isAdapterActive || ! isEnabled ) {
			return;
		}

		let ignore = false;

		apiFetch( { path: '/surerank/v1/mcp-status' } )
			.then( ( result ) => {
				if ( ! ignore ) {
					setServerStatus( result );
				}
			} )
			.catch( () => {
				// A status check that never answered says nothing about the
				// server, so stay quiet rather than warn on a guess.
				if ( ! ignore ) {
					setServerStatus( null );
				}
			} );

		return () => {
			ignore = true;
		};
	}, [ isAdapterActive, isEnabled ] );

	const isServerMissing =
		!! serverStatus &&
		serverStatus.registration_expected &&
		! serverStatus.server_registered;

	return (
		<PageContentWrapper
			title={ __( 'MCP', 'surerank' ) }
			description={ __(
				'Configure AI client access to SureRank through the Model Context Protocol.',
				'surerank'
			) }
		>
			<div className="flex flex-col gap-6">
				<GeneratePageContent json={ PAGE_CONTENT } />
				{ isEnabled && (
					<div className="p-6 bg-white shadow-sm rounded-xl flex flex-col gap-6">
						{ ! isAdapterActive && <AdapterMissingAlert /> }
						{ isServerMissing && (
							<ServerMissingAlert
								message={ serverStatus.message }
							/>
						) }
						<McpConnectionConfig />
					</div>
				) }
			</div>
		</PageContentWrapper>
	);
};

export const LazyRoute = createLazyRoute( '/tools/mcp' )( {
	component: withSuspense( Mcp ),
} );

export default withSuspense( Mcp );
