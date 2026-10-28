import { __ } from '@wordpress/i18n';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import {
	select as staticSelect,
	dispatch as staticDispatch,
	useSelect,
} from '@wordpress/data';
import { Label, Alert, Accordion, Text, Button, Badge } from '@bsf/force-ui';
import { Info, Trash, Sparkles, RefreshCw } from 'lucide-react';
import { applyFilters } from '@wordpress/hooks';
import { STORE_NAME } from '@Store/constants';
import { flat } from '@Functions/variables';
import WpSchemaProNotice from '@/global/components/wp-schema-pro-notice';
import { cn } from '@Functions/utils';
import {
	renderFieldCommon,
	renderFieldSwitch,
} from '@AdminComponents/schema-utils/render-helper';
import { AddFieldMenu } from '@AdminComponents/schema-utils/add-field-menu';
import { DeleteFieldButton } from '@AdminComponents/schema-utils/delete-field-button';
import {
	noFieldsAlert,
	generateUUID,
	isSchemaTypeValid,
	processFields,
	shouldShowField,
	getAvailableFields,
	canDeleteField,
	sortFieldsByPriority,
} from '@AdminComponents/schema-utils/utils';
import Modal from '@/apps/admin-general/schema/modal';
import { SeoPopupTooltip } from '@AdminComponents/tooltip';
import ConfirmationPopover from '@/global/components/confirmation-popover';
import { getEditorData } from '@SeoPopup/modal';
import {
	recommendSchemas,
	trackSchemaRecommendationEvent,
} from '@/functions/api';
import { redirectToPricingPage } from '@/functions/nudges';

const normalizeSchemaKey = ( value = '' ) =>
	String( value )
		.toLowerCase()
		.replace( /[^a-z0-9]/g, '' );

const recommendText = __( 'Recommend Schema Using AI', 'surerank' );
const recommendingText = __( 'Recommending…', 'surerank' );
const alreadyAdded = __( 'Already Added', 'surerank' );
const addNow = __( 'Add', 'surerank' );
const normalizeRecommendation = ( recommendation = {} ) => {
	const parent =
		recommendation?.parent_schema ||
		recommendation?.parent ||
		recommendation?.schema ||
		'';
	const child =
		recommendation?.child_schema_type ||
		recommendation?.child_type ||
		recommendation?.type ||
		parent;

	return {
		...recommendation,
		parent_schema: parent,
		child_schema_type: child,
		schema: recommendation?.schema || child || parent,
	};
};

const getPairKey = ( parent, child ) =>
	`${ normalizeSchemaKey( parent ) }::${ normalizeSchemaKey( child ) }`;

const getSchemaTypeOptionsByParent = ( parent, schemaTypeOptions ) => {
	const options = schemaTypeOptions?.[ parent ] || {};

	if ( options?.options ) {
		return options.options;
	}

	if ( Array.isArray( options ) ) {
		return options.reduce( ( acc, option ) => {
			if ( option?.value ) {
				acc[ option.value ] = option?.label || option.value;
			}
			return acc;
		}, {} );
	}

	const flattened = {};
	Object.values( options ).forEach( ( group ) => {
		if ( group?.options ) {
			Object.assign( flattened, group.options );
		}
	} );

	return Object.keys( flattened ).length > 0 ? flattened : options;
};

const getGroupChildren = ( group = {} ) => {
	if ( Array.isArray( group?.children ) ) {
		return group.children;
	}

	if ( Array.isArray( group?.items ) ) {
		return group.items;
	}

	return [];
};

const buildGroupedRecommendations = (
	responseRecommendations,
	groupedData
) => {
	if ( Array.isArray( groupedData ) ) {
		return groupedData
			.map( ( group ) => {
				const parent = group?.parent_schema || '';
				const rawChildren = getGroupChildren( group );
				return {
					parent_schema: parent,
					children: rawChildren.map( normalizeRecommendation ),
				};
			} )
			.filter( ( group ) => group.parent_schema );
	}

	if (
		groupedData &&
		typeof groupedData === 'object' &&
		! Array.isArray( groupedData )
	) {
		return Object.entries( groupedData ).map( ( [ parent, children ] ) => {
			const rawChildren = Array.isArray( children )
				? children
				: getGroupChildren( children );

			return {
				parent_schema: parent,
				children: rawChildren.map( normalizeRecommendation ),
			};
		} );
	}

	const groupedMap = new Map();
	( Array.isArray( responseRecommendations ) ? responseRecommendations : [] )
		.map( normalizeRecommendation )
		.forEach( ( recommendation ) => {
			const parent = recommendation?.parent_schema || '';
			if ( ! parent ) {
				return;
			}
			if ( ! groupedMap.has( parent ) ) {
				groupedMap.set( parent, [] );
			}
			groupedMap.get( parent ).push( recommendation );
		} );

	return Array.from( groupedMap.entries() ).map(
		( [ parent_schema, children ] ) => ( {
			parent_schema,
			children,
		} )
	);
};

const SchemaTab = ( { postMetaData, globalDefaults, updatePostMetaData } ) => {
	const closeModal = () => setIsModalOpen( false );
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ selectedSchema, setSelectedSchema ] = useState( '' );
	const [ selectedType, setSelectedType ] = useState( '' );
	const [ expandedSchemaId, setExpandedSchemaId ] = useState( null );
	const [ fieldItemIds, setFieldItemIds ] = useState( {} );
	const [ isGeneratingRecommendations, setIsGeneratingRecommendations ] =
		useState( false );
	const [ schemaRecommendations, setSchemaRecommendations ] = useState( [] );
	const [ recommendationsError, setRecommendationsError ] = useState( '' );
	const [ hasRecommendationAttempt, setHasRecommendationAttempt ] =
		useState( false );
	const [ dismissedRecommendationGroups, setDismissedRecommendationGroups ] =
		useState( {} );
	const isWpSchemaProActive = surerank_globals?.wp_schema_pro_active || false;

	const defaultSchemasObject = surerank_globals?.default_schemas || {};
	const defaultSchemas = Object.entries( defaultSchemasObject ).map(
		( [ id, schema ] ) => ( {
			id,
			...schema,
		} )
	);

	const globalSchemas = globalDefaults.schemas || {};
	// postMetaData.schemas is the server-resolved effective set: inherited
	// globals merged with this page's overrides and exclusions. An empty map
	// legitimately means every schema is excluded on this page, so only fall
	// back to globals while the settings fetch has not resolved yet.
	const schemas = postMetaData?.schemas ?? globalSchemas ?? {};
	const excludedSchemas = postMetaData?.excluded_schemas || [];
	// Server-owned provenance: global keys whose effective entry carries
	// page-level values. Refreshed on every save round-trip.
	const overriddenSchemas = Array.isArray( postMetaData?.overridden_schemas )
		? postMetaData.overridden_schemas
		: [];

	const validSchemas = useMemo(
		() =>
			Object.entries( schemas ).filter( ( [ , schema ] ) => {
				return isSchemaTypeValid( schema?.title );
			} ),
		[ schemas ]
	);
	const schemaTypeData = surerank_globals?.schema_type_data || {};
	const schemaTypeOptions = surerank_globals?.schema_type_options || {};

	const variableSuggestions = useMemo(
		() =>
			Object.entries( surerank_globals?.schema_variables || {} ).map(
				( [ value, label ] ) => ( {
					value,
					label,
				} )
			),
		[]
	);

	const availableSchemaTools = useMemo( () => {
		const uniqueSchemas = new Map();
		const freeSchemaParents = new Set(
			defaultSchemas.map( ( schema ) =>
				normalizeSchemaKey( schema?.title || '' )
			)
		);

		Object.entries( schemaTypeOptions || {} ).forEach( ( [ parent ] ) => {
			const normalizedParent = normalizeSchemaKey( parent );
			const options = getSchemaTypeOptionsByParent(
				parent,
				schemaTypeOptions
			);

			Object.entries( options || {} ).forEach(
				( [ childValue, childLabel ] ) => {
					const child = childValue || childLabel || parent;
					const pairKey = getPairKey( parent, child );

					if ( uniqueSchemas.has( pairKey ) ) {
						return;
					}

					uniqueSchemas.set( pairKey, {
						schema: child,
						title: childLabel || child,
						parent_schema: parent,
						child_schema_type: child,
						type: child,
						tier: freeSchemaParents.has( normalizedParent )
							? 'free'
							: 'pro',
						can_add: freeSchemaParents.has( normalizedParent ),
						is_pro: ! freeSchemaParents.has( normalizedParent ),
					} );
				}
			);
		} );

		return Array.from( uniqueSchemas.values() );
	}, [ defaultSchemas, schemaTypeOptions ] );

	const activeSchemasContext = useMemo( () => {
		const activeSchemas = Object.entries( schemas || {} )
			.map( ( [ id, schema ] ) => {
				const parent = schema?.title || '';
				const child =
					schema?.fields?.[ '@type' ] || schema?.type || parent || '';

				return {
					id,
					parent_schema: parent,
					child_schema_type: child,
					child: {
						title: child,
						'@type': child,
					},
				};
			} )
			.filter(
				( item ) =>
					normalizeSchemaKey( item.parent_schema ) &&
					normalizeSchemaKey( item.child_schema_type )
			);

		const activeParentSet = new Set(
			activeSchemas.map( ( schema ) =>
				normalizeSchemaKey( schema.parent_schema )
			)
		);

		return {
			activeSchemas,
			activeParentSet,
			activeSchemaTitles: Array.from(
				new Set(
					activeSchemas.map( ( schema ) => schema.parent_schema )
				)
			),
			activeSchemaTypes: Array.from(
				new Set(
					activeSchemas.map( ( schema ) => schema.child_schema_type )
				)
			),
		};
	}, [ schemas ] );

	const handleAddField = ( schemaId, fieldId ) => {
		cancelPendingReset( schemaId );
		const schemaTitle = schemas[ schemaId ]?.title;
		const allFields = schemaTypeData[ schemaTitle ] || [];
		const fieldToAdd = allFields.find( ( f ) => f.id === fieldId );

		if ( ! fieldToAdd ) {
			return;
		}

		let defaultValue = fieldToAdd.std !== undefined ? fieldToAdd.std : '';

		if ( fieldToAdd.type === 'Group' && fieldToAdd.fields ) {
			defaultValue = processFields( [ fieldToAdd ] )[ fieldToAdd.id ];
		}

		const updatedSchemas = {
			...schemas,
			[ schemaId ]: {
				...schemas[ schemaId ],
				fields: {
					...schemas[ schemaId ].fields,
					[ fieldId ]: defaultValue,
				},
			},
		};

		const cleanedSchemas = cleanSchemas( updatedSchemas );
		commitSchemas( cleanedSchemas );
	};

	const handleDeleteField = ( schemaId, fieldId ) => {
		cancelPendingReset( schemaId );
		const updatedFields = { ...schemas[ schemaId ].fields };
		delete updatedFields[ fieldId ];

		const updatedSchemas = {
			...schemas,
			[ schemaId ]: {
				...schemas[ schemaId ],
				fields: updatedFields,
			},
		};

		const cleanedSchemas = cleanSchemas( updatedSchemas );
		commitSchemas( cleanedSchemas );
	};

	const cleanSchemas = ( schemasData ) => {
		const cleanedSchemas = {};
		Object.entries( schemasData ).forEach( ( [ schemaId, schema ] ) => {
			const { show_on, not_show_on, ...rest } = schema; // we removed show_on and not_show_on, as we are using post meta data for schema now, cause we edited now.
			const cleanedSchema = {
				...rest,
				parent: true,
			};
			cleanedSchemas[ schemaId ] = cleanedSchema;
		} );
		return cleanedSchemas;
	};

	// Global keys whose state this session actually knows: the server-resolved
	// effective set it was seeded with plus the exclusions already stored.
	// Rule-hidden globals are in neither — they were never shown, so their
	// absence from a save payload must not read as a deletion, even if a rule
	// change makes them visible while the popup is open.
	const seenSchemaKeysRef = useRef( null );
	useEffect( () => {
		if (
			seenSchemaKeysRef.current === null &&
			postMetaData?.schemas !== undefined
		) {
			seenSchemaKeysRef.current = [
				...Object.keys( postMetaData.schemas ),
				...( postMetaData.excluded_schemas || [] ),
			];
		}
	}, [ postMetaData?.schemas, postMetaData?.excluded_schemas ] );

	// globalDefaults lands in a separate store dispatch after postSeoMeta. The
	// whole object is the loaded signal (a site can legitimately have zero
	// global schemas); schemas must also have resolved so the effective set is
	// real. Until both land, provenance cannot be judged and a mutation would
	// ship an empty baseline — the server would read "session saw zero
	// globals" and silently drop deletions.
	const globalsLoaded = Object.keys( globalDefaults ).length > 0;
	const schemasReady = globalsLoaded && postMetaData?.schemas !== undefined;

	// Every schema mutation ships with a snapshot of the globals this session
	// was seeded with, scoped to the keys it displayed. The server diffs
	// payload entries against it to tell "stale but untouched" (a global
	// edited in another tab keeps inheriting the live value) from real
	// page-level edits, and only tombstones globals this session actually
	// saw. Transport-only — never persisted.
	const commitSchemas = ( updatedSchemas ) => {
		// Mutations are UI-gated on schemasReady; this is the safety net for
		// filter-injected callers (Pro extensions).
		if ( ! schemasReady ) {
			return;
		}
		const seenKeys = seenSchemaKeysRef.current;
		const baseline = {};
		Object.entries( cleanSchemas( globalSchemas ) ).forEach(
			( [ key, entry ] ) => {
				if ( seenKeys === null || seenKeys.includes( key ) ) {
					baseline[ key ] = entry;
				}
			}
		);
		updatePostMetaData( {
			schemas: updatedSchemas,
			schemas_baseline: baseline,
		} );
	};

	// Some field widgets are uncontrolled (EditorInput and the Title Input
	// take defaultValue), so a store update alone does not repaint them. A
	// reset bumps the nonce of each affected schema; the nonce flows into
	// renderFieldCommon as remountVersion, remounting only the uncontrolled
	// leaf inputs in place — layout, group state, and other schemas stay
	// untouched.
	const [ resetNonces, setResetNonces ] = useState( {} );
	const bumpResetNonces = ( schemaIds ) =>
		setResetNonces( ( nonces ) => {
			const next = { ...nonces };
			schemaIds.forEach( ( id ) => {
				next[ id ] = ( next[ id ] || 0 ) + 1;
			} );
			return next;
		} );

	// Optimistic provenance: the rest of the popup shows draft state, so a
	// reset flips the badge back to Global and hides the reset affordances
	// immediately, even though the override stays in saved meta until Save.
	// Editing the schema again cancels its pending reset (it is an override
	// again); a save round-trip replaces everything with server truth.
	const [ pendingResets, setPendingResets ] = useState( () => new Set() );

	useEffect( () => {
		setPendingResets( new Set() );
	}, [ postMetaData?.overridden_schemas ] );

	const cancelPendingReset = ( schemaId ) =>
		setPendingResets( ( previous ) => {
			if ( ! previous.has( schemaId ) ) {
				return previous;
			}
			const next = new Set( previous );
			next.delete( schemaId );
			return next;
		} );

	// Draft provenance the UI renders: server-reported overrides minus the
	// ones reset in this session.
	const effectiveOverridden = overriddenSchemas.filter(
		( key ) => ! pendingResets.has( key )
	);

	// Exclusions and page-added schemas are detectable client-side; value-level
	// overrides come from the server-owned overridden_schemas provenance list,
	// refreshed on every save round-trip.
	const hasPageLevelChanges =
		schemasReady &&
		( excludedSchemas.length > 0 ||
			effectiveOverridden.length > 0 ||
			Object.keys( schemas ).some(
				( schemaId ) => ! ( schemaId in globalSchemas )
			) );

	// Round-trip the untouched global set: the server diffs it to empty
	// overrides and no exclusions, returning this page to full inheritance.
	// Entries stale against a global edited in another tab are protected by
	// the baseline (they equal it, so the live global wins server-side).
	// Scoped to the keys this session saw: globalDefaults holds the raw
	// global map, but the effective set is rule-filtered server-side, so an
	// unscoped reset would surface globals whose display rules exclude this
	// page (and the next save would silently drop them again).
	const handleResetToGlobal = () => {
		const seenKeys = seenSchemaKeysRef.current;
		const visibleGlobalSchemas = Object.fromEntries(
			Object.entries( globalSchemas ).filter(
				( [ key ] ) => seenKeys === null || seenKeys.includes( key )
			)
		);
		commitSchemas( cleanSchemas( visibleGlobalSchemas ) );
		// Draft exclusions clear too so the Reset affordance hides; the save
		// path always recomputes excluded_schemas from the payload, so a
		// client-sent value cannot leak into stored meta.
		updatePostMetaData( { excluded_schemas: [] } );
		setPendingResets( new Set( overriddenSchemas ) );
		bumpResetNonces( Object.keys( visibleGlobalSchemas ) );
	};

	// Reset one overridden schema to its global values while preserving every
	// other page-level change: swap the live global copy back into the
	// effective set; the server diff then drops the override for this key.
	const handleResetSchemaToGlobal = ( schemaId ) => {
		if ( ! ( schemaId in globalSchemas ) ) {
			return;
		}
		commitSchemas(
			cleanSchemas( {
				...schemas,
				[ schemaId ]: globalSchemas[ schemaId ],
			} )
		);
		setPendingResets( ( previous ) => new Set( previous ).add( schemaId ) );
		bumpResetNonces( [ schemaId ] );
	};

	const handleDeleteSchema = ( schemaId ) => {
		const updatedSchemas = { ...schemas };
		delete updatedSchemas[ schemaId ];

		const cleanedSchemas = cleanSchemas( updatedSchemas );
		commitSchemas( cleanedSchemas );
	};

	const handleAddSchema = () => {
		const schemaUniqueId = generateUUID();
		// Fill default/required fields at creation — new schemas must be
		// complete when saved; nothing back-fills them afterwards.
		const newSchema = {
			title: selectedSchema,
			type: selectedType,
			show_on: {
				rules: [],
				specific: [],
				specificText: [],
			},
			fields: {
				...processFields(
					schemaTypeData[ selectedSchema ] || [],
					true
				),
				'@type': selectedType,
			},
		};

		const updatedSchemas = { ...schemas, [ schemaUniqueId ]: newSchema };
		const cleanedSchemas = cleanSchemas( updatedSchemas );

		commitSchemas( cleanedSchemas );

		setExpandedSchemaId( schemaUniqueId );
		setIsModalOpen( false );
		setSelectedSchema( '' );
		setSelectedType( '' );
	};

	// Take over the popup with the "Connect SureRank AI" screen, remembering where
	// to return once the user connects (or hits Back).
	const redirectToSchemaAuth = () => {
		const appSettings = staticSelect( STORE_NAME ).getAppSettings();
		staticDispatch( STORE_NAME ).updateAppSettings( {
			currentScreen: 'schemaAuth',
			previousScreen: appSettings?.currentScreen || 'settings',
			previousTab: appSettings?.currentTab,
			previousMetaTab: appSettings?.currentMetaTab,
			previousAccordion: appSettings?.currentAccordion,
		} );
	};

	const handleRecommendSchemas = async () => {
		const snapshot = getEditorData();
		const isTaxonomy = window?.surerank_seo_popup?.is_taxonomy === '1';
		const postType =
			wp?.data?.select( 'core/editor' )?.getCurrentPostType?.() ||
			surerank_seo_popup?.post_type ||
			'post';
		// On listing pages there is no editor, so getEditorData() returns empty
		// title/content. Fall back to the resolved meta variables, which the
		// store already loads for the active post or term when its SEO popup is
		// opened. Term screens expose their values as term_* variables.
		const variablesArray = flat(
			staticSelect( STORE_NAME ).getVariables()
		);
		const postTitle = isTaxonomy
			? variablesArray?.term_title || ''
			: snapshot?.title || variablesArray?.title || '';
		const postContent = isTaxonomy
			? variablesArray?.term_description || ''
			: snapshot?.postContent || variablesArray?.content || '';

		if ( ! postTitle && ! postContent ) {
			setRecommendationsError(
				isTaxonomy
					? __(
							'Please add a term name or description first to get schema recommendations.',
							'surerank'
					  )
					: __(
							'Please add post title or content first to get schema recommendations.',
							'surerank'
					  )
			);
			setSchemaRecommendations( [] );
			return;
		}

		// Recommendations require a connected SureRank AI account. If the user
		// isn't connected, show the Connect SureRank AI screen instead of calling
		// the API (which would just fail with a no_auth_token error).
		const isAiAuthenticated =
			staticSelect( STORE_NAME ).getPageSeoChecks()?.authenticated;
		if ( ! isAiAuthenticated ) {
			redirectToSchemaAuth();
			return;
		}

		setIsGeneratingRecommendations( true );
		setHasRecommendationAttempt( true );
		setRecommendationsError( '' );
		setSchemaRecommendations( [] );
		setDismissedRecommendationGroups( {} );

		try {
			const response = await recommendSchemas( {
				post_type: postType,
				post_title: postTitle,
				post_content: postContent,
				available_schemas: availableSchemaTools,
				active_schemas: activeSchemasContext.activeSchemas,
				active_schema_titles: activeSchemasContext.activeSchemaTitles,
				active_schema_types: activeSchemasContext.activeSchemaTypes,
			} );

			if ( ! response?.success ) {
				// Token missing/expired server-side: send the user to connect.
				if ( 'no_auth_token' === response?.code ) {
					redirectToSchemaAuth();
					return;
				}
				throw new Error(
					response?.message ||
						__(
							'Unable to recommend schema right now. Please try again.',
							'surerank'
						)
				);
			}

			const groupedRecommendations = buildGroupedRecommendations(
				response?.recommendations,
				response?.grouped_recommendations
			);
			const recommendations = groupedRecommendations
				.map( ( group ) => {
					const children = ( group?.children || [] ).filter(
						( childRecommendation ) => {
							const recommendation =
								normalizeRecommendation( childRecommendation );
							return ! activeSchemasContext.activeParentSet.has(
								normalizeSchemaKey(
									recommendation?.parent_schema
								)
							);
						}
					);
					return {
						...group,
						children,
					};
				} )
				.filter( ( group ) => ( group?.children || [] ).length > 0 );

			setSchemaRecommendations( recommendations );

			if ( recommendations.length === 0 ) {
				setRecommendationsError(
					__(
						'No relevant schema recommendations found for this content.',
						'surerank'
					)
				);
			}
		} catch ( error ) {
			const errorCode =
				error?.code ||
				error?.data?.code ||
				error?.responseJSON?.code ||
				'';

			// Token missing/expired (rejection path): send the user to connect.
			if ( 'no_auth_token' === errorCode ) {
				redirectToSchemaAuth();
				return;
			}

			let resolvedMessage =
				error?.message ||
				__(
					'Unable to recommend schema right now. Please try again.',
					'surerank'
				);

			if ( 'request_timeout' === errorCode ) {
				resolvedMessage = __(
					'Schema recommendation timed out. Please try again in a moment.',
					'surerank'
				);
			}

			if ( 'limit_exceeded' === errorCode ) {
				resolvedMessage = __(
					'You have reached your monthly schema recommendation limit.',
					'surerank'
				);
			}

			if ( 'require_pro' === errorCode ) {
				resolvedMessage = __(
					'Schema recommendations are unavailable for your current plan.',
					'surerank'
				);
			}

			setRecommendationsError( resolvedMessage );
		} finally {
			setIsGeneratingRecommendations( false );
		}
	};

	// After the user returns from connecting their account, auto-run the
	// recommendation they originally triggered. The flag is set by the
	// "Connect SureRank AI" screen only on a successful connection (not on Back).
	const triggerSchemaRecommend = useSelect(
		( select ) =>
			select( STORE_NAME ).getAppSettings()?.triggerSchemaRecommend,
		[]
	);
	useEffect( () => {
		if ( ! triggerSchemaRecommend ) {
			return;
		}
		staticDispatch( STORE_NAME ).updateAppSettings( {
			triggerSchemaRecommend: false,
		} );
		handleRecommendSchemas();
	}, [ triggerSchemaRecommend ] );

	const addRecommendationSchema = ( recommendation ) => {
		const parentSchema = recommendation?.parent_schema || '';
		const childType =
			recommendation?.child_schema_type ||
			recommendation?.schema ||
			parentSchema;

		if (
			! normalizeSchemaKey( parentSchema ) ||
			! normalizeSchemaKey( childType )
		) {
			return;
		}

		if (
			activeSchemasContext.activeParentSet.has(
				normalizeSchemaKey( parentSchema )
			)
		) {
			return;
		}

		const pairKey = getPairKey( parentSchema, childType );

		const existingParentEntry = Object.entries( schemas ).find(
			( [ , schema ] ) =>
				normalizeSchemaKey( schema?.title ) ===
				normalizeSchemaKey( parentSchema )
		);

		const updatedSchemas = { ...schemas };
		let targetSchemaId = '';

		if ( existingParentEntry ) {
			const [ existingSchemaId, existingSchema ] = existingParentEntry;
			const existingChild = existingSchema?.fields?.[ '@type' ] || '';

			if ( ! normalizeSchemaKey( existingChild ) ) {
				targetSchemaId = existingSchemaId;
				updatedSchemas[ existingSchemaId ] = {
					...existingSchema,
					fields: {
						...( existingSchema?.fields || {} ),
						'@type': childType,
					},
				};
			}
		}

		if ( ! targetSchemaId ) {
			targetSchemaId = generateUUID();
			const parentFields = processFields(
				schemaTypeData[ parentSchema ] || [],
				true
			);
			updatedSchemas[ targetSchemaId ] = {
				title: parentSchema,
				type: parentSchema,
				show_on: {
					rules: [],
					specific: [],
					specificText: [],
				},
				fields: {
					...parentFields,
					'@type': childType,
				},
			};
		}

		const cleanedSchemas = cleanSchemas( updatedSchemas );

		commitSchemas( cleanedSchemas );
		setExpandedSchemaId( targetSchemaId );
		trackSchemaRecommendationEvent( 'recommendation_added' ).catch(
			() => {}
		);
		setSchemaRecommendations( ( previousGroups ) =>
			previousGroups
				.map( ( group ) => ( {
					...group,
					children: ( group?.children || [] ).filter(
						( candidate ) => {
							const candidateRecommendation =
								normalizeRecommendation( candidate );
							return (
								getPairKey(
									candidateRecommendation?.parent_schema,
									candidateRecommendation?.child_schema_type
								) !== pairKey
							);
						}
					),
				} ) )
				.filter( ( group ) => ( group?.children || [] ).length > 0 )
		);
	};

	const handleFieldUpdate = ( schemaId, fieldKey, newValue ) => {
		const updatedSchemas = {
			...schemas,
			[ schemaId ]: {
				...schemas[ schemaId ],
				fields: {
					...schemas[ schemaId ].fields,
					[ fieldKey ]: newValue,
				},
			},
		};
		const cleanedSchemas = cleanSchemas( updatedSchemas );
		commitSchemas( cleanedSchemas );
	};

	const getFieldValue = ( schemaId, fieldId ) => {
		return schemas[ schemaId ]?.fields?.[ fieldId ] || '';
	};

	const onFieldChange = ( schemaId, fieldId, newValue ) => {
		cancelPendingReset( schemaId ); // Edited again — it's an override again.
		handleFieldUpdate( schemaId, fieldId, newValue );
		if ( fieldId === '@type' ) {
			const updatedSchemas = { ...schemas };
			updatedSchemas[ schemaId ].type =
				updatedSchemas[ schemaId ]?.title || ''; // added for backward compatibility.
			updatedSchemas[ schemaId ].fields[ '@type' ] = newValue;
			commitSchemas( updatedSchemas );
		}
	};

	const handleProUpgradeClick = ( recommendation ) => {
		trackSchemaRecommendationEvent( 'upgrade_clicked' ).catch( () => {} );
		const upgradeUrl = recommendation?.upgrade_url;

		if ( upgradeUrl ) {
			window.open( upgradeUrl, '_blank', 'noopener,noreferrer' );
			return;
		}

		redirectToPricingPage( 'schema_recommendation_pro' );
	};

	/**
	 * Render a single subfield within a non-cloneable Group field
	 *
	 * @param {Object} field    - The parent Group field configuration
	 * @param {Object} subField - The subfield to render
	 * @param {string} schemaId - The schema ID
	 * @return {JSX.Element|null} - Rendered subfield or null if hidden
	 */
	const renderGroupSubField = ( field, subField, schemaId ) => {
		if ( subField.type === 'Hidden' ) {
			return null;
		}

		const groupValue = getFieldValue( schemaId, field.id ) || {};

		const handleSubFieldChange = ( fldId, newVal ) => {
			const currentGroupValue = getFieldValue( schemaId, field.id ) || {};
			const updatedGroupValue = {
				...currentGroupValue,
				[ fldId ]: newVal,
			};
			onFieldChange( schemaId, field.id, updatedGroupValue );
		};

		return (
			<div
				key={ subField.id }
				className="flex flex-col items-start justify-start gap-1.5 w-full"
			>
				<div className="flex items-center gap-1.5">
					<Label tag="span" size="sm">
						{ subField.label }
					</Label>
					{ subField.tooltip && (
						<SeoPopupTooltip
							content={ subField.tooltip }
							placement="top"
							arrow
							className="z-[99999]"
						>
							<Info
								className="size-4 text-icon-secondary"
								title={ subField.tooltip }
							/>
						</SeoPopupTooltip>
					) }
				</div>
				<div className="flex items-center gap-1.5 w-full">
					{ renderFieldCommon( {
						field: subField,
						schemaType: schemas[ schemaId ].type,
						getFieldValue: ( fldId ) =>
							groupValue[ fldId ] || subField.std || '',
						onFieldChange: handleSubFieldChange,
						variableSuggestions,
						renderAsGroupComponent: false,
						remountVersion: resetNonces[ schemaId ] || 0,
					} ) }
				</div>
			</div>
		);
	};

	/**
	 * Render a field based on its type and configuration
	 *
	 * @param {Object} field    - The field configuration
	 * @param {string} schemaId - The schema ID
	 * @return {JSX.Element} - Rendered field component
	 */
	const renderField = ( field, schemaId ) => {
		if ( field.type === 'Group' && ! field.cloneable ) {
			return (
				<div className="flex flex-col w-full space-y-3 border border-border-subtle rounded-lg p-3">
					{ field.fields.map( ( subField ) =>
						renderGroupSubField( field, subField, schemaId )
					) }
				</div>
			);
		}

		return renderFieldSwitch( field, {
			schemaId,
			schemaType: schemas[ schemaId ].type,
			getFieldValue: ( fieldId ) => getFieldValue( schemaId, fieldId ),
			onFieldChange: ( fieldId, newVal ) =>
				onFieldChange( schemaId, fieldId, newVal ),
			variableSuggestions,
			fieldItemIds,
			setFieldItemIds,
			renderAsGroupComponent: true,
			remountVersion: resetNonces[ schemaId ] || 0,
		} );
	};

	const renderSchemaFields = ( schemaId ) => {
		const schemaTitle = schemas[ schemaId ]?.title;

		// Check if schema type is valid first
		if ( ! isSchemaTypeValid( schemaTitle ) ) {
			return noFieldsAlert;
		}

		const allSchemaFields = schemaTypeData[ schemaTitle ] || [];
		const existingFields = schemas[ schemaId ]?.fields || {};

		if (
			allSchemaFields.length === 0 ||
			allSchemaFields.every(
				( field ) =>
					field.type === 'Hidden' || field.id === 'schema_name'
			)
		) {
			return noFieldsAlert;
		}

		// Filter fields to show: must exist in existingFields OR be required OR have parent dependency
		const filteredFields = allSchemaFields.filter( ( field ) => {
			if ( field.type === 'Hidden' || field.type === 'SchemaDocs' ) {
				return false;
			}

			// Show if field exists in the schema
			if (
				Object.prototype.hasOwnProperty.call( existingFields, field.id )
			) {
				return true;
			}

			// Show if required
			if ( field.required ) {
				return true;
			}

			// Show if has parent dependency that is active
			if ( field.parent && field.parent_option ) {
				return shouldShowField( field, ( fieldId ) =>
					getFieldValue( schemaId, fieldId )
				);
			}

			return false;
		} );

		const fieldsToShow = sortFieldsByPriority(
			filteredFields,
			existingFields
		);

		const availableFields = getAvailableFields(
			allSchemaFields,
			existingFields
		);

		return (
			<>
				{ fieldsToShow.map( ( field ) => {
					if ( field.parent && field.parent_option ) {
						const shouldShow = shouldShowField(
							field,
							( fieldId ) => getFieldValue( schemaId, fieldId )
						);
						if ( ! shouldShow ) {
							return null;
						}
					}

					const canDelete = canDeleteField( field );

					return (
						<div
							key={ field.id }
							className="flex flex-col items-start justify-start gap-1.5 w-full p-1"
						>
							{ /* Label + tooltip + delete button */ }
							<div className="flex items-center justify-between gap-1.5 w-full">
								<div className="flex items-center gap-1.5">
									<Label tag="span" size="sm">
										{ field.label }
									</Label>
									{ field.tooltip && (
										<SeoPopupTooltip
											content={ field.tooltip }
											placement="top"
											arrow
											className="z-[99999]"
										>
											<Info
												className="size-4 text-icon-secondary"
												title={ field.tooltip }
											/>
										</SeoPopupTooltip>
									) }
								</div>
								{ canDelete && (
									<DeleteFieldButton
										onDelete={ () =>
											handleDeleteField(
												schemaId,
												field.id
											)
										}
									/>
								) }
							</div>

							{ /* Field render */ }
							{ renderField( field, schemaId ) }
						</div>
					);
				} ) }

				{ applyFilters( 'surerank.schema.properties.extensions', null, {
					schemaId,
					schemaType: schemas[ schemaId ]?.type || schemaTitle,
					schema: schemaTitle,
					metaSettings: { schemas },
					currentSchema: schemas[ schemaId ] || {},
					setMetaSetting: ( key, value ) => {
						if ( key === 'schemas' ) {
							commitSchemas( value );
						}
					},
					variableSuggestions,
					getFieldValue: ( fieldId ) =>
						getFieldValue( schemaId, fieldId ),
					onFieldChange: ( fieldId, newValue ) =>
						onFieldChange( schemaId, fieldId, newValue ),
				} ) }

				<AddFieldMenu
					availableFields={ availableFields }
					onAddField={ ( fieldId ) =>
						handleAddField( schemaId, fieldId )
					}
					className="p-2 w-full border-t border-border-subtle mt-2"
					filterContext={ {
						schemaId,
						schemaType: schemas[ schemaId ]?.type || schemaTitle,
						schema: schemaTitle,
						metaSettings: { schemas },
						currentSchema: schemas[ schemaId ] || {},
						setMetaSetting: ( key, value ) => {
							if ( key === 'schemas' ) {
								commitSchemas( value );
							}
						},
					} }
				/>
			</>
		);
	};

	if ( isWpSchemaProActive ) {
		return <WpSchemaProNotice />;
	}

	const visibleRecommendationGroups = schemaRecommendations.filter(
		( group ) => ! dismissedRecommendationGroups[ group?.parent_schema ]
	);

	return (
		<div className="pt-2 gap-2">
			<div className="flex items-center justify-between gap-2 mb-4.5 -mt-0.5">
				<Text size={ 14 } weight={ 500 } color="label">
					{ __( 'Schemas in Use', 'surerank' ) }
				</Text>
				<div className="flex items-center gap-2">
					{ hasPageLevelChanges && (
						<ConfirmationPopover
							onConfirm={ handleResetToGlobal }
							confirmText={ __( 'Reset', 'surerank' ) }
							placement="bottom"
						>
							<Button
								variant="link"
								size="sm"
								icon={ <RefreshCw className="size-3.5" /> }
							>
								{ __( 'Reset to Global', 'surerank' ) }
							</Button>
						</ConfirmationPopover>
					) }
					<Button
						variant="outline"
						size="sm"
						icon={ <Sparkles className="size-3.5" /> }
						onClick={ handleRecommendSchemas }
						disabled={
							! schemasReady || isGeneratingRecommendations
						}
					>
						{ isGeneratingRecommendations
							? recommendingText
							: recommendText }
					</Button>
				</div>
			</div>

			{ recommendationsError && (
				<Alert
					className="w-full shadow-none mb-3"
					content={ recommendationsError }
					variant="info"
				/>
			) }

			{ schemaRecommendations.length > 0 && (
				<div className="mb-3 space-y-4">
					<div className="flex items-center justify-between gap-2">
						<Text size={ 14 } weight={ 500 } color="secondary">
							{ __( 'Recommended for this page', 'surerank' ) }
						</Text>
						<Button
							size="xs"
							variant="link"
							icon={ <RefreshCw className="size-3.5" /> }
							onClick={ handleRecommendSchemas }
						>
							{ __( 'Refresh suggestions', 'surerank' ) }
						</Button>
					</div>
					{ visibleRecommendationGroups.map( ( group ) => {
						const childRecommendations = ( group?.children || [] )
							.map( normalizeRecommendation )
							.filter( ( recommendation ) => {
								return ! activeSchemasContext.activeParentSet.has(
									normalizeSchemaKey(
										recommendation?.parent_schema
									)
								);
							} );

						if ( 0 === childRecommendations.length ) {
							return null;
						}

						const dismissGroup = () => {
							setDismissedRecommendationGroups(
								( previous ) => ( {
									...previous,
									[ group?.parent_schema ]: true,
								} )
							);
							trackSchemaRecommendationEvent(
								'group_dismissed'
							).catch( () => {} );
						};

						return childRecommendations.map( ( recommendation ) => {
							const pairKey = getPairKey(
								recommendation?.parent_schema,
								recommendation?.child_schema_type
							);
							const isAlreadyAdded =
								activeSchemasContext.activeParentSet.has(
									normalizeSchemaKey(
										recommendation?.parent_schema
									)
								);
							const isPro =
								recommendation?.is_pro ||
								recommendation?.tier === 'pro';
							const isCompanion =
								recommendation?.source === 'companion_rule';
							const cardTitle =
								recommendation?.child_schema_type ||
								recommendation?.schema ||
								group?.parent_schema ||
								'';

							return (
								<div
									key={ pairKey }
									className="rounded-xl border border-border-subtle bg-background-primary shadow-sm overflow-hidden"
								>
									<div className="px-4 py-3.5">
										<div className="flex items-center gap-1.5">
											<Text
												size={ 14 }
												weight={ 600 }
												color="label"
											>
												{ cardTitle }
											</Text>
											{ isCompanion && (
												<Badge
													label={ __(
														'Companion',
														'surerank'
													) }
													size="xs"
													variant="neutral"
												/>
											) }
											{ isPro && (
												<Badge
													label={ __(
														'Pro',
														'surerank'
													) }
													size="xs"
													variant="yellow"
												/>
											) }
										</div>
										{ recommendation?.reason && (
											<Text
												size={ 13 }
												color="help"
												className="block mt-1"
											>
												{ recommendation.reason }
											</Text>
										) }
									</div>
									<div className="flex items-center justify-end gap-3 px-4 py-2 bg-background-secondary border-t border-border-subtle">
										<Button
											size="sm"
											variant="ghost"
											onClick={ dismissGroup }
										>
											{ __( 'Dismiss', 'surerank' ) }
										</Button>
										{ recommendation?.can_add === false ? (
											<Button
												size="sm"
												variant="outline"
												className="shrink-0"
												onClick={ () =>
													handleProUpgradeClick(
														recommendation
													)
												}
											>
												{ __(
													'Upgrade to Add',
													'surerank'
												) }
											</Button>
										) : (
											<Button
												size="sm"
												variant="primary"
												className="shrink-0"
												disabled={ isAlreadyAdded }
												onClick={ () =>
													addRecommendationSchema(
														recommendation
													)
												}
											>
												{ isAlreadyAdded
													? alreadyAdded
													: addNow }
											</Button>
										) }
									</div>
								</div>
							);
						} );
					} ) }
				</div>
			) }

			{ isGeneratingRecommendations && (
				<div className="mb-3 p-3 rounded border border-border-subtle bg-background-secondary space-y-2">
					<div className="h-4 w-40 rounded bg-background-primary animate-pulse" />
					<div className="h-14 w-full rounded bg-background-primary animate-pulse" />
					<div className="h-14 w-full rounded bg-background-primary animate-pulse" />
				</div>
			) }

			{ hasRecommendationAttempt &&
				! isGeneratingRecommendations &&
				0 === visibleRecommendationGroups.length &&
				! recommendationsError && (
					<Alert
						className="w-full shadow-none mb-3"
						content={ __(
							'No missing high-relevance schema found. Try adding more content context and refresh suggestions.',
							'surerank'
						) }
						variant="info"
					/>
				) }

			<div
				className={ cn(
					'w-full bg-background-secondary flex flex-col items-center justify-center rounded p-1'
				) }
			>
				{ /* Global defaults and the resolved effective set arrive in
				separate store updates; mutating before both land would ship an
				empty baseline (deletions silently dropped), so the whole list
				stays a read-only skeleton until ready. */ }
				{ ! schemasReady && (
					<div className="w-full p-2 space-y-2">
						<div className="h-10 w-full rounded bg-background-primary animate-pulse" />
						<div className="h-10 w-full rounded bg-background-primary animate-pulse" />
						<div className="h-10 w-full rounded bg-background-primary animate-pulse" />
					</div>
				) }
				{ schemasReady && validSchemas.length > 0 && (
					<Accordion
						type="simple"
						iconType="arrow"
						className="w-full space-y-1"
						autoClose={ false }
					>
						{ validSchemas.map( ( [ schemaId, schema ] ) => {
							// Provenance: a key outside the global map is
							// page-added; a global key listed in the server's
							// overridden_schemas carries page-level values.
							// Only an unmodified global key is inherited.
							const isGlobalKey = schemaId in globalSchemas;
							const isOverridden =
								effectiveOverridden.includes( schemaId );
							const isInherited = isGlobalKey && ! isOverridden;
							return (
								<Accordion.Item
									key={ schemaId }
									value={ schemaId }
									className="bg-background-primary rounded-md border border-border-subtle"
									defaultExpanded={
										schemaId === expandedSchemaId
									}
								>
									<Accordion.Trigger
										iconType="arrow"
										className="hover:bg-background-primary rounded-md flex justify-between items-center [&>div]:w-full p-2 gap-2 [&>svg]:size-4 cursor-pointer"
									>
										<span className="text-base font-normal text-text-primary leading-6 ml-1 inline-flex items-center gap-1.5">
											{ schema.title }
											{ schemasReady && (
												<Badge
													label={
														isInherited
															? __(
																	'Global',
																	'surerank'
															  )
															: __(
																	'Page',
																	'surerank'
															  )
													}
													size="xs"
													variant={
														isInherited
															? 'neutral'
															: 'blue'
													}
												/>
											) }
										</span>
										<span className="inline-flex ml-auto items-center gap-2">
											{ isOverridden && (
												<ConfirmationPopover
													onConfirm={ () =>
														handleResetSchemaToGlobal(
															schemaId
														)
													}
													confirmText={ __(
														'Reset',
														'surerank'
													) }
													placement="bottom"
													offset={ {
														mainAxis: 8,
														crossAxis: -28,
													} }
												>
													<div
														className="inline-flex"
														role="button"
														tabIndex={ 0 }
														aria-label={ __(
															'Reset this schema to Global',
															'surerank'
														) }
													>
														<SeoPopupTooltip
															content={ __(
																'Reset this schema to Global',
																'surerank'
															) }
															placement="top"
															arrow
															className="z-[99999]"
														>
															<RefreshCw className="size-3.5 text-icon-secondary cursor-pointer" />
														</SeoPopupTooltip>
													</div>
												</ConfirmationPopover>
											) }
											<ConfirmationPopover
												onConfirm={ () =>
													handleDeleteSchema(
														schemaId
													)
												}
												confirmText={
													isGlobalKey
														? __(
																'Remove from page',
																'surerank'
														  )
														: __(
																'Remove',
																'surerank'
														  )
												}
												placement="bottom"
												offset={ {
													mainAxis: 8,
													crossAxis: -28,
												} }
											>
												<div
													className="inline-flex"
													role="button"
													tabIndex={ 0 }
												>
													<Trash className="size-3.5 text-icon-secondary cursor-pointer" />
												</div>
											</ConfirmationPopover>
										</span>
									</Accordion.Trigger>
									<Accordion.Content>
										<div className="mt-3 space-y-4">
											{ renderSchemaFields( schemaId ) }
										</div>
									</Accordion.Content>
								</Accordion.Item>
							);
						} ) }
					</Accordion>
				) }
				{ schemasReady && 0 === validSchemas.length && (
					<Alert
						className="w-full shadow-none"
						content={ __( 'No schemas configured.', 'surerank' ) }
						variant="info"
					/>
				) }
			</div>
			{ schemasReady && (
				<div className="w-full mt-6 rounded">
					<Modal
						selectedSchema={ selectedSchema }
						setSelectedSchema={ setSelectedSchema }
						selectedType={ selectedType }
						setSelectedType={ setSelectedType }
						schemaTypeOptions={ schemaTypeOptions }
						defaultSchemas={ defaultSchemas }
						handleAddSchema={ handleAddSchema }
						isModalOpen={ isModalOpen }
						closeModal={ closeModal }
					/>
				</div>
			) }
		</div>
	);
};

export default SchemaTab;
