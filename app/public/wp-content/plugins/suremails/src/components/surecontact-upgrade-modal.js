// @components/surecontact-upgrade-modal.js
import { Dialog, Button, Badge, Text, Loader } from '@bsf/force-ui';
import PropTypes from 'prop-types';
import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { ExternalLink } from 'lucide-react';
import { getSureContactPlans } from '@api/surecontact';

/**
 * Resolve the destination for a purchasable item's CTA. Prefer the item's own
 * `full_checkout_url` (set by the SureContact pricing endpoint); fall back to the
 * generic billing URL already injected into the page.
 *
 * @param {Object} item       Plan or credit pack.
 * @param {string} billingUrl Fallback billing URL.
 * @return {string}           The URL to open, or an empty string.
 */
const resolveCheckoutUrl = ( item, billingUrl ) =>
	item?.full_checkout_url || billingUrl || '';

/**
 * Price block: a small superscript currency, the large amount, and a muted
 * suffix (e.g. "/mo" or "One-time payment"). Sizing mirrors the SureContact
 * pricing dialog: text-4xl amount with a text-sm currency and a self-end suffix.
 *
 * When `maxPrice` is greater than `price`, it's rendered as a struck-through
 * pre-discount price ahead of the current amount.
 *
 * @param {Object} props
 * @param {string} props.currency Currency symbol.
 * @param {string} props.price    Amount.
 * @param {string} props.maxPrice Pre-discount amount; shown struck-through when greater than price.
 * @param {string} props.suffix   Trailing label.
 */
const PriceBlock = ( { currency, price, maxPrice, suffix } ) => {
	const showOriginal =
		maxPrice !== undefined &&
		maxPrice !== null &&
		maxPrice !== '' &&
		parseFloat( maxPrice ) > parseFloat( price );

	return (
		<div className="flex flex-wrap items-start gap-1">
			{ showOriginal ? (
				<span className="flex items-start gap-0.5 mr-1 text-text-tertiary line-through">
					<span className="mt-0.5 text-xs font-normal">
						{ currency }
					</span>
					<span className="text-2xl font-normal leading-none">
						{ maxPrice }
					</span>
				</span>
			) : null }
			<span className="mt-1 text-sm font-semibold text-text-secondary">
				{ currency }
			</span>
			<span className="text-4xl font-bold text-text-primary leading-none">
				{ price }
			</span>
			{ suffix ? (
				<span className="self-end text-base font-medium text-text-tertiary">
					{ suffix }
				</span>
			) : null }
		</div>
	);
};

PriceBlock.propTypes = {
	currency: PropTypes.string,
	price: PropTypes.string,
	maxPrice: PropTypes.string,
	suffix: PropTypes.string,
};

/**
 * A single SMTP plan card. Padding, type scale and button height match the
 * reference PricingCard (p-6, text-xl name, text-4xl price, h-12 CTA).
 *
 * @param {Object} props
 * @param {Object} props.plan       Plan data.
 * @param {string} props.billingUrl Fallback billing URL.
 */
const PlanCard = ( { plan, billingUrl } ) => {
	const url = resolveCheckoutUrl( plan, billingUrl );

	return (
		<div
			className={ `flex flex-col h-full p-6 border-0.5 border-solid rounded-xl ${
				plan.recommended
					? 'border-[#8345DD] ring-1 ring-[#8345DD] bg-gradient-to-b from-background-primary from-70% to-[#F3EAFF]'
					: 'border-border-subtle bg-background-primary'
			}` }
		>
			<div className="flex items-start justify-between gap-2 mb-2">
				<Text as="h3" size={ 20 } weight={ 700 } color="primary">
					{ plan.name }
				</Text>
				{ plan.recommended ? (
					<Badge
						label={ __( 'Recommended', 'suremails' ) }
						size="xs"
						type="pill"
						variant="green"
					/>
				) : null }
			</div>

			<Text as="p" size={ 14 } color="tertiary" className="mb-4">
				{ plan.description }
			</Text>

			<div className="mb-4">
				<PriceBlock
					currency={ plan.currency }
					price={ plan.price }
					maxPrice={ plan.original_price }
					suffix={ plan.price_suffix }
				/>
			</div>

			<div className="flex flex-wrap items-center gap-2 mb-4">
				{ plan.email_count ? (
					<Badge
						label={ plan.email_count }
						size="sm"
						type="pill"
						variant="neutral"
					/>
				) : null }
				{ plan.rate_text ? (
					<Text as="span" size={ 12 } color="tertiary">
						{ plan.rate_text }
					</Text>
				) : null }
			</div>

			<div className="mt-auto">
				<Button
					tag="a"
					href={ url || undefined }
					target="_blank"
					rel="noopener noreferrer"
					variant="ghost"
					size="md"
					disabled={ ! url }
					className={ `flex items-center justify-center w-full h-12 no-underline ${
						plan.recommended
							? 'bg-[#8345DD] hover:bg-[#6937B1] text-white border border-solid border-[#6937B1]'
							: 'bg-background-secondary hover:bg-[#F3F4F6] text-text-primary border border-solid border-[#E5E7EB]'
					}` }
				>
					{ plan.cta_label || __( 'Buy Credits', 'suremails' ) }
				</Button>
			</div>
		</div>
	);
};

PlanCard.propTypes = {
	plan: PropTypes.object.isRequired,
	billingUrl: PropTypes.string,
};

/**
 * A single email-credit pack column inside the promo section. Mirrors one tier
 * of the reference LifetimePricingCard: the first tier sits on a shaded panel,
 * later tiers on white with a left divider — the two-tone split is what visually
 * separates the tiers.
 *
 * @param {Object}  props
 * @param {Object}  props.item        Credit pack data.
 * @param {string}  props.billingUrl  Fallback billing URL.
 * @param {boolean} props.shaded      Use the shaded (gray) background for this tier.
 * @param {boolean} props.dividerLeft Render a left divider (for tiers after the first).
 */
const CreditCard = ( { item, billingUrl, shaded, dividerLeft } ) => {
	const url = resolveCheckoutUrl( item, billingUrl );

	return (
		<div
			className={ `flex flex-col flex-1 p-6 sm:p-8 ${
				shaded ? 'bg-background-secondary' : 'bg-background-primary'
			} ${
				dividerLeft
					? 'md:border-l md:border-solid md:border-border-subtle'
					: ''
			}` }
		>
			<Text as="h3" size={ 20 } weight={ 700 } color="primary">
				{ item.name }
			</Text>
			<Text as="p" size={ 14 } color="tertiary" className="mt-1 mb-4">
				{ item.description }
			</Text>

			<div className="mb-1">
				<PriceBlock
					currency={ item.currency }
					price={ item.price }
					maxPrice={ item.original_price }
					suffix={ item.price_suffix }
				/>
			</div>

			{ item.rate_text ? (
				<Text as="p" size={ 12 } color="tertiary">
					{ item.rate_text }
				</Text>
			) : null }

			{ item.note ? (
				<Text as="p" size={ 12 } color="tertiary" className="mt-4 mb-5">
					{ item.note }
				</Text>
			) : null }

			<div className="mt-auto">
				<Button
					tag="a"
					href={ url || undefined }
					target="_blank"
					rel="noopener noreferrer"
					variant="ghost"
					size="md"
					disabled={ ! url }
					className={ `flex items-center justify-center w-full h-12 no-underline ${
						item.highlighted
							? 'bg-[#111827] hover:bg-[#1F2937] text-white border-0'
							: 'bg-background-primary hover:bg-[#F9FAFB] text-text-primary border border-solid border-[#1F2937]'
					}` }
				>
					{ item.cta_label || __( 'Buy Credits', 'suremails' ) }
				</Button>
			</div>
		</div>
	);
};

CreditCard.propTypes = {
	item: PropTypes.object.isRequired,
	billingUrl: PropTypes.string,
	shaded: PropTypes.bool,
	dividerLeft: PropTypes.bool,
};

/**
 * Upgrade modal for SureContact SMTP. Dimensions follow the SureContact pricing
 * dialog (max-w-7xl, max-h-90vh, 24px padding, scrollable body) but rendered
 * with force-ui components. Presents the SMTP subscription plans and, below an
 * "OR" divider, the one-time email-credit packs. Triggered from the dashboard
 * meter (Upgrade button + critical-state link).
 *
 * @param {Object}   props
 * @param {boolean}  props.open           Whether the modal is visible.
 * @param {Function} props.setOpen        Setter to toggle visibility.
 * @param {string}   [props.connectionId] Active SureContact connection (passed to the API).
 */
const SureContactUpgradeModal = ( { open, setOpen, connectionId } ) => {
	const billingUrl = window?.suremails?.surecontactBillingUrl || '';
	const paymentLogosUrl = window?.suremails?.paymentLogosUrl || '';

	const { data, isLoading, isError } = useQuery( {
		queryKey: [ 'surecontact-plans', connectionId ],
		queryFn: () => getSureContactPlans( connectionId ),
		enabled: open,
		staleTime: Infinity,
		refetchOnWindowFocus: false,
	} );

	const header = data?.header || {};
	const plans = data?.plans || [];
	const credits = data?.credits || null;

	const closeModal = () => setOpen( false );

	return (
		<Dialog
			design="simple"
			exitOnEsc
			scrollLock
			setOpen={ setOpen }
			open={ open }
		>
			<Dialog.Backdrop />
			<Dialog.Panel className="max-w-7xl w-full max-h-[90vh] my-0 flex flex-col gap-0">
				<Dialog.Header className="shrink-0 px-6 pt-6 pb-4">
					<div className="flex items-start justify-between gap-4">
						<Dialog.Title className="text-2xl font-bold">
							{ ! isLoading
								? header.title ||
								  __(
										'Email Delivery Built for You and Pay for What You Send.',
										'suremails'
								  )
								: null }
						</Dialog.Title>
						<Dialog.CloseButton onClick={ closeModal } />
					</div>
					{ ! isLoading ? (
						<Dialog.Description className="text-base mt-1">
							{ header.subtitle ||
								__(
									'No IP warming, no separate billing. Pick a monthly SMTP plan or buy non-expiring credits and start sending emails today.',
									'suremails'
								) }{ ' ' }
							{ header.features_url ? (
								<a
									href={ header.features_url }
									target="_blank"
									rel="noopener noreferrer"
									className="inline-flex items-center gap-1 underline text-text-secondary hover:text-text-primary"
								>
									{ __( 'Explore Features', 'suremails' ) }
									<ExternalLink className="size-3" />
								</a>
							) : null }
						</Dialog.Description>
					) : null }
				</Dialog.Header>

				<Dialog.Body className="overflow-y-auto max-h-[calc(90vh-180px)] px-6 pt-6 pb-6">
					{ isLoading ? (
						<div className="flex items-center justify-center py-16">
							<Loader />
						</div>
					) : null }

					{ ! isLoading && isError ? (
						<div className="flex items-center justify-center py-16">
							<Text as="p" size={ 14 } color="tertiary">
								{ __(
									'Unable to load plans right now. Please try again later.',
									'suremails'
								) }
							</Text>
						</div>
					) : null }

					{ ! isLoading && ! isError ? (
						<div className="space-y-6">
							<div className="grid grid-cols-1 md:grid-cols-3 gap-4">
								{ plans.map( ( plan ) => (
									<PlanCard
										key={ plan.id }
										plan={ plan }
										billingUrl={ billingUrl }
									/>
								) ) }
							</div>

							{ credits &&
							Array.isArray( credits.items ) &&
							credits.items.length > 0 ? (
								<div>
									<div className="flex items-center gap-4 mb-6">
										<span className="flex-1 h-px bg-border-subtle" />
										<Text
											as="span"
											size={ 12 }
											weight={ 500 }
											color="tertiary"
										>
											{ __( 'OR', 'suremails' ) }
										</Text>
										<span className="flex-1 h-px bg-border-subtle" />
									</div>

									<div className="w-full max-w-4xl mx-auto border-2 border-solid border-[#8345DD] rounded-xl overflow-hidden">
										{ credits.banner ? (
											<div className="px-6 py-3 bg-[#EA580C] text-center">
												<Text
													as="p"
													size={ 16 }
													weight={ 600 }
													className="text-white"
												>
													{ credits.banner }
												</Text>
											</div>
										) : null }
										<div className="flex flex-col md:flex-row">
											{ credits.items.map(
												( item, index ) => (
													<CreditCard
														key={ item.id }
														item={ item }
														billingUrl={
															billingUrl
														}
														shaded={ index === 0 }
														dividerLeft={
															index > 0
														}
													/>
												)
											) }
										</div>
									</div>
								</div>
							) : null }

							{ paymentLogosUrl ? (
								<div className="flex items-center justify-center pt-2">
									<img
										src={ paymentLogosUrl }
										alt={ __(
											'We accept PayPal, Stripe, and Visa',
											'suremails'
										) }
										className="h-7 w-auto"
									/>
								</div>
							) : null }
						</div>
					) : null }
				</Dialog.Body>
			</Dialog.Panel>
		</Dialog>
	);
};

SureContactUpgradeModal.propTypes = {
	open: PropTypes.bool.isRequired,
	setOpen: PropTypes.func.isRequired,
	connectionId: PropTypes.oneOfType( [ PropTypes.string, PropTypes.number ] ),
};

export default SureContactUpgradeModal;
