import { useState } from '@wordpress/element';
import { Button } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { ArrowRight, X } from 'lucide-react';
import Title from '@components/title/title';
import { SureContactIcon } from '@assets/icons';
import { fetchSettings } from '@api/connections';
import { fetchInstalledPluginsData } from '@api/plugins';
import { dismissSureContactPromo } from '@api/surecontact';

const SURECONTACT_KEY = 'SURECONTACT';
const SURECONTACT_PLUGIN_SLUG = 'surecontact';

/**
 * Dashboard-only cross-sell banner. Once a SureContact SMTP connection exists we
 * nudge the user toward the full SureContact plugin (marketing, forms, landing
 * pages, automations, contact lists).
 *
 * Visibility rules:
 *   - Only when a SureContact connection is present.
 *   - Hidden once the SureContact plugin is installed — no point cross-selling.
 *   - Dismissible for 15 days; the seed flag comes from the server and the timer
 *     resets via the disable-surecontact-promo endpoint (mirrors the admin
 *     configuration-notice pattern).
 */
const SureContactPromoBanner = () => {
	const [ dismissed, setDismissed ] = useState(
		Boolean( window?.suremails?.surecontactPromoDismissed )
	);

	const { data: settings, isFetched: settingsFetched } = useQuery( {
		queryKey: [ 'settings' ],
		queryFn: fetchSettings,
		select: ( data ) => data?.data || {},
		refetchOnMount: false,
		refetchOnWindowFocus: false,
	} );

	const { data: pluginsData, isFetched: pluginsFetched } = useQuery( {
		queryKey: [ 'installed-plugins' ],
		queryFn: fetchInstalledPluginsData,
		refetchOnMount: false,
		refetchOnWindowFocus: false,
	} );

	const handleDismiss = () => {
		setDismissed( true );
		dismissSureContactPromo();
	};

	// Wait for both queries to settle before deciding. Otherwise the banner
	// renders the moment the cached settings arrive (hasConnection = true) and
	// then vanishes once the installed-plugins query resolves — a visible flicker.
	if ( ! settingsFetched || ! pluginsFetched ) {
		return null;
	}

	const hasConnection = settings?.connections
		? Object.values( settings.connections ).some(
				( c ) => c?.type === SURECONTACT_KEY
		  )
		: false;

	const pluginInstalled = Boolean(
		pluginsData?.installed?.includes( SURECONTACT_PLUGIN_SLUG )
	);

	if ( dismissed || ! hasConnection || pluginInstalled ) {
		return null;
	}

	const pluginUrl = window?.suremails?.surecontactPluginUrl || '';

	return (
		<div className="w-full bg-background-secondary px-8 pt-8">
			<div className="flex items-center justify-between gap-4 flex-wrap p-4 border-0.5 border-solid rounded-xl shadow-sm bg-background-primary border-border-subtle">
				<div className="flex items-center gap-3">
					<SureContactIcon className="size-10 shrink-0" />
					<Title
						title={ __(
							'SureContact SMTP connected',
							'suremails'
						) }
						description={ __(
							'Use SureContact to also run email marketing, forms, landing pages, automations, and contact lists.',
							'suremails'
						) }
						tag="h3"
						size="xs"
					/>
				</div>
				<div className="flex items-center gap-1 shrink-0">
					<Button
						tag="a"
						href={ pluginUrl || undefined }
						target="_blank"
						rel="noopener noreferrer"
						variant="primary"
						size="sm"
						disabled={ ! pluginUrl }
						icon={ <ArrowRight className="size-3" /> }
						iconPosition="right"
						className="no-underline"
					>
						{ __( 'Explore SureContact', 'suremails' ) }
					</Button>
					<Button
						className="p-0.5 before:hidden"
						size="sm"
						variant="ghost"
						icon={ <X /> }
						onClick={ handleDismiss }
						aria-label={ __( 'Dismiss', 'suremails' ) }
						type="button"
					/>
				</div>
			</div>
		</div>
	);
};

export default SureContactPromoBanner;
