import { useNavigate, useRouterState } from '@tanstack/react-router';
import steps, { FINAL_STEP_NUMBER } from './routes';
import { useSelect } from '@wordpress/data';
import { STORE_KEY } from '../store';
import apiFetch from '@wordpress/api-fetch';
import {
	getFunnelSessionId,
	markFunnelSessionCompleted,
} from '../utils/funnel-session';

/**
 * Record a completed wizard step with ZipWP.
 *
 * Attaches the per-session funnel id so each build is counted as one attempt,
 * and marks the funnel session as completed once the final step is recorded.
 *
 * @param {Object} args
 * @param {number} args.stepNumber The wizard step number being recorded.
 * @param {string} args.slug       The backend-whitelisted step slug.
 */
export const stepNextButtonClick = async ( { stepNumber, slug } ) => {
	if ( ! stepNumber || ! slug ) {
		return;
	}

	const isFinalStep = stepNumber >= FINAL_STEP_NUMBER;
	// UUID that stays stable for this wizard session (cookie-backed, survives tab close) so
	// ZipWP counts each build as one attempt. Null on a lone final step; the field is then
	// omitted (ZipWP accepts it as nullable).
	const funnelSessionId = getFunnelSessionId( { isFinalStep } );

	try {
		await apiFetch( {
			path: 'zipwp/v1/record-step',
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify( {
				action: 'next-step',
				current_step: stepNumber,
				current_step_name: slug,
				...( funnelSessionId && {
					funnel_session_id: funnelSessionId,
				} ),
			} ),
		} );
	} catch ( error ) {
		console.error( 'Error recording wizard step:', error );
	}

	if ( isFinalStep ) {
		markFunnelSessionCompleted();
	}
};

export const useNavigateSteps = () => {
	const routerState = useRouterState(),
		currentLocation = routerState.location.pathname;
	const navigate = useNavigate();

	const currentStepIndex = steps.findIndex(
		( step ) => step?.path === currentLocation
	);
	let previousStepURL = '',
		nextStepURL = '',
		currentStepURL = '';
	if ( currentStepIndex !== -1 ) {
		currentStepURL = steps[ currentStepIndex ]?.path ?? '';
		previousStepURL = steps[ currentStepIndex - 1 ]?.path ?? '';
		nextStepURL = steps[ currentStepIndex + 1 ]?.path ?? '';
	}

	const nextStep = ( from = '' ) => {
		const firstStep = steps[ 0 ]?.path;

		navigate( {
			...( !! from && { from } ),
			to: `/${ nextStepURL || firstStep }`,
			state: { from: currentStepURL },
		} );

		stepNextButtonClick( {
			stepNumber: steps[ currentStepIndex ]?.layoutConfig?.stepNumber,
			slug: steps[ currentStepIndex ]?.layoutConfig?.stepSlug,
		} );
	};

	const previousStep = ( from = '' ) => {
		navigate( {
			...( !! from && { from } ),
			to: `/${ previousStepURL }`,
			state: { from: currentStepURL },
		} );
	};

	return Object.seal( {
		currentStepURL,
		previousStepURL,
		nextStepURL,
		nextStep,
		previousStep,
		navigateTo: navigate,
		currentStepIndex,
	} );
};

export const useValidateStep = ( currentStepURL ) => {
	const stepData = useSelect( ( select ) => {
		const { getAIStepData, getWebsiteInfo } = select( STORE_KEY );
		return {
			...getAIStepData(),
			websiteInfo: getWebsiteInfo(),
		};
	}, [] );
	const stepIndex = steps.findIndex(
		( step ) => step?.path === currentStepURL
	);

	if ( stepIndex === -1 ) {
		return '';
	}

	return steps.reduce( ( acc, step, indx ) => {
		if ( indx > stepIndex || acc ) {
			return acc;
		}

		if (
			! step?.requiredStates?.every( ( state ) => {
				const stateValue = stepData?.[ state ],
					valueType = typeof stateValue;

				if ( valueType === 'string' && !! stateValue.trim() ) {
					return true;
				} else if (
					valueType === 'object' &&
					Object.values( stateValue ).length
				) {
					return true;
				}
				return false;
			} )
		) {
			return steps[ indx ].path;
		}

		return acc;
	}, '' );
};
