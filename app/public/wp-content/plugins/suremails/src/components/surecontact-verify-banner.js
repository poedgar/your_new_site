import { useEffect, useState } from '@wordpress/element';
import { Button, Text, toast } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Loader2 as LoaderIcon, Mail } from 'lucide-react';
import { fetchSettings } from '@api/connections';
import { getSureContactStatus, resendVerification } from '@api/surecontact';

const SURECONTACT_KEY = 'SURECONTACT';

/**
 * Banner shown above the dashboard whenever a SureContact connection still has
 * `email_verified=false`. Clicking the verification link in the email lifts a
 * 3/day cap, so this banner is the primary nudge to do that.
 *
 * Verification is a discrete user action (clicking a link in the email tab)
 * rather than a continuous server-side state change, so we don't poll. We sync
 * the local flag against SureContact at three precise moments:
 *   1. Banner mount — covers a stale flag at page load. The banner stays
 *      hidden until this first sync completes so a verified-on-the-platform
 *      account never flashes the banner before being silently confirmed.
 *   2. The tab becoming visible — covers the user returning from their email
 *      client after clicking the verification link.
 *   3. After a successful resend — covers the case where the user clicks the
 *      link before we re-check.
 */
const SureContactVerifyBanner = () => {
	const queryClient = useQueryClient();
	const [ isResending, setIsResending ] = useState( false );
	const [ syncedConnectionId, setSyncedConnectionId ] = useState( null );

	const { data: settings } = useQuery( {
		queryKey: [ 'settings' ],
		queryFn: fetchSettings,
		select: ( data ) => data?.data || {},
		refetchOnMount: false,
		refetchOnWindowFocus: false,
	} );

	const connections = settings?.connections
		? Object.values( settings.connections )
		: [];

	const unverified = connections.find(
		( connection ) =>
			connection?.type === SURECONTACT_KEY && ! connection?.email_verified
	);

	useEffect( () => {
		if ( ! unverified?.id ) {
			return;
		}

		let cancelled = false;

		const sync = async () => {
			try {
				const response = await getSureContactStatus( unverified.id );
				if ( cancelled ) {
					return;
				}
				if ( response?.success && response.email_verified ) {
					queryClient.invalidateQueries( {
						queryKey: [ 'settings' ],
					} );
					return;
				}
				setSyncedConnectionId( unverified.id );
			} catch ( err ) {
				// Network or server error — trust the local flag and surface
				// the banner so the user isn't left guessing.
				if ( ! cancelled ) {
					setSyncedConnectionId( unverified.id );
				}
			}
		};

		sync();

		const onVisibility = () => {
			if ( document.visibilityState === 'visible' ) {
				sync();
			}
		};
		document.addEventListener( 'visibilitychange', onVisibility );

		return () => {
			cancelled = true;
			document.removeEventListener( 'visibilitychange', onVisibility );
		};
	}, [ unverified?.id, queryClient ] );

	// Hold the banner until the first sync confirms the account really is
	// unverified. Without this gate, an account that was verified on the
	// platform briefly flashes the banner on every page load while the local
	// flag catches up.
	if ( ! unverified || syncedConnectionId !== unverified.id ) {
		return null;
	}

	const handleResend = async () => {
		setIsResending( true );
		try {
			const response = await resendVerification( unverified.id );
			if ( response?.success ) {
				toast.success(
					response.email_verified
						? __( 'Email already verified.', 'suremails' )
						: __(
								'Verification email sent — check your inbox.',
								'suremails'
						  )
				);
				queryClient.invalidateQueries( { queryKey: [ 'settings' ] } );
			} else {
				toast.error(
					__( 'Could not send verification email.', 'suremails' ),
					{
						description: response?.message,
					}
				);
			}
		} catch ( error ) {
			toast.error(
				__( 'Could not send verification email.', 'suremails' ),
				{
					description: error?.message,
				}
			);
		} finally {
			setIsResending( false );
		}
	};

	return (
		<div className="bg-alert-background-warning border-b border-x-0 border-t-0 border-solid border-alert-border-warning px-6 py-3">
			<div className="max-w-screen-2xl mx-auto flex items-center justify-between gap-4 flex-wrap">
				<div className="flex items-start gap-3">
					<Mail
						className="size-4 text-icon-warning shrink-0 mt-0.5"
						strokeWidth={ 1.75 }
					/>
					<div>
						<Text as="p" size={ 13 } weight={ 600 } color="primary">
							{ __(
								'Verify your SureContact email to unlock the full free-tier monthly cap.',
								'suremails'
							) }
						</Text>
						<Text as="p" size={ 12 } color="tertiary">
							{ __(
								'Until verified, sends are limited to a few per day across this account.',
								'suremails'
							) }
						</Text>
					</div>
				</div>
				<Button
					size="sm"
					variant="outline"
					icon={
						isResending ? (
							<LoaderIcon className="animate-spin" />
						) : null
					}
					disabled={ isResending }
					onClick={ handleResend }
					type="button"
				>
					{ isResending
						? __( 'Sending…', 'suremails' )
						: __( 'Resend verification', 'suremails' ) }
				</Button>
			</div>
		</div>
	);
};

export default SureContactVerifyBanner;
