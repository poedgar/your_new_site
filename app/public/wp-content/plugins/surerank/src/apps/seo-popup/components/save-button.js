import {
	useSelect,
	useDispatch,
	select as staticSelect,
} from '@wordpress/data';
import { STORE_NAME } from '@/store/constants';
import apiFetch from '@wordpress/api-fetch';
import GlobalSaveButton from '@/apps/admin-components/global-save-button';
import { isCurrentPage, omitSavedValues } from '@/functions/utils';
import {
	POST_SEO_DATA_URL,
	TERM_SEO_DATA_URL,
	USER_SEO_DATA_URL,
} from '@/global/constants/api';
import { fetchMetaSettings } from '@/functions/api';
import {
	startRequest,
	getLatestRequest,
	isLatestRequest,
} from '@/functions/request-sequence';
import { DotIcon } from '@/global/components/icons';
import { validateCustomJsonLdSchemas } from '@/apps/admin-components/schema-utils/custom-json-ld';

const SaveButton = () => {
	const unsavedMetaSettings = useSelect(
		( select ) => select( STORE_NAME ).getUnsavedMetaSettings(),
		[]
	);
	const {
		clearSavedMetaSettings,
		initMetaDataAndDefaults,
		updateMetaboxState,
	} = useDispatch( STORE_NAME );

	const updateMetaSettings = async (
		savedMetaSettings,
		savedPostId,
		requestId
	) => {
		try {
			const response = await fetchMetaSettings();
			const store = staticSelect( STORE_NAME );
			// The listing page may have switched rows, or reopened this one, meanwhile.
			if (
				! isLatestRequest( 'seo-meta', requestId ) ||
				store.getActivePostId() !== savedPostId
			) {
				return;
			}
			// Keep edits made after the save was submitted on top of the server data.
			const newerEdits = omitSavedValues(
				store.getUnsavedMetaSettings(),
				savedMetaSettings
			);
			initMetaDataAndDefaults( {
				postSeoMeta: Object.keys( newerEdits ).length
					? { ...response.data, ...newerEdits }
					: response.data,
				globalDefaults: response.global_default,
			} );
			// A popup load superseded by this refresh will not mark the popup as loaded.
			updateMetaboxState( true );
		} catch ( error ) {
			// Do nothing
		}
	};

	const save = async () => {
		// Detects a popup load (a reopened row) started between this save and its refresh.
		const saveStartRequestId = getLatestRequest( 'seo-meta' );
		const savedMetaSettings = unsavedMetaSettings;
		const savedPostId = staticSelect( STORE_NAME ).getActivePostId();
		const schemaValidation = validateCustomJsonLdSchemas(
			unsavedMetaSettings?.schemas
		);
		if ( ! schemaValidation.valid ) {
			throw new Error( schemaValidation.message );
		}

		const isUser = !! surerank_seo_popup?.is_user;
		const isTerm =
			! isUser &&
			( !! surerank_seo_popup.is_taxonomy ||
				isCurrentPage( 'term.php' ) );

		let idParam = { post_id: surerank_seo_popup?.post_id };
		let dataUrl = POST_SEO_DATA_URL;
		let savedType = 'post';
		if ( isUser ) {
			idParam = { user_id: surerank_seo_popup?.user_id };
			dataUrl = USER_SEO_DATA_URL;
			savedType = 'user';
		} else if ( isTerm ) {
			idParam = { term_id: surerank_seo_popup?.term_id };
			dataUrl = TERM_SEO_DATA_URL;
			savedType = 'taxonomy';
		}

		const queryParams = {
			metaData: savedMetaSettings,
			...idParam,
		};

		const response = await apiFetch( {
			path: dataUrl,
			method: 'POST',
			data: queryParams,
		} );
		if ( ! response.success ) {
			throw response;
		}
		// Update the store with the latest data. The refresh starts after the save landed, so
		// its response is the newest data and supersedes any popup load still in flight.
		// Skip it when the listing page has switched to another row, so the save never
		// supersedes that row's own load.
		if ( staticSelect( STORE_NAME ).getActivePostId() === savedPostId ) {
			const refreshRequestId = startRequest( 'seo-meta' );
			updateMetaSettings(
				savedMetaSettings,
				savedPostId,
				refreshRequestId
			);
			setTimeout( () => {
				// Skip when the listing page has switched rows, or reopened this one, meanwhile.
				if (
					! isLatestRequest( 'seo-meta', refreshRequestId ) ||
					refreshRequestId !== saveStartRequestId + 1 ||
					staticSelect( STORE_NAME ).getActivePostId() !== savedPostId
				) {
					return;
				}
				// Only the submitted values are saved, edits made meanwhile stay unsaved.
				clearSavedMetaSettings( savedMetaSettings );
			}, 1000 );
		}

		// Notify the seo-bar (listing page) to refresh the badge for this post.
		const savedId =
			queryParams.user_id ?? queryParams.term_id ?? queryParams.post_id;
		if ( savedId ) {
			window.dispatchEvent(
				new CustomEvent( 'surerank:seo-data-saved', {
					detail: {
						postId: savedId,
						type: savedType,
					},
				} )
			);
		}

		return response;
	};

	const hasUnsavedSettings =
		Object.keys( unsavedMetaSettings ?? {} ).length > 0;

	return (
		<GlobalSaveButton
			onClick={ save }
			className={
				! hasUnsavedSettings
					? 'opacity-60 bg-background-brand cursor-not-allowed pointer-events-none'
					: ''
			}
			icon={ hasUnsavedSettings ? <DotIcon /> : null }
			disabled={ ! hasUnsavedSettings }
		/>
	);
};

export default SaveButton;
