/* global describe, it, expect, jest, beforeEach, afterEach */

import { mountBricksToolbar } from './toolbar';

const BUTTON_ID = 'surerank-bricks-toolbar-button';
const flushMutations = async () => {
	await Promise.resolve();
	await Promise.resolve();
};

describe( 'Bricks toolbar lifecycle', () => {
	let cleanup;
	let onMount;
	let createButton;

	beforeEach( () => {
		document.body.innerHTML = '';
		onMount = jest.fn();
		createButton = jest.fn( () => {
			const button = document.createElement( 'li' );
			button.id = BUTTON_ID;
			return button;
		} );
	} );

	afterEach( () => {
		cleanup?.();
		jest.useRealTimers();
	} );

	const mount = ( isReady = () => true ) => {
		cleanup = mountBricksToolbar( { isReady, createButton, onMount } );
		return document.getElementById( BUTTON_ID );
	};

	it.each( [ 'right', 'end' ] )(
		'preserves legacy .%s placement after the fourth control',
		( group ) => {
			document.body.innerHTML = `<div id="bricks-toolbar"><ul class="${ group }"><li>1</li><li>2</li><li>3</li><li>4</li><li>5</li></ul></div>`;
			const button = mount();
			expect( button.previousElementSibling.textContent ).toBe( '4' );
			expect( button.nextElementSibling.textContent ).toBe( '5' );
			expect( onMount ).toHaveBeenCalledTimes( 1 );
		}
	);

	// Bricks 2.4 positions end-group items with CSS `order`: undo, redo, WordPress, new tab, preview, save.
	const bricks24EndGroup = ( orders = [ 0, 100, 300, 400, 500, 600 ] ) =>
		`<div id="bricks-toolbar-top" class="bricks-toolbar toolbar-top"><ul class="group-wrapper end">${ orders
			.map( ( order ) => `<li style="order: ${ order }">${ order }</li>` )
			.join( '' ) }</ul></div>`;

	it( 'copies the CSS order of the Bricks 2.4 item it follows', () => {
		document.body.innerHTML = bricks24EndGroup();
		const button = mount();
		expect( button.previousElementSibling.textContent ).toBe( '400' );
		expect( button.style.order ).toBe( '400' );
	} );

	it( 'uses the highest CSS order when appended to a short Bricks 2.4 group', () => {
		document.body.innerHTML = bricks24EndGroup( [ 500, 100 ] );
		const button = mount();
		expect( button.nextElementSibling ).toBeNull();
		expect( button.style.order ).toBe( '500' );
	} );

	it( 'follows new CSS orders when Bricks renumbers its items', async () => {
		document.body.innerHTML = bricks24EndGroup();
		const button = mount();
		button.previousElementSibling.style.order = '300';
		button.previousElementSibling.className = 'renumbered';
		await flushMutations();
		expect( button.style.order ).toBe( '300' );
	} );

	it( 'clears the CSS order in the toolbar-root fallback', () => {
		document.body.innerHTML =
			'<div id="bricks-toolbar-top" class="bricks-toolbar"><ul class="end is-hidden"></ul></div>';
		const button = mount();
		expect( button.style.order ).toBe( '' );
	} );

	it.each( [
		[ 'top', 'bottom' ],
		[ 'right', 'left' ],
		[ 'bottom', 'top' ],
		[ 'left', 'right' ],
	] )( 'supports the Bricks 2.4 %s edge', ( edge, tooltipPosition ) => {
		document.body.innerHTML = `<div id="bricks-toolbar-${ edge }" class="bricks-toolbar toolbar-${ edge }"><ul class="group-wrapper end"><li>Save</li></ul></div>`;
		const button = mount();
		expect( button.parentElement.className ).toBe( 'group-wrapper end' );
		expect( button.getAttribute( 'data-balloon-pos' ) ).toBe(
			tooltipPosition
		);
	} );

	it.each( [
		'<div id="bricks-toolbar"></div>',
		'<div id="bricks-toolbar-top" class="bricks-toolbar"><ul class="end is-hidden"></ul></div>',
	] )(
		'uses the root fallback when no usable action group exists',
		( html ) => {
			document.body.innerHTML = html;
			const button = mount();
			expect( button.parentElement ).toBe(
				document.body.firstElementChild
			);
		}
	);

	it( 'creates only one trigger when multiple groups and edges exist', async () => {
		document.body.innerHTML =
			'<div id="bricks-toolbar" class="bricks-toolbar"><ul class="right"></ul><ul class="end"></ul></div><div class="bricks-toolbar toolbar-left"><ul class="end"></ul></div>';
		const button = mount();
		await flushMutations();
		expect( document.querySelectorAll( `#${ BUTTON_ID }` ) ).toHaveLength(
			1
		);
		expect( createButton ).toHaveBeenCalledTimes( 1 );
		expect( onMount ).toHaveBeenCalledTimes( 1 );
		expect( button.parentElement.className ).toBe( 'right' );
	} );

	it( 'recovers a toolbar rendered after the old five-second cutoff', async () => {
		jest.useFakeTimers();
		mount();
		jest.advanceTimersByTime( 6000 );
		document.body.innerHTML =
			'<section><div class="bricks-toolbar toolbar-left"><ul class="end"></ul></div></section>';
		await flushMutations();
		expect( document.getElementById( BUTTON_ID ) ).not.toBeNull();
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	it( 'waits for delayed store registration without a DOM change', () => {
		jest.useFakeTimers();
		document.body.innerHTML =
			'<div class="bricks-toolbar"><ul class="end"></ul></div>';
		let ready = false;
		mount( () => ready );
		jest.advanceTimersByTime( 6000 );
		expect( document.getElementById( BUTTON_ID ) ).toBeNull();
		ready = true;
		jest.advanceTimersByTime( 1000 );
		expect( document.getElementById( BUTTON_ID ) ).not.toBeNull();
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	it( 'reuses the trigger after group removal, toolbar replacement and edge changes', async () => {
		document.body.innerHTML =
			'<div class="bricks-toolbar toolbar-top"><ul class="end"></ul></div>';
		const button = mount();
		button.addEventListener( 'click', onMount );
		button.parentElement.remove();
		await flushMutations();
		expect( document.getElementById( BUTTON_ID ) ).toBe( button );
		document.body.innerHTML =
			'<section><div class="bricks-toolbar toolbar-right"><ul class="end"></ul></div></section>';
		await flushMutations();
		expect( document.getElementById( BUTTON_ID ) ).toBe( button );
		expect( button.getAttribute( 'data-balloon-pos' ) ).toBe( 'left' );
		expect( createButton ).toHaveBeenCalledTimes( 1 );
		expect( onMount ).toHaveBeenCalledTimes( 1 );
		button.click();
		expect( onMount ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'moves out of hidden groups and into late visible groups', async () => {
		document.body.innerHTML =
			'<div class="bricks-toolbar"><ul class="end"></ul></div>';
		const button = mount();
		const toolbar = document.body.firstElementChild;
		const group = button.parentElement;
		group.classList.add( 'is-hidden' );
		await flushMutations();
		expect( button.parentElement ).toBe( toolbar );
		group.classList.remove( 'is-hidden' );
		await flushMutations();
		expect( button.parentElement ).toBe( group );
	} );

	it( 'retains placement on an existing edge when another edge is added', async () => {
		document.body.innerHTML =
			'<div class="bricks-toolbar toolbar-bottom"><ul class="end"></ul></div>';
		const button = mount();
		const group = button.parentElement;
		document.body.insertAdjacentHTML(
			'afterbegin',
			'<div class="bricks-toolbar toolbar-top"><ul class="end"></ul></div>'
		);
		await flushMutations();
		expect( button.parentElement ).toBe( group );
	} );

	it( 'ignores nested unrelated groups, hidden toolbars and status mutations', async () => {
		document.body.innerHTML =
			'<div class="bricks-toolbar" hidden><ul class="end"></ul></div><div id="bricks-toolbar"><div><span class="end"></span></div></div>';
		const button = mount();
		expect( button.parentElement.id ).toBe( 'bricks-toolbar' );
		button.innerHTML = '<div class="surerank-status-indicator"></div>';
		button.firstElementChild.className = 'status-red';
		document.body.appendChild( document.createElement( 'p' ) );
		await flushMutations();
		expect( createButton ).toHaveBeenCalledTimes( 1 );
		expect( onMount ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not clone a trigger already owned by another initialization', () => {
		document.body.innerHTML = `<div id="bricks-toolbar"><ul class="end"><li id="${ BUTTON_ID }"></li></ul></div>`;
		mount();
		expect( createButton ).not.toHaveBeenCalled();
		expect( onMount ).not.toHaveBeenCalled();
	} );

	it( 'cleans up and cannot remount after teardown', async () => {
		jest.useFakeTimers();
		let ready = false;
		mount( () => ready );
		expect( jest.getTimerCount() ).toBe( 1 );
		cleanup();
		ready = true;
		document.body.innerHTML =
			'<div class="bricks-toolbar"><ul class="end"></ul></div>';
		jest.advanceTimersByTime( 10000 );
		await flushMutations();
		expect( jest.getTimerCount() ).toBe( 0 );
		expect( createButton ).not.toHaveBeenCalled();
	} );
} );
