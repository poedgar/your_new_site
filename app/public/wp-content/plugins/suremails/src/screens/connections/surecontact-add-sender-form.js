import { useState, useMemo, useEffect } from '@wordpress/element';
import { Button, Input, Label, Select, Text, toast } from '@bsf/force-ui';
import { Divider } from '@screens/onboarding/components';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, Loader2 as LoaderIcon } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { fetchSettings, testAndSaveEmailConnection } from '@api/connections';
import { getSureContactSendingDomains } from '@api/surecontact';

const SURECONTACT_KEY = 'SURECONTACT';

/**
 * "Add sender" form for paid SureContact accounts. Lets the user point a new
 * connection at another from_email on one of the workspace's verified sending
 * domains. The new row shares the account's api_key server-side (the clone
 * path in SaveTestConnection) — no OAuth, no key rotation.
 *
 * @param {Object}   root0
 * @param {number}   root0.sequenceId Next free connection priority/sequence.
 * @param {Function} root0.onBack     Returns to the provider list.
 * @param {Function} root0.onSuccess  Called with the saved connection.
 */
const SureContactAddSenderForm = ( { sequenceId = 1, onBack, onSuccess } ) => {
	const { data: settings } = useQuery( {
		queryKey: [ 'settings' ],
		queryFn: fetchSettings,
		// get-settings wraps the payload as { success, data: {...} }; unwrap to
		// the same shape the connections screen + meter use so settings.connections
		// resolves (otherwise connectionId stays null and the domains fetch never
		// fires).
		select: ( data ) => data?.data || {},
		refetchOnWindowFocus: false,
	} );

	// Any SureContact row works as the source — they all share one api_key, and
	// the server resolves the primary row when cloning.
	const connectionId = useMemo( () => {
		const connection = settings?.connections
			? Object.values( settings.connections ).find(
					( item ) => item?.type === SURECONTACT_KEY
			  )
			: undefined;
		return connection?.id ?? null;
	}, [ settings?.connections ] );

	const {
		data: domainData,
		isLoading: domainsLoading,
		isError: domainsError,
		error: domainsErrorObj,
	} = useQuery( {
		queryKey: [ 'surecontact-sending-domains', connectionId ],
		queryFn: () => getSureContactSendingDomains( connectionId ),
		enabled: Boolean( connectionId ),
		refetchOnWindowFocus: false,
		// Verified domains can change on the SureContact side at any time;
		// always pull a fresh list when the picker opens rather than serving a
		// stale cached result from earlier in the session.
		staleTime: 0,
		refetchOnMount: 'always',
		gcTime: 0,
	} );

	const domains = useMemo( () => domainData?.domains ?? [], [ domainData ] );

	const [ localPart, setLocalPart ] = useState( '' );
	const [ fromName, setFromName ] = useState( '' );
	const [ selectedDomain, setSelectedDomain ] = useState( '' );
	const [ errors, setErrors ] = useState( {} );
	const [ isSaving, setIsSaving ] = useState( false );

	// Default to the first verified domain once the list loads.
	useEffect( () => {
		if ( ! selectedDomain && domains.length > 0 ) {
			setSelectedDomain( domains[ 0 ].domain );
		}
	}, [ domains, selectedDomain ] );

	const sendingDomainsUrl =
		window?.suremails?.surecontactSendingDomainsUrl || '';

	const handleSubmit = async () => {
		const nextErrors = {};
		const trimmedLocal = localPart.trim().replace( /@.*$/, '' );
		if ( ! trimmedLocal ) {
			nextErrors.localPart = __(
				'Enter the part before the @.',
				'suremails'
			);
		}
		if ( ! selectedDomain ) {
			nextErrors.domain = __(
				'Select a verified sending domain.',
				'suremails'
			);
		}
		if ( Object.keys( nextErrors ).length > 0 ) {
			setErrors( nextErrors );
			return;
		}

		const fromEmail = `${ trimmedLocal }@${ selectedDomain }`;
		const trimmedName = fromName.trim();
		setIsSaving( true );

		try {
			const response = await testAndSaveEmailConnection( {
				provider: SURECONTACT_KEY,
				settings: {
					connection_title: __( 'SureContact SMTP', 'suremails' ),
					from_email: fromEmail,
					from_name: trimmedName,
					force_from_email: true,
					force_from_name: Boolean( trimmedName ),
					priority: sequenceId,
				},
			} );

			if ( response?.success ) {
				toast.success( __( 'Sender added.', 'suremails' ) );
				if ( onSuccess ) {
					onSuccess( response.connection );
				}
			} else {
				toast.error( __( 'Could not add sender.', 'suremails' ), {
					description:
						response?.message ||
						__( 'Please try again.', 'suremails' ),
					autoDismiss: false,
				} );
			}
		} catch ( error ) {
			toast.error( __( 'Could not add sender.', 'suremails' ), {
				description:
					error?.message || __( 'Please try again.', 'suremails' ),
				autoDismiss: false,
			} );
		} finally {
			setIsSaving( false );
		}
	};

	const renderBack = () => (
		<Button
			variant="outline"
			icon={ <ArrowLeft /> }
			iconPosition="left"
			type="button"
			onClick={ onBack }
		>
			{ __( 'Back', 'suremails' ) }
		</Button>
	);

	if ( domainsLoading ) {
		return (
			<div className="flex items-center justify-center py-10">
				<LoaderIcon className="animate-spin size-5 text-text-secondary" />
			</div>
		);
	}

	if ( domainsError ) {
		return (
			<div className="flex flex-col gap-4">
				<Text size={ 14 } color="secondary">
					{ __(
						'Could not load your sending domains:',
						'suremails'
					) }{ ' ' }
					{ domainsErrorObj?.message ||
						__( 'Unknown error.', 'suremails' ) }
				</Text>
				<Text size={ 13 } color="secondary">
					{ __(
						'If this mentions an invalid API key, your SureContact connection needs reconnecting — reconnect it, then try again.',
						'suremails'
					) }
				</Text>
				{ renderBack() }
			</div>
		);
	}

	if ( domains.length === 0 ) {
		return (
			<div className="flex flex-col gap-4">
				<Text size={ 14 } color="secondary">
					{ __(
						'No verified sending domains were found for your SureContact account. Add and verify a sending domain in SureContact, then come back to send from addresses on that domain.',
						'suremails'
					) }
				</Text>
				<div className="flex items-center gap-3">
					{ renderBack() }
					{ sendingDomainsUrl && (
						<Button
							variant="primary"
							tag="a"
							href={ sendingDomainsUrl }
							target="_blank"
							rel="noopener noreferrer"
							className="no-underline"
						>
							{ __( 'Manage sending domains', 'suremails' ) }
						</Button>
					) }
				</div>
			</div>
		);
	}

	return (
		<div className="space-y-6">
			<div className="space-y-1.5">
				<Label htmlFor="surecontact-from-name">
					{ __( 'From Name', 'suremails' ) }
				</Label>
				<Input
					id="surecontact-from-name"
					size="md"
					value={ fromName }
					onChange={ ( value ) => setFromName( value ) }
					placeholder={ __( 'Support Team', 'suremails' ) }
				/>
			</div>

			<div className="space-y-1.5">
				<Label htmlFor="surecontact-local-part">
					{ __( 'From Email', 'suremails' ) }
				</Label>
				<div className="flex items-start gap-2">
					<div className="flex-1">
						<Input
							id="surecontact-local-part"
							size="md"
							value={ localPart }
							onChange={ ( value ) => {
								setLocalPart( value );
								if ( errors.localPart ) {
									setErrors( ( prev ) => ( {
										...prev,
										localPart: undefined,
									} ) );
								}
							} }
							placeholder={ __( 'info', 'suremails' ) }
						/>
					</div>
					<Text
						size={ 16 }
						color="secondary"
						className="pt-2 leading-none"
					>
						@
					</Text>
					<div className="flex-1">
						<Select
							value={ selectedDomain }
							onChange={ ( value ) => {
								setSelectedDomain( value );
								if ( errors.domain ) {
									setErrors( ( prev ) => ( {
										...prev,
										domain: undefined,
									} ) );
								}
							} }
							className="w-full h-[40px]"
						>
							<Select.Button type="button">
								{ selectedDomain ||
									__( 'Select domain', 'suremails' ) }
							</Select.Button>
							<Select.Options className="text-black bg-background-primary z-999999">
								{ domains.map( ( item ) => (
									<Select.Option
										key={ item.uuid || item.domain }
										value={ item.domain }
										selected={
											item.domain === selectedDomain
										}
									>
										{ item.domain }
									</Select.Option>
								) ) }
							</Select.Options>
						</Select>
					</div>
				</div>
				{ ( errors.localPart || errors.domain ) && (
					<Text as="p" size={ 12 } color="error">
						{ errors.localPart || errors.domain }
					</Text>
				) }
			</div>

			<Divider />

			<div className="flex items-center justify-between gap-3">
				{ renderBack() }
				<Button
					variant="primary"
					loading={ isSaving }
					icon={
						isSaving ? (
							<LoaderIcon className="animate-spin" />
						) : null
					}
					onClick={ handleSubmit }
					type="button"
				>
					{ isSaving
						? __( 'Adding…', 'suremails' )
						: __( 'Add sender', 'suremails' ) }
				</Button>
			</div>
		</div>
	);
};

export default SureContactAddSenderForm;
