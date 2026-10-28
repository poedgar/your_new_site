/**
 * First-run editor guided tour — framework-agnostic launcher.
 *
 * Shared by the Gutenberg/classic React component (editor-tour.js) and the
 * vanilla page-builder integrations (Elementor, Bricks, Breakdance, Divi). Each
 * caller passes the trigger button and how to find its status dot; the launcher
 * handles the once-per-user gate, lazy-loading driver.js, styling, and persistence.
 */
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '@/store/constants';
import { ENABLE_PAGE_LEVEL_SEO } from '@/global/constants';

// Default trigger candidates for the Gutenberg/classic editors, in priority order.
const DEFAULT_TRIGGER_SELECTORS = [
	'#surerank-toolbar-portal button',
	'#surerank-classic-seo-popup-trigger button',
	'#seo-popup button',
];

// Guard so the tour runs at most once per page load.
let tourStarted = false;

// Per-browser "seen" gate. The tour is non-essential, so a browser-local flag is
// enough: re-showing once on a new browser / after a cache clear is acceptable,
// which is why this is localStorage rather than a server-side per-user flag.
const TOUR_SEEN_KEY = 'surerank_editor_tour_seen';

const TOUR_STYLE_ID = 'surerank-editor-tour-style';

// driver.js's own stylesheet (driver.js/dist/driver.css) cannot be imported here:
// SureRank's build rewrites every selector under `#surerank-root` / `.surerank-root`
// to isolate its Tailwind from wp-admin. driver appends the popover to document.body,
// outside that scope, so the rewritten rules never match and the popover renders as an
// unstyled, full-width static block. Instead we inject our own popover stylesheet
// UNSCOPED at runtime (a JS-created <style> bypasses the build), before the popover is
// created so driver measures the correctly sized box and anchors it with an arrow.
//
// This is a SureRank-branded restyle of driver's structure (driver.js ^1.4.0): keep
// driver's class names and arrow geometry, but apply our own card, typography, and
// button styling. Colors mirror tailwind.config.js (brand #4F46E5 / #3730A3, text
// #111827 / #4B5563 / #9CA3AF, border #E5E7EB, surface #F9FAFB).
const TOUR_CSS = `
.driver-overlay{z-index:999999998 !important}
.driver-popover{all:unset;box-sizing:border-box;position:fixed;top:0;right:0;z-index:1000000000;min-width:280px;max-width:340px;padding:20px;background-color:#fff;border:1px solid #E5E7EB;border-radius:12px;box-shadow:0 12px 32px -8px rgba(17,24,39,.18),0 4px 8px -4px rgba(17,24,39,.1);color:#111827;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}
.driver-popover *{box-sizing:border-box;font-family:inherit}
.driver-popover-title{display:block;margin:0;padding-right:24px;font-size:15px;font-weight:600;line-height:1.4;letter-spacing:-.01em;color:#111827}
.driver-popover-description{margin:8px 0 0;font-size:13px;font-weight:400;line-height:1.6;color:#4B5563}
.driver-popover .driver-popover-close-btn{all:unset;position:absolute;top:14px;right:14px;left:auto;bottom:auto;width:24px;height:24px;border-radius:6px;cursor:pointer;font-size:18px;line-height:24px;text-align:center;color:#9CA3AF;transition:color .15s ease,background-color .15s ease}
.driver-popover .driver-popover-close-btn:hover,.driver-popover .driver-popover-close-btn:focus{color:#111827;background-color:#F9FAFB}
.driver-popover-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:18px}
.driver-popover-progress-text{font-size:12px;font-weight:500;color:#9CA3AF}
.driver-popover-navigation-btns{display:flex;flex-grow:1;align-items:center;justify-content:flex-end;gap:8px}
.driver-popover-footer button{all:unset;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:500;line-height:1.2;cursor:pointer;transition:background-color .15s ease,border-color .15s ease,color .15s ease}
.driver-popover-footer .driver-popover-prev-btn{background-color:#fff;color:#4B5563;border:1px solid #E5E7EB}
.driver-popover-footer .driver-popover-prev-btn:hover,.driver-popover-footer .driver-popover-prev-btn:focus{background-color:#F9FAFB;color:#111827}
.driver-popover-footer .driver-popover-next-btn{background-color:#4F46E5;color:#fff;border:1px solid #4F46E5}
.driver-popover-footer .driver-popover-next-btn:hover,.driver-popover-footer .driver-popover-next-btn:focus{background-color:#3730A3;border-color:#3730A3}
.driver-popover-footer .driver-popover-btn-disabled{opacity:.45;pointer-events:none}
.driver-popover-arrow{content:'';position:absolute;border:6px solid #fff}
.driver-popover-arrow-side-over{display:none}
.driver-popover-arrow-side-left{left:100%;border-right-color:transparent;border-bottom-color:transparent;border-top-color:transparent}
.driver-popover-arrow-side-right{right:100%;border-left-color:transparent;border-bottom-color:transparent;border-top-color:transparent}
.driver-popover-arrow-side-top{top:100%;border-right-color:transparent;border-bottom-color:transparent;border-left-color:transparent}
.driver-popover-arrow-side-bottom{bottom:100%;border-left-color:transparent;border-top-color:transparent;border-right-color:transparent}
.driver-popover-arrow-side-center{display:none}
.driver-popover-arrow-side-left.driver-popover-arrow-align-start,.driver-popover-arrow-side-right.driver-popover-arrow-align-start{top:16px}
.driver-popover-arrow-side-top.driver-popover-arrow-align-start,.driver-popover-arrow-side-bottom.driver-popover-arrow-align-start{left:16px}
.driver-popover-arrow-align-end.driver-popover-arrow-side-left,.driver-popover-arrow-align-end.driver-popover-arrow-side-right{bottom:16px}
.driver-popover-arrow-side-top.driver-popover-arrow-align-end,.driver-popover-arrow-side-bottom.driver-popover-arrow-align-end{right:16px}
.driver-popover-arrow-side-left.driver-popover-arrow-align-center,.driver-popover-arrow-side-right.driver-popover-arrow-align-center{top:50%;margin-top:-6px}
.driver-popover-arrow-side-top.driver-popover-arrow-align-center,.driver-popover-arrow-side-bottom.driver-popover-arrow-align-center{left:50%;margin-left:-6px}
.driver-popover-arrow-none{display:none}
`;

// Inject the unscoped tour stylesheet once, before the popover is created.
const injectTourStyles = () => {
	if ( document.getElementById( TOUR_STYLE_ID ) ) {
		return;
	}
	const style = document.createElement( 'style' );
	style.id = TOUR_STYLE_ID;
	style.textContent = TOUR_CSS;
	document.head.appendChild( style );
};

/**
 * Resolve the first present element from a list of selectors.
 *
 * @param {string[]} selectors Selectors to try, in priority order.
 * @return {Element|null} The first match, or null.
 */
const findFirst = ( selectors ) => {
	for ( const selector of selectors ) {
		const el = document.querySelector( selector );
		if ( el ) {
			return el;
		}
	}
	return null;
};

/**
 * Whether an element is actually visible to the user and not covered by another
 * layer. Page builders (e.g. Bricks) inject the toolbar button while a loading
 * splash still covers the editor, so the button exists in the DOM but is hidden
 * behind the overlay. Anchoring the tour then would render it over the splash.
 * We treat the trigger as ready only once it is rendered and is the top-most
 * element at its own center point.
 *
 * @param {Element} element The candidate element.
 * @return {boolean} True when the element is rendered and not occluded.
 */
const isUsable = ( element ) => {
	if ( ! element || ! element.isConnected ) {
		return false;
	}
	const rect = element.getBoundingClientRect();
	if ( rect.width <= 0 || rect.height <= 0 ) {
		return false;
	}
	const centerX = rect.left + rect.width / 2;
	const centerY = rect.top + rect.height / 2;
	// Off-screen / below-the-fold elements (e.g. the classic editor sidebar
	// trigger) are not occluded — they are just scrolled out of view, and driver
	// scrolls to them. Only the occlusion test below would (correctly) reject a
	// button covered by a builder loading splash, so treat off-screen as usable.
	if (
		centerX < 0 ||
		centerY < 0 ||
		centerX > window.innerWidth ||
		centerY > window.innerHeight
	) {
		return true;
	}
	const topElement = document.elementFromPoint( centerX, centerY );
	// elementFromPoint may return the button, a child (icon/svg), or an ancestor
	// wrapper — any of those means the trigger is on top and clickable. A null
	// result (rare edge cases) is not treated as occluded.
	return (
		! topElement ||
		element === topElement ||
		element.contains( topElement ) ||
		topElement.contains( element )
	);
};

/**
 * Wait for an element to appear. Editor UI mounts asynchronously: toolbar
 * buttons are injected after load, and status dots render only once the page
 * checks finish. Resolves as soon as find() returns an element, or null on timeout.
 *
 * @param {Function} find    Returns the target element, or null if not present yet.
 * @param {number}   timeout Max time to wait, in ms.
 * @return {Promise<Element|null>} The element, or null on timeout.
 */
const waitForElement = ( find, timeout ) =>
	new Promise( ( resolve ) => {
		const existing = find();
		if ( existing ) {
			resolve( existing );
			return;
		}

		let settled = false;
		const finish = ( el ) => {
			if ( settled ) {
				return;
			}
			settled = true;
			observer.disconnect();
			window.clearInterval( poll );
			window.clearTimeout( timer );
			resolve( el );
		};

		const check = () => {
			const el = find();
			if ( el ) {
				finish( el );
			}
		};

		const observer = new window.MutationObserver( check );
		observer.observe( document.body, { childList: true, subtree: true } );

		// Poll as well: a loading splash may be dismissed via a style/class change
		// rather than a DOM mutation, which the observer would not catch.
		const poll = window.setInterval( check, 300 );

		const timer = window.setTimeout( () => finish( null ), timeout );
	} );

/**
 * Like waitForElement, but additionally waits until the element's position has
 * SETTLED — the same bounding box across two consecutive checks. Builders such
 * as Divi re-inject their toolbar button right after the editor mounts; driving
 * the tour against a mid-re-injection element leaves driver's spotlight computed
 * against a stale/zero rect (the cutout lands in the page corner).
 *
 * @param {Function} find    Returns the target element, or null if not ready.
 * @param {number}   timeout Max time to wait, in ms.
 * @return {Promise<Element|null>} The settled element, or the last seen / null on timeout.
 */
const waitForStable = ( find, timeout ) =>
	new Promise( ( resolve ) => {
		const startedAt = Date.now();
		let prevKey = null;
		const tick = () => {
			const element = find();
			if ( element ) {
				const rect = element.getBoundingClientRect();
				const key = [ rect.left, rect.top, rect.width, rect.height ]
					.map( Math.round )
					.join();
				if ( key === prevKey ) {
					resolve( element );
					return;
				}
				prevKey = key;
			} else {
				prevKey = null;
			}
			if ( Date.now() - startedAt >= timeout ) {
				resolve( element || null );
				return;
			}
			window.setTimeout( tick, 350 );
		};
		tick();
	} );

/**
 * Wait until the SEO page checks have finished initializing. The status dot
 * renders only once `pageSeoChecks.initializing` flips to false (and a status
 * exists); on slow connections the checks request can take well over any fixed
 * DOM timeout, so gate the dot-step decision on this store signal instead of
 * racing the network. Resolves immediately when there is nothing to wait on:
 * page-level SEO off (no dot ever), or no store/selector in this context.
 *
 * @param {number} timeout Max time to wait, in ms. Resolves the instant checks
 *                         finish, so this cap only bites if they never do.
 * @return {Promise<void>} Resolves when checks are done, or on timeout.
 */
const waitForChecksReady = ( timeout ) =>
	new Promise( ( resolve ) => {
		// No page-level SEO => no dot will ever render; don't wait.
		if ( ! ENABLE_PAGE_LEVEL_SEO ) {
			resolve();
			return;
		}
		const ready = () => {
			try {
				const checks = window.wp?.data
					?.select?.( STORE_NAME )
					?.getPageSeoChecks?.();
				// No store/selector in this context => nothing to gate on.
				return ! checks || checks.initializing === false;
			} catch ( e ) {
				return true;
			}
		};
		if ( ready() ) {
			resolve();
			return;
		}
		const poll = window.setInterval( () => {
			if ( ready() ) {
				window.clearInterval( poll );
				window.clearTimeout( timer );
				resolve();
			}
		}, 200 );
		const timer = window.setTimeout( () => {
			window.clearInterval( poll );
			resolve();
		}, timeout );
	} );

/**
 * Whether the tour has already been seen in this browser. Reads are wrapped
 * because localStorage access throws in some privacy modes; on failure we treat
 * the tour as unseen.
 *
 * @return {boolean} True when the flag is set.
 */
const hasSeenTour = () => {
	try {
		return window.localStorage.getItem( TOUR_SEEN_KEY ) === '1';
	} catch ( e ) {
		return false;
	}
};

/**
 * Persist the "tour seen" flag for this browser. Failures are swallowed: a
 * missed write only means the tour may show once more, which is acceptable.
 *
 * @return {void}
 */
const markTourSeen = () => {
	try {
		window.localStorage.setItem( TOUR_SEEN_KEY, '1' );
	} catch ( e ) {}
};

/**
 * Whether the SureRank SEO metabox is currently open. Source of truth is the
 * front-end store's `getModalState` selector (state.modalEnabled), the same
 * value the toolbar button reads. Reads via window.wp.data so this stays usable
 * from the vanilla page-builder callers too. Wrapped because the store/selector
 * may be absent in some contexts; on failure we treat it as closed.
 *
 * @return {boolean} True when the metabox modal is open.
 */
const isMetaboxOpen = () => {
	try {
		return !! window.wp?.data?.select?.( STORE_NAME )?.getModalState?.();
	} catch ( e ) {
		return false;
	}
};

/**
 * Default status-dot locator for the Gutenberg/classic editors: the colored dot
 * rendered inside the trigger's positioning wrapper.
 *
 * @param {Element} trigger The resolved trigger button.
 * @return {Element|null} The status dot, or null when not rendered.
 */
const defaultFindStatus = ( trigger ) => {
	const scope = trigger.closest( '.relative' ) || trigger.parentElement;
	return scope ? scope.querySelector( '.size-2.rounded-full' ) : null;
};

// Target gap (px) between the trigger's visible icon and the spotlight edge.
// Calibrated to the Gutenberg toolbar icon (24px icon in a 32px button), whose
// default-10 spotlight reads well.
const ICON_EDGE_GAP = 14;

/**
 * Compute a spotlight padding that keeps a consistent gap around the trigger's
 * VISIBLE icon across editors. driver highlights the button box plus this
 * padding, but builders inset the icon inside the box by different amounts
 * (Gutenberg: 24px icon in a 32px button; Elementor: a smaller 20px icon in a
 * larger 36px button), so a single fixed padding looks tight in one editor and
 * oversized in another. Deriving padding from the icon keeps the highlight
 * proportional everywhere. Returns undefined when no icon is measurable, so
 * driver falls back to its own default.
 *
 * @param {Element} trigger The resolved trigger button.
 * @return {number|undefined} Padding in px, or undefined to use driver's default.
 */
const computeStagePadding = ( trigger ) => {
	const icon = trigger.querySelector( 'svg, img' );
	if ( ! icon ) {
		return undefined;
	}
	const buttonRect = trigger.getBoundingClientRect();
	const iconRect = icon.getBoundingClientRect();
	const buttonMin = Math.min( buttonRect.width, buttonRect.height );
	const iconMin = Math.min( iconRect.width, iconRect.height );
	if ( ! buttonMin || ! iconMin ) {
		return undefined;
	}
	const innerInset = ( buttonMin - iconMin ) / 2;
	// Clamp to a sane range; never exceed driver's default look (10).
	return Math.max(
		2,
		Math.min( 10, Math.round( ICON_EDGE_GAP - innerInset ) )
	);
};

/**
 * Launch the first-run guided tour, once per user.
 *
 * @param {Object}   [options]                  Options.
 * @param {Element}  [options.trigger]          Trigger button element (page builders pass it directly).
 * @param {string[]} [options.triggerSelectors] Selector candidates when no element is passed (Gutenberg/classic).
 * @param {Function} [options.getStatusEl]      Returns the status-dot element, or null. Defaults to the Gutenberg locator.
 * @param {number}   [options.stagePadding]     Spotlight padding around the highlighted element, in px. Defaults to driver's 10; pass a smaller value for compact builder triggers (e.g. Elementor).
 * @return {void}
 */
export const startEditorTour = ( {
	trigger,
	triggerSelectors = DEFAULT_TRIGGER_SELECTORS,
	getStatusEl,
	stagePadding,
} = {} ) => {
	if ( hasSeenTour() || tourStarted ) {
		return;
	}
	tourStarted = true;

	( async () => {
		// If the user already opened the metabox before the tour could resolve, the
		// walkthrough would anchor to an already-open trigger — an invalid state. Mark
		// it seen so it never fires later this session/on rerender, and bail.
		if ( isMetaboxOpen() ) {
			markTourSeen();
			return;
		}

		// Watch the store during our async wait window (trigger settle + checks +
		// driver load): if the user opens the metabox while the tour is still pending
		// but not yet visible, cancel the pending tour and mark it seen. Unsubscribed
		// once the tour actually drives, so the already-visible case is left entirely
		// to driver's existing dismiss-on-interaction handling.
		let metaboxOpened = false;
		let unsubscribe = () => {};
		try {
			if ( window.wp?.data?.subscribe ) {
				unsubscribe = window.wp.data.subscribe( () => {
					if ( isMetaboxOpen() ) {
						metaboxOpened = true;
						markTourSeen();
						unsubscribe();
					}
				} );
			}
		} catch ( e ) {}

		// Resolve the trigger LIVE each time: some builders (e.g. Divi) re-inject
		// their toolbar button, so a node captured once goes stale. When the caller
		// passes selectors we re-query; when it passes a node we use it as-is.
		const freshTrigger = trigger
			? () => trigger
			: () => findFirst( triggerSelectors );

		// Wait until the trigger is present AND actually visible (not covered by a
		// builder loading splash, which would otherwise render the tour over it).
		const resolveTrigger = () => {
			const el = freshTrigger();
			return el && isUsable( el ) ? el : null;
		};
		// Wait until the trigger is usable AND its position has settled, so the
		// first step's spotlight is computed against the final button (not a
		// mid-re-injection one).
		const triggerEl = await waitForStable( resolveTrigger, 8000 );
		if ( ! triggerEl || metaboxOpened ) {
			unsubscribe();
			return;
		}

		// The status dot renders only once the SEO page checks finish. Gate on the
		// store's completion signal rather than a blind timeout, so a slow checks
		// request no longer causes the dot step to be dropped on slow connections.
		// Once checks are done the dot's render outcome is final: it's present
		// (status exists) or it never will be (no status / SEO off).
		await waitForChecksReady( 20000 );
		const findStatus =
			getStatusEl || ( () => defaultFindStatus( triggerEl ) );
		// Short DOM wait just to catch React's render tick now that checks are done.
		const hasStatusDot = !! ( await waitForElement( findStatus, 2000 ) );

		// The metabox may have been opened during any of the waits above; don't
		// pop the tour over an already-open metabox.
		if ( metaboxOpened ) {
			unsubscribe();
			return;
		}

		const { driver } = await import(
			/* webpackChunkName: "editor-tour" */ 'driver.js'
		);

		// Final gate after the async chunk load: bail if the metabox opened while
		// driver.js was loading. Past this point the tour is committed, so stop
		// watching and hand dismissal to driver's own onDestroyed handling.
		if ( metaboxOpened ) {
			unsubscribe();
			return;
		}
		unsubscribe();

		const steps = [
			{
				element: () => freshTrigger() || triggerEl,
				popover: {
					title: __( 'Optimize this page for search', 'surerank' ),
					description: __(
						'Open the SureRank Metabox from this icon to edit your SEO title, meta description, and social preview, along with other settings that help this page rank higher in search results.',
						'surerank'
					),
					side: 'bottom',
					align: 'end',
				},
			},
		];

		// Anchor the status step to the dot itself, but pass a RESOLVER function
		// rather than a captured node: driver.js calls it when the step activates,
		// so even though the page builders remove and recreate the dot on every
		// store update, the highlight always lands on the current dot. Fall back to
		// the trigger icon if the dot momentarily vanishes during a re-render.
		if ( hasStatusDot ) {
			steps.push( {
				element: () => findStatus() || freshTrigger() || triggerEl,
				popover: {
					title: __(
						'Check your SEO health at a glance',
						'surerank'
					),
					description: __(
						'This dot reflects the SEO status of your page. Green means all checks pass, while red, yellow, or blue flag errors, warnings, or suggestions to review.',
						'surerank'
					),
					side: 'bottom',
					align: 'end',
				},
			} );
		}

		let persisted = false;
		const persistOnce = () => {
			if ( persisted ) {
				return;
			}
			persisted = true;
			markTourSeen();
		};

		// Inject driver's stylesheet (unscoped) before driver creates and measures
		// the popover, so its size/position are computed correctly.
		injectTourStyles();

		// Tighten the spotlight to the trigger's icon so compact builder buttons
		// (e.g. Elementor) don't get an oversized highlight. An explicit caller
		// value wins; otherwise it's derived from the icon, falling back to
		// driver's default when no icon is measurable.
		const resolvedStagePadding =
			typeof stagePadding === 'number'
				? stagePadding
				: computeStagePadding( triggerEl );

		const driverObj = driver( {
			showProgress: steps.length > 1,
			allowClose: true,
			nextBtnText: __( 'Next', 'surerank' ),
			prevBtnText: __( 'Back', 'surerank' ),
			doneBtnText: __( 'Done', 'surerank' ),
			steps,
			...( typeof resolvedStagePadding === 'number'
				? { stagePadding: resolvedStagePadding }
				: {} ),
			// Fires on complete, skip/close button, ESC, and overlay click.
			onDestroyed: persistOnce,
		} );
		driverObj.drive();
	} )().catch( () => {
		// Tour is non-essential; ignore load/render failures.
	} );
};
