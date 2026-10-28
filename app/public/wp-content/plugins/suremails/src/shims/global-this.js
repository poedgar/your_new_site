// CSP-safe replacement for `lodash/_root.js` and `core-js/internals/global-this.js`.
// Both upstream files use `Function('return this')()` as a final fallback, which
// strict CSP (`script-src` without `'unsafe-eval'`) blocks — the bundle then
// fails to evaluate and the dashboard renders blank. Wired up via
// NormalModuleReplacementPlugin in webpack.config.js.
//
// `globalThis` covers every modern browser WordPress admin supports, but we
// keep the typeof checks so the shim is resilient if the bundle is ever
// evaluated in an environment where `globalThis` is unavailable.
/* global globalThis, self */
function getGlobal() {
	if ( typeof globalThis !== 'undefined' ) {
		return globalThis;
	}
	if ( typeof self !== 'undefined' ) {
		return self;
	}
	if ( typeof window !== 'undefined' ) {
		return window;
	}
	if ( typeof global !== 'undefined' ) {
		return global;
	}
	return {};
}

module.exports = getGlobal();
