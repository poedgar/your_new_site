import { __ } from '@wordpress/i18n';
import { createRoot, useEffect, useState } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import { useDispatch } from '@wordpress/data';
import { STORE_NAME } from '@Store/constants';
import useAuthPolling from '@Global/hooks/use-auth-polling';
import { LEARN_MORE_AI_AUTH as LEARN_MORE_URL } from '@Global/constants';
import { batchStatus, getAuth } from '@Functions/api';
import AiAuth from './ai-auth';

const ROOT_ID = 'surerank-bulk-content-generation-tracker-root';
const REFRESH_INTERVAL = 2000; // 2 seconds
const MAX_RETRIES = 30;
const WP_LISTING_FORM_SELECTOR = '#posts-filter';
const WP_SELECT_SELECTOR = '#bulk-action-selector-top';
const LOCAL_STORAGE_KEY = 'surerank_bulk_content_generation_status';

/* global localStorage, toast */

const initBulkContentGenerationTracker = () => {
	domReady( () => {
		let rootElement = document.getElementById( ROOT_ID );
		if ( ! rootElement ) {
			rootElement = document.createElement( 'div' );
			rootElement.id = ROOT_ID;
			rootElement.classList.add( 'surerank-root' );
			document.body.appendChild( rootElement );
		}

		// React rendering can be added here if needed in the future
		const root = createRoot( rootElement );
		if ( ! root ) {
			return;
		}
		root.render( <BulkContentGenerationTracker /> );
	} );
};

const BulkContentGenerationTracker = () => {
	const { setPageSeoCheck } = useDispatch( STORE_NAME );
	const [ open, setOpen ] = useState( false );
	const [ showUpgrade, setShowUpgrade ] = useState( false );

	const storeBatchGenerationStatus = ( detail ) => {
		setPageSeoCheck( 'batchGeneration', detail );

		if ( detail?.completed && detail?.response?.failed_ids ) {
			// The server flags which failures an upgrade resolves, so this never has
			// to match on the wording of a translated message.
			const requiresProUpgrade = Object.values(
				detail.response.failed_ids
			).some( ( failure ) => failure?.upgrade_required === true );
			if ( requiresProUpgrade ) {
				setShowUpgrade( true );
				setOpen( true );
			}
		}
	};

	// Check if user is authenticated
	const isAuthenticated = () => {
		return window?.surerank_globals?.ai_authenticated ?? false;
	};

	// Get form data for bulk action
	const getFormData = () => {
		const form = document.querySelector( WP_LISTING_FORM_SELECTOR );
		const select = document.querySelector( WP_SELECT_SELECTOR );

		if ( ! form || ! select ) {
			return null;
		}

		const action = select.value;
		if ( ! action.startsWith( 'surerank_generate_' ) ) {
			return null;
		}

		const postIds = [];
		form.querySelectorAll(
			'input[name="post[]"]:checked, input[name="delete_tags[]"]:checked'
		).forEach( ( input ) => {
			postIds.push( input.value );
		} );

		if ( postIds.length === 0 ) {
			return null;
		}

		return { form, action, postIds };
	};

	// Store bulk action data in localStorage
	const storeBulkActionData = ( action, postIds ) => {
		localStorage.setItem(
			LOCAL_STORAGE_KEY,
			JSON.stringify( { status: 'started', action, postIds } )
		);
	};

	// Set up form submission listener to track bulk action
	useEffect( () => {
		const form = document.querySelector( WP_LISTING_FORM_SELECTOR );
		if ( ! form ) {
			return;
		}

		const trackFormSubmission = ( event ) => {
			// Early return if local storage value already exists
			if ( localStorage?.getItem( LOCAL_STORAGE_KEY ) ) {
				return;
			}

			const formData = getFormData();
			if ( ! formData ) {
				return;
			}

			// Check if user is authenticated
			if ( ! isAuthenticated() ) {
				// Prevent default form submission
				event.preventDefault();
				// Show AI authentication modal
				setOpen( true );
				return;
			}

			// Store the bulk action data
			storeBulkActionData( formData.action, formData.postIds );
		};

		form.addEventListener( 'submit', trackFormSubmission );
		return () => form.removeEventListener( 'submit', trackFormSubmission );
	}, [ open ] );

	// Set up polling to check the status of the bulk action
	useEffect( () => {
		// Read status from localStorage
		const statusData = localStorage.getItem( LOCAL_STORAGE_KEY );
		if ( ! statusData ) {
			return;
		}
		const {
			status = '',
			action = '',
			postIds = [],
		} = JSON.parse( statusData );
		if ( status !== 'started' ) {
			return;
		}

		let retries = 0;
		const fetchStatus = async () => {
			// Stop if max retries reached
			if ( retries >= MAX_RETRIES ) {
				localStorage.removeItem( LOCAL_STORAGE_KEY );
				storeBatchGenerationStatus( {
					completed: true,
					maxRetriesReached: true,
					action,
					response: {},
					error: null,
					retries,
				} );
				return;
			}
			// increment retries
			retries++;

			try {
				const response = await batchStatus();
				if ( ! response.found ) {
					throw response;
				}
				storeBatchGenerationStatus( {
					completed: response.status === 'executed',
					maxRetriesReached: false,
					response,
					action,
					error: null,
					retries,
				} );
				if ( response.status === 'executed' ) {
					localStorage.removeItem( LOCAL_STORAGE_KEY );
					return;
				}
				// Recursively call fetchStatus after interval
				setTimeout( fetchStatus, REFRESH_INTERVAL );
			} catch ( error ) {
				// Handle error if needed
				if ( retries < MAX_RETRIES ) {
					setTimeout( fetchStatus, REFRESH_INTERVAL );
					return;
				}
				localStorage.removeItem( LOCAL_STORAGE_KEY );
			}
		};

		storeBatchGenerationStatus( {
			completed: false,
			maxRetriesReached: false,
			action,
			response: { pending_ids: postIds },
			error: null,
			retries,
		} );
		// Initial call to start polling
		fetchStatus();
	}, [] );

	const { openAuthPopup } = useAuthPolling( () => {
		// On success callback - submit the form programmatically
		const formData = getFormData();

		if ( formData ) {
			// Update global authentication status
			if ( window?.surerank_globals ) {
				window.surerank_globals.ai_authenticated = true;
			}

			// Manually track the submission since form.submit() bypasses event listeners
			storeBulkActionData( formData.action, formData.postIds );

			// Close the modal
			setOpen( false );

			// Submit the form programmatically
			// Use setTimeout to ensure state updates complete
			setTimeout( () => formData.form.submit(), 100 );
		}
	} );

	const handleClickLearnMore = () =>
		window.open( LEARN_MORE_URL, '_blank', 'noopener' );

	const handleGetStarted = async () => {
		try {
			const response = await getAuth();
			if ( ! response?.success ) {
				throw new Error(
					response?.message ||
						__( 'Authentication failed', 'surerank' )
				);
			}
			if ( response?.auth_url ) {
				openAuthPopup( response.auth_url );
			}
		} catch ( error ) {
			toast.error(
				error?.message ||
					__( 'An error occurred during authentication', 'surerank' )
			);
		}
	};

	const handleSetOpen = ( isOpen ) => {
		setOpen( isOpen );
		if ( ! isOpen ) {
			setShowUpgrade( false );
		}
	};

	return (
		<AiAuth
			open={ open }
			setOpen={ handleSetOpen }
			onClickLearnMore={ handleClickLearnMore }
			onClickGetStarted={ handleGetStarted }
			showUpgrade={ showUpgrade }
		/>
	);
};

export default initBulkContentGenerationTracker;
