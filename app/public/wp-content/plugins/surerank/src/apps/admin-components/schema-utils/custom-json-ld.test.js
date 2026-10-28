/* global describe, it, expect */
import {
	jsonLdToEditorState,
	editorStateToJsonLdString,
	validateCustomJsonLd,
} from './custom-json-ld';

const OPTIONS = [
	{ value: '%post.title%', label: 'Post Title' },
	{ value: '%post.excerpt%', label: 'Post Excerpt' },
];

const roundTrip = ( raw, options = OPTIONS ) =>
	editorStateToJsonLdString(
		JSON.parse( jsonLdToEditorState( raw, options ) )
	);

describe( 'jsonLdToEditorState', () => {
	it( 'preserves pretty-printed JSON byte-identically on round-trip', () => {
		const raw = `{
	"@context": "https://schema.org",
	"@type": "Article",
	"headline": "Hello world",
	"nested": {
		"deep": "value"
	}
}`;
		expect( roundTrip( raw ) ).toBe( raw );
	} );

	it( 'preserves indentation with spaces and blank lines', () => {
		const raw =
			'{\n    "@context": "https://schema.org",\n\n    "@type": "Article"\n}';
		expect( roundTrip( raw ) ).toBe( raw );
	} );

	it( 'converts known smart tags into mention nodes', () => {
		const state = JSON.parse(
			jsonLdToEditorState( '"headline": "%post.title%"', OPTIONS )
		);
		const children = state.root.children[ 0 ].children;
		const mention = children.find( ( node ) => node.type === 'mention' );

		expect( mention ).toBeDefined();
		expect( mention.data.value ).toBe( '%post.title%' );
	} );

	it( 'keeps unknown smart tags as plain text', () => {
		const raw = '"field": "%custom_field.some_key%"';
		const state = JSON.parse( jsonLdToEditorState( raw, OPTIONS ) );
		const children = state.root.children[ 0 ].children;

		expect( children.some( ( node ) => node.type === 'mention' ) ).toBe(
			false
		);
		expect( roundTrip( raw ) ).toBe( raw );
	} );

	it( 'converts newlines into linebreak nodes, not text', () => {
		const state = JSON.parse( jsonLdToEditorState( '{\n}', OPTIONS ) );
		const types = state.root.children[ 0 ].children.map(
			( node ) => node.type
		);

		expect( types ).toEqual( [ 'text', 'linebreak', 'text' ] );
	} );

	it( 'round-trips smart tags back to %tag% text', () => {
		const raw = '{"headline": "%post.title% and %post.excerpt%"}';
		expect( roundTrip( raw ) ).toBe( raw );
	} );

	it( 'handles empty and non-string input', () => {
		expect( roundTrip( '' ) ).toBe( '' );
		expect( roundTrip( undefined ) ).toBe( '' );
	} );
} );

describe( 'jsonLdToEditorState edge cases', () => {
	it( 'preserves CRLF line endings byte-identically', () => {
		const raw = '{\r\n\t"@type": "Article"\r\n}';
		expect( roundTrip( raw ) ).toBe( raw );
	} );

	it( 'handles back-to-back tags with no separator', () => {
		const raw = '{"headline": "%post.title%%post.excerpt%"}';
		const state = JSON.parse( jsonLdToEditorState( raw, OPTIONS ) );
		const mentions = state.root.children[ 0 ].children.filter(
			( node ) => node.type === 'mention'
		);

		expect( mentions ).toHaveLength( 2 );
		expect( roundTrip( raw ) ).toBe( raw );
	} );

	it( 'leaves unclosed tags as plain text', () => {
		const raw = '{"headline": "%post.title"}';
		const state = JSON.parse( jsonLdToEditorState( raw, OPTIONS ) );

		expect(
			state.root.children[ 0 ].children.some(
				( node ) => node.type === 'mention'
			)
		).toBe( false );
		expect( roundTrip( raw ) ).toBe( raw );
	} );

	it( 'leaves lone percent signs and %% untouched', () => {
		const raw = '{"discount": "100% off, was 50%% more"}';
		expect( roundTrip( raw ) ).toBe( raw );
	} );

	it( 'does not treat percent-space-percent spans as tags', () => {
		const raw = '{"note": "50% today and 20% tomorrow"}';
		const state = JSON.parse( jsonLdToEditorState( raw, OPTIONS ) );

		expect(
			state.root.children[ 0 ].children.every(
				( node ) => node.type !== 'mention'
			)
		).toBe( true );
		expect( roundTrip( raw ) ).toBe( raw );
	} );

	it( 'converts tags embedded mid-sentence with punctuation', () => {
		const raw = '{"headline": "Read: %post.title%, now!"}';
		const state = JSON.parse( jsonLdToEditorState( raw, OPTIONS ) );
		const mention = state.root.children[ 0 ].children.find(
			( node ) => node.type === 'mention'
		);

		expect( mention?.data?.value ).toBe( '%post.title%' );
		expect( roundTrip( raw ) ).toBe( raw );
	} );
} );

describe( 'editorStateToJsonLdString', () => {
	it( 'joins multiple paragraphs with newlines instead of dropping them', () => {
		const state = {
			root: {
				children: [
					{
						type: 'paragraph',
						children: [ { type: 'text', text: '{' } ],
					},
					{
						type: 'paragraph',
						children: [ { type: 'text', text: '}' } ],
					},
				],
			},
		};

		expect( editorStateToJsonLdString( state ) ).toBe( '{\n}' );
	} );

	it( 'returns empty string for empty state', () => {
		expect( editorStateToJsonLdString( undefined ) ).toBe( '' );
		expect( editorStateToJsonLdString( { root: { children: [] } } ) ).toBe(
			''
		);
	} );
} );

describe( 'validateCustomJsonLd smart-tag messaging', () => {
	it( 'flags smart tags outside quoted strings with a specific message', () => {
		const result = validateCustomJsonLd(
			'{"@context": "https://schema.org", "@type": "Article", "headline": %post.title%}'
		);

		expect( result.valid ).toBe( false );
		expect( result.message ).toMatch( /quoted JSON string values/ );
	} );

	it( 'accepts smart tags inside quoted strings', () => {
		const result = validateCustomJsonLd(
			'{"@context": "https://schema.org", "@type": "Article", "headline": "%post.title%"}'
		);

		expect( result.valid ).toBe( true );
	} );
} );

describe( 'jsonLdToEditorState does not corrupt encoded/percent content (contrast with PHP renderer)', () => {
	it( 'preserves URL-encoded characters as literal text', () => {
		const raw = '{"url": "https://ex.com/my%20page%20name"}';
		expect( roundTrip( raw ) ).toBe( raw );
	} );

	it( 'preserves percentage ranges', () => {
		const raw = '{"note": "20%-50% range"}';
		expect( roundTrip( raw ) ).toBe( raw );
	} );
} );
