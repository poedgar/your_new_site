import { __ } from '@wordpress/i18n';
import { select, subscribe } from '@wordpress/data';
import { cn, getStatusIndicatorClasses } from '@/functions/utils';
import { getTooltipText } from '@/apps/seo-popup/utils/page-checks-status-tooltip-text';
import { ENABLE_PAGE_LEVEL_SEO } from '@/global/constants';
import {
	sureRankLogoForBuilder,
	handleOpenSureRankDrawer,
} from '@SeoPopup/utils/page-builder-functions';
import { STORE_NAME } from '@/store/constants';
import {
	getPageCheckStatus,
	handleRefreshWithBrokenLinks,
} from '../elementor/page-checks';
import { startEditorTour } from '@SeoPopup/components/editor-tour/start-tour';
import { mountBricksToolbar } from './toolbar';

const updateStatusIndicator = ( button ) => {
	// Reading status may dispatch an initialization update. Resolve it before
	// replacing the dot so a nested subscription cannot leave duplicate dots.
	const { status, counts } = getPageCheckStatus();
	button.querySelector( '.surerank-status-indicator' )?.remove();
	button.setAttribute( 'data-balloon', getTooltipText( counts ) );

	if ( status && ENABLE_PAGE_LEVEL_SEO ) {
		const indicator = document.createElement( 'div' );
		indicator.className = cn(
			'surerank-status-indicator absolute top-1.5 right-2.5 size-1.5 rounded-full z-10 duration-200',
			getStatusIndicatorClasses( status )
		);
		button.appendChild( indicator );
	}
};

const createButton = () => {
	const button = document.createElement( 'li' );
	button.id = 'surerank-bricks-toolbar-button';
	button.className = 'surerank-root';
	// SureRank utilities target descendants of .surerank-root, not the root
	// itself. Anchor the dot here without depending on Bricks' group styles.
	button.style.position = 'relative';
	button.style.flexShrink = '0';
	button.setAttribute( 'role', 'button' );
	button.setAttribute( 'aria-label', __( 'Open SureRank SEO', 'surerank' ) );
	button.setAttribute( 'data-balloon-pos', 'bottom' );
	button.tabIndex = 0;

	const logo = document.createElement( 'span' );
	logo.className = 'bricks-svg-wrapper';
	logo.innerHTML = sureRankLogoForBuilder( 'surerank' );
	button.appendChild( logo );

	button.addEventListener( 'click', ( event ) => {
		event.stopPropagation();
		handleOpenSureRankDrawer();
	} );
	button.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Enter' || event.key === ' ' ) {
			event.preventDefault();
			event.stopPropagation();
			handleOpenSureRankDrawer();
		}
	} );
	return button;
};

let stopIntegration;

const initializeBricksIntegration = () => {
	if ( stopIntegration ) {
		return;
	}
	let unsubscribeStatus;
	let unsubscribeRefresh;

	const setupBricksButton = ( button ) => {
		updateStatusIndicator( button );

		// Reconnecting the same trigger does not restart the tour or subscriptions.
		startEditorTour( {
			trigger: button,
			getStatusEl: () =>
				button.querySelector( '.surerank-status-indicator' ),
		} );
		unsubscribeStatus = subscribe( () => updateStatusIndicator( button ) );

		const refreshWhenReady = () => {
			const postId =
				select( STORE_NAME )?.getVariables()?.post?.ID?.value;
			if ( postId ) {
				// Refresh dispatches store updates synchronously. Unsubscribe first.
				unsubscribeRefresh?.();
				unsubscribeRefresh = null;
				handleRefreshWithBrokenLinks();
			}
		};
		unsubscribeRefresh = subscribe( refreshWhenReady );
		refreshWhenReady();
	};

	const stopToolbar = mountBricksToolbar( {
		isReady: () =>
			typeof select( STORE_NAME )?.getPageSeoChecks === 'function',
		createButton,
		onMount: setupBricksButton,
	} );
	stopIntegration = () => {
		stopToolbar();
		unsubscribeStatus?.();
		unsubscribeRefresh?.();
		stopIntegration = null;
	};
	window.addEventListener( 'pagehide', stopIntegration, { once: true } );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener(
		'DOMContentLoaded',
		initializeBricksIntegration,
		{
			once: true,
		}
	);
} else {
	initializeBricksIntegration();
}

// A builder restored from the browser's back/forward cache needs its listeners again.
window.addEventListener( 'pageshow', ( event ) => {
	if ( event.persisted ) {
		initializeBricksIntegration();
	}
} );
