/* global surerankNoticeResponse */
( function () {
	const notices = {
		'surerank-rating-notice': {
			primary: 'rate_surerank',
			snooze: 'maybe_later',
			dismiss: 'dismissed',
		},
	};

	function getAction( element, noticeId ) {
		const config = notices[ noticeId ];
		if ( ! config ) {
			return null;
		}

		if (
			element.classList.contains( 'button-primary' ) ||
			( element.classList.contains( 'astra-notice-close' ) &&
				element.getAttribute( 'target' ) === '_blank' )
		) {
			return config.primary;
		}

		if ( element.hasAttribute( 'data-repeat-notice-after' ) ) {
			return config.snooze;
		}

		if ( element.classList.contains( 'astra-notice-close' ) ) {
			return config.dismiss;
		}

		return null;
	}

	function sendResponse( noticeId, button ) {
		const body = new FormData();
		body.append( 'action', 'surerank_notice_response' );
		body.append( 'nonce', surerankNoticeResponse.nonce );
		body.append( 'notice_id', noticeId );
		body.append( 'button', button );

		fetch( surerankNoticeResponse.ajaxurl, {
			method: 'POST',
			body,
			credentials: 'same-origin',
			keepalive: true,
		} ).catch( () => {} );
	}

	Object.keys( notices ).forEach( function ( noticeId ) {
		const container = document.getElementById( noticeId );
		if ( ! container ) {
			return;
		}

		container.addEventListener( 'click', function ( event ) {
			const link = event.target.closest( 'a' );
			if ( ! link ) {
				return;
			}

			const action = getAction( link, noticeId );
			if ( action ) {
				sendResponse( noticeId, action );
			}
		} );
	} );
}() );
