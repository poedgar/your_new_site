/**
 * First-run editor guided tour (Gutenberg + classic editor).
 *
 * Renders nothing; on mount it launches the shared tour against the default
 * Gutenberg/classic trigger selectors. The page-builder integrations call
 * startEditorTour() directly with their own trigger. See start-tour.js.
 */
import { useEffect } from '@wordpress/element';
import { startEditorTour } from './start-tour';

const EditorTour = () => {
	useEffect( () => {
		startEditorTour();
	}, [] );

	return null;
};

export default EditorTour;
