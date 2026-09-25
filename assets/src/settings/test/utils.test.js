import { describe, expect, it } from 'vitest';
import {
	matchesSearch,
	sameValue,
	changedModules,
	mergeValues,
	parseImport,
	exportPayload,
	normalizeHex,
	withoutStaleOptions,
	defaultsOf,
} from '../utils';

const modules = [
	{
		id: 'comments',
		title: 'Comments',
		fields: [
			{
				key: 'disable',
				type: 'bool',
				label: 'Disable comments',
				description: 'Turns off comments everywhere.',
				why: 'Spam.',
				sideEffects: null,
				default: false,
			},
		],
	},
	{
		id: 'media',
		title: 'Media',
		fields: [
			{
				key: 'quality',
				type: 'int',
				label: 'Image quality',
				description: 'JPEG quality.',
				why: null,
				sideEffects: 'Only new uploads.',
				default: 82,
			},
			{
				key: 'roles',
				type: 'multi',
				label: 'SVG roles',
				description: 'Who may upload.',
				why: null,
				sideEffects: null,
				default: [],
				options: [
					{ value: 'a', label: 'A' },
					{ value: 'b', label: 'B' },
				],
			},
		],
	},
];
const [ comments, media ] = modules;

describe( 'matchesSearch', () => {
	it( 'matches every term, in any text of the field or its module', () => {
		expect( matchesSearch( comments, comments.fields[ 0 ], 'spam' ) ).toBe(
			true
		);
		expect( matchesSearch( media, media.fields[ 0 ], 'MEDIA new' ) ).toBe(
			true
		);
		expect( matchesSearch( media, media.fields[ 0 ], 'jpeg spam' ) ).toBe(
			false
		);
	} );

	it( 'treats an empty query as a match', () => {
		expect( matchesSearch( comments, comments.fields[ 0 ], '   ' ) ).toBe(
			true
		);
	} );
} );

describe( 'sameValue', () => {
	it( 'ignores the order of lists', () => {
		expect( sameValue( [ 'a', 'b' ], [ 'b', 'a' ] ) ).toBe( true );
		expect( sameValue( [ 'a' ], [ 'a', 'b' ] ) ).toBe( false );
		expect( sameValue( 82, 82 ) ).toBe( true );
		expect( sameValue( 82, '82' ) ).toBe( false );
	} );
} );

describe( 'changedModules', () => {
	it( 'lists modules with at least one different value', () => {
		const saved = {
			comments: { disable: false },
			media: { quality: 82, roles: [ 'a', 'b' ] },
		};
		const draft = {
			comments: { disable: true },
			media: { quality: 82, roles: [ 'b', 'a' ] },
		};

		expect( changedModules( draft, saved ) ).toEqual( [ 'comments' ] );
	} );
} );

describe( 'mergeValues', () => {
	const draft = {
		comments: { disable: false },
		media: { quality: 82, roles: [] },
	};

	it( 'applies known values and reports the rest', () => {
		const result = mergeValues(
			draft,
			{
				comments: { disable: true, nope: 1 },
				other: { x: 1 },
				media: 'x',
			},
			modules,
			{}
		);

		expect( result.values.comments.disable ).toBe( true );
		expect( result.applied ).toBe( 1 );
		expect( result.ignored ).toEqual( [
			'comments.nope',
			'other',
			'media',
		] );
		expect( draft.comments.disable ).toBe( false );
	} );

	it( 'never changes locked values', () => {
		const result = mergeValues(
			draft,
			{ media: { quality: 50 } },
			modules,
			{ media: [ 'quality' ] }
		);

		expect( result.values.media.quality ).toBe( 82 );
		expect( result.ignored ).toEqual( [ 'media.quality' ] );
	} );
} );

describe( 'parseImport', () => {
	it( 'accepts an export file and a plain settings object', () => {
		expect(
			parseImport(
				'{"plugin":"wp-toolbox","settings":{"comments":{"disable":true}}}'
			)
		).toEqual( { comments: { disable: true } } );
		expect( parseImport( '{"comments":{"disable":true}}' ) ).toEqual( {
			comments: { disable: true },
		} );
	} );

	it( 'rejects anything else', () => {
		expect( () => parseImport( 'nope' ) ).toThrow();
		expect( () => parseImport( '[1,2]' ) ).toThrow();
		expect( () => parseImport( 'null' ) ).toThrow();
		expect( () =>
			parseImport( '{"plugin":"wp-toolbox","settings":[]}' )
		).toThrow();
	} );
} );

describe( 'exportPayload', () => {
	it( 'contains everything but locked values, which belong to wp-config.php', () => {
		const payload = exportPayload(
			{ comments: { disable: true }, media: { quality: 70, roles: [] } },
			{ comments: [ 'disable' ] },
			'4.0.0'
		);

		expect( payload ).toEqual( {
			plugin: 'wp-toolbox',
			version: '4.0.0',
			settings: { comments: {}, media: { quality: 70, roles: [] } },
		} );
		expect( parseImport( JSON.stringify( payload ) ) ).toEqual(
			payload.settings
		);
	} );
} );

describe( 'normalizeHex', () => {
	it( 'returns #rrggbb in lowercase, or empty for anything else', () => {
		expect( normalizeHex( '#1A2B3C' ) ).toBe( '#1a2b3c' );
		expect( normalizeHex( '#abc' ) ).toBe( '#aabbcc' );
		expect( normalizeHex( '#1a2b3cff' ) ).toBe( '#1a2b3c' );
		expect( normalizeHex( 'red' ) ).toBe( '' );
		expect( normalizeHex( undefined ) ).toBe( '' );
	} );
} );

describe( 'withoutStaleOptions', () => {
	it( 'drops list values that are no longer options (e.g. a removed post type)', () => {
		const values = {
			comments: { disable: true },
			media: { quality: 82, roles: [ 'a', 'gone' ] },
		};

		expect( withoutStaleOptions( values, modules ).media.roles ).toEqual( [
			'a',
		] );
		expect( values.media.roles ).toEqual( [ 'a', 'gone' ] );
	} );
} );

describe( 'defaultsOf', () => {
	it( 'returns the defaults of a module, keeping locked values', () => {
		expect(
			defaultsOf( media, { quality: 50, roles: [ 'a' ] }, [ 'quality' ] )
		).toEqual( { quality: 50, roles: [] } );
	} );
} );
