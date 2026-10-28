/* global coAdmin, tinyMCE */
( function () {
	'use strict';

	function post( action, body ) {
		body.append( 'action', action );
		body.append( '_ajax_nonce', coAdmin.nonce );
		return fetch( coAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json();
		} );
	}

	// New offer: test send + confirmation.
	var form = document.getElementById( 'co-offer-form' );
	if ( form ) {
		var result = form.querySelector( '.co-test-result' );

		document.getElementById( 'co-test' ).addEventListener( 'click', function () {
			if ( window.tinyMCE ) {
				tinyMCE.triggerSave();
			}
			var data = new FormData( form );
			data.delete( 'action' );
			result.textContent = '…';
			post( 'co_test_send', data ).then( function ( r ) {
				result.textContent = r.data;
			} ).catch( function () {
				result.textContent = 'Request failed.';
			} );
		} );

		form.addEventListener( 'submit', function ( e ) {
			var n = form.dataset.count;
			var onlyNew = form.querySelector( '[name="only_new"]' ).checked;
			var msg = onlyNew ? 'Send this offer to every subscribed contact who has never been emailed?' : 'Send this offer to ' + n + ' subscribed contacts?';
			if ( ! window.confirm( msg ) ) {
				e.preventDefault();
			}
		} );
	}

	// Campaign: batch sending loop.
	var box = document.getElementById( 'co-progress' );
	var toggle = document.getElementById( 'co-toggle' );
	if ( box && toggle ) {
		var running = false;
		var timer = null;
		var log = box.querySelector( '.co-log' );
		var bar = box.querySelector( '.co-bar span' );

		function render( d ) {
			[ 'sent', 'failed', 'total', 'status' ].forEach( function ( k ) {
				box.querySelector( '[data-k="' + k + '"]' ).textContent = d[ k ];
			} );
			bar.style.width = ( d.total ? Math.round( ( d.sent + d.failed ) / d.total * 100 ) : 100 ) + '%';
		}

		function setRunning( on ) {
			running = on;
			toggle.textContent = on ? toggle.dataset.pause : toggle.dataset.start;
			if ( ! on ) {
				clearTimeout( timer );
			}
		}

		function tick() {
			var data = new FormData();
			data.append( 'campaign', box.dataset.campaign );
			post( 'co_send_batch', data ).then( function ( r ) {
				if ( ! r.success ) {
					throw new Error( r.data );
				}
				render( r.data );
				if ( r.data.errors.length ) {
					log.textContent = r.data.errors.join( ' | ' );
				}
				if ( 'sending' !== r.data.status ) {
					setRunning( false );
					box.querySelectorAll( '#co-toggle, .co-inline, .description' ).forEach( function ( el ) {
						el.remove();
					} );
					log.textContent = 'Finished.';
					return;
				}
				if ( running ) {
					log.textContent = 'Waiting ' + coAdmin.delay + 's before the next batch…';
					timer = setTimeout( tick, coAdmin.delay * 1000 );
				}
			} ).catch( function ( err ) {
				setRunning( false );
				log.textContent = 'Paused: ' + err.message;
			} );
		}

		toggle.addEventListener( 'click', function () {
			setRunning( ! running );
			if ( running ) {
				log.textContent = 'Sending…';
				tick();
			} else {
				log.textContent = 'Paused.';
			}
		} );

		window.addEventListener( 'beforeunload', function ( e ) {
			if ( running ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );

		if ( '1' === box.dataset.autostart ) {
			toggle.click();
		}
	}

	// Contacts: select all.
	var all = document.getElementById( 'co-check-all' );
	if ( all ) {
		all.addEventListener( 'change', function () {
			document.querySelectorAll( 'input[name="ids[]"]' ).forEach( function ( c ) {
				c.checked = all.checked;
			} );
		} );
	}
} )();
