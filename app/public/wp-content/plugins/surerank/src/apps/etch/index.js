import {
	cn,
	getStatusIndicatorClasses,
	getStatusIndicatorAriaLabel,
} from '@/functions/utils';
import { STORE_NAME } from '@/store/constants';
import { select } from '@wordpress/data';
import { getTooltipText } from '@/apps/seo-popup/utils/page-checks-status-tooltip-text';
import {
	handleOpenSureRankDrawer,
	sureRankLogoForBuilder,
} from '@SeoPopup/utils/page-builder-functions';
import { ENABLE_PAGE_LEVEL_SEO } from '@/global/constants';
import {
	getPageCheckStatus,
	handleRefreshWithBrokenLinks,
} from '../elementor/page-checks';
import { startEditorTour } from '@SeoPopup/components/editor-tour/start-tour';

/**
 * Control id, also what we pass to remove() on teardown.
 */
const CONTROL_ID = 'surerank-seo';

/**
 * Slot to register into. `top.after` sits under Etch's own icon group.
 */
const SETTINGS_BAR_SLOT = 'top';

/**
 * Placeholder icon. Etch only accepts an Iconify name, so the real logo is
 * swapped in by mountLogo() once the button exists.
 */
const PLACEHOLDER_ICON = 'hugeicons:search-01';

/**
 * Milliseconds between attempts to find Etch's control API.
 */
const API_POLL_INTERVAL = 100;

/**
 * How long to keep waiting for the builder to mount, in milliseconds.
 *
 * Etch boots its application, then renders the settings bar some time later.
 * On a slow load that gap has been measured at over five seconds, so a short
 * budget silently loses the button: we stop waiting before the rail exists and
 * never register. Wait generously instead, and let the observer below settle it
 * the moment the rail appears rather than on a timer.
 */
const BUILDER_MOUNT_TIMEOUT = 60000;

/**
 * Maximum attempts to find Etch's control API before giving up.
 */
const API_MAX_RETRIES = BUILDER_MOUNT_TIMEOUT / API_POLL_INTERVAL;

/**
 * Milliseconds between checks that the builder is still on the same post.
 */
const POST_WATCH_INTERVAL = 1000;

/**
 * Read Etch's settings-bar API, or null when unavailable.
 *
 * Feature-detected every call so a future Etch release degrades to no button
 * rather than throwing inside the builder.
 *
 * @return {Object|null} The settings-bar slot, or null when unavailable.
 */
const getSettingsBarSlot = () => {
	const slot =
		window?.etchControls?.builder?.settingsBar?.[ SETTINGS_BAR_SLOT ];

	if (
		! slot ||
		typeof slot.addAfter !== 'function' ||
		typeof slot.remove !== 'function'
	) {
		return null;
	}

	return slot;
};

/**
 * Whether the builder is still on the post the popup was localized with.
 *
 * Etch rewrites post_id with pushState, so the URL is the only live source.
 *
 * @return {boolean} True when the builder and the popup agree on the post.
 */
const isEditingLocalizedPost = () => {
	const localized = Number( window?.surerank_seo_popup?.post_id ?? 0 );

	if ( ! localized ) {
		return false;
	}

	const raw = new URLSearchParams( window.location.search ).get( 'post_id' );

	/** A missing argument is benign. Anything present but different is not. */
	if ( null === raw ) {
		return true;
	}

	return Number( raw ) === localized;
};

/**
 * Build the status dot, matching the other builder integrations.
 *
 * @return {Element|null} The indicator, or null when there is no status.
 */
const createStatusIndicator = () => {
	const { status, counts } = getPageCheckStatus();

	if ( ! status || ! ENABLE_PAGE_LEVEL_SEO ) {
		return null;
	}

	const indicator = document.createElement( 'div' );
	/**
	 * The other builders hang this off a padded button, so an inset corner
	 * lands in that padding. Etch gives us only the icon slot, and the wrapper
	 * shrink-wraps the logo, so an inset dot sits on the mark itself. The
	 * negative offsets push it just outside the artwork instead.
	 */
	indicator.className = cn(
		'surerank-status-indicator',
		'absolute -top-1 -right-1 size-2 rounded-full z-10 duration-200',
		getStatusIndicatorClasses( status )
	);

	const ariaLabel = getStatusIndicatorAriaLabel( counts.errorAndWarnings );
	indicator.setAttribute( 'aria-label', ariaLabel );
	indicator.setAttribute( 'title', ariaLabel );

	return indicator;
};

/**
 * Marks our logo, to tell it apart from Etch's own icon.
 */
const LOGO_CLASS = 'surerank-builder-logo';

/**
 * Container class every SureRank utility class is compiled to require.
 */
const ROOT_CLASS = 'surerank-root';

/**
 * Poll a getter until truthy. The builder mounts progressively.
 *
 * @param {Function} getValue Called on each attempt.
 * @return {Promise<*>} The first truthy value, or null when attempts run out.
 */
const waitFor = async ( getValue ) => {
	for ( let attempt = 0; attempt < API_MAX_RETRIES; attempt++ ) {
		const value = getValue();

		if ( value ) {
			return value;
		}

		await new Promise( ( resolve ) => {
			setTimeout( resolve, API_POLL_INTERVAL );
		} );
	}

	return null;
};

/**
 * Resolve once an element matching the selector is in the document.
 *
 * Driven by a MutationObserver rather than a poll, so it fires the moment Etch
 * inserts the node however long that takes, instead of racing a deadline.
 *
 * @param {string} selector CSS selector to wait for.
 * @return {Promise<Element|null>} The element, or null if it never appears.
 */
const waitForElement = ( selector ) =>
	new Promise( ( resolve ) => {
		const existing = document.querySelector( selector );

		if ( existing ) {
			resolve( existing );
			return;
		}

		let timer = null;

		const observer = new MutationObserver( () => {
			const found = document.querySelector( selector );

			if ( ! found ) {
				return;
			}

			clearTimeout( timer );
			observer.disconnect();
			resolve( found );
		} );

		observer.observe( document.body, { childList: true, subtree: true } );

		timer = setTimeout( () => {
			observer.disconnect();
			resolve( null );
		}, BUILDER_MOUNT_TIMEOUT );
	} );

/**
 * Swap Etch's placeholder icon for the SureRank logo.
 *
 * Etch paints its icon asynchronously and overwrites the wrapper, so this is
 * safe to call repeatedly and is re-run from a MutationObserver.
 *
 * @param {Element} button The button Etch rendered for our control.
 * @return {Element|null} The wrapper to append the status indicator to.
 */
const mountLogo = ( button ) => {
	const iconWrapper = button?.querySelector( '.icon-wrapper' );

	if ( ! iconWrapper ) {
		return null;
	}

	const existing = iconWrapper.querySelector( `.${ ROOT_CLASS }` );

	/** Already ours. Rewriting would retrigger the observer that calls us. */
	if ( existing ) {
		return existing;
	}

	/**
	 * Every rule is compiled scoped under .surerank-root (postcss.config.js),
	 * and the popup's own root is in a shadow tree, so the button needs its own
	 * or the utility classes do nothing.
	 */
	const root = document.createElement( 'span' );
	root.className = `${ ROOT_CLASS } relative`;
	root.innerHTML = sureRankLogoForBuilder( `w-5 h-5 ${ LOGO_CLASS }` );

	iconWrapper.replaceChildren( root );

	return root;
};

/**
 * Register the control and return the button Etch rendered for it.
 *
 * Etch assigns its own id, so the node is found by diffing the slot's children
 * around registration. Assumes ours is the only control appearing in that
 * window; nothing else registers here today.
 *
 * @param {Object} slot Etch settings-bar slot.
 * @return {Promise<Element|null>} The rendered button, or null if not found.
 */
const registerControl = async ( slot ) => {
	const selector = `.settings-bar__section.${ SETTINGS_BAR_SLOT }`;

	/** etchControls exists well before the rail is in the DOM, so wait for it. */
	const container = await waitForElement( selector );

	if ( ! container ) {
		return null;
	}

	const before = new Set( container.children );

	slot.addAfter( {
		id: CONTROL_ID,
		icon: PLACEHOLDER_ICON,
		tooltip: getTooltipText( getPageCheckStatus().counts ),
		callback: handleOpenSureRankDrawer,
	} );

	return waitFor(
		() =>
			[ ...container.children ].find(
				( child ) => ! before.has( child )
			) ?? null
	);
};

/**
 * Wire SureRank into the Etch builder, once the store is ready.
 *
 * @return {void}
 */
const setupEtchIntegration = () => {
	let unsubscribe = null;
	let observer = null;
	let retryCount = 0;
	let cleanup = null;
	let postWatcher = null;

	/**
	 * Withdraws the button when the builder moves to a different post. There is
	 * no way to re-localize, so a reload is what brings it back. Replaced in
	 * start() once the button exists.
	 */
	let onPostMayHaveChanged = () => {};

	const start = async () => {
		const slot = getSettingsBarSlot();

		if ( ! slot ) {
			/** Etch's bundle may still be evaluating. Retry, then stop. */
			if ( retryCount < API_MAX_RETRIES ) {
				retryCount++;
				setTimeout( start, API_POLL_INTERVAL );
			}
			return;
		}

		const button = await registerControl( slot );

		if ( ! button ) {
			return;
		}

		const updateStatusIndicator = () => {
			const wrapper = mountLogo( button );

			if ( ! wrapper ) {
				return;
			}

			const indicator = createStatusIndicator();
			const existing = wrapper.querySelector(
				'.surerank-status-indicator'
			);

			/**
			 * Replace only on a real change, or the observer retriggers on
			 * every notification. Label as well as class: the colour comes
			 * from the status, the label from the count.
			 */
			if (
				existing?.className === indicator?.className &&
				existing?.getAttribute( 'title' ) ===
					indicator?.getAttribute( 'title' )
			) {
				return;
			}

			existing?.remove();

			if ( indicator ) {
				wrapper.appendChild( indicator );
			}
		};

		const teardown = () => {
			if ( observer ) {
				observer.disconnect();
				observer = null;
			}
			if ( typeof unsubscribe === 'function' ) {
				unsubscribe();
				unsubscribe = null;
			}
			if ( postWatcher ) {
				clearInterval( postWatcher );
				postWatcher = null;
			}
			window.removeEventListener( 'popstate', onPostMayHaveChanged );
			getSettingsBarSlot()?.remove( CONTROL_ID );
		};

		/** Terminal for this page, so stop observing rather than work on a detached node. */
		onPostMayHaveChanged = () => {
			if ( isEditingLocalizedPost() ) {
				return;
			}

			teardown();
		};

		cleanup = teardown;

		updateStatusIndicator();

		/** Etch repaints its icon and wipes ours, so put them back each time. */
		observer = new MutationObserver( updateStatusIndicator );
		observer.observe( button, { childList: true, subtree: true } );

		/** Populate the checks if nothing has requested them yet. */
		handleRefreshWithBrokenLinks();

		/**
		 * A throw here would stop every later subscriber being notified for
		 * that dispatch and silently freeze the popup, so nothing escapes.
		 */
		unsubscribe = wp?.data?.subscribe?.( () => {
			try {
				updateStatusIndicator();
				onPostMayHaveChanged();
			} catch ( error ) {
				/* Leave the builder working even if the button cannot update. */
			}
		} );

		/**
		 * pushState fires no event and popstate only covers back and forward,
		 * so poll. A query-string read and an integer compare.
		 */
		postWatcher = setInterval( onPostMayHaveChanged, POST_WATCH_INTERVAL );
		window.addEventListener( 'popstate', onPostMayHaveChanged );

		startEditorTour( {
			/** Tour points at the button, reads status from the dot on it. */
			triggerSelectors: [ `.${ ROOT_CLASS } .${ LOGO_CLASS }` ],
			getStatusEl: () =>
				document.querySelector(
					`.${ ROOT_CLASS } .surerank-status-indicator`
				),
		} );
	};

	start();

	window.addEventListener( 'beforeunload', () => {
		cleanup?.();
	} );
};

/**
 * Wait for the SureRank store, which seo-popup registers, before wiring up.
 *
 * Same guard the Divi integration uses.
 *
 * @return {void}
 */
const waitForStoreInit = () => {
	let retryCount = 0;
	let storeUnsubscribe = null;
	let isInitialized = false;
	const maxRetries = 50; // Maximum 5 seconds of retrying (50 * 100ms).

	const cleanup = () => {
		if ( storeUnsubscribe && typeof storeUnsubscribe === 'function' ) {
			storeUnsubscribe();
			storeUnsubscribe = null;
		}
	};

	const checkStoreAndInitialize = () => {
		if ( isInitialized ) {
			return;
		}

		try {
			const storeSelectors = select( STORE_NAME );

			if (
				! storeSelectors ||
				typeof storeSelectors.getVariables !== 'function'
			) {
				if ( retryCount < maxRetries ) {
					retryCount++;
					setTimeout( checkStoreAndInitialize, 100 );
				}
				return;
			}

			const variables = storeSelectors.getVariables();

			if ( variables ) {
				isInitialized = true;
				cleanup();
				setupEtchIntegration();
			} else if ( ! storeUnsubscribe ) {
				storeUnsubscribe = wp?.data?.subscribe?.( () => {
					try {
						const currentVariables =
							select( STORE_NAME )?.getVariables();
						if ( currentVariables && ! isInitialized ) {
							isInitialized = true;
							cleanup();
							setupEtchIntegration();
						}
					} catch ( error ) {
						/* Silently handle subscription errors. */
					}
				} );

				setTimeout( () => {
					if ( ! isInitialized ) {
						const fallbackVariables =
							select( STORE_NAME )?.getVariables();
						if ( fallbackVariables ) {
							isInitialized = true;
							cleanup();
							setupEtchIntegration();
						}
					}
				}, 3000 );
			}
		} catch ( error ) {
			if ( retryCount < maxRetries ) {
				retryCount++;
				setTimeout( checkStoreAndInitialize, 100 );
			}
		}
	};

	checkStoreAndInitialize();
};

waitForStoreInit();
