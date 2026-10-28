import { Badge } from '@bsf/force-ui';
import {
	memo,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { RefreshCw } from 'lucide-react';
import { SeoPopupTooltip } from '@AdminComponents/tooltip';
import { cn } from '@/functions/utils';
import { STORE_NAME } from '@/store/constants';
import { errorMessageFor, errorLabelFor } from './status-messages';

const DEFAULT_FRESH_TTL_SECONDS = 12 * 60 * 60;
const ENDPOINT = '/surerank/v1/google-search-console/url-inspection';

// Per-page in-memory cache so reopening the popup for the same post or
// term avoids a "Checking…" flash, while opening a different row never
// inherits another row's pill. Keyed by `${kind}:${id}` to isolate
// post-vs-term and prevent ID collisions across taxonomies.
const inMemoryCache = new Map();
const cacheKey = ( kind, id ) => `${ kind }:${ id }`;

const STATUS_VARIANT = {
	indexed: 'green',
	crawled_not_indexed: 'yellow',
	discovered_not_indexed: 'yellow',
	noindex: 'red',
	other_not_indexed: 'red',
};

const labelFor = ( status ) => {
	switch ( status ) {
		case 'indexed':
			return __( 'Indexed', 'surerank' );
		case 'crawled_not_indexed':
			return __( 'Crawled, not indexed', 'surerank' );
		case 'discovered_not_indexed':
			return __( 'Discovered, not indexed', 'surerank' );
		case 'noindex':
			return __( 'Noindex', 'surerank' );
		case 'other_not_indexed':
		default:
			return __( 'Not indexed', 'surerank' );
	}
};

const formatRelative = ( unixSeconds ) => {
	if ( ! unixSeconds ) {
		return '';
	}
	const diff = Math.max( 0, Math.floor( Date.now() / 1000 - unixSeconds ) );
	if ( diff < 60 ) {
		return __( 'just now', 'surerank' );
	}
	if ( diff < 3600 ) {
		const m = Math.floor( diff / 60 );
		/* translators: %d: minutes */
		return sprintf( __( '%d min ago', 'surerank' ), m );
	}
	if ( diff < 86400 ) {
		const h = Math.floor( diff / 3600 );
		/* translators: %d: hours */
		return sprintf( __( '%d h ago', 'surerank' ), h );
	}
	const d = Math.floor( diff / 86400 );
	/* translators: %d: days */
	return sprintf( __( '%d d ago', 'surerank' ), d );
};

const buildTooltipText = ( { data, errorCode, isRefreshing } ) => {
	if ( isRefreshing ) {
		return __( 'Checking indexing status…', 'surerank' );
	}
	if ( errorCode ) {
		return errorMessageFor( errorCode );
	}
	if ( ! data?.checked_at ) {
		return __( 'Checking indexing status…', 'surerank' );
	}
	const when = sprintf(
		/* translators: %s: relative time */
		__( 'Last checked %s', 'surerank' ),
		formatRelative( data.checked_at )
	);
	if ( data.coverageState ) {
		return `${ when } · ${ data.coverageState }`;
	}
	return when;
};

const IndexingStatus = () => {
	const popup = window?.surerank_seo_popup ?? {};
	const {
		is_gsc_connected: isGscConnected,
		is_gsc_site_matching: isGscSiteMatching,
		is_taxonomy: isTaxonomy,
		post_id: localizedPostId,
		term_id: localizedTermId,
		indexing_status: initialStatus,
		indexing_fresh_ttl: freshTtl = DEFAULT_FRESH_TTL_SECONDS,
	} = popup;

	const activePostId = useSelect(
		( select ) => select( STORE_NAME ).getActivePostId(),
		[]
	);
	const kind = isTaxonomy ? 'term' : 'post';
	const localizedId = isTaxonomy ? localizedTermId : localizedPostId;
	// Prefer the store's activePostId (listing page sets this per row);
	// fall back to localized data for single-post/term edit screens.
	const objectId = activePostId || localizedId || 0;
	const enabled = isGscConnected && isGscSiteMatching && !! objectId;

	// Seed from the per-page memory cache when available; otherwise from
	// localized data only when it belongs to THIS object (single-post
	// edit screen). Never inherit a cross-row payload.
	const seed = useMemo( () => {
		if ( ! objectId ) {
			return null;
		}
		const cached = inMemoryCache.get( cacheKey( kind, objectId ) );
		if ( cached ) {
			return cached;
		}
		if ( initialStatus && localizedId === objectId ) {
			return initialStatus;
		}
		return null;
		// initialStatus + localizedId are stable per page load; objectId
		// is the only meaningful change driver.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ kind, objectId ] );

	const [ data, setData ] = useState( seed );
	const [ errorCode, setErrorCode ] = useState( null );
	const [ isRefreshing, setIsRefreshing ] = useState( false );

	// When activePostId changes mid-mount (listing-page row switch
	// without a popup close), reset state to the new row's seed.
	useEffect( () => {
		setData( seed );
		setErrorCode( null );
	}, [ seed ] );

	const fetchStatus = useCallback(
		async ( { manual = false } = {} ) => {
			if ( ! enabled || ! objectId ) {
				return;
			}
			setIsRefreshing( true );
			try {
				const args = isTaxonomy
					? { term_id: objectId }
					: { post_id: objectId };
				if ( manual ) {
					args.refresh = true;
				}
				const response = await apiFetch( {
					path: addQueryArgs( ENDPOINT, args ),
				} );
				// Send_Json::error() returns HTTP 200 with a
				// { success: false, error_code } envelope, so apiFetch
				// resolves (never throws) here. Surface it as an error
				// state explicitly — otherwise the badge stays stuck on
				// "Checking…" forever.
				if (
					response?.success === false ||
					( ! response?.status && response?.error_code )
				) {
					setErrorCode(
						response?.error_code ??
							response?.code ??
							'unknown_error'
					);
					return;
				}
				if ( response?.status ) {
					setData( response );
					setErrorCode( null );
					// Per-row cache (survives popup unmount within the
					// same page session) — keyed so listing-page row
					// switches never inherit another row's payload.
					inMemoryCache.set( cacheKey( kind, objectId ), response );
				}
			} catch ( err ) {
				const code = err?.error_code || err?.code || 'unknown_error';
				setErrorCode( code );
			} finally {
				setIsRefreshing( false );
			}
		},
		[ enabled, isTaxonomy, objectId, kind ]
	);

	// Stash the latest fetcher in a ref so the mount effect can call it
	// without re-running when its dependencies change.
	const fetchStatusRef = useRef( fetchStatus );
	useEffect( () => {
		fetchStatusRef.current = fetchStatus;
	}, [ fetchStatus ] );

	useEffect( () => {
		if ( ! enabled ) {
			return;
		}
		const isStale =
			! data?.checked_at ||
			Date.now() / 1000 - data.checked_at > freshTtl;
		if ( ! data || isStale ) {
			fetchStatusRef.current();
		}
	}, [ enabled, data, freshTtl ] );

	if ( ! enabled ) {
		return null;
	}

	const status = data?.status;
	const hasError = !! errorCode;

	let variant = 'neutral';
	if ( hasError ) {
		variant = 'red';
	} else if ( status ) {
		variant = STATUS_VARIANT[ status ] || 'neutral';
	}

	let label = __( 'Checking…', 'surerank' );
	if ( hasError ) {
		label = errorLabelFor( errorCode );
	} else if ( status ) {
		label = labelFor( status );
	}

	const tooltipText = buildTooltipText( { data, errorCode, isRefreshing } );

	const handleRefresh = ( e ) => {
		e.stopPropagation();
		fetchStatus( { manual: true } );
	};

	const badgeLabel = (
		<span className="inline-flex items-center gap-1.5">
			<span>{ label }</span>
			<button
				type="button"
				onClick={ handleRefresh }
				disabled={ isRefreshing }
				aria-label={ __( 'Refresh indexing status', 'surerank' ) }
				className="inline-flex items-center justify-center p-0 m-0 bg-transparent border-0 cursor-pointer text-current opacity-80 hover:opacity-100 focus:outline-none disabled:cursor-not-allowed"
			>
				<RefreshCw
					className={ cn( 'size-3', isRefreshing && 'animate-spin' ) }
				/>
			</button>
		</span>
	);

	return (
		<SeoPopupTooltip
			content={ tooltipText }
			placement="bottom"
			arrow
			className="z-[99999]"
		>
			<span className="inline-flex rounded-full">
				<Badge
					label={ badgeLabel }
					size="xs"
					type="pill"
					variant={ variant }
					disableHover
				/>
			</span>
		</SeoPopupTooltip>
	);
};

export default memo( IndexingStatus );
