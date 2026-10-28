import { SeoPopupTooltip } from '@/apps/admin-components/tooltip';
import { Text, Button } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { redirectToPricingPage } from '@/functions/nudges';

/**
 * Shared "Upgrade to Pro" tooltip.
 *
 * Presentational wrapper that renders the standard upgrade tooltip (title,
 * description, and an "Upgrade Now" link) around an arbitrary trigger element.
 * Callers supply their own trigger via `children` and keep ownership of their
 * own gating/behavior — this component only owns the tooltip presentation and
 * the pricing-page redirect.
 *
 * @param {Object}      props              Component props.
 * @param {string}      props.title        Tooltip heading.
 * @param {string}      props.description  Tooltip body copy.
 * @param {string}      props.linkLabel    Label for the upgrade link.
 * @param {string}      props.utmContent   UTM content passed to the pricing redirect.
 * @param {Object}      props.tooltipProps Extra props forwarded to the tooltip.
 * @param {JSX.Element} props.children     The trigger element the tooltip wraps.
 * @return {JSX.Element} The trigger wrapped in the upgrade tooltip.
 */
const ProUpgradeTooltip = ( {
	title,
	description,
	linkLabel = __( 'Upgrade Now', 'surerank' ),
	utmContent,
	tooltipProps,
	children,
} ) => (
	<SeoPopupTooltip
		arrow
		interactive
		placement="top-end"
		{ ...tooltipProps }
		content={
			<div className="space-y-1">
				<Text size={ 12 } weight={ 600 } color="inverse">
					{ title }
				</Text>
				<Text
					size={ 12 }
					weight={ 400 }
					color="inverse"
					className="leading-relaxed"
				>
					{ description }
				</Text>
				<div className="mt-1.5">
					<Button
						size="xs"
						variant="link"
						className="[&>span]:px-0 no-underline hover:no-underline focus:[box-shadow:none] text-link-visited-inverse hover:text-link-visited-inverse-hover"
						onClick={ () => redirectToPricingPage( utmContent ) }
					>
						{ linkLabel }
					</Button>
				</div>
			</div>
		}
	>
		{ children }
	</SeoPopupTooltip>
);

export default ProUpgradeTooltip;
