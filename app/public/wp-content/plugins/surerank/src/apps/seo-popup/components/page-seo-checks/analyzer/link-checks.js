import { __, _n, sprintf } from '@wordpress/i18n';
import { createCheck } from './content-checks';
import apiFetch from '@wordpress/api-fetch';

const cacheBrokenLinksResults = new Map();

/**
 * Normalize a URL for ignored-list comparison (trailing slash insensitive).
 *
 * @param {string} url URL to normalize.
 * @return {string} Normalized URL.
 */
export const normalizeIgnoredUrl = ( url ) =>
	( url || '' ).trim().replace( /\/+$/, '' );

/**
 * Get the site-wide ignored broken link URLs as a normalized Set.
 *
 * @return {Set<string>} Set of normalized ignored URLs.
 */
export const getIgnoredUrlSet = () =>
	new Set(
		( window?.surerank_seo_popup?.broken_link_ignored_urls || [] ).map(
			normalizeIgnoredUrl
		)
	);

/**
 * Mark a URL as ignored for the current session and drop it from the
 * results cache so it disappears from subsequent check runs.
 *
 * @param {string} url URL to ignore.
 */
export const markBrokenLinkIgnored = ( url ) => {
	cacheBrokenLinksResults.delete( url );
	const globals = window?.surerank_seo_popup;
	if ( globals ) {
		globals.broken_link_ignored_urls = [
			...new Set( [
				...( globals.broken_link_ignored_urls || [] ),
				url,
			] ),
		];
	}
};

/**
 * Remove a URL from the session ignored list and clear its cache entry so
 * the next analyze cycle re-checks it truthfully.
 *
 * @param {string} url URL to restore.
 */
export const markBrokenLinkRestored = ( url ) => {
	cacheBrokenLinksResults.delete( url );
	const globals = window?.surerank_seo_popup;
	if ( globals ) {
		globals.broken_link_ignored_urls = (
			globals.broken_link_ignored_urls || []
		).filter(
			( ignoredUrl ) =>
				normalizeIgnoredUrl( ignoredUrl ) !== normalizeIgnoredUrl( url )
		);
	}
};

/**
 * Get broken links from cache.
 * @param {string[]} links
 * @return {string[]} Array of broken links
 */
const getCacheBrokenLinks = ( links ) => {
	if ( ! links?.length ) {
		return [];
	}

	const result = [];
	for ( const url of links ) {
		const cachedResult = cacheBrokenLinksResults.get( url );
		if ( cachedResult?.broken ) {
			result.push( {
				url,
				...cachedResult,
			} );
		}
	}
	return result;
};

const updateCache = ( links ) => {
	if ( ! links?.length ) {
		return cacheBrokenLinksResults.clear();
	}
	// Remove links from cache if they are not in the links array.
	Array.from( cacheBrokenLinksResults.keys() ).forEach( ( url ) => {
		if ( ! links.includes( url ) ) {
			cacheBrokenLinksResults.delete( url );
		}
	} );
};

/**
 * Determine whether a URL should be skipped. We use an inclusive check:
 * - Allow absolute http/https URLs
 * - Allow relative URLs (no scheme)
 * - Skip everything else (mailto:, tel:, sms:, geo:, javascript:, data:, etc.)
 *
 * @param {string} href Raw href value
 * @return {boolean} True if the URL should be skipped
 */
const shouldSkipUrl = ( href ) => {
	if ( ! href ) {
		return true;
	}

	const trimmed = href.trim();
	if ( trimmed === '' ) {
		return true;
	}

	// Anchors
	if ( trimmed.startsWith( '#' ) ) {
		return true;
	}

	try {
		// If it's an absolute URL, the constructor will succeed. If it has a scheme
		// like mailto:, URL will still be constructed but protocol will reflect that.
		const url = new URL( trimmed, surerank_globals.site_url );
		const protocol = ( url.protocol || '' )
			.replace( ':', '' )
			.toLowerCase();
		return ! ( protocol === 'http' || protocol === 'https' );
	} catch ( error ) {
		// An href that does not parse (a malformed host such as "//my site/x")
		// cannot be checked, and the REST endpoint rejects it as well, so skip
		// it rather than resolve it into a bogus URL.
		return true;
	}
};

/**
 * Get all unique links (href/src) from <a> and <img> tags in the document.
 * @param {Document} document
 * @param {string}   permalink Current post permalink used as the URL base.
 * @return {string[]} Array of unique URLs
 */
export const getAllLinks = ( document, permalink = '' ) => {
	if ( ! document ) {
		return [];
	}

	const linkElements = Array.from( document.querySelectorAll( 'a[href]' ) );
	const urls = linkElements
		.map( ( element ) => {
			const href = element.getAttribute( 'href' );
			if ( ! href ) {
				return null;
			}

			const trimmed = href.trim();
			// Skip anchors, javascript and various schemes we don't want to check.
			if ( shouldSkipUrl( trimmed ) ) {
				return null;
			}

			// Resolve relative hrefs against the post permalink, the same base
			// the REST endpoint and PostAnalyzer resolve against.
			if ( ! trimmed.startsWith( 'http' ) ) {
				try {
					return new URL(
						trimmed,
						permalink || surerank_globals.site_url
					).href;
				} catch ( error ) {
					// Unresolvable against this base: skip it instead of
					// failing the whole link check.
					return null;
				}
			}

			return trimmed;
		} )
		.filter( Boolean ); // Remove null/undefined values

	// Return unique URLs
	return [ ...new Set( urls ) ];
};

/**
 * Check links for broken status via API and save results.
 * @param {Object}   params
 * @param {string[]} params.links
 * @param {number}   params.postId
 * @param {string}   params.userAgent
 * @param {Function} params.onProgress
 * @return {Promise<string[]>} Array of broken links
 */
export const checkLinks = async ( {
	links,
	postId,
	userAgent,
	onProgress,
} ) => {
	// Update cache before checking links.
	updateCache( links );

	if ( ! links.length ) {
		return [];
	}

	const uniqueLinks = links.filter(
		( url ) => ! cacheBrokenLinksResults.has( url )
	);
	const resultFromCache = getCacheBrokenLinks( links );

	if ( uniqueLinks.length === 0 ) {
		return resultFromCache;
	}

	const total = links.length;
	let current = links.length - uniqueLinks.length;

	if ( typeof onProgress === 'function' ) {
		onProgress( 'isCheckingLinks', true );
		onProgress( 'linkCheckProgress', {
			current,
			total,
		} );
	}

	// Process links sequentially to avoid overwhelming servers
	for ( const url of uniqueLinks ) {
		try {
			const result = await fetchBrokenLinkStatus( {
				postId,
				userAgent,
				url,
				allLinks: links,
			} );
			const { success, ...rest } = result;
			cacheBrokenLinksResults.set( url, {
				broken: result.verified !== false && ! success,
				unverified: result.verified === false,
				...rest,
			} );
		} catch ( error ) {
			// An API failure does not prove that the destination is broken.
			cacheBrokenLinksResults.set( url, {
				broken: false,
				unverified: true,
				status: error?.data?.status ?? error?.code ?? 'error',
				details: error.message,
				message: __( 'Failed to check link', 'surerank' ),
			} );
		}
		current++;
		if ( typeof onProgress === 'function' ) {
			onProgress( 'linkCheckProgress', {
				current,
				total,
			} );
		}

		// Add small delay between requests to prevent rate limiting
		if ( current < total ) {
			await new Promise( ( resolve ) => setTimeout( resolve, 100 ) );
		}
	}
	onProgress( 'isCheckingLinks', false );

	return getCacheBrokenLinks( links );
};

/**
 * Collect the links whose check did not complete, with the reason.
 *
 * @param {string[]} links URLs that were checked.
 * @return {Array<Object>} Unverified links.
 */
const getUnverifiedLinks = ( links ) =>
	links
		.filter( ( url ) => cacheBrokenLinksResults.get( url )?.unverified )
		.map( ( url ) => {
			const cached = cacheBrokenLinksResults.get( url );
			return {
				url,
				status: cached?.status,
				details: cached?.details,
			};
		} );

/**
 * Build the passing broken-links check, noting any link we could not verify.
 *
 * @param {number} unverifiedCount Number of links that could not be verified.
 * @return {string} Check title.
 */
export const getPassingLinksTitle = ( unverifiedCount ) => {
	if ( unverifiedCount > 0 ) {
		return sprintf(
			/* translators: %d: number of links that could not be verified. */
			_n(
				'No broken links found. Could not verify %d link.',
				'No broken links found. Could not verify %d links.',
				unverifiedCount,
				'surerank'
			),
			unverifiedCount
		);
	}

	return __( 'No broken links found on the page.', 'surerank' );
};

/**
 * Check for broken links in the document and report results.
 *
 * @param {Document} document   Document to scan.
 * @param {number}   postId     Post ID.
 * @param {string}   userAgent  User agent used for the checks.
 * @param {Function} onProgress Progress callback.
 * @param {string}   permalink  Current post permalink used as the URL base.
 * @return {Promise<Object>} createCheck result
 */
export const checkBrokenLinks = async (
	document,
	postId,
	userAgent = window.navigator.userAgent,
	onProgress,
	permalink = ''
) => {
	if ( ! document || ! postId ) {
		return;
	}

	const allLinks = getAllLinks( document, permalink );
	if ( ! allLinks.length ) {
		return;
	}

	// Site-wide ignored URLs are skipped entirely and surfaced separately.
	// Build the normalized set once per run instead of per URL.
	const ignoredSet = getIgnoredUrlSet();
	const links = allLinks.filter(
		( url ) => ! ignoredSet.has( normalizeIgnoredUrl( url ) )
	);

	const brokenLinks = links.length
		? await checkLinks( {
				links,
				postId,
				userAgent,
				onProgress,
		  } )
		: [];

	// Recompute from the (possibly updated) session list after the slow
	// link checks, so an ignore/restore made mid-run is reflected.
	const finalIgnoredSet = getIgnoredUrlSet();
	const ignoredBrokenLinks = allLinks.filter( ( url ) =>
		finalIgnoredSet.has( normalizeIgnoredUrl( url ) )
	);
	const unverifiedBrokenLinks = getUnverifiedLinks( links );

	if ( brokenLinks.length ) {
		return {
			...createCheck( {
				id: 'broken_links',
				title: __(
					'One or more broken links found on the page.',
					'surerank'
				),
				status: 'error',
				data: brokenLinks,
				type: 'page',
			} ),
			ignoredBrokenLinks,
			unverifiedBrokenLinks,
		};
	}

	return {
		...createCheck( {
			id: 'broken_links',
			title: getPassingLinksTitle( unverifiedBrokenLinks.length ),
			status: 'success',
			description: [],
			type: 'page',
		} ),
		ignoredBrokenLinks,
		unverifiedBrokenLinks,
	};
};

export const checkCanonicalUrl = ( canonical ) => {
	if ( ! canonical ) {
		return createCheck( {
			id: 'canonical_url',
			title: __(
				'Canonical tag is not present on the page.',
				'surerank'
			),
			status: 'warning',
			type: 'page',
		} );
	}

	return createCheck( {
		id: 'canonical_url',
		title: __( 'Canonical tag is present on the page.', 'surerank' ),
		status: 'success',
		type: 'page',
	} );
};

/**
 * Fetch broken link status from the API.
 *
 * @param {Object}   params
 * @param {number}   params.postId
 * @param {string}   params.userAgent
 * @param {string}   params.url
 * @param {string[]} params.allLinks
 * @return {Promise<Object>} API response
 */
export const fetchBrokenLinkStatus = async ( {
	postId,
	userAgent,
	url,
	allLinks,
} ) => {
	return await apiFetch( {
		path: '/surerank/v1/checks/broken-link',
		method: 'POST',
		data: {
			post_id: postId,
			user_agent: userAgent,
			url,
			urls: allLinks,
		},
	} );
};
