import { Container, Label, Text, Button, Badge } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useNavigate } from '@tanstack/react-router';
import { ArrowRight } from 'lucide-react';
import { getAuth } from '@Functions/api';

/**
 * Compact SureRank AI card for the Dashboard right rail.
 * Connected: shows a link into the full screen.
 * Not connected: shows a short promo with a Connect call to action.
 * Both states deep-link to General → SureRank AI (`/surerank-ai`).
 */
const SureRankAiCard = () => {
	const navigate = useNavigate();
	// Seed from the localized flag for a no-flash first paint, then confirm the
	// real connection status via the API (the global can be stale after the
	// SureRank AI screen mutates it).
	const [ connected, setConnected ] = useState( () =>
		Boolean( window?.surerank_globals?.ai_authenticated )
	);

	useEffect( () => {
		let mounted = true;
		getAuth()
			.then( ( response ) => {
				if ( ! mounted ) {
					return;
				}
				const isConnected = Boolean(
					response?.success && ! response?.auth_url
				);
				setConnected( isConnected );
				if ( window?.surerank_globals ) {
					window.surerank_globals.ai_authenticated = isConnected;
				}
			} )
			.catch( () => {
				// A transient failure is not a real disconnect — keep the
				// last-known (seeded) state instead of forcing "Connect".
			} );
		return () => {
			mounted = false;
		};
	}, [] );

	const goToScreen = () => navigate( { to: '/surerank-ai' } );

	return (
		<Container
			className="w-full h-fit bg-background-primary border-0.5 border-solid rounded-xl border-border-subtle p-4 shadow-sm"
			containerType="flex"
			direction="column"
			gap="sm"
		>
			<Container.Item className="flex items-center justify-between gap-2 w-full">
				<div className="flex items-center gap-2">
					<Label className="font-semibold text-text-primary">
						{ __( 'SureRank AI', 'surerank' ) }
					</Label>
					{ connected && (
						<Badge
							size="xs"
							variant="green"
							label={ __( 'Connected', 'surerank' ) }
						/>
					) }
				</div>
				{ connected && (
					<Button
						variant="link"
						size="xs"
						icon={ <ArrowRight /> }
						iconPosition="right"
						onClick={ goToScreen }
					>
						{ __( 'Manage', 'surerank' ) }
					</Button>
				) }
			</Container.Item>

			{ ! connected && (
				<Container.Item className="w-full flex flex-col gap-3">
					<Text
						size={ 13 }
						color="secondary"
						className="leading-relaxed"
					>
						{ __(
							'Connect to unlock AI titles, meta descriptions, alt text and more right inside your editor.',
							'surerank'
						) }
					</Text>
					<Button
						variant="primary"
						size="sm"
						className="w-full justify-center"
						onClick={ goToScreen }
					>
						{ __( 'Connect SureRank AI', 'surerank' ) }
					</Button>
				</Container.Item>
			) }
		</Container>
	);
};

export default SureRankAiCard;
