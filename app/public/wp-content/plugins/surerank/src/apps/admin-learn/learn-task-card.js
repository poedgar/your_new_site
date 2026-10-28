import { Badge, Button } from '@bsf/force-ui';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowUpRight, Check, Info, Lock } from 'lucide-react';
import { cn } from '@/functions/utils';
import { Tooltip } from '@AdminComponents/tooltip';
import { getSurerankUtmUrl } from '@/global/utils/utm';

const buildLearnMoreHref = ( url, stepId ) => {
	if ( ! url ) {
		return '';
	}
	return getSurerankUtmUrl( url, 'admin_learn', stepId );
};

const LearnTaskCard = ( {
	chapterId,
	step,
	completed,
	autoDetected,
	onToggle,
	onCta,
} ) => {
	const locked = Boolean( step.locked );
	const isPro = Boolean( step.pro );
	// Unlocked Pro cards (active + licensed) get the `proCta` attached by the
	// Pro plugin via the `surerank-pro.learn-chapter` filter; locked cards get
	// a default Upgrade to Pro CTA that routes to the pricing page (see the
	// `step.locked` branch in cta-handler.js).
	let activeCta = isPro ? step.proCta : step.cta;
	if ( locked ) {
		activeCta = { label: __( 'Upgrade to Pro', 'surerank' ) };
	}
	const showCta = Boolean( activeCta );
	// Learn More is surfaced as a compact info icon beside the CTA (mirrors the
	// Astra onboarding pattern) rather than an inline text link in the title
	// row, which cluttered the heading and pushed the CTA buttons out of
	// alignment. Kept off locked Pro cards, same as the previous behaviour.
	const showLearnMore = Boolean( step.learnMoreUrl ) && ! locked;

	const handleCheckboxClick = ( e ) => {
		e.stopPropagation();
		if ( autoDetected ) {
			return;
		}
		onToggle( chapterId, step.id, ! completed );
	};

	return (
		<div
			className={ cn(
				'flex items-center gap-4 p-4 bg-background-primary border border-solid border-border-subtle rounded-lg',
				locked && 'opacity-90'
			) }
		>
			{ locked ? (
				<span
					aria-hidden="true"
					className="shrink-0 self-start mt-0.5 flex items-center justify-center size-5 rounded-full border border-solid bg-background-secondary border-border-strong text-icon-secondary cursor-not-allowed"
				>
					<Lock className="size-3 shrink-0" />
				</span>
			) : (
				<button
					type="button"
					onClick={ handleCheckboxClick }
					disabled={ autoDetected }
					aria-pressed={ completed }
					aria-label={
						completed
							? __( 'Mark step incomplete', 'surerank' )
							: __( 'Mark step complete', 'surerank' )
					}
					className={ cn(
						'shrink-0 self-start mt-0.5 flex items-center justify-center size-5 rounded-full border border-solid transition-colors',
						completed
							? 'bg-button-primary border-button-primary text-text-on-color'
							: 'bg-background-primary border-border-strong text-transparent hover:border-button-primary',
						autoDetected
							? 'cursor-not-allowed opacity-90'
							: 'cursor-pointer'
					) }
				>
					<Check className="size-3 shrink-0" strokeWidth={ 3 } />
				</button>
			) }
			<div className="flex-1 min-w-0 flex flex-col gap-1">
				<div className="flex items-center gap-2 flex-wrap">
					<span className="text-sm font-medium text-text-primary leading-5">
						{ step.title }
					</span>
					{ autoDetected && (
						<Badge
							size="xs"
							variant="green"
							label={ __( 'Auto-detected', 'surerank' ) }
						/>
					) }
				</div>
				<span className="text-sm text-text-secondary leading-5">
					{ step.description }
				</span>
			</div>
			{ ( showLearnMore || showCta ) && (
				<div className="shrink-0 flex items-center gap-3">
					{ showLearnMore && (
						<Tooltip
							content={ __( 'Learn More', 'surerank' ) }
							placement="top"
							arrow
							className="z-[99999]"
						>
							<a
								href={ buildLearnMoreHref(
									step.learnMoreUrl,
									step.id
								) }
								target="_blank"
								rel="noopener noreferrer"
								className="inline-flex items-center justify-center p-1 -m-1 rounded text-icon-secondary hover:text-icon-primary transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-focus"
								aria-label={ sprintf(
									// translators: %s: step title
									__( 'Learn more about %s', 'surerank' ),
									step.title
								) }
							>
								<Info aria-hidden="true" className="size-4" />
							</a>
						</Tooltip>
					) }
					{ showCta && (
						<Button
							variant="primary"
							size="sm"
							className="min-w-40"
							icon={ <ArrowUpRight /> }
							iconPosition="right"
							onClick={ () =>
								onCta( chapterId, step, autoDetected )
							}
						>
							{ ( autoDetected && step.autoDetectedCta?.label ) ||
								activeCta?.label }
						</Button>
					) }
				</div>
			) }
		</div>
	);
};

export default LearnTaskCard;
