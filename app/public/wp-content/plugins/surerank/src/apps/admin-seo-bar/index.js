import { __ } from '@wordpress/i18n';
import { createRoot, memo, useEffect, useRef } from '@wordpress/element';
import { Badge } from '@bsf/force-ui';
import { BarChart } from 'lucide-react';
import { useSuspenseSelect, resolveSelect, dispatch } from '@wordpress/data';
import RenderQueue from '@Functions/render-queue';
import { STORE_NAME } from '@Store/constants';
import { cn } from '@/functions/utils';
import initBulkContentGenerationTracker from './bulk-content-generation';
import getBatchGenerationStatusProps from './bulk-content-generation/badge-prop-getter';
import BatchFailureTooltip from './bulk-content-generation/failure-tooltip';
import '@Store/store';
import './style.scss';

// Track bulk content generation progress on the list screens. An older Pro
// still ships its own tracker, so the flag keeps only one of them mounted.
if ( window?.surerank_seo_bar?.bulk_generation ) {
	initBulkContentGenerationTracker();
}

// Initialize a global RenderQueue for sequential badge rendering
const renderQueue = new RenderQueue();

const CustomBadge = ( {
	id,
	spanElement,
	forceRefresh = null,
	onRenderComplete,
} ) => {
	const postIdRef = useRef( id );

	const seoBarType = window?.surerank_seo_bar?.type;
	const isTaxonomy = seoBarType === 'taxonomy';
	const isUser = seoBarType === 'user';
	const checksType = seoBarType || 'post';
	const {
		checks: seoChecks,
		error: errorMessage,
		batchGeneration,
	} = useSuspenseSelect(
		( select ) => {
			const store = select( STORE_NAME );
			const checksResult =
				store?.getSeoBarChecks( id, checksType, forceRefresh ) || {};
			const pageSeoChecks = store?.getPageSeoChecks() || {};

			return {
				...checksResult,
				batchGeneration: pageSeoChecks.batchGeneration,
			};
		},
		[ forceRefresh ]
	);

	// Call onRenderComplete when data is loaded and component is rendered
	useEffect( () => {
		if ( typeof onRenderComplete !== 'function' ) {
			return;
		}
		onRenderComplete();
	}, [ onRenderComplete ] );

	// Opens the full SEO meta box modal for this specific post/term.
	// Mutates window.surerank_seo_popup so existing API helpers (fetchMetaSettings,
	// save-button) read the correct post context without any refactoring.
	const handleEditSeoClick = ( e ) => {
		e.stopPropagation();

		if ( ! window.surerank_seo_popup ) {
			return;
		}

		const link = spanElement?.getAttribute( 'data-link' ) || '';

		// Update the global context for this post/term/user.
		if ( isUser ) {
			window.surerank_seo_popup.post_id = undefined;
			window.surerank_seo_popup.term_id = undefined;
			window.surerank_seo_popup.user_id = id;
			window.surerank_seo_popup.is_user = '1';
			window.surerank_seo_popup.is_taxonomy = '';
		} else if ( isTaxonomy ) {
			window.surerank_seo_popup.post_id = undefined;
			window.surerank_seo_popup.term_id = id;
			window.surerank_seo_popup.user_id = undefined;
			window.surerank_seo_popup.is_user = '';
			window.surerank_seo_popup.is_taxonomy = '1';
		} else {
			window.surerank_seo_popup.post_id = id;
			window.surerank_seo_popup.term_id = undefined;
			window.surerank_seo_popup.user_id = undefined;
			window.surerank_seo_popup.is_user = '';
			window.surerank_seo_popup.is_taxonomy = '';
		}
		window.surerank_seo_popup.link = link;

		// Reset store state for the new post (synchronously wipes modal state,
		// then fires an async background fetch for editor template variables).
		let resetType = 'post';
		if ( isUser ) {
			resetType = 'user';
		} else if ( isTaxonomy ) {
			resetType = 'taxonomy';
		}
		dispatch( STORE_NAME ).resetForNewPost( id, resetType, isTaxonomy );

		// Open the modal immediately — the modal will fetch meta settings once
		// metaboxInitialized is false (set by resetForNewPost above).
		dispatch( STORE_NAME ).updateModalState( true );
	};

	const handleBadgeClick = ( e ) => {
		if ( batchStatus ) {
			return;
		}
		if ( ! window.surerank_seo_popup ) {
			return;
		}
		handleEditSeoClick( e );
	};

	let badgeProps = {
		icon: <BarChart />,
		variant: 'green',
		label: __( 'Optimized', 'surerank' ),
		className: 'w-fit',
	};

	// Check batch generation status first
	let batchStatus = null;
	if ( ! isUser ) {
		batchStatus = getBatchGenerationStatusProps(
			parseInt( postIdRef.current || 0 ),
			batchGeneration
		);
	}
	// requiresPro/message drive the tooltip below, they are not Badge props.
	const {
		requiresPro: batchUpgradeRequired = false,
		message: batchFailureMessage = '',
		...batchBadgeProps
	} = batchStatus ?? {};

	if ( batchStatus ) {
		badgeProps = {
			...badgeProps,
			...batchBadgeProps,
		};
	} else if ( ! seoChecks || errorMessage ) {
		badgeProps = {
			...badgeProps,
			variant: 'red',
			label: errorMessage || __( 'No Data', 'surerank' ),
		};
	} else if ( seoChecks.badChecks.length > 0 ) {
		badgeProps = {
			...badgeProps,
			variant: 'red',
			label: __( 'Issues Detected', 'surerank' ),
		};
	} else if ( seoChecks.fairChecks.length > 0 ) {
		badgeProps = {
			...badgeProps,
			variant: 'yellow',
			label: __( 'Needs Improvement', 'surerank' ),
		};
	}

	return (
		<BatchFailureTooltip
			message={ batchFailureMessage }
			upgradeRequired={ batchUpgradeRequired }
		>
			<div
				onClick={ handleBadgeClick }
				role="button"
				tabIndex={ batchStatus ? -1 : 0 }
				className={ cn(
					'inline-block',
					batchStatus ? 'cursor-default' : 'cursor-pointer'
				) }
				onKeyDown={ ( e ) => {
					if ( e.key === 'Enter' || e.key === ' ' ) {
						handleBadgeClick( e );
					}
				} }
			>
				<Badge { ...badgeProps } />
			</div>
		</BatchFailureTooltip>
	);
};

const CustomBadgeMemoized = memo( CustomBadge );

// Store root instances to properly cleanup
const rootInstances = new Map();

const renderBadge = ( span, forceRefresh = false ) => {
	return new Promise( ( resolve ) => {
		const id = span.getAttribute( 'data-id' );
		if ( ! id ) {
			resolve();
			return;
		}

		// Skip if already rendered and not forcing refresh
		if ( ! forceRefresh && span.dataset.rendered === 'true' ) {
			resolve();
			return;
		}

		// Cleanup existing root if it exists
		const existingRoot = rootInstances.get( span );
		if ( existingRoot ) {
			try {
				existingRoot.unmount();
			} catch ( e ) {}
			rootInstances.delete( span );
		}

		// Create new root and render
		try {
			const root = createRoot( span );
			rootInstances.set( span, root );
			root.render(
				<CustomBadgeMemoized
					id={ id }
					spanElement={ span }
					forceRefresh={ forceRefresh }
					onRenderComplete={ resolve }
				/>
			);
			span.dataset.rendered = 'true'; // Mark as rendered
		} catch ( e ) {
			resolve();
		}
	} );
};

const renderBadges = async () => {
	const spans = document.querySelectorAll(
		'span.surerank-page-score[data-id]'
	);

	const seoBarType = window?.surerank_seo_bar?.type || 'post';
	const ids = Array.from( spans )
		.map( ( span ) => span.getAttribute( 'data-id' ) )
		.filter( Boolean );

	if ( ids.length > 0 ) {
		await resolveSelect( STORE_NAME ).getSeoBarChecks( ids, seoBarType );
	}

	// Use queue for sequential rendering
	spans.forEach( ( span ) => {
		renderQueue.enqueue( () => renderBadge( span, null ) );
	} );
};

// Debounce function to prevent multiple rapid calls
const debounce = ( func, wait ) => {
	let timeout;
	return function executedFunction( ...args ) {
		const later = () => {
			clearTimeout( timeout );
			func( ...args );
		};
		clearTimeout( timeout );
		timeout = setTimeout( later, wait );
	};
};

/* global inlineEditTax, Node */

// Initialize badges on page load
document.addEventListener( 'DOMContentLoaded', () => {
	if (
		window.location.pathname.includes( 'edit.php' ) ||
		window.location.pathname.includes( 'edit-tags.php' ) ||
		window.location.pathname.includes( 'users.php' )
	) {
		renderBadges();
	}

	// Set up MutationObserver to watch for new span elements
	const table = document.querySelector( '#the-list' );
	if ( table ) {
		const observer = new MutationObserver( ( mutations ) => {
			const newSpans = [];
			const newIds = [];

			mutations.forEach( ( mutation ) => {
				if ( mutation.addedNodes.length ) {
					mutation.addedNodes.forEach( ( node ) => {
						if ( node.nodeType === Node.ELEMENT_NODE ) {
							const spans = node.querySelectorAll(
								'span.surerank-page-score[data-id]'
							);
							spans.forEach( ( span ) => {
								if ( ! span.dataset.rendered ) {
									newSpans.push( span );
									newIds.push( span.dataset.id );
								}
							} );
						}
					} );
				}
			} );

			const seoBarType = window?.surerank_seo_bar?.type || 'post';

			const processSpans = ( forceRefresh = true ) => {
				newSpans.forEach( ( span ) => {
					renderQueue.enqueue( () =>
						renderBadge( span, forceRefresh ? Date.now() : null )
					);
				} );
			};

			if ( newIds.length > 0 ) {
				resolveSelect( STORE_NAME )
					.getSeoBarChecks( newIds, seoBarType )
					.finally( () => processSpans( true ) );
			}
		} );

		observer.observe( table, {
			childList: true,
			subtree: true,
		} );
	}
} );

// Handle inline edit for existing terms
document.addEventListener( 'DOMContentLoaded', () => {
	if (
		typeof inlineEditTax !== 'undefined' &&
		typeof inlineEditTax.save === 'function'
	) {
		const originalTaxSave = inlineEditTax.save;
		inlineEditTax.save = function ( id ) {
			let termId = id;
			if ( id && typeof id === 'object' && id.nodeType ) {
				try {
					const row = id.closest( 'tr[id^="tag-"], tr[id^="edit-"]' );
					const idStr = row ? row.id : null;
					if ( idStr ) {
						const parts = idStr.split( '-' );
						termId = parts[ parts.length - 1 ];
					} else {
						return;
					}
				} catch ( e ) {
					return;
				}
			} else if ( typeof id === 'string' && id.startsWith( 'tag-' ) ) {
				termId = id.replace( 'tag-', '' );
			}

			const result = originalTaxSave.call( this, termId );

			const debouncedRerender = debounce( () => {
				const span = document.querySelector(
					`span.surerank-page-score[data-id="${ termId }"]`
				);
				if ( span ) {
					renderQueue.enqueue( () =>
						renderBadge( span, Date.now() )
					);
				}
			}, 3000 );

			debouncedRerender();

			return result;
		};
	}
} );

// After saving from the meta box modal, refresh the SEO bar badge for the
// saved post so the listing-page column reflects the updated checks.
window.addEventListener( 'surerank:seo-data-saved', ( e ) => {
	const postId = e.detail?.postId;
	if ( ! postId ) {
		return;
	}
	const span = document.querySelector(
		`span.surerank-page-score[data-id="${ postId }"]`
	);
	if ( span ) {
		renderQueue.enqueue( () => renderBadge( span, Date.now() ) );
	}
} );

export default memo( CustomBadge );
