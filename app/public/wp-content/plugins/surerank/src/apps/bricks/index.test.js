/* global it, expect, jest, beforeAll, beforeEach, afterEach */

let mockStore;
let mockPageSeoEnabled = true;
let mockPageStatus;
let mockStatusDispatch = false;
const mockSubscribers = new Set();
const mockPublish = () => {
	[ ...mockSubscribers ].forEach( ( callback ) => {
		if ( mockSubscribers.has( callback ) ) {
			callback();
		}
	} );
};

jest.mock(
	'@wordpress/data',
	() => ( {
		select: () => mockStore,
		subscribe: ( callback ) => {
			mockSubscribers.add( callback );
			return () => mockSubscribers.delete( callback );
		},
	} ),
	{ virtual: true }
);
jest.mock( '@wordpress/i18n', () => ( { __: ( text ) => text } ), {
	virtual: true,
} );
jest.mock(
	'@/functions/utils',
	() => ( {
		cn: ( ...classes ) => classes.join( ' ' ),
		getStatusIndicatorClasses: ( status ) => `status-${ status }`,
	} ),
	{ virtual: true }
);
jest.mock(
	'@/apps/seo-popup/utils/page-checks-status-tooltip-text',
	() => ( {
		getTooltipText: ( counts ) => `Issues: ${ counts.error }`,
	} ),
	{ virtual: true }
);
jest.mock(
	'@/global/constants',
	() => ( {
		get ENABLE_PAGE_LEVEL_SEO() {
			return mockPageSeoEnabled;
		},
	} ),
	{ virtual: true }
);
jest.mock( '@/store/constants', () => ( { STORE_NAME: 'surerank' } ), {
	virtual: true,
} );
jest.mock(
	'@SeoPopup/utils/page-builder-functions',
	() => ( {
		sureRankLogoForBuilder: () => '<svg aria-hidden="true"></svg>',
		handleOpenSureRankDrawer: jest.fn(),
	} ),
	{ virtual: true }
);
jest.mock( '../elementor/page-checks', () => ( {
	getPageCheckStatus: () => {
		if ( mockStatusDispatch ) {
			mockStatusDispatch = false;
			mockPublish();
		}
		return mockPageStatus;
	},
	handleRefreshWithBrokenLinks: jest.fn( () => mockPublish() ),
} ) );
jest.mock(
	'@SeoPopup/components/editor-tour/start-tour',
	() => ( {
		startEditorTour: jest.fn(),
	} ),
	{ virtual: true }
);

const {
	handleOpenSureRankDrawer,
} = require( '@SeoPopup/utils/page-builder-functions' );
const {
	handleRefreshWithBrokenLinks,
} = require( '../elementor/page-checks' );
const {
	startEditorTour,
} = require( '@SeoPopup/components/editor-tour/start-tour' );
const flushMutations = async () => {
	await Promise.resolve();
	await Promise.resolve();
};

beforeAll( () => {
	Object.defineProperty( document, 'readyState', {
		value: 'complete',
		configurable: true,
	} );
	require( './index' );
	window.dispatchEvent( new Event( 'pagehide' ) );
} );

beforeEach( () => {
	jest.clearAllMocks();
	mockPageSeoEnabled = true;
	mockStatusDispatch = false;
	mockPageStatus = { status: 'success', counts: { error: 0 } };
	mockStore = {
		getPageSeoChecks: () => ( {} ),
		getVariables: () => ( { post: { ID: { value: 123 } } } ),
	};
	document.body.innerHTML =
		'<div id="bricks-toolbar-top" class="bricks-toolbar toolbar-top"><ul class="end"></ul></div>';
} );

afterEach( () => {
	window.dispatchEvent( new Event( 'pagehide' ) );
	expect( mockSubscribers.size ).toBe( 0 );
} );

const start = () => {
	window.dispatchEvent(
		new window.PageTransitionEvent( 'pageshow', { persisted: true } )
	);
	return document.getElementById( 'surerank-bricks-toolbar-button' );
};

it( 'initializes immediately after DOM ready and refreshes an already available post once', () => {
	const button = start();
	expect( button.getAttribute( 'role' ) ).toBe( 'button' );
	expect( button.getAttribute( 'aria-label' ) ).toBe( 'Open SureRank SEO' );
	expect( button.style.position ).toBe( 'relative' );
	expect( handleRefreshWithBrokenLinks ).toHaveBeenCalledTimes( 1 );
	expect( mockSubscribers.size ).toBe( 1 );
	mockPublish();
	expect( handleRefreshWithBrokenLinks ).toHaveBeenCalledTimes( 1 );
} );

it( 'waits for post variables and unsubscribes before synchronous refresh dispatch', () => {
	mockStore.getVariables = () => ( {} );
	start();
	expect( handleRefreshWithBrokenLinks ).not.toHaveBeenCalled();
	expect( mockSubscribers.size ).toBe( 2 );
	mockStore.getVariables = () => ( { post: { ID: { value: 456 } } } );
	mockPublish();
	expect( handleRefreshWithBrokenLinks ).toHaveBeenCalledTimes( 1 );
	expect( mockSubscribers.size ).toBe( 1 );
} );

it( 'updates a single status dot and safely assigns tooltip text', () => {
	const button = start();
	mockPageStatus = { status: 'error', counts: { error: 3 } };
	mockPublish();
	mockPublish();
	expect(
		button.querySelectorAll( '.surerank-status-indicator' )
	).toHaveLength( 1 );
	expect( button.querySelector( '.status-error' ) ).not.toBeNull();
	expect( button.getAttribute( 'data-balloon' ) ).toBe( 'Issues: 3' );
} );

it( 'keeps one dot when reading check status dispatches a store update', () => {
	const button = start();
	mockStatusDispatch = true;
	mockPublish();
	expect(
		button.querySelectorAll( '.surerank-status-indicator' )
	).toHaveLength( 1 );
} );

it.each( [ null, 'success' ] )(
	'does not show a status dot with page-level SEO disabled (%s)',
	( status ) => {
		mockPageSeoEnabled = false;
		mockPageStatus.status = status;
		const button = start();
		expect(
			button.querySelector( '.surerank-status-indicator' )
		).toBeNull();
	}
);

it( 'keeps the trigger without a status while checks initialize', () => {
	mockPageStatus.status = null;
	const button = start();
	expect( button ).not.toBeNull();
	expect( button.querySelector( '.surerank-status-indicator' ) ).toBeNull();
	mockPageStatus.status = 'warning';
	mockPublish();
	expect( button.querySelector( '.status-warning' ) ).not.toBeNull();
} );

it( 'opens the shared drawer from the icon, badge, Enter and Space without bubbling to Bricks', () => {
	const button = start();
	const toolbarClick = jest.fn();
	button.parentElement.addEventListener( 'click', toolbarClick );
	button
		.querySelector( 'svg' )
		.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
	button
		.querySelector( '.surerank-status-indicator' )
		.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
	[ 'Enter', ' ' ].forEach( ( key ) => {
		const event = new window.KeyboardEvent( 'keydown', {
			key,
			bubbles: true,
			cancelable: true,
		} );
		button.dispatchEvent( event );
		expect( event.defaultPrevented ).toBe( true );
	} );
	button.dispatchEvent(
		new window.KeyboardEvent( 'keydown', { key: 'Tab' } )
	);
	expect( handleOpenSureRankDrawer ).toHaveBeenCalledTimes( 4 );
	expect( toolbarClick ).not.toHaveBeenCalled();
} );

it( 'reconnects without duplicate subscriptions, refreshes or tours and retains live status', async () => {
	const button = start();
	document.body.innerHTML =
		'<div class="bricks-toolbar toolbar-left"><ul class="end"></ul></div>';
	await flushMutations();
	expect( document.getElementById( button.id ) ).toBe( button );
	expect( mockSubscribers.size ).toBe( 1 );
	expect( handleRefreshWithBrokenLinks ).toHaveBeenCalledTimes( 1 );
	expect( startEditorTour ).toHaveBeenCalledTimes( 1 );
	mockPageStatus = { status: 'warning', counts: { error: 0 } };
	mockPublish();
	expect( button.querySelector( '.status-warning' ) ).not.toBeNull();
} );

it( 'removes owned resources on pagehide and restores the integration on cached navigation', () => {
	const button = start();
	window.dispatchEvent( new Event( 'pagehide' ) );
	expect( button.isConnected ).toBe( false );
	expect( mockSubscribers.size ).toBe( 0 );
	const restored = start();
	expect( restored.isConnected ).toBe( true );
	expect( restored ).not.toBe( button );
	expect( mockSubscribers.size ).toBe( 1 );
} );

it( 'ignores repeated initialization while active', () => {
	const button = start();
	expect( start() ).toBe( button );
	expect( startEditorTour ).toHaveBeenCalledTimes( 1 );
	expect( mockSubscribers.size ).toBe( 1 );
} );
