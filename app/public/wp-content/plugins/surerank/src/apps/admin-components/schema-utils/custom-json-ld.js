import { __ } from '@wordpress/i18n';

export const CUSTOM_JSON_LD_SCHEMA_TITLE = 'Custom JSON-LD';
const SMART_TAG_PATTERN = /%[\w\-_.]+%/;
// Captures smart tags and newlines as separate items so everything between
// them (including indentation whitespace) stays byte-identical.
const EDITOR_STATE_SPLIT_PATTERN = /(%[\w\-_.]+%|\n)/;

const createTextNode = ( text ) => ( {
	detail: 0,
	format: 0,
	mode: 'normal',
	style: '',
	text,
	type: 'text',
	version: 1,
} );

/**
 * Convert a raw JSON-LD string into a Lexical editor state for EditorInput.
 *
 * Unlike the shared stringValueToFormatJSON, this preserves whitespace
 * exactly (no trim, indentation kept inside text nodes) so pretty-printed
 * JSON survives the round-trip. Newlines become linebreak nodes; known
 * smart tags become mention nodes; unknown tags stay plain text.
 *
 * @param {string}   stringContent - Raw JSON-LD markup.
 * @param {Object[]} options       - Variable suggestions ({ value, label }).
 * @return {string} Serialized Lexical editor state.
 */
export const jsonLdToEditorState = ( stringContent, options = [] ) => {
	const children = [];
	const content = typeof stringContent === 'string' ? stringContent : '';

	content
		.split( EDITOR_STATE_SPLIT_PATTERN )
		.filter( ( item ) => item !== '' )
		.forEach( ( item ) => {
			if ( item === '\n' ) {
				children.push( { type: 'linebreak', version: 1 } );
				return;
			}

			if ( SMART_TAG_PATTERN.test( item ) ) {
				const option = options?.find(
					( suggestion ) => suggestion?.value === item
				);
				if ( option ) {
					children.push( {
						type: 'mention',
						version: 1,
						data: { ...option },
						size: 'md',
						by: 'label',
					} );
					return;
				}
			}

			children.push( createTextNode( item ) );
		} );

	return JSON.stringify( {
		root: {
			children: [
				{
					children,
					direction: null,
					format: '',
					indent: 0,
					type: 'paragraph',
					version: 1,
					textFormat: 0,
					textStyle: '',
				},
			],
			direction: null,
			format: '',
			indent: 0,
			type: 'root',
			version: 1,
		},
	} );
};

/**
 * Convert a Lexical editor state back into the raw JSON-LD string.
 *
 * Walks every paragraph (not just the first, unlike editorValueToString)
 * so no content is dropped if the editor ever produces more than one.
 *
 * @param {Object} valueObj - Parsed editor state (editorState.toJSON()).
 * @return {string} Raw JSON-LD markup with smart tags as %tag% text.
 */
export const editorStateToJsonLdString = ( valueObj ) => {
	const paragraphs = valueObj?.root?.children || [];

	return paragraphs
		.map( ( paragraph ) =>
			( paragraph?.children || [] )
				.map( ( child ) => {
					switch ( child.type ) {
						case 'text':
							return child.text;
						case 'mention':
							return child.data?.value ?? '';
						case 'linebreak':
							return '\n';
						default:
							return '';
					}
				} )
				.join( '' )
		)
		.join( '\n' );
};

const normalizeNodes = ( decoded ) => {
	if ( Array.isArray( decoded?.[ '@graph' ] ) ) {
		return decoded[ '@graph' ].filter(
			( node ) =>
				node && typeof node === 'object' && ! Array.isArray( node )
		);
	}

	if ( Array.isArray( decoded ) ) {
		return decoded.filter(
			( node ) =>
				node && typeof node === 'object' && ! Array.isArray( node )
		);
	}

	if ( decoded && typeof decoded === 'object' ) {
		return [ decoded ];
	}

	return [];
};

export const validateCustomJsonLd = ( rawValue ) => {
	const raw = typeof rawValue === 'string' ? rawValue.trim() : '';

	if ( ! raw ) {
		return {
			valid: false,
			message: __(
				'Add valid JSON-LD markup before saving.',
				'surerank'
			),
		};
	}

	try {
		const decoded = JSON.parse( raw );
		const nodes = normalizeNodes( decoded );

		if ( ! nodes.length ) {
			return {
				valid: false,
				message: __(
					'Provide a JSON-LD object or @graph payload with at least one node.',
					'surerank'
				),
			};
		}

		const hasContext =
			!! decoded?.[ '@context' ] ||
			nodes.some( ( node ) => !! node?.[ '@context' ] );
		if ( ! hasContext ) {
			return {
				valid: false,
				message: __(
					'Include an @context value such as https://schema.org.',
					'surerank'
				),
			};
		}

		const missingType = nodes.some( ( node ) => ! node?.[ '@type' ] );
		if ( missingType ) {
			return {
				valid: false,
				message: __(
					'Each JSON-LD object must include an @type value.',
					'surerank'
				),
			};
		}

		return { valid: true, message: '' };
	} catch ( error ) {
		if ( SMART_TAG_PATTERN.test( raw ) ) {
			return {
				valid: false,
				message: __(
					'Smart tags are supported only inside quoted JSON string values.',
					'surerank'
				),
			};
		}

		return {
			valid: false,
			message:
				error?.message ||
				__( 'The JSON-LD markup could not be parsed.', 'surerank' ),
		};
	}
};

export const validateCustomJsonLdSchemas = ( schemas = {} ) => {
	for ( const schema of Object.values( schemas || {} ) ) {
		if ( schema?.title !== CUSTOM_JSON_LD_SCHEMA_TITLE ) {
			continue;
		}

		const label =
			schema?.fields?.schema_name || CUSTOM_JSON_LD_SCHEMA_TITLE;
		const validation = validateCustomJsonLd(
			schema?.fields?.custom_json_ld || ''
		);

		if ( ! validation.valid ) {
			return {
				valid: false,
				message: `${ label }: ${ validation.message }`,
			};
		}
	}

	return { valid: true, message: '' };
};
