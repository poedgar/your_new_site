import { toast } from '@bsf/force-ui';
import { select } from '@wordpress/data';
import { STORE_NAME } from './constants';
import * as actions from './actions';
import { ADMIN_SETTINGS_URL, SITE_SETTINGS_URL } from '@Global/constants/api';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';
import { getDefaultPageDescription } from '@/global/constants';
import { addCategoryToSiteSeoChecks } from '@/functions/utils';
import currentUserCan from '@/functions/role-capabilities';

const resolvers = {
	*getMetaSettings() {
		yield actions.setLoading( 'pending' );
		const response = yield actions.fetchFromAPI( ADMIN_SETTINGS_URL );

		if ( response.success ) {
			yield actions.initSettings( response.data );
			return actions.setLoading( 'success' );
		}

		yield actions.setMessage( 'Failed to fetch meta settings' );
		return actions.setLoading( 'error' );
	},

	*getSiteSettings() {
		const response = yield actions.fetchFromAPI( SITE_SETTINGS_URL );
		if ( response.success ) {
			yield actions.setSiteSettings( {
				...( response?.data ?? {} ),
				site: {
					...( response?.data?.site ?? {} ),
					content: getDefaultPageDescription(),
				},
			} );
		}
	},

	*getSiteSeoAnalysis() {
		let report = {};
		const url = surerank_globals.site_url;

		if ( ! currentUserCan( 'surerank_global_setting' ) ) {
			return;
		}

		// Fetch critical issues
		try {
			const criticalIssuesResponse = yield actions.fetchFromAPI(
				addQueryArgs( '/surerank/v1/checks/general', {
					url,
				} )
			);
			if ( criticalIssuesResponse ) {
				report = {
					...report,
					...addCategoryToSiteSeoChecks(
						criticalIssuesResponse,
						'general'
					),
				};
			}
		} catch ( error ) {
			toast.error(
				error?.message ??
					__(
						'Failed to fetch general seo analysis report',
						'surerank'
					),
				{
					description: __(
						'An unexpected error occurred while fetching the general SEO analysis report. Please try again later.',
						'surerank'
					),
				}
			);
		}

		// Fetch other issues
		try {
			const othersIssuesResponse = yield actions.fetchFromAPI(
				addQueryArgs( '/surerank/v1/checks/other', {
					url,
				} )
			);
			if ( othersIssuesResponse ) {
				report = {
					...report,
					...addCategoryToSiteSeoChecks(
						othersIssuesResponse,
						'other'
					),
				};
			}
		} catch ( error ) {
			toast.error(
				error?.message ??
					__(
						'Failed to fetch other seo analysis report',
						'surerank'
					),
				{
					description: __(
						'An unexpected error occurred while fetching the other SEO analysis report. Please try again later.',
						'surerank'
					),
				}
			);
		}

		// Fetch settings report
		try {
			const settingsReportResponse = yield actions.fetchFromAPI(
				addQueryArgs( '/surerank/v1/checks/settings-checks', {
					url,
				} )
			);
			if ( settingsReportResponse ) {
				report = {
					...report,
					...addCategoryToSiteSeoChecks(
						settingsReportResponse,
						'settings'
					),
				};
			}
		} catch ( error ) {
			toast.error(
				error?.message ??
					__( 'Failed to fetch analysis report', 'surerank' ),
				{
					description: __(
						'An unexpected error occurred while fetching the settings SEO analysis report. Please try again later.',
						'surerank'
					),
				}
			);
		}
		return yield actions.setSiteSeoAnalysis( { report } );
	},

	*getSearchConsole() {
		// is on the dashboard page.
		let isOnDashboard = false;
		try {
			const urlParams = new URLSearchParams( window.location.search );
			const page = urlParams.get( 'page' );
			if ( page === 'surerank' ) {
				isOnDashboard = true;
			}
		} catch ( error ) {
			// Do nothing
		}

		const searchConsoleData = select( STORE_NAME ).getSearchConsole();
		if ( ! searchConsoleData.authenticated ) {
			return;
		}
		const updatedData = {};
		// Fetch selected site
		const selectedSiteResponse = yield actions.fetchFromAPI(
			'/surerank/v1/google-search-console/site'
		);
		if ( selectedSiteResponse.success ) {
			updatedData.selectedSite = selectedSiteResponse.site;
			if ( ! updatedData.selectedSite ) {
				if ( isOnDashboard ) {
					yield actions.toggleSiteSelectorModal();
				}
			}
		}
		// Fetch sites
		const sitesResponse = yield actions.fetchFromAPI(
			'/surerank/v1/google-search-console/sites'
		);
		if ( sitesResponse.success ) {
			updatedData.sites = sitesResponse.siteEntry;

			if ( ! updatedData.selectedSite ) {
				// Pre-select the property that actually covers this site:
				// exact URL-prefix property first, then a sc-domain property
				// covering the host. Never fall back to an arbitrary property —
				// a wrong pre-selection gets saved and every Search Console
				// call then fails with an opaque 403 "Access denied".
				const origin = window.location.origin.toLowerCase();
				const host = window.location.host.toLowerCase();
				const entries = sitesResponse?.siteEntry ?? [];
				const prefixMatch = entries.find(
					( site ) =>
						site?.siteUrl?.toLowerCase()?.replace( /\/$/, '' ) ===
						origin
				);
				const domainMatch = entries.find( ( site ) => {
					const url = site?.siteUrl?.toLowerCase() ?? '';
					if ( ! url.startsWith( 'sc-domain:' ) ) {
						return false;
					}
					const domain = url.slice( 'sc-domain:'.length );
					return host === domain || host.endsWith( `.${ domain }` );
				} );
				updatedData.tempSelectedSite =
					prefixMatch?.siteUrl ?? domainMatch?.siteUrl ?? '';
			}
		}
		return yield actions.setSearchConsole( updatedData );
	},

	*getEmailReportsSettings() {
		const response = yield actions.fetchFromAPI(
			'/surerank/v1/email-reports/report-settings'
		);
		if ( response.success ) {
			return yield actions.setEmailReportsSettings( response.data );
		}
	},
};

export default resolvers;
