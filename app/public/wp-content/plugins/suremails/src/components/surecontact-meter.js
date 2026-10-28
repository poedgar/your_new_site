import { useEffect, useState } from '@wordpress/element';
import { Badge, Button, Text } from '@bsf/force-ui';
import { __, sprintf } from '@wordpress/i18n';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRight, Gift } from 'lucide-react';
import { fetchSettings } from '@api/connections';
import { getSureContactStatus } from '@api/surecontact';
import SureContactUpgradeModal from './surecontact-upgrade-modal';

const SURECONTACT_KEY = 'SURECONTACT';

const getHeadline = ( period ) => {
	switch ( period ) {
		case 'monthly':
			return __( 'Monthly limit', 'suremails' );
		case 'daily':
			return __( 'Daily limit', 'suremails' );
		case 'lifetime':
		default:
			return __( 'Free plan', 'suremails' );
	}
};

const getMeterState = ( pct ) => {
	if ( pct >= 100 ) {
		return 'critical';
	}
	if ( pct >= 90 ) {
		return 'alert';
	}
	if ( pct >= 80 ) {
		return 'warning';
	}
	return 'safe';
};

const STATE_STYLES = {
	safe: {
		container: 'bg-background-primary border-border-subtle',
		track: 'bg-misc-progress-background',
		fill: 'bg-background-brand',
	},
	warning: {
		container: 'bg-[#FFFBEB] border-[#FCD34D]',
		track: 'bg-[#FDE68A]',
		fill: 'bg-[#F59E0B]',
	},
	alert: {
		container: 'bg-[#FFF7ED] border-[#FDBA74]',
		track: 'bg-[#FED7AA]',
		fill: 'bg-[#EA580C]',
	},
	critical: {
		container: 'bg-[#FEF2F2] border-[#FCA5A5]',
		track: 'bg-[#FECACA]',
		fill: 'bg-[#EF4444]',
	},
};

const getStatusCopy = (
	state,
	{ used, limit, remaining, period },
	onUpgrade
) => {
	if ( state === 'critical' ) {
		if ( period === 'daily' ) {
			return sprintf(
				/* translators: 1: emails used, 2: emails limit */
				__(
					'%1$s/%2$s Daily limit reached — verify your email to unlock 100/month.',
					'suremails'
				),
				used.toLocaleString(),
				limit.toLocaleString()
			);
		}
		const prefix = sprintf(
			/* translators: 1: emails used, 2: emails limit */
			__( "%1$s/%2$s Limit reached emails won't send. ", 'suremails' ),
			used.toLocaleString(),
			limit.toLocaleString()
		);
		const linkText = __( 'Upgrade your plan', 'suremails' );
		return (
			<>
				{ prefix }
				{ onUpgrade ? (
					<button
						type="button"
						onClick={ onUpgrade }
						className="p-0 bg-transparent border-0 cursor-pointer underline text-text-secondary hover:text-text-primary"
					>
						{ linkText }
					</button>
				) : (
					linkText
				) }
			</>
		);
	}

	if ( state === 'alert' ) {
		return sprintf(
			/* translators: 1: emails used, 2: emails limit, 3: emails remaining */
			__( '%1$s/%2$s Almost at your limit · %3$s left', 'suremails' ),
			used.toLocaleString(),
			limit.toLocaleString(),
			remaining.toLocaleString()
		);
	}

	if ( state === 'warning' ) {
		return sprintf(
			/* translators: 1: emails used, 2: emails limit, 3: emails remaining */
			__( '%1$s/%2$s Getting close · %3$s left', 'suremails' ),
			used.toLocaleString(),
			limit.toLocaleString(),
			remaining.toLocaleString()
		);
	}

	return sprintf(
		/* translators: 1: emails used, 2: emails limit */
		__( '%1$s/%2$s email used', 'suremails' ),
		used.toLocaleString(),
		limit.toLocaleString()
	);
};

/**
 * Dashboard meter for SureContact accounts. Mirrors the verify-banner pattern:
 * fetch the cap on mount and again whenever the tab regains focus, but skip
 * the timer-based refetch — sends are bursty and the user always gets a fresh
 * count after a tab switch.
 */
const SureContactMeter = () => {
	const queryClient = useQueryClient();
	const [ isUpgradeOpen, setIsUpgradeOpen ] = useState( false );

	const { data: settings } = useQuery( {
		queryKey: [ 'settings' ],
		queryFn: fetchSettings,
		select: ( data ) => data?.data || {},
		refetchOnMount: false,
		refetchOnWindowFocus: false,
	} );

	const connection = settings?.connections
		? Object.values( settings.connections ).find(
				( c ) => c?.type === SURECONTACT_KEY
		  )
		: undefined;
	const connectionId = connection?.id ?? null;

	const { data: status } = useQuery( {
		queryKey: [ 'surecontact-cap', connectionId ],
		queryFn: () => getSureContactStatus( connectionId ),
		enabled: Boolean( connectionId ),
		refetchOnWindowFocus: false,
	} );

	// Only listen for visibility changes once we have a renderable status —
	// keeps a permanently-failing status (e.g. revoked key) from triggering a
	// fresh refetch on every tab switch.
	useEffect( () => {
		if ( ! connectionId || ! status?.success ) {
			return;
		}
		const onVisibility = () => {
			if ( document.visibilityState === 'visible' ) {
				queryClient.invalidateQueries( {
					queryKey: [ 'surecontact-cap', connectionId ],
				} );
			}
		};
		document.addEventListener( 'visibilitychange', onVisibility );
		return () =>
			document.removeEventListener( 'visibilitychange', onVisibility );
	}, [ connectionId, status?.success, queryClient ] );

	if ( ! connectionId || ! status?.success ) {
		return null;
	}

	const cap = status.cap;
	if ( ! cap || typeof cap.limit !== 'number' || cap.limit === 0 ) {
		return null;
	}

	const isUnlimited = cap.limit === -1;
	const showUpgrade = cap.period !== 'daily';

	if ( isUnlimited ) {
		return (
			<div className="w-full p-4 border-0.5 border-solid rounded-xl bg-alert-background-warning border-alert-border-warning">
				<div className="flex items-center gap-3">
					<div className="grid bg-background-primary place-items-center rounded-lg size-10 border-0.5 border-solid border-alert-border-warning">
						<Gift
							className="size-4 text-icon-warning"
							strokeWidth={ 1.75 }
						/>
					</div>
					<div className="flex-1">
						<Text as="p" size={ 14 } weight={ 600 } color="primary">
							{ __( 'Unlimited sends', 'suremails' ) }
						</Text>
						<Text as="p" size={ 12 } color="tertiary">
							{ __(
								'Your plan has no monthly cap.',
								'suremails'
							) }
						</Text>
					</div>
					<Badge
						label={ __( 'Unlimited', 'suremails' ) }
						size="xs"
						type="pill"
						variant="green"
					/>
				</div>
			</div>
		);
	}

	const used = Math.max( 0, Number( cap.used ) || 0 );
	const limit = Math.max( 1, Number( cap.limit ) || 1 );
	const remaining = Math.max( 0, Number( cap.remaining ?? limit - used ) );
	const pct = Math.min( 100, Math.max( 0, ( used / limit ) * 100 ) );
	const meterState = getMeterState( pct );
	const styles = STATE_STYLES[ meterState ];
	const headline = getHeadline( cap.period );
	const statusCopy = getStatusCopy(
		meterState,
		{ used, limit, remaining, period: cap.period },
		() => setIsUpgradeOpen( true )
	);

	return (
		<>
			<div
				className={ `w-full p-4 border-0.5 border-solid rounded-xl ${ styles.container }` }
			>
				<div className="flex items-center gap-3 mb-3">
					<div className="flex-1 min-w-0 flex items-center gap-2">
						<Text as="p" size={ 14 } weight={ 600 } color="primary">
							{ headline }
						</Text>
						<Badge
							label={ sprintf(
								/* translators: %s: email limit */
								__( '%s Emails', 'suremails' ),
								limit.toLocaleString()
							) }
							size="xs"
							type="pill"
							variant="blue"
						/>
					</div>
					{ showUpgrade && (
						<Button
							variant="primary"
							size="sm"
							onClick={ () => setIsUpgradeOpen( true ) }
							icon={ <ArrowRight className="size-3" /> }
							iconPosition="right"
						>
							{ __( 'Upgrade', 'suremails' ) }
						</Button>
					) }
				</div>
				<div
					className={ `w-full h-2 rounded-full overflow-hidden ${ styles.track }` }
					role="progressbar"
					aria-valuenow={ Math.round( pct ) }
					aria-valuemin={ 0 }
					aria-valuemax={ 100 }
				>
					<div
						className={ `h-full rounded-full transition-[width] duration-300 ${ styles.fill }` }
						style={ { width: `${ Math.max( pct, 3 ) }%` } }
					/>
				</div>
				<div className="flex items-center justify-between mt-2 gap-3">
					<Text as="span" size={ 12 } color="tertiary">
						{ statusCopy }
					</Text>
					<Text
						as="span"
						size={ 12 }
						weight={ 500 }
						color="secondary"
					>
						{ sprintf(
							/* translators: %1$s: percentage of cap consumed */
							__( '%1$s%%', 'suremails' ),
							Math.round( pct )
						) }
					</Text>
				</div>
			</div>
			<SureContactUpgradeModal
				open={ isUpgradeOpen }
				setOpen={ setIsUpgradeOpen }
				connectionId={ connectionId }
			/>
		</>
	);
};

export default SureContactMeter;
