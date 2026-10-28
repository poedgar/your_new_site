export const parseContent = ( content ) => {
	const parser = new DOMParser();
	return parser.parseFromString( content, 'text/html' );
};

/**
 * Get the root node the page checks should query against.
 *
 * Raw post content is blind to dynamic blocks (their save() returns null, so
 * the markup is only a block comment with JSON attributes — no <img>, <a>,
 * headings, etc.). The block editor canvas renders those blocks live, so when
 * it is available we query it instead of parsing the raw content.
 *
 * @param {string} content Raw post content, used as fallback.
 * @return {Document|Element} Node supporting querySelectorAll for the checks.
 */
export const getContentRoot = ( content ) => {
	return getEditorCanvasRoot() || parseContent( content );
};

/**
 * Get the live block editor canvas root, or null outside the block editor
 * (or before the canvas has mounted).
 *
 * @return {Element|null} The canvas content wrapper.
 */
export const getEditorCanvasRoot = () => {
	if ( window?.surerank_seo_popup?.editor_type !== 'block' ) {
		return null;
	}

	// The canvas is iframed by default; with meta boxes it renders inline.
	const canvasDocument =
		document.querySelector( 'iframe[name="editor-canvas"]' )
			?.contentDocument || document;
	return canvasDocument.querySelector( '.editor-styles-wrapper' );
};

/**
 * Cheap fingerprint of the canvas elements the checks care about. Dynamic
 * blocks render asynchronously (after the first checks run), so this is
 * compared between runs to detect canvas changes that don't change the raw
 * post content.
 *
 * @return {string} Signature, empty outside the block editor canvas.
 */
export const getContentSignature = () => {
	const root = getEditorCanvasRoot();
	if ( ! root ) {
		return '';
	}

	return [ 'img', 'video', 'a[href]', 'h2, h3, h4, h5, h6' ]
		.map( ( selector ) => root.querySelectorAll( selector ).length )
		.join( ':' );
};
