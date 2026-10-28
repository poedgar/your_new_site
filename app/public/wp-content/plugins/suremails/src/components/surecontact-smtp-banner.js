import { useState } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import { Button } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { ArrowRight, X } from 'lucide-react';
import Title from '@components/title/title';
import { SureContactIcon } from '@assets/icons';
import { fetchSettings } from '@api/connections';
import { dismissSureContactSmtpPromo } from '@api/surecontact';

const SURECONTACT_KEY = 'SURECONTACT';

/**
 * Launch banner that nudges existing users — those without a SureContact SMTP
 * connection — to try the newly released provider in one click.
 *
 * Visibility rules:
 *   - Only when NO SureContact SMTP connection exists yet.
 *   - Dismissible for 15 days; the seed flag comes from the server and the timer
 *     resets via the disable-surecontact-smtp-promo endpoint (mirrors the
 *     cross-sell promo pattern).
 *
 * Clicking "Set Up Now" opens the connection drawer with SureContact
 * pre-selected via React Router location state.
 */
const SureContactSmtpBanner = () => {
	const navigate = useNavigate();
	const [ dismissed, setDismissed ] = useState(
		Boolean( window?.suremails?.surecontactSmtpPromoDismissed )
	);

	const { data: settings, isFetched: settingsFetched } = useQuery( {
		queryKey: [ 'settings' ],
		queryFn: fetchSettings,
		select: ( data ) => data?.data || {},
		refetchOnMount: false,
		refetchOnWindowFocus: false,
	} );

	const hasConnection = settings?.connections
		? Object.values( settings.connections ).some(
				( c ) => c?.type === SURECONTACT_KEY
		  )
		: false;

	const handleDismiss = () => {
		setDismissed( true );
		dismissSureContactSmtpPromo();
	};

	const handleSetup = () => {
		navigate( '/connections', {
			state: { openDrawer: true, selectedProvider: SURECONTACT_KEY },
		} );
	};

	// Wait for settings to settle before deciding. Otherwise the banner renders
	// before the cached connections arrive and then vanishes once a SureContact
	// connection is found — a visible flicker.
	if ( ! settingsFetched ) {
		return null;
	}

	if ( dismissed || hasConnection ) {
		return null;
	}

	return (
		<div className="w-full bg-background-secondary px-8 pt-8">
			<div className="flex items-center justify-between gap-4 flex-wrap p-4 border-0.5 border-solid rounded-xl shadow-sm bg-background-primary border-border-subtle">
				<div className="flex items-center gap-3">
					<SureContactIcon className="size-10 shrink-0" />
					<Title
						title={ __( 'SureContact SMTP is live', 'suremails' ) }
						description={ __(
							'From the team behind SureMails. Your first 100 emails are on us.',
							'suremails'
						) }
						tag="h3"
						size="xs"
					/>
				</div>
				<div className="flex items-center gap-1 shrink-0">
					<Button
						variant="primary"
						size="sm"
						onClick={ handleSetup }
						icon={ <ArrowRight className="size-3" /> }
						iconPosition="right"
						type="button"
					>
						{ __( 'Set Up Now', 'suremails' ) }
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

export default SureContactSmtpBanner;
