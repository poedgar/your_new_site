import { Accordion, Badge, Text } from '@bsf/force-ui';
import { Star, Check } from 'lucide-react';
import { __ } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';
import { isProActive, redirectToPricingPage } from '@/functions/nudges';
import IconBadge from '@/apps/admin-components/icon-badge';
import UpgradeButton from './upgrade-button';

/**
 * Feature bullets for the Link Suggestion upgrade nudge. Each string carries a
 * `<b>` lead-in that is interpolated into a semibold span at render time so the
 * full sentence stays a single translatable string.
 */
const FEATURES = [
	__( '<b>Contextual matches</b> from your existing posts', 'surerank' ),
	__( '<b>Ready anchor text</b>, picked for relevance', 'surerank' ),
	__( '<b>One-click insert</b> right into the editor', 'surerank' ),
];

/**
 * LinkSuggestionUpgradeNudge
 *
 * Free-side upgrade entry for the SEO popup Analyze tab, styled to match the
 * sibling accordions (Page Checks / Keyword Checks). The expanded content is a
 * Pro feature card (badge, title, subtitle, feature bullets, CTA). Returns null
 * when Pro is active so the real Pro accordion item (injected at the
 * `surerank.analyze.pro_accordions` filter slot) renders without collision.
 *
 * Must be rendered as a direct child of `<Accordion>` with a `value` prop — the
 * Accordion clones direct children that have a `value` prop to inject the
 * `isOpen`/`onToggle`/`type` state, which we forward to the inner `Accordion.Item`.
 *
 * @since x.x.x
 *
 * @param {Object} props Props forwarded by the parent `<Accordion>` (value, isOpen, onToggle, type, …).
 * @return {JSX.Element|null} Accordion entry, or null when Pro is active.
 */
const LinkSuggestionUpgradeNudge = ( props ) => {
	if ( isProActive( 'pro' ) ) {
		return null;
	}

	return (
		<Accordion.Item
			{ ...props }
			className="bg-background-primary overflow-hidden"
		>
			<Accordion.Trigger className="text-base [&>svg]:size-5 pr-2 pl-3 py-3">
				{ __( 'Link Suggestions', 'surerank' ) }
			</Accordion.Trigger>
			<Accordion.Content>
				<div className="pt-3">
					<div className="flex flex-col gap-4 p-4 rounded-xl border-0.5 border-solid border-border-subtle bg-gradient-to-br from-brand-background-50 to-background-primary">
						<div className="flex">
							<Badge
								label={ __( 'Pro', 'surerank' ) }
								variant="blue"
								size="sm"
								type="pill"
								icon={ <Star className="fill-current" /> }
							/>
						</div>
						<div className="flex flex-col gap-1">
							<Text size={ 16 } weight={ 600 } color="primary">
								{ __(
									'AI-powered internal linking',
									'surerank'
								) }
							</Text>
							<Text size={ 14 } weight={ 400 } color="secondary">
								{ __(
									'Let SureRank scan this content and surface the internal links that strengthen your site structure.',
									'surerank'
								) }
							</Text>
						</div>
						<ul className="flex flex-col gap-2 m-0 p-0 list-none">
							{ FEATURES.map( ( feature, index ) => (
								<li
									key={ index }
									className="flex items-center gap-2"
								>
									<IconBadge
										color="green"
										icon={ <Check /> }
									/>
									<Text
										size={ 14 }
										weight={ 400 }
										color="primary"
									>
										{ createInterpolateElement( feature, {
											b: (
												<span className="font-semibold" />
											),
										} ) }
									</Text>
								</li>
							) ) }
						</ul>
						<div className="-mx-4 border-0 border-t border-solid border-border-subtle" />
						<UpgradeButton
							label={ __(
								'Upgrade to SureRank Pro',
								'surerank'
							) }
							size="md"
							className="w-full"
							onClick={ () =>
								redirectToPricingPage( 'link_suggestion' )
							}
						/>
					</div>
				</div>
			</Accordion.Content>
		</Accordion.Item>
	);
};

export default LinkSuggestionUpgradeNudge;
