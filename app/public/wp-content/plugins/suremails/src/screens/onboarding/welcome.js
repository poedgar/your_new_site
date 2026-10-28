import { Badge, Button, Text } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { Check } from 'lucide-react';
import { useOnboardingNavigation } from './hooks';
import { Divider } from './components';

const features = [
	__( '100 free emails included no credit card', 'suremails' ),
	__( 'Quick and easy setup, no technical skills needed', 'suremails' ),
	__( 'Monitor and track every email', 'suremails' ),
	__( 'Works with WooCommerce, Contact Form 7, and more', 'suremails' ),
	__( 'Auto-retry failed emails', 'suremails' ),
];

const Welcome = () => {
	const { navigateToNextRoute } = useOnboardingNavigation();

	return (
		<form
			onSubmit={ ( event ) => event.preventDefault() }
			className="space-y-6"
		>
			<div className="space-y-1.5">
				<div className="flex items-center gap-2">
					<Text as="h2" size={ 30 } weight={ 600 }>
						{ __( 'Welcome to SureMail', 'suremails' ) }
					</Text>
					<Badge
						label={ __( '2 min Setup', 'suremails' ) }
						size="sm"
						type="pill"
						variant="green"
					/>
				</div>
				<Text size={ 16 } weight={ 500 } color="secondary">
					{ __(
						'Reliable Email Delivery for WordPress without the Technical Hassle.',
						'suremails'
					) }
				</Text>
			</div>

			<iframe
				className="w-full aspect-video rounded-lg"
				src="https://www.youtube.com/embed/fFKJfbWLif4?autoplay=1&mute=1"
				title="SureMail introduction video"
				frameBorder="0"
				allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; autoplay"
				allowFullScreen
			></iframe>

			<ul className="space-y-2">
				{ features.map( ( feature, index ) => (
					<li key={ index } className="flex items-center gap-2">
						<Check
							className="size-4 text-icon-primary shrink-0"
							strokeWidth={ 1.5 }
						/>
						<Text size={ 14 } weight={ 500 } color="label">
							{ feature }
						</Text>
					</li>
				) ) }
			</ul>

			<Divider />

			<Button onClick={ navigateToNextRoute }>
				{ __( 'Start Sending Emails', 'suremails' ) }
			</Button>
		</form>
	);
};

export default Welcome;
