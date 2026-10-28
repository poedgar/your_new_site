import { __ } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	GenerateContent as RenderGeneratedContent,
	UpgradeCPT,
} from '@GlobalComponents/fix-it-for-me';
import { STORE_NAME } from '@Store/constants';
import {
	PROCESS_STATUSES,
	CONTENT_GENERATION_MAPPING,
	COMBINED_META_TYPE,
	COMBINED_META_FIELD_KEYS,
	isCombinedMetaField,
} from '@Global/constants';
import { generateContent as generateContentAPI } from '@Functions/api';
import ContentGenerationError from '@GlobalComponents/content-generation-error';
import useFixPageSeoCheck from './hooks/useFixPageSeoCheck';

// Session flag: once the SaaS reports it cannot produce a combined response
// (combined_meta unsupported / not seeded), skip the combined request for the
// rest of the editor session so each field does not repeat a failed combined
// call and burn a second rate-limit hit.
let combinedMetaUnsupported = false;

const GenerateContent = ( props ) => {
	const { updateAppSettings } = useDispatch( STORE_NAME );
	const {
		status = PROCESS_STATUSES.IDLE,
		allGeneratedContents = {},
		selectedCheckId,
		selectedFieldKey,
		checkType,
		genError,
		postId,
		onSuccess,
		onError,
		onProgress,
		onUseThis,
		previousScreen,
		currentFieldType,
	} = useSelect( ( select ) => {
		const seoChecks = select( STORE_NAME ).getPageSeoChecks();
		const appSettings = select( STORE_NAME ).getAppSettings();
		return {
			status: appSettings?.generateContentProcess,
			allGeneratedContents: appSettings?.generatedContents,
			selectedCheckId:
				appSettings?.selectedCheckId || seoChecks?.selectedItem,
			selectedFieldKey: appSettings?.selectedFieldKey,
			genError: appSettings?.error,
			postId: seoChecks?.postId,
			checkType: seoChecks?.checkType,
			previousScreen: appSettings?.previousScreen,
			onSuccess: appSettings?.onSuccess,
			onError: appSettings?.onError,
			onProgress: appSettings?.onProgress,
			onUseThis: appSettings?.onUseThis,
			currentFieldType: appSettings?.currentFieldType,
		};
	}, [] );

	// Get the content for the current check/field
	const currentKey = selectedFieldKey || selectedCheckId;
	const content = allGeneratedContents[ currentKey ] || [];

	const { handleFixContent, isFixing } = useFixPageSeoCheck( {
		onSuccess,
		onError,
		onProgress,
	} );

	// Generate content using the real API.
	// forceSingle bypasses the combined request so an explicit Regenerate only
	// refreshes the field the user is viewing, leaving the other cached fields
	// intact.
	const generateContent = async ( { forceSingle = false } = {} ) => {
		if ( status === PROCESS_STATUSES.IN_PROGRESS ) {
			return; // Prevent multiple simultaneous requests
		}

		try {
			updateAppSettings( {
				generateContentProcess: PROCESS_STATUSES.START,
			} );

			// Get the content type from the selected check or field key
			const contentType = selectedCheckId || selectedFieldKey;
			const mappedType = CONTENT_GENERATION_MAPPING[ contentType ]
				? CONTENT_GENERATION_MAPPING[ contentType ]
				: '';

			// Store mapped type for passing to UI components
			updateAppSettings( {
				currentFieldType: mappedType,
			} );

			if ( ! mappedType ) {
				throw {
					message: __(
						'No content type selected. Please select an item to generate content for.',
						'surerank'
					),
					code: 'no_content_type',
				};
			}

			updateAppSettings( {
				generateContentProcess: PROCESS_STATUSES.IN_PROGRESS,
			} );

			// Get the post/term ID
			const id =
				postId ||
				window?.surerank_seo_popup?.post_id ||
				window?.surerank_seo_popup?.term_id;
			const isTermPage =
				checkType === 'taxonomy' ||
				window?.surerank_seo_popup?.is_taxonomy === '1';

			// Meta box fields (title/description/social) share one combined
			// request so all four are generated at once instead of per field.
			// Regenerate (forceSingle) targets only the active field.
			const useCombined =
				isCombinedMetaField( contentType ) &&
				! forceSingle &&
				! combinedMetaUnsupported;

			// Turn a list of suggestion strings into unique UI items,
			// dropping anything that is not a usable non-empty string.
			const toItems = ( list, seed ) =>
				( Array.isArray( list ) ? list : [] )
					.filter(
						( item ) => typeof item === 'string' && item.trim()
					)
					.map( ( item, index ) => ( {
						id: seed + index,
						text: item,
					} ) );

			if ( useCombined ) {
				let combinedResponse;
				try {
					combinedResponse = await generateContentAPI(
						COMBINED_META_TYPE,
						id,
						isTermPage
					);
					if ( ! combinedResponse?.success ) {
						throw combinedResponse;
					}
				} catch ( combinedError ) {
					// Only fall back when the SaaS responded but could not produce
					// combined content (combined_meta unsupported / not seeded).
					// Every other error (credit limits, auth, network, malformed
					// output) is preserved as-is, so the right screen shows and we
					// do not spend a second request on a genuine failure.
					if ( combinedError?.code !== 'content_generation_error' ) {
						throw combinedError;
					}
					// Remember for the session so other fields skip the combined
					// request and go straight to single-field (no repeated second
					// rate-limit hit per field).
					combinedMetaUnsupported = true;

					const fallbackResponse = await generateContentAPI(
						mappedType,
						id,
						isTermPage
					);
					if ( ! fallbackResponse?.success ) {
						throw fallbackResponse;
					}
					const fallbackItems = toItems(
						fallbackResponse.content,
						Date.now()
					);
					if ( ! fallbackItems.length ) {
						throw {
							message: __(
								'The AI response was empty. Please try again.',
								'surerank'
							),
							code: 'invalid_ai_response',
						};
					}
					updateAppSettings( {
						generateContentProcess: PROCESS_STATUSES.COMPLETED,
						generatedContents: {
							...allGeneratedContents,
							[ currentKey ]: fallbackItems,
						},
					} );
					return;
				}

				// response.content is a keyed map of variation lists. Validate the
				// full contract before trusting it: every expected key must be a
				// non-empty array containing at least one usable string. A partial
				// or malformed map is a retryable failure, not a silent empty
				// "completed" state.
				const combined = combinedResponse.content || {};
				const outputKeys = [
					...new Set(
						COMBINED_META_FIELD_KEYS.map(
							( key ) => CONTENT_GENERATION_MAPPING[ key ]
						)
					),
				];
				const isValidCombined = outputKeys.every(
					( key ) =>
						Array.isArray( combined[ key ] ) &&
						combined[ key ].some(
							( item ) => typeof item === 'string' && item.trim()
						)
				);
				if ( ! isValidCombined ) {
					throw {
						message: __(
							'The AI response was incomplete. Please try again.',
							'surerank'
						),
						code: 'invalid_combined_response',
					};
				}

				// Distribute each list to every field it covers so switching
				// fields reuses this single response without another request.
				let uniqueId = Date.now();
				const distributedContents = { ...allGeneratedContents };
				COMBINED_META_FIELD_KEYS.forEach( ( key ) => {
					const items = toItems(
						combined[ CONTENT_GENERATION_MAPPING[ key ] ],
						uniqueId
					);
					uniqueId += items.length; // keep ids unique across fields
					distributedContents[ key ] = items;
				} );

				updateAppSettings( {
					generateContentProcess: PROCESS_STATUSES.COMPLETED,
					generatedContents: distributedContents,
				} );
				return;
			}

			// Single-field request (non-combined checks/fields).
			const response = await generateContentAPI(
				mappedType,
				id,
				isTermPage
			);
			if ( ! response?.success ) {
				throw response;
			}
			const singleItems = toItems( response.content, Date.now() );
			if ( ! singleItems.length ) {
				throw {
					message: __(
						'The AI response was empty. Please try again.',
						'surerank'
					),
					code: 'invalid_ai_response',
				};
			}
			updateAppSettings( {
				generateContentProcess: PROCESS_STATUSES.COMPLETED,
				generatedContents: {
					...allGeneratedContents,
					[ currentKey ]: singleItems,
				},
			} );
		} catch ( error ) {
			updateAppSettings( {
				generateContentProcess: PROCESS_STATUSES.FAILED,
				error: {
					message:
						error?.message ??
						__(
							'An unexpected error occurred while generating content.',
							'surerank'
						),
					code: error?.code || 'unknown_error',
				},
			} );
		}
	};

	const handleRegenerate = () => {
		// Regenerate only the field being viewed, not every combined field.
		generateContent( { forceSingle: true } );
	};

	// Handle "Use Me" action for generated content
	const handleUseContent = async ( selectedContent ) => {
		// Check if this is field-based generation (from magic button)
		if ( onUseThis && selectedFieldKey ) {
			// Call the field success callback with the selected content
			onUseThis( selectedFieldKey, selectedContent );
			updateAppSettings( {
				currentScreen: previousScreen,
				previousScreen: '',
				// Clear field generation state
				selectedCheckId: null,
				selectedFieldKey: null,
				onUseThis: null,
				generateContentProcess: PROCESS_STATUSES.IDLE,
				error: null,
			} );
			return;
		}
		await handleFixContent( selectedContent );
	};

	// Auto-start generation when component mounts and no content exists
	useEffect( () => {
		if (
			status === PROCESS_STATUSES.IDLE &&
			( selectedCheckId || selectedFieldKey ) &&
			! content.length
		) {
			generateContent();
		}
	}, [] );

	if ( status === PROCESS_STATUSES.FAILED ) {
		// If the error is due to not having a Pro plan and limit exceeded, show the upgrade component
		if ( genError?.code === 'require_pro' ) {
			return <UpgradeCPT />;
		}

		if ( genError?.code === 'limit_exceeded' ) {
			return (
				<UpgradeCPT
					title={ __( 'Daily Limit Reached', 'surerank' ) }
					description={ __(
						"You've used all your AI credits for today. Your credits will refresh automatically tomorrow, so you can continue creating content.",
						'surerank'
					) }
					showButton={ false }
				/>
			);
		}

		// For other errors, show the ContentGenerationError component
		return (
			<ContentGenerationError
				error={ genError }
				onRetry={ handleRegenerate }
				title={ __( 'Generation Failed', 'surerank' ) }
				supportText={ __(
					'Click here to contact support.',
					'surerank'
				) }
				retryText={ __( 'Retry', 'surerank' ) }
			/>
		);
	}

	// Show content or empty state for completed state
	if ( status === PROCESS_STATUSES.COMPLETED ) {
		return (
			<RenderGeneratedContent
				{ ...props }
				contents={ content }
				onRegenerate={ handleRegenerate }
				onUseThis={ handleUseContent }
				fixing={ isFixing }
				fieldType={ currentFieldType }
			/>
		);
	}

	// Default fallback
	return (
		<RenderGeneratedContent
			{ ...props }
			contents={ content }
			onRegenerate={ handleRegenerate }
			onUseThis={ handleUseContent }
			generating={
				status === PROCESS_STATUSES.IN_PROGRESS ||
				status === PROCESS_STATUSES.START
			}
			error={ status === PROCESS_STATUSES.FAILED ? genError : null }
			fixing={ isFixing }
			fieldType={ currentFieldType }
		/>
	);
};

export default GenerateContent;
