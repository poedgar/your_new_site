import {
	createContext,
	memo,
	useContext,
	useMemo,
	isValidElement,
	Children,
	cloneElement,
} from '@wordpress/element';
import { motion, LayoutGroup } from 'framer-motion';
import { classNames } from '../../utils/functions';

const ButtonGroupContext = createContext( {} );
ButtonGroupContext.displayName = 'ButtonGroupContext';
const useButtonGroupState = () => useContext( ButtonGroupContext );

const nonElementChildrenTypes = [ 'string', 'number' ];

/**
 * Segmented button control.
 *
 * @since x.x.x
 * @param {Object}   props          Component props.
 * @param {Object}   props.value    Currently selected item.
 * @param {string}   props.by       Key used to compare items.
 * @param {Function} props.onChange Selection change handler.
 * @param {any}      props.children Button items.
 */
function ButtonGroup( { value, by = 'id', onChange, children } ) {
	const layoutGroupId = useMemo(
		() => Math.random().toString( 16 ).substring( 3 ),
		[]
	);

	const renderChildren = Children.map( children, ( child, index ) => {
		if ( isValidElement( child ) ) {
			return cloneElement( child, { index } );
		}
		return child;
	} );

	const handleChange = ( newValue ) => () => {
		if ( typeof onChange !== 'function' ) {
			return;
		}

		onChange( newValue );
	};

	const contextValue = useMemo(
		() => ( {
			onChange: handleChange,
			selectedValue: value,
			by,
			lastItemIndex: Children.count( children ) - 1,
		} ),
		[ onChange, value, children ]
	);

	return (
		<div className="isolate inline-flex rounded-md shadow-sm border border-solid border-border-primary divide-solid divide-x divide-border-primary">
			<LayoutGroup id={ `button-group-${ layoutGroupId }` }>
				<ButtonGroupContext.Provider value={ contextValue }>
					{ renderChildren }
				</ButtonGroupContext.Provider>
			</LayoutGroup>
		</div>
	);
}
ButtonGroup = memo( ButtonGroup );

ButtonGroup.ButtonItem = ( {
	children,
	value,
	className,
	index,
	...props
} ) => {
	const { onChange, lastItemIndex, selectedValue, by } =
		// eslint-disable-next-line react-hooks/rules-of-hooks
		useButtonGroupState();
	const isSelected = selectedValue?.[ by ] === value?.[ by ];

	const renderChildren = Children.map( children, ( child ) => {
		if ( nonElementChildrenTypes.includes( typeof child ) ) {
			return <span className="z-10">{ children }</span>;
		}
		if ( isValidElement( child ) ) {
			const existingClassName = child.props.className;
			return cloneElement( child, {
				className: classNames( 'z-10', existingClassName ),
			} );
		}
		return child;
	} );

	return (
		<button
			type="button"
			className={ classNames(
				index === 0 && 'rounded-l-md',
				index === lastItemIndex && 'rounded-r-md',
				index !== 0 && '-ml-px',
				'relative w-auto h-auto flex justify-center items-center bg-white p-2 text-sm font-normal text-zip-app-inactive-icon focus:outline-none focus-visible:outline-none border-0 shadow-sm cursor-pointer active:outline-none z-auto transition-colors ease-out duration-[250ms]',
				isSelected && 'text-zip-app-heading cursor-default z-[1]',
				isSelected && index <= lastItemIndex && '!border-transparent',
				className
			) }
			onClick={ onChange( value ) }
			{ ...props }
		>
			{ renderChildren }
			{ isSelected && (
				<motion.span
					className="bg-background-secondary rounded absolute inset-0 z-0"
					layoutId="active-mode"
					layoutDependency={ value }
					transition={ {
						layout: {
							duration: 0.25,
							ease: 'easeOut',
						},
					} }
				/>
			) }
		</button>
	);
};
ButtonGroup.ButtonItem = memo( ButtonGroup.ButtonItem );

export default ButtonGroup;
