import React, { useRef, useState } from 'react';
import { Divider, Header } from './components';
import { __, sprintf } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';
import {
	Checkbox,
	Container,
	Input,
	Label,
	Switch,
	toast,
} from '@bsf/force-ui';
import { cn } from '@utils/utils';
import NavigationButtons from './navigation-buttons';
import { useOnboardingNavigation, useFormValidation } from './hooks';
import { useOnboardingState } from './onboarding-state';
import { ChevronRight, Sparkles } from 'lucide-react';
import { z } from 'zod';
import { activateContentGuard, saveUserDetails } from '@api/settings';
import { setOnboardingCompletionStatus } from '@api/onboarding';

// Constants for the component
const INITIAL_FORM_STATE = {
	first_name:
		window?.suremails?.contentGuardUserDetails?.first_name ||
		window?.suremails?.currentUser?.firstName ||
		'',
	last_name:
		window?.suremails?.contentGuardUserDetails?.last_name ||
		window?.suremails?.currentUser?.lastName ||
		'',
	email:
		window?.suremails?.contentGuardUserDetails?.email ||
		window?.suremails?.currentUser?.email ||
		'',
	agree_to_terms:
		typeof window?.suremails?.contentGuardUserDetails?.agree_to_terms ===
		'boolean'
			? window?.suremails?.contentGuardUserDetails?.agree_to_terms
			: true,
};

// Form validation schema using Zod
const validationSchema = z.object( {
	first_name: z
		.string()
		.min( 1, __( 'Please enter first name', 'suremails' ) ),
	last_name: z.string().min( 1, __( 'Please enter last name', 'suremails' ) ),
	email: z
		.string()
		.email( __( 'Please enter a valid email address', 'suremails' ) ),
} );

const formFields = [
	{
		label: __( 'First Name', 'suremails' ),
		name: 'first_name',
		type: 'text',
		placeholder: __( 'First name', 'suremails' ),
		width: '1/2',
	},
	{
		label: __( 'Last Name', 'suremails' ),
		name: 'last_name',
		type: 'text',
		placeholder: __( 'Last name', 'suremails' ),
		width: '1/2',
	},
	{
		label: __( 'Email', 'suremails' ),
		name: 'email',
		type: 'email',
		placeholder: __( 'Enter Email Address', 'suremails' ),
		width: 'full',
	},
];

const SafeGuardForm = ( {
	formData,
	errors,
	isLoading,
	handleChange,
	handleValidation,
} ) => {
	const [ { safeGuard } ] = useOnboardingState();

	if ( ! safeGuard?.showLeadForm ) {
		return null;
	}

	return (
		<>
			<Container gap="lg" wrap="wrap">
				{ formFields.map( ( field ) => (
					<div
						key={ field.name }
						className={ cn(
							'w-full space-y-1.5',
							field.width === 'full' && 'w-full',
							field.width === '1/2' && 'w-2/5 flex-1'
						) }
					>
						<Label htmlFor={ field.name }>{ field.label }</Label>
						<Input
							id={ field.name }
							name={ field.name }
							size="md"
							type={ field.type }
							placeholder={ field.placeholder }
							autoComplete="off"
							value={ formData[ field.name ] }
							onChange={ handleChange( field.name ) }
							error={ errors[ field.name ] }
							disabled={ isLoading }
							onBlur={ () => handleValidation( field.name ) }
						/>
						{ errors[ field.name ] && (
							<p className="text-text-error text-sm mt-1.5">
								{ errors[ field.name ] }
							</p>
						) }
					</div>
				) ) }
			</Container>
			<div className="mt-3">
				<Checkbox
					name="agree_to_terms"
					checked={ Boolean( formData.agree_to_terms ) }
					size="sm"
					onChange={ handleChange( 'agree_to_terms' ) }
					disabled={ isLoading }
					label={ {
						heading: createInterpolateElement(
							__(
								'Stay in the loop and help shape SureMail! Get feature updates, and help us build a better SureMail by sharing how you use the plugin. <a>Privacy Policy</a>',
								'suremails'
							),
							{
								a: (
									<a
										href={
											window?.suremails?.privacyPolicyURL
										}
										target="_blank"
										rel="noopener noreferrer"
										className="sm-consent ring-0"
									>
										{ __( 'Privacy Policy', 'suremails' ) }
									</a>
								),
							}
						),
					} }
				/>
			</div>
		</>
	);
};

const SafeGuardActivation = ( { handleActivateContentGuard } ) => {
	const [ { safeGuard } ] = useOnboardingState();

	if ( safeGuard?.showLeadForm ) {
		return null;
	}

	return (
		<>
			<div>
				<Switch
					id="reputation-shield-activation"
					label={ {
						heading: __( 'Reputation Shield', 'suremails' ),
						description: __(
							'Reputation Shield identifies potentially problematic content in your emails and blocks them from being sent to your SMTP service.',
							'suremails'
						),
					} }
					value={ safeGuard.activation }
					onChange={ handleActivateContentGuard }
				/>
			</div>
		</>
	);
};

const SafeGuard = () => {
	const [ { safeGuard, hasSkippedStep = false }, setState ] =
		useOnboardingState();
	const { navigateToNextRoute, navigateToPreviousRoute } =
		useOnboardingNavigation();
	const [ isLoading, setIsLoading ] = useState( false );
	const [ formData, setFormData ] = useState( INITIAL_FORM_STATE );
	const [ errors, setErrors ] = useState( {} );
	const formRef = useRef( null );

	// Use the form validation hook
	const { onBlurValidation, validateForm } = useFormValidation(
		formRef,
		formData,
		validationSchema,
		( newErrors ) => {
			setErrors( ( prevErrors ) => ( {
				...prevErrors,
				...newErrors,
			} ) );
		}
	);

	// Handles form field changes
	const handleChange = ( name ) => ( value ) => {
		if ( errors[ name ] ) {
			setErrors( ( prev ) => ( {
				...prev,
				[ name ]: undefined,
			} ) );
		}
		setFormData( ( prev ) => ( {
			...prev,
			[ name ]: value,
		} ) );
	};

	// Wrapper for field validation on blur
	const handleValidation = ( name ) => {
		// Create a mock event object with target.name to use with onBlurValidation
		onBlurValidation( { target: { name } } );
	};

	// Handles content guard activation
	const handleActivateContentGuard = async ( value ) => {
		try {
			const response = await activateContentGuard( value );
			if ( response.success ) {
				setState( {
					safeGuard: {
						...safeGuard,
						activation: value,
					},
				} );
				toast.success(
					sprintf(
						// translators: %1$s is the status of the Content Guard.
						__( 'Reputation Shield %s successfully', 'suremails' ),
						value
							? __( 'activated', 'suremails' )
							: __( 'deactivated', 'suremails' )
					)
				);
				if ( window.suremails ) {
					window.suremails.contentGuardActiveStatus = value
						? 'yes'
						: 'no';
				}
			}
		} catch ( error ) {
			toast.error(
				error.message ||
					__( 'Error authenticating Reputation Shield', 'suremails' )
			);
		}
	};

	// Handles saving user details and activating content guard
	const handleSaveUserDetailsAndActivate = async ( skip = false ) => {
		if ( isLoading ) {
			return false;
		}

		const shouldSkip = skip || ! formData.agree_to_terms;

		if ( ! shouldSkip ) {
			// Use the validateForm function from the hook
			const isValid = validateForm();
			if ( ! isValid ) {
				return false;
			}
		}

		setIsLoading( true );
		try {
			const payload = shouldSkip
				? {
						skip: 'yes',
						agree_to_terms: false,
				  }
				: {
						...formData,
						skip: 'no',
						agree_to_terms: true,
				  };

			await saveUserDetails( {
				...payload,
			} );
			const response = await activateContentGuard( true );
			if ( response.success ) {
				toast.success(
					__( 'Reputation Shield activated', 'suremails' ),
					{
						description: __(
							'Reputation Shield activated successfully',
							'suremails'
						),
					}
				);
				setState( {
					hasSkippedStep: shouldSkip ? true : hasSkippedStep,
					safeGuard: {
						...safeGuard,
						activation: true,
						showLeadForm: false,
					},
				} );
				// Set the localized variable content guard popup status to false
				if ( window.suremails ) {
					window.suremails.contentGuardPopupStatus = false;
					window.suremails.contentGuardActiveStatus = 'yes';
					window.suremails.contentGuardUserDetails = {
						...( window.suremails.contentGuardUserDetails || {} ),
						...payload,
					};
				}

				return true;
			}
		} catch ( error ) {
			toast.error(
				error.message ||
					__( 'Error Activating Reputation Shield', 'suremails' )
			);
			return false;
		} finally {
			setIsLoading( false );
		}

		return false;
	};

	// Mark onboarding as complete in the backend.
	const updateOnboardingCompletionStatus = async ( skipped = false ) => {
		if ( !! window?.suremails?.onboardingCompleted ) {
			return;
		}
		try {
			await setOnboardingCompletionStatus( { skipped } );
		} catch ( error ) {
			toast.error(
				error?.message ?? __( 'Something went wrong', 'suremails' ),
				! error?.message
					? {
							description: __(
								'An error occurred while setting the onboarding status.',
								'suremails'
							),
					  }
					: {}
			);
		}
	};

	// Handles the main activation flow
	const handleActivation = async () => {
		if ( ! safeGuard?.activation && safeGuard?.showLeadForm ) {
			// If not activated, initiate the save user details and activate process
			const isActivated = await handleSaveUserDetailsAndActivate(
				! formData.agree_to_terms
			);
			if ( isActivated ) {
				await updateOnboardingCompletionStatus( hasSkippedStep );
				navigateToNextRoute();
			}
			return;
		}

		// If already activated, mark complete and navigate to done
		await updateOnboardingCompletionStatus( hasSkippedStep );
		navigateToNextRoute();
	};

	const handleSkip = async () => {
		setState( {
			hasSkippedStep: true,
		} );
		await updateOnboardingCompletionStatus( true );
		navigateToNextRoute();
	};

	// Get the appropriate icon for the continue button based on loading state and activation state
	const isFirstTimeActivation =
		! safeGuard?.activation && safeGuard?.showLeadForm;
	const getButtonIcon = () => {
		if ( ! isFirstTimeActivation ) {
			return <ChevronRight />;
		}

		return <Sparkles />;
	};

	return (
		<form
			className="space-y-6"
			ref={ formRef }
			onSubmit={ ( event ) => {
				event.preventDefault();
				handleActivation();
			} }
		>
			<Header
				title={ __(
					'Safeguard Your Email with Reputation Shield',
					'suremails'
				) }
				{ ...( safeGuard.showLeadForm && {
					description: __(
						'Reputation Shield validates your emails with AI for harmful and inappropriate content before they are processed. If an email contains problematic material, it is blocked before it reaches your SMTP provider.',
						'suremails'
					),
				} ) }
			/>

			<SafeGuardForm
				formData={ formData }
				errors={ errors }
				isLoading={ isLoading }
				handleChange={ handleChange }
				handleValidation={ handleValidation }
			/>

			<SafeGuardActivation
				handleActivateContentGuard={ handleActivateContentGuard }
			/>

			<Divider />

			<NavigationButtons
				backProps={ { onClick: navigateToPreviousRoute } }
				continueProps={ {
					onClick: handleActivation,
					text: isFirstTimeActivation
						? __( 'Activate Reputation Shield', 'suremails' )
						: __( 'Continue Setup', 'suremails' ),
					icon: getButtonIcon(),
					iconPosition: ! isFirstTimeActivation ? 'right' : 'left',
				} }
				skipProps={ {
					onClick: handleSkip,
					text: __( 'Skip', 'suremails' ),
				} }
			/>
		</form>
	);
};

export default SafeGuard;
