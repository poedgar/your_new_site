/**
 * Live-updates the SureRank admin-bar status dots when SEO checks are
 * ignored / restored / fixed, without a page reload.
 *
 * Watches wp.apiFetch for SureRank check mutations, then re-fetches the
 * server-computed counts (same logic the bar renders with) and patches the
 * dot colors + tooltips in place.
 *
 * @since x.x.x
 * @package
 */
( function () {
	const apiFetch = window.wp && window.wp.apiFetch;

	if ( ! apiFetch ) {
		return;
	}

	const data = window.surerank_admin_bar_live || {};
	const postId = parseInt( data.post_id, 10 ) || 0;

	const COLORS = {
		error: '#d63638',
		warning: '#dba617',
		success: '#00a32a',
	};

	function colorFor( counts ) {
		if ( counts.error > 0 ) {
			return COLORS.error;
		}
		if ( counts.warning > 0 ) {
			return COLORS.warning;
		}
		return COLORS.success;
	}

	function tooltipFor( counts ) {
		if ( ! counts.error && ! counts.warning ) {
			return 'All checks passed';
		}
		return counts.error + ' issues, ' + counts.warning + ' warnings';
	}

	function updateNode( nodeId, counts ) {
		if ( ! counts ) {
			return;
		}
		const li = document.getElementById( 'wp-admin-bar-' + nodeId );
		if ( ! li ) {
			return;
		}
		const dot = li.querySelector( '.surerank-ab-dot' );
		if ( dot ) {
			dot.style.background = colorFor( counts );
		}
		const link = li.querySelector( 'a.ab-item' );
		if ( link ) {
			link.title = tooltipFor( counts );
		}
	}

	function updateParent( counts ) {
		if ( ! counts ) {
			return;
		}
		const dot = document.querySelector(
			'#wp-admin-bar-surerank-meta-box > .ab-item .surerank-ab-dot'
		);
		if ( dot ) {
			dot.style.background = colorFor( counts );
		}
	}

	function refresh() {
		apiFetch( { path: '/surerank/v1/seo-bar-status?post_id=' + postId } )
			.then( function ( res ) {
				if ( ! res ) {
					return;
				}
				updateNode( 'surerank-meta-box-site', res.site );
				updateNode( 'surerank-meta-box-edit', res.page );

				// The parent dot mirrors the page on a post, else the site.
				const hasPage = document.getElementById(
					'wp-admin-bar-surerank-meta-box-edit'
				);
				updateParent( hasPage && res.page ? res.page : res.site );
			} )
			.catch( function () {} );
	}

	let timer = null;
	function scheduleRefresh() {
		if ( timer ) {
			clearTimeout( timer );
		}
		timer = setTimeout( refresh, 400 );
	}

	// Refresh after any SureRank check mutation (ignore/restore/fix).
	apiFetch.use( function ( options, next ) {
		return next( options ).then( function ( result ) {
			const path = ( options && options.path ) || '';
			if (
				path.indexOf( '/checks/ignore-' ) !== -1 ||
				path.indexOf( 'seo-checks/fix' ) !== -1
			) {
				scheduleRefresh();
			}
			return result;
		} );
	} );
}() );
