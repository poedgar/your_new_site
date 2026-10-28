/**
 * Funnel session tracking for Build-with-AI step records.
 *
 * ZipWP groups `record-step` calls into one build attempt by `funnel_session_id`.
 * The session lives in a cookie with a sliding 24-hour expiry, refreshed on
 * every step record. That gives it these characteristics:
 *
 * - It survives reloads, in-app navigation and closing the tab, so a wizard
 *   resumed via "Continue progress" keeps reporting under the original attempt.
 * - The browser enforces the expiry, so an id left over from a build abandoned
 *   more than a day ago can never be stitched onto a fresh one.
 * - It is shared across tabs. Two genuinely concurrent builds in one browser
 *   would be merged into one attempt; that trade is accepted because the wizard's
 *   own local-storage state is already shared the same way, whereas resume after
 *   a tab close is a flow the wizard actively offers.
 *
 * The id is rotated when a new build starts after a completed one, and cleared
 * explicitly when the user chooses "Start over". When ZipWP hands the user back
 * to the wizard with a `funnel_session_id` query param (signup-first flow), that
 * id is adopted so the cross-domain journey is stitched into one attempt.
 */

import { deleteCookie, getCookie, setCookie } from './helpers';
import { FUNNEL_SESSION_COOKIE, FUNNEL_SESSION_TTL_SECONDS } from './constants';

/**
 * Generate an RFC 4122 version 4 UUID.
 *
 * Uses `crypto.randomUUID()` when available and falls back to
 * `crypto.getRandomValues()` in insecure (http) contexts where
 * `randomUUID` is not exposed by the browser.
 *
 * @return {string} A v4 UUID.
 */
export const generateUuidV4 = () => {
	const cryptoAPI = window.crypto;

	if ( typeof cryptoAPI?.randomUUID === 'function' ) {
		return cryptoAPI.randomUUID();
	}

	const bytes = new Uint8Array( 16 );
	if ( typeof cryptoAPI?.getRandomValues === 'function' ) {
		cryptoAPI.getRandomValues( bytes );
	} else {
		for ( let i = 0; i < bytes.length; i++ ) {
			bytes[ i ] = Math.floor( Math.random() * 256 );
		}
	}

	// Set the version (4) and variant (RFC 4122) bits.
	// Written with modulo/addition instead of bit masks to satisfy the no-bitwise lint rule:
	// keep the low 4 bits and set the high nibble to 0100 (version 4).
	bytes[ 6 ] = ( bytes[ 6 ] % 16 ) + 64;
	// keep the low 6 bits and set the top two bits to 10 (RFC 4122 variant).
	bytes[ 8 ] = ( bytes[ 8 ] % 64 ) + 128;

	const hex = Array.from( bytes, ( byte ) =>
		byte.toString( 16 ).padStart( 2, '0' )
	).join( '' );

	return [
		hex.slice( 0, 8 ),
		hex.slice( 8, 12 ),
		hex.slice( 12, 16 ),
		hex.slice( 16, 20 ),
		hex.slice( 20 ),
	].join( '-' );
};

// Same shape wp_is_uuid() accepts server-side (any RFC 4122 version, lower-case hex).
const UUID_PATTERN =
	/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/**
 * Whether a value is a well-formed UUID string.
 *
 * @param {*} value The value to check.
 * @return {boolean} True when the value is a UUID.
 */
export const isUuid = ( value ) =>
	typeof value === 'string' && UUID_PATTERN.test( value.toLowerCase() );

/**
 * Read the stored funnel session, if any.
 *
 * @return {{id: string, completed: boolean}|null} The stored session or null.
 */
const readFunnelSession = () => {
	try {
		// getCookie() percent-decodes and can throw on a malformed value.
		const raw = getCookie( FUNNEL_SESSION_COOKIE );
		if ( ! raw ) {
			return null;
		}

		const session = JSON.parse( raw );
		// Normalise on read so callers never see mixed case, whatever wrote the cookie.
		return session && isUuid( session.id )
			? { id: session.id.toLowerCase(), completed: !! session.completed }
			: null;
	} catch ( error ) {
		return null;
	}
};

/**
 * Persist the funnel session, refreshing its sliding expiry.
 *
 * @param {{id: string, completed: boolean}} session The session to store.
 */
const writeFunnelSession = ( session ) => {
	setCookie(
		FUNNEL_SESSION_COOKIE,
		encodeURIComponent( JSON.stringify( session ) ),
		FUNNEL_SESSION_TTL_SECONDS
	);
};

/**
 * Get the funnel session id for the current wizard attempt.
 *
 * The id is a UUID generated once per wizard session and kept in a cookie so
 * it survives reloads and tab closes. A fresh id is generated when none exists
 * yet, or when the previous attempt has already completed and a non-final step
 * is recorded (i.e. a new build has started).
 * Repeated final-step records reuse the completed attempt's id so the same
 * build is never split into two attempts.
 *
 * A final step recorded with no session at all (cookie expired or cleared
 * mid-build) returns null rather than minting an id, since a lone final step
 * would otherwise show up in ZipWP as a one-step attempt.
 *
 * @param {Object}  options
 * @param {boolean} options.isFinalStep Whether the step being recorded is the final one.
 * @return {string|null} The funnel session id, or null when none should be sent.
 */
export const getFunnelSessionId = ( { isFinalStep = false } = {} ) => {
	const session = readFunnelSession();

	if ( session && ( ! session.completed || isFinalStep ) ) {
		// Touch the cookie so the expiry slides with activity.
		writeFunnelSession( session );
		return session.id;
	}

	if ( isFinalStep && ! session ) {
		return null;
	}

	const id = generateUuidV4();
	writeFunnelSession( { id, completed: false } );

	return id;
};

/**
 * Adopt a funnel session id handed to the wizard from outside.
 *
 * ZipWP mints an id during its signup flow and passes it back through the
 * hand-off URL so the signup steps and the wizard steps land as a single
 * attempt. A signup-first user cannot have recorded steps in the current build
 * yet, so any stored session that is still in progress belongs to a build the
 * user is mid-way through (e.g. returning from a switch-team or reconnect
 * hand-off). That session is kept and the inbound id ignored, so an existing
 * attempt is never orphaned. Completed or absent sessions are replaced.
 *
 * @param {string} id The inbound funnel session id.
 * @return {boolean} True when the id was valid and adopted.
 */
export const adoptFunnelSessionId = ( id ) => {
	if ( ! isUuid( id ) ) {
		return false;
	}

	const current = readFunnelSession();
	if ( current && ! current.completed ) {
		return false;
	}

	writeFunnelSession( { id: id.toLowerCase(), completed: false } );

	return true;
};

/**
 * Clear the current funnel session.
 *
 * Used when the user explicitly starts over, so the abandoned build and the
 * new one are not reported to ZipWP as a single attempt.
 */
export const clearFunnelSession = () => {
	deleteCookie( FUNNEL_SESSION_COOKIE );
};

/**
 * Mark the current funnel session as completed.
 *
 * The id is kept so that repeated final-step records stay attached to the
 * same attempt; the next non-final step record rotates to a fresh id.
 */
export const markFunnelSessionCompleted = () => {
	const session = readFunnelSession();

	if ( ! session ) {
		return;
	}

	writeFunnelSession( { ...session, completed: true } );
};
