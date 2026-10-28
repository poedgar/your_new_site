import { __ } from '@wordpress/i18n';
import { SparklesIconSolid, ReloadIcon } from '@GlobalComponents/icons';
import { useDispatch, useSelect } from '@wordpress/data';
import { Button } from '@bsf/force-ui';
import { STORE_NAME } from '@/store/constants';
import { cn } from '@/functions/utils';
import {
	CONTENT_GENERATION_MAPPING,
	isCombinedMetaField,
} from '@Global/constants';
import { SeoPopupTooltip } from '@AdminComponents/tooltip';
import { isUserContext } from '@SeoPopup/components/page-seo-checks/analyzer/utils/page-builder';

const MagicButton = ( { fieldKey, onUseThis, tooltip } ) => {
	const {
		currentScreen,
		currentTab,
		currentMetaTab,
		currentAccordion,
		generatedContents,
	} = useSelect( ( select ) => {
		const selector = select( STORE_NAME );

		return {
			...selector.getPageSeoChecks(),
			...selector.getAppSettings(),
		};
	}, [] );
	const { updateAppSettings } = useDispatch( STORE_NAME );

	// The generate-content API builds prompts from post/term context and has
	// no user (author archive) support — hide the AI button in user context.
	if ( isUserContext() ) {
		return null;
	}

	// Check if content has been generated for this field
	const hasGeneratedContent =
		generatedContents &&
		generatedContents[ fieldKey ] &&
		generatedContents[ fieldKey ].length > 0;

	const handleClick = () => {
		const updatedGeneratedContents = { ...generatedContents };

		// Combined meta fields share a single response cached across every
		// field. Preserve it when present so opening another field reuses the
		// suggestions with no extra request (regeneration lives inside the
		// screen). Other fields keep the original regenerate-on-click behavior.
		const preserveCache =
			isCombinedMetaField( fieldKey ) && hasGeneratedContent;

		if ( updatedGeneratedContents[ fieldKey ] && ! preserveCache ) {
			delete updatedGeneratedContents[ fieldKey ];
		}

		updateAppSettings( {
			currentScreen: 'fixItForMe',
			previousScreen: currentScreen,
			previousTab: currentTab,
			previousMetaTab: currentMetaTab,
			previousAccordion: currentAccordion,
			selectedFieldKey: fieldKey,
			onUseThis,
			generateContentProcess: 'idle',
			generatedContents: updatedGeneratedContents,
			error: null,
			// When reusing cache, generateContent() will not run to set this,
			// so set the field type here for correct length hints.
			...( preserveCache && {
				currentFieldType: CONTENT_GENERATION_MAPPING[ fieldKey ],
			} ),
		} );
	};

	// Combined meta fields reuse a cached response, so clicking does not
	// regenerate — show the AI (view) icon instead of a reload icon, which
	// would wrongly imply regeneration, and label it "View AI suggestions".
	const isCombinedCached =
		isCombinedMetaField( fieldKey ) && hasGeneratedContent;
	const showReloadIcon = hasGeneratedContent && ! isCombinedCached;
	// Cached state wins over the caller's tooltip; MetaField always passes
	// "Generate with AI", which would otherwise mask the cached "View" label.
	const effectiveTooltip = isCombinedCached
		? __( 'View AI suggestions', 'surerank' )
		: tooltip;

	const button = (
		<Button
			size="xs"
			variant="ghost"
			className={ cn(
				'p-0.5 text-icon-interactive outline-brand-200 rounded-sm',
				hasGeneratedContent && '[&>svg]:size-3 p-1'
			) }
			icon={ showReloadIcon ? <ReloadIcon /> : <SparklesIconSolid /> }
			onClick={ handleClick }
		/>
	);

	if ( effectiveTooltip ) {
		return (
			<SeoPopupTooltip content={ effectiveTooltip } placement="top-end">
				{ button }
			</SeoPopupTooltip>
		);
	}

	return button;
};

export default MagicButton;
