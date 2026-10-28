import { useDispatch, useSelect, select as selectStore } from '@wordpress/data';
import { toast } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '@AdminStore/constants';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { addCategoryToSiteSeoChecks } from '@Functions/utils';
import { useEffect } from '@wordpress/element';
import currentUserCan from '@/functions/role-capabilities';

/**
 * Custom hook for running SEO checks
 *
 * @param {Object} options            - Configuration options for the hook
 * @param {Array}  options.categories - Array of categories to fetch ('settings', 'other', 'general'). If not provided, fetches all categories.
 * @return {Object} Hook return object containing isLoading state and handleRunChecksAgain function
 */
export const useRunSeoChecks = ( options = {} ) => {
	const { categories = [ 'settings', 'other', 'general' ] } = options;
	const dispatch = useDispatch( STORE_NAME );
	const { runningChecks, report } =
		useSelect( ( select ) => select( STORE_NAME ).getSiteSeoAnalysis() ) ||
		false;
	const { setSiteSeoAnalysis } = dispatch;

	/**
	 * Refresh the audit checks for the configured categories.
	 *
	 * @param {Object}  options        - Refresh options.
	 * @param {boolean} options.silent - When true, skip toggling the page-level
	 *                                 loading state (used by background flows
	 *                                 such as the Fix It All For Me wizard).
	 * @return {Promise<Object|null>} The merged report, or null when a refresh is
	 *                                already in progress.
	 * @since x.x.x
	 */
	const refreshChecks = async ( { silent = false } = {} ) => {
		if ( runningChecks ) {
			return null;
		}
		if ( ! silent ) {
			setSiteSeoAnalysis( { runningChecks: true } );
		}
		const url = surerank_globals.site_url;
		const force = true;

		let settingsResponse = {};
		let otherResponse = {};
		let generalResponse = {};
		const failed = [];

		// Fetch only the requested categories
		if ( categories.includes( 'settings' ) ) {
			try {
				settingsResponse = await apiFetch( {
					path: addQueryArgs( '/surerank/v1/checks/settings-checks', {
						url,
						force,
					} ),
				} );
				settingsResponse = addCategoryToSiteSeoChecks(
					settingsResponse,
					'settings'
				);
			} catch ( error ) {
				failed.push( 'settings' );
			}
		}

		if ( categories.includes( 'other' ) ) {
			try {
				otherResponse = await apiFetch( {
					path: addQueryArgs( '/surerank/v1/checks/other', {
						url,
						force,
					} ),
				} );
				otherResponse = addCategoryToSiteSeoChecks(
					otherResponse,
					'other'
				);
			} catch ( error ) {
				failed.push( 'other' );
			}
		}

		if ( categories.includes( 'general' ) ) {
			try {
				generalResponse = await apiFetch( {
					path: addQueryArgs( '/surerank/v1/checks/general', {
						url,
						force,
					} ),
				} );
				generalResponse = addCategoryToSiteSeoChecks(
					generalResponse,
					'general'
				);
			} catch ( error ) {
				failed.push( 'general' );
			}
		}

		const hasAnyData =
			Object.keys( settingsResponse ).length > 0 ||
			Object.keys( otherResponse ).length > 0 ||
			Object.keys( generalResponse ).length > 0;

		// Read the latest report fresh so repeated background refreshes merge
		// onto current state instead of a stale render-time snapshot.
		const currentReport =
			selectStore( STORE_NAME ).getSiteSeoAnalysis()?.report ||
			report ||
			{};

		// A failed request leaves the previous rows in place, which is indistinguishable from
		// "checks ran and nothing changed" unless it is reported.
		if ( failed.length && ! silent ) {
			toast.error( __( 'Some checks could not be run', 'surerank' ), {
				description: __(
					'We could not read your site just now. Please try again in a moment.',
					'surerank'
				),
			} );
		}

		const payload = {
			runningChecks: false,
		};
		if ( hasAnyData ) {
			payload.report = {
				...currentReport,
				...generalResponse,
				...settingsResponse,
				...otherResponse,
			};
		}
		setSiteSeoAnalysis( payload );

		return payload.report || currentReport;
	};

	const handleRunChecksAgain = async () => {
		await refreshChecks();
	};

	// Auto-trigger checks if pending action exists
	useEffect( () => {
		if ( runningChecks || ! currentUserCan( 'surerank_global_setting' ) ) {
			return;
		}

		try {
			const pendingActions = JSON.parse(
				window.localStorage.getItem( 'surerank_pending_actions' ) ||
					'[]'
			);
			if ( pendingActions.includes( 'enable_google_console' ) ) {
				// Remove the action first to prevent double-trigger
				const updatedActions = pendingActions.filter(
					( action ) => action !== 'enable_google_console'
				);
				window.localStorage.setItem(
					'surerank_pending_actions',
					JSON.stringify( updatedActions )
				);

				// Trigger the checks
				handleRunChecksAgain();
			}
		} catch ( error ) {
			// Silently fail if localStorage is not available
		}
	}, [ runningChecks, handleRunChecksAgain ] );

	return { isLoading: runningChecks, handleRunChecksAgain, refreshChecks };
};
