import { useEffect, useMemo } from '@wordpress/element';
import { Button, Container, Text, Title } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { Check, ExternalLink, TriangleAlert } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { WelcomeImage } from '@assets/icons';
import { Divider } from './components';
import { useOnboardingState } from './onboarding-state';
import { setOnboardingCompletionStatus } from '@api/onboarding';
import { activateContentGuard } from '@api/settings';
import { fetchSettings } from '@api/connections';
import { getSureContactStatus } from '@api/surecontact';

const SURECONTACT_KEY = 'SURECONTACT';

const features = [
	[
		__( '100 Free:', 'suremails' ),
		__( 'You will get 100 emails per month.', 'suremails' ),
	],
	[
		__( 'Easy tracking:', 'suremails' ),
		__( 'See every email you send in one place', 'suremails' ),
	],
	[
		__( 'Peace of mind:', 'suremails' ),
		__(
			'If sending fails, SureMail will automatically retry',
			'suremails'
		),
	],
];

const Done = () => {
	const navigate = useNavigate();
	const [
		{ sureContact = {}, connection, connectionSaved, hasSkippedStep },
	] = useOnboardingState();

	const selectedProvider = connection || connectionSaved;
	const isSureContact = selectedProvider === SURECONTACT_KEY;

	const { data: settings } = useQuery( {
		queryKey: [ 'settings' ],
		queryFn: fetchSettings,
		select: ( data ) => data?.data || {},
		refetchOnMount: 'always',
		refetchOnWindowFocus: false,
		enabled: isSureContact,
	} );

	const sureContactConnection = useMemo( () => {
		const connections = settings?.connections
			? Object.values( settings.connections )
			: [];
		return connections.find( ( c ) => c?.type === SURECONTACT_KEY );
	}, [ settings ] );

	// Authoritative check — only show the banner if the connection exists and
	// its `email_verified` flag is false. Mirrors SureContactVerifyBanner.
	const { data: liveStatus } = useQuery( {
		queryKey: [ 'surecontact-status', sureContactConnection?.id ],
		queryFn: () => getSureContactStatus( sureContactConnection.id ),
		enabled:
			isSureContact &&
			!! sureContactConnection?.id &&
			! sureContactConnection?.email_verified,
		refetchOnWindowFocus: false,
		retry: false,
	} );

	const emailVerified =
		liveStatus?.email_verified ?? sureContactConnection?.email_verified;
	const showVerifyBanner =
		isSureContact &&
		!! sureContactConnection?.id &&
		emailVerified === false;
	const verifyEmail =
		sureContactConnection?.from_email ||
		sureContact?.email ||
		suremails?.currentUser?.email ||
		'';

	useEffect( () => {
		if ( ! window?.suremails?.onboardingCompleted ) {
			setOnboardingCompletionStatus( {
				skipped: !! hasSkippedStep,
			} ).catch( () => {} );
		}

		if (
			isSureContact &&
			window?.suremails?.contentGuardActiveStatus !== 'yes'
		) {
			activateContentGuard( true )
				.then( () => {
					if ( window.suremails ) {
						window.suremails.contentGuardActiveStatus = 'yes';
						window.suremails.contentGuardPopupStatus = false;
					}
				} )
				.catch( () => {} );
		}
	}, [] );

	const handleGoToDashboard = () => {
		navigate( '/dashboard' );
	};

	return (
		<div className="space-y-6">
			<Container gap="sm" align="center" className="h-auto">
				<div className="space-y-2 max-w-[24rem]">
					<Title
						tag="h3"
						title={ __( "You're Good to Go! 🚀", 'suremails' ) }
						size="lg"
					/>
					<Text size={ 14 } weight={ 400 } color="secondary">
						{ __(
							"You've successfully set up SMTP, and your site is ready to send emails without a hitch! Now you can focus on your business and let us handle the rest.",
							'suremails'
						) }
					</Text>
				</div>
				<WelcomeImage className="w-full h-full max-w-32 mx-auto" />
			</Container>

			<div className="space-y-2">
				<Text size={ 14 } weight={ 600 } color="primary">
					{ __( 'Here is what free plan offer', 'suremails' ) }
				</Text>
				{ features.map( ( feature, index ) => (
					<Container
						key={ index }
						className="flex items-center gap-1.5"
					>
						<Check
							className="size-4 text-icon-interactive"
							strokeWidth={ 1.75 }
						/>
						<Text size={ 14 } weight={ 400 } color="label">
							<Text as="b" weight={ 500 }>
								{ feature[ 0 ] }{ ' ' }
							</Text>
							{ feature[ 1 ] }
						</Text>
					</Container>
				) ) }
			</div>

			{ showVerifyBanner && (
				<div className="flex items-start gap-3 rounded-lg border border-solid border-alert-border-warning bg-alert-background-warning p-4">
					<TriangleAlert
						className="size-5 text-support-warning shrink-0 mt-0.5"
						strokeWidth={ 1.75 }
					/>
					<div className="space-y-1">
						<Text as="p" size={ 13 } weight={ 600 } color="primary">
							{ __(
								"Don't forget to verify your email.",
								'suremails'
							) }
						</Text>
						<Text as="p" size={ 13 } color="primary">
							{ __( 'Check your inbox at', 'suremails' ) }{ ' ' }
							<Text as="span" size={ 13 } weight={ 600 }>
								{ verifyEmail }
							</Text>{ ' ' }
							{ __(
								"click the link to unlock 100 monthly sends. Until then you're limited to 3/day.",
								'suremails'
							) }
						</Text>
					</div>
				</div>
			) }

			<Divider />

			<Container align="start" className="h-auto gap-3">
				<Button onClick={ handleGoToDashboard }>
					{ __( 'Start Sending Mails', 'suremails' ) }
				</Button>
				<Button
					variant="ghost"
					icon={ <ExternalLink /> }
					iconPosition="right"
					onClick={ handleGoToDashboard }
				>
					{ __( 'Go to Dashboard', 'suremails' ) }
				</Button>
			</Container>
		</div>
	);
};

export default Done;
