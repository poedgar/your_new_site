import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Input, Label, Text, toast } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, Gift, Loader2 as LoaderIcon } from 'lucide-react';
import { provisionSureContact } from '@api/surecontact';
import { testAndSaveEmailConnection } from '@api/connections';
import { get_auth_url } from '@api/auth';
import { Divider } from '@screens/onboarding/components';
import { useFormValidation } from '@screens/onboarding/hooks';
import { validationSchema } from '@screens/onboarding/connect-surecontact';

const SURECONTACT_KEY = 'SURECONTACT';

/**
 * SureContact connection form rendered inside the connections drawer.
 *
 * Mirrors the onboarding "Create your SureContact account" screen — single
 * form with name/email/website, blue info banner, and an inline "Connect it
 * instead" link for the existing-account OAuth path.
 *
 * Two paths:
 *   - Provision: POSTs to /suremails/v1/surecontact/provision. Server creates
 *     the SureContact account and saves the SureMails connection in one go.
 *   - Existing account: redirects to surecontact.com/connect for an OAuth-style
 *     handshake. On return the drawer remounts with `pendingOAuthToken` set
 *     from `currentConnection.oauth_token` — we exchange it on mount.
 *
 * @param {Object}   root0
 * @param {string}   root0.pendingOAuthToken OAuth token from the redirect callback.
 * @param {string}   root0.pendingOAuthState OAuth state from the redirect callback (CSRF token).
 * @param {number}   root0.sequenceId        Next free connection priority/sequence.
 * @param {Function} root0.onBack            Called when the user clicks Back — returns to the provider list.
 * @param {Function} root0.onSuccess         Called with the saved connection.
 */
const SureContactDrawerForm = ( {
	pendingOAuthToken,
	pendingOAuthState,
	sequenceId = 1,
	onBack,
	onSuccess,
} ) => {
	const [ formData, setFormData ] = useState( {
		name:
			[
				suremails?.currentUser?.firstName,
				suremails?.currentUser?.lastName,
			]
				.filter( Boolean )
				.join( ' ' ) || '',
		email: suremails?.currentUser?.email || '',
		website: suremails?.siteUrl || '',
	} );
	const [ errors, setErrors ] = useState( {} );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ isRedirecting, setIsRedirecting ] = useState( false );
	const [ isExchanging, setIsExchanging ] = useState( false );
	const formRef = useRef( null );
	// Synchronous guard against the OAuth exchange firing twice — useState
	// can't help here because StrictMode double-invokes the effect before the
	// first setState has committed.
	const exchangeStartedRef = useRef( false );

	const { onBlurValidation, validateForm } = useFormValidation(
		formRef,
		formData,
		validationSchema,
		( newErrors ) => setErrors( ( prev ) => ( { ...prev, ...newErrors } ) )
	);

	const handleFieldChange = ( field, value ) => {
		setFormData( ( prev ) => ( { ...prev, [ field ]: value } ) );
		if ( errors[ field ] ) {
			setErrors( ( prev ) => ( { ...prev, [ field ]: undefined } ) );
		}
	};

	const handleBlur = ( field ) => {
		onBlurValidation( { target: { name: field } } );
	};

	// On mount: if we just returned from the SureContact OAuth redirect, the
	// connections screen will have hydrated `pendingOAuthToken` from the
	// stashed callback data. Exchange it for an api_key.
	useEffect( () => {
		if ( ! pendingOAuthToken || exchangeStartedRef.current ) {
			return;
		}
		exchangeStartedRef.current = true;

		const exchange = async () => {
			setIsExchanging( true );
			// Token is single-use and now spent — purge any leftover copy in
			// localStorage so it doesn't outlive the exchange window even if
			// the connections-screen mount-cleanup raced or missed.
			localStorage.removeItem( 'formStateValues' );
			localStorage.removeItem( 'formStateValuesTimestamp' );
			try {
				const response = await testAndSaveEmailConnection( {
					provider: SURECONTACT_KEY,
					settings: {
						connection_title: 'SureContact SMTP',
						from_email:
							formData.email ||
							suremails?.currentUser?.email ||
							'',
						from_name: formData.name || '',
						force_from_email: true,
						force_from_name: !! formData.name,
						priority: sequenceId,
						oauth_token: pendingOAuthToken,
						oauth_state: pendingOAuthState,
					},
				} );

				if ( response?.success ) {
					toast.success(
						__( 'Connected to SureContact.', 'suremails' )
					);
					if ( onSuccess ) {
						onSuccess( response.connection );
					}
				} else {
					toast.error(
						__( 'Could not connect SureContact.', 'suremails' ),
						{
							description:
								response?.message ||
								__( 'Please try again.', 'suremails' ),
							autoDismiss: false,
						}
					);
				}
			} catch ( error ) {
				toast.error(
					__( 'Could not connect SureContact.', 'suremails' ),
					{
						description:
							error?.message ||
							__( 'Please try again.', 'suremails' ),
						autoDismiss: false,
					}
				);
			} finally {
				setIsExchanging( false );
			}
		};

		exchange();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ pendingOAuthToken ] );

	const handleProvision = async () => {
		if ( ! validateForm() ) {
			return;
		}
		setIsLoading( true );
		try {
			const response = await provisionSureContact( {
				name: formData.name.trim(),
				email: formData.email.trim(),
				website: formData.website || suremails?.siteUrl || '',
			} );
			if ( response?.success ) {
				toast.success( __( 'Connected to SureContact.', 'suremails' ) );
				if ( onSuccess ) {
					onSuccess( response.connection );
				}
			} else {
				toast.error(
					__( 'Could not connect SureContact.', 'suremails' ),
					{
						description:
							response?.message ||
							__( 'Please try again.', 'suremails' ),
						autoDismiss: false,
					}
				);
			}
		} catch ( error ) {
			toast.error( __( 'Could not connect SureContact.', 'suremails' ), {
				description:
					error?.message || __( 'Please try again.', 'suremails' ),
				autoDismiss: false,
			} );
		} finally {
			setIsLoading( false );
		}
	};

	const handleConnectExisting = async () => {
		setIsRedirecting( true );
		try {
			// Bare admin URL — see note in connect-surecontact.js. A fragment
			// here would push the OAuth callback params into window.location.hash
			// where the auth-code-display component can't read them.
			const redirectUrl = suremails?.adminURL || window.location.href;
			const response = await get_auth_url(
				'surecontact',
				'',
				'',
				redirectUrl
			);
			if ( response?.auth_url ) {
				localStorage.setItem(
					'formStateValuesTimestamp',
					String( Date.now() + 300000 )
				);
				localStorage.setItem(
					'formStateValues',
					JSON.stringify( {
						type: SURECONTACT_KEY,
						force_save: true,
					} )
				);
				window.open(
					response.auth_url,
					'_self',
					'noopener noreferrer'
				);
				return;
			}

			toast.error( __( 'Could not start authorization.', 'suremails' ), {
				description:
					response?.error || __( 'Please try again.', 'suremails' ),
				autoDismiss: false,
			} );
		} catch ( error ) {
			toast.error( __( 'Could not start authorization.', 'suremails' ), {
				description:
					error?.message || __( 'Please try again.', 'suremails' ),
				autoDismiss: false,
			} );
		} finally {
			setIsRedirecting( false );
		}
	};

	const isBusy = isLoading || isRedirecting || isExchanging;

	let submitLabel = __( 'Create Account and Connect', 'suremails' );
	if ( isExchanging ) {
		submitLabel = __( 'Connecting…', 'suremails' );
	} else if ( isLoading ) {
		submitLabel = __( 'Creating account…', 'suremails' );
	}

	// Enter inside any input submits the form. The drawer's outer <form> has
	// no onSubmit (it spans multiple provider paths), so we wire Enter here
	// to match the onboarding form's keyboard behaviour.
	const handleKeyDown = ( event ) => {
		if ( event.key === 'Enter' && ! isBusy ) {
			event.preventDefault();
			handleProvision();
		}
	};

	return (
		<div ref={ formRef } className="space-y-6">
			<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
				<div className="space-y-1.5">
					<Label htmlFor="sc-drawer-name">
						{ __( 'Your Name', 'suremails' ) }
					</Label>
					<Input
						id="sc-drawer-name"
						name="name"
						size="md"
						type="text"
						placeholder={ __( 'Your name', 'suremails' ) }
						value={ formData.name }
						onChange={ ( val ) => handleFieldChange( 'name', val ) }
						onBlur={ () => handleBlur( 'name' ) }
						onKeyDown={ handleKeyDown }
						error={ errors.name }
						disabled={ isBusy }
					/>
					{ errors.name && (
						<Text as="p" size={ 12 } color="error">
							{ errors.name }
						</Text>
					) }
				</div>
				<div className="space-y-1.5">
					<Label htmlFor="sc-drawer-email">
						{ __( 'Your Email', 'suremails' ) }
					</Label>
					<Input
						id="sc-drawer-email"
						name="email"
						size="md"
						type="email"
						placeholder={ __( 'you@example.com', 'suremails' ) }
						value={ formData.email }
						onChange={ ( val ) =>
							handleFieldChange( 'email', val )
						}
						onBlur={ () => handleBlur( 'email' ) }
						onKeyDown={ handleKeyDown }
						error={ errors.email }
						disabled={ isBusy }
					/>
					{ errors.email && (
						<Text as="p" size={ 12 } color="error">
							{ errors.email }
						</Text>
					) }
				</div>
			</div>

			<div className="space-y-1.5">
				<Label htmlFor="sc-drawer-website">
					{ __( 'Website URL', 'suremails' ) }
				</Label>
				<Input
					id="sc-drawer-website"
					name="website"
					size="md"
					type="text"
					placeholder="https://yourdomian.com"
					value={ formData.website }
					disabled
				/>
				<Text as="p" size={ 12 } color="tertiary">
					{ __(
						'Locked to this site. We verify it during account creation to protect deliverability.',
						'suremails'
					) }
				</Text>
			</div>

			<div className="flex items-start gap-3 rounded-lg border border-solid border-alert-border-info bg-alert-background-info p-4">
				<Gift
					className="size-5 text-support-info shrink-0 mt-0.5"
					strokeWidth={ 1.75 }
				/>
				<Text as="p" size={ 13 } color="primary">
					{ __(
						"You'll get 100 free emails on the Free plan. Upgrade anytime to keep sending.",
						'suremails'
					) }
				</Text>
			</div>

			<button
				type="button"
				onClick={ handleConnectExisting }
				disabled={ isBusy }
				className="bg-transparent border-0 p-0 cursor-pointer text-text-primary underline underline-offset-2 hover:text-text-secondary disabled:cursor-not-allowed disabled:opacity-60 text-sm"
			>
				{ isRedirecting
					? __( 'Redirecting…', 'suremails' )
					: __(
							'Already have a SureContact account? Connect it instead →',
							'suremails'
					  ) }
			</button>

			<Divider />

			<div className="flex items-center justify-between gap-3">
				<Button
					variant="outline"
					icon={ <ArrowLeft /> }
					onClick={ onBack }
					disabled={ isBusy }
					type="button"
				>
					{ __( 'Back', 'suremails' ) }
				</Button>
				<Button
					icon={
						isLoading || isExchanging ? (
							<LoaderIcon className="animate-spin" />
						) : null
					}
					onClick={ handleProvision }
					disabled={ isBusy }
					type="button"
				>
					{ submitLabel }
				</Button>
			</div>
		</div>
	);
};

export default SureContactDrawerForm;
