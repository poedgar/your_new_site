import { cn } from '@utils/utils';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { Topbar, ProgressSteps, Button } from '@bsf/force-ui';
import { SureMailLogo } from '@assets/icons';
import { XIcon } from 'lucide-react';
import { __ } from '@wordpress/i18n';
import { useOnboardingNavigation } from './hooks';
import { getProgressMeta } from './onboarding-routes-config';
import {
	OnboardingProvider,
	ONBOARDING_SESSION_STORAGE_KEY,
	useOnboardingState,
} from './onboarding-state';
import { useEffect, useLayoutEffect } from 'react';
import './styles.css';
/* global sessionStorage */

const NavBar = () => {
	const location = useLocation();
	const [ state ] = useOnboardingState();
	const progress = getProgressMeta( state, location.pathname );

	return (
		<Topbar className="p-5 bg-background-secondary">
			<Topbar.Left>
				<Topbar.Item>
					<SureMailLogo />
				</Topbar.Item>
			</Topbar.Left>
			<Topbar.Middle align="center">
				<Topbar.Item className="md:block hidden">
					{ progress.totalSteps > 0 && (
						<ProgressSteps
							key={ `progress-${ progress.totalSteps }` }
							completedVariant="number"
							currentStep={ progress.currentStep }
							size="md"
							type="inline"
							variant="number"
						>
							{ Array.from( {
								length: progress.totalSteps,
							} ).map( ( _, index ) => (
								<ProgressSteps.Step key={ index } size="md" />
							) ) }
						</ProgressSteps>
					) }
				</Topbar.Item>
			</Topbar.Middle>
			<Topbar.Right>
				<Topbar.Item>
					<Button
						className="no-underline"
						tag={ Link }
						to="/dashboard"
						icon={ <XIcon /> }
						size="xs"
						variant="ghost"
						iconPosition="right"
					>
						{ __( 'Exit Guided Setup', 'suremails' ) }
					</Button>
				</Topbar.Item>
			</Topbar.Right>
		</Topbar>
	);
};

const NavigationGuard = () => {
	const location = useLocation();
	const navigate = useNavigate();
	const { checkRequiredStep } = useOnboardingNavigation();

	useLayoutEffect( () => {
		const redirectUrl = checkRequiredStep();
		if ( redirectUrl ) {
			navigate( redirectUrl, { replace: true } );
		}
	}, [ location.pathname, checkRequiredStep, navigate ] );

	return null;
};

const OnboardingLayout = () => {
	const location = useLocation();

	const containerWidth = 'max-w-[46.875rem]'; // 750px — all onboarding screens

	useEffect( () => {
		document.body.classList.add( 'suremails-onboarding-page' );

		return () => {
			document.body.classList.remove( 'suremails-onboarding-page' );
		};
	}, [] );

	useEffect( () => {
		if ( location.pathname === '/onboarding/done' ) {
			sessionStorage.removeItem( ONBOARDING_SESSION_STORAGE_KEY );
		}
	}, [ location.pathname ] );

	useEffect( () => {
		return () => {
			sessionStorage.removeItem( ONBOARDING_SESSION_STORAGE_KEY );
		};
	}, [] );

	return (
		<OnboardingProvider>
			<NavigationGuard />

			<div className="bg-background-secondary h-full space-y-7 pb-10">
				<NavBar />
				<div className="p-7 w-full h-full">
					<div
						className={ cn(
							'w-full h-full border-0.5 border-solid border-border-subtle bg-background-primary shadow-sm rounded-xl mx-auto p-7',
							containerWidth
						) }
					>
						<Outlet />
					</div>
				</div>
			</div>
		</OnboardingProvider>
	);
};

export default OnboardingLayout;
