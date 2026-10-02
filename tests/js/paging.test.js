import {
	DEFAULT_PAGE_SIZE,
	PAGE_SIZES,
	pageOfIndex,
	paginate,
	readPageSize,
	writePageSize,
} from '../../assets/src/utils/paging';

const range = ( count ) => Array.from( { length: count }, ( _, i ) => i );

describe( 'paginate', () => {
	it( 'returns the requested page with 0-based slice bounds', () => {
		const result = paginate( range( 94 ), 1, 25 );
		expect( result.items ).toEqual( range( 94 ).slice( 25, 50 ) );
		expect( result ).toMatchObject( {
			page: 1,
			pageCount: 4,
			start: 25,
			end: 50,
			total: 94,
		} );
	} );
	it( 'ends the last page at the list length', () => {
		const result = paginate( range( 94 ), 3, 25 );
		expect( result.items ).toHaveLength( 19 );
		expect( result ).toMatchObject( { start: 75, end: 94 } );
	} );
	it( 'clamps a negative page to the first', () => {
		expect( paginate( range( 60 ), -3, 25 ).page ).toBe( 0 );
	} );
	it( 'clamps a page past the end to the last', () => {
		const result = paginate( range( 60 ), 99, 25 );
		expect( result.page ).toBe( 2 );
		expect( result.items ).toEqual( range( 60 ).slice( 50 ) );
	} );
	it( 'lands on the last page after the list shrinks', () => {
		expect( paginate( range( 51 ), 2, 25 ).page ).toBe( 2 );
		const shrunk = paginate( range( 50 ), 2, 25 );
		expect( shrunk.page ).toBe( 1 );
		expect( shrunk ).toMatchObject( { start: 25, end: 50 } );
	} );
	it( 'treats a non-numeric page as the first', () => {
		expect( paginate( range( 60 ), NaN, 25 ).page ).toBe( 0 );
	} );
	it( 'shows everything on one page when the size is 0', () => {
		const result = paginate( range( 94 ), 5, 0 );
		expect( result.items ).toHaveLength( 94 );
		expect( result ).toMatchObject( {
			page: 0,
			pageCount: 1,
			start: 0,
			end: 94,
			total: 94,
		} );
	} );
	it( 'handles an empty list', () => {
		expect( paginate( [], 3, 25 ) ).toEqual( {
			items: [],
			page: 0,
			pageCount: 1,
			start: 0,
			end: 0,
			total: 0,
		} );
		expect( paginate( [], 0, 0 ).pageCount ).toBe( 1 );
	} );
	it( 'splits at exact boundaries', () => {
		expect( paginate( range( 25 ), 0, 25 ).pageCount ).toBe( 1 );
		expect( paginate( range( 26 ), 0, 25 ).pageCount ).toBe( 2 );
		expect( paginate( range( 26 ), 1, 25 ).items ).toEqual( [ 25 ] );
		expect( paginate( range( 50 ), 0, 25 ).pageCount ).toBe( 2 );
	} );
} );

describe( 'pageOfIndex', () => {
	it( 'finds the page holding an index', () => {
		expect( pageOfIndex( 0, 25 ) ).toBe( 0 );
		expect( pageOfIndex( 24, 25 ) ).toBe( 0 );
		expect( pageOfIndex( 25, 25 ) ).toBe( 1 );
		expect( pageOfIndex( 99, 50 ) ).toBe( 1 );
	} );
	it( 'is always page 0 for All', () => {
		expect( pageOfIndex( 500, 0 ) ).toBe( 0 );
	} );
} );

describe( 'page size preference', () => {
	afterEach( () => {
		jest.restoreAllMocks();
		window.localStorage.clear();
	} );

	it( 'offers 25, 50, 100 and All, defaulting to 25', () => {
		expect( PAGE_SIZES ).toEqual( [ 25, 50, 100, 0 ] );
		expect( DEFAULT_PAGE_SIZE ).toBe( 25 );
	} );
	it( 'defaults when nothing is stored', () => {
		expect( readPageSize() ).toBe( 25 );
	} );
	it( 'round-trips every allowed size, including All', () => {
		PAGE_SIZES.forEach( ( size ) => {
			writePageSize( size );
			expect( readPageSize() ).toBe( size );
		} );
	} );
	it( 'ignores garbage and sizes that are not offered', () => {
		[ 'abc', '37', '-1', '', ' ', '1e2x', '[]' ].forEach( ( value ) => {
			window.localStorage.setItem( 'adv_redirects_page_size', value );
			expect( readPageSize() ).toBe( 25 );
		} );
	} );
	it( 'survives storage that throws', () => {
		jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation( () => {
			throw new Error( 'blocked' );
		} );
		jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'blocked' );
		} );
		expect( readPageSize() ).toBe( 25 );
		expect( () => writePageSize( 50 ) ).not.toThrow();
	} );
} );
