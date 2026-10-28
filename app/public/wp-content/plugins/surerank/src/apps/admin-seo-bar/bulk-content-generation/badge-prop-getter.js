import { __ } from '@wordpress/i18n';
import { Check, LoaderCircle, TriangleAlert } from 'lucide-react';

// Configuration for action types and their labels
const ACTION_CONFIG = {
	description: {
		generating: __( 'Description Generating', 'surerank' ),
		generated: __( 'Description Generated', 'surerank' ),
	},
	title: {
		generating: __( 'Title Generating', 'surerank' ),
		generated: __( 'Title Generated', 'surerank' ),
	},
};

// Helper function to get action type from action string
const getActionType = ( action ) => {
	if ( action?.includes( 'description' ) ) {
		return 'description';
	}
	if ( action?.includes( 'title' ) ) {
		return 'title';
	}
	return null;
};

// Helper function to create status props
const createStatusProps = (
	icon,
	variant,
	label,
	requiresPro = false,
	message = ''
) => ( {
	icon,
	variant,
	label,
	requiresPro,
	message,
} );

/**
 * Read a failure entry from the batch response.
 *
 * The server sends { message, code, upgrade_required } per failed ID and decides
 * itself whether an upgrade resolves the code, so nothing here needs to know the
 * code vocabulary. Older batches stored a bare message string.
 *
 * @param {Object|string} failure Failure entry for a single ID.
 * @return {{message: string, upgradeRequired: boolean}} Normalised failure.
 */
const readFailure = ( failure ) => {
	if ( typeof failure === 'string' ) {
		return { message: failure, upgradeRequired: false };
	}

	return {
		message: failure?.message ?? '',
		upgradeRequired: failure?.upgrade_required === true,
	};
};

// Helper function to check if post is in batch generation
const getBatchGenerationStatusProps = ( postId, batchGeneration ) => {
	if ( ! batchGeneration || ! postId ) {
		return null;
	}

	const { response, action } = batchGeneration;
	const actionType = getActionType( action );

	// Check if this post is in pending_ids (being generated)
	if ( response?.pending_ids?.includes( postId ) && actionType ) {
		return createStatusProps(
			<LoaderCircle className="animate-spin" />,
			'blue',
			ACTION_CONFIG[ actionType ].generating
		);
	}

	// Check if this post is in processed_ids (completed)
	if ( response?.processed_ids?.includes( postId ) && actionType ) {
		return createStatusProps(
			<Check />,
			'green',
			ACTION_CONFIG[ actionType ].generated
		);
	}

	// Check if this post is in failed_ids
	if ( response?.failed_ids?.hasOwnProperty( postId ) ) {
		const { message, upgradeRequired } = readFailure(
			response.failed_ids[ postId ]
		);

		return createStatusProps(
			<TriangleAlert />,
			'red',
			__( 'Generation Failed', 'surerank' ),
			upgradeRequired,
			message
		);
	}

	return null;
};

export default getBatchGenerationStatusProps;
