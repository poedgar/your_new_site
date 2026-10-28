import {
	Button,
	Badge,
	Container,
	Label,
	Text,
	Title,
	Skeleton,
	DropdownMenu,
	Loader,
	ProgressBar,
	toast,
} from '@bsf/force-ui';
import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import {
	Sparkles,
	Type,
	Link2,
	Image as ImageIcon,
	Braces,
	ShieldCheck,
	Wand2,
	EllipsisVertical,
	ArrowRight,
	ArrowUpRight,
	ExternalLink,
	Unplug,
} from 'lucide-react';
import { getAuth, disconnectAuth, getAIUsage } from '@Functions/api';
import useAuthPolling from '@/global/hooks/use-auth-polling';
import { ConfirmationDialog } from '@/global/components/confirmation-dialog';
import { Tooltip } from '@AdminComponents/tooltip';
import {
	isProActive,
	getActivePlan,
	redirectToPricingPage,
} from '@/functions/nudges';
import { cn } from '@/functions/utils';
import { getSurerankUtmUrl } from '@/global/utils/utm';

/**
 * SureRank AI capability catalog (product content).
 * `free` marks capabilities available on the free plan; Pro-only capabilities
 * are unlocked via the `surerank.ai-usage.features` filter or Pro entitlement.
 */
const BASE_FEATURES = [
	{
		id: 'content-generation',
		title: __( 'Content Generation', 'surerank' ),
		description: __(
			'Generate SEO-optimized titles and meta descriptions instantly.',
			'surerank'
		),
		icon: Type,
		free: true,
		learnMore: 'https://surerank.com/surerank-ai/',
	},
	{
		id: 'schema-recommendations',
		title: __( 'AI Schema Recommendations', 'surerank' ),
		description: __(
			'Get schema suggestions that help pages qualify for rich results.',
			'surerank'
		),
		icon: ShieldCheck,
		free: true,
		learnMore: 'https://surerank.com/schema-builder/',
	},
	{
		id: 'fix-it-for-me',
		title: __( 'Fix It For Me', 'surerank' ),
		description: __(
			'Turn detected SEO issues into guided, one-click fixes across your pages.',
			'surerank'
		),
		icon: Wand2,
		free: false,
		learnMore: 'https://surerank.com/surerank-ai/',
	},
	{
		id: 'link-suggestion',
		title: __( 'Link Suggestion', 'surerank' ),
		description: __(
			'Discover contextual internal link opportunities from your content.',
			'surerank'
		),
		icon: Link2,
		free: false,
		learnMore: 'https://surerank.com/link-management/',
	},
	{
		id: 'image-generation',
		title: __( 'Image Generation', 'surerank' ),
		description: __(
			'Create optimized Open Graph images for better social sharing.',
			'surerank'
		),
		icon: ImageIcon,
		free: false,
		learnMore:
			'https://surerank.com/docs/how-to-generate-open-graph-images-in-surerank/',
	},
	{
		id: 'alt-text-generation',
		title: __( 'Alt Text Generation', 'surerank' ),
		description: __(
			'Auto-generate descriptive, SEO-friendly alt text for images.',
			'surerank'
		),
		icon: Braces,
		free: false,
		learnMore: 'https://surerank.com/image-seo/',
	},
];

const getGlobals = () => window?.surerank_globals || {};

// Active plan slug (from `active_plan`) → badge label. No active license
// falls back to the free plan.
const PLAN_LABELS = {
	'surerank-starter': __( 'Starter Plan', 'surerank' ),
	'surerank-pro': __( 'Pro Plan', 'surerank' ),
	'surerank-business': __( 'Business Plan', 'surerank' ),
};

const clampPercent = ( value ) =>
	Math.min( 100, Math.max( 0, Number( value ) || 0 ) );

// Fair-use threshold for the header usage bar (numbers are never shown).
const USAGE_WARN_AT = 90;

// Bar state: 'ok' (< 90%), 'warn' (>= 90%), 'full' (100%).
const usageState = ( value ) => {
	const pct = clampPercent( value );
	if ( pct >= 100 ) {
		return 'full';
	}
	return pct >= USAGE_WARN_AT ? 'warn' : 'ok';
};

// The usage value the bar should reflect. The credit service already scopes
// the response to the caller's plan (a free token only receives its own
// features) and computes `percent` as the max across them, so the same
// figure is correct for free and pro alike.
const resolveUsagePercent = ( usage ) => {
	// Unknown figure (service unreachable) — callers hide the bar instead of
	// showing a misleading 0%.
	if ( usage?.percent === null ) {
		return null;
	}
	if ( typeof usage?.percent !== 'undefined' ) {
		return clampPercent( usage.percent );
	}
	// No combined figure — fall back to the worst per-feature usage.
	const values = Object.values( usage?.features || {} ).map( ( feature ) =>
		clampPercent( feature?.percent ?? feature )
	);
	return values.length ? Math.max( ...values ) : 0;
};

// Feature icon tile.
const FeatureVisual = ( { icon: Icon = Sparkles, muted = false } ) => (
	<span
		className={ cn(
			'flex items-center justify-center size-9 rounded-[10px] shrink-0',
			muted
				? 'bg-background-secondary text-icon-secondary'
				: 'bg-brand-background-50 text-brand-800'
		) }
	>
		<Icon className="size-[18px]" strokeWidth={ 2 } />
	</span>
);

// Card title + description shared by the feature and preview cards.
const FeatureHeader = ( { feature } ) => (
	<h4 className="m-0 text-[14.5px] font-semibold leading-tight tracking-[-0.01em] text-text-primary">
		{ feature.title }
	</h4>
);

const FeatureBody = ( { feature } ) => (
	<p className="m-0 text-[12.5px] leading-[1.5] text-text-secondary">
		{ feature.description }
	</p>
);

// Learn More / Upgrade footer links (persistent underline on Learn More).
const LearnMoreLink = ( { href } ) => (
	<a
		className="inline-flex items-center gap-1 text-[12.5px] font-semibold text-brand-800 no-underline hover:no-underline"
		href={ href }
		target="_blank"
		rel="noreferrer"
	>
		{ __( 'Learn More', 'surerank' ) }
		<ArrowRight className="size-3.5" strokeWidth={ 2 } />
	</a>
);

const UpgradeLink = () => (
	<button
		type="button"
		className="inline-flex items-center gap-1 p-0 m-0 bg-transparent border-0 cursor-pointer text-[12.5px] font-semibold text-brand-800"
		onClick={ () => redirectToPricingPage( 'surerank_ai_screen' ) }
	>
		{ __( 'Upgrade', 'surerank' ) }
		<ArrowUpRight className="size-3.5" strokeWidth={ 2 } />
	</button>
);

// Read-only feature card (connected state).
const FeatureCard = ( { feature, learnMoreUrl } ) => (
	<div className="relative flex flex-col gap-2.5 p-4 bg-background-primary border border-solid border-border-subtle rounded-xl shadow-sm">
		<div className="flex items-start justify-between gap-2">
			<div className="flex items-center gap-[11px]">
				<FeatureVisual
					icon={ feature.icon }
					muted={ ! feature.available }
				/>
				<FeatureHeader feature={ feature } />
			</div>
			{ ! feature.available && (
				<Badge
					size="xs"
					variant="inverse"
					label={ __( 'Pro', 'surerank' ) }
				/>
			) }
		</div>
		<FeatureBody feature={ feature } />
		<div className="flex items-center justify-between mt-auto pt-1">
			<LearnMoreLink
				href={ getSurerankUtmUrl(
					feature.learnMore || learnMoreUrl,
					'surerank_ai_screen',
					( feature.id || '' ).replace( /-/g, '_' )
				) }
			/>
			{ ! feature.available && <UpgradeLink /> }
		</div>
	</div>
);

// Muted preview card (not-connected state).
const PreviewCard = ( { feature } ) => (
	<div className="flex flex-col gap-2.5 p-4 bg-background-primary border border-solid border-border-subtle rounded-xl shadow-sm opacity-90">
		<div className="flex items-center gap-[11px]">
			<FeatureVisual icon={ feature.icon } muted />
			<FeatureHeader feature={ feature } />
		</div>
		<FeatureBody feature={ feature } />
	</div>
);

// Human-friendly percent for tooltip copy (tiny usage reads as "less than 1%").
const percentText = ( value ) => {
	const pct = clampPercent( value );
	if ( pct >= 1 ) {
		return `${ Math.round( pct ) }%`;
	}
	return pct > 0 ? __( 'less than 1%', 'surerank' ) : '0%';
};

// Header usage bar: fills with real usage, shows no numbers (percent lives in
// the tooltip). Violet < 90%, amber >= 90%, red at 100%. Near/at the limit it
// nudges Pro users to support (fair-use reset) and free users to upgrade.
const UsageBar = ( { percent, isPro } ) => {
	// Keep the bar visible for connected users; when the figure is unknown
	// (service unavailable) show a neutral empty state instead of a fake 0%.
	const isUnknown = percent === null || percent === undefined;
	const value = isUnknown ? 0 : clampPercent( percent );
	const state = usageState( value );
	const isAlert = ! isUnknown && state !== 'ok';
	const pctText = percentText( value );
	const supportUrl =
		getGlobals().support_link || getGlobals().help_link || '';

	let tooltip;
	let linkHref = '';
	let handleClick = null;

	if ( isUnknown ) {
		tooltip = __(
			'Your SureRank AI usage will appear here once it’s available.',
			'surerank'
		);
	} else if ( isPro ) {
		const tips = {
			full: __(
				'One of your AI features has reached its usage limit. Contact support if you need it reset.',
				'surerank'
			),
			warn: __(
				'One of your AI features is close to its usage limit. Contact support if you need it reset.',
				'surerank'
			),
			ok: sprintf(
				/* translators: %s is the highest per-feature usage percentage. */
				__(
					'Your most-used AI feature is at %s of its current usage limit.',
					'surerank'
				),
				pctText
			),
		};
		tooltip = tips[ state ];
		if ( isAlert && supportUrl ) {
			linkHref = supportUrl;
		}
	} else {
		const tips = {
			full: __(
				"You've used all of your free AI usage. Upgrade to Pro for more AI usage.",
				'surerank'
			),
			warn: sprintf(
				/* translators: %s is the percentage of free AI usage used. */
				__(
					"You've used %s of your free AI usage. Upgrade to Pro for more AI usage.",
					'surerank'
				),
				pctText
			),
			ok: sprintf(
				/* translators: %s is the percentage of free AI usage used. */
				__( "You've used %s of your free AI usage.", 'surerank' ),
				pctText
			),
		};
		tooltip = tips[ state ];
		if ( isAlert ) {
			handleClick = () => redirectToPricingPage( 'surerank_ai_usage' );
		}
	}

	const bar = (
		<span className="flex items-center gap-2">
			<span className="text-[11px] font-semibold uppercase tracking-wide text-text-tertiary">
				{ __( 'AI usage', 'surerank' ) }
			</span>
			<span
				role="progressbar"
				aria-label={ __( 'AI usage', 'surerank' ) }
				aria-valuenow={ value }
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
				className="block w-[120px]"
			>
				<ProgressBar progress={ value } className="w-full" />
			</span>
		</span>
	);

	let trigger = <span aria-label={ tooltip }>{ bar }</span>;
	if ( linkHref ) {
		trigger = (
			<a
				href={ linkHref }
				target="_blank"
				rel="noreferrer"
				className="no-underline"
				aria-label={ tooltip }
			>
				{ bar }
			</a>
		);
	} else if ( handleClick ) {
		trigger = (
			<button
				type="button"
				className="p-0 m-0 bg-transparent border-0 cursor-pointer"
				onClick={ handleClick }
				aria-label={ tooltip }
			>
				{ bar }
			</button>
		);
	}

	return (
		<Tooltip
			content={ tooltip }
			placement="bottom"
			arrow
			className="max-w-72 z-[999999]"
		>
			{ trigger }
		</Tooltip>
	);
};

// Loading skeleton (before auth status resolves).
const LoadingState = () => (
	<Container direction="column" gap="lg" className="p-2">
		<Skeleton className="w-48 h-8" />
		<Skeleton className="w-full h-24 rounded-xl" />
		<div className="grid grid-cols-1 md:grid-cols-3 gap-3">
			{ Array.from( { length: 6 } ).map( ( _, index ) => (
				<Skeleton key={ index } className="w-full h-36 rounded-xl" />
			) ) }
		</div>
	</Container>
);

const SureRankAI = () => {
	const [ authenticated, setAuthenticated ] = useState( null );
	const [ authSource, setAuthSource ] = useState(
		() => getGlobals().ai_auth_source || ''
	);
	const [ usage, setUsage ] = useState( { percent: 0, features: {} } );
	const [ disconnectOpen, setDisconnectOpen ] = useState( false );
	const [ connecting, setConnecting ] = useState( false );

	const features = useMemo( () => {
		const withAvailability = BASE_FEATURES.map( ( feature ) => ( {
			...feature,
			available:
				feature.free ||
				isProActive( 'pro' ) ||
				!! getGlobals().ai_features?.[ feature.id ]?.available,
		} ) );
		return applyFilters(
			'surerank.ai-usage.features',
			withAvailability
		).filter( ( feature ) => feature?.id );
	}, [] );

	const learnMoreUrl = useMemo(
		() =>
			applyFilters(
				'surerank_ai_learn_more_url',
				'https://surerank.com/'
			),
		[]
	);

	const planLabel =
		PLAN_LABELS[ getActivePlan() ] || __( 'Free Plan', 'surerank' );

	const refreshUsage = useCallback( async () => {
		try {
			const response = await getAIUsage();
			setUsage( response?.usage || { percent: null, features: {} } );
		} catch ( error ) {
			// Unknown, not a real 0% — the bar hides instead of misleading.
			setUsage( { percent: null, features: {} } );
		}
	}, [] );

	// Fetch the authoritative status and reflect it (connected, source, usage).
	const syncAuth = useCallback( async () => {
		const response = await getAuth();
		const connected = Boolean( response?.success && ! response?.auth_url );
		setAuthenticated( connected );
		setAuthSource( response?.source || getGlobals().ai_auth_source || '' );
		if ( window?.surerank_globals ) {
			window.surerank_globals.ai_authenticated = connected;
		}
		if ( connected ) {
			refreshUsage();
		} else {
			setUsage( { percent: 0, features: {} } );
		}
	}, [ refreshUsage ] );

	const handleConnected = useCallback( () => {
		setConnecting( false );
		syncAuth().catch( () => {} );
	}, [ syncAuth ] );

	const { openAuthPopup } = useAuthPolling( handleConnected, () =>
		setConnecting( false )
	);

	const handleConnect = useCallback( async () => {
		setConnecting( true );
		try {
			const response = await getAuth();
			if ( response?.auth_url ) {
				openAuthPopup( response.auth_url );
				return;
			}
			handleConnected();
		} catch ( error ) {
			setConnecting( false );
		}
	}, [ openAuthPopup, handleConnected ] );

	const handleDisconnect = useCallback( async () => {
		try {
			await disconnectAuth();
		} catch ( error ) {
			// Ignore transport errors; the re-sync below reflects real state.
		}
		// The local account is removed, but an active Pro license may still
		// keep the user connected — re-sync so the UI shows the true state
		// (disconnected for account-only, still connected for license users).
		await syncAuth().catch( () => {
			// Re-sync failed: don't assume a disconnect (the DELETE may have
			// failed, or an active license still connects). Keep the last-known
			// state and surface the error instead.
			toast.error(
				__(
					'Could not refresh SureRank AI status. Please reload the page.',
					'surerank'
				)
			);
		} );
	}, [ syncAuth ] );

	const openManagePlan = useCallback( () => {
		const portal = getGlobals().ai_billing_portal;
		if ( portal ) {
			window.open( portal, '_blank', 'noopener,noreferrer' );
		}
	}, [] );

	const licenseAdminUrl = getGlobals().license_admin_url || '';
	const openManageLicense = useCallback( () => {
		if ( licenseAdminUrl ) {
			window.location.href = licenseAdminUrl;
		}
	}, [ licenseAdminUrl ] );

	// Resolve real auth status on mount (avoids stale connected/disconnected UI).
	useEffect( () => {
		syncAuth().catch( () => {
			// A transient failure is not a real disconnect — keep the
			// last-known status from the localized flags instead of forcing
			// the user back to a "Connect" state.
			setAuthenticated( Boolean( getGlobals().ai_authenticated ) );
			setAuthSource( getGlobals().ai_auth_source || '' );
		} );
	}, [ syncAuth ] );

	// The auth flow completes in a separate popup/tab that saves the token and
	// then closes, so re-sync when this window regains focus / becomes visible —
	// otherwise the screen keeps showing "Connect" until a manual reload.
	useEffect( () => {
		if ( authenticated ) {
			return;
		}

		const handleFocus = () => {
			syncAuth().catch( () => {} );
		};
		const handleVisibility = () => {
			if ( document.visibilityState === 'visible' ) {
				handleFocus();
			}
		};

		window.addEventListener( 'focus', handleFocus );
		document.addEventListener( 'visibilitychange', handleVisibility );

		return () => {
			window.removeEventListener( 'focus', handleFocus );
			document.removeEventListener(
				'visibilitychange',
				handleVisibility
			);
		};
	}, [ authenticated, syncAuth ] );

	if ( authenticated === null ) {
		return <LoadingState />;
	}

	// Not-connected state: promo hero + capability preview.
	if ( ! authenticated ) {
		return (
			<Container direction="column" gap="xl" className="p-2">
				<div className="flex flex-col gap-5 p-7 rounded-xl border border-solid border-border-subtle bg-gradient-to-br from-brand-background-50 to-background-primary">
					<div className="flex flex-col gap-3 max-w-xl">
						<Badge
							size="sm"
							variant="inverse"
							icon={ <Sparkles /> }
							label={ __( 'SureRank AI', 'surerank' ) }
							className="w-fit"
						/>
						<Title
							tag="h2"
							size="lg"
							title={ __(
								'AI-powered SEO, right inside WordPress',
								'surerank'
							) }
						/>
						<Text
							as="p"
							size={ 14 }
							color="secondary"
							className="leading-relaxed"
						>
							{ __(
								'Connect SureRank AI to generate titles and meta descriptions, get internal link suggestions, write image alt text, and fix SEO issues without leaving your editor.',
								'surerank'
							) }
						</Text>
					</div>
					<div className="flex items-center gap-4">
						<Button
							variant="primary"
							size="md"
							icon={ connecting ? <Loader /> : <Sparkles /> }
							iconPosition="left"
							disabled={ connecting }
							onClick={ handleConnect }
						>
							{ __( 'Connect SureRank AI', 'surerank' ) }
						</Button>
					</div>
				</div>

				<div className="flex flex-col gap-3">
					<Label
						size="sm"
						className="uppercase tracking-wide text-text-tertiary"
					>
						{ __( "What you'll unlock", 'surerank' ) }
					</Label>
					<div className="grid grid-cols-1 md:grid-cols-3 gap-3">
						{ features.map( ( feature ) => (
							<PreviewCard
								key={ feature.id }
								feature={ feature }
							/>
						) ) }
					</div>
				</div>
			</Container>
		);
	}

	// Connected state.
	const isPro = isProActive( 'pro' );
	const usageBarPercent = resolveUsagePercent( usage );
	return (
		<Container direction="column" gap="lg" className="p-2">
			<div className="flex items-center justify-between gap-3 flex-wrap">
				<div className="flex items-center gap-3">
					<Title
						tag="h2"
						size="md"
						title={ __( 'SureRank AI', 'surerank' ) }
					/>
					<Badge
						size="sm"
						variant="green"
						label={ __( 'Connected', 'surerank' ) }
					/>
					<Badge size="sm" variant="blue" label={ planLabel } />
				</div>
				<div className="flex items-center gap-3">
					<UsageBar percent={ usageBarPercent } isPro={ isPro } />
					<DropdownMenu placement="bottom-end">
						<DropdownMenu.Trigger>
							<Button
								variant="ghost"
								size="sm"
								icon={ <EllipsisVertical /> }
								aria-label={ __(
									'SureRank AI options',
									'surerank'
								) }
							/>
						</DropdownMenu.Trigger>
						<DropdownMenu.ContentWrapper className="z-[999999] !opacity-100 !transition-none">
							<DropdownMenu.Content className="w-48">
								<DropdownMenu.List>
									<DropdownMenu.Item
										onClick={ openManagePlan }
									>
										<ExternalLink className="size-4" />
										{ __( 'Manage Plan', 'surerank' ) }
									</DropdownMenu.Item>
									{ /* Disconnect applies only to account auth; a
									license-only user manages the license instead. */ }
									{ authSource === 'account' && (
										<>
											<DropdownMenu.Separator />
											<DropdownMenu.Item
												onClick={ () =>
													setDisconnectOpen( true )
												}
											>
												<Unplug className="size-4" />
												{ __(
													'Disconnect',
													'surerank'
												) }
											</DropdownMenu.Item>
										</>
									) }
									{ authSource === 'license' &&
										licenseAdminUrl && (
											<>
												<DropdownMenu.Separator />
												<DropdownMenu.Item
													onClick={
														openManageLicense
													}
												>
													<ExternalLink className="size-4" />
													{ __(
														'Manage License',
														'surerank'
													) }
												</DropdownMenu.Item>
											</>
										) }
								</DropdownMenu.List>
							</DropdownMenu.Content>
						</DropdownMenu.ContentWrapper>
					</DropdownMenu>
				</div>
			</div>

			{ /* Capabilities */ }
			<div className="grid grid-cols-1 md:grid-cols-3 gap-3">
				{ features.map( ( feature ) => (
					<FeatureCard
						key={ feature.id }
						feature={ feature }
						learnMoreUrl={ learnMoreUrl }
					/>
				) ) }
			</div>

			<ConfirmationDialog
				open={ disconnectOpen }
				setOpen={ setDisconnectOpen }
				title={ __( 'Disconnect SureRank AI', 'surerank' ) }
				description={ __(
					'Are you sure you want to disconnect SureRank AI? You can reconnect at any time.',
					'surerank'
				) }
				confirmLabel={ __( 'Disconnect', 'surerank' ) }
				confirmDestructive
				onConfirm={ handleDisconnect }
			/>
		</Container>
	);
};

export default SureRankAI;
