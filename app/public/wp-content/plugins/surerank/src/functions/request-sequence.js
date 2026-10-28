/**
 * Per-key request counters for async responses that can arrive out of order.
 *
 * A caller takes an ID when it starts a request and applies the response only if that ID
 * is still the latest for the key, so an older response can never replace a newer one.
 */
const sequences = {};

/**
 * Start a request for a key and return its ID.
 *
 * @since x.x.x
 * @param {string} key Request group, e.g. 'seo-meta'.
 * @return {number} Request ID.
 */
export const startRequest = ( key ) => {
	sequences[ key ] = ( sequences[ key ] || 0 ) + 1;
	return sequences[ key ];
};

/**
 * Whether a request is still the latest one started for its key.
 *
 * @since x.x.x
 * @param {string} key       Request group.
 * @param {number} requestId ID returned by startRequest().
 * @return {boolean} True when no newer request has started.
 */
export const isLatestRequest = ( key, requestId ) =>
	( sequences[ key ] || 0 ) === requestId;

/**
 * The latest request ID for a key, without starting a new request.
 *
 * Lets a follow-up (such as a refresh after saving) yield to any request started after it
 * without invalidating requests already in flight.
 *
 * @since x.x.x
 * @param {string} key Request group.
 * @return {number} Latest request ID, or 0 when none has started.
 */
export const getLatestRequest = ( key ) => sequences[ key ] || 0;
