import { __ } from '@wordpress/i18n';
import { Button, Text } from '@bsf/force-ui';
import { Tooltip } from '@AdminComponents/tooltip';
import { getPricingLink, redirectToPricingPage } from '@Functions/nudges';

// UTM content tag so upgrades started from a failed bulk row are attributable.
const UPGRADE_UTM_CONTENT = 'bulk_generation_limit';

/**
 * Explain why a bulk generation row failed, on hover.
 *
 * The upgrade link only appears when the server marked the failure as one an
 * upgrade resolves, so this component never inspects error codes or message text.
 *
 * @param {Object}  props                 Component props.
 * @param {string}  props.message         Failure message from the batch status.
 * @param {boolean} props.upgradeRequired Whether an upgrade resolves this failure.
 * @param {Object}  props.children        Element the tooltip is anchored to.
 * @return {JSX.Element} The wrapped children.
 */
const BatchFailureTooltip = ( { message, upgradeRequired, children } ) => {
	if ( ! message ) {
		return children;
	}

	// Only offer the upgrade path when there is somewhere to send them; without a
	// pricing link redirectToPricingPage would open an empty tab.
	const showUpgrade = !! upgradeRequired && !! getPricingLink();

	return (
		<Tooltip
			variant="dark"
			placement="top"
			arrow
			triggers={ [ 'hover', 'focus' ] }
			interactive={ showUpgrade }
			className="max-w-80 z-[99999]"
			content={
				<div className="space-y-1">
					<Text
						size={ 12 }
						weight={ 400 }
						color="inverse"
						className="leading-relaxed"
					>
						{ message }
					</Text>
					{ showUpgrade && (
						<div className="mt-1.5">
							<Button
								size="xs"
								variant="link"
								onClick={ () =>
									redirectToPricingPage( UPGRADE_UTM_CONTENT )
								}
								className="[&>span]:px-0 no-underline hover:no-underline focus:[box-shadow:none] text-link-visited-inverse hover:text-link-visited-inverse-hover"
							>
								{ __( 'Upgrade to Premium', 'surerank' ) }
							</Button>
						</div>
					) }
				</div>
			}
		>
			{ children }
		</Tooltip>
	);
};

export default BatchFailureTooltip;
