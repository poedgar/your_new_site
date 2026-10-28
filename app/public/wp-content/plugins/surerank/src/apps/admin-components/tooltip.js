import { cn } from '@/functions/utils';
import { Tooltip as TooltipComponent } from '@bsf/force-ui';
import { Info } from 'lucide-react';
import { getPortalRoot } from '@AdminComponents/portal-root';

const Tooltip = ( props ) => {
	if ( ! props.content && ! props.title ) {
		return props.children;
	}
	const portalRoot = getPortalRoot();
	return (
		<TooltipComponent
			{ ...props }
			tooltipPortalRoot={ portalRoot }
			boundary={ portalRoot || 'clippingAncestors' }
		/>
	);
};

const InfoTooltip = ( { content, ...rest } ) => {
	return (
		<Tooltip
			content={ content }
			placement="top"
			arrow
			className={ cn( 'max-w-95 z-[99999]', rest?.className ) }
			{ ...rest }
		>
			<Info className="size-4 text-icon-secondary" />
		</Tooltip>
	);
};

const SeoPopupTooltip = ( props ) => {
	if ( ! props.content && ! props.title ) {
		return props.children;
	}
	// Query the modal container WITHIN the portal root: inside the shadow DOM it
	// is not reachable from the light-DOM `document`.
	const portalRoot = getPortalRoot();
	const boundary =
		portalRoot?.querySelector?.( '#surerank-seo-popup-modal-container' ) ||
		portalRoot ||
		'clippingAncestors';
	return (
		<TooltipComponent
			{ ...props }
			className={ cn( 'z-[99999] max-w-95', props.className ) }
			tooltipPortalRoot={ portalRoot }
			boundary={ boundary }
		/>
	);
};

export const SeoPopupInfoTooltip = ( { content, className, ...rest } ) => {
	return (
		<SeoPopupTooltip
			className={ cn( 'z-[99999] max-w-95', className ) }
			content={ content }
			placement="top"
			arrow
			{ ...rest }
		>
			<Info className="shrink-0 size-4 !text-icon-secondary" />
		</SeoPopupTooltip>
	);
};

export { Tooltip, InfoTooltip, SeoPopupTooltip };
