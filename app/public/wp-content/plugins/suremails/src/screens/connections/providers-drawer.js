// ProvidersDrawer.js
import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Drawer, Button, toast } from '@bsf/force-ui';
import {
	LoaderCircle as LoaderIcon,
	ChevronLeft as ChevronLeftIcon,
} from 'lucide-react';
import ProviderList from '@screens/connections/provider-list';
import { testAndSaveEmailConnection as apiTestAndSaveEmailConnection } from '@api/connections';
import { useMemo } from 'react';
import ProvidersSkeleton from './providers-skeleton';
import ExtendedDynamicForm from './extended-dynamic-form';
import SureContactDrawerForm from './surecontact-drawer-form';
import SureContactAddSenderForm from './surecontact-add-sender-form';
import { isOAuthProvider } from '@oauth/oauth-providers';
import { SURECONTACT_KEY } from './use-dynamic-providers';

// Editable subset for an existing SureContact connection. Account email and
// OAuth identity are platform-owned (the bearer is bound to from_email), so
// only the cosmetic + routing fields are exposed; from_email / force_from_email
// stay in formData so the save payload still carries them.
const SURECONTACT_EDIT_FIELDS = [
	'connection_title',
	'from_name',
	'force_from_name',
	'priority',
];

const ProvidersDrawer = ( {
	isOpen,
	setIsOpen,
	currentConnection = {},
	onSave,
	providers: providersList = [],
	isProvidersLoading = false,
	sequenceId = 1,
	connectionCount = {},
	surecontactPaid = false,
} ) => {
	// Free accounts get one SureContact connection per site; paid accounts can
	// add more (each a new from_email sharing the account's api_key). The drawer
	// always opens on the provider list — a specific provider is only
	// pre-selected when the caller passes one via currentConnection.type (e.g.
	// the SureContact SMTP banner's "Set Up Now").
	const surecontactExists = ( connectionCount[ SURECONTACT_KEY ] || 0 ) > 0;
	const surecontactLocked = surecontactExists && ! surecontactPaid;
	const defaultProvider = null;
	const [ selectedProvider, setSelectedProvider ] = useState(
		currentConnection?.type || defaultProvider
	);
	const [ formData, setFormData ] = useState( currentConnection );
	const [ errors, setErrors ] = useState( {} );
	// Edit mode is only entered by opening an existing connection. Picking a
	// provider from the list (or hitting Back and re-selecting) is always a new
	// connection — tracked here so a stale currentConnection.id can't keep the
	// drawer in edit mode after the user navigates back to create one.
	const [ isEditing, setIsEditing ] = useState(
		Boolean( currentConnection?.id )
	);

	const [ isLoading, setIsLoading ] = useState( false );
	// Add form refs
	const formRef = useRef( null );

	// Get the title postfix for the selected provider
	const titlePostfix = ( connectionCount[ selectedProvider ] || 0 ) + 1;

	// Get the fields for the selected provider
	const selectedProviderData = useMemo( () => {
		return providersList.find(
			( provider ) => provider.value === selectedProvider
		);
	}, [ selectedProvider, providersList ] );
	const fields = selectedProviderData?.fields;

	// SureContact splits provision (OAuth flow via SureContactDrawerForm) from
	// edit (the generic form, with a narrowed field set). Edit mode is only
	// reachable when an existing connection is loaded into currentConnection.
	const isSureContactEdit = selectedProvider === SURECONTACT_KEY && isEditing;

	// Paid account adding another sender: a SureContact connection already
	// exists and we're creating a new row. Shows the domain/local-part picker
	// instead of the account-provision flow.
	const isSureContactAddSender =
		selectedProvider === SURECONTACT_KEY &&
		! isSureContactEdit &&
		surecontactExists &&
		surecontactPaid;

	const visibleFields = useMemo( () => {
		if ( ! fields || ! isSureContactEdit ) {
			return fields;
		}
		return fields.filter( ( field ) =>
			SURECONTACT_EDIT_FIELDS.includes( field.name )
		);
	}, [ fields, isSureContactEdit ] );

	const defaultValues = useMemo( () => {
		return selectedProviderData?.fields.reduce( ( acc, field ) => {
			acc[ field.name ] = field.default;
			return acc;
		}, {} );
	}, [ selectedProviderData ] );

	useEffect( () => {
		if ( currentConnection?.type ) {
			setSelectedProvider( currentConnection.type );
			setIsEditing( Boolean( currentConnection?.id ) );

			setFormData( ( prevData ) => ( {
				...prevData,
				...currentConnection,
			} ) );
		} else {
			// New connection with no explicit provider — open on the list.
			setSelectedProvider( defaultProvider );
			setIsEditing( false );
			setFormData( null );
		}
	}, [ currentConnection ] );

	useEffect( () => {
		if ( ! formData || Object.keys( formData ).length === 0 ) {
			const config = selectedProviderData;
			if ( ! config ) {
				return;
			}

			// Get the current count for the selected provider and add 1 for priority
			const defaultTitle =
				titlePostfix === 1
					? config.title
					: `${ config.title } (${ titlePostfix - 1 })`;
			setFormData( {
				...defaultValues,
				connection_title: defaultTitle,
				priority: sequenceId,
			} );
		}
	}, [ selectedProvider, formData, currentConnection ] );

	const handleProviderSelect = ( provider ) => {
		setSelectedProvider( provider );
		// Picking from the provider list always starts a fresh connection.
		setIsEditing( false );
		const config = selectedProviderData;
		if ( ! config ) {
			return;
		}

		// Get the current count for the selected provider and add 1 for priority
		const defaultTitle =
			titlePostfix === 1
				? config.title
				: `${ config.title } (${ titlePostfix - 1 })`;
		setFormData( {
			...defaultValues,
			connection_title: defaultTitle,
			priority: sequenceId,
		} );
		setErrors( {} );
	};

	// On blur, validate the input.
	const handleOnBlurValidation = ( event ) => {
		if ( ! event.target ) {
			return;
		}

		const field = event.target.name;
		const config = selectedProviderData;
		if ( ! config ) {
			return;
		}

		const { schema } = config;

		try {
			schema.pick( { [ field ]: true } ).parse( {
				[ field ]: formData[ field ],
			} );
			setErrors( ( prev ) => ( {
				...prev,
				[ field ]: undefined,
			} ) );
		} catch ( error ) {
			setErrors( ( prev ) => ( {
				...prev,
				[ field ]: error.errors[ 0 ].message,
			} ) );
		}
	};

	const handleSetOpenDrawer = ( value ) => {
		setIsOpen( value );
		if ( ! value ) {
			setSelectedProvider( currentConnection?.type || defaultProvider );
			setErrors( {} );
			setFormData( {} );
		}
	};

	const validateForm = () => {
		const config = selectedProviderData;
		if ( ! config ) {
			return false;
		}

		const { schema } = config;

		try {
			schema.parse( formData );
			setErrors( {} );
			return true;
		} catch ( error ) {
			const formattedErrors = {};
			error.errors.forEach( ( err ) => {
				formattedErrors[ err.path[ 0 ] ] = err.message;
			} );
			setErrors( formattedErrors );

			// Focus the first input with error
			const firstErrorField = error.errors[ 0 ]?.path[ 0 ];
			const firstErrorInput = formRef.current?.querySelector(
				`input[name="${ firstErrorField }"]`
			);
			firstErrorInput?.focus();

			return false;
		}
	};

	const resetProviderState = () => {
		setSelectedProvider( null );
		setFormData( null );
		setErrors( {} );
		setIsEditing( false );
	};

	const hasChanges =
		JSON.stringify( formData ) !== JSON.stringify( currentConnection ) ||
		formData?.force_save === true;
	const handleSaveChanges = async () => {
		if ( ! hasChanges ) {
			toast.info( __( 'No changes to save.', 'suremails' ) );
			return;
		}

		if ( ! validateForm() ) {
			return;
		}

		// SureContact edits ship only the editable allowlist + id, so a
		// tampered client can't smuggle from_email / api_key / etc. into the
		// payload. The server enforces the same restriction on its side; this
		// is defense-in-depth.
		const settingsPayload = isSureContactEdit
			? [ ...SURECONTACT_EDIT_FIELDS, 'id' ].reduce( ( acc, key ) => {
					if ( formData?.[ key ] !== undefined ) {
						acc[ key ] = formData[ key ];
					}
					return acc;
			  }, {} )
			: formData;

		const payload = {
			settings: settingsPayload,
			provider: selectedProvider.toUpperCase(),
		};
		setIsLoading( true );

		try {
			const response = await apiTestAndSaveEmailConnection( payload );

			if ( response?.success ) {
				toast.success( __( 'Saved successfully!', 'suremails' ), {
					description: __(
						'Connection details saved successfully!',
						'suremails'
					),
				} );
				setIsOpen( false ); // Close drawer on success
				onSave( response.connection );
				resetProviderState();
			} else {
				toast.error( __( 'Verification Failed!', 'suremails' ), {
					description: response.message,
					autoDismiss: false,
				} );
			}
		} catch ( error ) {
			toast.error( __( 'Verification Failed!', 'suremails' ), {
				description:
					error.message ||
					__(
						'An unexpected error occurred while testing the connection.',
						'suremails'
					),
				autoDismiss: false,
			} );
		} finally {
			setIsLoading( false );
		}
	};

	/**
	 * Captures form data from provider-specific forms.
	 *
	 * @param {Object} data - The form data.
	 */
	const handleFormSubmit = ( data ) => {
		const [ field, value ] = Object.entries( data )[ 0 ];
		setFormData( ( prev ) => ( {
			...prev,
			[ field ]: value,
		} ) );

		// Clear error only for the field being changed
		setErrors( ( prev ) => ( {
			...prev,
			[ field ]: undefined,
		} ) );
	};

	const handleClickAuthenticate = ( provider, formStateValues ) => {
		const timestampOffset = 5 * 60 * 1000;
		const providerLower = provider?.toLowerCase();

		if ( isOAuthProvider( providerLower ) ) {
			localStorage.setItem(
				'formStateValues',
				JSON.stringify( {
					...formStateValues,
				} )
			);
			localStorage.setItem(
				'formStateValuesTimestamp',
				Date.now() + timestampOffset
			);
		}
	};

	// Define drawer title and description based on selected provider. The
	// SureContact provision flow gets the onboarding-style header so the
	// drawer and onboarding screens read identically.
	const isSureContactProvision =
		selectedProvider === SURECONTACT_KEY &&
		! isSureContactEdit &&
		! isSureContactAddSender;

	let title;
	let description;
	if ( isSureContactAddSender ) {
		title = __( 'Add a sender', 'suremails' );
		description = __(
			'Send from another address on one of your verified sending domains.',
			'suremails'
		);
	} else if ( isSureContactProvision ) {
		title = __( 'Create your SureContact account', 'suremails' );
		description = __(
			"Takes 30 seconds. No credit card needed. We'll auto-configure everything.",
			'suremails'
		);
	} else if ( selectedProvider ) {
		title = __( 'Connection Details', 'suremails' );
		description =
			selectedProviderData?.description ??
			__(
				'Enter the details below to connect with your {providerName} account.',
				'suremails'
			).replace(
				'{providerName}',
				selectedProviderData?.display_name || selectedProvider
			);
	} else {
		title = __( 'New Connection', 'suremails' );
		description = __(
			'Pick an email provider to ensure your WordPress emails are delivered securely and reliably.',
			'suremails'
		);
	}

	return (
		<Drawer
			design="footer-bordered"
			exitOnEsc
			position="right"
			scrollLock
			transitionDuration={ 0.2 }
			open={ isOpen }
			setOpen={ handleSetOpenDrawer }
			className="z-999999"
		>
			<Drawer.Backdrop />
			<form ref={ formRef } noValidate>
				<Drawer.Panel className="w-[34.75rem]">
					<Drawer.Header>
						<div className="flex items-center justify-between text-text-primary">
							<Drawer.Title>{ title }</Drawer.Title>
							<Drawer.CloseButton
								type="button"
								onClick={ () => {
									resetProviderState();
									setIsOpen( false );
								} }
							/>
						</div>
						<Drawer.Description className="text-sm font-normal text-text-secondary">
							{ description }
						</Drawer.Description>
					</Drawer.Header>
					<Drawer.Body className="overflow-x-hidden">
						{ /* Paid "add another sender" — domain/local-part picker, clones the shared api_key. */ }
						{ ! isProvidersLoading && isSureContactAddSender && (
							<SureContactAddSenderForm
								sequenceId={ sequenceId }
								onBack={ () => {
									setSelectedProvider( null );
									setFormData( null );
								} }
								onSuccess={ ( connection ) => {
									setIsOpen( false );
									if ( onSave ) {
										onSave( connection );
									}
									resetProviderState();
								} }
							/>
						) }

						{ /* SureContact provision/connect — only when adding the first connection. */ }
						{ ! isProvidersLoading &&
							selectedProvider === SURECONTACT_KEY &&
							! isSureContactEdit &&
							! isSureContactAddSender && (
								<SureContactDrawerForm
									pendingOAuthToken={
										currentConnection?.oauth_token || ''
									}
									pendingOAuthState={
										currentConnection?.oauth_state || ''
									}
									sequenceId={ sequenceId }
									onBack={ () => {
										setSelectedProvider( null );
										setFormData( null );
									} }
									onSuccess={ ( connection ) => {
										setIsOpen( false );
										if ( onSave ) {
											onSave( connection );
										}
										resetProviderState();
									} }
								/>
							) }

						{ /* Generic form: any non-SureContact provider, or SureContact in edit mode (with a narrowed field set). */ }
						{ ! isProvidersLoading &&
							selectedProvider &&
							( selectedProvider !== SURECONTACT_KEY ||
								isSureContactEdit ) && (
								<div>
									<ExtendedDynamicForm
										fields={ visibleFields }
										onChange={ handleFormSubmit }
										connectionData={ formData }
										errors={ errors }
										inlineValidator={
											handleOnBlurValidation
										}
										onClickAuthenticate={
											handleClickAuthenticate
										}
										providerOptions={ selectedProviderData }
									/>
								</div>
							) }

						{ /* Provider List when no provider is selected */ }
						{ ! isProvidersLoading && ! selectedProvider && (
							<ProviderList
								onSelectProvider={ handleProviderSelect }
								providers={ providersList }
								surecontactDisabled={ surecontactLocked }
							/>
						) }

						{ /* Skeleton for loading state */ }
						{ isProvidersLoading && <ProvidersSkeleton /> }
					</Drawer.Body>
					{ selectedProvider &&
						( selectedProvider !== SURECONTACT_KEY ||
							isSureContactEdit ) && (
							<Drawer.Footer>
								<Button
									onClick={ () => {
										setSelectedProvider( null );
										setFormData( null );
										setErrors( {} );
										setIsEditing( false );
									} }
									variant="outline"
									icon={ <ChevronLeftIcon /> }
									size="sm"
									iconPosition="left"
									className="font-medium"
									type="button"
								>
									{ __( 'Back', 'suremails' ) }
								</Button>
								<Button
									variant="primary"
									loading={ isLoading }
									icon={
										isLoading ? (
											<LoaderIcon className="animate-spin" />
										) : null
									}
									onClick={ handleSaveChanges }
									className="font-medium"
									size="sm"
									type="button"
								>
									{ isLoading
										? __( 'Testing…', 'suremails' )
										: __( 'Save Changes', 'suremails' ) }
								</Button>
							</Drawer.Footer>
						) }
				</Drawer.Panel>
			</form>
		</Drawer>
	);
};

export default ProvidersDrawer;
