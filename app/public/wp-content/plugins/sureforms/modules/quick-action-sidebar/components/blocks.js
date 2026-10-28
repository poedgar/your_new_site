/**
 * Creates sidebar blocks.
 */
import { useSelect } from '@wordpress/data';
import { createBlock, getBlockTypes } from '@wordpress/blocks';
import DraggableBlock from './draggable-block';
import DragAndDropComponent from './move-up-down';

// Stable reference so `useSelect` doesn't return a fresh empty array on every
// call when no allowed blocks are resolved, which would trigger needless re-renders.
const EMPTY_ALLOWED_BLOCKS = [];

const Blocks = ( {
	defaultAllowedQuickSidebarBlocks,
	updateDefaultAllowedQuickSidebarBlocks,
	saveOptionToDatabase,
	enableRearrange,
} ) => {
	const blocks = getBlockTypes();
	const {
		blockInsertionPoint,
		getBlockRootClientId,
		getSelectedBlockAllowedBlocks,
		getSelectedBlockClientId,
	} = useSelect( ( select ) => {
		const blockEditor = select( 'core/block-editor' );
		const { index } = blockEditor.getBlockInsertionPoint();
		const clientId = blockEditor.getSelectedBlockClientId();
		const rootClientId = blockEditor.getBlockRootClientId( clientId );
		const allowedBlocks = blockEditor.getAllowedBlocks( clientId );
		return {
			blockInsertionPoint: index,
			getBlockRootClientId: rootClientId,
			getSelectedBlockClientId: clientId,
			getSelectedBlockAllowedBlocks: allowedBlocks || EMPTY_ALLOWED_BLOCKS,
		};
	}, [] );
	const srfmBlocks = blocks.filter( ( block ) => {
		return defaultAllowedQuickSidebarBlocks.includes( block.name );
	} );
	const create = ( name ) => {
		return createBlock( name );
	};

	// Loop through each object and add id
	srfmBlocks.forEach( ( item, index ) => {
		item.id = `${ index + 1 }`;
	} );

	const sortedY = defaultAllowedQuickSidebarBlocks
		.filter( ( item ) => item !== undefined && item !== null )
		.map( ( item ) => srfmBlocks.find( ( { name } ) => name === item ) )
		.filter( ( item ) => item !== undefined ); // Remove undefined objects

	return (
		<>
			{ ! enableRearrange &&
				sortedY.map( ( block, index ) => (
					<DraggableBlock
						key={ index }
						id={ index }
						{ ...{
							block,
							create,
							blockInsertionPoint,
							getBlockRootClientId,
							getSelectedBlockClientId,
							getSelectedBlockAllowedBlocks,
							defaultAllowedQuickSidebarBlocks,
							updateDefaultAllowedQuickSidebarBlocks,
							saveOptionToDatabase,
						} }
					/>
				) ) }
			{ enableRearrange && (
				<DragAndDropComponent
					initialItems={ sortedY }
					updateDefaultAllowedQuickSidebarBlocks={
						updateDefaultAllowedQuickSidebarBlocks
					}
					saveOptionToDatabase={ saveOptionToDatabase }
				/>
			) }
		</>
	);
};

export default Blocks;
