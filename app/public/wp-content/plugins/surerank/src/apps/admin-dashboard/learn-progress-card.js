import { Button, Container, Label, ProgressBar } from '@bsf/force-ui';
import { __, sprintf } from '@wordpress/i18n';
import { useNavigate } from '@tanstack/react-router';
import { ArrowRight, GraduationCap } from 'lucide-react';
import useLearnProgress from '@/apps/admin-learn/use-learn-progress';

const LearnProgressCard = () => {
	const navigate = useNavigate();
	const { getOverallStats } = useLearnProgress();
	const stats = getOverallStats();

	if ( stats.total === 0 || stats.pct === 100 ) {
		return null;
	}

	return (
		<Container
			containerType="flex"
			direction="column"
			className="w-full p-4 sm:p-5 gap-3 bg-background-primary border-0.5 border-solid rounded-xl border-border-subtle shadow-sm"
		>
			<Container.Item className="flex items-center gap-2">
				<GraduationCap className="size-5 text-icon-primary" />
				<Label className="font-semibold text-text-primary">
					{ __( 'Continue Learning', 'surerank' ) }
				</Label>
			</Container.Item>
			<Container.Item className="flex flex-col gap-1">
				<span className="text-sm text-text-secondary leading-5">
					{ sprintf(
						// translators: 1: completed steps, 2: total steps
						__(
							'%1$d of %2$d setup steps complete. A few more and your site is ready.',
							'surerank'
						),
						stats.done,
						stats.total
					) }
				</span>
				<ProgressBar progress={ stats.pct } />
			</Container.Item>
			<Container.Item>
				<Button
					variant="outline"
					size="sm"
					icon={ <ArrowRight /> }
					iconPosition="right"
					onClick={ () => navigate( { to: '/learn' } ) }
				>
					{ __( 'Continue', 'surerank' ) }
				</Button>
			</Container.Item>
		</Container>
	);
};

export default LearnProgressCard;
