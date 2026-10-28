import { useState, useCallback, useRef } from '@wordpress/element';

/**
 * Persist a piece of React state in localStorage.
 *
 * State is lazily initialized from the stored value (parsed as JSON) and falls
 * back to `defaultValue` when the key is absent or the stored value is malformed.
 * All localStorage access is wrapped in try/catch so private mode, quota errors
 * or a missing `window` (SSR) never throw.
 *
 * The next value is computed synchronously (from a ref, not from React's
 * deferred state updater) so the localStorage write always reflects the real
 * next value, even when `setState` is called from a React event handler where
 * state updates are batched.
 *
 * @param {string} key          The localStorage key to read from and write to.
 * @param {*}      defaultValue Value returned when nothing valid is stored.
 *
 * @return {Array} `[ state, setState ]` where `setState` accepts a value or an
 *                 updater function (like React's setState) and returns the next value.
 */
const useLocalStorageState = ( key, defaultValue ) => {
	const [ state, setStateInternal ] = useState( () => {
		if ( typeof window === 'undefined' ) {
			return defaultValue;
		}

		try {
			const stored = window.localStorage.getItem( key );
			if ( stored === null ) {
				return defaultValue;
			}
			return JSON.parse( stored );
		} catch ( error ) {
			// Missing localStorage or malformed JSON, fall back to the default.
			return defaultValue;
		}
	} );

	// Mirror of the latest value so `setState` can resolve an updater
	// synchronously without depending on React's deferred state update.
	const stateRef = useRef( state );
	stateRef.current = state;

	const setState = useCallback(
		( valueOrUpdater ) => {
			const nextValue =
				typeof valueOrUpdater === 'function'
					? valueOrUpdater( stateRef.current )
					: valueOrUpdater;

			// Keep the ref current so back-to-back updater calls in the same
			// tick build on each other rather than on a stale value.
			stateRef.current = nextValue;

			try {
				if ( typeof window !== 'undefined' ) {
					window.localStorage.setItem(
						key,
						JSON.stringify( nextValue )
					);
				}
			} catch ( error ) {
				// Silently ignore write failures (private mode / quota).
			}

			setStateInternal( nextValue );

			return nextValue;
		},
		[ key ]
	);

	return [ state, setState ];
};

export default useLocalStorageState;
