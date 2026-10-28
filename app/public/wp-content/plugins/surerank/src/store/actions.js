import { pick } from 'lodash';
import { select } from '@wordpress/data';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from './constants';
import * as actionTypes from './action-types';
import { EDITOR_URL } from '@Global/constants/api';
import {
	applyIgnoredBrokenLinks,
	getBrokenLinkItems,
	getCategorizedChecks,
	getCheckTypeKey,
	mergeAllCheckTypes,
} from '@/functions/utils';
import { CHECK_TYPES } from '@/global/constants';
import { startRequest, isLatestRequest } from '@/functions/request-sequence';
/**
 * Returns an action object used in signalling that viewport queries have been
 * updated. Values are specified as an object of breakpoint query keys where
 * value represents whether query matches.
 * Ignored from documentation as it is for internal use only.
 *
 * @param {string} value Value to update.
 */
export function updatePostSeoMeta( value ) {
	return {
		type: 'UPDATE_POST_SEO_META',
		value,
	};
}

export function updateMetaboxState( value ) {
	return {
		type: 'UPDATE_METABOX_STATE',
		value,
	};
}

export function updateModalState( value ) {
	return {
		type: 'UPDATE_MODAL_STATE',
		value,
	};
}

// To create content dynamically.
export function updatePostDynamicData( value ) {
	return {
		type: 'UPDATE_DYNAMIC_DATA',
		value,
	};
}

export function* updatePostMetaData( value ) {
	yield setUnsavedMetaSettings( value );

	return {
		type: actionTypes.UPDATE_META_DATA,
		value,
	};
}

export function updateInitialState( value ) {
	return {
		type: 'UPDATE_INITIAL_STATE',
		value,
	};
}

export const updateGlobalDefaults = ( payload ) => ( {
	type: actionTypes.UPDATE_GLOBAL_DEFAULTS,
	payload,
} );

export function* initMetaDataAndDefaults( { postSeoMeta, globalDefaults } ) {
	let postSeoMetaObj = postSeoMeta;
	if ( postSeoMetaObj && ! Object.keys( postSeoMetaObj ).length ) {
		postSeoMetaObj = select( STORE_NAME ).getPostSeoMeta();
		postSeoMetaObj = pick( globalDefaults, Object.keys( postSeoMetaObj ) );
		yield updatePostMetaData( postSeoMetaObj );
	} else {
		yield updatePostSeoMeta( postSeoMetaObj );
	}
	return updateGlobalDefaults( globalDefaults );
}

export function updateAppSettings( value ) {
	return {
		type: actionTypes.UPDATE_APP_SETTINGS,
		value,
	};
}

export const setPageSeoChecks = ( payload ) => {
	return {
		type: actionTypes.SET_PAGE_SEO_CHECKS,
		payload,
	};
};

export const setPageSeoCheck = ( key, value ) => {
	let payload = { [ key ]: value };

	if ( key === 'checks' ) {
		const state = select( STORE_NAME ).getState();
		const ignoredList = state.pageSeoChecks?.ignoredList || [];

		const categorizedChecks = getCategorizedChecks( value, ignoredList );

		payload = {
			checks: value,
			categorizedChecks,
		};
	} else if ( CHECK_TYPES.includes( key ) ) {
		// Handle any check type dynamically
		const state = select( STORE_NAME ).getState();
		const ignoredList = state.pageSeoChecks?.ignoredList || [];

		const allChecks =
			mergeAllCheckTypes( state, key, value )?.filter( Boolean ) || [];
		const categorizedChecks = getCategorizedChecks(
			allChecks,
			ignoredList
		);
		const categorizedCheckType = getCategorizedChecks( value, ignoredList );
		const storeKeys = getCheckTypeKey( key );

		payload = {
			[ storeKeys.type ]: value,
			checks: allChecks,
			categorizedChecks,
			[ storeKeys.categorizedType ]: categorizedCheckType,
		};
	}

	return {
		type: actionTypes.SET_PAGE_SEO_CHECK,
		payload,
	};
};

export const setUnsavedMetaSettings = ( payload ) => {
	return {
		type: actionTypes.SET_UNSAVED_META_SETTINGS,
		payload,
	};
};

export const resetUnsavedMetaSettings = () => {
	return {
		type: actionTypes.RESET_UNSAVED_META_SETTINGS,
	};
};

/**
 * Marks the submitted meta values as saved, keeping newer edits unsaved.
 *
 * @since x.x.x
 *
 * @param {Object} savedMetaSettings Snapshot of the meta values that were submitted.
 */
export const clearSavedMetaSettings = ( savedMetaSettings ) => {
	return {
		type: actionTypes.CLEAR_SAVED_META_SETTINGS,
		payload: savedMetaSettings,
	};
};

export const setRefreshCalled = ( value ) => ( {
	type: actionTypes.SET_REFRESH_CALLED,
	value,
} );

export const setCurrentPostIgnoredList = ( payload ) => ( {
	type: actionTypes.SET_CURRENT_POST_IGNORED_LIST,
	payload,
} );

export function fetchFromAPI( payload ) {
	return {
		type: actionTypes.FETCH_FROM_API,
		payload,
	};
}

export function* restoreIgnoreCheck( checkId, actionType ) {
	const state = select( STORE_NAME ).getState();
	const postId =
		state.pageSeoChecks?.postId ||
		state.variables?.post?.ID?.value ||
		state.variables?.term?.ID?.value ||
		state.variables?.user?.ID?.value;
	let postType = 'post';
	if ( window?.surerank_seo_popup?.is_user ) {
		postType = 'user';
	} else if ( window?.surerank_seo_popup?.is_taxonomy === '1' ) {
		postType = 'taxonomy';
	}

	try {
		const data = yield fetchFromAPI( {
			path: 'surerank/v1/checks/ignore-page-check',
			method: actionType === 'ignore' ? 'POST' : 'DELETE',
			data: { post_id: postId, id: checkId, check_type: postType },
		} );

		// Update ignoredList with the array of IDs
		yield setCurrentPostIgnoredList( data?.checks );

		const seoChecksState = select( STORE_NAME ).getPageSeoChecks();
		const checkType = seoChecksState.checks.find(
			( check ) => check.id === checkId
		)?.type;
		const storeKey = getCheckTypeKey( checkType )?.type || 'checks';
		yield setPageSeoCheck( checkType, seoChecksState[ storeKey ] );

		// Sync back to seo-bar per-post cache so the badge reflects the change.
		const allChecks = select( STORE_NAME ).getPageSeoChecks()?.checks || [];
		if ( postId && allChecks.length > 0 ) {
			yield setPageSeoChecksByIdAndType( postId, postType, allChecks );
		}
	} catch ( error ) {
		// Silently fail for aborted requests
	}
}

export function* ignorePageSeoCheck( checkId ) {
	yield restoreIgnoreCheck( checkId, 'ignore' );
}

export function* restorePageSeoCheck( checkId ) {
	yield restoreIgnoreCheck( checkId, 'restore' );
}

/**
 * Ignore or restore a broken link URL site-wide and update the
 * broken_links check in the store.
 *
 * @param {string} url        URL to ignore/restore.
 * @param {string} actionType Either 'ignore' or 'restore'.
 */
function* updateBrokenLinkIgnoreState( url, actionType ) {
	const state = select( STORE_NAME ).getState();
	const isTaxonomy = window?.surerank_seo_popup?.is_taxonomy === '1';
	const postId = isTaxonomy
		? 0
		: state.pageSeoChecks?.postId || state.variables?.post?.ID?.value || 0;

	// Stored broken links must be passed along so the backend keeps the
	// other entries intact when reconciling the post meta. The check data can
	// be flat (block editor) or nested (listing / page builder), so normalize
	// it before reading the URLs.
	const getBrokenUrls = () =>
		getBrokenLinkItems(
			select( STORE_NAME )
				.getPageSeoChecks()
				?.pageChecks?.find( ( check ) => check?.id === 'broken_links' )
				?.data
		)
			.map( ( item ) => item?.url )
			.filter( Boolean );

	try {
		yield fetchFromAPI( {
			path: 'surerank/v1/checks/broken-link-ignore',
			method: actionType === 'ignore' ? 'POST' : 'DELETE',
			data:
				actionType === 'ignore'
					? { url, post_id: postId, urls: getBrokenUrls() }
					: { url },
		} );

		// On restore, immediately re-check the URL so it returns to the
		// broken list right away when it is still broken.
		let recheckResult = null;
		if ( actionType === 'restore' && postId ) {
			recheckResult = yield fetchFromAPI( {
				path: 'surerank/v1/checks/broken-link',
				method: 'POST',
				data: {
					url,
					post_id: postId,
					urls: [ ...new Set( [ ...getBrokenUrls(), url ] ) ],
					user_agent: window.navigator.userAgent,
				},
			} );
		}

		// Re-read the checks after the requests resolve so a concurrent
		// analyze cycle is not clobbered with stale data.
		const pageChecks =
			select( STORE_NAME ).getPageSeoChecks()?.pageChecks || [];

		const updatedPageChecks = pageChecks.map( ( check ) => {
			if ( check?.id !== 'broken_links' ) {
				return check;
			}

			const ignoredBrokenLinks = check?.ignoredBrokenLinks || [];
			// Normalize to a flat list of objects so the URL filter works for
			// both the block-editor and listing / page-builder data shapes.
			const brokenItems = getBrokenLinkItems( check?.data );

			if ( actionType === 'ignore' ) {
				const data = brokenItems.filter(
					( item ) => item?.url !== url
				);

				return {
					...check,
					data,
					ignoredBrokenLinks: [
						...new Set( [ ...ignoredBrokenLinks, url ] ),
					],
					...( data.length === 0 && {
						status: 'success',
						title: __(
							'No broken links found on the page.',
							'surerank'
						),
					} ),
				};
			}

			// Restore: re-add the URL to the broken list when the live
			// re-check confirms it is still broken.
			const existingData = brokenItems;
			const stillBroken =
				recheckResult &&
				recheckResult?.success !== true &&
				recheckResult?.verified !== false &&
				! existingData.some( ( item ) => item?.url === url );
			const data = stillBroken
				? [
						...existingData,
						{
							url,
							status: recheckResult?.status ?? 'error',
							details: recheckResult?.details ?? '',
						},
				  ]
				: existingData;

			return {
				...check,
				data,
				ignoredBrokenLinks: ignoredBrokenLinks.filter(
					( ignoredUrl ) => ignoredUrl !== url
				),
				...( data.length > 0 && {
					status: 'error',
					title: __(
						'One or more broken links found on the page.',
						'surerank'
					),
				} ),
			};
		} );

		yield setPageSeoCheck( 'page', updatedPageChecks );

		return { success: true };
	} catch ( error ) {
		// The request failed or was aborted — report it so callers can
		// surface an error state instead of a false success.
		return { success: false };
	}
}

export function* ignoreBrokenLinkUrl( url ) {
	return yield* updateBrokenLinkIgnoreState( url, 'ignore' );
}

export function* restoreBrokenLinkUrl( url ) {
	return yield* updateBrokenLinkIgnoreState( url, 'restore' );
}

export const setPageSeoChecksByIdAndType = (
	postId,
	postType,
	checks,
	error = null
) => {
	const { categorizedChecks, sequence } = categorizeChecksList( checks );

	return {
		type: actionTypes.SET_PAGE_SEO_CHECKS_BY_ID_AND_TYPE,
		payload: {
			postId,
			postType,
			checks: categorizedChecks,
			sequence,
			error,
		},
	};
};

export const setBatchPageSeoChecks = ( data, type = 'post' ) => {
	const processedData = {};
	Object.entries( data ).forEach( ( [ id, itemData ] ) => {
		const checks = itemData.checks || {};
		const processedChecks = Object.entries( checks ).map(
			( [ key, value ] ) => ( {
				...value,
				id: key,
				title:
					value?.message ||
					key
						.replace( /_/g, ' ' )
						.replace( /\b\w/g, ( c ) => c.toUpperCase() ),
				data: value?.description,
				showImages: key === 'image_alt_text',
			} )
		);

		// Surface site-wide ignored broken links so the listing modal shows the
		// ignore / restore UI on open, without waiting for a manual refresh.
		applyIgnoredBrokenLinks( processedChecks, checks.all_links );

		const { categorizedChecks, sequence } =
			categorizeChecksList( processedChecks );

		processedData[ id ] = {
			postType: type,
			checks: categorizedChecks,
			sequence,
			error: null,
		};
	} );

	return {
		type: actionTypes.SET_BATCH_PAGE_SEO_CHECKS,
		payload: processedData,
	};
};

const categorizeChecksList = ( checks ) => {
	const sequence = [];
	const categorizedChecks = checks.reduce(
		( acc, check ) => {
			// For preserving the order of the checks
			sequence.push( check.id );

			if ( check?.ignore ) {
				acc.ignoredChecks.push( check );
			} else {
				// set the flag to false to show the check in the UI
				check.ignore = false;

				if ( check.status === 'error' ) {
					acc.badChecks.push( check );
				} else if ( check.status === 'warning' ) {
					acc.fairChecks.push( check );
				} else if ( check.status === 'suggestion' ) {
					acc.suggestionChecks.push( check );
				} else if ( check.status === 'success' ) {
					acc.passedChecks.push( check );
				}
			}
			return acc;
		},
		{
			badChecks: [],
			fairChecks: [],
			suggestionChecks: [],
			passedChecks: [],
			ignoredChecks: [],
		}
	);

	return { categorizedChecks, sequence };
};

function* handleSeoBarCheckIgnoreUpdate(
	checkId,
	postId,
	postType,
	method,
	value
) {
	try {
		const response = yield fetchFromAPI( {
			path: 'surerank/v1/checks/ignore-page-check',
			method,
			data: { post_id: postId, id: checkId, check_type: postType },
		} );

		if ( response?.status !== 'success' ) {
			throw new Error( response?.message );
		}

		const { checks, sequence } = select( STORE_NAME ).getSeoBarChecks(
			postId,
			postType
		);
		const flatChecks = Object.values( checks )
			.flat()
			.map( ( check ) => {
				if ( check.id === checkId ) {
					check.ignore = value;
				}
				return check;
			} )
			.sort(
				( a, b ) => sequence.indexOf( a.id ) - sequence.indexOf( b.id )
			);

		yield setPageSeoChecksByIdAndType( postId, postType, flatChecks );
	} catch ( error ) {
		// Silently fail for aborted requests
	}
}

export function* ignoreSeoBarCheck( checkId, postId, postType ) {
	yield handleSeoBarCheckIgnoreUpdate(
		checkId,
		postId,
		postType,
		'POST',
		true
	);
}

export function* restoreSeoBarCheck( checkId, postId, postType ) {
	yield handleSeoBarCheckIgnoreUpdate(
		checkId,
		postId,
		postType,
		'DELETE',
		false
	);
}

/**
 * Converts the seo-bar's per-post categorized check cache into the flat format
 * expected by the modal, then seeds pageSeoChecks so analyze.js skips the
 * duplicate refreshPageChecks API call (refreshCalled: true).
 *
 * @param {string|number} postId     The post or term ID.
 * @param {Object}        seoBarData The cached seo-bar entry: { checks, sequence }.
 */
const seedModalFromSeoBarCache = ( postId, seoBarData ) => {
	const { checks: categorized, sequence } = seoBarData;

	// Flatten the seo-bar's categorized buckets into a single sorted array.
	const allChecks = [
		'badChecks',
		'fairChecks',
		'suggestionChecks',
		'passedChecks',
		'ignoredChecks',
	]
		.flatMap( ( key ) => categorized[ key ] || [] )
		.sort(
			( a, b ) => sequence.indexOf( a.id ) - sequence.indexOf( b.id )
		);

	const pageChecks = allChecks.filter( ( c ) => c.type === 'page' );
	const keywordChecks = allChecks.filter( ( c ) => c.type === 'keyword' );
	const ignoredList = allChecks
		.filter( ( c ) => c.ignore )
		.map( ( c ) => c.id );

	const categorizedChecks = getCategorizedChecks( allChecks, ignoredList );
	const categorizedPageChecks = getCategorizedChecks(
		pageChecks,
		ignoredList
	);
	const categorizedKeywordChecks = getCategorizedChecks(
		keywordChecks,
		ignoredList
	);

	return setPageSeoChecks( {
		postId,
		checks: allChecks,
		pageChecks,
		keywordChecks,
		categorizedChecks,
		categorizedPageChecks,
		categorizedKeywordChecks,
		ignoredList,
		initializing: false,
		refreshCalled: true,
		isRefreshing: false,
	} );
};

/**
 * Reset the store for a new post context on listing pages.
 * Clears modal-specific state while preserving per-post seo-bar check cache.
 *
 * @param {string|number} postId     The post, term or user ID being opened.
 * @param {string}        postType   'post', 'taxonomy' or 'user'.
 * @param {boolean}       isTaxonomy Whether this is a taxonomy term.
 */
export function* resetForNewPost( postId, postType, isTaxonomy ) {
	yield {
		type: actionTypes.RESET_FOR_NEW_POST,
		payload: { postId },
	};

	// If the seo-bar already fetched checks for this post, seed the modal state
	// so analyze.js skips the duplicate refreshPageChecks API call.
	// RESET_FOR_NEW_POST preserves numeric-keyed cache entries, so we read after.
	const cachedData = select( STORE_NAME ).getPageSeoChecks()?.[ postId ];
	if ( cachedData?.checks ) {
		yield seedModalFromSeoBarCache( postId, cachedData );
	}

	// Fetch editor variables (used for title/description template replacement).
	let queryParams = { post_id: postId };
	if ( postType === 'user' ) {
		queryParams = { user_id: postId };
	} else if ( isTaxonomy ) {
		queryParams = { term_id: postId };
	}

	const requestId = startRequest( 'editor-variables' );
	try {
		const response = yield fetchFromAPI( {
			path: addQueryArgs( EDITOR_URL, queryParams ),
			method: 'GET',
		} );

		// Ignore the response when the listing page switched to another row or reopened
		// this one meanwhile.
		if (
			! isLatestRequest( 'editor-variables', requestId ) ||
			select( STORE_NAME ).getActivePostId() !== postId
		) {
			return;
		}

		if ( response?.success ) {
			let initialState = { variables: response.variables };
			if ( response.other ) {
				initialState = { ...initialState, ...response.other };
			}
			yield updateInitialState( initialState );
		}
	} catch ( error ) {
		// Silently fail — the modal will still work without variables.
	}
}
