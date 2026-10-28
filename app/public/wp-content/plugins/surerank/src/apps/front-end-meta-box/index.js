// Register SureRank's apiFetch middleware before any apiFetch call so
// metabox saves automatically fall back to admin-ajax.php when a
// security plugin or WAF blocks /wp-json/. Previously provided by the
// seo-popup bundle, which no longer loads on the frontend (its wp-editor
// dependency broke WooCommerce block hydration). See #2362.
import '@Functions/api-fetch-middleware';

import { createRoot } from '@wordpress/element';
import { dispatch, select } from '@wordpress/data';
import { STORE_NAME } from '@Store/constants';
// Register the SureRank data store. Previously registered as a side effect of
// the seo-popup bundle, which no longer loads on the frontend.
import '@Store/store';
import domReady from '@wordpress/dom-ready';
import { handleRefreshWithBrokenLinks } from '@/apps/elementor/page-checks';
import Modal from '@SeoPopup/modal';
import './style.scss';

domReady( () => {
	// Server-side gate denied enqueue — nothing to initialize.
	if ( typeof window.surerank_seo_popup === 'undefined' ) {
		return;
	}

	// Attach a shadow root so theme stylesheets cannot bleed into the metabox UI.
	const shadowHost = document.createElement( 'div' );
	shadowHost.id = 'surerank-shadow-host';
	// z-index needs a positioned element to take effect; keeps the modal above
	// theme headers/admin bars with inflated stacking contexts.
	shadowHost.style.position = 'relative';
	shadowHost.style.zIndex = '9999999';
	document.body.appendChild( shadowHost );

	const shadowRoot = shadowHost.attachShadow( { mode: 'open' } );

	// Cloned <link> nodes share the browser cache entry — no extra network requests.
	document
		.querySelectorAll( 'link[rel="stylesheet"][id^="surerank-"]' )
		.forEach( ( link ) => shadowRoot.appendChild( link.cloneNode() ) );

	const mountNode = document.createElement( 'div' );
	mountNode.id = 'surerank-root';
	mountNode.className = 'surerank-root';
	shadowRoot.appendChild( mountNode );

	// Publish the shadow mount node so floating-ui portals (tooltips etc.) can
	// render inside the shadow root. Lookups like getElementById('surerank-root')
	// cannot cross the shadow boundary, so portals must receive this element by
	// reference — otherwise floating-ui creates a stray, theme-styled node in
	// the light-DOM <body>. See getPortalRoot() in admin-components/portal-root.
	window.surerankPortalRoot = mountNode;

	createRoot( mountNode ).render( <Modal /> );

	const { getMetaboxState, getPageSeoChecks } = select( STORE_NAME );

	if ( getPageSeoChecks()?.initializing ) {
		dispatch( STORE_NAME ).setPageSeoCheck( 'initializing', false );
	}

	let attempts = 0;
	const maxAttempts = 20;
	const intervalTime = 3000;

	const intervalId = setInterval( () => {
		attempts++;
		const initialized = getMetaboxState();

		if ( initialized ) {
			handleRefreshWithBrokenLinks();
			clearInterval( intervalId );
		} else if ( attempts >= maxAttempts ) {
			clearInterval( intervalId );
		}
	}, intervalTime );

	// Admin bar lives in light DOM (outside shadow root) — click handler on document.
	document.addEventListener( 'click', ( event ) => {
		const link = event.target.closest( 'a' );
		// Only href="#" trigger items open the metabox; real links navigate.
		if (
			link &&
			link.closest( '#wp-admin-bar-surerank-meta-box' ) &&
			'#' === link.getAttribute( 'href' )
		) {
			event.preventDefault();
			dispatch( STORE_NAME ).updateModalState( true );
		}
	} );
} );
