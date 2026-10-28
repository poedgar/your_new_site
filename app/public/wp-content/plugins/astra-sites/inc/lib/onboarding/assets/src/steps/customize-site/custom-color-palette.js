import { useEffect, useReducer, useRef } from '@wordpress/element';
import { sprintf, __ } from '@wordpress/i18n';
import tinyColor from 'tinycolor2';
import { useStateValue } from '../../store/store';
import {
	sendPostMessage as dispatchPostMessage,
	getDefaultColorPalette,
	getColorScheme,
	classNames,
} from '../../utils/functions';
import { getURLParmsValue } from '../../utils/url-params';
import ColorPicker from '../../components/color-picker';
import ButtonGroup from '../../components/button-group';

const colorSchemes = [
	{
		id: 'light',
		name: __( 'Light', 'astra-sites' ),
	},
	{
		id: 'dark',
		name: __( 'Dark', 'astra-sites' ),
	},
];

const backgroundSaturations = [
	{
		id: 'muted',
		name: __( 'Muted', 'astra-sites' ),
	},
	{
		id: 'normal',
		name: __( 'Normal', 'astra-sites' ),
	},
	{
		id: 'vibrant',
		name: __( 'Vibrant', 'astra-sites' ),
	},
];

const brightnessLevels = [
	{
		id: 1,
		name: 1,
	},
	{
		id: 2,
		name: 2,
	},
	{
		id: 3,
		name: 3,
	},
];

const generateColorPalette = ( color, scheme, bgSaturation, brightness ) => {
	const primaryColor = tinyColor( color.hex );
	const white = tinyColor( '#ffffff' );

	let brightnessLevel = 0;
	switch ( brightness.id ) {
		case 1:
			brightnessLevel = 25;
			break;
		case 2:
			brightnessLevel = 35;
			break;
		case 3:
			brightnessLevel = 45;
			break;
		default:
			brightnessLevel = 0;
			break;
	}

	let bgSaturationLevel = 0;
	switch ( bgSaturation.id ) {
		case 'muted':
			bgSaturationLevel = 100;
			break;
		case 'normal':
			bgSaturationLevel = 20;
			break;
		case 'vibrant':
			bgSaturationLevel = 0;
			break;
		default:
			bgSaturationLevel = 0;
			break;
	}

	let colorPalette;
	if ( scheme?.id === 'dark' ) {
		colorPalette = [
			primaryColor.toHexString(),
			primaryColor.clone().darken( 15 ).toHexString(),
			white.toHexString(),
			white.clone().darken( 4 ).toHexString(),
			primaryColor
				.clone()
				.desaturate( bgSaturationLevel )
				.darken( 65 - brightnessLevel )
				.toHexString(),
			primaryColor
				.clone()
				.desaturate( bgSaturationLevel )
				.darken( 80 - brightnessLevel )
				.toHexString(),
			white.clone().darken( 65 ).toHexString(),
			primaryColor
				.clone()
				.desaturate( bgSaturationLevel )
				.darken( 85 - brightnessLevel )
				.toHexString(),
			white.clone().darken( 85 ).toHexString(),
		];
	} else {
		colorPalette = [
			primaryColor.toHexString(),
			primaryColor.clone().darken( 15 ).toHexString(),
			primaryColor
				.clone()
				.desaturate( bgSaturationLevel )
				.darken( 80 )
				.toHexString(),
			primaryColor
				.clone()
				.desaturate( bgSaturationLevel )
				.darken( 65 )
				.toHexString(),
			primaryColor
				.clone()
				.lighten( 40 )
				.desaturate( bgSaturationLevel )
				.toHexString(),
			white.toHexString(),
			primaryColor.clone().lighten( 38 ).toHexString(),
			primaryColor
				.clone()
				.desaturate( bgSaturationLevel )
				.darken( 85 )
				.toHexString(),
			white.clone().darken( 85 ).toHexString(),
		];
	}

	return colorPalette;
};

const sendPostMessage = ( data ) => {
	dispatchPostMessage( data, 'astra-starter-templates-preview' );
};

/**
 * Custom color palette generator for the classic import flow.
 *
 * Generates a full 9-color Astra global palette from a single primary
 * color and applies it to the live preview + import via `activePalette`.
 *
 * @since x.x.x
 */
const CustomColorPalette = () => {
	const [ { activePalette, templateResponse }, dispatch ] = useStateValue();
	const deeplinkApplied = useRef( false );
	const prevActiveSlug = useRef( activePalette?.slug );

	const defaultPalette = getDefaultColorPalette( templateResponse );
	const defaultPrimary = defaultPalette?.[ 0 ]?.colors?.[ 0 ] || '#3858E9';
	const defaultScheme =
		getColorScheme( templateResponse ) === 'dark'
			? colorSchemes[ 1 ]
			: colorSchemes[ 0 ];

	const [ customColorState, setCustomColorState ] = useReducer(
			( state, newState ) => ( { ...state, ...newState } ),
			{
				color: { hex: defaultPrimary },
				scheme: defaultScheme,
				backgroundSaturation: backgroundSaturations[ 0 ],
				brightnessLevel: brightnessLevels[ 0 ],
			}
		),
		{ color, scheme, backgroundSaturation, brightnessLevel } =
			customColorState;

	const isCustomActive = activePalette?.slug === 'custom';

	// Apply a custom palette to the preview + reducer state.
	const applyCustomPalette = ( nextState ) => {
		const palette = generateColorPalette(
			nextState.color,
			nextState.scheme,
			nextState.backgroundSaturation,
			nextState.brightnessLevel
		);
		sendPostMessage( {
			param: 'colorPalette',
			data: { colors: palette },
		} );
		dispatch( {
			type: 'set',
			activePalette: {
				slug: 'custom',
				title: __( 'Custom', 'astra-sites' ),
				colors: palette,
			},
		} );
	};

	const setColor = ( colorValue ) => {
		setCustomColorState( { color: colorValue } );
		applyCustomPalette( { ...customColorState, color: colorValue } );
	};

	const setColorScheme = ( schemeValue ) => {
		setCustomColorState( { scheme: schemeValue } );
		applyCustomPalette( { ...customColorState, scheme: schemeValue } );
	};

	const setBackgroundSaturation = ( bgSaturationValue ) => {
		setCustomColorState( { backgroundSaturation: bgSaturationValue } );
		applyCustomPalette( {
			...customColorState,
			backgroundSaturation: bgSaturationValue,
		} );
	};

	const setBrightnessLevel = ( brightnessValue ) => {
		setCustomColorState( { brightnessLevel: brightnessValue } );
		applyCustomPalette( {
			...customColorState,
			brightnessLevel: brightnessValue,
		} );
	};

	const isPassedAccessibility = ( colorValue, colorScheme ) => {
		const hexValue = tinyColor( colorValue ).toHexString();
		if ( colorScheme === 'light' ) {
			return tinyColor.isReadable( hexValue, '#FFFFFF' );
		}
		return tinyColor.isReadable( hexValue, '#000000' );
	};

	// When the active palette moves away from the custom one — because a preset
	// was picked or the reset control was used — restore the custom picker's
	// controls to the template defaults so they reflect the reset.
	useEffect( () => {
		const currentSlug = activePalette?.slug;
		const wasCustom = prevActiveSlug.current === 'custom';
		prevActiveSlug.current = currentSlug;

		if ( wasCustom && currentSlug !== 'custom' ) {
			setCustomColorState( {
				color: { hex: defaultPrimary },
				scheme: defaultScheme,
				backgroundSaturation: backgroundSaturations[ 0 ],
				brightnessLevel: brightnessLevels[ 0 ],
			} );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ activePalette?.slug ] );

	// Deeplink: apply `?st-color=<hex>` once when the preview screen loads.
	useEffect( () => {
		if ( ! templateResponse || deeplinkApplied.current ) {
			return;
		}

		const rawColor = getURLParmsValue( window.location.search, 'st-color' );
		if ( ! rawColor ) {
			return;
		}

		const hex = rawColor.startsWith( '#' ) ? rawColor : `#${ rawColor }`;
		const parsed = tinyColor( hex );
		if ( ! parsed.isValid() ) {
			return;
		}

		deeplinkApplied.current = true;
		const colorValue = { hex: parsed.toHexString() };
		setCustomColorState( { color: colorValue } );
		applyCustomPalette( { ...customColorState, color: colorValue } );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ templateResponse ] );

	return (
		<div className="space-y-4">
			<div className="space-y-3">
				{ /* Primary Color */ }
				<div className="flex items-center justify-between gap-3">
					<span className="text-zip-app-heading text-sm font-semibold">
						{ __( 'Custom Color', 'astra-sites' ) }
					</span>
					<ColorPicker onChange={ setColor } value={ color }>
						<div
							className={ classNames(
								'w-[30px] h-[20px] rounded border border-solid border-border-primary',
								isCustomActive &&
									'outline-1 outline outline-offset-2 outline-accent-st-secondary'
							) }
							style={ { background: color.hex } }
						/>
					</ColorPicker>
				</div>
				{ /* Contrast ratio warning */ }
				{ ! isPassedAccessibility( color.hex, scheme.id ) && (
					<div className="px-3 py-2 bg-background-secondary rounded">
						<p className="!text-xs !font-normal !text-body-text">
							{ sprintf(
								/* translators: %1$s: light or dark, %2$s: brighter or darker */
								__(
									'This color is not suitable for reading on %1$s backgrounds. Consider making it slightly %2$s.',
									'astra-sites'
								),
								scheme.id === 'dark' ? 'dark' : 'light',
								scheme.id === 'dark' ? 'brighter' : 'darker'
							) }
						</p>
					</div>
				) }
				{ /* Colors preview */ }
				<div className="w-full h-[25px] grid grid-cols-9 auto-rows-auto border border-solid border-border-primary rounded overflow-clip">
					{ generateColorPalette(
						color,
						scheme,
						backgroundSaturation,
						brightnessLevel
					).map( ( colorValue, indx ) => (
						<div
							key={ `${ indx }-${ colorValue }` }
							className="w-full h-full"
							style={ { background: colorValue } }
						/>
					) ) }
				</div>
			</div>
			<div className="space-y-3">
				{ /* Style */ }
				<div className="flex items-center justify-between gap-3">
					<span className="text-zip-app-heading text-sm font-normal">
						{ __( 'Style', 'astra-sites' ) }
					</span>
					<ButtonGroup onChange={ setColorScheme } value={ scheme }>
						{ colorSchemes.map( ( schemeItem ) => (
							<ButtonGroup.ButtonItem
								key={ schemeItem.id }
								className="px-2 py-1"
								value={ schemeItem }
							>
								{ schemeItem.name }
							</ButtonGroup.ButtonItem>
						) ) }
					</ButtonGroup>
				</div>
				{ /* Saturation */ }
				<div className="flex items-center justify-between gap-3">
					<span className="text-zip-app-heading text-sm font-normal">
						{ __( 'Saturation', 'astra-sites' ) }
					</span>
					<ButtonGroup
						onChange={ setBackgroundSaturation }
						value={ backgroundSaturation }
					>
						{ backgroundSaturations.map( ( saturationItem ) => (
							<ButtonGroup.ButtonItem
								key={ saturationItem.id }
								className="px-2 py-1"
								value={ saturationItem }
							>
								{ saturationItem.name }
							</ButtonGroup.ButtonItem>
						) ) }
					</ButtonGroup>
				</div>
				{ /* Brightness */ }
				{ scheme.id === 'dark' && (
					<div className="flex items-center justify-between gap-3">
						<span className="text-zip-app-heading text-sm font-normal">
							{ __( 'Brightness', 'astra-sites' ) }
						</span>
						<ButtonGroup
							onChange={ setBrightnessLevel }
							value={ brightnessLevel }
						>
							{ brightnessLevels.map( ( brightnessItem ) => (
								<ButtonGroup.ButtonItem
									key={ brightnessItem.id }
									className="w-7 h-7 px-2 py-1 justify-center"
									value={ brightnessItem }
								>
									{ brightnessItem.name }
								</ButtonGroup.ButtonItem>
							) ) }
						</ButtonGroup>
					</div>
				) }
			</div>
		</div>
	);
};

export default CustomColorPalette;
