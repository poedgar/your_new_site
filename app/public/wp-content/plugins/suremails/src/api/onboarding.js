import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Set onboarding completion status.
 *
 * @since 0.0.1
 * @param {Object}  payload         Completion payload.
 * @param {boolean} payload.skipped Whether any onboarding step was skipped.
 * @return {Promise<Object>} The response containing the onboarding completion status.
 */
export const setOnboardingCompletionStatus = async ( payload = {} ) => {
	try {
		const response = await apiFetch( {
			path: '/suremails/v1/onboarding/set-status',
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': suremails.nonce,
			},
			body: JSON.stringify( payload ),
		} );

		if ( typeof response !== 'object' ) {
			throw new Error( __( 'Invalid JSON response', 'suremails' ) );
		}

		return response;
	} catch ( error ) {
		throw new Error(
			error.message ||
				__( 'Error setting onboarding completion status', 'suremails' )
		);
	}
};
