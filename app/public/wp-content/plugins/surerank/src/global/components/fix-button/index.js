import { Button } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { cn } from '@Functions/utils';
import { ProUpgradeTooltip } from '@/global/components/nudges';

const FixButton = ( {
	size = 'xs',
	tooltipProps,
	title = __( 'Fix SEO Issues with AI', 'surerank' ),
	description = (
		<>
			<span>
				{ __(
					'Upgrade to SureRank Pro and let AI help you fix critical SEO issues and warnings, so your website stays fully optimized and ready to perform better in search results.',
					'surerank'
				) }
			</span>
			<br />
		</>
	),
	linkLabel = __( 'Upgrade Now', 'surerank' ),
	iconPosition = 'left',
	icon,
	buttonLabel = __( 'Fix It for Me', 'surerank' ),
	className,
	hidden = true,
	locked = true,
	onClick,
	runBeforeOnClick,
	runAfterOnClick,
	utmContent = 'fix_it_button',
	...props
} ) => {
	const handleOnClick = () => {
		if ( typeof onClick !== 'function' || locked ) {
			return;
		}
		onClick();
	};

	const buttonComponent = (
		<Button
			className={ cn( 'w-fit', hidden && 'hidden', className ) }
			size={ size }
			icon={ icon }
			iconPosition={ iconPosition }
			{ ...props }
			onClick={ handleOnClick }
		>
			{ buttonLabel }
		</Button>
	);

	// If locked is false, render just the button without tooltip
	if ( ! locked ) {
		return buttonComponent;
	}

	// If locked is true (default), render with tooltip
	return (
		<ProUpgradeTooltip
			title={ title }
			description={ description }
			linkLabel={ linkLabel }
			utmContent={ utmContent }
			tooltipProps={ tooltipProps }
		>
			{ buttonComponent }
		</ProUpgradeTooltip>
	);
};

export default FixButton;
