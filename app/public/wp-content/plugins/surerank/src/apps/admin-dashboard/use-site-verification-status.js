import { useMemo } from '@wordpress/element';

/**
 * URL normalization utility function
 * @param {string} url - The URL to normalize
 */
export const normalizeUrl = ( url ) => url?.replace( /\/$/, '' ) || '';

// Custom hook to determine site verification status and related information
const useSiteVerificationStatus = (
	selectedSite,
	currentSiteUrl,
	searchConsole
) => {
	return useMemo( () => {
		// The full property entry for the current site. Google APIs only accept
		// the exact property string (URL-prefix properties end with a trailing
		// slash), so consumers must send currentSiteEntry.siteUrl — never the
		// raw origin — when selecting a property.
		const currentSiteEntry = searchConsole?.sites?.find(
			( site ) =>
				site.siteUrl === currentSiteUrl ||
				site.siteUrl === `${ currentSiteUrl }/` ||
				normalizeUrl( site.siteUrl ) === normalizeUrl( currentSiteUrl )
		);
		const currentSiteInList = !! currentSiteEntry;

		const isSelectedSiteVerified = () => {
			if ( ! selectedSite ) {
				return false;
			}
			const site = searchConsole?.sites?.find(
				( siteItem ) =>
					siteItem.siteUrl === selectedSite ||
					siteItem.siteUrl === `${ selectedSite }/` ||
					normalizeUrl( siteItem.siteUrl ) ===
						normalizeUrl( selectedSite )
			);
			return site?.isVerified === true;
		};

		const currentSiteInListButNotVerified =
			currentSiteInList &&
			normalizeUrl( selectedSite ) === normalizeUrl( currentSiteUrl ) &&
			! isSelectedSiteVerified();

		// Only show connect alert if current site is selected and not verified
		const shouldShowConnectAlert =
			normalizeUrl( selectedSite ) === normalizeUrl( currentSiteUrl ) &&
			! isSelectedSiteVerified();

		// Check if current site is already the selected site in SureRank
		const isCurrentSiteAlreadySelected =
			currentSiteInList &&
			isSelectedSiteVerified() &&
			searchConsole?.selectedSite &&
			( normalizeUrl( searchConsole.selectedSite ) ===
				normalizeUrl( currentSiteUrl ) ||
				searchConsole.selectedSite === currentSiteUrl ||
				searchConsole.selectedSite === `${ currentSiteUrl }/` );

		return {
			currentSiteEntry,
			currentSiteInList,
			isSelectedSiteVerified: isSelectedSiteVerified(),
			currentSiteInListButNotVerified,
			shouldShowConnectAlert,
			isCurrentSiteAlreadySelected,
		};
	}, [ selectedSite, currentSiteUrl, searchConsole ] );
};

export default useSiteVerificationStatus;
