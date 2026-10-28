/**
 * Get the pricing link for the SureRank plugin.
 *
 * @param {string} utmContent - Placement identifier for utm_content. Required for accurate attribution.
 * @return {string} The pricing link URL.
 */
export const getPricingLink = ( utmContent = '' ) => {
	const pricingLink = window?.surerank_globals?.pricing_link;

	if ( ! pricingLink ) {
		return '';
	}

	try {
		const parsed = new URL( pricingLink );
		parsed.searchParams.set( 'utm_source', 'surerank_plugin' );
		parsed.searchParams.set( 'utm_medium', 'in_product' );
		parsed.searchParams.set( 'utm_campaign', 'core_plugin' );
		if ( utmContent ) {
			parsed.searchParams.set( 'utm_content', utmContent );
		}
		return parsed.toString();
	} catch ( error ) {
		return pricingLink;
	}
};

/**
 * Redirect to the pricing page for the SureRank plugin.
 *
 * @param {string} utmContent - Placement identifier for utm_content.
 */
export const redirectToPricingPage = ( utmContent ) => {
	const pricingLink = getPricingLink( utmContent );
	window.open( pricingLink, '_blank', 'noopener,noreferrer' );
};

/**
 * Get the current active plan.
 *
 * @return {string|null} The active plan slug (e.g., 'surerank-starter', 'surerank-pro', 'surerank-business') or null if no plan is active.
 */
export const getActivePlan = () => {
	return window?.surerank_globals?.active_plan || null;
};

/**
 * Plan hierarchy mapping - higher number means higher tier
 */
const PLAN_HIERARCHY = {
	'surerank-starter': 1,
	'surerank-pro': 2,
	'surerank-business': 3,
};

/**
 * Normalize plan name to standard format
 *
 * @param {string} plan - The plan name (e.g., 'pro', 'surerank-pro', 'business')
 * @return {string} Normalized plan name (e.g., 'surerank-pro')
 */
const normalizePlanName = ( plan ) => {
	if ( ! plan ) {
		return '';
	}

	if ( plan.startsWith( 'surerank-' ) ) {
		return plan;
	}

	return `surerank-${ plan }`;
};

/**
 * Check if the current plan should see an upgrade nudge for a specific feature.
 *
 * @param {string} requiredPlan - The minimum plan required for the feature (e.g., 'pro', 'surerank-pro', 'business')
 * @return {boolean} True if the nudge should be shown, false otherwise.
 */
export const isProActive = ( requiredPlan = null ) => {
	if ( ! requiredPlan ) {
		return window?.surerank_globals?.is_pro_active;
	}

	const activePlan = getActivePlan();

	if ( ! activePlan ) {
		return false;
	}

	const normalizedRequired = normalizePlanName( requiredPlan );
	const normalizedActive = normalizePlanName( activePlan );

	const requiredLevel = PLAN_HIERARCHY[ normalizedRequired ] || 0;
	const activeLevel = PLAN_HIERARCHY[ normalizedActive ] || 0;
	return activeLevel >= requiredLevel;
};
