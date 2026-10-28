import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { toast } from '@bsf/force-ui';
import { getLearnChapters } from './learn-config';

const SYNC_EVENT = 'surerank:learn-progress-updated';

const initialFromWindow = () => {
	const seeded = window?.surerank_admin_common?.learn_progress;
	if ( seeded && typeof seeded === 'object' && seeded.chapters ) {
		return seeded;
	}
	return { version: 1, chapters: {} };
};

const initialAutoDetected = () => {
	const seeded = window?.surerank_admin_common?.learn_auto_detected;
	if ( seeded && typeof seeded === 'object' ) {
		return seeded;
	}
	return {};
};

const writeSeed = ( next ) => {
	if ( ! window.surerank_admin_common ) {
		return;
	}
	window.surerank_admin_common.learn_progress = next;
};

const writeAutoDetectedSeed = ( next ) => {
	if ( ! window.surerank_admin_common ) {
		return;
	}
	window.surerank_admin_common.learn_auto_detected = next;
};

// `Send_Json::success` flattens the data into the response root via
// `wp_send_json`, so progress is at `res.progress`, not `res.data.progress`.
const extractProgress = ( res ) => {
	if ( ! res || res.success !== true ) {
		return null;
	}
	if ( res.progress && typeof res.progress === 'object' ) {
		return res.progress;
	}
	if ( res.data?.progress && typeof res.data.progress === 'object' ) {
		return res.data.progress;
	}
	return null;
};

const extractAutoDetected = ( res ) => {
	if ( ! res || res.success !== true ) {
		return null;
	}
	if ( res.auto_detected && typeof res.auto_detected === 'object' ) {
		return res.auto_detected;
	}
	if (
		res.data?.auto_detected &&
		typeof res.data.auto_detected === 'object'
	) {
		return res.data.auto_detected;
	}
	return null;
};

const useLearnProgress = () => {
	const [ progress, setProgress ] = useState( initialFromWindow );
	const [ autoDetected, setAutoDetected ] = useState( initialAutoDetected );
	const chapters = getLearnChapters();

	const applyProgress = useCallback( ( next, broadcast = false ) => {
		setProgress( next );
		writeSeed( next );
		if ( broadcast && typeof window.CustomEvent === 'function' ) {
			window.dispatchEvent(
				new window.CustomEvent( SYNC_EVENT, {
					detail: { progress: next },
				} )
			);
		}
	}, [] );

	const applyAutoDetected = useCallback( ( next ) => {
		setAutoDetected( next );
		writeAutoDetectedSeed( next );
	}, [] );

	// Refetch from server on mount so revisits and route re-entries show fresh data.
	useEffect( () => {
		let cancelled = false;
		apiFetch( { path: '/surerank/v1/learn-progress' } )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				const nextProgress = extractProgress( res );
				if ( nextProgress ) {
					applyProgress( nextProgress, false );
				}
				const nextAuto = extractAutoDetected( res );
				if ( nextAuto ) {
					applyAutoDetected( nextAuto );
				}
			} )
			.catch( () => {
				// Silent: keep seeded state if fetch fails.
			} );
		return () => {
			cancelled = true;
		};
	}, [ applyProgress, applyAutoDetected ] );

	// Listen for sibling hook instances broadcasting updates within the same page.
	useEffect( () => {
		const handler = ( event ) => {
			const next = event?.detail?.progress;
			if ( next && typeof next === 'object' ) {
				setProgress( next );
			}
		};
		window.addEventListener( SYNC_EVENT, handler );
		return () => {
			window.removeEventListener( SYNC_EVENT, handler );
		};
	}, [] );

	const isStepAutoDetected = useCallback(
		( chapterId, stepId ) =>
			Boolean( autoDetected?.[ chapterId ]?.[ stepId ] ),
		[ autoDetected ]
	);

	const isStepComplete = useCallback(
		( chapterId, stepId ) => {
			if ( isStepAutoDetected( chapterId, stepId ) ) {
				return true;
			}
			return Boolean( progress?.chapters?.[ chapterId ]?.[ stepId ] );
		},
		[ progress, isStepAutoDetected ]
	);

	const markStep = useCallback(
		async ( chapterId, stepId, done ) => {
			// Auto-detected steps cannot be manually toggled.
			if ( isStepAutoDetected( chapterId, stepId ) ) {
				return;
			}

			// Locked steps (Pro cards for free users) can't be toggled.
			const targetStep = chapters
				.find( ( c ) => c.id === chapterId )
				?.steps.find( ( s ) => s.id === stepId );
			if ( targetStep?.locked ) {
				return;
			}

			const currentlyDone = Boolean(
				progress?.chapters?.[ chapterId ]?.[ stepId ]
			);
			if ( currentlyDone === Boolean( done ) ) {
				return;
			}

			const previous = progress;

			// Optimistic update.
			setProgress( ( prev ) => {
				const next = {
					...prev,
					chapters: { ...( prev.chapters || {} ) },
				};
				const chapter = { ...( next.chapters[ chapterId ] || {} ) };
				if ( done ) {
					chapter[ stepId ] = {
						completed_at: Math.floor( Date.now() / 1000 ),
					};
					next.chapters[ chapterId ] = chapter;
				} else {
					delete chapter[ stepId ];
					if ( Object.keys( chapter ).length === 0 ) {
						delete next.chapters[ chapterId ];
					} else {
						next.chapters[ chapterId ] = chapter;
					}
				}
				return next;
			} );

			try {
				const response = await apiFetch( {
					path: '/surerank/v1/learn-progress',
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						chapter_id: chapterId,
						step_id: stepId,
						completed: Boolean( done ),
					} ),
				} );

				const nextProgress = extractProgress( response );
				if ( nextProgress ) {
					applyProgress( nextProgress, true );
				}
				const nextAuto = extractAutoDetected( response );
				if ( nextAuto ) {
					applyAutoDetected( nextAuto );
				}
			} catch ( err ) {
				setProgress( previous );
				writeSeed( previous );
				toast.error(
					__( 'Could not update Learn progress.', 'surerank' ),
					{
						description:
							err?.message ||
							__( 'Please try again.', 'surerank' ),
					}
				);
			}
		},
		[
			chapters,
			progress,
			isStepAutoDetected,
			applyProgress,
			applyAutoDetected,
		]
	);

	const getChapterStats = useCallback(
		( chapterId ) => {
			const chapter = chapters.find( ( c ) => c.id === chapterId );
			if ( ! chapter ) {
				return { done: 0, total: 0 };
			}
			// Locked steps (e.g. Pro cards for free users) aren't tracked.
			// Unlocked Pro steps count just like free steps.
			const trackable = chapter.steps.filter( ( s ) => ! s.locked );
			const total = trackable.length;
			const done = trackable.filter( ( s ) =>
				isStepComplete( chapterId, s.id )
			).length;
			return { done, total };
		},
		[ chapters, isStepComplete ]
	);

	const getOverallStats = useCallback( () => {
		let done = 0;
		let total = 0;
		chapters.forEach( ( chapter ) => {
			const stats = getChapterStats( chapter.id );
			done += stats.done;
			total += stats.total;
		} );
		const pct = total === 0 ? 0 : Math.round( ( done / total ) * 100 );
		return { done, total, pct };
	}, [ chapters, getChapterStats ] );

	return {
		chapters,
		isStepComplete,
		isStepAutoDetected,
		markStep,
		getChapterStats,
		getOverallStats,
	};
};

export default useLearnProgress;
