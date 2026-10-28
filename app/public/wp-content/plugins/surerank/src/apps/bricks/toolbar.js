const BUTTON_ID = 'surerank-bricks-toolbar-button';
const TOOLBAR_SELECTOR = '#bricks-toolbar, .bricks-toolbar';

const getOrder = ( element ) =>
	parseInt( window.getComputedStyle( element ).order, 10 ) || 0;

/**
 * Match the CSS order of the item the trigger follows.
 *
 * Bricks 2.4+ positions toolbar items with CSS `order`, so without it the trigger
 * renders between Undo and Redo no matter where it sits in the DOM.
 *
 * @since x.x.x
 * @param {Element} button  The trigger.
 * @param {Element} target  The group or toolbar root holding the trigger.
 * @param {Element} toolbar The toolbar root.
 * @return {void}
 */
const syncButtonOrder = ( button, target, toolbar ) => {
	if ( target === toolbar ) {
		button.style.order = '';
		return;
	}

	const items = [ ...target.children ].filter( ( item ) => item !== button );
	const order = button.nextElementSibling
		? getOrder( button.previousElementSibling ?? button.nextElementSibling )
		: Math.max( 0, ...items.map( getOrder ) );
	button.style.order = String( order );
};

/**
 * Resolve a usable action group across legacy and movable Bricks toolbars.
 *
 * @param {Element|undefined} currentTarget The previous insertion target.
 * @return {Element|null} A visible action group or toolbar-root fallback.
 */
const getToolbarTarget = ( currentTarget ) => {
	const toolbars = [
		...document.querySelectorAll( TOOLBAR_SELECTOR ),
	].filter( ( toolbar ) => ! toolbar.closest( '[hidden], .is-hidden' ) );
	const groups = toolbars.flatMap( ( toolbar ) =>
		[
			...toolbar.querySelectorAll( ':scope > .right, :scope > .end' ),
		].filter( ( group ) => ! group.closest( '[hidden], .is-hidden' ) )
	);
	// Keep placement stable when multiple edges are in use.
	if ( groups.includes( currentTarget ) ) {
		return currentTarget;
	}
	return groups[ 0 ] || toolbars[ 0 ] || null;
};

/**
 * Keep a single trigger attached as Bricks mounts or rebuilds its toolbar.
 *
 * @param {Object}   options              Integration callbacks.
 * @param {Function} options.isReady      Whether the SureRank store is ready.
 * @param {Function} options.createButton Create the trigger DOM element.
 * @param {Function} options.onMount      Initialize behavior after insertion.
 * @return {Function} Stop observing and retrying.
 */
export const mountBricksToolbar = ( { isReady, createButton, onMount } ) => {
	let toolbar;
	let button;
	let readinessTimer;
	let retryDelay = 100;
	let stopped = false;

	const attach = () => {
		if ( stopped ) {
			return;
		}

		// Store registration can finish without a DOM mutation.
		if ( ! isReady() ) {
			if ( ! readinessTimer ) {
				readinessTimer = window.setTimeout( () => {
					readinessTimer = null;
					attach();
				}, retryDelay );
				retryDelay = Math.min( retryDelay * 2, 1000 );
			}
			return;
		}
		window.clearTimeout( readinessTimer );
		readinessTimer = null;

		const target = getToolbarTarget( button?.parentElement );
		if ( ! target ) {
			toolbar = null;
			return;
		}
		toolbar = target.closest( TOOLBAR_SELECTOR );
		let firstMount = false;
		if ( ! button ) {
			if ( document.getElementById( BUTTON_ID ) ) {
				return;
			}
			button = createButton();
			firstMount = true;
		}

		if ( button.parentElement !== target ) {
			const insertionTarget = target.children[ 3 ];
			if ( target !== toolbar && insertionTarget ) {
				insertionTarget.after( button );
			} else {
				target.appendChild( button );
			}
		}
		// Bricks renumbers item orders when a layout is saved, so sync on every attach.
		syncButtonOrder( button, target, toolbar );
		// In a native group, inherit Bricks' alignment. Only the root fallback
		// needs its own centering, bounded by the toolbar's inner height.
		button.style.alignSelf = target === toolbar ? 'center' : '';
		button.style.maxHeight = target === toolbar ? '100%' : '';
		// Keep the tooltip inside the viewport for every movable toolbar edge.
		let tooltipPosition = 'bottom';
		if ( toolbar.classList.contains( 'toolbar-left' ) ) {
			tooltipPosition = 'right';
		} else if ( toolbar.classList.contains( 'toolbar-right' ) ) {
			tooltipPosition = 'left';
		} else if ( toolbar.classList.contains( 'toolbar-bottom' ) ) {
			tooltipPosition = 'top';
		}
		button.setAttribute( 'data-balloon-pos', tooltipPosition );

		if ( firstMount ) {
			onMount( button );
		}
	};

	const observer = new window.MutationObserver( ( records ) => {
		const toolbarChanged = records.some( ( record ) => {
			// Ignore our status indicator updates and unrelated builder content.
			if ( button?.contains( record.target ) ) {
				return false;
			}
			if ( toolbar?.contains( record.target ) ) {
				return true;
			}
			if (
				record.type === 'attributes' &&
				( record.target.matches( TOOLBAR_SELECTOR ) ||
					record.target.querySelector( TOOLBAR_SELECTOR ) )
			) {
				return true;
			}
			return [ ...record.addedNodes, ...record.removedNodes ].some(
				( node ) =>
					node.nodeType === 1 &&
					( node.matches( TOOLBAR_SELECTOR ) ||
						node.querySelector( TOOLBAR_SELECTOR ) )
			);
		} );
		if ( toolbarChanged ) {
			attach();
		}
	} );

	observer.observe( document.body, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: [ 'class', 'id', 'hidden' ],
	} );
	attach();

	return () => {
		stopped = true;
		observer.disconnect();
		window.clearTimeout( readinessTimer );
		button?.remove();
	};
};
