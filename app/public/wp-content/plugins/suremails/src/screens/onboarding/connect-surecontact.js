import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Input, Label, Text, toast } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import {
	ArrowLeft,
	ArrowRight,
	CircleCheck,
	Gift,
	Loader2 as LoaderIcon,
} from 'lucide-react';
import { useOnboardingNavigation, useFormValidation } from './hooks';
import { useOnboardingState } from './onboarding-state';
import { Header, Divider } from './components';
import { provisionSureContact } from '@api/surecontact';
import { testAndSaveEmailConnection, fetchSettings } from '@api/connections';
import { get_auth_url } from '@api/auth';
import { z } from 'zod';

const SURECONTACT_KEY = 'SURECONTACT';

export const validationSchema = z.object( {
	name: z.string().min( 1, __( 'Please enter your name', 'suremails' ) ),
	email: z
		.string()
		.min( 1, __( 'Please enter your email', 'suremails' ) )
		.email( __( 'Please enter a valid email address', 'suremails' ) ),
} );

const ConnectSureContact = () => {
	const [
		{ sureContact = {}, connectionFormData = {}, connection },
		setState,
	] = useOnboardingState();
	const { navigateToNextRoute, navigateToPreviousRoute } =
		useOnboardingNavigation();

	const { data: settings } = useQuery( {
		queryKey: [ 'settings' ],
		queryFn: fetchSettings,
		select: ( data ) => data?.data || {},
		refetchOnMount: false,
		refetchOnWindowFocus: false,
	} );

	const existingSureContact = settings?.connections
		? Object.values( settings.connections ).find(
				( c ) => c?.type === SURECONTACT_KEY
		  )
		: null;

	const [ formData, setFormData ] = useState( () => ( {
		name:
			sureContact.name ||
			[
				suremails?.currentUser?.firstName,
				suremails?.currentUser?.lastName,
			]
				.filter( Boolean )
				.join( ' ' ),
		email: sureContact.email || suremails?.currentUser?.email || '',
		website: sureContact.website || suremails?.siteUrl || '',
	} ) );
	const [ errors, setErrors ] = useState( {} );
	const [ isCreating, setIsCreating ] = useState( false );
	const [ isRedirecting, setIsRedirecting ] = useState( false );
	const [ isExchanging, setIsExchanging ] = useState( false );
	const formRef = useRef( null );

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

	const persistFormToOnboarding = () => {
		setState( ( prev ) => ( {
			...prev,
			sureContact: {
				...( prev.sureContact ?? {} ),
				name: formData.name,
				email: formData.email,
				website: formData.website,
			},
		} ) );
	};

	// Auto-exchange OAuth token if returning from SureContact OAuth.
	useEffect( () => {
		const pendingToken = connectionFormData?.oauth_token;
		const pendingState = connectionFormData?.oauth_state;
		if ( ! pendingToken || isExchanging ) {
			return;
		}

		const exchange = async () => {
			setIsExchanging( true );
			try {
				const response = await testAndSaveEmailConnection( {
					provider: SURECONTACT_KEY,
					settings: {
						connection_title: 'SureContact SMTP',
						from_email:
							formData.email ||
							sureContact?.email ||
							suremails?.currentUser?.email ||
							'',
						from_name: ( formData.name || '' ).trim(),
						force_from_email: true,
						force_from_name: !! formData.name,
						priority: 1,
						oauth_token: pendingToken,
						oauth_state: pendingState,
					},
				} );

				if ( response?.success ) {
					toast.success(
						__( 'Connected to SureContact.', 'suremails' )
					);
					setState( ( prev ) => ( {
						...prev,
						connectionSaved: SURECONTACT_KEY,
						connectionFormData: {
							...( prev.connectionFormData ?? {} ),
							oauth_token: undefined,
							oauth_state: undefined,
						},
					} ) );
					navigateToNextRoute();
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
	}, [ connectionFormData?.oauth_token ] );

	const handleProvision = async () => {
		if ( ! validateForm() ) {
			return;
		}

		persistFormToOnboarding();
		setIsCreating( true );

		try {
			const response = await provisionSureContact( {
				name: formData.name.trim(),
				email: formData.email.trim(),
				website: formData.website || suremails?.siteUrl || '',
			} );

			if ( response?.success ) {
				toast.success( __( 'Connected to SureContact.', 'suremails' ), {
					description: __(
						'Your free SureContact account is ready.',
						'suremails'
					),
				} );
				setState( ( prev ) => ( {
					...prev,
					connectionSaved: SURECONTACT_KEY,
					sureContactConnection: response.connection ?? null,
				} ) );
				navigateToNextRoute();
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
			setIsCreating( false );
		}
	};

	const handleConnectExisting = async () => {
		persistFormToOnboarding();
		setIsRedirecting( true );
		try {
			const redirectUrl = suremails?.adminURL || window.location.href;
			const response = await get_auth_url(
				'surecontact',
				'',
				'',
				redirectUrl
			);
			if ( response?.auth_url ) {
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

	// If user lands here without picking SureContact (unlikely guard).
	useEffect( () => {
		if ( connection && connection !== SURECONTACT_KEY ) {
			navigateToPreviousRoute();
		}
	}, [ connection ] );

	const isBusy = isCreating || isRedirecting || isExchanging;

	// If an existing SureContact connection is already saved (returning to
	// onboarding), show a brief confirmation and let the user move on.
	if ( existingSureContact ) {
		return (
			<form
				className="space-y-6"
				onSubmit={ ( event ) => {
					event.preventDefault();
					navigateToNextRoute();
				} }
			>
				<Header
					title={ __( 'SureContact is connected', 'suremails' ) }
					description={ __(
						'Only one SureContact connection is allowed per site. Continue to send a test email.',
						'suremails'
					) }
				/>

				<div className="flex items-start gap-3 rounded-lg border border-solid border-border-subtle bg-background-primary p-5">
					<CircleCheck
						className="size-5 text-icon-success shrink-0 mt-0.5"
						strokeWidth={ 1.75 }
					/>
					<div className="space-y-1">
						<Text as="p" size={ 14 } weight={ 600 } color="primary">
							{ __(
								'SureContact is already connected',
								'suremails'
							) }
						</Text>
						<Text as="p" size={ 13 } color="secondary">
							{ existingSureContact.from_email }
						</Text>
					</div>
				</div>

				<Divider />

				<div className="flex items-center justify-between gap-3">
					<Button
						variant="outline"
						icon={ <ArrowLeft /> }
						onClick={ navigateToPreviousRoute }
						type="button"
					>
						{ __( 'Back', 'suremails' ) }
					</Button>
					<Button
						icon={ <ArrowRight /> }
						iconPosition="right"
						onClick={ navigateToNextRoute }
						type="button"
					>
						{ __( 'Continue', 'suremails' ) }
					</Button>
				</div>
			</form>
		);
	}

	return (
		<form
			ref={ formRef }
			className="space-y-6"
			onSubmit={ ( event ) => {
				event.preventDefault();
				handleProvision();
			} }
		>
			<Header
				title={ __( 'Create your SureContact account', 'suremails' ) }
				description={ __(
					"Takes 30 seconds. No credit card needed. We'll auto-configure everything.",
					'suremails'
				) }
			/>

			<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
				<div className="space-y-1.5">
					<Label htmlFor="sc-name">
						{ __( 'Your Name', 'suremails' ) }
					</Label>
					<Input
						id="sc-name"
						name="name"
						size="md"
						type="text"
						placeholder={ __( 'Your name', 'suremails' ) }
						value={ formData.name }
						onChange={ ( val ) => handleFieldChange( 'name', val ) }
						onBlur={ () => handleBlur( 'name' ) }
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
					<Label htmlFor="sc-email">
						{ __( 'Your Email', 'suremails' ) }
					</Label>
					<Input
						id="sc-email"
						name="email"
						size="md"
						type="email"
						placeholder={ __( 'you@example.com', 'suremails' ) }
						value={ formData.email }
						onChange={ ( val ) =>
							handleFieldChange( 'email', val )
						}
						onBlur={ () => handleBlur( 'email' ) }
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
				<Label htmlFor="sc-website">
					{ __( 'Website URL', 'suremails' ) }
				</Label>
				<Input
					id="sc-website"
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
					onClick={ navigateToPreviousRoute }
					disabled={ isBusy }
					type="button"
				>
					{ __( 'Back', 'suremails' ) }
				</Button>
				<Button
					icon={
						isCreating || isExchanging ? (
							<LoaderIcon className="animate-spin" />
						) : null
					}
					onClick={ handleProvision }
					disabled={ isBusy }
					type="button"
				>
					{ isCreating || isExchanging
						? __( 'Creating account…', 'suremails' )
						: __( 'Create Account and Connect', 'suremails' ) }
				</Button>
			</div>
		</form>
	);
};

export default ConnectSureContact;
