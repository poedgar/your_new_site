import { Button } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { SparklesIconSolid } from '@/global/components/icons';
import { isProActive } from '@/functions/nudges';
import { ProUpgradeTooltip } from '@/global/components/nudges';

/**
 * OG Image upgrade nudge.
 *
 * Renders a Pro-styled "Generate Image" trigger button for free users. On hover
 * it surfaces an upgrade tooltip (matching the analyze tab "Fix It for Me"
 * nudge). Returns null when a Pro plan is active, so it sits alongside the
 * `surerank-pro.og-image-trigger-button` filter slot without conflicting.
 *
 * @param {Object} props              Component props.
 * @param {string} props.description  Tooltip body copy describing the feature.
 * @param {Object} props.tooltipProps Extra props forwarded to the tooltip.
 * @return {JSX.Element|null} The upgrade button, or null for Pro users.
 */
const OgImageUpgradeButton = ( {
	description = __(
		'Upgrade to SureRank Pro to generate branded, AI-powered Open Graph images.',
		'surerank'
	),
	tooltipProps,
} ) => {
	if ( isProActive( 'pro' ) ) {
		return null;
	}

	return (
		<ProUpgradeTooltip
			title={ __( 'Generate OG images with Pro', 'surerank' ) }
			description={ description }
			utmContent="og_image_generation"
			tooltipProps={ tooltipProps }
		>
			<Button
				size="xs"
				variant="ghost"
				className="p-0.5 text-icon-interactive outline-brand-200 rounded-sm"
				aria-label={ __( 'Generate Image', 'surerank' ) }
				icon={ <SparklesIconSolid /> }
			/>
		</ProUpgradeTooltip>
	);
};

export default OgImageUpgradeButton;
