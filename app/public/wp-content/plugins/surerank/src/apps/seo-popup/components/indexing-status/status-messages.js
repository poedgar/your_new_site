/**
 * Indexing-status error mapping.
 *
 * Pure helpers that turn an error code — an HTTP status (number or numeric
 * string) returned by the Search Console URL Inspection API, a WordPress
 * transport code, or one of SureRank's own string codes — into the badge
 * label and tooltip text. Kept free of UI/store imports so they can be unit
 * tested in isolation and reused by the IndexingStatus component.
 */

import { __ } from '@wordpress/i18n';

// HTTP codes can arrive as a number (REST) or a numeric string (AJAX
// fallback / query transports); normalize before range checks.
export const toHttpStatus = ( code ) =>
	typeof code === 'number' ? code : parseInt( code, 10 );

/**
 * Full sentence shown in the tooltip for a given error code.
 *
 * @param {number|string} code Error code.
 * @return {string} Human-readable message.
 */
export const errorMessageFor = ( code ) => {
	switch ( code ) {
		case 'RESOURCE_EXHAUSTED':
		case 429:
		case '429':
			return __(
				'Search Console quota reached. Try again later.',
				'surerank'
			);
		case 'forbidden':
		case 'forbidden_object':
		case 'unauthorized':
		case 401:
		case '401':
		case 403:
		case '403':
			return __(
				'You do not have permission to view this indexing status.',
				'surerank'
			);
		case 'not_found':
		case 404:
		case '404':
			return __(
				'The URL was not found in this Search Console property.',
				'surerank'
			);
		case 'surerank_timeout':
			return __(
				'The request took too long to complete. Please try again.',
				'surerank'
			);
		case 'surerank_network_error':
		case 'http_request_failed':
			return __(
				'Could not reach Search Console. Check your connection and try again.',
				'surerank'
			);
		case 'surerank_invalid_json_response':
		case 'invalid_response':
			return __(
				'Received an unexpected response from Search Console. Please try again.',
				'surerank'
			);
		case 'no_permalink':
			return __(
				'Save the post as published to inspect its URL.',
				'surerank'
			);
		case 'no_site_selected':
			return __(
				'Connect a Search Console property to see indexing status.',
				'surerank'
			);
		case 'site_mismatch':
			return __(
				'The connected Search Console property does not match this site.',
				'surerank'
			);
		default: {
			const httpStatus = toHttpStatus( code );
			if ( httpStatus >= 500 && httpStatus <= 599 ) {
				return __(
					'Search Console is temporarily unavailable. Try again later.',
					'surerank'
				);
			}
			return __( 'Unable to fetch indexing status.', 'surerank' );
		}
	}
};

/**
 * Short pill label per error category. The full sentence lives in the tooltip
 * via errorMessageFor(); this keeps the badge scannable while still surfacing
 * the real failure state (never "Checking…").
 *
 * @param {number|string} code Error code.
 * @return {string} Short badge label.
 */
export const errorLabelFor = ( code ) => {
	switch ( code ) {
		case 'RESOURCE_EXHAUSTED':
		case 429:
		case '429':
			return __( 'Rate limited', 'surerank' );
		case 'forbidden':
		case 'forbidden_object':
		case 'unauthorized':
		case 401:
		case '401':
		case 403:
		case '403':
			return __( 'Access denied', 'surerank' );
		case 'not_found':
		case 404:
		case '404':
			return __( 'Not found', 'surerank' );
		case 'no_permalink':
			return __( 'Not published', 'surerank' );
		case 'no_site_selected':
		case 'site_mismatch':
			return __( 'Not connected', 'surerank' );
		default: {
			const httpStatus = toHttpStatus( code );
			if ( httpStatus >= 500 && httpStatus <= 599 ) {
				return __( 'Server error', 'surerank' );
			}
			return __( 'Request failed', 'surerank' );
		}
	}
};
