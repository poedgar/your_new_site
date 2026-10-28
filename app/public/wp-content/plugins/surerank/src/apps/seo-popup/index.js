// Register SureRank's apiFetch middleware before any apiFetch call so
// metabox saves automatically fall back to admin-ajax.php when a
// security plugin or WAF blocks /wp-json/. See #2362.
import '@Functions/api-fetch-middleware';

import { Skeleton } from '@bsf/force-ui';
import Modal from '@SeoPopup/modal';
import RegisterMenu from './register-menu';
import { registerPlugin } from '@wordpress/plugins';
import { select, useDispatch } from '@wordpress/data';
import { STORE_NAME } from '@Store/constants';
import { SureRankMonoLogo } from '@GlobalComponents/icons';
import { createRoot, useEffect } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import PageCheckStatusIndicator from '@AdminComponents/page-check-status-indicator';
import usePageCheckStatus from './hooks/usePageCheckStatus';
import EditorTour from '@SeoPopup/components/editor-tour/editor-tour';
import { cn } from '@Functions/utils';

import '@Store/store';
import './style.scss';

if ( select( 'core/editor' ) ) {
	// If Gutenberg editor, then only.
	registerPlugin( 'surerank-page-level-settings', { render: RegisterMenu } );
}

const RenderTriggerPopupButton = () => {
	const { updateModalState } = useDispatch( STORE_NAME );

	// Get page checks status for indicator
	const { status, initializing, counts } = usePageCheckStatus();
	const isSidebarVariant =
		document
			.querySelector( '#surerank-classic-seo-popup-trigger' )
			?.getAttribute( 'data-surerank-variant' ) === 'sidebar';

	const getButtonText = () => {
		return __( 'Optimize Here', 'surerank' );
	};

	useEffect( () => {
		const adminBar = document.querySelector( '#wpadminbar' );
		if ( adminBar ) {
			adminBar.style.zIndex = '10';
		}
	}, [] );

	const totalIssues = counts.error + counts.warning;

	// Severity hint: red when any errors, amber for warnings only, green when clean.
	let statusDotClass = 'bg-support-success';
	if ( counts.error > 0 ) {
		statusDotClass = 'bg-support-error';
	} else if ( counts.warning > 0 ) {
		statusDotClass = 'bg-support-warning';
	}

	const statusLabel =
		totalIssues > 0
			? sprintf(
					/* translators: %d: number of SEO improvements available. */
					_n(
						'%d SEO issue found.',
						'%d SEO issues found.',
						totalIssues,
						'surerank'
					),
					totalIssues
			  )
			: __(
					'Your SEO looks good. You are ready to publish.',
					'surerank'
			  );

	if ( isSidebarVariant ) {
		return (
			<div className="flex flex-col items-start gap-2.5">
				{ initializing && (
					<Skeleton
						variant="rectangular"
						className="w-20 h-3 rounded-sm"
					/>
				) }
				{ ! initializing && (
					<p className="surerank-classic-sidebar-status m-0 flex items-start gap-2 text-xs leading-5 text-text-secondary">
						<span
							className={ cn(
								'mt-[7px] size-1.5 shrink-0 rounded-full',
								statusDotClass
							) }
						/>
						<span>{ statusLabel }</span>
					</p>
				) }
				<div className="surerank-classic-sidebar-trigger-wrap">
					<button
						className="button button-primary"
						type="button"
						onClick={ () => updateModalState( true ) }
					>
						{ getButtonText() }
					</button>
					<EditorTour />
				</div>
			</div>
		);
	}

	return (
		<div className="relative inline-flex">
			<button
				className="inline-flex w-auto h-auto p-1 rounded-full border-0 bg-transparent focus:outline-none outline-none cursor-pointer"
				type="button"
				onClick={ () => updateModalState( true ) }
			>
				<SureRankMonoLogo className="size-6" />
			</button>
			<PageCheckStatusIndicator
				className="z-auto"
				status={ status }
				errorAndWarnings={ counts.errorAndWarnings }
				initializing={ initializing }
			/>
			<EditorTour />
		</div>
	);
};

const getClassicTriggerMountTarget = () => {
	const sidebarTrigger = document.querySelector(
		'#surerank-classic-seo-popup-trigger'
	);
	if ( sidebarTrigger ) {
		return sidebarTrigger;
	}

	// Term/user edit pages: #seo-popup is PHP-rendered inside <form>, so move it
	// into .wrap > h1 to appear beside the page heading (matches old insertRoot behaviour).
	const seoPopup = document.querySelector( '#seo-popup' );
	const pageHeading = document.querySelector( '.wrap > h1' );
	if ( seoPopup && pageHeading ) {
		pageHeading.appendChild( seoPopup );
	}
	return seoPopup;
};

const mountClassicTrigger = () => {
	if ( ! [ 'classic', 'user' ].includes( surerank_seo_popup.editor_type ) ) {
		return;
	}
	const targetElement = getClassicTriggerMountTarget();
	if ( ! targetElement ) {
		return;
	}
	const root = createRoot( targetElement );
	root.render( <RenderTriggerPopupButton /> );
};

// Metabox markup is server-rendered in the body, but this script may load in
// the head. Defer mount until the DOM has parsed the metabox container.
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountClassicTrigger );
} else {
	mountClassicTrigger();
}

document.addEventListener( 'DOMContentLoaded', function () {
	// On frontend single views the shadow-DOM entry (front-end-meta-box) owns
	// the mount. Returning here prevents a duplicate root in the light DOM.
	if ( window.surerank_seo_popup?.is_frontend ) {
		return;
	}

	let node = document.querySelector( '#surerank-root' );

	if ( ! node ) {
		node = document.body.appendChild( document.createElement( 'div' ) );
		node.id = 'surerank-root';
		node.className = 'surerank-root';
	}

	setTimeout( function () {
		const root = createRoot( node );
		root.render( <Modal /> );
	}, 1000 );
} );
