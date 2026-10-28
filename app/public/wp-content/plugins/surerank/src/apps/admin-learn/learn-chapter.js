import { Accordion, Badge } from '@bsf/force-ui';
import { __, sprintf } from '@wordpress/i18n';
import LearnTaskCard from './learn-task-card';

const LearnChapter = ( {
	chapter,
	stats,
	isStepComplete,
	isStepAutoDetected,
	markStep,
	onCta,
	// Forwarded by the parent <Accordion>:
	value,
	isOpen,
	onToggle,
	type,
	disabled,
} ) => {
	const pct =
		stats.total === 0
			? 0
			: Math.round( ( stats.done / stats.total ) * 100 );
	const countLabel = sprintf(
		// translators: 1: completed steps, 2: total steps
		__( '%1$d/%2$d', 'surerank' ),
		stats.done,
		stats.total
	);

	return (
		<Accordion.Item
			value={ value }
			isOpen={ isOpen }
			onToggle={ onToggle }
			type={ type }
			disabled={ disabled }
			className={ [
				'border border-solid rounded-lg overflow-hidden',
				'focus:outline-none focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-focus',
				'[&>h3]:focus-visible:outline-none',
				isOpen ? 'border-brand-border-300' : 'border-border-subtle',
			].join( ' ' ) }
		>
			<Accordion.Trigger className="[&>div:first-child]:flex-1 [&>div:first-child]:justify-between transition-[box-shadow,color] focus:outline-none focus-visible:outline-none">
				<span className="text-base font-semibold text-text-primary text-left">
					{ chapter.title }
				</span>
				{ chapter.isPro && stats.total === 0 ? (
					<Badge
						size="sm"
						type="pill"
						variant="blue"
						label={ __( 'Pro', 'surerank' ) }
					/>
				) : (
					<span
						className="relative inline-flex items-center justify-center gap-1 min-w-[2.75rem] h-5 px-2 rounded-full overflow-hidden bg-background-secondary border border-solid border-brand-border-300"
						aria-label={ sprintf(
							// translators: %d: percent complete
							__( 'Chapter progress: %d%%', 'surerank' ),
							pct
						) }
					>
						<span
							aria-hidden="true"
							className="absolute inset-y-0 left-0 bg-brand-200"
							style={ { width: `${ pct }%` } }
						/>
						<span className="relative text-xs font-medium text-text-primary leading-none">
							{ countLabel }
						</span>
					</span>
				) }
			</Accordion.Trigger>
			<Accordion.Content>
				<div className="flex flex-col gap-4 pt-2">
					<span className="text-sm text-text-secondary leading-5">
						{ chapter.description }
					</span>
					<div className="flex flex-col gap-3">
						{ chapter.steps.map( ( step ) => (
							<LearnTaskCard
								key={ step.id }
								chapterId={ chapter.id }
								step={ step }
								completed={ isStepComplete(
									chapter.id,
									step.id
								) }
								autoDetected={
									! step.locked &&
									isStepAutoDetected( chapter.id, step.id )
								}
								onToggle={ markStep }
								onCta={ onCta }
							/>
						) ) }
					</div>
				</div>
			</Accordion.Content>
		</Accordion.Item>
	);
};

export default LearnChapter;
