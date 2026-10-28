import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
// import { Tooltip } from '@brainstormforce/starter-templates-components';
import Tooltip from '../components/tooltip/tooltip';
import { __ } from '@wordpress/i18n';
import { useStateValue } from '../store/store';
import ICONS from '../../icons';
import Logo from '../components/logo';
import {
	getStepIndex,
	getStoredState,
	storeCurrentState,
} from '../utils/functions';
import {
	getDemo,
	checkRequiredPlugins,
	checkFileSystemPermissions,
} from './import-site/import-utils';
import LoadingSpinner from '../components/loading-spinner';
import { STEPS } from './util';
import toast from 'react-hot-toast';
const { adminUrl } = starterTemplates;
const $ = jQuery;

const pageBuilders = [ 'gutenberg', 'elementor', 'beaver-builder' ];

const Steps = () => {
	const storedState = useStateValue();
	const [ stateValue, dispatch ] = storedState;
	const {
		builder,
		searchTerms,
		searchTermsWithCount,
		currentIndex,
		currentCustomizeIndex,
		templateResponse,
		designStep,
		importError,
	} = stateValue;
	const [ settingHistory, setSettinghistory ] = useState( true );
	// Captured during the first render. Effects declared above the deep link one
	// rewrite the query string, so reading it later loses the entry values.
	const [ deepLinkParams ] = useState( () => {
		const params = new URLSearchParams( window.location.search );
		return {
			templateId: params.get( 'template_id' ) || '',
			stepIndex: parseInt( params.get( 'ci' ), 10 ) || 0,
		};
	} );
	const [ deepLinkLoading, setDeepLinkLoading ] = useState(
		() => !! deepLinkParams.templateId
	);
	const [ settingIndex, setSettingIndex ] = useState( true );
	const current = STEPS[ currentIndex ];
	const history = useNavigate();

	// Helper function to validate template data exists
	const hasTemplateData = ( state ) => {
		return (
			state &&
			( state.templateResponse ||
				state.selectedTemplateID ||
				state.templateResponse?.id ||
				( state.selectedTemplateID !== undefined &&
					state.selectedTemplateID !== '' ) )
		);
	};

	useEffect( () => {
		$( document ).on( 'heartbeat-send', sendHeartbeat );
		$( document ).on( 'heartbeat-tick', heartbeatDone );
	}, [ searchTerms, searchTermsWithCount ] );

	const heartbeatDone = ( event, data ) => {
		// Check for our data, and use it.
		if ( ! data[ 'ast-sites-search-terms' ] ) {
			return;
		}
		dispatch( {
			type: 'set',
			searchTerms: [],
			searchTermsWithCount: [],
		} );
	};

	const sendHeartbeat = ( event, data ) => {
		// Add additional data to Heartbeat data.
		if ( searchTerms.length > 0 ) {
			data[ 'ast-sites-search-terms' ] = searchTermsWithCount;
			data[ 'ast-sites-builder' ] = builder;
		}
	};

	useEffect( () => {
		const previousIndex = parseInt( currentIndex ) - 1;
		const nextIndex = parseInt( currentIndex ) + 1;

		if ( nextIndex > 0 && nextIndex < STEPS.length ) {
			document.body.classList.remove( STEPS[ nextIndex ].class );
		}

		if ( previousIndex > 0 ) {
			document.body.classList.remove( STEPS[ previousIndex ].class );
		}

		if (
			currentIndex >= 0 &&
			currentIndex < STEPS.length &&
			STEPS[ currentIndex ]
		) {
			document.body.classList.add( STEPS[ currentIndex ].class );
		}
	} );

	useEffect( () => {
		if ( importError ) {
			document.body.classList.add( 'st-error' );
		} else {
			document.body.classList.remove( 'st-error' );
		}
	}, [ importError ] );

	useEffect( () => {
		const currentUrlParams = new URLSearchParams( window.location.search );
		const storedStateValue = JSON.parse(
			localStorage.getItem( 'starter-templates-onboarding' )
		);
		const urlIndex = parseInt( currentUrlParams.get( 'ci' ) ) || 0;
		const designIndex =
			parseInt( currentUrlParams.get( 'designStep' ) ) || 0;
		const searchTerm = currentUrlParams.get( 's' ) || '';

		if ( urlIndex !== 0 ) {
			const stateValueUpdates = {};
			for ( const key in storedStateValue ) {
				if ( key === 'currentIndex' || key === 'siteSearchTerm' ) {
					continue;
				}

				if ( key === 'builder' ) {
					continue;
				}
				stateValueUpdates[ key ] = storedStateValue[ `${ key }` ];
			}

			dispatch( {
				type: 'set',
				currentIndex: urlIndex,
				designStep: designIndex,
				siteSearchTerm: searchTerm,
				...stateValueUpdates,
			} );

			// Validate template data exists when at CI index 3 or more
			if ( urlIndex >= 3 ) {
				if ( ! hasTemplateData( storedStateValue ) ) {
					// Template data is missing, prevent user from proceeding beyond current index.
					dispatch( {
						type: 'set',
						// Reset to a safe state where user can reselect template.
						currentIndex: 2, // Go back to template selection step.
					} );
				}
			}
		} else {
			localStorage.removeItem( 'starter-templates-onboarding' );
		}

		setSettinghistory( false );
	}, [ history ] );

	useEffect( () => {
		const currentUrlParams = new URLSearchParams( window.location.search );
		const urlIndex = parseInt( currentUrlParams.get( 'ci' ) ) || 0;
		const builderValue = currentUrlParams.get( 'builder' ) || '';

		if ( currentIndex === getStepIndex( 'page-builder' ) ) {
			currentUrlParams.delete( 'ci' );
			currentUrlParams.delete( 'ai' );
			currentUrlParams.delete( 'builder' );
			if ( builderValue && pageBuilders.includes( builderValue ) ) {
				dispatch( {
					type: 'set',
					builder: builderValue,
					currentIndex: 2,
				} );
			}
			history(
				window.location.pathname + '?' + currentUrlParams.toString()
			);
		}

		if (
			( currentIndex !== getStepIndex( 'page-builder' ) &&
				urlIndex !== currentIndex ) ||
			templateResponse !== null
		) {
			// Prevent navigation forward if at CI index 3+ and template data is missing.
			if ( currentIndex >= 3 ) {
				if ( ! hasTemplateData( stateValue ) ) {
					// If template data is missing, don't allow forward navigation.
					// Reset to a safe state.
					dispatch( {
						type: 'set',
						currentIndex: 2, // Go back to template selection step.
					} );
					return; // Exit early to prevent state storage.
				}
			}
			storeCurrentState( stateValue );
			currentUrlParams.set( 'ci', currentIndex );
			history(
				window.location.pathname + '?' + currentUrlParams.toString()
			);
		}

		// Execute only for the last Customization step.
		if (
			designStep !== 0 &&
			urlIndex === STEPS.length - 1 &&
			templateResponse !== null
		) {
			storeCurrentState( stateValue );
			currentUrlParams.set( 'designStep', designStep );
			history(
				window.location.pathname + '?' + currentUrlParams.toString()
			);
		}

		if ( currentIndex === getStepIndex( 'site-list' ) ) {
			dispatch( {
				type: 'set',
				activePalette: {},
				activePaletteSlug: 'default',
				typography: {},
				typographyIndex: 0,
			} );
		}

		setSettingIndex( false );
	}, [ currentIndex, templateResponse, designStep ] );

	useEffect( () => {
		if ( ! stateValue.isExternalDeepLink || ! templateResponse ) {
			return;
		}

		const templateUpdates = {};
		const templateName = templateResponse?.title?.rendered || '';
		const templateType = templateResponse?.[ 'astra-site-type' ] || '';

		// Only overwrite what the response actually carries, an empty value
		// would blank out the name/type the site list already stored.
		if ( templateName ) {
			templateUpdates.selectedTemplateName = templateName;
		}
		if ( templateType ) {
			templateUpdates.selectedTemplateType = templateType;
		}

		if ( Object.keys( templateUpdates ).length ) {
			dispatch( {
				type: 'set',
				...templateUpdates,
			} );
		}
	}, [ templateResponse, stateValue.isExternalDeepLink ] );

	useEffect( () => {
		const templateIdParam = deepLinkParams.templateId;

		if ( ! templateIdParam ) {
			return;
		}

		const dropTemplateIdParam = () => {
			const urlParams = new URLSearchParams( window.location.search );
			urlParams.delete( 'template_id' );
			history( window.location.pathname + '?' + urlParams.toString() );
		};

		const templateIdValue = parseInt( templateIdParam, 10 );

		// A non numeric id never resolves to a template, bail before requesting it.
		if ( isNaN( templateIdValue ) || templateIdValue <= 0 ) {
			dropTemplateIdParam();
			setDeepLinkLoading( false );
			return;
		}

		// Strict mode keeps `template_id` in the URL, so this effect runs again on
		// every reload. Leave the restored step alone once the user is past the
		// customizer, but only when the stored state belongs to this template.
		// Stored state for a different template would rehydrate that one instead
		// and silently import it, and with nothing stored at all the step guards
		// drop the user on the site list, so reloading the deep linked template
		// is the better recovery in both cases.
		const storedDeepLinkState = getStoredState();

		if (
			deepLinkParams.stepIndex > getStepIndex( 'customizer' ) &&
			hasTemplateData( storedDeepLinkState ) &&
			storedDeepLinkState?.selectedTemplateID === templateIdValue
		) {
			setDeepLinkLoading( false );
			return;
		}

		if ( ! starterTemplates.lockDeepLinkedTemplate ) {
			dropTemplateIdParam();
		}

		const loadDeepLinkedTemplate = async () => {
			dispatch( {
				type: 'set',
				selectedTemplateID: templateIdValue,
				isExternalDeepLink: true,
			} );

			const templateData = await getDemo( templateIdValue, storedState );

			// `getDemo` handles its own errors, so an empty response is the only
			// signal the template could not be fetched. Advancing anyway strands
			// the user on a customizer with no template and no way back.
			if ( ! templateData ) {
				dropTemplateIdParam();

				// Clearing the flag also restores the back / change template
				// controls that strict mode hides, so the fallback is usable.
				dispatch( {
					type: 'set',
					isExternalDeepLink: false,
					currentIndex: getStepIndex( 'site-list' ),
				} );
				setDeepLinkLoading( false );

				toast.error(
					__(
						'We could not load that template. Choose another one to continue.',
						'astra-sites'
					),
					{ duration: 6000 }
				);
				return;
			}

			const templateBuilder =
				templateData?.[ 'astra-site-page-builder' ] || '';

			dispatch( {
				type: 'set',
				builder: pageBuilders.includes( templateBuilder )
					? templateBuilder
					: 'gutenberg',
			} );

			await checkRequiredPlugins( storedState );
			checkFileSystemPermissions( storedState );

			dispatch( {
				type: 'set',
				currentIndex: getStepIndex( 'customizer' ),
				currentCustomizeIndex: 1,
			} );

			setDeepLinkLoading( false );
		};

		loadDeepLinkedTemplate();
	}, [] );

	window.onpopstate = () => {
		if (
			!! designStep &&
			designStep !== 1 &&
			currentIndex !== getStepIndex( 'site-list' )
		) {
			if ( currentIndex >= getStepIndex( 'survey' ) ) {
				dispatch( {
					type: 'set',
					currentIndex: currentIndex - 1,
				} );
			} else {
				dispatch( {
					type: 'set',
					designStep: designStep - 1,
					currentCustomizeIndex: currentCustomizeIndex - 1,
					currentIndex,
				} );
			}
		}
		if ( currentIndex > getStepIndex( 'site-list' ) && designStep === 1 ) {
			dispatch( {
				type: 'set',
				currentIndex: currentIndex - 1,
			} );
		}
	};

	return (
		<div className={ `st-step ${ current?.class ?? '' }` }>
			{ ! deepLinkLoading &&
				! [ getStepIndex( 'customizer' ) ].includes( currentIndex ) && (
					<div className="step-header">
						{ current.header ? (
							current.header
						) : (
							<div className="row">
								<div className="col">
									<Logo />
								</div>
								<div className="right-col">
									<div className="col exit-link">
										<a href={ adminUrl }>
											<Tooltip
												content={ __(
													'Exit to Dashboard',
													'astra-sites'
												) }
											>
												{ ICONS.remove }
											</Tooltip>
										</a>
									</div>
								</div>
							</div>
						) }

						<canvas
							id="ist-bashcanvas"
							width={ window.innerWidth }
							height={ window.innerHeight }
						/>
					</div>
				) }
			{ deepLinkLoading && (
				<div className="flex items-center justify-center w-full h-screen">
					<LoadingSpinner
						widthClassName="w-10"
						heightClassName="h-10"
						colorClassName="text-accent-st"
					/>
				</div>
			) }
			{ settingHistory === false &&
			settingIndex === false &&
			deepLinkLoading === false &&
			current
				? current.content
				: null }
		</div>
	);
};

export default Steps;
