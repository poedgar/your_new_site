import { useRef, useEffect, useMemo, useCallback } from '@wordpress/element';
import { Badge, Button, Skeleton, Text, toast } from '@bsf/force-ui';
import { __, sprintf } from '@wordpress/i18n';
import useProviders, {
	SURECONTACT_KEY,
} from '@screens/connections/use-dynamic-providers';
import { cn } from '@utils/utils';
import { useOnboardingState } from './onboarding-state';
import {
	useConnectionTitleAndSequence,
	useFormValidation,
	useOnboardingNavigation,
} from './hooks';
import { Header, Divider } from './components';
import ExtendedDynamicForm from '@screens/connections/extended-dynamic-form';
import { testAndSaveEmailConnection } from '@api/connections';
import { SureContactIcon as SureContactSvg } from '@assets/icons';
import {
	CheckSquare,
	ChevronDown,
	ChevronLeft,
	ChevronRight,
	Square,
	ShieldCheck,
	BarChart3,
	ShoppingCart,
} from 'lucide-react';

const MAX_PREVIEW_ICONS = 6;

const SureContactLogo = () => (
	<SureContactSvg className="shrink-0 size-10 rounded-lg" />
);

const useSelectedConnection = () => {
	const { providers } = useProviders();
	const [ { connection } ] = useOnboardingState();
	return providers.find( ( provider ) => provider.value === connection );
};

const SureContactCard = ( { isSelected, onSelect } ) => {
	const features = [
		{
			icon: ShieldCheck,
			label: __( 'Auto-authenticated', 'suremails' ),
		},
		{
			icon: BarChart3,
			// translators: %+ is a literal percent sign, not a placeholder.
			label: __( '99%+ deliverability', 'suremails' ),
		},
		{
			icon: ShoppingCart,
			label: __( 'Works with WooCommerce', 'suremails' ),
		},
	];

	const Indicator = isSelected ? CheckSquare : Square;

	return (
		<button
			type="button"
			onClick={ () => onSelect( SURECONTACT_KEY ) }
			aria-pressed={ isSelected }
			className={ cn(
				'w-full text-left rounded-lg border border-solid p-4 transition-all cursor-pointer',
				isSelected
					? 'border-border-interactive bg-alert-background-info'
					: 'border-border-subtle bg-background-primary hover:border-border-strong'
			) }
		>
			<div className="flex items-start gap-3">
				<SureContactLogo />
				<div className="flex-1 min-w-0 space-y-2">
					<div className="flex items-center gap-2 flex-wrap">
						<Text
							as="span"
							size={ 15 }
							weight={ 600 }
							color="primary"
						>
							{ __( 'SureContact SMTP', 'suremails' ) }
						</Text>
						<Text
							as="span"
							size={ 12 }
							weight={ 500 }
							className="text-support-info"
						>
							{ __( 'Recommended', 'suremails' ) }
						</Text>
						<Badge
							label={ __( '100 Free Emails', 'suremails' ) }
							size="xs"
							type="pill"
							variant="green"
						/>
					</div>
					<Text
						as="p"
						size={ 13 }
						color="secondary"
						className="leading-relaxed"
					>
						{ __(
							'Start sending in one click no API keys, no DNS. Includes delivery reports, bounce tracking, and a WordPress-native dashboard.',
							'suremails'
						) }
					</Text>
					<div className="flex items-center gap-4 flex-wrap">
						{ features.map( ( { icon: Icon, label } ) => (
							<div
								key={ label }
								className="flex items-center gap-1.5"
							>
								<Icon
									className="size-3.5 text-icon-secondary"
									strokeWidth={ 1.75 }
								/>
								<Text as="span" size={ 12 } color="secondary">
									{ label }
								</Text>
							</div>
						) ) }
					</div>
				</div>
				<Indicator
					className={ cn(
						'size-5 shrink-0',
						isSelected
							? 'text-icon-interactive'
							: 'text-icon-tertiary'
					) }
					strokeWidth={ 1.75 }
				/>
			</div>
		</button>
	);
};

const ProviderTile = ( { provider, isSelected, onSelect } ) => {
	const isUnavailable = !! provider.badge;

	return (
		<button
			type="button"
			onClick={ () => onSelect( provider ) }
			aria-pressed={ isSelected }
			className={ cn(
				'w-full flex items-center justify-between gap-3 rounded-lg border border-solid p-3 transition-all text-left',
				isSelected
					? 'border-border-interactive bg-background-primary'
					: 'border-border-subtle bg-background-primary hover:border-border-strong',
				isUnavailable && 'opacity-70 cursor-not-allowed'
			) }
		>
			<div className="flex items-center gap-3 min-w-0">
				<span className="shrink-0 size-6 flex items-center justify-center">
					{ provider.icon }
				</span>
				<Text
					as="span"
					size={ 14 }
					weight={ 500 }
					color="primary"
					className="truncate"
				>
					{ provider.display_name }
				</Text>
				{ provider.badge }
			</div>
			<span
				className={ cn(
					'shrink-0 size-5 rounded-full border border-solid flex items-center justify-center',
					isSelected
						? 'border-border-interactive'
						: 'border-border-strong'
				) }
			>
				{ isSelected && (
					<span className="size-2.5 rounded-full bg-border-interactive" />
				) }
			</span>
		</button>
	);
};

const OtherProvidersCard = ( {
	isOpen,
	onToggle,
	providers,
	selectedValue,
	onSelect,
} ) => {
	const toastRef = useRef( false );

	const handlePick = ( provider ) => {
		if ( provider?.badge ) {
			if ( ! toastRef.current ) {
				const prerequisiteMessage = provider.prerequisite ? (
					provider.prerequisite
				) : (
					<span
						dangerouslySetInnerHTML={ {
							__html: sprintf(
								// translators: %1$s is an anchor opening tag and %2$s is the closing tag.
								__(
									"This provider isn't compatible. For help, contact us %1$shere%2$s.",
									'suremails'
								),
								'<a href="' + suremails.supportURL + '">',
								'</a>'
							),
						} }
					/>
				);
				toast.info(
					provider.provider_type === 'not_compatible'
						? prerequisiteMessage
						: __( 'This provider is coming soon!', 'suremails' )
				);
				toastRef.current = true;
				setTimeout( () => {
					toastRef.current = false;
				}, 500 );
			}
			return;
		}
		onSelect( provider.value );
	};

	const previewProviders = providers.slice( 0, MAX_PREVIEW_ICONS );
	const overflowCount = providers.length - previewProviders.length;

	return (
		<div className="rounded-lg border border-solid border-border-subtle bg-background-primary overflow-hidden">
			<button
				type="button"
				onClick={ onToggle }
				className="w-full flex items-center justify-between gap-3 p-4 bg-transparent border-0 cursor-pointer"
			>
				<Text as="span" size={ 14 } weight={ 500 } color="primary">
					{ __( 'Or use a different provider', 'suremails' ) }
				</Text>
				<span className="flex items-center gap-3 min-w-0">
					{ ! isOpen && !! previewProviders.length && (
						<span className="flex items-center gap-2">
							{ previewProviders.map( ( provider ) => (
								<span
									key={ provider.value }
									title={ provider.display_name }
									className="shrink-0 size-9 flex items-center justify-center rounded-lg border border-solid border-border-subtle bg-background-primary shadow-sm"
								>
									{ provider.icon }
								</span>
							) ) }
							{ overflowCount > 0 && (
								<Text
									as="span"
									size={ 12 }
									weight={ 500 }
									color="secondary"
								>
									{ sprintf(
										// translators: %d is the number of additional providers.
										__( '+%d more', 'suremails' ),
										overflowCount
									) }
								</Text>
							) }
						</span>
					) }
					<ChevronDown
						className={ cn(
							'size-4 shrink-0 text-icon-secondary transition-transform',
							isOpen && 'rotate-180'
						) }
						strokeWidth={ 1.75 }
					/>
				</span>
			</button>
			{ isOpen && (
				<div className="mx-4 mb-4 rounded-lg bg-background-secondary p-3">
					<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
						{ providers.map( ( provider ) => (
							<ProviderTile
								key={ provider.value }
								provider={ provider }
								isSelected={ selectedValue === provider.value }
								onSelect={ handlePick }
							/>
						) ) }
					</div>
				</div>
			) }
		</div>
	);
};

const ConnectionListSkeleton = () => (
	<div className="space-y-3">
		<Skeleton className="h-32 w-full rounded-lg" />
		<Skeleton className="h-14 w-full rounded-lg" />
	</div>
);

const ConnectionForm = ( {
	connection,
	formData,
	formFields,
	errors,
	onBlurValidation,
	handleChange,
} ) => {
	if ( ! connection || connection.value === SURECONTACT_KEY ) {
		return null;
	}

	return (
		<>
			<Header
				title={ sprintf(
					// translators: %s is the provider name.
					__( "Now, Let's Connect With %s", 'suremails' ),
					connection?.display_name
				) }
				description={ sprintf(
					// translators: %s is the provider name.
					__(
						'Enter the details below to connect with your %s account.',
						'suremails'
					),
					connection?.display_name
				) }
			/>
			<ExtendedDynamicForm
				connectionData={ formData ?? {} }
				fields={ formFields ?? {} }
				onChange={ handleChange }
				errors={ errors ?? {} }
				inlineValidator={ onBlurValidation }
			/>
		</>
	);
};

const ConnectionProviders = () => {
	const [
		{
			connection,
			connectionFormData,
			connectionSaved,
			connectionErrors = {},
			otherProvidersOpen = false,
		},
		setState,
	] = useOnboardingState();
	const { providers, isLoading } = useProviders();
	const selectedConnection = useSelectedConnection();
	const { navigateToNextRoute, navigateToPreviousRoute } =
		useOnboardingNavigation();
	const formRef = useRef( null );

	const { titleSuffix, sequenceNumber } =
		useConnectionTitleAndSequence( selectedConnection );

	const otherProviders = useMemo(
		() => providers.filter( ( p ) => p.value !== SURECONTACT_KEY ),
		[ providers ]
	);

	const selectedOtherProvider =
		connection && connection !== SURECONTACT_KEY ? connection : '';

	const isSureContactSelected = connection === SURECONTACT_KEY;
	const showInlineForm = !! connection && connection !== SURECONTACT_KEY;

	const handleSelectSureContact = () => {
		setState( {
			connection: SURECONTACT_KEY,
			otherProvidersOpen: false,
			...( connectionSaved !== SURECONTACT_KEY
				? { connectionSaved: null, connectionFormData: null }
				: {} ),
		} );
	};

	const handleToggleOtherProviders = () => {
		setState( {
			otherProvidersOpen: ! otherProvidersOpen,
		} );
	};

	const handleSelectOtherProvider = ( value ) => {
		setState( {
			connection: value,
			otherProvidersOpen: true,
			...( connectionSaved !== value
				? { connectionSaved: null, connectionFormData: null }
				: {} ),
		} );
	};

	const handleChange = ( value ) => {
		const fieldName = Object.keys( value )[ 0 ] ?? '';

		setState( {
			connectionFormData: {
				...connectionFormData,
				...value,
			},
			connectionErrors: {
				...connectionErrors,
				[ fieldName ]: undefined,
			},
		} );
	};

	const defaultValues = useMemo( () => {
		return selectedConnection?.fields?.reduce( ( acc, field ) => {
			acc[ field.name ] = field.default;
			return acc;
		}, {} );
	}, [ selectedConnection ] );

	useEffect( () => {
		if ( connectionFormData || ! selectedConnection ) {
			return;
		}
		if ( selectedConnection.value === SURECONTACT_KEY ) {
			return;
		}
		setState( {
			connectionFormData: {
				...defaultValues,
				connection_title: titleSuffix,
				priority: sequenceNumber,
			},
		} );
	}, [ selectedConnection, connectionSaved ] );

	const handleError = useCallback( ( errors ) => {
		setState( ( prev ) => ( {
			...prev,
			connectionErrors: {
				...prev.connectionErrors,
				...errors,
			},
		} ) );
	} );

	const { onBlurValidation, validateForm } = useFormValidation(
		formRef,
		connectionFormData,
		selectedConnection?.schema,
		handleError
	);

	const handleSaveOtherProvider = async () => {
		if ( ! validateForm() ) {
			return;
		}

		const payload = {
			settings: connectionFormData,
			provider: connection?.toUpperCase(),
		};

		try {
			const response = await testAndSaveEmailConnection( payload );

			if ( response?.success ) {
				toast.success( __( 'Saved successfully!', 'suremails' ), {
					description: __(
						'Connection details saved successfully!',
						'suremails'
					),
				} );
				setState( ( prev ) => ( {
					...prev,
					connectionSaved: connection,
				} ) );
				navigateToNextRoute();
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
		}
	};

	const handleContinue = async () => {
		if ( ! connection ) {
			return;
		}

		if ( connection === SURECONTACT_KEY ) {
			navigateToNextRoute();
			return;
		}

		if ( connectionSaved === connection ) {
			navigateToNextRoute();
			return;
		}

		await handleSaveOtherProvider();
	};

	const handleBack = () => {
		// While the form is up, Back returns to the provider picker.
		if ( showInlineForm ) {
			setState( {
				connection: null,
				connectionFormData: null,
			} );
			return;
		}
		navigateToPreviousRoute();
	};

	return (
		<form ref={ formRef } className="space-y-6">
			{ showInlineForm ? (
				<ConnectionForm
					connection={ selectedConnection }
					formData={ connectionFormData }
					formFields={ selectedConnection?.fields }
					errors={ connectionErrors }
					onBlurValidation={ onBlurValidation }
					handleChange={ handleChange }
				/>
			) : (
				<>
					<Header
						title={ __( 'Pick a sending provider', 'suremails' ) }
						description={ __(
							"We recommend SureContact SMTP it's free, zero-config, and built for WordPress. You can switch anytime.",
							'suremails'
						) }
					/>

					{ isLoading ? (
						<ConnectionListSkeleton />
					) : (
						<div className="space-y-3">
							<SureContactCard
								isSelected={ isSureContactSelected }
								onSelect={ handleSelectSureContact }
							/>
							<OtherProvidersCard
								isOpen={ otherProvidersOpen }
								onToggle={ handleToggleOtherProviders }
								providers={ otherProviders }
								selectedValue={ selectedOtherProvider }
								onSelect={ handleSelectOtherProvider }
							/>
						</div>
					) }
				</>
			) }

			<Divider />

			<div className="flex items-center justify-between gap-3">
				<Button
					variant="outline"
					icon={ <ChevronLeft /> }
					onClick={ handleBack }
					type="button"
				>
					{ __( 'Back', 'suremails' ) }
				</Button>
				<Button
					icon={ <ChevronRight /> }
					iconPosition="right"
					onClick={ handleContinue }
					disabled={ ! connection }
					type="button"
				>
					{ showInlineForm
						? __( 'Save & Continue', 'suremails' )
						: __( 'Continue Setup', 'suremails' ) }
				</Button>
			</div>
		</form>
	);
};

export default ConnectionProviders;
