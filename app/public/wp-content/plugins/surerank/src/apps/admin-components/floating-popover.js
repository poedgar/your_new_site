import { useState, cloneElement } from '@wordpress/element';
import {
	useFloating,
	autoUpdate,
	offset as offsetMiddleware,
	flip,
	shift,
	size,
	useClick,
	useDismiss,
	useRole,
	useInteractions,
	FloatingPortal,
	FloatingFocusManager,
} from '@floating-ui/react';
import { cn } from '@Functions/utils';
import { getPortalRoot } from '@AdminComponents/portal-root';

/**
 * Viewport-aware floating popover anchored to a trigger element.
 *
 * Positioning is collision-aware: flip() picks the side of the trigger with
 * more room, shift() slides the popover over the trigger when neither side
 * fully fits, and size() caps its height to the viewport as a last resort
 * (the popover scrolls internally only when the viewport itself is shorter
 * than its content). Content renders through a portal into getPortalRoot()
 * so it escapes overflow clipping and works inside the front-end metabox
 * Shadow DOM.
 *
 * Open state is uncontrolled by default (trigger click toggles, outside
 * click / Escape dismiss). Pass `open` + `onOpenChange` to control it, e.g.
 * to close from an Apply/Cancel button inside the popover.
 *
 * @param {Object}   props              Component props.
 * @param {Object}   props.trigger      Single element the popover anchors to.
 *                                      It is cloned with the floating ref and
 *                                      interaction props, so it must forward
 *                                      ref to a DOM node.
 * @param {Object}   props.children     Popover content.
 * @param {boolean}  props.open         Controlled open state.
 * @param {Function} props.onOpenChange Controlled open-state setter.
 * @param {string}   props.placement    Preferred floating-ui placement.
 * @param {number}   props.offset       Gap between trigger and popover in px.
 * @param {number}   props.padding      Minimum gap to the viewport edges in px.
 * @param {string}   props.role         ARIA role of the popover.
 * @param {string}   props.className    Extra classes for the popover container.
 */
const FloatingPopover = ( {
	trigger,
	children,
	open: controlledOpen,
	onOpenChange,
	placement = 'bottom-end',
	offset = 6,
	padding = 16,
	role = 'dialog',
	className,
} ) => {
	const [ uncontrolledOpen, setUncontrolledOpen ] = useState( false );
	const isOpen = controlledOpen ?? uncontrolledOpen;
	const setIsOpen = onOpenChange ?? setUncontrolledOpen;

	const { refs, floatingStyles, context } = useFloating( {
		open: isOpen,
		onOpenChange: setIsOpen,
		placement,
		strategy: 'fixed',
		middleware: [
			offsetMiddleware( offset ),
			flip( { padding } ),
			shift( { crossAxis: true, padding } ),
			size( {
				padding,
				apply( { availableHeight, elements } ) {
					elements.floating.style.maxHeight = `${ availableHeight }px`;
				},
			} ),
		],
		whileElementsMounted: autoUpdate,
	} );
	const { getReferenceProps, getFloatingProps } = useInteractions( [
		useClick( context ),
		useDismiss( context ),
		useRole( context, { role } ),
	] );

	return (
		<>
			{ cloneElement( trigger, {
				ref: refs.setReference,
				...getReferenceProps(),
			} ) }
			{ isOpen && (
				<FloatingPortal root={ getPortalRoot() }>
					<FloatingFocusManager context={ context } modal={ false }>
						<div
							ref={ refs.setFloating }
							style={ floatingStyles }
							className={ cn(
								'z-[99999] overflow-y-auto rounded-lg shadow-lg bg-background-primary [scrollbar-width:none] [&::-webkit-scrollbar]:hidden',
								className
							) }
							{ ...getFloatingProps() }
						>
							{ children }
						</div>
					</FloatingFocusManager>
				</FloatingPortal>
			) }
		</>
	);
};

export default FloatingPopover;
