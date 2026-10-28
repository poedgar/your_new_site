/* global surecookieRatingNotice */
/**
 * Click tracking for the SureCookie review-request notice.
 *
 * Fires a non-blocking AJAX beacon when the user clicks the CTA, "Maybe later",
 * or "I already did". The button is inferred from the link's existing classes
 * so the notice markup needs no extra data attributes. Mirrors the SureForms
 * review flow.
 */
( function () {
	const NOTICE_ID = 'surecookie-rating-notice';

	// Link role -> button key sent to the AJAX handler.
	const BUTTONS = {
		primary: 'rate_surecookie',
		snooze: 'maybe_later',
		dismiss: 'dismissed',
	};

	function getButton( el ) {
		// CTA: the "Rate SureCookie" link opens wp.org in a new tab.
		if (
			el.classList.contains( 'button-primary' ) ||
			( el.classList.contains( 'astra-notice-close' ) &&
				el.getAttribute( 'target' ) === '_blank' )
		) {
			return BUTTONS.primary;
		}
		// Snooze: only the "Maybe later" link carries the repeat interval.
		if ( el.hasAttribute( 'data-repeat-notice-after' ) ) {
			return BUTTONS.snooze;
		}
		// Dismiss: any remaining close link ("I already did").
		if ( el.classList.contains( 'astra-notice-close' ) ) {
			return BUTTONS.dismiss;
		}
		return null;
	}

	function sendResponse( button ) {
		const body = new FormData();
		body.append( 'action', 'surecookie_rating_notice_track' );
		body.append( 'nonce', surecookieRatingNotice.nonce );
		body.append( 'button', button );

		// Fire and forget - tracking must never block the notice interaction.
		fetch( surecookieRatingNotice.ajaxurl, {
			method: 'POST',
			body,
		} ).catch( function () {} );
	}

	const container = document.getElementById( NOTICE_ID );
	if ( ! container ) {
		return;
	}

	container.addEventListener( 'click', function ( e ) {
		const link = e.target.closest( 'a' );
		if ( ! link ) {
			return;
		}

		const button = getButton( link );
		if ( button ) {
			sendResponse( button );
		}
	} );
}() );
