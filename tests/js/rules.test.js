import {
	DEFAULT_FILTERS,
	filterRules,
	isFiltered,
	moveItem,
	pathWithoutQuery,
	restoredRegexOrder,
	sortRules,
	splitByType,
	visibleSelection,
} from '../../assets/src/utils/rules';

const rules = [
	{
		id: 1,
		type: 'exact',
		source: '/b-page',
		target: '/x',
		status_code: 301,
		enabled: true,
		origin: 'manual',
		note: '',
		hits: 5,
		last_hit_at: '2026-10-01 10:00:00',
		created_at: '2026-01-01 00:00:00',
		position: 0,
	},
	{
		id: 2,
		type: 'exact',
		source: '/A-page',
		target: '/y',
		status_code: 302,
		enabled: false,
		origin: 'auto',
		note: 'Slug changed',
		hits: 9,
		last_hit_at: null,
		created_at: '2026-02-01 00:00:00',
		position: 0,
	},
	{
		id: 3,
		type: 'regex',
		source: '^/r/(.*)$',
		target: '/z/$1',
		status_code: 301,
		enabled: true,
		origin: 'manual',
		note: '',
		hits: 0,
		last_hit_at: null,
		created_at: '2026-03-01 00:00:00',
		position: 2,
	},
	{
		id: 4,
		type: 'regex',
		source: '^/q',
		target: null,
		status_code: 410,
		enabled: true,
		origin: 'manual',
		note: '',
		hits: 1,
		last_hit_at: null,
		created_at: '2026-03-02 00:00:00',
		position: 1,
	},
];

describe( 'filterRules', () => {
	it( 'returns everything with default filters', () => {
		expect( filterRules( rules, DEFAULT_FILTERS ) ).toHaveLength( 4 );
		expect( isFiltered( DEFAULT_FILTERS ) ).toBe( false );
	} );
	it( 'searches source, target and note case-insensitively', () => {
		expect(
			filterRules( rules, { ...DEFAULT_FILTERS, search: 'a-PAGE' } ).map(
				( r ) => r.id
			)
		).toEqual( [ 2 ] );
		expect(
			filterRules( rules, { ...DEFAULT_FILTERS, search: 'slug' } ).map(
				( r ) => r.id
			)
		).toEqual( [ 2 ] );
		expect(
			filterRules( rules, { ...DEFAULT_FILTERS, search: '/z/' } ).map(
				( r ) => r.id
			)
		).toEqual( [ 3 ] );
	} );
	it( 'filters by status, enabled and origin', () => {
		expect(
			filterRules( rules, { ...DEFAULT_FILTERS, status: '302' } ).map(
				( r ) => r.id
			)
		).toEqual( [ 2 ] );
		expect(
			filterRules( rules, {
				...DEFAULT_FILTERS,
				enabled: 'disabled',
			} ).map( ( r ) => r.id )
		).toEqual( [ 2 ] );
		expect(
			filterRules( rules, { ...DEFAULT_FILTERS, origin: 'auto' } ).map(
				( r ) => r.id
			)
		).toEqual( [ 2 ] );
		expect( isFiltered( { ...DEFAULT_FILTERS, origin: 'auto' } ) ).toBe(
			true
		);
	} );
} );

describe( 'sortRules', () => {
	it( 'sorts by source case-insensitively by default', () => {
		expect( sortRules( rules.slice( 0, 2 ) ).map( ( r ) => r.id ) ).toEqual(
			[ 2, 1 ]
		);
	} );
	it( 'sorts by hits descending', () => {
		expect(
			sortRules( rules, { orderby: 'hits', order: 'desc' } ).map(
				( r ) => r.id
			)
		).toEqual( [ 2, 1, 4, 3 ] );
	} );
	it( 'puts never-hit rules last when sorting last hit descending', () => {
		expect(
			sortRules( rules, { orderby: 'last_hit_at', order: 'desc' } )[ 0 ]
				.id
		).toBe( 1 );
	} );
	it( 'does not mutate the input', () => {
		const copy = [ ...rules ];
		sortRules( rules, { orderby: 'hits', order: 'desc' } );
		expect( rules ).toEqual( copy );
	} );
} );

describe( 'splitByType', () => {
	it( 'splits and orders regex by position', () => {
		const { exact, regex } = splitByType( rules );
		expect( exact.map( ( r ) => r.id ) ).toEqual( [ 1, 2 ] );
		expect( regex.map( ( r ) => r.id ) ).toEqual( [ 4, 3 ] );
	} );
} );

describe( 'moveItem', () => {
	it( 'moves an id and ignores out-of-range targets', () => {
		expect( moveItem( [ 1, 2, 3 ], 0, 2 ) ).toEqual( [ 2, 3, 1 ] );
		expect( moveItem( [ 1, 2, 3 ], 2, 1 ) ).toEqual( [ 1, 3, 2 ] );
		expect( moveItem( [ 1, 2, 3 ], 0, -1 ) ).toEqual( [ 1, 2, 3 ] );
	} );
} );

describe( 'visibleSelection', () => {
	it( 'keeps only selected ids that are visible, preserving order', () => {
		expect( visibleSelection( [ 3, 1, 2 ], [ 1, 2, 4 ] ) ).toEqual( [
			1, 2,
		] );
	} );
	it( 'returns an empty list when nothing selected is visible', () => {
		expect( visibleSelection( [ 5 ], [ 1, 2 ] ) ).toEqual( [] );
		expect( visibleSelection( [], [ 1, 2 ] ) ).toEqual( [] );
	} );
} );

describe( 'pathWithoutQuery', () => {
	it( 'drops the query string', () => {
		expect( pathWithoutQuery( '/old?fbclid=x&y=1' ) ).toBe( '/old' );
		expect( pathWithoutQuery( '/old/' ) ).toBe( '/old/' );
		expect( pathWithoutQuery( '/?p=1' ) ).toBe( '/' );
	} );
} );

describe( 'restoredRegexOrder', () => {
	const items = [
		{ id: 1, type: 'exact', position: 0 },
		{ id: 5, type: 'regex', position: 1 },
		{ id: 6, type: 'regex', position: 2 },
		{ id: 7, type: 'regex', position: 3 },
	];
	it( 'puts the re-created rule back where the deleted one was', () => {
		expect( restoredRegexOrder( items, items[ 2 ], 20 ) ).toEqual( [
			5, 20, 7,
		] );
	} );
	it( 'does nothing for exact rules', () => {
		expect( restoredRegexOrder( items, items[ 0 ], 20 ) ).toBeNull();
	} );
} );
