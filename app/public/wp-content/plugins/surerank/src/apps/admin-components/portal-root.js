/**
 * Resolve the element that floating-ui portals (tooltips, dropdowns, selects)
 * should render into.
 *
 * On the front end the SEO metabox mounts inside a Shadow DOM; the shadow mount
 * node is published on `window.surerankPortalRoot` by the front-end-meta-box
 * entry. `document.getElementById()` / `querySelector()` cannot see across the
 * shadow boundary, so portals must receive this element BY REFERENCE — passing
 * an `id` string makes floating-ui spawn a stray, theme-styled node in the
 * light-DOM `<body>` instead of rendering inside the shadow root.
 *
 * In the admin (no shadow root) it falls back to the light-DOM popup root so
 * portaled content stays inside the `#surerank-root`-scoped Tailwind styles.
 *
 * Shared with the Pro plugin via the `@AdminComponents` webpack alias.
 *
 * @return {Element|undefined} Portal container element, or undefined to let
 *                             floating-ui fall back to its own default.
 */
export const getPortalRoot = () => {
	const published =
		typeof window !== 'undefined' ? window.surerankPortalRoot : null;
	// nodeType === 1 is ELEMENT_NODE (avoids referencing the `Node` global,
	// which is not whitelisted in this project's ESLint config).
	if ( published && published.nodeType === 1 ) {
		return published;
	}

	return (
		document.getElementById( 'surerank-root' ) ||
		document.querySelector( '.surerank-root' ) ||
		undefined
	);
};
