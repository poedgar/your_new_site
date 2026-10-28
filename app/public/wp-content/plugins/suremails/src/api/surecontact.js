// @api/surecontact.js
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Provision a new SureContact account from inside WordPress and persist the
 * returned credentials as a SureMails connection.
 *
 * @param {Object} payload
 * @param {string} payload.name    Display name supplied by the user.
 * @param {string} payload.email   Email supplied by the user.
 * @param {string} payload.website Site URL — defaults to home_url() server-side.
 * @return {Promise<Object>} { success, message, connection? }
 */
export const provisionSureContact = async ( { name, email, website } ) => {
	if ( ! email ) {
		throw new Error(
			__( 'Email is required to provision SureContact.', 'suremails' )
		);
	}

	try {
		return await apiFetch( {
			path: '/suremails/v1/surecontact/provision',
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': suremails.nonce,
			},
			body: JSON.stringify( { name, email, website } ),
		} );
	} catch ( error ) {
		throw new Error(
			error.message ||
				__(
					'There was an issue connecting to SureContact.',
					'suremails'
				)
		);
	}
};

/**
 * Trigger a verification-email resend for a saved SureContact connection.
 *
 * @param {string} connectionId The local connection ID.
 * @return {Promise<Object>}    { success, message, retry_after?, email_verified? }
 */
export const resendVerification = async ( connectionId ) => {
	if ( ! connectionId ) {
		throw new Error( __( 'Missing connection identifier.', 'suremails' ) );
	}

	try {
		return await apiFetch( {
			path: '/suremails/v1/surecontact/resend-verification',
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': suremails.nonce,
			},
			body: JSON.stringify( { connection_id: connectionId } ),
		} );
	} catch ( error ) {
		throw new Error(
			error.message ||
				__(
					'There was an issue resending the verification email.',
					'suremails'
				)
		);
	}
};

/**
 * Fetch the workspace's verified sending domains for the "add sender" picker.
 *
 * @param {string} connectionId The local connection ID.
 * @return {Promise<Object>}    { success, domains: [{ uuid, domain }] }
 */
export const getSureContactSendingDomains = async ( connectionId ) => {
	if ( ! connectionId ) {
		throw new Error( __( 'Missing connection identifier.', 'suremails' ) );
	}

	try {
		return await apiFetch( {
			path: `/suremails/v1/surecontact/sending-domains?connection_id=${ encodeURIComponent(
				connectionId
			) }`,
			method: 'GET',
			headers: {
				'X-WP-Nonce': suremails.nonce,
			},
		} );
	} catch ( error ) {
		throw new Error(
			error.message ||
				__(
					'There was an issue fetching the SureContact sending domains.',
					'suremails'
				)
		);
	}
};

/**
 * Refresh the SureContact-side status (verification + cap) for a connection.
 *
 * @param {string} connectionId The local connection ID.
 * @return {Promise<Object>}    { success, email_verified, is_paid, cap }
 */
export const getSureContactStatus = async ( connectionId ) => {
	if ( ! connectionId ) {
		throw new Error( __( 'Missing connection identifier.', 'suremails' ) );
	}

	try {
		return await apiFetch( {
			path: `/suremails/v1/surecontact/status?connection_id=${ encodeURIComponent(
				connectionId
			) }`,
			method: 'GET',
			headers: {
				'X-WP-Nonce': suremails.nonce,
			},
		} );
	} catch ( error ) {
		throw new Error(
			error.message ||
				__(
					'There was an issue fetching the SureContact status.',
					'suremails'
				)
		);
	}
};

/**
 * Fetch the SMTP plans + email-credit packs shown in the upgrade modal.
 *
 * Proxies SureContact's `/smtp-plans` pricing endpoint through the plugin so the
 * bearer key stays server-side. Returns the payload as-is — { success, header,
 * plans, credits } — which the modal consumes directly.
 *
 * @param {string} connectionId The local connection ID.
 * @return {Promise<Object>}    { success, header, plans, credits }
 */
export const getSureContactPlans = async ( connectionId ) => {
	if ( ! connectionId ) {
		throw new Error( __( 'Missing connection identifier.', 'suremails' ) );
	}

	try {
		return await apiFetch( {
			path: `/suremails/v1/surecontact/plans?connection_id=${ encodeURIComponent(
				connectionId
			) }`,
			method: 'GET',
			headers: {
				'X-WP-Nonce': suremails.nonce,
			},
		} );
	} catch ( error ) {
		throw new Error(
			error.message ||
				__(
					'There was an issue fetching the SureContact plans.',
					'suremails'
				)
		);
	}
};

/**
 * Dismiss the SureContact cross-sell promo banner for 15 days. Fire-and-forget:
 * the banner hides locally regardless of the request outcome.
 *
 * @return {Promise<Object>} { success, message }
 */
export const dismissSureContactPromo = async () => {
	return apiFetch( {
		path: '/suremails/v1/disable-surecontact-promo',
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': suremails.nonce,
		},
	} ).catch( () => {} );
};

/**
 * Dismiss the "SureContact SMTP is live" launch promo banner for 15 days.
 * Fire-and-forget: the banner hides locally regardless of the request outcome.
 *
 * @return {Promise<Object>} { success, message }
 */
export const dismissSureContactSmtpPromo = async () => {
	return apiFetch( {
		path: '/suremails/v1/disable-surecontact-smtp-promo',
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': suremails.nonce,
		},
	} ).catch( () => {} );
};
