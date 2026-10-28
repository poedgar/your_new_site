import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { Button, toast } from '@bsf/force-ui';
import { formatSeoChecks, cn } from '@/functions/utils';
import { STORE_NAME } from '@/store/constants';
import {
	fetchBrokenLinkStatus,
	getIgnoredUrlSet,
	getPassingLinksTitle,
	normalizeIgnoredUrl,
} from '../link-checks';
import { RefreshCcw } from 'lucide-react';
import { CHECK_TYPES, ENABLE_PAGE_LEVEL_SEO } from '@/global/constants';

// Function to check broken links for Elementor editor
export const checkBrokenLinks = async (
	links,
	postId,
	allLinks,
	setBrokenLinkState,
	setPageSeoCheck,
	brokenLinkState,
	pageSeoChecks
) => {
	// Site-wide ignored URLs are skipped during checking and surfaced
	// separately, so the per-URL ignore/restore UI matches the block editor.
	const ignoredSet = getIgnoredUrlSet();
	const linksToCheck = links.filter(
		( url ) => ! ignoredSet.has( normalizeIgnoredUrl( url ) )
	);
	const ignoredBrokenLinks = allLinks.filter( ( url ) =>
		ignoredSet.has( normalizeIgnoredUrl( url ) )
	);
	const totalLinks = linksToCheck.length;
	const brokenLinksArray = [];
	const unverifiedBrokenLinks = [];

	for ( const url of linksToCheck ) {
		let brokenItem = null;

		try {
			const result = await fetchBrokenLinkStatus( {
				postId,
				userAgent: window.navigator.userAgent,
				url,
				allLinks,
			} );

			if ( result.verified === false ) {
				unverifiedBrokenLinks.push( {
					url,
					status: result.status,
					details: result.details,
				} );
			} else if ( ! result.success ) {
				const { success, ...rest } = result;
				brokenItem = { url, broken: true, ...rest };
			}
		} catch ( error ) {
			// A request failure is inconclusive, not a broken-link result.
			unverifiedBrokenLinks.push( {
				url,
				status: error?.data?.status ?? error?.code ?? 'error',
				details: error?.message,
			} );
			brokenItem = null;
		}

		// Update checkedLinks and collect broken links
		setBrokenLinkState( ( prev ) => {
			const updatedChecked = new Set( prev.checkedLinks );
			const updatedBroken = new Set( prev.brokenLinks );

			updatedChecked.add( url );
			if ( brokenItem ) {
				updatedBroken.add( url );
				brokenLinksArray.push( brokenItem ); // Add broken-link object
			}

			// Update linkCheckProgress synchronously
			setPageSeoCheck( 'linkCheckProgress', {
				current: updatedChecked.size,
				total: totalLinks,
			} );

			return {
				...prev,
				checkedLinks: updatedChecked,
				brokenLinks: updatedBroken,
			};
		} );
	}

	// Final state update: mark checking as complete and update SEO checks
	setBrokenLinkState( ( prev ) => {
		const updatedChecks = [ ...pageSeoChecks ].filter(
			( c ) => c.id !== 'broken_links'
		);

		if ( brokenLinksArray.length > 0 ) {
			updatedChecks.push( {
				id: 'broken_links',
				title: __(
					'One or more broken links found on the page.',
					'surerank'
				),
				status: 'error',
				type: 'page',
				data: [ ...brokenLinksArray ],
				ignoredBrokenLinks,
				unverifiedBrokenLinks,
			} );
		} else if (
			ignoredBrokenLinks.length > 0 ||
			unverifiedBrokenLinks.length > 0
		) {
			// No active broken links, but ignored or unverifiable ones exist:
			// emit a passing check so the "Ignored links" restore section and
			// the unverified note stay available.
			updatedChecks.push( {
				id: 'broken_links',
				title: getPassingLinksTitle( unverifiedBrokenLinks.length ),
				status: 'success',
				type: 'page',
				data: [],
				ignoredBrokenLinks,
				unverifiedBrokenLinks,
			} );
		}

		// Update all SEO checks at once
		CHECK_TYPES.forEach( ( type ) => {
			setPageSeoCheck(
				type,
				updatedChecks.filter( ( item ) => item.type === type )
			);
		} );
		setPageSeoCheck( 'isCheckingLinks', false );
		setPageSeoCheck( 'linkCheckProgress', {
			current: totalLinks,
			total: totalLinks,
		} );

		return {
			...prev,
			isChecking: false,
		};
	} );
};

// Function to refresh page SEO checks for Elementor editor
export const refreshPageChecks = async (
	setIsRefreshing,
	setBrokenLinkState,
	setPageSeoCheck,
	staticSelect,
	pageSeoChecks,
	brokenLinkState
) => {
	const isUser = isUserContext();
	const isTaxonomyListing =
		! isUser && surerank_seo_popup?.is_taxonomy === '1';
	const dynamicPostId =
		staticSelect( STORE_NAME ).getVariables()?.post?.ID?.value ||
		staticSelect( STORE_NAME ).getVariables()?.user?.ID?.value ||
		staticSelect( STORE_NAME ).getActivePostId() ||
		( isUser ? surerank_seo_popup?.user_id : 0 ) ||
		( isTaxonomyListing ? surerank_seo_popup?.term_id || 0 : 0 ) ||
		0;
	setIsRefreshing( true );
	const timestamp = Date.now();
	let apiPath = '/surerank/v1/checks/page';
	let apiParams = { post_ids: [ dynamicPostId ], _t: timestamp };
	if ( isUser ) {
		apiPath = '/surerank/v1/checks/user';
		apiParams = { user_ids: [ dynamicPostId ], _t: timestamp };
	} else if ( isTaxonomyListing ) {
		apiPath = '/surerank/v1/checks/taxonomy';
		apiParams = { term_ids: [ dynamicPostId ], _t: timestamp };
	}

	try {
		const response = await apiFetch( {
			path: addQueryArgs( apiPath, apiParams ),
			method: 'GET',
		} );

		const checks = formatSeoChecks(
			response?.data[ dynamicPostId ]?.checks
		);
		const allLinks =
			response.data[ dynamicPostId ]?.checks?.all_links || [];

		// Reset brokenLinkState, keeping only broken links that still exist
		setBrokenLinkState( ( prev ) => {
			const allLinksSet = new Set( allLinks );
			const cleanedBrokenLinks = new Set();
			prev.brokenLinks.forEach( ( link ) => {
				if ( allLinksSet.has( link ) ) {
					cleanedBrokenLinks.add( link );
				}
			} );

			return {
				isChecking: false,
				checkedLinks: new Set(),
				brokenLinks: cleanedBrokenLinks,
				allLinks,
			};
		} );

		// Populate ignoredList from the full server response before filtering out
		// broken_links. The server stamps `ignore: true` on ignored checks via
		// get_updated_ignored_check_list(). Extracting from the unfiltered array
		// ensures broken_links (removed below) is included when it was ignored.
		const serverIgnoredIds = checks
			.filter( ( check ) => check.ignore === true )
			.map( ( check ) => check.id );
		setPageSeoCheck( 'ignoredList', serverIgnoredIds );

		const cleanedChecks = [ ...checks ].filter(
			( item ) => item.id !== 'broken_links'
		);

		// Update pageSeoChecks with cleaned checks
		CHECK_TYPES.forEach( ( type ) => {
			setPageSeoCheck(
				type,
				cleanedChecks.filter( ( item ) => item.type === type )
			);
		} );

		// Mark initialization complete so the status indicator and accordion
		// render with real data (broken-links check may still be running).
		setPageSeoCheck( 'initializing', false );

		if ( allLinks.length === 0 ) {
			setPageSeoCheck( 'isCheckingLinks', false );
			setPageSeoCheck( 'linkCheckProgress', { current: 0, total: 0 } );
		} else {
			setPageSeoCheck( 'isCheckingLinks', true );
			setPageSeoCheck( 'linkCheckProgress', {
				current: 0,
				total: allLinks.length,
			} );

			await checkBrokenLinks(
				allLinks,
				dynamicPostId,
				allLinks,
				setBrokenLinkState,
				setPageSeoCheck,
				brokenLinkState,
				cleanedChecks
			);
		}
	} catch ( error ) {
		toast.error( error.message );
		// Reset states on error
		setBrokenLinkState( {
			isChecking: false,
			checkedLinks: new Set(),
			brokenLinks: new Set(),
			allLinks: [],
		} );
		setPageSeoCheck( 'isCheckingLinks', false );
		setPageSeoCheck( 'linkCheckProgress', { current: 0, total: 0 } );
	} finally {
		setIsRefreshing( false );
	}
};

/**
 * Check if the page is in frontend.
 *
 * @return {boolean} True if the page is in frontend
 */
export const isFrontend = () => !! surerank_seo_popup?.is_frontend;

export const isElementorBuilder = () => {
	return (
		typeof window !== 'undefined' &&
		typeof window.elementor !== 'undefined' &&
		window.elementor.hasOwnProperty( 'elements' )
	);
};

export const isBricksBuilder = () => {
	return !! surerank_globals?.is_bricks;
};

export const isBreakdanceBuilder = () => {
	return !! surerank_globals?.is_breakdance;
};

export const isTagDivBuilder = () => {
	return !! surerank_globals?.is_tagdiv;
};

export const isEtchBuilder = () => {
	return !! surerank_globals?.is_etch;
};

export const isAvadaBuilder = () => {
	// Check for Fusion Builder frontend context
	if (
		typeof window !== 'undefined' &&
		typeof window.FusionPageBuilder !== 'undefined'
	) {
		return true;
	}
	return false;
};

export const isListingPage = () => {
	return surerank_seo_popup?.editor_type === 'listing';
};

/**
 * Whether we're in the block (Gutenberg) editor — the only context where the
 * AI image-alt grid can reliably read images and write alt back. Classic, page
 * builders, and server-sourced contexts fall back to "Help Me Fix".
 *
 * @since x.x.x
 * @return {boolean} True in the block editor.
 */
export const isBlockEditor = () => {
	return surerank_seo_popup?.editor_type === 'block';
};

/**
 * Check if the popup is in a user (author profile) context.
 *
 * Covers the user profile edit screens (editor_type 'user') and the
 * users.php list table (seo bar type 'user').
 *
 * @since 1.9.0
 * @return {boolean} True if user context
 */
export const isUserContext = () => {
	return (
		surerank_seo_popup?.editor_type === 'user' ||
		!! surerank_seo_popup?.is_user ||
		window?.surerank_seo_bar?.type === 'user'
	);
};

export const isPageBuilderActive = () => {
	return (
		isBricksBuilder() ||
		isBreakdanceBuilder() ||
		isTagDivBuilder() ||
		isEtchBuilder() ||
		isElementorBuilder() ||
		isAvadaBuilder() ||
		// Consider frontend as page builder active as page requires refresh.
		isFrontend() ||
		// Listing pages use server-side checks — no live editor available.
		isListingPage() ||
		// User profile screens have no editable content — live checks unavailable.
		isUserContext()
	);
};

/**
 * Check if SEO analysis should be disabled
 * Returns true if SEO analysis should be disabled due to:
 * - Page level SEO being disabled
 * - Active Bricks builder
 * - Active Avada builder
 *
 *
 * @return {boolean} True if SEO analysis should be disabled
 */
export const isSeoAnalysisDisabled = () => {
	return ! ENABLE_PAGE_LEVEL_SEO || isAvadaBuilder();
};

export const RefreshButton = ( { isRefreshing, isChecking, onClick } ) => {
	return (
		<Button
			variant="outline"
			size="xs"
			onClick={ onClick }
			disabled={ isRefreshing || isChecking }
			icon={
				<RefreshCcw
					className={ cn(
						'size-4',
						( isRefreshing || isChecking ) && 'animate-spin'
					) }
				/>
			}
		>
			{ isRefreshing || isChecking
				? __( 'Refreshing', 'surerank' )
				: __( 'Refresh', 'surerank' ) }
		</Button>
	);
};
