const SURECONTACT_KEY = 'SURECONTACT';

// SureContact flow: Welcome → Provider → Connect → Test → Done
const SURECONTACT_ROUTES = [
	{ url: '/onboarding/welcome', index: true },
	{ url: '/onboarding/connection' },
	{ url: '/onboarding/connect' },
	{ url: '/onboarding/test' },
	{ url: '/onboarding/done' },
];

// Other providers flow: Welcome → Provider (inline credentials) → Reputation Shield → Done
const OTHER_PROVIDER_ROUTES = [
	{ url: '/onboarding/welcome', index: true },
	{ url: '/onboarding/connection' },
	{ url: '/onboarding/reputation-shield' },
	{ url: '/onboarding/done' },
];

const isSureContactSelection = ( state ) => {
	const selected = state?.connection || state?.connectionSaved || '';
	return ! selected || selected === SURECONTACT_KEY;
};

export const getVisibleOnboardingRoutes = ( onboardingState = {} ) => {
	if ( isSureContactSelection( onboardingState ) ) {
		return SURECONTACT_ROUTES;
	}
	return OTHER_PROVIDER_ROUTES;
};

/**
 * Returns the progress-bar metadata for the topbar.
 *
 * The progress dots are not 1:1 with navigation routes:
 *   • SureContact path shows 4 dots (Welcome / Provider / Connect / Test);
 *     the Done page is a celebration and renders all dots as complete.
 *   • Other-provider path shows 5 dots (Welcome / Provider / Connect /
 *     Reputation / Done). The "Connect" dot represents the inline
 *     credentials form that lives on the Provider screen — it becomes
 *     the current step once a provider is selected but not yet saved.
 *
 * @param {Object} state       The onboarding context state.
 * @param {string} currentPath The current router pathname.
 * @return {{ totalSteps: number, currentStep: number }} Progress meta for the topbar.
 */
export const getProgressMeta = ( state = {}, currentPath = '' ) => {
	if ( isSureContactSelection( state ) ) {
		const totalSteps = 4; // Welcome / Provider / Connect / Test
		const map = {
			'/onboarding/welcome': 1,
			'/onboarding/connection': 2,
			'/onboarding/connect': 3,
			'/onboarding/test': 4,
			// On Done, overshoot the count so every dot renders as completed.
			'/onboarding/done': totalSteps + 1,
		};
		return {
			totalSteps,
			currentStep: map[ currentPath ] ?? 1,
		};
	}

	const totalSteps = 5; // Welcome / Provider / Credentials / Reputation / Done
	const credentialsShown =
		state?.connection &&
		state?.connection !== SURECONTACT_KEY &&
		state?.connectionSaved !== state?.connection;

	let currentStep = 1;
	switch ( currentPath ) {
		case '/onboarding/welcome':
			currentStep = 1;
			break;
		case '/onboarding/connection':
			currentStep = credentialsShown ? 3 : 2;
			break;
		case '/onboarding/reputation-shield':
			currentStep = 4;
			break;
		case '/onboarding/done':
			currentStep = totalSteps + 1;
			break;
		default:
			currentStep = 1;
	}

	return {
		totalSteps,
		currentStep,
	};
};

// Default export includes all possible routes (used for route registration)
const ALL_ONBOARDING_ROUTES = [
	{ url: '/onboarding/welcome', index: true },
	{ url: '/onboarding/connection' },
	{ url: '/onboarding/connect' },
	{ url: '/onboarding/test' },
	{ url: '/onboarding/reputation-shield' },
	{ url: '/onboarding/done' },
];

export default ALL_ONBOARDING_ROUTES;
