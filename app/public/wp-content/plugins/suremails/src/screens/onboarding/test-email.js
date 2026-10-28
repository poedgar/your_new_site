import { useState, useMemo } from '@wordpress/element';
import { Button, Input, Label, Text } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { ChevronLeft, Lightbulb, Loader2 } from 'lucide-react';
import { useOnboardingNavigation } from './hooks';
import { useOnboardingState } from './onboarding-state';
import { Header, Divider } from './components';
import { fetchSettings, sendTestEmail } from '@api/connections';

const SURECONTACT_KEY = 'SURECONTACT';

const TestEmail = () => {
	const [ { sureContact = {} }, setState ] = useOnboardingState();
	const { navigateToNextRoute, navigateToPreviousRoute } =
		useOnboardingNavigation();

	const { data: settings } = useQuery( {
		queryKey: [ 'settings' ],
		queryFn: fetchSettings,
		select: ( data ) => data?.data || {},
		refetchOnMount: 'always',
		refetchOnWindowFocus: false,
	} );

	const sureContactConnection = useMemo( () => {
		const connections = settings?.connections
			? Object.values( settings.connections )
			: [];
		return connections.find(
			( connection ) => connection?.type === SURECONTACT_KEY
		);
	}, [ settings ] );

	const adminEmail =
		sureContact.email ||
		sureContactConnection?.from_email ||
		suremails?.userEmail ||
		'';

	const [ toEmail, setToEmail ] = useState( adminEmail );
	const [ isSending, setIsSending ] = useState( false );
	const [ emailError, setEmailError ] = useState( '' );
	const [ statusMessage, setStatusMessage ] = useState( '' );

	const handleSendTest = async () => {
		if ( ! toEmail.trim() ) {
			setEmailError( __( 'Email is required', 'suremails' ) );
			return;
		}
		if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( toEmail ) ) {
			setEmailError( __( 'Enter a valid email', 'suremails' ) );
			return;
		}
		if ( ! sureContactConnection?.id ) {
			setStatusMessage(
				__(
					'No SureContact connection found yet — wait a moment and try again.',
					'suremails'
				)
			);
			return;
		}

		setEmailError( '' );
		setStatusMessage( '' );
		setIsSending( true );

		try {
			const response = await sendTestEmail( {
				from_email: sureContactConnection.from_email,
				to_email: toEmail,
				type: SURECONTACT_KEY,
				id: sureContactConnection.id,
				is_html: true,
			} );

			if ( response?.success ) {
				navigateToNextRoute();
				return;
			}
			setStatusMessage(
				response?.message ||
					__(
						'Send failed. Check your SureContact connection and try again.',
						'suremails'
					)
			);
		} catch ( error ) {
			setStatusMessage(
				error?.message ||
					__(
						'Send failed. Check your SureContact connection and try again.',
						'suremails'
					)
			);
		} finally {
			setIsSending( false );
		}
	};

	const handleSkip = () => {
		setState( ( prev ) => ( {
			...prev,
			hasSkippedStep: true,
		} ) );
		navigateToNextRoute();
	};

	return (
		<form
			className="space-y-6"
			onSubmit={ ( event ) => {
				event.preventDefault();
				handleSendTest();
			} }
		>
			<Header
				title={ __( 'Send a test email', 'suremails' ) }
				description={ __(
					'Confirm your WordPress site is sending through SureContact correctly.',
					'suremails'
				) }
			/>

			<div className="space-y-1.5">
				<Label htmlFor="test-to-email">
					{ __( 'Send to', 'suremails' ) }
				</Label>
				<Input
					id="test-to-email"
					name="to_email"
					size="md"
					type="email"
					value={ toEmail }
					onChange={ ( val ) => {
						setToEmail( val );
						if ( emailError ) {
							setEmailError( '' );
						}
					} }
					error={ emailError }
					disabled={ isSending }
				/>
				<Text as="p" size={ 12 } color="tertiary">
					{ __(
						'Sends through your new SureContact connection.',
						'suremails'
					) }
				</Text>
			</div>

			<div className="flex items-start gap-3 rounded-lg border border-solid border-alert-border-info bg-alert-background-info p-4">
				<Lightbulb
					className="size-5 text-support-info shrink-0 mt-0.5"
					strokeWidth={ 1.75 }
				/>
				<Text as="p" size={ 13 } color="primary">
					<Text as="span" size={ 13 } weight={ 600 }>
						{ __( 'Tip', 'suremails' ) }
					</Text>
					{ __(
						': Check that the email lands in the inbox, not spam. If it goes to spam, see our deliverability guide.',
						'suremails'
					) }
				</Text>
			</div>

			{ statusMessage && (
				<Text as="p" size={ 13 } color="error">
					{ statusMessage }
				</Text>
			) }

			<Divider />

			<div className="flex items-center justify-between gap-3">
				<Button
					variant="outline"
					icon={ <ChevronLeft /> }
					onClick={ navigateToPreviousRoute }
					type="button"
				>
					{ __( 'Back', 'suremails' ) }
				</Button>
				<div className="flex items-center gap-3">
					<Button
						variant="ghost"
						onClick={ handleSkip }
						type="button"
						disabled={ isSending }
					>
						{ __( 'Skip', 'suremails' ) }
					</Button>
					<Button
						icon={
							isSending ? (
								<Loader2 className="animate-spin" />
							) : null
						}
						onClick={ handleSendTest }
						disabled={ isSending }
						type="button"
					>
						{ isSending
							? __( 'Sending…', 'suremails' )
							: __( 'Send Test Email', 'suremails' ) }
					</Button>
				</div>
			</div>
		</form>
	);
};

export default TestEmail;
