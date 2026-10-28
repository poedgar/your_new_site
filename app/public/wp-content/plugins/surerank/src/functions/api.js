import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import {
	TERM_SEO_DATA_URL,
	POST_SEO_DATA_URL,
	USER_SEO_DATA_URL,
} from '@Global/constants/api';
import { isCurrentPage } from '@/functions/utils';

export const API_BASE_URL = '/surerank/v1';

export const fetchMetaSettings = async () => {
	const queryParams = {};
	const isUser = !! surerank_seo_popup?.is_user;
	const isTerm =
		! isUser &&
		( isCurrentPage( 'term.php' ) || !! surerank_seo_popup.is_taxonomy );
	if ( isUser ) {
		queryParams.user_id = surerank_seo_popup?.user_id;
	} else if ( isTerm ) {
		queryParams.term_id = surerank_seo_popup?.term_id;
	} else {
		queryParams.post_id = surerank_seo_popup?.post_id;
	}
	if ( ! isUser ) {
		queryParams.post_type = surerank_seo_popup?.post_type;
		queryParams.is_taxonomy = surerank_seo_popup?.is_taxonomy;
	}

	let dataUrl = POST_SEO_DATA_URL;
	if ( isUser ) {
		dataUrl = USER_SEO_DATA_URL;
	} else if ( isTerm ) {
		dataUrl = TERM_SEO_DATA_URL;
	}

	try {
		const response = await apiFetch( {
			path: addQueryArgs( dataUrl, queryParams ),
			method: 'GET',
		} );
		if ( ! response.success ) {
			throw new Error( response.message );
		}
		return response;
	} catch ( error ) {
		throw new Error( error.message );
	}
};

/**
 * Fetch image data by URL.
 *
 * @param {string} imageUrl The URL of the image to fetch.
 * @return {Promise} A promise that resolves to the image data.
 */
export const fetchImageDataByUrl = async ( imageUrl ) => {
	if ( ! imageUrl ) {
		return null;
	}

	// Extract filename variations for better search
	const url = new URL( imageUrl );
	const pathname = url.pathname;
	const filename = pathname.split( '/' ).pop().split( '?' )[ 0 ];

	// Remove common optimization suffixes (webp, scaled, etc.)
	const baseFilename = filename
		.replace( /-\d+x\d+\.(jpg|jpeg|png|gif|webp)$/i, '' ) // Remove dimension suffixes
		.replace( /-scaled\.(jpg|jpeg|png|gif|webp)$/i, '' ) // Remove -scaled suffix
		.replace( /\.(webp)$/i, '' ) // Remove .webp extension
		.replace( /(-optimized|-compressed)/i, '' ); // Remove optimization keywords

	// Try multiple search strategies
	const searchStrategies = [
		// 1. Search by exact filename
		{ search: filename },
		// 2. Search by base filename without optimization suffixes
		{ search: baseFilename },
		// 3. Search by filename without extension
		{ search: filename.replace( /\.[^/.]+$/, '' ) },
		// 4. Search by base filename without extension
		{ search: baseFilename.replace( /\.[^/.]+$/, '' ) },
	];

	for ( const strategy of searchStrategies ) {
		try {
			const response = await apiFetch( {
				path: addQueryArgs( '/wp/v2/media', {
					search: strategy.search,
					media_type: 'image',
					slug: strategy.search, // Use slug for better matching
					per_page: 20, // Increase results to find better matches
				} ),
				method: 'GET',
			} );

			if ( response && response.length > 0 ) {
				// Try to find exact match first
				const exactMatch = response.find( ( media ) => {
					const mediaUrl = media.source_url || media.url;
					const mediaFilename = mediaUrl
						.split( '/' )
						.pop()
						.split( '?' )[ 0 ];
					return (
						mediaFilename === filename ||
						mediaUrl.includes( baseFilename ) ||
						mediaFilename.includes( baseFilename )
					);
				} );

				if ( exactMatch ) {
					return exactMatch;
				}

				// Return first result if no exact match
				return response[ 0 ];
			}
		} catch ( error ) {
			continue;
		}
	}

	// If all strategies fail, try a broader search using just the base name
	try {
		const broadSearch = baseFilename.split( '-' )[ 0 ]; // Get first part before any dashes
		const response = await apiFetch( {
			path: addQueryArgs( '/wp/v2/media', {
				search: broadSearch,
				media_type: 'image',
				per_page: 50,
			} ),
			method: 'GET',
		} );

		if ( response && response.length > 0 ) {
			return response[ 0 ];
		}
	} catch ( error ) {
		// eslint-disable-next-line no-console
		console.warn( 'Broad search failed', error );
	}

	return null;
};

/**
 * Fetch pages from the custom posts-list API
 * This searches only by page title (not content) for more accurate results
 *
 * @param {string} search - Search query
 * @return {Promise<Array>} Array of page objects with label and value
 */
export const fetchPages = async ( search = '' ) => {
	try {
		const response = await apiFetch( {
			path: `/surerank/v1/posts-list?post_type=page&per_page=200${
				search ? `&search=${ encodeURIComponent( search ) }` : ''
			}`,
			method: 'GET',
		} );

		// Response is already in {label, value} format from the backend
		return response;
	} catch ( error ) {
		return [];
	}
};

/**
 * Get migrated data for onboarding steps after migration done successfully.
 *
 * @return {Promise<Object>} A promise that resolves to the migrated data.
 */
export const getMigratedData = () => {
	return apiFetch( {
		path: `${ API_BASE_URL }/migration/migrated-data`,
		method: 'GET',
	} );
};

/**
 * Fetch AI authentication status
 * @return {Promise<Object>} The authentication status
 */
export const getAuth = () => {
	return apiFetch( { path: `${ API_BASE_URL }/ai/auth` } );
};

/**
 * Fetch bulk content generation batch status
 * @return {Promise<Object>} The batch status
 */
export const batchStatus = () => {
	return apiFetch( {
		path: `${ API_BASE_URL }/content-generation/batch-status`,
	} );
};

/**
 * Save AI access token
 * @param {string} accessKey The access token
 * @return {Promise<Object>} The response from the API
 */
export const saveAuthAccessToken = ( accessKey ) => {
	return apiFetch( {
		path: `${ API_BASE_URL }/ai/auth`,
		method: 'POST',
		data: { accessKey },
	} );
};

/**
 * Disconnect SureRank AI
 * @return {Promise<Object>} The response from the API
 */
export const disconnectAuth = () => {
	return apiFetch( {
		path: `${ API_BASE_URL }/ai/auth`,
		method: 'DELETE',
	} );
};

/**
 * Get combined SureRank AI usage
 * @return {Promise<Object>} The usage payload { usage: { percent, features } }
 */
export const getAIUsage = () => {
	return apiFetch( { path: `${ API_BASE_URL }/ai/usage` } );
};

/**
 * Generate content
 * @param {string} type         - The type of content to generate.
 * @param {string} [postId]     - The optional post ID.
 * @param {string} [isTaxonomy] - The optional taxonomy flag.
 */
export const generateContent = ( type, postId, isTaxonomy ) => {
	const data = { type };
	if ( postId ) {
		data.post_id = postId;
	}
	if ( isTaxonomy ) {
		data.is_taxonomy = isTaxonomy;
	}
	return apiFetch( {
		path: `${ API_BASE_URL }/generate-content`,
		method: 'POST',
		data,
	} );
};

const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

const requestWithTimeout = async ( fetchOptions, timeoutMs ) => {
	const abortController = new AbortController();
	let timerId = null;

	try {
		timerId = setTimeout( () => {
			abortController.abort();
		}, timeoutMs );

		return await apiFetch( {
			...fetchOptions,
			signal: abortController.signal,
		} );
	} catch ( error ) {
		if ( error?.name === 'AbortError' ) {
			const timeoutError = new Error( 'request_timeout' );
			timeoutError.code = 'request_timeout';
			throw timeoutError;
		}
		throw error;
	} finally {
		if ( timerId ) {
			clearTimeout( timerId );
		}
	}
};

/**
 * Recommend schema types for a page/post using AI.
 *
 * @param {Object} params              Request payload.
 * @param {string} params.post_type    Post type.
 * @param {string} params.post_title   Post title.
 * @param {string} params.post_content Post content.
 * @return {Promise<Object>} API response with recommendations.
 */
export const recommendSchemas = async ( params ) => {
	let attempts = 0;
	let lastError;

	while ( attempts < 2 ) {
		try {
			const response = await requestWithTimeout(
				{
					path: `${ API_BASE_URL }/schemas/generator`,
					method: 'POST',
					data: params,
				},
				25000
			);
			return response;
		} catch ( error ) {
			lastError = error;
			attempts++;

			if ( attempts >= 2 ) {
				break;
			}

			await sleep( 500 );
		}
	}

	throw lastError;
};

/**
 * Track schema recommendation interactions.
 *
 * @param {string} eventKey Event key to track.
 * @return {Promise<Object>} API response.
 */
export const trackSchemaRecommendationEvent = ( eventKey ) =>
	apiFetch( {
		path: `${ API_BASE_URL }/schemas/recommendation-event`,
		method: 'POST',
		data: { event_key: eventKey },
	} );

/**
 * Save email reports settings
 * @param {Object}  settings                - The email reports settings to save.
 * @param {boolean} settings.enabled        - Whether email reports are enabled.
 * @param {string}  settings.recipientEmail - The recipient email address.
 * @param {string}  settings.dayOfWeek      - The day of the week for reports.
 */
export const saveEmailReportsSettings = ( settings ) => {
	return apiFetch( {
		path: `${ API_BASE_URL }/email-reports/report-settings`,
		method: 'POST',
		data: settings,
	} );
};

/**
 * Get email reports settings
 * @return {Promise<Object>} The email reports settings.
 */
export const getEmailReportsSettings = () => {
	return apiFetch( {
		path: `${ API_BASE_URL }/email-reports/report-settings`,
		method: 'GET',
	} );
};

/**
 * Send test email report
 * @param {string} recipientEmail - The recipient email address.
 * @return {Promise<Object>} The response from the API.
 */
export const sendTestEmailReport = ( recipientEmail ) => {
	return apiFetch( {
		path: `${ API_BASE_URL }/email-reports/send-test`,
		method: 'POST',
		data: { recipientEmail },
	} );
};

/**
 * Improve existing content with AI based on a prompt type.
 *
 * @since 1.9.0
 * @param {string}      type             Prompt type (e.g. 'og_image_title').
 * @param {string}      input            Text to improve.
 * @param {Object}      [options]        Optional request options.
 * @param {AbortSignal} [options.signal] AbortController signal to cancel the request.
 * @return {Promise<Object>} Response with improved_text string.
 */
export const improveContent = ( type, input, { signal } = {} ) => {
	const fetchOptions = {
		path: `${ API_BASE_URL }/improve-content`,
		method: 'POST',
		data: { type, input },
	};

	if ( signal ) {
		fetchOptions.signal = signal;
	}

	return apiFetch( fetchOptions );
};
